"""Closed-loop concurrency benchmark: N threads, each sends queries back-to-back for D seconds.
usage: conc.py <index> <mode> <duration_s> <conc,...> [label]
mode: one of esq.SCENARIOS keys, 'mix' (all scenarios round-robin), or 'flat:<scenario>' via variants.py"""
import sys, json, time, threading, http.client, statistics, esq

idx, mode, dur = sys.argv[1], sys.argv[2], float(sys.argv[3])
concs = [int(x) for x in sys.argv[4].split(',')]
label = sys.argv[5] if len(sys.argv) > 5 else ''


def bodies():
    if mode.startswith('v:'):
        import variants
        kind, scen = mode[2:].split('/')
        return [json.dumps(variants.build(kind, esq.SCENARIOS[s])) for s in scen.split('+')]
    if mode == 'mix':
        return [json.dumps(esq.body(c)) for c in esq.SCENARIOS.values()]
    return [json.dumps(esq.body(esq.SCENARIOS[s])) for s in mode.split('+')]


B = bodies()


def tp():
    s = esq.req('GET', '/_nodes/stats/thread_pool,jvm,process?filter_path=nodes.*.thread_pool.search,nodes.*.jvm.gc,nodes.*.jvm.mem.heap_used_percent,nodes.*.process.cpu')
    n = list(s['nodes'].values())[0]
    gc = n['jvm']['gc']['collectors']
    return {'rejected': n['thread_pool']['search']['rejected'], 'completed': n['thread_pool']['search']['completed'],
            'largest_q': n['thread_pool']['search'].get('queue'), 'young_ms': gc['young']['collection_time_in_millis'],
            'young_n': gc['young']['collection_count'], 'old_n': gc['old']['collection_count'], 'heap_pct': n['jvm']['mem']['heap_used_percent'], 'cpu_ms': n['process']['cpu']['total_in_millis']}


def worker(lat, errs, stop, k, qmax):
    conn = http.client.HTTPConnection('127.0.0.1', 19200, timeout=60)
    i = k
    while time.time() < stop:
        b = B[i % len(B)]; i += 1
        t = time.perf_counter()
        try:
            conn.request('POST', '/%s/_search?request_cache=false' % idx, b, {'content-type': 'application/json'})
            r = conn.getresponse(); data = r.read()
            if r.status != 200:
                errs.append(r.status)
                continue
        except Exception as e:
            errs.append(str(e)); conn = http.client.HTTPConnection('127.0.0.1', 19200, timeout=60); continue
        lat.append((time.perf_counter() - t) * 1000)


def sampler(stop, out):
    while time.time() < stop:
        s = esq.req('GET', '/_nodes/stats/thread_pool?filter_path=nodes.*.thread_pool.search')
        n = list(s['nodes'].values())[0]['thread_pool']['search']
        out.append((n['active'], n['queue']))
        time.sleep(0.25)


res = []
for c in concs:
    # warm-up
    for b in B: esq.req('POST', '/%s/_search?request_cache=false' % idx, json.loads(b))
    before = tp()
    lat, errs, samples = [], [], []
    stop = time.time() + dur
    ts = [threading.Thread(target=worker, args=(lat, errs, stop, k, None)) for k in range(c)]
    st = threading.Thread(target=sampler, args=(stop, samples))
    t0 = time.time()
    for t in ts: t.start()
    st.start()
    for t in ts: t.join()
    st.join()
    el = time.time() - t0
    after = tp()
    lat.sort()
    q = lambda p: round(lat[min(len(lat) - 1, int(p * len(lat)))], 0) if lat else None
    row = {'label': label, 'index': idx, 'mode': mode, 'conc': c, 'n': len(lat), 'qps': round(len(lat) / el, 2),
           'p50': q(0.5), 'p90': q(0.9), 'p99': q(0.99), 'max': q(1.0), 'gt2s_pct': round(100 * sum(1 for x in lat if x > 2000) / max(1, len(lat)), 1),
           'errors': len(errs), 'rejected': after['rejected'] - before['rejected'],
           'max_active': max((a for a, _ in samples), default=0), 'max_queue': max((qq for _, qq in samples), default=0),
           'young_gc_ms': after['young_ms'] - before['young_ms'], 'young_gc_n': after['young_n'] - before['young_n'],
           'old_gc_n': after['old_n'] - before['old_n'], 'heap_pct_after': after['heap_pct'], 'cpu_ms_per_query': round((after['cpu_ms'] - before['cpu_ms']) / max(1, len(lat)), 1)}
    print(json.dumps(row), flush=True)
    res.append(row)
with open('conc_results.jsonl', 'a') as f:
    for r in res: f.write(json.dumps(r) + '\n')

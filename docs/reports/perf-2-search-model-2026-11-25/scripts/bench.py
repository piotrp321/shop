"""Measurements of PERF-2 stage A against the isolated Elasticsearch. Every request has request_cache=false.

  bench.py single <index> <kind> [scenario,...]         per scenario: took (median of 7 after 2 warm-ups) and the CPU
                                                        time of the Elasticsearch process per request
  bench.py conc <index> <kind> <seconds> <n,...> [mix|scenario]   closed loop, n threads: q/s, latency, CPU per request
  bench.py burst <index> <kind> <n>                     n different requests of the mix at once: slowest, CPU
  bench.py open <index> <kind> <rate/s> <seconds>       open loop at a fixed rate (requests of the mix)
kind: cur | flat3 | pl | pl-split (hits and facets as two requests in one _msearch)
Results are appended to ../results/bench.jsonl with the label of $LABEL."""
import http.client, json, os, statistics, sys, threading, time
import models as m
import cpu as cg
from models import esq

OUT = os.path.join(m.HERE, '..', 'results', 'bench.jsonl')
LABEL = os.environ.get('LABEL', '')
CACHE = os.environ.get('CACHE', '')
HOST, PORT = '127.0.0.1', 19200


def payload(index, kind, c, page):
    """-> (path, body bytes, content type)"""
    if kind == 'pl-split':
        lines = []
        for i, body in enumerate(m.pl_split(c, page)):
            # CACHE=facets: the native request cache for the facets request only (the cold path keeps it off)
            lines += [json.dumps({'request_cache': CACHE == 'facets' and i == 1}), json.dumps(body)]
        return '/%s/_msearch' % index, ('\n'.join(lines) + '\n').encode(), 'application/x-ndjson'
    if kind == 'plm' and c.get('phrase'):
        kind = 'pl'  # a phrase is matched once: one request, the text query is not repeated per aggregation group
    if kind == 'plm':
        lines = []
        for role, body in m.plm(c, page):
            # the cold path keeps the native request cache off; CACHE=facets turns it on for every size-0 request,
            # CACHE=tok only for the facets of a listing without conditions (a bounded set: category x availability)
            cached = body['size'] == 0 and (CACHE == 'facets' or (CACHE == 'tok' and 'tok' in body.get('aggs', {})))
            lines += [json.dumps({'request_cache': cached}), json.dumps(body)]
        return '/%s/_msearch' % index, ('\n'.join(lines) + '\n').encode(), 'application/x-ndjson'
    return '/%s/_search?request_cache=false' % index, json.dumps(m.build(kind, c, page)).encode(), 'application/json'


def send(conn, p):
    t = time.perf_counter()
    conn.request('POST', p[0], p[1], {'content-type': p[2]})
    r = conn.getresponse()
    data = r.read()
    wall = (time.perf_counter() - t) * 1000
    ok = r.status == 200
    took = None
    if ok:
        d = json.loads(data)
        if 'responses' in d:
            ok = all(x.get('status') == 200 and not x.get('timed_out') and x['_shards']['failed'] == 0 for x in d['responses'])
            took = d['took']
        else:
            ok = not d.get('timed_out') and d['_shards']['failed'] == 0
            took = d['took']
    return ok, took, wall


def stats():
    stats.calls += 1
    s = esq.req('GET', '/_nodes/stats/thread_pool,jvm,process?filter_path=nodes.*.thread_pool.search,nodes.*.jvm.gc,nodes.*.jvm.mem.heap_used_percent,nodes.*.process.cpu')
    n = list(s['nodes'].values())[0]
    gc = n['jvm']['gc']['collectors']
    return {'cpu_ms': cg.usage_ms() - cg.OVERHEAD * stats.calls, 'rejected': n['thread_pool']['search']['rejected'],
            'young_ms': gc['young']['collection_time_in_millis'], 'old_n': gc['old']['collection_count'], 'heap_pct': n['jvm']['mem']['heap_used_percent']}


stats.calls = 0


def write(row):
    row = dict({'label': LABEL}, **row)
    with open(OUT, 'a') as f:
        f.write(json.dumps(row, ensure_ascii=False) + '\n')
    print(json.dumps(row, ensure_ascii=False), flush=True)


def pct(lat, p):
    return round(lat[min(len(lat) - 1, int(p * len(lat)))]) if lat else None


def mix():
    return json.load(open(os.path.join(m.HERE, '..', 'results', 'mix.json')))


def single(index, kind, names):
    conn = http.client.HTTPConnection(HOST, PORT, timeout=600)
    for name in names:
        c, page = m.scenario(name)
        p = payload(index, kind, c, page)
        for _ in range(2):
            send(conn, p)
        time.sleep(0.3)
        before = stats()
        took, n = [], 7
        for _ in range(n):
            ok, t, _w = send(conn, p)
            assert ok, name
            took.append(t)
        after = stats()
        write({'test': 'single', 'index': index, 'kind': kind, 'scenario': name, 'took': statistics.median(took), 'min': min(took),
               'max': max(took), 'cpu_ms': round((after['cpu_ms'] - before['cpu_ms']) / n, 1)})


def conc(index, kind, dur, concs, what):
    if what == 'mix':
        ps = [payload(index, kind, x['c'], x['page']) for x in mix()]
    else:
        ps = [payload(index, kind, *m.scenario(what))]
    for n in concs:
        warm = http.client.HTTPConnection(HOST, PORT, timeout=600)
        for p in ps[:40]:
            send(warm, p)
        lat, errs = [], []
        stop = time.time() + dur
        before = stats()

        def worker(k):
            conn = http.client.HTTPConnection(HOST, PORT, timeout=600)
            i = k * 7919
            while time.time() < stop:
                try:
                    ok, _t, wall = send(conn, ps[i % len(ps)])
                except Exception as e:  # noqa: BLE001
                    errs.append(str(e)); conn = http.client.HTTPConnection(HOST, PORT, timeout=600); continue
                i += 1
                (lat if ok else errs).append(wall)
        t0 = time.time()
        ts = [threading.Thread(target=worker, args=(k,)) for k in range(n)]
        for t in ts: t.start()
        for t in ts: t.join()
        el = time.time() - t0
        after = stats()
        lat.sort()
        write({'test': 'conc', 'index': index, 'kind': kind, 'what': what, 'n': n, 'requests': len(lat), 'qps': round(len(lat) / el, 1),
               'p50': pct(lat, .5), 'p95': pct(lat, .95), 'p99': pct(lat, .99), 'max': pct(lat, 1), 'errors': len(errs),
               'rejected': after['rejected'] - before['rejected'], 'cpu_ms': round((after['cpu_ms'] - before['cpu_ms']) / max(1, len(lat)), 1),
               'cpu_cores': round((after['cpu_ms'] - before['cpu_ms']) / 1000 / el, 2), 'young_gc_ms': after['young_ms'] - before['young_ms'],
               'old_gc': after['old_n'] - before['old_n'], 'heap_pct': after['heap_pct']})


def burst(index, kind, n):
    items = mix()
    for rnd in range(3):
        ps = [payload(index, kind, x['c'], x['page']) for x in items[rnd * n:(rnd + 1) * n]]
        lat, errs = [], []
        go = threading.Event()

        def worker(p):
            conn = http.client.HTTPConnection(HOST, PORT, timeout=600)
            conn.connect()
            go.wait()
            try:
                ok, _t, wall = send(conn, p)
                (lat if ok else errs).append(wall)
            except Exception as e:  # noqa: BLE001
                errs.append(str(e))
        ts = [threading.Thread(target=worker, args=(p,)) for p in ps]
        for t in ts: t.start()
        time.sleep(1)
        before = stats()
        t0 = time.time()
        go.set()
        for t in ts: t.join()
        el = time.time() - t0
        after = stats()
        lat.sort()
        write({'test': 'burst', 'index': index, 'kind': kind, 'n': n, 'round': rnd, 'ok': len(lat), 'errors': len(errs), 'p50': pct(lat, .5),
               'p95': pct(lat, .95), 'slowest': pct(lat, 1), 'all_done_ms': round(el * 1000), 'cpu_ms_total': after['cpu_ms'] - before['cpu_ms'],
               'cpu_ms': round((after['cpu_ms'] - before['cpu_ms']) / n, 1)})
        time.sleep(2)


def open_loop(index, kind, rate, dur):
    items = mix()
    ps = [payload(index, kind, x['c'], x['page']) for x in items]
    lat, errs, late = [], [], []
    total = int(rate * dur)
    pool = []
    lock = threading.Lock()

    def fire(i, due):
        with lock:
            conn = pool.pop() if pool else None
        conn = conn or http.client.HTTPConnection(HOST, PORT, timeout=600)
        try:
            ok, _t, wall = send(conn, ps[(i * 7919) % len(ps)])
            (lat if ok else errs).append(wall + max(0.0, (time.perf_counter() - due) * 1000 - wall))  # from the scheduled start
            with lock:
                pool.append(conn)
        except Exception as e:  # noqa: BLE001
            errs.append(str(e))
    before = stats()
    t0 = time.perf_counter()
    ts = []
    for i in range(total):
        due = t0 + i / rate
        d = due - time.perf_counter()
        if d > 0:
            time.sleep(d)
        elif d < -0.05:
            late.append(i)
        t = threading.Thread(target=fire, args=(i, due))
        t.start(); ts.append(t)
    for t in ts: t.join()
    el = time.perf_counter() - t0
    after = stats()
    lat.sort()
    write({'test': 'open', 'index': index, 'kind': kind, 'rate': rate, 'seconds': dur, 'ok': len(lat), 'errors': len(errs), 'late_starts': len(late),
           'p50': pct(lat, .5), 'p95': pct(lat, .95), 'p99': pct(lat, .99), 'max': pct(lat, 1), 'elapsed': round(el, 1),
           'cpu_ms': round((after['cpu_ms'] - before['cpu_ms']) / max(1, len(lat)), 1), 'cpu_cores': round((after['cpu_ms'] - before['cpu_ms']) / 1000 / el, 2)})


if __name__ == '__main__':
    test, index, kind = sys.argv[1:4]
    if test == 'single':
        single(index, kind, sys.argv[4].split(',') if len(sys.argv) > 4 else m.ALL)
    elif test == 'conc':
        conc(index, kind, float(sys.argv[4]), [int(x) for x in sys.argv[5].split(',')], sys.argv[6] if len(sys.argv) > 6 else 'mix')
    elif test == 'burst':
        burst(index, kind, int(sys.argv[4]))
    elif test == 'open':
        open_loop(index, kind, float(sys.argv[4]), float(sys.argv[5]))

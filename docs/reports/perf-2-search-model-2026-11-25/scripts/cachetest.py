"""Native request cache on the facets requests of plm: hit cost, invalidation by a write, concurrent identical
requests, and how many different facet requests the mix has. usage: cachetest.py <index>"""
import http.client, json, sys, threading, time
import models as m, bench, cpu as cg
from models import esq
idx = sys.argv[1]
out = {'test': 'cache', 'index': idx}
mix = bench.mix()
bodies = {}
for x in mix:
    for role, b in m.plm(x['c'], x['page']):
        if b['size'] == 0:
            bodies.setdefault(json.dumps(b, sort_keys=True), 0)
            bodies[json.dumps(b, sort_keys=True)] += 1
n0 = sum(bodies.values())
out.update(mix_addresses=len(mix), size0_requests=n0, distinct_size0_requests=len(bodies), most_shared=max(bodies.values()))
tok = dict(m.plm(m.SCENARIOS['all_name'])[1][1])
def clear(): esq.req('POST', '/%s/_cache/clear?request=true' % idx)
def one(cache=True):
    c0 = cg.usage_ms(); r = esq.req('POST', '/%s/_search?request_cache=%s' % (idx, 'true' if cache else 'false'), tok, timeout=600)
    return r['took'], round(cg.usage_ms() - c0 - cg.OVERHEAD, 1)
clear(); out['miss_took_cpu'] = one(); out['hit_took_cpu'] = one(); out['hit2_took_cpu'] = one()
d = esq.req('POST', '/%s/_search' % idx, {'size': 1})['hits']['hits'][0]
esq.req('PUT', '/%s/_doc/%s?refresh=true' % (idx, d['_id']), d['_source'])
out['after_write_took_cpu'] = one(); out['after_write_hit_took_cpu'] = one()
# 20 identical requests at once on an empty cache: does the engine compute once?
clear(); time.sleep(1)
lat = []; go = threading.Event()
def w():
    conn = http.client.HTTPConnection('127.0.0.1', 19200, timeout=600); conn.connect(); go.wait()
    t = time.perf_counter(); conn.request('POST', '/%s/_search?request_cache=true' % idx, json.dumps(tok), {'content-type': 'application/json'})
    r = conn.getresponse(); r.read(); lat.append(round((time.perf_counter() - t) * 1000))
ts = [threading.Thread(target=w) for _ in range(20)]
for t in ts: t.start()
time.sleep(0.5); c0 = cg.usage_ms(); go.set()
for t in ts: t.join()
out['concurrent20_cpu_ms'] = round(cg.usage_ms() - c0 - cg.OVERHEAD); out['concurrent20_latency_min_max'] = (min(lat), max(lat))
st = esq.req('GET', '/%s/_stats/request_cache' % idx)['_all']['total']['request_cache']
out['request_cache_stats'] = st
print(json.dumps(out)); open(bench.OUT, 'a').write(json.dumps(out) + '\n')

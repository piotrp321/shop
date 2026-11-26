"""First request after a write and a refresh against a repeated one (global ordinals are rebuilt after a refresh).
usage: refresh.py <index> <kind> <scenario>"""
import json, statistics, sys, time
import models as m, bench, cpu as cg
from models import esq
idx, kind, name = sys.argv[1:4]
c, page = m.scenario(name)
import http.client
conn = http.client.HTTPConnection(bench.HOST, bench.PORT, timeout=600)
p = bench.payload(idx, kind, c, page)
bench.send(conn, p)
d = esq.req('POST', '/%s/_search' % idx, {'size': 5, 'query': {'function_score': {'random_score': {'seed': 9, 'field': '_seq_no'}}}})['hits']['hits']
first, again = [], []
for i in range(5):
    esq.req('PUT', '/%s/_doc/%s?refresh=true' % (idx, d[i]['_id']), d[i]['_source'])
    time.sleep(3)
    for dst in (first, again):
        c0 = cg.usage_ms(); ok, took, wall = bench.send(conn, p); dst.append((round(cg.usage_ms() - c0 - cg.OVERHEAD), round(wall)))
row = {'test': 'refresh', 'index': idx, 'kind': kind, 'scenario': name, 'first_cpu_ms': statistics.median(x[0] for x in first), 'first_wall_ms': statistics.median(x[1] for x in first),
       'repeat_cpu_ms': statistics.median(x[0] for x in again), 'repeat_wall_ms': statistics.median(x[1] for x in again)}
print(json.dumps(row)); open(bench.OUT, 'a').write(json.dumps(row) + '\n')

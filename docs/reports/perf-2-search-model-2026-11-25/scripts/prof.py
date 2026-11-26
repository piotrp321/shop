"""Profile of the aggregations of one request: aggregator type, time and debug info per aggregation.
usage: prof.py <index> <kind> <scenario> [match_all]"""
import json, sys
import models as m
from models import esq
idx, kind, name = sys.argv[1:4]
c, page = m.scenario(name)
b = m.build(kind, c, page) if kind != 'pl-facets' else m.pl_split(c, page)[1]
if len(sys.argv) > 4:
    b['query'] = {'match_all': {}}
b['profile'] = True
r = esq.req('POST', '/%s/_search?request_cache=false' % idx, b, timeout=600)
print('took', r['took'], 'shards', len(r['profile']['shards']))
def walk(a, d=0):
    t = a['time_in_nanos'] / 1e6
    dbg = {k: v for k, v in a.get('debug', {}).items() if k not in ('filters',)}
    print('%s%-12s %-45s %8.1f ms %s' % ('  ' * d, a['description'][:12], a['type'][:45], t, json.dumps(dbg)[:230]))
    for ch in a.get('children', []):
        walk(ch, d + 1)
sh = r['profile']['shards'][0]
for s in sh['searches']:
    q = s['query'][0]; print('query', q['type'], round(q['time_in_nanos'] / 1e6, 1), 'ms; collector', s['collector'][0]['name'], round(s['collector'][0]['time_in_nanos'] / 1e6, 1), 'ms')
for a in sh['aggregations']:
    walk(a)

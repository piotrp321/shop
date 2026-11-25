import sys, json, esq
idx, scen = sys.argv[1], sys.argv[2]
b = esq.body(esq.SCENARIOS[scen]); b['profile'] = True
for _ in range(3): esq.req('POST', '/%s/_search?request_cache=false' % idx, b)
r = esq.req('POST', '/%s/_search?request_cache=false' % idx, b)
json.dump(r['profile'], open('profile_%s_%s.json' % (idx, scen), 'w'))
print('took', r['took'])
def walk(a, d=0):
    bd = a['breakdown']
    print('%s%-28s %-40s %8.1f ms  collect=%.1f build=%.1f init=%.1f' % ('  '*d, a['description'][:28], a['type'][-40:], a['time_in_nanos']/1e6,
          bd.get('collect',0)/1e6, (bd.get('build_aggregation',0)+bd.get('build_leaf_collector',0))/1e6, bd.get('initialize',0)/1e6))
    for ch in a.get('children', []): walk(ch, d+1)
for sh in r['profile']['shards']:
    print('shard', sh['id'][-12:])
    for s in sh['searches']:
        print(' query total %.1f ms, collector %.1f ms' % (sum(q['time_in_nanos'] for q in s['query'])/1e6, sum(c['time_in_nanos'] for c in s['collector'])/1e6))
        for q in s['query']: print('  q', q['type'], round(q['time_in_nanos']/1e6,1))
        def cw(c,d=0):
            print('   '+'  '*d, c['name'], c.get('reason'), round(c['time_in_nanos']/1e6,1))
            for x in c.get('children',[]): cw(x,d+1)
        for c in s['collector']: cw(c)
    for a in sh['aggregations']: walk(a)
    if 'fetch' in sh: print(' fetch %.1f ms' % (sh['fetch']['time_in_nanos']/1e6))

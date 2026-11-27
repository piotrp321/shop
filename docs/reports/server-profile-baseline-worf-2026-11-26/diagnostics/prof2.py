import sys,json
sys.path.insert(0,'.')
from prof import es,body
p=es('/products/_search?request_cache=false',{**body(None,('available','price','free')),'profile':True})
if 'profile' not in p: print(json.dumps(p)[:400]); sys.exit()
def walk(a,d=0):
    bd={k:v//10**6 for k,v in a['breakdown'].items() if v>10**7}
    print('  '*d+f"AGG {a['type']} {a['description'][:48]} {a['time_in_nanos']//10**6} ms {bd}")
    for c in a.get('children',[]): walk(c,d+1)
print('took',p['took'])
for i,sh in enumerate(p['profile']['shards'][:1]):
    for s in sh['searches']:
        for c in s['collector']: print('COLLECTOR',c['name'],c['reason'],c['time_in_nanos']//10**6,'ms')
        for q in s['query']: print('QUERY',q['type'],q['time_in_nanos']//10**6,'ms')
    for a in sh['aggregations']: walk(a)

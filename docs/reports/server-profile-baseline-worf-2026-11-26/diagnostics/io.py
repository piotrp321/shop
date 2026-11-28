import json,subprocess,os,sys
sys.path.insert(0,'.')
from prof import es,body,ENV
def io():
    r=subprocess.run(['kubectl','-n','shop','exec','elasticsearch-0','--','curl','-s','localhost:9200/_nodes/stats/fs'],capture_output=True,env=ENV)
    n=list(json.loads(r.stdout)['nodes'].values())[0]['fs']['io_stats']['total']; return n['read_kilobytes'],n['read_operations']
t=es('/products/_search?request_cache=false',{'size':0,'aggs':{'c':{'terms':{'field':'category_ancestor_ids','size':200}}}})['aggregations']['c']['buckets']
leaf=t[len(t)//2]['key']
for label,cat,aggs in (('leaf none',leaf,()),('leaf none again',leaf,()),('leaf all',leaf,('available','price','free')),('home none',None,()),('home all',None,('available','price','free'))):
    a=io(); r=es('/products/_search?request_cache=false',body(cat,aggs)); b=io()
    print(f"{label:16s} took={r['took']} ms, device read during query: {(b[0]-a[0])/1024:.0f} MiB in {b[1]-a[1]} ops")
# profile of leaf none
p=es('/products/_search?request_cache=false&profile=true',{**body(leaf,()), 'profile':True})
for sh in p['profile']['shards'][:1]:
    for s in sh['searches']:
        for q in s['query']:
            print('QUERY',q['type'],q['description'][:80],q['time_in_nanos']//10**6,'ms', {k:v//10**6 for k,v in q['breakdown'].items() if v>5*10**6})
        for c in s['collector']:
            print('COLLECTOR',c['name'],c['reason'],c['time_in_nanos']//10**6,'ms', [ (x['name'],x['time_in_nanos']//10**6) for x in c.get('children',[])])
    print('fetch', sh['fetch']['time_in_nanos']//10**6 if sh.get('fetch') else None)
# profile of home all aggregations
p=es('/products/_search?request_cache=false&profile=true',{**body(None,('available','price','free')),'profile':True})
def walk(a,d=0):
    bd={k:v//10**6 for k,v in a['breakdown'].items() if v>10**7}
    print('  '*d+f"AGG {a['type']} {a['description'][:40]} {a['time_in_nanos']//10**6} ms {bd}")
    for c in a.get('children',[]): walk(c,d+1)
sh=p['profile']['shards'][0]
for a in sh['aggregations']: walk(a)

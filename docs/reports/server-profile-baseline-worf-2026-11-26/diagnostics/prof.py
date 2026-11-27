import json, subprocess, sys, os
K=open('/private/tmp/claude-501/kubedir.txt').read().strip()
ENV=dict(os.environ, KUBECONFIG=K+'/config')
def es(path, body, method='POST'):
    cmd=['kubectl','-n','shop','exec','-i','elasticsearch-0','--','curl','-s','-X',method,'-H','Content-Type: application/json',f'localhost:9200{path}','-d','@-']
    r=subprocess.run(cmd,input=json.dumps(body).encode(),capture_output=True,env=ENV,timeout=600)
    try: return json.loads(r.stdout)
    except Exception: return {'raw': r.stdout[:300].decode(errors='replace'), 'err': r.stderr[:300].decode(errors='replace')}
def nested_agg(conds, aggs):
    f={'match_all':{}} if not conds else {'bool':{'filter':conds}}
    return {'nested':{'path':'variants'},'aggs':{'matching':{'filter':f,'aggs':aggs}}}
RN={'reverse_nested':{}}
AVAIL=lambda: nested_agg([{'term':{'variants.purchasable':True}}],{'product':RN})
PRICE=lambda: nested_agg([{'term':{'variants.purchasable':True}}],{'min':{'min':{'field':'variants.gross'}},'max':{'max':{'field':'variants.gross'}}})
FREE=lambda: nested_agg([],{'pairs':{'nested':{'path':'variants.pairs'},'aggs':{'axes':{'terms':{'field':'variants.pairs.axis','size':10,'order':[{'product':'desc'},{'_key':'asc'}]},'aggs':{'product':RN,'values':{'terms':{'field':'variants.pairs.value','size':30,'order':[{'product':'desc'},{'_key':'asc'}]},'aggs':{'product':RN}}}}}}})
def body(cat=None, aggs=()):
    flt=[{'term':{'status':'published'}}]
    if cat: flt.append({'term':{'category_ancestor_ids':cat}})
    b={'query':{'bool':{'filter':flt}},'sort':[{'name.sort':'asc'},{'id':'asc'}],'track_total_hits':True,'from':0,'size':48}
    a={}
    if 'available' in aggs: a['available']=AVAIL()
    if 'price' in aggs: a['price']=PRICE()
    if 'free' in aggs: a['free']=FREE()
    if a: b['aggs']=a
    return b
if __name__=='__main__':
    # pick a department-size and a leaf-size category
    t=es('/products/_search?request_cache=false',{'size':0,'aggs':{'c':{'terms':{'field':'category_ancestor_ids','size':200}}}})
    bs=t['aggregations']['c']['buckets']; print('categories sampled',len(bs),'largest',bs[0]['doc_count'],'median',bs[len(bs)//2]['doc_count'],'smallest',bs[-1]['doc_count'])
    targets={'home':None,'large_dept':bs[1]['doc_count'] and bs[1]['key'],'leaf_median':bs[len(bs)//2]['key']}
    cnt={k:(v['doc_count'] for v in bs if v['key']==targets[k]) for k in ('large_dept','leaf_median')}
    for name,cat in targets.items():
        for label,aggs in (('none',()),('available',('available',)),('price',('price',)),('free',('free',)),('all',('available','price','free'))):
            took=[]
            for i in range(3):
                r=es('/products/_search?request_cache=false',body(cat,aggs))
                took.append(r.get('took','ERR:'+str(r)[:80]))
            tot=r.get('hits',{}).get('total',{}).get('value')
            print(f'{name:12s} total={tot} aggs={label:10s} took_ms={took}')

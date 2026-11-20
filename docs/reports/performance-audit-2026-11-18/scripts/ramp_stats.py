import sys,re,collections
f=sys.argv[1]; t=None; rows=collections.OrderedDict()
for l in open(f):
    p=l.split()
    if not p: continue
    if p[0]=='T': t=p[1]; rows[t]={}; continue
    if t is None: continue
    if p[0]=='FOREIGN_TOTAL': rows[t]['foreign']=float(p[1]) if len(p)>1 else 0; continue
    name=p[0].replace('shop-perfaudit-','').replace('-1','')
    try: rows[t][name]=float(p[1].rstrip('%'))
    except: pass
cols=['store','api','postgres','elasticsearch','varnish','redis','k6-ramp','foreign']
print('time     '+' '.join(f'{c[:8]:>8}' for c in cols))
step=int(sys.argv[2]) if len(sys.argv)>2 else 1
for i,(t,r) in enumerate(rows.items()):
    if i%step==0: print(t+' '+' '.join(f"{r.get(c,0):8.0f}" for c in cols))

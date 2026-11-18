import sys,re
for l in sys.stdin:
    l=l.rstrip()
    m=re.search(r'cpu ms/req: (.*?) \| foreign cpu%=(.*)',l)
    if not m: print(l[:300]); continue
    cpu=dict(x.split('=') for x in m.group(1).split())
    keep=' '.join(f'{k}={v}' for k,v in cpu.items() if k in('postgres','elasticsearch','api_prod','api_prodw','api_prodp','store','varnish','api_dev','redis') and float(v)>=0.05)
    print(re.sub(r'bytes=\d+ ','',l[:l.index('| cpu')].replace('fail=0 ok=1 ','').replace('medium.',''))+'| '+keep+' | foreign='+m.group(2))

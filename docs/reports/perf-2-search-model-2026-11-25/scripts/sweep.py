"""Cost of recomputing the facets of every category listing without conditions (the set a materialised read model
or a periodic refresh would hold): 181 listings x (all products, purchasable only). usage: sweep.py <index of kind r|h>"""
import json, sys, time
import models as m, cpu as cg, bench
from models import esq

idx = sys.argv[1]
cats = [None] + list(esq.CNT)
t0 = time.time(); c0 = cg.usage_ms(); n = 0
for cat in cats:
    c = dict(category=cat) if cat else {}
    for buy in (False, True):
        esq.req('POST', '/%s/_search?request_cache=false' % idx, m._tok_search(c, buy), timeout=600); n += 1
row = {'test': 'sweep', 'index': idx, 'requests': n, 'cpu_ms_total': round(cg.usage_ms() - c0 - cg.OVERHEAD), 'seconds': round(time.time() - t0, 1)}
print(json.dumps(row)); open(bench.OUT, 'a').write(json.dumps(row) + '\n')

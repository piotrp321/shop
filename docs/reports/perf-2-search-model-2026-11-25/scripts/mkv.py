"""Builds the index of the candidate "variant as a document": one flat document per variant with the fields of its
product, no nested documents. The first variant of a product carries first=true and the product-level tokens, so
product counts without variant conditions are exact (count of first=true).
usage: mkv.py <name> <source index with the current mapping> [max products]"""
import json, os, sys, time, urllib.request
import mk
from mk import esq

name, source = sys.argv[1], sys.argv[2]
limit = int(sys.argv[3]) if len(sys.argv) > 3 else None
orig = mk.mappings('cur')['properties']
props = {k: orig[k] for k in ('category_ancestor_ids', 'category_names', 'from_gross', 'name', 'short_description', 'status', 'main_image', 'slug')}
props.update({'product_id': {'type': 'keyword'}, 'first': {'type': 'boolean'}, 'gross': {'type': 'long'}, 'purchasable': {'type': 'boolean'},
              'options': {'type': 'keyword'}, 'axes': {'type': 'keyword'}, 'facets': {'type': 'keyword'}})
sett = list(json.load(open(os.path.join(mk.AUDIT, 'settings_orig.json'))).values())[0]['settings']['index']
try: esq.req('DELETE', '/' + name)
except Exception: pass
esq.req('PUT', '/' + name, {'settings': {'number_of_shards': 1, 'number_of_replicas': 0, 'refresh_interval': '-1', 'analysis': sett['analysis']},
                             'mappings': {'dynamic': 'strict', 'properties': props}})
US = '\x1f'
t0 = time.time(); n = 0; docs = 0
r = esq.req('POST', '/%s/_search?scroll=5m' % source, {'size': 2000, 'sort': ['_doc']})
while r['hits']['hits'] and (limit is None or n < limit):
    lines = []
    for h in r['hits']['hits']:
        s = h['_source']; n += 1
        vs = s.get('variants') or []
        fa = sorted({o.split(US)[0] for v in vs for o in v['options']}); fo = sorted({o for v in vs for o in v['options']})
        toks = ['#' + c for c in s['category_ancestor_ids']] + ['@' + a for a in fa] + fo + (['$'] if any(v['purchasable'] for v in vs) else [])
        for i, v in enumerate(vs):
            d = {k: s.get(k) for k in ('category_ancestor_ids', 'category_names', 'from_gross', 'name', 'short_description', 'status', 'main_image', 'slug')}
            d.update(product_id=s['id'], first=(i == 0), gross=v.get('gross'), purchasable=v['purchasable'], options=v['options'],
                     axes=[o.split(US)[0] for o in v['options']], facets=toks if i == 0 else [])
            lines.append(json.dumps({'index': {'_id': '%s-%d' % (s['id'], i)}})); lines.append(json.dumps(d, ensure_ascii=False)); docs += 1
    req = urllib.request.Request(esq.ES + '/%s/_bulk' % name, data=('\n'.join(lines) + '\n').encode(), headers={'content-type': 'application/x-ndjson'})
    assert not json.loads(urllib.request.urlopen(req, timeout=300).read())['errors']
    r = esq.req('POST', '/_search/scroll', {'scroll': '5m', 'scroll_id': r['_scroll_id']})
esq.req('POST', '/%s/_refresh' % name, timeout=600)
esq.req('PUT', '/%s/_settings' % name, {'index': {'refresh_interval': '1s'}})
st = esq.req('GET', '/%s/_stats/docs,store' % name)['_all']['primaries']
print(json.dumps({'index': name, 'products': n, 'docs': docs, 'seconds': round(time.time() - t0), 'store_gb': round(st['store']['size_in_bytes'] / 2**30, 2)}))

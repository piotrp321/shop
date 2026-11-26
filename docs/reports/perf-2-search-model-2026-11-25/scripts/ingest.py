"""Indexing cost of a model: bulk rate (server-side _reindex of 1M products without a script, one task) and the cost
of rewriting single products one by one (the incremental path), with the CPU time of the Elasticsearch container.
usage: ingest.py <source index> <kind cur|h>"""
import json, sys, time
import mk, cpu as cg
from mk import esq

src, kind = sys.argv[1], sys.argv[2]
sort = sys.argv[3] if len(sys.argv) > 3 else None
tmp = 'tmp_ingest'
sett = list(json.load(open(mk.os.path.join(mk.AUDIT, 'settings_orig.json'))).values())[0]['settings']['index']
try: esq.req('DELETE', '/' + tmp)
except Exception: pass
settings = {'number_of_shards': 1, 'number_of_replicas': 0, 'refresh_interval': '-1', 'analysis': sett['analysis']}
if sort:
    settings.update({'sort.field': sort, 'sort.order': 'asc'})
esq.req('PUT', '/' + tmp, {'settings': settings, 'mappings': mk.mappings(kind)})
c0, t0 = cg.usage_ms(), time.time()
r = esq.req('POST', '/_reindex?wait_for_completion=true&refresh=true', {'max_docs': 1000000, 'source': {'index': src, 'size': 2000}, 'dest': {'index': tmp}}, timeout=3600)
el, c1 = time.time() - t0, cg.usage_ms()
st = esq.req('GET', '/%s/_stats/docs,store' % tmp)['_all']['primaries']
row = {'test': 'ingest', 'kind': kind, 'sort': sort, 'bulk_products': r['created'], 'bulk_seconds': round(el, 1), 'bulk_products_per_s': round(r['created'] / el),
       'bulk_cpu_ms_per_product': round((c1 - c0 - cg.OVERHEAD) / r['created'], 3), 'lucene_docs': st['docs']['count'], 'store_mb': round(st['store']['size_in_bytes'] / 2**20)}
# incremental: 2000 products rewritten one request each (as ProductIndexing does), refresh every 1 s
esq.req('PUT', '/%s/_settings' % tmp, {'index': {'refresh_interval': '1s'}})
docs = esq.req('POST', '/%s/_search' % tmp, {'size': 2000, 'query': {'function_score': {'random_score': {'seed': 3, 'field': '_seq_no'}}}})['hits']['hits']
import http.client
conn = http.client.HTTPConnection('127.0.0.1', 19200, timeout=60)
time.sleep(2)
c0, t0 = cg.usage_ms(), time.time()
for d in docs:
    conn.request('PUT', '/%s/_doc/%s' % (tmp, d['_id']), json.dumps(d['_source']).encode(), {'content-type': 'application/json'})
    resp = conn.getresponse(); resp.read(); assert resp.status in (200, 201)
time.sleep(1.5)
el, c1 = time.time() - t0 - 1.5, cg.usage_ms()
row.update({'update_n': len(docs), 'update_ms_per_product': round(el * 1000 / len(docs), 2), 'update_cpu_ms_per_product': round((c1 - c0 - cg.OVERHEAD) / len(docs), 2)})
esq.req('DELETE', '/' + tmp)
print(json.dumps(row))
open(mk.os.path.join(mk.HERE, '..', 'results', 'ingest.jsonl'), 'a').write(json.dumps(row) + '\n')

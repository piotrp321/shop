"""Background writer: rewrites existing products one request each at a fixed rate (as price and stock changes do
through ProductIndexing). usage: writes.py <index> <rate/s> <seconds>"""
import http.client, json, sys, time
from models import esq
idx, rate, dur = sys.argv[1], float(sys.argv[2]), float(sys.argv[3])
docs = esq.req('POST', '/%s/_search' % idx, {'size': 3000, 'query': {'function_score': {'random_score': {'seed': 5, 'field': '_seq_no'}}}})['hits']['hits']
conn = http.client.HTTPConnection('127.0.0.1', 19200, timeout=60)
t0 = time.perf_counter(); n = 0
while time.perf_counter() - t0 < dur:
    d = docs[n % len(docs)]
    conn.request('PUT', '/%s/_doc/%s' % (idx, d['_id']), json.dumps(d['_source']).encode(), {'content-type': 'application/json'})
    r = conn.getresponse(); r.read(); assert r.status in (200, 201), r.status
    n += 1
    delay = t0 + n / rate - time.perf_counter()
    if delay > 0: time.sleep(delay)
print(json.dumps({'writes': n, 'seconds': round(time.perf_counter() - t0, 1)}))

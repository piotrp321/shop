"""Waits for the _reindex tasks of mk.py (JSON on stdin), then refreshes the index and restores refresh_interval.
Prints products/s, the time and the size. usage: mk.py ... | wait.py"""
import json, os, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'performance-audit-2026-11-25', 'results', 'es'))
import esq  # noqa: E402

job = json.loads(sys.stdin.read())
t0 = time.time()
done, created, failures = set(), 0, []
while len(done) < len(job['tasks']):
    time.sleep(5)
    for t in job['tasks']:
        if t in done:
            continue
        r = esq.req('GET', '/_tasks/' + t)
        if r.get('completed'):
            done.add(t)
            resp = r.get('response', {})
            created += resp.get('created', 0)
            failures += resp.get('failures', [])
            if r.get('error'):
                failures.append(r['error'])
el = time.time() - t0
esq.req('POST', '/%s/_refresh' % job['index'], timeout=600)
esq.req('PUT', '/%s/_settings' % job['index'], {'index': {'refresh_interval': '1s'}})
st = esq.req('GET', '/%s/_stats/docs,store,segments' % job['index'])['_all']['primaries']
print(json.dumps({'index': job['index'], 'products': created, 'seconds': round(el), 'products_per_s': round(created / el),
                  'lucene_docs': st['docs']['count'], 'store_gb': round(st['store']['size_in_bytes'] / 2**30, 2),
                  'segments': st['segments']['count'], 'failures': failures[:3]}))

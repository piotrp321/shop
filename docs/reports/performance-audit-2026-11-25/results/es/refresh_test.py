"""Cost of the first listing query after a write + refresh (global ordinals rebuilt), with and without eager_global_ordinals.
usage: refresh_test.py <index> <kind> [eager]"""
import sys, json, time, esq, variants, statistics
idx, kind = sys.argv[1], sys.argv[2]
eager = len(sys.argv) > 3
b = variants.build(kind, esq.SCENARIOS['all_name'])
doc = esq.req('POST', '/%s/_search' % idx, {'size': 1, 'query': {'term': {'status': 'published'}}})['hits']['hits'][0]
def go():
    return esq.req('POST', '/%s/_search?request_cache=false' % idx, b)['took']
def gostats():
    s = esq.req('GET', '/%s/_stats/fielddata?fields=*' % idx)
    return s['_all']['total']['fielddata'].get('global_ordinals', {}).get('build_time_in_millis')
for _ in range(3): go()
warm = [go() for _ in range(5)]
first, refresh_ms = [], []
for i in range(5):
    src = dict(doc['_source']); src['version'] = src['version'] + 1000 + i
    esq.req('PUT', '/%s/_doc/%s?version=%d&version_type=external_gte' % (idx, doc['_id'], src['version']), src)
    t = time.perf_counter(); esq.req('POST', '/%s/_refresh' % idx); refresh_ms.append(round((time.perf_counter() - t) * 1000))
    g0 = gostats(); first.append(go()); g1 = gostats()
    print('after write+refresh: refresh %d ms, first query %d ms, global ordinals build +%s ms' % (refresh_ms[-1], first[-1], (g1 or 0) - (g0 or 0)), flush=True)
print(json.dumps({'index': idx, 'kind': kind, 'eager': eager, 'warm_median': statistics.median(warm), 'first_after_refresh_median': statistics.median(first), 'refresh_median': statistics.median(refresh_ms)}))

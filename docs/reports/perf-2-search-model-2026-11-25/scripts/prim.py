"""Cost of single aggregation primitives (took: median of 7 after 2 warm-ups, request_cache=false).
usage: prim.py <index> [category id]"""
import json, statistics, sys
import models as m
from models import esq
idx = sys.argv[1]
cat = sys.argv[2] if len(sys.argv) > 2 else None
opts = [b['key'] for b in esq.req('POST', '/%s/_search' % idx, {'size': 0, 'aggs': {'o': {'terms': {'field': 'facet_options', 'size': 2000}}}})['aggregations']['o']['buckets']]
pub = {'bool': {'filter': [{'term': {'status': 'published'}}] + ([{'term': {'category_ancestor_ids': cat}}] if cat else [])}}
import cpu as cg
def cpu():
    return cg.usage_ms()
def t(name, body, prof=False):
    for _ in range(2): esq.req('POST', '/%s/_search?request_cache=false' % idx, body, timeout=600)
    c0 = cpu()
    tk = [esq.req('POST', '/%s/_search?request_cache=false' % idx, body, timeout=600)['took'] for _ in range(7)]
    c1 = cpu()
    extra = ''
    if prof:
        r = esq.req('POST', '/%s/_search?request_cache=false' % idx, dict(body, profile=True), timeout=600)
        a = r['profile']['shards'][0]['aggregations'][0]
        d = a.get('debug', {})
        extra = a['type'].split('.')[-1] + ' ' + json.dumps({k: v for k, v in d.items() if k in ('delegate', 'collection_strategy', 'segments_counted', 'segments_collected')})
        dd = d.get('delegate_debug', d)
        if 'filters' in dd: extra += ' const_time_segments=%s' % sum(f.get('segments_counted_in_constant_time', 0) for f in dd['filters'])
    print('%-46s took %5.0f ms (%d-%d) cpu %6.1f ms %s' % (name, statistics.median(tk), min(tk), max(tk), (c1 - c0 - cg.OVERHEAD) / 7, extra), flush=True)
for qn, q in (('published', pub), ('match_all', {'match_all': {}})):
    if cat and qn == 'match_all': continue
    t(qn + ' count only', {'size': 0, 'track_total_hits': True, 'query': q})
    t(qn + ' hits name sort + total', {'size': 24, 'track_total_hits': True, 'query': q, 'sort': m.esq.sort_of('name')})
    t(qn + ' hits name sort, no total', {'size': 24, 'track_total_hits': False, 'query': q, 'sort': m.esq.sort_of('name')})
    t(qn + ' terms facet_options', {'size': 0, 'track_total_hits': False, 'query': q, 'aggs': {'a': {'terms': {'field': 'facet_options', 'size': 1000}}}}, True)
    t(qn + ' filters 208 x facet_options', {'size': 0, 'track_total_hits': False, 'query': q, 'aggs': {'a': {'filters': {'filters': {o: {'term': {'facet_options': o}} for o in opts}}}}}, True)
    t(qn + ' terms facet_axes', {'size': 0, 'track_total_hits': False, 'query': q, 'aggs': {'a': {'terms': {'field': 'facet_axes', 'size': 10}}}}, True)
    t(qn + ' filter purchasable', {'size': 0, 'track_total_hits': False, 'query': q, 'aggs': {'a': {'filter': {'term': {'purchasable': True}}}}}, True)
    t(qn + ' min from_gross', {'size': 0, 'track_total_hits': False, 'query': q, 'aggs': {'a': {'min': {'field': 'from_gross'}}}}, True)
    t(qn + ' min+max in one request', {'size': 0, 'track_total_hits': False, 'query': q, 'aggs': {'a': {'min': {'field': 'from_gross'}}, 'b': {'max': {'field': 'max_gross'}}}})
    t(qn + ' top1 sort from_gross asc', {'size': 1, '_source': False, 'track_total_hits': False, 'query': {'bool': {'filter': [q, {'term': {'purchasable': True}}]}}, 'sort': [{'from_gross': 'asc'}]})
    t(qn + ' top1 sort max_gross desc', {'size': 1, '_source': False, 'track_total_hits': False, 'query': {'bool': {'filter': [q, {'term': {'purchasable': True}}]}}, 'sort': [{'max_gross': 'desc'}]})
    cats = m.ROOTS if not cat else m.esq.children(cat)
    t(qn + ' filters categories', {'size': 0, 'track_total_hits': False, 'query': q, 'aggs': {'a': {'filters': {'filters': {k: {'term': {'category_ancestor_ids': k}} for k in cats}}}}}, True)
    if 'facets' in esq.req('GET', '/%s/_mapping' % idx)[idx]['mappings']['properties']:
        t(qn + ' terms facets (combined)', {'size': 0, 'track_total_hits': False, 'query': q, 'aggs': {'a': {'terms': {'field': 'facets', 'size': 2000}}}}, True)

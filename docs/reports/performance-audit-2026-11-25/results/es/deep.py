import esq, variants, copy, json
out = {}
c = esq.SCENARIOS['all_name']
for idx, kind in [('t_orig1', 'cur'), ('t_flat1', 'flat2'), ('t_flat4', 'flat2')]:
    for page in [1, 100, 400, 417]:
        b = variants.build(kind, c, page=page)
        out['%s %s p%d facets' % (idx, kind, page)] = esq.bench(b, idx)['median_took']
        h = copy.deepcopy(b); h.pop('aggs')
        out['%s p%d hits only tth=true' % (idx, page)] = esq.bench(h, idx)['median_took']
        h['track_total_hits'] = 10000
        out['%s p%d hits only tth=10000' % (idx, page)] = esq.bench(h, idx)['median_took']
    # search_after at the position of page 400
    h = copy.deepcopy(variants.build(kind, c, page=400)); h.pop('aggs')
    h['size'] = 1; h['from'] = 9575; h['track_total_hits'] = False
    sa = esq.req('POST', '/%s/_search' % idx, h)['hits']['hits'][0]['sort']
    h2 = copy.deepcopy(variants.build(kind, c, page=1)); h2.pop('aggs'); h2['search_after'] = sa; h2['track_total_hits'] = False
    out['%s search_after at 9576, no total' % idx] = esq.bench(h2, idx)['median_took']
    h2['track_total_hits'] = True
    out['%s search_after at 9576, total' % idx] = esq.bench(h2, idx)['median_took']
for k, v in out.items(): print(k, v)
json.dump(out, open('deep.json', 'w'), indent=1)

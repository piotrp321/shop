import json, esq, variants
rep = {}
for name, c in esq.SCENARIOS.items():
    ref_r = esq.req('POST', '/t_orig1/_search', variants.build('cur', c))
    ref = variants.facets('cur', c, ref_r)
    ref_ids = [h['_id'] for h in ref_r['hits']['hits']]
    row = {}
    for kind in ['B', 'Bp', 'flat']:
        r = esq.req('POST', '/t_flat1/_search', variants.build(kind, c))
        f = variants.facets(kind, c, r)
        diffs = [k for k in ref if ref[k] != f[k]]
        same_hits = ref_ids == [h['_id'] for h in r['hits']['hits']]
        row[kind] = {'identical_facets': not diffs, 'diff_keys': diffs, 'same_hits_page': same_hits}
        if diffs:
            for k in diffs: row[kind]['ref_'+k] = ref[k]; row[kind]['got_'+k] = f[k]
    rep[name] = row
    print(name, {k: (v['identical_facets'], v['same_hits_page'], v['diff_keys']) for k, v in row.items()})
json.dump(rep, open('semantics_compare.json', 'w'), indent=1, ensure_ascii=False)

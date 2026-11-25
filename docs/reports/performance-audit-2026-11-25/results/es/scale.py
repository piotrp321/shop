import esq, variants, json, time
out = {}
for idx, kind in [('t_orig1', 'cur'), ('t_orig1_x3', 'cur'), ('t_flat1', 'flat2'), ('t_flat1_x3', 'flat2')]:
    for s in ['all_name', 'root_name', 'leaf_name', 'phrase_kurtka', 'root_color', 'all_color_avail']:
        b = variants.build(kind, esq.SCENARIOS[s])
        first = esq.req('POST', '/%s/_search?request_cache=false' % idx, b)['took']
        r = esq.bench(b, idx)
        out['%s|%s|%s' % (idx, kind, s)] = {'first': first, **r}
        print(idx, kind, s, 'first', first, r, flush=True)
json.dump(out, open('scale.json', 'w'), indent=1)

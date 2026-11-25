import sys, json, esq
idx = sys.argv[1] if len(sys.argv) > 1 else 'products'
out = {}
for name, c in esq.SCENARIOS.items():
    out[name] = esq.bench(esq.body(c), idx)
    print(name, out[name], flush=True)
out['deep_p400'] = esq.bench(esq.body(esq.DEEP, page=400), idx); print('deep_p400', out['deep_p400'])
out['deep_p417_window_end'] = esq.bench(esq.body(esq.DEEP, page=418), idx); print('deep_p418', out['deep_p417_window_end'])
json.dump(out, open('baseline_%s.json' % idx, 'w'), indent=1)

import json, sys, esq, variants
combos = [('cur', 't_orig1'), ('cur', 't_orig4'), ('B', 't_flat1'), ('Bp', 't_flat1'), ('flat', 't_flat1'), ('flat', 't_flat4')]
scen = sys.argv[1].split(',') if len(sys.argv) > 1 else list(esq.SCENARIOS)
out = {}
for s in scen:
    c = esq.SCENARIOS[s]
    row = {}
    for kind, idx in combos:
        r = esq.bench(variants.build(kind, c), idx)
        row['%s@%s' % (kind, idx)] = '%d (%d-%d)' % (r['median_took'], r['min'], r['max'])
    out[s] = row
    print(s, row, flush=True)
json.dump(out, open('bench_variants_%s.json' % sys.argv[2] if len(sys.argv) > 2 else 'bench_variants.json', 'w'), indent=1)

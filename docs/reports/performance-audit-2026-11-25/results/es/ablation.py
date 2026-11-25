import sys, json, copy, esq
idx = sys.argv[1] if len(sys.argv) > 1 else 'products'
scen = sys.argv[2] if len(sys.argv) > 2 else 'all_name'
c = esq.SCENARIOS[scen]
full = esq.body(c)
res = {}
def run(name, b):
    res[name] = esq.bench(b, idx); print(name, res[name], flush=True)
run('full', full)
b = copy.deepcopy(full); b.pop('aggs'); run('hits_only_tth_true', b)
b2 = copy.deepcopy(b); b2['track_total_hits'] = False; run('hits_only_tth_false', b2)
b3 = copy.deepcopy(b); b3['size']=0; b3.pop('sort'); run('count_only', b3)
for agg in full['aggs']:
    b = copy.deepcopy(full); b['aggs'] = {agg: full['aggs'][agg]}; b['size'] = 0; b.pop('sort'); run('agg_only_' + agg, b)
b = copy.deepcopy(full); b['size']=0; b.pop('sort'); run('all_aggs_size0', b)
json.dump(res, open('ablation_%s_%s.json' % (idx, scen), 'w'), indent=1)

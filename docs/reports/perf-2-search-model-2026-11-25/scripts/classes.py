"""CPU and time per class of the mix: every address of the class once, one after another (after one warm-up pass).
usage: classes.py <index> <kind>"""
import collections, http.client, json, os, sys, time
import bench, cpu as cg
idx, kind = sys.argv[1], sys.argv[2]
groups = collections.defaultdict(list)
for x in bench.mix():
    groups[x['class']].append(bench.payload(idx, kind, x['c'], x['page']))
conn = http.client.HTTPConnection(bench.HOST, bench.PORT, timeout=600)
tot_cpu = tot_n = 0
for cls in ['all', 'all_filtered', 'root', 'root_filtered', 'mid', 'mid_filtered', 'leaf', 'leaf_filtered', 'phrase', 'phrase_filtered', 'deep']:
    ps = groups[cls]
    for p in ps[:5]: bench.send(conn, p)
    c0 = cg.usage_ms(); took = []
    for p in ps:
        ok, t, wall = bench.send(conn, p); assert ok; took.append(wall)
    cpu = cg.usage_ms() - c0 - cg.OVERHEAD
    took.sort()
    row = {'label': bench.LABEL, 'test': 'class', 'index': idx, 'kind': kind, 'class': cls, 'n': len(ps), 'cpu_ms': round(cpu / len(ps), 1),
           'wall_p50': round(took[len(took) // 2]), 'wall_max': round(took[-1]), 'share_of_mix_pct': round(100 * len(ps) / 294, 1)}
    tot_cpu += cpu; tot_n += len(ps)
    print(json.dumps(row), flush=True)
    open(bench.OUT, 'a').write(json.dumps(row) + '\n')
print(json.dumps({'kind': kind, 'mix_cpu_ms': round(tot_cpu / tot_n, 1)}))

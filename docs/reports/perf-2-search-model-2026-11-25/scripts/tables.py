"""Markdown tables of the report from ../results/bench.jsonl."""
import json, os
R = [json.loads(l) for l in open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'results', 'bench.jsonl'))]
def sel(test, label, kind=None): return [r for r in R if r['test'] == test and r.get('label') == label and (kind is None or r.get('kind') == kind)]
print('### single (took / CPU ms)\n')
cols = [('cur', '10m', 'cur'), ('flat3', '10m', 'flat3'), ('pl', '10m', 'pl'), ('plm', '10m', 'plm'), ('plm r10', 'r10', 'plm')]
by = {(c[0], r['scenario']): r for c in cols for r in sel('single', c[1], c[2])}
print('| scenariusz | ' + ' | '.join(c[0] for c in cols) + ' |'); print('|---|' + '---:|' * len(cols))
for s in dict.fromkeys(r['scenario'] for r in sel('single', '10m', 'cur')):
    print('| `%s` | ' % s + ' | '.join('%d / %d' % (by[(c[0], s)]['took'], round(by[(c[0], s)]['cpu_ms'])) for c in cols) + ' |')
print('\n### classes (CPU ms per listing)\n')
cols = [('cur', '10m-class', 'cur'), ('flat3', '10m-class', 'flat3'), ('pl', '10m-class', 'pl'), ('plm', '10m-class2', 'plm'), ('plm sort main', '10m-sort', 'plm'), ('plm r10', 'r10', 'plm')]
by = {(c[0], r['class']): r for c in cols for r in sel('class', c[1], c[2])}
print('| klasa | udział | ' + ' | '.join(c[0] for c in cols) + ' |'); print('|---|---:|' + '---:|' * len(cols))
tot = {c[0]: 0 for c in cols}
for s in dict.fromkeys(r['class'] for r in sel('class', '10m-class', 'cur')):
    n = by[('cur', s)]['n']
    for c in cols: tot[c[0]] += by[(c[0], s)]['cpu_ms'] * n
    print('| %s | %.1f%% | ' % (s, 100 * n / 294) + ' | '.join('%d' % round(by[(c[0], s)]['cpu_ms']) for c in cols) + ' |')
print('| **mieszanka** | 100% | ' + ' | '.join('**%d**' % round(tot[c[0]] / 294) for c in cols) + ' |')
print('\n### conc\n')
print('| wariant | N | zapytań/s | p50 | p95 | p99 | CPU ms/zap. | rdzenie |'); print('|---|---:|---:|---:|---:|---:|---:|---:|')
for name, label, kind in [('cur', '10m-cpu8', 'cur'), ('flat3', '10m-cpu8', 'flat3'), ('pl', '10m-cpu8', 'pl'), ('plm', '10m-cpu8', 'plm'), ('plm, bez równoległości segmentów', '10m-nopar', 'plm'), ('plm, 4 shardy', '10m-s4', 'plm'), ('plm r10', 'r10', 'plm'), ('plm r10, 3 CPU', 'r10-cpu3', 'plm'), ('cur, 3 CPU', 'c10-cpu3', 'cur')]:
    for r in sel('conc', label, kind):
        print('| %s | %d | %s | %s | %s | %s | %d | %s |' % (name, r['n'], r['qps'], r['p50'], r['p95'], r['p99'], round(r['cpu_ms']), r['cpu_cores']))
print('\n### burst 100\n')
print('| wariant | rundy: najwolniejsza odpowiedź ms | p50 ms | CPU ms/zap. | błędy |'); print('|---|---|---|---|---:|')
for name, label, kind in [('cur', '10m-cpu8', 'cur'), ('flat3', '10m-cpu8', 'flat3'), ('pl', '10m-cpu8', 'pl'), ('plm', '10m-cpu8', 'plm'), ('plm, 4 shardy', '10m-s4', 'plm'), ('plm r10', 'r10', 'plm'), ('plm r10, 3 CPU', 'r10-cpu3', 'plm'), ('cur, 3 CPU', 'c10-cpu3', 'cur')]:
    rs = sel('burst', label, kind)
    print('| %s | %s | %s | %s | %d |' % (name, ' / '.join(str(r['slowest']) for r in rs), ' / '.join(str(r['p50']) for r in rs), ' / '.join(str(round(r['cpu_ms'])) for r in rs), sum(r['errors'] for r in rs)))
print('\n### open\n')
for r in [x for x in R if x['test'] == 'open']:
    print('| %s | %s/s | %d | %d | %s | %s | %s | %s | %d | %s |' % (r['label'], r['rate'], r['ok'], r['errors'] + r['late_starts'], r['p50'], r['p95'], r['p99'], r['max'], round(r['cpu_ms']), r['cpu_cores']))
print('\n### writes / cache\n')
for r in R:
    if r['test'] == 'conc' and ('w50' in r['label'] or 'tok' in r['label']):
        print('| %s | %s | %s | %s | %s | %s | %d |' % (r['label'], r['kind'], r['qps'], r['p50'], r['p95'], r['p99'], round(r['cpu_ms'])))

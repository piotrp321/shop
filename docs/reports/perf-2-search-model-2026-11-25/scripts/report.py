"""Tables from ../results/bench.jsonl. usage: report.py single <label> | conc <label> | burst <label> | open <label>"""
import json, os, sys, collections
rows = [json.loads(l) for l in open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'results', 'bench.jsonl'))]
what, label = sys.argv[1], sys.argv[2]
rows = [r for r in rows if r['test'] == what and r['label'] == label]
if what == 'single':
    kinds = list(dict.fromkeys((r['index'], r['kind']) for r in rows))
    by = {(r['index'], r['kind'], r['scenario']): r for r in rows}
    print('| scenariusz | ' + ' | '.join('%s %s: took / CPU ms' % k for k in kinds) + ' |')
    print('|---|' + '---|' * len(kinds))
    for s in dict.fromkeys(r['scenario'] for r in rows):
        print('| %s | ' % s + ' | '.join(('%d / %d' % (by[k + (s,)]['took'], by[k + (s,)]['cpu_ms'])) if k + (s,) in by else '–' for k in kinds) + ' |')
else:
    for r in rows:
        print(json.dumps({k: v for k, v in r.items() if k not in ('label', 'test')}))

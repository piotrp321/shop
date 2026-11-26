"""Automatic equivalence check: every scenario of models.ALL, candidate against the current model.
Compares the total, the category, availability, price and option facets (as the API shows them) and the ids of the page.
usage: check.py <ref index> <index> <kind,...> [out.json]   kind: flat3 | pl | pl-split"""
import json, os, sys
import models as m
from models import esq

ref_idx, idx, kinds = sys.argv[1], sys.argv[2], sys.argv[3].split(',')
out = sys.argv[4] if len(sys.argv) > 4 else None


def run(kind, index, c, page):
    if kind == 'pl-split':
        rs = [esq.req('POST', '/%s/_search' % index, b, timeout=600) for b in m.pl_split(c, page)]
        return m.split_facets(c, rs), [x['_id'] for x in rs[0]['hits']['hits']]
    if kind == 'plm' and not c.get('phrase'):
        parts = m.plm(c, page)
        rs = [esq.req('POST', '/%s/_search' % index, b, timeout=600) for _, b in parts]
        return m.plm_facets(c, parts, rs), [x['_id'] for x in rs[0]['hits']['hits']]
    kind = 'pl' if kind == 'plm' else kind
    r = esq.req('POST', '/%s/_search' % index, m.build(kind, c, page), timeout=600)
    return m.facets(kind, c, r), [x['_id'] for x in r['hits']['hits']]


rep, bad = {}, 0
MIX = os.environ.get('MIX')
cases = [('mix_%03d_%s' % (i, x['class']), x['c'], x['page']) for i, x in enumerate(json.load(open(os.path.join(m.HERE, '..', 'results', 'mix.json'))))] if MIX else [(n,) + m.scenario(n) for n in m.ALL]
for name, c, page in cases:
    ref, ref_ids = run('cur', ref_idx, c, page)
    row = {'total': ref['total']}
    for kind in kinds:
        f, ids = run(kind, idx, c, page)
        diffs = [k for k in ref if ref[k] != f[k]]
        row[kind] = {'facets': not diffs, 'page': ref_ids == ids}
        if diffs:
            row[kind]['diff'] = {k: {'ref': ref[k], 'got': f[k]} for k in diffs}
        if diffs or ref_ids != ids:
            bad += 1
    rep[name] = row
    print('%-22s total=%-9d axes=%-2d %s' % (name, ref['total'], len(ref['axes']), ' '.join(
        '%s:%s%s' % (k, 'OK' if row[k]['facets'] and row[k]['page'] else 'DIFF', '' if row[k]['facets'] else str(list(row[k]['diff']))) for k in kinds)), flush=True)
print('scenarios', len(cases), 'kinds', len(kinds), 'mismatches', bad)
if out:
    json.dump(rep, open(out, 'w'), indent=1, ensure_ascii=False)
sys.exit(1 if bad else 0)

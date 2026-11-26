"""Candidate "variant as a document" (index of mkv.py): cost and accuracy against the exact facets of the pl model.
Products are counted with `cardinality` of product_id (HyperLogLog++, precision_threshold 40000, the maximum), the
page of products with `collapse`. Listings without variant conditions use the tokens of the first variant (exact).
usage: vd.py <variant index> <h index> [scenario,...]"""
import json, statistics, sys
import models as m, cpu as cg
from models import esq, US

vidx, hidx = sys.argv[1], sys.argv[2]
names = sys.argv[3].split(',') if len(sys.argv) > 3 else ['all_name', 'root_name', 'all_color', 'root_color', 'all_price', 'root_price', 'all_color_price', 'root_color_price', 'all_avail', 'all_color2_size']
CARD = {'p': {'cardinality': {'field': 'product_id', 'precision_threshold': 40000}}}


def conds(c, except_axis=None, with_price=True, force_available=False):
    out = [{'terms': {'options': [a + US + v for v in vals]}} for a, vals in c.get('options', {}).items() if a != except_axis]
    priced = with_price and (c.get('pmin') is not None or c.get('pmax') is not None)
    if priced: out.append({'range': {'gross': m.price_range(c)}})
    if priced or force_available or c.get('available'): out.append({'term': {'purchasable': True}})
    return out


def body(c):
    q = esq.query(c)
    allc = conds(c)
    b = {'query': q, 'sort': [{'name.sort': 'asc'}, {'product_id': 'asc'}], 'size': 24, 'track_total_hits': False}
    if not allc:
        q['bool']['filter'].append({'term': {'first': True}})
        b['track_total_hits'] = True
        b['aggs'] = {'tok': {'terms': {'field': 'facets', 'size': m.TOK_SIZE}}}
        return b
    b['collapse'] = {'field': 'product_id'}
    b['post_filter'] = {'bool': {'filter': allc}}
    f = lambda cs, aggs: {'filter': esq.filter_of(cs), 'aggs': aggs}
    sel = list(c.get('options', {}))
    a = {'total': f(allc, CARD),
         'categories': f(allc, {'ids': {'filters': {'filters': {k: {'term': {'category_ancestor_ids': k}} for k in c.get('facet_cats', [])}}, 'aggs': CARD}}),
         'available': f(conds(c, force_available=True), CARD),
         'price': f(conds(c, with_price=False, force_available=True), {'min': {'min': {'field': 'gross'}}, 'max': {'max': {'field': 'gross'}}}),
         'free': f(allc, {'axes': {'terms': {'field': 'axes', 'size': 30, **({'exclude': sel} if sel else {})}, 'aggs': CARD},
                          'opts': {'terms': {'field': 'options', 'size': 1000, **({'exclude': '(' + '|'.join(m.lre(s) for s in sel) + ')' + m.lre(US) + '.*'} if sel else {})}, 'aggs': CARD}})}
    for i, axis in enumerate(sel):
        a['selected_%d' % i] = f(conds(c, except_axis=axis), {'top': {'terms': {'field': 'options', 'include': m.lre(axis + US) + '.*', 'size': 1000}, 'aggs': CARD}})
    b['aggs'] = a
    return b


def norm(c, r):
    ag = r['aggregations']
    if 'tok' in ag:
        return m.facets('pl', c, r)
    out = {'total': ag['total']['p']['value'], 'available': ag['available']['p']['value'], 'price': (ag['price']['min']['value'], ag['price']['max']['value']),
           'categories': {k: v['p']['value'] for k, v in ag['categories']['ids']['buckets'].items() if v['p']['value']}, 'axes': {}, 'values': {}}
    axes = {b['key']: b['p']['value'] for b in ag['free']['axes']['buckets']}
    vals = {}
    for b in ag['free']['opts']['buckets']:
        ax, v = b['key'].split(US, 1); vals.setdefault(ax, {})[v] = b['p']['value']
    for ax, n in sorted(axes.items(), key=lambda kv: (-kv[1], kv[0]))[:esq.MAX_AXES]:
        out['axes'][ax] = n; out['values'][ax] = vals.get(ax, {})
    for i, axis in enumerate(c.get('options', {})):
        out['axes'][axis] = out['total']
        out['values'][axis] = {b['key'].split(US, 1)[1]: b['p']['value'] for b in ag['selected_%d' % i]['top']['buckets']}
    return m._top(out, c)


def flat(f):
    d = {'total': f['total'], 'available': f['available']}
    d.update({'cat:' + k: v for k, v in f['categories'].items()}); d.update({'axis:' + k: v for k, v in f['axes'].items()})
    d.update({'val:%s=%s' % (a, v): n for a, vs in f['values'].items() for v, n in vs.items()})
    return d


for name in names:
    c, page = m.scenario(name)
    b = body(c)
    for _ in range(2): esq.req('POST', '/%s/_search?request_cache=false' % vidx, b, timeout=600)
    c0 = cg.usage_ms(); tk = []
    for _ in range(5):
        r = esq.req('POST', '/%s/_search?request_cache=false' % vidx, b, timeout=600); tk.append(r['took'])
    cpu = (cg.usage_ms() - c0 - cg.OVERHEAD) / 5
    got = norm(c, r)
    ref_r = esq.req('POST', '/%s/_search' % hidx, m.build('pl', c, page), timeout=600)
    ref = m.facets('pl', c, ref_r)
    fg, fr = flat(got), flat(ref)
    wrong = {k: (fr.get(k), fg.get(k)) for k in set(fg) | set(fr) if fg.get(k) != fr.get(k)}
    rel = max((abs((g or 0) - (e or 0)) / max(1, e or 0) for e, g in wrong.values()), default=0)
    same_page = [h['_source']['product_id'] if '_source' in h else None for h in r['hits']['hits']] == [h['_id'] for h in ref_r['hits']['hits']]
    row = {'test': 'vd', 'index': vidx, 'scenario': name, 'took': statistics.median(tk), 'cpu_ms': round(cpu, 1), 'numbers': len(fr), 'wrong_numbers': len(wrong),
           'max_rel_error_pct': round(100 * rel, 2), 'total_exact': ref['total'], 'total_got': got['total'], 'price_equal': tuple(got['price']) == tuple(ref['price']), 'same_page': same_page}
    print(json.dumps(row), flush=True)
    open(m.os.path.join(m.HERE, '..', 'results', 'vd.jsonl'), 'a').write(json.dumps(row) + '\n')

"""Alternative listing request bodies, same criteria as esq.SCENARIOS.
cur   - current query (esq.body), needs the original mapping (nested pairs)
B     - one nested level: facets from variants.options / variants.axes with reverse_nested (no variants.pairs)
Bp    - B, and every nested facet aggregation first narrows the products with a nested query of its conditions
flat  - hybrid: no variant conditions -> product-level fields only (facet_axes, facet_options, purchasable,
        from_gross, max_gross), no nested aggregation at all; with conditions -> Bp
B and flat need the t_flat* indices (mapping from mk_indices.py)."""
import re, copy, esq

SEP = esq.SEP
OPT_SIZE = 1000  # every value of every axis in the result set; grouped to 10 axes x 30 values in PHP


def _rev():
    return {'product': {'reverse_nested': {}}}


def _by_products():
    return [{'product': 'desc'}, {'_key': 'asc'}]


def _nested_agg(conds, aggs, prefilter):
    inner = esq.nested_agg(conds, aggs)
    if prefilter and conds:
        return {'filter': esq.nested(conds), 'aggs': {'n': inner}}
    return inner


def _b_aggs(c, prefilter):
    a = {}
    fc = c.get('facet_cats', [])
    conds = esq.conditions(c)
    if fc:
        a['categories'] = {'filter': {'match_all': {}} if not conds else esq.nested(conds),
                           'aggs': {'ids': {'terms': {'field': 'category_ancestor_ids', 'include': fc, 'size': len(fc)}}}}
    a['available'] = _nested_agg(esq.conditions(c, force_available=True), _rev(), prefilter)
    a['price'] = _nested_agg(esq.conditions(c, with_price=False, force_available=True),
                             {'min': {'min': {'field': 'variants.gross'}}, 'max': {'max': {'field': 'variants.gross'}}}, prefilter)
    selected = list(c.get('options', {}).keys())
    axes = {'terms': {'field': 'variants.axes', 'size': esq.MAX_AXES, 'order': _by_products()}, 'aggs': _rev()}
    opts = {'terms': {'field': 'variants.options', 'size': OPT_SIZE, 'order': _by_products()}, 'aggs': _rev()}
    if selected:
        axes['terms']['exclude'] = selected
        opts['terms']['exclude'] = '(' + '|'.join(re.escape(s) for s in selected) + ')' + SEP + '.*'
    a['free'] = _nested_agg(conds, {'axes': axes, 'opts': opts}, prefilter)
    for pos, axis in enumerate(selected):
        a['selected_%d' % pos] = _nested_agg(esq.conditions(c, except_axis=axis), {
            'top': {'terms': {'field': 'variants.options', 'include': re.escape(axis) + SEP + '.*', 'size': esq.MAX_VALUES, 'order': _by_products()}, 'aggs': _rev()},
            'chosen': {'terms': {'field': 'variants.options', 'include': [axis + SEP + v for v in c['options'][axis]], 'size': len(c['options'][axis])}, 'aggs': _rev()},
        }, prefilter)
    return a


def _flat_aggs(c):
    a = {}
    fc = c.get('facet_cats', [])
    if fc:
        a['categories'] = {'terms': {'field': 'category_ancestor_ids', 'include': fc, 'size': len(fc)}}
    a['available'] = {'filter': {'term': {'purchasable': True}}}
    a['price'] = {'filter': {'term': {'purchasable': True}}, 'aggs': {'min': {'min': {'field': 'from_gross'}}, 'max': {'max': {'field': 'max_gross'}}}}
    a['axes'] = {'terms': {'field': 'facet_axes', 'size': esq.MAX_AXES}}
    a['opts'] = {'terms': {'field': 'facet_options', 'size': OPT_SIZE}}
    return a


def build(kind, c, page=1, per_page=24):
    if kind == 'cur':
        return esq.body(c, page, per_page)
    b = esq.body(dict(c, facet_cats=[], options={}), page, per_page)  # query, sort, paging
    b.pop('post_filter', None)
    conds = esq.conditions(c)
    if conds:
        b['post_filter'] = esq.nested(conds)
    if kind == 'flat' and not conds:
        b['aggs'] = _flat_aggs(c)
    else:
        b['aggs'] = _b_aggs(c, prefilter=(kind in ('Bp', 'flat')))
    return b


# ---- normalisation of facet results to compare semantics ----------------------------------
def _m(x, *path):
    for p in path:
        x = x[p]
    return x


def _matching(a):
    return a['n']['matching'] if 'n' in a else a['matching']


def facets(kind, c, resp):
    """-> dict(total, categories{id:n}, available, price(min,max), axes{axis:n}, values{axis:{v:n}}) as the API would show"""
    ag = resp['aggregations']
    total = resp['hits']['total']['value']
    out = {'total': total, 'categories': {}, 'axes': {}, 'values': {}}
    if 'categories' in ag:
        cb = ag['categories']['ids']['buckets'] if 'ids' in ag['categories'] else ag['categories']['buckets']
        out['categories'] = {b['key']: b['doc_count'] for b in cb}
    selected = list(c.get('options', {}).keys())
    if kind == 'cur':
        out['available'] = ag['available']['matching']['product']['doc_count']
        pm = ag['price']['matching']
        out['price'] = (pm['min']['value'], pm['max']['value'])
        for b in ag['free']['matching']['pairs']['axes']['buckets']:
            out['axes'][b['key']] = b['product']['doc_count']
            out['values'][b['key']] = {v['key']: v['product']['doc_count'] for v in b['values']['buckets']}
        for pos, axis in enumerate(selected):
            m = ag['selected_%d' % pos]['matching']['pairs']['axis']
            vals = {v['key']: v['product']['doc_count'] for v in m['top']['buckets'] + m['chosen']['buckets']}
            out['axes'][axis] = total; out['values'][axis] = vals
    elif kind == 'flat' and not esq.conditions(c):
        out['available'] = ag['available']['doc_count']
        out['price'] = (ag['price']['min']['value'], ag['price']['max']['value'])
        for b in ag['axes']['buckets']:
            out['axes'][b['key']] = b['doc_count']
        for b in ag['opts']['buckets']:
            ax, v = b['key'].split(SEP, 1)
            if ax in out['axes']:
                out['values'].setdefault(ax, {})[v] = b['doc_count']
    else:
        out['available'] = _matching(ag['available'])['product']['doc_count']
        pm = _matching(ag['price'])
        out['price'] = (pm['min']['value'], pm['max']['value'])
        fm = _matching(ag['free'])
        for b in fm['axes']['buckets']:
            out['axes'][b['key']] = b['product']['doc_count']
        for b in fm['opts']['buckets']:
            ax, v = b['key'].split(SEP, 1)
            if ax in out['axes']:
                out['values'].setdefault(ax, {})[v] = b['product']['doc_count']
        for pos, axis in enumerate(selected):
            m = _matching(ag['selected_%d' % pos])
            vals = {b['key'].split(SEP, 1)[1]: b['product']['doc_count'] for b in m['top']['buckets'] + m['chosen']['buckets']}
            out['axes'][axis] = total; out['values'][axis] = vals
    # what the API shows: top 30 values per axis by count desc, name asc (+ selected ones)
    for ax, vals in out['values'].items():
        keep = sorted(vals.items(), key=lambda kv: (-kv[1], kv[0]))[:esq.MAX_VALUES]
        sel = c.get('options', {}).get(ax, [])
        keep += [(v, vals.get(v, 0)) for v in sel if v not in dict(keep)]
        out['values'][ax] = dict(keep)
    return out


# flat2: flat with the category facet as a `filters` aggregation (one term filter per facet category) instead of
# `terms` with `include` (measured 60 ms -> 10 ms on 1M products, 1 shard)
_build_orig = build


def build(kind, c, page=1, per_page=24):
    if kind != 'flat2':
        return _build_orig(kind, c, page, per_page)
    b = _build_orig('flat', c, page, per_page)
    fc = c.get('facet_cats', [])
    if fc:
        inner = {'filters': {'filters': {k: {'term': {'category_ancestor_ids': k}} for k in fc}}}
        if 'categories' in b['aggs'] and 'filter' in b['aggs']['categories']:
            b['aggs']['categories']['aggs'] = {'ids': inner}
        else:
            b['aggs']['categories'] = inner
    return b


# flat3: per facet aggregation - when the aggregation's own condition set is empty, the product-level fields
# (exact: no variant condition to co-locate), otherwise one nested level with a parent-level prefilter (Bp).
_build2 = build


def build(kind, c, page=1, per_page=24):
    if kind != 'flat3':
        return _build2(kind, c, page, per_page)
    b = _build2('flat2', c, page, per_page)
    if not esq.conditions(c):
        return b
    ag = b['aggs']
    if not esq.conditions(c, force_available=True):
        ag['available'] = {'filter': {'term': {'purchasable': True}}}
    for pos, axis in enumerate(c.get('options', {})):
        if not esq.conditions(c, except_axis=axis):
            ag['selected_%d' % pos] = {
                'top': {'terms': {'field': 'facet_options', 'include': re.escape(axis) + SEP + '.*', 'size': esq.MAX_VALUES}},
                'chosen': {'terms': {'field': 'facet_options', 'include': [axis + SEP + v for v in c['options'][axis]], 'size': len(c['options'][axis])}}}
            ag['selected_%d' % pos] = {'filter': {'match_all': {}}, 'aggs': ag['selected_%d' % pos]}
    return b

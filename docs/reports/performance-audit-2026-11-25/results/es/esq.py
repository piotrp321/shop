"""Faithful Python port of apps/api/src/Catalog/Infrastructure/Search/ProductSearchQuery.php (main 2b4258c7)
plus scenario definitions and timing helpers. Variants of the query (flattened facets etc.) live in variants.py."""
import json, os, time, statistics, urllib.request

ES = os.environ.get('ES', 'http://127.0.0.1:19200')
HERE = os.path.dirname(os.path.abspath(__file__))
SEP = '\x1f'
MAX_AXES, MAX_VALUES = 10, 30
BY_PRODUCTS = [{'product': 'desc'}, {'_key': 'asc'}]


def req(method, path, body=None, timeout=120):
    data = None if body is None else json.dumps(body).encode()
    r = urllib.request.Request(ES + path, data=data, method=method, headers={'content-type': 'application/json'})
    with urllib.request.urlopen(r, timeout=timeout) as f:
        return json.loads(f.read())


def phrase_q(p):
    return {'bool': {'minimum_should_match': 1, 'should': [
        {'multi_match': {'query': p, 'type': 'cross_fields', 'operator': 'and',
                         'fields': ['name^3', 'name.folded^3', 'category_names^2', 'category_names.folded^2', 'short_description', 'short_description.folded']}},
        {'multi_match': {'query': p, 'type': 'most_fields', 'operator': 'and', 'fuzziness': 'AUTO', 'prefix_length': 1,
                         'max_expansions': 20, 'boost': 0.3, 'fields': ['name.folded^3', 'category_names.folded^2', 'short_description.folded']}}]}}


def sort_of(s):
    return {
        'relevance': [{'_score': 'desc'}, {'name.sort': 'asc'}, {'id': 'asc'}],
        'name': [{'name.sort': 'asc'}, {'id': 'asc'}],
        'price_asc': [{'from_gross': {'order': 'asc', 'missing': '_last'}}, {'name.sort': 'asc'}, {'id': 'asc'}],
        'price_desc': [{'from_gross': {'order': 'desc', 'missing': '_last'}}, {'name.sort': 'asc'}, {'id': 'asc'}],
    }[s]


def conditions(c, except_axis=None, with_price=True, force_available=False):
    out = []
    for axis, values in c.get('options', {}).items():
        if axis != except_axis:
            out.append({'terms': {'variants.options': [axis + SEP + v for v in values]}})
    buyable = force_available or c.get('available', False)
    priced = with_price and (c.get('pmin') is not None or c.get('pmax') is not None)
    if priced:
        rng = {}
        if c.get('pmin') is not None: rng['gte'] = c['pmin']
        if c.get('pmax') is not None: rng['lte'] = c['pmax']
        out.append({'range': {'variants.gross': rng}})
    if buyable or priced:
        out.append({'term': {'variants.purchasable': True}})
    return out


def filter_of(conds):
    return {'match_all': {}} if not conds else {'bool': {'filter': conds}}


def nested(conds):
    return {'nested': {'path': 'variants', 'query': {'bool': {'filter': conds}}}}


def nested_agg(conds, aggs):
    return {'nested': {'path': 'variants'}, 'aggs': {'matching': {'filter': filter_of(conds), 'aggs': aggs}}}


def aggregations(c):
    a = {}
    fc = c.get('facet_cats', [])
    if fc:
        conds = conditions(c)
        a['categories'] = {'filter': {'match_all': {}} if not conds else nested(conds),
                           'aggs': {'ids': {'terms': {'field': 'category_ancestor_ids', 'include': fc, 'size': len(fc)}}}}
    a['available'] = nested_agg(conditions(c, force_available=True), {'product': {'reverse_nested': {}}})
    a['price'] = nested_agg(conditions(c, with_price=False, force_available=True),
                            {'min': {'min': {'field': 'variants.gross'}}, 'max': {'max': {'field': 'variants.gross'}}})
    selected = list(c.get('options', {}).keys())
    axes = {'terms': {'field': 'variants.pairs.axis', 'size': MAX_AXES, 'order': BY_PRODUCTS},
            'aggs': {'product': {'reverse_nested': {}},
                     'values': {'terms': {'field': 'variants.pairs.value', 'size': MAX_VALUES, 'order': BY_PRODUCTS},
                                'aggs': {'product': {'reverse_nested': {}}}}}}
    if selected:
        axes['terms']['exclude'] = selected
    a['free'] = nested_agg(conditions(c), {'pairs': {'nested': {'path': 'variants.pairs'}, 'aggs': {'axes': axes}}})
    for pos, axis in enumerate(selected):
        a['selected_%d' % pos] = nested_agg(conditions(c, except_axis=axis), {'pairs': {
            'nested': {'path': 'variants.pairs'},
            'aggs': {'axis': {'filter': {'term': {'variants.pairs.axis': axis}}, 'aggs': {
                'top': {'terms': {'field': 'variants.pairs.value', 'size': MAX_VALUES, 'order': BY_PRODUCTS},
                        'aggs': {'product': {'reverse_nested': {}}}},
                'chosen': {'terms': {'field': 'variants.pairs.value', 'include': c['options'][axis], 'size': len(c['options'][axis])},
                           'aggs': {'product': {'reverse_nested': {}}}}}}}}})
    return a


def query(c):
    b = {'filter': [{'term': {'status': 'published'}}]}
    if c.get('category'):
        b['filter'].append({'term': {'category_ancestor_ids': c['category']}})
    if c.get('phrase'):
        b['must'] = [phrase_q(c['phrase'])]
    return {'bool': b}


def body(c, page=1, per_page=24, window=10000):
    frm = (page - 1) * per_page
    frm, size = (0, 0) if frm >= window else (frm, min(per_page, window - frm))
    sort = c.get('sort') or ('relevance' if c.get('phrase') else 'name')
    b = {'query': query(c), 'sort': sort_of(sort), 'track_total_hits': True, 'from': frm, 'size': size, 'aggs': aggregations(c)}
    conds = conditions(c)
    if conds:
        b['post_filter'] = nested(conds)
    return b


# ---- scenarios -------------------------------------------------------------
TREE = json.load(open(os.path.join(HERE, 'tree.json')))
ROOTS = TREE['roots']
CNT = TREE['cnt']


def children(cat):
    """children of cat: categories whose ordered path has cat right before them"""
    out = set()
    for leaf, (ids, anc, names) in TREE['paths'].items():
        if len(ids) != 1: continue
        path = sorted(anc, key=lambda x: -CNT[x])
        if cat in path:
            i = path.index(cat)
            if i + 1 < len(path): out.add(path[i + 1])
    return sorted(out, key=lambda x: -CNT[x])


ROOT = ROOTS[0]  # largest root (Turystyka, ~207k)
LEAF = max((l for l, v in TREE['paths'].items() if len(v[0]) == 1), key=lambda l: CNT[l])

SCENARIOS = {
    'all_name': dict(facet_cats=ROOTS),
    'all_price_asc': dict(facet_cats=ROOTS, sort='price_asc'),
    'root_name': dict(category=ROOT, facet_cats=children(ROOT)),
    'leaf_name': dict(category=LEAF, facet_cats=children(LEAF)),
    'phrase_kurtka': dict(phrase='kurtka', facet_cats=ROOTS),
    'phrase_typo': dict(phrase='spiwor puchowy', facet_cats=ROOTS),
    'root_color': dict(category=ROOT, facet_cats=children(ROOT), options={'Kolor': ['czarne']}),
    'root_color_size': dict(category=ROOT, facet_cats=children(ROOT), options={'Kolor': ['czarne', 'czarny'], 'Rozmiar': ['M']}),
    'root_price': dict(category=ROOT, facet_cats=children(ROOT), pmin=10000, pmax=30000),
    'all_color_avail': dict(facet_cats=ROOTS, options={'Kolor': ['czarne']}, available=True),
}
DEEP = dict(facet_cats=ROOTS)  # page 400 of the whole catalog


def timed(b, index='products', params='request_cache=false'):
    t = time.perf_counter()
    r = req('POST', '/%s/_search?%s' % (index, params), b)
    return r, (time.perf_counter() - t) * 1000


def bench(b, index='products', warm=3, n=9, params='request_cache=false'):
    for _ in range(warm):
        timed(b, index, params)
    took, wall = [], []
    for _ in range(n):
        r, w = timed(b, index, params)
        took.append(r['took']); wall.append(w)
    return {'median_took': statistics.median(took), 'min': min(took), 'max': max(took),
            'p_wall': round(statistics.median(wall), 1), 'total': (r['hits'].get('total') or {}).get('value')}

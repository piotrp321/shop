"""Listing request bodies of the candidate models of PERF-2 stage A and the normalisation of their facets to what
the API shows, so every model is compared with the current one on the same criteria.

Models (index kind in brackets, see mk.py):
  cur    [cur] the current query: esq.body, a 1:1 port of ProductSearchQuery.php
  flat3  [h]   the alternative of the audit: product fields when an aggregation has no variant condition, one nested
               level otherwise
  pl     [h]   product-level tokens: every condition without a price range is a term query on a product field
               (one option: facet_options, two options of one variant: pair_any, purchasable variants: buy_*,
               pair_buy), so filters and facets need no join; nested only for a price range combined with options
               and for a facet conditioned on several values of one axis (exact de-duplication per product)
  pl with split=True: the same, as two requests: hits (no aggregations) and facets (size 0, no sort, no paging)
"""
import os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', 'performance-audit-2026-11-25', 'results', 'es'))
import esq  # noqa: E402
import variants as audit  # noqa: E402

US, RS = '\x1f', '\x1e'
OPT_SIZE = 1000
TOK_SIZE = 3000  # every token of the one-pass field (categories, axes, options)


def lre(s):
    """literal -> Lucene regexp (every non-alphanumeric character escaped, '@' and '#' are operators there)"""
    return ''.join(ch if ch.isalnum() else '\\' + ch for ch in s)


# ---- condition sets ---------------------------------------------------------------------------------------------
def condset(c, except_axis=None, with_price=True, force_available=False):
    sel = [(a, v) for a, v in c.get('options', {}).items() if a != except_axis]
    priced = with_price and (c.get('pmin') is not None or c.get('pmax') is not None)
    return {'sel': sel, 'buy': bool(force_available or c.get('available') or priced), 'priced': priced,
            'variant': esq.conditions(c, except_axis=except_axis, with_price=with_price, force_available=force_available)}


def price_range(c):
    rng = {}
    if c.get('pmin') is not None: rng['gte'] = c['pmin']
    if c.get('pmax') is not None: rng['lte'] = c['pmax']
    return rng


def token_query(sel, buy):
    """exact product-level query of 'a variant with these options (and purchasable)'; None = every product"""
    if not sel:
        return {'term': {'purchasable': True}} if buy else None
    if len(sel) == 1:
        a, vals = sel[0]
        return {'terms': {'buy_facets' if buy else 'facets': [a + US + v for v in vals]}}
    if len(sel) == 2:
        (a1, v1), (a2, v2) = sel
        return {'terms': {'pair_buy' if buy else 'pair_any': [a1 + US + x + RS + a2 + US + y for x in v1 for y in v2]}}
    return {'match_none': {}}  # a variant has at most 2 options


def prefilter(c, cs):
    """product-level necessary condition (cheap), list of filters"""
    out = []
    t = token_query(cs['sel'], cs['buy'])
    if t: out.append(t)
    if cs['priced']: out.append({'range': {'buy_prices': price_range(c)}})
    return out


def exact_query(c, cs):
    """exact product-level-or-nested query of a condition set; None = every product"""
    pre = prefilter(c, cs)
    if cs['priced'] and cs['sel']:
        pre.append(esq.nested(cs['variant']))  # price and option in the same variant: the join is needed
    if not pre:
        return None
    return pre[0] if len(pre) == 1 else {'bool': {'filter': pre}}


def _f(q):
    return q if q else {'match_all': {}}


def _rev():
    return {'product': {'reverse_nested': {}}}


def nested_agg(c, cs, aggs):
    return {'filter': _f(None if not prefilter(c, cs) else {'bool': {'filter': prefilter(c, cs)}}),
            'aggs': {'n': {'nested': {'path': 'variants'}, 'aggs': {'matching': {'filter': esq.filter_of(cs['variant']), 'aggs': aggs}}}}}


def single(sel):
    return all(len(v) == 1 for _, v in sel)


# ---- pl ---------------------------------------------------------------------------------------------------------
def pl_aggs(c, price_agg=True):
    a = {}
    allc = condset(c)
    fc = c.get('facet_cats', [])
    one_pass = not allc['sel'] and not allc['priced']  # categories, axes, options and availability from one field
    if one_pass and allc['buy']:
        a['tok'] = {'filter': {'term': {'purchasable': True}}, 'aggs': {'t': {'terms': {'field': 'buy_facets', 'size': TOK_SIZE}}}}
    elif one_pass:
        a['tok'] = {'terms': {'field': 'facets', 'size': TOK_SIZE}}
    if fc and not one_pass:
        a['categories'] = {'filter': _f(exact_query(c, allc)),
                           'aggs': {'ids': {'filters': {'filters': {k: {'term': {'category_ancestor_ids': k}} for k in fc}}}}}
    if not allc['buy'] and not one_pass:  # with buy every hit has a purchasable matching variant: available = total
        a['available'] = {'filter': _f(exact_query(c, condset(c, force_available=True)))}
    pc = condset(c, with_price=False, force_available=True)
    if not pc['sel']:
        if price_agg:
            a['price'] = {'filter': {'term': {'purchasable': True}}, 'aggs': {'min': {'min': {'field': 'from_gross'}}, 'max': {'max': {'field': 'max_gross'}}}}
    elif len(pc['sel']) <= 2:
        a['price'] = nested_agg(c, pc, {'min': {'min': {'field': 'variants.gross'}}, 'max': {'max': {'field': 'variants.gross'}}})
    sel = allc['sel']
    names = [s[0] for s in sel]
    # free axes: only variants with every selected option and one more axis, impossible beyond one selected axis
    if one_pass:
        pass
    elif len(sel) == 1 and not allc['priced'] and single(sel):
        x = sel[0][0] + US + sel[0][1][0]
        a['pairs'] = {'terms': {'field': 'pair_buy' if allc['buy'] else 'pair_any', 'include': lre(x + RS) + '.*', 'size': OPT_SIZE}}
    elif len(sel) <= 1:
        axes = {'terms': {'field': 'variants.axes', 'size': esq.MAX_AXES, 'order': audit._by_products()}, 'aggs': _rev()}
        opts = {'terms': {'field': 'variants.options', 'size': OPT_SIZE, 'order': audit._by_products()}, 'aggs': _rev()}
        if names:
            axes['terms']['exclude'] = names
            opts['terms']['exclude'] = '(' + '|'.join(lre(s) for s in names) + ')' + lre(US) + '.*'
        a['free'] = nested_agg(c, allc, {'axes': axes, 'opts': opts})
    for pos, (axis, chosen) in enumerate(sel):
        cs = condset(c, except_axis=axis)
        key = 'selected_%d' % pos
        if len(cs['sel']) >= 2:
            continue  # the axis would be a third option of the variant: no values
        if not cs['priced'] and not cs['sel']:
            f = 'buy_facets' if cs['buy'] else 'facets'
            a[key] = {'filter': {'match_all': {}}, 'aggs': {
                'top': {'terms': {'field': f, 'include': lre(axis + US) + '.*', 'size': esq.MAX_VALUES}},
                'chosen': {'terms': {'field': f, 'include': [axis + US + v for v in chosen], 'size': len(chosen)}}}}
        elif not cs['priced'] and single(cs['sel']):
            y = cs['sel'][0][0] + US + cs['sel'][0][1][0] + RS
            f = 'pair_buy' if cs['buy'] else 'pair_any'
            a[key] = {'filter': {'match_all': {}}, 'aggs': {
                'top': {'terms': {'field': f, 'include': lre(y + axis + US) + '.*', 'size': esq.MAX_VALUES}},
                'chosen': {'terms': {'field': f, 'include': [y + axis + US + v for v in chosen], 'size': len(chosen)}}}}
        else:
            a[key] = nested_agg(c, cs, {
                'top': {'terms': {'field': 'variants.options', 'include': lre(axis + US) + '.*', 'size': esq.MAX_VALUES, 'order': audit._by_products()}, 'aggs': _rev()},
                'chosen': {'terms': {'field': 'variants.options', 'include': [axis + US + v for v in chosen], 'size': len(chosen)}, 'aggs': _rev()}})
    return a


def pl_body(c, page=1, per_page=24):
    b = esq.body(dict(c, facet_cats=[], options={}, pmin=None, pmax=None, available=False), page, per_page)
    q = exact_query(c, condset(c))
    if q:
        b['post_filter'] = q
    b['aggs'] = pl_aggs(c)
    return b


def pl_split(c, page=1, per_page=24):
    """-> list of requests: hits (no aggregations), facets (size 0: independent of the page and of the sort) and,
    for a listing without option conditions, the lowest and the highest price as two top-1 searches (the sort on
    an indexed number skips non-competitive documents instead of reading the price of every product)"""
    b = pl_body(c, page, per_page)
    b.pop('aggs')
    q = b.pop('post_filter', None)
    if q:
        b['query']['bool']['filter'].append(q)  # no aggregation in this request: the conditions narrow the query
    out = [b, {'query': esq.query(c), 'size': 0, 'track_total_hits': False, 'aggs': pl_aggs(c, price_agg=False)}]
    if not c.get('options'):
        base = esq.query(c)
        base['bool']['filter'] = base['bool']['filter'] + [{'term': {'purchasable': True}}]
        for field, order in (('from_gross', 'asc'), ('max_gross', 'desc')):
            out.append({'query': base, 'size': 1, '_source': False, 'track_total_hits': False,
                        'sort': [{field: order}], 'docvalue_fields': [field]})
    return out


def split_facets(c, responses):
    """-> the facets of the responses of pl_split"""
    f = facets('pl', c, responses[1], total=responses[0]['hits']['total']['value'])
    if len(responses) > 2:
        v = [r['hits']['hits'][0]['sort'][0] if r['hits']['hits'] else None for r in responses[2:4]]
        f['price'] = (None if v[0] is None else float(v[0]), None if v[1] is None else float(v[1]))
    return f


def _with(base, q):
    b = {'bool': dict(base['bool'], filter=list(base['bool']['filter']) + ([q] if q else []))}
    return b


def _nested_only(cs, aggs):
    return {'n': {'nested': {'path': 'variants'}, 'aggs': {'matching': {'filter': esq.filter_of(cs['variant']), 'aggs': aggs}}}}


def _tok_search(c, buy):
    """facets of a listing without option and price conditions: one pass over one field; the same request serves
    the values of a selected axis of a filtered listing, so the native request cache shares it"""
    q = _with(esq.query(c), {'term': {'purchasable': True}} if buy else None)
    return {'query': q, 'size': 0, 'track_total_hits': False, 'aggs': {'tok': {'terms': {'field': 'buy_facets' if buy else 'facets', 'size': TOK_SIZE}}}}


def plm(c, page=1, per_page=24):
    """pl as one _msearch: the aggregations grouped by their condition set, every group a search whose query is
    narrowed by the conditions (the engine walks only matching products instead of testing a filter on every product
    of the listing). -> list of (role, body)"""
    base = esq.query(c)
    allc = condset(c)
    sel = allc['sel']
    hits = esq.body(dict(c, facet_cats=[], options={}, pmin=None, pmax=None, available=False), page, per_page)
    hits.pop('aggs', None)
    hits['query'] = _with(base, exact_query(c, allc))
    out = [('hits', hits)]
    one_pass = not sel and not allc['priced']
    size0 = lambda q, aggs: {'query': q, 'size': 0, 'track_total_hits': False, 'aggs': aggs}
    if one_pass:
        out.append(('tok', _tok_search(c, allc['buy'])))
    else:
        aggs = {}
        fc = c.get('facet_cats', [])
        if fc:
            aggs['ids'] = {'filters': {'filters': {k: {'term': {'category_ancestor_ids': k}} for k in fc}}}
        if len(sel) == 1 and not allc['priced'] and single(sel):
            x = sel[0][0] + US + sel[0][1][0]
            aggs['pairs'] = {'terms': {'field': 'pair_buy' if allc['buy'] else 'pair_any', 'include': lre(x + RS) + '.*', 'size': OPT_SIZE}}
        elif len(sel) <= 1:
            names = [s[0] for s in sel]
            axes = {'terms': {'field': 'variants.axes', 'size': esq.MAX_AXES, 'order': audit._by_products()}, 'aggs': _rev()}
            opts = {'terms': {'field': 'variants.options', 'size': OPT_SIZE, 'order': audit._by_products()}, 'aggs': _rev()}
            if names:
                axes['terms']['exclude'] = names
                opts['terms']['exclude'] = '(' + '|'.join(lre(n) for n in names) + ')' + lre(US) + '.*'
            aggs.update(_nested_only(allc, {'axes': axes, 'opts': opts}))
        if aggs:
            out.append(('all', size0(_with(base, exact_query(c, allc)), aggs)))
        if not allc['buy']:
            av = condset(c, force_available=True)
            out.append(('avail', {'query': _with(base, exact_query(c, av)), 'size': 0, 'track_total_hits': True}))
    pc = condset(c, with_price=False, force_available=True)
    if not pc['sel']:
        q = _with(base, {'term': {'purchasable': True}})
        for role, field, order in (('pmin', 'from_gross', 'asc'), ('pmax', 'max_gross', 'desc')):
            out.append((role, {'query': q, 'size': 1, '_source': False, 'track_total_hits': False, 'sort': [{field: order}]}))
    elif len(pc['sel']) <= 2:
        out.append(('price', size0(_with(base, token_query(pc['sel'], True)),
                                   _nested_only(pc, {'min': {'min': {'field': 'variants.gross'}}, 'max': {'max': {'field': 'variants.gross'}}}))))
    for pos, (axis, chosen) in enumerate(sel):
        cs = condset(c, except_axis=axis)
        role = 'sel_%d' % pos
        if len(cs['sel']) >= 2:
            continue
        if not cs['priced'] and not cs['sel']:
            out.append((role, _tok_search(c, cs['buy'])))
        elif not cs['priced'] and single(cs['sel']):
            y = cs['sel'][0][0] + US + cs['sel'][0][1][0] + RS
            out.append((role, size0(_with(base, token_query(cs['sel'], cs['buy'])),
                                    {'v': {'terms': {'field': 'pair_buy' if cs['buy'] else 'pair_any', 'include': lre(y + axis + US) + '.*', 'size': OPT_SIZE}}})))
        else:
            out.append((role, size0(_with(base, exact_query(c, cs)), _nested_only(cs, {
                'top': {'terms': {'field': 'variants.options', 'include': lre(axis + US) + '.*', 'size': esq.MAX_VALUES, 'order': audit._by_products()}, 'aggs': _rev()},
                'chosen': {'terms': {'field': 'variants.options', 'include': [axis + US + v for v in chosen], 'size': len(chosen)}, 'aggs': _rev()}}))))
    return out


def plm_facets(c, parts, responses):
    """-> the facets of the responses of plm (parts: its list of (role, body))"""
    r = {role: resp for (role, _), resp in zip(parts, responses)}
    total = r['hits']['hits']['total']['value']
    allc = condset(c)
    ag = {}
    if 'tok' in r:
        ag['tok'] = r['tok']['aggregations']['tok']
    if 'all' in r:
        a = r['all']['aggregations']
        if 'ids' in a: ag['categories'] = {'ids': a['ids']}
        if 'pairs' in a: ag['pairs'] = a['pairs']
        if 'n' in a: ag['free'] = {'n': a['n']}
    if 'avail' in r:
        ag['available'] = {'doc_count': r['avail']['hits']['total']['value']}
    if 'pmin' in r:
        v = [r[k]['hits']['hits'][0]['sort'][0] if r[k]['hits']['hits'] else None for k in ('pmin', 'pmax')]
        ag['price'] = {'min': {'value': None if v[0] is None else float(v[0])}, 'max': {'value': None if v[1] is None else float(v[1])}}
    elif 'price' in r:
        ag['price'] = {'n': r['price']['aggregations']['n']}
    for pos, (axis, _chosen) in enumerate(allc['sel']):
        resp = r.get('sel_%d' % pos)
        if resp is None:
            continue
        a = resp['aggregations']
        if 'tok' in a:
            ag['selected_%d' % pos] = {'top': {'buckets': [b for b in a['tok']['buckets'] if b['key'].startswith(axis + US)]}, 'chosen': {'buckets': []}}
        elif 'v' in a:
            ag['selected_%d' % pos] = {'top': a['v'], 'chosen': {'buckets': []}}
        else:
            ag['selected_%d' % pos] = {'n': a['n']}
    out = facets('pl', c, {'aggregations': ag}, total=total)
    if allc['buy']:
        out['available'] = total  # every hit has a purchasable matching variant
    return out


def build(kind, c, page=1, per_page=24):
    if kind == 'cur':
        return esq.body(c, page, per_page)
    if kind == 'pl':
        return pl_body(c, page, per_page)
    return audit.build(kind, c, page, per_page)


# ---- normalisation ----------------------------------------------------------------------------------------------
def _matching(a):
    return a['n']['matching'] if 'n' in a else a['matching']


def _top(out, c):
    for ax, vals in out['values'].items():
        keep = sorted(vals.items(), key=lambda kv: (-kv[1], kv[0]))[:esq.MAX_VALUES]
        sel = c.get('options', {}).get(ax, [])
        keep += [(v, vals.get(v, 0)) for v in sel if v not in dict(keep)]
        out['values'][ax] = dict(keep)
    return out


def _nested_free(fm, out):
    for b in fm['axes']['buckets']:
        out['axes'][b['key']] = b['product']['doc_count']
    for b in fm['opts']['buckets']:
        ax, v = b['key'].split(US, 1)
        if ax in out['axes']:
            out['values'].setdefault(ax, {})[v] = b['product']['doc_count']


def facets(kind, c, resp, total=None):
    """-> dict(total, categories, available, price, axes, values) as the API would show them"""
    if kind == 'cur':
        return audit.facets(kind, c, resp)
    ag = resp['aggregations']
    total = resp['hits']['total']['value'] if total is None else total
    out = {'total': total, 'categories': {}, 'axes': {}, 'values': {}}
    if 'categories' in ag:
        cb = (ag['categories']['ids'] if 'ids' in ag['categories'] else ag['categories'])['buckets']
        out['categories'] = {k: v['doc_count'] for k, v in cb.items() if v['doc_count']} if isinstance(cb, dict) else {b['key']: b['doc_count'] for b in cb}
    selected = list(c.get('options', {}).keys())
    av = ag.get('available')
    out['available'] = total if av is None else (av['doc_count'] if 'n' not in av and 'matching' not in av else _matching(av)['product']['doc_count'])
    pr = ag.get('price')
    if pr is None:
        out['price'] = (None, None)
    else:
        pm = pr if 'min' in pr else _matching(pr)
        out['price'] = (pm['min']['value'], pm['max']['value'])
    if 'tok' in ag:
        tb = (ag['tok']['t'] if 't' in ag['tok'] else ag['tok'])['buckets']
        fc = set(c.get('facet_cats', []))
        axes, vals = {}, {}
        for b in tb:
            k = b['key']
            if k == '$':
                out['available'] = b['doc_count']
            elif k[0] == '#':
                if k[1:] in fc: out['categories'][k[1:]] = b['doc_count']
            elif k[0] == '@':
                axes[k[1:]] = b['doc_count']
            else:
                ax, v = k.split(US, 1)
                vals.setdefault(ax, {})[v] = b['doc_count']
        if 't' not in ag['tok'] and not any(b['key'] == '$' for b in tb):
            out['available'] = 0
        for ax, n in sorted(axes.items(), key=lambda kv: (-kv[1], kv[0]))[:esq.MAX_AXES]:
            out['axes'][ax] = n
            out['values'][ax] = vals.get(ax, {})
    elif 'axes' in ag:
        for b in ag['axes']['buckets']:
            out['axes'][b['key']] = b['doc_count']
        for b in ag['opts']['buckets']:
            ax, v = b['key'].split(US, 1)
            if ax in out['axes']:
                out['values'].setdefault(ax, {})[v] = b['doc_count']
    elif 'pairs' in ag:
        axes, vals = {}, {}
        for b in ag['pairs']['buckets']:
            rest = b['key'].split(RS, 1)[1]
            if rest.startswith('@'):
                axes[rest[1:]] = b['doc_count']
            else:
                ax, v = rest.split(US, 1)
                vals.setdefault(ax, {})[v] = b['doc_count']
        for ax, n in sorted(axes.items(), key=lambda kv: (-kv[1], kv[0]))[:esq.MAX_AXES]:
            out['axes'][ax] = n
            out['values'][ax] = vals.get(ax, {})
    elif 'free' in ag:
        _nested_free(_matching(ag['free']), out)
    for pos, axis in enumerate(selected):
        s = ag.get('selected_%d' % pos)
        vals = {}
        if s is not None:
            m = s if 'top' in s else _matching(s)
            for b in m['top']['buckets'] + m['chosen']['buckets']:
                vals[b['key'].rsplit(US, 1)[1]] = b['product']['doc_count'] if 'product' in b else b['doc_count']
        out['axes'][axis] = total
        out['values'][axis] = vals
    return _top(out, c)


# ---- scenarios --------------------------------------------------------------------------------------------------
ROOT, LEAF, ROOTS = esq.ROOT, esq.LEAF, esq.ROOTS
RC, LC = esq.children(ROOT), esq.children(LEAF)
SCENARIOS = {
    'all_name': dict(facet_cats=ROOTS),
    'all_price_asc': dict(facet_cats=ROOTS, sort='price_asc'),
    'all_avail': dict(facet_cats=ROOTS, available=True),
    'all_price': dict(facet_cats=ROOTS, pmin=5000, pmax=15000),
    'all_color': dict(facet_cats=ROOTS, options={'Kolor': ['czarne']}),
    'all_color_avail': dict(facet_cats=ROOTS, options={'Kolor': ['czarne']}, available=True),
    'all_color_price': dict(facet_cats=ROOTS, options={'Kolor': ['czarne']}, pmin=5000, pmax=15000),
    'all_color2_size': dict(facet_cats=ROOTS, options={'Kolor': ['czarne', 'czarny'], 'Rozmiar': ['M']}),
    'all_color_size_price': dict(facet_cats=ROOTS, options={'Kolor': ['czarne'], 'Rozmiar': ['M']}, pmin=5000, pmax=15000, available=True),
    'root_name': dict(category=ROOT, facet_cats=RC),
    'root_price_desc': dict(category=ROOT, facet_cats=RC, sort='price_desc'),
    'root_color': dict(category=ROOT, facet_cats=RC, options={'Kolor': ['czarne']}),
    'root_color2': dict(category=ROOT, facet_cats=RC, options={'Kolor': ['czarne', 'szare']}),
    'root_color_size': dict(category=ROOT, facet_cats=RC, options={'Kolor': ['czarne'], 'Rozmiar': ['M']}),
    'root_color2_size': dict(category=ROOT, facet_cats=RC, options={'Kolor': ['czarne', 'czarny'], 'Rozmiar': ['M']}),
    'root_price': dict(category=ROOT, facet_cats=RC, pmin=10000, pmax=30000),
    'root_avail': dict(category=ROOT, facet_cats=RC, available=True),
    'root_color_price': dict(category=ROOT, facet_cats=RC, options={'Kolor': ['czarne']}, pmin=10000, pmax=30000),
    'leaf_name': dict(category=LEAF, facet_cats=LC),
    'leaf_color_price': dict(category=LEAF, facet_cats=LC, options={'Kolor': ['czarne']}, pmin=5000, pmax=30000, available=True),
    'phrase_kurtka': dict(phrase='kurtka', facet_cats=ROOTS),
    'phrase_typo': dict(phrase='spiwor puchowy', facet_cats=ROOTS),
    'phrase_color': dict(phrase='kurtka', facet_cats=ROOTS, options={'Kolor': ['czarna']}),
    'three_axes': dict(facet_cats=ROOTS, options={'Kolor': ['czarne'], 'Rozmiar': ['M'], 'Pojemność': ['1 l']}),
}
PAGES = {'all_p400': ('all_name', 400), 'all_p100': ('all_name', 100)}  # deep pages of the whole catalog


def scenario(name):
    """-> (criteria, page)"""
    if name in PAGES:
        base, page = PAGES[name]
        return SCENARIOS[base], page
    return SCENARIOS[name], 1


ALL = list(SCENARIOS) + list(PAGES)

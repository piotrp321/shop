"""Generates the provisional mix of listing addresses of PERF-2 stage A (the official mix is defined by PERF-0):
300 different criteria from the data of the index, seeded, saved to ../results/mix.json with the class of each.
Weights: whole catalog 6% (+6% filtered), department 12% (+8%), middle category 10% (+6%), leaf 20% (+12%),
phrase 14% (+4%), deep page 2%. Sort: 70% default, 30% by price; page 2-5 in 20% of the requests.
usage: mix.py <index with the h mapping>"""
import json, os, random, sys
import models as m
from models import esq

idx = sys.argv[1]
rnd = random.Random(20261125)
cats = list(esq.CNT)
kids = {c: esq.children(c) for c in cats}
roots = list(m.ROOTS)
leaves = [c for c in cats if not kids[c] and esq.CNT[c] >= 500]
mids = [c for c in cats if kids[c] and c not in roots]


def facets_of(c):
    r = esq.req('POST', '/%s/_search' % idx, dict(m.pl_body(c), size=0))
    f = m.facets('pl', c, r)
    p = esq.req('POST', '/%s/_search' % idx, {'size': 0, 'query': esq.query(c), 'aggs': {'p': {'percentiles': {'field': 'from_gross', 'percents': [10, 30, 50, 70, 90]}}}})
    return f, [int(v) for v in p['aggregations']['p']['values'].values() if v is not None]


def pick_value(f, axis):
    vals = list(f['values'][axis].items())
    return rnd.choices([v for v, _ in vals], weights=[n for _, n in vals])[0]


def filtered(base):
    f, pct = facets_of(base)
    axes = [a for a in f['axes'] if f['values'].get(a)]
    c = dict(base)
    kind = rnd.choices(['opt', 'opt2v', 'two', 'price', 'avail', 'opt_price', 'opt_avail'], weights=[40, 10, 10, 15, 10, 10, 5])[0]
    if not axes and kind not in ('price', 'avail'):
        kind = 'price'
    if kind in ('price', 'opt_price') and len(pct) == 5:
        i = rnd.randrange(0, 3)
        c['pmin'], c['pmax'] = pct[i] // 100 * 100, pct[i + 2] // 100 * 100 + 100
    if kind in ('avail', 'opt_avail'):
        c['available'] = True
    if kind in ('opt', 'opt2v', 'two', 'opt_price', 'opt_avail'):
        a = rnd.choices(axes, weights=[f['axes'][x] for x in axes])[0]
        vals = [pick_value(f, a)]
        if kind == 'opt2v':
            vals = list(dict.fromkeys(vals + [pick_value(f, a)]))
        c['options'] = {a: vals}
        if kind == 'two':
            f2 = m.facets('pl', c, esq.req('POST', '/%s/_search' % idx, dict(m.pl_body(c), size=0)))
            other = [x for x in f2['axes'] if x != a and f2['values'].get(x)]
            if other:
                b = rnd.choice(other)
                c['options'][b] = [pick_value(f2, b)]
    return c, kind


def sort_page(c):
    s = rnd.choices([None, 'price_asc', 'price_desc'], weights=[70, 15, 15])[0]
    if s:
        c = dict(c, sort=s)
    return c, (1 if rnd.random() < 0.8 else rnd.randint(2, 5))


def weighted(pool):
    return rnd.choices(pool, weights=[esq.CNT[c] ** 0.5 for c in pool])[0]


names = [h['_source']['name'] for h in esq.req('POST', '/%s/_search' % idx, {'size': 400, '_source': ['name'], 'query': {'function_score': {'query': {'term': {'status': 'published'}}, 'random_score': {'seed': 11, 'field': '_seq_no'}}}})['hits']['hits']]
fold = str.maketrans('ąćęłńóśźż', 'acelnoszz')


def phrase():
    w = rnd.choice(names).lower().split()
    p = ' '.join(w[:rnd.choice([1, 1, 2, 2, 3])])
    return p.translate(fold) if rnd.random() < 0.3 else p


items, seen = [], set()


def add(cls, n, make):
    made = 0
    while made < n:
        c, page, extra = make()
        key = json.dumps([c, page], sort_keys=True, ensure_ascii=False)
        if key in seen:
            continue
        seen.add(key); made += 1
        items.append({'class': cls, 'c': c, 'page': page, **extra})


def plain(base):
    c, page = sort_page(base)
    return c, page, {}


def with_filter(base):
    c, kind = filtered(base)
    c, page = sort_page(c)
    return c, page, {'filter': kind}


cat_c = lambda cat: dict(category=cat, facet_cats=kids[cat])
add('all', 12, lambda: plain(dict(facet_cats=roots)))  # only 12 different addresses exist (3 sorts x pages 1-5 minus repeats)
add('all_filtered', 18, lambda: with_filter(dict(facet_cats=roots)))
add('root', 36, lambda: plain(cat_c(rnd.choice(roots))))
add('root_filtered', 24, lambda: with_filter(cat_c(rnd.choice(roots))))
add('mid', 30, lambda: plain(cat_c(weighted(mids))))
add('mid_filtered', 18, lambda: with_filter(cat_c(weighted(mids))))
add('leaf', 60, lambda: plain(cat_c(weighted(leaves))))
add('leaf_filtered', 36, lambda: with_filter(cat_c(weighted(leaves))))
add('phrase', 42, lambda: plain(dict(phrase=phrase(), facet_cats=roots)))
add('phrase_filtered', 12, lambda: with_filter(dict(phrase=phrase(), facet_cats=roots)))
add('deep', 6, lambda: (dict(facet_cats=roots), rnd.choice([100, 200, 300, 400]), {}) if rnd.random() < 0.5 else (cat_c(rnd.choice(roots)), rnd.choice([100, 200, 300, 400]), {}))
rnd.shuffle(items)
json.dump(items, open(os.path.join(m.HERE, '..', 'results', 'mix.json'), 'w'), ensure_ascii=False, indent=0)
import collections
print(len(items), dict(collections.Counter(i['class'] for i in items)))
print('filters', dict(collections.Counter(i.get('filter') for i in items if i.get('filter'))))
print('categories: roots %d, middle %d, leaves %d' % (len(roots), len(mids), len(leaves)))
print('phrases sample', [i['c']['phrase'] for i in items if i['class'] == 'phrase'][:8])

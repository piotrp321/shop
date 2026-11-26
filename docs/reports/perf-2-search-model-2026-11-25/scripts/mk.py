"""Builds the test indices of PERF-2 stage A in the isolated Elasticsearch (es-audit) by server-side _reindex.

usage: mk.py <name> <kind> <shards> <copies> [source] [sort]
  kind   cur  - the current mapping (nested variants -> nested pairs), documents copied as they are
         h    - the superset of the candidate models: one nested level and product-level facet fields
  copies N    - the source (1M products of the `medium` profile) is written N times; copy 0 keeps the ids, copy k
                gets ids with the prefix "g<k>" (same distributions as the `server` profile, which differs from
                `medium` only in counts: apps/api/fixtures/demo-data/profiles.yaml)
  sort   optional index sort field (e.g. main_category_id)
Prints the task ids; wait.py polls them."""
import copy, json, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
AUDIT = os.path.join(HERE, '..', '..', 'performance-audit-2026-11-25', 'results', 'es')
sys.path.insert(0, AUDIT)
import esq  # noqa: E402

RENAME = """
if (params.k > 0) { String nid = 'g' + params.k + ctx._source.id.substring(2); ctx._id = nid; ctx._source.id = nid; }
"""

# Product-level fields derived from the variants. A variant has at most 2 options (VariantAxes::MAX_AXES = 2) and
# the option tuples of one product are unique.
#   facet_axes / facet_options   axes and "axis<US>value" of all variants
#   buy_axes / buy_options       the same of purchasable variants only
#   pair_any / pair_buy          for a variant with options x and y: "x<RS>y", "y<RS>x", "x<RS>@axis(y)", "y<RS>@axis(x)"
#   purchasable, max_gross       any purchasable variant; its highest price (from_gross is the lowest already)
#   buy_prices                   distinct prices of purchasable variants
#   one_price                    at most one distinct price among purchasable variants
#   facets                       one field for one pass: "#category" (ancestors), "@axis", "axis<US>value", "$" when
#                                purchasable; buy_facets: the same of purchasable variants (no "$")
HYBRID = RENAME + """
String sep = String.valueOf((char)31); String ps = String.valueOf((char)30);
Set fa = new TreeSet(); Set fo = new TreeSet(); Set ba = new TreeSet(); Set bo = new TreeSet();
Set pa = new TreeSet(); Set pb = new TreeSet(); Set prices = new TreeSet(); long mx = -1; boolean buy = false;
for (def v : ctx._source.variants) {
  v.remove('pairs');
  List os = v.options; List vax = new ArrayList();
  for (def o : os) { vax.add(o.substring(0, o.indexOf(sep))); }
  v.axes = vax; fo.addAll(os); fa.addAll(vax);
  boolean b = v.purchasable;
  if (b) { buy = true; bo.addAll(os); ba.addAll(vax); if (v.gross != null) { prices.add(v.gross); if (v.gross > mx) { mx = v.gross; } } }
  if (os.size() == 2) {
    for (int i = 0; i < 2; i++) {
      String x = os.get(i); String t1 = x + ps + os.get(1 - i); String t2 = x + ps + '@' + vax.get(1 - i);
      pa.add(t1); pa.add(t2);
      if (b) { pb.add(t1); pb.add(t2); }
    }
  }
}
ctx._source.facet_axes = new ArrayList(fa); ctx._source.facet_options = new ArrayList(fo);
ctx._source.buy_axes = new ArrayList(ba); ctx._source.buy_options = new ArrayList(bo);
ctx._source.pair_any = new ArrayList(pa); ctx._source.pair_buy = new ArrayList(pb);
List f = new ArrayList(); List bf = new ArrayList();
for (def c : ctx._source.category_ancestor_ids) { f.add('#' + c); bf.add('#' + c); }
for (def a : fa) { f.add('@' + a); } for (def a : ba) { bf.add('@' + a); }
f.addAll(fo); bf.addAll(bo); if (buy) { f.add('$'); }
ctx._source.facets = f; ctx._source.buy_facets = buy ? bf : new ArrayList();
ctx._source.purchasable = buy; ctx._source.buy_prices = new ArrayList(prices); ctx._source.one_price = prices.size() <= 1;
if (mx >= 0) { ctx._source.max_gross = mx; }
"""

# The recommended model on its own (kind r): only the fields plm reads, and sort_path (the ids of the path of the main
# category, root first) as the index sort key, so the products of a category subtree are contiguous.
LEAN = RENAME + """
String sep = String.valueOf((char)31); String ps = String.valueOf((char)30);
Set fa = new TreeSet(); Set fo = new TreeSet(); Set ba = new TreeSet(); Set bo = new TreeSet();
Set pa = new TreeSet(); Set pb = new TreeSet(); Set prices = new TreeSet(); long mx = -1; boolean buy = false;
for (def v : ctx._source.variants) {
  v.remove('pairs');
  List os = v.options; List vax = new ArrayList();
  for (def o : os) { vax.add(o.substring(0, o.indexOf(sep))); }
  v.axes = vax; fo.addAll(os); fa.addAll(vax);
  boolean b = v.purchasable;
  if (b) { buy = true; bo.addAll(os); ba.addAll(vax); if (v.gross != null) { prices.add(v.gross); if (v.gross > mx) { mx = v.gross; } } }
  if (os.size() == 2) {
    for (int i = 0; i < 2; i++) {
      String x = os.get(i); String t1 = x + ps + os.get(1 - i); String t2 = x + ps + '@' + vax.get(1 - i);
      pa.add(t1); pa.add(t2);
      if (b) { pb.add(t1); pb.add(t2); }
    }
  }
}
List f = new ArrayList(); List bf = new ArrayList();
for (def c : ctx._source.category_ancestor_ids) { f.add('#' + c); bf.add('#' + c); }
for (def a : fa) { f.add('@' + a); } for (def a : ba) { bf.add('@' + a); }
f.addAll(fo); bf.addAll(bo); if (buy) { f.add('$'); }
ctx._source.facets = f; ctx._source.buy_facets = buy ? bf : new ArrayList();
ctx._source.pair_any = new ArrayList(pa); ctx._source.pair_buy = new ArrayList(pb);
ctx._source.purchasable = buy; ctx._source.buy_prices = new ArrayList(prices);
if (mx >= 0) { ctx._source.max_gross = mx; }
List anc = ctx._source.category_ancestor_ids; int at = anc.indexOf(ctx._source.main_category_id);
String path = '';
for (int i = 0; i <= at; i++) { path = path + (i > 0 ? '/' : '') + anc.get(i); }
ctx._source.sort_path = at < 0 ? ctx._source.main_category_id : path;
"""


def mappings(kind):
    orig = list(json.load(open(os.path.join(AUDIT, 'mapping_orig.json'))).values())[0]['mappings']
    if kind == 'cur':
        return orig
    m = copy.deepcopy(orig)
    v = m['properties']['variants']['properties']
    del v['pairs']
    if kind == 'r':
        v['axes'] = {'type': 'keyword', 'index': False}  # read only by the nested facet aggregation
        for f in ('facets', 'buy_facets', 'pair_any', 'pair_buy'):
            m['properties'][f] = {'type': 'keyword'}
        m['properties']['max_gross'] = {'type': 'long'}
        m['properties']['buy_prices'] = {'type': 'long', 'doc_values': False}  # range filter only
        m['properties']['purchasable'] = {'type': 'boolean'}
        m['properties']['sort_path'] = {'type': 'keyword', 'index': False}  # index sort only
        return m
    v['axes'] = {'type': 'keyword'}
    for f in ('facet_axes', 'facet_options', 'buy_axes', 'buy_options', 'pair_any', 'pair_buy', 'facets', 'buy_facets'):
        m['properties'][f] = {'type': 'keyword'}
    m['properties']['max_gross'] = {'type': 'long'}
    m['properties']['buy_prices'] = {'type': 'long'}
    m['properties']['purchasable'] = {'type': 'boolean'}
    m['properties']['one_price'] = {'type': 'boolean'}
    return m


def main():
    name, kind, shards, copies = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4])
    source = sys.argv[5] if len(sys.argv) > 5 else 'products'
    sort = sys.argv[6] if len(sys.argv) > 6 else None
    sett = list(json.load(open(os.path.join(AUDIT, 'settings_orig.json'))).values())[0]['settings']['index']
    settings = {'number_of_shards': shards, 'number_of_replicas': 0, 'refresh_interval': '-1', 'analysis': sett['analysis']}
    if sort:
        settings['sort.field'] = sort
        settings['sort.order'] = 'asc'
    try:
        esq.req('DELETE', '/' + name)
    except Exception:
        pass
    esq.req('PUT', '/' + name, {'settings': settings, 'mappings': mappings(kind)})
    script = {'cur': RENAME, 'h': HYBRID, 'r': LEAN, 'copy': RENAME}[kind if source == 'products' else 'copy']
    tasks = []
    for k in range(copies):
        r = esq.req('POST', '/_reindex?wait_for_completion=false&refresh=false&slices=%s' % os.environ.get('SLICES', '1'), {
            'source': {'index': source, 'size': 2000}, 'dest': {'index': name},
            'script': {'lang': 'painless', 'source': script, 'params': {'k': k}}})
        tasks.append(r['task'])
    print(json.dumps({'index': name, 'tasks': tasks}))


if __name__ == '__main__':
    main()

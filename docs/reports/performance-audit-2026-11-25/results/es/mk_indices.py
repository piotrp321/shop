import json, copy, esq, sys
orig = list(json.load(open('mapping_orig.json')).values())[0]['mappings']
sett = list(json.load(open('settings_orig.json')).values())[0]['settings']['index']
analysis = sett['analysis']
def create(name, mappings, shards):
    try: esq.req('DELETE', '/' + name)
    except Exception: pass
    print(name, esq.req('PUT', '/' + name, {'settings': {'number_of_shards': shards, 'number_of_replicas': 0, 'refresh_interval': '-1', 'analysis': analysis}, 'mappings': mappings}))
flat = copy.deepcopy(orig)
v = flat['properties']['variants']['properties']
del v['pairs']
v['axes'] = {'type': 'keyword'}
flat['properties']['facet_axes'] = {'type': 'keyword'}
flat['properties']['facet_options'] = {'type': 'keyword'}
flat['properties']['max_gross'] = {'type': 'long'}
flat['properties']['purchasable'] = {'type': 'boolean'}
which = sys.argv[1:]
for w in which:
    name, kind, shards = w.split(':')
    create(name, orig if kind == 'orig' else flat, int(shards))

"""Field sizes of an index (_disk_usage): total, _source, doc values, inverted index, points; and the part the
listing queries read (everything except stored fields). usage: disk.py <index>"""
import json, sys
from models import esq
idx = sys.argv[1]
r = esq.req('POST', '/%s/_disk_usage?run_expensive_tasks=true' % idx, timeout=3600)[idx]
f = r['fields']; mb = lambda b: round(b / 2**20)
tot = lambda k: sum(v.get(k, 0) for v in f.values())
row = {'test': 'disk', 'index': idx, 'store_mb': mb(r['store_size_in_bytes']), 'stored_fields_mb': mb(tot('stored_fields_in_bytes')), 'doc_values_mb': mb(tot('doc_values_in_bytes')),
       'inverted_index_mb': mb(sum(v.get('inverted_index', {}).get('total_in_bytes', 0) for v in f.values())), 'points_mb': mb(tot('points_in_bytes')), 'norms_mb': mb(tot('norms_in_bytes')),
       'top_fields_mb': {k: mb(v['total_in_bytes']) for k, v in sorted(f.items(), key=lambda kv: -kv[1]['total_in_bytes'])[:14]}}
row['hot_mb'] = row['doc_values_mb'] + row['inverted_index_mb'] + row['points_mb'] + row['norms_mb']
print(json.dumps(row, ensure_ascii=False))
open('../results/disk.jsonl', 'a').write(json.dumps(row, ensure_ascii=False) + '\n')

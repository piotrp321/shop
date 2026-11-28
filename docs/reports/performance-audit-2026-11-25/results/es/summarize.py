import json, sys
for l in open(sys.argv[1]):
  try: r=json.loads(l)
  except: print(l.strip()[:200]); continue
  m = 'mix' if '+' in r['mode'] or r['mode']=='mix' else r['mode']
  print('| %s | %s | %s | %d | %.2f | %s | %s | %s | %.0f%% | %d | %d/%d | %d |' % (r['label'], r['index'], m, r['conc'], r['qps'], int(r['p50']), int(r['p90']), int(r['p99']), r['gt2s_pct'], r['rejected'], r['max_active'], r['max_queue'], r['young_gc_ms']))

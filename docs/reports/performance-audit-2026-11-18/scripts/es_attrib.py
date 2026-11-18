#!/usr/bin/env python3
"""Attribution of the cost of the listing query: variants of the captured body, `took` of each (median of N)."""
import copy, json, statistics, subprocess, sys
S = sys.argv[1]; body = json.load(open(sys.argv[2])); N = int(sys.argv[3]) if len(sys.argv) > 3 else 7
ES = "shop-perfaudit-elasticsearch-1"
def run(b):
    out = subprocess.run(["docker", "exec", "-i", ES, "curl", "-s", "-XPOST", "localhost:9200/products/_search?request_cache=false&filter_path=took,hits.total,timed_out",
                          "-H", "Content-Type: application/json", "-d", "@-"], input=json.dumps(b), capture_output=True, text=True).stdout
    return json.loads(out)
def variant(name, f):
    b = copy.deepcopy(body); f(b)
    took = [run(b)["took"] for _ in range(N)]
    r = run(b)
    print(f"{name:52s} took median={statistics.median(took):7.1f} ms  min={min(took):6.1f} max={max(took):6.1f}  total={r['hits']['total']}")
aggs = lambda b: b["aggregations"]
variant("A full (as sent by the API)", lambda b: None)
variant("B no aggregations, exact total", lambda b: b.pop("aggregations"))
variant("C no aggregations, track_total_hits=10000", lambda b: (b.pop("aggregations"), b.update(track_total_hits=10000)))
variant("D full aggregations, track_total_hits=10000", lambda b: b.update(track_total_hits=10000))
variant("E without 'free' (option facets)", lambda b: aggs(b).pop("free"))
variant("F only 'free' (option facets)", lambda b: [aggs(b).pop(k) for k in list(aggs(b)) if k != "free"])
variant("G only 'categories'", lambda b: [aggs(b).pop(k) for k in list(aggs(b)) if k != "categories"])
variant("H only 'available'", lambda b: [aggs(b).pop(k) for k in list(aggs(b)) if k != "available"])
variant("I only 'price'", lambda b: [aggs(b).pop(k) for k in list(aggs(b)) if k != "price"])
variant("J size=0 (aggregations only, no hits)", lambda b: (b.update(size=0), b.pop("sort")))

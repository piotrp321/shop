"""Cost breakdown of the listing query on a local copy of the medium index (ES 9.4.0). Usage: bench.py <body.json> [index]"""
import copy
import json
import statistics
import sys
import urllib.request

ES = "http://localhost:19200"
INDEX = sys.argv[2] if len(sys.argv) > 2 else "products"
BASE = json.load(open(sys.argv[1]))


def search(body, params="request_cache=false", index=None):
    request = urllib.request.Request(f"{ES}/{index or INDEX}/_search?{params}", data=json.dumps(body).encode(),
                                     headers={"Content-Type": "application/json"})
    return json.load(urllib.request.urlopen(request, timeout=120))


def took(body, runs=9, warm=2, **kw):
    for _ in range(warm):
        search(body, **kw)
    times = [search(body, **kw)["took"] for _ in range(runs)]
    return statistics.median(times), min(times), max(times)


def variant(name, body, **kw):
    med, low, high = took(body, **kw)
    print(f"{name:58s} median {med:6.0f} ms  (min {low}, max {high})", flush=True)
    return med


def only(agg_names, size=0):
    body = copy.deepcopy(BASE)
    body["aggregations"] = {k: v for k, v in BASE["aggregations"].items() if k in agg_names}
    body["size"] = size
    if size == 0:
        body.pop("sort", None)
    return body


if __name__ == "__main__":
    full = variant("full query (hits 24 + all aggs)", BASE)
    no_aggs = copy.deepcopy(BASE)
    no_aggs.pop("aggregations")
    variant("hits only, track_total_hits exact", no_aggs)
    capped = copy.deepcopy(no_aggs)
    capped["track_total_hits"] = 10000
    variant("hits only, track_total_hits 10000", capped)
    variant("all aggs, size 0", only(BASE["aggregations"].keys()))
    for name in BASE["aggregations"]:
        variant(f"only agg '{name}', size 0", only([name]))

    free = only(["free"])
    axes = free["aggregations"]["free"]["aggregations"]["matching"]["aggregations"]["pairs"]["aggregations"]["axes"]
    # 1. the same shape, ordered by the count of nested docs instead of the reverse_nested count
    by_count = copy.deepcopy(free)
    a = by_count["aggregations"]["free"]["aggregations"]["matching"]["aggregations"]["pairs"]["aggregations"]["axes"]
    a["terms"]["order"] = [{"_count": "desc"}, {"_key": "asc"}]
    a["aggregations"]["values"]["terms"]["order"] = [{"_count": "desc"}, {"_key": "asc"}]
    variant("free: order by _count (reverse_nested kept as metric)", by_count)
    # 2. no reverse_nested at all (counts nested pairs, not products)
    no_rev = copy.deepcopy(by_count)
    a = no_rev["aggregations"]["free"]["aggregations"]["matching"]["aggregations"]["pairs"]["aggregations"]["axes"]
    a["aggregations"].pop("product")
    a["aggregations"]["values"].pop("aggregations")
    variant("free: no reverse_nested (counts pairs)", no_rev)
    # 3. axes only, no values
    axes_only = copy.deepcopy(free)
    a = axes_only["aggregations"]["free"]["aggregations"]["matching"]["aggregations"]["pairs"]["aggregations"]["axes"]
    a["aggregations"].pop("values")
    variant("free: axes only with reverse_nested", axes_only)
    # 4. collect_mode breadth_first
    bfs = copy.deepcopy(free)
    a = bfs["aggregations"]["free"]["aggregations"]["matching"]["aggregations"]["pairs"]["aggregations"]["axes"]
    a["terms"]["collect_mode"] = "breadth_first"
    try:
        variant("free: axes collect_mode breadth_first", bfs)
    except Exception as error:  # ordering by a sub-aggregation may forbid it
        print("free: breadth_first refused:", str(error)[:120])
    # 5. execution_hint map
    hint = copy.deepcopy(free)
    a = hint["aggregations"]["free"]["aggregations"]["matching"]["aggregations"]["pairs"]["aggregations"]["axes"]
    a["terms"]["execution_hint"] = "map"
    a["aggregations"]["values"]["terms"]["execution_hint"] = "map"
    variant("free: execution_hint map", hint)

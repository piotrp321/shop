
"""facets.py <api base url> <out.json>; two files of the same data must be identical (cmp)."""
import json
import sys
import urllib.parse
import urllib.request

base, out = sys.argv[1].rstrip("/"), sys.argv[2]


def get(path):
    request = urllib.request.Request(base + path, headers={"User-Agent": "facets-compare"})
    return json.load(urllib.request.urlopen(request, timeout=30))


roots = get("/api/v1/categories")
roots = roots["items"] if isinstance(roots, dict) else roots
queries = ["", "?sort=price_asc", "?available=true", "?priceMin=100&priceMax=300"]
queries += ["?category=" + c["id"] for c in roots]
queries += ["?category=" + child["id"] for c in roots for child in (c.get("children") or [])[:2]]
queries += ["?q=" + urllib.parse.quote(q) for q in ("buty", "kurtka", "sportowe", "rower", "buty biegowe")]
for scope in ("", "?category=" + roots[0]["id"]):
    for option in get("/api/v1/products" + scope)["facets"]["options"][:3]:
        chosen = urllib.parse.quote(f'{option["axis"]}:{option["values"][0]["value"]}')
        queries.append(f'{scope}{"&" if scope else "?"}option={chosen}')
result = {}
for query in queries:
    page = get("/api/v1/products" + query)
    result[query] = {"total": page["total"], "facets": page["facets"]}
json.dump(result, open(out, "w"), ensure_ascii=False, sort_keys=True, indent=1)
print(len(result), "queries saved to", out)

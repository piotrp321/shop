"""Measurement of the listing on worf.pl for issue #928 (GET requests only). Usage: measure.py <out.json>

Steps (sequential, so that they do not disturb one another): the baseline /api/v1/categories; the listing through the API
(whole catalog, a department, a phrase, a leaf; 10 tries each); the cold home page (10 unused ?page=K, then / five times
90 s apart with an immediate repeat); N = 1, 2, 4, 8 parallel entries, 3 rounds, on the API and on the store.
"""
import json
import statistics
import subprocess
import sys
import time
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor

API = "https://api.worf.pl"
STORE = "https://worf.pl"
UA = "listing-measure-928"
out_path = sys.argv[1]
result = {"started": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "steps": {}}


def get(url):
    """One GET with curl: (status, time to first byte, total time)."""
    done = subprocess.run(
        ["curl", "-s", "-o", "/dev/null", "-m", "60", "-A", UA, "-w", "%{http_code} %{time_starttransfer} %{time_total}", url],
        capture_output=True, text=True)
    parts = done.stdout.split()
    if len(parts) != 3:
        return {"url": url, "status": 0, "ttfb": None, "total": None}
    return {"url": url, "status": int(parts[0]), "ttfb": float(parts[1]), "total": float(parts[2])}


def summary(samples):
    times = [s["ttfb"] for s in samples if s["ttfb"] is not None]
    statuses = {}
    for s in samples:
        statuses[str(s["status"])] = statuses.get(str(s["status"]), 0) + 1
    return {"n": len(samples), "median": round(statistics.median(times), 3) if times else None,
            "min": round(min(times), 3) if times else None, "max": round(max(times), 3) if times else None, "statuses": statuses}


def step(name, samples):
    result["steps"][name] = {"at": time.strftime("%H:%M:%SZ", time.gmtime()), "summary": summary(samples), "samples": samples}
    json.dump(result, open(out_path, "w"), ensure_ascii=False, indent=1)
    print(name, result["steps"][name]["summary"], flush=True)


def categories():
    request = urllib.request.Request(API + "/api/v1/categories", headers={"User-Agent": UA})
    tree = json.load(urllib.request.urlopen(request, timeout=30))
    return tree["items"] if isinstance(tree, dict) else tree


def find(nodes, name):
    for node in nodes:
        if node["name"] == name:
            return node
        hit = find(node.get("children") or [], name)
        if hit:
            return hit
    return None


roots = categories()
department = find(roots, "Bieganie")
leaf = find(roots, "Akcesoria biegowe")
result["categories"] = {"Bieganie": department and department["id"], "Akcesoria biegowe": leaf and leaf["id"]}

step("api_categories", [get(API + "/api/v1/categories") for _ in range(10)])
listing = {
    "api_all": API + "/api/v1/products",
    "api_department": API + "/api/v1/products?category=" + department["id"],
    "api_phrase": API + "/api/v1/products?q=" + urllib.parse.quote("buty biegowe"),
    "api_leaf": API + "/api/v1/products?category=" + leaf["id"],
}
for name, url in listing.items():
    step(name, [get(url) for _ in range(10)])

step("store_cold_pages", [get(f"{STORE}/?page={k}") for k in range(201, 211)])
cold, warm = [], []
for index in range(5):
    if index:
        time.sleep(90)
    cold.append(get(STORE + "/"))
    warm.append(get(STORE + "/"))
step("store_home_after_90s", cold)
step("store_home_immediate_repeat", warm)

page = {"api": 220, "store": 270}
for target, base in (("api", API + "/api/v1/products?page="), ("store", STORE + "/?page=")):
    for n in (1, 2, 4, 8):
        samples = []
        for _ in range(3):
            urls = [base + str(page[target] + i) for i in range(n)]
            page[target] += n
            with ThreadPoolExecutor(max_workers=n) as pool:
                samples.extend(pool.map(get, urls))
            time.sleep(2)
        step(f"{target}_parallel_{n}", samples)

result["finished"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
json.dump(result, open(out_path, "w"), ensure_ascii=False, indent=1)
print("finished", result["finished"])

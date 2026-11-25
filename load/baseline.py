#!/usr/bin/env python3
"""GET-only measurement of the cold and warm catalog path (performance program, PERF-0: W1–W4, W8).

Runs on the host (Python 3 standard library and curl), reads only, so it can point at worf.pl. One curl per request, a new
connection every time, so every sample carries the same network cost.

  baseline.py urls  API STORE OUT.json [--listings N] [--products N] [--seed S]
  baseline.py run   URLS.json OUT.jsonl --mode single
  baseline.py run   URLS.json OUT.jsonl --mode open  --rate R --duration S [--kinds k1,k2]
  baseline.py run   URLS.json OUT.jsonl --mode burst --n N [--rounds 3] [--pause 90] [--kinds k1,k2]
  baseline.py report OUT.jsonl

urls draws the mix from the data (fresh random addresses every call: a product page stays an hour in Varnish and in the
ISR cache of the store, so a list used before is not cold) and saves it. Listings: the home page, departments and their deep pages
(2–200), leaves, phrases, filters (option, price, availability, sort) and deep pages, as store pages and as API calls;
product pages as store pages and API calls. Reading the data touches only listings with perPage=48 (60 s in Varnish), the
category tree and the sitemap (not cached), never a product page of the mix.

run, single: every address once (cold), then at once again (warm). open: an open model, requests leave at a constant rate
whatever the earlier ones do, each address used once; a request that left more than 100 ms late is a failure. burst: N
addresses released together, rounds apart. Every sample keeps the status, the curl timings (DNS, TCP connect, TLS, first
byte, total), the cache headers and the contract check.

A sample counts only as a full response: 2xx and the contract (API listing: items, total, facets; API product: id; store
listing: the product grid or the empty result and the price facet; store product: JSON-LD of the product). Its cache state
is from the headers: X-Cache of Varnish (API), x-nextjs-cache of the store, cf-cache-status; a cold sample that shows a hit
is reported as not cold, not as a success of W1/W3.

Network estimate: the TCP connect time is one round trip; the time to the first byte minus the TLS end and one round trip
is the estimated server time. Both are estimates, reported next to the client times, never instead of them.

Exit codes: 0 done; 1 the run had failures (the samples are written); 2 wrong arguments, a missing file, the API down or
returning no data.
"""
import argparse
import json
import random
import statistics
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

UA = "perf-0-baseline"
STORE_SORTS = {"nazwa": "name", "cena-rosnaco": "price_asc", "cena-malejaco": "price_desc"}
CURL_FORMAT = "%{http_code} %{time_namelookup} %{time_connect} %{time_appconnect} %{time_starttransfer} %{time_total} %{size_download}"
HEADERS = ("x-cache", "x-nextjs-cache", "cf-cache-status", "cache-control", "age")


def fail(message):
    print(f"baseline: {message}", file=sys.stderr)
    sys.exit(2)


def api_json(api, path):
    request = urllib.request.Request(api + path, headers={"User-Agent": UA, "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.load(response)
    except (urllib.error.URLError, TimeoutError, ValueError) as error:
        fail(f"GET {api}{path} failed: {error}")


def nodes_of(tree, depth=1):
    for node in tree:
        children = node.get("children") or []
        yield {"id": node["id"], "path": node["path"], "depth": depth, "leaf": not children}
        yield from nodes_of(children, depth + 1)


def words_of(name):
    words = [w for w in name.lower().replace(",", " ").replace(".", " ").replace("-", " ").split() if len(w) >= 4]
    return words[:1] + ([" ".join(words[:2])] if len(words) >= 2 else [])


def build_urls(args):
    rng = random.Random(args.seed)
    nodes = list(nodes_of(api_json(args.api, "/api/v1/categories")))
    if not nodes:
        fail("the API returned no categories; is the data loaded?")
    departments = [n for n in nodes if n["depth"] == 1]
    leaves = [n for n in nodes if n["leaf"]]
    phrases, options, price = set(), set(), None
    for node in rng.sample(nodes, min(10, len(nodes))):
        listing = api_json(args.api, f"/api/v1/products?category={node['id']}&perPage=48")
        for item in listing.get("items", []):
            phrases.update(words_of(item["name"]))
        for axis in listing.get("facets", {}).get("options") or []:
            options.update(f"{axis['axis']}:{value['value']}" for value in axis["values"])
        if listing.get("facets", {}).get("price"):
            p = listing["facets"]["price"]
            price = p if price is None else {"min": min(price["min"], p["min"]), "max": max(price["max"], p["max"])}
    if not phrases:
        fail("the sampled categories have no products; is the data loaded?")
    phrases, options = sorted(phrases), sorted(options)

    entries, seen = [], set()

    def add(kind, store_path, api_path):
        if store_path in seen:
            return False
        seen.add(store_path)
        entries.append({"kind": kind, "target": "store", "path": store_path})
        entries.append({"kind": kind, "target": "api", "path": api_path})
        return True

    def join(base, part):
        return base + ("&" if "?" in base else "?") + part

    def listing(kind):
        page = (rng.randint(2, 5) if kind == "phrase_deep" else rng.randint(2, 200)) if kind.endswith("deep") else 1
        node = rng.choice(departments if kind.startswith("department") else leaves)
        if kind.startswith("home"):
            return f"/?page={page}", f"/api/v1/products?page={page}"
        if kind.startswith(("department", "leaf")) and not kind.endswith("filter"):
            suffix = f"?page={page}" if page > 1 else ""
            return f"/c/{node['path']}{suffix}", f"/api/v1/products?category={node['id']}" + (f"&page={page}" if page > 1 else "")
        if kind == "leaf_filter":
            store, api = f"/c/{node['path']}", f"/api/v1/products?category={node['id']}"
        else:
            phrase = urllib.parse.quote(rng.choice(phrases))
            store, api = f"/szukaj?q={phrase}", f"/api/v1/products?q={phrase}"
        if kind == "phrase":
            return store, api
        if kind == "phrase_deep":
            return join(store, f"page={page}"), join(api, f"page={page}")
        choices = ["available"] + (["option"] if options else []) + (["price"] if price else []) + ([] if kind == "leaf_filter" else ["sort"])
        choice = rng.choice(choices)
        if choice == "option":
            option = urllib.parse.quote(rng.choice(options))
            return join(store, f"o={option}"), join(api, f"option={option}")
        if choice == "price":
            span = price["max"] - price["min"]
            low = price["min"] + int(span * rng.uniform(0, 0.3))
            high = low + int(span * rng.uniform(0.05, 0.3))
            return join(store, f"cena-od={low // 100}&cena-do={high // 100}"), join(api, f"priceMin={low // 100 * 100}&priceMax={high // 100 * 100}")
        if choice == "available":
            return join(store, "dostepne=1"), join(api, "available=true")
        sort = rng.choice(list(STORE_SORTS))
        return join(store, f"sort={sort}"), join(api, f"sort={STORE_SORTS[sort]}")

    kinds = ["home", "home_deep", "department", "department_deep", "leaf", "leaf_filter", "phrase", "phrase_deep", "phrase_filter"]
    attempts = 0
    while sum(1 for e in entries if e["target"] == "store") < args.listings and attempts < args.listings * 20:
        attempts += 1
        add(kinds[len(entries) // 2 % len(kinds)], *listing(kinds[len(entries) // 2 % len(kinds)]))

    chunks = [c["key"] for c in api_json(args.api, "/api/v1/sitemap/product-chunks")["chunks"]]
    if not chunks:
        fail("the sitemap has no product chunks")
    slugs = set()
    for key in rng.sample(chunks, min(len(chunks), max(1, args.products // 50))):
        items = api_json(args.api, f"/api/v1/sitemap/products?chunk={urllib.parse.quote(key)}")["items"]
        slugs.update(item["slug"] for item in rng.sample(items, min(len(items), 50)))
    for slug in sorted(slugs)[: args.products]:
        add("product", f"/p/{urllib.parse.quote(slug)}", f"/api/v1/products/{urllib.parse.quote(slug)}")

    rng.shuffle(entries)
    result = {"api": args.api, "store": args.store, "seed": args.seed, "created": now(), "entries": entries}
    with open(args.out, "w") as file:
        json.dump(result, file, indent=1)
    counts = {}
    for e in entries:
        counts[e["kind"]] = counts.get(e["kind"], 0) + 1
    print(f"{len(entries)} addresses: {counts}")


def now():
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def contract(entry, body):
    try:
        if entry["target"] == "api":
            data = json.loads(body)
            if entry["kind"] == "product":
                return "id" in data
            return isinstance(data.get("items"), list) and isinstance(data.get("total"), int) and isinstance(data.get("facets"), dict)
        text = body.decode("utf-8", "replace")
        if entry["kind"] == "product":
            return '"@type":"Product"' in text.replace(" ", "")
        return ('data-testid="product-grid"' in text or "Nie znaleźliśmy produktów" in text) and "Cena (zł)" in text
    except (ValueError, AttributeError):
        return False


def fetch(base, entry, label, scheduled=None):
    started = time.monotonic()
    with tempfile.NamedTemporaryFile() as body, tempfile.NamedTemporaryFile(mode="r") as heads:
        done = subprocess.run(
            ["curl", "-s", "-m", "60", "-A", UA, "-H", "Accept-Encoding: gzip", "--compressed", "-o", body.name, "-D", heads.name,
             "-w", CURL_FORMAT, base + entry["path"]], capture_output=True, text=True)
        parts = done.stdout.split()
        headers = {}
        for line in heads.read().splitlines():
            name, _, value = line.partition(":")
            if name.strip().lower() in HEADERS:
                headers[name.strip().lower()] = value.strip()
        content = body.read()
    sample = {"label": label, "kind": entry["kind"], "target": entry["target"], "path": entry["path"], "at": now(), "headers": headers}
    if scheduled is not None:
        sample["late"] = round(started - scheduled, 3)
    if len(parts) != 7:
        return {**sample, "status": 0, "ok": False, "error": done.stderr.strip()[:200] or f"curl exit {done.returncode}"}
    status = int(parts[0])
    dns, connect, tls, ttfb, total = (float(p) for p in parts[1:6])
    rtt = connect - dns
    sample.update({"status": status, "dns": dns, "connect": connect, "tls": tls, "ttfb": ttfb, "total": total, "bytes": int(parts[6]),
                   "rtt": round(rtt, 4), "server_est": round(max(0.0, ttfb - max(tls, connect) - rtt), 4)})
    sample["ok"] = 200 <= status < 300 and contract(entry, content) and (scheduled is None or sample["late"] <= 0.1)
    return sample


def run(args):
    try:
        with open(args.urls) as file:
            urls = json.load(file)
    except (OSError, ValueError) as error:
        fail(f"cannot read {args.urls}: {error}")
    kinds = set(args.kinds.split(",")) if args.kinds else None
    entries = [e for e in urls["entries"] if kinds is None or e["kind"] in kinds]
    if args.target:
        entries = [e for e in entries if e["target"] == args.target]
    if not entries:
        fail("no addresses of the given kinds and target")
    base = {"api": urls["api"], "store": urls["store"]}
    lock, samples = threading.Lock(), []

    def record(sample):
        with lock:
            samples.append(sample)
            out.write(json.dumps(sample, ensure_ascii=False) + "\n")
            out.flush()

    with open(args.out, "a") as out:
        if args.mode == "single":
            for entry in entries:
                record(fetch(base[entry["target"]], entry, "cold"))
                record(fetch(base[entry["target"]], entry, "warm"))
        elif args.mode == "open":
            if not args.rate or not args.duration:
                fail("open needs --rate and --duration")
            count = int(args.rate * args.duration)
            if count > len(entries):
                fail(f"open needs {count} unused addresses, the list has {len(entries)}; run urls with more --listings")
            threads, start = [], time.monotonic() + 1
            for i, entry in enumerate(entries[:count]):
                at = start + i / args.rate
                time.sleep(max(0.0, at - time.monotonic()))
                thread = threading.Thread(target=lambda e=entry, a=at: record(fetch(base[e["target"]], e, f"open-{args.rate}", a)))
                thread.start()
                threads.append(thread)
            for thread in threads:
                thread.join()
        else:
            if not args.n or args.n * args.rounds > len(entries):
                fail(f"burst needs --n and {args.n} x {args.rounds} unused addresses, the list has {len(entries)}")
            for round_no in range(args.rounds):
                chosen = entries[round_no * args.n:(round_no + 1) * args.n]
                barrier = threading.Barrier(len(chosen))

                def go(entry):
                    barrier.wait()
                    record(fetch(base[entry["target"]], entry, f"burst-{args.n}-r{round_no + 1}"))

                threads = [threading.Thread(target=go, args=(e,)) for e in chosen]
                for thread in threads:
                    thread.start()
                for thread in threads:
                    thread.join()
                if round_no + 1 < args.rounds:
                    time.sleep(args.pause)
    failures = sum(1 for s in samples if not s["ok"])
    print(f"{len(samples)} samples, {failures} failures, written to {args.out}")
    sys.exit(1 if failures else 0)


def pct(values, p):
    values = sorted(values)
    return round(values[min(len(values) - 1, int(round(p / 100 * (len(values) - 1))))], 3) if values else None


def report(args):
    groups = {}
    try:
        with open(args.samples) as file:
            for line in file:
                s = json.loads(line)
                cached = any(s["headers"].get(h, "").upper().startswith("HIT") for h in ("x-cache", "x-nextjs-cache", "cf-cache-status"))
                groups.setdefault((s["label"], s["target"], s["kind"]), []).append((s, cached))
    except (OSError, ValueError) as error:
        fail(f"cannot read {args.samples}: {error}")
    print("label\ttarget\tkind\tn\tfull\tcold_hit\tp50\tp95\tp99\tmax\tserver_est_p50\tserver_est_p95\trtt_p50\tstatuses")
    for (label, target, kind), rows in sorted(groups.items()):
        full = [s for s, _ in rows if s["ok"]]
        statuses = {}
        for s, _ in rows:
            statuses[s["status"]] = statuses.get(s["status"], 0) + 1
        totals = [s["total"] for s in full]
        est = [s["server_est"] for s in full]
        hits = sum(1 for _, c in rows if c) if label != "warm" else "-"
        print("\t".join(str(v) for v in (label, target, kind, len(rows), len(full), hits, pct(totals, 50), pct(totals, 95), pct(totals, 99),
                                          pct(totals, 100), pct(est, 50), pct(est, 95), pct([s["rtt"] for s, _ in rows if "rtt" in s], 50), statuses)))


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)
    u = sub.add_parser("urls")
    u.add_argument("api")
    u.add_argument("store")
    u.add_argument("out")
    u.add_argument("--listings", type=int, default=300)
    u.add_argument("--products", type=int, default=150)
    u.add_argument("--seed", type=int, default=int(time.time()))
    r = sub.add_parser("run")
    r.add_argument("urls")
    r.add_argument("out")
    r.add_argument("--mode", choices=["single", "open", "burst"], required=True)
    r.add_argument("--rate", type=float)
    r.add_argument("--duration", type=float)
    r.add_argument("--n", type=int)
    r.add_argument("--rounds", type=int, default=3)
    r.add_argument("--pause", type=float, default=90)
    r.add_argument("--kinds")
    r.add_argument("--target", choices=["store", "api"])
    p = sub.add_parser("report")
    p.add_argument("samples")
    args = parser.parse_args()
    {"urls": build_urls, "run": run, "report": report}[args.command](args)


if __name__ == "__main__":
    main()

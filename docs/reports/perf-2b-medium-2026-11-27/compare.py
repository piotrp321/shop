#!/usr/bin/env python3
"""PERF-2B-8: the comparison of GET /api/v1/products before and after PERF-2 stage B (criterion 10, second part).

Host Python 3, standard library only, GET only. Not a measurement tool: it saves the answers of the API (not Varnish) to the
addresses of a mix of load/baseline.py and compares two saved sets.

  compare.py fetch URLS.json API_BASE OUT.jsonl [--workers N]   every API listing of the mix (not the product pages), the full
                                                                body of each answer, one line per address, in the order of the mix
  compare.py digest ANSWERS.jsonl OUT.json                      a compact digest (total, facet sizes, hash of the whole answer)
  compare.py diff BEFORE.jsonl AFTER.jsonl [--out RESULT.json]  per address: status, total, pageCount, facets (categories,
                                                                options, price, availableCount) and the items of the page
  compare.py singles BEFORE.jsonl AFTER.jsonl                   the times of two runs of baseline.py --mode single side by side
                                                                (p50, p95, max of the total time per label and kind; LOCAL, preliminary)
  compare.py selftest                                           the diff on synthetic answers: must see every kind of difference

fetch exit codes: 0 every answer is 200, 1 some answer is not (all are written). diff: 0 no differences, 1 differences (listed,
also in RESULT.json), 2 wrong arguments or unreadable input. A list that differs only in order is reported as "order" (a
difference, not hidden): the criterion is the same results, and the order of facets and items is part of the answer. The order of
the relevance sort is comparable only with the same number of shards, so both stacks run with SEARCH_SHARDS=1 (run.sh).
"""
import argparse
import hashlib
import json
import sys
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor

UA = "perf-2b-8-compare"


def fail(message, code=2):
    print(f"compare: {message}", file=sys.stderr)
    sys.exit(code)


def listing_paths(urls_file):
    try:
        with open(urls_file) as file:
            entries = json.load(file)["entries"]
    except (OSError, ValueError, KeyError) as error:
        fail(f"cannot read {urls_file}: {error}")
    # Listings only: the product pages (kind "product") are not search results.
    paths = [e["path"] for e in entries if e["target"] == "api" and e["kind"] != "product"]
    if not paths:
        fail(f"{urls_file} has no API listing addresses")
    return paths


def get(base, path):
    request = urllib.request.Request(base + path, headers={"User-Agent": UA, "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            status, text = response.status, response.read().decode("utf-8")
    except urllib.error.HTTPError as error:
        status, text = error.code, error.read().decode("utf-8", "replace")
    except (urllib.error.URLError, TimeoutError, OSError) as error:
        return {"path": path, "status": 0, "body": f"transport error: {error}"}
    try:
        body = json.loads(text)
    except ValueError:
        body = text
    return {"path": path, "status": status, "body": body}


def fetch(args):
    paths = listing_paths(args.urls)
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        answers = list(pool.map(lambda p: get(args.api, p), paths))
    bad = [a for a in answers if a["status"] != 200]
    with open(args.out, "w") as out:
        for answer in answers:
            out.write(json.dumps(answer, ensure_ascii=False, sort_keys=True) + "\n")
    print(f"{len(answers)} answers written to {args.out}, {len(bad)} not 200")
    for answer in bad[:10]:
        print(f"  {answer['status']} {answer['path']}", file=sys.stderr)
    return 1 if bad else 0


def read_answers(file_name):
    try:
        with open(file_name) as file:
            return [json.loads(line) for line in file if line.strip()]
    except (OSError, ValueError) as error:
        fail(f"cannot read {file_name}: {error}")


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def digest(args):
    result = []
    for answer in read_answers(args.answers):
        body = answer["body"] if isinstance(answer["body"], dict) else {}
        facets = body.get("facets") or {}
        result.append({
            "path": answer["path"], "status": answer["status"], "total": body.get("total"), "pageCount": body.get("pageCount"),
            "categories": len(facets.get("categories") or []), "axes": len(facets.get("options") or []),
            "price": facets.get("price"), "availableCount": facets.get("availableCount"),
            "items": len(body.get("items") or []), "sha256": hashlib.sha256(canonical(answer["body"]).encode()).hexdigest()[:16],
        })
    with open(args.out, "w") as out:
        json.dump(result, out, ensure_ascii=False, indent=0)
    print(f"digest of {len(result)} answers written to {args.out}")
    return 0


def unordered(value):
    """The value with every list (at any depth) sorted, so that only the order of elements is ignored."""
    if isinstance(value, list):
        return sorted((unordered(v) for v in value), key=canonical)
    if isinstance(value, dict):
        return {k: unordered(v) for k, v in value.items()}
    return value


def compare_values(before, after):
    """equal | order (the same elements in another order, at any depth) | different."""
    if canonical(before) == canonical(after):
        return "equal"
    if canonical(unordered(before)) == canonical(unordered(after)):
        return "order"
    return "different"


def compare_answers(before, after):
    """Returns {field: verdict} for the fields that differ; empty when the answers are identical."""
    if before["status"] != after["status"]:
        return {"status": f"{before['status']} -> {after['status']}"}
    b, a = before["body"], after["body"]
    if not (isinstance(b, dict) and isinstance(a, dict)):
        return {} if canonical(b) == canonical(a) else {"body": "different"}
    differences = {}
    for key in sorted(set(b) | set(a)):
        if key == "facets":
            continue
        if key == "items":
            verdict = compare_values([i.get("id") for i in b.get(key) or []], [i.get("id") for i in a.get(key) or []])
            if verdict == "equal":
                verdict = "equal" if compare_values(b.get(key), a.get(key)) == "equal" else "items-content"
            else:
                verdict = f"ids-{verdict}"
        else:
            verdict = compare_values(b.get(key), a.get(key))
        if verdict != "equal":
            differences[key] = verdict
    bf, af = b.get("facets") or {}, a.get("facets") or {}
    for key in sorted(set(bf) | set(af)):
        verdict = compare_values(bf.get(key), af.get(key))
        if verdict != "equal":
            differences[f"facets.{key}"] = verdict
    return differences


def describe(before, after, field):
    """A short example of a difference for the listing in the output."""
    b, a = before["body"], after["body"]
    if field.startswith("facets."):
        b, a, field = b.get("facets") or {}, a.get("facets") or {}, field[len("facets."):]
    bv, av = b.get(field), a.get(field)
    if isinstance(bv, list) and isinstance(av, list):
        return f"{len(bv)} -> {len(av)} elements"
    return f"{canonical(bv)[:80]} -> {canonical(av)[:80]}"


def diff(args):
    before, after = read_answers(args.before), read_answers(args.after)
    if [x["path"] for x in before] != [x["path"] for x in after]:
        fail("the two sets have different addresses or another order; both come from fetch of the same mix")
    rows, by_field = [], {}
    for b, a in zip(before, after):
        fields = compare_answers(b, a)
        for field, verdict in fields.items():
            by_field.setdefault(field, {}).setdefault(verdict, 0)
            by_field[field][verdict] += 1
        if fields:
            rows.append({"path": b["path"], "fields": fields,
                         "examples": {f: describe(b, a, f) for f in fields if f not in ("status", "body")}})
    not_ok = sum(1 for x in before + after if x["status"] != 200)
    result = {"addresses": len(before), "identical": len(before) - len(rows), "different": len(rows), "not_200": not_ok,
              "by_field": by_field, "differences": rows}
    if args.out:
        with open(args.out, "w") as out:
            json.dump(result, out, ensure_ascii=False, indent=1)
    print(f"{result['addresses']} addresses: {result['identical']} identical, {result['different']} different, "
          f"{not_ok} answers other than 200")
    for field, verdicts in sorted(by_field.items()):
        print(f"  {field}: {verdicts}")
    for row in rows[:20]:
        print(f"  {row['path']}: {row['fields']} {row['examples']}")
    if len(rows) > 20:
        print(f"  ... and {len(rows) - 20} more (RESULT.json has all)")
    return 1 if rows or not_ok else 0


def singles(args):
    def load(file_name):
        groups = {}
        for sample in read_answers(file_name):
            if sample.get("ok"):
                groups.setdefault((sample["label"], sample["kind"]), []).append(sample["total"])
                groups.setdefault((sample["label"], "ALL"), []).append(sample["total"])
        return groups

    def quantile(values, share):
        values = sorted(values)
        return values[min(len(values) - 1, int(share * len(values)))]

    before, after = load(args.before), load(args.after)
    print("label\tkind\tn\tp50 before\tp50 after\tp95 before\tp95 after\tmax before\tmax after")
    for key in sorted(set(before) & set(after), key=lambda k: (k[0], k[1] == "ALL", k[1])):
        b, a = before[key], after[key]
        print(f"{key[0]}\t{key[1]}\t{len(b)}/{len(a)}\t" + "\t".join(
            f"{x:.3f}" for x in (quantile(b, .5), quantile(a, .5), quantile(b, .95), quantile(a, .95), max(b), max(a))))
    return 0


def synthetic(total=10, items=("a", "b"), categories=(("c1", 5), ("c2", 5)), values=(("S", 4), ("M", 6)), price=(100, 900), available=7):
    return {"path": "/api/v1/products", "status": 200, "body": {
        "items": [{"id": i, "name": i} for i in items], "page": 1, "perPage": 24, "total": total, "pageCount": 1,
        "facets": {"categories": [{"id": i, "count": c} for i, c in categories],
                   "options": [{"axis": "Rozmiar", "values": [{"value": v, "count": c} for v, c in values]}],
                   "price": {"min": price[0], "max": price[1]}, "availableCount": available}}}


def selftest(_args):
    base = synthetic()
    content = synthetic()
    content["body"]["items"][0]["name"] = "other"
    cases = [
        ("identical", synthetic(), {}),
        ("total", synthetic(total=11), {"total": "different"}),
        ("facet count", synthetic(values=(("S", 4), ("M", 5))), {"facets.options": "different"}),
        ("facet order", synthetic(values=(("M", 6), ("S", 4))), {"facets.options": "order"}),
        ("category count", synthetic(categories=(("c1", 5), ("c2", 4))), {"facets.categories": "different"}),
        ("price range", synthetic(price=(100, 901)), {"facets.price": "different"}),
        ("available count", synthetic(available=6), {"facets.availableCount": "different"}),
        ("first page order", synthetic(items=("b", "a")), {"items": "ids-order"}),
        ("first page ids", synthetic(items=("a", "c")), {"items": "ids-different"}),
        ("item content", content, {"items": "items-content"}),
        ("status", {"path": base["path"], "status": 500, "body": {}}, {"status": "200 -> 500"}),
    ]
    failures = 0
    for name, changed, expected in cases:
        found = compare_answers(base, changed)
        ok = found == expected
        failures += not ok
        print(f"{'ok  ' if ok else 'FAIL'} {name}: {found}" + ("" if ok else f" (expected {expected})"))
    return 1 if failures else 0


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="command", required=True)
    f = sub.add_parser("fetch")
    f.add_argument("urls")
    f.add_argument("api")
    f.add_argument("out")
    f.add_argument("--workers", type=int, default=4)
    f.set_defaults(run=fetch)
    g = sub.add_parser("digest")
    g.add_argument("answers")
    g.add_argument("out")
    g.set_defaults(run=digest)
    d = sub.add_parser("diff")
    d.add_argument("before")
    d.add_argument("after")
    d.add_argument("--out")
    d.set_defaults(run=diff)
    s = sub.add_parser("singles")
    s.add_argument("before")
    s.add_argument("after")
    s.set_defaults(run=singles)
    sub.add_parser("selftest").set_defaults(run=selftest)
    args = parser.parse_args()
    sys.exit(args.run(args))


if __name__ == "__main__":
    main()

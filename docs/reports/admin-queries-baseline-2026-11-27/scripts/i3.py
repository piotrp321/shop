#!/usr/bin/env python3
"""I3 of the review of PERF-7-1: phrases of 2 characters and of punctuation WITH hits (the matrix had only phrases without hits), for F0, F1 and F2.

  i3.py pick  --db DB --out DIR     draws the phrases from the names (a sample): 2-character strings and strings with punctuation, with the exact
                                    number of hits, one of the rare class (up to ~300), one of the medium class (~2 000 - 20 000) and one of the common
                                    class (over the bound of the count), and the place of the first 26 hits in the order of the names
  i3.py cases --db DB --phrases FILE --out DIR     the statements: F0 (the present one: page and count), F1 (the walk of 20 000 rows, then the trigram candidates),
                                    F2 (the bigram candidates); run them with `matrix.py run --cases DIR/cases.json`
  i3.py table --results DIR         the table: median ms on medium, buffers, hits, the estimate for `server` in memory

The class of a phrase decides the path of F2, so `show_trgm` of the phrase is part of the output of `pick`: a phrase from which pg_trgm extracts a trigram
goes by the trigram index of F1; one without a trigram goes by the bigrams of F2.
"""
import argparse
import json
import os
import random
import re
import statistics
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import matrix  # noqa: E402
import variants  # noqa: E402

lit, like_escape, rows = matrix.lit, matrix.like_escape, matrix.rows


def pick(db, out):
    rng = random.Random(5)
    names = [r[0] for r in rows(db, "SELECT name FROM catalog_product TABLESAMPLE SYSTEM (0.3) LIMIT 4000")]
    seen = {}
    for name in names:
        low = name.lower()
        for i in range(len(low) - 1):
            pair = low[i : i + 2]
            seen[pair] = seen.get(pair, 0) + 1
    letters = [p for p in seen if re.fullmatch(r"[a-ząćęłńóśźż]{2}", p)]
    punctuation = [p for p in seen if re.search(r"[^a-ząćęłńóśźż0-9 ]", p) and p.strip() == p and len(p) == 2]
    chosen = []
    rng.shuffle(letters)
    rng.shuffle(punctuation)
    result = []
    for kind, pool in (("two-letters", letters[:60]), ("punctuation", punctuation[:40])):
        counted = []
        for term in pool:
            hits = int(rows(db, matrix.exact_count("catalog_product p", matrix.product_conditions(term=term)))[0][0])
            counted.append((hits, term))
        for label, low, high in (("rare", 5, 400), ("medium", 1500, 20000), ("common", 100000, 10**9)):
            found = [c for c in counted if low <= c[0] <= high]
            if found:
                hits, term = sorted(found)[len(found) // 2]
                trigrams = rows(db, f"SELECT show_trgm({lit(term)})")[0][0]
                first = rows(db, f"SELECT count(*) FROM (SELECT name FROM catalog_product ORDER BY name, id LIMIT 1000000) w WHERE false")  # placeholder keeps the structure simple
                del first
                result.append({"label": f"{kind}-{label}", "term": term, "hits": hits, "show_trgm": trigrams})
    os.makedirs(out, exist_ok=True)
    json.dump(result, open(os.path.join(out, "phrases.json"), "w"), ensure_ascii=False, indent=1)
    for item in result:
        print(item)


def cases(db, phrases_file, out):
    phrases = json.load(open(phrases_file, encoding="utf-8"))
    store = matrix.Cases(db)
    store.totals = {"products": int(rows(db, "select count(*) from catalog_product")[0][0]), "orders": 0, "customers": 0, "phrases": phrases}
    for item in phrases:
        label, term, hits = item["label"], item["term"], item["hits"]
        conditions = matrix.product_conditions(term=term)
        for sort in ("name", "-updatedAt"):
            store.cases.append({"id": f"F0.{label}.p1.{sort}", "group": "phrase", "title": f"present, {label} '{term}', {sort}", "sql": matrix.product_page(conditions, sort), "variable": "hits", "value": hits, "scale": "products"})
            store.cases.append({"id": f"F1.{label}.p1.{sort}.walk", "group": "phrase", "title": f"walk, {label} '{term}', {sort}", "sql": variants.f1_walk(term, sort, 20000), "variable": "budget", "value": 20000, "scale": "const"})
            store.cases.append({"id": f"F1.{label}.p1.{sort}.gin", "group": "phrase", "title": f"trigram candidates, {label} '{term}', {sort}", "sql": variants.f1_gin(term, sort, False), "variable": "hits", "value": hits, "scale": "products"})
            store.cases.append({"id": f"F2.{label}.p1.{sort}.gin", "group": "phrase", "title": f"bigram candidates, {label} '{term}', {sort}", "sql": variants.f1_gin(term, sort, True), "variable": "hits", "value": hits, "scale": "products"})
        store.cases.append({"id": f"F0.{label}.count", "group": "phrase", "title": f"present count, {label} '{term}'", "sql": matrix.bounded_count("catalog_product p", conditions), "variable": "hits", "value": hits, "scale": "products"})
        store.cases.append({"id": f"F2.{label}.count", "group": "phrase", "title": f"bigram count, {label} '{term}'", "sql": variants.f1_count(term, True), "variable": "hits", "value": hits, "scale": "products"})
    os.makedirs(out, exist_ok=True)
    json.dump({"db": db, "totals": store.totals, "cases": store.cases}, open(os.path.join(out, "cases.json"), "w"), ensure_ascii=False, indent=1)
    print(len(store.cases), "cases")


def table(directory):
    d = json.load(open(os.path.join(directory, "results.json"), encoding="utf-8"))
    totals = d["totals"]
    growth = 10_000_191 / totals["products"]
    print("| fraza | trafień (medium → server) | obecne p1 name / -updatedAt (ms) | licznik (ms) | F1: spacer + kandydaci trygramy name / -updatedAt (ms) | F2: spacer + kandydaci bigramy name / -updatedAt (ms) | licznik F2 (ms) | server w pamięci: obecne / F1 / F2 (ms, name) |")
    print("|---|---|---|---|---|---|---|---|")
    labels = []
    for case_id in d["cases"]:
        label = case_id.split(".")[1]
        if label not in labels:
            labels.append(label)

    def med(case_id):
        e = d["cases"].get(case_id)
        if not e or not e["runs"]:
            return None
        runs = e["runs"]
        return statistics.median([r["ms"] for r in runs[1:]] or [runs[0]["ms"]])

    def filled(case_id):
        e = d["cases"].get(case_id)
        return e["runs"][-1]["returned"] >= 26

    for label in labels:
        value = next(e["value"] for k, e in d["cases"].items() if k.split(".")[1] == label and e["variable"] == "hits")
        term = next(e["title"] for k, e in d["cases"].items() if k.split(".")[1] == label)
        cells = {}
        for sort in ("name", "-updatedAt"):
            walk = med(f"F1.{label}.p1.{sort}.walk")
            full = filled(f"F1.{label}.p1.{sort}.walk")
            cells[("f1", sort)] = walk + (0 if full else med(f"F1.{label}.p1.{sort}.gin"))
            cells[("f2", sort)] = walk + (0 if full else med(f"F2.{label}.p1.{sort}.gin"))
        now = med(f"F0.{label}.p1.name")
        server = (now * growth, cells[("f1", "name")] * (growth if not filled(f"F1.{label}.p1.name.walk") else 1), cells[("f2", "name")] * (growth if not filled(f"F1.{label}.p1.name.walk") else 1))
        print(f"| {label} ({term.split(',')[1].strip()}) | {value} → {round(value * growth)} | {now:.1f} / {med(f'F0.{label}.p1.-updatedAt'):.1f} | {med(f'F0.{label}.count'):.1f} | {cells[('f1','name')]:.1f} / {cells[('f1','-updatedAt')]:.1f} | {cells[('f2','name')]:.1f} / {cells[('f2','-updatedAt')]:.1f} | {med(f'F2.{label}.count'):.1f} | {server[0]:.0f} / {server[1]:.0f} / {server[2]:.0f} |")


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)
    a = sub.add_parser("pick")
    a.add_argument("--db", required=True)
    a.add_argument("--out", required=True)
    b = sub.add_parser("cases")
    b.add_argument("--db", required=True)
    b.add_argument("--phrases", required=True)
    b.add_argument("--out", required=True)
    c = sub.add_parser("table")
    c.add_argument("--results", required=True)
    args = parser.parse_args()
    if args.command == "pick":
        pick(args.db, args.out)
    elif args.command == "cases":
        cases(args.db, args.phrases, args.out)
    else:
        table(args.results)


if __name__ == "__main__":
    main()

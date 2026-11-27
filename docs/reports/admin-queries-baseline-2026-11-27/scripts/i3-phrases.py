#!/usr/bin/env python3
"""The phrases of I3 (after i3-synthetic.sql): punctuation and 2 characters, rare, medium and common, with the exact hits and show_trgm. Usage: i3-phrases.py DB OUT.json"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import matrix  # noqa: E402

db, out = sys.argv[1], sys.argv[2]
items = [("punct-rare", "#!#"), ("punct-medium", "a.b"), ("two-rare", "qx"), ("two-medium", "gg"), ("two-common", "ze")]
result = []
for label, term in items:
    hits = int(matrix.rows(db, matrix.exact_count("catalog_product p", matrix.product_conditions(term=term)))[0][0])
    trigrams = matrix.rows(db, f"SELECT show_trgm({matrix.lit(term)})")[0][0]
    result.append({"label": label, "term": term, "hits": hits, "show_trgm": trigrams})
    print(result[-1])
os.makedirs(os.path.dirname(out), exist_ok=True)
json.dump(result, open(out, "w"), ensure_ascii=False, indent=1)

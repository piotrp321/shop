#!/usr/bin/env python3
"""Checks that the statement of a prototype returns the same rows in the same order as the present statement (P7, in the prototype).

  equiv.py --db DB --base CASES.json --variant CASES.json [--pairs ID=BASEID,...]

Without --pairs the variant cases `<V>.<label>.cat.p1.<sort>`, `.p2.<sort>` and `<V>.<label>.count` are compared with `P-cat-<label>.p1.<sort>`,
`.p2.<sort>` and `.count`, and the phrase cases `<V>.<label>.p1.<sort>.gin` with `P-term-<label>.p1.<sort>`. Pages compare the ids in order,
counts compare the numbers. Prints one line per pair; exit code 1 when a pair differs.
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import matrix  # noqa: E402


def result(db, sql):
    code, out, err = matrix.psql(db, sql + ";")
    if code != 0:
        return "ERROR " + err.strip()[:120]
    return [line.split("|")[0] for line in out.splitlines() if line]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--db", required=True)
    parser.add_argument("--base", required=True)
    parser.add_argument("--variant", required=True)
    args = parser.parse_args()
    base = {c["id"]: c for c in json.load(open(args.base, encoding="utf-8"))["cases"]}
    variant = json.load(open(args.variant, encoding="utf-8"))["cases"]
    differ = 0
    checked = 0
    for case in variant:
        parts = case["id"].split(".", 1)[1].split(".")
        label = parts[0]
        if "cat" in parts and parts[1] == "cat":
            target = "P-cat-" + label + "." + ".".join(parts[2:])
        elif parts[-1] == "gin":
            target = "P-term-" + label + "." + ".".join(parts[1:-1])
        elif parts[-1] == "count" and len(parts) == 2:
            target = "P-term-" + label + ".count"
        else:
            continue
        if target not in base:
            continue
        # The cursors of the pages 2 differ in no way: both are drawn from the page 1 of the present statement.
        a, b = result(args.db, base[target]["sql"]), result(args.db, case["sql"])
        # A page holds the 26 rows; the ids are compared up to the length of the shorter list when the statement of the variant
        # is the candidate step only (it returns a full page too), so equality is exact.
        checked += 1
        same = a == b
        if not same:
            differ += 1
        print(("same  " if same else "DIFF  ") + f"{case['id']} vs {target}" + ("" if same else f": {str(a)[:80]} / {str(b)[:80]}"))
    print(f"equiv: {checked} pairs checked, {differ} differ")
    sys.exit(1 if differ else 0)


if __name__ == "__main__":
    main()

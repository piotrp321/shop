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
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import matrix  # noqa: E402


def result(db, sql):
    code, out, err = matrix.psql(db, sql + ";")
    if code != 0:
        return "ERROR " + err.strip()[:120]
    return [line.split("|")[0] for line in out.splitlines() if line]


CURSOR = re.compile(r"\(p\.updated_at, p\.id\) < \(CAST\('([^']*)' AS timestamptz\), CAST\('([^']*)' AS uuid\)\)|\(p\.name, p\.id\) > \('((?:[^']|'')*)', CAST\('([^']*)' AS uuid\)\)")


def with_cursor_of(base_sql, variant_sql):
    """The cursors of the pages 2 were drawn from the page 1 of each statement on the database of its time (the prototype database has
    had writes since the matrix was drawn), so the statement of the present SQL gets the cursor of the variant: the same question."""
    wanted = CURSOR.search(variant_sql)
    if wanted is None or CURSOR.search(base_sql) is None:
        return base_sql
    return CURSOR.sub(lambda _: wanted.group(0), base_sql, count=1)


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
    totals = json.load(open(args.base, encoding="utf-8"))["totals"]
    chosen = {c["label"]: c["id"] for c in totals.get("chosen_categories", [])}
    extras_of = {"cat+brand": dict(term="pro"), "cat+drafts": dict(status="draft"), "cat+noimages": dict(no_images=True)}
    for case in variant:
        parts_ = case["id"].split(".")
        if len(parts_) >= 4 and parts_[2] in extras_of and parts_[1] in chosen:
            # The combinations of a category with another filter: the present statement is built here from the same filters.
            prefix = matrix.rows(args.db, f"SELECT path FROM catalog_category WHERE id = '{chosen[parts_[1]]}'")[0][0]
            conditions = matrix.product_conditions(prefix=prefix, **extras_of[parts_[2]])
            if parts_[3] == "count":
                base_sql = matrix.bounded_count("catalog_product p", conditions)
            else:
                base_sql = matrix.product_page(conditions, "-updatedAt" if case["id"].endswith("-updatedAt") else "name")
            a, b = result(args.db, base_sql), result(args.db, case["sql"])
            checked += 1
            if a != b:
                differ += 1
            print(("same  " if a == b else "DIFF  ") + f"{case['id']} vs the present statement of the combination" + ("" if a == b else f": {str(a)[:80]} / {str(b)[:80]}"))
            continue
        parts = case["id"].split(".", 1)[1].split(".")
        label = parts[0]
        if "cat" in parts and parts[1] == "cat":
            target = "P-cat-" + label + "." + ".".join(parts[2:])
        elif label.startswith(("O-", "C-")):
            target = ("O-frag-" if label.startswith("O-") else "C-frag-") + label[2:] + "." + ".".join(parts[1:])
        elif parts[-1] == "walk":
            target = "P-term-" + label + "." + ".".join(parts[1:-1])
            if target in base:
                a, b = result(args.db, base[target]["sql"]), result(args.db, case["sql"])
                if isinstance(b, list) and len(b) < 26:
                    print(f"skip  {case['id']}: the walk does not fill the page ({len(b)} rows), the candidates take over")
                    continue
                checked += 1
                differ += 0 if a == b else 1
                print(("same  " if a == b else "DIFF  ") + f"{case['id']} (a full page of the walk) vs {target}")
            continue
        elif parts[-1] == "gin":
            target = "P-term-" + label + "." + ".".join(parts[1:-1])
        elif parts[-1] == "count" and len(parts) == 2:
            target = "P-term-" + label + ".count"
        else:
            continue
        if target not in base:
            continue
        # The cursors of the pages 2 differ in no way: both are drawn from the page 1 of the present statement.
        a, b = result(args.db, with_cursor_of(base[target]["sql"], case["sql"])), result(args.db, case["sql"])
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

#!/usr/bin/env python3
"""PERF-7-3, R2d: the department with a phrase (rare, medium, frequent), page 1, on the COPY shop_proto of `medium`: the present way of
PhraseSearch (the walk of the budgets over the whole table, then the candidates of the GIN index with the category condition of the source)
against the present statement before it. Every statement of every step with its time and buffers (EXPLAIN (ANALYZE, BUFFERS), median of 3).

  r2d.py --db shop_proto --out DIR
"""
import argparse
import importlib.util
import os
import sys

here = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location("pr", os.path.join(here, "phrase-regressions.py"))
pr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pr)

DEPARTMENT = "d7c098d0-4bae-595e-a1e1-e4a923448956"
CATEGORY = (
    "(p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {prefix}) OR EXISTS (SELECT 1 FROM catalog_product_category pc "
    "WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {prefix})))"
)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--db", default="shop_proto")
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    if args.db == "shop":
        sys.exit("use the copy shop_proto")
    os.makedirs(args.out, exist_ok=True)
    prefix = pr.lit("/" + DEPARTMENT + "/%")
    category = CATEGORY.format(prefix=prefix)
    table = ["| fraza (trafień w katalogu / w dziale) | sort | obecne przed PhraseSearch: ms / bufory | PhraseSearch razem: ms / bufory | kroki (ms / bufory) | te same wiersze |", "|---|---|---|---|---|---|"]
    for term in ("ravotar", "snowboardowe", "rowerowa", "zupa"):
        hits = int(pr.value(args.db, f"SELECT count(*) FROM catalog_product p WHERE p.name ILIKE {pr.pattern(term)}")[0])
        in_department = int(pr.value(args.db, f"SELECT count(*) FROM catalog_product p WHERE p.name ILIKE {pr.pattern(term)} AND {category}")[0])
        for sort in ("name", "-updatedAt"):
            column, direction = pr.sort_parts(sort)
            tag = f"r2d-{term}-{sort}"
            present = f"SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE {pr.pattern(term)} AND {category} ORDER BY p.{column} {direction}, p.id {direction} LIMIT {pr.PAGE}"
            pm, pb = pr.median(pr.explain(args.db, present, False, tag + "-present", args.out))
            expected = pr.value(args.db, present)
            steps, total_ms, total_b, budget, level = [], 0.0, 0, pr.BUDGET, 0
            while True:
                extra = ", w0.updated_at" if column == "updated_at" else ""
                walk = (
                    f"SELECT p.id, p.name, p.tax_class, p.updated_at FROM (SELECT w0.id, w0.name{extra} FROM catalog_product w0 ORDER BY w0.{column} {direction}, w0.id {direction} LIMIT {budget}) w "
                    f"JOIN catalog_product p ON p.id = w.id WHERE w.name ILIKE {pr.pattern(term)} AND {category} ORDER BY w.{column} {direction}, w.id {direction} LIMIT {pr.PAGE}"
                )
                ms, b = pr.median(pr.explain(args.db, walk, False, f"{tag}-L{level}-walk", args.out))
                total_ms, total_b = total_ms + ms, total_b + b
                steps.append(f"L{level} walk B={budget}: {ms:.1f} / {b}")
                got = pr.value(args.db, walk)
                if len(got) >= pr.PAGE:
                    found = got
                    break
                cap = max(1, budget // 2)
                cand = (
                    f"WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE {pr.candidate_condition(term)} AND {category}) "
                    f"SELECT c.id, c.name, c.tax_class, c.updated_at, count(*) OVER () AS candidates FROM (SELECT * FROM m LIMIT {cap + 1}) c ORDER BY c.{column} {direction}, c.id {direction} LIMIT {pr.PAGE}"
                )
                ms, b = pr.median(pr.explain(args.db, cand, True, f"{tag}-L{level}-candidates", args.out))
                total_ms, total_b = total_ms + ms, total_b + b
                steps.append(f"L{level} candidates cap={cap}: {ms:.1f} / {b}")
                rows = pr.value(args.db, cand, True)
                if not rows or int(rows[0].rsplit("|", 1)[-1]) <= cap:
                    found = [row.rsplit("|", 1)[0] for row in rows]
                    break
                budget *= 10
                level += 1
            same = [r.split("|")[0] for r in found] == [r.split("|")[0] for r in expected]
            table.append(f"| `{term}` ({hits} / {in_department}) | {sort} | {pm:.1f} / {pb} | {total_ms:.1f} / {total_b} | {'; '.join(steps)} | {'tak' if same else 'NIE'} |")
    pr.summary(args.out, "r2d", table)


if __name__ == "__main__":
    main()

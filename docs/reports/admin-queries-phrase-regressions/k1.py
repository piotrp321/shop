#!/usr/bin/env python3
"""PERF-7-3: the statements of CategoryList (K1) on the COPY shop_proto of `medium`, with the indexes of the migration Version20261128100000
(build them first: the six CREATE INDEX of the migration). Page 1 and the count, of the department, a small category and a big leaf, whole,
drafts and without images, against the statement before K1; time and buffers of the top node (EXPLAIN (ANALYZE, BUFFERS), median of 3).

  k1.py --db shop_proto --out DIR
"""
import argparse
import importlib.util
import os
import sys

here = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location("pr", os.path.join(here, "phrase-regressions.py"))
pr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pr)

LIMIT = 26


def members(columns, where, prefix):
    return (
        f"SELECT DISTINCT {columns} FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id WHERE pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {prefix}) "
        f"AND p.primary_category_id NOT IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {prefix}){where}"
    )


def page(prefix, sort, where):
    column, direction = pr.sort_parts(sort)
    return (
        f"WITH additional AS MATERIALIZED ({members('p.id, p.name, p.tax_class, p.updated_at', where, prefix)}) "
        f"SELECT u.id, u.name, u.tax_class, u.updated_at FROM (SELECT m.id, m.name, m.tax_class, m.updated_at FROM catalog_category c CROSS JOIN LATERAL "
        f"(SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.primary_category_id = c.id{where} ORDER BY p.{column} {direction}, p.id {direction} LIMIT {LIMIT}) m "
        f"WHERE c.path LIKE {prefix} UNION ALL SELECT a.id, a.name, a.tax_class, a.updated_at FROM (SELECT * FROM additional ORDER BY {column} {direction}, id {direction} LIMIT {LIMIT}) a) u "
        f"ORDER BY u.{column} {direction}, u.id {direction} LIMIT {LIMIT}"
    )


def count(prefix, where):
    counted = " ORDER BY p.name, p.id" if where else ""
    return (
        f"WITH additional AS MATERIALIZED ({members('p.id', where, prefix)}) "
        f"SELECT count(*) FROM (SELECT 1 FROM (SELECT 1 FROM catalog_category c CROSS JOIN LATERAL (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = c.id{where}{counted} LIMIT 10001) m "
        f"WHERE c.path LIKE {prefix} UNION ALL SELECT 1 FROM additional) u LIMIT 10001) bounded"
    )


def category(prefix):
    return (
        f"(p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {prefix}) OR EXISTS (SELECT 1 FROM catalog_product_category pc "
        f"WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {prefix})))"
    )


def present_page(prefix, sort, where):
    column, direction = pr.sort_parts(sort)
    return f"SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE {category(prefix)}{where} ORDER BY p.{column} {direction}, p.id {direction} LIMIT {LIMIT}"


def present_count(prefix, where):
    return f"SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE {category(prefix)}{where} LIMIT 10001) bounded"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--db", default="shop_proto")
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    if args.db == "shop":
        sys.exit("use the copy shop_proto")
    os.makedirs(args.out, exist_ok=True)
    db = args.db
    dept = pr.value(db, "SELECT path FROM catalog_category WHERE id = 'd7c098d0-4bae-595e-a1e1-e4a923448956'")[0]
    small = pr.value(db, "SELECT path FROM catalog_category WHERE name = 'Piłka nożna' LIMIT 1")[0]
    big = pr.value(db, "SELECT path FROM catalog_category WHERE name = 'Stroje pływackie' LIMIT 1")[0]
    cases = {"dział": dept, "mała (Piłka nożna)": small, "duża późna (Stroje pływackie)": big}
    filters = {"": "", " + szkice": " AND p.status = 'draft'", " + bez zdjęć": " AND p.images = '[]'::jsonb"}
    table = ["| kategoria | filtr | przypadek | obecne: ms / bufory | K1: ms / bufory | te same wiersze |", "|---|---|---|---|---|---|"]
    for name, path in cases.items():
        prefix = pr.lit(path + "%")
        for flabel, where in filters.items():
            for sort in ("name", "-updatedAt"):
                tag = f"k1-{name}-{flabel}-{sort}".replace(" ", "_")
                old = pr.median(pr.explain(db, present_page(prefix, sort, where), False, tag + "-present", args.out))
                new = pr.median(pr.explain(db, page(prefix, sort, where), False, tag, args.out))
                same = [r.split("|")[0] for r in pr.value(db, present_page(prefix, sort, where))] == [r.split("|")[0] for r in pr.value(db, page(prefix, sort, where))]
                table.append(f"| {name} | {flabel or 'brak'} | str. 1 {sort} | {old[0]:.1f} / {old[1]} | {new[0]:.1f} / {new[1]} | {'tak' if same else 'NIE'} |")
            tag = f"k1-{name}-{flabel}-count".replace(" ", "_")
            old = pr.median(pr.explain(db, present_count(prefix, where), False, tag + "-present", args.out))
            new = pr.median(pr.explain(db, count(prefix, where), False, tag, args.out))
            same = pr.value(db, present_count(prefix, where)) == pr.value(db, count(prefix, where))
            table.append(f"| {name} | {flabel or 'brak'} | licznik | {old[0]:.1f} / {old[1]} | {new[0]:.1f} / {new[1]} | {'tak' if same else 'NIE'} |")
    pr.summary(args.out, "k1", table)


if __name__ == "__main__":
    main()

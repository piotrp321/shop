#!/usr/bin/env python3
"""The cheap checks of R1 and R2 of the review of PERF-7-1 (sections 10.2 of the report): the cases, on the COPY shop_proto.

  r-checks.py cases --db shop_proto --out DIR     DIR/cases.json: R1 (the index-only walk to the 26th hit, no budget, for six frequent phrases) and R2 (the department with a
                                                  rare and a medium phrase, with drafts, without images, the small category with drafts: the K1 statements; run them
                                                  BEFORE and AFTER the partial indexes of r-checks.sql with `matrix.py run`)
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import matrix  # noqa: E402
import variants  # noqa: E402

lit, like_escape, rows = matrix.lit, matrix.like_escape, matrix.rows
DEPARTMENT = "d7c098d0-4bae-595e-a1e1-e4a923448956"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--db", required=True)
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    db = args.db
    cases = []

    def add(case_id, title, sql, variable="hits", value=0, scale="products"):
        cases.append({"id": case_id, "group": "check", "title": title, "sql": sql, "variable": variable, "value": value, "scale": scale})

    # R1: the walk of the index (name, id) without a heap read, to the 26th hit, no budget (the index-only subquery, then the filter)
    for label, term in (("rowerowa", "rowerowa"), ("snowboardowe", "snowboardowe"), ("pływacki", "pływacki"), ("zupa", "zupa"), ("źródło", "źródło"), ("bagażnik", "bagażnik")):
        hits = int(rows(db, matrix.exact_count("catalog_product p", matrix.product_conditions(term=term)))[0][0])
        pattern = lit("%" + like_escape(term) + "%")
        add(f"R1.{label}.walk-to-26", f"R1 walk to the 26th hit of '{term}' ({hits} hits), index only", f"SELECT id FROM (SELECT id, name FROM catalog_product ORDER BY name, id) w WHERE name ILIKE {pattern} LIMIT 26", value=hits)

    # R2: the K1 statements for the department with other filters, and the small category with drafts
    prefix = rows(db, f"SELECT path FROM catalog_category WHERE id = '{DEPARTMENT}'")[0][0]
    ids = variants.ids_of(db, prefix)
    small = rows(db, "SELECT id, path FROM catalog_category WHERE name = 'Piłka nożna' LIMIT 1")[0]
    small_ids = variants.ids_of(db, small[1])
    for label, extras in (("rare", ["p.name ILIKE '%ravotar%'"]), ("medium", ["p.name ILIKE '%snowboardowe%'"]), ("drafts", ["p.status = 'draft'"]), ("noimages", ["p.images = '[]'::jsonb"]), ("drafts-noimages", ["p.status = 'draft'", "p.images = '[]'::jsonb"])):
        for sort in ("name", "-updatedAt"):
            page, count = variants.k1_page(ids, extras, sort, None)
            add(f"R2.department.{label}.p1.{sort}", f"R2 department + {label}, page 1, sort {sort} (K1)", page)
        add(f"R2.department.{label}.count", f"R2 department + {label}, count (K1)", count)
    for sort in ("name", "-updatedAt"):
        add(f"R2.small.drafts.p1.{sort}", f"R2 small category + drafts, sort {sort} (K1)", variants.k1_page(small_ids, ["p.status = 'draft'"], sort, None)[0])
    # R2a: the additional-only products of the department, as the K1 statement reads them (the join of the memberships with the products)
    add("R2a.department.additional-only.join", "R2a the additional-only products of the department: memberships joined to the products (K1 today)",
        f"SELECT DISTINCT p.id FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id WHERE pc.category_id = ANY({variants.uuid_array(ids)}) AND NOT (p.primary_category_id = ANY({variants.uuid_array(ids)}))", value=41770)
    os.makedirs(args.out, exist_ok=True)
    json.dump({"db": db, "totals": {"products": int(rows(db, "select count(*) from catalog_product")[0][0]), "orders": 0, "customers": 0}, "cases": cases}, open(os.path.join(args.out, "cases.json"), "w"), ensure_ascii=False, indent=1)
    print(len(cases), "cases")


if __name__ == "__main__":
    main()

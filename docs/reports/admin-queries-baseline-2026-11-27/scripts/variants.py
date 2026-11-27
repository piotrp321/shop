#!/usr/bin/env python3
"""The statements of the prototype variants of PERF-7-1 (K0, K1, F1, F2, Z1), for the cases of the matrix; run them with `matrix.py run`.

  variants.py gen --db DB --base CASES.json --variant K0|K1|F1|F2|Z1 --out DIR [--budget B]

The cases are the ones the matrix drew (same categories, phrases and fragments), so a variant is compared with the present
statement case by case. `--budget B` is the budget of the walk of F1 (rows of the index of the sort).

K0  the filter of the category as two branches (the main category and the additional ones) joined by UNION, then sort and LIMIT.
K1  K0 + btree (primary_category_id, name, id) and (primary_category_id, updated_at, id): one ordered branch per category of the
    subtree with LIMIT, merged; the additional categories as one branch ordered after the membership rows.
F1  the walk of the index of the sort for at most B rows, then (when the page is not full) the candidates of the trigram index, sorted;
    both steps are cases of their own (<id>.walk, <id>.gin) and the table adds them up when the walk does not fill the page.
F2  F1 + a GIN index on perf_bigrams(name): the candidates for a phrase without a trigram from the bigrams of the phrase.
Z1  a GIN index on bigrams of the expressions of the search of the orders and the customers: the candidates of a fragment from its bigrams.
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import matrix  # noqa: E402

lit, like_escape, rows = matrix.lit, matrix.like_escape, matrix.rows
PAGE = matrix.PAGE


def ids_of(db, prefix):
    return [r[0] for r in rows(db, f"SELECT id FROM catalog_category WHERE path LIKE {lit(like_escape(prefix) + '%')} ORDER BY path")]


def uuid_array(ids):
    return lit("{" + ",".join(ids) + "}") + "::uuid[]"


def sort_parts(sort):
    return ("p.updated_at", "DESC") if sort == "-updatedAt" else ("p.name", "ASC")


def cursor_condition(sort, after):
    if after is None:
        return []
    value, ident = after
    if sort == "-updatedAt":
        return [f"(p.updated_at, p.id) < (CAST({lit(value)} AS timestamptz), CAST({lit(ident)} AS uuid))"]
    return [f"(p.name, p.id) > ({lit(value)}, CAST({lit(ident)} AS uuid))"]


def k0_page(prefix, extras, sort, after):
    category = (
        "p.id IN (SELECT id FROM catalog_product WHERE primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {0}) "
        "UNION SELECT product_id FROM catalog_product_category WHERE category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {0}))"
    ).format(lit(like_escape(prefix) + "%"))
    return matrix.product_page([category] + extras + cursor_condition(sort, after), sort), matrix.bounded_count("catalog_product p", [category] + extras)


def k1_page(ids, extras, sort, after):
    column, direction = sort_parts(sort)
    where_extra = "".join(f" AND {condition}" for condition in extras + cursor_condition(sort, after))
    order = f"ORDER BY {column} {direction}, p.id {direction} LIMIT {PAGE + 1}"
    select = "SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p"
    branches = [f"({select} WHERE p.primary_category_id = '{category_id}'{where_extra} {order})" for category_id in ids]
    branches.append(f"({select} WHERE p.id IN (SELECT product_id FROM catalog_product_category WHERE category_id = ANY({uuid_array(ids)})){where_extra} {order})")
    key = "updated_at DESC, id DESC" if sort == "-updatedAt" else "name, id"
    distinct = "updated_at, id" if sort == "-updatedAt" else "name, id"
    page = f"SELECT DISTINCT ON ({distinct}) id, name, tax_class, updated_at FROM (" + " UNION ALL ".join(branches) + f") u ORDER BY {key} LIMIT {PAGE + 1}"
    extra = "".join(f" AND {condition}" for condition in extras)
    count = (
        f"SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = ANY({uuid_array(ids)}){extra} UNION ALL "
        f"SELECT 1 FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id WHERE pc.category_id = ANY({uuid_array(ids)}) "
        f"AND NOT (p.primary_category_id = ANY({uuid_array(ids)})){extra} LIMIT {matrix.BOUND + 1}) bounded"
    )
    return page, count


def f1_walk(term, sort, budget):
    pattern = lit("%" + like_escape(term) + "%")
    column, direction = ("updated_at", "DESC") if sort == "-updatedAt" else ("name", "ASC")
    inner = f"SELECT id, name, updated_at FROM catalog_product ORDER BY {column} {direction}, id {direction} LIMIT {budget}"
    return (
        f"SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.id IN (SELECT w.id FROM ({inner}) w WHERE w.name ILIKE {pattern}) "
        f"ORDER BY p.{column} {direction}, p.id {direction} LIMIT {PAGE + 1}"
    )


def f1_gin(term, sort, bigrams=False):
    pattern = lit("%" + like_escape(term) + "%")
    column, direction = ("updated_at", "DESC") if sort == "-updatedAt" else ("name", "ASC")
    candidate = f"perf_bigrams(p.name) @> perf_bigrams({lit(term)}) AND " if bigrams else ""
    return (
        f"WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE {candidate}p.name ILIKE {pattern}) "
        f"SELECT * FROM m ORDER BY {column} {direction}, id {direction} LIMIT {PAGE + 1}"
    )


def f1_count(term, bigrams=False):
    pattern = lit("%" + like_escape(term) + "%")
    candidate = f"perf_bigrams(p.name) @> perf_bigrams({lit(term)}) AND " if bigrams else ""
    return (
        f"WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE {candidate}p.name ILIKE {pattern}) "
        f"SELECT count(*) FROM (SELECT * FROM m LIMIT {matrix.BOUND + 1}) bounded"
    )


def z1_order(fragment, sort):
    expression = "lower(o.number || E'\\x1f' || o.email || E'\\x1f' || o.full_name)"
    return z1_conditions(fragment, expression)


def z1_conditions(fragment, expression):
    escaped = lit(like_escape(fragment))
    return [f"perf_bigrams({expression}) @> perf_bigrams(lower({lit(fragment)}))", f"{expression} LIKE '%' || lower({escaped}) || '%'"]


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)
    gen = sub.add_parser("gen")
    gen.add_argument("--db", required=True)
    gen.add_argument("--base", required=True)
    gen.add_argument("--variant", required=True, choices=["K0", "K1", "F1", "F2", "Z1"])
    gen.add_argument("--out", required=True)
    gen.add_argument("--budget", type=int, default=20000)
    args = parser.parse_args()
    with open(args.base, encoding="utf-8") as handle:
        base = json.load(handle)
    db, totals = args.db, base["totals"]
    cases = matrix.Cases(db)
    cases.totals = {**totals, "variant": args.variant, "budget": args.budget}

    def add(case_id, group, title, sql, variable, value, scale):
        cases.add(f"{args.variant}.{case_id}", group, title, sql, variable, value, scale)

    by_id = {c["id"]: c for c in base["cases"]}
    if args.variant in ("K0", "K1"):
        for chosen in totals["chosen_categories"]:
            label = chosen["label"]
            prefix = rows(db, f"SELECT path FROM catalog_category WHERE id = '{chosen['id']}'")[0][0]
            ids = ids_of(db, prefix)
            groups = [("cat", [], f"category {label} ({chosen['products']} products)", f"P-cat-{label}", chosen["products"])]
            small = next((c for c in totals["chosen_categories"] if c["label"] == "small"), None)
            groups.append(("cat+brand", None, f"category {label} + common phrase", f"P-cat-{label}", chosen["products"]))
            groups.append(("cat+drafts", ["p.status = 'draft'"], f"category {label} + drafts", f"P-cat-{label}", chosen["products"]))
            groups.append(("cat+noimages", ["p.images = '[]'::jsonb"], f"category {label} + without images", f"P-cat-{label}", chosen["products"]))
            for key, extras, title, source, value in groups:
                if extras is None:
                    extras = ["p.name ILIKE '%pro%'"]
                for sort in ("name", "-updatedAt"):
                    after = None
                    page, count = (k0_page(prefix, extras, sort, after) if args.variant == "K0" else k1_page(ids, extras, sort, after))
                    add(f"{label}.{key}.p1.{sort}", "category", f"{title}, page 1, sort {sort}", page, "products in the subtree", value, "products")
                    kind = "product-name" if sort == "name" else "product-updated"
                    cursor = matrix.cursor_after(db, page, kind)
                    if cursor and key == "cat":
                        page2 = (k0_page(prefix, extras, sort, cursor) if args.variant == "K0" else k1_page(ids, extras, sort, cursor))[0]
                        add(f"{label}.{key}.p2.{sort}", "category", f"{title}, page 2, sort {sort}", page2, "products in the subtree", value, "products")
                add(f"{label}.{key}.count", "category", f"{title}, count", count, "products in the subtree", value, "products")
    elif args.variant in ("F1", "F2"):
        bigrams = args.variant == "F2"
        for item in totals["phrases"]:
            label, term = item["label"], item["term"]
            value = by_id[f"P-term-{label}.count"]["value"]
            for sort in ("name", "-updatedAt"):
                add(f"{label}.p1.{sort}.walk", "phrase", f"phrase {label} '{term}', sort {sort}: the walk of {args.budget} rows", f1_walk(term, sort, args.budget), "budget", args.budget, "const")
                add(f"{label}.p1.{sort}.gin", "phrase", f"phrase {label} '{term}', sort {sort}: the candidates {'from the bigrams' if bigrams else 'from the trigrams'}", f1_gin(term, sort, bigrams), "hits", value, "products")
            add(f"{label}.count", "phrase", f"phrase {label} '{term}', count by the candidates", f1_count(term, bigrams), "hits", value, "products")
    else:
        for group, table, alias, build_page, expression_fn in (
            ("orders", "ordering_order o", "o", matrix.order_page, lambda f: z1_order(f, None)),
        ):
            for item in [c for c in base["cases"] if c["id"].startswith("O-frag-") and c["id"].endswith(".p1") and "'" in c["title"]]:
                label = item["id"][len("O-frag-"):-len(".p1")]
                fragment = item["title"].split("'")[1]
                conditions = expression_fn(fragment)
                add(f"O-{label}.p1", "orders", f"orders, fragment {label} '{fragment}', page 1 by the bigrams", matrix.order_page(conditions, "-placedAt"), "hits", item["value"], "orders")
                add(f"O-{label}.count", "orders", f"orders, fragment {label} '{fragment}', count by the bigrams", matrix.bounded_count("ordering_order o", conditions), "hits", item["value"], "orders")
        for item in [c for c in base["cases"] if c["id"].startswith("C-frag-") and c["id"].endswith(".p1") and "'" in c["title"]]:
            label = item["id"][len("C-frag-"):-len(".p1")]
            fragment = item["title"].split("'")[1]
            email = z1_conditions(fragment, "lower(c.email)")
            name = z1_conditions(fragment, "lower(c.full_name)")
            conditions = [f"(({email[0]} AND {email[1]}) OR ({name[0]} AND {name[1]}))"]
            add(f"C-{label}.p1", "customers", f"customers, fragment {label} '{fragment}', page 1 by the bigrams", matrix.customer_page(conditions, "-lastOrderAt"), "hits", item["value"], "customers")
            add(f"C-{label}.count", "customers", f"customers, fragment {label} '{fragment}', count by the bigrams", matrix.bounded_count("ordering_customer c", conditions), "hits", item["value"], "customers")

    os.makedirs(os.path.join(args.out, "sql"), exist_ok=True)
    for case in cases.cases:
        with open(os.path.join(args.out, "sql", case["id"] + ".sql"), "w", encoding="utf-8") as handle:
            handle.write(f"-- {case['title']}\n-- cost variable: {case['variable']} = {case['value']} (scales with {case['scale']})\n{case['sql']};\n")
    with open(os.path.join(args.out, "cases.json"), "w", encoding="utf-8") as handle:
        json.dump({"db": db, "totals": cases.totals, "cases": cases.cases}, handle, ensure_ascii=False, indent=1)
    print(f"variants: {len(cases.cases)} cases of {args.variant} in {args.out}")


if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""The matrix of the panel queries of PERF-7-1: the cases are drawn from the data, run with EXPLAIN (ANALYZE, BUFFERS) and tabled.

Python 3 standard library only, on the host; the database is reached with `docker exec -i <container> psql` (the container of
the stack of the task, PG_CONTAINER, default perf7-postgres-1). The statements are those of the application, with the values
inlined (the application sends them as bound parameters of a statement planned for that execution, so the plan is the same):
DbalBackofficeProductSource (page and BoundedCounter count), DbalBackofficeOrderReader, DbalCustomerReader, the lists of discount
codes, the counters of the dashboard, DbalSitemapProductSource and DbalCategoryProductCounts.

  matrix.py gen   --db shop --out DIR                 draws the cases from the data: DIR/cases.json and DIR/sql/<id>.sql
  matrix.py run   --db shop --cases FILE --out DIR [--repeats 10] [--slow-repeats 3] [--slow-ms 2000] [--only TEXT]
                                                       every case: 1 first run + repeats-1 repeated runs in one session, DIR/results.json,
                                                       DIR/explain/<id>.out (the text plan of the last run)
  matrix.py table --results DIR [--results DIR2]       the markdown table of the results

The first run of a case is "first" (the shared buffers hold what the cases before it left; the page cache of the host is warm,
the host cache cannot be dropped without touching the other stacks), the others are "repeated". Time is Execution Time of
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF), so without the cost of the clock of every node; buffers and rows are the measure that does
not depend on the machine.
"""
import argparse
import json
import os
import re
import statistics
import subprocess
import sys
import time

CONTAINER = os.environ.get("PG_CONTAINER", "perf7-postgres-1")
BOUND = 10000
PAGE = 25


def fail(message):
    print(f"matrix: {message}", file=sys.stderr)
    sys.exit(2)


def psql(db, script, timeout=3600):
    result = subprocess.run(
        ["docker", "exec", "-i", CONTAINER, "psql", "-U", "postgres", "-d", db, "-At", "-v", "ON_ERROR_STOP=1", "-q"],
        input=script, capture_output=True, text=True, timeout=timeout,
    )
    return result.returncode, result.stdout, result.stderr


def rows(db, sql):
    code, out, err = psql(db, sql)
    if code != 0:
        fail(f"query failed: {err.strip()[:300]} :: {sql[:200]}")
    return [line.split("|") for line in out.splitlines() if line != ""]


def lit(text):
    return "'" + text.replace("'", "''") + "'"


def like_escape(text):
    return text.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")


# ---------------------------------------------------------------- the statements of the application

CATEGORY = (
    "(p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {prefix}) OR EXISTS (SELECT 1 FROM catalog_product_category pc "
    "WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE {prefix})))"
)


def product_conditions(term=None, prefix=None, status=None, no_images=False):
    conditions = []
    if term is not None:
        conditions.append(f"p.name ILIKE {lit('%' + like_escape(term) + '%')}")
    if prefix is not None:
        conditions.append(CATEGORY.format(prefix=lit(like_escape(prefix) + "%")))
    if status is not None:
        conditions.append(f"p.status = '{status}'")
    if no_images:
        conditions.append("p.images = '[]'::jsonb")
    return conditions


def product_page(conditions, sort, after=None, limit=PAGE + 1):
    column, direction = ("p.updated_at", "DESC") if sort == "-updatedAt" else ("p.name", "ASC")
    conditions = list(conditions)
    if after is not None:
        value, ident = after
        if sort == "-updatedAt":
            conditions.append(f"(p.updated_at, p.id) < (CAST({lit(value)} AS timestamptz), CAST({lit(ident)} AS uuid))")
        else:
            conditions.append(f"(p.name, p.id) > ({lit(value)}, CAST({lit(ident)} AS uuid))")
    where = " WHERE " + " AND ".join(conditions) if conditions else ""
    return f"SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p{where} ORDER BY {column} {direction}, p.id {direction} LIMIT {limit}"


def bounded_count(table, conditions):
    where = " WHERE " + " AND ".join(conditions) if conditions else ""
    return f"SELECT count(*) FROM (SELECT 1 FROM {table}{where} LIMIT {BOUND + 1}) bounded"


def exact_count(table, conditions):
    where = " WHERE " + " AND ".join(conditions) if conditions else ""
    return f"SELECT count(*) FROM {table}{where}"


ORDER_COLUMNS = (
    "o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, "
    "(SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, "
    "o.refund_amount, o.refund_due_at, o.refund_completed_at"
)
ORDER_FRAGMENT = "lower(o.number || E'\\x1f' || o.email || E'\\x1f' || o.full_name) LIKE '%' || lower({}) || '%'"
ORDER_SORTS = {"-placedAt": ("o.placed_at", "DESC", "timestamptz"), "placedAt": ("o.placed_at", "ASC", "timestamptz"), "-total": ("o.total_gross", "DESC", "bigint"), "total": ("o.total_gross", "ASC", "bigint")}


def order_conditions(fragment=None, status=None, number=None, email=None, placed_from=None, placed_before=None):
    conditions = []
    if status:
        conditions.append(f"o.status = {lit(status)}")
    if number:
        conditions.append(f"o.number = {lit(number)}")
    if email:
        conditions.append(f"lower(o.email) = lower({lit(email)})")
    if fragment:
        conditions.append(ORDER_FRAGMENT.format(lit(like_escape(fragment))))
    if placed_from:
        conditions.append(f"o.placed_at >= {lit(placed_from)}")
    if placed_before:
        conditions.append(f"o.placed_at < {lit(placed_before)}")
    return conditions


def order_page(conditions, sort, after=None):
    column, direction, kind = ORDER_SORTS[sort]
    conditions = list(conditions)
    if after is not None:
        operator = "<" if direction == "DESC" else ">"
        conditions.append(f"({column}, o.id) {operator} (CAST({lit(after[0])} AS {kind}), CAST({lit(after[1])} AS uuid))")
    where = " WHERE " + " AND ".join(conditions) if conditions else ""
    return f"SELECT {ORDER_COLUMNS} FROM ordering_order o{where} ORDER BY {column} {direction}, o.id {direction} LIMIT {PAGE + 1}"


CUSTOMER_COLUMNS = "c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name"
CUSTOMER_FRAGMENT = "(lower(c.email) LIKE '%' || lower({0}) || '%' OR lower(c.full_name) LIKE '%' || lower({0}) || '%')"
CUSTOMER_SORTS = {"-lastOrderAt": ("c.last_order_at", True, "timestamptz"), "name": ("lower(c.full_name)", False, "text"), "-orderCount": ("c.order_count", True, "int"), "-paidGross": ("c.paid_gross", True, "bigint")}


def customer_conditions(fragment=None):
    return [CUSTOMER_FRAGMENT.format(lit(like_escape(fragment)))] if fragment else []


def customer_page(conditions, sort, after=None):
    column, descending, kind = CUSTOMER_SORTS[sort]
    conditions = list(conditions)
    if after is not None:
        value, ident = after
        if descending:
            conditions.append(f"{column} <= CAST({lit(value)} AS {kind}) AND ({column} < CAST({lit(value)} AS {kind}) OR c.customer_id > {lit(ident)})")
        else:
            conditions.append(f"({column}, c.customer_id) > (CAST({lit(value)} AS {kind}), {lit(ident)})")
    where = " WHERE " + " AND ".join(conditions) if conditions else ""
    return f"SELECT {CUSTOMER_COLUMNS} FROM ordering_customer c{where} ORDER BY {column} {'DESC' if descending else 'ASC'}, c.customer_id LIMIT {PAGE + 1}"


# ---------------------------------------------------------------- drawing the cases


class Cases:
    def __init__(self, db):
        self.db = db
        self.cases = []
        self.totals = {}

    def add(self, case_id, group, title, sql, variable, value, scale):
        self.cases.append({"id": case_id, "group": group, "title": title, "sql": sql, "variable": variable, "value": value, "scale": scale})

    def one(self, sql):
        found = rows(self.db, sql)
        return found[0] if found else None


def cursor_after(db, page_sql, kind):
    """The cursor of the page that follows the page of PAGE rows: the last row of it, read by the page query itself."""
    code, out, err = psql(db, page_sql.replace(f"LIMIT {PAGE + 1}", f"LIMIT {PAGE}"))
    if code != 0:
        fail(f"cursor query failed: {err[:200]}")
    lines = [line for line in out.splitlines() if line]
    if len(lines) < PAGE:
        return None
    columns = lines[-1].split("|")
    if kind == "product-name":
        return columns[1], columns[0]
    if kind == "product-updated":
        return columns[3], columns[0]
    if kind == "order-placed":
        return columns[2], columns[0]
    if kind == "order-total":
        return columns[6], columns[0]
    if kind == "customer-lastorder":
        return columns[5], columns[0]
    if kind == "customer-name":
        return columns[2].lower(), columns[0]
    if kind == "customer-count":
        return columns[3], columns[0]
    if kind == "customer-gross":
        return columns[4], columns[0]
    raise ValueError(kind)


def draw(db, out):
    cases = Cases(db)
    totals = {
        "products": int(rows(db, "select count(*) from catalog_product")[0][0]),
        "orders": int(rows(db, "select count(*) from ordering_order")[0][0]),
        "customers": int(rows(db, "select count(*) from ordering_customer")[0][0]),
        "categories": int(rows(db, "select count(*) from catalog_category")[0][0]),
    }
    cases.totals = totals
    print(f"matrix: {db}: {totals}", file=sys.stderr)

    # ---- categories by size: the products of the subtree (the main category or an additional one) and where its first name stands
    sized = rows(db, """
        SELECT c.id, c.path, c.name, (SELECT count(DISTINCT x.pid) FROM catalog_category s
              CROSS JOIN LATERAL (SELECT id AS pid FROM catalog_product WHERE primary_category_id = s.id
                                  UNION ALL SELECT product_id FROM catalog_product_category WHERE category_id = s.id) x
              WHERE s.path LIKE c.path || '%') AS products,
            (SELECT count(*) FROM catalog_category s WHERE s.parent_id = c.id) AS children,
            (SELECT count(DISTINCT pc.product_id) FROM catalog_category s JOIN catalog_product_category pc ON pc.category_id = s.id
              JOIN catalog_product p ON p.id = pc.product_id
              WHERE s.path LIKE c.path || '%' AND NOT EXISTS (SELECT 1 FROM catalog_category m WHERE m.id = p.primary_category_id AND m.path LIKE c.path || '%')) AS additional_only
        FROM catalog_category c ORDER BY c.path""")
    categories = [{"id": r[0], "path": r[1], "name": r[2], "products": int(r[3]), "leaf": r[4] == "0", "additional_only": int(r[5]), "root": r[1].count("/") == 2} for r in sized]
    leaves = sorted((c for c in categories if c["leaf"] and c["products"] > 0), key=lambda c: -c["products"])
    chosen = []

    def pick(label, category, why):
        if category is not None and all(category["id"] != c["id"] for _, c, _ in chosen):
            chosen.append((label, category, why))

    pick("empty", next((c for c in categories if c["products"] == 0), None), "no product")
    pick("small", next((c for c in leaves if 4 <= c["products"] <= 20), None), "4-20 products")
    mid = [c for c in leaves if 1000 <= c["products"] <= 5000 * max(1, totals["products"] // 1_000_000)] or [c for c in leaves if 100 <= c["products"] <= 5000]
    mid = mid or leaves[len(leaves) // 3 : len(leaves) // 3 + 1]
    if mid:
        pick("mid", mid[len(mid) // 2], "a middle category (1-5 thousand products on medium)")
    big = [c for c in leaves if c["products"] >= max(500, totals["products"] // 100)] or leaves[:3]
    if big:
        # The place of the first name of the subtree in the order of all the names, as the share of the products that sort before it.
        for category in big:
            category["first_name"] = rows(db, f"SELECT min(p.name) FROM catalog_product p WHERE {CATEGORY.format(prefix=lit(like_escape(category['path']) + '%'))}")[0][0]
            before = int(rows(db, f"SELECT count(*) FROM catalog_product WHERE name < {lit(category['first_name'])}")[0][0])
            category["alphabet"] = before / totals["products"]
        ordered = sorted(big, key=lambda c: c["alphabet"])
        pick("big-early", ordered[0], f"a big leaf whose names start at {ordered[0]['alphabet']:.0%} of the alphabet")
        pick("big-late", ordered[-1], f"a big leaf whose names start at {ordered[-1]['alphabet']:.0%} of the alphabet")
    roots = sorted((c for c in categories if c["root"]), key=lambda c: -c["products"])
    if roots:
        pick("department", roots[0], "the biggest department")
    additional = sorted((c for c in categories if c["products"] >= 20 and c["additional_only"] > 0), key=lambda c: -c["additional_only"] / c["products"])
    if additional:
        pick("mostly-additional", additional[0], f"{additional[0]['additional_only']} of {additional[0]['products']} products only through an additional category")
    cases.totals["chosen_categories"] = [{"label": l, **{k: v for k, v in c.items() if k != "path"}, "why": w} for l, c, w in chosen]

    def product_family(prefix_id, group, title, conditions, scale, variable, exact_sql=None):
        value = int(rows(db, exact_sql or exact_count("catalog_product p", conditions))[0][0])
        for sort in ("name", "-updatedAt"):
            page1 = product_page(conditions, sort)
            cases.add(f"{prefix_id}.p1.{sort}", group, f"{title}, page 1, sort {sort}", page1, variable, value, scale)
            kind = "product-name" if sort == "name" else "product-updated"
            after = cursor_after(db, page1, kind)
            if after:
                cases.add(f"{prefix_id}.p2.{sort}", group, f"{title}, page 2, sort {sort}", product_page(conditions, sort, after), variable, value, scale)
        cases.add(f"{prefix_id}.count", group, f"{title}, count of the first page", bounded_count("catalog_product p", conditions), variable, value, scale)

    # ---- the lists without a filter: the two sorts of the matrix and a deep page
    product_family("P-default", "lists", "all products", [], "const", "page", "SELECT 25")
    middle = rows(db, f"SELECT p.name, p.id FROM catalog_product p ORDER BY p.name, p.id OFFSET {totals['products'] // 2} LIMIT 1")[0]
    cases.add("P-default.deep.name", "lists", "all products, a page in the middle of the names", product_page([], "name", (middle[0], middle[1])), "page", PAGE, "const")

    # ---- categories
    for label, category, why in chosen:
        prefix = category["path"]
        product_family(f"P-cat-{label}", "category", f"category {label} ({why}; {category['products']} products)", product_conditions(prefix=prefix), "products", "products in the subtree",
                       exact_sql=None)

    # ---- phrases from the names
    sample = [r[0] for r in rows(db, f"SELECT name FROM catalog_product ORDER BY random() LIMIT {min(400, totals['products'])}")]
    types, brands, models = {}, {}, {}
    for name in sample:
        words = [w for w in re.split(r"[\s,.\-()/]+", name) if w]
        if words:
            types[words[0].lower()] = 1
        if len(words) > 1:
            brands[words[1].lower()] = 1
        for word in words[2:]:
            if len(word) >= 3 and not word.isdigit():
                models[word.lower()] = 1

    def hits(word):
        return int(rows(db, exact_count("catalog_product p", product_conditions(term=word)))[0][0])

    lo, hi = max(3, int(totals["products"] * 0.003)), int(totals["products"] * 0.05)
    typed = []
    for word in list(types)[:40]:
        h = hits(word)
        if lo <= h <= hi:
            first = rows(db, f"SELECT min(name) FROM catalog_product p WHERE p.name ILIKE {lit('%' + like_escape(word) + '%')}")[0][0]
            rank = int(rows(db, f"SELECT count(*) FROM catalog_product WHERE name < {lit(first)}")[0][0]) / totals["products"]
            typed.append((rank, word, h))
    typed.sort()
    brand_hits = sorted(((hits(word), word) for word in list(brands)[:25]), reverse=True)
    rare = [(h, w) for h, w in ((hits(word), word) for word in list(models)[:60]) if 1 <= h <= 20]
    rare.sort(reverse=True)
    polish = [w for w in list(models) + list(brands) if re.search(r"[ąćęłńóśźż]", w)][:1]
    phrases = [("none", "zzqqxx"), ("rare", rare[0][1] if rare else "ravotar"), ("very-common-a", "pro"), ("very-common-b", "ka"), ("two-rare", "qx"), ("two-other", "zx"),
               ("two-common", "er"), ("punct-1", "#!#"), ("punct-2", "---"), ("punct-3", "a.b"), ("punct-4", "ab-cd")]
    if typed:
        phrases.append(("type-early", typed[0][1]))
        phrases.append(("type-late", typed[-1][1]))
    if brand_hits:
        phrases.append(("brand-common", brand_hits[0][1]))
        phrases.append(("brand-mid", brand_hits[len(brand_hits) // 2][1]))
    if polish:
        phrases += [("polish-lower", polish[0]), ("polish-upper", polish[0].upper()), ("polish-title", polish[0].title())]
    cases.totals["phrases"] = [{"label": l, "term": t} for l, t in phrases]
    for label, term in phrases:
        product_family(f"P-term-{label}", "phrase", f"phrase {label} '{term}'", product_conditions(term=term), "products", "hits")

    # ---- combinations
    small = next((c for l, c, _ in chosen if l == "small"), None)
    dept = next((c for l, c, _ in chosen if l == "department"), None)
    late = next((c for l, c, _ in chosen if l == "big-late"), None)
    mids = next((c for l, c, _ in chosen if l == "mid"), None)
    brand = brand_hits[0][1] if brand_hits else "pro"
    combos = []
    if small:
        combos.append(("small-cat+common", "small category + a phrase common in the catalog (pro)", product_conditions(term="pro", prefix=small["path"]), "products"))
        combos.append(("small-cat+drafts", "small category + drafts", product_conditions(prefix=small["path"], status="draft"), "products"))
    if late:
        combos.append(("late-leaf+type", "big late leaf + a phrase rare in it", product_conditions(term=(typed[0][1] if typed else "qx"), prefix=late["path"]), "products"))
    if mids:
        combos.append(("mid-cat+brand", "middle category + brand", product_conditions(term=brand, prefix=mids["path"]), "products"))
    combos += [
        ("drafts", "drafts", product_conditions(status="draft"), "const"),
        ("published", "published only", product_conditions(status="published"), "products"),
        ("no-images", "without images", product_conditions(no_images=True), "const"),
        ("no-images+brand", "without images + brand", product_conditions(term=brand, no_images=True), "products"),
        ("no-images+rare", "without images + rare phrase", product_conditions(term=(rare[0][1] if rare else "ravotar"), no_images=True), "products"),
    ]
    if dept:
        combos.append(("no-images+dept", "without images in the biggest department", product_conditions(prefix=dept["path"], no_images=True), "products"))
        combos.append(("drafts+dept", "drafts in the biggest department", product_conditions(prefix=dept["path"], status="draft"), "products"))
    for key, title, conditions, scale in combos:
        product_family(f"P-combo-{key}", "combination", title, conditions, scale, "hits")

    # ---- orders
    order_rows = rows(db, "SELECT number, email, full_name FROM ordering_order ORDER BY random() LIMIT 300")
    surnames = {}
    for _, _, name in order_rows:
        parts = name.split()
        if parts:
            surnames[parts[-1].lower()] = surnames.get(parts[-1].lower(), 0) + 1
    common_surname = max(surnames, key=surnames.get)
    rare_surname = min((s for s in surnames if len(s) >= 5), key=lambda s: surnames[s])
    sample_order = order_rows[0]
    order_fragments = [("common-surname", common_surname), ("rare-surname", rare_surname), ("domain", "example"), ("three-letters", "ann"), ("none", "qqzzxx"),
                       ("punct-1", "#!#"), ("punct-2", "---"), ("punct-3", "a.b"), ("punct-4", "ab-cd"), ("polish", "ąęś"), ("number-part", sample_order[0][2:5] if len(sample_order[0]) > 4 else "R-1")]
    for sort in ORDER_SORTS:
        page = order_page([], sort)
        cases.add(f"O-default.p1.{sort}", "orders", f"orders, no filter, sort {sort}, page 1", page, "page", PAGE, "const")
    deep_order = rows(db, f"SELECT o.placed_at::text, o.id FROM ordering_order o ORDER BY o.placed_at DESC, o.id DESC OFFSET {totals['orders'] // 2} LIMIT 1")[0]
    cases.add("O-default.deep", "orders", "orders, a page in the middle of the dates", order_page([], "-placedAt", (deep_order[0], deep_order[1])), "page", PAGE, "const")
    cases.add("O-default.count", "orders", "orders, count without filter", bounded_count("ordering_order o", []), "page", BOUND, "const")
    for status in ("paid", "delivered"):
        conditions = order_conditions(status=status)
        value = int(rows(db, exact_count("ordering_order o", conditions))[0][0])
        cases.add(f"O-status-{status}.p1", "orders", f"orders with status {status}, page 1", order_page(conditions, "-placedAt"), "orders with the status", value, "orders" if value > BOUND else "const")
        cases.add(f"O-status-{status}.count", "orders", f"orders with status {status}, count", bounded_count("ordering_order o", conditions), "orders with the status", value, "orders" if value > BOUND else "const")
    for label, fragment in order_fragments:
        conditions = order_conditions(fragment=fragment)
        value = int(rows(db, exact_count("ordering_order o", conditions))[0][0])
        page1 = order_page(conditions, "-placedAt")
        cases.add(f"O-frag-{label}.p1", "orders", f"orders, fragment {label} '{fragment}', page 1", page1, "hits", value, "orders")
        after = cursor_after(db, page1, "order-placed")
        if after:
            cases.add(f"O-frag-{label}.p2", "orders", f"orders, fragment {label} '{fragment}', page 2", order_page(conditions, "-placedAt", after), "hits", value, "orders")
        cases.add(f"O-frag-{label}.p1.total", "orders", f"orders, fragment {label} '{fragment}', page 1, sort -total", order_page(conditions, "-total"), "hits", value, "orders")
        cases.add(f"O-frag-{label}.count", "orders", f"orders, fragment {label} '{fragment}', count", bounded_count("ordering_order o", conditions), "hits", value, "orders")
    conditions = order_conditions(fragment=common_surname, status="delivered")
    cases.add("O-frag-status.p1", "orders", f"orders, fragment {common_surname} + status delivered, page 1", order_page(conditions, "-placedAt"), "hits", int(rows(db, exact_count("ordering_order o", conditions))[0][0]), "orders")
    for key, conditions, title in (("number", order_conditions(number=sample_order[0]), "order number (exact)"), ("email", order_conditions(email=sample_order[1]), "full e-mail (exact)")):
        cases.add(f"O-{key}.p1", "orders", f"orders, {title}, page 1", order_page(conditions, "-placedAt"), "page", 1, "const")
    placed = rows(db, "SELECT min(placed_at)::date::text, max(placed_at)::date::text, (min(placed_at)::date + 1)::text FROM ordering_order")[0]
    cases.add("O-range.p1", "orders", "orders, one-day range + status delivered, page 1", order_page(order_conditions(status="delivered", placed_from=placed[0], placed_before=placed[2]), "-placedAt"), "page", PAGE, "const")
    cases.add("O-range.year.count", "orders", "orders, delivered over the whole range, count", bounded_count("ordering_order o", order_conditions(status="delivered", placed_from=placed[0], placed_before=placed[1])), "page", BOUND, "const")
    pending = "o.refund_amount IS NOT NULL AND o.refund_completed_at IS NULL"
    cases.add("O-refund.p1", "orders", "orders, pending refunds sorted by due date", f"SELECT {ORDER_COLUMNS} FROM ordering_order o WHERE {pending} ORDER BY o.refund_due_at ASC, o.id ASC LIMIT {PAGE + 1}", "pending refunds", 0, "const")
    cases.add("O-refund.count", "orders", "orders, overdue refunds, count", f"SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE {pending} AND o.refund_due_at < now() LIMIT 1001) bounded", "pending refunds", 0, "const")

    # ---- customers
    customer_rows = rows(db, "SELECT email, full_name FROM ordering_customer ORDER BY random() LIMIT 200")
    customer_surname = {}
    for _, name in customer_rows:
        parts = name.split()
        if parts:
            customer_surname[parts[-1].lower()] = customer_surname.get(parts[-1].lower(), 0) + 1
    c_common = max(customer_surname, key=customer_surname.get)
    c_rare = min((s for s in customer_surname if len(s) >= 5), key=lambda s: customer_surname[s])
    kinds = {"-lastOrderAt": "customer-lastorder", "name": "customer-name", "-orderCount": "customer-count", "-paidGross": "customer-gross"}
    for sort in CUSTOMER_SORTS:
        page1 = customer_page([], sort)
        cases.add(f"C-default.p1.{sort}", "customers", f"customers, no filter, sort {sort}, page 1", page1, "page", PAGE, "const")
        after = cursor_after(db, page1, kinds[sort])
        if after:
            cases.add(f"C-default.p2.{sort}", "customers", f"customers, no filter, sort {sort}, page 2", customer_page([], sort, after), "page", PAGE, "const")
    cases.add("C-default.count", "customers", "customers, count without filter", bounded_count("ordering_customer c", []), "page", BOUND, "const")
    customer_fragments = [("common-surname", c_common), ("rare-surname", c_rare), ("domain", "example"), ("three-letters", "ann"), ("none", "qqzzxx"), ("punct-1", "#!#"),
                          ("punct-2", "---"), ("punct-3", "a.b"), ("punct-4", "ab-cd"), ("polish", "ąęś"), ("full-email", customer_rows[0][0])]
    for label, fragment in customer_fragments:
        conditions = customer_conditions(fragment)
        value = int(rows(db, exact_count("ordering_customer c", conditions))[0][0])
        page1 = customer_page(conditions, "-lastOrderAt")
        cases.add(f"C-frag-{label}.p1", "customers", f"customers, fragment {label} '{fragment}', page 1", page1, "hits", value, "customers")
        after = cursor_after(db, page1, "customer-lastorder")
        if after:
            cases.add(f"C-frag-{label}.p2", "customers", f"customers, fragment {label} '{fragment}', page 2", customer_page(conditions, "-lastOrderAt", after), "hits", value, "customers")
        # A search combines only with the sort by the last order (the API answers 400 otherwise), so no sort `name` here.
        cases.add(f"C-frag-{label}.count", "customers", f"customers, fragment {label} '{fragment}', count", bounded_count("ordering_customer c", conditions), "hits", value, "customers")

    # ---- others
    cases.add("X-discount-codes", "other", "discount codes, list with the active uses per row (A4)", "SELECT c.id, (SELECT count(*) FROM pricing_discount_code_usage u WHERE u.code_id = c.id AND u.released_at IS NULL) AS active_uses FROM pricing_discount_code c ORDER BY c.created_at DESC, c.id LIMIT 26", "active uses of the codes", int(rows(db, "SELECT count(*) FROM pricing_discount_code_usage WHERE released_at IS NULL")[0][0]), "orders")
    cases.add("X-category-counts", "other", "category tree with counts", "SELECT c.id, (SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = c.id UNION ALL SELECT 1 FROM catalog_product_category pc WHERE pc.category_id = c.id LIMIT 1001) t) AS products FROM catalog_category c", "categories x bound 1001", totals["categories"], "const")
    cases.add("X-dashboard-sales", "other", "dashboard, sales of 60 days", "SELECT CAST(COALESCE(SUM(order_count), 0) AS bigint), CAST(COALESCE(SUM(gross), 0) AS bigint) FROM ordering_sales_hour WHERE hour_start >= now() - interval '60 days' AND hour_start < now()", "hourly rows of 60 days", 0, "const")
    cases.add("X-dashboard-out-of-stock", "other", "dashboard, out of stock (limit 1000)", "SELECT count(*) FROM (SELECT 1 FROM inventory_stock_item WHERE physical - reserved = 0 AND product_id IS NOT NULL LIMIT 1001) bounded", "bound", 1001, "const")
    cases.add("X-dashboard-no-images", "other", "dashboard, products without images (limit 1000)", "SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.images = '[]'::jsonb LIMIT 1001) bounded", "bound", 1001, "const")
    cases.add("X-dashboard-recent-orders", "other", "dashboard, the five last orders", "SELECT o.id, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) FROM ordering_order o ORDER BY o.placed_at DESC, o.id DESC LIMIT 6", "page", 5, "const")
    cases.add("X-stock-low-count", "other", "stock list, low-stock filter count", "SELECT count(*) FROM (SELECT 1 FROM inventory_stock_item WHERE physical - reserved BETWEEN 1 AND 5 AND product_id IS NOT NULL GROUP BY product_id LIMIT 10001) bounded", "bound", BOUND, "const")
    cases.add("X-sitemap-boundaries-M0", "sitemap", "sitemap boundaries, row_number() over the whole key (M0, the present query)", f"SELECT id FROM (SELECT id, row_number() OVER (ORDER BY id) AS position FROM catalog_product) numbered WHERE position > 1 AND (position - 1) % 10000 = 0 ORDER BY position", "products", totals["products"], "products")
    ids = rows(db, f"SELECT id FROM catalog_product ORDER BY id OFFSET {min(20000, totals['products'] // 4)} LIMIT 2")
    cases.add("X-sitemap-chunk", "sitemap", "sitemap chunk (a range of the key, limit 50001)", f"SELECT slug, status, updated_at FROM catalog_product WHERE id >= CAST({lit(ids[0][0])} AS uuid) AND id < CAST({lit(ids[1][0])} AS uuid) ORDER BY id LIMIT 50001", "chunk", PAGE, "const")

    os.makedirs(os.path.join(out, "sql"), exist_ok=True)
    for case in cases.cases:
        with open(os.path.join(out, "sql", case["id"] + ".sql"), "w", encoding="utf-8") as handle:
            handle.write(f"-- {case['title']}\n-- cost variable: {case['variable']} = {case['value']} (scales with {case['scale']})\n{case['sql']};\n")
    with open(os.path.join(out, "cases.json"), "w", encoding="utf-8") as handle:
        json.dump({"db": db, "totals": cases.totals, "cases": cases.cases}, handle, ensure_ascii=False, indent=1)
    print(f"matrix: {len(cases.cases)} cases in {out}")


# ---------------------------------------------------------------- running

EX_FUNCTION = (
    "CREATE FUNCTION pg_temp.ex(q text) RETURNS text LANGUAGE plpgsql AS $f$ DECLARE r text; BEGIN "
    "EXECUTE 'EXPLAIN (ANALYZE, BUFFERS, TIMING OFF, SUMMARY ON, FORMAT JSON) ' || q INTO r; RETURN r::jsonb::text; END $f$;\n"
    "SET default_transaction_read_only = on; SET statement_timeout = '180s'; SET track_io_timing = on;\n"
)


def scan_nodes(plan):
    stack, nodes = [plan], []
    while stack:
        node = stack.pop()
        nodes.append(node)
        stack.extend(node.get("Plans", []))
    return nodes


def summarize(document):
    root = document[0]
    plan = root["Plan"]
    scanned, entries, kinds = 0, 0, []
    for node in scan_nodes(plan):
        kind = node["Node Type"]
        loops = node.get("Actual Loops", 1)
        if "Scan" in kind:
            label = kind + (f" {node['Index Name']}" if "Index Name" in node else f" {node.get('Relation Name', '')}")
            if label not in kinds:
                kinds.append(label)
            if kind == "Bitmap Index Scan":
                entries += node.get("Actual Rows", 0) * loops
            elif kind != "Bitmap Heap Scan" or True:
                if kind == "Bitmap Heap Scan":
                    scanned += (node.get("Actual Rows", 0) + node.get("Rows Removed by Index Recheck", 0) + node.get("Rows Removed by Filter", 0)) * loops
                else:
                    scanned += (node.get("Actual Rows", 0) + node.get("Rows Removed by Filter", 0)) * loops
    return {
        "ms": root["Execution Time"], "planning_ms": root.get("Planning Time"), "hit": plan.get("Shared Hit Blocks", 0), "read": plan.get("Shared Read Blocks", 0),
        "temp": plan.get("Temp Written Blocks", 0), "returned": plan.get("Actual Rows", 0), "rows_scanned": scanned, "index_entries": entries,
        "plan": kinds, "io_read_ms": plan.get("Shared I/O Read Time", 0), "jit": "JIT" in root,
    }


def run(db, cases_file, out, repeats, slow_repeats, slow_ms, only):
    with open(cases_file, encoding="utf-8") as handle:
        document = json.load(handle)
    os.makedirs(os.path.join(out, "explain"), exist_ok=True)
    results = {"db": db, "started": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "totals": document["totals"], "cases": {}}
    path = os.path.join(out, "results.json")
    if os.path.exists(path):
        with open(path, encoding="utf-8") as handle:
            results["cases"] = json.load(handle).get("cases", {})
    for index, case in enumerate(document["cases"], 1):
        if only and only not in case["id"]:
            continue
        if case["id"] in results["cases"] and not only:
            continue
        sql = case["sql"]
        runs = []
        reps = repeats
        error = None
        for rep in range(1, repeats + 1):
            script = EX_FUNCTION + f"SELECT pg_temp.ex($q${sql}$q$);\n"
            started = time.time()
            code, output, err = psql(db, script)
            if code != 0:
                error = err.strip().splitlines()[-1][:200] if err.strip() else "psql failed"
                break
            runs.append(summarize(json.loads(output.strip().splitlines()[-1])))
            # The repeats share the session of one psql call in the audit; here every run is its own call, so the buffers carry over
            # (the page cache of the host and the shared buffers), and the first run is the one after the cases before it.
            if rep == 1 and runs[0]["ms"] > slow_ms:
                reps = slow_repeats
            if rep >= reps:
                break
        entry = {"id": case["id"], "group": case["group"], "title": case["title"], "variable": case["variable"], "value": case["value"], "scale": case["scale"], "runs": runs, "error": error}
        if runs:
            code, output, err = psql(db, "SET default_transaction_read_only = on; SET statement_timeout = '180s'; SET track_io_timing = on;\nEXPLAIN (ANALYZE, BUFFERS, SETTINGS) " + sql + ";\n")
            with open(os.path.join(out, "explain", case["id"] + ".out"), "w", encoding="utf-8") as handle:
                handle.write(f"-- {case['title']}\n-- {case['variable']} = {case['value']}\n{sql};\n\n{output if code == 0 else err}")
        results["cases"][case["id"]] = entry
        with open(path, "w", encoding="utf-8") as handle:
            json.dump(results, handle, ensure_ascii=False, indent=1)
        if runs:
            print(f"[{index}/{len(document['cases'])}] {case['id']}: first {runs[0]['ms']:.1f} ms, buffers {runs[0]['hit'] + runs[0]['read']}, {len(runs)} runs", file=sys.stderr)
        else:
            print(f"[{index}/{len(document['cases'])}] {case['id']}: ERROR {error}", file=sys.stderr)


# ---------------------------------------------------------------- the table


def table(directories):
    loaded = []
    for directory in directories:
        with open(os.path.join(directory, "results.json"), encoding="utf-8") as handle:
            loaded.append(json.load(handle))
    names = [d["db"] for d in loaded]
    header = "| case | cost variable | " + " | ".join(f"{n}: first ms | {n}: repeated median ms | {n}: max ms | {n}: buffers | {n}: rows scanned | {n}: value" for n in names) + " |"
    lines = [header, "|" + "---|" * (2 + 6 * len(names))]
    ids = []
    for d in loaded:
        for case_id in d["cases"]:
            if case_id not in ids:
                ids.append(case_id)
    for case_id in ids:
        first = next(d["cases"][case_id] for d in loaded if case_id in d["cases"])
        cells = []
        for d in loaded:
            entry = d["cases"].get(case_id)
            if not entry or not entry["runs"]:
                cells.append("| | | | | | " + (entry["error"] if entry and entry["error"] else ""))
                continue
            runs = entry["runs"]
            repeated = [r["ms"] for r in runs[1:]] or [runs[0]["ms"]]
            buffers = runs[-1]["hit"] + runs[-1]["read"]
            cells.append(f"{runs[0]['ms']:.1f} | {statistics.median(repeated):.1f} | {max(r['ms'] for r in runs):.1f} | {buffers} | {runs[-1]['rows_scanned']} | {entry['value']}")
        lines.append(f"| {case_id} | {first['variable']} | " + " | ".join(cells) + " |")
    print("\n".join(lines))


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)
    gen = sub.add_parser("gen")
    gen.add_argument("--db", required=True)
    gen.add_argument("--out", required=True)
    runner = sub.add_parser("run")
    runner.add_argument("--db", required=True)
    runner.add_argument("--cases", required=True)
    runner.add_argument("--out", required=True)
    runner.add_argument("--repeats", type=int, default=10)
    runner.add_argument("--slow-repeats", type=int, default=3)
    runner.add_argument("--slow-ms", type=float, default=2000)
    runner.add_argument("--only", default="")
    tab = sub.add_parser("table")
    tab.add_argument("--results", action="append", required=True)
    args = parser.parse_args()
    if args.command == "gen":
        draw(args.db, args.out)
    elif args.command == "run":
        run(args.db, args.cases, args.out, args.repeats, args.slow_repeats, args.slow_ms, args.only)
    else:
        table(args.results)


if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""Builds the target lists of the audit from the data of the running stack.

gen_targets.py SCALE [--api http://localhost:52702] [--token-file FILE]

Reads the public API (and Postgres for the admin samples) and writes targets/<SCALE>/<scenario>.<host>.json for the
hosts api-prod (classic mode, as shipped) and api-prodw (worker mode variant). Deterministic (seeded).
"""
import argparse
import json
import os
import random
import subprocess
import urllib.parse
import urllib.request

S = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PG = "shop-perfaudit-postgres-1"


def get(base, path):
    with urllib.request.urlopen(base + path, timeout=120) as r:
        return json.load(r)


def psql(sql):
    out = subprocess.run(
        ["docker", "exec", PG, "psql", "-U", "postgres", "-d", "shop", "-At", "-F", "\t", "-c", sql],
        capture_output=True, text=True, check=True,
    ).stdout
    return [line.split("\t") for line in out.splitlines() if line]


def flatten(nodes, acc):
    for n in nodes:
        acc.append(n)
        flatten(n.get("children", []), acc)
    return acc


def main():
    p = argparse.ArgumentParser()
    p.add_argument("scale")
    p.add_argument("--api", default="http://localhost:52702")
    p.add_argument("--token-file", default="")
    a = p.parse_args()
    rnd = random.Random(20261118)
    q = urllib.parse.quote

    cats = flatten(get(a.api, "/api/v1/categories"), [])
    top = [c for c in cats if "/" not in c["path"]]
    leaves = [c for c in cats if not c.get("children")]
    chunks = [c["key"] for c in get(a.api, "/api/v1/sitemap/product-chunks")["chunks"]]
    products = []
    for key in rnd.sample(chunks, min(len(chunks), 6)):
        data = get(a.api, "/api/v1/sitemap/products?chunk=" + q(key))
        items = data["items"] if isinstance(data, dict) else data
        products += rnd.sample(items, min(len(items), 200))
    slugs = [x["slug"] for x in products]
    # Names and ids of a sample of the products, for the search phrases and the offers.
    sample = []
    for slug in rnd.sample(slugs, min(len(slugs), 60)):
        sample.append(get(a.api, "/api/v1/products/" + q(slug)))
    words = sorted({w.lower() for d in sample for w in d["name"].split() if len(w) >= 5 and w.isalpha()})
    phrases = rnd.sample(words, min(len(words), 40))
    ids = [d["id"] for d in sample]

    T = {}
    T["health"] = ["/health"]
    T["categories"] = ["/api/v1/categories"]
    T["listing_all"] = ["/api/v1/products"] + [f"/api/v1/products?page={n}" for n in (2, 3, 5)]
    T["listing_top_category"] = [f"/api/v1/products?category={c['id']}" for c in top]
    T["listing_leaf_category"] = [f"/api/v1/products?category={c['id']}" for c in rnd.sample(leaves, min(len(leaves), 40))]
    T["listing_filtered"] = (
        [f"/api/v1/products?category={c['id']}&available=true&sort=price_asc" for c in top]
        + [f"/api/v1/products?category={c['id']}&priceMin=5000&priceMax=30000" for c in top]
    )
    T["listing_deep_page"] = [f"/api/v1/products?page={n}&perPage=24" for n in (50, 100, 200, 400)]
    T["search"] = [f"/api/v1/products?q={q(w)}" for w in phrases]
    T["search_filtered"] = [f"/api/v1/products?q={q(w)}&available=true&sort=price_desc" for w in phrases]
    T["product"] = [f"/api/v1/products/{q(s)}" for s in rnd.sample(slugs, min(len(slugs), 300))]
    T["offers_24"] = ["/api/v1/product-offers?" + "&".join("productId=" + i for i in rnd.sample(ids, min(len(ids), 24))) for _ in range(20)]
    T["sitemap_chunks"] = ["/api/v1/sitemap/product-chunks"]
    T["sitemap_products"] = [f"/api/v1/sitemap/products?chunk={q(k)}" for k in rnd.sample(chunks, min(len(chunks), 20))]

    admin = {}
    if a.token_file:
        order_ids = [r[0] for r in psql("select id from ordering_order order by random() limit 50")]
        customer_ids = [r[0] for r in psql("select customer_id from ordering_customer order by random() limit 50")]
        product_ids = [r[0] for r in psql("select id from catalog_product order by random() limit 50")]
        variant_ids = [r[0] for r in psql("select id from catalog_product_variant order by random() limit 50")]
        emails = [r[0] for r in psql("select email from ordering_customer order by random() limit 20")]
        numbers = [r[0] for r in psql("select number from ordering_order order by random() limit 20")]
        admin["admin_dashboard"] = ["/api/v1/admin/dashboard"]
        admin["admin_orders"] = ["/api/v1/admin/orders"]
        admin["admin_orders_status"] = [f"/api/v1/admin/orders?status={s}" for s in ("paid", "shipped", "cancelled", "delivered")]
        admin["admin_orders_q_number"] = [f"/api/v1/admin/orders?q={q(n)}" for n in numbers]
        admin["admin_orders_q_email"] = [f"/api/v1/admin/orders?q={q(e)}" for e in emails]
        admin["admin_orders_q_fragment"] = [f"/api/v1/admin/orders?q={q(e.split('@')[0][:5])}" for e in emails]
        admin["admin_orders_customer"] = [f"/api/v1/admin/orders?customerId={c}" for c in customer_ids]
        admin["admin_order"] = [f"/api/v1/admin/orders/{o}" for o in order_ids]
        admin["admin_customers"] = ["/api/v1/admin/customers"]
        admin["admin_customers_q"] = [f"/api/v1/admin/customers?q={q(e.split('@')[0][:5])}" for e in emails]
        admin["admin_customer"] = [f"/api/v1/admin/customers/{c}" for c in customer_ids]
        admin["admin_products"] = ["/api/v1/admin/products"]
        admin["admin_products_q"] = [f"/api/v1/admin/products?q={q(w)}" for w in phrases[:20]]
        admin["admin_products_category"] = [f"/api/v1/admin/products?category={c['id']}" for c in top]
        admin["admin_product"] = [f"/api/v1/admin/products/{x}" for x in product_ids]
        admin["admin_categories_counts"] = ["/api/v1/admin/categories?withCounts=true"]
        admin["admin_stock"] = ["/api/v1/admin/stock"]
        admin["admin_stock_q"] = [f"/api/v1/admin/stock?q={q(w)}" for w in phrases[:20]]
        admin["admin_stock_low"] = ["/api/v1/admin/stock?level=low_stock", "/api/v1/admin/stock?level=out_of_stock"]
        admin["admin_stock_history"] = [f"/api/v1/admin/variants/{v}/stock-history" for v in variant_ids]
        admin["admin_prices"] = ["/api/v1/admin/prices"]
        admin["admin_prices_q"] = [f"/api/v1/admin/prices?q={q(w)}" for w in phrases[:20]]
        admin["admin_price_history"] = [f"/api/v1/admin/variants/{v}/price-history" for v in variant_ids]
        admin["admin_promotions"] = ["/api/v1/admin/promotions"]
        admin["admin_discount_codes"] = ["/api/v1/admin/discount-codes"]

    out = os.path.join(S, "targets", a.scale)
    os.makedirs(out, exist_ok=True)
    token = open(a.token_file).read().strip() if a.token_file else ""
    for host in ("api-prod", "api-prodw"):
        for name, paths in T.items():
            json.dump([{"url": f"http://{host}{x}"} for x in paths], open(f"{out}/{name}.{host}.json", "w"))
        for name, paths in admin.items():
            json.dump(
                [{"url": f"http://{host}{x}", "headers": {"Authorization": "Bearer " + token}} for x in paths],
                open(f"{out}/{name}.{host}.json", "w"),
            )
    store = {
        "store_home": ["/"],
        "store_category_top": [f"/c/{c['path']}" for c in top],
        "store_category_leaf": [f"/c/{c['path']}" for c in rnd.sample(leaves, min(len(leaves), 40))],
        "store_product": [f"/p/{q(x)}" for x in rnd.sample(slugs, min(len(slugs), 300))],
        "store_product_one": [f"/p/{q(slugs[0])}"],
        "store_search": [f"/szukaj?q={q(w)}" for w in phrases],
        "store_site_api": ["/api/site"],
        "store_cart_summary_api": ["/api/cart/summary"],
        "store_session_api": ["/auth/session"],
    }
    for name, paths in store.items():
        json.dump([{"url": f"http://store:3000{x}", "headers": {"Accept": "text/html,application/json"}} for x in paths],
                  open(f"{out}/{name}.store.json", "w"))
    variants = [r[0] for r in psql(
        "select s.variant_id from inventory_stock_item s join catalog_product p on p.id = s.product_id "
        "join pricing_variant_price pr on pr.variant_id = s.variant_id "
        "where p.status = 'published' and s.physical - s.reserved > 50 order by random() limit 3000")]
    json.dump(variants, open(f"{out}/variants.json", "w"))
    print(f"{a.scale}: {len(cats)} categories, {len(chunks)} chunks, {len(slugs)} slugs, {len(phrases)} phrases; "
          f"{len(T)} public, {len(admin)} admin, {len(store)} store scenarios, {len(variants)} variants in {out}")


if __name__ == "__main__":
    main()

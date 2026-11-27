#!/usr/bin/env python3
"""The mix of the requests of the panel for the W7 and P4 scenarios of k6 (performance program, PERF-7), drawn from the data.

Reads the data through the API only (the admin API with the token of load/panel_token.py, the public API for the names of the
products), so it can point at any environment, a worf.pl read included. Runs on the host (Python 3 standard library) and
writes one JSON file: the requests of the panel by class (the lists in their sorts, deep pages, phrases by frequency, the
categories by size, the combinations of filters, the prices and the stock, the orders and the customers with their fragments,
the discount codes, the promotions, the dashboard) and the way the mix was drawn. The same file is used before and after a
change; k6 (load/panel.js) draws from it by the weights.

  panel_mix.py --api URL --token-file FILE --out MIX.json [--seed N] [--products N] [--deep-pages N]

The classes of a phrase are decided by what the API answers: the number of the products that match it, counted up to the
bound of the list (10 000): none, rare (up to 20), medium, common (over the bound); a phrase of two letters is its own class
(the API accepts it, pg_trgm extracts no trigram from it), as is one of punctuation only. The categories are classed by
their number of products the same way: empty, small (up to 20), medium (up to 5000), big (the bound reached) and the
departments (the roots of the tree).

Exit codes: 0 the file is written; 2 wrong arguments, a missing token file, the API down or answering without data.
"""
import argparse
import json
import random
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BOUND = 10_000
SORTS_PRODUCTS = ["name", "-name", "-updatedAt", "updatedAt"]
SORTS_ORDERS = ["-placedAt", "placedAt", "-total", "total"]
SORTS_CUSTOMERS = ["-lastOrderAt", "name", "-orderCount", "-paidGross"]
PUNCTUATION = ["#!#", "---", "a.b", "ab-cd"]
# The share of every class in the mix (the weights are relative). The lists open the panel, so they weigh most; the heavy
# cases of the matrix have a share of their own, so that their cost shows in the percentiles.
WEIGHTS = {
    "products_default": 8,
    "products_deep": 4,
    "products_phrase_none": 2,
    "products_phrase_rare": 4,
    "products_phrase_type": 4,
    "products_phrase_brand": 3,
    "products_phrase_common": 3,
    "products_phrase_twochar": 3,
    "products_phrase_punct": 1,
    "products_phrase_polish": 2,
    "products_category_empty": 1,
    "products_category_small": 4,
    "products_category_medium": 4,
    "products_category_big": 3,
    "products_category_department": 2,
    "products_combo": 6,
    "products_drafts": 2,
    "products_no_images": 2,
    "prices": 4,
    "stock": 4,
    "orders_default": 6,
    "orders_status": 3,
    "orders_deep": 2,
    "orders_fragment": 4,
    "orders_punct": 1,
    "orders_number_email": 2,
    "orders_range": 2,
    "orders_refunds": 1,
    "customers_default": 3,
    "customers_deep": 1,
    "customers_fragment": 3,
    "customers_punct": 1,
    "discount_codes": 2,
    "promotions": 1,
    "dashboard": 3,
    "categories_counts": 1,
}
# The classes the eight parallel threads of P4 repeat: the cases that cost most in the matrix.
WORST = [
    "products_category_small",
    "products_phrase_type",
    "products_phrase_twochar",
    "products_phrase_punct",
    "orders_punct",
    "customers_punct",
    "products_combo",
]


def fail(message):
    print(f"panel_mix: {message}", file=sys.stderr)
    sys.exit(2)


class Api:
    def __init__(self, base, token):
        self.base = base.rstrip("/")
        self.token = token
        # The file may be older than its access token lives: the first call renews it.
        self.expires = 0
        self.calls = 0

    def renew(self):
        """The access token lives 5 minutes, a draw lasts longer: renewed with the refresh token before it expires."""
        body = urllib.parse.urlencode({"grant_type": "refresh_token", "client_id": "admin", "refresh_token": self.token["refresh_token"]}).encode()
        try:
            with urllib.request.urlopen(urllib.request.Request(self.token["token_url"], data=body), timeout=30) as response:
                fresh = json.load(response)
        except (urllib.error.URLError, TimeoutError, ValueError) as error:
            fail(f"the token could not be renewed at {self.token['token_url']}: {error}")
        self.token = {**self.token, **fresh}
        self.expires = time.time() + fresh.get("expires_in", 300)

    def get(self, path, admin=True, timeout=120):
        headers = {"Accept": "application/json", "User-Agent": "perf-7-panel-mix"}
        if admin:
            if time.time() > self.expires - 30:
                self.renew()
            headers["Authorization"] = f"Bearer {self.token['access_token']}"
        request = urllib.request.Request(self.base + path, headers=headers)
        self.calls += 1
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                return json.load(response)
        except urllib.error.HTTPError as error:
            fail(f"GET {path} answered {error.code}")
        except (urllib.error.URLError, TimeoutError, ValueError) as error:
            fail(f"GET {self.base}{path} failed: {error}")

    def total(self, path):
        """The bounded total of the first page of a list: (value, capped)."""
        page = self.get(path + ("&" if "?" in path else "?") + "perPage=1")
        total = page.get("total") or {"value": 0, "capped": False}
        return total["value"], total["capped"]


def q(**params):
    return urllib.parse.urlencode({k: v for k, v in params.items() if v is not None})


def category_nodes(tree):
    for node in tree:
        yield node
        yield from category_nodes(node.get("children") or [])


def sample_names(api, rng, products):
    """Names of random published products: random chunks of the sitemap, then the product by its slug."""
    chunks = [c["key"] for c in api.get("/api/v1/sitemap/product-chunks", admin=False)["chunks"]]
    if not chunks:
        fail("the sitemap has no chunks: the catalog is empty")
    names = []
    tries = 0
    while len(names) < products and tries < products * 3:
        tries += 1
        items = api.get("/api/v1/sitemap/products?" + q(chunk=rng.choice(chunks)), admin=False)["items"]
        if not items:
            continue
        details = None
        for _ in range(3):
            slug = rng.choice(items)["slug"]
            try:
                details = api.get("/api/v1/products/" + urllib.parse.quote(slug), admin=False)
                break
            except SystemExit:
                raise
        if details:
            names.append(details["name"])
    return names


def words_of(name):
    return [w for w in re.split(r"[\s,.\-()/]+", name) if w]


def phrase_pools(names):
    """The words of the names by their place in the pattern `type brand model number`: the first word (the type of the
    product, which decides its category and so correlates with the order of the names), the second (the brand), the rest."""
    types, brands, models = set(), set(), set()
    for name in names:
        words = words_of(name)
        if len(words) >= 1:
            types.add(words[0].lower())
        if len(words) >= 2:
            brands.add(words[1].lower())
        for word in words[2:]:
            if len(word) >= 3 and not word.isdigit():
                models.add(word.lower())
    return sorted(types), sorted(brands), sorted(models)


def classify_phrase(api, phrase):
    value, capped = api.total("/api/v1/admin/products?" + q(q=phrase))
    if value == 0:
        return "none"
    if capped:
        return "common"
    return "rare" if value <= 20 else "medium"


def follow(api, path, field, hops, per_page=100):
    """The cursor of the page `hops` pages deep, or of the last page but one when the list ends sooner (None: one page only)."""
    cursor = None
    for _ in range(hops):
        page = api.get(path + ("&" if "?" in path else "?") + q(perPage=per_page, after=cursor))
        if page.get(field) is None:
            break
        cursor = page[field]
    return cursor


def build(api, rng, products, deep_pages):
    classes = {name: [] for name in WEIGHTS}
    notes = {}

    def add(cls, path):
        classes[cls].append(path)

    products_path = "/api/v1/admin/products"
    # The lists in every sort, and the same query behind the prices and the stock.
    for sort in SORTS_PRODUCTS:
        add("products_default", f"{products_path}?" + q(sort=sort))
        add("products_default", f"{products_path}?" + q(sort=sort, perPage=100))
    for sort in ("name", "-updatedAt"):
        for base, cls in (("/api/v1/admin/prices", "prices"), ("/api/v1/admin/stock", "stock")):
            add(cls, f"{base}?" + q(sort=sort))
    add("stock", "/api/v1/admin/stock?" + q(level="out_of_stock"))
    add("stock", "/api/v1/admin/stock?" + q(level="low_stock"))

    # Deep pages: real cursors, followed from the first page.
    for sort in ("name", "-updatedAt"):
        for hops in (deep_pages // 2, deep_pages):
            cursor = follow(api, products_path + "?" + q(sort=sort), "next", hops)
            if cursor:
                add("products_deep", f"{products_path}?" + q(sort=sort, perPage=100, after=cursor))

    # Phrases by frequency, from the names of random products.
    names = sample_names(api, rng, products)
    types, brands, models = phrase_pools(names)
    notes["names_sampled"] = len(names)
    notes["pools"] = {"types": len(types), "brands": len(brands), "models": len(models)}
    rng.shuffle(types), rng.shuffle(brands), rng.shuffle(models)
    for word in types[:12]:
        add("products_phrase_type", f"{products_path}?" + q(q=word, sort=rng.choice(["name", "-updatedAt"])))
    for word in brands[:12]:
        add("products_phrase_brand", f"{products_path}?" + q(q=word, sort=rng.choice(["name", "-updatedAt"])))
    found = {"none": [], "rare": [], "medium": [], "common": []}
    for word in models[:30] + brands[:10]:
        found[classify_phrase(api, word)].append(word)
    for kind in ("rare", "medium", "common"):
        for word in found[kind][:10]:
            add("products_phrase_rare" if kind in ("rare", "medium") else "products_phrase_common", f"{products_path}?" + q(q=word, sort=rng.choice(SORTS_PRODUCTS)))
    for word in ("pro", "ka", "ra", "ter"):
        add("products_phrase_common", f"{products_path}?" + q(q=word))
    for word in ("qx", "zx", "xq", "jj", "ka", "ra", "er"):
        add("products_phrase_twochar", f"{products_path}?" + q(q=word, sort=rng.choice(["name", "-updatedAt"])))
    for word in ("zzqqxx", "qqzzxx", "nie ma takiego"):
        add("products_phrase_none", f"{products_path}?" + q(q=word))
    for word in PUNCTUATION + ["#!#", "(*)"]:
        add("products_phrase_punct", f"{products_path}?" + q(q=word))
    for word in ("żuraw", "ŻURAW", "Łódź", "ąęś", "ĄĘŚ", "zażółć"):
        add("products_phrase_polish", f"{products_path}?" + q(q=word))
    notes["phrase_classes"] = {k: len(v) for k, v in found.items()}

    # Categories by size.
    tree = api.get("/api/v1/admin/categories?withCounts=true")
    nodes = list(category_nodes(tree))
    sized = []
    for node in nodes:
        value, capped = api.total(f"{products_path}?" + q(category=node["id"]))
        sized.append((node, value, capped))
    roots = {node["id"] for node in tree}
    kinds = {"empty": [], "small": [], "medium": [], "big": [], "department": []}
    for node, value, capped in sized:
        if node["id"] in roots:
            kinds["department"].append(node)
        elif value == 0:
            kinds["empty"].append(node)
        elif capped:
            kinds["big"].append(node)
        elif value <= 20:
            kinds["small"].append(node)
        else:
            kinds["medium"].append(node)
    notes["category_classes"] = {k: len(v) for k, v in kinds.items()}
    for kind, members in kinds.items():
        rng.shuffle(members)
        for node in members[:12]:
            add(f"products_category_{kind}", f"{products_path}?" + q(category=node["id"], sort=rng.choice(SORTS_PRODUCTS)))
    big_or_dept = kinds["big"] + kinds["department"]
    small = kinds["small"] + kinds["medium"]
    for _ in range(12):
        parts = {}
        pick = rng.choice(["category_phrase", "drafts_category", "images_phrase", "images_department", "category_common"])
        if pick == "category_phrase" and small and types:
            parts = {"category": rng.choice(small)["id"], "q": rng.choice(types)}
        elif pick == "drafts_category" and small:
            parts = {"category": rng.choice(small)["id"], "status": "draft"}
        elif pick == "images_phrase" and brands:
            parts = {"images": "none", "q": rng.choice(brands)}
        elif pick == "images_department" and big_or_dept:
            parts = {"images": "none", "category": rng.choice(big_or_dept)["id"]}
        elif big_or_dept:
            parts = {"category": rng.choice(big_or_dept)["id"], "q": "pro"}
        else:
            continue
        add("products_combo", f"{products_path}?" + q(sort=rng.choice(["name", "-updatedAt"]), **parts))
    for sort in ("name", "-updatedAt"):
        add("products_drafts", f"{products_path}?" + q(status="draft", sort=sort))
        add("products_drafts", f"{products_path}?" + q(status="published", sort=sort))
        add("products_no_images", f"{products_path}?" + q(images="none", sort=sort))

    # Orders: sorts, statuses, deep pages, fragments of the names and e-mails of real orders, ranges, refunds.
    orders = api.get("/api/v1/admin/orders?perPage=100")["items"]
    if not orders:
        fail("the shop has no orders to draw the mix from")
    statuses = sorted({o["status"] for o in orders} | {"paid", "delivered", "cancelled", "returned", "shipped"})
    for sort in SORTS_ORDERS:
        add("orders_default", "/api/v1/admin/orders?" + q(sort=sort))
    for status in statuses:
        add("orders_status", "/api/v1/admin/orders?" + q(status=status))
        add("orders_status", "/api/v1/admin/orders?" + q(status=status, sort="-total"))
    for sort in ("-placedAt", "-total"):
        for hops in (deep_pages // 2, deep_pages):
            cursor = follow(api, "/api/v1/admin/orders?" + q(sort=sort), "nextCursor", hops)
            if cursor:
                add("orders_deep", "/api/v1/admin/orders?" + q(sort=sort, perPage=100, after=cursor))
    # A fragment is 3 to 100 characters (OrderSearchTerm, BackofficeCustomerSearchTerm).
    surnames = sorted({o["fullName"].split()[-1].lower() for o in orders if o["fullName"] and len(o["fullName"].split()[-1]) >= 4})
    rng.shuffle(surnames)
    for surname in surnames[:8]:
        add("orders_fragment", "/api/v1/admin/orders?" + q(q=surname))
        add("orders_fragment", "/api/v1/admin/orders?" + q(q=surname[:4], status="delivered"))
    for fragment in ("example", "ann", "kowal", "ąęś", "qqzzxx"):
        add("orders_fragment", "/api/v1/admin/orders?" + q(q=fragment))
    for fragment in PUNCTUATION:
        add("orders_punct", "/api/v1/admin/orders?" + q(q=fragment))
        add("orders_punct", "/api/v1/admin/orders?" + q(q=fragment, status="delivered"))
    for order in rng.sample(orders, min(8, len(orders))):
        add("orders_number_email", "/api/v1/admin/orders?" + q(q=order["number"]))
        add("orders_number_email", "/api/v1/admin/orders?" + q(q=order["email"]))
    placed = sorted(o["placedAt"][:10] for o in orders)
    add("orders_range", "/api/v1/admin/orders?" + q(placedFrom=placed[0], placedTo=placed[-1]))
    add("orders_range", "/api/v1/admin/orders?" + q(placedFrom=placed[0], status="delivered"))
    add("orders_range", "/api/v1/admin/orders?" + q(placedFrom="2020-01-01", placedTo="2020-01-02"))
    add("orders_range", "/api/v1/admin/orders?" + q(placedFrom="2024-03-01", placedTo="2024-03-02", q="example"))
    add("orders_refunds", "/api/v1/admin/orders?" + q(refund="pending"))
    add("orders_refunds", "/api/v1/admin/orders?" + q(refund="overdue"))

    # Customers.
    customers = api.get("/api/v1/admin/customers?perPage=100")["items"]
    for sort in SORTS_CUSTOMERS:
        add("customers_default", "/api/v1/admin/customers?" + q(sort=sort))
    for sort in ("-lastOrderAt", "name"):
        cursor = follow(api, "/api/v1/admin/customers?" + q(sort=sort), "nextCursor", deep_pages)
        if cursor:
            add("customers_deep", "/api/v1/admin/customers?" + q(sort=sort, perPage=100, after=cursor))
    for customer in rng.sample(customers, min(8, len(customers))):
        add("customers_fragment", "/api/v1/admin/customers?" + q(q=customer["fullName"].split()[-1].lower().ljust(3, "x")))
        add("customers_fragment", "/api/v1/admin/customers?" + q(q=customer["email"].split("@")[0][:6].ljust(3, "x"), sort="name"))
    for fragment in ("example", "ann", "qqzzxx"):
        add("customers_fragment", "/api/v1/admin/customers?" + q(q=fragment))
    for fragment in PUNCTUATION:
        add("customers_punct", "/api/v1/admin/customers?" + q(q=fragment))

    # The small lists and the dashboard.
    for status in ("active", "expired", "planned", "disabled"):
        add("discount_codes", "/api/v1/admin/discount-codes?" + q(status=status))
    add("discount_codes", "/api/v1/admin/discount-codes")
    add("discount_codes", "/api/v1/admin/discount-codes?" + q(q="WITAJ"))
    for status in ("active", "planned", "ended"):
        add("promotions", "/api/v1/admin/promotions?" + q(status=status))
    add("dashboard", "/api/v1/admin/dashboard")
    add("dashboard", "/api/v1/admin/orders?perPage=5")
    add("categories_counts", "/api/v1/admin/categories?withCounts=true")

    empty = [name for name, paths in classes.items() if not paths]
    for name in empty:
        notes.setdefault("empty_classes", []).append(name)
    return {name: paths for name, paths in classes.items() if paths}, notes


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--api", required=True, help="the address of the API, e.g. http://localhost:8000")
    parser.add_argument("--token-file", required=True, help="the JSON of load/panel_token.py")
    parser.add_argument("--out", required=True)
    parser.add_argument("--seed", type=int, default=7)
    parser.add_argument("--products", type=int, default=120, help="how many products to read the names of")
    parser.add_argument("--deep-pages", type=int, default=40, help="how many pages of 100 the deep cursors are followed")
    args = parser.parse_args()
    try:
        with open(args.token_file, encoding="utf-8") as handle:
            token = json.load(handle)
        token["access_token"], token["refresh_token"], token["token_url"]
    except (OSError, ValueError, KeyError) as error:
        fail(f"cannot read the token from {args.token_file}: {error}")
    started = time.time()
    api = Api(args.api, token)
    classes, notes = build(api, random.Random(args.seed), args.products, args.deep_pages)
    mix = {
        "schema": 1,
        "api": args.api,
        "seed": args.seed,
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "seconds": round(time.time() - started, 1),
        "api_calls": api.calls,
        "weights": {name: WEIGHTS[name] for name in classes},
        "worst": [name for name in WORST if name in classes],
        "notes": notes,
        "classes": classes,
    }
    try:
        with open(args.out, "w", encoding="utf-8") as handle:
            json.dump(mix, handle, ensure_ascii=False, indent=1)
    except OSError as error:
        fail(f"cannot write {args.out}: {error}")
    total = sum(len(paths) for paths in classes.values())
    print(f"panel_mix: {total} requests in {len(classes)} classes written to {args.out} ({api.calls} API calls, {mix['seconds']} s)")


if __name__ == "__main__":
    main()

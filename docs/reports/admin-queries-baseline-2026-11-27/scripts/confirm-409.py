#!/usr/bin/env python3
"""The cause of the 409 of the P4 scenario: does the step of the cart that raises the quantity from 1 to 2 get 409 for the variants with fewer than 2 available units?

  confirm-409.py --api URL --token-file FILE [--products 60] [--seed 3] [--out FILE.json]

Does what the scenario does (load/panel.js, shopCart): draws products from random chunks of the sitemap, takes their purchasable variants from
`GET /api/v1/product-offers` (as `discover()` of load/lib/data.js does), and for every variant makes a guest cart, adds 1 unit and changes the quantity to 2
(`PUT /api/v1/guest-carts/{cart}/items/{variant}`). Beside it reads the available units of the variant from the admin API (`GET /api/v1/admin/stock`), which the
public API never tells, and prints the table: the status of the change by the available units. The address of the client of every request is its own
(`CF-Connecting-IP`, trusted when the API has CLIENT_IP_HEADER), so the limits of the carts do not interfere. It writes carts only (they expire).
Exit codes: 0 done; 2 wrong arguments, an unreachable API or a token that is refused.
"""
import argparse
import json
import random
import sys
import urllib.error
import urllib.parse
import urllib.request


def fail(message):
    print(f"confirm-409: {message}", file=sys.stderr)
    sys.exit(2)


class Api:
    def __init__(self, base, token):
        self.base, self.token, self.n = base.rstrip("/"), token, 0

    def call(self, method, path, body=None, admin=False):
        self.n += 1
        headers = {"Accept": "application/json", "CF-Connecting-IP": f"2001:db8:{self.n >> 16 & 0xFFFF:x}:{self.n & 0xFFFF:x}::1"}
        if admin:
            headers["Authorization"] = f"Bearer {self.token}"
        data = None
        if body is not None:
            headers["Content-Type"] = "application/json"
            data = json.dumps(body).encode()
        request = urllib.request.Request(self.base + path, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                return response.status, json.load(response)
        except urllib.error.HTTPError as error:
            try:
                return error.code, json.load(error)
            except ValueError:
                return error.code, {}
        except (urllib.error.URLError, TimeoutError) as error:
            fail(f"{method} {path} failed: {error}")


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--api", required=True)
    parser.add_argument("--token-file", required=True)
    parser.add_argument("--products", type=int, default=60)
    parser.add_argument("--seed", type=int, default=3)
    parser.add_argument("--out")
    args = parser.parse_args()
    try:
        token = json.load(open(args.token_file))["access_token"]
    except (OSError, ValueError, KeyError) as error:
        fail(f"cannot read the token: {error}")
    api = Api(args.api, token)
    rng = random.Random(args.seed)
    status, chunks = api.call("GET", "/api/v1/sitemap/product-chunks")
    if status != 200 or not chunks.get("chunks"):
        fail("the sitemap has no chunks")
    products = {}
    while len(products) < args.products:
        status, listing = api.call("GET", "/api/v1/sitemap/products?chunk=" + urllib.parse.quote(rng.choice(chunks["chunks"])["key"]))
        if status != 200 or not listing["items"]:
            continue
        status, product = api.call("GET", "/api/v1/products/" + urllib.parse.quote(rng.choice(listing["items"])["slug"]))
        if status == 200:
            products[product["id"]] = product["slug"]
    rows = []
    for product_id in products:
        status, offers = api.call("GET", f"/api/v1/product-offers?productId={product_id}")
        if status != 200:
            continue
        status, stock = api.call("GET", f"/api/v1/admin/stock?productId={product_id}", admin=True)
        if status == 401:
            fail("the admin API refused the token")
        available = {v["variantId"]: v["stock"]["available"] for item in stock.get("items", []) for v in item["variants"]}
        for offer in offers["items"]:
            for variant in offer["variants"]:
                if not variant["purchasable"]:
                    continue
                status, cart = api.call("POST", "/api/v1/guest-carts")
                if status != 201:
                    fail(f"a cart could not be made (status {status})")
                added, _ = api.call("POST", f"/api/v1/guest-carts/{cart['id']}/items", {"variantId": variant["variantId"], "quantity": 1})
                changed, body = api.call("PUT", f"/api/v1/guest-carts/{cart['id']}/items/{variant['variantId']}", {"quantity": 2})
                rows.append({"variantId": variant["variantId"], "available": available.get(variant["variantId"]), "add_1": added, "change_to_2": changed, "detail": body.get("detail") if changed != 200 else None})
    table = {}
    for row in rows:
        key = row["available"] if row["available"] is None or row["available"] < 3 else "3+"
        entry = table.setdefault(key, {"variants": 0, "change_200": 0, "change_409": 0, "other": 0, "add_not_200": 0})
        entry["variants"] += 1
        entry["add_not_200"] += row["add_1"] != 200
        entry["change_200" if row["change_to_2"] == 200 else "change_409" if row["change_to_2"] == 409 else "other"] += 1
    print(f"{len(rows)} purchasable variants of {len(products)} products; the change of the quantity 1 -> 2 by the available units:")
    print("| available units | variants | change 200 | change 409 | other | add 1 not 200 |")
    print("|---|---|---|---|---|---|")
    for key in sorted(table, key=lambda k: (k is None, str(k))):
        e = table[key]
        print(f"| {key} | {e['variants']} | {e['change_200']} | {e['change_409']} | {e['other']} | {e['add_not_200']} |")
    sample = next((r for r in rows if r["change_to_2"] == 409), None)
    if sample:
        print("a 409:", sample["detail"])
    if args.out:
        json.dump({"rows": rows, "table": {str(k): v for k, v in table.items()}}, open(args.out, "w"), indent=1)


if __name__ == "__main__":
    main()

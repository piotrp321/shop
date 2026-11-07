"""Checks of the smoke test, run inside the cluster (scripts/smoke.sh pipes this file to `python -` in a pod).

The pod reaches the services by their names and the ingresses through Traefik with the Host header, so no port of
the host is involved. Usage: python - <command> [argument]

  basic     the checks of a new installation (API, store, panel, realms, image, order e-mail)
  snapshot  prints a JSON snapshot of what an upgrade must not change (products, realms)
  compare   compares the snapshot given as the argument with the current state

Every failed check raises with a message that says what was expected; the exit code is then not zero.
"""

import hashlib
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

TRAEFIK = "http://traefik.kube-system.svc.cluster.local"
DOMAIN = "localhost"
KEYCLOAK = "http://keycloak:8080"
MAILPIT = "http://mailpit:8025"


def request(method, url, host=None, body=None, headers=None, form=None):
    """Returns (status, headers, text); an HTTP error status is a result, not an exception."""
    data = None
    headers = dict(headers or {})
    if host:
        headers["Host"] = host
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    if form is not None:
        data = urllib.parse.urlencode(form).encode()
    req = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=60) as response:
            return response.status, response.headers, response.read().decode(errors="replace")
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read().decode(errors="replace")


def through_ingress(path, sub="", **kwargs):
    host = f"{sub}.{DOMAIN}" if sub else DOMAIN
    return request(kwargs.pop("method", "GET"), TRAEFIK + path, host=host, **kwargs)


def expect(condition, message):
    if not condition:
        raise AssertionError(message)
    print(f"ok: {message}")


def retry(description, function, seconds=120):
    """Calls function until it returns a truthy value (a page that is built on the first request, a message in transit)."""
    deadline = time.time() + seconds
    last = None
    while time.time() < deadline:
        try:
            last = function()
            if last:
                return last
        except (urllib.error.URLError, OSError, AssertionError) as error:
            last = error
        time.sleep(2)
    raise AssertionError(f"{description}: not satisfied in {seconds} s (last: {last})")


def admin_token():
    status, _, text = request(
        "POST",
        f"{KEYCLOAK}/realms/master/protocol/openid-connect/token",
        form={
            "client_id": "admin-cli",
            "grant_type": "password",
            "username": "admin",
            "password": os.environ["KC_ADMIN_PASSWORD"],
        },
    )
    expect(status == 200, "the administrator of Keycloak logs in")
    return json.loads(text)["access_token"]


def admin_get(path, token):
    status, _, text = request("GET", f"{KEYCLOAK}/admin/realms{path}", headers={"Authorization": f"Bearer {token}"})
    expect(status == 200, f"Keycloak admin API answers {path}")
    return json.loads(text)


def check_api_and_store():
    status, _, text = through_ingress("/health", "api")
    expect(status == 200 and '"status":"ok"' in text.replace(" ", ""), "the /health of the API answers ok")

    # The page is rendered on the first request (ISR) from the catalog the job `initialize` loaded.
    def home():
        status, headers, text = through_ingress("/")
        return (status, headers, text) if status == 200 and 'href="/p/' in text else None

    status, headers, text = retry("the home page of the store lists products", home)
    expect(True, "the home page of the store lists products")
    expect("noindex" in headers.get("X-Robots-Tag", ""), "the store sends X-Robots-Tag with noindex")
    status, headers, _ = through_ingress("/", "admin")
    expect(status == 200 and "noindex" in headers.get("X-Robots-Tag", ""), "the panel answers with X-Robots-Tag noindex")


def check_admin_config():
    status, headers, text = through_ingress("/config.js", "admin")
    expect(status == 200, "/config.js of the panel answers")
    for key, sub in (("VITE_API_URL", "api"), ("VITE_KEYCLOAK_URL", "auth"), ("VITE_ADMIN_URL", "admin")):
        expect(f'"{key}": "http://{sub}.{DOMAIN}"' in text, f"/config.js has {key} of the environment")
    expect("no-cache" in headers.get("Cache-Control", ""), "/config.js is not cached")
    status, _, text = through_ingress("/auth/callback", "admin")
    expect(status == 200 and "<div id=" in text, "the panel serves index.html for /auth/callback")


def check_realms():
    for realm in ("shop", "backoffice"):
        status, _, text = through_ingress(f"/realms/{realm}/.well-known/openid-configuration", "auth")
        expect(status == 200, f"discovery of the realm {realm} answers")
        expect(json.loads(text)["issuer"] == f"http://auth.{DOMAIN}/realms/{realm}", f"the issuer of {realm} is the public address")
    for path in ("/realms/master/.well-known/openid-configuration", "/admin/master/console/", "/", "/admin/realms"):
        status, _, _ = through_ingress(path, "auth")
        expect(status == 404, f"auth.{DOMAIN}{path} is not exposed (got {status})")
    token = admin_token()
    for realm in ("shop", "backoffice"):
        expect(admin_get(f"/{realm}/users", token) == [], f"the realm {realm} has no users")


def check_image():
    status, _, text = through_ingress("/api/v1/products?perPage=1", "api")
    expect(status == 200, "the product list of the API answers")
    url = json.loads(text)["items"][0]["image"]["variants"]["webp"][0]["url"]
    parsed = urllib.parse.urlparse(url)
    expect(parsed.netloc == f"img.{DOMAIN}", f"the image URL points at the public imgproxy host ({parsed.netloc})")
    status, headers, _ = through_ingress(parsed.path, "img")
    expect(status == 200 and headers.get("Content-Type", "").startswith("image/"), "imgproxy serves the product image")


def check_order_mail():
    status, _, text = through_ingress("/api/v1/guest-carts", "api", method="POST")
    expect(status == 201, "a guest cart is created")
    cart = json.loads(text)["id"]
    status, _, text = through_ingress("/api/v1/products?perPage=10", "api")
    total = None
    for item in json.loads(text)["items"]:
        status, _, detail = through_ingress(f"/api/v1/products/{item['slug']}", "api")
        for variant in json.loads(detail)["variants"]:
            status, _, text = through_ingress(
                f"/api/v1/guest-carts/{cart}/items", "api", method="POST", body={"variantId": variant["variantId"], "quantity": 1}
            )
            if status == 200:
                total = json.loads(text)["totals"]["gross"]
                break
        if total is not None:
            break
    expect(total is not None, "a product is added to the cart")
    order = {
        "cartId": cart,
        "email": "smoke@example.com",
        "fullName": "Smoke Test",
        "phone": "600100200",
        "street": "ul. Testowa 1",
        "postalCode": "00-950",
        "city": "Warszawa",
        "expectedTotalGross": total,
    }
    status, _, text = through_ingress(
        "/api/v1/orders", "api", method="POST", body=order, headers={"Idempotency-Key": f"smoke-{int(time.time())}"}
    )
    expect(status == 201, f"the order is placed (got {status}: {text[:200]})")
    number = json.loads(text)["number"]

    def confirmation():
        status, _, text = request("GET", f"{MAILPIT}/api/v1/search?query=" + urllib.parse.quote(f'to:"smoke@example.com" subject:"{number}"'))
        return status == 200 and json.loads(text)["messages"] or None

    messages = retry(f"the confirmation of {number} in Mailpit", confirmation)
    expect("Potwierdzenie zamówienia" in messages[0]["Subject"], f"Mailpit has the confirmation of the order {number}")


def snapshot():
    token = admin_token()
    realms = {}
    for realm in ("shop", "backoffice"):
        document = {
            "realm": admin_get(f"/{realm}", token),
            "clients": sorted(admin_get(f"/{realm}/clients", token), key=lambda c: c["clientId"]),
            "roles": sorted(admin_get(f"/{realm}/roles", token), key=lambda r: r["name"]),
            "users": admin_get(f"/{realm}/users", token),
        }
        realms[realm] = hashlib.sha256(json.dumps(document, sort_keys=True).encode()).hexdigest()
    status, _, text = through_ingress("/api/v1/products?perPage=1", "api")
    expect(status == 200, "the product list answers")
    return {"products": json.loads(text)["total"], "realms": realms}


def main():
    command = sys.argv[1] if len(sys.argv) > 1 else ""
    if command == "basic":
        check_api_and_store()
        check_admin_config()
        check_realms()
        check_image()
        check_order_mail()
    elif command == "snapshot":
        print(json.dumps(snapshot(), sort_keys=True))
    elif command == "compare":
        before = json.loads(sys.argv[2])
        after = snapshot()
        expect(before == after, f"products and realms are unchanged (before {before}, after {after})")
    else:
        raise SystemExit(f"checks.py: unknown command '{command}'")


if __name__ == "__main__":
    main()

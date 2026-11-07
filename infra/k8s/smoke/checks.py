"""Checks of the smoke test, run inside the cluster (scripts/smoke.sh pipes this file to `python -` in a pod).

The pod reaches the services by their names and the ingresses through Traefik with the Host header, so no port of
the host is involved. Usage: python - <command> [argument]

  basic     the checks of a new installation (API, store, panel, realms, image, order e-mail)
  snapshot  prints a JSON snapshot of what an upgrade must not change (products, realms)
  compare   compares the snapshot given as the argument with the current state

Every failed check raises with a message that says what was expected; the exit code is then not zero.
"""

import hashlib
import http.client
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


def raw_get(path, sub):
    """GET with the path exactly as given (no normalization on the client side). Returns (status, text)."""
    connection = http.client.HTTPConnection(TRAEFIK.split("//", 1)[1], timeout=60)
    try:
        connection.putrequest("GET", path, skip_host=True, skip_accept_encoding=True)
        connection.putheader("Host", f"{sub}.{DOMAIN}")
        connection.endheaders()
        response = connection.getresponse()
        return response.status, response.read().decode(errors="replace")
    finally:
        connection.close()


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
    check_auth_bypasses()


# Paths that try to get from an allowed prefix of the auth host to the realm master or to the administration console:
# dot segments, encoded dots and slashes (any case), a doubled slash. Sent as they are, without normalization.
AUTH_BYPASSES = (
    "/realms/shop/../master/.well-known/openid-configuration",
    "/realms/shop/%2e%2e/master/.well-known/openid-configuration",
    "/realms/shop/%2E%2E/master/.well-known/openid-configuration",
    "/realms/shop/..%2fmaster/.well-known/openid-configuration",
    "/realms/shop/..%2Fmaster/.well-known/openid-configuration",
    "/realms/shop/%2e%2e%2fmaster/.well-known/openid-configuration",
    "//realms/master/.well-known/openid-configuration",
    "/realms/backoffice/../../admin/master/console/",
    "/realms/backoffice/%2e%2e/%2e%2e/admin/master/console/",
    "/resources/../admin/master/console/",
    "/resources/%2e%2e/admin/master/console/",
    "/resources/..;/admin/master/console/",
)


def check_auth_bypasses():
    for path in AUTH_BYPASSES:
        status, text = raw_get(path, "auth")
        leaked = status == 200 or '"issuer"' in text or "Keycloak Administration" in text
        expect(status in (400, 404) and not leaked, f"auth.{DOMAIN}{path} does not reach the realm master or the console (got {status})")


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


def canonical(value):
    """The value with every list in a fixed order: Keycloak returns sets (scopes, locales, ...) in no particular order."""
    if isinstance(value, dict):
        return {key: canonical(item) for key, item in value.items()}
    if isinstance(value, list):
        return sorted((canonical(item) for item in value), key=lambda item: json.dumps(item, sort_keys=True))
    return value


def digest(value):
    return hashlib.sha256(json.dumps(canonical(value), sort_keys=True).encode()).hexdigest()[:12]


def snapshot():
    """Digests of what an upgrade must not change, per realm and per part, so that a difference says where it is."""
    token = admin_token()
    realms = {}
    for realm in ("shop", "backoffice"):
        document = admin_get(f"/{realm}", token)
        parts = {f"realm.{key}": digest(value) for key, value in document.items()}
        parts["clients"] = digest(sorted(admin_get(f"/{realm}/clients", token), key=lambda c: c["clientId"]))
        parts["roles"] = digest(sorted(admin_get(f"/{realm}/roles", token), key=lambda r: r["name"]))
        parts["users"] = digest(admin_get(f"/{realm}/users", token))
        realms[realm] = parts
    status, _, text = through_ingress("/api/v1/products?perPage=1", "api")
    expect(status == 200, "the product list answers")
    return {"products": json.loads(text)["total"], "realms": realms}


def differences(before, after):
    found = []
    if before["products"] != after["products"]:
        found.append(f"products {before['products']} -> {after['products']}")
    for realm, parts in before["realms"].items():
        for part, value in parts.items():
            if after["realms"][realm].get(part) != value:
                found.append(f"{realm}: {part}")
    return found


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
        expect(not differences(before, after), f"products and realms are unchanged (differences: {differences(before, after)})")
    else:
        raise SystemExit(f"checks.py: unknown command '{command}'")


if __name__ == "__main__":
    main()

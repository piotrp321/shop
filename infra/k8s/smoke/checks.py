"""Checks of the smoke test, run inside the cluster (scripts/smoke.sh pipes this file to `python -` in a pod).

The pod reaches the services by their names and the ingresses through Traefik with the Host header, so no port of
the host is involved. Usage: python - <command> [argument]

  basic         the checks of a new installation (search analysis, API, store, switches, panel and its security headers, realms, store login,
                image, order e-mail)
  snapshot      prints a JSON snapshot of what an upgrade must not change (products, realms)
  compare       compares the snapshot given as the argument with the current state
  users         prints a JSON of the users of the realms and of their credentials (ids and dates, no secrets)
  client-added  after the upgrade that added the client smoke-extra to the realm file: the client is there, the users
                and their credentials are those of the argument (the output of `users`), the customer logs in again
  admin-absent  <login> <email>: no user <login> in backoffice and no e-mail to <email> (create-admin without `tak`)
  admin-mail    <login> <email>: the user of create-admin and its one e-mail with the actions in Mailpit
  restored      <login> <slug> <phrase>: after make restore on a reinstalled cluster: the administrator <login> of create-admin is
                in backoffice, the customer of `basic` is there and logs in to the store, the home page lists products, imgproxy
                serves an image, and the search finds the product <slug> by the <phrase> that only the restored database has

Every failed check raises with a message that says what was expected; the exit code is then not zero.
"""

import base64
import hashlib
import html
import http.client
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

TRAEFIK = "http://traefik.kube-system.svc.cluster.local"
DOMAIN = "localhost"
KEYCLOAK = "http://keycloak:8080"
MAILPIT = "http://mailpit:8025"
ELASTICSEARCH = "http://elasticsearch:9200"
# The customer the smoke test logs in with (created in the realm shop by `basic`, after the check that it has no users).
CUSTOMER = "smoke.customer@example.com"
DEMO_NOTICE = "Sklep demonstracyjny: płatności i zamówienia są symulowane"


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


def admin_get(path, token, quiet=False):
    status, _, text = request("GET", f"{KEYCLOAK}/admin/realms{path}", headers={"Authorization": f"Bearer {token}"})
    if quiet:
        assert status == 200, f"Keycloak admin API answers {path} (got {status})"
    else:
        expect(status == 200, f"Keycloak admin API answers {path}")
    return json.loads(text)


def check_search_analysis():
    """The image of infra/elasticsearch/Dockerfile carries the Polish analysis plugin and its stemmer works."""
    status, _, text = request("GET", f"{ELASTICSEARCH}/_cat/plugins?h=component")
    expect(status == 200 and "analysis-stempel" in text.split(), f"Elasticsearch lists the plugin analysis-stempel ({text.strip()})")
    status, _, text = request(
        "POST", f"{ELASTICSEARCH}/_analyze", body={"tokenizer": "standard", "filter": ["lowercase", "polish_stem"], "text": "koszulkami"}
    )
    tokens = [token["token"] for token in json.loads(text).get("tokens", [])] if status == 200 else text[:200]
    expect(tokens == ["koszulka"], f"polish_stem stems 'koszulkami' to 'koszulka' (got {status}: {tokens})")


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
    # The store is the bare domain (the link "Sklep" of the topbar).
    expect(f'"VITE_STORE_URL": "http://{DOMAIN}"' in text, "/config.js has VITE_STORE_URL of the environment")
    expect("no-cache" in headers.get("Cache-Control", ""), "/config.js is not cached")
    status, _, text = through_ingress("/auth/callback", "admin")
    expect(status == 200 and "<div id=" in text, "the panel serves index.html for /auth/callback")


ADMIN_SECURITY_HEADERS = {
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "strict-origin-when-cross-origin",
    "X-Frame-Options": "DENY",
    "Permissions-Policy": "camera=(), microphone=(), geolocation=()",
    "Content-Security-Policy": "script-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self'",
}


def check_admin_security_headers():
    """D10: every location of the nginx of the panel sends the security headers, on 200 and on 404 alike. The ingress of
    the profile ci has no TLS, so no HSTS here (tests of the render)."""
    _, _, index = through_ingress("/", "admin")
    scripts = [path for path in re.findall(r'<script[^>]*\ssrc="([^"]+)"', index) if path.startswith("/assets/")]
    expect(len(scripts) == 1, f"index.html of the panel loads one bundle from /assets/ ({scripts})")
    expect(re.search(r"<script(?![^>]*\ssrc=)", index) is None, "index.html of the panel has no inline script")
    for path, status_expected in (("/", 200), ("/config.js", 200), ("/auth/callback", 200), (scripts[0], 200), ("/assets/missing.js", 404)):
        status, headers, _ = through_ingress(path, "admin")
        expect(status == status_expected, f"the panel answers {path} with {status_expected} (got {status})")
        for key, value in ADMIN_SECURITY_HEADERS.items():
            expect(headers.get(key) == value, f"the panel sends {key}: {value} on {path} (got {headers.get(key)})")
        expect(headers.get("Strict-Transport-Security") is None, f"the profile ci sends no HSTS on {path}")
    status, headers, _ = through_ingress(scripts[0], "admin")
    expect("immutable" in headers.get("Cache-Control", ""), "the bundle keeps its long Cache-Control")


def check_store_and_api_security_headers():
    """D10 on the production images: the store sends the headers of the panel except script-src, the API nosniff."""
    store = {**ADMIN_SECURITY_HEADERS, "Content-Security-Policy": "frame-ancestors 'none'; object-src 'none'; base-uri 'self'"}
    status, headers, _ = through_ingress("/")
    expect(status == 200, f"the store answers / (got {status})")
    for key, value in store.items():
        expect(headers.get(key) == value, f"the store sends {key}: {value} (got {headers.get(key)})")
    for path in ("/health", "/does-not-exist"):
        _, headers, _ = through_ingress(path, "api")
        expect(headers.get("X-Content-Type-Options") == "nosniff", f"the API sends X-Content-Type-Options: nosniff on {path}")


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
        expect(admin_get(f"/{realm}/users", token) == [], f"the realm {realm} has no users (no demo accounts)")
    check_realm_settings(token)
    check_auth_bypasses()


def check_realm_settings(token):
    """The values the job keycloak-config sets on every run (profile ci: customerAccounts false, mail to Mailpit)."""
    shop = admin_get("/shop", token)
    expect(shop["registrationAllowed"] is False, "registration is disabled in the realm shop (customerAccounts: false)")
    expect(shop["verifyEmail"] is True, "the realm shop verifies e-mail addresses")
    for realm, document in (("shop", shop), ("backoffice", admin_get("/backoffice", token))):
        smtp = document.get("smtpServer", {})
        expect(
            (smtp.get("host"), smtp.get("port"), smtp.get("from")) == ("mailpit", "1025", f"konto@{DOMAIN}"),
            f"the realm {realm} sends through Mailpit as konto@{DOMAIN} ({smtp.get('host')}:{smtp.get('port')} {smtp.get('from')})",
        )
    store = admin_get("/shop/clients?clientId=store", token)[0]
    expect(store["redirectUris"] == [f"http://{DOMAIN}/auth/callback"], f"the client store redirects to the store ({store['redirectUris']})")
    expect(store["attributes"].get("pkce.code.challenge.method") == "S256", "the client store keeps its attributes (PKCE S256)")
    admin = admin_get("/backoffice/clients?clientId=admin", token)[0]
    expect(admin["webOrigins"] == [f"http://admin.{DOMAIN}"], f"the client admin allows the origin of the panel ({admin['webOrigins']})")


# Paths that try to get from an allowed prefix of the auth host to the realm master or to the administration console:
# dot segments, encoded dots and slashes (any case), a doubled slash. Sent as they are, without normalization.
AUTH_BYPASSES = (
    "/realms/shop/../master/.well-known/openid-configuration",
    "/realms/shop/%2e%2e/master/.well-known/openid-configuration",
    "/realms/shop/%2E%2E/master/.well-known/openid-configuration",
    "/realms/shop/..%2fmaster/.well-known/openid-configuration",
    "/realms/shop/..%2Fmaster/.well-known/openid-configuration",
    "/realms/shop/%2e%2e%2fmaster/.well-known/openid-configuration",
    "/realms/shop/..;/master/.well-known/openid-configuration",
    "/realms/shop/%252e%252e/master/.well-known/openid-configuration",
    "/realms/shop/..%5cmaster/.well-known/openid-configuration",
    "/realms/shop/./../master/.well-known/openid-configuration",
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


def check_store_switches():
    """The switches of /api/site (the browser part is checked by smoke.sh with a real browser); the HTML of the pages does
    not depend on them."""
    status, headers, text = through_ingress("/api/site")
    expect(status == 200 and json.loads(text) == {"demoNotice": True, "customerAccounts": False},
           f"/api/site of the store gives the switches of the profile ci ({status}: {text[:100]})")
    expect(headers.get("Cache-Control") == "no-store", "/api/site is not cached")
    for path in ("/", "/cart"):
        status, _, text = through_ingress(path)
        expect(status == 200 and DEMO_NOTICE not in text, f"the HTML of {path} has no demo bar (the browser adds it)")
    # The login link waits for the session check too: it answers, so a missing link is the switch.
    status, _, text = through_ingress("/auth/session")
    expect(status == 200 and json.loads(text) is None, f"/auth/session of the store answers for a guest ({status}: {text[:100]})")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Browser:
    """A minimal browser for the login: cookies per host, no automatic redirects, and every request goes to Traefik with
    the Host of its URL (http://<host>.localhost/... is not reachable by name from a pod)."""

    def __init__(self):
        self.cookies = {}
        self.opener = urllib.request.build_opener(NoRedirect)

    def send(self, url, form=None):
        parsed = urllib.parse.urlparse(url)
        jar = self.cookies.setdefault(parsed.netloc, {})
        headers = {"Host": parsed.netloc}
        if jar:
            headers["Cookie"] = "; ".join(f"{key}={value}" for key, value in jar.items())
        data = urllib.parse.urlencode(form).encode() if form is not None else None
        path = parsed.path + (f"?{parsed.query}" if parsed.query else "")
        req = urllib.request.Request(TRAEFIK + path, data=data, headers=headers, method="POST" if form is not None else "GET")
        try:
            response = self.opener.open(req, timeout=60)
            status, response_headers, body = response.status, response.headers, response.read()
        except urllib.error.HTTPError as error:
            status, response_headers, body = error.code, error.headers, error.read()
        for cookie in response_headers.get_all("Set-Cookie") or []:
            pair, _, attributes = cookie.partition(";")
            key, _, value = pair.strip().partition("=")
            if value and "max-age=0" not in attributes.lower().replace(" ", ""):
                jar[key] = value
            else:
                jar.pop(key, None)
        return status, response_headers, body.decode(errors="replace")


def ensure_customer(token):
    """The customer of the smoke test in the realm shop, with the password of SMOKE_CUSTOMER_PASSWORD."""
    found = admin_get(f"/shop/users?username={urllib.parse.quote(CUSTOMER)}&exact=true", token)
    if found:
        return found[0]["id"]
    customer = {
        "username": CUSTOMER,
        "email": CUSTOMER,
        "firstName": "Smoke",
        "lastName": "Klient",
        "enabled": True,
        "emailVerified": True,
        "credentials": [{"type": "password", "value": os.environ["SMOKE_CUSTOMER_PASSWORD"], "temporary": False}],
    }
    status, _, text = request("POST", f"{KEYCLOAK}/admin/realms/shop/users", body=customer, headers={"Authorization": f"Bearer {token}"})
    expect(status == 201, f"the customer {CUSTOMER} is created in the realm shop for the login ({status} {text[:200]})")
    return admin_get(f"/shop/users?username={urllib.parse.quote(CUSTOMER)}&exact=true", token)[0]["id"]


def store_login():
    """The whole login of the store (code flow): /auth/login, the login form of Keycloak, the callback, where the store
    exchanges the code with the secret of the client store set by the job, and /auth/session with the new session."""
    browser = Browser()
    status, headers, _ = browser.send(f"http://{DOMAIN}/auth/login?returnTo=%2Fcart")
    location = headers.get("Location", "")
    expect(status in (302, 303, 307) and location.startswith(f"http://auth.{DOMAIN}/realms/shop/"),
           f"/auth/login of the store sends the browser to the realm shop ({status} {location[:80]})")
    status, _, page = browser.send(location)
    action = next((html.unescape(part.split('"', 1)[0]) for part in page.split('action="')[1:] if "login-actions/authenticate" in part), None)
    expect(status == 200 and action, f"Keycloak shows the login form ({status})")
    status, headers, page = browser.send(action, form={"username": CUSTOMER, "password": os.environ["SMOKE_CUSTOMER_PASSWORD"], "credentialId": ""})
    location = headers.get("Location", "")
    expect(status == 302 and location.startswith(f"http://{DOMAIN}/auth/callback?"),
           f"Keycloak accepts the password and returns to the callback of the store ({status} {location[:80]} {page[:200] if status != 302 else ''})")
    status, headers, _ = browser.send(location)
    expect(status in (302, 303, 307) and headers.get("Location", "").endswith("/cart") and "store_session" in browser.cookies[DOMAIN],
           f"the store exchanges the code with its client secret and starts a session ({status} {headers.get('Location')})")
    status, _, text = browser.send(f"http://{DOMAIN}/auth/session")
    session = json.loads(text) if status == 200 else None
    expect(session and session.get("email") == CUSTOMER, f"/auth/session gives the customer that logged in ({status}: {text[:100]})")


def check_store_client_secret():
    """The token endpoint authenticates the client store with the secret of the chart and refuses another one: a bogus
    code then fails as invalid_grant (the client was authenticated) and not as invalid_client."""
    token_url = f"{KEYCLOAK}/realms/shop/protocol/openid-connect/token"
    form = {"grant_type": "authorization_code", "code": "bogus", "redirect_uri": f"http://{DOMAIN}/auth/callback", "client_id": "store"}
    status, _, text = request("POST", token_url, form=form | {"client_secret": os.environ["STORE_CLIENT_SECRET"]})
    expect(status == 400 and json.loads(text).get("error") == "invalid_grant",
           f"Keycloak authenticates the client store with the secret of the chart ({status} {text[:100]})")
    status, _, text = request("POST", token_url, form=form | {"client_secret": "not-the-secret"})
    expect(status == 401, f"Keycloak refuses the client store with another secret ({status} {text[:100]})")


def check_store_login():
    ensure_customer(admin_token())
    check_store_client_secret()
    store_login()


def users_state():
    """Per realm and user: the id and the ids, types and dates of the credentials (the admin API gives no secret)."""
    token = admin_token()
    state = {}
    for realm in ("shop", "backoffice"):
        users = admin_get(f"/{realm}/users?briefRepresentation=true", token, quiet=True)
        state[realm] = {
            user["username"]: {
                "id": user["id"],
                "credentials": sorted(
                    (c["id"], c["type"], c.get("createdDate")) for c in admin_get(f"/{realm}/users/{user['id']}/credentials", token, quiet=True)
                ),
            }
            for user in users
        }
    return state


def check_client_added(before):
    token = admin_token()
    clients = admin_get("/shop/clients?clientId=smoke-extra", token)
    expect(len(clients) == 1 and clients[0]["redirectUris"] == [f"http://{DOMAIN}/smoke-extra/*"],
           "the client smoke-extra of the realm file is in the realm shop, with the address of the domain")
    after = json.loads(json.dumps(users_state()))
    expect(after == before, f"the users of the realms and their credentials (passwords) are unchanged ({sorted(after['shop'])}, {sorted(after['backoffice'])})")
    expect(CUSTOMER in after["shop"], "the customer is still there")
    store_login()


def mails_to(address):
    status, _, text = request("GET", f"{MAILPIT}/api/v1/search?query=" + urllib.parse.quote(f'to:"{address}"'))
    assert status == 200, f"Mailpit search answers ({status})"
    return json.loads(text)["messages"]


def backoffice_user(token, login):
    return admin_get(f"/backoffice/users?username={urllib.parse.quote(login)}&exact=true", token)


def check_admin_absent(login, address):
    token = admin_token()
    expect(backoffice_user(token, login) == [], f"there is no user {login} in the realm backoffice")
    expect(mails_to(address) == [], f"nothing was sent to {address}")


def jwt_claims(token):
    payload = token.split(".")[1]
    return json.loads(base64.urlsafe_b64decode(payload + "=" * (-len(payload) % 4)))


def check_admin_mail(login, address):
    """The user create-admin made (role admin, no password) and its one e-mail through the SMTP set by keycloak-config."""
    token = admin_token()
    users = backoffice_user(token, login)
    expect(len(users) == 1 and users[0]["email"] == address and users[0]["enabled"], f"the realm backoffice has the user {login} ({address})")
    user = users[0]["id"]
    roles = [role["name"] for role in admin_get(f"/backoffice/users/{user}/role-mappings/realm", token)]
    expect("admin" in roles, f"the user {login} has the role admin ({roles})")
    expect(admin_get(f"/backoffice/users/{user}/credentials", token) == [], f"the user {login} has no password nor TOTP")
    messages = retry("the e-mail of create-admin in Mailpit", lambda: mails_to(address))
    expect(len(messages) == 1, f"Mailpit has one e-mail to {address} (got {len(messages)})")
    sender = messages[0]["From"]["Address"]
    expect(sender == f"konto@{DOMAIN}", f"the e-mail comes from the sender of the realm ({sender})")
    status, _, text = request("GET", f"{MAILPIT}/api/v1/message/{messages[0]['ID']}")
    body = json.loads(text)["Text"] if status == 200 else ""
    links = [word for word in body.split() if "/realms/backoffice/login-actions/action-token?key=" in word]
    expect(links, "the e-mail has the link of the actions of the realm backoffice")
    key = urllib.parse.parse_qs(urllib.parse.urlparse(links[0].strip("<>()")).query)["key"][0]
    claims = jwt_claims(key)
    # exp is counted from a moment just before the token is issued: a second may pass in between.
    expect(abs(claims["exp"] - claims["iat"] - 43200) <= 2, f"the link is valid 12 h ({claims['exp'] - claims['iat']} s)")
    actions = next((value for value in claims.values() if isinstance(value, list) and "UPDATE_PASSWORD" in value), [])
    expect(sorted(actions) == ["CONFIGURE_TOTP", "UPDATE_PASSWORD"], f"the link sets the password and configures TOTP ({actions})")
    print(json.dumps({"user": user}))


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


def check_restored(login, slug, phrase):
    token = admin_token()
    users = backoffice_user(token, login)
    expect(len(users) == 1 and users[0]["enabled"], f"the administrator {login} of create-admin is in the realm backoffice")
    roles = [role["name"] for role in admin_get(f"/backoffice/users/{users[0]['id']}/role-mappings/realm", token)]
    expect("admin" in roles, f"the administrator {login} has the role admin ({roles})")
    customers = admin_get(f"/shop/users?username={urllib.parse.quote(CUSTOMER)}&exact=true", token)
    expect(len(customers) == 1, f"the customer {CUSTOMER} of the backup is in the realm shop")
    check_store_client_secret()
    store_login()
    check_api_and_store()
    check_image()

    def found():
        status, _, text = through_ingress(f"/api/v1/products?q={urllib.parse.quote(phrase)}&perPage=5", "api")
        return status == 200 and slug in [item["slug"] for item in json.loads(text)["items"]]

    retry(f"the search finds {slug} by '{phrase}'", found)
    expect(True, f"the search finds the product {slug} by '{phrase}', which only the restored database has (the index was rebuilt)")


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
    """What differs between two snapshots, over the union of the parts: one that only one side has counts too."""
    found = []
    if before["products"] != after["products"]:
        found.append(f"products {before['products']} -> {after['products']}")
    for realm in sorted(set(before["realms"]) | set(after["realms"])):
        if realm not in after["realms"]:
            found.append(f"{realm}: missing after the upgrade")
        elif realm not in before["realms"]:
            found.append(f"{realm}: missing before the upgrade")
        else:
            old, new = before["realms"][realm], after["realms"][realm]
            found += [f"{realm}: {part}" for part in sorted(set(old) | set(new)) if old.get(part) != new.get(part)]
    return found


def main():
    command = sys.argv[1] if len(sys.argv) > 1 else ""
    if command == "basic":
        check_search_analysis()
        check_api_and_store()
        check_store_switches()
        check_admin_config()
        check_admin_security_headers()
        check_store_and_api_security_headers()
        check_realms()
        check_store_login()
        check_image()
        check_order_mail()
    elif command == "snapshot":
        print(json.dumps(snapshot(), sort_keys=True))
    elif command == "users":
        print(json.dumps(users_state(), sort_keys=True))
    elif command == "client-added":
        check_client_added(json.loads(sys.argv[2]))
    elif command == "admin-absent":
        check_admin_absent(sys.argv[2], sys.argv[3])
    elif command == "admin-mail":
        check_admin_mail(sys.argv[2], sys.argv[3])
    elif command == "restored":
        check_restored(sys.argv[2], sys.argv[3], sys.argv[4])
    elif command == "compare":
        before = json.loads(sys.argv[2])
        after = snapshot()
        expect(not differences(before, after), f"products and realms are unchanged (differences: {differences(before, after)})")
    else:
        raise SystemExit(f"checks.py: unknown command '{command}'")


if __name__ == "__main__":
    main()

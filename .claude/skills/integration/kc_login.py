#!/usr/bin/env python3
"""Logs a demo account in to the local stack the way a browser does (Python standard library only).

staff:    authorization code + PKCE S256 with the public client `admin` of the realm `backoffice` (password, then
          the TOTP code of the demo secret); prints the access token for `Authorization: Bearer` on /api/v1/admin.
customer: logs in through the store (`/auth/login` -> Keycloak realm `shop` -> `/auth/callback`, which also merges
          the guest cart of `store_cart`); loads and saves a Netscape cookie jar shared with `curl -b/-c`.

Use `localhost` (not 127.0.0.1) for Keycloak, the panel and the store: Keycloak's hostname is `localhost`, and its
login form posts to that host with cookies set for it; a mismatch makes Keycloak answer 400.
Each login uses one TOTP code; a code already used in the current 30 s window is rejected once, then the login
waits for the next window. A wrong password is never retried (brute force protection of both realms).
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import html
import http.cookiejar
import os
import re
import secrets
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from html.parser import HTMLParser

# Demo accounts of the imported realms; the same values as e2e/support/accounts.ts (checked by the tests).
STAFF = {
    "admin": ("admin", "Pracownik-Demo-1", "demo-totp-admin-0001"),
    "support": ("support", "Pracownik-Demo-1", "demo-totp-support-01"),
    "warehouse": ("warehouse", "Pracownik-Demo-1", "demo-totp-warehouse1"),
    "marketing": ("marketing", "Pracownik-Demo-1", "demo-totp-marketing1"),
}
CUSTOMERS = {
    "jan": ("jan.kowalski@example.com", "Klient-Demo-1"),
    "anna": ("anna.nowak@example.com", "Klient-Demo-1"),
}

OTP_PERIOD = 30
# A code this close to the end of its window may expire before Keycloak checks it.
OTP_MIN_REMAINING = 3


class LoginError(Exception):
    pass


def totp(raw_secret: str, now: float) -> str:
    """TOTP (SHA1, 6 digits, 30 s) of a raw UTF-8 secret, as e2e/support/totp.ts computes it."""
    counter = int(now // OTP_PERIOD).to_bytes(8, "big")
    digest = hmac.new(raw_secret.encode("utf-8"), counter, hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    code = (int.from_bytes(digest[offset : offset + 4], "big") & 0x7FFFFFFF) % 1_000_000
    return f"{code:06d}"


def fresh_totp(raw_secret: str) -> str:
    remaining = OTP_PERIOD - time.time() % OTP_PERIOD
    if remaining < OTP_MIN_REMAINING:
        time.sleep(remaining + 0.1)
    return totp(raw_secret, time.time())


def pkce_pair() -> tuple[str, str]:
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
    return verifier, challenge


@dataclass
class Form:
    action: str
    fields: dict = field(default_factory=dict)


class _FormParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms: list[Form] = []
        self._current: Form | None = None

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == "form":
            # HTMLParser already unescapes attribute values (&amp; -> &).
            self._current = Form(action=attributes.get("action") or "")
            self.forms.append(self._current)
        elif tag == "input" and self._current is not None:
            name = attributes.get("name")
            if name and (attributes.get("type") or "text").lower() not in ("submit", "button", "checkbox"):
                self._current.fields[name] = attributes.get("value") or ""

    def handle_endtag(self, tag):
        if tag == "form":
            self._current = None


def find_form(page: str, required_field: str) -> Form | None:
    """The first form of the page that has an input named `required_field`."""
    parser = _FormParser()
    parser.feed(page)
    return next((form for form in parser.forms if required_field in form.fields), None)


def otp_rejected(page: str) -> bool:
    return 'id="input-error-otp"' in page


class CurlCookieJar(http.cookiejar.CookieJar):
    """A Netscape cookie file in the dialect of `curl -b/-c`: `#HttpOnly_` prefix, host `localhost` (the standard
    library keeps it as `localhost.local`) and expiry 0 for session cookies (curl drops a line with an empty one)."""

    HTTPONLY = "#HttpOnly_"

    def __init__(self, filename: str):
        super().__init__()
        self.filename = filename

    def load(self) -> None:
        if not os.path.exists(self.filename):
            return
        with open(self.filename, encoding="utf-8") as jar_file:
            for line in jar_file.read().splitlines():
                http_only = line.startswith(self.HTTPONLY)
                if http_only:
                    line = line[len(self.HTTPONLY):]
                parts = line.split("\t")
                if line.startswith("#") or len(parts) != 7:
                    continue
                domain, _, path, secure, expires, name, value = parts
                if "." not in domain:
                    domain += ".local"
                session = expires in ("", "0")
                self.set_cookie(http.cookiejar.Cookie(
                    0, name, value, None, False, domain, domain.startswith("."), domain.startswith("."), path, True,
                    secure == "TRUE", None if session else int(expires), session, None, None,
                    {"HttpOnly": None} if http_only else {},
                ))

    def save(self) -> None:
        lines = ["# Netscape HTTP Cookie File", ""]
        for cookie in self:
            domain = cookie.domain[: -len(".local")] if cookie.domain.endswith(".local") else cookie.domain
            prefix = self.HTTPONLY if cookie.has_nonstandard_attr("HttpOnly") else ""
            lines.append("\t".join([
                prefix + domain, "TRUE" if domain.startswith(".") else "FALSE", cookie.path,
                "TRUE" if cookie.secure else "FALSE", str(cookie.expires or 0), cookie.name, cookie.value or "",
            ]))
        with open(self.filename, "w", encoding="utf-8") as jar_file:
            jar_file.write("\n".join(lines) + "\n")


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class _LocalhostIsSecure(http.cookiejar.DefaultCookiePolicy):
    """Sends `Secure` cookies to http://localhost like browsers and curl do (Keycloak marks its session cookies
    `Secure`; without them the login form POST is answered 400 `cookie_not_found`)."""

    def return_ok_secure(self, cookie, request):
        host = urllib.parse.urlparse(request.get_full_url()).hostname
        return host in ("localhost", "127.0.0.1") or super().return_ok_secure(cookie, request)


class Browser:
    """Cookie-keeping HTTP client that follows redirects by hand and stops at a given URL prefix."""

    def __init__(self, jar: http.cookiejar.CookieJar):
        jar.set_policy(_LocalhostIsSecure())
        self.jar = jar
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), _NoRedirect)

    def request(self, url: str, data: dict | None = None, stop_at: str | None = None) -> tuple[str, str]:
        """Returns (final URL, body); follows up to 10 redirects, but not into a URL starting with `stop_at`."""
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        for _ in range(10):
            request = urllib.request.Request(url, data=body)
            if body is not None:
                request.add_header("Content-Type", "application/x-www-form-urlencoded")
            try:
                with self.opener.open(request, timeout=20) as response:
                    return url, response.read().decode("utf-8", "replace")
            except urllib.error.HTTPError as error:
                if error.code not in (301, 302, 303, 307, 308):
                    text = error.read().decode("utf-8", "replace")[:300]
                    raise LoginError(f"HTTP {error.code} from {url.split('?')[0]}: {text}") from None
                url = urllib.parse.urljoin(url, error.headers["Location"])
                body = None
                if stop_at and url.startswith(stop_at):
                    return url, ""
            except urllib.error.URLError as error:
                raise LoginError(f"cannot reach {url.split('?')[0]}: {error.reason}") from None
        raise LoginError("too many redirects")


def keycloak_login(browser: Browser, url: str, page: str, username: str, password: str,
                   totp_secret: str | None, stop_at: str | None) -> tuple[str, str]:
    """Submits the Keycloak login form (and the OTP form when asked); returns where Keycloak sent the browser."""
    form = find_form(page, "username")
    if form is None:
        raise LoginError(f"no Keycloak login form at {url.split('?')[0]}")
    form.fields.update(username=username, password=password)
    url, page = browser.request(form.action, form.fields, stop_at)
    if find_form(page, "username") is not None:
        raise LoginError("Keycloak rejected the username or password (not retried: brute force protection)")
    for attempt in range(2):
        otp_form = find_form(page, "otp")
        if otp_form is None:
            return url, page
        if totp_secret is None:
            raise LoginError("Keycloak asks for a TOTP code, but the account has no TOTP secret")
        if attempt == 1:
            # The rejected code was already used in this window; the next code differs only in the next window.
            time.sleep(OTP_PERIOD - time.time() % OTP_PERIOD + 0.2)
        otp_form.fields["otp"] = fresh_totp(totp_secret)
        url, page = browser.request(otp_form.action, otp_form.fields, stop_at)
        if not otp_rejected(page):
            return url, page
    raise LoginError("Keycloak rejected the TOTP code twice")


def staff_token(keycloak: str, admin: str, account: str) -> str:
    username, password, secret = STAFF[account]
    redirect_uri = admin.rstrip("/") + "/"
    verifier, challenge = pkce_pair()
    realm = keycloak.rstrip("/") + "/realms/backoffice/protocol/openid-connect"
    query = urllib.parse.urlencode({
        "client_id": "admin", "response_type": "code", "scope": "openid", "redirect_uri": redirect_uri,
        "state": secrets.token_urlsafe(16), "nonce": secrets.token_urlsafe(16),
        "code_challenge": challenge, "code_challenge_method": "S256",
    })
    browser = Browser(http.cookiejar.CookieJar())
    url, page = browser.request(f"{realm}/auth?{query}", stop_at=redirect_uri)
    url, _ = keycloak_login(browser, url, page, username, password, secret, stop_at=redirect_uri)
    code = urllib.parse.parse_qs(urllib.parse.urlparse(url).query).get("code", [None])[0]
    if not url.startswith(redirect_uri) or code is None:
        raise LoginError(f"Keycloak did not return an authorization code (ended at {url.split('?')[0]})")
    _, token = browser.request(f"{realm}/token", {
        "grant_type": "authorization_code", "client_id": "admin", "code": code,
        "redirect_uri": redirect_uri, "code_verifier": verifier,
    })
    match = re.search(r'"access_token"\s*:\s*"([^"]+)"', token)
    if match is None:
        raise LoginError("token response without access_token")
    return match.group(1)


def customer_login(store: str, account: str, cookie_jar: str) -> None:
    email, password = CUSTOMERS[account]
    store = store.rstrip("/")
    jar = CurlCookieJar(cookie_jar)
    jar.load()
    browser = Browser(jar)
    url, page = browser.request(f"{store}/auth/login?returnTo=/", stop_at=f"{store}/?")
    url, page = keycloak_login(browser, url, page, email, password, None, stop_at=None)
    if not url.startswith(store):
        raise LoginError(f"the store login did not return to the store (ended at {url.split('?')[0]})")
    if not any(cookie.name == "store_session" for cookie in jar):
        raise LoginError("the store did not set its session cookie (see the store logs: /auth/callback)")
    jar.save()


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    commands = parser.add_subparsers(dest="command", required=True)
    staff = commands.add_parser("staff", help="print an access token of the realm backoffice")
    staff.add_argument("--keycloak", required=True, help="http://localhost:<KEYCLOAK_PORT>")
    staff.add_argument("--admin", required=True, help="http://localhost:<ADMIN_PORT> (redirect_uri of client admin)")
    staff.add_argument("--account", default="admin", choices=sorted(STAFF))
    customer = commands.add_parser("customer", help="log in through the store into a curl cookie jar")
    customer.add_argument("--store", required=True, help="http://localhost:<STORE_PORT>")
    customer.add_argument("--cookie-jar", required=True, help="Netscape cookie file, read if present and written")
    customer.add_argument("--account", default="jan", choices=sorted(CUSTOMERS))
    args = parser.parse_args(argv)
    try:
        if args.command == "staff":
            print(staff_token(args.keycloak, args.admin, args.account))
        else:
            customer_login(args.store, args.account, args.cookie_jar)
    except LoginError as error:
        print(f"kc_login: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

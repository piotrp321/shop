#!/usr/bin/env python3
"""The refresh token of a panel session of the local stack (compose), for the writes scenario (load/writes.sh).

Logs in as a demo staff account of the realm backoffice (infra/keycloak/realms/backoffice.json: password and TOTP) the way
the panel does (the public client `admin`, code flow with PKCE) and prints the refresh token on the standard output, nothing
else. Only for the local stack: it talks to http://localhost:<KEYCLOAK_PORT of .env>; the server has no demo accounts (there
the token comes from a login to the panel in a browser, load/README.md).

    python3 load/admin-token.py            # the account admin
    STAFF_USER=warehouse python3 load/admin-token.py

Python of the host, standard library only. Exit code: 0 the token is printed, 2 with a message on the standard error when the
login fails (Keycloak not running, wrong password or TOTP, no code, no refresh token).
"""

import base64
import hashlib
import hmac
import html
import json
import os
import secrets
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

# The demo accounts of the realm (public demo data, as in e2e/support/accounts.ts).
PASSWORD = "Pracownik-Demo-1"
TOTP_SECRETS = {
    "admin": "demo-totp-admin-0001",
    "warehouse": "demo-totp-warehouse1",
    "support": "demo-totp-support-01",
    "marketing": "demo-totp-marketing1",
}
PERIOD = 30


def fail(message):
    print(f"admin-token: {message}", file=sys.stderr)
    sys.exit(2)


def ports():
    """KEYCLOAK_PORT and ADMIN_PORT from the environment, else from the .env of the worktree, else the defaults of compose."""
    values = {"KEYCLOAK_PORT": "8080", "ADMIN_PORT": "5173"}
    env_file = Path(__file__).resolve().parent.parent / ".env"
    if env_file.is_file():
        for line in env_file.read_text().splitlines():
            key, _, value = line.partition("=")
            if key.strip() in values and value.strip():
                values[key.strip()] = value.strip()
    for key in values:
        values[key] = os.environ.get(key, values[key])
    return values["KEYCLOAK_PORT"], values["ADMIN_PORT"]


def totp(secret, at):
    counter = struct.pack(">Q", int(at) // PERIOD)
    digest = hmac.new(secret.encode(), counter, hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    return f"{(struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7FFFFFFF) % 1_000_000:06d}"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Browser:
    """Cookies of one host and no automatic redirects: enough for the login pages of Keycloak."""

    def __init__(self):
        self.cookies = {}
        self.opener = urllib.request.build_opener(NoRedirect)

    def send(self, url, form=None):
        headers = {"Cookie": "; ".join(f"{k}={v}" for k, v in self.cookies.items())} if self.cookies else {}
        data = urllib.parse.urlencode(form).encode() if form is not None else None
        request = urllib.request.Request(url, data=data, headers=headers, method="POST" if form is not None else "GET")
        try:
            response = self.opener.open(request, timeout=30)
            status, response_headers, body = response.status, response.headers, response.read()
        except urllib.error.HTTPError as error:
            status, response_headers, body = error.code, error.headers, error.read()
        except OSError as error:
            fail(f"Keycloak does not answer at {url.split('?')[0]} ({error}); is the stack running (make up)?")
        for cookie in response_headers.get_all("Set-Cookie") or []:
            key, _, value = cookie.partition(";")[0].strip().partition("=")
            self.cookies[key] = value
        return status, response_headers, body.decode(errors="replace")


def form_action(page):
    for part in page.split('action="')[1:]:
        action = html.unescape(part.split('"', 1)[0])
        if "login-actions/authenticate" in action:
            return action
    return None


def main():
    user = os.environ.get("STAFF_USER", "admin")
    if user not in TOTP_SECRETS:
        fail(f"STAFF_USER must be one of {', '.join(TOTP_SECRETS)}, got '{user}'")
    keycloak_port, admin_port = ports()
    realm = f"http://localhost:{keycloak_port}/realms/backoffice/protocol/openid-connect"
    redirect_uri = f"http://localhost:{admin_port}/"
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
    query = urllib.parse.urlencode({
        "client_id": "admin", "response_type": "code", "scope": "openid", "redirect_uri": redirect_uri,
        "state": secrets.token_urlsafe(12), "code_challenge": challenge, "code_challenge_method": "S256",
    })
    browser = Browser()
    status, _, page = browser.send(f"{realm}/auth?{query}")
    action = form_action(page)
    if status != 200 or action is None:
        fail(f"Keycloak shows no login form (status {status})")
    status, headers, page = browser.send(action, {"username": user, "password": PASSWORD, "credentialId": ""})
    location = headers.get("Location", "")
    for attempt in range(2):
        action = form_action(page)
        if status != 200 or action is None or 'name="otp"' not in page:
            break
        status, headers, page = browser.send(action, {"otp": totp(TOTP_SECRETS[user], time.time())})
        location = headers.get("Location", "")
        if status == 302 or attempt == 1:
            break
        # A code is accepted once: a second login in the same window waits for the next one.
        time.sleep(PERIOD - time.time() % PERIOD + 1)
    code = urllib.parse.parse_qs(urllib.parse.urlparse(location).query).get("code", [None])[0]
    if status != 302 or not location.startswith(redirect_uri) or code is None:
        fail(f"the login of '{user}' did not end with a code (status {status}, password or TOTP refused?)")
    request = urllib.request.Request(f"{realm}/token", data=urllib.parse.urlencode({
        "grant_type": "authorization_code", "client_id": "admin", "code": code, "redirect_uri": redirect_uri,
        "code_verifier": verifier,
    }).encode(), method="POST")
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            tokens = json.load(response)
    except urllib.error.HTTPError as error:
        fail(f"the token endpoint refused the code ({error.code} {error.read()[:200]!r})")
    except OSError as error:
        fail(f"the token endpoint does not answer ({error})")
    if not tokens.get("refresh_token"):
        fail("the token endpoint gave no refresh token")
    print(tokens["refresh_token"])


if __name__ == "__main__":
    main()

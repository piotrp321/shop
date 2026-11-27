#!/usr/bin/env python3
"""Access token of an administrator of the panel for the k6 scenarios of the panel (performance program, PERF-7).

Logs in the way the panel does and the E2E tests do (support/staff-login.ts): the authorization code flow with PKCE of the
public client `admin` in the realm `backoffice`, with the password and the TOTP code of the account, but without a browser:
the login form is read and submitted over HTTP. Runs on the host (Python 3 standard library only) and prints one JSON object
on the standard output: the access token, the refresh token, the lifetime and the token endpoint, so that k6 can renew the
token (the access token lives 5 minutes, a run of the panel lasts longer).

  panel_token.py [--keycloak URL] [--admin-url URL] [--user NAME] [--password TEXT] [--totp-secret TEXT]

The defaults are the demo account `admin` of the stack (README, "Konta demo": public data). Another environment sets
PANEL_KEYCLOAK_URL (the public address of Keycloak, the issuer of the tokens), PANEL_ADMIN_URL (the address of the panel,
the redirect of the client), PANEL_USER, PANEL_PASSWORD and PANEL_TOTP_SECRET (the raw secret of the TOTP).

Exit codes: 0 the token is printed; 2 no token (wrong arguments, Keycloak unreachable, the login refused), the reason on the
standard error. The password and the tokens are never written to the standard error.
"""
import argparse
import base64
import hashlib
import hmac
import html
import json
import os
import re
import secrets
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

REALM = "backoffice"
CLIENT = "admin"
PERIOD = 30


def fail(message):
    print(f"panel_token: {message}", file=sys.stderr)
    sys.exit(2)


def totp(secret, now=None):
    """RFC 6238 (SHA-1, 6 digits, 30 s) for the raw UTF-8 secret, the same as e2e/support/totp.ts."""
    moment = time.time() if now is None else now
    counter = struct.pack(">Q", int(moment // PERIOD))
    digest = hmac.new(secret.encode(), counter, hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    code = (struct.unpack(">I", digest[offset : offset + 4])[0] & 0x7FFFFFFF) % 1_000_000
    return f"{code:06d}"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def form_action(page):
    match = re.search(r'<form[^>]*\sid="kc-form-login"[^>]*\saction="([^"]+)"', page) or re.search(r'<form[^>]*\saction="([^"]+)"', page)
    if match is None:
        return None
    return html.unescape(match.group(1))


def login(keycloak, admin_url, user, password, secret):
    # The cookies of Keycloak are `Secure` even over plain HTTP (a browser takes localhost for a secure origin, the cookie jar
    # of urllib does not), so they are kept and sent back by hand.
    cookies = {}
    opener = urllib.request.build_opener(NoRedirect)
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
    redirect = f"{admin_url}/auth/callback"
    base = f"{keycloak}/realms/{REALM}/protocol/openid-connect"
    query = urllib.parse.urlencode(
        {
            "client_id": CLIENT,
            "redirect_uri": redirect,
            "response_type": "code",
            "scope": "openid",
            "state": secrets.token_hex(8),
            "code_challenge": challenge,
            "code_challenge_method": "S256",
        }
    )

    def keep(headers):
        for line in headers.get_all("Set-Cookie") or []:
            name, _, rest = line.partition("=")
            cookies[name.strip()] = rest.split(";", 1)[0]

    def call(url, data=None):
        body = None if data is None else urllib.parse.urlencode(data).encode()
        request = urllib.request.Request(url, data=body)
        if cookies:
            request.add_header("Cookie", "; ".join(f"{name}={value}" for name, value in cookies.items()))
        try:
            with opener.open(request, timeout=30) as response:
                keep(response.headers)
                return response.status, response.headers, response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            keep(error.headers)
            return error.code, error.headers, error.read().decode("utf-8", "replace")
        except (urllib.error.URLError, TimeoutError) as error:
            fail(f"Keycloak at {keycloak} is unreachable: {error}")

    status, _, page = call(f"{base}/auth?{query}")
    action = form_action(page) if status == 200 else None
    if action is None:
        fail(f"the login page of the realm {REALM} was not served (status {status})")
    status, headers, page = call(action, {"username": user, "password": password, "credentialId": ""})
    for attempt in range(2):
        if status in (301, 302, 303):
            break
        action = form_action(page) if status == 200 else None
        if action is None or 'name="otp"' not in page:
            fail(f"the login of {user} was refused (status {status}); a wrong user or password, or the account is locked")
        # A code is accepted once: a second run in the same window waits for the next one.
        if attempt == 1:
            time.sleep(PERIOD - (time.time() % PERIOD) + 1)
        elif PERIOD - (time.time() % PERIOD) < 3:
            time.sleep(PERIOD - (time.time() % PERIOD) + 1)
        status, headers, page = call(action, {"otp": totp(secret), "login": "Sign In"})
    if status not in (301, 302, 303):
        fail(f"the one-time code of {user} was refused (status {status})")
    location = headers.get("Location", "")
    code = urllib.parse.parse_qs(urllib.parse.urlparse(location).query).get("code", [""])[0]
    if code == "":
        fail("Keycloak redirected without an authorization code")
    status, _, text = call(f"{base}/token", {"grant_type": "authorization_code", "client_id": CLIENT, "code": code, "redirect_uri": redirect, "code_verifier": verifier})
    if status != 200:
        fail(f"the token endpoint answered {status}")
    token = json.loads(text)
    return {
        "access_token": token["access_token"],
        "refresh_token": token["refresh_token"],
        "expires_in": token["expires_in"],
        "token_url": f"{base}/token",
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--keycloak", default=os.environ.get("PANEL_KEYCLOAK_URL", "http://localhost:8080"))
    parser.add_argument("--admin-url", default=os.environ.get("PANEL_ADMIN_URL", "http://localhost:5173"))
    parser.add_argument("--user", default=os.environ.get("PANEL_USER", "admin"))
    parser.add_argument("--password", default=os.environ.get("PANEL_PASSWORD", "Pracownik-Demo-1"))
    parser.add_argument("--totp-secret", default=os.environ.get("PANEL_TOTP_SECRET", "demo-totp-admin-0001"))
    args = parser.parse_args()
    result = login(args.keycloak.rstrip("/"), args.admin_url.rstrip("/"), args.user, args.password, args.totp_secret)
    json.dump(result, sys.stdout)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()

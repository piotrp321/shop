#!/bin/sh
# Local stack only: a test staff account without OTP and a password grant on the admin client of the local backoffice realm,
# so that the measurement can call /api/v1/admin. Writes the access token to $1. Nothing is printed.
set -eu
cd "$(dirname "$0")/../.."
ROOT="${SHOP_ROOT:?set SHOP_ROOT to the root of the repository checkout}"
KC=http://localhost:52703
PASS="$(grep '^KC_BOOTSTRAP_ADMIN_PASSWORD=' "$ROOT/.env.local" | cut -d= -f2-)"
ADMIN_USER="${KC_ADMIN_USER:-admin}"
TOK="$(curl -fsS -d grant_type=password -d client_id=admin-cli -d "username=$ADMIN_USER" --data-urlencode "password=$PASS" "$KC/realms/master/protocol/openid-connect/token" | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')"
H="Authorization: Bearer $TOK"
J="Content-Type: application/json"
TESTPASS="$(grep '^STORE_SESSION_SECRET=' "$ROOT/.env.local" | cut -d= -f2- | cut -c1-24)"
# Realm: longer access tokens for the runs. Client admin: direct grants on.
curl -fsS -X PUT -H "$H" -H "$J" -d '{"accessTokenLifespan":7200}' "$KC/admin/realms/backoffice" >/dev/null
CID="$(curl -fsS -H "$H" "$KC/admin/realms/backoffice/clients?clientId=admin" | python3 -c 'import sys,json;print(json.load(sys.stdin)[0]["id"])')"
curl -fsS -X PUT -H "$H" -H "$J" -d '{"clientId":"admin","directAccessGrantsEnabled":true}' "$KC/admin/realms/backoffice/clients/$CID" >/dev/null
UID_="$(curl -fsS -H "$H" "$KC/admin/realms/backoffice/users?username=perf-audit&exact=true" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d[0]["id"] if d else "")')"
if [ -z "$UID_" ]; then
  curl -fsS -X POST -H "$H" -H "$J" -d "{\"username\":\"perf-audit\",\"email\":\"perf-audit@example.com\",\"firstName\":\"Perf\",\"lastName\":\"Audit\",\"enabled\":true,\"emailVerified\":true,\"credentials\":[{\"type\":\"password\",\"value\":\"$TESTPASS\",\"temporary\":false}]}" "$KC/admin/realms/backoffice/users" >/dev/null
  UID_="$(curl -fsS -H "$H" "$KC/admin/realms/backoffice/users?username=perf-audit&exact=true" | python3 -c 'import sys,json;print(json.load(sys.stdin)[0]["id"])')"
  ROLE="$(curl -fsS -H "$H" "$KC/admin/realms/backoffice/roles/admin")"
  curl -fsS -X POST -H "$H" -H "$J" -d "[$ROLE]" "$KC/admin/realms/backoffice/users/$UID_/role-mappings/realm" >/dev/null
fi
curl -fsS -X PUT -H "$H" -H "$J" -d '{"requiredActions":[]}' "$KC/admin/realms/backoffice/users/$UID_" >/dev/null
curl -sS -d grant_type=password -d client_id=admin -d username=perf-audit --data-urlencode "password=$TESTPASS" -d scope=openid "$KC/realms/backoffice/protocol/openid-connect/token" > /tmp/.kc-perf.$$ || true
python3 - "$1" /tmp/.kc-perf.$$ <<'PY'
import sys, json
d = json.load(open(sys.argv[2]))
if "access_token" not in d:
    print("token error:", {k: v for k, v in d.items() if k.startswith("error")}); sys.exit(1)
open(sys.argv[1], "w").write(d["access_token"])
print("token ok, expires_in", d["expires_in"])
PY
rm -f /tmp/.kc-perf.$$

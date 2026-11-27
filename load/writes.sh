#!/bin/sh
# The writes scenario (load/writes.js, load/README.md "Scenariusz zapisów"): price changes and stock receipts through the API of the
# panel, the freshness of the index and the consistency check, with k6 of load/k6.sh in a container.
#
#   ADMIN_REFRESH_TOKEN=<token> WRITE_RATE=<writes per second> sh load/writes.sh
#
# The refresh token of a panel session of an administrator is read from the environment and passed to the container by name, never
# on a command line. Without API_URL the run is against the stack of compose of this checkout (`make up`, `make seed`): k6 on its
# network, http://api (the API itself, not Varnish) and http://keycloak:8080. With API_URL (and KEYCLOAK_URL) it is against those
# addresses from the default network of Docker (WRITES_NETWORK to change it); against the server only with the consent of the owner
# for that run. The other settings (WRITE_*, FRESHNESS_*, CHECK_*, API_HOST, KEYCLOAK_HOST: the header of load/writes.js) are
# passed on when set. WRITES_OUT, an existing directory, gets the result writes.json (also of an interrupted run).
#
# Exit code: that of k6 (0 a measurement at the rate whose consistency check passed; 99 no such measurement: a write dropped from
# the rate, unanswered or with a 5xx, a freshness timeout, a refused write, a mismatch, an error of the run or a check that did not
# finish, the reasons in the line `verdict:`; 107 the run could not start: token, API, Keycloak, empty sample, settings; 105
# interrupted), or 2 when the environment is not fit (no Docker, no token, the stack not running, WRITES_OUT not a directory).
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=k6.sh
. "$here/k6.sh"

fail() { echo "writes: $1" >&2; exit 2; }

command -v docker > /dev/null 2>&1 || fail "docker is not installed"
[ -n "${ADMIN_REFRESH_TOKEN:-}" ] || fail "ADMIN_REFRESH_TOKEN is not set: the refresh token of a panel session of an administrator (load/README.md, \"Token\")"
out=""
if [ -n "${WRITES_OUT:-}" ]; then
  [ -d "$WRITES_OUT" ] || fail "WRITES_OUT is not a directory: $WRITES_OUT"
  out="$(cd "$WRITES_OUT" && pwd)"
fi

if [ -z "${API_URL:-}" ]; then
  api_container="$(docker compose ps -q api 2> /dev/null | head -n 1 || true)"
  [ -n "$api_container" ] || fail "the API of the stack is not running (make up and make seed first), and API_URL is not set"
  network="$(docker inspect --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{"\n"}}{{end}}' "$api_container" | head -n 1)"
  [ -n "$network" ] || fail "cannot find the network of the API container"
  API_URL=http://api
  KEYCLOAK_URL=http://keycloak:8080
else
  [ -n "${KEYCLOAK_URL:-}" ] || fail "KEYCLOAK_URL is required with API_URL"
  network="${WRITES_NETWORK:-bridge}"
fi

settings=""
for name in WRITE_RATE WRITE_SECONDS WRITE_SAMPLE WRITE_UNSTOCKED_SHARE WRITE_PRICE_PROBES WRITE_PRICE_SHARE WRITE_MAX_IN_FLIGHT \
  WRITE_DISCOVER_PRODUCTS WRITE_TIMEOUT_SECONDS WRITE_SEND_TOLERANCE_MS FRESHNESS_POLL_MS FRESHNESS_TIMEOUT_SECONDS \
  CHECK_TIMEOUT_SECONDS CHECK_POLL_SECONDS API_HOST KEYCLOAK_HOST; do
  value="$(printenv "$name" || true)"
  [ -z "$value" ] || settings="$settings -e $name=$value"
done
[ -z "$out" ] || settings="$settings -e WRITES_RESULT=/out/writes.json"

echo "writes: k6 on the network $network, API $API_URL, Keycloak $KEYCLOAK_URL${out:+, result in $out/writes.json}"
export ADMIN_REFRESH_TOKEN
# shellcheck disable=SC2086 # the settings are separate words (their values have no spaces)
k6_run "$network" "$out" writes.js -e "API_URL=$API_URL" -e "KEYCLOAK_URL=$KEYCLOAK_URL" -e ADMIN_REFRESH_TOKEN $settings

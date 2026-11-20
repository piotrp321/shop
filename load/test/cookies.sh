#!/bin/sh
# make load-cookies: the cookies of the k6 client (load/lib/http.js) against a server that sets a Secure cookie, in Docker, on a
# network of its own (no stack needed; k6 is the image of load/k6.sh, the server is python:3.13-alpine running cookie-server.py).
#
# The store behind a TLS-terminating ingress (the cluster) sets Secure cookies and k6 calls it over plain HTTP (http://store:3000), where
# k6 would never send them back, so the cart and the checkout would fail. Two cases:
#   - STORE_URL http:, STORE_ORIGIN https: (the store behind TLS, called inside the cluster): the Secure cookie comes back as well;
#   - STORE_ORIGIN http: (compose, the default): k6 sends back only the cookie without Secure, as it always did.
# Exit code: 0 when both cases pass, 99 when k6 failed a check, 2 when the test could not run.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# k6.sh takes the directory of the scripts from its caller: that is load/, not load/test/.
LOAD_DIR="$(cd "$here/.." && pwd)"
# shellcheck source=../k6.sh
. "$here/../k6.sh"

fail() { echo "load-cookies: $1" >&2; exit 2; }

command -v docker > /dev/null 2>&1 || fail "docker is not installed"
network="load-cookies-$$"
server="load-cookies-server-$$"
docker network create "$network" > /dev/null || fail "cannot create a docker network"
cleanup() {
  docker rm -f "$server" > /dev/null 2>&1 || true
  docker network rm "$network" > /dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d --name "$server" --network "$network" -v "$here/cookie-server.py:/cookie-server.py:ro" python:3.13-alpine \
  python /cookie-server.py > /dev/null || fail "cannot start the server"
tries=0
until docker run --rm --network "$network" python:3.13-alpine \
  python -c "import urllib.request; urllib.request.urlopen('http://$server:3000/read')" > /dev/null 2>&1; do
  tries=$((tries + 1))
  [ "$tries" -lt 30 ] || fail "the server does not answer"
  sleep 1
done

failed=0
echo "load-cookies: the store behind TLS (STORE_ORIGIN https), called over plain HTTP"
status=0
k6_run "$network" "" test/cookies.js -e "STORE_URL=http://$server:3000" -e API_URL=http://unused -e STORE_ORIGIN=https://shop.example \
  -e EXPECT_SECURE_SENT=true || status=$?
[ "$status" -eq 0 ] || { echo "load-cookies: the Secure cookie did not come back (exit $status)" >&2; failed=$status; }
echo "load-cookies: the store over plain HTTP (STORE_ORIGIN http, the default)"
status=0
k6_run "$network" "" test/cookies.js -e "STORE_URL=http://$server:3000" -e API_URL=http://unused -e EXPECT_SECURE_SENT=false || status=$?
[ "$status" -eq 0 ] || { echo "load-cookies: the Secure cookie came back over plain HTTP (exit $status)" >&2; failed=$status; }
[ "$failed" -eq 0 ] || exit "$failed"
echo "load-cookies: ok"

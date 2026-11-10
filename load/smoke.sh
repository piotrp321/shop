#!/bin/sh
# make load-smoke: the k6 scripts, one user and one iteration of every scenario (including a checkout of one unit) against the
# running stack of docker compose (make up and make seed first). Runs k6 in a container on the network of the stack and
# reaches the services by their names there (http://store:3000, http://api); the Origin of the writes is the public
# address of the store, as the store itself has it (STORE_URL). Without thresholds of speed; any failed scenario is a non-zero
# exit code (99: a check or a threshold of the run failed; 2: the stack is not running).
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=k6.sh
. "$here/k6.sh"

fail() { echo "load-smoke: $1" >&2; exit 2; }

command -v docker > /dev/null 2>&1 || fail "docker is not installed"
store_container="$(docker compose ps -q store 2> /dev/null | head -n 1 || true)"
[ -n "$store_container" ] || fail "the store of the stack is not running (make up and make seed first)"
network="$(docker inspect --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{"\n"}}{{end}}' "$store_container" | head -n 1)"
[ -n "$network" ] || fail "cannot find the network of the store container"
origin="$(docker compose exec -T store printenv STORE_URL | tr -d '\r\n')"
[ -n "$origin" ] || fail "the store container has no STORE_URL"

echo "load-smoke: k6 on the network $network, store http://store:3000 (public address $origin), api http://api"
k6_run "$network" "" smoke.js -e STORE_URL=http://store:3000 -e API_URL=http://api -e "STORE_ORIGIN=$origin"

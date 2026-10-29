#!/usr/bin/env bash
# Forwards the public ports of the stack (localhost:<port>) to the services, then runs Playwright.
# Public ports come from the same variables as in Compose (defaults match the Compose defaults).
set -euo pipefail

# name  public port  service host  internal port
SERVICES=(
  "store ${STORE_PORT:-3000} store 3000"
  "admin ${ADMIN_PORT:-5173} admin 5173"
  "api ${API_PORT:-8000} api 80"
  "keycloak ${KEYCLOAK_PORT:-8080} keycloak 8080"
  "imgproxy ${IMGPROXY_PORT:-8081} imgproxy 8080"
)

can_connect() {
  timeout 3 bash -c "exec 3<>/dev/tcp/$1/$2" 2>/dev/null
}

# Fail fast with a readable message instead of letting every test time out.
for entry in "${SERVICES[@]}"; do
  read -r name _ host port <<<"$entry"
  if ! can_connect "$host" "$port"; then
    echo "e2e: $name is not reachable at $host:$port. Start the stack first: make up && make seed" >&2
    exit 1
  fi
done

for entry in "${SERVICES[@]}"; do
  read -r _ public host port <<<"$entry"
  # Per-connection noise (browsers closing sockets) goes to a log file instead of the test output.
  socat -lf "/tmp/socat-$public.log" "TCP-LISTEN:$public,fork,reuseaddr" "TCP:$host:$port" &
done

for entry in "${SERVICES[@]}"; do
  read -r name public _ _ <<<"$entry"
  for _attempt in $(seq 1 50); do
    can_connect 127.0.0.1 "$public" && continue 2
    sleep 0.1
  done
  echo "e2e: the forwarder for $name did not start listening on port $public" >&2
  exit 1
done

exec npx playwright test "$@"

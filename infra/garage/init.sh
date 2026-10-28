#!/bin/sh
# Idempotent Garage bootstrap: node layout, S3 key import, buckets and permissions.
# Secrets come from the environment (.env.local).
set -eu

ADMIN_URL="http://garage:3903"
BUCKETS="product-images product-images-test"

# Wait for the admin API and read the node id.
node_id=""
for _ in $(seq 1 60); do
  node_id="$(wget -qO- --header "Authorization: Bearer ${GARAGE_ADMIN_TOKEN}" "${ADMIN_URL}/v2/GetClusterStatus" 2>/dev/null \
    | sed -n 's/.*"id": *"\([0-9a-f]\{64\}\)".*/\1/p' | head -n 1 || true)"
  [ -n "$node_id" ] && break
  sleep 1
done
[ -n "$node_id" ] || { echo "Garage admin API did not return a node id" >&2; exit 1; }

g() {
  garage -h "${node_id}@garage:3901" "$@"
}

# Layout: assign the single node once.
if g status | grep -q "NO ROLE ASSIGNED"; then
  version="$(g layout show | sed -n 's/.*[Cc]urrent cluster layout version: *\([0-9]\+\).*/\1/p' | head -n 1)"
  g layout assign -z dc1 -c 1G "$node_id"
  g layout apply --version "$(( ${version:-0} + 1 ))"
fi

# S3 key shared with imgproxy and the API.
if ! g key info "$GARAGE_ACCESS_KEY_ID" >/dev/null 2>&1; then
  g key import --yes -n shop "$GARAGE_ACCESS_KEY_ID" "$GARAGE_SECRET_ACCESS_KEY"
fi

for bucket in $BUCKETS; do
  if ! g bucket info "$bucket" >/dev/null 2>&1; then
    g bucket create "$bucket"
  fi
  g bucket allow --read --write --owner "$bucket" --key "$GARAGE_ACCESS_KEY_ID"
done

echo "Garage ready"

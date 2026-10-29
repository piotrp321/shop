#!/bin/sh
# Creates .env.local with generated local secrets. Never overwrites an existing file: an existing file must
# contain every key, otherwise the script fails and says how to recreate the stack.
set -eu

dir="$(cd "$(dirname "$0")/../.." && pwd)"
target="$dir/.env.local"

# KEY PREFIX BYTES: the value is PREFIX (- for none) followed by BYTES random bytes in hex.
secrets='POSTGRES_PASSWORD - 16
KEYCLOAK_DB_PASSWORD - 16
SHOP_DB_PASSWORD - 16
RABBITMQ_DEFAULT_PASS - 16
KC_BOOTSTRAP_ADMIN_PASSWORD - 16
GARAGE_RPC_SECRET - 32
GARAGE_ADMIN_TOKEN - 32
GARAGE_ACCESS_KEY_ID GK 12
GARAGE_SECRET_ACCESS_KEY - 32
IMGPROXY_KEY - 32
IMGPROXY_SALT - 32
STORE_CLIENT_SECRET - 24
STORE_SESSION_SECRET - 32'

if [ -f "$target" ]; then
  missing=""
  while read -r key _prefix _bytes; do
    grep -q "^$key=" "$target" || missing="$missing $key"
  done <<EOF
$secrets
EOF
  if [ -n "$missing" ]; then
    echo "$target is missing:$missing" >&2
    echo "A new secret usually needs fresh volumes too (e.g. the shop role in Postgres). Recreate the stack:" >&2
    echo "remove .env.local, remove this Compose project's volumes (docker compose down -v), then run make up." >&2
    exit 1
  fi
  exit 0
fi

command -v openssl >/dev/null 2>&1 || { echo "openssl is required to generate .env.local" >&2; exit 1; }

umask 077
tmp="$(mktemp "$dir/.env.local.XXXXXX")"
trap 'rm -f "$tmp"' EXIT

# Appends KEY=<prefix><random hex> to the temp file. The loop below reads a heredoc, so it runs in this shell
# and a failing openssl aborts the script.
add() {
  value="$(openssl rand -hex "$3")" || exit 1
  [ "${#value}" -eq "$(( $3 * 2 ))" ] || { echo "openssl rand returned unexpected output" >&2; exit 1; }
  printf '%s=%s%s\n' "$1" "$2" "$value" >> "$tmp"
}

while read -r key prefix bytes; do
  [ "$prefix" = "-" ] && prefix=""
  add "$key" "$prefix" "$bytes"
done <<EOF
$secrets
EOF

mv "$tmp" "$target"
trap - EXIT
echo "Created $target"

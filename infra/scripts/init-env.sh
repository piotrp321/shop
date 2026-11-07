#!/bin/sh
# Creates .env.local with generated local secrets. Never overwrites an existing file: an existing file must
# contain every key, otherwise the script fails and says how to recreate the stack. The one exception are the keys
# of the `appendable` list, which no volume holds (nothing has to be recreated for them): the script appends the ones
# an older file lacks, with fresh values, and leaves every existing line as it is.
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
STORE_SESSION_SECRET - 32
VARNISH_PURGE_SECRET - 32
STORE_REVALIDATE_SECRET - 32'

# The keys that may be appended to an existing file: the API keys and the webhook secrets of the payment simulator.
appendable='PAYMENT_DEMO_TRANSFER_API_KEY - 24
PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET - 32
PAYMENT_DEMO_CARD_API_KEY - 24
PAYMENT_DEMO_CARD_WEBHOOK_SECRET - 32'

# Appends KEY=<prefix><random hex> to the file $4. The loops below read a heredoc, so they run in this shell
# and a failing openssl aborts the script.
add() {
  command -v openssl >/dev/null 2>&1 || { echo "openssl is required to generate .env.local" >&2; exit 1; }
  value="$(openssl rand -hex "$3")" || exit 1
  [ "${#value}" -eq "$(( $3 * 2 ))" ] || { echo "openssl rand returned unexpected output" >&2; exit 1; }
  printf '%s=%s%s\n' "$1" "$2" "$value" >> "$4"
}

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

  # Only appendable keys can be missing now. They are generated aside first, so a failing openssl leaves the file as it was.
  umask 077
  tmp="$(mktemp "$dir/.env.local.XXXXXX")"
  trap 'rm -f "$tmp"' EXIT
  while read -r key prefix bytes; do
    grep -q "^$key=" "$target" && continue
    [ "$prefix" = "-" ] && prefix=""
    add "$key" "$prefix" "$bytes" "$tmp"
  done <<EOF
$appendable
EOF
  if [ -s "$tmp" ]; then
    # A file without a newline at the end must not get the first key glued to its last line.
    [ -z "$(tail -c 1 "$target")" ] || echo >> "$target"
    cat "$tmp" >> "$target"
    echo "Appended to $target: $(cut -d= -f1 "$tmp" | tr '\n' ' ')"
  fi
  exit 0
fi

umask 077
tmp="$(mktemp "$dir/.env.local.XXXXXX")"
trap 'rm -f "$tmp"' EXIT

while read -r key prefix bytes; do
  [ "$prefix" = "-" ] && prefix=""
  add "$key" "$prefix" "$bytes" "$tmp"
done <<EOF
$secrets
$appendable
EOF

mv "$tmp" "$target"
trap - EXIT
echo "Created $target"

#!/bin/sh
# Creates .env.local with generated local secrets. Never overwrites an existing file.
set -eu

dir="$(cd "$(dirname "$0")/../.." && pwd)"
target="$dir/.env.local"

if [ -f "$target" ]; then
  exit 0
fi

command -v openssl >/dev/null 2>&1 || { echo "openssl is required to generate .env.local" >&2; exit 1; }

umask 077
tmp="$(mktemp "$dir/.env.local.XXXXXX")"
trap 'rm -f "$tmp"' EXIT

# Appends KEY=<random hex> to the temp file. Runs at top level so a failing openssl aborts the script.
add() {
  value="$(openssl rand -hex "$3")" || exit 1
  [ "${#value}" -eq "$(( $3 * 2 ))" ] || { echo "openssl rand returned unexpected output" >&2; exit 1; }
  printf '%s=%s%s\n' "$1" "$2" "$value" >> "$tmp"
}

add POSTGRES_PASSWORD "" 16
add KEYCLOAK_DB_PASSWORD "" 16
add RABBITMQ_DEFAULT_PASS "" 16
add KC_BOOTSTRAP_ADMIN_PASSWORD "" 16
add GARAGE_RPC_SECRET "" 32
add GARAGE_ADMIN_TOKEN "" 32
add GARAGE_ACCESS_KEY_ID GK 12
add GARAGE_SECRET_ACCESS_KEY "" 32
add IMGPROXY_KEY "" 32
add IMGPROXY_SALT "" 32
add STORE_CLIENT_SECRET "" 24
add STORE_SESSION_SECRET "" 32

mv "$tmp" "$target"
trap - EXIT
echo "Created $target"

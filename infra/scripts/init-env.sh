#!/bin/sh
# Creates .env.local with generated local secrets. Never overwrites an existing file.
set -eu

dir="$(cd "$(dirname "$0")/../.." && pwd)"
target="$dir/.env.local"

if [ -f "$target" ]; then
  exit 0
fi

command -v openssl >/dev/null 2>&1 || { echo "openssl is required to generate .env.local" >&2; exit 1; }

hex() {
  out="$(openssl rand -hex "$1")"
  [ -n "$out" ] || { echo "openssl rand failed" >&2; exit 1; }
  printf '%s' "$out"
}

umask 077
tmp="$(mktemp "$dir/.env.local.XXXXXX")"
trap 'rm -f "$tmp"' EXIT

{
  echo "POSTGRES_PASSWORD=$(hex 16)"
  echo "KEYCLOAK_DB_PASSWORD=$(hex 16)"
  echo "RABBITMQ_DEFAULT_PASS=$(hex 16)"
  echo "KC_BOOTSTRAP_ADMIN_PASSWORD=$(hex 16)"
  echo "GARAGE_RPC_SECRET=$(hex 32)"
  echo "GARAGE_ADMIN_TOKEN=$(hex 32)"
  echo "GARAGE_ACCESS_KEY_ID=GK$(hex 12)"
  echo "GARAGE_SECRET_ACCESS_KEY=$(hex 32)"
  echo "IMGPROXY_KEY=$(hex 32)"
  echo "IMGPROXY_SALT=$(hex 32)"
  echo "STORE_CLIENT_SECRET=$(hex 24)"
  echo "STORE_SESSION_SECRET=$(hex 32)"
} > "$tmp"

mv "$tmp" "$target"
trap - EXIT
echo "Created $target"

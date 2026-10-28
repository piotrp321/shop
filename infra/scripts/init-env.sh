#!/bin/sh
# Creates .env.local with generated local secrets. Never overwrites an existing file.
set -eu

target="$(dirname "$0")/../../.env.local"

if [ -f "$target" ]; then
  exit 0
fi

hex() {
  openssl rand -hex "$1"
}

umask 077
cat > "$target" <<ENV
POSTGRES_PASSWORD=$(hex 16)
KEYCLOAK_DB_PASSWORD=$(hex 16)
RABBITMQ_DEFAULT_PASS=$(hex 16)
KC_BOOTSTRAP_ADMIN_PASSWORD=$(hex 16)
GARAGE_RPC_SECRET=$(hex 32)
GARAGE_ADMIN_TOKEN=$(hex 32)
GARAGE_ACCESS_KEY_ID=GK$(hex 12)
GARAGE_SECRET_ACCESS_KEY=$(hex 32)
IMGPROXY_KEY=$(hex 32)
IMGPROXY_SALT=$(hex 32)
STORE_CLIENT_SECRET=$(hex 24)
STORE_SESSION_SECRET=$(hex 32)
ENV
echo "Created $target"

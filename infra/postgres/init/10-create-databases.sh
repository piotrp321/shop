#!/bin/sh
# Runs once on first start of an empty data directory (docker-entrypoint-initdb.d).
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<SQL
CREATE ROLE shop LOGIN PASSWORD '$SHOP_DB_PASSWORD';
CREATE DATABASE shop OWNER shop;
CREATE DATABASE shop_test OWNER shop;
CREATE ROLE keycloak LOGIN PASSWORD '$KEYCLOAK_DB_PASSWORD';
CREATE DATABASE keycloak OWNER keycloak;
SQL

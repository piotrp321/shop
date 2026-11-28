#!/bin/sh
# The statistics and the metrics role of Postgres (docs/features/runtime-config/spec.md, "Postgres" and D7). Idempotent, so it runs
# in two places:
#   - on the first start of an empty data directory (docker-entrypoint-initdb.d, after 10-create-databases.sh);
#   - in an existing database, where the init directory does not run again: `make up` of compose (docker compose exec) and the
#     initContainer postgres-setup of the Job migrate in the chart (the same script from the ConfigMap postgres-init, with
#     PGHOST, PGUSER and PGPASSWORD of the superuser).
# It creates the extension pg_stat_statements in the database postgres (the preload library of postgresql.conf fills it) and the role
# `exporter` of the metrics exporter, which has only pg_monitor (the statistics and the settings, no table of the shop), with the
# password EXPORTER_DB_PASSWORD, set again on every run. psql reads the connection from PGHOST, PGUSER and PGPASSWORD when they are set.
set -eu

: "${EXPORTER_DB_PASSWORD:?EXPORTER_DB_PASSWORD is not set}"

psql -X -v ON_ERROR_STOP=1 --username "${POSTGRES_USER:-${PGUSER:-postgres}}" --dbname postgres <<'SQL'
\getenv exporter_password EXPORTER_DB_PASSWORD
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
SELECT 'CREATE ROLE exporter LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'exporter') \gexec
ALTER ROLE exporter LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD :'exporter_password';
GRANT pg_monitor TO exporter;
SQL

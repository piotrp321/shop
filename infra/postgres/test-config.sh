#!/bin/sh
# Tests of the configuration of the compose service postgres (docs/features/runtime-config/spec.md, "Postgres" and "Dane w logach
# i metrykach"), on the running service; `make postgres-config-test`. Needs the stack service `postgres` (docker compose up -d --wait
# postgres) and the Docker image of it. It works in its own database (pg_config_test), which it drops at the end, and changes nothing else.
#
#   1. SHOW of every setting of infra/postgres/postgresql.conf and of the two -c arguments of the compose file gives the value of the file.
#   2. pg_stat_statements answers in the database postgres; the role `exporter` reads the statistics and no table.
#   3. A parallel hash join of more than 64 MB (the /dev/shm of Docker) runs, with a parallel plan, without `could not resize shared memory segment`.
#   4. The log of Postgres after a violation of a unique constraint, a deadlock, a wait for a lock, a cancelled query and the errors of data
#      has no line DETAIL, HINT, QUERY, CONTEXT or STATEMENT and no value or text of a query planted in them, but it has the main
#      message of each error (the test does not pass on an empty log). The main message of the errors of data (SQLSTATE class 22) carries
#      the value, so Alloy masks it (the log corpus of the masking test); POSTGRES_LOG_CORPUS_OUT=<file> writes those lines to a file.
#   5. A name of a setting that Postgres does not know stops the start of a throwaway container of the same image with the message
#      `unrecognized configuration parameter`.
#
# Exit code: 0 every check passed, 1 a check failed, 2 the service postgres does not run.
set -u

cd "$(dirname "$0")/../.." || exit 2
conf=infra/postgres/postgresql.conf
# The -c arguments of the service postgres of infra/docker-compose.yml (the test of the render compares them with the compose file).
memory_settings='shared_buffers=128MB effective_cache_size=512MB'
db=pg_config_test
failures=0
out="${TMPDIR:-/tmp}/postgres-config-test.$$"
mkdir -p "$out"
trap 'cleanup' EXIT

pg() { docker compose exec -T postgres "$@"; }
# psql as the superuser in the database $1 with the statements on the standard input or in -c.
psql_in() {
  database="$1"
  shift
  pg psql -X -q -At -v ON_ERROR_STOP=1 -U postgres -d "$database" "$@"
}
ok() { echo "ok: $1"; }
fail() { echo "FAIL: $1" >&2; failures=$((failures + 1)); }
check() { # check <description> <command...>: the command succeeds
  description="$1"
  shift
  if "$@" > "$out/last" 2>&1; then ok "$description"; else fail "$description"; sed 's/^/    /' "$out/last" >&2; fi
}
cleanup() {
  psql_in postgres -c "DROP DATABASE IF EXISTS $db WITH (FORCE)" > /dev/null 2>&1
  rm -rf "$out"
}

pg true > /dev/null 2>&1 || { echo "postgres-config-test: the compose service postgres does not run" >&2; trap - EXIT; rm -rf "$out"; exit 2; }
psql_in postgres -c "DROP DATABASE IF EXISTS $db WITH (FORCE)" -c "CREATE DATABASE $db" > /dev/null || { echo "cannot create the database $db" >&2; exit 1; }

# ---------------------------------------------------------------------------------------------------------------
echo "== 1. the settings"
expected="$(sed -e 's/#.*//' -e '/^[[:space:]]*$/d' -e "s/'//g" -e 's/[[:space:]]*=[[:space:]]*/=/' -e 's/[[:space:]]*$//' "$conf")"
[ -n "$expected" ] || fail "no settings read from $conf"
for pair in $expected $memory_settings; do
  setting="${pair%%=*}" want="${pair#*=}"
  have="$(psql_in postgres -c "SHOW $setting" 2>&1)"
  if [ "$have" = "$want" ]; then ok "SHOW $setting = $want"; else fail "SHOW $setting is '$have', the file says '$want'"; fi
done
# What the file leaves to the defaults of Postgres stays the default.
for pair in 'jit=on' 'work_mem=4MB' 'random_page_cost=4' 'max_connections=100' 'log_min_duration_statement=-1' 'log_parameter_max_length_on_error=0'; do
  setting="${pair%%=*}" want="${pair#*=}"
  have="$(psql_in postgres -c "SHOW $setting" 2>&1)"
  if [ "$have" = "$want" ]; then ok "SHOW $setting = $want (the default)"; else fail "SHOW $setting is '$have', the default is '$want'"; fi
done

# ---------------------------------------------------------------------------------------------------------------
echo "== 2. pg_stat_statements and the role exporter"
check "SELECT count(*) FROM pg_stat_statements" psql_in postgres -c "SELECT count(*) FROM pg_stat_statements"
psql_in "$db" -c "CREATE TABLE secret_table (v text)" -c "INSERT INTO secret_table VALUES ('x')" > /dev/null
password="$(pg printenv EXPORTER_DB_PASSWORD)"
# Over the address of the container, like the other containers connect: the connections from localhost are trusted without a password
# in the image, so they would not check the password. as_exporter <password> <psql arguments>.
address="$(pg hostname -i | awk '{ print $1 }')"
as_exporter() {
  pw="$1"
  shift
  pg env PGPASSWORD="$pw" psql -X -q -At -h "$address" -U exporter "$@"
}
check "the role exporter logs in with its password and reads pg_stat_database" as_exporter "$password" -d postgres -c "SELECT count(*) FROM pg_stat_database"
if as_exporter "wrong-$password" -d postgres -c "SELECT 1" > "$out/last" 2>&1; then fail "the role exporter logs in with a wrong password"; else
  if grep -q "password authentication failed" "$out/last"; then ok "the role exporter does not log in with a wrong password"; else fail "a wrong password failed for another reason"; cat "$out/last" >&2; fi
fi
check "the role exporter reads pg_stat_statements" as_exporter "$password" -d postgres -c "SELECT count(*) FROM pg_stat_statements"
roles="$(psql_in postgres -c "SELECT string_agg(g.rolname, ',' ORDER BY g.rolname) FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid JOIN pg_roles r ON r.oid = m.member WHERE r.rolname = 'exporter'")"
if [ "$roles" = "pg_monitor" ]; then ok "the role exporter is a member of pg_monitor and nothing else"; else fail "the role exporter is a member of: $roles"; fi
flags="$(psql_in postgres -c "SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname = 'exporter'")"
if [ "$flags" = "f" ]; then ok "the role exporter has no privilege of a role"; else fail "the role exporter has a privilege (rolsuper, createdb, createrole, replication or bypassrls): $flags"; fi
if as_exporter "$password" -d "$db" -c "SELECT v FROM secret_table" > "$out/last" 2>&1; then fail "the role exporter reads a table"; else
  if grep -q "permission denied" "$out/last"; then ok "the role exporter cannot read a table (permission denied)"; else fail "reading a table failed for another reason"; cat "$out/last" >&2; fi
fi
# Running it again changes nothing and fails nowhere (an existing database).
check "20-monitoring.sh is idempotent" pg sh /docker-entrypoint-initdb.d/20-monitoring.sh

# ---------------------------------------------------------------------------------------------------------------
echo "== 3. /dev/shm: a parallel hash join of more than 64 MB"
psql_in "$db" > /dev/null <<'SQL'
CREATE TABLE big_a AS SELECT g AS id, md5(g::text) AS pad FROM generate_series(1, 1500000) g;
CREATE TABLE big_b AS SELECT g AS id, md5(g::text) AS pad FROM generate_series(1, 1500000) g;
ANALYZE big_a;
ANALYZE big_b;
SQL
join_settings="SET work_mem = '512MB'; SET max_parallel_workers_per_gather = 2; SET parallel_setup_cost = 0; SET parallel_tuple_cost = 0; SET min_parallel_table_scan_size = 0; SET enable_mergejoin = off; SET enable_nestloop = off; SET enable_hashagg = off;"
plan="$(psql_in "$db" -c "$join_settings EXPLAIN SELECT count(*) FROM big_a a JOIN big_b b ON a.pad = b.pad" 2>&1)"
case "$plan" in *"Parallel Hash"*) ok "the plan is a Parallel Hash Join" ;; *) fail "the plan is not a parallel hash join: $plan" ;; esac
if result="$(psql_in "$db" -c "$join_settings SELECT count(*) FROM big_a a JOIN big_b b ON a.pad = b.pad" 2>&1)"; then
  if [ "$(printf '%s\n' "$result" | tail -n 1)" = "1500000" ]; then ok "the join ran (1500000 rows)"; else fail "the join returned: $result"; fi
else
  fail "the parallel hash join failed: $result"
fi
shm="$(pg df -k /dev/shm | awk 'NR == 2 { print $2 }')"
if [ "$shm" -ge 1000000 ]; then ok "/dev/shm of the container has $((shm / 1024)) MB"; else fail "/dev/shm has only $((shm / 1024)) MB"; fi

# ---------------------------------------------------------------------------------------------------------------
echo "== 4. the log of Postgres"
# POSTGRES_TEST_CANARY names the planted value (the corpus of the masking test uses a fixed one).
canary="${POSTGRES_TEST_CANARY:-canary$$x}"
before="$(docker compose logs --no-color --no-log-prefix postgres 2>&1 | wc -l)"
psql_in "$db" > /dev/null <<'SQL'
CREATE TABLE customer (id int PRIMARY KEY, email text UNIQUE);
CREATE TABLE lockme (id int PRIMARY KEY, v text);
INSERT INTO lockme VALUES (1, 'a'), (2, 'b');
CREATE TYPE status AS ENUM ('new', 'paid');
SQL
# A violation of a unique constraint: DETAIL would carry the e-mail address, STATEMENT the query.
psql_in "$db" -c "INSERT INTO customer VALUES (1, 'jan.$canary@example.pl')" > /dev/null
psql_in "$db" -c "INSERT INTO customer VALUES (2, 'jan.$canary@example.pl')" > /dev/null 2>&1
# A deadlock: two transactions that update the rows in the opposite order (DETAIL has the queries of both).
{ printf "BEGIN;\nUPDATE lockme SET v = '%s' WHERE id = 1;\nSELECT pg_sleep(1.5);\nUPDATE lockme SET v = '%s' WHERE id = 2;\nCOMMIT;\n" "$canary-a" "$canary-a" | psql_in "$db" > /dev/null 2>&1; } &
sleep 0.5
{ printf "BEGIN;\nUPDATE lockme SET v = '%s' WHERE id = 2;\nSELECT pg_sleep(1.5);\nUPDATE lockme SET v = '%s' WHERE id = 1;\nCOMMIT;\n" "$canary-b" "$canary-b" | psql_in "$db" > /dev/null 2>&1; } &
wait
# A wait for a lock longer than deadlock_timeout (1 s), which log_lock_waits logs; both transactions finish.
{ printf "BEGIN;\nUPDATE lockme SET v = '%s' WHERE id = 1;\nSELECT pg_sleep(2.5);\nCOMMIT;\n" "$canary-c" | psql_in "$db" > /dev/null 2>&1; } &
sleep 0.7
psql_in "$db" -c "UPDATE lockme SET v = '$canary-d' WHERE id = 1" > /dev/null 2>&1
wait
# A cancelled query (what statement_timeout of the API does).
psql_in "$db" -c "SET statement_timeout = '300ms'; SELECT pg_sleep(5), '$canary-e'" > /dev/null 2>&1
# The errors of data of the class 22: the main message has the value.
for statement in "SELECT 'qty-$canary-1'::integer" "SELECT 'id-$canary-2'::uuid" "SELECT '{\"name\": $canary-3}'::json" \
  "SELECT 'state-$canary-4'::status" "SELECT '99999$canary-5'::smallint" "SELECT 'price-$canary-6'::numeric" "SELECT '2026-13-45'::date" \
  "SELECT '99999999999'::integer"; do
  psql_in "$db" -c "$statement" > /dev/null 2>&1
done
sleep 1
docker compose logs --no-color --no-log-prefix postgres 2>&1 | tail -n "+$((before + 1))" > "$out/log"

has() {
  if grep -q -- "$1" "$out/log"; then ok "the log has: $2"; else fail "the log has no: $2 (the test would pass on an empty log)"; fi
}
has 'ERROR:  duplicate key value violates unique constraint "customer_email_key"' "the violation of the unique constraint (main message)"
has 'ERROR:  deadlock detected' "the deadlock"
has 'LOG:  process [0-9]* still waiting for ShareLock on transaction' "the wait for a lock (log_lock_waits)"
has 'ERROR:  canceling statement due to statement timeout' "the cancelled query"
has 'ERROR:  invalid input syntax for type integer' "an error of data (a number)"
if grep -E -n '^(DETAIL|HINT|QUERY|CONTEXT|STATEMENT):|(^|[]] )(DETAIL|HINT|QUERY|CONTEXT|STATEMENT):' "$out/log" > "$out/found"; then
  fail "the log has lines DETAIL, HINT, QUERY, CONTEXT or STATEMENT"; head -n 5 "$out/found" >&2
else ok "no line DETAIL, HINT, QUERY, CONTEXT or STATEMENT"; fi
# The values and the texts of the queries of the scenarios that are not errors of data: nothing of them is in the log.
# (The e-mail address of the violation; the SQL of the deadlock, the wait and the cancelled query: UPDATE, SELECT pg_sleep.)
if grep -n -e "jan\.$canary" -e "$canary-[abcde]" -e 'UPDATE lockme' -e 'pg_sleep' -e 'INSERT INTO customer' "$out/log" > "$out/found"; then
  fail "the log has a value or the text of a query"; head -n 5 "$out/found" >&2
else ok "no value and no text of a query in the log of the violation, the deadlock, the wait and the cancelled query"; fi
# Only the errors of data keep the value of the input in the main message: the corpus of the masking test (docs/features/runtime-config/spec.md).
grep -E 'invalid input (syntax|value)|out of range' "$out/log" > "$out/class22"
if [ -s "$out/class22" ]; then ok "$(wc -l < "$out/class22" | tr -d ' ') lines of the errors of data (class 22) in the log"; else fail "no line of an error of data in the log"; fi
if [ -n "${POSTGRES_LOG_CORPUS_OUT:-}" ]; then
  cp "$out/class22" "$POSTGRES_LOG_CORPUS_OUT"
  echo "the lines of the errors of data are in $POSTGRES_LOG_CORPUS_OUT"
fi

# ---------------------------------------------------------------------------------------------------------------
echo "== 5. an unknown setting stops the start"
image="$(docker compose config --images postgres | head -n 1)"
bad="$out/bad.conf"
{ cat "$conf"; echo "no_such_setting = on"; } > "$bad"
if docker run --rm -e POSTGRES_PASSWORD=unused -v "$bad:/etc/postgresql/postgresql.conf:ro" "$image" -c config_file=/etc/postgresql/postgresql.conf > "$out/start" 2>&1; then
  fail "Postgres started with an unknown setting in the file"
else
  if grep -q 'unrecognized configuration parameter "no_such_setting"' "$out/start"; then
    ok "the start fails with: unrecognized configuration parameter \"no_such_setting\""
  else
    fail "the start failed without the message about the setting"; tail -n 5 "$out/start" >&2
  fi
fi

echo
if [ "$failures" -eq 0 ]; then echo "postgres-config-test: all checks passed"; else echo "postgres-config-test: $failures checks failed" >&2; exit 1; fi

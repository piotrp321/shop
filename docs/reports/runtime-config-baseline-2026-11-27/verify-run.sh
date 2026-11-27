#!/bin/sh
# One run of demo-data:verify alone (no other load on the stack) for the PERF-1 task 1 report, section 3. From the
# repository root, on the stack of perf1.override.yaml with the generated data of the profile:
#   sh docs/reports/runtime-config-baseline-2026-11-27/verify-run.sh <output directory> <cold|warm> [profile] [anchor]
# cold: Postgres is recreated with the configuration of the PERF1_PG_* variables of .env and the page cache of the Docker
#       VM is dropped first (the cache of the host operating system under the VM is not; see the report);
# warm: the run follows the previous one without a restart.
# It resets pg_stat_statements, pg_stat_database and pg_stat_io, runs the verifier without the accounts (Keycloak is not
# measured), and writes: time.txt (wall time and exit code), statements.tsv (every statement of the run), database.tsv,
# io.tsv, settings.tsv, memory.txt (memory.stat of Postgres after the run) and, when PERF1_AUTO_EXPLAIN_MS is set, the
# plans of the slow statements in postgres.log. Exit code: that of the verifier.
set -eu
out="$1"
mode="$2"
profile="${3:-medium}"
anchor="${4:-2026-11-27}"
mkdir -p "$out"
psql() { docker compose exec -T postgres psql -U postgres -d shop -X -A -F "$(printf '\t')" "$@"; }

if [ "$mode" = cold ]; then
  docker compose up -d --wait --no-deps --force-recreate postgres >/dev/null
  docker run --rm --privileged --pid=host alpine:3 sh -c 'sync; echo 3 > /proc/sys/vm/drop_caches'
fi
psql -qc "SELECT pg_stat_statements_reset(); SELECT pg_stat_reset(); SELECT pg_stat_reset_shared('io');" >/dev/null
since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
start="$(date +%s)"
code=0
docker compose exec -T api bin/console demo-data:verify --demo-profile="$profile" --anchor="$anchor" --no-accounts > "$out/verify.log" 2>&1 || code=$?
end="$(date +%s)"
printf 'mode=%s profile=%s seconds=%s exit=%s since=%s\n' "$mode" "$profile" "$((end - start))" "$code" "$since" > "$out/time.txt"

psql -c "SELECT queryid, calls, round(total_exec_time) AS total_ms, round(max_exec_time) AS max_ms, shared_blks_hit, shared_blks_read, temp_blks_written, round(shared_blk_read_time) AS read_ms, round(jit_generation_time + jit_inlining_time + jit_optimization_time + jit_emission_time) AS jit_ms, rows, regexp_replace(query, '\s+', ' ', 'g') AS query FROM pg_stat_statements WHERE dbid = (SELECT oid FROM pg_database WHERE datname = 'shop') ORDER BY total_exec_time DESC" > "$out/statements.tsv"
psql -c "SELECT datname, blks_read, blks_hit, round(100.0 * blks_hit / nullif(blks_hit + blks_read, 0), 2) AS hit_pct, temp_files, temp_bytes, round(blk_read_time) AS read_ms FROM pg_stat_database WHERE datname = 'shop'" > "$out/database.tsv"
psql -c "SELECT backend_type, object, context, reads, read_bytes, round(read_time) AS read_ms, writes, hits, evictions FROM pg_stat_io WHERE reads > 0 OR hits > 0" > "$out/io.tsv"
psql -c "SELECT name, setting, unit FROM pg_settings WHERE name IN ('shared_buffers', 'effective_cache_size', 'work_mem', 'jit', 'max_parallel_workers_per_gather', 'random_page_cost', 'auto_explain.log_min_duration')" > "$out/settings.tsv"
docker exec "$(docker compose ps -q postgres)" cat /sys/fs/cgroup/memory.stat /sys/fs/cgroup/memory.max /sys/fs/cgroup/memory.peak > "$out/memory.txt" 2>/dev/null || true
if [ "$(psql -Atc "SHOW auto_explain.log_min_duration")" != "-1" ]; then
  docker compose logs --no-log-prefix --since "$since" postgres > "$out/postgres.log" 2>&1 || true
fi
cat "$out/time.txt"
exit "$code"

#!/bin/sh
# Samples of the PERF-1 task 1 measurement on the local compose stack (native views only, spec Z6), every INTERVAL
# seconds until stopped: memory.stat of every running container of the project (cgroup v2), and from Postgres the size of
# pg_wal, the connections by role and the checkpointer counters. Usage, from the repository root:
#   sh docs/reports/runtime-config-baseline-2026-11-27/sample.sh <output.tsv> [INTERVAL]
# Lines: <unix time> mem <container> <key>=<value>...   (anon shmem file slab_unreclaimable kernel_stack pagetables sock, bytes)
#        <unix time> pg wal_bytes=<n> conn_<role>=<n>... num_timed=<n> num_requested=<n> buffers_written=<n>
# A container that stops is skipped; a failing psql writes nothing for that sample. Ends on SIGINT/SIGTERM.
set -u
out="$1"
interval="${2:-10}"
project="$(docker compose config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["name"])')"
while :; do
  now="$(date +%s)"
  for c in $(docker ps --filter "label=com.docker.compose.project=$project" --format '{{.Names}}'); do
    docker exec "$c" cat /sys/fs/cgroup/memory.stat 2>/dev/null \
      | awk -v t="$now" -v c="$c" '$1 ~ /^(anon|shmem|file|slab_unreclaimable|kernel_stack|pagetables|sock)$/ { s = s " " $1 "=" $2 } END { if (s != "") print t " mem " c s }'
  done >> "$out"
  docker compose exec -T postgres psql -U postgres -d shop -Atq -F ' ' -c "
    SELECT 'wal_bytes=' || (SELECT coalesce(sum(size), 0) FROM pg_ls_waldir())
      || coalesce((SELECT ' ' || string_agg('conn_' || coalesce(usename, 'system') || '=' || n, ' ') FROM (SELECT usename, count(*) AS n FROM pg_stat_activity WHERE backend_type = 'client backend' GROUP BY 1) a), '')
      || (SELECT ' num_timed=' || num_timed || ' num_requested=' || num_requested || ' buffers_written=' || buffers_written FROM pg_stat_checkpointer)" 2>/dev/null \
    | awk -v t="$now" 'NF { print t " pg " $0 }' >> "$out"
  sleep "$interval"
done

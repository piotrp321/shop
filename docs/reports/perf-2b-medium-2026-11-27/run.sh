#!/bin/sh
# PERF-2B-8: the local verification of PERF-2 stage B on the data medium (1 million products): the comparison of the answers of
# GET /api/v1/products before and after the stage, the run of the writes scenario and the first local comparison of the cold path.
#
#   PERF2B8_WINDOW=start sh docs/reports/perf-2b-medium-2026-11-27/run.sh <command> [argument]
#
# The commands start containers and load the host, so they refuse to run without PERF2B8_WINDOW=start (the window of the
# owner of the host: other sessions measure outside it). Only `prepare` and `revisions` need no container.
#
# The data: the volumes perf2b-8_postgres-data, perf2b-8_garage-data, perf2b-8_garage-meta are a copy of medium (main 98ac711b).
# The two revisions use the same project (perf2b-8) and so the same volumes, one stack at a time: `down` (never `down -v`)
# stops one, `up` starts the other. The checkout of the revision "before" is a git worktree of main 98ac711b, "after" is this
# worktree (the epic branch with PERF-2B-1…7 and the scripts of this directory).
#
#   prepare             .env and .env.local of both checkouts, the worktree "before"; checks the ports. No container.
#   revisions           the revisions and the version of the tool to results/revisions.txt. No container.
#   up before|after     the stack of one revision, lean: postgres, redis, elasticsearch, rabbitmq, keycloak, api; migrations and
#                       setup of the search index of that revision; NO workers (nothing writes to the data during the comparison)
#   down                stops the stack of the project (the volumes stay)
#   dbsums NAME         results/dbsums-NAME.txt: rows and hashes of every table (the state of the data)
#   reindex before|after   catalog:reindex of the revision, its time and the indexes of Elasticsearch to results/es-REV.txt
#   urls                the mix of load/baseline.py (fixed seed) to results/urls.json (run once, on "before")
#   fetch before|after  the answers of the API (not Varnish) to every listing of the mix: $WORK/answers-REV.jsonl and the
#                       digest results/digest-REV.json
#   diff                the comparison of the two sets: results/diff.json, results/diff.txt; exit 0 = 0 differences
#   singles before|after   the empty request cache, baseline.py run --mode single on the API addresses of the listings: a LOCAL,
#                       preliminary comparison (results/singles-REV.*), not an acceptance result
#   writes-up           the whole stack with the workers on the revision "after": the realm of Keycloak imported again for the
#                       ports of this stack (the copied realm has the addresses of the stack of the source of the copy), make up
#   writes clean12|lag50   one run of the writes scenario (load/writes.sh) with the state of the caches before and after it
#   cachestats LABEL    results/cache-LABEL.txt: request cache of Elasticsearch, the memory (file cache) of its container
#   failed LABEL        results/failed-LABEL.txt: messenger:failed:show --stats and the list
#   build-prod          docker build --target prod of apps/api into the image perf2b-8-api:prod (the revision of this worktree)
#   prod-up             with MODE=prod: make up with the api and the workers on that image (prod.override.yaml)
#   probe LABEL ENV DEBUG LIMIT [holder|stopwatch]   memory per batch of catalog:reindex (probe-reindex.php), results/probe/LABEL.jsonl
#   MODE=prod           the writes (clean12, lag50) go to results/writes-prod, labels with the suffix -prod
#   phase-before        prepare, up before, dbsums, reindex, urls, fetch, singles, dbsums, down
#   phase-after         up after, dbsums, reindex, dbsums, fetch, diff, singles; the stack stays up
#   phase-writes        writes-up, clean12, lag50, failed
#   sync-after          git merge of origin/feat/perf-2b into this worktree (when PERF-2B-7 is there); then revisions
# Logs: $WORK (default $TMPDIR/PERF-2B-8); the small results that belong to the report: results/ next to this script.
set -eu
export LC_ALL=C

here="$(cd "$(dirname "$0")" && pwd)"
after_dir="$(cd "$here/../../.." && pwd)"
before_dir="${BEFORE_DIR:-$(dirname "$after_dir")/perf2b-8-before}"
before_rev="${BEFORE_REV:-98ac711b}"
secrets_src="${SECRETS_SRC:-/Users/pp/Development/claude/shop/.claude/worktrees/api-input-contract-stage-2-8d56e4/.env.local}"
project=perf2b-8
port_base=41800
api_port=$((port_base + 2))
refresh="${SEARCH_REFRESH_INTERVAL:-5s}"
work="${WORK:-${TMPDIR:-/tmp}/PERF-2B-8}"
res="$here/results"
mkdir -p "$work" "$res"
# MODE=prod: the api and both workers run the production image perf2b-8-api:prod (prod.override.yaml); the results of the writes
# go to results/writes-prod, the labels of the cache and failed files get the suffix -prod.
mode="${MODE:-dev}"
suffix=""
if [ "$mode" = prod ]; then
  suffix="-prod"
  # Absolute paths: make -C apps/api runs docker compose from another directory.
  export COMPOSE_FILE="$after_dir/compose.yaml:$here/es.override.yaml:$here/prod.override.yaml"
elif [ "$mode" != dev ]; then
  echo "run.sh: MODE is dev or prod, not '$mode'" >&2
  exit 1
fi
mix_seed=20261127
kinds=home,home_deep,department,department_deep,leaf,leaf_filter,phrase,phrase_deep,phrase_filter

fail() { echo "run.sh: $*" >&2; exit 1; }
log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" | tee -a "$res/events.log"; }
need_window() { [ "${PERF2B8_WINDOW:-}" = start ] || fail "containers only in the window of the host: set PERF2B8_WINDOW=start after the OKNO START"; }
dir_of() {
  case "$1" in
    before) echo "$before_dir" ;;
    after) echo "$after_dir" ;;
    *) fail "the revision is 'before' or 'after', not '$1'" ;;
  esac
}
# dc REV args: docker compose in the checkout of the revision (the .env of it names the project and the ports)
dc() { _rev="$1"; shift; ( cd "$(dir_of "$_rev")" && docker compose "$@" ); }
# es REV METHOD PATH: a request to Elasticsearch from inside its container (the port is not published)
es() { _rev="$1"; _method="$2"; _path="$3"; dc "$_rev" exec -T elasticsearch curl -s -X "$_method" "http://localhost:9200$_path"; }
console() { _rev="$1"; shift; dc "$_rev" exec -T api bin/console "$@"; }

cmd_prepare() {
  [ -f "$secrets_src" ] || fail "no $secrets_src (the secrets of the session that made the copy of the data)"
  python3 - "$port_base" <<'PY' || fail "a port of 41800-41806 is in use"
import socket, sys
base = int(sys.argv[1])
for port in range(base, base + 7):
    s = socket.socket()
    try:
        s.bind(("127.0.0.1", port))
    except OSError:
        print(f"port {port} busy", file=sys.stderr)
        sys.exit(1)
    finally:
        s.close()
PY
  if [ ! -d "$before_dir" ]; then
    git -C "$after_dir" worktree add --detach "$before_dir" "$before_rev"
  fi
  [ "$(git -C "$before_dir" rev-parse HEAD | cut -c1-8)" = "$(echo "$before_rev" | cut -c1-8)" ] || fail "$before_dir is not at $before_rev"
  for dir in "$after_dir" "$before_dir"; do
    # The secrets are those of the database (the passwords of its users); never printed, never committed (.gitignore).
    [ -f "$dir/.env.local" ] || { cp "$secrets_src" "$dir/.env.local"; chmod 600 "$dir/.env.local"; }
    cat > "$dir/.env" <<ENV
COMPOSE_PROJECT_NAME=$project
STORE_PORT=$port_base
ADMIN_PORT=$((port_base + 1))
API_PORT=$api_port
KEYCLOAK_PORT=$((port_base + 3))
IMGPROXY_PORT=$((port_base + 4))
MAILPIT_PORT=$((port_base + 5))
RABBITMQ_MANAGEMENT_PORT=$((port_base + 6))
SEARCH_SHARDS=1
SEARCH_REFRESH_INTERVAL=$refresh
COMPOSE_FILE=compose.yaml:$here/es.override.yaml
ENV
    { git -C "$dir" check-ignore -q .env && git -C "$dir" check-ignore -q .env.local; } || fail "$dir: .env or .env.local is not ignored by git"
  done
  log "prepare: .env (project $project, ports $port_base-$((port_base + 6)), 1 shard, refresh $refresh) and .env.local in both checkouts"
  cmd_revisions
}

cmd_revisions() {
  {
    echo "before: $(git -C "$before_dir" rev-parse HEAD) (main, the data of the copy)"
    echo "after: $(git -C "$after_dir" rev-parse HEAD) on $(git -C "$after_dir" branch --show-current)"
    echo "origin/feat/perf-2b: $(git -C "$after_dir" rev-parse origin/feat/perf-2b)"
    echo "merge-base of after and 98ac711b: $(git -C "$after_dir" merge-base HEAD "$before_rev")"
    echo "load/baseline.py: $(git -C "$after_dir" hash-object load/baseline.py) (the same tool for both revisions)"
    echo "mix seed: $mix_seed"
    echo "refresh_interval of the stack: $refresh; shards: 1"
  } | tee "$res/revisions.txt"
}

# The stack of one revision without the workers: the migrations and the setup of the index of that revision run, nothing consumes
# messages, so the data stays as it was copied. The OIDC key cache of the API is on the bind mount of the checkout.
cmd_up() {
  need_window
  rev="$1"
  log "up $rev ($(git -C "$(dir_of "$rev")" rev-parse --short HEAD))"
  ( cd "$(dir_of "$rev")" && sh infra/scripts/init-env.sh )
  dc "$rev" up -d --wait postgres redis elasticsearch rabbitmq keycloak api > "$work/up-$rev.log" 2>&1 || { tail -30 "$work/up-$rev.log"; fail "docker compose up failed ($work/up-$rev.log)"; }
  dc "$rev" run --rm rabbitmq-init >> "$work/up-$rev.log" 2>&1 || fail "rabbitmq-init failed ($work/up-$rev.log)"
  console "$rev" doctrine:migrations:migrate --no-interaction --allow-no-migration >> "$work/up-$rev.log" 2>&1 || fail "migrations failed"
  console "$rev" catalog:search-index:setup > "$work/setup-$rev.log" 2>&1 || { cat "$work/setup-$rev.log"; fail "catalog:search-index:setup failed"; }
  cat "$work/setup-$rev.log"
  console "$rev" cache:pool:clear cache.oidc >> "$work/up-$rev.log" 2>&1
  dc "$rev" images > "$res/images-$rev.txt" 2>&1 || true
  curl -fs --max-time 10 "http://127.0.0.1:$api_port/health" > /dev/null || fail "the API does not answer on $api_port"
  log "up $rev: ready (setup output in $work/setup-$rev.log)"
}

cmd_down() {
  need_window
  # Whichever checkout the stack came from: the project is the same. No -v: the volumes are the copy of the data.
  ( cd "$after_dir" && docker compose down ) || fail "docker compose down failed"
  log "down (volumes kept)"
}

cmd_dbsums() {
  need_window
  name="$1"
  log "dbsums $name"
  dc after exec -T postgres psql -v ON_ERROR_STOP=1 -U postgres -d shop -At -f - < "$here/dbsums.sql" > "$res/dbsums-$name.txt" \
    || fail "dbsums failed (is the stack up?)"
  echo "dbsums $name: $(wc -l < "$res/dbsums-$name.txt" | tr -d ' ') tables"
}

cmd_reindex() {
  need_window
  rev="$1"
  log "reindex $rev: start"
  started="$(date +%s)"
  code=0
  # With kernel.debug (APP_DEBUG=1, the default of APP_ENV=dev) the rebuild keeps every SQL statement with its parameters (Doctrine
  # DebugDataHolder) and every period of the Stopwatch, about 0.4 MiB per batch, and exhausts the 256 MB of memory_limit near 250 000
  # products (report perf-2b-medium-2026-11-27.md, section 6). --no-debug keeps the memory flat; no limit is raised.
  dc "$rev" exec -T api bin/console catalog:reindex --no-debug > "$work/reindex-$rev.log" 2>&1 || code=$?
  seconds=$(( $(date +%s) - started ))
  tail -5 "$work/reindex-$rev.log"
  {
    echo "catalog:reindex on $rev: exit $code, $seconds s"
    echo "== _cat/indices"; es "$rev" GET '/_cat/indices?v&bytes=b'
    echo "== _cat/aliases"; es "$rev" GET '/_cat/aliases?v'
    echo "== products/_count"; es "$rev" GET '/products/_count'; echo
    echo "== products/_settings"; es "$rev" GET '/products/_settings?flat_settings=true'; echo
    echo "== products/_stats/docs,store"; es "$rev" GET '/products/_stats/docs,store'; echo
  } > "$res/es-$rev.txt"
  log "reindex $rev: exit $code in $seconds s (log $work/reindex-$rev.log)"
  [ "$code" -eq 0 ] || fail "catalog:reindex exit $code"
}

cmd_urls() {
  need_window
  [ ! -e "$res/urls.json" ] || fail "results/urls.json exists: the mix is saved once and reused (delete it only to start over)"
  python3 -I "$after_dir/load/baseline.py" urls "http://localhost:$api_port" "http://localhost:$port_base" "$res/urls.json" \
    --listings 300 --products 150 --seed "$mix_seed" | tee "$res/urls.txt"
  log "urls: mix saved, seed $mix_seed"
}

cmd_fetch() {
  need_window
  rev="$1"
  [ -f "$res/urls.json" ] || fail "no results/urls.json (urls first)"
  python3 -I "$here/compare.py" fetch "$res/urls.json" "http://localhost:$api_port" "$work/answers-$rev.jsonl" || fail "some answers are not 200"
  python3 -I "$here/compare.py" digest "$work/answers-$rev.jsonl" "$res/digest-$rev.json"
  log "fetch $rev: answers in $work/answers-$rev.jsonl"
}

cmd_diff() {
  code=0
  python3 -I "$here/compare.py" diff "$work/answers-before.jsonl" "$work/answers-after.jsonl" --out "$res/diff.json" > "$res/diff.txt" || code=$?
  cat "$res/diff.txt"
  log "diff: exit $code"
  return "$code"
}

cmd_cachestats() {
  need_window
  label="$1"
  {
    echo "== $(date -u +%Y-%m-%dT%H:%M:%SZ) $label"
    echo "== _nodes/stats/indices/request_cache"; es after GET '/_nodes/stats/indices/request_cache'; echo
    echo "== products/_stats/request_cache,query_cache"; es after GET '/products/_stats/request_cache,query_cache'; echo
    echo "== memory of the Elasticsearch container (cgroup v2: file = page cache, anon = heap and other)"
    dc after exec -T elasticsearch sh -c 'for f in memory.current memory.max; do printf "%s " "$f"; cat /sys/fs/cgroup/$f; done; grep -E "^(anon|file|file_mapped|active_file|inactive_file) " /sys/fs/cgroup/memory.stat' 2>&1 || true
    echo "== docker stats"
    ids="$(dc after ps -q elasticsearch api postgres 2>/dev/null | tr '\n' ' ')"
    # Without ids docker stats would list every container of the host, other sessions included.
    [ -z "$ids" ] || docker stats --no-stream --format '{{.Name}} cpu {{.CPUPerc}} mem {{.MemUsage}}' $ids 2>&1 || true
  } > "$res/cache-$label.txt"
  echo "cachestats: $res/cache-$label.txt"
}

cmd_failed() {
  need_window
  label="$1"
  {
    echo "== messenger:failed:show --stats"; console after messenger:failed:show --stats
    echo "== messenger:failed:show --max=50"; console after messenger:failed:show --max=50
  } > "$res/failed-$label.txt" 2>&1 || true
  tail -4 "$res/failed-$label.txt"
}

cmd_singles() {
  need_window
  rev="$1"
  out="$work/singles-$rev.jsonl"
  [ ! -e "$out" ] || fail "$out exists (baseline.py appends): move it away"
  es "$rev" POST '/products/_cache/clear?request=true' > "$res/singles-$rev-cache-clear.txt"
  cmd_cachestats "singles-$rev-before"
  log "singles $rev: baseline.py run --mode single (API addresses of the listings, request cache cleared)"
  code=0
  python3 -I "$after_dir/load/baseline.py" run "$res/urls.json" "$out" --mode single --target api --kinds "$kinds" || code=$?
  cmd_cachestats "singles-$rev-after"
  python3 -I "$after_dir/load/baseline.py" report "$out" > "$res/singles-$rev-report.txt" || true
  cp "$out.meta.json" "$res/singles-$rev.meta.json"
  log "singles $rev: exit $code (LOCAL, preliminary; report in results/singles-$rev-report.txt)"
}

cmd_writes_up() {
  need_window
  cd "$after_dir"
  log "writes-up: the realm of Keycloak imported for the ports of this stack, make up"
  # The realm of the copy has the redirect addresses of the source stack (admin port): the login of load/admin-token.py needs ours.
  make keycloak-reset > "$work/keycloak-reset.log" 2>&1 || { tail -20 "$work/keycloak-reset.log"; fail "keycloak-reset failed"; }
  make up > "$work/make-up-after.log" 2>&1 || { tail -30 "$work/make-up-after.log"; fail "make up failed ($work/make-up-after.log)"; }
  console after cache:pool:clear cache.oidc > /dev/null
  dc after ps > "$res/ps-writes.txt"
  log "writes-up: ready"
}

cmd_build_prod() {
  need_window
  log "build-prod: docker build --target prod of apps/api at $(git -C "$after_dir" rev-parse --short HEAD)"
  docker build --target prod -t perf2b-8-api:prod "$after_dir/apps/api" > "$work/build-prod.log" 2>&1 || { tail -20 "$work/build-prod.log"; fail "docker build failed"; }
  log "build-prod: perf2b-8-api:prod built"
}

# The whole stack with the api and the workers on the production image (MODE=prod): make up recreates them, the OIDC key cache of
# the API is cleared, APP_ENV of every one of them is checked. The realm of Keycloak stays as writes-up imported it.
cmd_prod_up() {
  need_window
  [ "$mode" = prod ] || fail "prod-up needs MODE=prod"
  docker image inspect perf2b-8-api:prod > /dev/null 2>&1 || fail "no image perf2b-8-api:prod (build-prod first)"
  cd "$after_dir"
  log "prod-up: make up with the production image"
  make up > "$work/make-up-prod.log" 2>&1 || { tail -30 "$work/make-up-prod.log"; fail "make up failed ($work/make-up-prod.log)"; }
  console after cache:pool:clear cache.oidc > /dev/null
  for service in api worker worker-commands; do
    printf '%s: %s\n' "$service" "$(dc after exec -T "$service" sh -c 'echo "APP_ENV=$APP_ENV APP_DEBUG=$APP_DEBUG $(test -d /app/vendor/phpunit && echo has-phpunit || echo no-phpunit)"')"
  done | tee "$res/prod-env.txt"
  dc after ps > "$res/ps-writes-prod.txt"
  log "prod-up: ready"
}

# probe LABEL ENV DEBUG LIMIT [holder|stopwatch]: probe-reindex.php in the api container (memory_limit 256M as in the image),
# one line per batch to results/probe/LABEL.jsonl; a limit above 0 ends the rebuild at that number of documents, 0 runs it to the end
# (the alias moves to the new index). The stack must be up (up after, or MODE=prod prod-up).
cmd_probe() {
  need_window
  label="$1"; env_name="$2"; debug="$3"; limit="$4"; reset="${5:-}"
  mkdir -p "$res/probe"
  dc after cp "$here/probe-reindex.php" api:/tmp/probe-reindex.php > /dev/null 2>&1 || fail "cannot copy the probe to the api container"
  log "probe $label: APP_ENV=$env_name APP_DEBUG=$debug limit $limit reset '$reset' (mode $mode)"
  code=0
  dc after exec -T -e "APP_ENV=$env_name" -e "APP_DEBUG=$debug" -e "PROBE_LABEL=$label" -e "PROBE_LIMIT=$limit" -e "PROBE_RESET=$reset" \
    -e "PROBE_OUT=/tmp/probe-$label.jsonl" api php -d memory_limit=256M /tmp/probe-reindex.php > "$work/probe-$label.out" 2>&1 || code=$?
  dc after exec -T api cat "/tmp/probe-$label.jsonl" > "$res/probe/$label.jsonl" || true
  grep -v '^{"message"' "$work/probe-$label.out" | tail -6 | cut -c1-300
  log "probe $label: exit $code, $(wc -l < "$res/probe/$label.jsonl" | tr -d ' ') batches (log $work/probe-$label.out)"
}

# writes <name> <expected exit> [VAR=value...]: the settings of $TMPDIR/PERF-2B-2/runs.sh (the run of the task PERF-2B-2)
writes_run() {
  name="$1"; expected="$2"; shift 2
  out="$res/writes$suffix/$name"
  mkdir -p "$out"
  cd "$after_dir"
  cmd_failed "$name$suffix-before"
  cmd_cachestats "writes-$name$suffix-before"
  log "writes $name: start ($*)"
  code=0
  # The refresh token goes through the environment of a subshell, never through a command line (load/README.md, "Token").
  ( ADMIN_REFRESH_TOKEN="$(python3 load/admin-token.py)"; export ADMIN_REFRESH_TOKEN
    env WRITES_OUT="$out" "$@" sh load/writes.sh ) > "$out/output.log" 2>&1 || code=$?
  cmd_cachestats "writes-$name$suffix-after"
  cmd_failed "$name$suffix-after"
  log "writes $name: exit $code (expected $expected)"
  grep -E "^verdict:|^Writes:|^send lag|mismatch of variant|consistency: [0-9]+ mismatches" "$out/output.log" | cut -c1-300 || true
  jq -c '{finished, verdict, late: .changes.late, dropped: .changes.dropped, unanswered: .changes.unanswered, send_lag: .changes.send_lag, percentiles_valid: .freshness.percentiles_valid, mismatches: .consistency.mismatches}' "$out/writes.json" || true
}

# Waits (up to 15 minutes) until no queue of RabbitMQ holds a message, then writes the time to results/queue-LABEL.txt.
cmd_drain() {
  label="$1"
  started="$(date +%s)"
  while :; do
    messages="$(dc after exec -T rabbitmq rabbitmqctl list_queues -q name messages 2>/dev/null | awk '{s += $2} END {print s + 0}')"
    [ "$messages" -eq 0 ] && break
    [ $(( $(date +%s) - started )) -lt 900 ] || { echo "queues not empty after 900 s: $messages messages" | tee "$res/queue-$label.txt"; return 1; }
    sleep 5
  done
  echo "queues empty $(( $(date +%s) - started )) s after the end of the run" | tee "$res/queue-$label.txt"
}

cmd_writes() {
  need_window
  case "$1" in
    # clean12: within the capacity of the dev API: 12/s over 320 s (longer than the access token, 300 s). Expected: exit 0, verdict
    # passed, every window in the band 10-14, 0 catch-up writes, token_renewals.background >= 1, blocking 0, failed 0.
    clean12)
      writes_run clean12 0 WRITE_RATE=12 WRITE_SECONDS=320 WRITE_SAMPLE=60 WRITE_UNSTOCKED_SHARE=0 WRITE_PRICE_PROBES=3
      jq -c '{rate: .changes.rate, send_lag: .changes.send_lag, histogram: .changes.send_lag_histogram, renewals: .changes.token_renewals, stall: .changes.stall_tolerance_ms}' "$res/writes$suffix/clean12/writes.json" || true ;;
    # lag50: the distribution of the lag of the generator under load, 50/s over 320 s, judged by send_lag_histogram and send_lag
    # p99.9/max (and the windows), not by the verdict: the dev API takes fewer than 50/s, so unanswered writes and exit 99 are expected.
    lag50)
      writes_run lag50 99 WRITE_RATE=50 WRITE_SECONDS=320 WRITE_SAMPLE=40 WRITE_UNSTOCKED_SHARE=0 WRITE_PRICE_PROBES=2 \
        WRITE_PRICE_SHARE=0 WRITE_MAX_IN_FLIGHT=2000 CHECK_TIMEOUT_SECONDS=180
      jq -c '{rate: .changes.rate, send_lag: .changes.send_lag, histogram: .changes.send_lag_histogram, renewals: .changes.token_renewals, unanswered: .changes.unanswered}' "$res/writes$suffix/lag50/writes.json" || true ;;
    *) fail "writes: clean12 or lag50" ;;
  esac
}

cmd_sync_after() {
  git -C "$after_dir" fetch origin
  git -C "$after_dir" merge --no-edit origin/feat/perf-2b
  cmd_revisions
}

cmd_phase_before() {
  cmd_prepare
  cmd_up before
  cmd_dbsums before-1
  cmd_reindex before
  cmd_urls
  cmd_fetch before
  cmd_singles before
  cmd_dbsums before-2
  diff "$res/dbsums-before-1.txt" "$res/dbsums-before-2.txt" > /dev/null || fail "the data changed during the phase before (dbsums-before-1 vs 2)"
  cmd_down
}

cmd_phase_after() {
  cmd_up after
  cmd_dbsums after-1
  diff "$res/dbsums-before-2.txt" "$res/dbsums-after-1.txt" > /dev/null || fail "the data differ from the state of the phase before (dbsums-before-2 vs after-1)"
  cmd_reindex after
  cmd_dbsums after-2
  diff "$res/dbsums-before-2.txt" "$res/dbsums-after-2.txt" > /dev/null || fail "the data changed during the phase after (dbsums-before-2 vs after-2)"
  cmd_fetch after
  cmd_diff || true
  cmd_singles after
}

cmd_phase_writes() {
  cmd_writes_up
  cmd_writes clean12
  cmd_writes lag50
  cmd_failed final
}

command="${1:-}"
[ $# -eq 0 ] || shift
case "$command" in
  prepare) cmd_prepare ;;
  revisions) cmd_revisions ;;
  up) cmd_up "${1:?before|after}" ;;
  down) cmd_down ;;
  dbsums) cmd_dbsums "${1:?name}" ;;
  reindex) cmd_reindex "${1:?before|after}" ;;
  urls) cmd_urls ;;
  fetch) cmd_fetch "${1:?before|after}" ;;
  diff) cmd_diff ;;
  singles) cmd_singles "${1:?before|after}" ;;
  writes-up) cmd_writes_up ;;
  writes) cmd_writes "${1:?clean12|lag50}" ;;
  cachestats) cmd_cachestats "${1:?label}" ;;
  failed) cmd_failed "${1:?label}" ;;
  build-prod) cmd_build_prod ;;
  prod-up) cmd_prod_up ;;
  probe) cmd_probe "${1:?label}" "${2:?env}" "${3:?debug}" "${4:?limit}" "${5:-}" ;;
  drain) need_window; cmd_drain "${1:?label}" ;;
  phase-before) need_window; cmd_phase_before ;;
  phase-after) need_window; cmd_phase_after ;;
  phase-writes) need_window; cmd_phase_writes ;;
  sync-after) cmd_sync_after ;;
  *) sed -n '2,42p' "$0" >&2; exit 2 ;;
esac

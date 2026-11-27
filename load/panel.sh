#!/bin/sh
# make load-panel: W7 (the panel under the load of its users) and P4 (what the panel costs the shop) of the performance program
# (PERF-7), against the running stack of docker compose (make up first, the data of the profile loaded: make seed-scale).
# One command: log in as the administrator (panel_token.py), draw the mix of requests from the data (panel_mix.py, once per
# output directory; PANEL_MIX reuses a file, so that the same mix is run before and after a change) and run k6 (panel.js)
# PANEL_RUNS times (default 3: the result is the median, the spread of the runs is reported next to it). The mix, the token's
# lifetime and every result are written together to PANEL_OUT.
#
#   sh load/panel.sh w7   the mix at PANEL_RATE requests per second (default 5, the proposal of the spec, not yet approved by the
#                         owner) for PANEL_SECONDS (default 300), PANEL_RUNS times
#   sh load/panel.sh p4   the shop (SHOP_LISTING_RATE cold listings and SHOP_CART_RATE carts per second) without the panel and with
#                         it (the mix at PANEL_RATE, PANEL_WORST_VUS threads of the heaviest classes, default 8, and the boundaries
#                         of the sitemap cut cold every BOUNDARIES_EVERY seconds, default 30), PANEL_RUNS runs of each, alternating;
#                         the load of PostgreSQL and of the containers is sampled and written next to every run
#
# Settings (environment): PANEL_OUT (default a new directory in $TMPDIR), PANEL_MIX, PANEL_RUNS, PANEL_RATE, PANEL_SECONDS,
# PANEL_WORST_VUS, BOUNDARIES_EVERY, SHOP_LISTING_RATE, SHOP_CART_RATE, PANEL_USER / PANEL_PASSWORD / PANEL_TOTP_SECRET (the demo
# administrator by default), PANEL_PAUSE (seconds between two runs, default 60; the caches of listings expire in it).
# Exit codes: 0 done; 2 the setup is wrong (no stack, no token, no mix file, wrong mode); k6's own code otherwise (99: a check
# failed, 108: the run was aborted, e.g. the token could not be renewed).
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=k6.sh
. "$here/k6.sh"

fail() { echo "load-panel: $1" >&2; exit 2; }

mode="${1:-w7}"
case "$mode" in w7 | p4) ;; *) fail "the mode is w7 or p4, got '$mode'" ;; esac
command -v docker > /dev/null 2>&1 || fail "docker is not installed"
command -v python3 > /dev/null 2>&1 || fail "python3 is not installed"

api_container="$(docker compose ps -q api 2> /dev/null | head -n 1 || true)"
[ -n "$api_container" ] || fail "the API of the stack is not running (make up and make seed-scale first)"
network="$(docker inspect --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{"\n"}}{{end}}' "$api_container" | head -n 1)"
[ -n "$network" ] || fail "cannot find the network of the API container"
host_port() { docker compose port "$1" "$2" 2> /dev/null | sed 's/.*://' || true; }
api_port="$(host_port api 80)"
keycloak_port="$(host_port keycloak 8080)"
admin_port="$(host_port admin 5173)"
[ -n "$api_port" ] && [ -n "$keycloak_port" ] || fail "the API or Keycloak has no published port"
api_host="http://localhost:$api_port"
keycloak_host="http://localhost:$keycloak_port"
admin_url="http://localhost:${admin_port:-5173}"

out="${PANEL_OUT:-${TMPDIR:-/tmp}/panel-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$out" || fail "cannot create $out"
runs="${PANEL_RUNS:-3}"
seconds="${PANEL_SECONDS:-300}"
rate="${PANEL_RATE:-5}"
pause="${PANEL_PAUSE:-60}"

# The account of the panel: the token and the way to renew it (the access token lives 5 minutes).
python3 -I "$here/panel_token.py" --keycloak "$keycloak_host" --admin-url "$admin_url" > "$out/token.json" || fail "no token of the administrator (see above)"

# The mix: drawn once and kept with the results; a given file is used as it is.
if [ -n "${PANEL_MIX:-}" ]; then
  [ -r "$PANEL_MIX" ] || fail "the mix file $PANEL_MIX does not exist or cannot be read"
  cp "$PANEL_MIX" "$out/mix.json"
elif [ ! -s "$out/mix.json" ]; then
  python3 -I "$here/panel_mix.py" --api "$api_host" --token-file "$out/token.json" --out "$out/mix.json" || fail "the mix could not be drawn (see above)"
fi

psql() { docker compose exec -T postgres psql -U postgres -d shop -At "$@"; }

# One snapshot of the counters of PostgreSQL (JSON, one line): the reads from disk and the hits of the shared buffers, the temporary
# bytes, the WAL and the statements of the database.
snapshot() {
  psql -c "select json_build_object('at', now(), 'blks_read', d.blks_read, 'blks_hit', d.blks_hit, 'temp_bytes', d.temp_bytes, 'xact_commit', d.xact_commit, 'tup_returned', d.tup_returned, 'wal_bytes', w.wal_bytes, 'io_read_bytes', (select coalesce(sum(read_bytes), 0) from pg_stat_io where backend_type = 'client backend'), 'io_reads', (select coalesce(sum(reads), 0) from pg_stat_io where backend_type = 'client backend')) from pg_stat_database d, pg_stat_wal w where d.datname = 'shop'"
}

# Samples every 5 seconds until the file $1.stop appears: the sessions of PostgreSQL by state and the CPU and memory of the containers.
sample() {
  while [ ! -e "$1.stop" ]; do
    {
      date +%s
      psql -F ' ' -c "select coalesce(state, 'none'), coalesce(wait_event_type, '-'), count(*) from pg_stat_activity where datname = 'shop' group by 1, 2" | sed 's/^/pg /'
      docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' "$(docker compose ps -q postgres)" "$(docker compose ps -q api)" "$(docker compose ps -q elasticsearch)" 2> /dev/null | sed 's/^/ct /'
    } >> "$1.samples" 2> /dev/null || true
    sleep 5
  done
}

# The cut of the boundaries is cold when the cache of the sitemap is empty: purged every BOUNDARIES_EVERY seconds during the run.
purge_loop() {
  while [ ! -e "$1.stop" ]; do
    docker compose exec -T api bin/console cache:pool:clear cache.sitemap > /dev/null 2>&1 || true
    sleep "$2"
  done
}

# run_k6 <directory> <extra k6 options...>
run_k6() {
  dir="$1"
  shift
  mkdir -p "$dir"
  cp "$out/mix.json" "$out/token.json" "$dir/"
  [ -z "${SHOP_URLS_FILE:-}" ] || cp "$SHOP_URLS_FILE" "$dir/shop-urls.json"
  rm -f "$dir.stop"
  snapshot > "$dir/pg-before.json" || true
  sample "$dir" &
  sampler=$!
  purger=""
  if [ "${boundaries:-0}" -gt 0 ]; then
    purge_loop "$dir" "$boundaries" &
    purger=$!
  fi
  code=0
  # shellcheck disable=SC2086 # the options of k6 are separate words
  k6_run "$network" "$dir" panel.js -e STORE_URL=http://store:3000 -e API_URL=http://api -e KEYCLOAK_URL=http://keycloak:8080 \
    -e PANEL_MIX=/out/mix.json -e PANEL_TOKEN=/out/token.json -e PANEL_RATE="$run_rate" -e PANEL_SECONDS="$seconds" "$@" > "$dir/k6.txt" 2>&1 || code=$?
  touch "$dir.stop"
  snapshot > "$dir/pg-after.json" || true
  wait "$sampler" ${purger:+"$purger"} 2> /dev/null || true
  mv "$dir.samples" "$dir/samples.txt" 2> /dev/null || true
  rm -f "$dir.stop"
  cat "$dir/k6.txt"
  [ -s "$dir/panel.json" ] || fail "k6 left no result in $dir (exit code $code): $(tail -n 3 "$dir/k6.txt" | tr '\n' ' ')"
  return "$code"
}

status=0
n=1
run_rate="$rate"
case "$mode" in
  w7)
    boundaries=0
    while [ "$n" -le "$runs" ]; do
      echo "load-panel: w7 run $n of $runs (rate $rate/s, $seconds s)"
      run_k6 "$out/w7-run$n" > /dev/null || status=$?
      [ "$n" -ge "$runs" ] || sleep "$pause"
      n=$((n + 1))
    done
    ;;
  p4)
    [ -n "${SHOP_URLS_FILE:-}" ] && [ -r "$SHOP_URLS_FILE" ] || fail "p4 needs SHOP_URLS_FILE: the file of 'python3 load/baseline.py urls <api> <store> FILE --listings N' (N at least the listings of a run)"
    listing="${SHOP_LISTING_RATE:-2}"
    cart="${SHOP_CART_RATE:-5}"
    worst="${PANEL_WORST_VUS:-8}"
    every="${BOUNDARIES_EVERY:-30}"
    shop="-e SHOP_URLS=/out/shop-urls.json -e SHOP_LISTING_RATE=$listing -e SHOP_CART_RATE=$cart"
    while [ "$n" -le "$runs" ]; do
      echo "load-panel: p4 run $n of $runs without the panel (shop: $listing listings/s, $cart carts/s, $seconds s)"
      boundaries=0
      # shellcheck disable=SC2086
      run_rate=0
      run_k6 "$out/p4-without-run$n" $shop > /dev/null || status=$?
      sleep "$pause"
      echo "load-panel: p4 run $n of $runs with the panel (mix $rate/s, $worst worst-case threads, boundaries every $every s)"
      boundaries="$every"
      run_rate="$rate"
      # shellcheck disable=SC2086
      run_k6 "$out/p4-with-run$n" $shop -e PANEL_WORST_VUS="$worst" -e BOUNDARIES_EVERY="$every" > /dev/null || status=$?
      [ "$n" -ge "$runs" ] || sleep "$pause"
      n=$((n + 1))
    done
    ;;
esac

python3 -I "$here/panel_report.py" "$out" "$mode" | tee "$out/report.txt"
echo "load-panel: results in $out"
exit "$status"

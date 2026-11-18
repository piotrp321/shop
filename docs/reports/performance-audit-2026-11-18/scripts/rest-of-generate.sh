#!/bin/sh
# The steps of bin/generate-demo after demo-data:accounts (which failed on the 5 s max_duration of the shared HTTP client),
# in the same order and with the same options. Runs inside the api container.
set -eu
common="--demo-profile=medium --anchor=2026-11-18T00:00:00Z"
step() { echo "== $(date +%T) START $*"; "$@" 2>&1 | grep -v '"message"' | grep -v '^ *$' | grep -v 'part [0-9]*:' | tail -6; echo "== $(date +%T) END $1 $2"; }
echo "== $(date +%T) START orders (4 workers)"
pids=""
for i in 0 1 2 3; do bin/console demo-data:orders $common --worker=$i --workers=4 2>&1 | grep -v '"message"' | grep '\[OK\]\|rror' & pids="$pids $!"; done
for p in $pids; do wait $p; done
echo "== $(date +%T) END orders"
step bin/console demo-data:stock $common
step bin/console demo-data:order-numbers $common
step bin/console ordering:read-models:rebuild
step bin/console demo-data:analyze
step bin/console catalog:reindex
step bin/console storefront:cache:purge-all
echo "REST_DONE"

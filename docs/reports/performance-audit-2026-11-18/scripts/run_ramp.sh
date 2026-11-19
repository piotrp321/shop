#!/bin/sh
# run_ramp.sh LABEL: the project's own ramp to saturation (load/saturate.js: reads and carts through the store) against
# the compose stack of the audit, with a sample of the CPU of every container every 5 s.
set -u
S="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="${SHOP_ROOT:?set SHOP_ROOT to the root of the repository checkout}"
out="$S/results/ramp-$1"; mkdir -p "$out"
K6_IMAGE="grafana/k6:2.3.0@sha256:2dd4756ec6e14e62778916e1eb850108e4b499304c02df56760fa0bdb0fba0b8"
( while :; do echo "T $(date +%T)"; docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' | grep -E 'perfaudit|k6-ramp' ; docker stats --no-stream --format '{{.Name}} {{.CPUPerc}}' | grep -v -E 'perfaudit|k6-ramp' | awk '{gsub("%","",$2); s+=$2} END {print "FOREIGN_TOTAL " s}'; sleep 3; done > "$out/stats.log" 2>&1 ) &
sampler=$!
date +%T > "$out/started"
docker rm -f k6-ramp >/dev/null 2>&1
docker run --rm --name k6-ramp --network shop-perfaudit_default -u "$(id -u):$(id -g)" -e HOME=/tmp \
  -v "$ROOT/load:/load:ro" -v "$out:/out" \
  -e STORE_URL=http://store:3000 -e API_URL=http://api -e STORE_ORIGIN=http://localhost:52700 \
  -e DISCOVER_MAX_PAGES="${DISCOVER_MAX_PAGES:-15}" -e RAMP_START_RATE="${RAMP_START_RATE:-10}" -e RAMP_RATE_STEP="${RAMP_RATE_STEP:-10}" \
  -e RAMP_STEPS="${RAMP_STEPS:-25}" -e RAMP_STEP_SECONDS="${RAMP_STEP_SECONDS:-20}" \
  "$K6_IMAGE" run --quiet /load/saturate.js > "$out/ramp.txt" 2>&1
echo "k6 exit $?" >> "$out/ramp.txt"
date +%T > "$out/finished"
kill $sampler

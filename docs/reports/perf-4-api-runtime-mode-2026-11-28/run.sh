#!/bin/sh
# run.sh <variant> <kind> <vus> : starts nothing; measures a running container proto-<variant>
set -eu
S=$(dirname "$0"); V=$1 K=$2 N=$3
cpu() { docker exec "$1" sh -c 'grep usage_usec /sys/fs/cgroup/cpu.stat | head -1 | cut -d" " -f2'; }
A0=$(cpu proto-$V); P0=$(cpu perf4-postgres-1); E0=$(cpu perf4-elasticsearch-1)
OUT=$(docker run --rm --network perf4_default -v "$S:/b:ro" -v "$S/out:/out" -u "$(id -u):$(id -g)" -e HOME=/tmp -e BASE=http://proto-$V -e KIND=$K -e VUS=$N -e DUR=${DUR:-30s} \
  -e SLUGS="$(cat $S/slugs.txt)" -e VARIANT=a37364d6-a557-5871-9466-e3581ef43be7 -e CATEGORY=9c5e2afb-1a3c-50dc-89d8-58cbc018ebb7 \
  grafana/k6:2.3.0@sha256:2dd4756ec6e14e62778916e1eb850108e4b499304c02df56760fa0bdb0fba0b8 run --quiet --summary-export=/out/s.json /b/bench.js >/dev/null 2>&1 || true; cat $S/out/s.json)
A1=$(cpu proto-$V); P1=$(cpu perf4-postgres-1); E1=$(cpu perf4-elasticsearch-1)
echo "$OUT" | python3 -c "
import json,sys
txt=sys.stdin.read(); d=json.loads(txt)
m=d['metrics']; dur=m['http_req_duration']; reqs=m['http_reqs']['count']; fails=m['checks']['fails']
a=($A1-$A0)/1000/reqs; p=($P1-$P0)/1000/reqs; e=($E1-$E0)/1000/reqs
print(f'$V\t$K\tN=$N\treq/s={m[\"http_reqs\"][\"rate\"]:.1f}\tp50={dur[\"med\"]:.1f}\tp95={dur[\"p(95)\"]:.1f}\tfails={fails}\tcpu_api_ms={a:.2f}\tcpu_pg_ms={p:.2f}\tcpu_es_ms={e:.2f}\tn={reqs}')"

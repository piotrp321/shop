#!/bin/sh
# sweep.sh SCALE HOST VUS DURATION [scenario...]: one run per scenario of targets/SCALE for HOST.
S="$(cd "$(dirname "$0")/.." && pwd)"
scale="$1"; host="$2"; vus="$3"; dur="$4"; shift 4
if [ "$#" -eq 0 ]; then set -- $(ls "$S/targets/$scale" | grep "\.$host\.json$" | sed "s/\.$host\.json$//"); fi
for sc in "$@"; do
  python3 "$S/scripts/bench.py" "$scale.$host.$sc.v$vus" "$S/targets/$scale/$sc.$host.json" --vus "$vus" --duration "$dur" --label "$scale"
done

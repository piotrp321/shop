#!/bin/sh
# PERF-2B-8: the load of the host during a run, as the proof that nothing else ran in parallel (the sections of the report).
#   sh hoststats.sh OUT_FILE STOP_FILE [SECONDS_BETWEEN_SAMPLES]
# Every sample: the time, uptime (load average), docker stats of EVERY container of the Docker host (the containers of other
# projects show up when they work), the busiest processes of the host. Stops when STOP_FILE exists. Read only.
out="$1"
stop="$2"
every="${3:-10}"
: > "$out"
while [ ! -e "$stop" ]; do
  {
    echo "== $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    uptime
    docker stats --no-stream --format '{{.Name}} cpu {{.CPUPerc}} mem {{.MemUsage}}' 2>&1
    ps -Ao pcpu,comm -r 2>/dev/null | head -6
  } >> "$out"
  sleep "$every"
done
echo "== stopped $(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$out"

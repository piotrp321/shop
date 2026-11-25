#!/bin/sh
# One run of the mass tag revalidation against the production image of the store: starts the image with the memory limit of prod
# (1536 MiB, no swap) on the network of a running compose stack (seeded; the API and Varnish render the pages), samples the memory of the
# container every 2 s and runs driver.mjs from the same image. Usage, from the root of the repository:
#   docker build --target prod -t "$STORE_IMAGE" apps/store
#   STORE_IMAGE=<image> PRODUCTS_TSV=<file of "id<TAB>slug" lines> PAUSE_AT=<tags> \
#     sh run.sh <out dir> <exact|bucket> <total tags> <compose network>
# The out dir gets driver.jsonl, memory.csv, state.txt and store.log (the log of the container with the GC trace); analyze.py reads them.
# The secret of /api/revalidate is a value of this run only; the other variables of the store come from .env.local of the stack.
set -u
out="$1" mode="$2" total="$3" network="$4"
dir="$(cd "$(dirname "$0")" && pwd)"
repo="${REPO:-$(cd "$dir/../../../.." && pwd)}"
image="${STORE_IMAGE:?STORE_IMAGE is the production image of the store}"
tsv="${PRODUCTS_TSV:?PRODUCTS_TSV is the file of id<TAB>slug lines}"
mkdir -p "$out"

docker rm -f tagtest-store > /dev/null 2>&1
docker run -d --name tagtest-store --network "$network" --memory=1536m --memory-swap=1536m \
  --env-file "$repo/.env.local" \
  -e API_INTERNAL_URL=http://api -e API_CATALOG_URL=http://varnish -e STORE_URL=http://tagtest-store:3000 \
  -e REDIS_URL=redis://redis:6379 -e KEYCLOAK_PUBLIC_URL=http://localhost:8080 -e KEYCLOAK_INTERNAL_URL=http://keycloak:8080 \
  -e STORE_REVALIDATE_SECRET=tagtest-local-secret \
  "$image" node --trace-gc server.js > /dev/null || exit 2

for _ in $(seq 1 60); do
  docker run --rm --network "$network" --entrypoint node "$image" \
    -e 'fetch("http://tagtest-store:3000/").then(r=>process.exit(r.status<500?0:1)).catch(()=>process.exit(1))' && break
  sleep 1
done

(
  echo "epoch_ms,memory_current,inactive_file,anon,file,rss_kb"
  while [ "$(docker inspect -f '{{.State.Running}}' tagtest-store 2> /dev/null)" = true ]; do
    docker exec tagtest-store sh -c 'echo "$(date +%s)000,$(cat /sys/fs/cgroup/memory.current),$(awk "/^inactive_file /{print \$2}" /sys/fs/cgroup/memory.stat),$(awk "/^anon /{print \$2}" /sys/fs/cgroup/memory.stat),$(awk "/^file /{print \$2}" /sys/fs/cgroup/memory.stat),$(awk "/^VmRSS/{print \$2}" /proc/1/status)"' 2> /dev/null
    sleep 2
  done
) > "$out/memory.csv" &
sampler=$!

docker run --rm --network "$network" -v "$dir:/work:ro" -v "$tsv:/data/products.tsv:ro" --entrypoint node "$image" \
  /work/driver.mjs --tsv /data/products.tsv --store http://tagtest-store:3000 --api http://api --secret tagtest-local-secret \
  --mode "$mode" --total "$total" --pauseAt "${PAUSE_AT:-0}" > "$out/driver.jsonl" 2> "$out/driver.err"
echo "driver exit $?" >> "$out/driver.err"

sleep 6
docker inspect -f 'running={{.State.Running}} oomkilled={{.State.OOMKilled}} exit={{.State.ExitCode}} restarts={{.RestartCount}}' tagtest-store > "$out/state.txt"
docker logs -t tagtest-store > "$out/store.log" 2>&1
kill "$sampler" 2> /dev/null
tail -3 "$out/driver.jsonl"
cat "$out/state.txt"

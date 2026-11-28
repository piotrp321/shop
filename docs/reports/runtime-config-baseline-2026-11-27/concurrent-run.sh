#!/bin/sh
# Usage, from the repository root on the perf1 stack (report, sections 4 and 8): sh docs/reports/runtime-config-baseline-2026-11-27/concurrent-run.sh <output directory> [pg]
# pg: only steps 0-2 (the Postgres part: W3 and the reindex with the mix), for a run with another Postgres configuration.
# Block 3 of the PERF-1 task 1 measurement: everything at once on perf1 (prod limits, API 2g, Postgres default).
set -u
O="$1"; mkdir -p "$O"
API=http://localhost:48502; STORE=http://localhost:48500
psql() { docker compose exec -T postgres psql -U postgres -d shop -X -A -F "$(printf '\t')" "$@"; }
stamp() { printf '%s %s\n' "$(date -u +%T)" "$1" >> "$O/phases.txt"; }
reset() { psql -qc "SELECT pg_stat_statements_reset(); SELECT pg_stat_reset(); SELECT pg_stat_reset_shared('io'); SELECT pg_stat_reset_shared('checkpointer');" >/dev/null; }
snap() { # $1 name
  psql -c "SELECT datname, blks_read, blks_hit, round(100.0*blks_hit/nullif(blks_hit+blks_read,0),2) AS hit_pct, temp_files, temp_bytes, xact_commit FROM pg_stat_database WHERE datname IN ('shop','keycloak')" > "$O/$1.database.tsv"
  psql -c "SELECT queryid, calls, round(total_exec_time) total_ms, round(mean_exec_time::numeric,2) mean_ms, round(max_exec_time) max_ms, shared_blks_hit, shared_blks_read, temp_blks_written, round(shared_blk_read_time) read_ms, round(jit_generation_time+jit_inlining_time+jit_optimization_time+jit_emission_time) jit_ms, rows, left(regexp_replace(query,'\s+',' ','g'),200) q FROM pg_stat_statements WHERE dbid=(SELECT oid FROM pg_database WHERE datname='shop') ORDER BY total_exec_time DESC LIMIT 25" > "$O/$1.statements.tsv"
  psql -c "SELECT relname, indexrelname, idx_blks_read, idx_blks_hit, round(100.0*idx_blks_hit/nullif(idx_blks_hit+idx_blks_read,0),2) hit_pct FROM pg_statio_user_indexes ORDER BY idx_blks_read DESC LIMIT 20" > "$O/$1.statio_indexes.tsv"
  psql -c "SELECT * FROM pg_stat_checkpointer" > "$O/$1.checkpointer.tsv"
  psql -c "SELECT backend_type, object, context, reads, read_bytes, round(read_time) read_ms, writes, hits, evictions FROM pg_stat_io WHERE reads > 0 OR writes > 0" > "$O/$1.io.tsv"
}
R=docs/reports/runtime-config-baseline-2026-11-27/sample.sh

# 0. The mix of PERF-0 drawn from the data (fixed seed, saved).
python3 load/baseline.py urls "$API" "$STORE" "$O/urls.json" --listings 400 --products 400 --seed 20261128 > "$O/urls.log" 2>&1
sh $R "$O/samples.tsv" 5 & SAMPLER=$!

# 1. W3: cold product pages alone (single: each once cold, then warm), Postgres hit ratio of that run.
reset; stamp "w3 start"
python3 load/baseline.py run "$O/urls.json" "$O/w3.jsonl" --mode single --kinds product > "$O/w3.log" 2>&1
stamp "w3 end"; snap w3
python3 load/baseline.py report "$O/w3.jsonl" > "$O/w3.report.txt" 2>&1

# 2. Everything at once: catalog:reindex with the listing and product mix at a constant rate (reads only, no writes).
reset; stamp "concurrent start"
docker compose exec -T api bin/console catalog:reindex > "$O/reindex.log" 2>&1 & REINDEX=$!
python3 load/baseline.py run "$O/urls.json" "$O/mix.jsonl" --mode open --rate 2 --duration 540 > "$O/mix.log" 2>&1 &
MIX=$!
wait $REINDEX; echo "reindex exit=$?" >> "$O/phases.txt"; stamp "reindex end"
wait $MIX; echo "mix exit=$?" >> "$O/phases.txt"; stamp "concurrent end"
snap concurrent
python3 load/baseline.py report "$O/mix.jsonl" > "$O/mix.report.txt" 2>&1
if [ "${2:-}" = pg ]; then kill $SAMPLER; stamp "done"; exit 0; fi

# 3. API: 16 parallel heaviest requests (16 different sitemap chunks), memory of the API sampled every second.
stamp "api16 start"
curl -s "$API/api/v1/sitemap/product-chunks" | python3 -c 'import json,sys; [print(c["key"]) for c in json.load(sys.stdin)["chunks"][:16]]' > "$O/chunks.txt"
( while :; do printf '%s ' "$(date +%s)"; docker exec "$(docker compose ps -q api)" sh -c 'grep -E "^(anon|file) " /sys/fs/cgroup/memory.stat | tr "\n" " "; cat /sys/fs/cgroup/memory.peak'; sleep 1; done > "$O/api16.mem" 2>&1 ) & APIMON=$!
while read -r k; do curl -s -o /dev/null -w '%{http_code} %{time_total} %{size_download}\n' "$API/api/v1/sitemap/products?chunk=$k" & done < "$O/chunks.txt" > "$O/api16.curl"; wait_jobs() { for p in $(jobs -p); do [ "$p" = "$APIMON" ] || [ "$p" = "$SAMPLER" ] || wait "$p"; done; }; wait_jobs
sleep 3; kill $APIMON; stamp "api16 end"

# 4. imgproxy: 16 cold transforms (photo URLs of 16 products not requested before, the largest size).
stamp "img16 start"
python3 - "$API" "$O/urls.json" > "$O/img16.urls" <<'PY'
import json, sys, urllib.request
api, urls = sys.argv[1], json.load(open(sys.argv[2]))
chunks = json.load(urllib.request.urlopen(api + "/api/v1/sitemap/product-chunks"))["chunks"]
out, originals = [], set()
for c in chunks[-3:]:
    for p in json.load(urllib.request.urlopen(api + "/api/v1/sitemap/products?chunk=" + c["key"]))["items"][:60]:
        d = json.load(urllib.request.urlopen(api + "/api/v1/products/" + p["slug"]))
        for img in (d.get("zoomImages") or []) + (d.get("images") or []):
            webp = (img.get("variants") or {}).get("webp") or []
            if not webp: continue
            best = max(webp, key=lambda v: v["width"])["url"]
            origin = best.rsplit("/", 1)[-1]
            if origin not in originals:
                originals.add(origin); out.append(best)
            break
        if len(out) >= 16: break
    if len(out) >= 16: break
print("\n".join(out))
PY
( while :; do printf '%s ' "$(date +%s)"; docker exec "$(docker compose ps -q imgproxy)" sh -c 'grep -E "^(anon|file) " /sys/fs/cgroup/memory.stat | tr "\n" " "; cat /sys/fs/cgroup/memory.peak' 2>/dev/null || echo; sleep 1; done > "$O/img16.mem" 2>&1 ) & IMGMON=$!
while read -r u; do curl -s -o /dev/null -w '%{http_code} %{time_total} %{size_download}\n' "$u" & done < "$O/img16.urls" > "$O/img16.curl"; for p in $(jobs -p); do [ "$p" = "$IMGMON" ] || [ "$p" = "$SAMPLER" ] || wait "$p"; done
sleep 3; kill $IMGMON; stamp "img16 end"

# 5. Varnish, Redis, Postgres connections and timing.
docker compose exec -T varnish varnishstat -1 -f 'SMA.*' -f 'MAIN.n_lru_nuked' -f 'MAIN.cache_hit' -f 'MAIN.cache_miss' -f 'MAIN.n_object' > "$O/varnishstat.txt" 2>&1
docker compose exec -T redis redis-cli INFO memory > "$O/redis-memory.txt" 2>&1
docker compose exec -T redis redis-cli INFO persistence | grep -E 'rdb_last_cow_size|aof_last_cow_size' >> "$O/redis-memory.txt" 2>&1
docker compose exec -T redis redis-cli --scan --count 1000 > "$O/redis-keys.txt" 2>&1
head -200 "$O/redis-keys.txt" | while read -r k; do printf '%s %s\n' "$(docker compose exec -T redis redis-cli MEMORY USAGE "$k")" "$k"; done > "$O/redis-key-usage.txt" 2>&1
docker compose exec -T postgres pg_test_timing -d 3 > "$O/pg_test_timing.txt" 2>&1
kill $SAMPLER
stamp "done"

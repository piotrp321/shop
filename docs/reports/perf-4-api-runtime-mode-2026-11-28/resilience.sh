#!/bin/sh
# resilience.sh <variant>: 16 parallel product requests warm the threads, then every backend of the shop role is
# terminated (as a Postgres restart or idle_session_timeout would), then 64 more requests; prints the status codes.
V=$1
burst() { docker run --rm --network perf4_default --entrypoint sh curlimages/curl:8.11.1 -c "for i in \$(seq 1 $1); do curl -s -o /dev/null -w '%{http_code}\n' http://proto-$V/api/v1/products/baton-energetyczny-lumora-daktylowy & done; wait" | sort | uniq -c | tr '\n' ' '; }
echo "$V warm: $(burst 32)"
docker exec perf4-postgres-1 psql -U postgres -d shop -Atc "select count(pg_terminate_backend(pid)) from pg_stat_activity where datname='shop' and usename='shop' and backend_type='client backend'" | sed "s/^/$V terminated: /"
echo "$V after: $(burst 64)"
echo "$V after2: $(burst 64)"

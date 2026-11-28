#!/bin/sh
# PERF-7-1, P8: the cost of the writes on the COPY shop_proto in four states, each measured twice (write-cost.sql):
# S0 without the prototype indexes, S1 + K1 (two btree on catalog_product), S2 + F2 (GIN of bigrams of the name), S3 + Z1 (GIN of bigrams of
# the search expressions of the orders and the customers). The indexes of an earlier run are dropped first. Output: DIR/<state>.<a|b>.txt
# and DIR/indexes-<variant>.txt (the build time and the size). Usage: write-cost-run.sh DIR
set -eu
here="$(cd "$(dirname "$0")" && pwd)"
out="${1:?usage: write-cost-run.sh DIR}"
c="${PG_CONTAINER:-perf7-postgres-1}"
mkdir -p "$out"
sql() { docker exec -i "$c" psql -U postgres -d shop_proto -At -v ON_ERROR_STOP=1 -c "$1" > /dev/null; }
sql "DROP INDEX IF EXISTS proto_k1_name, proto_k1_updated, proto_f2_name_bigrams, proto_z1_orders, proto_z1_customer_email, proto_z1_customer_name"
measure() { # state
  for pass in a b; do
    docker exec -i "$c" psql -U postgres -d shop_proto -At -v label="$1.$pass" < "$here/write-cost.sql" > "$out/$1.$pass.txt" 2>&1
    grep NOTICE "$out/$1.$pass.txt" | cut -c1-200
  done
}
measure S0-base
sh "$here/prototype-indexes.sh" K1 | tee "$out/indexes-K1.txt"
measure S1-K1
sh "$here/prototype-indexes.sh" F2 | tee "$out/indexes-F2.txt"
measure S2-K1-F2
sh "$here/prototype-indexes.sh" Z1 | tee "$out/indexes-Z1.txt"
measure S3-K1-F2-Z1

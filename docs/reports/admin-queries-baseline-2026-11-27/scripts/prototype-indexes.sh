#!/bin/sh
# PERF-7-1: creates the indexes of the prototype variants on the COPY shop_proto of the database, one step at a time, and prints the
# build time and the size of each (the builds are not CONCURRENTLY: a prototype). Usage: prototype-indexes.sh K1|F2|Z1
# The function perf_bigrams(text) is the one of F2 and Z1: the distinct bigrams (2-character substrings) of the lowered text, as an array.
set -eu
c="${PG_CONTAINER:-perf7-postgres-1}"
sql() { docker exec -i "$c" psql -U postgres -d shop_proto -At -v ON_ERROR_STOP=1 -c "$1"; }
build() { # name, definition
  start=$(python3 -c 'import time; print(time.time())')
  sql "$2" > /dev/null
  end=$(python3 -c 'import time; print(time.time())')
  size=$(sql "select pg_size_pretty(pg_relation_size('$1')), pg_relation_size('$1')")
  echo "$1: built in $(python3 -c "print(round($end - $start, 1))") s, size $size"
}
sql "CREATE OR REPLACE FUNCTION perf_bigrams(text) RETURNS text[] LANGUAGE sql IMMUTABLE PARALLEL SAFE AS \$f\$ SELECT coalesce(array_agg(DISTINCT substr(lower(\$1), i, 2)), '{}') FROM generate_series(1, greatest(length(\$1) - 1, 0)) i \$f\$" > /dev/null
case "$1" in
  K1)
    build proto_k1_name "CREATE INDEX proto_k1_name ON catalog_product (primary_category_id, name, id)"
    build proto_k1_updated "CREATE INDEX proto_k1_updated ON catalog_product (primary_category_id, updated_at, id)"
    ;;
  F2)
    build proto_f2_name_bigrams "CREATE INDEX proto_f2_name_bigrams ON catalog_product USING gin (perf_bigrams(name))"
    ;;
  Z1)
    build proto_z1_orders "CREATE INDEX proto_z1_orders ON ordering_order USING gin (perf_bigrams(lower(number || E'\\x1f' || email || E'\\x1f' || full_name)))"
    build proto_z1_customer_email "CREATE INDEX proto_z1_customer_email ON ordering_customer USING gin (perf_bigrams(lower(email)))"
    build proto_z1_customer_name "CREATE INDEX proto_z1_customer_name ON ordering_customer USING gin (perf_bigrams(lower(full_name)))"
    ;;
  *) echo "usage: prototype-indexes.sh K1|F2|Z1" >&2; exit 2 ;;
esac
sql "ANALYZE catalog_product; ANALYZE ordering_order; ANALYZE ordering_customer;" > /dev/null

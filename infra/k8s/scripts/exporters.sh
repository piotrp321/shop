#!/bin/sh
# Runs the exporters of the databases of the application monitoring in a real Grafana Alloy (docs/features/runtime-config/spec.md, D7),
# twice: with Postgres and Elasticsearch NOT there (the path of the error), and with a real Postgres (the series). The config is read from
# argocd/apps/monitoring.yaml (collectors.alloy.extraConfig, never copied), with two substitutions that only take the cluster out of it:
# the Secret shop-exporter (remote.kubernetes.secret) becomes a file with the DSN (local.file, is_secret, the same type of value) and
# the receiver of the metrics grafana_metrics is a receiver of remote write on the Docker network (tests/exporters/sink.py). A third
# component, the metrics of Alloy itself with its own scrape, stands for "the other metrics".
#
# 1. No databases: the DSN and the address of Elasticsearch name hosts that are not on the network.
#      <outdir>/exporters-sink.jsonl   one object per series and push: {"labels": {...}, "value": ...}
#      <outdir>/exporters-alloy.log    the log of Alloy (it must not stop; no password in it)
#      <outdir>/exporters-state.txt    "running" or "exited <code>" of the container after the run
#      <outdir>/exporters-config.alloy the config that ran
# 2. A Postgres of the image of the chart (images.postgres), prepared by the script of the shop (infra/postgres/init/20-monitoring.sh:
#    the role exporter with pg_monitor) and with sessions of different roles, applications, states and waits in the database shopdb.
#    Elasticsearch is not there. The test compares the sessions with the series.
#      <outdir>/exporters-real-sink.jsonl, exporters-real-alloy.log   as above
#      <outdir>/exporters-real-sessions.txt   one line per group of sessions of shopdb (as Postgres sees them): usename|application_name|
#                                             state|wait_event_type|wait_event|count
#
# Usage: exporters.sh <outdir>   (outdir inside TOOLS_CACHE; needs monitoring-render.sh to have run there)
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

out="${1:?usage: exporters.sh <outdir>}"
mkdir -p "$out"
app="$TOOLS_REPO/infra/k8s/argocd/apps/monitoring.yaml"
work="$out/exporters"
rm -rf "$work"
mkdir -p "$work"
suffix="$$"
network="exporters-$suffix"
sink="exporters-sink-$suffix"
alloy="exporters-alloy-$suffix"
network_real="exporters-real-$suffix"
sink_real="exporters-real-sink-$suffix"
alloy_real="exporters-real-alloy-$suffix"
postgres="exporters-postgres-$suffix"
# The password of the DSN: it must not come out of Alloy anywhere (tests/test_monitoring.py looks for it). The real Postgres gets it as
# EXPORTER_DB_PASSWORD, as the shop does.
exporter_password=planted-dsn-password-7
superuser_password=planted-superuser-password-8
role_password=planted-role-password-9
printf 'postgresql://exporter:%s@postgres.shop.svc.cluster.local:5432/postgres?sslmode=disable' "$exporter_password" > "$work/dsn.txt"

cleanup() {
  docker rm -f "$alloy" "$sink" "$alloy_real" "$sink_real" "$postgres" > /dev/null 2>&1 || true
  docker network rm "$network" "$network_real" > /dev/null 2>&1 || true
}
trap cleanup EXIT

yq 'explode(.) | .spec.source.helm.valuesObject.collectors.alloy.extraConfig' < "$app" > "$work/extra.alloy"
for needle in 'remote.kubernetes.secret "shop_exporter" {' 'remote.kubernetes.secret.shop_exporter.data["POSTGRES_EXPORTER_DSN"]' \
  'prometheus.remote_write.grafana_metrics.receiver' 'prometheus.exporter.postgres "shop"' 'prometheus.exporter.elasticsearch "shop"'; do
  grep -qF -- "$needle" "$work/extra.alloy" || { echo "exporters: the extraConfig of $app has no '$needle': this script is out of date" >&2; exit 1; }
done

# config <sink host> <output file>: the config of the manifest with the substitutions, a receiver of remote write and the control metric.
config() {
  {
    # The Secret is a file; the block of the Secret (up to its closing brace in the first column) is left out.
    printf 'local.file "shop_exporter" {\n  filename  = "/work/dsn.txt"\n  is_secret = true\n}\n\n'
    awk '/^remote\.kubernetes\.secret "shop_exporter" \{/ { skip = 1 } !skip { print } skip && /^\}/ { skip = 0 }' "$work/extra.alloy" |
      sed 's/remote\.kubernetes\.secret\.shop_exporter\.data\["POSTGRES_EXPORTER_DSN"\]/local.file.shop_exporter.content/'
    cat <<'ALLOY'

prometheus.remote_write "grafana_metrics" {
  endpoint {
    url = "http://SINK:8080/push"
  }
}

// The other metrics: Alloy itself.
prometheus.exporter.self "control" {}

prometheus.scrape "control" {
  targets         = prometheus.exporter.self.control.targets
  job_name        = "control"
  scrape_interval = "15s"
  scrape_timeout  = "10s"
  forward_to      = [prometheus.relabel.control.receiver]
}

prometheus.relabel "control" {
  rule {
    source_labels = ["__name__"]
    regex         = "alloy_build_info"
    action        = "keep"
  }
  forward_to = [prometheus.remote_write.grafana_metrics.receiver]
}
ALLOY
  } | sed "s/SINK/$1/" > "$2"
}
config "$sink" "$work/config.alloy"
config "$sink_real" "$work/config-real.alloy"
cp "$work/config.alloy" "$out/exporters-config.alloy"

start_sink() {
  : > "$work/$2"
  chmod 666 "$work/$2"
  docker run -d --name "$1" --network "$3" -v "$TOOLS_REPO/infra/k8s/tests/exporters:/sink:ro" -v "$work:/out" "$PYTHON_IMAGE" \
    python -I /sink/sink.py "/out/$2" > /dev/null
}

docker network create "$network" > /dev/null
docker network create "$network_real" > /dev/null
start_sink "$sink" sink.jsonl "$network"
start_sink "$sink_real" sink-real.jsonl "$network_real"

# --- the real Postgres: the image of the chart, prepared as the shop prepares it, with sessions of five groups in shopdb -----------------
postgres_image="$(yq '.images.postgres' < "$TOOLS_REPO/infra/k8s/charts/shop/values.yaml")"
docker run -d --name "$postgres" --network "$network_real" --network-alias postgres.shop.svc.cluster.local \
  -v "$TOOLS_REPO/infra/postgres/init:/init:ro" -e POSTGRES_PASSWORD="$superuser_password" "$postgres_image" \
  -c shared_preload_libraries=pg_stat_statements -c track_io_timing=on > /dev/null
psql_super() {
  docker exec -e PGPASSWORD="$superuser_password" "$postgres" psql -h localhost -U postgres -X -v ON_ERROR_STOP=1 "$@"
}
waited=0
until docker exec "$postgres" pg_isready -h localhost -U postgres > /dev/null 2>&1 && psql_super -tAc 'select 1' > /dev/null 2>&1; do
  waited=$((waited + 1))
  [ "$waited" -lt 90 ] || { echo "exporters: the Postgres of the test did not start in 90 s:" >&2; docker logs "$postgres" 2>&1 | tail -n 10 >&2; exit 1; }
  sleep 1
done
docker exec -e EXPORTER_DB_PASSWORD="$exporter_password" -e PGHOST=localhost -e PGUSER=postgres -e PGPASSWORD="$superuser_password" "$postgres" \
  sh /init/20-monitoring.sh > /dev/null
psql_super -q -c "create role shop login password '$role_password'" -c "create role kc login password '$role_password'" \
  -c "create database shopdb owner shop" > /dev/null
# session <role> <application> <shell command that holds the session>: a psql of that role in shopdb, kept for the run.
session() {
  docker exec -d -e PGPASSWORD="$role_password" -e PGAPPNAME="$2" "$postgres" sh -c "$3 | psql -h localhost -U $1 -d shopdb -q"
}
for _ in 1 2 3; do session shop php "echo 'select pg_sleep(3600);'"; done
session shop worker "echo 'select pg_sleep(3600);'"
session shop idle "(echo 'select 1;'; sleep 3600)"
session shop idletx "(echo 'begin;'; echo 'select 1;'; sleep 3600)"
for _ in 1 2; do session kc keycloak "echo 'select pg_sleep(3600);'"; done
sessions_query="select usename, application_name, state, coalesce(wait_event_type, ''), coalesce(wait_event, ''), count(*) from pg_stat_activity \
where datname = 'shopdb' and backend_type = 'client backend' group by 1, 2, 3, 4, 5 order by 1, 2, 3, 4, 5"
waited=0
until [ "$(psql_super -tAc "select count(*) from pg_stat_activity where datname = 'shopdb' and backend_type = 'client backend'" 2> /dev/null)" = 8 ]; do
  waited=$((waited + 1))
  [ "$waited" -lt 60 ] || { echo "exporters: the eight sessions of the test did not start in 60 s" >&2; exit 1; }
  sleep 1
done
# The waits settle when the sessions are in their sleep or their read.
sleep 2
psql_super -tA -c "$sessions_query" > "$out/exporters-real-sessions.txt"

docker run -d --name "$alloy" --network "$network" -v "$work:/work:ro" "$ALLOY_IMAGE" \
  run --storage.path=/tmp/alloy /work/config.alloy > /dev/null
docker run -d --name "$alloy_real" --network "$network_real" -v "$work:/work:ro" "$ALLOY_IMAGE" \
  run --storage.path=/tmp/alloy /work/config-real.alloy > /dev/null

# Done when the control metric and both targets (up) have arrived at the receiver, or after 150 s.
waited=0
while [ "$waited" -lt 150 ]; do
  for container in "$alloy" "$alloy_real"; do
    if [ "$(docker inspect -f '{{.State.Running}}' "$container")" != true ]; then
      echo "exporters: Alloy ($container) has stopped (a database that is not there must not stop it, and a real one neither):" >&2
      docker logs "$container" 2>&1 | tail -n 15 >&2
      exit 1
    fi
  done
  if grep -q '"alloy_build_info"' "$work/sink.jsonl" && grep -q '"job": "integrations/postgres"' "$work/sink.jsonl" &&
    grep -q '"job": "integrations/elasticsearch"' "$work/sink.jsonl" && grep -q '"pg_stat_activity_count"' "$work/sink-real.jsonl"; then
    break
  fi
  sleep 1
  waited=$((waited + 1))
done
# One more interval of the exporters, so that the later pushes are in the file too.
sleep 5
docker logs "$alloy" > "$out/exporters-alloy.log" 2>&1 || true
docker logs "$alloy_real" > "$out/exporters-real-alloy.log" 2>&1 || true
if [ "$(docker inspect -f '{{.State.Running}}' "$alloy")" = true ]; then
  echo running > "$out/exporters-state.txt"
else
  echo "exited $(docker inspect -f '{{.State.ExitCode}}' "$alloy")" > "$out/exporters-state.txt"
fi
cp "$work/sink.jsonl" "$out/exporters-sink.jsonl"
cp "$work/sink-real.jsonl" "$out/exporters-real-sink.jsonl"

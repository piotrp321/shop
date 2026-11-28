#!/bin/sh
# Runs the exporters of the databases of the application monitoring in a real Grafana Alloy, with Postgres and Elasticsearch NOT
# there (docs/features/runtime-config/spec.md, D7; the path of the error): the config is read from argocd/apps/monitoring.yaml
# (collectors.alloy.extraConfig, never copied), with two substitutions that only take the cluster out of it: the Secret
# shop-exporter (remote.kubernetes.secret) becomes a file with the DSN (local.file, is_secret, the same type of value) and the receiver
# of the metrics grafana_metrics is a receiver of remote write on the Docker network (tests/exporters/sink.py). A third component, the
# metrics of Alloy itself with its own scrape, stands for "the other metrics". The databases do not exist: the DSN and the address
# of Elasticsearch name hosts that are not on the network. The test reads the result:
#
#   <outdir>/exporters-sink.jsonl   one object per series and push: {"labels": {...}, "value": ...}
#   <outdir>/exporters-alloy.log    the log of Alloy (it must not stop; no password in it)
#   <outdir>/exporters-state.txt    "running" or "exited <code>" of the container after the run
#   <outdir>/exporters-config.alloy the config that ran
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
# The password of the DSN: it must not come out of Alloy anywhere (tests/test_monitoring.py looks for it).
printf 'postgresql://exporter:planted-dsn-password-7@postgres.shop.svc.cluster.local:5432/postgres?sslmode=disable' > "$work/dsn.txt"

cleanup() {
  docker rm -f "$alloy" "$sink" > /dev/null 2>&1 || true
  docker network rm "$network" > /dev/null 2>&1 || true
}
trap cleanup EXIT

yq 'explode(.) | .spec.source.helm.valuesObject.collectors.alloy.extraConfig' < "$app" > "$work/extra.alloy"
for needle in 'remote.kubernetes.secret "shop_exporter" {' 'remote.kubernetes.secret.shop_exporter.data["POSTGRES_EXPORTER_DSN"]' \
  'prometheus.remote_write.grafana_metrics.receiver' 'prometheus.exporter.postgres "shop"' 'prometheus.exporter.elasticsearch "shop"'; do
  grep -qF -- "$needle" "$work/extra.alloy" || { echo "exporters: the extraConfig of $app has no '$needle': this script is out of date" >&2; exit 1; }
done
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
} | sed "s/SINK/$sink/" > "$work/config.alloy"
cp "$work/config.alloy" "$out/exporters-config.alloy"

docker network create "$network" > /dev/null
: > "$work/sink.jsonl"
chmod 666 "$work/sink.jsonl"
docker run -d --name "$sink" --network "$network" -v "$TOOLS_REPO/infra/k8s/tests/exporters:/sink:ro" -v "$work:/out" "$PYTHON_IMAGE" \
  python -I /sink/sink.py /out/sink.jsonl > /dev/null
docker run -d --name "$alloy" --network "$network" -v "$work:/work:ro" "$ALLOY_IMAGE" \
  run --storage.path=/tmp/alloy /work/config.alloy > /dev/null

# Done when the control metric and both targets (up) have arrived at the receiver, or after 150 s.
waited=0
while [ "$waited" -lt 150 ]; do
  if [ "$(docker inspect -f '{{.State.Running}}' "$alloy")" != true ]; then
    echo "exporters: Alloy has stopped (the databases are not there, and it must not):" >&2
    docker logs "$alloy" 2>&1 | tail -n 15 >&2
    exit 1
  fi
  if grep -q '"alloy_build_info"' "$work/sink.jsonl" && grep -q '"job": "integrations/postgres"' "$work/sink.jsonl" &&
    grep -q '"job": "integrations/elasticsearch"' "$work/sink.jsonl"; then
    break
  fi
  sleep 1
  waited=$((waited + 1))
done
# One more interval of the exporters, so that the later pushes are in the file too.
sleep 5
docker logs "$alloy" > "$out/exporters-alloy.log" 2>&1 || true
if [ "$(docker inspect -f '{{.State.Running}}' "$alloy")" = true ]; then
  echo running > "$out/exporters-state.txt"
else
  echo "exited $(docker inspect -f '{{.State.ExitCode}}' "$alloy")" > "$out/exporters-state.txt"
fi
cp "$work/sink.jsonl" "$out/exporters-sink.jsonl"

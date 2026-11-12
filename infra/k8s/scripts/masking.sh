#!/bin/sh
# Runs the log processing stages of the application monitoring in a real Grafana Alloy (docs/features/deploy-ops/spec.md, O7):
# the stages are read from argocd/apps/monitoring.yaml (extraLogProcessingStages of podLogsViaKubernetesApi for the logs of the pods
# and of clusterEvents for the cluster events, never copied), the input is the corpus tests/log-corpus (a file per source, in the
# format of that source, with the secrets and personal data of customers planted in it), the output goes through loki.echo.
# The corpus goes through both pipelines: the one of the pods (CRI lines, as the container runtime writes them, read by stage.cri
# before the stages, as the chart does) and the one of the events. The rendered Alloy configuration of the chart is validated
# by the same Alloy. tests/test_log_masking.py reads the result.
#
#   <outdir>/masking-output.log        the lines of loki.echo (component loki.echo.pods and loki.echo.events)
#   <outdir>/masking-stages-pods.alloy, masking-stages-events.alloy   the stages as read from the manifest
#
# Usage: masking.sh <outdir>   (outdir inside TOOLS_CACHE; needs monitoring-render.sh to have run there)
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

out="${1:?usage: masking.sh <outdir>}"
mkdir -p "$out"
app="$TOOLS_REPO/infra/k8s/argocd/apps/monitoring.yaml"
corpus="$TOOLS_REPO/infra/k8s/tests/log-corpus"
work="$out/masking"
rm -rf "$work"
mkdir -p "$work"

# stages <path in the values>: the text of the stages ("null" is none: a manifest without stages must give the test a failure
# to report, not a configuration error).
stages() {
  # explode: the stages of the events are an alias of the block of the pods.
  text="$(yq "explode(.) | .spec.source.helm.valuesObject.$1.extraLogProcessingStages" < "$app")"
  [ "$text" = null ] || printf '%s\n' "$text"
}
stages podLogsViaKubernetesApi > "$out/masking-stages-pods.alloy"
stages clusterEvents > "$out/masking-stages-events.alloy"

sources=""
for file in "$corpus"/*.log; do
  source_name="$(basename "$file" .log)"
  sources="$sources $source_name"
  # The sentinel is the last line of every file: the run is over when both pipelines have printed it for every source.
  { cat "$file"; echo "END-OF-CORPUS $source_name"; } > "$work/$source_name.log"
  sed 's/^/2026-11-11T10:00:00.000000000Z stdout F /' "$work/$source_name.log" > "$work/pods-$source_name.log"
done

{
  for pipeline in pods events; do
    printf 'loki.source.file "%s" {\n  targets = [\n' "$pipeline"
    for source_name in $sources; do
      if [ "$pipeline" = pods ]; then path="pods-$source_name.log"; else path="$source_name.log"; fi
      printf '    {"__path__" = "/work/%s", "source" = "%s"},\n' "$path" "$source_name"
    done
    printf '  ]\n  forward_to = [loki.process.%s.receiver]\n}\n\n' "$pipeline"
    printf 'loki.process "%s" {\n' "$pipeline"
    [ "$pipeline" != pods ] || printf '  stage.cri {}\n'
    sed 's/^/  /' "$out/masking-stages-$pipeline.alloy"
    printf '  forward_to = [loki.echo.%s.receiver]\n}\n\nloki.echo "%s" {}\n\n' "$pipeline" "$pipeline"
  done
} > "$work/config.alloy"

# The rendered configuration of the chart must be valid Alloy (the stages are inside it).
cp "$out/monitoring-config.alloy" "$work/rendered.alloy"
if ! validation="$(docker run --rm -v "$work:/work:ro" "$ALLOY_IMAGE" validate /work/rendered.alloy 2>&1)"; then
  echo "masking: Alloy rejects the rendered configuration of the application monitoring:" >&2
  echo "$validation" >&2
  exit 1
fi

name="alloy-masking-$$"
trap 'docker rm -f "$name" > /dev/null 2>&1 || true' EXIT
docker run -d --name "$name" -v "$work:/work:ro" "$ALLOY_IMAGE" run --storage.path=/tmp/alloy /work/config.alloy > /dev/null
expected=0
for _ in $sources; do expected=$((expected + 2)); done
waited=0
while [ "$waited" -lt 120 ]; do
  docker logs "$name" > "$work/alloy.log" 2>&1 || true
  seen="$(grep -c 'END-OF-CORPUS' "$work/alloy.log" || true)"
  [ "$seen" -ge "$expected" ] && break
  if grep -q 'level=error' "$work/alloy.log" || [ "$(docker inspect -f '{{.State.Running}}' "$name")" != true ]; then
    echo "masking: Alloy reports an error or has stopped:" >&2
    grep -E 'level=error|^Error' "$work/alloy.log" >&2 || tail -n 10 "$work/alloy.log" >&2
    exit 1
  fi
  sleep 1
  waited=$((waited + 1))
done
if [ "$seen" -lt "$expected" ]; then
  echo "masking: Alloy printed the end of $seen of $expected inputs in 120 s:" >&2
  tail -n 20 "$work/alloy.log" >&2
  exit 1
fi
grep 'component_id=loki.echo' "$work/alloy.log" > "$out/masking-output.log"

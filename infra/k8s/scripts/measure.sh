#!/bin/sh
# Measures the catalog path of the deployed shop from inside the cluster, GET only (docs/features/performance-program/plan.md,
# PERF-0: W1-W4, W8 and the rise of the rate towards W9): make -C infra/k8s measure KUBE_DIR=<directory> MEASURE_OUT=<directory>.
# Only after the consent of the user in the conversation that names the operation and the host.
#
# It runs load/baseline.py (the tool of PERF-0) in a Job of the namespace, in the image python of tools.sh, and reads the shop
# through the Services varnish (the API) and store, so the shop can stay closed: the rule shop-closed lets the pods of the namespace
# in, while Traefik and Cloudflare stay out. The measurement therefore has no network of the Internet and no ingress in it; the
# times are those of the server. It writes nothing to the shop (GET only; the data of the demo is not touched), changes no
# configuration and no resource limit, and the Job is deleted at the end (the pod needs 256 Mi, the curl is installed by apk).
#
# Stages (every one is written to MEASURE_OUT as <stage>.jsonl and <stage>.txt, the report of baseline.py; the log of the Job is
# measure.log; top.log: kubectl top of the pods and the node every 15 s, the pod of the measurement included): single (every address once cold, at once again warm), then open at the rates of MEASURE_RATES (product pages) and
# MEASURE_LISTING_RATES (listings leaf and leaf_filter), MEASURE_STEP_SECONDS each (a new list of addresses for each step), then burst of MEASURE_BURST addresses released together, three rounds.
#
#   KUBE_DIR (required)       the directory with the kubeconfig (file 'config') of the cluster; KUBE_NAMESPACE=shop
#   MEASURE_OUT (required)    an existing directory outside the repository for the results
#   MEASURE_RATES="2 5 10 20 40"   requests per second of the open steps of the product pages (an address is used once)
#   MEASURE_LISTING_RATES="1 2 4 8 16"   the same for the listings of the kinds leaf and leaf_filter
#   MEASURE_STAGES="single open burst"   the stages to run (a subset, e.g. "open" for the rise of the rate alone)
#   MEASURE_STEP_SECONDS=60   the length of an open step
#   MEASURE_BURST=100         the addresses of a burst (W8)
#   MEASURE_LISTINGS=300 MEASURE_PRODUCTS=150   the size of the list of the single stage
#   MEASURE_TIMEOUT=7200      seconds to wait for the Job
#
# Refuses, without any change, while a Job other than the CronJobs rate-limit-check, queue-check and order-expiry is active in the
# namespace (the generator, a reindex), or when the Services cannot be read. The word tak is asked for first.
# Exit code: 0 the measurement ran, 1 it failed or was refused, 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"
. "$here/tools.sh"
. "$here/shop-closed.sh"

if [ -z "${KUBE_DIR:-}" ] || [ -z "${MEASURE_OUT:-}" ]; then
  echo "measure: set KUBE_DIR (the kubeconfig of the cluster) and MEASURE_OUT (an existing directory outside the repository)" >&2
  exit 2
fi
[ -f "$KUBE_DIR/config" ] || { echo "measure: no kubeconfig at $KUBE_DIR/config" >&2; exit 1; }
[ -f "$repo/load/baseline.py" ] || { echo "measure: no $repo/load/baseline.py" >&2; exit 1; }
[ -d "$MEASURE_OUT" ] || { echo "measure: MEASURE_OUT is not a directory: $MEASURE_OUT" >&2; exit 2; }
case "$(cd "$MEASURE_OUT" && pwd)/" in
  "$repo"/*) echo "measure: MEASURE_OUT must be outside the repository" >&2; exit 2 ;;
esac
KUBE_NETWORK="${KUBE_NETWORK:-host}"
namespace="${KUBE_NAMESPACE:-shop}"
rates="${MEASURE_RATES:-2 5 10 20 40}"
listing_rates="${MEASURE_LISTING_RATES:-1 2 4 8 16}"
stages="${MEASURE_STAGES:-single open burst}"
has() { case " $stages " in *" $1 "*) return 0 ;; *) return 1 ;; esac; }
step="${MEASURE_STEP_SECONDS:-60}"
burst="${MEASURE_BURST:-100}"
listings="${MEASURE_LISTINGS:-300}"
products="${MEASURE_PRODUCTS:-150}"
timeout="${MEASURE_TIMEOUT:-7200}"
for n in $step $burst $listings $products $timeout; do
  case "$n" in "" | *[!0-9]* | 0*) echo "measure: not a positive number: $n" >&2; exit 2 ;; esac
done
for r in $rates $listing_rates; do
  case "$r" in "" | *[!0-9]* | 0*) echo "measure: a rate is not a positive number: $r" >&2; exit 2 ;; esac
done
job="perf-measure-$(date -u +%Y%m%d%H%M%S)"
config="$job-script"

active="$(active_jobs < /dev/null)" || { echo "measure: cannot read the Jobs of the namespace $namespace; nothing changed" >&2; exit 1; }
if [ -n "$active" ]; then
  echo "measure: Jobs are active in the namespace $namespace: $active; nothing changed" >&2
  exit 1
fi
for svc in varnish store; do
  kc get service "$svc" > /dev/null < /dev/null || { echo "measure: no Service $svc in the namespace $namespace; nothing changed" >&2; exit 1; }
done

cat <<EOF
measure: in the namespace $namespace of the cluster of $KUBE_DIR/config:
  - a Job $job runs load/baseline.py (GET only) against http://varnish and http://store:3000 inside the cluster;
  - stages ($stages): single ($listings listings, $products products, cold then warm), open at $rates req/s (product pages) and $listing_rates req/s (listings), $step s each, burst of $burst x 3;
  - no data, configuration or resource limit changes; the shop stays as it is (closed or open); the Job and its ConfigMap are deleted at the end;
  - results in $MEASURE_OUT.
EOF
printf 'measure: type tak to go on: '
answer=""
read -r answer || true
[ "$answer" = tak ] || { echo "measure: nothing changed"; exit 1; }

cleanup() {
  status=$?
  trap '' HUP INT TERM
  trap - EXIT
  kc delete job "$job" --ignore-not-found --wait=false > /dev/null 2>&1 < /dev/null || true
  kc delete configmap "$config" --ignore-not-found > /dev/null 2>&1 < /dev/null || true
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

script="$MEASURE_OUT/measure-job.sh"
{
  echo 'set -u'
  echo 'apk add --no-cache curl > /dev/null'
  echo 'B="python3 /m/baseline.py"; API=http://varnish; STORE=http://store:3000'
  echo "mkdir -p /w; cd /w"
  if has single; then
    echo "echo '##BEGIN single'"
    echo "\$B urls \$API \$STORE single-urls.json --listings $listings --products $products || true"
    echo "\$B run single-urls.json single.jsonl --mode single || true"
    echo "\$B report single.jsonl || true"
    echo "echo '##JSONL single'; cat single.jsonl; echo '##END single'"
  fi
  # The open steps run per group of kinds: the kinds that fail in every request would stop (20% failures) the steps of the others.
  if has open; then
    for group in product leaf,leaf_filter; do
      if [ "$group" = product ]; then group_rates="$rates"; per=1; else group_rates="$listing_rates"; per=3; fi
      for r in $group_rates; do
        count=$((r * step))
        name="open-${group%%,*}-$r"
        echo "echo '##BEGIN $name'"
        echo "\$B urls \$API \$STORE $name-urls.json --listings $((count * per)) --products $count"
        echo "\$B run $name-urls.json $name.jsonl --mode open --rate $r --duration $step --kinds $group || true"
        echo "\$B report $name.jsonl || true"
        echo "echo '##JSONL $name'; cat $name.jsonl; echo '##END $name'"
      done
    done
  fi
  if has burst; then
    echo "echo '##BEGIN burst'"
    echo "\$B urls \$API \$STORE burst-urls.json --listings $((burst * 3)) --products $((burst * 3))"
    echo "\$B run burst-urls.json burst.jsonl --mode burst --n $burst --rounds 3 --pause 90 || true"
    echo "\$B report burst.jsonl || true"
    echo "echo '##JSONL burst'; cat burst.jsonl; echo '##END burst'"
  fi
} > "$script"

{
  printf 'apiVersion: v1\nkind: ConfigMap\nmetadata:\n  name: %s\ndata:\n  baseline.py: |\n' "$config"
  sed 's/^/    /' "$repo/load/baseline.py"
  printf '  run.sh: |\n'
  sed 's/^/    /' "$script"
} | kc apply -f - > /dev/null
kc apply -f - > /dev/null <<EOF
apiVersion: batch/v1
kind: Job
metadata:
  name: $job
  labels: {app.kubernetes.io/name: perf-measure}
spec:
  backoffLimit: 0
  activeDeadlineSeconds: $timeout
  template:
    metadata:
      labels: {app.kubernetes.io/name: perf-measure}
    spec:
      restartPolicy: Never
      containers:
        - name: measure
          image: $PYTHON_IMAGE
          command: [sh, /m/run.sh]
          resources:
            requests: {cpu: 250m, memory: 256Mi}
            limits: {memory: 1Gi}
          volumeMounts:
            - {name: m, mountPath: /m}
      volumes:
        - name: m
          configMap: {name: $config}
EOF
echo "measure: Job $job started at $(date -u +%H:%M:%S) UTC"

waited=0
while :; do
  state="$(kc get job "$job" -o jsonpath='{.status.succeeded}/{.status.failed}' 2> /dev/null < /dev/null || echo "?/?")"
  case "$state" in
    1/*) break ;;
    */1 | */2) echo "measure: the Job failed (its log: $MEASURE_OUT/measure.log)" >&2; break ;;
  esac
  [ "$waited" -lt "$timeout" ] || { echo "measure: timeout after $timeout s" >&2; break; }
  # The load of the node and of the pods, the Job of the measurement included (it takes part of the resources of the server).
  { date -u +%H:%M:%S; kc top pod --no-headers; kc top node --no-headers; } >> "$MEASURE_OUT/top.log" 2> /dev/null < /dev/null || true
  sleep 15
  waited=$((waited + 15))
done

kc logs "job/$job" > "$MEASURE_OUT/measure.log" 2>&1 < /dev/null || true
awk -v out="$MEASURE_OUT" '
  /^##BEGIN / { stage = $2; ext = "txt"; next }
  /^##JSONL /  { ext = "jsonl"; next }
  /^##END /    { stage = ""; next }
  stage != ""  { print > (out "/" stage "." ext) }
' "$MEASURE_OUT/measure.log"
grep -q '^##END ' "$MEASURE_OUT/measure.log" || { echo "measure: the Job did not reach the end (log: $MEASURE_OUT/measure.log)" >&2; exit 1; }
echo "measure: done; results in $MEASURE_OUT"

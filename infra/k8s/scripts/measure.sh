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
#   MEASURE_STAGES="single open burst"   the stages to run (a subset, e.g. "open" for the rise of the rate alone); the stage k6 is not
#                             in the default: it runs the ramp of load/saturate.js (k6, in its own Job) on the read mixes of MEASURE_K6_RUNS
#   MEASURE_K6_RUNS="read:home:10,category:20,product:35,search:25 product:product:100"   name:MIX of each ramp (no cart, no checkout:
#                             the stage writes nothing), MEASURE_K6_STEPS=8 MEASURE_K6_START=10 MEASURE_K6_RATE_STEP=10 MEASURE_K6_STEP_SECONDS=30
#                             (iterations per second of the first step, the rise per step), MEASURE_K6_MAX_VUS=300, the CPU of the pod 2
#                             Only the kinds home, category, product and search are accepted (cart POSTs): anything else is refused with exit 2
#                             before the cluster is touched.
#   MEASURE_SEED=<unix time>  the seed of the lists of addresses (baseline.py urls --seed); the same seed gives the same addresses, which are then
#                             warm in Varnish from an earlier run, so repeat with a new seed unless the warm path is meant
#   MEASURE_STEP_SECONDS=60   the length of an open step
#   MEASURE_BURST=100         the addresses of a burst (W8)
#   MEASURE_LISTINGS=300 MEASURE_PRODUCTS=150   the size of the list of the single stage
#   MEASURE_TIMEOUT=7200      seconds to wait for the Job
#
# Refuses, without any change, while a Job other than the CronJobs rate-limit-check, queue-check and order-expiry is active in the
# namespace (the generator, a reindex), or when the Services cannot be read. The word tak is asked for first.
# Writes run-parameters.txt (revision, hashes of the tools, images, every variable and the seed) and, per stage, <stage>.urls.json
# (the list of addresses). Exit code: 0 the measurement is complete, 1 it failed, was refused or is incomplete (a Job that failed
# or timed out, a crash of baseline.py or k6, no closing marker; an expected end at saturation is not a failure), 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"
. "$here/tools.sh"
. "$here/shop-closed.sh"
. "$here/measure-lib.sh"

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
k6_runs="${MEASURE_K6_RUNS:-read:home:10,category:20,product:35,search:25 product:product:100}"
k6_steps="${MEASURE_K6_STEPS:-8}"
k6_start="${MEASURE_K6_START:-10}"
k6_rate_step="${MEASURE_K6_RATE_STEP:-10}"
k6_step_seconds="${MEASURE_K6_STEP_SECONDS:-30}"
k6_max_vus="${MEASURE_K6_MAX_VUS:-300}"
has() { case " $stages " in *" $1 "*) return 0 ;; *) return 1 ;; esac; }
step="${MEASURE_STEP_SECONDS:-60}"
burst="${MEASURE_BURST:-100}"
listings="${MEASURE_LISTINGS:-300}"
products="${MEASURE_PRODUCTS:-150}"
timeout="${MEASURE_TIMEOUT:-7200}"
for n in $step $burst $listings $products $timeout $k6_steps $k6_start $k6_rate_step $k6_step_seconds $k6_max_vus; do
  case "$n" in "" | *[!0-9]* | 0*) echo "measure: not a positive number: $n" >&2; exit 2 ;; esac
done
for r in $rates $listing_rates; do
  case "$r" in "" | *[!0-9]* | 0*) echo "measure: a rate is not a positive number: $r" >&2; exit 2 ;; esac
done
seed="${MEASURE_SEED:-$(date -u +%s)}"
case "$seed" in "" | *[!0-9]*) echo "measure: MEASURE_SEED is not a number: $seed" >&2; exit 2 ;; esac
# Before anything is created in the cluster: only the scenarios that read (GET) may run, whatever the variable says.
if has k6; then k6_runs_valid "$k6_runs" || exit 2; fi
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

k6_image=
if has k6; then
  # shellcheck disable=SC2016 # the pattern is for sed, not for the shell
  k6_image="$(sed -n 's/^K6_IMAGE="${K6_IMAGE:-\(.*\)}"$/\1/p' "$repo/load/k6.sh")"
  [ -n "$k6_image" ] || { echo "measure: no K6_IMAGE in load/k6.sh" >&2; exit 1; }
fi
# What a repeat needs: the revision and hashes of the tools, the images, every variable and the seed (the lists of addresses are
# written per stage as <stage>.urls.json, the meta file of every run as <stage>.jsonl.meta.json).
params="$MEASURE_OUT/run-parameters.txt"
{
  echo "started=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "job=$job namespace=$namespace"
  echo "revision=$(git -C "$repo" rev-parse HEAD 2> /dev/null || echo unknown)"
  echo "dirty_tools=$(git -C "$repo" status --porcelain -- load infra/k8s/scripts 2> /dev/null | wc -l | tr -d ' ') (files of load/ or infra/k8s/scripts changed against the revision)"
  for f in load/baseline.py load/saturate.js load/lib/config.js infra/k8s/scripts/measure.sh infra/k8s/scripts/measure-lib.sh; do
    echo "sha1 $f=$(git -C "$repo" hash-object "$f" 2> /dev/null || echo unknown)"
  done
  echo "python_image=$PYTHON_IMAGE"
  echo "stages=$stages seed=$seed"
  echo "rates=$rates listing_rates=$listing_rates step_seconds=$step burst=$burst listings=$listings products=$products timeout=$timeout"
  echo "k6_runs=$k6_runs k6_steps=$k6_steps k6_start=$k6_start k6_rate_step=$k6_rate_step k6_step_seconds=$k6_step_seconds k6_max_vus=$k6_max_vus"
  if has k6; then echo "k6_image=$k6_image"; fi
} > "$params"

cleanup() {
  status=$?
  trap '' HUP INT TERM
  trap - EXIT
  kc delete job "$job" "$job-k6" --ignore-not-found --wait=false > /dev/null 2>&1 < /dev/null || true
  kc delete configmap "$config" "$config-k6" --ignore-not-found > /dev/null 2>&1 < /dev/null || true
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

script="$MEASURE_OUT/measure-job.sh"
{
  echo 'set -u'
  echo '. /m/lib.sh'
  echo 'apk add --no-cache curl > /dev/null'
  echo 'B="python3 /m/baseline.py"; API=http://varnish; STORE=http://store:3000'
  echo "mkdir -p /w; cd /w"
  if has single; then
    echo "echo '##BEGIN single'"
    echo "\$B urls \$API \$STORE single-urls.json --listings $listings --products $products --seed $seed || true"
    echo "need_file single single-urls.json"
    echo "echo '##URLS single'; cat single-urls.json"
    echo "\$B run single-urls.json single.jsonl --mode single; baseline_outcome single \$? single.jsonl.meta.json"
    echo "\$B report single.jsonl || true"
    echo "echo '##META single'; cat single.jsonl.meta.json 2>/dev/null; echo; echo '##JSONL single'; cat single.jsonl; echo '##END single'"
  fi
  # The open steps run per group of kinds: the kinds that fail in every request would stop (20% failures) the steps of the others.
  if has open; then
    for group in product leaf,leaf_filter; do
      if [ "$group" = product ]; then group_rates="$rates"; per=1; else group_rates="$listing_rates"; per=3; fi
      for r in $group_rates; do
        count=$((r * step))
        name="open-${group%%,*}-$r"
        echo "echo '##BEGIN $name'"
        echo "\$B urls \$API \$STORE $name-urls.json --listings $((count * per)) --products $count --seed $seed || true"
        echo "need_file $name $name-urls.json"
        echo "echo '##URLS $name'; cat $name-urls.json"
        echo "\$B run $name-urls.json $name.jsonl --mode open --rate $r --duration $step --kinds $group; baseline_outcome $name \$? $name.jsonl.meta.json"
        echo "\$B report $name.jsonl || true"
        echo "echo '##META $name'; cat $name.jsonl.meta.json 2>/dev/null; echo; echo '##JSONL $name'; cat $name.jsonl; echo '##END $name'"
      done
    done
  fi
  if has burst; then
    echo "echo '##BEGIN burst'"
    echo "\$B urls \$API \$STORE burst-urls.json --listings $((burst * 3)) --products $((burst * 3)) --seed $seed || true"
    echo "need_file burst burst-urls.json"
    echo "echo '##URLS burst'; cat burst-urls.json"
    echo "\$B run burst-urls.json burst.jsonl --mode burst --n $burst --rounds 3 --pause 90; baseline_outcome burst \$? burst.jsonl.meta.json"
    echo "\$B report burst.jsonl || true"
    echo "echo '##META burst'; cat burst.jsonl.meta.json 2>/dev/null; echo; echo '##JSONL burst'; cat burst.jsonl; echo '##END burst'"
  fi
  echo "echo '##DONE'"
} > "$script"

# wait_job <job> <log name>: waits for the Job, sampling the load of the node and pods every 15 s, and saves its log. The
# measurement is complete only when the Job succeeded and its log has the closing marker ##DONE and no ##FAILED line
# (log_problem); a failed or timed out Job, a crash of baseline.py or k6 and a log cut short set failed_job. The end of a k6 ramp at
# saturation (exit 99) and baseline.py with failed requests (exit 1 with its meta file) are results, not failures.
wait_job() {
  waited=0
  outcome=
  while :; do
    state="$(kc get job "$1" -o jsonpath='{.status.succeeded}/{.status.failed}' 2> /dev/null < /dev/null || echo "?/?")"
    case "$state" in
      1/*) outcome=succeeded; break ;;
      */1 | */2) outcome=failed; break ;;
    esac
    [ "$waited" -lt "$timeout" ] || { outcome=timeout; break; }
    # The load of the node and of the pods, the Job of the measurement included (it takes part of the resources of the server).
    { date -u +%H:%M:%S; kc top pod --no-headers; kc top node --no-headers; } >> "$MEASURE_OUT/top.log" 2> /dev/null < /dev/null || true
    sleep 15
    waited=$((waited + 15))
  done
  kc logs "job/$1" > "$MEASURE_OUT/$2.log" 2>&1 < /dev/null || true
  awk -v out="$MEASURE_OUT" '
    /^##BEGIN / { stage = $2; ext = "txt"; next }
    /^##URLS /   { ext = "urls.json"; next }
    /^##META /   { ext = "meta.json"; next }
    /^##JSONL /  { ext = "jsonl"; next }
    /^##END /    { stage = ""; next }
    stage != ""  { print > (out "/" stage "." ext) }
  ' "$MEASURE_OUT/$2.log"
  if [ "$outcome" != succeeded ]; then
    echo "measure: the Job $1 ended as '$outcome', the measurement is incomplete (log: $MEASURE_OUT/$2.log)" >&2
    failed_job=1
  fi
  if problem="$(log_problem "$MEASURE_OUT/$2.log")"; then
    :
  else
    echo "measure: the measurement of $1 is incomplete: $problem (log: $MEASURE_OUT/$2.log)" >&2
    failed_job=1
  fi
}
failed_job=0

if has single || has open || has burst; then
  {
    printf 'apiVersion: v1\nkind: ConfigMap\nmetadata:\n  name: %s\ndata:\n  baseline.py: |\n' "$config"
    sed 's/^/    /' "$repo/load/baseline.py"
    printf '  run.sh: |\n'
    sed 's/^/    /' "$script"
    printf '  lib.sh: |\n'
    sed 's/^/    /' "$here/measure-lib.sh"
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
  wait_job "$job" measure
fi

if has k6; then
  k6_script="$MEASURE_OUT/measure-k6-job.sh"
  {
    echo '. /m/lib.sh'
    echo 'mkdir -p /tmp/l && cd /tmp/l && tar xzf /m/load.tgz && cd load'
    for run in $k6_runs; do
      name="${run%%:*}"
      mix="${run#*:}"
      echo "echo '##BEGIN k6-$name'"
      echo "rm -f /out/ramp.json /out/steady-rate"
      # No pipe: the exit code of k6 is kept (a pipe into tail would hide it); the end of the output is printed afterwards.
      echo "MIX='$mix' RAMP_START_RATE=$k6_start RAMP_RATE_STEP=$k6_rate_step RAMP_STEPS=$k6_steps RAMP_STEP_SECONDS=$k6_step_seconds RAMP_VUS=50 RAMP_MAX_VUS=$k6_max_vus \\"
      echo "  STORE_URL=http://store:3000 API_URL=http://varnish k6 run --quiet saturate.js > /tmp/k6.out 2>&1"
      echo "rc=\$?; tail -n 60 /tmp/k6.out; k6_outcome $name \$rc /out/ramp.json"
      echo "echo '##JSONL k6-$name'; cat /out/ramp.json 2>&1; echo; echo '##END k6-$name'"
    done
    echo "echo '##DONE'"
  } > "$k6_script"
  {
    printf 'apiVersion: v1\nkind: ConfigMap\nmetadata:\n  name: %s-k6\ndata:\n  run.sh: |\n' "$config"
    sed 's/^/    /' "$k6_script"
    printf '  lib.sh: |\n'
    sed 's/^/    /' "$here/measure-lib.sh"
    printf 'binaryData:\n  load.tgz: %s\n' "$(tar czf - -C "$repo" load/lib load/saturate.js | base64 | tr -d '\n')"
  } | kc apply -f - > /dev/null
  kc apply -f - > /dev/null <<EOF
apiVersion: batch/v1
kind: Job
metadata:
  name: $job-k6
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
        - name: k6
          image: $k6_image
          command: [sh, /m/run.sh]
          resources:
            requests: {cpu: 500m, memory: 512Mi}
            limits: {cpu: "2", memory: 1Gi}
          volumeMounts:
            - {name: m, mountPath: /m}
            - {name: out, mountPath: /out}
      volumes:
        - name: m
          configMap: {name: $config-k6}
        - name: out
          emptyDir: {}
EOF
  echo "measure: Job $job-k6 started at $(date -u +%H:%M:%S) UTC"
  wait_job "$job-k6" measure-k6
fi

[ "$failed_job" = 0 ] || exit 1
echo "measure: done; results in $MEASURE_OUT"

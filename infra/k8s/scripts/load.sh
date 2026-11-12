#!/bin/sh
# The load measurement (make -C infra/k8s load, docs/features/hardening/spec.md "Testy obciążeniowe", D15): the chart with
# the profile `load` (ci with the resources of prod and the header of the client address, environments/load) on an empty
# k3d cluster with the production images built here, the demo data, the stocks raised, a warm-up, the ramp to saturation
# (reads and carts) and the steady run at 70% of it with a checkout of limited concurrency next to it (load/*.js, k6 in a
# container). Nothing is installed on the host and nothing is pushed. The cluster is deleted on every exit.
#
#   LOAD_OUT=<directory> load.sh   the directory for the results: required, an existing absolute path outside the repository
#
# Results in LOAD_OUT: environment.txt (host, Docker, versions, resources, data), ramp.json, ramp.txt, steady.json, steady.txt,
# warmup.txt, top.log (kubectl top pods every 10 s), node-stats.log (the node container, every 10 s).
#
# Exit code: 0 the measurement was done (it has no thresholds of speed; a ramp that ended on saturation is the normal
# end), 2 the environment is not fit (LOAD_OUT missing or wrong, no Docker, too few CPUs or too little memory in the Docker
# VM, pods not ready, the data of the demo or the stocks not prepared, the warm-up failed) with a message, 1 anything else
# that went wrong (a failed run of k6 in the ramp or the steady run, a failed build).
#
#   LOAD_BUILD=0          use the images shop-load-<cluster>/<app>:r1 that are already there
#   LOAD_TIMEOUT=1200     seconds to wait for the cluster to settle
#   LOAD_MIN_CPUS=10, LOAD_MIN_MEM_GIB=20   what the Docker VM must have (the pods of prod ask for about 5 vCPU and 8.5 GiB);
#                         the memory counts with a tolerance of 5% (docker info reports a little less than is set)
#   LOAD_CHECKOUT_VUS     virtual users of the checkout of the steady run; by default a quarter of the threads of
#                         FrankenPHP in the API (below half of them, D18)
#   LOAD_STEADY_RATE      iterations per second of the steady run; by default 70% of what the ramp held
#   LOAD_COOLDOWN=60      seconds between the ramp and the steady run
#   RAMP_*, STEADY_SECONDS, WARMUP_*, CHECKOUT_THINK_SECONDS   settings of the k6 scripts (see their headers), e.g.
#                         RAMP_STEPS=2 RAMP_STEP_SECONDS=10 STEADY_SECONDS=20 WARMUP_SECONDS=10 for a short run
#
# The tools run in containers on the Docker network of the cluster, so no port of the host is used. The cluster is called
# ld-<COMPOSE_PROJECT_NAME>; the smoke test's is sm-<...>, so both can exist.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
LOAD_DIR="$TOOLS_REPO/load"
# shellcheck source=../../../load/k6.sh
. "$LOAD_DIR/k6.sh"

LOAD_BUILD="${LOAD_BUILD:-1}"
LOAD_TIMEOUT="${LOAD_TIMEOUT:-1200}"
LOAD_POLL="${LOAD_POLL:-5}"
LOAD_MIN_CPUS="${LOAD_MIN_CPUS:-10}"
LOAD_MIN_MEM_GIB="${LOAD_MIN_MEM_GIB:-20}"
LOAD_COOLDOWN="${LOAD_COOLDOWN:-60}"
namespace=shop
started="$(date +%s)"

log() { printf '\n== %s (%ss)\n' "$1" "$(( $(date +%s) - started ))"; }
fail() { echo "load: $1" >&2; exit 2; }

# ---------------------------------------------------------------------------------------------------------------
# Before anything is made: the arguments and the machine
# ---------------------------------------------------------------------------------------------------------------
[ -n "${LOAD_OUT:-}" ] || fail "set LOAD_OUT=<directory> for the results (an existing absolute path outside the repository)"
case "$LOAD_OUT" in
  /*) ;;
  *) fail "LOAD_OUT '$LOAD_OUT' is not an absolute path" ;;
esac
[ -d "$LOAD_OUT" ] || fail "LOAD_OUT '$LOAD_OUT' is not an existing directory"
out="$(cd "$LOAD_OUT" && pwd -P)"
repo_real="$(cd "$TOOLS_REPO" && pwd -P)"
case "$out/" in
  "$repo_real"/*) fail "LOAD_OUT '$LOAD_OUT' is inside the repository; it must be a directory outside the repository (the results are not committed)" ;;
esac
for name in RAMP_START_RATE RAMP_RATE_STEP WARMUP_RATE CHECKOUT_THINK_SECONDS; do
  value="$(printenv "$name" || true)"
  case "$value" in
    *[!0-9.]*) fail "$name must be a number, got '$value'" ;;
  esac
done
for name in RAMP_STEPS RAMP_STEP_SECONDS RAMP_VUS RAMP_MAX_VUS STEADY_SECONDS STEADY_VUS STEADY_MAX_VUS WARMUP_SECONDS WARMUP_CHECKOUTS \
  LOAD_CHECKOUT_VUS LOAD_STEADY_RATE LOAD_TIMEOUT LOAD_POLL LOAD_COOLDOWN LOAD_MIN_CPUS LOAD_MIN_MEM_GIB; do
  value="$(printenv "$name" || true)"
  case "$value" in
    *[!0-9]*) fail "$name must be a whole number, got '$value'" ;;
  esac
done

docker_info="$(docker info --format '{{.NCPU}} {{.MemTotal}}' 2>/dev/null)" || fail "Docker is not available (docker info failed)"
docker_cpus="${docker_info% *}"
docker_mem="${docker_info#* }"
case "$docker_cpus$docker_mem" in
  "" | *[!0-9]*) fail "cannot read the CPUs and the memory of the Docker VM (docker info answered '$docker_info')" ;;
esac
[ "$docker_cpus" -ge "$LOAD_MIN_CPUS" ] || fail "the Docker VM has $docker_cpus CPUs, the measurement needs at least $LOAD_MIN_CPUS (the pods of prod ask for about 5 vCPU; give Docker more CPUs, or lower LOAD_MIN_CPUS knowingly)"
# MemTotal of the Docker VM is a little below what is set (Docker Desktop: 32 GiB gives 31.3 GiB), so 95% of the limit counts.
[ "$docker_mem" -ge $(( LOAD_MIN_MEM_GIB * 1073741824 * 95 / 100 )) ] || fail "the Docker VM has $(( docker_mem / 1073741824 )) GiB of memory, the measurement needs at least $LOAD_MIN_MEM_GIB GiB (the pods of prod ask for about 8.5 GiB; give Docker more memory, or lower LOAD_MIN_MEM_GIB knowingly)"

# The name of the cluster: from COMPOSE_PROJECT_NAME (or the .env of the worktree), at most 32 characters.
project="${COMPOSE_PROJECT_NAME:-}"
if [ -z "$project" ] && [ -f "$TOOLS_REPO/.env" ]; then
  project="$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' "$TOOLS_REPO/.env" | head -n 1)"
fi
project="$(printf '%s' "${project:-shop}" | tr 'A-Z_' 'a-z-' | tr -c 'a-z0-9\n-' '-')"
cluster="ld-$project"
if [ "${#cluster}" -gt 32 ]; then
  cluster="$(printf '%s' "$cluster" | cut -c1-22)-$(printf '%s' "$project" | cksum | cut -d' ' -f1 | cut -c1-8)"
fi
node="k3d-$cluster-server-0"
registry="shop-load-$cluster"
KUBE_NETWORK="k3d-$cluster"
mkdir -p "$TOOLS_CACHE"
KUBE_DIR="$(mktemp -d "$TOOLS_CACHE/kube-XXXXXX")"
apps="api store admin elasticsearch"
sampler_pids=""
built=0

cleanup() {
  status=$?
  trap - EXIT INT TERM
  for pid in $sampler_pids; do kill "$pid" 2>/dev/null || true; done
  echo "load: deleting the cluster $cluster"
  k3d cluster delete "$cluster" >/dev/null 2>&1 || echo "load: could not delete the cluster $cluster" >&2
  if [ "$built" = 1 ]; then
    for app in $apps; do docker rmi -f "$registry/$app:r1" >/dev/null 2>&1 || true; done
  fi
  rm -rf "$KUBE_DIR"
  case "$status" in
    0) echo "load: done; the results are in $out" ;;
    *) echo "load: FAILED (exit $status)" >&2 ;;
  esac
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

kc() { kubectl -n "$namespace" "$@"; }

# ---------------------------------------------------------------------------------------------------------------
# Waiting for the cluster (as in smoke.sh)
# ---------------------------------------------------------------------------------------------------------------
dump_state() {
  echo "---- pods" >&2
  kc get pods -o wide >&2 || true
  echo "---- jobs" >&2
  kc get jobs >&2 || true
  echo "---- events" >&2
  kc get events --sort-by=.lastTimestamp 2>/dev/null | tail -n 25 >&2 || true
}

# Fails at once on what cannot recover: a job out of attempts, a container that keeps crashing, an image that is not there.
check_failures() {
  failed="$(kc get jobs -l shop/job-kind=version -o jsonpath='{range .items[*]}{.metadata.name}{" "}{.status.conditions[?(@.type=="Failed")].status}{"\n"}{end}' | awk '$2 == "True" {print $1}')"
  if [ -n "$failed" ]; then
    echo "load: failed jobs: $failed" >&2
    dump_state
    return 1
  fi
  broken="$(kc get pods -o jsonpath='{range .items[*]}{.metadata.name}{range .status.initContainerStatuses[*]}{" "}{.state.waiting.reason}:{.restartCount}{end}{range .status.containerStatuses[*]}{" "}{.state.waiting.reason}:{.restartCount}{end}{"\n"}{end}' \
    | awk '{ for (i = 2; i <= NF; i++) { split($i, p, ":"); if ((p[1] == "CrashLoopBackOff" && p[2] >= 3) || p[1] == "ErrImageNeverPull" || p[1] == "InvalidImageName") { print $1 ": " p[1]; break } } }')"
  if [ -n "$broken" ]; then
    echo "load: pods that cannot start:" >&2
    echo "$broken" >&2
    dump_state
    return 1
  fi
}

# All jobs complete (there must be five), all deployments and statefulsets rolled out with the new pods ready.
all_ready() {
  jobs="$(kc get jobs -l shop/job-kind=version -o jsonpath='{range .items[*]}{.status.succeeded}{"\n"}{end}')"
  [ "$(echo "$jobs" | grep -c .)" -ge 5 ] || return 1
  [ -z "$(echo "$jobs" | grep -vx 1 | grep . || true)" ] || return 1
  not_ready="$(kc get deployments -o jsonpath='{range .items[*]}{.metadata.generation}{" "}{.status.observedGeneration}{" "}{.status.replicas}{" "}{.status.updatedReplicas}{" "}{.status.readyReplicas}{" "}{.status.availableReplicas}{"\n"}{end}' \
    | awk 'NF != 6 || $1 != $2 || $3 != 1 || $4 != 1 || $5 != 1 || $6 != 1 {print}')"
  [ -z "$not_ready" ] || return 1
  not_ready="$(kc get statefulsets -o jsonpath='{range .items[*]}{.status.readyReplicas}{" "}{.status.updatedReplicas}{"\n"}{end}' | awk '$1 != 1 || $2 != 1 {print}')"
  [ -z "$not_ready" ]
}

wait_ready() {
  deadline=$(( $(date +%s) + LOAD_TIMEOUT ))
  while :; do
    check_failures || fail "pods of the cluster cannot start (above); the pods are not ready"
    if all_ready; then
      echo "load: all jobs complete and all deployments ready"
      return 0
    fi
    if [ "$(date +%s)" -ge "$deadline" ]; then
      dump_state
      fail "the pods are not ready in ${LOAD_TIMEOUT} s"
    fi
    sleep "$LOAD_POLL"
  done
}

# k3s installs Traefik and its CRDs (the Middleware of the chart) in the background after the node is ready.
wait_for_traefik() {
  deadline=$(( $(date +%s) + 300 ))
  until kubectl get crd middlewares.traefik.io >/dev/null 2>&1; do
    if [ "$(date +%s)" -ge "$deadline" ]; then fail "the CRDs of Traefik were not installed in 300 s (the pods are not ready)"; fi
    sleep 3
  done
  kubectl wait --for=condition=established crd/middlewares.traefik.io --timeout=120s >/dev/null
}

# ---------------------------------------------------------------------------------------------------------------
# k6
# ---------------------------------------------------------------------------------------------------------------
# The settings of the k6 scripts that the caller gave, as `-e NAME=value` options.
script_settings() {
  settings=""
  for name in RAMP_START_RATE RAMP_RATE_STEP RAMP_STEPS RAMP_STEP_SECONDS RAMP_VUS RAMP_MAX_VUS STEADY_SECONDS STEADY_VUS STEADY_MAX_VUS \
    WARMUP_RATE WARMUP_SECONDS WARMUP_CHECKOUTS CHECKOUT_THINK_SECONDS; do
    value="$(printenv "$name" || true)"
    [ -z "$value" ] || settings="$settings -e $name=$value"
  done
  printf '%s' "$settings"
}

# run_k6 <script> <log name> [-e NAME=value...]: runs a script of load/ against the store and the API of the cluster, its
# output in LOAD_OUT/<log name>.txt and then on the terminal. Prints the exit code of k6 in $k6_status (0, 99 for crossed
# thresholds).
run_k6() {
  script="$1" logname="$2"
  shift 2
  k6_status=0
  # shellcheck disable=SC2046 # script_settings prints options
  k6_run "$KUBE_NETWORK" "$out" "$script" -e "STORE_URL=http://$node:$traefik_port" -e STORE_HOST=localhost -e "API_URL=http://$node:$traefik_port" \
    -e API_HOST=api.localhost -e STORE_ORIGIN=http://localhost $(script_settings) "$@" > "$out/$logname.txt" 2>&1 || k6_status=$?
  cat "$out/$logname.txt"
}

start_samplers() {
  : > "$out/top.log"
  : > "$out/node-stats.log"
  (
    while :; do
      stamp="$(date +%s)"
      kc top pods --no-headers 2>/dev/null | sed "s/^/$stamp /" >> "$out/top.log" || true
      sleep 10
    done
  ) &
  sampler_pids="$sampler_pids $!"
  (
    while :; do
      stamp="$(date +%s)"
      docker stats --no-stream --format '{{.CPUPerc}} {{.MemUsage}}' "$node" 2>/dev/null | sed "s/^/$stamp /" >> "$out/node-stats.log" || true
      sleep 10
    done
  ) &
  sampler_pids="$sampler_pids $!"
}

# ---------------------------------------------------------------------------------------------------------------
# The measurement
# ---------------------------------------------------------------------------------------------------------------
log "images"
if [ "$LOAD_BUILD" = 1 ]; then
  built=1
  for app in api store admin; do
    echo "load: docker build --target prod $app"
    docker build -q --target prod -t "$registry/$app:r1" "$TOOLS_REPO/apps/$app" >/dev/null
  done
  echo "load: docker build elasticsearch (infra/elasticsearch)"
  docker build -q -t "$registry/elasticsearch:r1" "$TOOLS_REPO/infra/elasticsearch" >/dev/null
fi
for app in $apps; do
  docker image inspect "$registry/$app:r1" >/dev/null 2>&1 || fail "the image $registry/$app:r1 is missing (run without LOAD_BUILD=0)"
done

log "cluster $cluster ($K3S_IMAGE)"
k3d cluster delete "$cluster" >/dev/null 2>&1 || true
# metrics-server stays on (kubectl top for the report); the load balancer is not needed.
k3d cluster create "$cluster" --no-lb --image "$K3S_IMAGE" --wait --timeout 300s --k3s-arg '--disable=servicelb@server:*' >/dev/null \
  || fail "the cluster $cluster could not be created (is the Docker VM out of resources?)"
k3d kubeconfig get "$cluster" | sed -E "s#server: https://[^:]+:[0-9]+#server: https://$node:6443#" > "$KUBE_DIR/config"
# shellcheck disable=SC2046 # the names have no spaces
sh "$here/import-images.sh" "$cluster" "$node" $(for app in $apps; do echo "$registry/$app:r1"; done) \
  || fail "the images could not be imported into the node $node"

log "install (profile load)"
wait_for_traefik
helm_cluster upgrade --install shop charts/shop --namespace "$namespace" --create-namespace \
  -f environments/ci/values.yaml -f environments/load/values.yaml --set image.tag=r1 --set "image.registry=$registry" >/dev/null
wait_ready

traefik_port="$(kubectl -n kube-system get service traefik -o jsonpath='{.spec.ports[?(@.name=="web")].nodePort}')"
[ -n "$traefik_port" ] || fail "the service of Traefik has no node port (the pods are not ready)"

log "data"
products="$(kc exec statefulset/postgres -- psql -U shop -d shop -tAc 'SELECT count(*) FROM catalog_product' | tr -d '[:space:]')"
[ "${products:-0}" -gt 0 ] || fail "the demo data is not there (0 products)"
# The stocks: every variant with a physical stock above 0 gets 100 000 more, so that the checkout does not use them up. A
# direct write for the data of this throwaway cluster only (the exception of the spec to writing through Inventory).
# Not a pipe into tr: the exit code of a pipe is the last one, and a failed psql must end the measurement (a checkout on
# the stocks of the demo data would use them up and skew the share of 409 in the results).
update_answer="$(kc exec statefulset/postgres -- psql -U shop -d shop -v ON_ERROR_STOP=1 -tAc \
  'UPDATE inventory_stock_item SET physical = physical + 100000, version = version + 1, updated_at = now() WHERE physical > 0')" \
  || fail "the stocks could not be raised (the UPDATE failed; above)"
raised="$(printf '%s' "$update_answer" | tr -d '\r')"
printf '%s\n' "$raised" | grep -Eqx 'UPDATE [1-9][0-9]*' || fail "the stocks were not raised (the UPDATE answered '$raised', expected UPDATE <n> with n of at least 1)"
echo "load: $products products; stocks raised: $raised"

# The threads of FrankenPHP in the API decide how many checkouts run at once (D18): the log of the server names them.
api_threads="$(kc logs deployment/api 2>/dev/null | sed -n 's/.*"total_threads":\([0-9]*\).*/\1/p' | head -n 1)"
case "$api_threads" in
  "" | *[!0-9]*) api_threads=$(( 2 * docker_cpus )) ;;
esac
checkout_vus="${LOAD_CHECKOUT_VUS:-$(( api_threads / 4 ))}"
[ "$checkout_vus" -ge 1 ] || checkout_vus=1

{
  echo "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "revision: $(git -C "$TOOLS_REPO" rev-parse HEAD 2>/dev/null || echo unknown)$(git -C "$TOOLS_REPO" status --porcelain 2>/dev/null | grep -q . && echo ' (with uncommitted changes)' || true)"
  echo "cluster: $cluster ($K3S_IMAGE, profile load)"
  echo "host: $(uname -srm)"
  echo "docker vm: $docker_cpus CPUs, $(( docker_mem / 1048576 )) MiB, $(docker info --format '{{.OperatingSystem}}, Docker {{.ServerVersion}}' 2>/dev/null || echo unknown)"
  echo "host load: $(uptime 2>/dev/null || echo unknown)"
  echo "products: $products; stock items raised by 100000: $raised"
  echo "api threads (FrankenPHP): $api_threads; checkout virtual users: $checkout_vus"
  echo "k6 image: $K6_IMAGE"
  echo "other containers on the host:"
  docker ps --format '  {{.Names}} ({{.Image}})' 2>/dev/null | grep -v "$node" || true
  echo "images of the pods:"
  kc get pods -o jsonpath='{range .items[*]}{range .spec.containers[*]}  {.name} {.image}{"\n"}{end}{end}' | sort -u
  echo "resources of the containers (requests and limits):"
  kc get pods -o jsonpath='{range .items[*]}{range .spec.containers[*]}  {.name} {.resources}{"\n"}{end}{end}' | sort -u
} > "$out/environment.txt"

start_samplers

log "warm-up"
run_k6 warmup.js warmup
[ "$k6_status" -eq 0 ] || fail "the warm-up failed (k6 exit $k6_status; above): the shop does not answer as the scripts expect"

log "ramp to saturation (reads and carts)"
rm -f "$out/steady-rate"
run_k6 saturate.js ramp
# 99 is the end on saturation (a threshold of a step with abortOnFail); anything else is a run that did not happen.
case "$k6_status" in
  0 | 99) ;;
  *) echo "load: the ramp failed (k6 exit $k6_status; above)" >&2; exit 1 ;;
esac
[ -s "$out/ramp.json" ] && [ -s "$out/steady-rate" ] || { echo "load: the ramp wrote no result (above)" >&2; exit 1; }

steady_rate="${LOAD_STEADY_RATE:-$(cat "$out/steady-rate")}"
echo "load: cooling down for ${LOAD_COOLDOWN} s; steady run: $steady_rate iterations/s and $checkout_vus checkout users"
sleep "$LOAD_COOLDOWN"

log "steady run (70% of the saturation, with the checkout)"
run_k6 steady.js steady -e "STEADY_RATE=$steady_rate" -e "CHECKOUT_VUS=$checkout_vus"
# The steady run has no thresholds: any exit code but 0 is a run that did not happen.
[ "$k6_status" -eq 0 ] || { echo "load: the steady run failed (k6 exit $k6_status; above)" >&2; exit 1; }
[ -s "$out/steady.json" ] || { echo "load: the steady run wrote no result (above)" >&2; exit 1; }
echo "load: peak CPU and memory per pod: see top.log; the node: node-stats.log"

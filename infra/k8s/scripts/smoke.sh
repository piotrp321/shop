#!/bin/sh
# The smoke test: the chart with the profile `ci` on an empty k3d cluster, with the production images built here and
# imported into the cluster (never pushed).
#
#   smoke.sh                       the whole test
#   SMOKE_BREAK=migration smoke.sh the api image gets a migration that fails: the test must fail
#   SMOKE_BREAK=image smoke.sh     the store image is unable to start: the test must fail
#   SMOKE_BUILD=0 smoke.sh         use the images shop-smoke-<cluster>/<app>:r1 that are already there
#   SMOKE_VALUES=<file> smoke.sh   install r1 with the images values file of the deployment (scripts/images-values.sh: the
#                                  revision and the digests) instead of the local images: the cluster pulls
#                                  ghcr.io/piotrp321/shop/<app>:<sha>@<digest> from the registry, and the test checks
#                                  that the pods run exactly those digests. The later phases use the local images
#                                  shop-smoke-<cluster>/<app>:r1 (SMOKE_BUILD=0), which must be there as well.
#                                  SMOKE_GHCR_USER and SMOKE_GHCR_TOKEN (packages: read) are given to k3d in a private
#                                  registries file when the packages are not public; the token is never printed.
#   SMOKE_KEEP=1 smoke.sh          keep the cluster after a failure, to look at it (delete it by hand)
#   SMOKE_REUSE=1 smoke.sh         go on with the later phases on the cluster that SMOKE_KEEP=1 left (a debugging aid)
#   SMOKE_TIMEOUT=900              seconds to wait for the cluster to settle after each install or upgrade
#
# Phases: install and checks (also the store in a browser and create-admin); upgrade with a new revision (initialize
# and keycloak-config change nothing); upgrade with a client added to the realm file of shop (keycloak-config adds it,
# the users and their passwords stay); upgrade with an image whose migration fails (the previous api and worker keep
# serving); upgrade with a good image on a database that has orders and no products (initialize fails and changes
# nothing). The peak memory and disk of the cluster are printed at the end. The cluster is deleted on every exit
# (success, error, interrupt).
#
# The images (api, store, admin and elasticsearch from infra/elasticsearch/Dockerfile) are tagged
# shop-smoke-<cluster>/<app>:r1..r4 (a registry name of their own per cluster) and removed at the end, so runs from
# several worktrees never overwrite or delete each other's images; the profile ci only supplies a default registry, the
# script passes its own.
#
# The tools run in containers on the Docker network of the cluster, so no port of the host is used and several
# clusters (one per COMPOSE_PROJECT_NAME) can run side by side. The cluster is called sm-<COMPOSE_PROJECT_NAME>.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

SMOKE_BREAK="${SMOKE_BREAK:-}"
SMOKE_BUILD="${SMOKE_BUILD:-1}"
SMOKE_TIMEOUT="${SMOKE_TIMEOUT:-900}"
SMOKE_VALUES="${SMOKE_VALUES:-}"
case "$SMOKE_BREAK" in
  "" | migration | image) ;;
  *) echo "smoke: SMOKE_BREAK must be empty, migration or image, got '$SMOKE_BREAK'" >&2; exit 2 ;;
esac
if [ -n "$SMOKE_VALUES" ]; then
  [ -f "$SMOKE_VALUES" ] || { echo "smoke: SMOKE_VALUES '$SMOKE_VALUES' is not a file" >&2; exit 2; }
  [ -z "$SMOKE_BREAK" ] || { echo "smoke: SMOKE_VALUES and SMOKE_BREAK cannot be used together" >&2; exit 2; }
  [ "${SMOKE_REUSE:-}" != 1 ] || { echo "smoke: SMOKE_VALUES and SMOKE_REUSE cannot be used together" >&2; exit 2; }
fi

namespace=shop

log() { printf '\n== %s (%ss)\n' "$1" "$(( $(date +%s) - started ))"; }
started="$(date +%s)"

# The name of the cluster: from COMPOSE_PROJECT_NAME (or the .env of the worktree), at most 32 characters.
project="${COMPOSE_PROJECT_NAME:-}"
if [ -z "$project" ] && [ -f "$TOOLS_REPO/.env" ]; then
  project="$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' "$TOOLS_REPO/.env" | head -n 1)"
fi
project="$(printf '%s' "${project:-shop}" | tr 'A-Z_' 'a-z-' | tr -c 'a-z0-9\n-' '-')"
cluster="sm-$project"
if [ "${#cluster}" -gt 32 ]; then
  cluster="$(printf '%s' "$cluster" | cut -c1-22)-$(printf '%s' "$project" | cksum | cut -d' ' -f1 | cut -c1-8)"
fi
node="k3d-$cluster-server-0"
# The images of this run are tagged <registry>/<app>:rN with a registry of their own (the name of the cluster, which
# comes from COMPOSE_PROJECT_NAME), so that runs from several worktrees never overwrite or delete each other's images.
registry="shop-smoke-$cluster"
KUBE_NETWORK="k3d-$cluster"
mkdir -p "$TOOLS_CACHE"
KUBE_DIR="$(mktemp -d "$TOOLS_CACHE/kube-XXXXXX")"
work="$KUBE_DIR"
# The files k3d gives to the node of the cluster, in a private directory (mktemp -d) outside the cache of the tools (which
# every tool container mounts) that only the k3d container mounts, at the same path, since k3d looks for the source of
# a volume there; removed on exit.
k3d_dir="$(mktemp -d "${TMPDIR:-/tmp}/shop-smoke-k3d-XXXXXX")"
K3D_MOUNT="$k3d_dir:$k3d_dir:ro"
export K3D_MOUNT
# The kubelet runs every image of the node for any pod. By default (KubeletEnsureSecretPulledImages, on since Kubernetes
# 1.35) an image ID the kubelet pulled itself may be used only under the repository it was pulled from: on main it pulls
# ghcr.io/piotrp321/shop/<app> for r1, and the same image ID imported as <registry>/<app>:r2 is then refused with
# ErrImageNeverPull. The test only runs images loaded into the node (pullPolicy Never) or pulled for r1; prod imports nothing.
cat > "$k3d_dir/90-smoke-kubelet.conf" <<'CONF'
apiVersion: kubelet.config.k8s.io/v1beta1
kind: KubeletConfiguration
imagePullCredentialsVerificationPolicy: NeverVerify
CONF
chart_registry="$(sed -n 's/^  registry: //p' "$here/../charts/shop/values.yaml" | head -n 1)"
if [ -n "$SMOKE_VALUES" ]; then
  # In the directory that the helm and k3d containers mount as /kube.
  cp "$SMOKE_VALUES" "$KUBE_DIR/values-images.yaml"
  if [ -n "${SMOKE_GHCR_TOKEN:-}" ]; then
    # A file for k3d (registries.yaml of the nodes) with the credentials for ghcr.io. Nothing here prints the token.
    (umask 077 && printf 'configs:\n  ghcr.io:\n    auth:\n      username: %s\n      password: %s\n' \
      "${SMOKE_GHCR_USER:-token}" "$SMOKE_GHCR_TOKEN" > "$k3d_dir/registries.yaml")
  fi
fi
unset SMOKE_GHCR_TOKEN
sampler_pid=""
# The images built here: the production stage of each application and Elasticsearch with its plugin.
apps="api store admin elasticsearch"
# The password of the customer the checks create in the realm shop and log in with (before and after an upgrade).
customer_password="smoke-$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"
# The administrator create-admin makes.
admin_login=smoke.admin
admin_email=smoke.admin@example.com
demo_notice="Sklep demonstracyjny: płatności i zamówienia są symulowane"

cleanup() {
  status=$?
  trap - EXIT INT TERM
  [ -z "$sampler_pid" ] || kill "$sampler_pid" 2>/dev/null || true
  if [ "${SMOKE_KEEP:-}" = 1 ] && [ "$status" -ne 0 ]; then
    # For debugging a failure only; never in CI.
    echo "smoke: SMOKE_KEEP=1, the failed cluster $cluster stays: k3d cluster delete $cluster" >&2
    exit "$status"
  fi
  echo "smoke: deleting the cluster $cluster"
  k3d cluster delete "$cluster" >/dev/null 2>&1 || echo "smoke: could not delete the cluster $cluster" >&2
  for tag in r1 r2 r3 r4; do
    for app in $apps; do docker rmi -f "$registry/$app:$tag" >/dev/null 2>&1 || true; done
  done
  rm -rf "$KUBE_DIR"
  rm -rf "$k3d_dir"
  if [ "$status" -eq 0 ]; then echo "smoke: ok"; else echo "smoke: FAILED (exit $status)" >&2; fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

kc() { kubectl -n "$namespace" "$@"; }
# Runs the checks of smoke/checks.py inside the cluster (python in the pod smoke-tools).
checks() {
  kc exec -i smoke-tools -- env "KC_ADMIN_PASSWORD=$kc_password" "STORE_CLIENT_SECRET=$store_secret" \
    "SMOKE_CUSTOMER_PASSWORD=$customer_password" python -I - "$@" < "$here/../smoke/checks.py"
}
read_secrets() {
  kc_password="$(kc get secret shop-secrets -o jsonpath='{.data.KC_BOOTSTRAP_ADMIN_PASSWORD}' | base64 -d)"
  store_secret="$(kc get secret shop-secrets -o jsonpath='{.data.STORE_CLIENT_SECRET}' | base64 -d)"
}

# ---------------------------------------------------------------------------------------------------------------
# Images
# ---------------------------------------------------------------------------------------------------------------
build_images() {
  for app in api store admin; do
    echo "smoke: docker build --target prod $app"
    docker build -q --target prod -t "$registry/$app:r1" "$TOOLS_REPO/apps/$app" >/dev/null
  done
  echo "smoke: docker build elasticsearch (infra/elasticsearch)"
  docker build -q -t "$registry/elasticsearch:r1" "$TOOLS_REPO/infra/elasticsearch" >/dev/null
}

# retag <from> <to>: the same images under another revision (a new revision of the chart, the same code).
retag() {
  for app in $apps; do docker tag "$registry/$app:$1" "$registry/$app:$2"; done
}

# An api image whose migrations include one that throws, tagged <revision>.
break_migration() {
  context="$work/broken-migration"
  mkdir -p "$context"
  cat > "$context/Version29990101000000.php" <<'PHP'
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version29990101000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        throw new \RuntimeException('migration broken on purpose (smoke test)');
    }
}
PHP
  printf 'FROM %s/api:r1\nCOPY Version29990101000000.php /app/migrations/\n' "$registry" > "$context/Dockerfile"
  docker build -q -t "$registry/api:$1" "$context" >/dev/null
}

# A store image that cannot start.
break_image() {
  printf 'FROM %s/store:r1\nENTRYPOINT ["/bin/false"]\n' "$registry" | docker build -q -t "$registry/store:$1" - >/dev/null
}

import_images() {
  # shellcheck disable=SC2046 # the names have no spaces
  k3d image import -c "$cluster" $(for app in $apps; do echo "$registry/$app:$1"; done) >/dev/null
}

# ---------------------------------------------------------------------------------------------------------------
# Peak memory and disk of the cluster, sampled in the background
# ---------------------------------------------------------------------------------------------------------------
sample_once() {
  mem="$(docker stats --no-stream --format '{{.MemUsage}}' "$node" 2>/dev/null | awk '{
    v=$1; u=v; gsub(/[0-9.]/, "", u); sub(/[A-Za-z]+$/, "", v);
    if (u=="GiB") v*=1024; else if (u=="KiB") v/=1024; else if (u=="B") v/=1048576;
    printf "%d", v }')"
  disk="$(docker exec "$node" sh -c 'du -sk /var/lib/rancher/k3s /var/lib/kubelet 2>/dev/null' 2>/dev/null | awk '{s+=$1} END {printf "%d", s/1024}')"
  echo "${mem:-0} ${disk:-0}" >> "$work/samples"
}

start_sampler() {
  : > "$work/samples"
  ( while :; do sample_once; sleep 10; done ) &
  sampler_pid=$!
}

report_peaks() {
  sample_once
  kill "$sampler_pid" 2>/dev/null || true
  sampler_pid=""
  awk '{ if ($1 > m) m = $1; if ($2 > d) d = $2 } END {
    printf "smoke: peak memory of the cluster: %d MiB\nsmoke: peak disk of the cluster (k3s data): %d MiB\n", m, d }' "$work/samples"
  docker images "$registry/*" --format '{{.Repository}}:{{.Tag}} {{.Size}}' | sort -u | sed 's/^/smoke: image /'
  echo "smoke: docker disk usage:"
  docker system df | sed 's/^/smoke:   /'
}

# ---------------------------------------------------------------------------------------------------------------
# Waiting
# ---------------------------------------------------------------------------------------------------------------
dump_state() {
  echo "---- pods" >&2
  kc get pods -o wide >&2 || true
  echo "---- jobs" >&2
  kc get jobs >&2 || true
  for pod in $(kc get pods -o jsonpath='{range .items[?(@.status.phase!="Succeeded")]}{.metadata.name}{"\n"}{end}' 2>/dev/null); do
    ready="$(kc get pod "$pod" -o jsonpath='{.status.conditions[?(@.type=="Ready")].status}' 2>/dev/null || true)"
    [ "$ready" = "True" ] && continue
    echo "---- logs of $pod" >&2
    kc logs "$pod" --all-containers --tail=25 >&2 2>&1 || true
  done
  echo "---- events" >&2
  kc get events --sort-by=.lastTimestamp 2>/dev/null | tail -n 25 >&2 || true
}

# Fails at once on what cannot recover: a job out of attempts, a container that keeps crashing, an image that is not there.
check_failures() {
  failed="$(kc get jobs -l shop/job-kind=version -o jsonpath='{range .items[*]}{.metadata.name}{" "}{.status.conditions[?(@.type=="Failed")].status}{"\n"}{end}' | awk '$2 == "True" {print $1}')"
  if [ -n "$failed" ]; then
    echo "smoke: failed jobs: $failed" >&2
    dump_state
    return 1
  fi
  broken="$(kc get pods -o jsonpath='{range .items[*]}{.metadata.name}{range .status.initContainerStatuses[*]}{" "}{.state.waiting.reason}:{.restartCount}{end}{range .status.containerStatuses[*]}{" "}{.state.waiting.reason}:{.restartCount}{end}{"\n"}{end}' \
    | awk '{ for (i = 2; i <= NF; i++) { split($i, p, ":"); if ((p[1] == "CrashLoopBackOff" && p[2] >= 3) || p[1] == "ErrImageNeverPull" || p[1] == "InvalidImageName") { print $1 ": " p[1]; break } } }')"
  if [ -n "$broken" ]; then
    echo "smoke: pods that cannot start:" >&2
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
  # name generation observedGeneration replicas updatedReplicas readyReplicas availableReplicas
  not_ready="$(kc get deployments -o jsonpath='{range .items[*]}{.metadata.generation}{" "}{.status.observedGeneration}{" "}{.status.replicas}{" "}{.status.updatedReplicas}{" "}{.status.readyReplicas}{" "}{.status.availableReplicas}{"\n"}{end}' \
    | awk 'NF != 6 || $1 != $2 || $3 != 1 || $4 != 1 || $5 != 1 || $6 != 1 {print}')"
  [ -z "$not_ready" ] || return 1
  not_ready="$(kc get statefulsets -o jsonpath='{range .items[*]}{.status.readyReplicas}{" "}{.status.updatedReplicas}{"\n"}{end}' | awk '$1 != 1 || $2 != 1 {print}')"
  [ -z "$not_ready" ]
}

wait_ready() {
  deadline=$(( $(date +%s) + SMOKE_TIMEOUT ))
  while :; do
    check_failures || return 1
    if all_ready; then
      echo "smoke: all jobs complete and all deployments ready ($1)"
      return 0
    fi
    if [ "$(date +%s)" -ge "$deadline" ]; then
      echo "smoke: not settled in ${SMOKE_TIMEOUT} s ($1)" >&2
      dump_state
      return 1
    fi
    sleep 5
  done
}

# wait_job_failed <logical name>: waits until the job of that name has used its attempts.
wait_job_failed() {
  deadline=$(( $(date +%s) + 300 ))
  while [ "$(date +%s)" -lt "$deadline" ]; do
    name="$(kc get jobs -l "app.kubernetes.io/name=$1" -o jsonpath='{range .items[*]}{.metadata.name}{" "}{.status.conditions[?(@.type=="Failed")].status}{"\n"}{end}' \
      | awk '$2 == "True" {print $1; exit}')"
    [ -z "$name" ] || { echo "$name"; return 0; }
    sleep 5
  done
  echo "smoke: the job $1 did not fail in 300 s" >&2
  dump_state
  return 1
}

job_name() { kc get jobs -l "app.kubernetes.io/name=$1" -o jsonpath='{.items[0].metadata.name}'; }
job_log() { kc logs "job/$1" --all-containers 2>&1 || true; }
count() { kc exec statefulset/postgres -- psql -U shop -d shop -tAc "SELECT count(*) FROM $1" | tr -d '[:space:]'; }

# ---------------------------------------------------------------------------------------------------------------
# The test
# ---------------------------------------------------------------------------------------------------------------
log "images"
if [ "$SMOKE_BUILD" = 1 ]; then build_images; fi
for app in $apps; do
  docker image inspect "$registry/$app:r1" >/dev/null 2>&1 || { echo "smoke: image $registry/$app:r1 is missing" >&2; exit 1; }
done
case "$SMOKE_BREAK" in
  migration) break_migration r1 ;;
  image) break_image r1 ;;
esac

# install <revision> [chart]: the chart of the repository unless another directory is given (a path in the container).
# With SMOKE_VALUES the first install (r1) takes the revision and the digests from the images values file and pulls the
# images from the registry of the chart (the profile ci would name the local registry and never pull: smoke_pull_args).
install() {
  if [ -n "$SMOKE_VALUES" ] && [ "$1" = r1 ]; then
    # shellcheck disable=SC2046 # smoke_pull_args prints options
    helm_cluster upgrade --install shop "${2:-charts/shop}" --namespace "$namespace" --create-namespace \
      -f environments/ci/values.yaml -f /kube/values-images.yaml $(smoke_pull_args) >/dev/null
  else
    helm_cluster upgrade --install shop "${2:-charts/shop}" --namespace "$namespace" --create-namespace \
      -f environments/ci/values.yaml --set "image.tag=$1" --set "image.registry=$registry" >/dev/null
  fi
}

# The pods run the images of the registry by the digests of the images values file (the point of SMOKE_VALUES).
check_registry_images() {
  image_ids="$(kc get pods -o jsonpath='{range .items[*]}{range .status.containerStatuses[*]}{.imageID}{"\n"}{end}{end}')"
  for app in $apps; do
    digest="$(sed -n "s/^    $app: \(sha256:[0-9a-f]\{64\}\)\$/\1/p" "$KUBE_DIR/values-images.yaml")"
    [ -n "$digest" ] || { echo "smoke: no digest of $app in $SMOKE_VALUES" >&2; return 1; }
    if ! printf '%s\n' "$image_ids" | grep -qF "$chart_registry/$app@$digest"; then
      echo "smoke: no pod runs $chart_registry/$app@$digest; the image ids of the pods:" >&2
      printf '%s\n' "$image_ids" | sort -u >&2
      return 1
    fi
    echo "ok: a pod runs $chart_registry/$app@$digest"
  done
}

# The store of the image built without flags, in a real browser (the bar and the login link come from /api/site, read by
# a client component): the bar is there and the login link is not, on / and on /cart. Chromium runs on the Docker
# network of the cluster and reaches Traefik on the node port of its service.
check_store_in_browser() {
  port="$(kubectl -n kube-system get service traefik -o jsonpath='{.spec.ports[?(@.name=="web")].nodePort}')"
  [ -n "$port" ] || { echo "smoke: the service of Traefik has no node port for the browser" >&2; return 1; }
  for path in / /cart; do
    attempt=1
    while :; do
      dom="$(browser_dom "http://$node:$port" "http://localhost$path" || true)"
      case "$dom" in *"$demo_notice"*) break ;; esac
      if [ "$attempt" -ge 3 ]; then
        echo "smoke: $path in the browser has no demo bar; the end of its DOM:" >&2
        printf '%s\n' "$dom" | tail -c 3000 >&2
        return 1
      fi
      attempt=$((attempt + 1))
      sleep 5
    done
    case "$dom" in
      *'href="/auth/login'* | *"Zaloguj się"*) echo "smoke: $path in the browser shows the login link" >&2; return 1 ;;
    esac
    echo "ok: $path in the browser has the demo bar and no login link"
  done
}

# create_admin <answer> <name of the log>: make -C infra/k8s create-admin with the answer on the standard input.
create_admin() {
  printf '%s\n' "$1" | KUBE_DIR="$KUBE_DIR" KUBE_NETWORK="$KUBE_NETWORK" KUBE_NAMESPACE="$namespace" \
    sh "$here/create-admin.sh" "$admin_login" "$admin_email" > "$work/create-admin-$2.log" 2>&1
}

check_create_admin() {
  if create_admin nie refused; then
    echo "smoke: create-admin went on without the word tak:" >&2; cat "$work/create-admin-refused.log" >&2; return 1
  fi
  grep -q 'nothing changed' "$work/create-admin-refused.log" || { cat "$work/create-admin-refused.log" >&2; return 1; }
  checks admin-absent "$admin_login" "$admin_email"
  echo "ok: create-admin without tak changed nothing"
  create_admin tak created || { echo "smoke: create-admin failed:" >&2; cat "$work/create-admin-created.log" >&2; return 1; }
  checks admin-mail "$admin_login" "$admin_email" > "$work/admin-mail"
  sed '$d' "$work/admin-mail"
  users_before="$(checks users | tail -n 1)"
  status=0
  create_admin tak again || status=$?
  if [ "$status" -ne 3 ] || ! grep -q "$admin_login exists" "$work/create-admin-again.log"; then
    echo "smoke: create-admin for an existing login ended with $status, not 3 with 'exists':" >&2
    cat "$work/create-admin-again.log" >&2
    return 1
  fi
  [ "$(checks admin-mail "$admin_login" "$admin_email" | tail -n 1)" = "$(tail -n 1 "$work/admin-mail")" ] \
    || { echo "smoke: the refused create-admin changed the administrator or sent an e-mail" >&2; return 1; }
  [ "$(checks users | tail -n 1)" = "$users_before" ] || { echo "smoke: the refused create-admin changed the users" >&2; return 1; }
  echo "ok: create-admin refused the existing login with exit 3 and changed nothing"
  # Nothing create-admin printed is a secret: none of the values of the secret of the profile ci, nor the customer's password.
  for value in $(yq '.secrets.values[]' environments/ci/values.yaml) "$customer_password"; do
    if cat "$work"/create-admin-*.log | grep -qF -- "$value"; then
      echo "smoke: the output of create-admin contains a secret" >&2
      return 1
    fi
  done
  echo "ok: the output of create-admin contains no secret"
}

# A copy of the chart whose realm file of shop has one more client (smoke-extra), in <work>/chart-extra.
add_realm_client() {
  rm -rf "$work/chart-extra"
  cp -RL "$here/../charts/shop" "$work/chart-extra"
  # shellcheck disable=SC2016 # ${STORE_URL} is the placeholder of the realm file, not a variable of this shell
  tools_run "$PYTHON_IMAGE" python -I -c '
import json, sys
path = sys.argv[1]
with open(path) as file:
    realm = json.load(file)
realm["clients"].append({"clientId": "smoke-extra", "enabled": True, "publicClient": True, "standardFlowEnabled": True,
                         "redirectUris": ["${STORE_URL}/smoke-extra/*"], "webOrigins": []})
with open(path, "w") as file:
    json.dump(realm, file, indent=2)
' "/cache/${work##*/}/chart-extra/files/realms/shop.json"
}

# k3s installs Traefik and its CRDs (the Middleware of the chart) in the background after the node is ready.
wait_for_traefik() {
  deadline=$(( $(date +%s) + 300 ))
  until kubectl get crd middlewares.traefik.io >/dev/null 2>&1; do
    if [ "$(date +%s)" -ge "$deadline" ]; then echo "smoke: the CRDs of Traefik were not installed in 300 s" >&2; return 1; fi
    sleep 3
  done
  kubectl wait --for=condition=established crd/middlewares.traefik.io --timeout=120s >/dev/null
}

# The kubelet of the node took the configuration of the test (90-smoke-kubelet.conf).
check_kubelet_policy() {
  kubectl get --raw "/api/v1/nodes/$node/proxy/configz" | grep -q '"imagePullCredentialsVerificationPolicy":"NeverVerify"' \
    || { echo "smoke: the kubelet of $node did not take 90-smoke-kubelet.conf (imagePullCredentialsVerificationPolicy)" >&2; return 1; }
}

# The pod the checks run in: python with the standard library only.
create_tools_pod() {
  kc apply -f - >/dev/null <<'YAML'
apiVersion: v1
kind: Pod
metadata:
  name: smoke-tools
spec:
  terminationGracePeriodSeconds: 0
  containers:
    - name: python
      image: python:3.13-alpine
      command: ["sleep", "infinity"]
YAML
}

if [ "${SMOKE_REUSE:-}" = 1 ]; then
  # A debugging aid: go on with the phases after the upgrade on the cluster that SMOKE_KEEP=1 left after a failed run.
  log "cluster $cluster (reused, at the state after the upgrade to r2)"
  k3d kubeconfig get "$cluster" | sed -E "s#server: https://[^:]+:[0-9]+#server: https://$node:6443#" > "$KUBE_DIR/config"
  start_sampler
  read_secrets
  orders_before="$(count ordering_order)"
  new_keycloak_config="$(job_name keycloak-config)"
else
  log "cluster $cluster ($K3S_IMAGE)"
  k3d cluster delete "$cluster" >/dev/null 2>&1 || true
  registry_config=""
  [ ! -f "$k3d_dir/registries.yaml" ] || registry_config="--registry-config $k3d_dir/registries.yaml"
  # shellcheck disable=SC2086 # $registry_config is an option and its value
  k3d cluster create "$cluster" --no-lb --image "$K3S_IMAGE" --wait --timeout 300s $registry_config \
    -v "$k3d_dir/90-smoke-kubelet.conf:/var/lib/rancher/k3s/agent/etc/kubelet.conf.d/90-smoke-kubelet.conf@server:*" \
    --k3s-arg '--disable=metrics-server@server:*' --k3s-arg '--disable=servicelb@server:*' >/dev/null
  k3d kubeconfig get "$cluster" | sed -E "s#server: https://[^:]+:[0-9]+#server: https://$node:6443#" > "$KUBE_DIR/config"
  check_kubelet_policy
  start_sampler
  # With SMOKE_VALUES the first install pulls the images from the registry; nothing is imported for it.
  [ -n "$SMOKE_VALUES" ] || import_images r1

  log "install r1"
  wait_for_traefik
  install r1
  create_tools_pod
  wait_ready "install r1"
  kc wait --for=condition=Ready pod/smoke-tools --timeout=180s >/dev/null
  read_secrets
  if [ -n "$SMOKE_VALUES" ]; then
    log "images pulled from the registry by digest"
    check_registry_images
  fi

  log "checks of the installation"
  checks basic

  log "the store in a browser"
  check_store_in_browser

  log "create-admin"
  check_create_admin

  log "upgrade r2: the same code, a new revision"
  snapshot_before="$(checks snapshot | tail -n 1)"
  orders_before="$(count ordering_order)"
  products_before="$(count catalog_product)"
  [ "$orders_before" -ge 1 ] || { echo "smoke: the checks should have placed an order" >&2; exit 1; }
  old_initialize="$(job_name initialize)"
  old_keycloak_config="$(job_name keycloak-config)"
  retag r1 r2
  import_images r2
  install r2
  wait_ready "upgrade r2"
  new_initialize="$(job_name initialize)"
  new_keycloak_config="$(job_name keycloak-config)"
  [ "$new_initialize" != "$old_initialize" ] || { echo "smoke: initialize did not run again for a new revision" >&2; exit 1; }
  [ "$new_keycloak_config" != "$old_keycloak_config" ] || { echo "smoke: keycloak-config did not run again for a new revision" >&2; exit 1; }
  job_log "$new_initialize" | grep -q 'nothing to do' || { echo "smoke: initialize did not report that the data is there:" >&2; job_log "$new_initialize" >&2; exit 1; }
  for realm in shop backoffice; do
    job_log "$new_keycloak_config" | grep -q "realm $realm exists: 0 added" || { echo "smoke: keycloak-config added resources to $realm:" >&2; job_log "$new_keycloak_config" >&2; exit 1; }
  done
  checks compare "$snapshot_before"
  [ "$(count ordering_order)" = "$orders_before" ] || { echo "smoke: the number of orders changed" >&2; exit 1; }
  [ "$(count catalog_product)" = "$products_before" ] || { echo "smoke: the number of products changed" >&2; exit 1; }
  echo "smoke: the upgrade changed no products, orders or realms ($products_before products, $orders_before orders)"

  log "upgrade r2 with a client added to the realm file of shop"
  users_before="$(checks users | tail -n 1)"
  add_realm_client
  old_keycloak_config="$new_keycloak_config"
  install r2 "/cache/${work##*/}/chart-extra"
  wait_ready "client added"
  new_keycloak_config="$(job_name keycloak-config)"
  [ "$new_keycloak_config" != "$old_keycloak_config" ] || { echo "smoke: keycloak-config did not run again for a changed realm file" >&2; exit 1; }
  job_log "$new_keycloak_config" | grep -q 'realm shop exists: 1 added' || { echo "smoke: keycloak-config did not add one resource to shop:" >&2; job_log "$new_keycloak_config" >&2; exit 1; }
  checks client-added "$users_before"

fi

log "rerun of a finished job"
KUBE_DIR="$KUBE_DIR" KUBE_NETWORK="$KUBE_NETWORK" KUBE_NAMESPACE="$namespace" sh "$here/rerun.sh" "$new_keycloak_config" >/dev/null
kc wait --for=condition=complete "job/$new_keycloak_config" --timeout=300s >/dev/null
job_log "$new_keycloak_config" | grep -q 'keycloak-config: done' || { echo "smoke: the rerun job did not run" >&2; exit 1; }
echo "smoke: rerun of $new_keycloak_config completed"

log "upgrade r3: a migration that fails"
retag r2 r3
break_migration r3
import_images r3
install r3
failed_migrate="$(wait_job_failed migrate)"
echo "smoke: the job $failed_migrate failed, as it must"
job_log "$failed_migrate" | grep -q 'broken on purpose' || { echo "smoke: the migration failed for another reason:" >&2; job_log "$failed_migrate" >&2; exit 1; }
for app in api worker; do
  serving="$(kc get pods -l "app.kubernetes.io/name=$app" -o jsonpath='{range .items[?(@.status.phase=="Running")]}{.spec.containers[0].image}{" "}{.status.conditions[?(@.type=="Ready")].status}{"\n"}{end}')"
  echo "$serving" | grep -q "$registry/api:r2 True" || { echo "smoke: the previous $app pod does not serve:" >&2; echo "$serving" >&2; exit 1; }
  if echo "$serving" | grep -q "$registry/api:r3 True"; then echo "smoke: the new $app pod became ready on the failed migration" >&2; exit 1; fi
done
status="$(kc exec smoke-tools -- python -I -c "
import urllib.request
r = urllib.request.Request('http://traefik.kube-system.svc.cluster.local/health', headers={'Host': 'api.localhost'})
print(urllib.request.urlopen(r, timeout=30).read().decode())")"
echo "$status" | grep -q '"status":"ok"' || { echo "smoke: the previous api does not answer /health: $status" >&2; exit 1; }
echo "smoke: the previous api and worker keep serving, the new ones are not ready"

log "upgrade r4: a good revision on a database with orders and without products"
retag r2 r4
import_images r4
kc exec statefulset/postgres -- psql -U shop -d shop -v ON_ERROR_STOP=1 -qc 'TRUNCATE catalog_product CASCADE' >/dev/null
install r4
failed_initialize="$(wait_job_failed initialize)"
echo "smoke: the job $failed_initialize failed, as it must"
job_log "$failed_initialize" | grep -q 'mixed state' || { echo "smoke: initialize failed for another reason:" >&2; job_log "$failed_initialize" >&2; exit 1; }
[ "$(count catalog_product)" = 0 ] || { echo "smoke: initialize loaded products into a database with orders" >&2; exit 1; }
[ "$(count ordering_order)" = "$orders_before" ] || { echo "smoke: initialize changed the orders" >&2; exit 1; }
echo "smoke: initialize refused to load the data (0 products, $orders_before orders) and changed nothing"

log "peaks"
report_peaks

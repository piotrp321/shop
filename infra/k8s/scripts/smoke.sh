#!/bin/sh
# The smoke test: the chart with the profile `ci` on an empty k3d cluster, with the production images built here and
# imported into the cluster (never pushed).
#
#   smoke.sh                       the whole test
#   SMOKE_BREAK=migration smoke.sh the api image gets a migration that fails: the test must fail
#   SMOKE_BREAK=image smoke.sh     the store image is unable to start: the test must fail
#   SMOKE_BREAK=demo-data smoke.sh the CronJob demo-data gets a profile the generator refuses: the Job fails, the test must fail
#                                  (with the log of the Job) in the last phase, after all the others passed
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
# Phases: install and checks (also a Job from the CronJob rate-limit-check, which must succeed on the Redis of the cluster; the store and
# the panel in a browser, and create-admin); the failures of the messages (the CronJob order-expiry suspended; RabbitMQ scaled to zero: an
# order past its deadline with its ready message is cancelled by worker-commands within two minutes, and after the broker is back every
# workload is ready; a message in `failed`: a Job from the CronJob queue-check fails, a Job from order-expiry cancels the order, and after
# the message is removed a Job from queue-check succeeds); the backup (an S3 of its own in the
# namespace smoke-backup, a Job from the CronJob backup, the snapshot it leaves, a Job from the CronJob backup-verify; the time of the
# phase and of the verification is printed); the restore on an empty cluster (make backups lists the snapshots, the namespace shop
# is deleted with its volumes and the chart installed again, make restore brings back the snapshot nightly: the administrator, the
# customer and its login, the numbers of products and orders, an image, the search, the home page, the snapshot pre-restore;
# make restore refuses a snapshot with a migration the code does not know and the shop goes on, also while a Job of the CronJob
# rate-limit-check runs (it is no reason to refuse) and none of the CronJobs backup, cart-purge-expired, rate-limit-check, queue-check
# and order-expiry is suspended by the refusal; the time of the phase and the
# peak disk are printed); the later phases run on the restored cluster: upgrade with a new revision (initialize
# and keycloak-config change nothing); upgrade with a client added to the realm file of shop (keycloak-config adds it,
# the users and their passwords stay); upgrade with an image whose migration fails (the previous api and both workers keep
# serving); upgrade with a good image on a database that has orders and no products (initialize fails and changes
# nothing). The last phase runs the demo data generator (docs/features/demo-data/spec.md): it waits until every StatefulSet is rolled
# out (the upgrade before it ends on a failed job, with Elasticsearch still on its way to the new image), then ONE Job from the
# suspended CronJob demo-data (bin/generate-demo --profile=small), waited for up to the time limit of the CronJob plus SMOKE_DEMO_DATA_MARGIN seconds
# (120); a Job that fails or does not finish fails the test with its log, and a Job that succeeded must have replaced the data of the
# shop (at least the products and orders of the profile). The generator deletes the data of every module and generates its own, so
# it comes after every phase that reads the seed, the backups and the restore (they count products and orders) and nothing runs on the
# data after it. The time of the phase is printed. The peak memory and disk of the cluster are printed at the end. The cluster is
# deleted on every exit (success, error, interrupt).
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
  "" | migration | image | demo-data) ;;
  *) echo "smoke: SMOKE_BREAK must be empty, migration, image or demo-data, got '$SMOKE_BREAK'" >&2; exit 2 ;;
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

# import_images <revision>: the images of that revision into the node, checked there before any helm upgrade uses them
# (import-images.sh: k3d may end with 0 without importing them).
import_images() {
  # shellcheck disable=SC2046 # the names have no spaces
  sh "$here/import-images.sh" "$cluster" "$node" $(for app in $apps; do echo "$registry/$app:$1"; done)
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

# The StatefulSets whose rolling update has not ended, one line each (nothing when every one is rolled out): the controller has seen
# the last spec, every replica runs the update revision (currentRevision = updateRevision) and is ready.
# name generation observedGeneration replicas readyReplicas updatedReplicas currentRevision updateRevision
# A failed read is a line too: it must never pass for a rolled-out cluster.
statefulsets_in_rollout() {
  statefulsets="$(kc get statefulsets -o jsonpath='{range .items[*]}{.metadata.name}{" "}{.metadata.generation}{" "}{.status.observedGeneration}{" "}{.spec.replicas}{" "}{.status.readyReplicas}{" "}{.status.updatedReplicas}{" "}{.status.currentRevision}{" "}{.status.updateRevision}{"\n"}{end}')" || {
    echo "(cannot read the StatefulSets)"
    return 0
  }
  printf '%s\n' "$statefulsets" | awk 'NF && (NF != 8 || $2 != $3 || $5 != $4 || $6 != $4 || $7 != $8) {print}'
}

# Every version job complete (there must be six, backfill among them; a running job has no succeeded, and its line must
# count), all deployments and statefulsets rolled out with the new pods ready.
all_ready() {
  # name=succeeded per job: a job that still runs is "name=" and is not ready.
  jobs="$(kc get jobs -l shop/job-kind=version -o jsonpath='{range .items[*]}{.metadata.name}={.status.succeeded}{"\n"}{end}')"
  [ "$(echo "$jobs" | grep -c .)" -ge 6 ] || return 1
  [ -z "$(echo "$jobs" | grep . | grep -v '=1$' || true)" ] || return 1
  # name generation observedGeneration replicas updatedReplicas readyReplicas availableReplicas
  not_ready="$(kc get deployments -o jsonpath='{range .items[*]}{.metadata.generation}{" "}{.status.observedGeneration}{" "}{.status.replicas}{" "}{.status.updatedReplicas}{" "}{.status.readyReplicas}{" "}{.status.availableReplicas}{"\n"}{end}' \
    | awk 'NF != 6 || $1 != $2 || $3 != 1 || $4 != 1 || $5 != 1 || $6 != 1 {print}')"
  [ -z "$not_ready" ] || return 1
  [ -z "$(statefulsets_in_rollout)" ]
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

# The panel of the image built without flags, in a real browser: an unauthenticated visit redirects to the login page of
# Keycloak (realm backoffice), so the scripts of the panel ran (index.html, /config.js, the bundle) under the CSP with
# script-src 'self' and the console has no CSP violation. Chromium reaches Traefik on the node port of its service.
check_admin_in_browser() {
  port="$(kubectl -n kube-system get service traefik -o jsonpath='{.spec.ports[?(@.name=="web")].nodePort}')"
  [ -n "$port" ] || { echo "smoke: the service of Traefik has no node port for the browser" >&2; return 1; }
  attempt=1
  while :; do
    dom="$(browser_dom "http://$node:$port" "http://admin.localhost/" || true)"
    case "$dom" in *'id="kc-form-login"'*) break ;; esac
    if [ "$attempt" -ge 3 ]; then
      echo "smoke: the panel in the browser did not reach the login page of Keycloak; the end of its DOM:" >&2
      printf '%s\n' "$dom" | tail -c 3000 >&2
      return 1
    fi
    attempt=$((attempt + 1))
    sleep 5
  done
  echo "ok: the panel in the browser redirects to the login page of Keycloak"
  messages="$(browser_console "http://$node:$port" "http://admin.localhost/")" || {
    echo "smoke: the browser failed to load the panel for the console check" >&2
    return 1
  }
  if printf '%s\n' "$messages" | grep -qiE 'content[ -]security[ -]policy|violates|refused to'; then
    echo "smoke: the panel in the browser reports a Content Security Policy violation:" >&2
    printf '%s\n' "$messages" >&2
    return 1
  fi
  echo "ok: the console of the panel in the browser has no CSP violation"
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

# ---------------------------------------------------------------------------------------------------------------
# The backup (docs/features/deploy-ops/spec.md, "Smoke test i check", steps 1 and 2)
# ---------------------------------------------------------------------------------------------------------------
backup_namespace=smoke-backup
bkc() { kubectl -n "$backup_namespace" "$@"; }

# The S3 of the repository of the backups: a Garage of its own (the image of the chart) in the namespace smoke-backup, outside
# the namespace of the shop, with the bucket `restic` and the key of the profile ci. The configuration and the script of
# the bootstrap are those of compose (infra/garage), as in the chart; only the bucket differs.
start_backup_repository() {
  garage_image="$(yq '.images.garage' charts/shop/values.yaml)"
  alpine_image="$(yq '.images.alpine' charts/shop/values.yaml)"
  key_id="$(yq '.backup.secretValues.BACKUP_S3_ACCESS_KEY_ID' environments/ci/values.yaml)"
  key_secret="$(yq '.backup.secretValues.BACKUP_S3_SECRET_ACCESS_KEY' environments/ci/values.yaml)"
  rpc_secret="$(yq '.secrets.values.GARAGE_RPC_SECRET' environments/ci/values.yaml)"
  kubectl create namespace "$backup_namespace" > /dev/null
  {
    echo "apiVersion: v1"
    echo "kind: ConfigMap"
    echo "metadata: { name: garage-config }"
    echo "data:"
    echo "  garage.toml: |"
    sed 's/^/    /' "$TOOLS_REPO/infra/garage/garage.toml"
    echo "  init.sh: |"
    sed 's/^BUCKETS=.*/BUCKETS="restic"/' "$TOOLS_REPO/infra/garage/init.sh" | sed 's/^/    /'
    cat <<YAML
---
apiVersion: v1
kind: Secret
metadata: { name: garage-secrets }
stringData:
  GARAGE_RPC_SECRET: "$rpc_secret"
  GARAGE_ADMIN_TOKEN: smoke-backup-admin-token
  GARAGE_ACCESS_KEY_ID: "$key_id"
  GARAGE_SECRET_ACCESS_KEY: "$key_secret"
---
apiVersion: v1
kind: Service
metadata: { name: garage }
spec:
  selector: { app: garage }
  publishNotReadyAddresses: true
  ports:
    - { name: s3, port: 3900 }
    - { name: rpc, port: 3901 }
    - { name: admin, port: 3903 }
---
apiVersion: apps/v1
kind: Deployment
metadata: { name: garage }
spec:
  replicas: 1
  selector: { matchLabels: { app: garage } }
  template:
    metadata: { labels: { app: garage } }
    spec:
      enableServiceLinks: false
      containers:
        - name: garage
          image: $garage_image
          envFrom: [{ secretRef: { name: garage-secrets } }]
          volumeMounts:
            - { name: config, mountPath: /etc/garage.toml, subPath: garage.toml, readOnly: true }
            - { name: meta, mountPath: /var/lib/garage/meta }
            - { name: data, mountPath: /var/lib/garage/data }
      volumes:
        - { name: config, configMap: { name: garage-config } }
        - { name: meta, emptyDir: {} }
        - { name: data, emptyDir: {} }
---
apiVersion: batch/v1
kind: Job
metadata: { name: garage-init }
spec:
  backoffLimit: 6
  template:
    spec:
      restartPolicy: Never
      enableServiceLinks: false
      containers:
        - name: garage-init
          image: $alpine_image
          command: ["/bin/sh", "/config/init.sh"]
          env: [{ name: PATH, value: "/garage-bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" }]
          envFrom: [{ secretRef: { name: garage-secrets } }]
          volumeMounts:
            - { name: garage-bin, mountPath: /garage-bin, readOnly: true }
            - { name: config, mountPath: /etc/garage.toml, subPath: garage.toml, readOnly: true }
            - { name: config, mountPath: /config, readOnly: true }
      volumes:
        - { name: garage-bin, image: { reference: $garage_image, pullPolicy: IfNotPresent } }
        - { name: config, configMap: { name: garage-config } }
YAML
  } | bkc apply -f - > /dev/null
  bkc wait --for=condition=complete job/garage-init --timeout=300s > /dev/null || {
    echo "smoke: the Garage of the backup repository did not start:" >&2
    bkc get pods >&2 || true
    bkc logs job/garage-init --all-containers --tail=30 >&2 || true
    return 1
  }
  echo "ok: the S3 of the backup repository is up in the namespace $backup_namespace (bucket restic)"
}

# A pod with restic (the repository of the profile ci, as the CronJob reads it) and rclone (the Garage of the shop), to put an
# object into the bucket of the images and to look into the repository. They only sleep; kc exec -c <tool> uses them.
create_backup_tools_pod() {
  restic_image="$(yq '.images.restic' charts/shop/values.yaml)"
  rclone_image="$(yq '.images.rclone' charts/shop/values.yaml)"
  repository="$(yq '.backup.repository' environments/ci/values.yaml)"
  region="$(yq '.backup.s3Region' environments/ci/values.yaml)"
  secret="$(yq '.backup.secretName' environments/ci/values.yaml)"
  kc apply -f - > /dev/null <<YAML
apiVersion: v1
kind: Pod
metadata: { name: smoke-backup-tools }
spec:
  terminationGracePeriodSeconds: 0
  enableServiceLinks: false
  automountServiceAccountToken: false
  containers:
    - name: restic
      image: $restic_image
      command: ["sleep", "infinity"]
      env:
        - { name: RESTIC_REPOSITORY, value: "$repository" }
        - { name: AWS_DEFAULT_REGION, value: "$region" }
        - { name: RESTIC_CACHE_DIR, value: /tmp/restic-cache }
        - { name: RESTIC_PASSWORD, valueFrom: { secretKeyRef: { name: $secret, key: RESTIC_PASSWORD } } }
        - { name: AWS_ACCESS_KEY_ID, valueFrom: { secretKeyRef: { name: $secret, key: BACKUP_S3_ACCESS_KEY_ID } } }
        - { name: AWS_SECRET_ACCESS_KEY, valueFrom: { secretKeyRef: { name: $secret, key: BACKUP_S3_SECRET_ACCESS_KEY } } }
    - name: rclone
      image: $rclone_image
      command: ["sleep", "infinity"]
      env:
        - { name: RCLONE_CONFIG_GARAGE_TYPE, value: s3 }
        - { name: RCLONE_CONFIG_GARAGE_PROVIDER, value: Other }
        - { name: RCLONE_CONFIG_GARAGE_ENDPOINT, value: "http://garage:3900" }
        - { name: RCLONE_CONFIG_GARAGE_REGION, value: garage }
        - { name: RCLONE_CONFIG_GARAGE_ACCESS_KEY_ID, valueFrom: { secretKeyRef: { name: shop-secrets, key: GARAGE_ACCESS_KEY_ID } } }
        - { name: RCLONE_CONFIG_GARAGE_SECRET_ACCESS_KEY, valueFrom: { secretKeyRef: { name: shop-secrets, key: GARAGE_SECRET_ACCESS_KEY } } }
YAML
  kc wait --for=condition=Ready pod/smoke-backup-tools --timeout=300s > /dev/null
}

# run_cronjob_job <cronjob> <job> [seconds]: a Job from a CronJob, waited for up to 900 s (or the seconds given; SMOKE_POLL, 5, is the
# time between two readings); a failure prints the logs of all its containers.
# wait_statefulsets <what>: waits up to SMOKE_TIMEOUT for every StatefulSet to be rolled out. A phase that follows an upgrade which
# ends on a failed job on purpose (r3, r4) needs it: the upgrade changed the image of Elasticsearch (its tag is the revision), and its
# pod is still being replaced when the failed job is seen.
wait_statefulsets() {
  deadline=$(( $(date +%s) + ${SMOKE_TIMEOUT:-900} ))
  while :; do
    rolling="$(statefulsets_in_rollout)"
    if [ -z "$rolling" ]; then
      echo "smoke: every StatefulSet rolled out ($1)"
      return 0
    fi
    if [ "$(date +%s)" -ge "$deadline" ]; then
      echo "smoke: StatefulSets not rolled out in ${SMOKE_TIMEOUT:-900} s ($1):" >&2
      echo "$rolling" >&2
      return 1
    fi
    echo "smoke: waiting for the rollout of $(echo "$rolling" | awk '{print $1}' | tr '\n' ' ')($1)"
    sleep "${SMOKE_POLL:-5}"
  done
}

run_cronjob_job() {
  kc create job "$2" --from="cronjob/$1" > /dev/null
  limit="${3:-900}"
  deadline=$(( $(date +%s) + limit ))
  while :; do
    state="$(kc get job "$2" -o jsonpath='{.status.succeeded}{" "}{.status.conditions[?(@.type=="Failed")].status}')"
    case "$state" in
      "1 "*) return 0 ;;
      *" True")
        echo "smoke: the Job $2 of the CronJob $1 failed:" >&2
        kc logs "job/$2" --all-containers --prefix --tail=40 >&2 || true
        return 1 ;;
    esac
    if [ "$(date +%s)" -ge "$deadline" ]; then
      echo "smoke: the Job $2 of the CronJob $1 did not finish in $limit s" >&2
      kc get pods >&2 || true
      kc logs "job/$2" --all-containers --prefix --tail=40 >&2 || true
      return 1
    fi
    sleep "${SMOKE_POLL:-5}"
  done
}

# The check of the Redis of the rate limiters (docs/features/abuse-limits/spec.md, L12): a Job made from the CronJob rate-limit-check, as
# the CronJob makes it every five minutes, succeeds against the Redis of the cluster, and its log says the control key was written,
# read and deleted. The alert RateLimitsOff counts exactly these successes. The Job is removed: it is no Job that restore waits for.
check_rate_limit_check() {
  run_cronjob_job rate-limit-check rate-limit-check-smoke 180
  log_text="$(kc logs job/rate-limit-check-smoke)"
  case "$log_text" in
    *"accepted, returned and deleted the control key"*) ;;
    *) echo "smoke: the log of the Job rate-limit-check-smoke does not say that the control key was written, read and deleted:" >&2; printf '%s\n' "$log_text" >&2; return 1 ;;
  esac
  [ "$(kc get cronjob rate-limit-check -o jsonpath='{.spec.suspend}')" != true ] || { echo "smoke: the CronJob rate-limit-check is suspended" >&2; return 1; }
  kc delete job rate-limit-check-smoke --now > /dev/null
  echo "ok: a Job of the CronJob rate-limit-check succeeds on the Redis of the cluster"
}

# expect_job_failed <cronjob> <job> [limit]: a Job made from the CronJob, as it makes it every five minutes, must end Failed (the check it
# runs finds something wrong); a Job that succeeds, or does not end in the limit, fails the test with its log.
expect_job_failed() {
  kc create job "$2" --from="cronjob/$1" > /dev/null
  limit="${3:-180}"
  deadline=$(( $(date +%s) + limit ))
  while :; do
    state="$(kc get job "$2" -o jsonpath='{.status.succeeded}{" "}{.status.conditions[?(@.type=="Failed")].status}')"
    case "$state" in
      "1 "*)
        echo "smoke: the Job $2 of the CronJob $1 succeeded, it must fail:" >&2
        kc logs "job/$2" --all-containers --prefix --tail=40 >&2 || true
        return 1 ;;
      *" True")
        echo "ok: the Job $2 of the CronJob $1 failed, as it must"
        return 0 ;;
    esac
    if [ "$(date +%s)" -ge "$deadline" ]; then
      echo "smoke: the Job $2 of the CronJob $1 did not fail in $limit s" >&2
      kc logs "job/$2" --all-containers --prefix --tail=40 >&2 || true
      return 1
    fi
    sleep "${SMOKE_POLL:-5}"
  done
}

# ---------------------------------------------------------------------------------------------------------------
# The failures of the messages (docs/features/async-reliability/spec.md, W9, criteria 3 to 5)
# ---------------------------------------------------------------------------------------------------------------
# sql <statement>: the answer of the statement on the database shop, without white space (one value).
sql() { kc exec statefulset/postgres -- psql -U shop -d shop -v ON_ERROR_STOP=1 -tAc "$1" | tr -d '[:space:]'; }

count_failed() { sql "SELECT count(*) FROM messenger_messages WHERE queue_name = 'failed'"; }

# set_suspend <cronjob> <true|false>
set_suspend() { kc patch cronjob "$1" --type merge -p "{\"spec\":{\"suspend\":$2}}" > /dev/null; }

# wait_cronjob_idle <cronjob>: the CronJob has no active Job (a Job of its schedule that runs would do the work of the phase).
wait_cronjob_idle() {
  deadline=$(( $(date +%s) + ${SMOKE_IDLE_WAIT:-660} ))
  while :; do
    active="$(kc get cronjob "$1" -o jsonpath='{.status.active[*].name}')"
    [ -n "$active" ] || return 0
    if [ "$(date +%s)" -ge "$deadline" ]; then
      echo "smoke: the Job $active of the CronJob $1 did not end in ${SMOKE_IDLE_WAIT:-660} s" >&2
      return 1
    fi
    sleep "${SMOKE_POLL:-5}"
  done
}

# wait_no_pods <selector> <limit>: no pod matches the label selector.
wait_no_pods() {
  deadline=$(( $(date +%s) + $2 ))
  while [ -n "$(kc get pods -l "$1" -o name)" ]; do
    if [ "$(date +%s)" -ge "$deadline" ]; then
      echo "smoke: the pods with $1 did not stop in $2 s" >&2
      kc get pods -l "$1" >&2 || true
      return 1
    fi
    sleep "${SMOKE_POLL:-5}"
  done
}

# wait_order_status <order id> <status> <limit>
wait_order_status() {
  deadline=$(( $(date +%s) + $3 ))
  while :; do
    current="$(sql "SELECT status FROM ordering_order WHERE id = '$1'")"
    [ "$current" != "$2" ] || return 0
    if [ "$(date +%s)" -ge "$deadline" ]; then
      echo "smoke: the order $1 is '$current' after $3 s, it must be $2" >&2
      return 1
    fi
    sleep "${SMOKE_POLL:-5}"
  done
}

# new_order <email>: places an order as a guest (checks.py); sets order_id.
new_order() {
  order_number="$(checks place-order "$1" | tail -n 1)"
  case "$order_number" in
    "" | *[!A-Za-z0-9-]*) echo "smoke: the order of $1 was not placed (got '$order_number')" >&2; return 1 ;;
  esac
  order_id="$(sql "SELECT id FROM ordering_order WHERE number = '$order_number'")"
  case "$order_id" in
    "" | *[!0-9a-f-]*) echo "smoke: no order $order_number in the database (got '$order_id')" >&2; return 1 ;;
  esac
}

# overdue_order <order id>: the payment deadline of the order two hours ago (past the window of the webhooks and the grace of the expiry).
overdue_order() {
  changed="$(sql "WITH overdue AS (UPDATE ordering_order SET payment_deadline = now() - interval '2 hours' WHERE id = '$1' AND status = 'new' RETURNING 1) SELECT count(*) FROM overdue")"
  [ "$changed" = 1 ] || { echo "smoke: the payment deadline of the order $1 was not moved ($changed rows)" >&2; return 1; }
}

# move_message <order id> <queue>: the delayed ExpireUnpaidOrder of the order is ready now, in the queue <queue> (scheduled: where the
# worker takes it; failed: where it lies after it failed).
move_message() {
  moved="$(sql "WITH moved AS (UPDATE messenger_messages SET queue_name = '$2', available_at = (now() AT TIME ZONE 'utc') - interval '1 minute', delivered_at = NULL WHERE queue_name = 'scheduled' AND body LIKE '%$1%' RETURNING 1) SELECT count(*) FROM moved")"
  case "$moved" in
    "" | 0 | *[!0-9]*) echo "smoke: no message of the order $1 was found in the queue scheduled (got '$moved')" >&2; return 1 ;;
  esac
}

# The criteria 3 to 5: the CronJob order-expiry is suspended for the whole phase, so that what cancels the orders is what is tested.
#   1. RabbitMQ scaled to zero, an order past its deadline with its message ready: worker-commands (no broker needed) cancels it within
#      two minutes; the broker comes back and every workload is ready (the pod of `worker` is deleted: it waits in a back-off);
#   2. a message in `failed`: a Job of queue-check fails, a Job of order-expiry cancels an order whose message lies there, and when the
#      message is removed a Job of queue-check succeeds.
check_messaging_failures() {
  messaging_started="$(date +%s)"
  set_suspend order-expiry true
  wait_cronjob_idle order-expiry
  failed_before="$(count_failed)"
  if [ "$failed_before" != 0 ]; then
    echo "smoke: $failed_before messages are in the transport failed before the phase (a message that failed in an earlier phase):" >&2
    kc exec statefulset/postgres -- psql -U shop -d shop -c "SELECT id, left(body, 120) AS body, created_at FROM messenger_messages WHERE queue_name = 'failed'" >&2 || true
    return 1
  fi

  new_order smoke-no-broker@example.com
  broker_order="$order_id"
  kc scale statefulset/rabbitmq --replicas=0 > /dev/null
  wait_no_pods app.kubernetes.io/name=rabbitmq "${SMOKE_BROKER_WAIT:-180}"
  overdue_order "$broker_order"
  move_message "$broker_order" scheduled
  wait_order_status "$broker_order" cancelled "${SMOKE_EXPIRY_WAIT:-120}"
  echo "ok: with RabbitMQ stopped worker-commands cancelled the order $order_number past its deadline"
  kc scale statefulset/rabbitmq --replicas=1 > /dev/null
  kc rollout status statefulset/rabbitmq --timeout=300s > /dev/null
  kc delete pod -l app.kubernetes.io/name=worker --wait=false > /dev/null
  wait_ready "RabbitMQ is back"

  new_order smoke-failed-message@example.com
  failed_order="$order_id"
  overdue_order "$failed_order"
  move_message "$failed_order" failed
  expect_job_failed queue-check queue-check-smoke-failed 180
  run_cronjob_job order-expiry order-expiry-smoke 600
  wait_order_status "$failed_order" cancelled "${SMOKE_EXPIRY_WAIT:-120}"
  echo "ok: the Job of order-expiry cancelled the order $order_number whose message is in failed"
  [ "$(count_failed)" = 1 ] || { echo "smoke: the message of the cancelled order is no more in failed: $(count_failed) messages" >&2; return 1; }
  message_id="$(sql "SELECT id FROM messenger_messages WHERE queue_name = 'failed' AND body LIKE '%$failed_order%'")"
  case "$message_id" in
    "" | *[!0-9]*) echo "smoke: cannot read the id of the message in failed (got '$message_id')" >&2; return 1 ;;
  esac
  kc exec deploy/worker-commands -- php -d memory_limit=128M bin/console messenger:failed:remove "$message_id" --force > /dev/null
  [ "$(count_failed)" = 0 ] || { echo "smoke: the message $message_id is still in failed after the removal" >&2; return 1; }
  run_cronjob_job queue-check queue-check-smoke-ok 180
  echo "ok: after the removal of the message the Job of queue-check succeeds"
  kc delete job queue-check-smoke-failed queue-check-smoke-ok order-expiry-smoke --now > /dev/null
  set_suspend order-expiry false
  echo "smoke: the phase of the failures of the messages took $(( $(date +%s) - messaging_started )) s"
}

# The snapshot of the backup: the tag nightly, the host of the cluster, the dumps of both databases, the object put into the
# bucket and a manifest with the revision, the migrations and the objects.
check_backup_snapshot() {
  cluster_uid="$(kubectl get namespace kube-system -o jsonpath='{.metadata.uid}')"
  snapshots="$(kc exec -c restic smoke-backup-tools -- restic snapshots --json --tag nightly)"
  case "$snapshots" in
    *"\"hostname\":\"$cluster_uid\""*) ;;
    *) echo "smoke: no snapshot with the host $cluster_uid and the tag nightly in the repository:" >&2; printf '%s\n' "$snapshots" >&2; return 1 ;;
  esac
  [ "$(printf '%s' "$snapshots" | grep -o '"tags":\["nightly"\]' | wc -l | tr -d ' ')" = 1 ] \
    || { echo "smoke: not exactly one snapshot with the tag nightly:" >&2; printf '%s\n' "$snapshots" >&2; return 1; }
  files="$(kc exec -c restic smoke-backup-tools -- restic ls --tag nightly latest)"
  for path in /work/dumps/shop.dump /work/dumps/keycloak.dump /work/objects/smoke-backup/object.txt /work/manifest.json; do
    printf '%s\n' "$files" | grep -qx "$path" || { echo "smoke: the snapshot lacks $path; it has:" >&2; printf '%s\n' "$files" >&2; return 1; }
  done
  manifest="$(kc exec -c restic smoke-backup-tools -- restic dump --tag nightly latest /work/manifest.json)"
  # The seed has put the demo images into the bucket too: the manifest counts what the bucket has.
  size="$(kc exec -c rclone smoke-backup-tools -- rclone size --json garage:product-images | tr -d ' \n')"
  objects="$(printf '%s' "$size" | sed -n 's/.*"count":\([0-9][0-9]*\),"bytes":\([0-9][0-9]*\).*/\1 \2/p')"
  [ -n "$objects" ] || { echo "smoke: cannot read the size of the bucket from: $size" >&2; return 1; }
  revision="$(kc get cronjob backup -o jsonpath='{.spec.jobTemplate.spec.template.spec.containers[0].env[?(@.name=="REVISION")].value}')"
  for expected in "\"revision\": \"$revision\"" "\"cluster\": \"$cluster_uid\"" '"databases": ["shop","keycloak"]' \
    "\"objects\": {\"count\": ${objects% *}, \"bytes\": ${objects#* }}" 'DoctrineMigrations\\Version'; do
    printf '%s\n' "$manifest" | grep -qF -- "$expected" || { echo "smoke: the manifest lacks $expected:" >&2; printf '%s\n' "$manifest" >&2; return 1; }
  done
  echo "ok: the snapshot nightly of the host $cluster_uid has the dumps of shop and keycloak, the object and a manifest of the revision $revision"
}

# The verification of that snapshot (the CronJob backup-verify, which the profile ci has read all the data): the Job succeeds, and
# its log shows the steps it took. The time of the verification is printed.
check_backup_verification() {
  verify_started="$(date +%s)"
  run_cronjob_job backup-verify backup-verify-smoke
  log="$(kc logs job/backup-verify-smoke -c verify)"
  for step in "backup-verify: restic check --read-data$" "the dump of shop restored" "the dump of keycloak restored" "the realms shop and backoffice are in keycloak" \
    "as in the manifest" "backup-verify: ok"; do
    printf '%s\n' "$log" | grep -q -- "$step" || { echo "smoke: the log of the verification lacks '$step':" >&2; printf '%s\n' "$log" >&2; return 1; }
  done
  echo "ok: the verification of the backups passed"
  echo "smoke: the verification phase took $(( $(date +%s) - verify_started )) s"
}

# The phase: the repository, one object in the bucket of the images, the Job of the CronJob, what it left in the repository and the
# verification of it.
check_backup() {
  backup_started="$(date +%s)"
  start_backup_repository
  create_backup_tools_pod
  kc exec -c rclone smoke-backup-tools -- sh -c 'printf "smoke object\n" | rclone rcat garage:product-images/smoke-backup/object.txt'
  run_cronjob_job backup backup-smoke
  check_backup_snapshot
  check_backup_verification
  # It holds the secrets of the repository and of the shop: gone as soon as nothing needs it.
  kc delete pod smoke-backup-tools --now > /dev/null
  sample_once
  echo "smoke: the backup phase took $(( $(date +%s) - backup_started )) s, the disk of the cluster after it: $(tail -n 1 "$work/samples" | cut -d' ' -f2) MiB"
}

# ---------------------------------------------------------------------------------------------------------------
# The restore on an empty cluster (docs/features/deploy-ops/spec.md, "Smoke test i check", steps 4 and 5)
# ---------------------------------------------------------------------------------------------------------------
# A word that only the database of the backup has in the name of a product (the index is not told): after the restore the search
# finds the product by it only if the index was rebuilt from the restored database.
restore_phrase=Smokerestore
mark_product_for_the_search() {
  restore_slug="$(kc exec statefulset/postgres -- psql -U shop -d shop -tAc 'SELECT slug FROM catalog_product ORDER BY slug LIMIT 1' | tr -d '[:space:]')"
  [ -n "$restore_slug" ] || { echo "smoke: no product to mark for the search" >&2; return 1; }
  kc exec statefulset/postgres -- psql -U shop -d shop -v ON_ERROR_STOP=1 -qc \
    "UPDATE catalog_product SET name = name || ' $restore_phrase' WHERE slug = '$restore_slug'" > /dev/null
}

# restore_with <answer> <snapshot> <name of the log>: make -C infra/k8s restore with the answer on the standard input.
restore_with() {
  printf '%s\n' "$1" | KUBE_DIR="$KUBE_DIR" KUBE_NETWORK="$KUBE_NETWORK" KUBE_NAMESPACE="$namespace" SNAPSHOT="$2" RESTORE_POLL_SECONDS=3 \
    sh "$here/restore.sh" > "$work/restore-$3.log" 2>&1
}

# make -C infra/k8s backups, and the newest snapshot with a tag in its list.
list_backups() { KUBE_DIR="$KUBE_DIR" KUBE_NETWORK="$KUBE_NETWORK" KUBE_NAMESPACE="$namespace" sh "$here/backups.sh"; }
snapshot_with_tag() { printf '%s\n' "$1" | awk -v tag="$2" '$4 == tag { id = $1 } END { print id }'; }

peak_disk() { awk '{ if ($2 > d) d = $2 } END { printf "%d", d }' "$work/samples"; }

check_restore() {
  restore_phase_started="$(date +%s)"
  backups="$(list_backups)" || { echo "smoke: make backups failed" >&2; return 1; }
  printf '%s\n' "$backups"
  nightly="$(snapshot_with_tag "$backups" nightly)"
  [ -n "$nightly" ] || { echo "smoke: make backups lists no snapshot nightly" >&2; return 1; }
  # Read first: a kubectl in the pipeline would take its standard input (docker run -i).
  cluster_uid="$(kubectl get namespace kube-system -o jsonpath='{.metadata.uid}')"
  printf '%s\n' "$backups" | grep -q "^$nightly  .*  $cluster_uid  nightly  [^ ?][^ ]*$" \
    || { echo "smoke: make backups does not give the time, the host, the tag and the revision of $nightly" >&2; return 1; }
  echo "ok: make backups lists the snapshot nightly $nightly with its time, host, tag and revision"

  echo "smoke: the namespace $namespace with its volumes deleted, the chart installed again"
  helm_cluster uninstall shop --namespace "$namespace" --wait > /dev/null
  kubectl delete namespace "$namespace" --wait=true --timeout=600s > /dev/null
  install r1
  create_tools_pod
  wait_ready "installed again"
  kc wait --for=condition=Ready pod/smoke-tools --timeout=180s > /dev/null
  read_secrets
  checks admin-absent "$admin_login" "$admin_email"
  [ "$(count ordering_order)" = 0 ] || { echo "smoke: the cluster installed again has orders" >&2; return 1; }
  echo "ok: the cluster installed again has the demo data only (no administrator, no orders)"

  restore_started="$(date +%s)"
  restore_with tak "$nightly" restored || { echo "smoke: make restore SNAPSHOT=$nightly failed:" >&2; cat "$work/restore-restored.log" >&2; dump_state; return 1; }
  restore_seconds=$(( $(date +%s) - restore_started ))
  grep '^restore: ' "$work/restore-restored.log"
  wait_ready "restored"
  checks restored "$admin_login" "$restore_slug" "$restore_phrase"
  [ "$(count catalog_product)" = "$products_at_backup" ] || { echo "smoke: $(count catalog_product) products after the restore, $products_at_backup in the backup" >&2; return 1; }
  [ "$(count ordering_order)" = "$orders_at_backup" ] || { echo "smoke: $(count ordering_order) orders after the restore, $orders_at_backup in the backup" >&2; return 1; }
  echo "ok: $products_at_backup products and $orders_at_backup orders, as in the backup"
  checks compare "$snapshot_at_backup"
  create_backup_tools_pod
  kc exec -c rclone smoke-backup-tools -- rclone lsf garage:product-images/smoke-backup/ | grep -qx object.txt \
    || { echo "smoke: the object of the backup is not in the bucket after the restore" >&2; return 1; }
  echo "ok: the object of the backup is in the bucket again"
  backups="$(list_backups)"
  pre_restore="$(snapshot_with_tag "$backups" pre-restore)"
  if [ -z "$pre_restore" ] || ! grep -q "pre-restore snapshot $pre_restore" "$work/restore-restored.log"; then
    echo "smoke: no snapshot pre-restore in the repository after the restore:" >&2; printf '%s\n' "$backups" >&2; return 1
  fi
  echo "ok: the snapshot pre-restore $pre_restore is in the repository"
  check_restore_refuses_a_newer_snapshot
  kc delete pod smoke-backup-tools --now > /dev/null
  sample_once
  echo "smoke: the restore phase took $(( $(date +%s) - restore_phase_started )) s (make restore itself $restore_seconds s), the peak disk of the cluster so far: $(peak_disk) MiB"
}

# A copy of the snapshot nightly whose manifest has one more migration (one the code does not know): make restore refuses it
# before the question, the backup pre-restore and any scaling, and the shop goes on as it was.
check_restore_refuses_a_newer_snapshot() {
  newer="$(kc exec -i -c restic smoke-backup-tools -- sh -s -- "$nightly" <<'SH'
set -eu
restic restore "$1:/work" --target /work > /dev/null
sed -i 's/"migrations": \[/"migrations": ["DoctrineMigrations\\\\Version29990101000000",/' /work/manifest.json
grep -q 'Version29990101000000' /work/manifest.json
restic backup --tag smoke-newer --host smoke-newer /work/dumps /work/objects /work/manifest.json | sed -n 's/^snapshot \([0-9a-f]*\) saved$/\1/p'
SH
)"
  [ -n "$newer" ] || { echo "smoke: could not make the snapshot with a newer migration" >&2; return 1; }
  pods_before="$(kc get pods -l 'app.kubernetes.io/name in (api,worker,worker-commands,store,keycloak)' -o jsonpath='{.items[*].metadata.name}')"
  pre_restore_before="$(list_backups | awk '$4 == "pre-restore"' | wc -l | tr -d ' ')"
  # A Job of the CronJob rate-limit-check that is running (a copy of the Job of the CronJob with the command `sleep`, so that it
  # lasts) is no reason for make restore to refuse: the refusal below comes from the snapshot, after the check of the active Jobs.
  kc create job rate-limit-check-busy --from=cronjob/rate-limit-check --dry-run=client -o yaml \
    | sed -e 's#^\( *\)- bin/console$#\1- sleep#' -e 's#^\( *\)- rate-limit:check$#\1- "400"#' -e 's#activeDeadlineSeconds: 60$#activeDeadlineSeconds: 600#' \
    | kc create -f - > /dev/null
  if [ "$(kc get job rate-limit-check-busy -o jsonpath='{.spec.template.spec.containers[0].command[0]}')" != sleep ]; then
    kc delete job rate-limit-check-busy --now --ignore-not-found > /dev/null || true
    echo "smoke: could not make a long Job of the CronJob rate-limit-check" >&2; return 1
  fi
  # The Job sleeps 400 s, far longer than the restore below takes even on a slow runner; it is read right after the restore and removed
  # at once, whatever the restore did.
  if restore_with tak "$newer" newer; then restore_exit=0; else restore_exit=1; fi
  busy_state="$(kc get job rate-limit-check-busy -o jsonpath='{.metadata.name}{" "}{.status.succeeded}{.status.failed}' || true)"
  kc delete job rate-limit-check-busy --now --ignore-not-found > /dev/null || true
  if [ "$restore_exit" = 0 ]; then
    echo "smoke: make restore accepted a snapshot newer than the code:" >&2; cat "$work/restore-newer.log" >&2; return 1
  fi
  [ "$busy_state" = "rate-limit-check-busy " ] \
    || { echo "smoke: the Job rate-limit-check-busy was not running while make restore refused (state: '$busy_state')" >&2; return 1; }
  if ! grep -q 'newer than the deployed code' "$work/restore-newer.log" || ! grep -q 'nothing changed' "$work/restore-newer.log"; then
    echo "smoke: make restore of a newer snapshot failed for another reason:" >&2; cat "$work/restore-newer.log" >&2; return 1
  fi
  if grep -q 'type tak' "$work/restore-newer.log"; then echo "smoke: make restore asked before it refused the newer snapshot" >&2; return 1; fi
  all_ready || { echo "smoke: the shop is not running after the refused restore" >&2; dump_state; return 1; }
  [ "$(kc get pods -l 'app.kubernetes.io/name in (api,worker,worker-commands,store,keycloak)' -o jsonpath='{.items[*].metadata.name}')" = "$pods_before" ] \
    || { echo "smoke: the refused restore restarted the applications" >&2; return 1; }
  if [ "$(count catalog_product)" != "$products_at_backup" ] || [ "$(count ordering_order)" != "$orders_at_backup" ]; then
    echo "smoke: the refused restore changed the data" >&2; return 1
  fi
  [ "$(list_backups | awk '$4 == "pre-restore"' | wc -l | tr -d ' ')" = "$pre_restore_before" ] \
    || { echo "smoke: the refused restore made a backup pre-restore" >&2; return 1; }
  for cronjob in backup cart-purge-expired rate-limit-check queue-check order-expiry; do
    [ "$(kc get cronjob "$cronjob" -o jsonpath='{.spec.suspend}')" != true ] || { echo "smoke: the refused restore suspended $cronjob" >&2; return 1; }
  done
  echo "ok: make restore refused the snapshot $newer with a migration the code does not know; the shop runs, the data is unchanged"
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

# The demo data generator (docs/features/demo-data/spec.md, "k3d (chart w profilach ci i load; część 2)"): ONE Job from the CronJob demo-data,
# which the chart renders suspended with the profile small. It is created exactly once (concurrencyPolicy does not guard against two
# Jobs made by hand). The Job deletes the data of every module and generates the profile on top of the seed (bin/generate-demo), so
# the phase runs last. Kubernetes fails the Job at its activeDeadlineSeconds; the wait ends a little after that (a Job that never
# starts is no Job at all). SMOKE_BREAK=demo-data gives the generator a profile it refuses, before it changes anything.
check_demo_data() {
  demo_data_started="$(date +%s)"
  generator="$(kc get cronjob demo-data -o jsonpath='{.spec.jobTemplate.spec.template.spec.containers[0].command}')"
  case "$generator" in
    *--profile=small*) ;;
    *) echo "smoke: the CronJob demo-data does not run the profile small (--profile=small): $generator" >&2; return 1 ;;
  esac
  if [ "$SMOKE_BREAK" = demo-data ]; then
    generator_arg=/spec/jobTemplate/spec/template/spec/containers/0/command/1
    kc patch cronjob demo-data --type=json \
      -p "[{\"op\": \"test\", \"path\": \"$generator_arg\", \"value\": \"--profile=small\"}, {\"op\": \"replace\", \"path\": \"$generator_arg\", \"value\": \"--profile=smoke-unknown\"}]" > /dev/null
  fi
  # The upgrade r4 before this phase gave Elasticsearch a new image and ended on the failed initialize, not on a settled cluster:
  # the generator resets the search index first and fails at once on an Elasticsearch whose pod is being replaced.
  wait_statefulsets "before the generator"
  demo_data_limit="$(kc get cronjob demo-data -o jsonpath='{.spec.jobTemplate.spec.activeDeadlineSeconds}')"
  run_cronjob_job demo-data demo-data-smoke $(( demo_data_limit + ${SMOKE_DEMO_DATA_MARGIN:-120} ))
  # The generator ran its own consistency check (demo-data:verify) before it ended with 0; here only that it replaced the data.
  demo_data_products="$(count catalog_product)"
  demo_data_orders="$(count ordering_order)"
  # A count that cannot be read (a failed exec gives an empty answer) is a failure, never a pass.
  for demo_data_count in "$demo_data_products" "$demo_data_orders"; do
    case "$demo_data_count" in
      "" | *[!0-9]*) echo "smoke: cannot read the numbers of products and orders after the generator ('$demo_data_products', '$demo_data_orders')" >&2; return 1 ;;
    esac
  done
  if [ "$demo_data_products" -lt 2000 ] || [ "$demo_data_orders" -lt 5000 ]; then
    echo "smoke: the Job of the generator ended with 0 but did not replace the data: $demo_data_products products and $demo_data_orders orders (the profile small: 2000 products and 5000 orders besides the seed)" >&2
    kc logs job/demo-data-smoke --all-containers --prefix --tail=40 >&2 || true
    return 1
  fi
  echo "ok: the generator (profile small) ended with 0: $demo_data_products products and $demo_data_orders orders"
  echo "smoke: the demo data phase took $(( $(date +%s) - demo_data_started )) s"
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

  log "rate-limit-check"
  check_rate_limit_check

  log "the store in a browser"
  check_store_in_browser

  log "the panel in a browser"
  check_admin_in_browser

  log "create-admin"
  check_create_admin

  log "messages: RabbitMQ stopped, a message in failed"
  check_messaging_failures

  log "backup"
  mark_product_for_the_search
  products_at_backup="$(count catalog_product)"
  orders_at_backup="$(count ordering_order)"
  snapshot_at_backup="$(checks snapshot | tail -n 1)"
  check_backup

  log "restore on an empty cluster"
  check_restore

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
for app in api worker worker-commands; do
  serving="$(kc get pods -l "app.kubernetes.io/name=$app" -o jsonpath='{range .items[?(@.status.phase=="Running")]}{.spec.containers[0].image}{" "}{.status.conditions[?(@.type=="Ready")].status}{"\n"}{end}')"
  echo "$serving" | grep -q "$registry/api:r2 True" || { echo "smoke: the previous $app pod does not serve:" >&2; echo "$serving" >&2; exit 1; }
  if echo "$serving" | grep -q "$registry/api:r3 True"; then echo "smoke: the new $app pod became ready on the failed migration" >&2; exit 1; fi
done
status="$(kc exec smoke-tools -- python -I -c "
import urllib.request
r = urllib.request.Request('http://traefik.kube-system.svc.cluster.local/health', headers={'Host': 'api.localhost'})
print(urllib.request.urlopen(r, timeout=30).read().decode())")"
echo "$status" | grep -q '"status":"ok"' || { echo "smoke: the previous api does not answer /health: $status" >&2; exit 1; }
echo "smoke: the previous api and both workers keep serving, the new ones are not ready"

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

log "demo data generator (profile small)"
check_demo_data

log "peaks"
report_peaks

#!/bin/sh
# The smoke test of the application monitoring (docs/features/deploy-ops/spec.md, O5 and O7): the chart k8s-monitoring of the
# pinned version with the values of argocd/apps/monitoring.yaml, installed on an empty k3d cluster, runs and has no more rights
# than the RBAC of the manifest gives it.
#
#   monitoring-smoke.sh                                   the whole test (a few minutes; the first run pulls the images)
#   MONITORING_SMOKE_KEEP=1 monitoring-smoke.sh           keep the cluster after a failure, to look at it (delete it by hand)
#   MONITORING_SMOKE_BREAK=operator-cluster-role ...      installs the operator with the ClusterRole of its chart (rbac.create):
#                                                         the test must fail on the rights of the operator
#   MONITORING_SMOKE_TIMEOUT=600                          seconds to wait for each of the phases that wait
#
# Phases: the cluster (k3d, the k3s of tools.sh); the namespace shop with dummy Secrets grafana-cloud, shop-backup and
# shop-secrets and a pod that logs a line with a password and an e-mail address; a receiver outside the cluster, a container
# on its Docker network (Grafana Alloy of ALLOY_IMAGE: loki.source.api and prometheus.receive_http, printed by loki.echo and
# prometheus.echo; inside the cluster Alloy would collect the log of the receiver and send it to the receiver again); the
# CRDs of prometheus-operator-crds and the chart k8s-monitoring from the archives that scripts/monitoring-render.sh downloads and
# checks (sha256), installed with the valuesObject of the manifest and one overlay: the addresses of the destinations, pointed at
# the receiver (never the RBAC). Then it waits for the operator and for the Alloy it makes, and for the receiver to get the line
# of the pod masked ([REDACTED], neither the password nor the address), a cluster event and the metrics of kube-state-metrics,
# node-exporter, the kubelet and cAdvisor; no log of the operator, Alloy or kube-state-metrics says forbidden. Last,
# kubectl auth can-i for the service accounts of the operator, Alloy, kube-state-metrics and node-exporter: no Secret of shop
# or kube-system, no bind, escalate or impersonate, no change of RBAC objects, no pod in shop; Alloy reads only the Secret
# grafana-cloud, the operator works in monitoring. Every mismatch is listed and fails the test. The time of each phase is
# printed; the cluster and the receiver are deleted on every exit. Needs only Docker (and the network for the images and, once, the charts).
#
# The tools run in containers on the Docker network of the cluster (scripts/tools.sh); the cluster is called
# mon-<COMPOSE_PROJECT_NAME>, so it runs beside the one of smoke.sh.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

MONITORING_SMOKE_BREAK="${MONITORING_SMOKE_BREAK:-}"
MONITORING_SMOKE_TIMEOUT="${MONITORING_SMOKE_TIMEOUT:-600}"
case "$MONITORING_SMOKE_BREAK" in
  "" | operator-cluster-role) ;;
  *) echo "monitoring-smoke: MONITORING_SMOKE_BREAK must be empty or operator-cluster-role, got '$MONITORING_SMOKE_BREAK'" >&2; exit 2 ;;
esac
docker info > /dev/null 2>&1 || { echo "monitoring-smoke: Docker does not answer (docker info failed): it is the only tool needed" >&2; exit 2; }

log() { printf '\n== %s (%ss)\n' "$1" "$(( $(date +%s) - started ))"; }
started="$(date +%s)"

project="${COMPOSE_PROJECT_NAME:-}"
if [ -z "$project" ] && [ -f "$TOOLS_REPO/.env" ]; then
  project="$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' "$TOOLS_REPO/.env" | head -n 1)"
fi
project="$(printf '%s' "${project:-shop}" | tr 'A-Z_' 'a-z-' | tr -c 'a-z0-9\n-' '-')"
cluster="mon-$project"
if [ "${#cluster}" -gt 32 ]; then
  cluster="$(printf '%s' "$cluster" | cut -c1-22)-$(printf '%s' "$project" | cksum | cut -d' ' -f1 | cut -c1-8)"
fi
node="k3d-$cluster-server-0"
KUBE_NETWORK="k3d-$cluster"
mkdir -p "$TOOLS_CACHE"
# The kubeconfig, the render of monitoring-render.sh and the overlay, in one directory of the cache (the containers mount it
# as /cache/<name>).
KUBE_DIR="$(mktemp -d "$TOOLS_CACHE/monitoring-smoke-XXXXXX")"
in_cache="/cache/${KUBE_DIR##*/}"
receiver_name="$cluster-receiver"
# The configuration of the receiver, in a directory of its own that only its container mounts.
receiver_dir="$(mktemp -d "${TMPDIR:-/tmp}/shop-monitoring-receiver-XXXXXX")"
marker="smoke-marker-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
marker_password="Sekret-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
marker_email="jan.kowalski.$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')@example.com"

cleanup() {
  status=$?
  trap - EXIT INT TERM
  if [ "${MONITORING_SMOKE_KEEP:-}" = 1 ] && [ "$status" -ne 0 ]; then
    echo "monitoring-smoke: MONITORING_SMOKE_KEEP=1, the failed cluster $cluster and the receiver stay:" \
      "k3d cluster delete $cluster; docker rm -f $receiver_name (kubeconfig: $KUBE_DIR/config)" >&2
    exit "$status"
  fi
  echo "monitoring-smoke: deleting the cluster $cluster and the receiver"
  docker rm -f "$receiver_name" > /dev/null 2>&1 || true
  k3d cluster delete "$cluster" > /dev/null 2>&1 || echo "monitoring-smoke: could not delete the cluster $cluster" >&2
  rm -rf "$KUBE_DIR" "$receiver_dir"
  if [ "$status" -eq 0 ]; then echo "monitoring-smoke: ok"; else echo "monitoring-smoke: FAILED (exit $status)" >&2; fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkc() { kubectl -n monitoring "$@"; }

dump_state() {
  echo "monitoring-smoke: the pods of the cluster:" >&2
  kubectl get pods -A -o wide >&2 || true
  for target in deployment/monitoring-alloy-operator deployment/monitoring-alloy; do
    echo "monitoring-smoke: the end of the log of $target:" >&2
    mkc logs "$target" --all-containers --tail=30 >&2 2>&1 || true
  done
}

# wait_for <description> <command...>: runs the command every 5 s until it succeeds, at most MONITORING_SMOKE_TIMEOUT seconds.
wait_for() {
  what="$1"
  shift
  deadline=$(( $(date +%s) + MONITORING_SMOKE_TIMEOUT ))
  until "$@"; do
    if [ "$(date +%s)" -ge "$deadline" ]; then
      echo "monitoring-smoke: $what: not in ${MONITORING_SMOKE_TIMEOUT} s" >&2
      dump_state
      return 1
    fi
    sleep 5
  done
  echo "monitoring-smoke: $what ($(( $(date +%s) - started )) s)"
}

ready() { [ "$(mkc get deployment "$1" -o jsonpath='{.status.readyReplicas}' 2> /dev/null)" = 1 ]; }
api_ready() { kubectl get --raw /readyz > /dev/null 2>&1; }
receiver_ready() { docker logs "$receiver_name" 2>&1 | grep -q 'now listening for http traffic'; }

# ---------------------------------------------------------------------------------------------------------------
log "charts (scripts/monitoring-render.sh: the pinned versions, sha256 checked)"
sh "$here/monitoring-render.sh" "$KUBE_DIR" > /dev/null
monitoring_archive="$(yq -p json '.charts | to_entries | .[] | select(.key == "k8s-monitoring") | .key + "-" + .value.version' < "$KUBE_DIR/monitoring-chart.json")"
crds_archive="$(yq -p json '.charts | to_entries | .[] | select(.key == "prometheus-operator-crds") | .key + "-" + .value.version' < "$KUBE_DIR/monitoring-chart.json")"
release="$(yq '.spec.source.helm.releaseName' < "$TOOLS_REPO/infra/k8s/argocd/apps/monitoring.yaml")"
echo "monitoring-smoke: $monitoring_archive and $crds_archive"

log "cluster $cluster ($K3S_IMAGE)"
k3d cluster delete "$cluster" > /dev/null 2>&1 || true
k3d cluster create "$cluster" --no-lb --image "$K3S_IMAGE" --wait --timeout 300s \
  --k3s-arg '--disable=metrics-server@server:*' --k3s-arg '--disable=servicelb@server:*' --k3s-arg '--disable=traefik@server:*' > /dev/null
k3d kubeconfig get "$cluster" | sed -E "s#server: https://[^:]+:[0-9]+#server: https://$node:6443#" > "$KUBE_DIR/config"
wait_for "the API of the cluster answers" api_ready

log "the receiver ($receiver_name, on the Docker network of the cluster)"
cat > "$receiver_dir/config.alloy" <<'ALLOY'
loki.source.api "in" {
  http {
    listen_address = "0.0.0.0"
    listen_port    = 3100
  }
  forward_to = [loki.echo.out.receiver]
}

loki.echo "out" {}

prometheus.receive_http "in" {
  http {
    listen_address = "0.0.0.0"
    listen_port    = 9009
  }
  forward_to = [prometheus.relabel.wanted.receiver]
}

// Only the metrics the test looks for are printed.
prometheus.relabel "wanted" {
  rule {
    source_labels = ["__name__"]
    regex         = "kube_deployment_spec_replicas|node_filesystem_avail_bytes|kubelet_running_pods|container_cpu_usage_seconds_total"
    action        = "keep"
  }
  forward_to = [prometheus.echo.out.receiver]
}

prometheus.echo "out" {}
ALLOY
chmod 755 "$receiver_dir"
chmod 644 "$receiver_dir/config.alloy"
docker rm -f "$receiver_name" > /dev/null 2>&1 || true
docker run -d --name "$receiver_name" --network "$KUBE_NETWORK" -v "$receiver_dir:/receiver:ro" "$ALLOY_IMAGE" \
  run --storage.path=/tmp/alloy /receiver/config.alloy > /dev/null
wait_for "the receiver listens" receiver_ready
receiver_ip="$(docker inspect -f "{{ (index .NetworkSettings.Networks \"$KUBE_NETWORK\").IPAddress }}" "$receiver_name")"
[ -n "$receiver_ip" ] || { echo "monitoring-smoke: the receiver has no address on $KUBE_NETWORK" >&2; exit 1; }
# The one overlay on the values of the manifest: the destinations send to the receiver over HTTP. Nothing else, and never the
# RBAC: what runs is what the manifest gives.
cat > "$KUBE_DIR/overlay.yaml" <<YAML
destinations:
  grafana-metrics:
    url: http://$receiver_ip:9009/api/v1/metrics/write
    rules:
      address: http://$receiver_ip:9009
  grafana-logs:
    url: http://$receiver_ip:3100/loki/api/v1/push
YAML

log "the namespace shop (dummy Secrets, a pod that logs a password and an e-mail address)"
kubectl apply -f - > /dev/null <<YAML
apiVersion: v1
kind: Namespace
metadata:
  name: shop
---
apiVersion: v1
kind: Secret
metadata:
  name: grafana-cloud
  namespace: shop
stringData:
  GRAFANA_CLOUD_TOKEN: dummy-grafana-token
---
apiVersion: v1
kind: Secret
metadata:
  name: shop-backup
  namespace: shop
stringData:
  RESTIC_PASSWORD: dummy-restic-password
  BACKUP_S3_ACCESS_KEY_ID: dummy-key-id
  BACKUP_S3_SECRET_ACCESS_KEY: dummy-secret-key
---
apiVersion: v1
kind: Secret
metadata:
  name: shop-secrets
  namespace: shop
stringData:
  APP_SECRET: dummy-app-secret
YAML
default_account() { kubectl -n shop get serviceaccount default > /dev/null 2>&1; }
wait_for "the namespace shop has its default service account" default_account
kubectl apply -f - > /dev/null <<YAML
apiVersion: v1
kind: Pod
metadata:
  name: $marker
  namespace: shop
spec:
  restartPolicy: Always
  containers:
    - name: marker
      image: $ALLOY_IMAGE
      command: [/bin/sh, -c]
      args:
        - while true; do echo "$marker user logged in password=$marker_password email=$marker_email"; sleep 5; done
YAML

log "CRDs ($crds_archive) and the monitoring ($monitoring_archive, the values of argocd/apps/monitoring.yaml)"
helm_cluster install prometheus-crds "/cache/charts/$crds_archive.tgz" --namespace kube-system \
  -f "$in_cache/prometheus-crds-values.yaml" > /dev/null
break_args=""
if [ "$MONITORING_SMOKE_BREAK" = operator-cluster-role ]; then
  # The regression the test must catch: the RBAC of the chart of the operator (a ClusterRole with all Secrets, bind, escalate).
  break_args="--set alloy-operator.rbac.create=true"
  echo "monitoring-smoke: MONITORING_SMOKE_BREAK=operator-cluster-role: $break_args (the test must fail)"
fi
# shellcheck disable=SC2086 # $break_args is an option and its value
helm_cluster install "$release" "/cache/charts/$monitoring_archive.tgz" --namespace monitoring --create-namespace \
  -f "$in_cache/monitoring-values.yaml" -f "$in_cache/overlay.yaml" $break_args > /dev/null

log "the operator and the Alloy it makes"
wait_for "the operator is ready" ready monitoring-alloy-operator
wait_for "the Deployment monitoring-alloy is ready" ready monitoring-alloy

log "what the receiver gets"
receiver_log="$KUBE_DIR/receiver.log"
fetch_receiver() { docker logs "$receiver_name" > "$receiver_log" 2>&1; }
received() { fetch_receiver && grep -q -- "$1" "$receiver_log"; }
masked_marker() { fetch_receiver && grep -- "$marker user logged in" "$receiver_log" | grep -q 'REDACTED'; }
event() { fetch_receiver && grep 'integrations/kubernetes/eventhandler' "$receiver_log" | grep -q -- "$marker"; }
wait_for "the line of the pod, masked" masked_marker
wait_for "a cluster event of the pod" event
for metric in kube_deployment_spec_replicas node_filesystem_avail_bytes kubelet_running_pods container_cpu_usage_seconds_total; do
  wait_for "the metric $metric" received "$metric"
done
fetch_receiver
echo "monitoring-smoke: a line of the pod as received:"
grep -m 1 -- "$marker user logged in" "$receiver_log"
echo "monitoring-smoke: a cluster event as received:"
grep 'integrations/kubernetes/eventhandler' "$receiver_log" | grep -m 1 -- "$marker"
failures=0
fail() { echo "monitoring-smoke: FAIL $1" >&2; failures=$((failures + 1)); }
if grep -qF -- "$marker_password" "$receiver_log"; then fail "the password of the pod came out of Alloy"; fi
if grep -qF -- "$marker_email" "$receiver_log"; then fail "the e-mail address of the pod came out of Alloy"; fi
for target in deployment/monitoring-alloy-operator deployment/monitoring-alloy deployment/monitoring-kube-state-metrics; do
  if forbidden="$(mkc logs "$target" --all-containers 2>&1 | grep -i 'forbidden')"; then
    fail "the log of $target says forbidden:"
    printf '%s\n' "$forbidden" | head -n 10 >&2
  fi
done

log "the rights of the service accounts (kubectl auth can-i)"
# can <yes|no> <service account of monitoring> <arguments of kubectl auth can-i>
can() {
  expected="$1" account="$2"
  shift 2
  got="$(kubectl auth can-i --as="system:serviceaccount:monitoring:$account" "$@" 2> /dev/null | head -n 1)" || true
  if [ "$got" = "$expected" ]; then
    printf '  ok        %-30s %-3s %s\n' "$account" "$got" "$*"
  else
    printf '  MISMATCH  %-30s %-3s %s (expected %s)\n' "$account" "${got:-error}" "$*" "$expected"
    failures=$((failures + 1))
  fi
}
operator=monitoring-alloy-operator
alloy=monitoring-alloy
for account in $operator $alloy monitoring-kube-state-metrics monitoring-node-exporter; do
  for namespace in shop kube-system; do
    for verb in get list watch create update patch delete; do can no "$account" "$verb" secrets -n "$namespace"; done
  done
  for secret in shop-backup shop-secrets; do
    for verb in get update delete; do can no "$account" "$verb" "secret/$secret" -n shop; done
  done
  can no "$account" list secrets --all-namespaces
  for verb in bind escalate; do
    can no "$account" "$verb" clusterroles
    for namespace in shop monitoring; do can no "$account" "$verb" roles -n "$namespace"; done
  done
  can no "$account" create clusterroles
  can no "$account" create clusterrolebindings
  for namespace in shop monitoring; do
    can no "$account" create roles -n "$namespace"
    can no "$account" create rolebindings -n "$namespace"
  done
  can no "$account" impersonate serviceaccounts
  can no "$account" impersonate users
  can no "$account" impersonate groups
  can no "$account" create serviceaccounts/token -n shop
  can no "$account" create pods -n shop
  can no "$account" create pods/exec -n shop
  can no "$account" list configmaps -n shop
done
# Alloy: its token and what it reads in the cluster, and nothing in monitoring it would not need.
can yes $alloy get secret/grafana-cloud -n shop
for check in "list nodes" "get nodes/metrics" "list pods --all-namespaces" "get pods/log -n shop" "list events --all-namespaces" \
  "list endpoints -n monitoring" "list prometheusrules.monitoring.coreos.com --all-namespaces"; do
  # shellcheck disable=SC2086 # the words of the check are arguments
  can yes $alloy $check
done
can no $alloy list secrets -n monitoring
can no $alloy create deployments -n monitoring
# The operator: in monitoring, what the Alloy chart makes and its Helm releases; nothing elsewhere.
for check in "create alloys.collectors.grafana.com -n monitoring" "update alloys.collectors.grafana.com/status -n monitoring" \
  "create deployments.apps -n monitoring" "create secrets -n monitoring" "create serviceaccounts -n monitoring" \
  "create services -n monitoring" "create configmaps -n monitoring" "update leases.coordination.k8s.io -n monitoring" "create events -n monitoring"; do
  # shellcheck disable=SC2086 # the words of the check are arguments
  can yes $operator $check
done
for check in "list alloys.collectors.grafana.com --all-namespaces" "create deployments.apps -n shop" "list deployments.apps -n shop" \
  "create serviceaccounts -n shop" "list pods -n monitoring" "list nodes"; do
  # shellcheck disable=SC2086 # the words of the check are arguments
  can no $operator $check
done
# kube-state-metrics reads the objects it counts, node-exporter nothing.
can yes monitoring-kube-state-metrics list deployments.apps --all-namespaces
can no monitoring-node-exporter list pods -n shop
for account in $operator $alloy; do
  for namespace in shop monitoring; do
    echo "monitoring-smoke: kubectl auth can-i --list --as=system:serviceaccount:monitoring:$account -n $namespace"
    kubectl auth can-i --list --as="system:serviceaccount:monitoring:$account" -n "$namespace"
  done
done

if [ "$failures" -ne 0 ]; then
  echo "monitoring-smoke: $failures check(s) failed" >&2
  exit 1
fi
echo "monitoring-smoke: everything checked ($(( $(date +%s) - started )) s)"

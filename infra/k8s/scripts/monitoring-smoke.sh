#!/bin/sh
# The smoke test of the application monitoring (docs/features/deploy-ops/spec.md, O5 and O7): the chart k8s-monitoring of the
# pinned version with the values of argocd/apps/monitoring.yaml, installed on an empty k3d cluster, runs, has no more rights
# than the RBAC of the manifest gives it, and its admission policies refuse in monitoring what reaches the host or the node.
#
#   monitoring-smoke.sh                                   the whole test (a few minutes; the first run pulls the images)
#   MONITORING_SMOKE_KEEP=1 monitoring-smoke.sh           keep the cluster after a failure, to look at it (delete it by hand)
#   MONITORING_SMOKE_BREAK=operator-cluster-role ...      installs the operator with the ClusterRole of its chart (rbac.create):
#                                                         the test must fail on the rights of the operator
#   MONITORING_SMOKE_BREAK=no-admission-policy ...        installs the extraObjects of the manifest without the
#                                                         ValidatingAdmissionPolicies and their bindings: the test must fail
#   MONITORING_SMOKE_TIMEOUT=600                          seconds to wait for each of the phases that wait
#
# Phases: the cluster (k3d, the k3s of tools.sh); the namespace shop with dummy Secrets grafana-cloud, shop-backup and
# shop-secrets and a pod that logs a line with a password and an e-mail address; a receiver outside the cluster, a container
# on its Docker network (Grafana Alloy of ALLOY_IMAGE: loki.source.api and prometheus.receive_http, printed by loki.echo and
# prometheus.echo; inside the cluster Alloy would collect the log of the receiver and send it to the receiver again); the
# CRDs of prometheus-operator-crds and the chart k8s-monitoring from the archives that scripts/monitoring-render.sh downloads and
# checks (sha256), installed with the valuesObject of the manifest and one overlay: the addresses of the destinations, pointed at
# the receiver (never the RBAC). Then it waits for the operator and for the Alloy it makes. The admission policies: requests made
# with --dry-run=server, as the service account of the operator (Deployments with hostPath /, hostNetwork, hostPID, hostIPC,
# hostPort, privileged, an added capability, a privileged init container, a priority class of the system, the labels of
# node-exporter, a template with an ownerReference to its DaemonSet; Services of the types NodePort, LoadBalancer, ExternalName and
# a ClusterIP with externalIPs, the Service of its Alloy changed to them; a Secret with a token of a service account) and as the
# administrator of the cluster (pods with the labels or a forged ownerReference of node-exporter, the other kinds of workloads, an
# ephemeral or another image in the pod of node-exporter, a pod and a CronJob with a priority class of the system, Services that
# are not a ClusterIP or have externalIPs, the Service of kube-state-metrics changed to a LoadBalancer) are refused by the policy
# (its name in the error, not RBAC), while a plain Deployment, a ClusterIP and a headless Service of the operator, a plain
# ephemeral container, an Opaque Secret and, in shop, a pod with hostPath, a pod with system-cluster-critical and a NodePort
# Service are not; then every pod of monitoring is deleted and must come back
# ready under the policies (node-exporter among them). Then the receiver must get the line of the pod masked ([REDACTED], neither
# the password nor the address), a cluster event and the metrics of kube-state-metrics, node-exporter, the kubelet and cAdvisor
# (the metrics after the pods came back); no log of the operator, Alloy or kube-state-metrics, before and after, says forbidden.
# Last, kubectl auth can-i for the service accounts of the operator, Alloy, kube-state-metrics and node-exporter: no Secret of shop
# or kube-system, no bind, escalate or impersonate, no change of RBAC objects, no pod in shop, no exec, attach, port-forward,
# ephemeral container or token of a service account in shop and kube-system, no proxy of a node; Alloy reads only the Secret
# grafana-cloud; the operator works in monitoring, makes Deployments but no DaemonSet, StatefulSet, pod or Alloy resource, and
# changes only the objects of its Alloy. Every mismatch is listed and fails the test. The time of each phase is
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
  "" | operator-cluster-role | no-admission-policy) ;;
  *) echo "monitoring-smoke: MONITORING_SMOKE_BREAK must be empty, operator-cluster-role or no-admission-policy, got '$MONITORING_SMOKE_BREAK'" >&2; exit 2 ;;
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
  echo "monitoring-smoke: the refusals of the admission policies in monitoring:" >&2
  mkc get events -o custom-columns=OBJECT:.involvedObject.name,MESSAGE:.message 2>&1 | grep -i 'denied' | tail -n 10 >&2 || true
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
daemon_set_ready() { [ "$(mkc get daemonset "$1" -o jsonpath='{.status.numberReady}' 2> /dev/null)" = 1 ]; }
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
elif [ "$MONITORING_SMOKE_BREAK" = no-admission-policy ]; then
  # The regression the test must catch: the namespace monitoring without the admission policies (a values file with the
  # extraObjects of the manifest but them; Helm replaces a list as a whole).
  yq '{"extraObjects": (.extraObjects | map(select(.kind != "ValidatingAdmissionPolicy" and .kind != "ValidatingAdmissionPolicyBinding")))}' \
    < "$KUBE_DIR/monitoring-values.yaml" > "$KUBE_DIR/break.yaml"
  break_args="-f $in_cache/break.yaml"
  echo "monitoring-smoke: MONITORING_SMOKE_BREAK=no-admission-policy: no ValidatingAdmissionPolicy (the test must fail)"
fi
# shellcheck disable=SC2086 # $break_args is an option and its value
helm_cluster install "$release" "/cache/charts/$monitoring_archive.tgz" --namespace monitoring --create-namespace \
  -f "$in_cache/monitoring-values.yaml" -f "$in_cache/overlay.yaml" $break_args > /dev/null

log "the operator and the Alloy it makes"
wait_for "the operator is ready" ready monitoring-alloy-operator
wait_for "the Deployment monitoring-alloy is ready" ready monitoring-alloy

failures=0
fail() { echo "monitoring-smoke: FAIL $1" >&2; failures=$((failures + 1)); }
operator=monitoring-alloy-operator
alloy=monitoring-alloy

log "the admission policies of monitoring (requests with --dry-run=server)"
host_policy=monitoring-no-host-access
token_policy=monitoring-no-service-account-tokens
service_policy=monitoring-cluster-ip-services
# admission <refused-by <policy>|allowed> <operator|admin> <namespace> <description> [<kubectl verb and arguments>]: sends the
# manifest of the standard input (kubectl create -f -, or the given command) as that user; a refusal must name the policy.
admission() {
  expected="$1" as="$2" namespace="$3" what="$4"
  shift 4
  [ "$#" -gt 0 ] || set -- create -f -
  as_args=""
  [ "$as" = operator ] && as_args="--as=system:serviceaccount:monitoring:$operator"
  # shellcheck disable=SC2086 # $as_args is one option or none
  if output="$(kubectl -n "$namespace" $as_args "$@" --dry-run=server 2>&1)"; then got=allowed; else got=refused; fi
  case "$expected" in
    refused-by-*)
      policy="${expected#refused-by-}"
      if [ "$got" = refused ] && printf '%s' "$output" | grep -qF "ValidatingAdmissionPolicy '$policy'"; then
        printf '  ok        %-8s refused by %s: %s\n' "$as" "$policy" "$what"
      else
        printf '  MISMATCH  %-8s %s, expected refused by %s: %s\n    %s\n' "$as" "$got" "$policy" "$what" "$output"
        failures=$((failures + 1))
      fi ;;
    allowed)
      if [ "$got" = allowed ]; then
        printf '  ok        %-8s allowed: %s\n' "$as" "$what"
      else
        printf '  MISMATCH  %-8s refused, expected allowed: %s\n    %s\n' "$as" "$what" "$output"
        failures=$((failures + 1))
      fi ;;
  esac
}
# workload <kind> <name> <JSON of the pod spec> [<JSON of the labels of the pod>] [<JSON of more metadata of the pod>]: one line of
# JSON, a workload of that kind whose pods have that spec (a CronJob: in its job template).
workload() {
  labels="${4:-}" more="${5:-}"
  [ -n "$labels" ] || labels="{\"smoke\":\"$2\"}"
  template="{\"metadata\":{\"labels\":$labels${more:+,$more}},\"spec\":$3}"
  case "$1" in
    Job) printf '{"apiVersion":"batch/v1","kind":"Job","metadata":{"name":"%s"},"spec":{"template":%s}}\n' "$2" "$template" ;;
    CronJob) printf '{"apiVersion":"batch/v1","kind":"CronJob","metadata":{"name":"%s"},"spec":{"schedule":"0 0 * * *","jobTemplate":{"spec":{"template":%s}}}}\n' "$2" "$template" ;;
    Pod) printf '{"apiVersion":"v1","kind":"Pod","metadata":{"name":"%s","labels":%s%s},"spec":%s}\n' "$2" "$labels" "${more:+,$more}" "$3" ;;
    *) printf '{"apiVersion":"apps/v1","kind":"%s","metadata":{"name":"%s"},"spec":{"selector":{"matchLabels":%s},%s"template":%s}}\n' \
      "$1" "$2" "$labels" "$([ "$1" = StatefulSet ] && printf '"serviceName":"%s",' "$2")" "$template" ;;
  esac
}
# try_workload <expected> <as> <namespace> <description> <arguments of workload>: admission of that workload (not in a pipeline:
# admission counts the failures in this shell).
try_workload() {
  t_expected="$1" t_as="$2" t_namespace="$3" t_what="$4"
  shift 4
  workload "$@" > "$KUBE_DIR/request.json"
  admission "$t_expected" "$t_as" "$t_namespace" "$t_what" < "$KUBE_DIR/request.json"
}
plain='{"containers":[{"name":"c","image":"busybox"}]}'
on_root='{"containers":[{"name":"c","image":"busybox","volumeMounts":[{"name":"root","mountPath":"/host"}]}],"volumes":[{"name":"root","hostPath":{"path":"/"}}]}'
node_exporter_labels="$(mkc get daemonset monitoring-node-exporter -o jsonpath='{.spec.template.metadata.labels}')"
node_exporter_uid="$(mkc get daemonset monitoring-node-exporter -o jsonpath='{.metadata.uid}')"
forged_owner="\"ownerReferences\":[{\"apiVersion\":\"apps/v1\",\"kind\":\"DaemonSet\",\"name\":\"monitoring-node-exporter\",\"uid\":\"$node_exporter_uid\",\"controller\":true}]"
# service <name> [<JSON members of the spec>]: one line of JSON, a Service with one port and a selector (a ClusterIP unless the
# members say otherwise).
service() {
  printf '{"apiVersion":"v1","kind":"Service","metadata":{"name":"%s"},"spec":{%s"selector":{"smoke":"%s"},"ports":[{"port":80}]}}\n' \
    "$1" "${2:+$2,}" "$1"
}
# try_service <expected> <as> <namespace> <description> <arguments of service>: admission of that Service.
try_service() {
  t_expected="$1" t_as="$2" t_namespace="$3" t_what="$4"
  shift 4
  service "$@" > "$KUBE_DIR/request.json"
  admission "$t_expected" "$t_as" "$t_namespace" "$t_what" < "$KUBE_DIR/request.json"
}
policy_active() { ! workload Deployment probe "$on_root" | kubectl -n monitoring create -f - --dry-run=server > /dev/null 2>&1; }
service_policy_active() { ! service probe '"type":"NodePort"' | kubectl -n monitoring create -f - --dry-run=server > /dev/null 2>&1; }
if mkc get validatingadmissionpolicy "$host_policy" > /dev/null 2>&1; then
  wait_for "the policy $host_policy refuses a Deployment with hostPath" policy_active
else
  fail "there is no ValidatingAdmissionPolicy $host_policy"
fi
if mkc get validatingadmissionpolicy "$service_policy" > /dev/null 2>&1; then
  wait_for "the policy $service_policy refuses a Service of the type NodePort" service_policy_active
else
  fail "there is no ValidatingAdmissionPolicy $service_policy"
fi
# What the operator makes (a Deployment) and could make otherwise (a Secret).
try_workload allowed operator monitoring "a plain Deployment" \
  Deployment plain "$plain"
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with hostPath /" \
  Deployment on-root "$on_root"
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with hostNetwork" \
  Deployment host-network '{"hostNetwork":true,"containers":[{"name":"c","image":"busybox"}]}'
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with hostPID" \
  Deployment host-pid '{"hostPID":true,"containers":[{"name":"c","image":"busybox"}]}'
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with hostIPC" \
  Deployment host-ipc '{"hostIPC":true,"containers":[{"name":"c","image":"busybox"}]}'
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with a hostPort" \
  Deployment host-port '{"containers":[{"name":"c","image":"busybox","ports":[{"containerPort":80,"hostPort":80}]}]}'
try_workload "refused-by-$host_policy" operator monitoring "a privileged Deployment" \
  Deployment privileged '{"containers":[{"name":"c","image":"busybox","securityContext":{"privileged":true}}]}'
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with the capability SYS_ADMIN" \
  Deployment sys-admin '{"containers":[{"name":"c","image":"busybox","securityContext":{"capabilities":{"add":["SYS_ADMIN"]}}}]}'
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with a privileged init container" \
  Deployment privileged-init '{"initContainers":[{"name":"i","image":"busybox","securityContext":{"privileged":true}}],"containers":[{"name":"c","image":"busybox"}]}'
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with the priority class system-cluster-critical" \
  Deployment cluster-critical '{"priorityClassName":"system-cluster-critical","containers":[{"name":"c","image":"busybox"}]}'
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with the priority class system-node-critical" \
  Deployment node-critical '{"priorityClassName":"system-node-critical","containers":[{"name":"c","image":"busybox"}]}'
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with hostPath / and the labels of node-exporter" \
  Deployment like-node-exporter "$on_root" "$node_exporter_labels"
try_workload "refused-by-$host_policy" operator monitoring "a Deployment with hostPath / and a template owned by the DaemonSet of node-exporter" \
  Deployment owned-by-node-exporter "$on_root" '{"smoke":"owned"}' "$forged_owner"
admission "refused-by-$token_policy" operator monitoring "a Secret with a token of the service account monitoring-alloy" <<'YAML'
apiVersion: v1
kind: Secret
metadata:
  name: alloy-token
  annotations:
    kubernetes.io/service-account.name: monitoring-alloy
type: kubernetes.io/service-account-token
YAML
# The Services the operator may create and change (its Alloy): a port of the node (NodePort, LoadBalancer through ServiceLB of k3s)
# or external IPs (CVE-2020-8554) would pass by ufw and the policy on hostPort.
try_service allowed operator monitoring "a Service of the type ClusterIP" plain
try_service allowed operator monitoring "a headless Service" headless '"clusterIP":"None"'
try_service "refused-by-$service_policy" operator monitoring "a Service of the type NodePort" node-port '"type":"NodePort"'
try_service "refused-by-$service_policy" operator monitoring "a Service of the type LoadBalancer" load-balancer '"type":"LoadBalancer"'
try_service "refused-by-$service_policy" operator monitoring "a ClusterIP Service with externalIPs" external-ips '"externalIPs":["203.0.113.10"]'
try_service "refused-by-$service_policy" operator monitoring "a Service of the type ExternalName" external-name \
  '"type":"ExternalName","externalName":"example.com"'
admission "refused-by-$service_policy" operator monitoring "the Service $alloy changed to the type NodePort" \
  patch service "$alloy" -p '{"spec":{"type":"NodePort"}}'
admission "refused-by-$service_policy" operator monitoring "the Service $alloy with externalIPs" \
  patch service "$alloy" -p '{"spec":{"externalIPs":["203.0.113.10"]}}'
# The administrator of the cluster: the policies hold for everybody, and node-exporter is known by who makes its pods, not by labels.
try_service "refused-by-$service_policy" admin monitoring "a Service of the type NodePort" node-port '"type":"NodePort"'
try_service "refused-by-$service_policy" admin monitoring "a Service of the type LoadBalancer" load-balancer '"type":"LoadBalancer"'
try_service "refused-by-$service_policy" admin monitoring "a ClusterIP Service with externalIPs" external-ips '"externalIPs":["203.0.113.10"]'
admission "refused-by-$service_policy" admin monitoring "the Service monitoring-kube-state-metrics changed to the type LoadBalancer" \
  patch service monitoring-kube-state-metrics -p '{"spec":{"type":"LoadBalancer"}}'
try_service allowed admin shop "a Service of the type NodePort in shop (the policies are of monitoring only)" node-port '"type":"NodePort"'
try_workload "refused-by-$host_policy" admin monitoring "a pod with the priority class system-node-critical" \
  Pod node-critical '{"priorityClassName":"system-node-critical","containers":[{"name":"c","image":"busybox"}]}'
try_workload "refused-by-$host_policy" admin monitoring "a CronJob with the priority class system-cluster-critical" \
  CronJob cluster-critical '{"restartPolicy":"Never","priorityClassName":"system-cluster-critical","containers":[{"name":"c","image":"busybox"}]}'
try_workload allowed admin shop "a pod with the priority class system-cluster-critical in shop (the policies are of monitoring only)" \
  Pod cluster-critical '{"priorityClassName":"system-cluster-critical","containers":[{"name":"c","image":"busybox"}]}'
try_workload "refused-by-$host_policy" admin monitoring "a pod with hostPath / and the labels of node-exporter" \
  Pod like-node-exporter "$on_root" "$node_exporter_labels"
try_workload "refused-by-$host_policy" admin monitoring "a pod with hostPath / owned by the DaemonSet of node-exporter (a forged ownerReference)" \
  Pod owned-by-node-exporter "$on_root" "$node_exporter_labels" "$forged_owner"
try_workload "refused-by-$host_policy" admin monitoring "another DaemonSet with hostPath /" \
  DaemonSet other-exporter "$on_root"
try_workload "refused-by-$host_policy" admin monitoring "a StatefulSet with hostPath /" \
  StatefulSet on-root "$on_root"
try_workload "refused-by-$host_policy" admin monitoring "a ReplicaSet with hostPath /" \
  ReplicaSet on-root "$on_root"
try_workload "refused-by-$host_policy" admin monitoring "a Job with hostPath /" \
  Job on-root "{\"restartPolicy\":\"Never\",${on_root#\{}"
try_workload "refused-by-$host_policy" admin monitoring "a privileged CronJob" \
  CronJob privileged '{"restartPolicy":"Never","containers":[{"name":"c","image":"busybox","securityContext":{"privileged":true}}]}'
node_exporter_pod="$(mkc get pods -l app.kubernetes.io/name=node-exporter -o jsonpath='{.items[0].metadata.name}')"
kube_state_metrics_pod="$(mkc get pods -l app.kubernetes.io/name=kube-state-metrics -o jsonpath='{.items[0].metadata.name}')"
admission "refused-by-$host_policy" admin monitoring "a privileged ephemeral container in the pod of node-exporter" patch pod "$node_exporter_pod" \
  --subresource=ephemeralcontainers --type=strategic \
  -p '{"spec":{"ephemeralContainers":[{"name":"debug","image":"busybox","securityContext":{"privileged":true}}]}}'
admission "refused-by-$host_policy" admin monitoring "another image in the pod of node-exporter" set image "pod/$node_exporter_pod" node-exporter=busybox
admission allowed admin monitoring "a plain ephemeral container in the pod of kube-state-metrics" patch pod "$kube_state_metrics_pod" \
  --subresource=ephemeralcontainers --type=strategic -p '{"spec":{"ephemeralContainers":[{"name":"debug","image":"busybox"}]}}'
admission "refused-by-$token_policy" admin monitoring "a Secret with a token of a service account" <<'YAML'
apiVersion: v1
kind: Secret
metadata:
  name: default-token
  annotations:
    kubernetes.io/service-account.name: default
type: kubernetes.io/service-account-token
YAML
admission allowed admin monitoring "an Opaque Secret" create secret generic smoke --from-literal=key=value
try_workload allowed admin shop "a pod with hostPath / in shop (the policies are of monitoring only)" \
  Pod on-root "$on_root"
# Every Service the charts and the operator made passes the policy on Services (a change goes through it with the whole object),
# and the requests above, dry runs, changed none of them.
services="$(mkc get services -o jsonpath='{.items[*].metadata.name}')"
case " $services " in *" $alloy "*) ;; *) fail "the Service $alloy is not there (Services: $services)" ;; esac
for service_name in $services; do
  admission allowed admin monitoring "the Service $service_name of the charts, annotated" annotate service "$service_name" smoke=checked
  service_type="$(mkc get service "$service_name" -o jsonpath='{.spec.type}/{.spec.externalIPs}')"
  [ "$service_type" = ClusterIP/ ] || fail "the Service $service_name is $service_type, expected ClusterIP/ (no external IPs)"
done

log "every pod of monitoring again, made under the policies"
logs_before="$KUBE_DIR/logs-before"
mkdir -p "$logs_before"
for target in monitoring-alloy-operator monitoring-alloy monitoring-kube-state-metrics; do
  mkc logs "deployment/$target" --all-containers > "$logs_before/$target.log" 2>&1 || true
done
mkc delete pods --all --wait=true > /dev/null
metrics_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
wait_for "the DaemonSet monitoring-node-exporter is ready" daemon_set_ready monitoring-node-exporter
for deployment in monitoring-alloy-operator monitoring-kube-state-metrics monitoring-alloy; do
  wait_for "the Deployment $deployment is ready" ready "$deployment"
done

log "what the receiver gets"
receiver_log="$KUBE_DIR/receiver.log"
fetch_receiver() { docker logs "$receiver_name" > "$receiver_log" 2>&1; }
received() { fetch_receiver && grep -q -- "$1" "$receiver_log"; }
# A metric sent after the pods of monitoring came back.
received_again() { docker logs --since "$metrics_since" "$receiver_name" 2>&1 | grep -q -- "$1"; }
masked_marker() { fetch_receiver && grep -- "$marker user logged in" "$receiver_log" | grep -q 'REDACTED'; }
event() { fetch_receiver && grep 'integrations/kubernetes/eventhandler' "$receiver_log" | grep -q -- "$marker"; }
wait_for "the line of the pod, masked" masked_marker
wait_for "a cluster event of the pod" event
for metric in kube_deployment_spec_replicas node_filesystem_avail_bytes kubelet_running_pods container_cpu_usage_seconds_total; do
  wait_for "the metric $metric" received_again "$metric"
done
fetch_receiver
echo "monitoring-smoke: a line of the pod as received:"
grep -m 1 -- "$marker user logged in" "$receiver_log"
echo "monitoring-smoke: a cluster event as received:"
grep 'integrations/kubernetes/eventhandler' "$receiver_log" | grep -m 1 -- "$marker"
if grep -qF -- "$marker_password" "$receiver_log"; then fail "the password of the pod came out of Alloy"; fi
if grep -qF -- "$marker_email" "$receiver_log"; then fail "the e-mail address of the pod came out of Alloy"; fi
for target in monitoring-alloy-operator monitoring-alloy monitoring-kube-state-metrics; do
  if forbidden="$({ cat "$logs_before/$target.log"; mkc logs "deployment/$target" --all-containers 2>&1; } | grep -i 'forbidden')"; then
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
  can no "$account" create pods -n shop
  can no "$account" list configmaps -n shop
  # Subresources: --subresource, not TYPE/NAME (kubectl reads pods/exec as the pod named exec).
  for namespace in shop kube-system; do
    for subresource in exec attach portforward; do
      can no "$account" create pods --subresource="$subresource" -n "$namespace"
      can no "$account" get pods --subresource="$subresource" -n "$namespace"
    done
    can no "$account" patch pods --subresource=ephemeralcontainers -n "$namespace"
    can no "$account" create serviceaccounts --subresource=token -n "$namespace"
  done
  can no "$account" get nodes --subresource=proxy
  can no "$account" create nodes --subresource=proxy
done
# Alloy: its token and what it reads in the cluster, and nothing in monitoring it would not need.
can yes $alloy get secret/grafana-cloud -n shop
for check in "list nodes" "get nodes --subresource=metrics" "list pods --all-namespaces" "get pods --subresource=log -n shop" "list events --all-namespaces" \
  "list endpoints -n monitoring" "list prometheusrules.monitoring.coreos.com --all-namespaces"; do
  # shellcheck disable=SC2086 # the words of the check are arguments
  can yes $alloy $check
done
can no $alloy list secrets -n monitoring
can no $alloy create deployments -n monitoring
# The operator: in monitoring, its Alloy resource (made by Argo CD), what the Alloy chart makes of it (a Deployment, its service
# account and Service, all named like the resource) and its Helm releases; nothing elsewhere.
for check in "update alloys.collectors.grafana.com/$alloy -n monitoring" "update alloys.collectors.grafana.com --subresource=status -n monitoring" \
  "update alloys.collectors.grafana.com --subresource=finalizers -n monitoring" \
  "create deployments.apps -n monitoring" "patch deployments.apps/$alloy -n monitoring" "delete deployments.apps/$alloy -n monitoring" \
  "create secrets -n monitoring" "create serviceaccounts -n monitoring" "patch serviceaccounts/$alloy -n monitoring" \
  "create services -n monitoring" "patch services/$alloy -n monitoring" "update leases.coordination.k8s.io -n monitoring" "create events -n monitoring"; do
  # shellcheck disable=SC2086 # the words of the check are arguments
  can yes $operator $check
done
for check in "list alloys.collectors.grafana.com --all-namespaces" "create alloys.collectors.grafana.com -n monitoring" \
  "delete alloys.collectors.grafana.com/$alloy -n monitoring" "create daemonsets.apps -n monitoring" "create statefulsets.apps -n monitoring" \
  "update daemonsets.apps/monitoring-node-exporter -n monitoring" "patch deployments.apps/monitoring-kube-state-metrics -n monitoring" \
  "update deployments.apps/$operator -n monitoring" "patch services/monitoring-kube-state-metrics -n monitoring" \
  "delete serviceaccounts/monitoring-node-exporter -n monitoring" "create configmaps -n monitoring" "create pods -n monitoring" \
  "create replicasets.apps -n monitoring" "create jobs.batch -n monitoring" "create deployments.apps -n shop" "list deployments.apps -n shop" \
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

#!/bin/sh
# The restore of a snapshot of the backups: `make -C infra/k8s restore SNAPSHOT=<id> [PRE_RESTORE=skip]` (docs/features/deploy-ops/
# spec.md, O4). The Jobs come from the CronJobs of the deployed chart (restore, backup) and the Jobs of its version, so the
# restore runs the images and the secrets of the deployed revision. Steps:
#   1. checks without changes: SNAPSHOT is an identifier (never latest); no Job of the namespace is active; with Argo CD (the CRD
#      Application and the application shop) shop has no synchronization going on; the Jobs migrate, keycloak-config and
#      purge-caches of the version are there; a Job of the CronJob restore in the mode check refuses a snapshot with a migration
#      the deployed code does not know (a snapshot newer than the code);
#   2. says which snapshot and what it will do (also whether the backup pre-restore is skipped), and goes on only after `tak`;
#   3. a backup of the current state with the tag pre-restore (a Job of the CronJob backup); its failure stops the restore
#      without changes, unless PRE_RESTORE=skip (then no backup is made);
#   4. with Argo CD: the automatic synchronization of root off, then of shop; the CronJobs cart-purge-expired and backup
#      suspended; api, worker, store and keycloak scaled to zero (the replicas are remembered);
#   5. a Job of the CronJob restore in the mode restore: the data of the snapshot (the databases, the objects; Redis flushed,
#      the queues of RabbitMQ purged);
#   6. migrate again (rerun), keycloak back and keycloak-config again, a Job of the CronJob restore in the mode reindex, api,
#      worker, store and the CronJobs back, the rollouts, purge-caches again; with Argo CD the automatic synchronization of root
#      on (root brings back that of shop);
#   7. a failure in the steps 4 to 6 says at which step it stopped and which snapshot pre-restore was made, and leaves the
#      applications off (no shop on partly restored data); infra/k8s/README.md says how to finish.
#
# Usage: SNAPSHOT=<id> restore.sh (make -C infra/k8s restore SNAPSHOT=<id>), the answer on the standard input.
# Environment: SNAPSHOT (required: the identifier of a snapshot from make -C infra/k8s backups), PRE_RESTORE (empty or skip),
# KUBE_DIR (required: the directory with the kubeconfig `config` of the cluster; no default, so that nothing is restored in
# whatever cluster ~/.kube points at), KUBE_NETWORK (the Docker network kubectl runs on, default host), KUBE_NAMESPACE (default
# shop), ARGOCD_NAMESPACE (default argocd).
# Exit codes: 0 restored; 1 refused, not confirmed or failed; 2 bad arguments or no KUBE_DIR.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=kube-jobs.sh
. "$here/kube-jobs.sh"

usage() {
  echo "restore: $1" >&2
  echo "restore: usage: make -C infra/k8s restore SNAPSHOT=<id from make -C infra/k8s backups> [PRE_RESTORE=skip] KUBE_DIR=<directory>" >&2
  exit 2
}
snapshot="${SNAPSHOT:-}"
pre_restore="${PRE_RESTORE:-}"
printf '%s' "$snapshot" | grep -Eqx '[0-9a-f]{8,64}' ||
  usage "SNAPSHOT '$snapshot' is not the identifier of a snapshot (8 to 64 hexadecimal digits from make -C infra/k8s backups; never latest)"
case "$pre_restore" in
  "" | skip) ;;
  *) usage "PRE_RESTORE '$pre_restore' is neither empty nor skip" ;;
esac
if [ -z "${KUBE_DIR:-}" ]; then
  echo "restore: set KUBE_DIR to the directory with the kubeconfig (file 'config') of the cluster" >&2
  exit 2
fi
KUBE_NETWORK="${KUBE_NETWORK:-host}"
namespace="${KUBE_NAMESPACE:-shop}"
argocd_namespace="${ARGOCD_NAMESPACE:-argocd}"
if [ ! -f "$KUBE_DIR/config" ]; then
  echo "restore: no kubeconfig at $KUBE_DIR/config (set KUBE_DIR)" >&2
  exit 1
fi

# The answer is read from the standard input of the script; every other command reads nothing (docker run -i would take it).
exec 3<&0 < /dev/null

stamp="$(date -u +%Y%m%d%H%M%S)"
deployments="api worker store keycloak"
# The containers of the pods of the CronJobs restore and backup, in the order they run.
restore_containers="migrations restic databases objects redis rabbitmq api"
backup_containers="cluster-id dump objects backup"
cronjobs="cart-purge-expired backup"
step=0
pre_restore_snapshot=""
argocd=0

refuse() {
  printf '%s\n' "restore: $*" >&2
  echo "restore: nothing changed" >&2
  exit 1
}

# On a failure after the first change: where it stopped, what is off, and the snapshot pre-restore.
on_exit() {
  status=$?
  trap - EXIT
  if [ "$status" -ne 0 ] && [ "$step" -ge 4 ]; then
    {
      echo "restore: FAILED at step $step ($step_name), restoring the snapshot $snapshot"
      echo "restore: api, worker, store and keycloak may stay scaled to zero and the CronJobs cart-purge-expired and backup suspended: no shop runs on partly restored data"
      [ "$argocd" -eq 0 ] || echo "restore: the automatic synchronization of the applications root and shop of Argo CD stays off"
      if [ -n "$pre_restore_snapshot" ]; then
        echo "restore: the state before the restore is the pre-restore snapshot $pre_restore_snapshot"
      else
        echo "restore: there is no pre-restore snapshot (PRE_RESTORE=skip)"
      fi
      echo "restore: to finish, see infra/k8s/README.md, section 8 (Odtworzenie przerwane w trakcie)"
    } >&2
  fi
  exit "$status"
}
trap on_exit EXIT

# py_json <python expression of d, the JSON document on the standard input>: prints the value (a string).
py_json() {
  py -c "import json, sys
d = json.load(sys.stdin)
print($1)"
}

# job_log <job> [kubectl logs options]: the log of a Job, for the messages.
job_log() {
  kubectl -n "$namespace" logs "job/$1" "${2:---all-containers}" 2>&1 | tail -n 40
}

# ---------------------------------------------------------------------------------------------------------------
# 1. Checks, without changes
# ---------------------------------------------------------------------------------------------------------------
active="$(kubectl -n "$namespace" get jobs -o json | py_json '" ".join(j["metadata"]["name"] for j in d["items"]
  if not {c.get("type") for c in (j.get("status", {}).get("conditions") or []) if c.get("status") == "True"} & {"Complete", "Failed"})')" ||
  refuse "cannot read the Jobs of the namespace $namespace"
[ -z "$active" ] || refuse "Jobs are active in the namespace $namespace: $active (wait for them to finish)"

crd="$(kubectl get crd applications.argoproj.io --ignore-not-found -o name)" || refuse "cannot ask the cluster for the CRD of Argo CD"
if [ -n "$crd" ]; then
  shop_app="$(kubectl -n "$argocd_namespace" get application shop --ignore-not-found -o json)" || refuse "cannot read the application shop of Argo CD"
  if [ -n "$shop_app" ]; then
    argocd=1
    syncing="$(printf '%s' "$shop_app" | py_json '"yes" if d.get("operation") or (d.get("status", {}).get("operationState") or {}).get("phase") in ("Running", "Terminating") else "no"')"
    [ "$syncing" = no ] || refuse "Argo CD is synchronizing the application shop (wait for it to finish)"
    root_app="$(kubectl -n "$argocd_namespace" get application root -o json)" || refuse "cannot read the application root of Argo CD"
    root_automated="$(printf '%s' "$root_app" | py_json 'json.dumps((d.get("spec", {}).get("syncPolicy") or {}).get("automated"))')"
  fi
fi

# version_job <logical name>: the one Job of that name of the deployed version, which step 6 runs again.
version_job() {
  version_names="$(kubectl -n "$namespace" get jobs -l "app.kubernetes.io/name=$1,shop/job-kind=version" -o json | py_json '" ".join(j["metadata"]["name"] for j in d["items"])')" ||
    refuse "cannot read the Job $1 of the deployed version"
  case "$version_names" in
    "" | *" "*) refuse "the deployed version has not one Job $1 but '$version_names' (is the chart installed?)" ;;
  esac
  printf '%s' "$version_names"
}
job_migrate="$(version_job migrate)"
job_keycloak_config="$(version_job keycloak-config)"
job_purge_caches="$(version_job purge-caches)"

check_job="restore-check-$stamp"
cronjob_job restore "$check_job" RESTORE_MODE=check "RESTORE_SNAPSHOT=$snapshot" || refuse "cannot create the Job of the check from the CronJob restore"
if ! wait_job "$check_job"; then
  # shellcheck disable=SC2086 # a list of names
  log="$(job_logs "$check_job" $restore_containers)"
  kubectl -n "$namespace" delete job "$check_job" --wait=true > /dev/null || echo "restore: could not delete the Job $check_job" >&2
  printf '%s\n' "$log" >&2
  refuse "the snapshot $snapshot cannot be restored on the deployed version (the check above)"
fi
description="$(job_log "$check_job" --container=restic | grep '^restore: snapshot ')" || description="restore: snapshot $snapshot"
kubectl -n "$namespace" delete job "$check_job" --wait=true > /dev/null || refuse "cannot delete the Job $check_job"

# ---------------------------------------------------------------------------------------------------------------
# 2. What it will do, and the confirmation
# ---------------------------------------------------------------------------------------------------------------
echo "restore: in the namespace $namespace of the cluster of $KUBE_DIR/config:"
printf '%s\n' "$description"
if [ "$pre_restore" = skip ]; then
  echo "  - PRE_RESTORE=skip: no backup pre-restore is made, the current data cannot be brought back after the restore;"
else
  echo "  - first a backup of the current state with the tag pre-restore (a Job of the CronJob backup); if it fails, nothing changes;"
fi
[ "$argocd" -eq 0 ] || echo "  - Argo CD: the automatic synchronization of root, then of shop, is turned off, and that of root on again at the end;"
cat <<EOF
  - the CronJobs cart-purge-expired and backup are suspended, api, worker, store and keycloak scaled to zero;
  - the databases shop and keycloak are dropped and restored from the snapshot, the bucket product-images gets the objects of the
    snapshot (others are deleted), Redis is flushed (the customers are logged out) and the queues of RabbitMQ are purged;
  - migrate and keycloak-config run again, the search index is rebuilt (catalog:reindex), api, worker, store and the CronJobs
    come back, and purge-caches runs again.
  Everything written since the snapshot is lost.
EOF
printf 'restore: type tak to go on: '
answer=""
read -r answer <&3 || answer=""
echo
[ "$answer" = tak ] || refuse "not confirmed (no 'tak')"

# ---------------------------------------------------------------------------------------------------------------
# 3. The backup pre-restore
# ---------------------------------------------------------------------------------------------------------------
if [ "$pre_restore" != skip ]; then
  backup_job="backup-pre-restore-$stamp"
  echo "restore: the backup pre-restore (Job $backup_job)"
  cronjob_job backup "$backup_job" BACKUP_TAG=pre-restore || refuse "cannot create the Job of the backup pre-restore (PRE_RESTORE=skip goes on without it)"
  if ! wait_job "$backup_job"; then
    # shellcheck disable=SC2086 # a list of names
    job_logs "$backup_job" $backup_containers >&2
    refuse "the backup pre-restore failed (PRE_RESTORE=skip goes on without it)"
  fi
  pre_restore_snapshot="$(job_log "$backup_job" --container=backup | sed -n 's/^snapshot \([0-9a-f]*\) saved$/\1/p' | tail -n 1)"
  [ -n "$pre_restore_snapshot" ] || refuse "the backup pre-restore left no snapshot in its log"
  echo "restore: the pre-restore snapshot is $pre_restore_snapshot"
fi

# ---------------------------------------------------------------------------------------------------------------
# 4. Everything that writes is stopped
# ---------------------------------------------------------------------------------------------------------------
step=4 step_name="stopping the applications"
if [ "$argocd" -eq 1 ]; then
  # root first: with selfHeal it would bring back the synchronization of shop from git.
  for app in root shop; do
    kubectl -n "$argocd_namespace" patch application "$app" --type json -p '[{"op":"remove","path":"/spec/syncPolicy/automated"}]' > /dev/null
  done
  echo "restore: the automatic synchronization of root and shop is off"
fi
# shellcheck disable=SC2086 # lists of names
suspended="$(kubectl -n "$namespace" get cronjob $cronjobs -o json | py_json '" ".join(c["metadata"]["name"] + "=" + str(bool(c["spec"].get("suspend"))).lower() for c in d["items"])')"
# shellcheck disable=SC2086 # lists of names
replicas="$(kubectl -n "$namespace" get deployment $deployments -o json | py_json '" ".join(x["metadata"]["name"] + "=" + str(x["spec"].get("replicas", 1)) for x in d["items"])')"
for cronjob in $cronjobs; do
  kubectl -n "$namespace" patch cronjob "$cronjob" --type merge -p '{"spec":{"suspend":true}}' > /dev/null
done
for deployment in $deployments; do
  kubectl -n "$namespace" scale deployment "$deployment" --replicas=0 > /dev/null
done
deadline=$(($(date +%s) + 600))
while [ -n "$(kubectl -n "$namespace" get pods -l 'app.kubernetes.io/name in (api,worker,store,keycloak)' -o name)" ]; do
  [ "$(date +%s)" -lt "$deadline" ] || { echo "restore: the pods of $deployments did not stop in 600 s" >&2; exit 1; }
  sleep "${RESTORE_POLL_SECONDS:-5}"
done
echo "restore: $deployments are stopped (replicas were: $replicas), the CronJobs $cronjobs suspended"

# replicas_of <deployment>: the number of replicas it had; one (as in the chart) for one that was at zero, which only a restore
# that stopped before leaves.
replicas_of() {
  for pair in $replicas; do
    if [ "${pair%%=*}" = "$1" ] && [ "${pair#*=}" -gt 0 ]; then
      printf '%s' "${pair#*=}"
      return 0
    fi
  done
  printf 1
}

# ---------------------------------------------------------------------------------------------------------------
# 5. The data of the snapshot
# ---------------------------------------------------------------------------------------------------------------
step=5 step_name="the Job of the restore of the data"
run_job="restore-run-$stamp"
echo "restore: the data of the snapshot $snapshot (Job $run_job)"
cronjob_job restore "$run_job" RESTORE_MODE=restore "RESTORE_SNAPSHOT=$snapshot"
if ! wait_job "$run_job"; then
  # shellcheck disable=SC2086 # a list of names
  job_logs "$run_job" $restore_containers >&2
  exit 1
fi

# ---------------------------------------------------------------------------------------------------------------
# 6. The version on the restored data, and everything back
# ---------------------------------------------------------------------------------------------------------------
step=6 step_name="the migrations (Job $job_migrate)"
echo "restore: migrate again (Job $job_migrate)"
rerun_job "$job_migrate"
wait_job "$job_migrate" || { job_log "$job_migrate" >&2; exit 1; }

step_name="Keycloak and keycloak-config (Job $job_keycloak_config)"
kubectl -n "$namespace" scale deployment keycloak "--replicas=$(replicas_of keycloak)" > /dev/null
kubectl -n "$namespace" rollout status deployment/keycloak --timeout=1500s > /dev/null
echo "restore: keycloak-config again (Job $job_keycloak_config)"
rerun_job "$job_keycloak_config"
wait_job "$job_keycloak_config" || { job_log "$job_keycloak_config" >&2; exit 1; }

step_name="the search index (catalog:reindex)"
reindex_job="restore-reindex-$stamp"
echo "restore: the search index (Job $reindex_job)"
cronjob_job restore "$reindex_job" RESTORE_MODE=reindex
# shellcheck disable=SC2086 # a list of names
wait_job "$reindex_job" || { job_logs "$reindex_job" $restore_containers >&2; exit 1; }

step_name="api, worker, store and the CronJobs back"
for deployment in api worker store; do
  kubectl -n "$namespace" scale deployment "$deployment" "--replicas=$(replicas_of "$deployment")" > /dev/null
done
for pair in $suspended; do
  kubectl -n "$namespace" patch cronjob "${pair%%=*}" --type merge -p "{\"spec\":{\"suspend\":${pair#*=}}}" > /dev/null
done
for deployment in api worker store; do
  kubectl -n "$namespace" rollout status "deployment/$deployment" --timeout=1500s > /dev/null
done

step_name="purge-caches (Job $job_purge_caches)"
echo "restore: purge-caches again (Job $job_purge_caches)"
rerun_job "$job_purge_caches"
wait_job "$job_purge_caches" || { job_log "$job_purge_caches" >&2; exit 1; }

if [ "$argocd" -eq 1 ]; then
  step_name="the automatic synchronization of root"
  if [ "$root_automated" = null ]; then
    echo "restore: the application root had no automatic synchronization before the restore: it stays off (and that of shop too)"
  else
    kubectl -n "$argocd_namespace" patch application root --type merge -p "{\"spec\":{\"syncPolicy\":{\"automated\":$root_automated}}}" > /dev/null
    echo "restore: the automatic synchronization of root is on again (root brings back that of shop)"
  fi
fi
step=7
if [ -n "$pre_restore_snapshot" ]; then
  echo "restore: done: the snapshot $snapshot is restored; the state before it is the pre-restore snapshot $pre_restore_snapshot"
else
  echo "restore: done: the snapshot $snapshot is restored; no pre-restore snapshot (PRE_RESTORE=skip)"
fi

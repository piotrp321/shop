#!/bin/sh
# The snapshots of the repository of the backups: `make -C infra/k8s backups` (docs/features/deploy-ops/spec.md, O4). A Job of the
# CronJob restore in the mode list prints them (identifier, time, host, tags, revision of the code), the oldest first; the Job is
# deleted after. Only reads: restic runs without a lock, nothing in the repository or in the cluster changes but that Job.
#
# Usage: backups.sh
# Environment: KUBE_DIR (required: the directory with the kubeconfig `config` of the cluster), KUBE_NETWORK (the Docker network
# kubectl runs on, default host), KUBE_NAMESPACE (default shop).
# Exit codes: 0 listed; 1 a failure; 2 no KUBE_DIR.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=kube-jobs.sh
. "$here/kube-jobs.sh"

if [ -z "${KUBE_DIR:-}" ]; then
  echo "backups: set KUBE_DIR to the directory with the kubeconfig (file 'config') of the cluster" >&2
  exit 2
fi
KUBE_NETWORK="${KUBE_NETWORK:-host}"
namespace="${KUBE_NAMESPACE:-shop}"
if [ ! -f "$KUBE_DIR/config" ]; then
  echo "backups: no kubeconfig at $KUBE_DIR/config (set KUBE_DIR)" >&2
  exit 1
fi
exec < /dev/null

job="restore-list-$(date -u +%Y%m%d%H%M%S)"
cronjob_job restore "$job" RESTORE_MODE=list || { echo "backups: cannot create a Job from the CronJob restore (is backup.enabled?)" >&2; exit 1; }
status=0
wait_job "$job" || status=$?
if [ "$status" -eq 0 ]; then
  kubectl -n "$namespace" logs "job/$job" --container=restic
else
  kubectl -n "$namespace" logs "job/$job" --all-containers >&2 || echo "backups: no log of the Job $job" >&2
fi
kubectl -n "$namespace" delete job "$job" --wait=true > /dev/null || { echo "backups: could not delete the Job $job" >&2; status=1; }
exit "$status"

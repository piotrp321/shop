#!/bin/sh
# Runs a job of a version again under the same name: `make -C infra/k8s rerun JOB=<name>` (D11: a failed job does not
# run again by itself; a new revision gives new jobs). The job is read from the cluster, deleted and created anew.
# A Job of the generator of the demo data (the label app.kubernetes.io/name: demo-data) is refused, with exit code 3 and
# nothing deleted or created: the copy would carry DEMO_DATA_CONFIRM and replace the data of the shop without the checks of
# `make generate`.
#
# Usage: rerun.sh <job name>
# Environment: KUBE_DIR (required: the directory with the kubeconfig `config` of the cluster; there is no default, so
# that a job is never deleted in whatever cluster ~/.kube points at), KUBE_NETWORK (the Docker network kubectl runs
# on, default host), KUBE_NAMESPACE (default shop).
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=kube-jobs.sh
. "$here/kube-jobs.sh"

job="${1:-}"
if [ -z "$job" ]; then
  echo "rerun: usage: rerun.sh <job name> (make -C infra/k8s rerun JOB=<name>)" >&2
  exit 2
fi
if [ -z "${KUBE_DIR:-}" ]; then
  echo "rerun: set KUBE_DIR to the directory with the kubeconfig (file 'config') of the cluster" >&2
  exit 2
fi
KUBE_NETWORK="${KUBE_NETWORK:-host}"
namespace="${KUBE_NAMESPACE:-shop}"
if [ ! -f "$KUBE_DIR/config" ]; then
  echo "rerun: no kubeconfig at $KUBE_DIR/config (set KUBE_DIR)" >&2
  exit 1
fi

# The label is read first; a Job that cannot be read (missing, cluster not reachable) is an error, nothing is deleted.
if ! job_label="$(kubectl -n "$namespace" get job "$job" -o json | py -c '
import json, sys
print(json.load(sys.stdin).get("metadata", {}).get("labels", {}).get("app.kubernetes.io/name", ""))
')"; then
  echo "rerun: could not read the job '$job' in the namespace $namespace" >&2
  exit 1
fi
if [ "$job_label" = demo-data ]; then
  echo "rerun: the job '$job' is a Job of the generator of the demo data (label app.kubernetes.io/name: demo-data); it is not run again: use make generate (make -C infra/k8s generate)" >&2
  exit 3
fi

rerun_job "$job" || { echo "rerun: could not run the job '$job' again in the namespace $namespace" >&2; exit 1; }
echo "rerun: job $job created again"

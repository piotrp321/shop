#!/bin/sh
# Runs a job of a version again under the same name: `make -C infra/k8s rerun JOB=<name>` (D11: a failed job does not
# run again by itself; a new revision gives new jobs). The job is read from the cluster, deleted and created anew.
#
# Usage: rerun.sh <job name>
# Environment: KUBE_DIR (required: the directory with the kubeconfig `config` of the cluster; there is no default, so
# that a job is never deleted in whatever cluster ~/.kube points at), KUBE_NETWORK (the Docker network kubectl runs
# on, default host), KUBE_NAMESPACE (default shop).
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

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

manifest="$(kubectl -n "$namespace" get job "$job" -o json)" || { echo "rerun: no job '$job' in the namespace $namespace" >&2; exit 1; }

# The manifest without what the cluster fills in, so that it can be created again.
clean="$(printf '%s' "$manifest" | tools_run "$PYTHON_IMAGE" python -I -c '
import json, sys
job = json.load(sys.stdin)
job.pop("status", None)
meta = job["metadata"]
for key in ("uid", "resourceVersion", "creationTimestamp", "generation", "ownerReferences", "managedFields", "selfLink"):
    meta.pop(key, None)
spec = job["spec"]
spec.pop("selector", None)
labels = spec["template"]["metadata"].get("labels", {})
for key in [k for k in labels if "controller-uid" in k or k.endswith("job-name")]:
    del labels[key]
print(json.dumps(job))
')"

kubectl -n "$namespace" delete job "$job" --wait=true --cascade=foreground >/dev/null
printf '%s' "$clean" | kubectl -n "$namespace" create -f - >/dev/null
echo "rerun: job $job created again"

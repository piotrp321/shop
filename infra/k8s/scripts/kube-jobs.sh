#!/bin/sh
# shellcheck disable=SC2154 # namespace is set by the script that sources this file
# Sourced by rerun.sh, restore.sh and backups.sh after tools.sh: the Jobs of a cluster through kubectl (KUBE_DIR, KUBE_NETWORK)
# in the namespace $namespace.
#
#   rerun_job <job>                        deletes a Job and creates it again under the same name (rerun, restore)
#   cronjob_job <cronjob> <job> [VAR=value...]
#                                          creates the Job <job> from the template of a CronJob (kubectl create job --from,
#                                          the variables set in every container by kubectl set env --local); no other object
#   wait_job <job>                         waits until the Job completes (status 0) or fails (status 1), at most its
#                                          activeDeadlineSeconds (after it Kubernetes fails the Job anyway)
#
# RESTORE_POLL_SECONDS (5) is the time between two readings of a Job.

rerun_job() {
  rerun_manifest="$(kubectl -n "$namespace" get job "$1" -o json)" || { echo "no job '$1' in the namespace $namespace" >&2; return 1; }
  # The manifest without what the cluster fills in, so that it can be created again.
  rerun_clean="$(printf '%s' "$rerun_manifest" | py -c '
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
')" || return 1
  kubectl -n "$namespace" delete job "$1" --wait=true --cascade=foreground > /dev/null || return 1
  printf '%s' "$rerun_clean" | kubectl -n "$namespace" create -f - > /dev/null
}

cronjob_job() {
  cronjob_source="$1" cronjob_name="$2"
  shift 2
  cronjob_manifest="$(kubectl -n "$namespace" create job "$cronjob_name" "--from=cronjob/$cronjob_source" --dry-run=client -o json)" || return 1
  if [ "$#" -gt 0 ]; then
    cronjob_manifest="$(printf '%s' "$cronjob_manifest" | kubectl set env --local -f - -o json "$@")" || return 1
  fi
  printf '%s' "$cronjob_manifest" | kubectl -n "$namespace" create -f - > /dev/null
}

# job_state: a Job (JSON) on the standard input; prints its state (Complete, Failed or Running) and its activeDeadlineSeconds.
job_state() {
  py -c '
import json, sys
try:
    job = json.load(sys.stdin)
except ValueError:
    print("Unknown 0")
    sys.exit(0)
true = {c.get("type") for c in job.get("status", {}).get("conditions") or [] if c.get("status") == "True"}
state = "Failed" if "Failed" in true else "Complete" if "Complete" in true else "Running"
print(state, job.get("spec", {}).get("activeDeadlineSeconds") or 0)
'
}

wait_job() {
  wait_deadline=""
  while :; do
    wait_now="$(date +%s)"
    # shellcheck disable=SC2046 # the state and the deadline, two words
    set -- "$1" $(kubectl -n "$namespace" get job "$1" -o json | job_state)
    if [ -z "$wait_deadline" ]; then
      # A Job without a deadline (or not readable yet) is given the longest one of the Jobs of the chart (the restore).
      wait_limit="${3:-0}"
      [ "$wait_limit" -gt 0 ] || wait_limit=10800
      wait_deadline=$((wait_now + wait_limit))
    fi
    case "${2:-}" in
      Complete) return 0 ;;
      Failed) echo "the Job $1 failed" >&2; return 1 ;;
    esac
    if [ "$wait_now" -ge "$wait_deadline" ]; then
      echo "the Job $1 did not finish in its activeDeadlineSeconds ($wait_limit s)" >&2
      return 1
    fi
    sleep "${RESTORE_POLL_SECONDS:-5}"
  done
}

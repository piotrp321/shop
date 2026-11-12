#!/bin/sh
# The state of the environment, read over SSH: `make -C infra/k8s status HOST=<user>@<address>`. Prints the applications of
# Argo CD, the pods and the jobs of the namespace shop, and the logs of every job of the namespace that failed (the Failed
# condition; the reaction to the alerts BackupFailed and BackupVerifyFailed): of all the pods of the job, so of every attempt
# (backoffLimit), while they exist; the containers whose log cannot be read (the ones after a failed init container never
# started) are skipped, the log of the others is printed. Changes nothing. Exit code 1 when any read failed.
#
# Usage: status.sh <user@address>      Environment: SSH_OPTS
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

check_host status "${1:-}"
failed=0
section() {
  title="$1"
  shift
  printf '\n== %s\n' "$title"
  remote_root k3s kubectl "$@" || { echo "status: could not read $title" >&2; failed=1; }
}
section "applications of Argo CD (sync and health)" -n argocd get applications.argoproj.io
section "pods of the namespace shop" -n shop get pods -o wide
section "jobs of the namespace shop" -n shop get jobs

# The jobs with the Failed condition, then the logs of each (every pod of the job by its label, all containers, the last lines of
# each; --ignore-errors skips a container that has no log, instead of ending kubectl before the log of the init container that
# failed, the cause, is printed): the words of a remote command are fixed (see remote_root), so the filter is one single-quoted
# word and the names are filtered here. kubectl prints nothing, with exit code 0, when no pod is left (the pods of a job can be deleted).
printf '\n== logs of the failed jobs of the namespace shop\n'
jobs_path='{range .items[*]}{.metadata.name}{" "}{.status.conditions[?(@.type=="Failed")].status}{"\n"}{end}'
if jobs="$(remote_root k3s kubectl -n shop get jobs -o "jsonpath='$jobs_path'")"; then
  failed_jobs="$(printf '%s\n' "$jobs" | awk '$2 == "True" {print $1}')"
  if [ -z "$failed_jobs" ]; then
    echo "no failed jobs"
  fi
  for job in $failed_jobs; do
    case "$job" in
      *[!a-z0-9.-]*) echo "status: skipped a job with an unexpected name" >&2; failed=1; continue ;;
    esac
    printf '\n== log of the failed job %s\n' "$job"
    if logs="$(remote_root k3s kubectl -n shop logs -l "job-name=$job" --all-containers --prefix --ignore-errors --tail=100)"; then
      if [ -n "$logs" ]; then
        printf '%s\n' "$logs"
      else
        echo "no pods of the job $job left; the logs of the pods are in Grafana Cloud (Explore, namespace shop)"
      fi
    else
      # kubectl still exits with a code other than 0 when it read something: what it printed before is not lost.
      [ -z "$logs" ] || printf '%s\n' "$logs"
      echo "status: could not read log of the failed job $job" >&2
      failed=1
    fi
  done
else
  echo "status: could not read the failed jobs" >&2
  failed=1
fi
exit "$failed"

#!/bin/sh
# The state of the environment, read over SSH: `make -C infra/k8s status HOST=<user>@<address>`. Prints the applications of
# Argo CD, the pods and the jobs of the namespace shop, and the log of every job of the namespace that failed (the Failed
# condition; the reaction to the alerts BackupFailed and BackupVerifyFailed). Changes nothing. Exit code 1 when any read failed.
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

# The jobs with the Failed condition, then the log of each (all containers, the last lines): the words of a remote command are
# fixed (see remote_root), so the filter is one single-quoted word and the names are filtered here.
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
    section "log of the failed job $job" -n shop logs "job/$job" --all-containers --prefix --tail=100
  done
else
  echo "status: could not read the failed jobs" >&2
  failed=1
fi
exit "$failed"

#!/bin/sh
# The state of the environment, read over SSH: `make -C infra/k8s status HOST=<user>@<address>`. Prints the applications of
# Argo CD, the pods and the jobs of the namespace shop, and the logs of every job of the namespace that failed (the Failed
# condition; the reaction to the alerts BackupFailed and BackupVerifyFailed): of all the pods of the job, so of every attempt
# (backoffLimit), while they exist; the containers whose log cannot be read (the ones after a failed init container never
# started) are skipped, the log of the others is printed. Last, the state of the demo data (docs/features/demo-scale/spec.md): data
# and phase of the record demo-data-state (no record: data seed, phase idle), its target and reason, whether the shop is closed (the
# NetworkPolicy shop-closed exists) and the last line of the log of the newest Job initialize (its row counts; it says so when no pod
# of the Job is left). Changes nothing. Exit code 1 when any read failed.
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

# The state of the demo data. The record is read as one text: a marker line when the ConfigMap exists, then data, phase, target and
# (the rest of the text) reason; kubectl prints nothing, with exit code 0, when there is no ConfigMap (--ignore-not-found).
# Control characters of the record are dropped (the reason is text written by a script, printed to the terminal of the operator).
printf '\n== demo data (record demo-data-state, rule shop-closed, job initialize)\n'
state_path='present{"\n"}{.data.data}{"\n"}{.data.phase}{"\n"}{.data.target}{"\n"}{.data.reason}'
if state="$(remote_root k3s kubectl -n shop get configmap demo-data-state --ignore-not-found -o "jsonpath='$state_path'")"; then
  if [ -z "$state" ]; then
    echo "no record demo-data-state (the demo data were not generated): data seed, phase idle"
    echo "data: seed"
    echo "phase: idle"
  else
    state="$(printf '%s\n' "$state" | tr -d '\000-\010\013-\037\177')"
    data="$(printf '%s\n' "$state" | sed -n 2p)"
    phase="$(printf '%s\n' "$state" | sed -n 3p)"
    target="$(printf '%s\n' "$state" | sed -n 4p)"
    reason="$(printf '%s\n' "$state" | sed -n '5,$p')"
    echo "data: ${data:-(empty)}"
    echo "phase: ${phase:-(empty)}"
    echo "target: ${target:-(none)}"
    [ -z "$reason" ] || echo "reason: $reason"
  fi
else
  echo "status: could not read the record of the demo data (ConfigMap demo-data-state)" >&2
  failed=1
fi
if closed="$(remote_root k3s kubectl -n shop get networkpolicy shop-closed --ignore-not-found -o name)"; then
  if [ -n "$closed" ]; then
    echo "shop: closed (NetworkPolicy shop-closed exists; every host answers 502)"
  else
    echo "shop: open (no NetworkPolicy shop-closed)"
  fi
else
  echo "status: could not read the rule shop-closed" >&2
  failed=1
fi
# The newest Job initialize (listed by the time of creation, so the last name) and the last line of its log: the container is named,
# because the init containers that wait for the rollouts have logs too.
init_path='{range .items[*]}{.metadata.name}{"\n"}{end}'
if init_jobs="$(remote_root k3s kubectl -n shop get jobs -l app.kubernetes.io/name=initialize --sort-by=.metadata.creationTimestamp -o "jsonpath='$init_path'")"; then
  init_job="$(printf '%s\n' "$init_jobs" | sed '/^$/d' | tail -n 1)"
  case "$init_job" in
    "") echo "no job initialize in the namespace shop" ;;
    *[!a-z0-9.-]*) echo "status: skipped a job initialize with an unexpected name" >&2; failed=1 ;;
    *)
      if init_log="$(remote_root k3s kubectl -n shop logs -l "job-name=$init_job" --container=initialize --tail=1)"; then
        if [ -n "$init_log" ]; then
          echo "last line of the log of the job $init_job:"
          printf '%s\n' "$init_log" | tail -n 1
        else
          echo "no pods of the job $init_job left; the logs of the pods are in Grafana Cloud (Explore, namespace shop)"
        fi
      else
        echo "status: could not read the log of the job $init_job" >&2
        failed=1
      fi ;;
  esac
else
  echo "status: could not read the jobs initialize" >&2
  failed=1
fi
exit "$failed"

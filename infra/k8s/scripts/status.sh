#!/bin/sh
# The state of the environment, read over SSH: `make -C infra/k8s status HOST=<user>@<address>`. Prints the applications of
# Argo CD, the pods and the jobs of the namespace shop, and the logs of every job of the namespace that failed (the Failed
# condition; the reaction to the alerts BackupFailed and BackupVerifyFailed): of all the pods of the job, so of every attempt
# (backoffLimit), while they exist; the containers whose log cannot be read (the ones after a failed init container never
# started) are skipped, the log of the others is printed. Last, the state of the demo data (docs/features/demo-scale/spec.md):
# whether the shop is closed (the NetworkPolicy shop-closed exists), the lock demo-data-lock of make generate and make open and its
# holder, the Job demo-data-run of make generate and its state, the active
# Jobs (make generate and make open refuse while one runs), whether the Secret of the photo pool is left (make open deletes it) and the
# last line of the log of the newest Job initialize (its row counts; it says so when no pod of the Job is left). Changes nothing. Exit
# code 1 when any read failed.
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

printf '\n== demo data (rule shop-closed, lock demo-data-lock, job demo-data-run, active jobs, secret of the photo pool, job initialize)\n'
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
lock_path='{.metadata.name}{"/"}{.data.holder}'
if lock="$(remote_root k3s kubectl -n shop get configmap demo-data-lock --ignore-not-found -o "jsonpath='$lock_path'")"; then
  if [ -n "$lock" ]; then
    lock="$(printf '%s' "${lock#*/}" | tr -d '\000-\037\177')"
    echo "lock demo-data-lock: HELD by ${lock:-(no holder written)} (make generate, make open and make reindex refuse while it is there; left by one killed with KILL, it is deleted only when none of them runs: sudo k3s kubectl -n shop delete configmap demo-data-lock)"
  else
    echo "lock demo-data-lock: none"
  fi
else
  echo "status: could not read the lock demo-data-lock" >&2
  failed=1
fi
# Every Job with the types of its true conditions (Complete, Failed; none while it runs), one per line.
states_path='{range .items[*]}{.metadata.name}{" "}{.status.conditions[?(@.status=="True")].type}{"\n"}{end}'
if states="$(remote_root k3s kubectl -n shop get jobs -o "jsonpath='$states_path'")"; then
  states="$(printf '%s\n' "$states" | tr -d '\000-\011\013-\037\177')"
  printf '%s\n' "$states" | awk '
    $1 == "demo-data-run" { run = "running"; for (i = 2; i <= NF; i++) if ($i == "Complete" || $i == "Failed") run = $i }
    NF { done = 0; for (i = 2; i <= NF; i++) if ($i == "Complete" || $i == "Failed") done = 1; if (!done) active = active " " $1 }
    END {
      print "demo-data-run: " (run == "" ? "none (make generate never ran, or it deleted the previous one)" : run)
      print "active jobs:" (active == "" ? " none" : active)
    }'
else
  echo "status: could not read the states of the jobs" >&2
  failed=1
fi
secret_path='{.spec.jobTemplate.spec.template.spec.containers[0].env[?(@.name=="DEMO_PHOTOS_ENDPOINT")].valueFrom.secretKeyRef.name}'
if ! secret="$(remote_root k3s kubectl -n shop get cronjob demo-data --ignore-not-found -o "jsonpath='$secret_path'")"; then
  echo "status: could not read the Secret of the photo pool (the CronJob demo-data)" >&2
  failed=1
elif [ -z "$secret" ]; then
  echo "the CronJob demo-data reads no Secret of the photo pool"
elif ! printf '%s' "$secret" | grep -Eqx '[a-z0-9.-]+'; then
  echo "status: skipped a Secret of the photo pool with an unexpected name" >&2
  failed=1
elif ! left="$(remote_root k3s kubectl -n shop get secret "$secret" --ignore-not-found -o name)"; then
  echo "status: could not read the Secret of the photo pool $secret" >&2
  failed=1
elif [ -n "$left" ]; then
  echo "the Secret $secret of the photo pool: LEFT (make open deletes it once no Job runs)"
else
  echo "the Secret $secret of the photo pool: none"
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

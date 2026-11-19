#!/bin/sh
# The Job of the CronJob demo-data (docs/features/demo-scale/spec.md, "Job generatora na serwerze (chart)"): replaces the data of the
# shop with the demo data of a profile (bin/generate-demo) or with the seed (bin/seed-demo), and stops that run before the disk of the
# node fills up. It runs in the image of the api (working directory /app; Debian: dash, util-linux setsid, coreutils df) as PID 1 of
# its container, so the processes it leaves end with it.
#
# Usage: demo-data.sh --profile=<profile> [--anchor=<date or UTC moment>] --min-free-gb=<GB>
#   (the CronJob passes demoData.profile, demoData.anchor when it is set, and demoData.minFreeGb; GB is 10^9 bytes)
#
# Environment:
#   DEMO_DATA_CONFIRM          the profile of --profile (bin/generate-demo --profile=<profile> [--anchor=<anchor>] --force-reset) or
#                              `seed` (bin/seed-demo). Only make -C infra/k8s generate, make -C infra/k8s load and the smoke test set
#                              it when they create the Job (scripts/kube-jobs.sh, cronjob_job), so a Job created from the CronJob by
#                              hand or by Argo CD refuses and deletes nothing.
#   DEMO_DATA_SPACE_POLL=30    seconds between two readings of the free space
#   DEMO_DATA_STOP_GRACE=30    seconds between TERM and KILL to the process group of the generator
#   DEMO_DATA_TERMINATION_LOG  the file of the line of the reason (/dev/termination-log; the tests point it elsewhere)
#
# Exit codes: 0 done; 64 refused before anything ran (DEMO_DATA_CONFIRM or the arguments); 74 too little room (below --min-free-gb;
# the seed has no floor) or no reading of `df -Pk /` before the start, nothing ran; 75 stopped by the guard of the free space (below
# the floor, three failed readings in a row, a reading that is no number) or by TERM, the whole process group of the generator stopped
# (the data is incomplete); any other: the exit code of the generator. Every path writes one line "exit <code>: <reason>" to the
# termination log. The run: docs/features/demo-scale/spec.md, "Job generatora na serwerze (chart)".
set -u

poll="${DEMO_DATA_SPACE_POLL:-30}"
grace="${DEMO_DATA_STOP_GRACE:-30}"
termination_log="${DEMO_DATA_TERMINATION_LOG:-/dev/termination-log}"

work="" command_line="none" generator_status="not started" gen_pid="" guard_pid=""
generator_done="" term_seen="" trapped="" self_pgid="" floor_kib="" floor_text=""

say() { printf 'demo-data: %s\n' "$*"; }

# The KiB of df as GB (10^9 bytes), two decimals.
gb() { awk -v kib="$1" 'BEGIN { printf "%.2f", kib * 1024 / 1000000000 }'; }

# A pause that a trapped signal interrupts at once (the trap runs, the caller goes on).
nap() {
  sleep "$1" &
  nap_pid=$!
  wait "$nap_pid" 2> /dev/null || :
}

# report <code> <reason>: the line of the reason in the termination log and at the end of the log; returns <code>, which the last
# command of the script passes on as its exit code. finish: report, then exit with <code> (the paths that end early).
report() {
  finish_reason="$(printf '%s' "$2" | tr '\n\r' '  ')"
  printf 'exit %s: %s\n' "$1" "$finish_reason" > "$termination_log" 2> /dev/null \
    || say "the reason could not be written to $termination_log"
  say "exit $1: $finish_reason"
  [ -z "$work" ] || rm -rf "$work"
  return "$1"
}
finish() {
  report "$@"
  exit "$1"
}

# One reading of the free space of /: space_state is ok (space_kib has the KiB), failed (df failed or printed no line for /) or nan
# (space_text is what stood in the column Available).
read_space() {
  space_kib="" space_text=""
  if ! space_out="$(df -Pk / 2> /dev/null)"; then
    space_state=failed
    return 0
  fi
  space_line="$(printf '%s\n' "$space_out" | sed -n 2p)"
  if [ -z "$space_line" ]; then
    space_state=failed
    return 0
  fi
  space_text="$(printf '%s\n' "$space_line" | awk '{ print $4 }')"
  case "$space_text" in
    "" | *[!0-9]*) space_state=nan ;;
    *) space_state=ok; space_kib="$space_text" ;;
  esac
}

below_floor() { [ -n "$floor_kib" ] && awk -v a="$1" -v b="$floor_kib" 'BEGIN { exit !(a + 0 < b + 0) }'; }

print_reading() { say "free space of /: $(gb "$1") GB ($1 KiB), the floor is $floor_text (reading $2)"; }

# The first cause of a stop wins (the guard and TERM may both come).
stop_reason() {
  [ -s "$work/reason" ] || printf '%s\n' "$1" > "$work/reason"
}

# A process of the group of the generator still runs (a zombie runs nothing). /proc/<pid>/stat: "<pid> (<name>) <state> <ppid> <pgrp>".
group_alive() {
  for group_stat in /proc/[0-9]*/stat; do
    group_line=""
    read -r group_line 2> /dev/null < "$group_stat" || continue
    # shellcheck disable=SC2086 # the fields after the name, split on purpose
    set -- ${group_line##*) }
    [ "${3:-}" = "$gen_pid" ] && [ "${1:-}" != Z ] && return 0
  done
  return 1
}

# The state (R, S, Z, ...) and the process group of <pid> into proc_state and proc_group (both empty when it is gone).
read_proc() {
  proc_state="" proc_group="" proc_line=""
  read -r proc_line 2> /dev/null < "/proc/$1/stat" || return 0
  # shellcheck disable=SC2086 # the fields after the name, split on purpose
  set -- ${proc_line##*) }
  proc_state="${1:-}" proc_group="${3:-}"
}

# Stops the process group of the generator, and only it: TERM, then KILL to what is left after DEMO_DATA_STOP_GRACE seconds. The group
# is the one setsid made (its number is the pid of the generator); it is never the group of this script.
stop_group() {
  case "$gen_pid" in "" | 0 | 1 | *[!0-9]*) return 0 ;; esac
  if [ "$gen_pid" = "$self_pgid" ]; then
    say "the generator is in the process group of this script; only it is stopped" >&2
    kill -TERM "$gen_pid" 2> /dev/null || :
    return 0
  fi
  group_alive || return 0
  say "stopping the process group $gen_pid of the generator (TERM, KILL after $grace s)"
  kill -TERM "-$gen_pid" 2> /dev/null || :
  stop_deadline=$(($(date +%s) + grace))
  while group_alive && [ "$(date +%s)" -lt "$stop_deadline" ]; do nap 1; done
  group_alive || return 0
  say "the process group $gen_pid of the generator still runs after TERM: KILL"
  kill -KILL "-$gen_pid" 2> /dev/null || :
  stop_deadline=$(($(date +%s) + 10))
  while group_alive && [ "$(date +%s)" -lt "$stop_deadline" ]; do nap 1; done
  group_alive || return 0
  say "processes of the group $gen_pid still run after KILL; they end with the container" >&2
}

# The guard: a reading every DEMO_DATA_SPACE_POLL seconds until the script stops it (TERM, and the file stop-guard: a TERM that comes
# before the guard set its trap is lost) or it stops the generator. A TERM that comes while it stops the group does not cut the stop
# short.
guard() {
  guard_quit="" guard_failures=0 guard_n=1
  trap 'guard_quit=1' TERM
  trap '' INT
  while :; do
    sleep "$poll" &
    guard_sleep=$!
    wait "$guard_sleep" 2> /dev/null || :
    if [ -n "$guard_quit" ] || [ -e "$work/stop-guard" ]; then
      kill "$guard_sleep" 2> /dev/null || :
      exit 0
    fi
    guard_n=$((guard_n + 1))
    read_space
    case "$space_state" in
      ok)
        guard_failures=0
        if below_floor "$space_kib"; then
          print_reading "$space_kib" "$guard_n"
          stop_reason "the free space of / fell to $(gb "$space_kib") GB ($space_kib KiB), below the floor of $min_free_gb GB (demoData.minFreeGb); the generator was stopped"
          stop_group
          exit 0
        fi
        [ $((guard_n % 10)) -ne 0 ] || print_reading "$space_kib" "$guard_n" ;;
      failed)
        guard_failures=$((guard_failures + 1))
        say "the free space of / could not be read (df -Pk /; reading $guard_n, $guard_failures in a row)"
        if [ "$guard_failures" -ge 3 ]; then
          stop_reason "the free space of / could not be read 3 times in a row (df -Pk /); the generator was stopped"
          stop_group
          exit 0
        fi ;;
      nan)
        say "the free space of / read as '$space_text', which is no number (df -Pk /; reading $guard_n)"
        stop_reason "the free space of / read as '$space_text', which is no number (df -Pk /); the generator was stopped"
        stop_group
        exit 0 ;;
    esac
  done
}

on_term() {
  trapped=1
  [ -z "$term_seen" ] || return 0
  term_seen=1
  if [ -z "$gen_pid" ]; then
    finish 75 "the container got TERM before the generator started; nothing was started"
  fi
  [ -z "$generator_done" ] || return 0
  say "TERM: stopping the generator"
  stop_reason "the container got TERM (the time limit of the Job, or the pod was deleted); the generator was stopped"
  stop_group
}
trap 'on_term' TERM INT

# 1. The arguments and the confirmation.
profile="" anchor="" min_free_gb="" bad_argument=""
for argument in "$@"; do
  case "$argument" in
    --profile=*) profile="${argument#--profile=}" ;;
    --anchor=*) anchor="${argument#--anchor=}" ;;
    --min-free-gb=*) min_free_gb="${argument#--min-free-gb=}" ;;
    *) bad_argument="$argument" ;;
  esac
done
[ -z "$bad_argument" ] || finish 64 "unknown argument '$bad_argument'; nothing was started"
case "$profile" in
  "" | *[!a-z0-9_-]*) finish 64 "--profile must be the name of a profile, got '$profile'; nothing was started" ;;
esac
case "$min_free_gb" in
  "" | *[!0-9]* | 0 | 0*) finish 64 "--min-free-gb must be a positive whole number of GB, got '$min_free_gb'; nothing was started" ;;
esac
case "$poll" in
  "" | *[!0-9]* | 0 | 0*) finish 64 "DEMO_DATA_SPACE_POLL must be a positive whole number of seconds, got '$poll'; nothing was started" ;;
esac
case "$grace" in
  "" | *[!0-9]*) finish 64 "DEMO_DATA_STOP_GRACE must be a whole number of seconds, got '$grace'; nothing was started" ;;
esac
confirm="${DEMO_DATA_CONFIRM:-}"
if [ -z "$confirm" ]; then
  finish 64 "DEMO_DATA_CONFIRM is empty: a Job of the CronJob demo-data runs only when make -C infra/k8s generate creates it; nothing was started"
fi
if [ "$confirm" = seed ]; then
  set -- bin/seed-demo
elif [ "$confirm" = "$profile" ]; then
  set -- bin/generate-demo "--profile=$profile"
  [ -z "$anchor" ] || set -- "$@" "--anchor=$anchor"
  set -- "$@" --force-reset
else
  finish 64 "DEMO_DATA_CONFIRM '$confirm' is neither the profile '$profile' of the CronJob nor seed; nothing was started"
fi
command_line="$*"

# 2. The room before the start: the floor of a profile (in KiB rounded up); the seed has none (it is the way back after a stop by
# the guard, when the space is below the floor; it starts with TRUNCATE and needs less than 2 GiB).
if [ "$confirm" = seed ]; then
  floor_text="none (the seed)"
else
  floor_kib=$(((min_free_gb * 1000000000 + 1023) / 1024))
  floor_text="$min_free_gb GB"
fi
work="$(mktemp -d "${TMPDIR:-/tmp}/demo-data.XXXXXX")" || { work=""; finish 74 "no work directory (mktemp -d failed); nothing was started"; }
read_space
case "$space_state" in
  failed) finish 74 "the free space of / could not be read (df -Pk /); nothing was started" ;;
  nan) finish 74 "the free space of / read as '$space_text', which is no number (df -Pk /); nothing was started" ;;
esac
print_reading "$space_kib" 1
if below_floor "$space_kib"; then
  finish 74 "the free space of / is $(gb "$space_kib") GB ($space_kib KiB), below the floor of $min_free_gb GB (demoData.minFreeGb); nothing was started"
fi

# 3. The run, in a process group of its own; its output goes to the log of the container.
read_proc $$
self_pgid="$proc_group"
say "running $command_line (a process group of its own; the free space is read every $poll s)"
setsid "$@" < /dev/null &
gen_pid=$!
# setsid makes the group a moment after the start: until then a stop would find no group.
# A generator that is already gone (a zombie keeps its group) or never made its group (setsid failed) is waited for below.
setsid_deadline=$(($(date +%s) + 10))
while :; do
  read_proc "$gen_pid"
  [ "$proc_group" != "$gen_pid" ] || break
  case "$proc_state" in "" | Z) break ;; esac
  [ "$(date +%s)" -lt "$setsid_deadline" ] || break
  nap 1
done
if [ -n "$term_seen" ]; then
  # TERM came between the start and the group: stop it now that it is there.
  stop_reason "the container got TERM (the time limit of the Job, or the pod was deleted); the generator was stopped"
  stop_group
fi
guard &
guard_pid=$!

# 4. and 5. Waiting for the generator: a TERM interrupts the wait, its trap stops the group, and the wait goes on.
while :; do
  trapped=""
  wait_status=0
  wait "$gen_pid" || wait_status=$?
  [ -n "$trapped" ] && continue
  generator_status="$wait_status"
  break
done
generator_done=1
# The guard ends after a stop it began; the rest of the group (a process the generator left) is stopped as well.
: > "$work/stop-guard"
kill -TERM "$guard_pid" 2> /dev/null || :
wait "$guard_pid" 2> /dev/null || :
stop_group

# 6. The exit code.
if [ -s "$work/reason" ]; then
  final_code=75 final_reason="$(cat "$work/reason")"
elif [ "$generator_status" = 0 ]; then
  final_code=0 final_reason="$command_line ended with 0"
else
  final_code="$generator_status" final_reason="$command_line ended with $generator_status (the last finished step is in the log)"
fi
report "$final_code" "$final_reason"

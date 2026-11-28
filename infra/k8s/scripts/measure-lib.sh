#!/bin/sh
# Functions of measure.sh that are tested alone (tests/test_measure.py); also copied into the Jobs (k6_outcome, baseline_outcome).
# POSIX sh, no side effects on sourcing.

# k6_runs_valid "<runs>": every run is name:kind:share[,kind:share...] with a name of [a-z0-9-], a kind that only reads
# (home, category, product, search; cart and anything else write or are unknown) and a share that is a positive number.
# Prints the reason to stderr and returns 1 on the first problem; creates nothing.
k6_runs_valid() {
  [ -n "$1" ] || { echo "measure: MEASURE_K6_RUNS is empty" >&2; return 1; }
  for run in $1; do
    name="${run%%:*}"
    mix="${run#*:}"
    case "$name" in "" | *[!a-z0-9-]*) echo "measure: a k6 run needs a name of a-z, 0-9 and -: '$run'" >&2; return 1 ;; esac
    [ "$mix" != "$run" ] && [ -n "$mix" ] || { echo "measure: a k6 run looks like name:kind:share,kind:share, got '$run'" >&2; return 1; }
    old_ifs="$IFS"
    IFS=,
    # shellcheck disable=SC2086 # splitting on commas is the point
    set -- $mix
    IFS="$old_ifs"
    for part in "$@"; do
      kind="${part%%:*}"
      share="${part#*:}"
      case "$kind" in
        home | category | product | search) ;;
        *) echo "measure: the kind '$kind' is not allowed in MEASURE_K6_RUNS (read only: home, category, product, search): '$run'" >&2; return 1 ;;
      esac
      case "$share" in "" | *[!0-9]* | 0*) echo "measure: the share of '$kind' is not a positive number: '$run'" >&2; return 1 ;; esac
    done
  done
}

# k6_outcome <name> <exit code of k6> <ramp.json>: the line the Job writes after a k6 run. Exit 0 (no threshold crossed) and 99
# (a threshold crossed: the expected end of a ramp at saturation) with a non-empty ramp.json are a measurement; every other
# exit code, or no result file, is a failure of the tool and the measurement is incomplete.
k6_outcome() {
  case "$2" in
    0 | 99)
      if [ -s "$3" ]; then
        echo "##K6 $1 exit=$2"
      else
        echo "##FAILED k6-$1 exit=$2 without $3"
      fi
      ;;
    *) echo "##FAILED k6-$1 exit=$2" ;;
  esac
}

# baseline_outcome <stage> <exit code of baseline.py> <meta file>: baseline.py exits 1 on failed requests or an aborted run and
# writes its meta file first (a result); without the file, or with any other code, it is a crash of the tool.
baseline_outcome() {
  if [ -s "$3" ] && { [ "$2" = 0 ] || [ "$2" = 1 ]; }; then
    echo "##RC $1 exit=$2"
  else
    echo "##FAILED $1 exit=$2 without $3"
  fi
}

# need_file <stage> <file>: the stage cannot go on without the file (the list of addresses).
need_file() {
  [ -s "$2" ] || echo "##FAILED $1 no $2"
}

# log_problem <log of the Job>: prints why the log is not a complete measurement and returns 1; returns 0 when it is complete.
# Complete = the closing marker ##DONE, written after all the stages, and no ##FAILED line (a single ##END of one stage is not enough).
log_problem() {
  [ -s "$1" ] || { echo "no log"; return 1; }
  failed="$(grep '^##FAILED ' "$1" || true)"
  if [ -n "$failed" ]; then
    printf '%s\n' "$failed"
    return 1
  fi
  grep -q '^##DONE$' "$1" || { echo "no ##DONE: the Job stopped before the last stage"; return 1; }
  return 0
}

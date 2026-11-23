#!/bin/sh
# Prints what the change from <base> to <head> touches, which decides how much of the CI runs:
#   docs     documentation only (docs/**, *.md outside .github/ and the issue forms in .github/ISSUE_TEMPLATE/**,
#            which no CI step reads)
#   tooling  repository tooling (.claude/**, .ao/**, tools/**), possibly with documentation: only the script tests run
#   k8s      the Kubernetes deployment (infra/k8s/**: chart, environments, sealed secrets, server scripts), possibly with
#            documentation: only `make -C infra/k8s check` runs. The compose stack of the tests and E2E does not use
#            infra/k8s; deploy.yml runs its own smoke test of the chart for such a change.
#   full     anything else (application code, other infra, Makefile, .github/), or a mix of tooling and k8s: the full CI
# Any error prints `full` with a ::warning::, so it can only cost a full CI run.
#
# With --k8s it answers instead whether the change touches infra/k8s at all (any file under it, also documentation, on
# either side of a move): `yes` or `no`. In the full CI it decides whether `make -C infra/k8s check` runs; deploy.yml runs
# that check before every deployment anyway. Any error prints `yes` with a ::warning::.
#
# Usage: scope.sh [--k8s] <base-sha> <head-sha>
set -u

mode=scope
if [ "${1:-}" = --k8s ]; then
    mode=k8s
    shift
fi

fail() {
    if [ "$mode" = k8s ]; then
        echo "::warning::scope: $1, running make -C infra/k8s check" >&2
        echo yes
    else
        echo "::warning::scope: $1, running the full CI" >&2
        echo full
    fi
    exit 0
}

[ $# -eq 2 ] || fail "expected <base> <head>, got $# argument(s)"

# --no-renames lists both sides of a rename, so moving code into docs/ or tooling still counts as a code change.
# Paths with control characters stay quoted ("...") and match no pattern below.
files="$(git -c core.quotePath=false diff --no-renames --name-only "$1...$2")" || fail "git diff $1...$2 failed"
[ -n "$files" ] || fail "empty diff"

# A quoted path keeps its directories after the opening quote. Any failure of the loop answers yes.
if [ "$mode" = k8s ]; then
    if echo "$files" | {
        while IFS= read -r file; do
            case "$file" in
                infra/k8s/* | '"infra/k8s/'*) exit 1 ;;
            esac
        done
    }; then
        echo no
    else
        echo yes
    fi
    exit 0
fi

# The loop runs in a subshell (pipe): its exit status carries the result. 0 docs, 1 tooling, 3 k8s, 2 full.
# Tooling and k8s in one change is full: each scope runs only its own checks.
echo "$files" | {
    scope=0
    while IFS= read -r file; do
        case "$file" in
            .github/ISSUE_TEMPLATE/*) ;;
            .github/*) exit 2 ;;
            docs/* | *.md) ;;
            .claude/* | .ao/* | tools/*) [ "$scope" -eq 3 ] && exit 2; scope=1 ;;
            infra/k8s/*) [ "$scope" -eq 1 ] && exit 2; scope=3 ;;
            *) exit 2 ;;
        esac
    done
    exit "$scope"
}
case $? in
    0) echo docs ;;
    1) echo tooling ;;
    3) echo k8s ;;
    *) echo full ;;
esac

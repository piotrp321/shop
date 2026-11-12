#!/bin/sh
# Prints what the change from <base> to <head> touches, which decides how much of the CI runs:
#   docs     documentation only (docs/** and *.md outside .github/)
#   tooling  repository tooling (.claude/**, .ao/**, tools/**), possibly with documentation: only the script tests run
#   k8s      the Kubernetes deployment (infra/k8s/**: chart, environments, sealed secrets, server scripts), possibly with
#            documentation: only `make -C infra/k8s check` runs. The compose stack of the tests and E2E does not use
#            infra/k8s; deploy.yml runs its own smoke test of the chart for such a change.
#   full     anything else (application code, other infra, Makefile, .github/), or a mix of tooling and k8s: the full CI
# Any error prints `full` with a ::warning::, so it can only cost a full CI run.
# Usage: scope.sh <base-sha> <head-sha>
set -u

fail() {
    echo "::warning::scope: $1, running the full CI" >&2
    echo full
    exit 0
}

[ $# -eq 2 ] || fail "expected <base> <head>, got $# argument(s)"

# --no-renames lists both sides of a rename, so moving code into docs/ or tooling still counts as a code change.
# Paths with control characters stay quoted ("...") and match no pattern below.
files="$(git -c core.quotePath=false diff --no-renames --name-only "$1...$2")" || fail "git diff $1...$2 failed"
[ -n "$files" ] || fail "empty diff"

# The loop runs in a subshell (pipe): its exit status carries the result. 0 docs, 1 tooling, 3 k8s, 2 full.
# Tooling and k8s in one change is full: each scope runs only its own checks.
echo "$files" | {
    scope=0
    while IFS= read -r file; do
        case "$file" in
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

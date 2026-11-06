#!/bin/sh
# Prints what the change from <base> to <head> touches, which decides how much of the CI runs:
#   docs     documentation only (docs/** and *.md outside .github/)
#   tooling  repository tooling (.claude/**, .ao/**, tools/**), possibly with documentation: only the script tests run
#   full     anything else (application code, infra, Makefile, .github/): the full CI
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

# The loop runs in a subshell (pipe): its exit status carries the result. 0 docs, 1 tooling, 2 full.
echo "$files" | {
    scope=0
    while IFS= read -r file; do
        case "$file" in
            .github/*) exit 2 ;;
            docs/* | *.md) ;;
            .claude/* | .ao/* | tools/*) scope=1 ;;
            *) exit 2 ;;
        esac
    done
    exit "$scope"
}
case $? in
    0) echo docs ;;
    1) echo tooling ;;
    *) echo full ;;
esac

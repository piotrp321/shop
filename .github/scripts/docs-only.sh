#!/bin/sh
# Prints `true` when the change from <base> to <head> touches documentation only (docs/** and *.md outside
# .github/), otherwise `false`. Any error prints `false` with a ::warning::, so it can only cost a full CI run.
# Usage: docs-only.sh <base-sha> <head-sha>
set -u

fail() {
    echo "::warning::docs-only: $1, running the full CI" >&2
    echo false
    exit 0
}

[ $# -eq 2 ] || fail "expected <base> <head>, got $# argument(s)"

# --no-renames lists both sides of a rename, so moving code into docs/ still counts as a code change.
# Paths with control characters stay quoted ("...") and match no pattern below.
files="$(git -c core.quotePath=false diff --no-renames --name-only "$1...$2")" || fail "git diff $1...$2 failed"
[ -n "$files" ] || fail "empty diff"

echo "$files" | while IFS= read -r file; do
    case "$file" in
        .github/*) exit 1 ;;
        docs/*|*.md) ;;
        *) exit 1 ;;
    esac
done || { echo false; exit 0; }

echo true

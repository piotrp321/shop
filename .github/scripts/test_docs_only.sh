#!/bin/sh
# Tests of docs-only.sh on throwaway git repositories: one per case, base commit + one change commit.
set -u

script="$(cd "$(dirname "$0")" && pwd)/docs-only.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0

# new_repo <name>: creates a repo with a base commit holding one code file and one doc.
new_repo() {
    repo="$tmp/$1"
    git init -q "$repo"
    git -C "$repo" config user.email ci@example.test
    git -C "$repo" config user.name CI
    mkdir -p "$repo/docs" "$repo/apps/api/src"
    echo base > "$repo/docs/a.md"
    echo base > "$repo/apps/api/src/A.php"
    git -C "$repo" add -A
    git -C "$repo" commit -qm base
    base="$(git -C "$repo" rev-parse HEAD)"
}

# change <path>...: writes the files and commits them as the PR head.
change() {
    for path in "$@"; do
        mkdir -p "$repo/$(dirname "$path")"
        echo changed >> "$repo/$path"
    done
    git -C "$repo" add -A
    git -C "$repo" commit -qm change --allow-empty
    head="$(git -C "$repo" rev-parse HEAD)"
}

# expect <name> <expected> [base]: runs the script in the repo and compares its stdout.
expect() {
    actual="$(cd "$repo" && sh "$script" "${3:-$base}" "$head" 2>"$tmp/stderr")"
    if [ "$actual" = "$2" ]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected '$2', got '$actual'"
        failures=$((failures + 1))
    fi
}

new_repo docs; change docs/features/x/spec.md README.md
expect "docs and root markdown only" true

new_repo app-md; change apps/api/CLAUDE.md
expect "markdown inside an app" true

new_repo mixed; change docs/features/x/spec.md apps/api/src/A.php
expect "docs plus a code file" false

new_repo ci; change .github/workflows/ci.yml
expect "only the CI workflow" false

new_repo gh-md; change .github/PULL_REQUEST_TEMPLATE.md
expect "markdown under .github" false

new_repo md-dir; change docs.md/run.sh
expect "code in a directory named like markdown" false

new_repo rename; git -C "$repo" mv apps/api/src/A.php docs/B.md; change
expect "code file renamed into docs" false

new_repo empty; change
expect "empty diff" false

new_repo bad-base; change docs/b.md
expect "unknown base commit" false 0000000000000000000000000000000000000000
if ! grep -q '::warning::' "$tmp/stderr"; then
    echo "FAIL unknown base commit: no ::warning:: on stderr"
    failures=$((failures + 1))
fi

new_repo no-args; change docs/b.md
actual="$(cd "$repo" && sh "$script" 2>/dev/null)"
if [ "$actual" = false ]; then echo "ok   missing arguments"; else
    echo "FAIL missing arguments: expected 'false', got '$actual'"
    failures=$((failures + 1))
fi

[ "$failures" -eq 0 ] || { echo "$failures failure(s)"; exit 1; }
echo "all passed"

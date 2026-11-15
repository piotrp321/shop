#!/bin/sh
# Tests of evals-args.sh: the arguments of `evals.py check` for each event of the CI, and the error paths.
set -u

script="$(cd "$(dirname "$0")" && pwd)/evals-args.sh"
base=1111111111111111111111111111111111111111
head=2222222222222222222222222222222222222222
failures=0

# expect <name> <expected stdout> <exit status> <script arguments...>
expect() {
    name="$1"; expected="$2"; status="$3"
    shift 3
    actual="$(sh "$script" "$@" 2>/dev/null)"
    code=$?
    if [ "$actual" = "$expected" ] && [ "$code" -eq "$status" ]; then
        echo "ok   $name"
    else
        echo "FAIL $name: expected '$expected' (exit $status), got '$actual' (exit $code)"
        failures=$((failures + 1))
    fi
}

expect "pull request to main: enforced against the diff" "--enforce --diff $base...$head" 0 pull_request main "$base" "$head"
expect "pull request to an epic branch: the diff, not enforced" "--diff $base...$head" 0 pull_request epic/evals "$base" "$head"
expect "pull request to another branch: the diff, not enforced" "--diff $base...$head" 0 pull_request feature "$base" "$head"
expect "push to main: enforced without a diff" "--enforce" 0 push main "" ""
expect "push to an epic branch: neither" "" 0 push epic/evals "" ""
expect "push ignores the shas" "--enforce" 0 push main "$base" "$head"

expect "pull request without shas" "" 2 pull_request main "" ""
expect "pull request with a short sha" "" 2 pull_request main abc123 "$head"
expect "pull request with a sha that is a revision expression" "" 2 pull_request main "main; rm -rf /" "$head"
expect "pull request with a base branch that is empty" "" 2 pull_request "" "$base" "$head"
expect "an unknown event" "" 2 workflow_dispatch main "$base" "$head"
expect "no arguments" "" 2
expect "too many arguments" "" 2 push main "" "" extra

# A branch name that is a shell or option injection never reaches the output.
expect "a push to a branch with an option-like name" "" 0 push "--enforce" "" ""

if [ "$failures" -ne 0 ]; then
    echo "$failures failure(s)"
    exit 1
fi

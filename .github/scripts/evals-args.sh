#!/bin/sh
# Prints the arguments of `evals.py check` for the event of the CI (the `evals` job; opis: tools/evals/README.md):
#   pull request to main          --enforce --diff <base>...<head>   a stale or regressed changed suite is an error
#   pull request to another base  --diff <base>...<head>             warnings only (epic/* branches)
#   push to main                  --enforce                           every stale suite with a measurement is an error
#   push to another branch        (nothing)                           warnings only (epic/** branches)
# Exit status: 0 printed, 2 bad arguments (nothing is printed), so the job fails instead of checking less.
# Usage: evals-args.sh <event> <target branch> <base sha> <head sha>
#   event: pull_request or push; target branch: the base branch of the pull request or the pushed branch;
#   the shas matter only for a pull request.
set -u

fail() {
    echo "evals-args: $1" >&2
    exit 2
}

[ $# -eq 4 ] || fail "expected <event> <target branch> <base sha> <head sha>, got $# argument(s)"
event="$1"
target="$2"
base="$3"
head="$4"

case "$event" in
    pull_request)
        [ -n "$target" ] || fail "the base branch of the pull request is empty"
        for sha in "$base" "$head"; do
            case "$sha" in
                *[!0-9a-f]* | '') fail "'$sha' is not a commit sha" ;;
            esac
            [ "${#sha}" -eq 40 ] || fail "'$sha' is not a 40-character commit sha"
        done
        if [ "$target" = main ]; then
            echo "--enforce --diff $base...$head"
        else
            echo "--diff $base...$head"
        fi
        ;;
    push)
        if [ "$target" = main ]; then
            echo "--enforce"
        fi
        ;;
    *) fail "unknown event '$event' (pull_request or push)" ;;
esac

#!/bin/sh
# Waits for the CI run of one commit and ends with it: first until GitHub has registered the run of the workflow for
# exactly this commit and event (a run of an older commit never counts), then until that run is over. For a session
# that pushed and waits for the result: run it in the background, it ends when the result is known.
#
# Usage: ci-wait.sh [--event pull_request|push] [--workflow FILE] [--register-timeout S] [--interval S] <commit>
#   <commit>             the full SHA (40 hex): the head of the pull request, or the merge commit on main with
#                        --event push
#   --event              pull_request (default) or push
#   --workflow           the workflow file (default ci.yml)
#   --register-timeout   seconds to wait for the run to show up (default 300)
#   --interval           seconds between two looks (default 10)
# Prints one line `ci-wait: <conclusion> sha=<commit> run=<id> <url>` and a line `failed job: <name>` for every failed
# job of a run that is not green.
# Exit status: 0 the run succeeded, 1 it ended otherwise (failure, cancelled, ...), 2 wrong usage, 3 no run of this
# commit showed up in time (GitHub can lose a push event: check `gh run list --commit <commit>` and push again),
# 4 gh failed or its answer is not the run of this commit.
set -u

usage() {
    echo "usage: ci-wait.sh [--event pull_request|push] [--workflow FILE] [--register-timeout S] [--interval S] <commit>" >&2
    [ $# -gt 0 ] && echo "ci-wait: $1" >&2
    exit 2
}

event=pull_request
workflow=ci.yml
timeout=300
interval=10
sha=""
while [ $# -gt 0 ]; do
    case "$1" in
        --event|--workflow|--register-timeout|--interval)
            [ $# -ge 2 ] || usage "$1 needs a value"
            case "$1" in
                --event) event="$2" ;;
                --workflow) workflow="$2" ;;
                --register-timeout) timeout="$2" ;;
                --interval) interval="$2" ;;
            esac
            shift 2 ;;
        -*) usage "unknown option $1" ;;
        *)
            [ -z "$sha" ] || usage "one commit only"
            sha="$1"
            shift ;;
    esac
done
[ -n "$sha" ] || usage "no commit given"
case "$sha" in
    *[!0-9a-f]*) usage "the commit must be the full SHA, 40 hex characters: $sha" ;;
esac
[ "${#sha}" -eq 40 ] || usage "the commit must be the full SHA, 40 hex characters: $sha"
case "$event" in
    pull_request|push) ;;
    *) usage "--event is pull_request or push, not $event" ;;
esac
for number in "$timeout" "$interval"; do
    case "$number" in
        ''|*[!0-9]*) usage "--register-timeout and --interval take a whole number of seconds, not '$number'" ;;
    esac
done

# The newest run of the workflow for this commit and event, or nothing. The waited time is counted in intervals, not
# read from the clock, so the limit does not depend on how long gh takes.
waited=0
run=""
error=""
while :; do
    if answer="$(gh run list --workflow "$workflow" --commit "$sha" --event "$event" --limit 20 \
            --json databaseId,createdAt -q 'sort_by(.createdAt) | last | .databaseId // empty' 2>&1)"; then
        error=""
        run="$answer"
        [ -n "$run" ] && break
    else
        error="$answer"
    fi
    [ "$waited" -ge "$timeout" ] && break
    sleep "$interval"
    waited=$((waited + (interval > 0 ? interval : 1)))
done
if [ -z "$run" ]; then
    if [ -n "$error" ]; then
        echo "ci-wait: gh run list failed: $error" >&2
        exit 4
    fi
    echo "ci-wait: no CI run of $event for $sha within $timeout s: the event may be lost, check 'gh run list --commit $sha' and push again" >&2
    exit 3
fi
case "$run" in
    *[!0-9]*)
        echo "ci-wait: gh run list answered '$run', not a run id" >&2
        exit 4 ;;
esac

# `gh run watch` can end before the run does (a network error ends it like a red run), so its exit status is not the
# result: the result is read from the run, and the watch is repeated while the run is not over.
attempt=0
while :; do
    attempt=$((attempt + 1))
    gh run watch "$run" --exit-status --interval "$interval" > /dev/null 2>&1
    if ! state="$(gh run view "$run" --json status,conclusion,headSha,url,jobs -q \
            '"\(.status) \(if (.conclusion // "") == "" then "-" else .conclusion end) \(.headSha) \(.url)", (.jobs[] | select(.conclusion == "failure") | .name)' 2>&1)"; then
        echo "ci-wait: gh run view failed: $state" >&2
        exit 4
    fi
    first="$(printf '%s\n' "$state" | head -n 1)"
    status="${first%% *}"
    [ "$status" = completed ] && break
    if [ "$attempt" -ge 3 ]; then
        echo "ci-wait: run $run is still $status after $attempt watches: gh run watch ended early" >&2
        exit 4
    fi
    sleep "$interval"
done
rest="${first#* }"
conclusion="${rest%% *}"
rest="${rest#* }"
head="${rest%% *}"
url="${rest#* }"
if [ "$head" != "$sha" ]; then
    echo "ci-wait: run $run is of commit $head, not of $sha" >&2
    exit 4
fi
echo "ci-wait: $conclusion sha=$sha run=$run $url"
[ "$conclusion" = success ] && exit 0
printf '%s\n' "$state" | tail -n +2 | while IFS= read -r job; do
    [ -n "$job" ] && echo "failed job: $job"
done
exit 1

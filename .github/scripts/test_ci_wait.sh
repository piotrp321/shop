#!/bin/sh
# Tests of ci-wait.sh with a fake `gh` and a fake `sleep` on PATH: the answers of `gh run list` and `gh run view` come
# from numbered files (the last one repeats), so a run can show up late, never, or end red. Those files hold the text
# the query of the script would print, so the cases at the end give the fake the JSON of GitHub instead and run the
# query of the script on it with jq (`gh -q` is jq): they test the queries themselves.
set -u

script="$(cd "$(dirname "$0")" && pwd)/ci-wait.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0
sha=0123456789abcdef0123456789abcdef01234567

mkdir -p "$tmp/bin"
cat > "$tmp/bin/gh" <<'GH'
#!/bin/sh
# Fake gh: logs the call; `run list` and `run view` print the next file of their sequence, `run watch` exits with the
# code in watch.rc. A file `<kind>.fail` makes that kind of call fail.
echo "$*" >> "$CASE/calls"
kind="$1-$2"
if [ -f "$CASE/$kind.fail" ]; then echo "HTTP 500: fake failure" >&2; exit 1; fi
if [ -f "$CASE/$kind.json" ]; then
    # the JSON as GitHub answers it: apply the query the script passed with -q
    filter=""
    prev=""
    for arg in "$@"; do
        [ "$prev" = "-q" ] && filter="$arg"
        prev="$arg"
    done
    exec jq -r "$filter" "$CASE/$kind.json"
fi
case "$kind" in
    run-list|run-view)
        n=$(cat "$CASE/$kind.n" 2>/dev/null || echo 1)
        file="$CASE/$kind.$n"
        [ -f "$file" ] || file="$CASE/$kind.last"
        echo $((n + 1)) > "$CASE/$kind.n"
        [ -f "$file" ] && cat "$file"
        exit 0 ;;
    run-watch) exit "$(cat "$CASE/watch.rc" 2>/dev/null || echo 0)" ;;
esac
exit 0
GH
printf '#!/bin/sh\necho "$1" >> "$CASE/sleeps"\n' > "$tmp/bin/sleep"
chmod +x "$tmp/bin/gh" "$tmp/bin/sleep"

# new_case <name>: an empty case directory; the fake gh reads its answers from there.
new_case() {
    CASE="$tmp/$1"
    mkdir -p "$CASE"
    export CASE
}

# run_script <args...>: runs ci-wait.sh with the fakes first on PATH; sets $code, stdout in out, stderr in err.
run_script() {
    PATH="$tmp/bin:$PATH" sh "$script" "$@" > "$CASE/out" 2> "$CASE/err"
    code=$?
}

# expect <name> <expected code> <text expected in stdout+stderr>
expect() {
    if [ "$code" = "$2" ] && cat "$CASE/out" "$CASE/err" | grep -q -- "$3"; then
        echo "ok   $1"
    else
        echo "FAIL $1: code $code (expected $2), expected text '$3'; output: $(cat "$CASE/out" "$CASE/err" | tr '\n' ' ')"
        failures=$((failures + 1))
    fi
}

# expect_calls <name> <pattern>: a call of the fake gh matches.
expect_calls() {
    if grep -q -- "$2" "$CASE/calls" 2>/dev/null; then
        echo "ok   $1"
    else
        echo "FAIL $1: no gh call matching '$2': $(cat "$CASE/calls" 2>/dev/null | tr '\n' '|')"
        failures=$((failures + 1))
    fi
}

new_case green
echo 111 > "$CASE/run-list.last"
echo "completed success $sha https://example.test/runs/111" > "$CASE/run-view.last"
run_script "$sha"
expect "a green run of the commit" 0 "success sha=$sha run=111 https://example.test/runs/111"
expect_calls "the run is looked up by the commit and the event" "run list .*--commit $sha .*--event pull_request"
expect_calls "the run that was found is the one watched" "run watch 111 "

new_case late
: > "$CASE/run-list.1"
: > "$CASE/run-list.2"
echo 222 > "$CASE/run-list.last"
echo "completed success $sha https://example.test/runs/222" > "$CASE/run-view.last"
run_script --interval 10 "$sha"
expect "a run registered after two empty answers" 0 "success sha=$sha run=222"
if [ "$(wc -l < "$CASE/sleeps" | tr -d ' ')" = 2 ]; then echo "ok   it waited twice before the run showed up"; else
    echo "FAIL it waited twice before the run showed up: $(cat "$CASE/sleeps" | tr '\n' ' ')"; failures=$((failures + 1)); fi

new_case never
: > "$CASE/run-list.last"
run_script --register-timeout 30 --interval 10 "$sha"
expect "no run within the time limit" 3 "no CI run .* for $sha within 30 s"
if grep -q "run watch" "$CASE/calls"; then echo "FAIL nothing is watched without a run"; failures=$((failures + 1)); else
    echo "ok   nothing is watched without a run"; fi

new_case red
echo 333 > "$CASE/run-list.last"
echo 1 > "$CASE/watch.rc"
printf 'completed failure %s https://example.test/runs/333\ne2e shard 2 (failure)\nchecks part apps (failure)\n' "$sha" > "$CASE/run-view.last"
run_script "$sha"
expect "a red run" 1 "failure sha=$sha run=333"
expect "a red run names its failed jobs" 1 "failed job: e2e shard 2 (failure)"

new_case timed-out
echo 334 > "$CASE/run-list.last"
echo 1 > "$CASE/watch.rc"
printf 'completed failure %s https://example.test/runs/334\ne2e shard 2 (timed_out)\nchecks part apps (failure)\nchecks (failure)\n' "$sha" > "$CASE/run-view.last"
run_script "$sha"
expect "a job that timed out is listed with its conclusion" 1 "failed job: e2e shard 2 (timed_out)"
expect "a failed job is listed with its conclusion" 1 "failed job: checks part apps (failure)"
if grep -q 'run view 334 .*timed_out' "$CASE/calls" && grep -q 'run view 334 .*startup_failure' "$CASE/calls" \
        && grep -q 'run view 334 .*cancelled' "$CASE/calls"; then
    echo "ok   the query asks for every conclusion that is not a success"; else
    echo "FAIL the query asks for every conclusion that is not a success: $(grep 'run view' "$CASE/calls" | head -1)"
    failures=$((failures + 1)); fi

new_case cancelled
echo 444 > "$CASE/run-list.last"
echo 1 > "$CASE/watch.rc"
echo "completed cancelled $sha https://example.test/runs/444" > "$CASE/run-view.last"
run_script "$sha"
expect "a cancelled run is no success" 1 "cancelled sha=$sha run=444"

new_case other-commit
echo 555 > "$CASE/run-list.last"
echo "completed success ffffffffffffffffffffffffffffffffffffffff https://example.test/runs/555" > "$CASE/run-view.last"
run_script "$sha"
expect "a run of another commit never confirms this one" 4 "run 555 is of commit ffffffffffffffffffffffffffffffffffffffff"

new_case push
echo 666 > "$CASE/run-list.last"
echo "completed success $sha https://example.test/runs/666" > "$CASE/run-view.last"
run_script --event push "$sha"
expect "a push run (the merge commit on main)" 0 "success sha=$sha run=666"
expect_calls "the push run is looked up by its event" "run list .*--event push"

new_case list-error
: > "$CASE/run-list.fail"
run_script --register-timeout 20 --interval 10 "$sha"
expect "gh failing while the run is looked up" 4 "gh run list failed"

new_case view-error
echo 777 > "$CASE/run-list.last"
: > "$CASE/run-view.fail"
run_script "$sha"
expect "gh failing when the result is read" 4 "gh run view failed"

new_case unfinished
echo 888 > "$CASE/run-list.last"
echo "in_progress - $sha https://example.test/runs/888" > "$CASE/run-view.last"
run_script "$sha"
expect "a watch that ended before the run did" 4 "run 888 is still in_progress"

new_case watch-again
echo 999 > "$CASE/run-list.last"
echo "in_progress - $sha https://example.test/runs/999" > "$CASE/run-view.1"
echo "completed success $sha https://example.test/runs/999" > "$CASE/run-view.last"
run_script "$sha"
expect "a watch that ended early is repeated until the run is over" 0 "success sha=$sha run=999"
if [ "$(grep -c 'run watch 999 ' "$CASE/calls")" = 2 ]; then echo "ok   the run was watched twice"; else
    echo "FAIL the run was watched twice: $(cat "$CASE/calls" | tr '\n' '|')"; failures=$((failures + 1)); fi

new_case never-push
: > "$CASE/run-list.last"
run_script --event push --register-timeout 10 --interval 10 "$sha"
expect "no push run: the commit cannot be pushed again" 3 "report it"

new_case never-pr
: > "$CASE/run-list.last"
run_script --register-timeout 10 --interval 10 "$sha"
expect "no pull request run: a conflict with the base is a cause too" 3 "conflict"

new_case no-sha
run_script
expect "no commit given" 2 "usage"

new_case short-sha
run_script 0123abc
expect "an abbreviated commit" 2 "40 hex"

new_case bad-event
run_script --event schedule "$sha"
expect "an unknown event" 2 "pull_request or push"

new_case bad-number
run_script --interval x "$sha"
expect "an interval that is no number" 2 "whole number"

# The queries of the script on the JSON of GitHub. jq is on the CI runner; without it the cases are skipped on a
# developer machine and fail in CI, so the queries never go untested there.
if command -v jq > /dev/null 2>&1; then
    # expect_line / expect_no_line <name> <text>: a line of the output of the last run of the script
    expect_line() {
        if grep -q -x -F -- "$2" "$CASE/out"; then echo "ok   $1"; else
            echo "FAIL $1: no line '$2' in: $(tr '\n' '|' < "$CASE/out")"; failures=$((failures + 1)); fi
    }
    expect_no_line() {
        if grep -q -F -- "$2" "$CASE/out"; then
            echo "FAIL $1: unexpected '$2' in: $(tr '\n' '|' < "$CASE/out")"; failures=$((failures + 1))
        else echo "ok   $1"; fi
    }

    new_case jq-red
    cat > "$CASE/run-list.json" <<'JSON'
[{"databaseId": 10, "createdAt": "2026-01-01T10:00:00Z"},
 {"databaseId": 20, "createdAt": "2026-01-01T11:00:00Z"},
 {"databaseId": 15, "createdAt": "2026-01-01T10:30:00Z"}]
JSON
    cat > "$CASE/run-view.json" <<JSON
{"status": "completed", "conclusion": "failure", "headSha": "$sha", "url": "https://example.test/runs/20",
 "jobs": [{"name": "scope", "conclusion": "success"}, {"name": "e2e shard 1", "conclusion": "timed_out"},
          {"name": "e2e shard 2", "conclusion": "failure"}, {"name": "e2e shard 3", "conclusion": "cancelled"},
          {"name": "docs", "conclusion": "skipped"}, {"name": "setup", "conclusion": "startup_failure"},
          {"name": "gate", "conclusion": "action_required"}, {"name": "note", "conclusion": "neutral"},
          {"name": "late", "conclusion": null}, {"name": "checks", "conclusion": "failure"}]}
JSON
    echo 1 > "$CASE/watch.rc"
    run_script "$sha"
    expect "the queries on the JSON of a red run" 1 "failure sha=$sha run=20 https://example.test/runs/20"
    expect_calls "the newest of the runs of the commit is the one watched" "run watch 20 "
    expect_line "a job that timed out is listed" "failed job: e2e shard 1 (timed_out)"
    expect_line "a failed job is listed" "failed job: e2e shard 2 (failure)"
    expect_line "a cancelled job is listed" "failed job: e2e shard 3 (cancelled)"
    expect_line "a job that did not start is listed" "failed job: setup (startup_failure)"
    expect_line "a job that waits for an action is listed" "failed job: gate (action_required)"
    expect_line "the aggregate job is listed" "failed job: checks (failure)"
    for job in scope docs note late; do
        expect_no_line "the job '$job' did not end red and is not listed" "failed job: $job"
    done

    new_case jq-green
    echo '[{"databaseId": 30, "createdAt": "2026-01-01T10:00:00Z"}]' > "$CASE/run-list.json"
    cat > "$CASE/run-view.json" <<JSON
{"status": "completed", "conclusion": "success", "headSha": "$sha", "url": "https://example.test/runs/30",
 "jobs": [{"name": "checks", "conclusion": "success"}, {"name": "docs", "conclusion": "skipped"}]}
JSON
    run_script "$sha"
    expect "the queries on the JSON of a green run" 0 "success sha=$sha run=30 https://example.test/runs/30"
    expect_no_line "a green run lists no job" "failed job"

    new_case jq-none
    echo '[]' > "$CASE/run-list.json"
    run_script --register-timeout 10 --interval 10 "$sha"
    expect "the query on an empty list of runs" 3 "no CI run"

    for conclusion in null '""'; do
        new_case "jq-progress-$conclusion"
        echo '[{"databaseId": 40, "createdAt": "2026-01-01T10:00:00Z"}]' > "$CASE/run-list.json"
        cat > "$CASE/run-view.json" <<JSON
{"status": "in_progress", "conclusion": $conclusion, "headSha": "$sha", "url": "https://example.test/runs/40",
 "jobs": [{"name": "checks", "conclusion": null}]}
JSON
        run_script "$sha"
        expect "a run in progress with the conclusion $conclusion is not a result" 4 "run 40 is still in_progress"
    done
elif [ -n "${CI:-}" ]; then
    echo "FAIL jq is missing in CI: the queries of ci-wait.sh are not tested"
    failures=$((failures + 1))
else
    echo "skip the queries on the JSON of GitHub: jq is not installed"
fi

if [ "$failures" -gt 0 ]; then
    echo "test_ci_wait: $failures failure(s)" >&2
    exit 1
fi
echo "test_ci_wait: all passed"

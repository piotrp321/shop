#!/bin/sh
# Tests of ci-wait.sh with a fake `gh` and a fake `sleep` on PATH: the answers of `gh run list` and `gh run view` come
# from numbered files (the last one repeats), so a run can show up late, never, or end red.
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
printf 'completed failure %s https://example.test/runs/333\ne2e shard 2\nchecks part apps\n' "$sha" > "$CASE/run-view.last"
run_script "$sha"
expect "a red run" 1 "failure sha=$sha run=333"
expect "a red run names its failed jobs" 1 "failed job: e2e shard 2"

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

if [ "$failures" -gt 0 ]; then
    echo "test_ci_wait: $failures failure(s)" >&2
    exit 1
fi
echo "test_ci_wait: all passed"

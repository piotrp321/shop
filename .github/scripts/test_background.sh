#!/bin/sh
# Tests of background.sh: each case uses its own BACKGROUND_DIR.
set -u

script="$(cd "$(dirname "$0")" && pwd)/background.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0

# check <name> <expected status> <expected output pattern> <actual status> <actual output>
check() {
    if [ "$4" -eq "$2" ] && { [ -z "$3" ] || printf '%s' "$5" | grep -q -- "$3"; }; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected status $2 and output matching '$3', got status $4 and output:"
        printf '%s\n' "$5" | sed 's/^/     /'
        failures=$((failures + 1))
    fi
}

# bg <case> <args...>: runs background.sh with the case's directory; sets out and status.
bg() {
    dir="$tmp/$1"
    shift
    out="$(BACKGROUND_DIR="$dir" BACKGROUND_TIMEOUT="${TIMEOUT:-10}" sh "$script" "$@" 2>&1)"
    status=$?
}

bg success start job sh -c 'echo hello; echo oops >&2'
check "start returns at once" 0 '' "$status" "$out"
bg success wait job
check "wait prints stdout and stderr and succeeds" 0 'hello' "$status" "$out"
check "wait output has stderr" 0 'oops' "$status" "$out"

bg failure start job sh -c 'echo broken; exit 3'
bg failure wait job
check "wait exits with the command's status" 3 'broken' "$status" "$out"
check "wait names the failed command" 3 "'job' failed with status 3" "$status" "$out"

started="$(date +%s)"
bg slow start job sh -c 'sleep 2; echo late'
if [ $(($(date +%s) - started)) -lt 2 ]; then echo "ok   start does not wait for the command"; else
    echo "FAIL start waited for the command"; failures=$((failures + 1)); fi
bg slow wait job
finished="$(date +%s)"
check "wait blocks until the command ends" 0 'late' "$status" "$out"
if [ $((finished - started)) -ge 2 ]; then echo "ok   wait took the command's time"; else
    echo "FAIL wait returned after $((finished - started)) s"; failures=$((failures + 1)); fi

bg timeout start job sleep 30
TIMEOUT=1 bg timeout wait job
check "wait gives up after the timeout" 124 'did not finish within 1 s' "$status" "$out"

bg missing start job no-such-command-xyz
bg missing wait job
check "a missing command fails in wait" 127 "'job' failed with status 127" "$status" "$out"

bg never wait job
check "wait without start fails" 2 "'job' was not started" "$status" "$out"

bg twice start job true
bg twice start job true
check "starting a name twice fails" 2 "'job' was already started" "$status" "$out"

bg names start '../x' true
check "a name with a slash is rejected" 2 'invalid name' "$status" "$out"

bg usage start job
check "start without a command is a usage error" 2 'usage:' "$status" "$out"

bg usage stop job
check "an unknown action is a usage error" 2 'usage:' "$status" "$out"

[ "$failures" -eq 0 ] || { echo "$failures failure(s)"; exit 1; }
echo "all passed"

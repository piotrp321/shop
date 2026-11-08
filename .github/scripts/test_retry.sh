#!/bin/sh
# Tests of retry.sh: the delay is 0 everywhere, so the cases run fast.
set -u

script="$(cd "$(dirname "$0")" && pwd)/retry.sh"
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

# check_runs <name> <expected number of runs> <counter file>
check_runs() {
    runs=0
    [ ! -f "$3" ] || runs="$(wc -l < "$3" | tr -d ' ')"
    if [ "$runs" -eq "$2" ]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected $2 run(s), got $runs"
        failures=$((failures + 1))
    fi
}

# retry <args...>: runs retry.sh; sets out and status.
retry() {
    out="$(sh "$script" "$@" 2>&1)"
    status=$?
}

# A command that records every run in $1 and exits with status $3 until it has run $2 times, then succeeds.
flaky="$tmp/flaky.sh"
cat > "$flaky" <<'SCRIPT'
#!/bin/sh
echo run >> "$1"
runs="$(wc -l < "$1" | tr -d ' ')"
[ "$runs" -ge "$2" ] && exit 0
exit "$3"
SCRIPT

c="$tmp/first"
retry 3 0 sh "$flaky" "$c" 1 5
check "success on the first try" 0 '' "$status" "$out"
check_runs "success on the first try runs the command once" 1 "$c"

c="$tmp/second"
retry 3 0 sh "$flaky" "$c" 2 5
check "success on the second try" 0 "attempt 1 of 3 failed with status 5" "$status" "$out"
check_runs "success on the second try runs the command twice" 2 "$c"

c="$tmp/exhausted"
retry 3 0 sh "$flaky" "$c" 99 7
check "exhausted attempts exit with the last status" 7 "attempt 3 of 3 failed with status 7" "$status" "$out"
check "exhausted attempts report the earlier tries" 7 "attempt 1 of 3 failed with status 7" "$status" "$out"
check_runs "exhausted attempts run the command attempts times" 3 "$c"

c="$tmp/single"
retry 1 0 sh "$flaky" "$c" 99 4
check "a single attempt does not retry" 4 "attempt 1 of 1 failed with status 4" "$status" "$out"
check_runs "a single attempt runs the command once" 1 "$c"

c="$tmp/last-status"
retry 2 0 sh -c 'echo run >> "$1"; if [ "$(wc -l < "$1" | tr -d " ")" -eq 1 ]; then exit 3; else exit 9; fi' sh "$c"
check "the status of the last attempt wins" 9 "attempt 2 of 2 failed with status 9" "$status" "$out"

retry 2 0 no-such-command-xyz
check "a missing command fails with 127 after the attempts" 127 "attempt 2 of 2 failed with status 127" "$status" "$out"

c="$tmp/args"
retry 2 0 sh -c 'printf "%s|%s|%s\n" "$1" "$2" "$3" > "$4"' sh 'a b' '' '  c  d ' "$c"
check "arguments with spaces reach the command" 0 '' "$status" "$out"
if [ "$(cat "$c")" = "a b||  c  d " ]; then echo "ok   arguments are passed unchanged"; else
    echo "FAIL arguments changed: $(cat "$c")"; failures=$((failures + 1)); fi

# Usage errors: status 2 and the command is never run.
c="$tmp/usage"
retry
check "no arguments is a usage error" 2 'usage:' "$status" "$out"
retry 3 0
check "a missing command is a usage error" 2 'usage:' "$status" "$out"
retry 3
check "a missing delay and command is a usage error" 2 'usage:' "$status" "$out"
for bad in 0 x -1 1.5 ''; do
    rm -f "$c"
    retry "$bad" 0 sh "$flaky" "$c" 1 5
    check "attempts '$bad' is a usage error" 2 'usage:' "$status" "$out"
    check_runs "attempts '$bad' does not run the command" 0 "$c"
done
for bad in x -1 1.5 ''; do
    rm -f "$c"
    retry 3 "$bad" sh "$flaky" "$c" 1 5
    check "delay '$bad' is a usage error" 2 'usage:' "$status" "$out"
    check_runs "delay '$bad' does not run the command" 0 "$c"
done

[ "$failures" -eq 0 ] || { echo "$failures failure(s)"; exit 1; }
echo "all passed"

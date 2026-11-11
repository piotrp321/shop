#!/bin/sh
# Tests of script-tests.sh on a temporary tree of fake suites.
set -u

script="$(cd "$(dirname "$0")" && pwd)/script-tests.sh"
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

# suites <root>: runs script-tests.sh on <root>; sets out and status.
suites() {
    out="$(sh "$script" "$1" 2>&1)"
    status=$?
}

# py_suite <dir> <pass|fail>: a unittest suite with one test that records its run in $tmp/ran.
py_suite() {
    mkdir -p "$1"
    if [ "$2" = pass ]; then assertion='self.assertTrue(True)'; else assertion='self.fail("broken")'; fi
    cat > "$1/test_fake.py" <<EOF
import unittest
class FakeTest(unittest.TestCase):
    def test_it(self):
        with open('$tmp/ran', 'a') as f:
            f.write('$1\n')
        $assertion
EOF
}

# sh_suite <file> <exit status>
sh_suite() {
    mkdir -p "$(dirname "$1")"
    printf 'echo "%s" >> "%s/ran"\nexit %s\n' "$1" "$tmp" "$2" > "$1"
}

ran() { [ -f "$tmp/ran" ] && grep -c . "$tmp/ran" || echo 0; }

# Every suite passes: each kind of location is found and run.
root="$tmp/ok"
sh_suite "$root/.github/scripts/test_a.sh" 0
py_suite "$root/.ao" pass
py_suite "$root/.claude/skills/one" pass
py_suite "$root/.claude/skills/two" pass
py_suite "$root/tools/rulesets" pass
mkdir -p "$root/.claude/skills/no-tests"
rm -f "$tmp/ran"
suites "$root"
check "all suites pass" 0 "5 suite(s) passed" "$status" "$out"
check "every suite ran" 0 "" "$(test "$(ran)" -eq 5; echo $?)" "ran: $(ran)"

# A failing suite fails the run, but the suites after it still run and the failures are named.
root="$tmp/fail"
sh_suite "$root/.github/scripts/test_a.sh" 1
py_suite "$root/.claude/skills/broken" fail
py_suite "$root/.claude/skills/zz-last" pass
rm -f "$tmp/ran"
suites "$root"
check "a failing python suite fails the run" 1 "FAILED: .*\.claude/skills/broken" "$status" "$out"
check "a failing shell suite is named" 1 "\.github/scripts/test_a\.sh" "$status" "$out"
check "the suites after a failure still run" 0 "" "$(test "$(ran)" -eq 3; echo $?)" "ran: $(ran)"

# No suite at all is an error, not a green run.
mkdir -p "$tmp/empty"
suites "$tmp/empty"
check "no suites is an error" 2 "no script test suites" "$status" "$out"

# A root that does not exist is an error.
suites "$tmp/missing"
check "a missing root is an error" 2 "missing" "$status" "$out"

[ "$failures" -eq 0 ] && echo "script-tests.sh: all tests passed" || { echo "script-tests.sh: $failures failure(s)"; exit 1; }

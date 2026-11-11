#!/bin/sh
# Runs every test suite of the repository scripts, found by location, so a new skill or script needs no change of the
# CI workflow:
#   .github/scripts/test_*.sh               one shell suite per file
#   .ao, .claude/skills/*, tools/*          a Python unittest suite in every directory with test_*.py files
# infra/k8s/tests are not here: `make -C infra/k8s check` runs them.
# Every suite runs even after a failure; the failing ones are listed at the end.
# Exit status: 0 all passed, 1 a suite failed, 2 no suites found or no such root.
# Usage: script-tests.sh [root]   (default: the current directory)
set -u

root="${1:-.}"
[ -d "$root" ] || { echo "script-tests: root '$root' is missing" >&2; exit 2; }
cd "$root" || exit 2

passed=0
failed=""

# run <label> <command...>
run() {
    label="$1"
    shift
    echo "::group::$label"
    if "$@"; then passed=$((passed + 1)); else failed="$failed $label"; fi
    echo "::endgroup::"
}

for suite in .github/scripts/test_*.sh; do
    [ -f "$suite" ] && run "$suite" sh "$suite"
done
for dir in .ao .claude/skills/* tools/*; do
    set -- "$dir"/test_*.py
    [ -f "$1" ] && run "$dir" python3 -m unittest discover -s "$dir" -p 'test_*.py'
done

if [ -n "$failed" ]; then
    echo "script-tests: $passed suite(s) passed, FAILED:$failed" >&2
    exit 1
fi
if [ "$passed" -eq 0 ]; then
    echo "script-tests: no script test suites under '$root'" >&2
    exit 2
fi
echo "script-tests: $passed suite(s) passed"

#!/bin/sh
# Tests of junit-slowest.py on small JUnit files written here.
set -u

script="$(cd "$(dirname "$0")" && pwd)/junit-slowest.py"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0

# check <name> <expected status> <expected output pattern> <actual status> <actual output>
check() {
    if [ "$4" -eq "$2" ] && { [ -z "$3" ] || printf '%s' "$5" | grep -qF -- "$3"; }; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected status $2 and output matching '$3', got status $4 and output:"
        printf '%s\n' "$5" | sed 's/^/     /'
        failures=$((failures + 1))
    fi
}

# check_absent <name> <pattern> <output>
check_absent() {
    if printf '%s' "$3" | grep -qF -- "$2"; then
        echo "FAIL $1: the output matches '$2':"
        printf '%s\n' "$3" | sed 's/^/     /'
        failures=$((failures + 1))
    else
        echo "ok   $1"
    fi
}

# slowest <args...>: runs junit-slowest.py; sets out and status.
slowest() {
    out="$(python3 "$script" "$@" 2>&1)"
    status=$?
}

# The nested suites of PHPUnit: the suite of the run, of the test suite, of the class, of a data provider.
cat > "$tmp/junit.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="integration" tests="4" time="13.5">
    <testsuite name="App\Tests\Integration\DemoData\VerifyStepTest" tests="2" time="10.25">
      <testcase name="testItPasses" class="App\Tests\Integration\DemoData\VerifyStepTest" time="7.5"/>
      <testcase name="testItLists" class="App\Tests\Integration\DemoData\VerifyStepTest" time="2.75"/>
    </testsuite>
    <testsuite name="App\Tests\Functional\DemoData\VerifyStepTest" tests="1" time="0.5">
      <testcase name="testOther" class="App\Tests\Functional\DemoData\VerifyStepTest" time="0.5"/>
    </testsuite>
    <testsuite name="App\Tests\Integration\Cart\CartTest" tests="2" time="3.25">
      <testsuite name="App\Tests\Integration\Cart\CartTest::testAdds" tests="2" time="3.25">
        <testcase name="testAdds with data set #0" class="App\Tests\Integration\Cart\CartTest" time="3.0"/>
        <testcase name="testAdds with data set #1" class="App\Tests\Integration\Cart\CartTest" time="0.25"/>
      </testsuite>
    </testsuite>
  </testsuite>
</testsuites>
XML

slowest "$tmp/junit.xml" 2
check "a summary of the tests and their time" 0 "5 tests, 14.0 s" "$status" "$out"
check "the slowest test first, with its class without App\\Tests" 0 "| 7.5 s | Integration\\DemoData\\VerifyStepTest::testItPasses |" "$status" "$out"
check "a test of a data provider" 0 "| 3.0 s | Integration\\Cart\\CartTest::testAdds with data set #0 |" "$status" "$out"
check_absent "only the given number of tests" "testItLists" "$out"
check "the slowest classes" 0 "| 10.2 s | 2 | Integration\\DemoData\\VerifyStepTest |" "$status" "$out"

slowest "$tmp/junit.xml"
check "fifteen tests by default, all five here" 0 "Integration\\Cart\\CartTest::testAdds with data set #1" "$status" "$out"
check "two classes of the same short name are two rows" 0 "| 0.5 s | 1 | Functional\\DemoData\\VerifyStepTest |" "$status" "$out"

slowest "$tmp/missing.xml"
check "a missing file" 2 "junit-slowest: cannot read" "$status" "$out"

printf '<testsuites><testsuite' > "$tmp/broken.xml"
slowest "$tmp/broken.xml"
check "a broken file" 2 "junit-slowest: cannot read" "$status" "$out"

printf '<testsuites/>' > "$tmp/empty.xml"
slowest "$tmp/empty.xml"
check "a file without tests" 0 "0 tests, 0.0 s" "$status" "$out"

for count in 0 -1 x; do
    slowest "$tmp/junit.xml" "$count"
    check "a count of '$count' is a usage error" 2 "usage" "$status" "$out"
done

slowest
check "no file is a usage error" 2 "usage" "$status" "$out"

[ "$failures" -eq 0 ] || { echo "$failures test(s) failed"; exit 1; }

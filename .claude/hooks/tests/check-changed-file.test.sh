#!/usr/bin/env bash
# Tests for ../check-changed-file.sh. Pure bash, feeds the script the hook JSON on stdin.
# Fixtures that must be analysed in a container are copied under apps/<app>/ for the duration of the
# test (the container only sees that directory) and removed by the EXIT trap.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
HOOK="$ROOT/.claude/hooks/check-changed-file.sh"
FIXTURES="$HERE/fixtures"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/check-hook-test.XXXXXX")"
STATE_DIR="$TMP/state"
STORE_STOPPED=0
FIXTURE_DIRS="$ROOT/apps/api/src/__hook_fixture__ $ROOT/apps/store/src/__hook_fixture__ $ROOT/apps/admin/src/__hook_fixture__"

cleanup() {
  for d in $FIXTURE_DIRS; do rm -rf "$d"; done
  if [ "$STORE_STOPPED" = 1 ]; then (cd "$ROOT" && docker compose start store >/dev/null 2>&1); fi
  rm -rf "$TMP"
}
trap cleanup EXIT

PASS=0
FAIL=0
SKIPPED=0
OUT=""
ERR=""
RC=0

pass() { PASS=$((PASS + 1)); echo "PASS: $1"; }
fail() { FAIL=$((FAIL + 1)); echo "FAIL: $1"; }
skip() { SKIPPED=$((SKIPPED + 1)); echo "SKIP: $1"; }

# run_hook <json> [VAR=value ...]: runs the hook, sets OUT, ERR and RC.
run_hook() {
  local json="$1"
  shift
  OUT="$(printf '%s' "$json" | env CLAUDE_PROJECT_DIR="$ROOT" HOOK_STATE_DIR="$STATE_DIR" "$@" /bin/bash "$HOOK" 2>"$TMP/stderr")"
  RC=$?
  ERR="$(cat "$TMP/stderr")"
}

payload() { # payload <file_path> [session_id]
  if [ -n "${2:-}" ]; then
    printf '{"session_id":"%s","tool_name":"Edit","tool_input":{"file_path":"%s"}}' "$2" "$1"
  else
    printf '{"tool_name":"Edit","tool_input":{"file_path":"%s"}}' "$1"
  fi
}

show() { echo "  rc=$RC"; echo "$ERR" | head -n 8 | sed 's/^/  stderr: /'; }

# expect <name> <rc> [stderr substring]: empty output when rc is 0 and no substring is given.
expect() {
  local name="$1" rc="$2" needle="${3:-}"
  show
  if [ "$RC" != "$rc" ]; then fail "$name (expected rc=$rc)"; return; fi
  if [ -n "$needle" ] && ! printf '%s' "$ERR" | grep -qF -- "$needle"; then
    fail "$name (stderr lacks: $needle)"; return
  fi
  if [ "$rc" = 0 ] && [ -z "$needle" ] && { [ -n "$OUT" ] || [ -n "$ERR" ]; }; then
    fail "$name (expected empty output)"; return
  fi
  pass "$name"
}

# place <app> <fixture> <name>: copies a fixture under apps/<app>/src/__hook_fixture__/, echoes its path.
place() {
  local dir="$ROOT/apps/$1/src/__hook_fixture__"
  mkdir -p "$dir" && cp "$FIXTURES/$2" "$dir/$3" && echo "$dir/$3"
}

app_runnable() { # app_runnable <app>: succeeds when the app dir exists and its container is running
  [ -d "$ROOT/apps/$1" ] || { skip "$2: apps/$1 does not exist yet"; return 1; }
  (cd "$ROOT" && docker compose ps --status running --services 2>/dev/null | grep -qx "$1") \
    || { skip "$2: container $1 is not running"; return 1; }
}

mkdir -p "$STATE_DIR"

echo "== api: PHPStan =="
f="$(place api bad-types.php BadTypes.php)"
run_hook "$(payload "$f" s-types)"
expect "php type error -> rc 2 with PHPStan message" 2 "should return int but returns string"

echo "== api: PHP CS Fixer (and file untouched) =="
f="$(place api bad-format.php BadFormat.php)"
before="$(cksum <"$f")"
run_hook "$(payload "$f" s-format)"
expect "php bad formatting -> rc 2 with CS Fixer diff" 2 "begin diff"
after="$(cksum <"$f")"
if [ "$before" = "$after" ]; then pass "bad-formatted file was not modified"; else fail "hook modified the file"; fi

echo "== api: clean file =="
f="$(place api good.php Good.php)"
run_hook "$(payload "$f" s-good)"
expect "valid php -> rc 0, empty output" 0

echo "== store/admin: ESLint =="
if app_runnable store "store .tsx ESLint"; then
  f="$(place store bad.tsx Bad.tsx)"
  run_hook "$(payload "$f" s-tsx)"
  expect "tsx with bg-[#ff0000] -> rc 2 with ESLint error" 2 "error"
fi
if app_runnable admin "admin .vue ESLint"; then
  f="$(place admin bad.vue Bad.vue)"
  run_hook "$(payload "$f" s-vue)"
  expect "vue with global fetch -> rc 2 with ESLint error" 2 "error"
fi

echo "== non-app files and other extensions =="
run_hook "$(payload "$ROOT/docs/vision.md" s-doc)"
expect "docs/vision.md -> rc 0, empty" 0
run_hook "$(payload "$ROOT/apps/api/CLAUDE.md" s-doc2)"
expect "apps/api/CLAUDE.md -> rc 0, empty" 0
run_hook "$(payload "$ROOT/apps/api/composer.json" s-json)"
expect "apps/api/composer.json (other extension) -> rc 0, empty" 0
run_hook "$(payload "/etc/hosts" s-out)"
expect "file outside the project -> rc 0, empty" 0
run_hook "$(payload "$ROOT/apps/api/../../docs/x.php" s-dots)"
expect "path with .. segment -> rc 0, empty" 0

echo "== degraded environment =="
run_hook "$(payload "$ROOT/apps/api/src/Kernel.php" s-nojq)" PATH=/var/empty
expect "no jq on PATH -> rc 0 with one-line notice" 0 "jq"
lines="$(printf '%s' "$ERR" | grep -c '')"
if [ "$lines" = 1 ]; then pass "no-jq notice is a single line"; else fail "no-jq notice has $lines lines"; fi

run_hook "$(payload "$ROOT/apps/api/src/Kernel.php" s-down)" COMPOSE_PROJECT_NAME=hook-test-no-such-project
expect "api container not running -> rc 0 with one-line notice" 0 "not running"
lines="$(printf '%s' "$ERR" | grep -c '')"
if [ "$lines" = 1 ]; then pass "container notice is a single line"; else fail "container notice has $lines lines"; fi

run_hook 'this is not json'
expect "malformed JSON -> rc 0 with notice" 0 "invalid"
run_hook '{"session_id":"s-nofile","tool_name":"Edit","tool_input":{}}'
expect "missing file_path -> rc 0, empty" 0
run_hook ''
expect "empty stdin -> rc 0, empty" 0

if (cd "$ROOT" && docker compose config --services 2>/dev/null | grep -qx store) && [ -d "$ROOT/apps/store" ]; then
  f="$(place store bad.tsx Bad.tsx)"
  if (cd "$ROOT" && docker compose ps --status running --services | grep -qx store); then
    STORE_STOPPED=1
    (cd "$ROOT" && docker compose stop store >/dev/null 2>&1)
    run_hook "$(payload "$f" s-stopped)"
    expect "stopped store container -> rc 0 with notice" 0 "not running"
    (cd "$ROOT" && docker compose start store >/dev/null 2>&1) && STORE_STOPPED=0
  else
    skip "stopped store container: store container is not running to begin with"
  fi
else
  skip "stopped store container: no store service or apps/store yet"
fi

echo "== session state file =="
rm -rf "$STATE_DIR" && mkdir -p "$STATE_DIR"
f1="$ROOT/apps/api/src/Kernel.php"
f2="$ROOT/apps/api/src/Shared/UI/Http/HealthController.php"
run_hook "$(payload "$f1" s-state)"
run_hook "$(payload "$f2" s-state)"
if [ "$(cat "$STATE_DIR/changed-apps-s-state" 2>/dev/null)" = "api" ]; then
  pass "two api files -> a single 'api' entry"
else
  fail "state file content: $(cat "$STATE_DIR/changed-apps-s-state" 2>/dev/null | tr '\n' ' ')"
fi
f3="$(place api bad-types.php Err.php)"
run_hook "$(payload "$f3" s-state-err)"
if [ "$RC" = 2 ] && [ "$(cat "$STATE_DIR/changed-apps-s-state-err" 2>/dev/null)" = "api" ]; then
  pass "state recorded even when the check reported errors"
else
  fail "no state after failing check (rc=$RC)"
fi
run_hook "$(payload "$ROOT/docs/vision.md" s-state-doc)"
if [ ! -e "$STATE_DIR/changed-apps-s-state-doc" ]; then pass "non-app file writes no state"; else fail "state written for non-app file"; fi
run_hook "$(payload "$f1")"
expect "missing session_id: check still runs, rc 0" 0
if [ -z "$(ls "$STATE_DIR" | grep -v -e s-state)" ]; then pass "missing session_id writes no state"; else fail "state written without session_id: $(ls "$STATE_DIR")"; fi
run_hook "$(payload "$f1" '../evil')"
if [ ! -e "$STATE_DIR/changed-apps-../evil" ] && [ ! -e "$TMP/evil" ]; then pass "unsafe session_id is not used in a file name"; else fail "unsafe session_id used"; fi

echo
echo "passed=$PASS failed=$FAIL skipped=$SKIPPED"
[ "$FAIL" = 0 ]

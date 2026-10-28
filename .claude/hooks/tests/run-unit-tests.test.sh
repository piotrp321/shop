#!/usr/bin/env bash
# Tests for ../run-unit-tests.sh. The real suites are never run: UNIT_TEST_CMD substitutes the test command.
# The substitute is called as `$UNIT_TEST_CMD <app>`, logs the app to $CALL_LOG and fails for apps in $FAIL_APPS.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
HOOK="$ROOT/.claude/hooks/run-unit-tests.sh"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/stop-hook-test.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
STATE_DIR="$TMP/state"
CALL_LOG="$TMP/calls"

cat >"$TMP/fake-tests.sh" <<'SH'
#!/usr/bin/env bash
echo "$1" >>"$CALL_LOG"
echo "running tests of $1"
case " $FAIL_APPS " in
  *" $1 "*) echo "FAILURES! test of $1 broke"; exit 1 ;;
esac
exit 0
SH
chmod +x "$TMP/fake-tests.sh"

PASS=0
FAIL=0
RC=0
ERR=""

pass() { PASS=$((PASS + 1)); echo "PASS: $1"; }
fail() { FAIL=$((FAIL + 1)); echo "FAIL: $1"; }

# run_stop <session_id|-> [VAR=value ...]; "-" sends JSON without session_id.
run_stop() {
  local sid="$1" json
  shift
  if [ "$sid" = "-" ]; then json='{"hook_event_name":"Stop","stop_hook_active":false}'
  else json="{\"session_id\":\"$sid\",\"hook_event_name\":\"Stop\",\"stop_hook_active\":false}"; fi
  : >"$CALL_LOG"
  printf '%s' "$json" | env CLAUDE_PROJECT_DIR="$ROOT" HOOK_STATE_DIR="$STATE_DIR" UNIT_TEST_CMD="$TMP/fake-tests.sh" \
    CALL_LOG="$CALL_LOG" FAIL_APPS="${FAIL_APPS:-}" "$@" /bin/bash "$HOOK" 2>"$TMP/stderr" >"$TMP/stdout"
  RC=$?
  ERR="$(cat "$TMP/stderr")"
  echo "  rc=$RC calls=[$(tr '\n' ' ' <"$CALL_LOG")]"
  echo "$ERR" | head -n 6 | sed 's/^/  stderr: /'
}

calls() { tr '\n' ' ' <"$CALL_LOG" | sed 's/ $//'; }
set_state() { mkdir -p "$STATE_DIR"; printf '%s\n' "${@:2}" >"$STATE_DIR/changed-apps-$1"; }
reset() { rm -rf "$STATE_DIR"; mkdir -p "$STATE_DIR"; FAIL_APPS=""; }

echo "== no state file =="
reset
run_stop s1
if [ "$RC" = 0 ] && [ -z "$(calls)" ]; then pass "no state -> rc 0, tests not called"; else fail "no state"; fi

echo "== state api, tests pass =="
reset; set_state s2 api
run_stop s2
if [ "$RC" = 0 ] && [ "$(calls)" = "api" ] && [ ! -e "$STATE_DIR/changed-apps-s2" ]; then
  pass "api tests only, state removed, rc 0"
else fail "api pass case"; fi

echo "== state api+store, store fails =="
reset; set_state s3 api store; FAIL_APPS="store"
run_stop s3
if [ "$RC" = 2 ] && [ "$(calls)" = "api store" ] && printf '%s' "$ERR" | grep -q "test of store broke" \
  && [ -e "$STATE_DIR/changed-apps-s3" ]; then
  pass "rc 2, stderr has store output, state kept"
else fail "store failure case"; fi

echo "== third consecutive block =="
run_stop s3
r2=$RC
run_stop s3
if [ "$r2" = 2 ] && [ "$RC" = 0 ] && printf '%s' "$ERR" | grep -qi "not passing" && [ -e "$STATE_DIR/changed-apps-s3" ]; then
  pass "second failure rc 2, third rc 0 with notice, state kept"
else fail "loop guard (second rc=$r2, third rc=$RC)"; fi

echo "== passing tests reset the counter =="
reset; set_state s4 api store; FAIL_APPS="store"
run_stop s4; run_stop s4          # counter = 2
FAIL_APPS=""
run_stop s4                       # pass -> counter reset, state removed
c_after="$(ls "$STATE_DIR" | grep -c stop-blocks)"
set_state s4 store; FAIL_APPS="store"
run_stop s4
if [ "$RC" = 2 ] && [ "$c_after" = 0 ]; then pass "counter reset after passing run (next failure blocks again)"; else fail "counter reset (rc=$RC counters=$c_after)"; fi

echo "== sessions are independent =="
reset; set_state sA store; set_state sB store; FAIL_APPS="store"
run_stop sA; run_stop sA; run_stop sA
run_stop sB
if [ "$RC" = 2 ]; then pass "other session's counter unaffected"; else fail "session isolation (rc=$RC)"; fi

echo "== error paths =="
reset; set_state s5 api
run_stop -
if [ "$RC" = 0 ] && [ -z "$(calls)" ]; then pass "missing session_id -> rc 0, tests not called"; else fail "missing session_id"; fi
run_stop '../evil'
if [ "$RC" = 0 ] && [ -z "$(calls)" ]; then pass "unsafe session_id -> rc 0, tests not called"; else fail "unsafe session_id"; fi
reset; set_state s6 bogus
run_stop s6
if [ "$RC" = 0 ] && [ -z "$(calls)" ] && printf '%s' "$ERR" | grep -q "bogus"; then pass "unknown app in state ignored with notice"; else fail "unknown app"; fi
printf 'garbage' | env CLAUDE_PROJECT_DIR="$ROOT" HOOK_STATE_DIR="$STATE_DIR" UNIT_TEST_CMD="$TMP/fake-tests.sh" /bin/bash "$HOOK" 2>"$TMP/stderr"
rc=$?; echo "  rc=$rc stderr: $(cat "$TMP/stderr")"
if [ "$rc" = 0 ]; then pass "malformed JSON -> rc 0"; else fail "malformed JSON"; fi
rc=$(printf '{"session_id":"s7"}' | env PATH=/var/empty CLAUDE_PROJECT_DIR="$ROOT" HOOK_STATE_DIR="$STATE_DIR" /bin/bash "$HOOK" 2>"$TMP/stderr" >/dev/null; echo $?)
echo "  rc=$rc stderr: $(cat "$TMP/stderr")"
if [ "$rc" = 0 ] && grep -q jq "$TMP/stderr"; then pass "no jq -> rc 0 with notice"; else fail "no jq"; fi

echo "== container not running (real default command path, no suite is started) =="
reset; set_state s8 api
printf '{"session_id":"s8"}' | env CLAUDE_PROJECT_DIR="$ROOT" HOOK_STATE_DIR="$STATE_DIR" COMPOSE_PROJECT_NAME=hook-test-no-such-project /bin/bash "$HOOK" 2>"$TMP/stderr" >/dev/null
rc=$?; echo "  rc=$rc stderr: $(cat "$TMP/stderr")"
if [ "$rc" = 0 ] && grep -q "not running" "$TMP/stderr" && [ -e "$STATE_DIR/changed-apps-s8" ]; then
  pass "container down -> rc 0, notice, state kept"
else fail "container down"; fi

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" = 0 ]

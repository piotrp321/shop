#!/usr/bin/env bash
# Tests for ../run-unit-tests.sh. The real suites are never run: SHOP_HOOK_UNIT_TEST_CMD substitutes the test command.
# The substitute is called as `$SHOP_HOOK_UNIT_TEST_CMD <app>`, logs the app to $CALL_LOG and fails for apps in $FAIL_APPS.
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
cat >"$TMP/fake-running.sh" <<'SH'
#!/usr/bin/env bash
case " ${DOWN_APPS:-} " in
  *" $1 "*) exit 1 ;;
esac
exit 0
SH
cat >"$TMP/fake-deptrac.sh" <<'SH'
#!/usr/bin/env bash
echo deptrac >>"$DEPTRAC_LOG"
if [ "${DEPTRAC_FAIL:-0}" = 1 ]; then echo "Deptrac: 1 violation, Domain must not depend on Symfony"; exit 1; fi
echo "Deptrac: no violations"
exit 0
SH
chmod +x "$TMP/fake-tests.sh" "$TMP/fake-running.sh" "$TMP/fake-deptrac.sh"
DEPTRAC_LOG="$TMP/deptrac-calls"

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
  : >"$CALL_LOG"; : >"$DEPTRAC_LOG"
  printf '%s' "$json" | env CLAUDE_PROJECT_DIR="$ROOT" SHOP_HOOK_STATE_DIR="$STATE_DIR" SHOP_HOOK_UNIT_TEST_CMD="$TMP/fake-tests.sh" \
    SHOP_HOOK_CONTAINER_RUNNING_CMD="$TMP/fake-running.sh" DOWN_APPS="${DOWN_APPS:-}" SHOP_HOOK_DEPTRAC_CMD="$TMP/fake-deptrac.sh" DEPTRAC_LOG="$DEPTRAC_LOG" DEPTRAC_FAIL="${DEPTRAC_FAIL:-0}" CALL_LOG="$CALL_LOG" FAIL_APPS="${FAIL_APPS:-}" "$@" /bin/bash "$HOOK" 2>"$TMP/stderr" >"$TMP/stdout"
  RC=$?
  ERR="$(cat "$TMP/stderr")"
  echo "  rc=$RC calls=[$(tr '\n' ' ' <"$CALL_LOG")] deptrac_runs=$(grep -c . "$DEPTRAC_LOG")"
  echo "$ERR" | head -n 8 | sed 's/^/  stderr: /'
}

calls() { tr '\n' ' ' <"$CALL_LOG" | sed 's/ $//'; }
set_state() { mkdir -p "$STATE_DIR"; printf '%s\n' "${@:2}" >"$STATE_DIR/changed-apps-$1"; }
reset() { rm -rf "$STATE_DIR"; mkdir -p "$STATE_DIR"; FAIL_APPS=""; DOWN_APPS=""; DEPTRAC_FAIL=0; }

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

echo "== three consecutive blocks, the fourth failing run exits 0 (run 1 was the block above) =="
run_stop s3; r2=$RC
run_stop s3; r3=$RC
run_stop s3; r4=$RC
if [ "$r2" = 2 ] && [ "$r3" = 2 ] && [ "$r4" = 0 ] && printf '%s' "$ERR" | grep -qi "still failing" \
  && [ -e "$STATE_DIR/changed-apps-s3" ]; then
  pass "runs 2 and 3 block (rc 2), run 4 rc 0 with notice, state kept"
else fail "loop guard (rc2=$r2 rc3=$r3 rc4=$r4)"; fi

echo "== passing tests reset the counter =="
reset; set_state s4 api store; FAIL_APPS="store"
run_stop s4; run_stop s4; run_stop s4   # counter = 3
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
printf 'garbage' | env CLAUDE_PROJECT_DIR="$ROOT" SHOP_HOOK_STATE_DIR="$STATE_DIR" SHOP_HOOK_UNIT_TEST_CMD="$TMP/fake-tests.sh" /bin/bash "$HOOK" 2>"$TMP/stderr"
rc=$?; echo "  rc=$rc stderr: $(cat "$TMP/stderr")"
if [ "$rc" = 0 ]; then pass "malformed JSON -> rc 0"; else fail "malformed JSON"; fi
rc=$(printf '{"session_id":"s7"}' | env PATH=/var/empty CLAUDE_PROJECT_DIR="$ROOT" SHOP_HOOK_STATE_DIR="$STATE_DIR" /bin/bash "$HOOK" 2>"$TMP/stderr" >/dev/null; echo $?)
echo "  rc=$rc stderr: $(cat "$TMP/stderr")"
if [ "$rc" = 0 ] && grep -q jq "$TMP/stderr"; then pass "no jq -> rc 0 with notice"; else fail "no jq"; fi

echo "== container not running (real default path, nothing is started) =="
reset; set_state s8 api
printf '{"session_id":"s8"}' | env CLAUDE_PROJECT_DIR="$ROOT" SHOP_HOOK_STATE_DIR="$STATE_DIR" COMPOSE_PROJECT_NAME=hook-test-no-such-project /bin/bash "$HOOK" 2>"$TMP/stderr" >/dev/null
rc=$?; echo "  rc=$rc stderr: $(cat "$TMP/stderr")"
if [ "$rc" = 2 ] && grep -q "make up" "$TMP/stderr" && grep -q "api" "$TMP/stderr" && [ -e "$STATE_DIR/changed-apps-s8" ]; then
  pass "docker project without containers -> rc 2 with make up message, state kept"
else fail "container down (real path)"; fi

echo "== docker unavailable on PATH =="
mkdir -p "$TMP/bin"
for t in jq grep sed cat tail tr mkdir rm dirname head; do ln -sf "$(command -v $t)" "$TMP/bin/$t"; done
reset; set_state s12 api
printf '{"session_id":"s12"}' | env PATH="$TMP/bin" CLAUDE_PROJECT_DIR="$ROOT" SHOP_HOOK_STATE_DIR="$STATE_DIR" /bin/bash "$HOOK" 2>"$TMP/stderr" >/dev/null
rc=$?; echo "  rc=$rc stderr: $(cat "$TMP/stderr")"
if [ "$rc" = 2 ] && grep -q "make up" "$TMP/stderr" && [ -e "$STATE_DIR/changed-apps-s12" ]; then
  pass "no docker binary -> rc 2, state kept"
else fail "docker unavailable"; fi

echo "== Deptrac =="
reset; set_state d1 api; DEPTRAC_FAIL=1
run_stop d1
if [ "$RC" = 2 ] && [ "$(calls)" = "api" ] && printf '%s' "$ERR" | grep -q "Domain must not depend on Symfony" && [ -e "$STATE_DIR/changed-apps-d1" ]; then
  pass "Deptrac fails, tests pass -> rc 2, Deptrac output on stderr, state kept"
else fail "deptrac only (rc=$RC)"; fi
reset; set_state d2 api; FAIL_APPS="api"
run_stop d2
if [ "$RC" = 2 ] && [ "$(grep -c . "$DEPTRAC_LOG")" = 1 ] && printf '%s' "$ERR" | grep -q "test of api broke" && ! printf '%s' "$ERR" | grep -q Deptrac; then
  pass "tests fail, Deptrac passes -> rc 2, Deptrac still ran"
else fail "tests only (rc=$RC)"; fi
reset; set_state d3 api; FAIL_APPS="api"; DEPTRAC_FAIL=1
run_stop d3
if [ "$RC" = 2 ] && printf '%s' "$ERR" | grep -q "test of api broke" && printf '%s' "$ERR" | grep -q "1 violation"; then
  pass "both fail -> rc 2, both outputs reported"
else fail "both fail (rc=$RC)"; fi
reset; set_state d4 store admin; DEPTRAC_FAIL=1
run_stop d4
if [ "$RC" = 0 ] && [ "$(grep -c . "$DEPTRAC_LOG")" = 0 ] && [ "$(calls)" = "store admin" ]; then
  pass "only store/admin changed -> Deptrac not run, rc 0"
else fail "deptrac for frontends (rc=$RC)"; fi
reset; set_state d5 api; DEPTRAC_FAIL=1
run_stop d5; a=$RC; run_stop d5; b=$RC; run_stop d5; c=$RC; run_stop d5; d=$RC
DEPTRAC_FAIL=0; run_stop d5; e=$RC
if [ "$a$b$c$d$e" = 22200 ] && [ ! -e "$STATE_DIR/changed-apps-d5" ] && [ ! -e "$STATE_DIR/stop-blocks-d5" ]; then
  pass "Deptrac blocks 3 times, 4th run rc 0; a pass clears state and counter ($a $b $c $d $e)"
else fail "deptrac loop guard ($a $b $c $d $e)"; fi
reset; set_state d6 api; DEPTRAC_FAIL=1
run_stop d6; run_stop d6; DEPTRAC_FAIL=0; run_stop d6; set_state d6 api; DEPTRAC_FAIL=1; run_stop d6; x=$RC; run_stop d6; y=$RC; run_stop d6; z=$RC; run_stop d6; w=$RC
if [ "$x$y$z$w" = 2220 ]; then pass "pass resets the counter: 3 fresh blocks after it, then rc 0"; else fail "counter reset ($x $y $z $w)"; fi

echo "== container down blocks =="
reset; set_state c1 store; DOWN_APPS="store"
run_stop c1
if [ "$RC" = 2 ] && [ -z "$(calls)" ] && printf '%s' "$ERR" | grep -q "make up" && printf '%s' "$ERR" | grep -q "store" && [ -e "$STATE_DIR/changed-apps-c1" ]; then
  pass "container down -> rc 2, message names app and make up, state kept"
else fail "container down (rc=$RC)"; fi
reset; set_state c2 api store; DOWN_APPS="store"
run_stop c2
if [ "$RC" = 2 ] && [ "$(calls)" = "api" ] && [ "$(grep -c . "$DEPTRAC_LOG")" = 1 ] && printf '%s' "$ERR" | grep -q "Changed app: store"; then
  pass "mixed: api ran and passed, store down -> rc 2, state kept"
else fail "mixed (rc=$RC)"; fi
reset; set_state c3 api store; DOWN_APPS="store"; FAIL_APPS="api"
run_stop c3
if [ "$RC" = 2 ] && printf '%s' "$ERR" | grep -q "test of api broke" && printf '%s' "$ERR" | grep -q "Changed app: store"; then
  pass "mixed: api failure and store down reported together"
else fail "mixed failing (rc=$RC)"; fi
reset; set_state c4 store; DOWN_APPS="store"
run_stop c4; a=$RC; run_stop c4; b=$RC; run_stop c4; c=$RC; run_stop c4; d=$RC
if [ "$a$b$c$d" = 2220 ] && printf '%s' "$ERR" | grep -qi "could not run" && [ -e "$STATE_DIR/changed-apps-c4" ]; then
  pass "down container: 3 blocks, 4th rc 0 with notice, state kept ($a $b $c $d)"
else fail "down loop guard ($a $b $c $d)"; fi
DOWN_APPS=""; run_stop c4
if [ "$RC" = 0 ] && [ ! -e "$STATE_DIR/changed-apps-c4" ]; then pass "stack back up and tests pass -> rc 0, state cleared"; else fail "recovery (rc=$RC)"; fi

echo "== bypass attempts =="
reset; : >"$STATE_DIR/changed-apps-b1"
run_stop b1
if [ "$RC" = 0 ] && [ -z "$(calls)" ]; then pass "empty state file -> rc 0, nothing runs (nothing changed)"; else fail "empty state (rc=$RC)"; fi
reset; set_state b2 '../x' 'api/../../etc'
run_stop b2
if [ "$RC" = 0 ] && [ -z "$(calls)" ] && printf '%s' "$ERR" | grep -q "unknown app '../x'"; then
  pass "only unknown/traversal entries -> ignored with notice, nothing runs, rc 0"
else fail "traversal state (rc=$RC)"; fi
reset; set_state b3 '../x' api; FAIL_APPS="api"
run_stop b3
if [ "$RC" = 2 ] && [ "$(calls)" = "api" ]; then pass "unknown entry ignored, valid app still checked and blocks"; else fail "unknown + api (rc=$RC)"; fi
reset; set_state b4 api; DEPTRAC_FAIL=1
echo 3 >"$STATE_DIR/stop-blocks-OLDSESSION"
run_stop b4
if [ "$RC" = 2 ]; then pass "stale counter of another session does not affect this one"; else fail "stale counter (rc=$RC)"; fi
reset; set_state b5 api
cat >"$TMP/dying-tests.sh" <<'SH'
#!/usr/bin/env bash
echo "Error response from daemon: container is not running"
exit 1
SH
chmod +x "$TMP/dying-tests.sh"
run_stop b5 SHOP_HOOK_UNIT_TEST_CMD="$TMP/dying-tests.sh"
if [ "$RC" = 2 ] && printf '%s' "$ERR" | grep -q "container is not running"; then pass "container dies between check and run (exec fails) -> counted as failure, rc 2"; else fail "dying exec (rc=$RC)"; fi
reset; set_state b6 api
printf '{"session_id":"b6"}' | env CLAUDE_PROJECT_DIR="$ROOT" SHOP_HOOK_STATE_DIR="$STATE_DIR" SHOP_HOOK_UNIT_TEST_CMD= SHOP_HOOK_DEPTRAC_CMD= SHOP_HOOK_CONTAINER_RUNNING_CMD= COMPOSE_PROJECT_NAME=hook-test-no-such-project /bin/bash "$HOOK" 2>"$TMP/stderr" >/dev/null
rc=$?; echo "  rc=$rc stderr: $(cat "$TMP/stderr")"
if [ "$rc" = 2 ] && grep -q "make up" "$TMP/stderr" && [ -z "$(calls)" ]; then
  pass "empty-string overrides count as unset (real container check used, no substitute ran)"
else fail "empty overrides (rc=$rc)"; fi

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" = 0 ]

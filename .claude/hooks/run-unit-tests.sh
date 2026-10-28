#!/usr/bin/env bash
# Stop hook: runs the unit tests of the apps changed in this session (listed in
# .claude/state/changed-apps-<session_id>, written by check-changed-file.sh).
# Failing tests: tail of the output on stderr and exit 2, so the agent keeps working. After 3 consecutive
# failing runs in one session the hook gives up (exit 0) so a hard failure cannot loop forever.
# Tests pass: state file and counter are removed. No state file, no jq, container not running: exit 0.
# Test-only overrides (used by the hook tests, never set in normal use):
#   SHOP_HOOK_STATE_DIR              state directory instead of .claude/state
#   SHOP_HOOK_UNIT_TEST_CMD          replaces the real test command, called as `<cmd> <app>`
#   SHOP_HOOK_CONTAINER_RUNNING_CMD  replaces the "is the container running" check, called as `<cmd> <app>`
# An app whose container is not running is skipped (with a notice); it never hides failures of other apps.
# Works with bash 3.2 and BSD tools.

MAX_BLOCKS=3
TAIL_LINES=40

if ! command -v jq >/dev/null 2>&1; then
  echo "run-unit-tests: jq not found on the host, skipping the tests" >&2
  exit 0
fi

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
STATE_DIR="${SHOP_HOOK_STATE_DIR:-$PROJECT_DIR/.claude/state}"

if ! SESSION_ID="$(jq -r '.session_id // empty' 2>/dev/null)"; then
  echo "run-unit-tests: invalid hook JSON on stdin, skipping the tests" >&2
  exit 0
fi
case "$SESSION_ID" in
  '' | *[!A-Za-z0-9_-]*) exit 0 ;;
esac

STATE_FILE="$STATE_DIR/changed-apps-$SESSION_ID"
COUNTER_FILE="$STATE_DIR/stop-blocks-$SESSION_ID"
[ -s "$STATE_FILE" ] || exit 0

cd "$PROJECT_DIR" || exit 0

container_running() {
  if [ -n "${SHOP_HOOK_CONTAINER_RUNNING_CMD:-}" ]; then
    "$SHOP_HOOK_CONTAINER_RUNNING_CMD" "$1"
  else
    command -v docker >/dev/null 2>&1 && docker compose ps --status running --services 2>/dev/null | grep -qx "$1"
  fi
}

# Default commands run through the app Makefiles; -T because a hook has no TTY.
default_tests() {
  case "$1" in
    api) make -C apps/api test-unit RUN="docker compose exec -T" ;;
    store) make -C apps/store test-unit RUN="docker compose exec -T" ;;
    admin) make -C apps/admin test RUN="docker compose exec -T" ;;
  esac
}

OUTPUT=""
FAILED=0
RAN=0
SKIPPED=0
while IFS= read -r APP; do
  case "$APP" in
    api | store | admin) ;;
    '') continue ;;
    *)
      echo "run-unit-tests: unknown app '$APP' in $STATE_FILE, ignoring" >&2
      continue
      ;;
  esac
  if ! container_running "$APP"; then
    echo "run-unit-tests: container $APP is not running (make up), skipping its tests" >&2
    SKIPPED=1
    continue
  fi
  RAN=1
  if [ -n "${SHOP_HOOK_UNIT_TEST_CMD:-}" ]; then
    OUT="$("$SHOP_HOOK_UNIT_TEST_CMD" "$APP" </dev/null 2>&1)"
  else
    OUT="$(default_tests "$APP" </dev/null 2>&1)"
  fi
  if [ $? -ne 0 ]; then
    FAILED=1
    OUTPUT="$OUTPUT--- unit tests of $APP failed (last $TAIL_LINES lines) ---"$'\n'"$(printf '%s\n' "$OUT" | tail -n "$TAIL_LINES")"$'\n'
  fi
done <"$STATE_FILE"

if [ "$RAN" -eq 0 ]; then
  exit 0
fi

if [ "$FAILED" -eq 0 ]; then
  # Keep the state when an app was skipped, so its tests still run once its container is back.
  [ "$SKIPPED" -eq 0 ] || exit 0
  rm -f "$STATE_FILE" "$COUNTER_FILE"
  exit 0
fi

BLOCKS=0
[ -f "$COUNTER_FILE" ] && BLOCKS="$(cat "$COUNTER_FILE" 2>/dev/null)"
case "$BLOCKS" in '' | *[!0-9]*) BLOCKS=0 ;; esac

printf '%s' "$OUTPUT" >&2
if [ "$BLOCKS" -ge "$MAX_BLOCKS" ]; then
  echo "run-unit-tests: unit tests are still not passing after $BLOCKS consecutive blocks; not blocking the stop again. Tell the user the tests do not pass." >&2
  exit 0
fi
mkdir -p "$STATE_DIR" && echo $((BLOCKS + 1)) >"$COUNTER_FILE"
exit 2

#!/usr/bin/env bash
# Stop hook: runs the unit tests of the apps changed in this session (listed in
# .claude/state/changed-apps-<session_id>, written by check-changed-file.sh). For api it also runs Deptrac.
# Everything is run and reported together, nothing stops at the first failure.
# Blocks (tail of the output on stderr, exit 2) when tests or Deptrac fail, and when a changed app's container
# is not running (or docker is missing), because then nothing was verified; the message tells the agent to run
# `make up` or to tell the user it cannot. All block reasons share one counter per session: the first 3
# consecutive blocks exit 2, the 4th exits 0 with a notice so an agent that cannot fix the problem is not
# looped forever. The state file is kept on every block; a clean run removes state and counter.
# No state file (or an empty one), no jq, unusable session_id: exit 0. Unknown app names in the state file
# (e.g. "../x") are ignored with a notice and never run anything.
# Test-only overrides (used by the hook tests, never set in normal use; an empty value counts as unset):
#   SHOP_HOOK_STATE_DIR              state directory instead of .claude/state
#   SHOP_HOOK_UNIT_TEST_CMD          replaces the real test command, called as `<cmd> <app>`
#   SHOP_HOOK_DEPTRAC_CMD            replaces the real Deptrac command, called without arguments
#   SHOP_HOOK_CONTAINER_RUNNING_CMD  replaces the "is the container running" check, called as `<cmd> <app>`
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

tail_block() { # tail_block <title> <output>
  printf '%s\n%s\n' "--- $1 (last $TAIL_LINES lines) ---" "$(printf '%s\n' "$2" | tail -n "$TAIL_LINES")"
}

run_deptrac() {
  if [ -n "${SHOP_HOOK_DEPTRAC_CMD:-}" ]; then
    "$SHOP_HOOK_DEPTRAC_CMD"
  else
    docker compose exec -T api vendor/bin/deptrac analyse --no-progress
  fi
}

OUTPUT=""
FAILED=0
VALID=0
NOT_RUNNING=""
while IFS= read -r APP; do
  case "$APP" in
    api | store | admin) ;;
    '') continue ;;
    *)
      echo "run-unit-tests: unknown app '$APP' in $STATE_FILE, ignoring" >&2
      continue
      ;;
  esac
  VALID=1
  if ! container_running "$APP"; then
    NOT_RUNNING="$NOT_RUNNING $APP"
    continue
  fi
  if [ -n "${SHOP_HOOK_UNIT_TEST_CMD:-}" ]; then
    OUT="$("$SHOP_HOOK_UNIT_TEST_CMD" "$APP" </dev/null 2>&1)"
  else
    OUT="$(default_tests "$APP" </dev/null 2>&1)"
  fi
  if [ $? -ne 0 ]; then
    FAILED=1
    OUTPUT="$OUTPUT$(tail_block "unit tests of $APP failed" "$OUT")"$'\n'
  fi
  if [ "$APP" = api ]; then
    OUT="$(run_deptrac </dev/null 2>&1)"
    if [ $? -ne 0 ]; then
      FAILED=1
      OUTPUT="$OUTPUT$(tail_block "Deptrac failed" "$OUT")"$'\n'
    fi
  fi
done <"$STATE_FILE"

[ "$VALID" -eq 1 ] || exit 0

if [ "$FAILED" -eq 0 ] && [ -z "$NOT_RUNNING" ]; then
  rm -f "$STATE_FILE" "$COUNTER_FILE"
  exit 0
fi

for APP in $NOT_RUNNING; do
  OUTPUT="$OUTPUT""Changed app: $APP. Its container is not running (or docker is unavailable), so its unit tests and checks did not run. Start the stack with \`make up\` and finish again, or tell the user explicitly that you cannot start it."$'\n'
done

BLOCKS=0
[ -f "$COUNTER_FILE" ] && BLOCKS="$(cat "$COUNTER_FILE" 2>/dev/null)"
case "$BLOCKS" in '' | *[!0-9]*) BLOCKS=0 ;; esac

printf '%s' "$OUTPUT" >&2
if [ "$BLOCKS" -ge "$MAX_BLOCKS" ]; then
  echo "run-unit-tests: tests or checks are still failing or could not run after $BLOCKS consecutive blocks; not blocking the stop again. Tell the user exactly what did not pass or did not run." >&2
  exit 0
fi
mkdir -p "$STATE_DIR" && echo $((BLOCKS + 1)) >"$COUNTER_FILE"
exit 2

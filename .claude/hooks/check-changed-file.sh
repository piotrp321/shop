#!/usr/bin/env bash
# PostToolUse hook (Edit|MultiEdit|Write): lints the changed file inside its app's container.
# Reads the hook JSON from stdin. Findings go to stderr with exit code 2 (Claude Code hands them to the
# agent). Everything that is not a finding (file outside an app, other extension, no jq, container down,
# bad input) exits 0. Also records the app in .claude/state/changed-apps-<session_id> for the Stop hook.
# Test-only override: SHOP_HOOK_STATE_DIR (state directory instead of .claude/state), never set in normal use.
# Works with bash 3.2 and BSD tools.

if ! command -v jq >/dev/null 2>&1; then
  echo "check-changed-file: jq not found on the host, skipping the check" >&2
  exit 0
fi

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
STATE_DIR="${SHOP_HOOK_STATE_DIR:-$PROJECT_DIR/.claude/state}"

INPUT="$(cat)"
if ! FILE="$(printf '%s' "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)"; then
  echo "check-changed-file: invalid hook JSON on stdin, skipping the check" >&2
  exit 0
fi
SESSION_ID="$(printf '%s' "$INPUT" | jq -r '.session_id // empty' 2>/dev/null)"

[ -n "$FILE" ] || exit 0

# Resolve to a path relative to the project root; anything outside the project is not ours.
case "$FILE" in
  "$PROJECT_DIR"/*) REL="${FILE#"$PROJECT_DIR"/}" ;;
  /*) exit 0 ;;
  *) REL="$FILE" ;;
esac
case "$REL" in
  */../* | ../* | */..) exit 0 ;;
esac

case "$REL" in
  apps/api/*) APP=api ;;
  apps/store/*) APP=store ;;
  apps/admin/*) APP=admin ;;
  *) exit 0 ;;
esac
IN_APP="${REL#apps/"$APP"/}" # path as seen inside the container (/app)

[ -f "$PROJECT_DIR/$REL" ] || exit 0

# Record the app for the Stop hook, once per session. Skipped without a usable session_id.
case "$SESSION_ID" in
  '' | *[!A-Za-z0-9_-]*) ;;
  *)
    STATE_FILE="$STATE_DIR/changed-apps-$SESSION_ID"
    if ! { [ -f "$STATE_FILE" ] && grep -qx "$APP" "$STATE_FILE"; }; then
      mkdir -p "$STATE_DIR" && echo "$APP" >>"$STATE_FILE"
    fi
    ;;
esac

case "$APP:$REL" in
  api:*.php) ;;
  store:*.ts | store:*.tsx | store:*.vue | store:*.js | store:*.mjs) ;;
  admin:*.ts | admin:*.tsx | admin:*.vue | admin:*.js | admin:*.mjs) ;;
  *) exit 0 ;;
esac

cd "$PROJECT_DIR" || exit 0
if ! command -v docker >/dev/null 2>&1; then
  echo "check-changed-file: docker not found on the host, skipping the check" >&2
  exit 0
fi
if ! docker compose ps --status running --services 2>/dev/null | grep -qx "$APP"; then
  echo "check-changed-file: container $APP is not running (make up), skipping the check" >&2
  exit 0
fi

STATUS=0
OUTPUT=""
run() { # run <command...>: runs in the app container, appends output on failure
  local out
  if ! out="$(docker compose exec -T "$APP" "$@" </dev/null 2>&1)"; then
    STATUS=2
    OUTPUT="$OUTPUT$out"$'\n'
  fi
}

if [ "$APP" = api ]; then
  run vendor/bin/phpstan analyse --memory-limit=1G --no-progress --error-format=raw "$IN_APP"
  run vendor/bin/php-cs-fixer fix --dry-run --diff --show-progress=none --no-interaction "$IN_APP"
else
  run npx eslint "$IN_APP"
fi

if [ "$STATUS" -ne 0 ]; then
  printf '%s' "$OUTPUT" >&2
  exit 2
fi
exit 0

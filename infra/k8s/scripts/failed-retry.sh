#!/bin/sh
# Retries the messages of the transport `failed`: `make -C infra/k8s failed-retry HOST=<user>@<address> [ID=<id> | MAX=<n>]`, in
# the pod of worker-commands. With ID it retries that one message (messenger:failed:retry <id> --force); without ID it runs
# app:messaging:failed-retry (the oldest MAX messages, 1 to 1000, 200 by default), which prints its report: how many were
# chosen, handled (removed), kept after an error (the old id and the new one), and whether more are left (then run it again). The
# bulk messenger:failed:retry without an id and messenger:consume failed are blocked in the application: never called here.
# A message kept after a failed attempt has a NEW id; there is no "exactly once" (a duplicate is removed with failed-remove). It
# changes the data, so the agent runs it only after the consent of the user in the conversation.
#
# Usage: failed-retry.sh <user@address> [id] [max]      Environment: SSH_OPTS
# Exit codes: 0 done; 1 the command failed (its code is in the message); 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

check_host failed-retry "${1:-}"
id="${2:-}"
max="${3:-}"
if [ -n "$id" ]; then
  [ -z "$max" ] || { echo "failed-retry: MAX goes with the bulk retry only, not with ID" >&2; exit 2; }
  check_number failed-retry ID "$id" 9223372036854775807
elif [ -n "$max" ]; then
  check_number failed-retry MAX "$max" 1000
fi

status=0
if [ -n "$id" ]; then
  worker_console messenger:failed:retry "$id" --force || status=$?
elif [ -n "$max" ]; then
  worker_console app:messaging:failed-retry "--max=$max" || status=$?
else
  worker_console app:messaging:failed-retry || status=$?
fi
if [ "$status" -ne 0 ]; then
  echo "failed-retry: the command failed (exit code $status); read the output above, some messages may be retried" >&2
  exit 1
fi
echo "failed-retry: done (the report is above; a kept message has a new id: check with make failed)"

#!/bin/sh
# Removes one message of the transport `failed`: `make -C infra/k8s failed-remove HOST=<user>@<address> ID=<id>`. Prints the
# message (messenger:failed:show <id> -vv), says what it will do and goes on only after the word `tak` on the standard input;
# then runs messenger:failed:remove <id> --force in the pod of worker-commands. The removal cannot be undone. Never for the
# webhook of a payment (a lost webhook leaves a paid order unpaid). The agent runs it only after the consent of the user in the
# conversation.
#
# Usage: failed-remove.sh <user@address> <id>      Environment: SSH_OPTS
# Exit codes: 0 removed; 1 not confirmed or a failure; 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

check_host failed-remove "${1:-}"
id="${2:-}"
[ -n "$id" ] || { echo "failed-remove: set ID=<id of the message> (make failed lists them)" >&2; exit 2; }
check_number failed-remove ID "$id" 9223372036854775807

worker_console messenger:failed:show "$id" -vv || { echo "failed-remove: could not show the message $id, nothing changed" >&2; exit 1; }
cat << EOF2
failed-remove: on $host, in the pod of worker-commands of the namespace shop:
  - REMOVES the message $id (shown above) from the transport failed; it cannot be brought back.
  Never remove the webhook of a payment: the order would stay unpaid.
EOF2
printf 'failed-remove: type tak to go on: '
answer=""
read -r answer || true
echo
if [ "$answer" != tak ]; then
  echo "failed-remove: not confirmed (no 'tak'), nothing changed" >&2
  exit 1
fi
worker_console messenger:failed:remove "$id" --force || { echo "failed-remove: the command failed, check with make failed" >&2; exit 1; }
echo "failed-remove: removed the message $id"

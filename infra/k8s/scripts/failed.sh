#!/bin/sh
# The messages of the transport `failed`, read over SSH: `make -C infra/k8s failed HOST=<user>@<address>`. Prints the statistics
# (messenger:failed:show --stats) and the list of the first 200 messages (id, class, error, date), in the pod of
# worker-commands. Only reads: nothing is retried, removed or consumed. Exit code 1 when any read failed.
#
# Usage: failed.sh <user@address>      Environment: SSH_OPTS
# Exit codes: 0 read; 1 a read failed; 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

check_host failed "${1:-}"
status=0
printf '== statistics of the transport failed\n'
worker_console messenger:failed:show --stats || { echo "failed: could not read the statistics of the transport failed" >&2; status=1; }
printf '\n== the first 200 messages of the transport failed\n'
worker_console messenger:failed:show --max=200 || { echo "failed: could not read the list of the transport failed" >&2; status=1; }
exit "$status"

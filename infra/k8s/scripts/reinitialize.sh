#!/bin/sh
# Loads the demo data again, wiping what is there: `make -C infra/k8s reinitialize HOST=<user>@<address>`. For an
# interrupted initialization (the job initialize ends with an error about a mixed state) or to start the demo data over.
# Says what it will do and goes on only after the word `tak` on the standard input; then runs bin/seed-demo (APP_ENV=prod:
# --force-reset) in the api pod. The agent runs it only after the consent of the user in the conversation.
#
# Usage: reinitialize.sh <user@address>      Environment: SSH_OPTS
# Exit codes: 0 done; 1 not confirmed or a failure; 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

check_host reinitialize "${1:-}"
cat << EOF2
reinitialize: on $host, in the api pod of the namespace shop:
  - WIPES the products, prices, stocks and orders of the shop (bin/seed-demo with --force-reset, as in production);
  - loads the demo data again and purges the caches of the store.
  Accounts in Keycloak are not touched. The data that is wiped cannot be brought back.
EOF2
printf 'reinitialize: type tak to go on: '
answer=""
read -r answer || true
echo
if [ "$answer" != tak ]; then
  echo "reinitialize: not confirmed (no 'tak'), nothing changed" >&2
  exit 1
fi
remote_root k3s kubectl -n shop exec deploy/api -- bin/seed-demo
echo "reinitialize: done"

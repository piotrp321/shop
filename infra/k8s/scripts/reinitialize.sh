#!/bin/sh
# Loads the demo data again, wiping what is there: `make -C infra/k8s reinitialize HOST=<user>@<address>`. For an
# interrupted initialization (the job initialize ends with an error about a mixed state) or to start the demo data over.
# Says what it will do and goes on only after the word `tak` on the standard input; then runs bin/seed-demo (APP_ENV=prod:
# --force-reset) in the api pod. The agent runs it only after the consent of the user in the conversation.
#
# It refuses, with exit code 1 and before the question, while the NetworkPolicy shop-closed exists (make generate closed the shop:
# its data is being replaced or a run failed; make generate PROFILE=seed goes back to the seed with the checks), or cannot be read.
#
# Usage: reinitialize.sh <user@address>      Environment: SSH_OPTS
# Exit codes: 0 done; 1 refused, not confirmed or a failure; 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

check_host reinitialize "${1:-}"

# ssh does not get the standard input, which holds the answer to the question.
if ! closed="$(remote_root k3s kubectl -n shop get networkpolicy shop-closed --ignore-not-found -o name < /dev/null)"; then
  echo "reinitialize: could not read the rule shop-closed, so nothing was changed" >&2
  exit 1
fi
if [ -n "$closed" ]; then
  echo "reinitialize: refused, the shop is closed by make generate (the NetworkPolicy shop-closed); nothing was changed." >&2
  echo "reinitialize: to go back to the seed data use: make -C infra/k8s generate PROFILE=seed ... (make status shows the state)" >&2
  exit 1
fi
cat << EOF2
reinitialize: on $host, in the api pod of the namespace shop:
  - WIPES the products, prices, promotions, stocks and orders of the shop (bin/seed-demo with --force-reset, as in production);
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

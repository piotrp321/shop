#!/bin/sh
# Loads the demo data again, wiping what is there: `make -C infra/k8s reinitialize HOST=<user>@<address>`. For an
# interrupted initialization (the job initialize ends with an error about a mixed state) or to start the demo data over.
# Says what it will do and goes on only after the word `tak` on the standard input; then runs bin/seed-demo (APP_ENV=prod:
# --force-reset) in the api pod. The agent runs it only after the consent of the user in the conversation.
#
# It acts only on the seed data: first it reads the record demo-data-state (docs/features/demo-scale/spec.md) and refuses, with exit
# code 1 and before the question, when it says data other than seed or phase other than idle (the data of the generator, or a
# generation under way, would be wiped by the seed; `make generate PROFILE=seed` goes back to the seed with the shop closed and the
# checks). No record is data seed, phase idle. A record that cannot be read is a refusal too.
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

# The record (ssh does not get the standard input, which holds the answer to the question), as one text: a marker line when the ConfigMap exists, then data and phase (nothing when there is no ConfigMap).
state_path='present{"\n"}{.data.data}{"\n"}{.data.phase}'
if ! state="$(remote_root k3s kubectl -n shop get configmap demo-data-state --ignore-not-found -o "jsonpath='$state_path'" < /dev/null)"; then
  echo "reinitialize: could not read the record of the demo data (ConfigMap demo-data-state), so nothing was changed" >&2
  exit 1
fi
if [ -n "$state" ]; then
  # Without control characters: the values are printed in the refusal.
  data="$(printf '%s\n' "$state" | sed -n 2p | tr -d '\000-\037\177')"
  phase="$(printf '%s\n' "$state" | sed -n 3p | tr -d '\000-\037\177')"
  if [ "$data" != seed ] || [ "$phase" != idle ]; then
    {
      echo "reinitialize: refused, the record of the demo data says data '${data:-(empty)}', phase '${phase:-(empty)}' (only data seed in phase idle can be loaded again this way)."
      echo "reinitialize: to go back to the seed data use: make -C infra/k8s generate PROFILE=seed ... (see make status for the state)"
    } >&2
    exit 1
  fi
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

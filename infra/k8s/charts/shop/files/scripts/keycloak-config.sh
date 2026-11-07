#!/bin/bash
# The job `keycloak-config`: creates the realms that do not exist yet in Keycloak, from the files rendered by the chart
# (no users, the addresses of the environment). A realm that exists is left as it is: nothing is overwritten and no
# user, password or resource is removed. Runs with the image of Keycloak (kcadm).
set -euo pipefail

kcadm=/opt/keycloak/bin/kcadm.sh
server=http://keycloak:8080

"$kcadm" config credentials --server "$server" --realm master \
  --user "$KC_BOOTSTRAP_ADMIN_USERNAME" --password "$KC_BOOTSTRAP_ADMIN_PASSWORD" >/dev/null

existing="$("$kcadm" get realms --fields realm)"

for realm in shop backoffice; do
  if [[ "$existing" == *"\"realm\" : \"$realm\""* ]]; then
    echo "keycloak-config: realm $realm exists, left as it is"
    continue
  fi
  # The files keep ${STORE_CLIENT_SECRET} for the client of the store; Keycloak substitutes it only at an import at start.
  content="$(<"/realms/$realm.json")"
  # shellcheck disable=SC2016 # the pattern is the literal placeholder
  content="${content//'${STORE_CLIENT_SECRET}'/$STORE_CLIENT_SECRET}"
  printf '%s' "$content" > "/tmp/$realm.json"
  "$kcadm" create realms -f "/tmp/$realm.json"
  rm -f "/tmp/$realm.json"
  echo "keycloak-config: realm $realm created"
done

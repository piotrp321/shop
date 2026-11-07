#!/bin/sh
# The part of `make -C infra/k8s create-admin` that runs in the Keycloak container (create-admin.sh pipes it to
# `kubectl exec ... sh -s`), with the kcadm of the image and the password of the administrator from the environment of
# the container (KC_CLI_PASSWORD, never an argument). Creates the user <login> with the e-mail <email> in the realm
# backoffice, with the role admin and no password, and sends the e-mail with the actions UPDATE_PASSWORD and
# CONFIGURE_TOTP (execute-actions-email, link valid 12 h). Prints no secret.
#
# Usage: create-admin-keycloak.sh <login> <email>
# Exit codes: 0 created and sent; 2 bad arguments; 3 the login or the e-mail exists (nothing changed); other: a failure
# (a user created in this run is removed again, so the command can simply be repeated).
set -eu

login="${1:-}"
email="${2:-}"
if [ -z "$login" ] || [ -z "$email" ]; then
  echo "create-admin: usage: create-admin-keycloak.sh <login> <email>" >&2
  exit 2
fi

kcadm="${KCADM:-/opt/keycloak/bin/kcadm.sh}"
server="${KEYCLOAK_URL:-http://localhost:8080}"
realm=backoffice
lifespan=43200

umask 077
config="$(mktemp)"
trap 'rm -f "$config"' EXIT
kc() { "$kcadm" "$@" --config "$config"; }

KC_CLI_PASSWORD="$KC_BOOTSTRAP_ADMIN_PASSWORD" kc config credentials --server "$server" --realm master \
  --user "$KC_BOOTSTRAP_ADMIN_USERNAME" >/dev/null

if [ -n "$(kc get users -r "$realm" -q "username=$login" -q exact=true --fields id,username --format csv --noquotes)" ]; then
  echo "create-admin: the user $login exists in the realm $realm: nothing changed" >&2
  exit 3
fi
if [ -n "$(kc get users -r "$realm" -q "email=$email" -q exact=true --fields id,username --format csv --noquotes)" ]; then
  echo "create-admin: a user of the realm $realm has the e-mail $email: nothing changed" >&2
  exit 3
fi

id="$(kc create users -r "$realm" -s "username=$login" -s "email=$email" -s enabled=true -i)"

# From here on a failure removes the new user, so that nothing is left half done.
undo() {
  if kc delete "users/$id" -r "$realm" >/dev/null; then
    echo "create-admin: $1 failed: the new user $login was removed, nothing changed" >&2
  else
    echo "create-admin: $1 failed and the new user $login could not be removed: delete it in Keycloak" >&2
  fi
  exit 1
}

kc add-roles -r "$realm" --uid "$id" --rolename admin || undo "adding the role admin"
kc update "users/$id/execute-actions-email" -r "$realm" -q "lifespan=$lifespan" -n -b '["UPDATE_PASSWORD","CONFIGURE_TOTP"]' \
  || undo "sending the e-mail"

echo "create-admin: the user $login was created in the realm $realm with the role admin and no password"
echo "create-admin: $email got an e-mail with the links to set the password and to configure TOTP (valid 12 h)"

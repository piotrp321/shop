#!/bin/sh
# The first administrator of the panel: `make -C infra/k8s create-admin LOGIN=<login> EMAIL=<email>`. Says what it will
# do and goes on only after the word `tak` on the standard input; then runs create-admin-keycloak.sh in the Keycloak
# container (kubectl exec), which creates the user in the realm backoffice with the role admin and no password and sends
# the e-mail to set the password and TOTP (link valid 12 h). The password and TOTP are set by the administrator in the
# browser; this script never sees, asks for or prints a secret.
#
# Usage: create-admin.sh <login> <email>
# Environment: KUBE_DIR (required: the directory with the kubeconfig `config` of the cluster; no default, so that no
# user is created in whatever cluster ~/.kube points at), KUBE_NETWORK (the Docker network kubectl runs on, default
# host), KUBE_NAMESPACE (default shop).
# Exit codes: 0 done; 1 not confirmed or a failure; 2 bad arguments or no KUBE_DIR; 3 the login or the e-mail exists.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

login="${1:-}"
email="${2:-}"
usage() {
  echo "create-admin: $1" >&2
  echo "create-admin: usage: make -C infra/k8s create-admin LOGIN=<login> EMAIL=<email>" >&2
  exit 2
}
[ -n "$login" ] && [ -n "$email" ] || usage "LOGIN and EMAIL are required"
# Keycloak keeps logins in lower case; a login that it would change, or one that looks like an option, is refused.
case "$login" in
  -* | *[!a-z0-9._-]*) usage "LOGIN may have only lower-case letters, digits, '.', '_' and '-', and not start with '-'" ;;
esac
case "$email" in
  -* | *[[:space:]]* | *@*@* | @* | *@ | *@*.) usage "EMAIL '$email' is not an e-mail address" ;;
  *@*.*) ;;
  *) usage "EMAIL '$email' is not an e-mail address" ;;
esac

if [ -z "${KUBE_DIR:-}" ]; then
  echo "create-admin: set KUBE_DIR to the directory with the kubeconfig (file 'config') of the cluster" >&2
  exit 2
fi
KUBE_NETWORK="${KUBE_NETWORK:-host}"
namespace="${KUBE_NAMESPACE:-shop}"
if [ ! -f "$KUBE_DIR/config" ]; then
  echo "create-admin: no kubeconfig at $KUBE_DIR/config (set KUBE_DIR)" >&2
  exit 1
fi

cat <<EOF
create-admin: in the Keycloak of the namespace $namespace of the cluster of $KUBE_DIR/config:
  - creates the user $login with the e-mail $email in the realm backoffice, with the role admin and no password;
  - sends to $email an e-mail with the actions UPDATE_PASSWORD and CONFIGURE_TOTP (the links are valid 12 h).
  An existing login or e-mail stops it without changes.
EOF
printf 'create-admin: type tak to go on: '
answer=""
read -r answer || true
echo
if [ "$answer" != tak ]; then
  echo "create-admin: not confirmed (no 'tak'), nothing changed" >&2
  exit 1
fi

status=0
kubectl -n "$namespace" exec -i deploy/keycloak -c keycloak -- sh -s -- "$login" "$email" < "$here/create-admin-keycloak.sh" \
  || status=$?
exit "$status"

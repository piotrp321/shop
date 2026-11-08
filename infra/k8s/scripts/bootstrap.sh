#!/bin/sh
# Prepares a clean Ubuntu 24.04 server over SSH: `make -C infra/k8s bootstrap HOST=<user>@<address> [SEALED_KEY_FILE=<file>]`.
# Copies scripts/server-bootstrap.sh and argocd/ to the server and runs it there as root (sudo -n when the user is not
# root): k3s, vm.max_map_count, the firewall, automatic security updates, Argo CD, the Sealed Secrets controller and the
# root application. It can be run again: what is already right is left alone.
#
# SEALED_KEY_FILE is the backup of the key of the Sealed Secrets controller (a YAML of its key Secret): with it the
# controller keeps the key, and the certificate in the repository stays valid. Without it the controller makes a new key.
# Afterwards the certificate of the controller is copied to infra/k8s/environments/prod/sealed-secrets.pem when the
# repository has none (commit it: seal-secrets.sh encrypts with it); a repository certificate that differs from the
# controller's is an error (the sealed secrets in the repository could not be decrypted), and nothing is overwritten.
#
# Usage: bootstrap.sh <user@address>      Environment: SEALED_KEY_FILE, SSH_OPTS
# Exit codes: 0 done; 1 failure; 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

fail() {
  echo "bootstrap: $1" >&2
  exit "${2:-1}"
}

check_host bootstrap "${1:-}"
key_file="${SEALED_KEY_FILE:-}"
if [ -n "$key_file" ] && [ ! -f "$key_file" ]; then
  fail "SEALED_KEY_FILE '$key_file' is not a file" 2
fi
version="$(printf '%s' "$K3S_VERSION" | sed 's/-k3s/+k3s/')"
cert="$TOOLS_REPO/infra/k8s/environments/prod/sealed-secrets.pem"

stage="$(umask 077 && mktemp -d)" || fail "cannot create a temporary directory"
trap 'rm -rf "$stage"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$stage/payload" "$stage/payload/scripts"
cp -R "$TOOLS_REPO/infra/k8s/argocd" "$stage/payload/argocd"
cp "$here/server-bootstrap.sh" "$stage/payload/scripts/server-bootstrap.sh"
if [ -n "$key_file" ]; then
  (umask 077 && cp "$key_file" "$stage/payload/sealed-secrets-key.yaml")
fi
(umask 077 && tar -cf "$stage/payload.tar" -C "$stage/payload" .) || fail "cannot pack the payload"

echo "bootstrap: $host: k3s $version, Argo CD and Sealed Secrets${key_file:+ (with the key from the backup)}"
# The payload goes in on the standard input; the key (when there is one) is in it, not in an argument.
# shellcheck disable=SC2016 # expanded on the server
remote_script='d="$(mktemp -d)" && trap "rm -rf \"$d\"" EXIT && tar -xf - -C "$d" &&
  if [ "$(id -u)" -eq 0 ]; then sudo=""; else sudo="sudo -n"; fi &&
  $sudo env K3S_VERSION='"'$version'"' TLS_SAN='"'$address'"' SSH_CONNECTION="$SSH_CONNECTION" sh "$d/scripts/server-bootstrap.sh" "$d"'
remote "$remote_script" < "$stage/payload.tar" || fail "the bootstrap failed on $host (the output is above); it can be run again"

if ! remote "cat /var/lib/shop-bootstrap/sealed-secrets.pem" > "$stage/cert.pem"; then
  fail "could not copy the certificate of the Sealed Secrets controller from $host"
fi
[ "$(head -n 1 "$stage/cert.pem")" = "-----BEGIN CERTIFICATE-----" ] || fail "what $host gave as the certificate is not a PEM certificate"
if [ -f "$cert" ]; then
  if cmp -s "$stage/cert.pem" "$cert"; then
    echo "bootstrap: the certificate in the repository is the one of the controller"
  else
    fail "the certificate in infra/k8s/environments/prod/sealed-secrets.pem is NOT the one of the controller on $host: the sealed secrets of the repository could not be decrypted. Restore the key from the backup (SEALED_KEY_FILE) or seal the secrets again with the new certificate (see the runbook). Nothing was overwritten."
  fi
else
  mkdir -p "$(dirname "$cert")"
  cp "$stage/cert.pem" "$cert"
  echo "bootstrap: the certificate of the controller is in infra/k8s/environments/prod/sealed-secrets.pem: commit it (it is public)"
fi
echo "bootstrap: done"

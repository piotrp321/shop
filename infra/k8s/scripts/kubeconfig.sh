#!/bin/sh
# Copies the kubeconfig of k3s from the server to the computer: `make -C infra/k8s kubeconfig HOST=<user>@<address>
# KUBE_DIR=<directory>`. rerun and create-admin use it (KUBE_DIR). The file holds the credentials of the cluster admin: it is
# written into KUBE_DIR (private, mode 600), which must be outside the repository, and never printed.
#
# Usage: kubeconfig.sh <user@address>      Environment: KUBE_DIR (required), SSH_OPTS
# Exit codes: 0 done; 1 failure; 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

check_host kubeconfig "${1:-}"
if [ -z "${KUBE_DIR:-}" ]; then
  echo "kubeconfig: set KUBE_DIR to the directory for the kubeconfig (outside the repository)" >&2
  exit 2
fi
case "$KUBE_DIR" in
  /*) absolute="$KUBE_DIR" ;;
  *) absolute="$PWD/$KUBE_DIR" ;;
esac
repo_real="$(cd "$TOOLS_REPO" && pwd -P)"
case "$absolute/" in
  "$TOOLS_REPO"/* | "$repo_real"/*) echo "kubeconfig: KUBE_DIR is inside the repository; the credentials must stay outside it" >&2; exit 2 ;;
esac

tmp="$(umask 077 && mktemp)"
trap 'rm -f "$tmp"' EXIT
if ! remote_root cat /etc/rancher/k3s/k3s.yaml > "$tmp"; then
  echo "kubeconfig: could not read /etc/rancher/k3s/k3s.yaml on $host (is k3s bootstrapped, may the user use sudo?)" >&2
  exit 1
fi
grep -q '^clusters:' "$tmp" || { echo "kubeconfig: what $host gave is not a kubeconfig" >&2; exit 1; }
mkdir -p "$KUBE_DIR"
(umask 077 && sed "s#https://127.0.0.1:6443#https://$address:6443#" "$tmp" > "$KUBE_DIR/config.new" && mv "$KUBE_DIR/config.new" "$KUBE_DIR/config")
chmod 600 "$KUBE_DIR/config"
echo "kubeconfig: $KUBE_DIR/config points at https://$address:6443"

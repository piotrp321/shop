#!/bin/sh
# Copies the kubeconfig of k3s from the server to the computer: `make -C infra/k8s kubeconfig HOST=<user>@<address>
# KUBE_DIR=<directory>`. rerun and create-admin use it (KUBE_DIR). The file holds the credentials of the cluster admin: it is
# written into KUBE_DIR (private, mode 600), which must be outside the repository, and never printed.
#
# The absolute path of KUBE_DIR is also recorded in the registry ~/.local/state/shop-ops/kube-dirs (a private file in a private
# directory, outside the reach of the executors), one path per line, without duplicates and without a trailing slash. The
# registry is a contract: ao-prepare (tools/agent-security/ao-prepare) reads it and refuses to start AO while any recorded
# directory still exists, because the kubeconfig of production in it is readable by an executor and its Docker. The runbook
# (README.md) says to remove KUBE_DIR after the operation. A registry that cannot be written stops the script before the
# directory and the kubeconfig are created.
#
# Usage: kubeconfig.sh <user@address>      Environment: KUBE_DIR (required), SSH_OPTS, HOME (the registry)
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
newline='
'
case "$KUBE_DIR" in
  *"$newline"*) echo "kubeconfig: KUBE_DIR has a line break; the registry of directories has one path per line" >&2; exit 2 ;;
esac
if [ -z "${HOME:-}" ]; then
  echo "kubeconfig: HOME is not set; the registry of KUBE_DIR is in \$HOME/.local/state/shop-ops" >&2
  exit 2
fi
case "$KUBE_DIR" in
  /*) absolute="$KUBE_DIR" ;;
  *) absolute="$PWD/$KUBE_DIR" ;;
esac
while [ "$absolute" != / ] && [ "${absolute%/}" != "$absolute" ]; do absolute="${absolute%/}"; done
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
registry_dir="$HOME/.local/state/shop-ops"
registry="$registry_dir/kube-dirs"
if ! (
  umask 077
  mkdir -p "$registry_dir" && chmod 700 "$registry_dir" && touch "$registry" && chmod 600 "$registry" \
    && { grep -Fxq -- "$absolute" "$registry" || printf '%s\n' "$absolute" >> "$registry"; }
) 2> /dev/null; then
  echo "kubeconfig: could not record KUBE_DIR in $registry; nothing was written" >&2
  exit 1
fi
mkdir -p "$KUBE_DIR"
(umask 077 && sed "s#https://127.0.0.1:6443#https://$address:6443#" "$tmp" > "$KUBE_DIR/config.new" && mv "$KUBE_DIR/config.new" "$KUBE_DIR/config")
chmod 600 "$KUBE_DIR/config"
echo "kubeconfig: $KUBE_DIR/config points at https://$address:6443"

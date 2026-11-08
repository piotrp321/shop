#!/bin/sh
# The state of the environment, read over SSH: `make -C infra/k8s status HOST=<user>@<address>`. Prints the applications of
# Argo CD, the pods and the jobs of the namespace shop. Changes nothing. Exit code 1 when any of the three reads failed.
#
# Usage: status.sh <user@address>      Environment: SSH_OPTS
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

check_host status "${1:-}"
failed=0
section() {
  title="$1"
  shift
  printf '\n== %s\n' "$title"
  remote_root k3s kubectl "$@" || { echo "status: could not read $title" >&2; failed=1; }
}
section "applications of Argo CD (sync and health)" -n argocd get applications.argoproj.io
section "pods of the namespace shop" -n shop get pods -o wide
section "jobs of the namespace shop" -n shop get jobs
exit "$failed"

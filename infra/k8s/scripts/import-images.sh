#!/bin/sh
# Imports local images into the node of a k3d cluster and makes sure the node has them (smoke.sh and load.sh).
#
#   import-images.sh <cluster> <node> <image>...
#
# `k3d image import` may end with 0 although the node has not got the images: it only logs a failed `ctr image import` of
# a node (the tarball it saved in the tools node may not be there yet when the node reads it), and a pod of the next
# revision then waits in ErrImageNeverPull. So after each import the node itself is asked, through the CRI as the kubelet
# asks it (crictl inspecti), and what is missing is imported again, at most three imports in all.
# Exit: 0 every image is in the node, 1 an image is still missing after three imports, 2 usage.
set -eu

[ "$#" -ge 3 ] || { echo "import-images: usage: import-images.sh <cluster> <node> <image>..." >&2; exit 2; }
cluster="$1"
node="$2"
shift 2

# shellcheck source=tools.sh
. "$(cd "$(dirname "$0")" && pwd)/tools.sh"

attempts=3
# Seconds between the imports (the tests set 0).
pause="${IMPORT_PAUSE:-5}"

missing="$*"
attempt=1
while :; do
  # The node decides whether the import worked, so the exit code of k3d is not checked.
  # shellcheck disable=SC2086 # the names have no spaces
  k3d image import -c "$cluster" $missing >/dev/null || true
  still=""
  for image in $missing; do
    docker exec "$node" crictl inspecti "$image" >/dev/null 2>&1 || still="${still:+$still }$image"
  done
  [ -n "$still" ] || exit 0
  if [ "$attempt" -ge "$attempts" ]; then
    echo "import-images: not in the node $node after $attempts imports: $still" >&2
    exit 1
  fi
  echo "import-images: not in the node $node after import $attempt of $attempts, importing again: $still" >&2
  missing="$still"
  attempt=$((attempt + 1))
  sleep "$pause"
done

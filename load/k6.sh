#!/bin/sh
# Sourced by the scripts that run the k6 scripts of this directory (load/smoke.sh and infra/k8s/scripts/load.sh): the one
# place that pins the k6 image, and the way it runs. k6 runs in a container (nothing is installed on the host).
#
# Update: docker buildx imagetools inspect grafana/k6:<tag>, the tag and the digest of the index of the images.

K6_IMAGE="${K6_IMAGE:-grafana/k6:2.3.0@sha256:2dd4756ec6e14e62778916e1eb850108e4b499304c02df56760fa0bdb0fba0b8}"
LOAD_DIR="${LOAD_DIR:-$(cd "$(dirname "$0")" && pwd)}"

# k6_run <docker network> <results directory or ""> <script> [docker run options...]
# Runs /load/<script> with the directory of the scripts read-only and, when given, the results directory writable at /out.
# The settings of the script (STORE_URL, ...) are passed as `-e NAME=value` options. Exit code of k6: 0, 99 when a threshold
# failed (saturate.js ends this way on saturation), another one for a script that could not run.
k6_run() {
  k6_network="$1" k6_out="$2" k6_script="$3"
  shift 3
  # shellcheck disable=SC2086 # the options of docker are separate words
  docker run --rm -i --network "$k6_network" -u "$(id -u):$(id -g)" -e HOME=/tmp \
    -v "$LOAD_DIR:/load:ro" ${k6_out:+-v "$k6_out:/out"} "$@" "$K6_IMAGE" run --quiet "/load/$k6_script"
}

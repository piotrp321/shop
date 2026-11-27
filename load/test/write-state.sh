#!/bin/sh
# make load-logic: the test of the state of the writes scenario (load/lib/write-state.js, load/test/write-state.js) in the k6
# container of load/k6.sh, without a network and without the stack.
# Exit code: 0 when every case passes, 99 when a case failed, another one (k6 or Docker) when the test could not run.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# k6.sh takes the directory of the scripts from its caller: that is load/, not load/test/.
LOAD_DIR="$(cd "$here/.." && pwd)"
# shellcheck source=../k6.sh
. "$here/../k6.sh"

command -v docker > /dev/null 2>&1 || { echo "load-logic: docker is not installed" >&2; exit 2; }
k6_run none "" test/write-state.js
echo "load-logic: ok"

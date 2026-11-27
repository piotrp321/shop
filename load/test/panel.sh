#!/bin/sh
# make load-panel-check: the tools of the panel scenario (load/panel.sh, panel_token.py, panel_mix.py, panel_report.py, panel.js)
# on the paths of failure and on what can be computed without a stack: the one-time code against the test vector of RFC 6238,
# a wrong mode, no stack, no Keycloak, a missing token file, an API that does not answer, a missing or malformed mix file (also in k6),
# an empty directory of results, and the report on a result written by hand (median, spread, verdict). Needs python3 and
# docker (the k6 image of load/k6.sh); no stack. Exit code: 0 when every case passes, 1 when one fails.
set -u

here="$(cd "$(dirname "$0")" && pwd)"
load="$(cd "$here/.." && pwd)"
# k6.sh takes the directory of the scripts from its caller: that is load/, not load/test/.
LOAD_DIR="$load"
# shellcheck source=../k6.sh
. "$load/k6.sh"
cd "$load/.." || exit 2
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
failed=0

# expect <name> <expected exit code> <text the output must contain> -- <command...>
expect() {
  name="$1" want="$2" text="$3"
  shift 4
  output="$("$@" 2>&1)"
  code=$?
  if [ "$code" -eq "$want" ] && printf '%s' "$output" | grep -qF -- "$text"; then
    echo "ok   $name (exit $code)"
  else
    echo "FAIL $name: exit $code (wanted $want), wanted '$text' in: $(printf '%s' "$output" | tail -n 3 | tr '\n' ' ')"
    failed=1
  fi
}

# The test vector of RFC 6238 (SHA-1, the secret 12345678901234567890, 59 s): the 6 digits of 94287082.
expect "totp vector of RFC 6238" 0 "287082" -- python3 -I -c "
import sys
sys.path.insert(0, '$load')
import panel_token
print(panel_token.totp('12345678901234567890', 59))"

expect "wrong mode" 2 "the mode is w7 or p4" -- sh "$load/panel.sh" nonsense
expect "no stack" 2 "the API of the stack is not running" -- env COMPOSE_PROJECT_NAME=load-panel-check-no-such-stack sh "$load/panel.sh" w7
expect "no Keycloak" 2 "is unreachable" -- python3 -I "$load/panel_token.py" --keycloak http://127.0.0.1:9 --admin-url http://127.0.0.1:9
expect "token file missing" 2 "cannot read the token" -- python3 -I "$load/panel_mix.py" --api http://127.0.0.1:9 --token-file "$work/none.json" --out "$work/mix.json"
printf '{"access_token":"a","refresh_token":"r","token_url":"http://127.0.0.1:9/token"}' > "$work/token.json"
expect "API down" 2 "could not be renewed" -- python3 -I "$load/panel_mix.py" --api http://127.0.0.1:9 --token-file "$work/token.json" --out "$work/mix.json"
expect "report without runs" 2 "no w7 runs" -- python3 -I "$load/panel_report.py" "$work" w7
expect "report of a wrong mode" 2 "usage" -- python3 -I "$load/panel_report.py" "$work" nonsense

# The report on results written by hand: three runs of the mix, one class above the maximum.
for n in 1 2 3; do
  mkdir -p "$work/w7-run$n"
  python3 -I -c "
import json
mix = {'requests': 100, 'requests_per_second': 5, 'p50_ms': 40.0 + $n, 'p90_ms': 100, 'p95_ms': 200.0 + $n, 'p99_ms': 300, 'max_ms': 900, 'server_error_rate': 0, 'conflicts_409': 0, 'rate_limited_429': 0, 'other_4xx': 0}
slow = dict(mix, max_ms=2500.0 + $n)
json.dump({'panel_rate': 5, 'seconds': 60, 'panel': mix, 'classes': {'fast': mix, 'slow': slow}}, open('$work/w7-run$n/panel.json', 'w'))"
done
expect "report names the class above the maximum" 0 "slow: max_ms 2502 > 2000" -- python3 -I "$load/panel_report.py" "$work" w7
expect "report gives the spread of the runs" 0 "42 (41–43)" -- python3 -I "$load/panel_report.py" "$work" w7

# k6: the script stops before the first request when its input is missing or broken.
if command -v docker > /dev/null 2>&1; then
  printf '{}' > "$work/empty.json"
  k6_failure() { k6_run bridge "$work" panel.js -e STORE_URL=http://127.0.0.1:9 -e API_URL=http://127.0.0.1:9 -e KEYCLOAK_URL=http://127.0.0.1:9 "$@"; }
  expect "k6: mix file missing" 107 "/out/none.json" -- k6_failure -e PANEL_MIX=/out/none.json -e PANEL_TOKEN=/out/token.json
  expect "k6: mix without classes" 107 "no classes of requests" -- k6_failure -e PANEL_MIX=/out/empty.json -e PANEL_TOKEN=/out/token.json
else
  echo "skip k6 cases: docker is not installed"
fi

[ "$failed" -eq 0 ] || { echo "load-panel-check: some cases failed" >&2; exit 1; }
echo "load-panel-check: all cases passed"

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
# shellcheck disable=SC2034 # read by k6.sh
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

expect "wrong mode" 2 "the mode is w7, p4 or shop" -- sh "$load/panel.sh" nonsense
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

# A product that vanished between the sitemap and its read (404) is skipped by the draw of the mix; any other failure ends it (exit 2).
expect "mix: a 404 of one product is skipped, a 500 ends the draw" 2 "answered 500" -- python3 -I -c "
import sys, threading, http.server
sys.path.insert(0, '$load')
import panel_mix
class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(404 if self.path == '/gone' else 500)
        self.end_headers()
    def log_message(self, *args):
        pass
server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
api = panel_mix.Api('http://127.0.0.1:%d' % server.server_port, {'access_token': 'a', 'refresh_token': 'r', 'token_url': 'x'})
assert api.get('/gone', admin=False, missing_ok=True) is None
api.get('/broken', admin=False, missing_ok=True)"

# The report of P4 on results written by hand: the shop answers 409 to some carts of the run with the panel (and nothing else is wrong): the run has
# failed requests, so P4 is not met, and the 409 is named; a dropped request of the panel mix is a failure of W7 too.
mkdir -p "$work/p4/p4-without-run1" "$work/p4/p4-with-run1" "$work/w7dropped/w7-run1"
python3 -I -c "
import json
def fig(requests=100, **extra):
    base = {'requests': requests, 'requests_per_second': 5, 'p50_ms': 10.0, 'p90_ms': 20, 'p95_ms': 30.0, 'p99_ms': 40.0, 'max_ms': 50.0, 'server_error_rate': 0, 'conflicts_409': 0, 'rate_limited_429': 0, 'other_4xx': 0}
    base.update(extra)
    return base
def result(carts, dropped=0):
    return {'panel_rate': 5, 'seconds': 60, 'shop_listing_rate': 15, 'shop_cart_rate': 5, 'panel': fig(), 'shop_listing': fig(), 'shop_cart': carts, 'dropped': {'panel': dropped, 'shop_listing': 0, 'shop_cart': 0}, 'classes': {'fast': fig()}}
json.dump(result(fig()), open('$work/p4/p4-without-run1/panel.json', 'w'))
json.dump(result(fig(conflicts_409=3)), open('$work/p4/p4-with-run1/panel.json', 'w'))
json.dump(result(fig(), dropped=7), open('$work/w7dropped/w7-run1/panel.json', 'w'))"
expect "P4 names the 409 as a failure" 0 "with run 1 shop carts: 3 failed (5xx/net 0, 409 3" -- python3 -I "$load/panel_report.py" "$work/p4" p4
expect "P4 with failures is not met" 0 "no dropped request, in both variants) not met:" -- python3 -I "$load/panel_report.py" "$work/p4" p4
expect "W7 with a dropped request is not met" 0 "the generator did not send in time: [7]" -- python3 -I "$load/panel_report.py" "$work/w7dropped" w7
# The failures of the panel mix alone (a 400 of the mix) are listed apart and do not fail P4: the shop has none in both variants.
mkdir -p "$work/p4panel/p4-without-run1" "$work/p4panel/p4-with-run1"
python3 -I -c "
import json
def fig(requests=100, **extra):
    base = {'requests': requests, 'requests_per_second': 5, 'p50_ms': 10.0, 'p90_ms': 20, 'p95_ms': 30.0, 'p99_ms': 40.0, 'max_ms': 50.0, 'server_error_rate': 0, 'conflicts_409': 0, 'rate_limited_429': 0, 'other_4xx': 0}
    base.update(extra)
    return base
def result(panel):
    return {'panel_rate': 5, 'seconds': 60, 'shop_listing_rate': 15, 'shop_cart_rate': 5, 'panel': panel, 'shop_listing': fig(), 'shop_cart': fig(), 'dropped': {'panel': 0, 'shop_listing': 0, 'shop_cart': 0}, 'classes': {'fast': fig()}}
json.dump(result(fig()), open('$work/p4panel/p4-without-run1/panel.json', 'w'))
json.dump(result(fig(other_4xx=2)), open('$work/p4panel/p4-with-run1/panel.json', 'w'))"
expect "P4 is not failed by the failures of the panel alone" 0 "no dropped request, in both variants) met" -- python3 -I "$load/panel_report.py" "$work/p4panel" p4
expect "P4 lists the failures of the panel apart" 0 "with run 1 panel mix: 2 failed" -- python3 -I "$load/panel_report.py" "$work/p4panel" p4

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

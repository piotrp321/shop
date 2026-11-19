"""The phase of the smoke test that runs the demo data generator through make generate and make open (scripts/smoke.sh
`check_generate`; docs/features/demo-scale/spec.md): scripts/generate.sh PROFILE=small with the word tak on its standard input, without a
file of the photo pool and without Argo CD, while requests go through the ingress without a pause (smoke/traffic.py): they get 200
before the closing, none while the shop is closed and 200 again after the opening; then the Jobs demo-data-run and load completed, the
numbers are those of the seed and of the profile and the rule shop-closed is gone. SMOKE_BREAK=demo-data: generate with a profile the
generator refuses ends with 1 and leaves the shop closed, make open refuses, generate PROFILE=seed opens the shop on the seed, and the
test fails on purpose.

The functions of the phase are cut out of smoke.sh (the script itself needs Docker and a cluster) and run against stubs of kubectl,
docker, scripts/generate.sh and scripts/open.sh, which answer by the command and log every call. smoke/traffic.py runs for real: its
verdict on the lines of the traffic, and its client against a local HTTP server. Where the phase stands in the script, and that the
lines it reads are the ones generate.sh and open.sh print, is checked on their text."""

import http.server
import importlib.util
import io
import os
import pathlib
import re
import subprocess
import tempfile
import threading
import time
import unittest

from support import REPO

K8S = REPO / "infra/k8s"
SMOKE = K8S / "scripts/smoke.sh"
TRAFFIC = K8S / "smoke/traffic.py"
FUNCTIONS = ("kc", "count", "job_states", "job_has", "statefulsets_in_rollout", "not_settled", "wait_settled", "ingress_code",
             "check_shop", "check_counts", "mark", "node_free_gb", "run_demo", "traffic_start", "traffic_stop",
             "check_generate_break", "check_generate")


def traffic():
    """smoke/traffic.py as a module."""
    spec = importlib.util.spec_from_file_location("smoke_traffic", TRAFFIC)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


# The lines of a run of the traffic that make generate closed and make open opened as they must.
GOOD = """0.0 200
0.3 200
0.6 200
1.2 error:ConnectionResetError
1.5 502
2.0 mark:closed
2.4 502
3.1 error:ConnectionRefusedError
4.0 502
5.0 mark:checks
5.5 502
6.0 200
6.2 mark:open
6.5 200
7.0 mark:stop
"""


class TrafficVerdict(unittest.TestCase):
    def verdict(self, text):
        return traffic().verdict(text.splitlines())

    def assert_fails(self, text, *words):
        ok, report = self.verdict(text)
        self.assertFalse(ok, report)
        joined = "\n".join(report)
        for word in words:
            self.assertIn(word, joined)

    def test_200_before_the_closing_none_while_closed_and_200_after_the_opening_pass(self):
        ok, report = self.verdict(GOOD)
        self.assertTrue(ok, report)
        joined = "\n".join(report)
        self.assertIn("3 answers 200 before the closing", joined)
        self.assertIn("0 answers 200", joined)
        self.assertIn("502", joined)
        self.assertIn("ConnectionRefusedError", joined)
        self.assertIn("after the opening", joined)

    def test_an_answer_200_while_the_shop_is_closed_fails(self):
        """A connection opened before the rule keeps getting 200 unless Traefik is restarted."""
        self.assert_fails(GOOD.replace("3.1 error:ConnectionRefusedError", "3.1 200"), "200", "closed")
        self.assert_fails(GOOD.replace("2.4 502", "2.4 200"), "200", "closed")

    def test_200_between_the_checks_and_the_opening_is_allowed(self):
        """make open deletes the rule after its checks and before its probe says open: 200 may come before the marker open."""
        ok, report = self.verdict(GOOD)
        self.assertTrue(ok, report)
        self.assertIn("6.0 200\n6.2 mark:open", GOOD)

    def test_no_200_before_the_closing_fails(self):
        self.assert_fails(GOOD.replace("0.0 200\n0.3 200\n0.6 200\n", "0.0 502\n"), "before the closing")

    def test_no_200_after_the_opening_fails(self):
        self.assert_fails(GOOD.replace("6.5 200", "6.5 502"), "after the opening")

    def test_no_request_while_the_shop_is_closed_fails(self):
        self.assert_fails("0.0 200\n2.0 mark:closed\n5.0 mark:checks\n6.2 mark:open\n6.5 200\n", "no request")

    def test_a_missing_marker_fails(self):
        for marker in ("closed", "checks", "open"):
            with self.subTest(marker):
                self.assert_fails(re.sub(rf"^[0-9.]+ mark:{marker}\n", "", GOOD, flags=re.M), f"mark:{marker}")

    def test_markers_out_of_order_fail(self):
        text = GOOD.replace("2.0 mark:closed", "2.0 mark:checkz").replace("5.0 mark:checks", "5.0 mark:closed").replace(
            "mark:checkz", "mark:checks")
        self.assert_fails(text, "order")

    def test_a_pause_between_two_answers_fails(self):
        """Without a pause: a gap longer than the limit means the requests did not go on through the closing."""
        self.assert_fails(GOOD.replace("4.0 502", "44.0 502").replace("5.0 mark:checks", "45.0 mark:checks").replace(
            "5.5 502", "45.5 502").replace("6.0 200", "46.0 200").replace("6.2 mark:open", "46.2 mark:open").replace(
            "6.5 200", "46.5 200").replace("7.0 mark:stop", "47.0 mark:stop"), "pause")

    def test_a_line_that_is_neither_an_answer_nor_a_marker_fails(self):
        for line in ("garbage", "1.0", "x 200", "1.0 20", "1.0 mark:"):
            with self.subTest(line):
                self.assert_fails(GOOD + line + "\n", "line")

    def test_no_lines_fail(self):
        self.assert_fails("", "mark:closed")

    def test_the_command_line_reads_the_standard_input_and_exits_1_on_a_failure(self):
        for text, code in ((GOOD, 0), (GOOD.replace("6.5 200", "6.5 502"), 1)):
            with self.subTest(code=code):
                result = subprocess.run(["python3", "-I", str(TRAFFIC), "verdict"], input=text, capture_output=True, text=True,
                                        timeout=30)
                self.assertEqual(code, result.returncode, result.stdout + result.stderr)
                self.assertIn("traffic", result.stdout + result.stderr)


class Server:
    """A local HTTP/1.1 server with keep-alive: the answers come from `codes` (the last one repeats); `connections` counts the TCP
    connections it accepted."""

    def __init__(self, codes, delay=0.0):
        server = self
        self.codes = list(codes)
        self.delay = delay
        self.connections = 0
        self.requests = []

        class Handler(http.server.BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def setup(self):
                server.connections += 1
                super().setup()

            def do_GET(self):
                server.requests.append((self.path, self.headers.get("Host")))
                time.sleep(server.delay)
                code = server.codes.pop(0) if len(server.codes) > 1 else server.codes[0]
                body = b"ok"
                self.send_response(code)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args):
                pass

        self.httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.address = f"127.0.0.1:{self.httpd.server_address[1]}"
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def close(self):
        self.httpd.shutdown()
        self.httpd.server_close()


class TrafficRun(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = pathlib.Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def marker(self, name, age=0.0):
        path = self.dir / f"mark-{name}"
        path.write_text("")
        moment = time.time() - age
        os.utime(path, (moment, moment))

    def run_client(self, address, limit=10.0, grace=5.0):
        out = io.StringIO()
        started = time.monotonic()
        traffic().run(address, "api.localhost", "/health", str(self.dir), limit, out=out, grace=grace, pause=0.01)
        return out.getvalue().splitlines(), time.monotonic() - started

    def test_the_requests_go_one_after_another_over_one_keep_alive_connection(self):
        server = Server([502, 502, 502, 502, 502, 200])
        self.addCleanup(server.close)
        self.marker("open", age=2)
        self.marker("stop", age=1)
        lines, _ = self.run_client(server.address)
        answers = [line.split()[1] for line in lines if "mark:" not in line]
        self.assertEqual(["502"] * 5 + ["200"], answers, lines)
        self.assertEqual(1, server.connections)
        self.assertEqual({("/health", "api.localhost")}, set(server.requests))
        for line in lines:
            self.assertRegex(line, r"^[0-9]+\.[0-9] (mark:[a-z]+|[0-9]{3}|error:[A-Za-z]+)$")

    def test_the_markers_are_printed_once_in_the_order_they_appeared(self):
        server = Server([200])
        self.addCleanup(server.close)
        # By name, checks would come before closed; they appeared the other way round.
        self.marker("closed", age=4)
        self.marker("checks", age=3)
        self.marker("open", age=2)
        self.marker("stop", age=1)
        lines, _ = self.run_client(server.address)
        marks = [line.split()[1] for line in lines if "mark:" in line]
        self.assertEqual(["mark:closed", "mark:checks", "mark:open", "mark:stop"], marks)

    def test_a_marker_that_appears_while_a_request_waits_is_printed_before_its_answer(self):
        """Through the closed shop Traefik answers 502 only after about a second (it cannot reach the backend), and make open deletes
        the rule about a second after the line of the marker checks: the answer 200 of a request sent before that line came after
        the opening and must follow the marker (smoke test of 2026-11-19: '68.2 200' before '68.4 mark:checks')."""
        server = Server([200], delay=1.5)
        self.addCleanup(server.close)
        threading.Timer(0.5, self.marker, ("checks",)).start()
        threading.Timer(2.5, self.marker, ("open",)).start()
        threading.Timer(2.6, self.marker, ("stop",)).start()
        lines, _ = self.run_client(server.address, limit=10)
        first_answer = next(i for i, line in enumerate(lines) if "mark:" not in line)
        self.assertLess(lines.index(next(line for line in lines if line.endswith("mark:checks"))), first_answer, lines)
        checks_at = float(next(line for line in lines if line.endswith("mark:checks")).split()[0])
        self.assertLess(checks_at, 1.2, lines)

    def test_after_the_marker_stop_it_ends_with_the_first_200_after_the_opening(self):
        server = Server([502, 502, 200, 200, 200])
        self.addCleanup(server.close)
        self.marker("open", age=2)
        self.marker("stop", age=1)
        lines, _ = self.run_client(server.address)
        self.assertEqual("200", lines[-1].split()[1], lines)
        self.assertEqual(3, len([line for line in lines if "mark:" not in line]), lines)

    def test_without_a_200_after_the_opening_it_ends_after_the_grace(self):
        server = Server([502])
        self.addCleanup(server.close)
        self.marker("open", age=2)
        self.marker("stop", age=1)
        lines, took = self.run_client(server.address, limit=30, grace=0.5)
        self.assertLess(took, 10)
        self.assertNotIn("200", [line.split()[1] for line in lines])

    def test_a_server_that_does_not_answer_gives_error_lines_until_the_limit(self):
        server = Server([200])
        address = server.address
        server.close()
        lines, took = self.run_client(address, limit=1.0)
        self.assertLess(took, 10)
        self.assertTrue(lines)
        for line in lines:
            self.assertRegex(line, r"^[0-9]+\.[0-9] error:[A-Za-z]+$")


# One line per call in $STUB_LOG (kubectl and docker). The CronJob demo-data has the command bin/generate-demo --profile=$STUB_PROFILE;
# the StatefulSet elasticsearch is in a rolling update for the first STUB_ROLLING reads; a Job backfill is active for the first STUB_ACTIVE
# reads of the Jobs, then complete; the Jobs that generate made are the lines of $STUB_DIR/jobs; the rule shop-closed exists while
# $STUB_DIR/rule does; the database has the numbers of $STUB_DIR/db-*; the kubelet reports STUB_FREE free bytes (none when "fail"). Every
# read of the kind STUB_FAILS (statefulsets, deployments or jobs) fails. STUB_RATE_LIMIT adds a running Job of the CronJob rate-limit-check.
# The Jobs come as `<name> <conditions>` lines (jsonpath) or as JSON (-o json) with the conditions and the owner.
KUBECTL = r"""#!/bin/sh
printf 'kubectl %s\n' "$*" >> "$STUB_LOG"
case "$*" in *"get ${STUB_FAILS:-none} "*) echo "The connection to the server was refused" >&2; exit 1 ;; esac
reads() { n=$(( $(cat "$STUB_DIR/$1-reads" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$STUB_DIR/$1-reads"; echo "$n"; }
case "$*" in
  *"get cronjob demo-data"*command*) printf 'bin/generate-demo --profile=%s --force-reset' "${STUB_PROFILE:-small}" ;;
  *"patch cronjob demo-data"*) touch "$STUB_DIR/patched" ;;
  *"get statefulsets"*)
    n="$(reads sts)"
    echo "postgres 1 1 1 1 1 postgres-1 postgres-1"
    if [ "${STUB_ROLLING:-0}" = always ] || [ "$n" -le "${STUB_ROLLING:-0}" ]; then
      echo "elasticsearch 3 3 1 0 0 elasticsearch-r3 elasticsearch-r4"
    else
      echo "elasticsearch 3 3 1 1 1 elasticsearch-r4 elasticsearch-r4"
    fi ;;
  *"get deployments"*) echo "api 2 2 1 1 1"; echo "store 2 2 1 1 1" ;;
  *"get jobs"*)
    n="$(reads jobs)"
    {
      echo "initialize-r4 Failed"
      if [ "${STUB_ACTIVE:-0}" = always ] || [ "$n" -le "${STUB_ACTIVE:-0}" ]; then echo "backfill-r4 "; else echo "backfill-r4 Complete"; fi
      cat "$STUB_DIR/jobs" 2>/dev/null || true
      [ -z "${STUB_RATE_LIMIT:-}" ] || echo "rate-limit-check-29000000 "
    } > "$STUB_DIR/job-lines"
    case "$*" in
      *"-o json") awk 'BEGIN { printf "{\"items\": [" }
        NF { c = ""; for (i = 2; i <= NF; i++) c = c (c == "" ? "" : ", ") "{\"type\": \"" $i "\", \"status\": \"True\"}"
             o = ($1 ~ /^rate-limit-check-/) ? "{\"kind\": \"CronJob\", \"name\": \"rate-limit-check\"}" : ""
             printf "%s{\"metadata\": {\"name\": \"%s\", \"ownerReferences\": [%s]}, \"status\": {\"conditions\": [%s]}}", (k++ ? ", " : ""), $1, o, c }
        END { print "]}" }' "$STUB_DIR/job-lines" ;;
      *) cat "$STUB_DIR/job-lines" ;;
    esac ;;
  *"get networkpolicy shop-closed"*) [ ! -e "$STUB_DIR/rule" ] || echo "networkpolicy.networking.k8s.io/shop-closed" ;;
  *"get --raw"*stats/summary*)
    [ "${STUB_FREE:-}" != fail ] || { echo "the server is unavailable" >&2; exit 1; }
    printf '{"node": {"fs": {"availableBytes": %s}}}' "${STUB_FREE:-52345678901}" ;;
  *"get service traefik"*) printf '30080' ;;
  *"FROM catalog_product"*) cat "$STUB_DIR/db-products" ;;
  *"FROM ordering_order"*) cat "$STUB_DIR/db-orders" ;;
esac
exit 0
"""

# docker: a request through the ingress with curl answers the code of $STUB_DIR/ingress; the client of the traffic (smoke/traffic.py
# run) waits for the marker stop in $STUB_TRAFFIC_DIR and prints $STUB_DIR/traffic (the lines of a run).
DOCKER = r"""#!/bin/sh
printf 'docker %s\n' "$*" >> "$STUB_LOG"
case "$*" in
  *"traffic.py run"*)
    i=0
    while [ ! -e "$STUB_TRAFFIC_DIR/mark-stop" ] && [ "$i" -lt 100 ]; do sleep 0.1; i=$((i + 1)); done
    cat "$STUB_DIR/traffic" ;;
  *"http_code"*) cat "$STUB_DIR/ingress" ;;
esac
exit 0
"""

# The environment that both stubs below write to $STUB_DIR/<name>-<n>.env, with the answer on the standard input.
SAVE_ENV = r"""
answer=""
IFS= read -r answer || true
n=$(( $(cat "$STUB_DIR/runs" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$STUB_DIR/runs"
{
  echo "answer=$answer"
  for variable in PROFILE KUBE_DIR KUBE_NETWORK KUBE_NAMESPACE GENERATE_PROBE_URL GENERATE_PROBE_HOST DEMO_PHOTOS_ENV_FILE; do
    echo "$variable=$(printenv "$variable")"
  done
} > "$STUB_DIR/$NAME-$n.env"
jobs_without() { sed "/^$1/d" "$STUB_DIR/jobs" > "$STUB_DIR/jobs.new" 2>/dev/null || true; mv "$STUB_DIR/jobs.new" "$STUB_DIR/jobs"; }
"""

# scripts/generate.sh: the lines generate.sh and open.sh print, and what they leave, as STUB_GENERATE_<n> says for the n-th run of a
# script of the phase: ok (the Jobs demo-data-run and load-<n> as STUB_RUN and STUB_LOAD say, the numbers, the rule gone unless
# STUB_RULE_STAYS, the ingress STUB_OPEN_INGRESS) or failed (demo-data-run as STUB_FAILED_RUN says, the rule there unless STUB_NO_RULE,
# the ingress STUB_FAILED_INGRESS, exit 1).
GENERATE = "#!/bin/sh\nNAME=generate\n" + SAVE_ENV + r"""
printf 'generate %s\n' "$PROFILE" >> "$STUB_LOG"
outcome="$(printenv "STUB_GENERATE_$n" || echo ok)"
echo "  - the free space: k3d-node 52.35 GB (needed: 2 GB (demoData.minFreeGb))"
[ -n "${STUB_NO_RULE:-}" ] || touch "$STUB_DIR/rule"
echo "generate: the shop is closed: http://node:30080/ (Host api.localhost): closed (502)"
jobs_without demo-data-run
echo "generate: the Job demo-data-run runs (its log and the metrics go to Grafana Cloud); this target only waits"
case "$outcome" in
  ok)
    echo "demo-data-run ${STUB_RUN:-Complete}" >> "$STUB_DIR/jobs"
    echo "generate: the Job demo-data-run completed"
    echo "load-$n ${STUB_LOAD:-Complete}" >> "$STUB_DIR/jobs"
    echo "open: ok: the Job load-$n of the CronJob load passed"
    if [ "$PROFILE" = seed ]; then products="${STUB_SEED_PRODUCTS:-40}" orders="${STUB_SEED_ORDERS:-1}"
    else products="${STUB_PRODUCTS:-2040}" orders="${STUB_ORDERS:-5001}"; fi
    echo "$products" > "$STUB_DIR/db-products"
    echo "$orders" > "$STUB_DIR/db-orders"
    echo "open: $products products and $orders orders; the sizes:"
    echo "== postgres"
    [ -n "${STUB_RULE_STAYS:-}" ] || rm -f "$STUB_DIR/rule"
    echo "${STUB_OPEN_INGRESS:-200}" > "$STUB_DIR/ingress"
    echo "open: the shop is open: http://node:30080/ (Host api.localhost): open (200)"
    exit 0 ;;
  failed)
    echo "demo-data-run ${STUB_FAILED_RUN:-Failed}" >> "$STUB_DIR/jobs"
    echo "generate: FAILED: the Job demo-data-run did not complete: exit 1: bin/generate-demo --profile=$PROFILE ended with 1" >&2
    echo "${STUB_FAILED_INGRESS:-502}" > "$STUB_DIR/ingress"
    exit 1 ;;
esac
"""

# scripts/open.sh: refuses (exit 1, nothing changed) unless STUB_OPEN=opens, which deletes the rule, opens the ingress and ends with 0.
OPEN = "#!/bin/sh\nNAME=open\n" + SAVE_ENV + r"""
printf 'open\n' >> "$STUB_LOG"
if [ "${STUB_OPEN:-}" = opens ]; then
  rm -f "$STUB_DIR/rule"
  echo 200 > "$STUB_DIR/ingress"
  echo "open: the shop is open: http://node:30080/ (Host api.localhost): open (200)"
  exit 0
fi
echo "open: the Job demo-data-run is Failed, not Complete (make status): make -C infra/k8s generate PROFILE=<profile> again, or PROFILE=seed" >&2
echo "open: nothing was changed" >&2
exit 1
"""


def cut_out(name, text):
    """The definition of the shell function `name` of smoke.sh (a one-line or a block that ends with a line `}`)."""
    lines = text.splitlines()
    for start, line in enumerate(lines):
        if line.startswith(f"{name}() {{"):
            if line.rstrip().endswith("}") and line.count("{") == line.count("}"):
                return line
            end = next(i for i in range(start + 1, len(lines)) if lines[i] == "}")
            return "\n".join(lines[start : end + 1])
    raise AssertionError(f"smoke.sh has no function {name}")


class Phase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self.tmp.name)
        for directory in ("bin", "scripts", "work", "kube"):
            (self.base / directory).mkdir()
        for name, text in (("bin/kubectl", KUBECTL), ("bin/docker", DOCKER), ("scripts/generate.sh", GENERATE),
                           ("scripts/open.sh", OPEN)):
            path = self.base / name
            path.write_text(text)
            path.chmod(0o755)
        (self.base / "kube/config").write_text("")
        self.log = self.base / "calls.log"
        self.log.write_text("")
        (self.base / "jobs").write_text("")
        (self.base / "ingress").write_text("200\n")
        (self.base / "db-products").write_text("0\n")
        (self.base / "db-orders").write_text("3\n")
        (self.base / "traffic").write_text(GOOD)
        self.home = self.base / "home"
        self.home.mkdir()

    def tearDown(self):
        self.tmp.cleanup()

    def fresh(self):
        """New stubs for the next case of a loop; the directory of the case before is removed."""
        self.tearDown()
        self.setUp()

    def run_phase(self, **env):
        text = SMOKE.read_text()
        work = self.base / "work"
        prelude = [
            "set -eu",
            "namespace=shop",
            'SMOKE_BREAK="${SMOKE_BREAK:-}"',
            f"here={self.base / 'scripts'}",
            f"work={work}",
            f"KUBE_DIR={self.base / 'kube'}",
            "KUBE_NETWORK=k3d-sm-test",
            "cluster=sm-test",
            "node=k3d-sm-test-server-0",
            "CURL_IMAGE=curlimages/curl:test",
            "PYTHON_IMAGE=python:test",
            "traffic_pid=''",
            'products_at_backup="${STUB_SEED_COUNT-40}"',
            # The tools of tools.sh: docker run in place of a container of the repository, python3 in place of py, the numbers of
            # the profile small for yq.
            'tools_run() { docker run "$@"; }',
            'py() { python3 -I "$@"; }',
            "yq() { case \"$*\" in *small.products*) echo 2000 ;; *small.orders*) echo 5000 ;; *) echo \"yq: $*\" >&2; return 1 ;; esac; }",
            # smoke.sh sources it (active_jobs, as make generate counts the active Jobs).
            f". {K8S / 'scripts/shop-closed.sh'}",
        ]
        script = "\n".join(prelude + [cut_out(n, text) for n in FUNCTIONS] + ["check_generate"])
        variables = {
            "PATH": f"{self.base / 'bin'}:{os.environ['PATH']}",
            "HOME": str(self.home),
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.base),
            "STUB_TRAFFIC_DIR": str(work / "generate/traffic"),
            "SMOKE_POLL": "0",
            **env,
        }
        return subprocess.run(["sh", "-c", script], env=variables, capture_output=True, text=True, timeout=120, cwd=str(K8S))

    def calls(self):
        return self.log.read_text().splitlines()

    def runs(self):
        """The runs of generate.sh and open.sh, in order: `generate <PROFILE>` and `open`."""
        return [call for call in self.calls() if call.startswith("generate ") or call == "open"]

    def env(self, name, n):
        path = self.base / f"{name}-{n}.env"
        return dict(line.split("=", 1) for line in path.read_text().splitlines())

    # --- the run without a break -------------------------------------------------------------------------------------

    def test_generate_runs_once_with_the_profile_small_and_the_phase_passes(self):
        result = self.run_phase()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(["generate small"], self.runs(), self.calls())
        # The output of generate.sh (and of open.sh, which it runs) is in the log of the test.
        self.assertIn("open: the shop is open:", result.stdout)
        self.assertIn("ok: make generate PROFILE=small", result.stdout)

    def test_generate_gets_tak_on_its_standard_input_and_the_variables_of_the_cluster(self):
        """smoke.sh runs scripts/generate.sh itself (the guard of AO is a step of the target, not of the script), so it gives the
        word tak, the kubeconfig of the test cluster and the addresses of the ingress (the node port of Traefik, with the hosts)."""
        self.run_phase()
        env = self.env("generate", 1)
        self.assertEqual("tak", env["answer"])
        self.assertEqual("small", env["PROFILE"])
        self.assertEqual(str(self.base / "kube"), env["KUBE_DIR"])
        self.assertEqual("k3d-sm-test", env["KUBE_NETWORK"])
        self.assertEqual("shop", env["KUBE_NAMESPACE"])
        urls = env["GENERATE_PROBE_URL"].split()
        hosts = env["GENERATE_PROBE_HOST"].split()
        self.assertEqual(len(urls), len(hosts))
        self.assertEqual({"api.localhost", "localhost"}, set(hosts))
        for url in urls:
            self.assertTrue(url.startswith("http://k3d-sm-test-server-0:30080/"), url)

    def test_generate_never_reads_a_file_of_the_photo_pool(self):
        """As in CI and deploy.yml: no file of the photo pool; DEMO_PHOTOS_ENV_FILE names a file that is not there, never the one
        of the user."""
        self.run_phase()
        photos = self.env("generate", 1)["DEMO_PHOTOS_ENV_FILE"]
        self.assertTrue(photos)
        self.assertFalse(pathlib.Path(photos).exists())
        self.assertNotIn(".config/shop", photos)

    def test_the_free_space_of_the_node_is_printed_before_generate_and_the_time_of_the_phase_at_its_end(self):
        result = self.run_phase()
        self.assertIn("the free space of the node k3d-sm-test-server-0 before make generate: 52.35 GB", result.stdout)
        self.assertLess(result.stdout.index("52.35 GB"), result.stdout.index("generate: the shop is closed"))
        calls = self.calls()
        raw = next(i for i, call in enumerate(calls) if "stats/summary" in call)
        self.assertLess(raw, calls.index("generate small"))
        self.assertRegex(result.stdout, r"the generator phase took [0-9]+ s \(make generate itself [0-9]+ s\)\n$")

    def test_a_free_space_that_cannot_be_read_fails_the_phase_without_generate(self):
        result = self.run_phase(STUB_FREE="fail")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("free space", result.stderr)
        self.assertEqual([], self.runs())

    def test_the_traffic_runs_through_the_ingress_while_generate_runs_and_its_verdict_is_printed(self):
        result = self.run_phase()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        calls = self.calls()
        start = next(i for i, call in enumerate(calls) if "traffic.py run" in call)
        self.assertLess(start, calls.index("generate small"))
        client = calls[start]
        self.assertIn("--network k3d-sm-test", client)
        self.assertIn("k3d-sm-test-server-0:30080 api.localhost /health", client)
        self.assertIn("3 answers 200 before the closing", result.stdout)

    def test_the_lines_of_generate_and_open_become_the_markers_of_the_traffic(self):
        self.run_phase()
        traffic_dir = self.base / "work/generate/traffic"
        for marker in ("closed", "checks", "open", "stop"):
            self.assertTrue((traffic_dir / f"mark-{marker}").exists(), marker)

    def test_an_answer_200_while_the_shop_is_closed_fails_the_phase(self):
        (self.base / "traffic").write_text(GOOD.replace("3.1 error:ConnectionRefusedError", "3.1 200"))
        result = self.run_phase()
        self.assertNotEqual(0, result.returncode)
        self.assertIn("while the shop was closed", result.stderr)

    def test_no_200_after_the_opening_fails_the_phase(self):
        (self.base / "traffic").write_text(GOOD.replace("6.5 200", "6.5 502"))
        result = self.run_phase()
        self.assertNotEqual(0, result.returncode)
        self.assertIn("after the opening", result.stderr)

    def test_generate_that_fails_fails_the_phase(self):
        result = self.run_phase(STUB_GENERATE_1="failed")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("make generate PROFILE=small ended with 1, not 0", result.stderr)
        self.assertEqual(["generate small"], self.runs(), "no second run without the break")

    def test_a_job_of_the_generator_or_of_load_that_did_not_complete_fails_the_phase(self):
        for variables, job in (({"STUB_RUN": "Failed"}, "demo-data-run"), ({"STUB_LOAD": "Failed"}, "load")):
            with self.subTest(variables):
                self.fresh()
                result = self.run_phase(**variables)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(job, result.stderr)
                self.assertIn("Complete", result.stderr)

    def test_numbers_other_than_those_of_the_seed_and_the_profile_fail_the_phase(self):
        """small: the 40 products of the seed and 2000, 5000 orders and the one of the checkout of the Job load."""
        for variables in ({"STUB_PRODUCTS": "2000"}, {"STUB_PRODUCTS": "2041"}, {"STUB_ORDERS": "5000"}, {"STUB_ORDERS": "5002"},
                          {"STUB_PRODUCTS": " "}, {"STUB_ORDERS": "error"}):
            with self.subTest(variables):
                self.fresh()
                result = self.run_phase(**variables)
                self.assertNotEqual(0, result.returncode)
                self.assertIn("not 2040 and 5001", result.stderr)

    def test_without_the_number_of_the_seed_only_the_orders_are_compared(self):
        """SMOKE_REUSE starts after the backup, so the number of products of the seed is not known."""
        result = self.run_phase(STUB_SEED_COUNT="", STUB_PRODUCTS="2077")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.fresh()
        result = self.run_phase(STUB_SEED_COUNT="", STUB_ORDERS="5000")
        self.assertNotEqual(0, result.returncode)

    def test_the_rule_that_stays_after_generate_fails_the_phase(self):
        result = self.run_phase(STUB_RULE_STAYS="1")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("shop-closed is still there", result.stderr)

    def test_no_generate_for_another_profile_of_the_cronjob_than_small(self):
        result = self.run_phase(STUB_PROFILE="medium")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("--profile=small", result.stderr)
        self.assertEqual([], self.runs())

    def test_generate_starts_only_after_the_statefulsets_jobs_and_deployments_settled(self):
        """The upgrade r4 before the phase ends with the failed initialize while Elasticsearch is still replacing its pod and other
        Jobs of the revision may run: make generate refuses an active Job and a workload that is not ready."""
        result = self.run_phase(STUB_ROLLING="2", STUB_ACTIVE="3")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        calls = self.calls()
        first = calls.index("generate small")
        reads = [i for i, call in enumerate(calls) if "get jobs" in call]
        self.assertGreaterEqual(len([i for i in reads if i < first]), 4, calls)
        self.assertIn("waiting", result.stdout)

    def test_a_cluster_that_does_not_settle_in_time_fails_the_phase_without_generate(self):
        for variables in ({"STUB_ROLLING": "always"}, {"STUB_ACTIVE": "always"}):
            with self.subTest(variables):
                self.fresh()
                result = self.run_phase(SMOKE_TIMEOUT="0", **variables)
                self.assertNotEqual(0, result.returncode)
                self.assertIn("not settled", result.stderr)
                self.assertEqual([], self.runs())

    def test_a_job_of_the_cronjob_rate_limit_check_does_not_hold_the_phase(self):
        """The phase counts the active Jobs as make generate does (active_jobs of scripts/shop-closed.sh): the Job of rate-limit-check
        (every five minutes, at most a minute) does not keep it waiting."""
        result = self.run_phase(STUB_RATE_LIMIT="1", SMOKE_TIMEOUT="0")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(["generate small"], self.runs())

    def test_workloads_or_jobs_that_cannot_be_read_are_never_taken_for_settled(self):
        """A failed read is never a settled cluster: make generate does not run and the phase fails."""
        for kind, what in (("statefulsets", "StatefulSets"), ("deployments", "Deployments"), ("jobs", "Jobs")):
            with self.subTest(kind):
                self.fresh()
                result = self.run_phase(STUB_FAILS=kind, SMOKE_TIMEOUT="0")
                self.assertNotEqual(0, result.returncode)
                self.assertIn("not settled", result.stderr)
                self.assertIn(f"(cannot read the {what})", result.stderr)
                self.assertEqual([], self.runs())

    def test_without_the_break_the_cronjob_is_not_patched(self):
        self.run_phase()
        self.assertEqual([], [call for call in self.calls() if "patch" in call])

    # --- SMOKE_BREAK=demo-data -----------------------------------------------------------------------------------------

    def run_break(self, **env):
        return self.run_phase(SMOKE_BREAK="demo-data", STUB_GENERATE_1="failed", **env)

    def test_the_break_fails_generate_open_refuses_the_seed_opens_and_the_test_fails_on_purpose(self):
        result = self.run_break()
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertEqual(["generate smoke-unknown", "open", "generate seed"], self.runs(), self.calls())
        self.assertIn("on purpose", result.stderr.splitlines()[-1])
        self.assertIn("502", result.stdout)
        self.assertIn("demo-data-run failed", result.stdout)
        self.assertIn("make open refused", result.stdout)
        self.assertIn("40 products of the seed", result.stdout)
        self.assertRegex(result.stdout, r"the generator phase took [0-9]+ s")
        for name, n in (("generate", 1), ("open", 2), ("generate", 3)):
            if name == "generate":
                self.assertEqual("tak", self.env(name, n)["answer"], n)
            self.assertEqual("k3d-sm-test", self.env(name, n)["KUBE_NETWORK"])
            self.assertEqual(self.env("generate", 1)["GENERATE_PROBE_URL"], self.env(name, n)["GENERATE_PROBE_URL"])

    def test_the_break_gives_the_generator_a_profile_it_refuses_before_generate(self):
        self.run_break()
        calls = self.calls()
        patches = [call for call in calls if "patch cronjob demo-data" in call]
        self.assertEqual(1, len(patches), calls)
        self.assertIn("--profile=smoke-unknown", patches[0])
        self.assertLess(calls.index(patches[0]), calls.index("generate smoke-unknown"))

    def test_the_break_with_generate_that_ends_with_0_fails_without_the_next_steps(self):
        result = self.run_phase(SMOKE_BREAK="demo-data")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("ended with 0, not 1", result.stderr)
        self.assertEqual(["generate smoke-unknown"], self.runs())

    def test_the_break_that_leaves_the_shop_open_fails_without_the_next_steps(self):
        for variables, words in (({"STUB_FAILED_INGRESS": "503"}, "not 502"), ({"STUB_FAILED_INGRESS": "200"}, "not 502"),
                                 ({"STUB_FAILED_INGRESS": "000"}, "not 502"), ({"STUB_NO_RULE": "1"}, "no NetworkPolicy shop-closed")):
            with self.subTest(variables):
                self.fresh()
                result = self.run_break(**variables)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(words, result.stderr)
                self.assertEqual(["generate smoke-unknown"], self.runs())

    def test_the_break_with_a_job_of_the_generator_that_did_not_fail_fails(self):
        result = self.run_break(STUB_FAILED_RUN="Complete")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("demo-data-run", result.stderr)
        self.assertEqual(["generate smoke-unknown"], self.runs())

    def test_the_break_with_make_open_that_opens_the_shop_fails_without_the_seed(self):
        result = self.run_break(STUB_OPEN="opens")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("make open", result.stderr)
        self.assertEqual(["generate smoke-unknown", "open"], self.runs())
        self.assertNotIn("on purpose", result.stderr)

    def test_the_break_with_a_failed_seed_fails(self):
        result = self.run_break(STUB_GENERATE_3="failed")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("make generate PROFILE=seed ended with 1, not 0", result.stderr)
        self.assertNotIn("on purpose", result.stderr)

    def test_the_break_with_a_shop_that_does_not_answer_after_the_seed_fails(self):
        result = self.run_break(STUB_OPEN_INGRESS="502")
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn("on purpose", result.stderr)

    def test_the_break_with_data_other_than_the_seed_after_the_seed_fails(self):
        for variables in ({"STUB_SEED_PRODUCTS": "2040"}, {"STUB_SEED_ORDERS": "5001"}, {"STUB_SEED_ORDERS": "0"}):
            with self.subTest(variables):
                self.fresh()
                result = self.run_break(**variables)
                self.assertNotEqual(0, result.returncode)
                self.assertIn("not 40 and 1", result.stderr)
                self.assertNotIn("on purpose", result.stderr)


class Script(unittest.TestCase):
    text = SMOKE.read_text()

    def test_the_phase_runs_once_after_every_phase_that_reads_the_data_of_the_seed(self):
        """The generator replaces the data of the shop: it runs after the last phase that counts products or orders, and the
        phases that follow it are none (only the report of the peaks)."""
        calls = [m.start() for m in re.finditer(r"^check_generate$", self.text, re.M)]
        self.assertEqual(1, len(calls), "the phase is called once")
        last_phase = self.text.index('log "upgrade r4:')
        self.assertGreater(calls[0], last_phase)
        following = [line for line in self.text[calls[0] :].splitlines() if line.strip()]
        self.assertEqual(["check_generate", 'log "peaks"', "report_peaks"], following[:3])

    def test_the_smoke_test_never_creates_a_job_of_the_generator_itself(self):
        """Only make generate creates it (with DEMO_DATA_CONFIRM): the phase goes through scripts/generate.sh and open.sh."""
        self.assertNotIn("DEMO_DATA_CONFIRM", self.text)
        self.assertNotIn("run_cronjob_job demo-data", self.text)
        self.assertNotIn("kube-jobs.sh", self.text)
        # The tests of the phase source it as well: active_jobs, which not_settled calls, is that of make generate.
        self.assertIn('. "$here/shop-closed.sh"', self.text)
        self.assertIn('sh "$here/$2"', cut_out("run_demo", self.text))

    def test_the_lines_the_phase_reads_are_the_ones_generate_and_open_print(self):
        """The markers of the traffic come from these lines: a changed message would leave the traffic without its markers."""
        run_demo = cut_out("run_demo", self.text)
        for script, line, pattern in (
            ("generate.sh", 'echo "generate: the shop is closed: $probe_report"', '"generate: the shop is closed: "*) mark closed ;;'),
            ("open.sh", 'echo "open: ${counts%%|*} products and ${counts#*|} orders; the sizes:"',
             '"open: "*" products and "*" orders; the sizes:") mark checks ;;'),
            ("open.sh", 'echo "open: the shop is open: $probe_report"', '"open: the shop is open: "*) mark open ;;'),
        ):
            with self.subTest(line):
                self.assertIn(line, (K8S / "scripts" / script).read_text())
                self.assertIn(pattern, run_demo)

    def test_the_client_of_the_traffic_is_stopped_on_every_exit(self):
        self.assertIn("traffic", cut_out("cleanup", self.text))

    def test_the_break_is_accepted_and_documented(self):
        self.assertIn('"" | migration | image | demo-data) ;;', self.text)
        self.assertIn("SMOKE_BREAK=demo-data smoke.sh", self.text)
        self.assertIn("SMOKE_BREAK must be empty, migration, image or demo-data", self.text)

    def test_the_phases_in_the_header_name_make_generate_and_make_open(self):
        header = self.text.split("set -eu", 1)[0]
        self.assertIn("make generate PROFILE=small", header)
        self.assertIn("make open", header)
        self.assertIn("PROFILE=seed", header)


if __name__ == "__main__":
    unittest.main()

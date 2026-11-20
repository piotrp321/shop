"""The phase of the smoke test with the failures of the messages (scripts/smoke.sh `check_messaging_failures`;
docs/features/async-reliability/spec.md, W9 and the criteria 3 to 5):

  - RabbitMQ scaled to zero with the CronJob order-expiry suspended: an order past its deadline with its ready message is cancelled by
    worker-commands within two minutes; the broker comes back and every workload is ready again (the pod of `worker` is deleted, as it
    waits in a back-off);
  - a message in `failed`: a Job from the CronJob queue-check fails, a Job from order-expiry cancels the order, and after the message is
    removed a Job from queue-check succeeds.

The functions of the phase are cut out of smoke.sh (the script itself needs Docker and a cluster) and run against a stub of kubectl that
answers by the command and logs every call; `checks` (which runs in a pod) and `wait_ready` (which reads the whole cluster) are stubs of the
test. Where the phase stands in the script is checked on the text of smoke.sh. The command `place-order` of smoke/checks.py is run against
a stub of the HTTP calls."""

import importlib.util
import json
import os
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO
from test_smoke_demo_data import cut_out

SMOKE = REPO / "infra/k8s/scripts/smoke.sh"
FUNCTIONS = ("kc", "sql", "count_failed", "set_suspend", "wait_cronjob_idle", "wait_no_pods", "wait_order_status", "new_order", "overdue_order",
             "move_message", "run_cronjob_job", "expect_job_failed", "check_messaging_failures")
UUID = "00000000-0000-0000-0000-00000000000{}"

# The cluster of the phase in files of $STUB_DIR: `broker-down` (RabbitMQ scaled to zero), `failed-count` (the messages of the transport
# failed), `orders` (the numbers handed out by place-order). The scenario is the variables STUB_*: STUB_ACTIVE (an active Job of
# order-expiry), STUB_FAILED_BEFORE, STUB_BROKER_STUCK, STUB_ORDER_STATUS and STUB_ORDER2_STATUS (of part 1 and 2), STUB_MOVED, STUB_OVERDUE (rows of the statements),
# STUB_FAILED_JOB, STUB_EXPIRY_JOB, STUB_OK_JOB (what the Jobs end as: succeeded, failed, running), STUB_REMOVE_FAILS and STUB_REMOVE_KEEPS.
KUBECTL = r"""#!/bin/sh
printf '%s\n' "$*" >> "$STUB_LOG"
d="$STUB_DIR"
job_state() {
  case "$1" in succeeded) printf '1 ' ;; failed) printf ' True' ;; *) printf ' ' ;; esac
}
case "$*" in
  *"get cronjob order-expiry"*status.active*) printf '%s' "${STUB_ACTIVE:-}" ;;
  *"scale statefulset/rabbitmq --replicas=0"*) touch "$d/broker-down" ;;
  *"scale statefulset/rabbitmq --replicas=1"*) rm -f "$d/broker-down" ;;
  *"get pods -l app.kubernetes.io/name=rabbitmq"*) if [ -e "$d/broker-down" ] && [ -z "${STUB_BROKER_STUCK:-}" ]; then :; else echo "pod/rabbitmq-0"; fi ;;
  *"get job queue-check-smoke-failed"*) job_state "${STUB_FAILED_JOB:-failed}" ;;
  *"get job queue-check-smoke-ok"*) job_state "${STUB_OK_JOB:-succeeded}" ;;
  *"get job order-expiry-smoke"*) job_state "${STUB_EXPIRY_JOB:-succeeded}" ;;
  *"logs job/"*) echo "failed: 1, the oldest since 2026-11-20T10:00:00+00:00" ;;
  *"messenger:failed:remove"*)
    if [ -n "${STUB_REMOVE_FAILS:-}" ]; then echo "remove failed" >&2; exit 1; fi
    [ -n "${STUB_REMOVE_KEEPS:-}" ] || echo 0 > "$d/failed-count" ;;
  *"WITH moved AS"*"SET queue_name = 'failed'"*) echo 1 > "$d/failed-count"; echo "${STUB_MOVED:-1}" ;;
  *"WITH moved AS"*) echo "${STUB_MOVED:-1}" ;;
  *"WITH overdue AS"*) echo "${STUB_OVERDUE:-1}" ;;
  *"SELECT count(*) FROM messenger_messages WHERE queue_name = 'failed'"*) cat "$d/failed-count" 2> /dev/null || echo "${STUB_FAILED_BEFORE:-0}" ;;
  *"SELECT id FROM messenger_messages"*) echo 42 ;;
  *"SELECT id FROM ordering_order WHERE number = 'SMK-"*)
    number="${*#*number = \'SMK-}"
    echo "00000000-0000-0000-0000-00000000000${number%%\'*}" ;;
  *"SELECT status FROM ordering_order"*)
    # Part 2 is when the message has been moved to failed.
    if [ -e "$d/failed-count" ]; then echo "${STUB_ORDER2_STATUS:-cancelled}"; else echo "${STUB_ORDER_STATUS:-cancelled}"; fi ;;
esac
exit 0
"""

# The functions of the test that stand for what needs a pod or the whole cluster.
STUBS = r"""
checks() {
  printf 'checks %s\n' "$*" >> "$STUB_LOG"
  orders=$(( $(cat "$STUB_DIR/orders" 2> /dev/null || echo 0) + 1 ))
  echo "$orders" > "$STUB_DIR/orders"
  echo "SMK-$orders"
}
wait_ready() { printf 'wait_ready %s\n' "$*" >> "$STUB_LOG"; }
dump_state() { echo "dump_state" >> "$STUB_LOG"; }
"""


class Phase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self.tmp.name)
        (self.base / "bin").mkdir()
        for name, text in (("kubectl", KUBECTL), ("sleep", "#!/bin/sh\nexit 0\n")):
            (self.base / "bin" / name).write_text(text)
            (self.base / "bin" / name).chmod(0o755)
        self.log = self.base / "log"
        self.log.write_text("")

    def tearDown(self):
        self.tmp.cleanup()

    def run_phase(self, **env):
        # A fresh cluster for every run (the subtests of a test run the phase more than once).
        for name in ("broker-down", "failed-count", "orders"):
            (self.base / name).unlink(missing_ok=True)
        self.log.write_text("")
        text = SMOKE.read_text()
        script = "\n".join(["set -eu", "namespace=shop", STUBS] + [cut_out(n, text) for n in FUNCTIONS] + ["check_messaging_failures"])
        variables = {"PATH": f"{self.base / 'bin'}:{os.environ['PATH']}", "STUB_LOG": str(self.log), "STUB_DIR": str(self.base), "SMOKE_POLL": "1", **env}
        return subprocess.run(["sh", "-c", script], env=variables, capture_output=True, text=True, timeout=60)

    def calls(self):
        return self.log.read_text().splitlines()

    def index(self, fragment, start=0):
        """The position of the first call after `start` that contains the fragment (the test fails when there is none)."""
        for position, call in enumerate(self.calls()):
            if position >= start and fragment in call:
                return position
        self.fail(f"no call with '{fragment}' after {start}: {self.calls()}")

    def count(self, fragment):
        return len([call for call in self.calls() if fragment in call])

    def creates(self):
        return [call for call in self.calls() if " create job " in f" {call} "]

    # The whole phase

    def test_the_phase_goes_through_both_parts_in_order(self):
        result = self.run_phase()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("ok:", result.stdout)
        suspend = self.index('patch cronjob order-expiry --type merge -p {"spec":{"suspend":true}}')
        idle = self.index("get cronjob order-expiry", suspend)
        place = self.index("checks place-order", idle)
        down = self.index("scale statefulset/rabbitmq --replicas=0", place)
        gone = self.index("get pods -l app.kubernetes.io/name=rabbitmq", down)
        overdue = self.index("WITH overdue AS", gone)
        ready = self.index("WITH moved AS", overdue)
        cancelled = self.index("SELECT status FROM ordering_order", ready)
        up = self.index("scale statefulset/rabbitmq --replicas=1", cancelled)
        rollout = self.index("rollout status statefulset/rabbitmq", up)
        worker = self.index("delete pod -l app.kubernetes.io/name=worker", rollout)
        settled = self.index("wait_ready", worker)
        failed_message = self.index("SET queue_name = 'failed'", settled)
        failed_job = self.index("create job queue-check-smoke-failed --from=cronjob/queue-check", failed_message)
        expiry_job = self.index("create job order-expiry-smoke --from=cronjob/order-expiry", failed_job)
        removed = self.index("messenger:failed:remove 42 --force", expiry_job)
        ok_job = self.index("create job queue-check-smoke-ok --from=cronjob/queue-check", removed)
        resumed = self.index('patch cronjob order-expiry --type merge -p {"spec":{"suspend":false}}', ok_job)
        self.assertGreater(self.index("delete job", ok_job), ok_job)
        self.assertEqual(len(self.calls()) - 1, resumed, "the Jobs are removed and the CronJob resumed last")

    def test_the_cronjob_order_expiry_is_suspended_for_the_whole_phase_and_resumed_once(self):
        self.run_phase()
        patches = [call for call in self.calls() if "patch cronjob" in call]
        self.assertEqual(2, len(patches), patches)
        self.assertIn('"suspend":true', patches[0])
        self.assertIn('"suspend":false', patches[1])
        self.assertTrue(all("cronjob order-expiry" in call for call in patches))

    def test_the_two_orders_are_two_orders_and_each_has_its_own_message_moved(self):
        self.run_phase()
        self.assertEqual(2, self.count("checks place-order"))
        moves = [call for call in self.calls() if "WITH moved AS" in call]
        self.assertEqual(2, len(moves))
        self.assertIn(UUID.format(1), moves[0])
        self.assertIn("queue_name = 'scheduled'", moves[0])
        self.assertIn(UUID.format(2), moves[1])
        self.assertIn("queue_name = 'failed'", moves[1])
        # Both orders go past their deadline by hand, each before its message is moved.
        overdue = [call for call in self.calls() if "WITH overdue AS" in call]
        self.assertEqual(2, len(overdue))
        self.assertIn(UUID.format(1), overdue[0])
        self.assertIn(UUID.format(2), overdue[1])

    def test_the_orders_are_not_cancelled_by_anything_but_the_parts_under_test(self):
        """Part 1 waits for the order with the broker down and no Job of order-expiry created; the Job of order-expiry comes only in part 2."""
        self.run_phase()
        creates = self.creates()
        self.assertEqual(3, len(creates), creates)
        self.assertLess(self.index("SELECT status FROM ordering_order"), self.index("create job"))
        self.assertLess(self.index("scale statefulset/rabbitmq --replicas=1"), self.index("create job"))

    # Failures: every one fails the phase with a message that says what was expected, and stops before the next step

    def test_an_active_job_of_order_expiry_that_does_not_end_fails_before_anything_changes(self):
        result = self.run_phase(STUB_ACTIVE="order-expiry-29341234", SMOKE_IDLE_WAIT="0")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("order-expiry-29341234", result.stderr)
        self.assertEqual(0, self.count("scale statefulset"))
        self.assertEqual(0, self.count("checks place-order"))

    def test_messages_in_failed_before_the_phase_fail_it_before_the_broker_is_touched(self):
        result = self.run_phase(STUB_FAILED_BEFORE="2")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("failed", result.stderr)
        self.assertIn("2", result.stderr)
        self.assertEqual(0, self.count("scale statefulset"))
        self.assertEqual([], self.creates())

    def test_a_broker_that_does_not_stop_fails_the_phase_before_the_order_is_made_ready(self):
        result = self.run_phase(STUB_BROKER_STUCK="1", SMOKE_BROKER_WAIT="0")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("rabbitmq", result.stderr)
        self.assertEqual(0, self.count("WITH overdue AS"))

    def test_an_order_that_is_not_cancelled_without_the_broker_fails_the_phase_with_its_status(self):
        result = self.run_phase(STUB_ORDER_STATUS="new", SMOKE_EXPIRY_WAIT="0")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("'new'", result.stderr)
        self.assertIn("cancelled", result.stderr)
        self.assertEqual(0, self.count("scale statefulset/rabbitmq --replicas=1"), "the phase stops where it failed")
        self.assertEqual([], self.creates())

    def test_a_statement_that_changes_no_row_fails_the_phase(self):
        for variables, text in (({"STUB_OVERDUE": "0"}, "payment deadline"), ({"STUB_MOVED": "0"}, "message")):
            with self.subTest(variables):
                result = self.run_phase(**variables)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(text, result.stderr)
                self.assertEqual(0, self.count("scale statefulset/rabbitmq --replicas=1"), self.calls())

    def test_a_check_of_the_queues_that_succeeds_with_a_message_in_failed_fails_the_phase(self):
        result = self.run_phase(STUB_FAILED_JOB="succeeded")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("must fail", result.stderr)
        self.assertEqual(0, self.count("create job order-expiry-smoke"))
        self.assertEqual(0, self.count("messenger:failed:remove"))

    def test_a_job_of_order_expiry_that_fails_fails_the_phase_and_keeps_the_message(self):
        result = self.run_phase(STUB_EXPIRY_JOB="failed")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("order-expiry-smoke", result.stderr)
        self.assertIn("failed: 1", result.stderr, "the log of the Job is printed")
        self.assertEqual(0, self.count("messenger:failed:remove"))

    def test_an_order_that_the_job_of_order_expiry_did_not_cancel_fails_the_phase_and_keeps_the_message(self):
        result = self.run_phase(STUB_ORDER2_STATUS="new", SMOKE_EXPIRY_WAIT="0")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("'new'", result.stderr)
        self.assertEqual(0, self.count("messenger:failed:remove"))
        self.assertEqual(0, self.count("create job queue-check-smoke-ok"))

    def test_a_removal_that_fails_or_removes_nothing_fails_the_phase(self):
        for variables, text in (({"STUB_REMOVE_FAILS": "1"}, "remove failed"), ({"STUB_REMOVE_KEEPS": "1"}, "still")):
            with self.subTest(variables):
                result = self.run_phase(**variables)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(text, result.stderr)
                self.assertEqual(0, self.count("create job queue-check-smoke-ok"))

    def test_a_check_that_keeps_failing_after_the_removal_fails_the_phase_with_its_log(self):
        result = self.run_phase(STUB_OK_JOB="failed")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("queue-check-smoke-ok", result.stderr)
        self.assertIn("failed: 1", result.stderr)
        self.assertEqual(0, self.count("suspend\":false"), "the CronJob is not resumed on a failure: the cluster is deleted")


class Script(unittest.TestCase):
    text = SMOKE.read_text()

    def test_the_phase_is_called_once_after_the_installation_checks_and_before_the_backup(self):
        calls = [m.start() for m in re.finditer(r"^ *check_messaging_failures$", self.text, re.M)]
        self.assertEqual(1, len(calls))
        self.assertGreater(calls[0], self.text.index("  check_create_admin\n"))
        self.assertLess(calls[0], self.text.index('  log "backup"'))
        self.assertRegex(self.text[: calls[0]].rstrip().splitlines()[-1], r'^ *log "')

    def test_the_refused_restore_checks_that_none_of_the_five_cronjobs_was_suspended(self):
        self.assertIn("for cronjob in backup cart-purge-expired rate-limit-check queue-check order-expiry; do", self.text)

    def test_the_header_names_the_phase_and_the_cronjobs(self):
        header = self.text.split("set -eu", 1)[0]
        for part in ("queue-check", "order-expiry", "RabbitMQ", "failed"):
            self.assertIn(part, header)

    def test_the_phase_is_a_function_of_the_script_and_the_helpers_it_is_built_of_are_defined(self):
        for name in FUNCTIONS:
            cut_out(name, self.text)


class PlaceOrder(unittest.TestCase):
    """smoke/checks.py `place-order <email>`: the order of check_order_mail, for an address of the caller; prints the number."""

    spec = importlib.util.spec_from_file_location("smoke_checks_place_order", REPO / "infra/k8s/smoke/checks.py")
    checks = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(checks)

    def fake(self, order_status=201):
        calls = []

        def through_ingress(path, sub="", **kwargs):
            calls.append((path, kwargs))
            if path == "/api/v1/guest-carts" and kwargs.get("method") == "POST":
                return 201, {}, json.dumps({"id": "cart-1"})
            if path.startswith("/api/v1/products?"):
                return 200, {}, json.dumps({"items": [{"slug": "shirt"}]})
            if path == "/api/v1/products/shirt":
                return 200, {}, json.dumps({"variants": [{"variantId": "v-1"}]})
            if path.endswith("/items"):
                return 200, {}, json.dumps({"totals": {"gross": 12300}})
            if path == "/api/v1/orders":
                return order_status, {}, json.dumps({"number": "ZAM-0042"})
            raise AssertionError(path)

        return through_ingress, calls

    def test_it_places_an_order_for_the_address_and_returns_its_number(self):
        fake, calls = self.fake()
        original = self.checks.through_ingress
        self.checks.through_ingress = fake
        try:
            number = self.checks.place_order("smoke-expiry@example.com")
        finally:
            self.checks.through_ingress = original
        self.assertEqual("ZAM-0042", number)
        (order,) = [kwargs for path, kwargs in calls if path == "/api/v1/orders"]
        self.assertEqual("smoke-expiry@example.com", order["body"]["email"])
        self.assertEqual(12300, order["body"]["expectedTotalGross"])
        self.assertEqual("cart-1", order["body"]["cartId"])
        self.assertTrue(order["headers"]["Idempotency-Key"])

    def test_an_order_that_is_not_placed_fails_the_check(self):
        fake, _ = self.fake(order_status=429)
        original = self.checks.through_ingress
        self.checks.through_ingress = fake
        try:
            with self.assertRaises(BaseException):
                self.checks.place_order("smoke-expiry@example.com")
        finally:
            self.checks.through_ingress = original

    def test_the_command_is_dispatched_and_documented(self):
        text = (REPO / "infra/k8s/smoke/checks.py").read_text()
        self.assertIn('command == "place-order"', text)
        self.assertRegex(text.split('"""', 2)[1], r"place-order\s+<email>")


if __name__ == "__main__":
    unittest.main()

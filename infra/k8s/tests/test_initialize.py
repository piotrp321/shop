"""The decision of the job `initialize` about the state of the database, with stubs for bin/console and bin/seed-demo."""

import os
import pathlib
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/charts/shop/files/scripts/initialize.sh"

CONSOLE = """#!/bin/sh
# Stub of bin/console: answers the state query like dbal:run-sql does (a table), or fails.
echo "$@" >> "$STUB_LOG"
[ -z "${STUB_QUERY_FAILS:-}" ] || { echo "connection refused" >&2; exit 1; }
printf ' -------------------------------------------- \\n  state  \\n -------------------------------------------- \\n'
printf '  %s  \\n' "$STUB_STATE"
printf ' -------------------------------------------- \\n'
"""

SEED = """#!/bin/sh
echo "seed-demo" >> "$STUB_LOG"
[ -z "${STUB_SEED_FAILS:-}" ] || exit 3
"""


def state(products=0, prices=0, stocks=0, orders=0):
    return f"products={products} prices={prices} stocks={stocks} orders={orders}"


class InitializeScriptTest(unittest.TestCase):
    def run_script(self, stub_state, **env):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            (root / "bin").mkdir()
            for filename, body in (("console", CONSOLE), ("seed-demo", SEED)):
                (root / "bin" / filename).write_text(body)
                (root / "bin" / filename).chmod(0o755)
            log = root / "log"
            log.write_text("")
            result = subprocess.run(
                ["sh", str(SCRIPT)],
                cwd=root,
                env={"PATH": os.environ["PATH"], "STUB_LOG": str(log), "STUB_STATE": stub_state, **env},
                capture_output=True,
                text=True,
            )
            return result, log.read_text().splitlines()

    def test_an_empty_database_gets_the_demo_data(self):
        result, calls = self.run_script(state())
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("seed-demo", calls[-1])
        self.assertEqual(1, calls.count("seed-demo"))

    def test_a_loaded_database_is_left_alone(self):
        for orders in (0, 4):
            result, calls = self.run_script(state(191, 1012, 1012, orders))
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertNotIn("seed-demo", calls)

    def test_no_products_but_orders_is_an_error_without_changes(self):
        result, calls = self.run_script(state(0, 1012, 1012, 3))
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn("seed-demo", calls)
        self.assertIn("products=0", result.stderr)
        self.assertIn("orders=3", result.stderr)

    def test_orders_alone_are_not_empty(self):
        result, calls = self.run_script(state(orders=1))
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn("seed-demo", calls)

    def test_an_interrupted_initialization_is_an_error(self):
        for mixed in (state(191, 0, 0, 0), state(191, 1012, 0, 0), state(0, 5, 0, 0), state(0, 0, 5, 0)):
            result, calls = self.run_script(mixed)
            self.assertNotEqual(0, result.returncode, mixed)
            self.assertNotIn("seed-demo", calls, mixed)
            self.assertIn(mixed.split()[0], result.stderr)

    def test_a_failing_query_stops_without_seeding(self):
        result, calls = self.run_script(state(), STUB_QUERY_FAILS="1")
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn("seed-demo", calls)

    def test_an_unreadable_answer_stops_without_seeding(self):
        result, calls = self.run_script("something else")
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn("seed-demo", calls)

    def test_a_failing_seed_fails_the_job(self):
        result, calls = self.run_script(state(), STUB_SEED_FAILS="1")
        self.assertEqual(3, result.returncode)

    def test_the_queries_only_read(self):
        _, calls = self.run_script(state(191, 1012, 1012, 0))
        for call in calls:
            self.assertNotRegex(call.lower(), r"insert|update|delete|truncate|drop|alter")


if __name__ == "__main__":
    unittest.main()

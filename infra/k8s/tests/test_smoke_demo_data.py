"""The phase of the smoke test that runs the demo data generator (scripts/smoke.sh `check_demo_data`;
docs/features/demo-data/spec.md, "k3d (chart w profilach ci i load; część 2)"): one Job from the CronJob `demo-data` (profile small),
waited for, and a failure of the Job (or a Job that does not finish in time) fails the test with the log of the Job.

The functions of the phase are cut out of smoke.sh (the script itself needs Docker and a cluster) and run against a stub of kubectl, which
answers by the command and logs every call. Where the phase stands in the script (after every phase that reads the data of the seed) is
checked on the text of smoke.sh."""

import os
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO

SMOKE = REPO / "infra/k8s/scripts/smoke.sh"
FUNCTIONS = ("kc", "count", "run_cronjob_job", "check_demo_data")

# One line per call in $STUB_LOG. The CronJob demo-data has the command bin/generate-demo --profile=$STUB_PROFILE --force-reset and the
# time limit $STUB_DEADLINE; the Job made from it ends as STUB_JOB says (succeeded, failed, running); the database has STUB_PRODUCTS
# products and STUB_ORDERS orders; STUB_PATCH_FAILS makes the Job fail once the CronJob was patched (the break of the test).
KUBECTL = r"""#!/bin/sh
printf '%s\n' "$*" >> "$STUB_LOG"
case "$*" in
  *"get cronjob demo-data"*activeDeadlineSeconds*) printf '%s' "${STUB_DEADLINE:-1800}" ;;
  *"get cronjob demo-data"*command*) printf 'bin/generate-demo --profile=%s --force-reset' "${STUB_PROFILE:-small}" ;;
  *"patch cronjob demo-data"*) touch "$STUB_DIR/patched" ;;
  *"create job"*) ;;
  *"get job "*)
    state="${STUB_JOB:-succeeded}"
    if [ -e "$STUB_DIR/patched" ] && [ -n "${STUB_PATCH_FAILS:-}" ]; then state=failed; fi
    case "$state" in
      succeeded) printf '1 ' ;;
      failed) printf ' True' ;;
      *) printf ' ' ;;
    esac ;;
  *"logs job/"*) echo "demo-data: the generator log of the Job" ;;
  *"SELECT count(*) FROM catalog_product"*) printf '%s\n' "${STUB_PRODUCTS:-2040}" ;;
  *"SELECT count(*) FROM ordering_order"*) printf '%s\n' "${STUB_ORDERS:-5000}" ;;
  *"get pods"*) echo "demo-data-smoke-abcde Running" ;;
esac
exit 0
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
        (self.base / "bin").mkdir()
        kubectl = self.base / "bin/kubectl"
        kubectl.write_text(KUBECTL)
        kubectl.chmod(0o755)
        self.log = self.base / "kubectl.log"
        self.log.write_text("")

    def tearDown(self):
        self.tmp.cleanup()

    def run_phase(self, **env):
        text = SMOKE.read_text()
        script = "\n".join(["set -eu", "namespace=shop", 'SMOKE_BREAK="${SMOKE_BREAK:-}"'] + [cut_out(n, text) for n in FUNCTIONS] + ["check_demo_data"])
        variables = {
            "PATH": f"{self.base / 'bin'}:{os.environ['PATH']}",
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.base),
            "SMOKE_POLL": "1",
            **env,
        }
        return subprocess.run(["sh", "-c", script], env=variables, capture_output=True, text=True, timeout=60)

    def calls(self):
        return self.log.read_text().splitlines()

    def creates(self):
        return [call for call in self.calls() if "create job" in call]

    def test_a_job_from_the_cronjob_succeeds_and_is_created_exactly_once(self):
        result = self.run_phase()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(1, len(self.creates()), self.calls())
        self.assertIn("--from=cronjob/demo-data", self.creates()[0])
        self.assertIn("ok:", result.stdout)

    def test_no_job_is_created_for_another_profile_than_small(self):
        result = self.run_phase(STUB_PROFILE="medium")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("--profile=small", result.stderr)
        self.assertEqual([], self.creates())

    def test_a_failed_job_fails_the_phase_with_the_log_of_the_job(self):
        result = self.run_phase(STUB_JOB="failed")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("failed", result.stderr)
        self.assertIn("demo-data: the generator log of the Job", result.stderr)
        self.assertEqual(1, len(self.creates()), "a failed Job is never created again")

    def test_a_job_that_does_not_finish_in_the_limit_fails_the_phase_with_its_log(self):
        # The limit of the phase is the time limit of the CronJob plus SMOKE_DEMO_DATA_MARGIN: 0 and 0 end the wait at once.
        result = self.run_phase(STUB_JOB="running", STUB_DEADLINE="0", SMOKE_DEMO_DATA_MARGIN="0")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("did not finish", result.stderr)
        self.assertIn("demo-data: the generator log of the Job", result.stderr)

    def test_a_successful_job_that_left_the_data_of_the_seed_fails_the_phase(self):
        for variables in ({"STUB_PRODUCTS": "40"}, {"STUB_ORDERS": "0"}):
            with self.subTest(variables):
                result = self.run_phase(**variables)
                self.assertNotEqual(0, result.returncode)
                self.assertIn("did not replace the data", result.stderr)

    def test_the_break_gives_the_generator_a_profile_it_refuses_and_the_phase_fails(self):
        result = self.run_phase(SMOKE_BREAK="demo-data", STUB_PATCH_FAILS="1")
        self.assertNotEqual(0, result.returncode)
        patches = [call for call in self.calls() if "patch cronjob demo-data" in call]
        self.assertEqual(1, len(patches), self.calls())
        self.assertIn("--profile=smoke-unknown", patches[0])
        self.assertLess(self.calls().index(patches[0]), self.calls().index(self.creates()[0]))
        self.assertIn("demo-data: the generator log of the Job", result.stderr)

    def test_without_the_break_the_cronjob_is_not_patched(self):
        self.run_phase()
        self.assertEqual([], [call for call in self.calls() if "patch" in call])


class Script(unittest.TestCase):
    text = SMOKE.read_text()

    def test_the_phase_runs_once_after_every_phase_that_reads_the_data_of_the_seed(self):
        """The generator replaces the data of the shop: it runs after the last phase that counts products or orders, and the
        phases that follow it are none (only the report of the peaks)."""
        calls = [m.start() for m in re.finditer(r"^check_demo_data$", self.text, re.M)]
        self.assertEqual(1, len(calls), "the phase is called once")
        last_phase = self.text.index('log "upgrade r4:')
        self.assertGreater(calls[0], last_phase)
        self.assertLess(calls[0], self.text.index("report_peaks\n", last_phase))
        following = [line for line in self.text[calls[0] :].splitlines() if line.strip()]
        self.assertEqual(["check_demo_data", 'log "peaks"', "report_peaks"], following[:3])

    def test_the_break_is_accepted_and_documented(self):
        self.assertIn('"" | migration | image | demo-data) ;;', self.text)
        self.assertIn("SMOKE_BREAK=demo-data smoke.sh", self.text)
        self.assertIn("SMOKE_BREAK must be empty, migration, image or demo-data", self.text)

    def test_the_phases_in_the_header_name_the_generator(self):
        header = self.text.split("set -eu", 1)[0]
        self.assertIn("demo-data", header)
        self.assertIn("generate-demo", header)


if __name__ == "__main__":
    unittest.main()

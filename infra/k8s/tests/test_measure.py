"""scripts/measure.sh and scripts/measure-lib.sh: only reading scenarios may run, and a measurement is complete only when the Job
and the tools finished (a single ##END, the exit code of k6 behind a pipe and a timed out Job must not pass for a result).
docker is a stub that logs its calls: a refused run must not call it (nothing is created in the cluster)."""

import os
import pathlib
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPTS = REPO / "infra/k8s/scripts"
LIB = SCRIPTS / "measure-lib.sh"


def lib(snippet, *args):
    return subprocess.run(["sh", "-c", f". {LIB}\n{snippet}", "sh", *args], capture_output=True, text=True)


class Validation(unittest.TestCase):
    def test_read_only_runs_are_accepted(self):
        for runs in ("read:home:10,category:20,product:35,search:25 product:product:100", "p:product:1"):
            with self.subTest(runs=runs):
                self.assertEqual(0, lib('k6_runs_valid "$1"', runs).returncode)

    def test_everything_else_is_refused_with_the_reason(self):
        for runs in (
            "mix:cart:100",
            "read:home:10,cart:5",
            "read:product:50 write:cart_add:50",
            "read:search_filtered:10",
            "read:home:0",
            "read:home:ten",
            "read:home",
            "read:",
            "BAD:home:10",
            "read:home:10;cart:5",
            "",
        ):
            with self.subTest(runs=runs):
                result = lib('k6_runs_valid "$1"', runs)
                self.assertEqual(1, result.returncode)
                self.assertIn("measure:", result.stderr)

    def test_a_refused_run_creates_nothing_in_the_cluster(self):
        with tempfile.TemporaryDirectory() as tmp:
            tmp = pathlib.Path(tmp)
            (tmp / "kube").mkdir()
            (tmp / "kube/config").write_text("apiVersion: v1\n")
            (tmp / "out").mkdir()
            (tmp / "bin").mkdir()
            log = tmp / "docker.log"
            stub = tmp / "bin/docker"
            stub.write_text(f'#!/bin/sh\necho "$@" >> {log}\nexit 1\n')
            stub.chmod(0o755)
            environment = dict(
                os.environ,
                PATH=f"{tmp / 'bin'}:{os.environ['PATH']}",
                KUBE_DIR=str(tmp / "kube"),
                MEASURE_OUT=str(tmp / "out"),
                MEASURE_STAGES="k6",
                MEASURE_K6_RUNS="mix:cart:100",
            )
            result = subprocess.run(["sh", str(SCRIPTS / "measure.sh")], env=environment, input="tak\n", capture_output=True, text=True)
            self.assertEqual(2, result.returncode, result.stdout + result.stderr)
            self.assertIn("cart", result.stderr)
            self.assertFalse(log.exists(), "docker (kubectl) was called before the validation")
            self.assertEqual([], list((tmp / "out").iterdir()))


class Outcome(unittest.TestCase):
    def log(self, text):
        handle = tempfile.NamedTemporaryFile("w", suffix=".log", delete=False)
        self.addCleanup(os.unlink, handle.name)
        handle.write(text)
        handle.close()
        return handle.name

    def test_one_end_marker_is_not_a_complete_measurement(self):
        early = self.log("##BEGIN single\n##END single\n##BEGIN burst\nTraceback\n")
        result = lib('log_problem "$1"', early)
        self.assertEqual(1, result.returncode)
        self.assertIn("##DONE", result.stdout)

    def test_a_failed_marker_is_not_a_complete_measurement(self):
        broken = self.log("##BEGIN k6-read\n##FAILED k6-read exit=137\n##END k6-read\n##DONE\n")
        result = lib('log_problem "$1"', broken)
        self.assertEqual(1, result.returncode)
        self.assertIn("##FAILED k6-read exit=137", result.stdout)

    def test_the_closing_marker_without_failures_is_complete(self):
        done = self.log("##BEGIN single\n##END single\n##RC single exit=1\n##K6 read exit=99\n##DONE\n")
        self.assertEqual(0, lib('log_problem "$1"', done).returncode)

    def test_no_log_is_not_complete(self):
        self.assertEqual(1, lib('log_problem "$1"', self.log("")).returncode)

    def test_k6_exit_codes(self):
        result = self.log("{}")
        empty = self.log("")
        cases = (
            ("0", result, "##K6 read exit=0"),
            ("99", result, "##K6 read exit=99"),  # a threshold crossed: the expected end of a ramp at saturation
            ("99", empty, "##FAILED k6-read exit=99 without"),
            ("0", "/nonexistent", "##FAILED k6-read exit=0 without"),
            ("107", result, "##FAILED k6-read exit=107"),  # a script error
            ("137", result, "##FAILED k6-read exit=137"),  # killed (memory)
            ("1", result, "##FAILED k6-read exit=1"),
        )
        for code, ramp, expected in cases:
            with self.subTest(code=code, ramp=ramp):
                self.assertIn(expected, lib('k6_outcome read "$1" "$2"', code, ramp).stdout)

    def test_baseline_exit_codes(self):
        meta = self.log("{}")
        empty = self.log("")
        cases = (
            ("0", meta, "##RC single exit=0"),
            ("1", meta, "##RC single exit=1"),  # failed requests or an aborted run, the meta file is written first
            ("1", empty, "##FAILED single exit=1"),  # a crash: no meta file
            ("2", meta, "##FAILED single exit=2"),
            ("0", empty, "##FAILED single exit=0"),
        )
        for code, file, expected in cases:
            with self.subTest(code=code, meta=file):
                self.assertIn(expected, lib('baseline_outcome single "$1" "$2"', code, file).stdout)

    def test_the_script_keeps_the_exit_code_of_k6_and_fails_the_run_on_a_bad_Job(self):
        text = (SCRIPTS / "measure.sh").read_text()
        self.assertNotIn("saturate.js 2>&1 | tail", text)
        self.assertIn("k6_outcome", text)
        wait = text[text.index("wait_job() {") : text.index("failed_job=0\n")]
        for outcome in ("failed", "timeout"):
            self.assertIn(f"outcome={outcome}", wait)
        self.assertIn('[ "$outcome" != succeeded ]', wait)
        self.assertIn("log_problem", wait)


if __name__ == "__main__":
    unittest.main()

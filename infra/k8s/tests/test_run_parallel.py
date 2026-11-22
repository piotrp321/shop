"""Tests of tests/run_parallel.py: the test files of a directory, each in its own unittest process, several at a time."""

import pathlib
import subprocess
import sys
import tempfile
import textwrap
import unittest

RUNNER = pathlib.Path(__file__).resolve().parent / "run_parallel.py"

PASSING = """
    import unittest

    class Passing(unittest.TestCase):
        def test_one(self):
            self.assertTrue(True)

        def test_two(self):
            self.assertTrue(True)
"""

FAILING = """
    import unittest

    class Failing(unittest.TestCase):
        def test_the_answer(self):
            self.assertEqual(41, 42, "the answer is wrong")
"""

# Two files that each wait for the marker of the other: they pass only when they run at the same time.
WAITING = """
    import os, pathlib, time, unittest

    class Waiting(unittest.TestCase):
        def test_the_other_file_runs_meanwhile(self):
            markers = pathlib.Path(os.environ["MARKERS"])
            (markers / "{me}").touch()
            deadline = time.monotonic() + 10
            while not (markers / "{other}").exists():
                self.assertLess(time.monotonic(), deadline, "the other file did not run meanwhile")
                time.sleep(0.05)
"""


class RunParallel(unittest.TestCase):
    def setUp(self):
        self.dir = pathlib.Path(tempfile.mkdtemp())
        self.tests = self.dir / "tests"
        self.tests.mkdir()
        self.addCleanup(subprocess.run, ["rm", "-rf", str(self.dir)])

    def file(self, name, text):
        (self.tests / name).write_text(textwrap.dedent(text))

    def run_parallel(self, *args, env=None):
        return subprocess.run([sys.executable, "-I", str(RUNNER), *args], cwd=self.dir, capture_output=True, text=True,
                              timeout=120, env=env)

    def test_all_files_pass_with_a_summary_of_the_files_and_the_tests(self):
        self.file("test_a.py", PASSING)
        self.file("test_b.py", PASSING)
        result = self.run_parallel("--jobs", "2", "tests")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertRegex(result.stdout, r"(?m)^ok +test_a\.py .*2 tests")
        self.assertRegex(result.stdout, r"(?m)^ok +test_b\.py .*2 tests")
        self.assertIn("run_parallel: 2 files, 4 tests, all passed", result.stdout)

    def test_a_failing_file_fails_the_run_shows_its_output_and_the_others_still_run(self):
        self.file("test_a.py", FAILING)
        self.file("test_b.py", PASSING)
        self.file("test_c.py", PASSING)
        result = self.run_parallel("--jobs", "1", "tests")
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("the answer is wrong", result.stdout)
        self.assertRegex(result.stdout, r"(?m)^FAILED +test_a\.py")
        self.assertRegex(result.stdout, r"(?m)^ok +test_b\.py")
        self.assertRegex(result.stdout, r"(?m)^ok +test_c\.py")
        self.assertIn("run_parallel: 3 files, FAILED: test_a.py", result.stdout)

    def test_a_file_that_cannot_be_imported_fails_the_run(self):
        self.file("test_a.py", "import a_module_that_does_not_exist\n")
        self.file("test_b.py", PASSING)
        result = self.run_parallel("--jobs", "2", "tests")
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("a_module_that_does_not_exist", result.stdout)
        self.assertIn("FAILED: test_a.py", result.stdout)

    def test_a_file_without_tests_fails_the_run(self):
        self.file("test_a.py", "import unittest\n")
        self.file("test_b.py", PASSING)
        result = self.run_parallel("--jobs", "2", "tests")
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("FAILED: test_a.py", result.stdout)

    def test_a_file_that_exits_with_zero_without_running_its_tests_fails_the_run(self):
        self.file("test_a.py", """
            import os
            os._exit(0)
        """)
        self.file("test_b.py", PASSING)
        result = self.run_parallel("--jobs", "2", "tests")
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertRegex(result.stdout, r"(?m)^FAILED +test_a\.py .*no tests ran")
        self.assertIn("FAILED: test_a.py", result.stdout)

    def test_output_that_is_not_utf_8_still_names_the_failing_file(self):
        self.file("test_a.py", """
            import sys, unittest

            class Bytes(unittest.TestCase):
                def test_bytes(self):
                    sys.stdout.flush()
                    sys.stdout.buffer.write(b"\\xff\\xfe broken bytes\\n")
                    sys.stdout.buffer.flush()
                    self.fail("the bytes test failed")
        """)
        self.file("test_b.py", PASSING)
        result = self.run_parallel("--jobs", "2", "tests")
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("the bytes test failed", result.stdout)
        self.assertIn("broken bytes", result.stdout)
        self.assertIn("run_parallel: 2 files, FAILED: test_a.py", result.stdout)

    def test_the_files_run_at_the_same_time(self):
        markers = self.dir / "markers"
        markers.mkdir()
        self.file("test_a.py", WAITING.format(me="a", other="b"))
        self.file("test_b.py", WAITING.format(me="b", other="a"))
        result = self.run_parallel("--jobs", "2", "tests", env={"MARKERS": str(markers), "PATH": "/usr/bin:/bin"})
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_the_helpers_of_the_directory_import_as_in_unittest_discover(self):
        (self.tests / "support.py").write_text("ANSWER = 42\n")
        self.file("test_a.py", """
            import unittest
            import support

            class UsesSupport(unittest.TestCase):
                def test_answer(self):
                    self.assertEqual(42, support.ANSWER)
        """)
        result = self.run_parallel("--jobs", "1", "tests")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_no_test_files_is_an_error(self):
        (self.tests / "support.py").write_text("")
        result = self.run_parallel("--jobs", "2", "tests")
        self.assertEqual(2, result.returncode, result.stdout + result.stderr)
        self.assertIn("no test files", result.stderr)

    def test_a_missing_directory_is_an_error(self):
        result = self.run_parallel("--jobs", "2", "missing")
        self.assertEqual(2, result.returncode, result.stdout + result.stderr)
        self.assertIn("missing", result.stderr)

    def test_a_number_of_jobs_below_one_is_an_error(self):
        self.file("test_a.py", PASSING)
        for jobs in ("0", "-1", "x"):
            with self.subTest(jobs=jobs):
                result = self.run_parallel("--jobs", jobs, "tests")
                self.assertEqual(2, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

"""Tests of system_tmp.py, the one place that decides whether a test may be skipped because the system temp
(/private/tmp) cannot be written: python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

Some tests start the real wrapper or make run directories in the system temp, which the executor's sandbox does not
allow (the executor works in an isolated TMPDIR). Those tests are skipped, with the reason, only inside the executor's
sandbox (AGENT_EXECUTOR_SANDBOX=1, set by claude-agent) when the system temp cannot be written and CI is not set. On a
host and in CI they never skip: an unusable /tmp there is a failure of the test.
"""
import importlib.util
import os
import shutil
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'system_tmp.py')
spec = importlib.util.spec_from_file_location('system_tmp', SCRIPT)
system_tmp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(system_tmp)

SANDBOX = {system_tmp.MARKER: '1'}


class SkipReasonTest(unittest.TestCase):
    def setUp(self):
        self.tmp = os.path.realpath(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.writable = os.path.join(self.tmp, 'writable')
        self.read_only = os.path.join(self.tmp, 'read-only')
        os.makedirs(self.writable)
        os.makedirs(self.read_only)
        os.chmod(self.read_only, 0o500)
        self.addCleanup(os.chmod, self.read_only, 0o700)
        self.can_deny = os.getuid() != 0   # root ignores directory permissions

    def reason(self, environ, parent=None):
        return system_tmp.skip_reason(environ, parent or self.read_only)

    def test_the_sandbox_with_an_unwritable_system_temp_skips_with_the_reason(self):
        if not self.can_deny:
            self.skipTest('root ignores directory permissions')
        reason = self.reason(SANDBOX)
        self.assertIsInstance(reason, str)
        self.assertIn(self.read_only, reason)
        self.assertIn('sandbox', reason)

    def test_the_sandbox_with_a_writable_system_temp_does_not_skip(self):
        self.assertIsNone(self.reason(SANDBOX, self.writable))

    def test_a_host_without_the_marker_never_skips(self):
        self.assertIsNone(self.reason({}))
        self.assertIsNone(self.reason({'HOME': '/x'}))

    def test_ci_never_skips_even_in_the_sandbox_marker(self):
        for value in ('true', '1', 'yes'):
            self.assertIsNone(self.reason(dict(SANDBOX, CI=value)), value)

    def test_an_empty_ci_does_not_count_as_ci(self):
        if not self.can_deny:
            self.skipTest('root ignores directory permissions')
        self.assertIsNotNone(self.reason(dict(SANDBOX, CI='')))

    def test_only_the_value_1_is_the_marker(self):
        for value in ('', '0', 'true', 'yes', ' 1'):
            self.assertIsNone(self.reason({system_tmp.MARKER: value}), repr(value))

    def test_a_missing_system_temp_in_the_sandbox_skips_and_on_a_host_does_not(self):
        missing = os.path.join(self.tmp, 'missing')
        self.assertIsNotNone(self.reason(SANDBOX, missing))
        self.assertIsNone(self.reason({}, missing))

    def test_the_default_environment_is_the_process_environment(self):
        saved = os.environ.get(system_tmp.MARKER)
        self.addCleanup(lambda: os.environ.pop(system_tmp.MARKER, None) if saved is None
                        else os.environ.__setitem__(system_tmp.MARKER, saved))
        os.environ.pop(system_tmp.MARKER, None)
        self.assertIsNone(system_tmp.skip_reason(parent=self.read_only))

    def test_the_default_parent_is_the_system_temp(self):
        self.assertEqual(system_tmp.SYSTEM_TMP, os.path.realpath('/tmp'))

    def test_writable_makes_and_removes_its_probe_directory(self):
        self.assertTrue(system_tmp.writable(self.writable))
        self.assertEqual(os.listdir(self.writable), [])
        if self.can_deny:
            self.assertFalse(system_tmp.writable(self.read_only))
        self.assertFalse(system_tmp.writable(os.path.join(self.tmp, 'missing')))


class RequireTest(unittest.TestCase):
    """require() skips the test case in the sandbox and returns normally everywhere else, so the test then runs (and
    fails by itself where /tmp cannot be written)."""

    class Case(unittest.TestCase):
        def runTest(self):
            pass

    def setUp(self):
        self.tmp = os.path.realpath(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.read_only = os.path.join(self.tmp, 'read-only')
        os.makedirs(self.read_only)
        os.chmod(self.read_only, 0o500)
        self.addCleanup(os.chmod, self.read_only, 0o700)
        if os.getuid() == 0:
            self.skipTest('root ignores directory permissions')

    def test_skips_in_the_sandbox(self):
        with self.assertRaises(unittest.SkipTest) as caught:
            system_tmp.require(self.Case(), SANDBOX, self.read_only)
        self.assertIn('sandbox', str(caught.exception))

    def test_does_not_skip_in_ci_or_on_a_host(self):
        system_tmp.require(self.Case(), dict(SANDBOX, CI='true'), self.read_only)
        system_tmp.require(self.Case(), {}, self.read_only)

    def test_a_test_that_needs_the_system_temp_fails_there_instead_of_skipping(self):
        # What happens on a host or in CI with an unusable /tmp: the require passes, the creation raises.
        system_tmp.require(self.Case(), {}, self.read_only)
        with self.assertRaises(OSError):
            tempfile.mkdtemp(prefix='agent.', dir=self.read_only)


if __name__ == '__main__':
    unittest.main()

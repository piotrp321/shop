"""Tests of sandbox_skip.py, the one place that decides whether a test may be skipped because the executor's sandbox
does not allow what it needs (the system temp /private/tmp, Unix sockets): python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

Some tests start the real wrapper or make run directories in the system temp, others bind Unix sockets; the executor's
sandbox allows neither (the executor works in an isolated TMPDIR). Those tests are skipped, with the reason, only inside
the executor's sandbox (AGENT_EXECUTOR_SANDBOX=1, set by claude-agent) when the thing really cannot be done and CI is not
set. On a host and in CI they never skip: an unusable /tmp or socket there is a failure of the test.
"""
import importlib.util
import os
import shutil
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'sandbox_skip.py')
spec = importlib.util.spec_from_file_location('sandbox_skip', SCRIPT)
sandbox_skip = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sandbox_skip)

SANDBOX = {sandbox_skip.MARKER: '1'}


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
        return sandbox_skip.tmp_skip_reason(environ, parent or self.read_only)

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
            self.assertIsNone(self.reason({sandbox_skip.MARKER: value}), repr(value))

    def test_a_missing_system_temp_in_the_sandbox_skips_and_on_a_host_does_not(self):
        missing = os.path.join(self.tmp, 'missing')
        self.assertIsNotNone(self.reason(SANDBOX, missing))
        self.assertIsNone(self.reason({}, missing))

    def test_the_default_environment_is_the_process_environment(self):
        saved = os.environ.get(sandbox_skip.MARKER)
        self.addCleanup(lambda: os.environ.pop(sandbox_skip.MARKER, None) if saved is None
                        else os.environ.__setitem__(sandbox_skip.MARKER, saved))
        os.environ.pop(sandbox_skip.MARKER, None)
        self.assertIsNone(sandbox_skip.tmp_skip_reason(parent=self.read_only))

    def test_the_default_parent_is_the_system_temp(self):
        self.assertEqual(sandbox_skip.SYSTEM_TMP, os.path.realpath('/tmp'))

    def test_writable_makes_and_removes_its_probe_directory(self):
        self.assertTrue(sandbox_skip.writable(self.writable))
        self.assertEqual(os.listdir(self.writable), [])
        if self.can_deny:
            self.assertFalse(sandbox_skip.writable(self.read_only))
        self.assertFalse(sandbox_skip.writable(os.path.join(self.tmp, 'missing')))


class SocketSkipReasonTest(unittest.TestCase):
    """Unix sockets: the sandbox refuses bind (allowUnixSockets lists only the Docker sockets)."""

    def setUp(self):
        self.tmp = os.path.realpath(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.missing = os.path.join(self.tmp, 'missing')   # bind there fails like a refused bind

    def test_can_bind_in_a_directory_that_allows_it_and_leaves_nothing(self):
        self.assertTrue(sandbox_skip.can_bind_unix_socket(self.tmp))
        self.assertEqual(os.listdir(self.tmp), [])
        self.assertFalse(sandbox_skip.can_bind_unix_socket(self.missing))

    def test_the_default_directory_is_the_temp_of_the_process(self):
        self.assertTrue(sandbox_skip.can_bind_unix_socket())

    def test_the_sandbox_where_bind_is_refused_skips_with_the_reason(self):
        reason = sandbox_skip.socket_skip_reason(SANDBOX, self.missing)
        self.assertIsInstance(reason, str)
        self.assertIn('sandbox', reason)
        self.assertIn('Unix', reason)

    def test_the_sandbox_where_bind_works_does_not_skip(self):
        self.assertIsNone(sandbox_skip.socket_skip_reason(SANDBOX, self.tmp))

    def test_a_host_or_ci_never_skips(self):
        self.assertIsNone(sandbox_skip.socket_skip_reason({}, self.missing))
        self.assertIsNone(sandbox_skip.socket_skip_reason(dict(SANDBOX, CI='true'), self.missing))
        self.assertIsNone(sandbox_skip.socket_skip_reason({sandbox_skip.MARKER: '0'}, self.missing))

    def test_require_skips_only_when_the_reason_holds(self):
        case = RequireTest.Case()
        with self.assertRaises(unittest.SkipTest):
            sandbox_skip.require_unix_sockets(case, SANDBOX, self.missing)
        sandbox_skip.require_unix_sockets(case, {}, self.missing)
        sandbox_skip.require_unix_sockets(case, SANDBOX, self.tmp)


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
            sandbox_skip.require_system_tmp(self.Case(), SANDBOX, self.read_only)
        self.assertIn('sandbox', str(caught.exception))

    def test_does_not_skip_in_ci_or_on_a_host(self):
        sandbox_skip.require_system_tmp(self.Case(), dict(SANDBOX, CI='true'), self.read_only)
        sandbox_skip.require_system_tmp(self.Case(), {}, self.read_only)

    def test_a_test_that_needs_the_system_temp_fails_there_instead_of_skipping(self):
        # What happens on a host or in CI with an unusable /tmp: the require passes, the creation raises.
        sandbox_skip.require_system_tmp(self.Case(), {}, self.read_only)
        with self.assertRaises(OSError):
            tempfile.mkdtemp(prefix='agent.', dir=self.read_only)


if __name__ == '__main__':
    unittest.main()

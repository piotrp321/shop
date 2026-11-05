"""Tests of claude_pin.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import contextlib
import importlib.util
import io
import os
import stat
import subprocess
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'claude_pin.py')
spec = importlib.util.spec_from_file_location('claude_pin', SCRIPT)
claude_pin = importlib.util.module_from_spec(spec)
spec.loader.exec_module(claude_pin)

LIFECYCLE = '''"""Lifecycle."""
from dataclasses import dataclass


@dataclass
class ExecutorConfig:
    claude_version: str = '9.9.9'


@dataclass
class LifecycleConfig:
    auth_mode: str = 'subscription'
    claude_version: str = '%s'
    claude_bin: str = 'claude'
'''


def git(cwd, *args):
    subprocess.run(['git', '-C', cwd] + list(args), check=True, capture_output=True, text=True)


def ao_checkout(root, lifecycle=LIFECYCLE % '2.1.308', origin=True):
    """A git checkout with orchestrator/lifecycle.py committed and, when origin is true, an origin/main ref."""
    ao = os.path.join(root, 'ao')
    os.makedirs(os.path.join(ao, 'orchestrator'))
    git(ao, 'init', '-q', '-b', 'main')
    with open(os.path.join(ao, 'orchestrator', 'lifecycle.py'), 'w') as f:
        f.write(lifecycle)
    git(ao, 'add', '.')
    git(ao, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init')
    if origin:
        git(ao, 'update-ref', 'refs/remotes/origin/main', 'HEAD')
    return ao


def fake_binary(versions, name, body, executable=True):
    os.makedirs(versions, exist_ok=True)
    path = os.path.join(versions, name)
    with open(path, 'w') as f:
        f.write('#!/bin/sh\n' + body + '\n')
    mode = stat.S_IRUSR | stat.S_IWUSR | (stat.S_IXUSR if executable else 0)
    os.chmod(path, mode)
    return path


class ClaudePinTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = self.tmp.name
        self.versions = os.path.join(self.root, 'versions')
        self.cask = os.path.join(self.root, 'Caskroom')
        self.candidates = [os.path.join(self.versions, '{version}'),
                           os.path.join(self.cask, '{version}', 'claude')]

    def run_main(self, ao):
        argv = ['--ao-dir', ao]
        for candidate in self.candidates:
            argv += ['--candidate', candidate]
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = claude_pin.main(argv)
        return code, out.getvalue(), err.getvalue()

    def test_prints_the_path_of_the_pinned_binary(self):
        ao = ao_checkout(self.root)
        path = fake_binary(self.versions, '2.1.308', 'echo "2.1.308 (Claude Code)"')
        code, out, err = self.run_main(ao)
        self.assertEqual((code, err), (0, ''))
        self.assertEqual(out, path + '\n')

    def test_reads_the_pin_from_origin_main_not_the_working_tree(self):
        ao = ao_checkout(self.root)
        with open(os.path.join(ao, 'orchestrator', 'lifecycle.py'), 'w') as f:
            f.write(LIFECYCLE % '2.1.311')
        path = fake_binary(self.versions, '2.1.308', 'echo "2.1.308 (Claude Code)"')
        fake_binary(self.versions, '2.1.311', 'echo "2.1.311 (Claude Code)"')
        code, out, _ = self.run_main(ao)
        self.assertEqual((code, out), (0, path + '\n'))

    def test_takes_a_later_candidate_when_an_earlier_one_is_missing(self):
        ao = ao_checkout(self.root)
        path = fake_binary(os.path.join(self.cask, '2.1.308'), 'claude', 'echo "2.1.308 (Claude Code)"')
        code, out, _ = self.run_main(ao)
        self.assertEqual((code, out), (0, path + '\n'))

    def test_takes_a_later_candidate_when_an_earlier_one_reports_another_version(self):
        ao = ao_checkout(self.root)
        fake_binary(self.versions, '2.1.308', 'echo "2.1.311 (Claude Code)"')
        path = fake_binary(os.path.join(self.cask, '2.1.308'), 'claude', 'echo "2.1.308 (Claude Code)"')
        code, out, _ = self.run_main(ao)
        self.assertEqual((code, out), (0, path + '\n'))

    def test_missing_binary_is_refused_with_an_install_instruction(self):
        ao = ao_checkout(self.root)
        fake_binary(self.versions, '2.1.311', 'echo "2.1.311 (Claude Code)"')
        code, out, err = self.run_main(ao)
        self.assertEqual((code, out), (2, ''))
        self.assertIn(os.path.join(self.versions, '2.1.308'), err)
        self.assertIn(os.path.join(self.cask, '2.1.308', 'claude'), err)
        self.assertIn('claude install 2.1.308', err)

    def test_candidate_without_the_version_placeholder_is_a_usage_error(self):
        self.candidates = [os.path.join(self.versions, 'claude')]
        ao = ao_checkout(self.root)
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit) as raised:
            self.run_main(ao)
        self.assertEqual(raised.exception.code, 2)

    def test_missing_versions_directory_is_refused(self):
        ao = ao_checkout(self.root)
        code, _, err = self.run_main(ao)
        self.assertEqual(code, 2)
        self.assertIn('claude install 2.1.308', err)

    def test_binary_reporting_another_version_is_refused(self):
        ao = ao_checkout(self.root)
        fake_binary(self.versions, '2.1.308', 'echo "2.1.311 (Claude Code)"')
        code, out, err = self.run_main(ao)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('2.1.311 (Claude Code)', err)
        self.assertIn('2.1.308', err)
        self.assertIn('claude install 2.1.308', err)

    def test_binary_failing_on_version_is_refused(self):
        ao = ao_checkout(self.root)
        fake_binary(self.versions, '2.1.308', 'echo "broken" >&2; exit 3')
        code, _, err = self.run_main(ao)
        self.assertEqual(code, 2)
        self.assertIn('broken', err)
        self.assertIn('3', err)

    def test_binary_reporting_the_version_only_as_a_later_word_is_refused(self):
        ao = ao_checkout(self.root)
        fake_binary(self.versions, '2.1.308', 'echo "Claude Code 2.1.308"')
        code, _, _ = self.run_main(ao)
        self.assertEqual(code, 2)

    def test_non_executable_binary_is_refused(self):
        ao = ao_checkout(self.root)
        fake_binary(self.versions, '2.1.308', 'echo "2.1.308 (Claude Code)"', executable=False)
        code, _, err = self.run_main(ao)
        self.assertEqual(code, 2)
        self.assertIn('claude install 2.1.308', err)

    def test_hanging_binary_is_refused(self):
        ao = ao_checkout(self.root)
        fake_binary(self.versions, '2.1.308', 'sleep 5')
        original = claude_pin.VERSION_TIMEOUT_S
        claude_pin.VERSION_TIMEOUT_S = 0.3
        self.addCleanup(setattr, claude_pin, 'VERSION_TIMEOUT_S', original)
        code, _, err = self.run_main(ao)
        self.assertEqual(code, 2)
        self.assertIn('--version', err)

    def test_checkout_without_origin_main_is_an_error(self):
        ao = ao_checkout(self.root, origin=False)
        fake_binary(self.versions, '2.1.308', 'echo "2.1.308 (Claude Code)"')
        code, out, err = self.run_main(ao)
        self.assertEqual((code, out), (1, ''))
        self.assertIn('origin/main:orchestrator/lifecycle.py', err)

    def test_missing_checkout_is_an_error(self):
        code, _, err = self.run_main(os.path.join(self.root, 'nothing'))
        self.assertEqual(code, 1)
        self.assertTrue(err.startswith('claude_pin: '))

    def test_lifecycle_without_the_pin_is_an_error(self):
        ao = ao_checkout(self.root, lifecycle='class LifecycleConfig:\n    claude_bin: str = "claude"\n')
        code, _, err = self.run_main(ao)
        self.assertEqual(code, 1)
        self.assertIn('claude_version', err)

    def test_unparsable_lifecycle_is_an_error(self):
        ao = ao_checkout(self.root, lifecycle='def broken(:\n')
        code, _, err = self.run_main(ao)
        self.assertEqual(code, 1)
        self.assertIn('lifecycle.py', err)

    def test_pin_that_is_not_a_version_is_an_error(self):
        ao = ao_checkout(self.root, lifecycle=LIFECYCLE % '../../bin/sh')
        code, _, err = self.run_main(ao)
        self.assertEqual(code, 1)
        self.assertIn('../../bin/sh', err)


if __name__ == '__main__':
    unittest.main()

"""metrics.py as a process: the real command runner with a stand-in `gh` on PATH (no network, no real GitHub)."""
import json
import os
import stat
import subprocess
import sys
import unittest

import testkit
from testkit import Env

SCRIPT = os.path.join(testkit.HERE, 'metrics.py')
FAKE_GH = """#!/bin/sh
# stand-in for `gh`: answers pr view / pr list, or fails when FAKE_GH_FAIL is set
[ -n "$FAKE_GH_FAIL" ] && { echo "$FAKE_GH_FAIL" >&2; exit 1; }
case "$2" in
  view) echo '{"state": "MERGED", "mergedAt": "2026-11-13T10:00:00Z", "closingIssuesReferences": [{"number": 496}]}' ;;
  list) echo '[]' ;;
esac
"""


class ProcessTest(Env):
    def setUp(self):
        super().setUp()
        self.home = testkit.fixture_home(self.root, 'deploy-ops')
        self.bin = os.path.join(self.root, 'bin')
        os.makedirs(self.bin)
        gh = os.path.join(self.bin, 'gh')
        with open(gh, 'w') as f:
            f.write(FAKE_GH)
        os.chmod(gh, os.stat(gh).st_mode | stat.S_IXUSR)

    def run_script(self, path, **env):
        environ = {'HOME': self.root, 'PATH': path, **env}
        return subprocess.run([sys.executable, SCRIPT, 'epic', 'deploy-ops', '--ao-home', self.home, '--state',
                               self.state, '--night', self.night], capture_output=True, text=True, env=environ)

    def test_runs_the_command_found_on_path(self):
        result = self.run_script(self.bin + ':/usr/bin:/bin')
        self.assertEqual(result.returncode, 0, result.stderr)
        record = json.loads(result.stdout)
        self.assertEqual((record['issue'], record['merged_at']), (496, '2026-11-13T10:00:00Z'))

    def test_failing_gh_ends_with_exit_code_3_and_a_message(self):
        result = self.run_script(self.bin + ':/usr/bin:/bin', FAKE_GH_FAIL='HTTP 401: bad credentials')
        self.assertEqual(result.returncode, 3)
        self.assertIn('metrics: błąd gh', result.stderr)
        self.assertIn('bad credentials', result.stderr)
        self.assertIsNone(json.loads(result.stdout)['merged_at'])

    def test_missing_gh_ends_with_exit_code_3_and_a_message(self):
        empty = os.path.join(self.root, 'empty')
        os.makedirs(empty)
        result = self.run_script(empty)
        self.assertEqual(result.returncode, 3)
        self.assertIn('metrics: błąd gh', result.stderr)

    def test_no_arguments_is_a_usage_error(self):
        result = subprocess.run([sys.executable, SCRIPT], capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)
        self.assertIn('usage', result.stderr)


if __name__ == '__main__':
    unittest.main()

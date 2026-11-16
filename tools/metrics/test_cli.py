"""Tests of metrics.py: command line, record skeleton and error paths.
python3 -m unittest discover -s tools/metrics -p 'test_*.py'"""
import contextlib
import io
import json
import os
import sqlite3
import tempfile
import unittest
from unittest import mock

import metrics
import testkit


def run_cli(argv, runner=None, now=1800000000.0):
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        code = metrics.main(argv, run=runner, now=now)
    return code, out.getvalue(), err.getvalue()


class Env(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.root = self._tmp.name
        self.state = os.path.join(self.root, 'state')
        self.night = os.path.join(self.root, 'night')
        os.makedirs(self.night)
        # `~` never reaches the real state of the user
        patcher = mock.patch.dict(os.environ, {'HOME': self.root})
        patcher.start()
        self.addCleanup(patcher.stop)

    def epic(self, name, *extra, runner=None, home=None, no_gh=True):
        argv = ['epic', name, '--state', self.state, '--night', self.night]
        if home is not None:
            argv += ['--ao-home', home]
        if no_gh:
            argv.append('--no-gh')
        return run_cli(argv + list(extra), runner)

    def record(self, name, *extra, home=None, runner=None, no_gh=True):
        code, out, err = self.epic(name, *extra, home=home, runner=runner, no_gh=no_gh)
        self.assertIn(code, (0, 3), err)
        return json.loads(out)


class SkeletonTest(Env):
    def test_record_basics_on_deploy_ops_fixture(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        code, out, err = self.epic('deploy-ops', home=home)
        self.assertEqual(code, 0, err)
        record = json.loads(out)
        self.assertEqual(record['epic'], 'deploy-ops')
        self.assertEqual(record['final_pr'], 476)
        self.assertEqual(record['tasks'], 5)
        self.assertEqual(record['started_at'], '2026-11-11T13:46:16Z')
        self.assertEqual(record['done_at'], '2026-11-12T21:56:10Z')
        self.assertAlmostEqual(record['wall']['to_done'], 1794520570.349689 - 1794404776.2295251, places=2)

    def test_prints_one_line_and_stores_the_record_in_the_state_directory(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        code, out, err = self.epic('deploy-ops', home=home)
        self.assertEqual(out.count('\n'), 1)
        with open(os.path.join(self.state, 'epics', 'deploy-ops.json'), encoding='utf-8') as f:
            self.assertEqual(json.load(f), json.loads(out))

    def test_default_ao_home_is_ao_epic_under_the_state_root(self):
        testkit.fixture_home(self.root, 'deploy-ops')
        with mock.patch.dict(os.environ, {'SHOP_METRICS_AO_ROOT': self.root}):
            code, out, err = self.epic('deploy-ops')
        self.assertEqual(code, 0, err)
        self.assertEqual(json.loads(out)['epic'], 'deploy-ops')

    def test_database_is_opened_read_only_and_never_changed(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        db = os.path.join(home, 'orchestrator.db')
        with open(db, 'rb') as f:
            before = f.read()
        uris = []
        real = sqlite3.connect

        def spy(target, *args, **kwargs):
            uris.append(target)
            return real(target, *args, **kwargs)

        with mock.patch.object(metrics.sqlite3, 'connect', spy):
            self.assertEqual(self.epic('deploy-ops', home=home)[0], 0)
        self.assertTrue(uris and all('mode=ro' in u for u in uris), uris)
        with open(db, 'rb') as f:
            self.assertEqual(f.read(), before)
        self.assertEqual(sorted(os.listdir(home)), ['events.jsonl', 'orchestrator.db'])


class ErrorPathTest(Env):
    def assert_fails(self, argv, *fragments, code=2):
        got, out, err = run_cli(argv)
        self.assertEqual(got, code, (out, err))
        self.assertEqual(out, '')
        for fragment in fragments:
            self.assertIn(fragment, err)
        self.assertTrue(err.startswith('metrics:'), err)

    def test_missing_ao_directory(self):
        missing = os.path.join(self.root, 'nope')
        self.assert_fails(['epic', 'x', '--ao-home', missing, '--state', self.state, '--night', self.night,
                           '--no-gh'], 'brak katalogu AO', missing)
        self.assertFalse(os.path.exists(os.path.join(self.state, 'epics', 'x.json')))

    def test_directory_without_database(self):
        home = os.path.join(self.root, 'ao-x')
        os.makedirs(home)
        self.assert_fails(['epic', 'x', '--ao-home', home, '--state', self.state, '--no-gh'], 'orchestrator.db')

    def test_unreadable_database(self):
        home = os.path.join(self.root, 'ao-x')
        os.makedirs(home)
        with open(os.path.join(home, 'orchestrator.db'), 'wb') as f:
            f.write(b'this is not a database' * 100)
        self.assert_fails(['epic', 'x', '--ao-home', home, '--state', self.state, '--no-gh'], 'nieczytelna baza')

    def test_locked_database(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        locker = sqlite3.connect(os.path.join(home, 'orchestrator.db'), isolation_level=None)
        self.addCleanup(locker.close)
        locker.execute('BEGIN EXCLUSIVE')
        self.assert_fails(['epic', 'deploy-ops', '--ao-home', home, '--state', self.state, '--no-gh',
                           '--busy-timeout-ms', '50'], 'zablokowana')

    def test_database_without_the_epic_row(self):
        home = testkit.synthetic_home(self.root, epic='other')
        self.assert_fails(['epic', 'x', '--ao-home', home, '--state', self.state, '--no-gh'], 'wiersza epiku x')

    def test_epic_without_a_final_pr_has_null_final_pr_and_a_gap(self):
        home = testkit.synthetic_home(self.root, epic='demo', final_pr=None, status='ACTIVE')
        record = self.record('demo', home=home)
        self.assertIsNone(record['final_pr'])
        self.assertTrue(any(g.startswith('final_pr') for g in record['gaps']), record['gaps'])

    def test_unwritable_state_directory(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        blocker = os.path.join(self.root, 'file')
        with open(blocker, 'w') as f:
            f.write('x')
        self.assert_fails(['epic', 'deploy-ops', '--ao-home', home, '--state', os.path.join(blocker, 'sub'),
                           '--night', self.night, '--no-gh'], 'nie można zapisać')


class ConfigTest(Env):
    def config(self, **changes):
        with open(metrics.DEFAULT_CONFIG, encoding='utf-8') as f:
            data = json.load(f)
        for key, value in changes.items():
            if value is KeyError:
                del data[key]
            else:
                data[key] = value
        path = os.path.join(self.root, 'config.json')
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(data, f)
        return path

    def run_with(self, path):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        return run_cli(['epic', 'deploy-ops', '--ao-home', home, '--state', self.state, '--night', self.night,
                        '--no-gh', '--config', path])

    def test_default_config_is_valid(self):
        self.assertEqual(self.run_with(metrics.DEFAULT_CONFIG)[0], 0)

    def test_config_that_is_not_json(self):
        path = os.path.join(self.root, 'config.json')
        with open(path, 'w') as f:
            f.write('{not json')
        code, out, err = self.run_with(path)
        self.assertEqual(code, 2)
        self.assertEqual(out, '')
        self.assertIn('config.json', err)

    def test_missing_config_file(self):
        code, out, err = self.run_with(os.path.join(self.root, 'absent.json'))
        self.assertEqual(code, 2)
        self.assertIn('config.json', err)

    def test_config_without_a_required_key(self):
        code, out, err = self.run_with(self.config(breakdown_order=KeyError))
        self.assertEqual(code, 2)
        self.assertIn('breakdown_order', err)

    def test_config_with_a_wrong_type(self):
        code, out, err = self.run_with(self.config(github_wait_cap_s='1800'))
        self.assertEqual(code, 2)
        self.assertIn('github_wait_cap_s', err)

    def test_config_with_an_unknown_breakdown_category(self):
        code, out, err = self.run_with(self.config(breakdown_order=['work', 'sleep']))
        self.assertEqual(code, 2)
        self.assertIn('breakdown_order', err)

    def test_config_with_a_bad_command_pattern(self):
        code, out, err = self.run_with(self.config(command_categories={'test': ['(unclosed']}))
        self.assertEqual(code, 2)
        self.assertIn('command_categories', err)


if __name__ == '__main__':
    unittest.main()

"""Tests of delivery_state.py: python3 -m unittest discover -s .claude/skills/delivery -p 'test_*.py'"""
import json
import os
import subprocess
import sys
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'delivery_state.py')


def git(repo, *args):
    return subprocess.run(['git', '-C', repo, *args], check=True, capture_output=True, text=True).stdout.strip()


class DeliveryStateTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = os.path.join(self.tmp.name, 'repo')
        os.makedirs(self.repo)
        git(self.repo, 'init', '-q', '-b', 'main')
        git(self.repo, 'config', 'user.email', 't@example.com')
        git(self.repo, 'config', 'user.name', 'T')
        self.commit('base')
        git(self.repo, 'checkout', '-q', '-b', 'feature/x')
        self.base = git(self.repo, 'rev-parse', 'HEAD')
        self.state = os.path.join(self.tmp.name, 'state', 'T-1.json')

    def tearDown(self):
        self.tmp.cleanup()

    def commit(self, name):
        with open(os.path.join(self.repo, name), 'w') as f:
            f.write(name)
        git(self.repo, 'add', name)
        git(self.repo, 'commit', '-q', '-m', name)
        return git(self.repo, 'rev-parse', 'HEAD')

    def cli(self, *args, cwd=None):
        return subprocess.run([sys.executable, SCRIPT, *args], capture_output=True, text=True, cwd=cwd or self.repo)

    def init_state(self, phase='preflight'):
        r = self.cli('checkpoint', self.state, '--phase', phase,
                     '--set', 'task_id="T-1"', '--set', 'risk="FAST"', '--set', 'worktree=' + json.dumps(self.repo),
                     '--set', 'base_sha=' + json.dumps(self.base), '--set', 'branch="feature/x"')
        self.assertEqual(0, r.returncode, r.stderr)
        return self.load()

    def load(self):
        with open(self.state) as f:
            return json.load(f)

    def resume(self):
        r = self.cli('resume-check', self.state)
        self.assertEqual(0, r.returncode, r.stderr)
        return json.loads(r.stdout)

    # checkpoint

    def test_checkpoint_creates_a_complete_state_with_head_from_git(self):
        s = self.init_state()
        self.assertEqual(2, s['schema_version'])
        self.assertEqual(self.base, s['head_sha'])
        self.assertEqual('IN_PROGRESS', s['status'])
        self.assertEqual(self.state + '.evidence.jsonl', s['evidence_file'])
        self.assertEqual(0, s['fix_rounds_used'])
        self.assertEqual([], s['blockers'])
        self.assertIn('updated_at', s)
        self.assertEqual({'status': 'missing', 'red': [], 'green': []}, s['tdd'])
        self.assertEqual([], [n for n in os.listdir(os.path.dirname(self.state)) if n.endswith('.tmp')])

    def test_checkpoint_keeps_previous_fields_and_follows_head(self):
        self.init_state()
        self.cli('checkpoint', self.state, '--phase', 'fixes', '--set', 'fix_rounds_used=1')
        head = self.commit('impl')
        self.cli('record-commit', self.state)
        r = self.cli('checkpoint', self.state, '--phase', 'review')
        self.assertEqual(0, r.returncode, r.stderr)
        s = self.load()
        self.assertEqual((1, 'review', head, 'T-1'), (s['fix_rounds_used'], s['phase'], s['head_sha'], s['task_id']))

    def test_invalid_checkpoint_fails_and_leaves_the_state_unchanged(self):
        self.init_state()
        before = open(self.state).read()
        for args in (['--phase', 'coding'], ['--phase', 'review', '--status', 'DONE'],
                     ['--phase', 'review', '--set', 'fix_rounds_used=3'], ['--phase', 'review', '--set', 'risk="LOW"'],
                     ['--phase', 'review', '--set', 'oops'], ['--phase', 'review', '--set', 'risk=not-json']):
            r = self.cli('checkpoint', self.state, *args)
            self.assertNotEqual(0, r.returncode, args)
            self.assertEqual(before, open(self.state).read(), args)

    def test_identity_of_an_existing_task_cannot_be_changed(self):
        self.init_state('review')
        other = self.commit('other')
        git(self.repo, 'reset', '-q', '--hard', self.base)
        before = open(self.state).read()
        for key, value in (('base_sha', other), ('task_id', 'T-2'), ('branch', 'feature/y'), ('worktree', self.tmp.name)):
            r = self.cli('checkpoint', self.state, '--phase', 'review', '--set', '%s=%s' % (key, json.dumps(value)))
            self.assertEqual(2, r.returncode, key)
            self.assertIn(key, r.stderr)
            self.assertEqual(before, open(self.state).read(), key)

    def test_identity_repeated_with_the_same_value_is_accepted(self):
        self.init_state()
        r = self.cli('checkpoint', self.state, '--phase', 'implementation', '--set', 'base_sha=' + json.dumps(self.base),
                     '--set', 'task_id="T-1"', '--set', 'branch="feature/x"', '--set', 'worktree=' + json.dumps(self.repo))
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertEqual('implementation', self.load()['phase'])

    def test_fields_computed_by_the_script_cannot_be_set(self):
        self.init_state('review')
        foreign = self.commit('foreign')
        before = open(self.state).read()
        for args in (['--set', 'head_sha=' + json.dumps(foreign)],
                     ['--status', 'BLOCKED', '--set', 'blockers=[{"id": "B1", "type": "technical"}]',
                      '--set', 'head_sha=' + json.dumps(foreign)],
                     ['--set', 'evidence_file="/tmp/other.jsonl"']):
            r = self.cli('checkpoint', self.state, '--phase', 'review', *args)
            self.assertEqual(2, r.returncode, args)
            self.assertEqual(before, open(self.state).read(), args)

    def test_operational_fields_still_change(self):
        self.init_state()
        head = self.commit('impl')
        self.cli('record-commit', self.state)
        r = self.cli('checkpoint', self.state, '--phase', 'fixes', '--set', 'risk="CRITICAL"', '--set', 'fix_rounds_used=2',
                     '--set', 'pr_url="https://example.com/pr/1"')
        self.assertEqual(0, r.returncode, r.stderr)
        s = self.load()
        self.assertEqual(('fixes', 'CRITICAL', 2, 'https://example.com/pr/1', head, self.base),
                         (s['phase'], s['risk'], s['fix_rounds_used'], s['pr_url'], s['head_sha'], s['base_sha']))

    def test_new_state_without_task_identity_is_rejected(self):
        r = self.cli('checkpoint', self.state, '--phase', 'preflight')
        self.assertNotEqual(0, r.returncode)
        self.assertFalse(os.path.exists(self.state))

    def test_corrupted_state_is_reported_not_overwritten(self):
        os.makedirs(os.path.dirname(self.state))
        with open(self.state, 'w') as f:
            f.write('{broken')
        for cmd in (['checkpoint', self.state, '--phase', 'review'], ['resume-check', self.state]):
            r = self.cli(*cmd)
            self.assertEqual(2, r.returncode, cmd)
            self.assertIn('not valid JSON', r.stderr)
        self.assertEqual('{broken', open(self.state).read())

    def test_missing_state_in_resume_check_is_an_error(self):
        r = self.cli('resume-check', self.state)
        self.assertEqual(2, r.returncode)
        self.assertIn('no state file', r.stderr)

    def test_checkpoint_does_not_move_head_past_an_unrecorded_commit(self):
        self.init_state('review')
        self.commit('foreign')
        before = open(self.state).read()
        r = self.cli('checkpoint', self.state, '--phase', 'pr_ci')
        self.assertNotEqual(0, r.returncode)
        self.assertIn('not recorded', r.stderr)
        self.assertEqual(before, open(self.state).read())

    def test_blocked_checkpoint_keeps_the_last_trusted_head(self):
        self.init_state('review')
        self.commit('foreign')
        r = self.cli('checkpoint', self.state, '--phase', 'review', '--status', 'BLOCKED',
                     '--set', 'blockers=[{"id": "B1", "type": "technical"}]')
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertEqual(self.base, self.load()['head_sha'])
        self.assertEqual('blocked', self.resume()['verdict'])

    def test_accept_head_takes_an_explained_commit(self):
        self.init_state('review')
        head = self.commit('rebased')
        r = self.cli('checkpoint', self.state, '--phase', 'review', '--accept-head')
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertEqual(head, self.load()['head_sha'])

    def test_checkpoint_follows_recorded_commits(self):
        self.init_state('implementation')
        head = self.commit('impl')
        self.cli('record-commit', self.state)
        self.assertEqual(0, self.cli('checkpoint', self.state, '--phase', 'local_checks').returncode)
        self.assertEqual(head, self.load()['head_sha'])

    # evidence

    def test_run_records_red_and_green_and_keeps_the_exit_code(self):
        self.init_state('implementation')
        red = self.cli('run', self.state, '--label', 'red', '--', sys.executable, '-c', 'print("FAIL: expected trim"); exit(1)')
        self.assertEqual(1, red.returncode)
        self.assertIn('FAIL: expected trim', red.stdout)
        green = self.cli('run', self.state, '--label', 'green', '--', sys.executable, '-c', 'print("OK (3 tests)")')
        self.assertEqual(0, green.returncode)
        entries = [json.loads(l) for l in open(self.state + '.evidence.jsonl')]
        self.assertEqual([('red', 1), ('green', 0)], [(e['label'], e['exit_code']) for e in entries])
        self.assertIn('FAIL: expected trim', entries[0]['output_tail'])
        self.assertEqual(self.base, entries[0]['head'])

    def test_run_of_a_missing_command_is_recorded_as_a_failure(self):
        self.init_state('implementation')
        r = self.cli('run', self.state, '--label', 'red', '--', 'definitely-not-a-command-xyz')
        self.assertEqual(127, r.returncode)
        entry = json.loads(open(self.state + '.evidence.jsonl').readline())
        self.assertEqual(127, entry['exit_code'])

    def test_unknown_label_is_rejected(self):
        self.init_state('implementation')
        r = self.cli('run', self.state, '--label', 'blue', '--', 'true')
        self.assertNotEqual(0, r.returncode)
        self.assertFalse(os.path.exists(self.state + '.evidence.jsonl'))

    def test_checkpoint_summarises_tdd_evidence(self):
        self.init_state('implementation')
        self.cli('run', self.state, '--label', 'red', '--', 'false')
        self.cli('run', self.state, '--label', 'green', '--', 'true')
        self.cli('checkpoint', self.state, '--phase', 'local_checks')
        tdd = self.load()['tdd']
        self.assertEqual('recorded', tdd['status'])
        self.assertEqual(([1], [0]), ([e['exit_code'] for e in tdd['red']], [e['exit_code'] for e in tdd['green']]))

    def test_red_that_passed_or_green_that_failed_is_not_evidence(self):
        self.init_state('implementation')
        self.cli('run', self.state, '--label', 'red', '--', 'true')
        self.cli('run', self.state, '--label', 'green', '--', 'false')
        self.cli('checkpoint', self.state, '--phase', 'local_checks')
        self.assertEqual('missing', self.load()['tdd']['status'])

    def test_verified_status_is_kept_and_the_evidence_lists_follow_the_log(self):
        self.init_state('implementation')
        self.cli('run', self.state, '--label', 'red', '--', 'false')
        self.cli('run', self.state, '--label', 'green', '--', 'true')
        self.cli('checkpoint', self.state, '--phase', 'local_checks', '--set', 'tdd={"status": "verified"}')
        self.cli('run', self.state, '--label', 'green', '--', 'true')
        self.cli('checkpoint', self.state, '--phase', 'review')
        tdd = self.load()['tdd']
        self.assertEqual(('verified', 1, 2), (tdd['status'], len(tdd['red']), len(tdd['green'])))

    def test_verified_without_red_and_green_is_rejected(self):
        self.init_state('implementation')
        r = self.cli('checkpoint', self.state, '--phase', 'local_checks', '--set', 'tdd={"status": "verified"}')
        self.assertNotEqual(0, r.returncode)
        self.assertEqual('implementation', self.load()['phase'])

    def test_manual_tdd_status_is_kept(self):
        self.init_state('implementation')
        self.cli('checkpoint', self.state, '--phase', 'local_checks', '--set', 'tdd={"status": "lost_verified_on_base", "red": [], "green": []}')
        self.cli('checkpoint', self.state, '--phase', 'review')
        self.assertEqual('lost_verified_on_base', self.load()['tdd']['status'])

    # resume-check

    def test_resume_with_current_checkpoint(self):
        self.init_state('local_checks')
        r = self.resume()
        self.assertEqual(('current', 'local_checks', []), (r['verdict'], r['phase'], r['commits']))

    def test_recorded_commit_after_the_checkpoint_is_recoverable(self):
        self.init_state('implementation')
        self.cli('run', self.state, '--label', 'red', '--', 'false')
        head = self.commit('impl')
        self.cli('run', self.state, '--label', 'green', '--', 'true')
        self.assertEqual(0, self.cli('record-commit', self.state).returncode)
        r = self.resume()
        self.assertEqual('recoverable', r['verdict'], r['reasons'])
        self.assertEqual([head], [c['sha'] for c in r['commits']])
        self.assertTrue(r['commits'][0]['recorded'])
        self.assertEqual('recorded', r['tdd']['status'])

    def test_unrecorded_commit_is_blocked(self):
        self.init_state('implementation')
        self.commit('foreign')
        r = self.resume()
        self.assertEqual('blocked', r['verdict'])
        self.assertTrue(any('not recorded' in x for x in r['reasons']))

    def test_one_foreign_commit_among_recorded_ones_is_blocked(self):
        self.init_state('implementation')
        self.commit('impl')
        self.cli('record-commit', self.state)
        self.commit('foreign')
        self.assertEqual('blocked', self.resume()['verdict'])

    def test_rewritten_history_is_blocked(self):
        self.init_state('implementation')
        self.commit('impl')
        self.cli('record-commit', self.state)
        self.cli('checkpoint', self.state, '--phase', 'review')
        git(self.repo, 'reset', '-q', '--hard', 'main')
        git(self.repo, 'commit', '-q', '--allow-empty', '-m', 'other')
        self.cli('record-commit', self.state)
        r = self.resume()
        self.assertEqual('blocked', r['verdict'])
        self.assertTrue(any('not an ancestor' in x for x in r['reasons']))

    def test_uncommitted_changes_are_blocked(self):
        self.init_state('implementation')
        with open(os.path.join(self.repo, 'base'), 'w') as f:
            f.write('edited')
        r = self.resume()
        self.assertEqual('blocked', r['verdict'])
        self.assertTrue(any('uncommitted' in x for x in r['reasons']))

    def test_other_branch_is_blocked(self):
        self.init_state('implementation')
        git(self.repo, 'checkout', '-q', 'main')
        r = self.resume()
        self.assertEqual('blocked', r['verdict'])
        self.assertTrue(any('branch' in x for x in r['reasons']))

    def test_recoverable_commit_without_evidence_reports_missing_tdd(self):
        self.init_state('implementation')
        self.commit('impl')
        self.cli('record-commit', self.state)
        r = self.resume()
        self.assertEqual(('recoverable', 'missing'), (r['verdict'], r['tdd']['status']))


if __name__ == '__main__':
    unittest.main()

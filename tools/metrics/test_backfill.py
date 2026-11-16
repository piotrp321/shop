"""Tests of `metrics.py backfill`: the runs whose final PR is MERGED."""
import json
import os
import sqlite3
import unittest

import testkit
from testkit import Env, FakeGh, merged_pr, run_cli


class BackfillTest(Env):
    def setUp(self):
        super().setUp()
        self.ao = os.path.join(self.root, 'ao-root')
        os.makedirs(self.ao)

    def run_dir(self, epic, status='DONE', final_pr=100, **kwargs):
        kwargs.setdefault('jsonl', [])
        kwargs.setdefault('tasks', [('T-1', 'plan')])
        return testkit.synthetic_home(self.ao, epic=epic, status=status, final_pr=final_pr, created=1000.0,
                                      events=[(5000, 'epic_done', {})] if status == 'DONE' else [], **kwargs)

    def backfill(self, gh, *extra):
        code, out, err = run_cli(['backfill', '--ao-root', self.ao, '--state', self.state, '--night', self.night]
                                 + list(extra), gh)
        return code, (json.loads(out) if out else None), err

    def stored(self):
        directory = os.path.join(self.state, 'epics')
        return sorted(n[:-5] for n in os.listdir(directory)) if os.path.isdir(directory) else []

    def skipped(self, result):
        return {s['run']: s['reason'] for s in result['skipped']}

    def test_records_merged_runs_in_any_state_of_ao(self):
        self.run_dir('done-epic', 'DONE', 11)
        self.run_dir('integrating-epic', 'INTEGRATING', 12)
        self.run_dir('blocked-epic', 'BLOCKED', 13)
        gh = FakeGh(prs={11: merged_pr(), 12: merged_pr(), 13: merged_pr()})
        code, result, err = self.backfill(gh)
        self.assertEqual(code, 0, err)
        self.assertEqual(sorted(result['recorded']), ['blocked-epic', 'done-epic', 'integrating-epic'])
        self.assertEqual(self.stored(), ['blocked-epic', 'done-epic', 'integrating-epic'])
        with open(os.path.join(self.state, 'epics', 'blocked-epic.json'), encoding='utf-8') as f:
            record = json.load(f)
        self.assertEqual((record['epic'], record['final_pr'], record['merged_at']), ('blocked-epic', 13,
                                                                                    '2026-11-13T10:00:00Z'))

    def test_skips_runs_in_progress_and_runs_whose_final_pr_is_not_merged(self):
        self.run_dir('running', 'ACTIVE', None)
        self.run_dir('review', 'DONE', 21)
        self.run_dir('merged', 'DONE', 22)
        gh = FakeGh(prs={21: {'state': 'OPEN', 'mergedAt': None, 'closingIssuesReferences': []}, 22: merged_pr()})
        code, result, err = self.backfill(gh)
        self.assertEqual(code, 0, err)
        self.assertEqual(result['recorded'], ['merged'])
        skipped = self.skipped(result)
        self.assertIn('końcowego PR', skipped['ao-running'])
        self.assertIn('OPEN', skipped['ao-review'])
        self.assertEqual(self.stored(), ['merged'])
        self.assertNotIn(['gh', 'pr', 'view', '100'], gh.calls)

    def test_skips_directories_without_the_epic_row_or_a_database(self):
        os.makedirs(os.path.join(self.ao, 'ao-night'))                   # no database
        os.makedirs(os.path.join(self.ao, 'ao-empty'))
        os.makedirs(os.path.join(self.ao, 'shop-metrics'))               # not an AO directory
        testkit.synthetic_home(self.ao, epic='someone-else', final_pr=31)
        os.rename(os.path.join(self.ao, 'ao-someone-else'), os.path.join(self.ao, 'ao-test-run'))
        self.run_dir('merged', 'DONE', 32)
        gh = FakeGh(prs={31: merged_pr(), 32: merged_pr()})
        code, result, err = self.backfill(gh)
        self.assertEqual(code, 0, err)
        self.assertEqual(result['recorded'], ['merged'])
        skipped = self.skipped(result)
        self.assertEqual(sorted(skipped), ['ao-empty', 'ao-night', 'ao-test-run'])
        self.assertIn('orchestrator.db', skipped['ao-night'])
        self.assertIn('wiersza epiku', skipped['ao-test-run'])
        self.assertEqual(self.stored(), ['merged'])

    def test_empty_root_records_nothing(self):
        code, result, err = self.backfill(FakeGh())
        self.assertEqual((code, result), (0, {'recorded': [], 'skipped': []}))

    def test_gh_error_skips_that_run_and_ends_with_exit_code_3(self):
        self.run_dir('merged', 'DONE', 41)
        gh = FakeGh(prs={41: merged_pr()})
        self.run_dir('unknown-pr', 'DONE', 42)               # `gh pr view 42` fails: no such PR
        code, result, err = self.backfill(gh)
        self.assertEqual(code, 3)
        self.assertEqual(result['recorded'], ['merged'])
        self.assertIn('gh', self.skipped(result)['ao-unknown-pr'])
        self.assertIn('unknown-pr', err)
        self.assertEqual(self.stored(), ['merged'])

    def test_unreadable_database_skips_that_run_and_ends_with_exit_code_3(self):
        home = os.path.join(self.ao, 'ao-broken')
        os.makedirs(home)
        with open(os.path.join(home, 'orchestrator.db'), 'wb') as f:
            f.write(b'not a database' * 100)
        self.run_dir('merged', 'DONE', 51)
        code, result, err = self.backfill(FakeGh(prs={51: merged_pr()}))
        self.assertEqual(code, 3)
        self.assertEqual(result['recorded'], ['merged'])
        self.assertIn('nieczytelna baza', self.skipped(result)['ao-broken'])
        self.assertIn('broken', err)

    def test_locked_database_skips_that_run_and_ends_with_exit_code_3(self):
        home = self.run_dir('busy', 'DONE', 61)
        locker = sqlite3.connect(os.path.join(home, 'orchestrator.db'), isolation_level=None)
        self.addCleanup(locker.close)
        locker.execute('BEGIN EXCLUSIVE')
        code, result, err = self.backfill(FakeGh(prs={61: merged_pr()}), '--busy-timeout-ms', '50')
        self.assertEqual(code, 3)
        self.assertEqual(result['recorded'], [])
        self.assertIn('zablokowana', self.skipped(result)['ao-busy'])

    def test_missing_ao_root_is_an_error(self):
        missing = os.path.join(self.root, 'nope')
        code, out, err = run_cli(['backfill', '--ao-root', missing, '--state', self.state], FakeGh())
        self.assertEqual(code, 2)
        self.assertEqual(out, '')
        self.assertIn(missing, err)

    def test_backfill_needs_gh_there_is_no_no_gh_option(self):
        code, out, err = run_cli(['backfill', '--ao-root', self.ao, '--state', self.state, '--no-gh'], FakeGh())
        self.assertEqual(code, 2)

    def test_second_run_replaces_the_records(self):
        self.run_dir('merged', 'DONE', 71)
        self.backfill(FakeGh(prs={71: merged_pr('2026-11-13T10:00:00Z', (1,))}))
        code, result, err = self.backfill(FakeGh(prs={71: merged_pr('2026-11-13T10:00:00Z', (2,))}))
        self.assertEqual(result['recorded'], ['merged'])
        with open(os.path.join(self.state, 'epics', 'merged.json'), encoding='utf-8') as f:
            self.assertEqual(json.load(f)['issue'], 2)

    def test_the_fixture_run_is_recorded_with_its_gaps(self):
        testkit.fixture_home(self.ao, 'deploy-ops')
        code, result, err = self.backfill(FakeGh(prs={476: merged_pr()}))
        self.assertEqual((code, result['recorded']), (0, ['deploy-ops']))
        with open(os.path.join(self.state, 'epics', 'deploy-ops.json'), encoding='utf-8') as f:
            record = json.load(f)
        self.assertIsNone(record['subscription'])
        self.assertTrue(record['gaps'])


if __name__ == '__main__':
    unittest.main()

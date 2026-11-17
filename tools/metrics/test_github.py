"""Tests of the GitHub fields (`gh`, read only), missing sources and the record cache."""
import json
import os
import subprocess
import unittest
from datetime import datetime

import testkit
from test_context import NightEnv, epoch, start, done
from testkit import Env, FakeGh, merged_pr

MERGED_AT = '2026-11-13T10:00:00Z'


def started_deploy_ops():
    return datetime.fromisoformat('2026-11-11T13:46:16.229525+00:00').timestamp()


class GithubFieldsTest(Env):
    def deploy_ops(self, gh, *extra, no_gh=False):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        return self.epic('deploy-ops', *extra, home=home, runner=gh, no_gh=no_gh)

    def test_merged_final_pr_gives_issue_merge_time_and_review_wall(self):
        gh = FakeGh(prs={476: merged_pr(MERGED_AT, issues=(496, 500))})
        code, out, err = self.deploy_ops(gh)
        self.assertEqual(code, 0, err)
        record = json.loads(out)
        self.assertEqual(record['issue'], 496)
        self.assertEqual(record['merged_at'], MERGED_AT)
        merged = datetime.fromisoformat('2026-11-13T10:00:00+00:00').timestamp()
        self.assertAlmostEqual(record['wall']['to_merge'], merged - 1794404776.2295251, places=2)
        self.assertAlmostEqual(record['wall']['review'], merged - 1794520570.349689, places=2)

    def test_only_gh_is_run_and_only_for_reading(self):
        gh = FakeGh(prs={476: merged_pr()})
        self.deploy_ops(gh)
        self.assertTrue(gh.calls)
        for call in gh.calls:
            self.assertEqual(call[0], 'gh')
            self.assertIn(call[1], ('pr', 'run', 'issue', 'api', 'repo'))
            if call[1] == 'repo':
                self.assertEqual(call[2], 'view')
            elif call[1] == 'api':
                self.assertTrue(call[2].startswith('repos/') or call[2] == 'graphql', call)
            else:
                self.assertIn(call[2], ('view', 'list'))
            self.assertFalse({'edit', 'merge', 'close', 'comment', 'create'} & set(call), call)
        self.assertEqual(gh.calls[0][:4], ['gh', 'pr', 'view', '476'])

    def test_repo_option_is_passed_to_gh(self):
        gh = FakeGh(prs={476: merged_pr()})
        self.deploy_ops(gh, '--repo', 'owner/shop')
        self.assertTrue(all(c[-2:] == ['-R', 'owner/shop'] for c in gh.calls if c[1] != 'api'), gh.calls)
        self.assertTrue(all('owner/shop' in c[2] and '-R' not in c for c in gh.calls if c[1] == 'api'), gh.calls)

    def test_final_pr_that_is_not_merged_has_no_merge_time_and_a_gap(self):
        gh = FakeGh(prs={476: {'state': 'OPEN', 'mergedAt': None, 'closingIssuesReferences': [{'number': 496}]}})
        code, out, err = self.deploy_ops(gh)
        record = json.loads(out)
        self.assertEqual(code, 0, err)
        self.assertIsNone(record['merged_at'])
        self.assertIsNone(record['wall']['to_merge'])
        self.assertIsNone(record['wall']['review'])
        self.assertTrue(any(g.startswith('merged_at') and 'OPEN' in g for g in record['gaps']), record['gaps'])

    def test_final_pr_without_a_closing_issue_has_a_null_issue_and_a_gap(self):
        code, out, err = self.deploy_ops(FakeGh(prs={476: merged_pr(issues=())}))
        record = json.loads(out)
        self.assertIsNone(record['issue'])
        self.assertTrue(any(g.startswith('issue') for g in record['gaps']), record['gaps'])

    def test_sync_prs_of_the_epic(self):
        gh = FakeGh(prs={476: merged_pr()}, sync=[{'number': 480, 'state': 'MERGED', 'createdAt': '2026-11-12T10:00:00Z',
                                                  'mergedAt': '2026-11-12T11:00:00Z'}])
        record = json.loads(self.deploy_ops(gh)[1])
        self.assertEqual(record['retries']['sync_prs'], [{'number': 480, 'state': 'MERGED'}])
        self.assertIn('sync/deploy-ops-main', gh.calls[1])

    def test_no_sync_prs_is_an_empty_list_not_null(self):
        record = json.loads(self.deploy_ops(FakeGh(prs={476: merged_pr()}))[1])
        self.assertEqual(record['retries']['sync_prs'], [])

    def test_final_pr_missing_in_the_repository_is_a_gh_error(self):
        code, out, err = self.deploy_ops(FakeGh(prs={}))
        self.assertEqual(code, 3)
        self.assertIn('gh', err)

    def test_attempt_without_outcome_is_failed_when_the_final_pr_is_merged(self):
        def run(no_gh, gh=None):
            home = testkit.synthetic_home(self.root, status='INTEGRATING', created=1000.0, jsonl=[],
                                          tasks=[('T-1', 'plan')],
                                          attempts=[dict(task_id='T-1', started_at=1100, ended_at=None,
                                                         outcome=None, executor_status=None)])
            return json.loads(self.epic('demo', home=home, runner=gh, no_gh=no_gh)[1])['retries']['attempts']
        self.assertEqual(run(True)['running'], 1)
        merged = run(False, FakeGh(prs={7: merged_pr()}))
        self.assertEqual((merged['running'], merged['failed']), (0, 1))


    def test_attempt_classes_do_not_change_when_gh_fails_after_a_merged_answer(self):
        home = testkit.synthetic_home(self.root, status='INTEGRATING', created=1000.0, jsonl=[],
                                      tasks=[('T-1', 'plan')],
                                      attempts=[dict(task_id='T-1', started_at=1100, ended_at=None,
                                                     outcome=None, executor_status=None)])
        first = self.epic('demo', home=home, runner=FakeGh(prs={7: merged_pr()}), no_gh=False)
        self.assertEqual(first[0], 0, first[2])
        again = self.epic('demo', home=home, runner=FakeGh(fail='HTTP 502'), no_gh=False)
        self.assertEqual(again[0], 3, again[2])
        merged, repeated = json.loads(first[1]), json.loads(again[1])
        self.assertEqual(repeated['merged_at'], merged['merged_at'])
        self.assertEqual(repeated['retries']['attempts'], merged['retries']['attempts'])
        self.assertEqual((repeated['retries']['attempts']['running'], repeated['retries']['attempts']['failed']),
                         (0, 1))


class MissingSourceTest(Env):
    """A missing source is `null` and a gap, never zero."""

    def test_gh_error_gives_nulls_gaps_the_record_and_exit_code_3(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        code, out, err = self.epic('deploy-ops', home=home, runner=FakeGh(fail='HTTP 502'), no_gh=False)
        self.assertEqual(code, 3)
        self.assertTrue(err.startswith('metrics:') and 'HTTP 502' in err, err)
        record = json.loads(out)
        for path in (('issue',), ('merged_at',), ('wall', 'to_merge'), ('wall', 'review'), ('retries', 'sync_prs')):
            value = record
            for key in path:
                value = value[key]
            self.assertIsNone(value, path)
        for field in ('issue', 'merged_at', 'retries.sync_prs'):
            self.assertTrue(any(g.startswith(field) and 'gh' in g for g in record['gaps']), (field, record['gaps']))
        self.assertTrue(os.path.exists(os.path.join(self.state, 'epics', 'deploy-ops.json')))

    def test_gh_that_cannot_be_run_or_times_out_is_a_gh_error(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')

        def missing(command):
            raise FileNotFoundError('gh')

        def slow(command):
            raise subprocess.TimeoutExpired(command, 60)

        for runner in (missing, slow):
            code, out, err = self.epic('deploy-ops', home=home, runner=runner, no_gh=False)
            self.assertEqual(code, 3, err)
            self.assertIn('metrics:', err)
            self.assertIsNone(json.loads(out)['merged_at'])

    def test_gh_answer_that_is_not_json_is_a_gh_error(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        code, out, err = self.epic('deploy-ops', home=home, runner=lambda command: testkit.result(0, 'oops'),
                                   no_gh=False)
        self.assertEqual(code, 3)
        self.assertIn('metrics:', err)

    def test_no_gh_option_gives_nulls_gaps_and_exit_code_0(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        gh = FakeGh(prs={476: merged_pr()})
        code, out, err = self.epic('deploy-ops', home=home, runner=gh, no_gh=True)
        self.assertEqual(code, 0, err)
        self.assertEqual(gh.calls, [])
        record = json.loads(out)
        self.assertIsNone(record['merged_at'])
        self.assertTrue(any(g.startswith('merged_at') and '--no-gh' in g for g in record['gaps']), record['gaps'])

    def test_missing_events_jsonl_gives_null_human_and_a_gap(self):
        home = testkit.fixture_home(self.root, 'deploy-ops', copy_events=False)
        record = self.record('deploy-ops', home=home)
        self.assertIsNone(record['breakdown']['human_wait'])
        self.assertIsNone(record['human']['waits'])
        self.assertTrue(any('events.jsonl' in g for g in record['gaps']), record['gaps'])

    def test_missing_epic_done_gives_nulls_and_gaps(self):
        home = testkit.synthetic_home(self.root, created=1000.0, jsonl=[], tasks=[('T-1', 'plan')])
        record = self.record('demo', home=home)
        for value in (record['done_at'], record['wall']['to_done'], record['breakdown']):
            self.assertIsNone(value)
        self.assertIsNone(record['wall'].get('review'))

    def test_missing_measurements_file_gives_nulls_and_gaps(self):
        record = self.record('deploy-ops', home=testkit.fixture_home(self.root, 'deploy-ops'))
        self.assertIsNone(record['subscription'])
        self.assertTrue(any('measurements.jsonl' in g for g in record['gaps']), record['gaps'])


class CacheTest(Env):
    def run_once(self, gh, no_gh=False, *extra):
        home = os.path.join(self.root, 'ao-deploy-ops')
        if not os.path.isdir(home):
            home = testkit.fixture_home(self.root, 'deploy-ops')
        return self.epic('deploy-ops', *extra, home=home, runner=gh, no_gh=no_gh)

    def stored(self):
        with open(os.path.join(self.state, 'epics', 'deploy-ops.json'), encoding='utf-8') as f:
            return json.load(f)

    def good_gh(self):
        return FakeGh(prs={476: merged_pr(MERGED_AT, (496,))}, sync=[
            {'number': 480, 'state': 'MERGED', 'createdAt': '2026-11-12T10:00:00Z', 'mergedAt': None}])

    def test_gh_error_never_replaces_stored_values_with_null(self):
        self.assertEqual(self.run_once(self.good_gh())[0], 0)
        code, out, err = self.run_once(FakeGh(fail='HTTP 502'))
        self.assertEqual(code, 3)
        record = json.loads(out)
        self.assertEqual((record['issue'], record['merged_at']), (496, MERGED_AT))
        self.assertEqual(record['retries']['sync_prs'], [{'number': 480, 'state': 'MERGED'}])
        self.assertIsNotNone(record['wall']['to_merge'])
        self.assertIsNotNone(record['wall']['review'])
        self.assertTrue(any('gh' in g and g.startswith('merged_at') for g in record['gaps']), record['gaps'])
        self.assertTrue(any('zachowano' in g for g in record['gaps']), record['gaps'])
        self.assertEqual(self.stored(), record)

    def test_no_gh_never_replaces_stored_values_with_null(self):
        self.run_once(self.good_gh())
        code, out, err = self.run_once(None, no_gh=True)
        self.assertEqual(code, 0, err)
        record = json.loads(out)
        self.assertEqual((record['issue'], record['merged_at']), (496, MERGED_AT))

    def test_a_new_value_replaces_the_stored_one(self):
        self.run_once(self.good_gh())
        record = json.loads(self.run_once(FakeGh(prs={476: merged_pr('2026-11-14T09:00:00Z', (497,))}))[1])
        self.assertEqual((record['issue'], record['merged_at']), (497, '2026-11-14T09:00:00Z'))

    def test_only_source_fields_are_kept_other_values_are_recomputed(self):
        self.run_once(self.good_gh())
        path = os.path.join(self.root, 'ao-deploy-ops', 'orchestrator.db')
        import sqlite3
        con = sqlite3.connect(path)
        con.execute("DELETE FROM events WHERE type = 'epic_done'")
        con.commit()
        con.close()
        record = json.loads(self.run_once(FakeGh(fail='x'))[1])
        self.assertIsNone(record['done_at'])
        # recomputed, not the stored one: without epic_done it ends at the merge kept from the stored record
        self.assertEqual(record['breakdown_end'], 'merged_at')
        self.assertIs(record['breakdown_estimated'], True)
        self.assertAlmostEqual(sum(record['breakdown'].values()), record['wall']['to_merge'], places=2)

    def test_unreadable_stored_record_is_ignored(self):
        os.makedirs(os.path.join(self.state, 'epics'))
        with open(os.path.join(self.state, 'epics', 'deploy-ops.json'), 'w') as f:
            f.write('{broken')
        code, out, err = self.run_once(FakeGh(fail='x'))
        self.assertEqual(code, 3)
        self.assertIsNone(json.loads(out)['merged_at'])

    def test_manual_fixes_are_kept_when_a_later_run_does_not_pass_them(self):
        path = os.path.join(self.root, 'fixes.txt')
        with open(path, 'w', encoding='utf-8') as f:
            f.write('- PR #61: konflikt\n')
        self.run_once(self.good_gh(), False, '--manual-fixes', path)
        record = json.loads(self.run_once(self.good_gh())[1])
        self.assertEqual(record['human']['manual_fixes'], [{'pr': 61, 'text': 'konflikt'}])


class SyncInOverlapTest(NightEnv):
    def test_sync_pr_created_during_the_overlap_is_listed_in_parallel(self):
        self.write_night([start('demo', 110), start('other', 2000), done('other', 3000), done('demo', 5010)])
        home = self.demo()
        created = datetime.fromtimestamp(epoch(2500)).astimezone().isoformat()
        outside = datetime.fromtimestamp(epoch(4000)).astimezone().isoformat()
        gh = FakeGh(prs={7: merged_pr()}, sync=[{'number': 81, 'state': 'MERGED', 'createdAt': created},
                                                {'number': 82, 'state': 'MERGED', 'createdAt': outside}])
        record = self.record('demo', home=home, runner=gh, no_gh=False)
        self.assertEqual(record['parallel']['sync_prs'], [81])
        self.assertEqual(record['retries']['sync_prs'], [{'number': 81, 'state': 'MERGED'},
                                                         {'number': 82, 'state': 'MERGED'}])


if __name__ == '__main__':
    unittest.main()

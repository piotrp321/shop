"""Tests of `retries` (attempt classes of the spec section Pojęcia, REPAIR cause, FIX) and `integration`."""
import unittest

import testkit
from testkit import Env


class AttemptClassTest(Env):
    """One attempt of the epic `demo` per test; the epic is done (epic_done) unless `ended` is False."""

    def classes(self, outcome, status=None, ended=True, number=1):
        events = [(5000, 'epic_done', {})] if ended else []
        home = testkit.synthetic_home(
            self.root, created=1000.0, status='DONE' if ended else 'ACTIVE', tasks=[('T-1', 'plan')], events=events,
            attempts=[dict(task_id='T-1', number=number, started_at=1100, ended_at=1200, outcome=outcome,
                           executor_status=status)], jsonl=[])
        record = self.record('demo', home=home)
        return record['retries']['attempts'], record

    def assert_class(self, got, expected):
        for name in ('successful', 'blocked', 'failed', 'usage_limit', 'running', 'unclassified'):
            self.assertEqual(got[name], 1 if name == expected else 0, (name, got))

    def test_result_with_a_finished_status_is_successful(self):
        for status in ('READY_FOR_INTEGRATION', 'PASSED', 'FINDINGS', None):
            with self.subTest(status=status):
                self.assert_class(self.classes('result', status)[0], 'successful')

    def test_result_blocked_is_blocked_not_failed(self):
        got, record = self.classes('result', 'BLOCKED')
        self.assert_class(got, 'blocked')
        self.assertEqual(record['retries']['failed_by_outcome'], {})

    def test_result_failed_is_failed(self):
        got, record = self.classes('result', 'FAILED')
        self.assert_class(got, 'failed')
        self.assertEqual(record['retries']['failed_by_outcome'], {'result_failed': 1})

    def test_each_failing_outcome_is_failed(self):
        for outcome in ('timeout', 'invalid_output', 'crash', 'policy_blocked'):
            with self.subTest(outcome=outcome):
                got, record = self.classes(outcome)
                self.assert_class(got, 'failed')
                self.assertEqual(record['retries']['failed_by_outcome'], {outcome: 1})

    def test_usage_limit_is_neither_successful_nor_failed(self):
        got, record = self.classes('usage_limit')
        self.assert_class(got, 'usage_limit')
        self.assertEqual(record['retries']['failed_by_outcome'], {})

    def test_blocked_status_wins_over_the_outcome(self):
        self.assert_class(self.classes('timeout', 'BLOCKED')[0], 'blocked')

    def test_empty_outcome_after_the_end_of_the_run_is_failed(self):
        for empty in (None, ''):
            with self.subTest(outcome=empty):
                got, record = self.classes(empty, ended=True)
                self.assert_class(got, 'failed')
                self.assertEqual(record['retries']['failed_by_outcome'], {'no_outcome': 1})

    def test_empty_outcome_in_a_running_epic_is_running(self):
        self.assert_class(self.classes(None, ended=False)[0], 'running')

    def test_unknown_outcome_is_unclassified_with_a_gap(self):
        got, record = self.classes('surprise')
        self.assert_class(got, 'unclassified')
        self.assertTrue(any(g.startswith('retries.attempts') and 'surprise' in g for g in record['gaps']),
                        record['gaps'])

    def test_attempts_per_task_and_total(self):
        home = testkit.synthetic_home(
            self.root, created=1000.0, tasks=[('T-1', 'plan'), ('T-2', 'plan')], events=[(5000, 'epic_done', {})],
            attempts=[dict(task_id='T-1', number=1, started_at=1100, ended_at=1200),
                      dict(task_id='T-1', number=2, started_at=1300, ended_at=1400),
                      dict(task_id='T-2', number=1, started_at=1500, ended_at=1600)], jsonl=[])
        attempts = self.record('demo', home=home)['retries']['attempts']
        self.assertEqual(attempts['per_task'], {'T-1': 2, 'T-2': 1})
        self.assertEqual(attempts['total'], 3)

    def test_deploy_ops_fixture(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        retries = self.record('deploy-ops', home=home)['retries']
        attempts = retries['attempts']
        self.assertEqual((attempts['total'], attempts['successful'], attempts['blocked'], attempts['failed'],
                          attempts['usage_limit']), (18, 12, 4, 2, 0))
        self.assertEqual(retries['failed_by_outcome'], {'timeout': 1, 'invalid_output': 1})
        self.assertEqual(attempts['per_task']['DEPLOY-OPS-4'], 6)
        self.assertEqual(attempts['per_task']['DEPLOY-OPS-5'], 2)


class RepairAndFixTest(Env):
    def run_record(self, tasks, events, rounds=1):
        home = testkit.synthetic_home(self.root, created=1000.0, tasks=tasks, rounds=rounds, jsonl=[],
                                      events=list(events) + [(5000, 'epic_done', {})])
        return self.record('demo', home=home)

    def test_repair_for_the_final_pr_is_a_conflict(self):
        record = self.run_record([('R-1', 'repair')], [(1100, 'epic_repairing', {'task_id': 'R-1', 'final_pr': 476})])
        self.assertEqual(record['retries']['repair'], {'count': 1, 'final_pr_conflict': 1, 'ci_red': 0, 'unknown': 0})

    def test_repair_without_final_pr_is_a_red_branch_ci(self):
        record = self.run_record([('R-1', 'repair')], [(1100, 'epic_repairing', {'task_id': 'R-1', 'sha': 'a' * 40})])
        self.assertEqual(record['retries']['repair'], {'count': 1, 'final_pr_conflict': 0, 'ci_red': 1, 'unknown': 0})

    def test_repair_task_without_an_event_has_an_unknown_cause_and_a_gap(self):
        record = self.run_record([('R-1', 'repair')], [])
        self.assertEqual(record['retries']['repair'], {'count': 1, 'final_pr_conflict': 0, 'ci_red': 0, 'unknown': 1})
        self.assertTrue(any(g.startswith('retries.repair') for g in record['gaps']), record['gaps'])

    def test_no_repair_is_zero_because_the_database_says_so(self):
        record = self.run_record([('T-1', 'plan')], [])
        self.assertEqual(record['retries']['repair']['count'], 0)
        self.assertEqual(record['retries']['fix'], 0)

    def test_fix_tasks_are_counted(self):
        record = self.run_record([('T-1', 'plan'), ('F-1', 'fix'), ('F-2', 'fix')], [])
        self.assertEqual(record['retries']['fix'], 2)

    def test_deploy_ops_fixture_repairs_and_fixes(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        retries = self.record('deploy-ops', home=home)['retries']
        self.assertEqual(retries['repair'], {'count': 2, 'final_pr_conflict': 1, 'ci_red': 1, 'unknown': 0})
        self.assertEqual(retries['fix'], 1)


class IntegrationTest(Env):
    def test_integration_sums_the_attempts_of_integration_fix_and_finalize(self):
        home = testkit.synthetic_home(
            self.root, created=1000.0, rounds=2, jsonl=[], events=[(9000, 'epic_done', {})],
            tasks=[('T-1', 'plan'), ('I-1', 'integration'), ('F-1', 'fix'), ('Z-1', 'finalize'), ('R-1', 'repair')],
            attempts=[dict(task_id='T-1', started_at=1100, ended_at=1900),
                      dict(task_id='I-1', started_at=2000, ended_at=2100),
                      dict(task_id='F-1', started_at=2200, ended_at=2500),
                      dict(task_id='Z-1', started_at=2600, ended_at=2650),
                      dict(task_id='R-1', started_at=2700, ended_at=3000)])
        integration = self.record('demo', home=home)['integration']
        self.assertEqual(integration, {'work_s': 450.0, 'attempts': 3, 'rounds': 2})

    def test_integration_without_attempts_is_zero_work(self):
        home = testkit.synthetic_home(self.root, created=1000.0, jsonl=[], events=[(9000, 'epic_done', {})],
                                      tasks=[('T-1', 'plan')])
        integration = self.record('demo', home=home)['integration']
        self.assertEqual(integration, {'work_s': 0.0, 'attempts': 0, 'rounds': 1})

    def test_attempt_without_wall_uses_its_start_and_end(self):
        home = testkit.synthetic_home(self.root, created=1000.0, jsonl=[], events=[(9000, 'epic_done', {})],
                                      tasks=[('I-1', 'integration')],
                                      attempts=[dict(task_id='I-1', started_at=2000, ended_at=2100, wall_s=None)])
        self.assertEqual(self.record('demo', home=home)['integration']['work_s'], 100.0)

    def test_attempt_without_wall_and_end_is_left_out_with_a_gap(self):
        home = testkit.synthetic_home(self.root, created=1000.0, jsonl=[], events=[(9000, 'epic_done', {})],
                                      tasks=[('I-1', 'integration')],
                                      attempts=[dict(task_id='I-1', started_at=2000, ended_at=None, outcome=None)])
        record = self.record('demo', home=home)
        self.assertEqual(record['integration']['work_s'], 0.0)
        self.assertTrue(any(g.startswith('integration') for g in record['gaps']), record['gaps'])

    def test_deploy_ops_fixture(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        integration = self.record('deploy-ops', home=home)['integration']
        self.assertAlmostEqual(integration['work_s'], 2191.274 + 2432.911 + 1598.294 + 352.013 + 1897.711, places=2)
        self.assertEqual((integration['attempts'], integration['rounds']), (5, 3))


if __name__ == '__main__':
    unittest.main()

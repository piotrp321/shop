"""Tests of the `breakdown` of the epic record: the categories of the spec table, their precedence and sources."""
import os
import unittest

import testkit
from testkit import Env

CATEGORIES = ('work', 'human_wait', 'limit_wait', 'ci_wait', 'host_pause', 'github_wait', 'idle')


def blocked(at, task=None):
    """A line of events.jsonl that starts a wait for the user."""
    if task is None:
        return {'at': at, 'type': 'epic_blocked', 'task_id': None, 'epic_id': 'demo', 'data': {}}
    return {'at': at, 'type': 'status_changed', 'task_id': task, 'epic_id': 'demo', 'data': {'to': 'BLOCKED'}}


def answered(at, task=None):
    return {'at': at, 'type': 'human_action', 'task_id': task, 'epic_id': None, 'data': {'action': 'answer'}}


class BreakdownTest(Env):
    """Synthetic runs: the epic starts at 1000 and `epic_done` is at 1000 + `length`."""

    def breakdown(self, length=2000, attempts=(), events=(), jsonl=(), jsonl_present=True):
        done = 1000.0 + length
        home = testkit.synthetic_home(
            self.root, created=1000.0, tasks=[('T-1', 'plan')], attempts=attempts,
            events=list(events) + [(done, 'epic_done', {})], jsonl=list(jsonl) if jsonl_present else None)
        record = self.record('demo', home=home)
        return record['breakdown'], record

    def attempt(self, start, end, number=1, task='T-1', **extra):
        return dict(task_id=task, number=number, started_at=start, ended_at=end, **extra)

    def assert_sums_to_done(self, breakdown, record):
        self.assertAlmostEqual(sum(v for v in breakdown.values() if v is not None), record['wall']['to_done'],
                               places=2)

    def test_every_category_is_present_and_they_sum_to_to_done(self):
        breakdown, record = self.breakdown(attempts=[self.attempt(1100, 1300)])
        self.assertEqual(tuple(breakdown), CATEGORIES)
        self.assert_sums_to_done(breakdown, record)

    def test_work_is_the_union_of_attempt_intervals(self):
        breakdown, record = self.breakdown(attempts=[self.attempt(1100, 1300), self.attempt(1200, 1500, 2)])
        self.assertAlmostEqual(breakdown['work'], 400)
        self.assertAlmostEqual(breakdown['idle'], 1600)

    def test_attempt_without_end_runs_until_epic_done(self):
        breakdown, record = self.breakdown(attempts=[self.attempt(1800, None, outcome=None)])
        self.assertAlmostEqual(breakdown['work'], 1200)

    def test_attempts_are_cut_to_the_time_from_start_to_epic_done(self):
        breakdown, record = self.breakdown(attempts=[self.attempt(900, 1100), self.attempt(2900, 3500, 2)])
        self.assertAlmostEqual(breakdown['work'], 100 + 100)

    def test_human_wait_is_a_closed_wait_from_events_jsonl(self):
        breakdown, record = self.breakdown(jsonl=[blocked(1300), answered(1500)])
        self.assertAlmostEqual(breakdown['human_wait'], 200)

    def test_human_wait_of_a_task_is_closed_by_the_decision_on_that_task(self):
        breakdown, record = self.breakdown(jsonl=[blocked(1300, 'T-1'), answered(1450, 'T-1')])
        self.assertAlmostEqual(breakdown['human_wait'], 150)

    def test_open_human_wait_lasts_until_epic_done(self):
        breakdown, record = self.breakdown(jsonl=[blocked(1500)])
        self.assertAlmostEqual(breakdown['human_wait'], 1500)

    def test_limit_wait_runs_from_cooldown_set_to_until(self):
        breakdown, record = self.breakdown(events=[(1100, 'cooldown_set', {'from': 1100, 'until': 1400})])
        self.assertAlmostEqual(breakdown['limit_wait'], 300)

    def test_limit_wait_ends_at_cooldown_released_when_that_comes_first(self):
        breakdown, record = self.breakdown(events=[(1100, 'cooldown_set', {'from': 1100, 'until': 1400}),
                                                   (1250, 'cooldown_released', {})])
        self.assertAlmostEqual(breakdown['limit_wait'], 150)

    def test_limit_wait_ends_at_until_when_that_comes_first(self):
        breakdown, record = self.breakdown(events=[(1100, 'cooldown_set', {'from': 1100, 'until': 1200}),
                                                   (1900, 'cooldown_released', {})])
        self.assertAlmostEqual(breakdown['limit_wait'], 100)

    def test_limit_wait_without_an_end_is_left_out_with_a_gap(self):
        breakdown, record = self.breakdown(events=[(1100, 'cooldown_set', {'from': 1100})])
        self.assertAlmostEqual(breakdown['limit_wait'], 0)
        self.assertTrue(any(g.startswith('breakdown.limit_wait') for g in record['gaps']), record['gaps'])

    def test_ci_wait_pending_until_each_terminating_event(self):
        for terminator in ('epic_ci_green', 'epic_ci_inherited', 'epic_ci_inherited_red', 'epic_ci_timeout',
                           'epic_repairing'):
            with self.subTest(terminator=terminator):
                breakdown, record = self.breakdown(events=[(1100, 'epic_ci_pending', {}),
                                                           (1200, 'gh_call', {}), (1260, terminator, {})])
                self.assertAlmostEqual(breakdown['ci_wait'], 160)

    def test_ci_wait_of_the_final_pr_runs_from_creation_to_its_ci(self):
        breakdown, record = self.breakdown(events=[(1700, 'final_pr_created', {}), (1750, 'final_pr_ci', {})])
        self.assertAlmostEqual(breakdown['ci_wait'], 50)

    def test_ci_wait_without_a_terminating_event_lasts_until_epic_done(self):
        breakdown, record = self.breakdown(events=[(1700, 'epic_ci_pending', {})])
        self.assertAlmostEqual(breakdown['ci_wait'], 1300)

    def test_host_pause_is_the_interval_of_the_event(self):
        breakdown, record = self.breakdown(events=[(1165, 'host_pause', {'from': 1100, 'to': 1160, 'seconds': 60})])
        self.assertAlmostEqual(breakdown['host_pause'], 60)

    def test_host_pause_without_from_uses_seconds_before_the_event(self):
        breakdown, record = self.breakdown(events=[(1160, 'host_pause', {'seconds': 45})])
        self.assertAlmostEqual(breakdown['host_pause'], 45)

    def test_github_wait_ends_at_the_next_event_other_than_gh_call(self):
        breakdown, record = self.breakdown(events=[(1100, 'github_wait', {}), (1150, 'gh_call', {}),
                                                   (1400, 'promoted', {})])
        self.assertAlmostEqual(breakdown['github_wait'], 300)

    def test_github_wait_is_capped_at_30_minutes(self):
        breakdown, record = self.breakdown(length=5000, events=[(1100, 'github_wait', {}), (4000, 'promoted', {})])
        self.assertAlmostEqual(breakdown['github_wait'], 1800)

    def test_github_wait_is_capped_30_minutes_after_the_last_of_repeated_waits(self):
        breakdown, record = self.breakdown(length=5000, events=[(1100, 'github_wait', {}), (1300, 'github_wait', {}),
                                                                (1350, 'gh_call', {}), (4900, 'promoted', {})])
        self.assertAlmostEqual(breakdown['github_wait'], 1300 + 1800 - 1100)

    def test_github_wait_without_a_following_event_is_capped_too(self):
        breakdown, record = self.breakdown(length=5000, events=[(1100, 'github_wait', {})])
        self.assertAlmostEqual(breakdown['github_wait'], 1800)

    def test_idle_is_everything_else(self):
        breakdown, record = self.breakdown(attempts=[self.attempt(1100, 1200)], events=[
            (1500, 'host_pause', {'from': 1400, 'to': 1500})])
        self.assertAlmostEqual(breakdown['idle'], 2000 - 100 - 100)

    def test_precedence_when_categories_overlap(self):
        # Every category covers a stretch that the categories after it also cover; each moment goes to the first.
        breakdown, record = self.breakdown(
            length=3000,
            attempts=[self.attempt(1100, 1200)],                                         # work 1100-1200
            jsonl=[blocked(1150), answered(1300)],                                       # human_wait 1150-1300
            events=[(1200, 'cooldown_set', {'from': 1200, 'until': 1400}),               # limit_wait 1200-1400
                    (1250, 'epic_ci_pending', {}), (1500, 'epic_ci_green', {}),          # ci_wait 1250-1500
                    (1450, 'host_pause', {'from': 1450, 'to': 1600}),                    # host_pause 1450-1600
                    (1550, 'github_wait', {}), (1700, 'promoted', {})])                  # github_wait 1550-1700
        self.assertAlmostEqual(breakdown['work'], 100)
        self.assertAlmostEqual(breakdown['human_wait'], 100)         # 1200-1300 (1150-1200 is work)
        self.assertAlmostEqual(breakdown['limit_wait'], 100)         # 1300-1400
        self.assertAlmostEqual(breakdown['ci_wait'], 100)            # 1400-1500
        self.assertAlmostEqual(breakdown['host_pause'], 100)         # 1500-1600
        self.assertAlmostEqual(breakdown['github_wait'], 100)        # 1600-1700
        self.assertAlmostEqual(breakdown['idle'], 3000 - 600)
        self.assert_sums_to_done(breakdown, record)

    def test_work_wins_over_every_other_category(self):
        breakdown, record = self.breakdown(
            attempts=[self.attempt(1100, 1900)], jsonl=[blocked(1200), answered(1800)],
            events=[(1300, 'host_pause', {'from': 1300, 'to': 1400})])
        self.assertAlmostEqual(breakdown['work'], 800)
        self.assertAlmostEqual(breakdown['human_wait'], 0)
        self.assertAlmostEqual(breakdown['host_pause'], 0)

    def test_without_events_jsonl_human_wait_is_null_and_its_time_is_idle(self):
        breakdown, record = self.breakdown(attempts=[self.attempt(1100, 1200)], jsonl_present=False)
        self.assertIsNone(breakdown['human_wait'])
        self.assertAlmostEqual(breakdown['idle'], 1900)
        self.assertTrue(any(g.startswith('breakdown.human_wait') and 'events.jsonl' in g for g in record['gaps']),
                        record['gaps'])
        self.assert_sums_to_done(breakdown, record)

    def test_without_epic_done_the_breakdown_is_null_with_a_gap(self):
        home = testkit.synthetic_home(self.root, created=1000.0, tasks=[('T-1', 'plan')],
                                      attempts=[self.attempt(1100, 1200)], jsonl=[])
        record = self.record('demo', home=home)
        self.assertIsNone(record['breakdown'])
        self.assertIsNone(record['wall']['to_done'])
        self.assertIsNone(record['done_at'])
        self.assertTrue(any(g.startswith('done_at') for g in record['gaps']), record['gaps'])
        self.assertTrue(any(g.startswith('breakdown') for g in record['gaps']), record['gaps'])

    def test_the_first_epic_done_ends_the_epic(self):
        home = testkit.synthetic_home(self.root, created=1000.0, tasks=[('T-1', 'plan')], jsonl=[],
                                      events=[(1500, 'epic_done', {}), (1900, 'epic_done', {})])
        record = self.record('demo', home=home)
        self.assertAlmostEqual(record['wall']['to_done'], 500)

    def test_deploy_ops_fixture_sums_to_done(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        record = self.record('deploy-ops', home=home)
        breakdown = record['breakdown']
        self.assertEqual(tuple(breakdown), CATEGORIES)
        self.assert_sums_to_done(breakdown, record)
        self.assertTrue(all(v >= 0 for v in breakdown.values()), breakdown)

    def test_deploy_ops_fixture_categories(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        breakdown = self.record('deploy-ops', home=home)['breakdown']
        # work: the union of the 18 attempts (every one ends before epic_done)
        self.assertAlmostEqual(breakdown['work'], 87765.7, delta=1)
        # human_wait: the blocked task and epic waits of events.jsonl, outside the attempts
        self.assertAlmostEqual(breakdown['human_wait'], 25546.5, delta=1)
        self.assertAlmostEqual(breakdown['limit_wait'], 0)
        self.assertAlmostEqual(breakdown['ci_wait'], 1129.7, delta=1)
        self.assertAlmostEqual(breakdown['host_pause'], 0)      # the only pause is inside attempt 6
        self.assertAlmostEqual(breakdown['github_wait'], 0)     # the only wait is inside attempt 6

    def test_catalog_admin_fixture_sums_to_done(self):
        home = testkit.fixture_home(self.root, 'catalog-admin')
        record = self.record('catalog-admin', home=home)
        self.assert_sums_to_done(record['breakdown'], record)

    def test_the_record_is_stable_between_runs(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        first = self.record('deploy-ops', home=home)
        self.assertEqual(first, self.record('deploy-ops', home=home))
        self.assertTrue(os.path.exists(os.path.join(self.state, 'epics', 'deploy-ops.json')))


if __name__ == '__main__':
    unittest.main()

"""Tests of `subscription`, `parallel`, `gate_wait` and `human` of the epic record (night state and events.jsonl)."""
import json
import os
import shutil
import unittest
from datetime import datetime, timedelta, timezone
from unittest import mock

import metrics
import testkit
from testkit import Env

BASE = datetime(2026, 11, 20, 8, 0, tzinfo=timezone.utc)
RESET = '2026-11-25T18:00:00Z'
ALL = 'Weekly · all models'
SONNET = 'Weekly · Sonnet'


def at(seconds):
    """The ISO timestamp (with an offset) `seconds` after BASE."""
    return (BASE + timedelta(seconds=seconds)).isoformat()


def epoch(seconds):
    return (BASE + timedelta(seconds=seconds)).timestamp()


def usage(all_percent=10, sonnet_percent=0, reset=RESET, status='ok', sonnet_reset=None):
    if status != 'ok':
        return {'status': status}
    return {'status': 'ok', 'windows': [{'label': ALL, 'percentUsed': all_percent, 'resetsAt': reset},
                                        {'label': SONNET, 'percentUsed': sonnet_percent,
                                         'resetsAt': sonnet_reset or reset}]}


def start(epic, seconds, **kwargs):
    return {'at': at(seconds), 'epic': epic, 'event': 'start', 'tasks': 3, 'usage': usage(**kwargs)}


def done(epic, seconds, **kwargs):
    return {'at': at(seconds), 'epic': epic, 'event': 'done', 'usage': usage(**kwargs)}


class NightEnv(Env):
    def write_night(self, events, name='measurements.jsonl'):
        with open(os.path.join(self.night, name), 'w', encoding='utf-8') as f:
            for event in events:
                f.write(json.dumps(event) + '\n')

    def demo(self, begin=100, end=5000, **kwargs):
        """The epic `demo`: created `begin` seconds after BASE, epic_done `end` seconds after BASE."""
        kwargs.setdefault('jsonl', [])
        kwargs.setdefault('tasks', [('T-1', 'plan')])
        return testkit.synthetic_home(self.root, created=epoch(begin), events=[(epoch(end), 'epic_done', {})],
                                      **kwargs)


class SubscriptionTest(NightEnv):
    def subscription(self, events, **kwargs):
        self.write_night(events)
        record = self.record('demo', home=self.demo(**kwargs))
        return record['subscription'], record

    def test_increase_of_each_window(self):
        sub, record = self.subscription([start('demo', 110, all_percent=10, sonnet_percent=2),
                                         done('demo', 5010, all_percent=25, sonnet_percent=4)])
        self.assertEqual(sub['windows'], {ALL: 15, SONNET: 2})
        self.assertEqual(sub['overlap'], [])

    def test_window_renewed_during_the_epic_is_null(self):
        sub, record = self.subscription([start('demo', 110, all_percent=90),
                                         done('demo', 5010, all_percent=5, reset='2026-12-02T18:00:00Z')])
        self.assertEqual(sub['windows'], {ALL: None, SONNET: None})
        self.assertTrue(any(g.startswith('subscription') for g in record['gaps']), record['gaps'])

    def test_only_the_renewed_window_is_null(self):
        sub, record = self.subscription([start('demo', 110, all_percent=10, sonnet_percent=2),
                                         done('demo', 5010, all_percent=25, sonnet_percent=1,
                                              sonnet_reset='2026-12-02T18:00:00Z')])
        self.assertEqual(sub['windows'], {ALL: 15, SONNET: None})

    def test_reset_time_that_differs_by_less_than_a_minute_is_the_same_window(self):
        sub, record = self.subscription([start('demo', 110, all_percent=10),
                                         done('demo', 5010, all_percent=12, reset='2026-11-25T18:00:30Z')])
        self.assertEqual(sub['windows'][ALL], 2)

    def test_decreasing_percent_is_null(self):
        sub, record = self.subscription([start('demo', 110, all_percent=30), done('demo', 5010, all_percent=20)])
        self.assertEqual(sub['windows'][ALL], None)

    def test_usage_state_other_than_ok_at_done_is_null_with_a_gap(self):
        sub, record = self.subscription([start('demo', 110, all_percent=10),
                                         done('demo', 5010, all_percent=0, status='unavailable')])
        self.assertEqual(sub['windows'], {ALL: None, SONNET: None})
        self.assertTrue(any(g.startswith('subscription') and 'unavailable' in g for g in record['gaps']),
                        record['gaps'])

    def test_usage_state_other_than_ok_at_start_is_null(self):
        sub, record = self.subscription([start('demo', 110, all_percent=0, status='unreadable'),
                                         done('demo', 5010, all_percent=30)])
        self.assertEqual(sub['windows'], {ALL: None, SONNET: None})

    def test_window_deltas_come_from_night_queue(self):
        self.write_night([start('demo', 110, all_percent=10), done('demo', 5010, all_percent=25)])
        home = self.demo()
        with mock.patch.object(metrics.night_queue, '_window_deltas', return_value={ALL: 99}) as spy:
            record = self.record('demo', home=home)
        self.assertTrue(spy.called)
        self.assertEqual(record['subscription']['windows'][ALL], 99)

    def test_missing_measurements_file_is_null_with_gaps(self):
        record = self.record('demo', home=self.demo())
        self.assertIsNone(record['subscription'])
        self.assertIsNone(record['parallel'])
        for field in ('subscription', 'parallel'):
            self.assertTrue(any(g.startswith(field) and 'measurements.jsonl' in g for g in record['gaps']),
                            record['gaps'])

    def test_unreadable_measurements_file_is_null_with_gaps(self):
        with open(os.path.join(self.night, 'measurements.jsonl'), 'w') as f:
            f.write('not json\n')
        record = self.record('demo', home=self.demo())
        self.assertIsNone(record['subscription'])
        self.assertTrue(any(g.startswith('subscription') and 'measurements.jsonl' in g for g in record['gaps']))

    def test_epic_without_a_done_measurement_is_null_with_a_gap(self):
        sub, record = self.subscription([start('demo', 110, all_percent=10)])
        self.assertIsNone(sub)
        self.assertTrue(any(g.startswith('subscription') for g in record['gaps']), record['gaps'])

    def test_epic_missing_from_measurements_is_null_with_a_gap(self):
        sub, record = self.subscription([start('other', 110, all_percent=10), done('other', 5010, all_percent=20)])
        self.assertIsNone(sub)
        self.assertTrue(any(g.startswith('subscription') for g in record['gaps']), record['gaps'])

    def test_deploy_ops_fixture_windows_renewed_during_the_epic(self):
        shutil.copy(testkit.fixture('measurements.jsonl'), self.night)
        record = self.record('deploy-ops', home=testkit.fixture_home(self.root, 'deploy-ops'))
        self.assertEqual(record['subscription'],
                         {'windows': {ALL: None, 'Weekly · Fable': None}, 'overlap': []})

    def test_catalog_admin_fixture_delta_and_overlap(self):
        shutil.copy(testkit.fixture('measurements.jsonl'), self.night)
        record = self.record('catalog-admin', home=testkit.fixture_home(self.root, 'catalog-admin'))
        self.assertEqual(record['subscription']['windows'], {ALL: 12, 'Weekly · Fable': 0})
        self.assertEqual(record['subscription']['overlap'], ['deploy', 'order-management'])


class OverlapTest(NightEnv):
    def parallel(self, events, **kwargs):
        self.write_night(events)
        record = self.record('demo', home=self.demo(**kwargs))
        return record['parallel'], record

    def test_overlap_with_a_closed_interval(self):
        parallel, record = self.parallel([start('demo', 110), start('other', 2000), done('other', 3000),
                                          done('demo', 5010)])
        self.assertEqual(parallel['epics'], ['other'])
        self.assertEqual(record['subscription']['overlap'], ['other'])

    def test_intervals_that_do_not_intersect_are_no_overlap(self):
        parallel, record = self.parallel([start('before', 0), done('before', 100), start('demo', 110),
                                          done('demo', 5010), start('after', 5020), done('after', 6000)])
        self.assertEqual(parallel['epics'], [])

    def test_open_start_of_another_epic_inside_the_interval_is_overlap(self):
        parallel, record = self.parallel([start('demo', 110), start('open', 2000), done('demo', 5010)])
        self.assertEqual(parallel['epics'], ['open'])

    def test_open_start_of_another_epic_before_the_interval_is_not(self):
        parallel, record = self.parallel([start('open', 0), start('demo', 110), done('demo', 5010)])
        self.assertEqual(parallel['epics'], [])

    def test_repair_attempt_in_the_overlap_is_listed(self):
        parallel, record = self.parallel(
            [start('demo', 110), start('other', 2000), done('other', 3000), done('demo', 5010)],
            tasks=[('T-1', 'plan'), ('R-1', 'repair'), ('R-2', 'repair')],
            attempts=[dict(task_id='R-1', started_at=epoch(2500), ended_at=epoch(2600)),
                      dict(task_id='R-2', started_at=epoch(3500), ended_at=epoch(3600))])
        self.assertEqual(parallel['repairs'], ['R-1'])

    def test_catalog_admin_fixture_has_its_repair_in_the_overlap(self):
        shutil.copy(testkit.fixture('measurements.jsonl'), self.night)
        record = self.record('catalog-admin', home=testkit.fixture_home(self.root, 'catalog-admin'))
        self.assertEqual(record['parallel']['epics'], ['deploy', 'order-management'])
        self.assertEqual(record['parallel']['repairs'], ['CATALOG-ADMIN-REPAIR-1'])


class GateWaitTest(NightEnv):
    def write_gate(self, lines):
        with open(os.path.join(self.night, 'gate.jsonl'), 'w', encoding='utf-8') as f:
            for line in lines:
                f.write(json.dumps(line) + '\n')

    def entry(self, seconds, waiting=None, epic=None, reason=None):
        return {'at': at(seconds), 'dir_arg': None, 'epic': epic, 'reason': reason, 'skipped': [],
                'waiting': waiting}

    def gate_wait(self, gate, **kwargs):
        """The epic starts (epics.created_at) 3590 s after BASE."""
        if gate is not None:
            self.write_gate(gate)
        record = self.record('demo', home=self.demo(begin=3590, **kwargs))
        return record['gate_wait'], record

    def test_wait_is_the_time_from_the_first_waiting_entry_to_the_start_of_the_epic(self):
        gate, record = self.gate_wait([self.entry(600, 'demo', reason='dwa epiki aktywne'),
                                       self.entry(1200, 'demo', reason='dwa epiki aktywne'),
                                       self.entry(3590, epic='demo')])
        self.assertEqual(gate, {'wait_s': 2990.0, 'reasons': ['dwa epiki aktywne']})

    def test_reasons_are_distinct_and_in_order(self):
        gate, record = self.gate_wait([self.entry(600, 'demo', reason='b'), self.entry(900, 'demo', reason='a'),
                                       self.entry(1200, 'demo', reason='b')])
        self.assertEqual(gate['reasons'], ['b', 'a'])

    def test_waiting_entries_of_other_epics_do_not_count(self):
        gate, record = self.gate_wait([self.entry(100, 'other'), self.entry(3590, epic='demo')])
        self.assertEqual(gate, {'wait_s': 0.0, 'reasons': []})

    def test_passing_the_gate_without_waiting_is_zero(self):
        gate, record = self.gate_wait([self.entry(3590, epic='demo')])
        self.assertEqual(gate, {'wait_s': 0.0, 'reasons': []})

    def test_no_entries_of_the_epic_is_null_with_a_gap(self):
        gate, record = self.gate_wait([self.entry(100, epic='other')])
        self.assertIsNone(gate)
        self.assertTrue(any(g.startswith('gate_wait') and 'gate.jsonl' in g for g in record['gaps']), record['gaps'])

    def test_missing_gate_file_is_null_with_a_gap(self):
        gate, record = self.gate_wait(None)
        self.assertIsNone(gate)
        self.assertTrue(any(g.startswith('gate_wait') for g in record['gaps']), record['gaps'])

    def test_a_waiting_entry_after_the_start_is_not_a_wait(self):
        gate, record = self.gate_wait([self.entry(3590, epic='demo'), self.entry(4000, 'demo')])
        self.assertEqual(gate, {'wait_s': 0.0, 'reasons': []})

    def test_fixture_wait_of_admin_screens_until_its_start(self):
        shutil.copy(testkit.fixture('measurements.jsonl'), self.night)
        shutil.copy(testkit.fixture('gate.jsonl'), self.night)
        begin = datetime.fromisoformat('2026-11-14T19:34:40+01:00').timestamp()   # epics.created_at
        end = datetime.fromisoformat('2026-11-15T07:26:20+01:00').timestamp()
        home = testkit.synthetic_home(self.root, epic='admin-screens', created=begin,
                                      events=[(end, 'epic_done', {})], jsonl=[])
        record = self.record('admin-screens', home=home)
        self.assertAlmostEqual(record['gate_wait']['wait_s'], 21716.143, places=2)
        self.assertEqual(record['gate_wait']['reasons'], ['redacted'])

    def test_fixture_epic_with_an_unfinished_measurement_has_a_gate_wait_but_no_subscription(self):
        shutil.copy(testkit.fixture('measurements.jsonl'), self.night)
        shutil.copy(testkit.fixture('gate.jsonl'), self.night)
        begin = datetime.fromisoformat('2026-11-15T09:11:05+01:00').timestamp()
        home = testkit.synthetic_home(self.root, epic='evals', created=begin, jsonl=[], status='ACTIVE',
                                      events=[(begin + 5000, 'epic_done', {})])
        record = self.record('evals', home=home)
        self.assertAlmostEqual(record['gate_wait']['wait_s'], 48942.722, places=2)
        self.assertIsNone(record['subscription'])


class HumanTest(NightEnv):
    def human(self, jsonl, **kwargs):
        home = self.demo(jsonl=jsonl, **kwargs)
        record = self.record('demo', home=home)
        return record['human'], record

    def blocked(self, seconds, task=None):
        return {'at': epoch(seconds), 'type': 'epic_blocked' if task is None else 'status_changed',
                'task_id': task, 'epic_id': None, 'data': {'to': 'BLOCKED'}}

    def action(self, seconds, task=None, action='answer'):
        return {'at': epoch(seconds), 'type': 'human_action', 'task_id': task, 'epic_id': None,
                'data': {'action': action}}

    def test_decisions_waits_and_their_sum(self):
        human, record = self.human([self.blocked(200), self.action(300, action='epic-resume'),
                                    self.blocked(400, 'T-1'), self.action(650, 'T-1')])
        self.assertEqual((human['interventions'], human['waits'], human['wait_s'], human['open']),
                         (2, 2, 350.0, []))

    def test_open_wait_is_listed_and_measured_until_epic_done(self):
        human, record = self.human([self.blocked(200, 'T-1')], end=1000)
        self.assertEqual(human['waits'], 1)
        self.assertEqual(human['wait_s'], 0.0)
        self.assertEqual(human['open'], [{'task_id': 'T-1', 'since': epoch(200), 'wait_s': 800.0}])

    def test_no_events_is_zero_because_the_file_says_so(self):
        human, record = self.human([])
        self.assertEqual((human['interventions'], human['waits'], human['wait_s'], human['open']), (0, 0, 0.0, []))

    def test_without_events_jsonl_everything_is_null_with_a_gap(self):
        human, record = self.human(None)
        self.assertEqual((human['interventions'], human['waits'], human['wait_s'], human['open']),
                         (None, None, None, None))
        self.assertTrue(any(g.startswith('human') and 'events.jsonl' in g for g in record['gaps']), record['gaps'])

    def test_waits_come_from_ao_events(self):
        home = self.demo(jsonl=[])
        with mock.patch.object(metrics.ao_events, 'summarize', return_value={
                'interventions': 9, 'waits': 8, 'wait_s': 7.0, 'open': []}) as spy:
            record = self.record('demo', home=home)
        self.assertTrue(spy.called)
        self.assertEqual(record['human']['interventions'], 9)

    def test_deploy_ops_fixture(self):
        human = self.record('deploy-ops', home=testkit.fixture_home(self.root, 'deploy-ops'))['human']
        self.assertEqual((human['interventions'], human['waits'], human['open']), (7, 7, []))
        self.assertAlmostEqual(human['wait_s'], 25765.358, places=2)

    def test_manual_fixes_are_read_from_the_given_file(self):
        path = os.path.join(self.root, 'fixes.txt')
        with open(path, 'w', encoding='utf-8') as f:
            f.write('Ręczne poprawki: 2\n- PR #61: konflikt w pliku\n- ręczne ao release po awarii stosu\n\n')
        record = self.record('demo', '--manual-fixes', path, home=self.demo())
        self.assertEqual(record['human']['manual_fixes'], [{'pr': 61, 'text': 'konflikt w pliku'},
                                                           {'pr': None, 'text': 'ręczne ao release po awarii stosu'}])

    def test_an_empty_manual_fixes_file_means_no_manual_fixes(self):
        path = os.path.join(self.root, 'fixes.txt')
        open(path, 'w').close()
        self.assertEqual(self.record('demo', '--manual-fixes', path, home=self.demo())['human']['manual_fixes'], [])

    def test_without_the_manual_fixes_option_the_list_is_null_with_a_gap(self):
        record = self.record('demo', home=self.demo())
        self.assertIsNone(record['human']['manual_fixes'])
        self.assertTrue(any(g.startswith('human.manual_fixes') for g in record['gaps']), record['gaps'])

    def test_unreadable_manual_fixes_file_is_an_error(self):
        code, out, err = self.epic('demo', '--manual-fixes', os.path.join(self.root, 'absent.txt'),
                                   home=self.demo())
        self.assertEqual(code, 2)
        self.assertEqual(out, '')
        self.assertIn('absent.txt', err)


if __name__ == '__main__':
    unittest.main()

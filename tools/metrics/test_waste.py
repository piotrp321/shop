"""Tests of `waste` (rules W1-W4, spec section Bezproduktywne zużycie, Q2, R4), of waste.json under waste.lock and of
the `waste` field of the epic record.
python3 -m unittest discover -s tools/metrics -p 'test_*.py'"""
import fcntl
import json
import os
import sqlite3
import subprocess
import sys
import threading
import time
import unittest
from unittest import mock

import metrics
import testkit
from testkit import OPUS, Env, Transcript, model_usage, run_cli

NOW = 1800000000.0
HOUR = 3600.0
SCRIPT = os.path.join(testkit.HERE, 'metrics.py')


def hist(task, kind, value=None, wall_s=None, number=1, **extra):
    """An entry of `attempts` of a stored record: an ended attempt."""
    return {'task': task, 'kind': kind, 'number': number, 'started_at': '2026-01-01T00:00:00Z',
            'ended_at': '2026-01-01T01:00:00Z', 'wall_s': wall_s, 'value': value, **extra}


class WasteEnv(Env):
    """The epic `demo` (not done) in a synthetic AO home and a history of stored records of other epics."""

    def write_record(self, name, attempts):
        os.makedirs(os.path.join(self.state, 'epics'), exist_ok=True)
        with open(os.path.join(self.state, 'epics', '%s.json' % name), 'w', encoding='utf-8') as f:
            json.dump({'epic': name, 'attempts': attempts}, f)

    def history(self, count, attempts):
        """`count` stored records of other epics, each with `attempts` (a list, or a function of the index)."""
        for i in range(count):
            self.write_record('old-%d' % i, attempts(i) if callable(attempts) else attempts)

    def home(self, tasks=(('T-1', 'plan'),), attempts=(), status='ACTIVE', **kwargs):
        return testkit.synthetic_home(self.root, status=status, tasks=list(tasks), attempts=list(attempts), **kwargs)

    def ended(self, task='T-1', number=1, outcome='result', status='READY_FOR_INTEGRATION', start=NOW - 90000.0,
              seconds=600.0):
        return dict(task_id=task, number=number, started_at=start, ended_at=start + seconds, outcome=outcome,
                    executor_status=status)

    def running(self, task='T-1', number=1, seconds=0.0, start=None):
        return dict(task_id=task, number=number, started_at=(NOW - seconds) if start is None else start,
                    ended_at=None, outcome=None, executor_status=None)

    def cost(self, home, task, number, usd):
        """A transcript whose attempt has the value `usd` (a CLI result of one model, one role)."""
        t = Transcript().init().message(input=1000, output=10).result(
            {OPUS: model_usage(input=1000, output=10, cost=usd)}, output=10)
        testkit.put_transcript(home, task, number, t.lines)

    def waste(self, home, *extra, now=NOW):
        return run_cli(['waste', home, '--state', self.state] + list(extra), now=now)

    def alarms(self, out):
        return [line for line in (json.loads(l) for l in out.splitlines() if l.strip()) if 'alarm' in line]

    def gaps(self, out):
        found = [line for line in (json.loads(l) for l in out.splitlines() if l.strip()) if 'gaps' in line]
        return found[-1]['gaps'] if found else []

    def keys(self, out):
        return sorted(a['key'] for a in self.alarms(out))

    def run_ok(self, home, *extra, now=NOW):
        code, out, err = self.waste(home, *extra, now=now)
        self.assertEqual(code, 0, err)
        return out

    def waste_json(self):
        with open(os.path.join(self.state, 'waste.json'), encoding='utf-8') as f:
            return json.load(f)


class HistoryOfAttemptsWithoutATaskTest(unittest.TestCase):
    def test_attempts_of_a_record_without_task_ids_are_not_one_made_up_task(self):
        record = {'attempts': [hist(None, 'plan', value=2.0, wall_s=10.0), hist(None, 'plan', value=3.0, wall_s=20.0)]}
        found = metrics.waste_history({'old': record}, set())
        self.assertEqual(found['value'], {'plan': [2.0, 3.0]})         # per attempt it is still a measurement
        self.assertEqual(found['wall'], {'plan': [10.0, 20.0]})
        self.assertEqual(found['task'], {})                            # per task it is not: which task is unknown


class W1Test(WasteEnv):
    """An attempt that has ended costs more than 3x the median of the ended attempts of its kind and at least 5 USD."""

    def setup_median(self, median, count=5):
        self.history(count, [hist('H', 'plan', value=median, wall_s=100.0)])

    def alarms(self, out):
        return [a for a in super().alarms(out) if a['alarm'] == 'W1']      # W4 sees the same single attempts

    def run_value(self, usd, *extra):
        home = self.home(attempts=[self.ended()])
        self.cost(home, 'T-1', 1, usd)
        return self.run_ok(home, *extra)

    def test_alarms_when_over_three_times_the_median_and_at_least_5_usd(self):
        self.setup_median(2.0)
        out = self.run_value(7.0)
        alarm = self.alarms(out)[0]
        self.assertEqual(self.keys(out), ['W1:demo:T-1:1'])
        self.assertEqual((alarm['alarm'], alarm['epic'], alarm['task'], alarm['attempt']), ('W1', 'demo', 'T-1', 1))
        self.assertAlmostEqual(alarm['value'], 7.0)
        self.assertAlmostEqual(alarm['median'], 2.0)
        self.assertIn('7,00 USD', alarm['push'])
        self.assertIn('3,5×', alarm['push'])

    def test_three_times_the_median_below_5_usd_does_not_alarm(self):
        self.setup_median(1.0)
        self.assertEqual(self.keys(self.run_value(4.9)), [])

    def test_5_usd_below_three_times_the_median_does_not_alarm(self):
        self.setup_median(2.0)
        self.assertEqual(self.keys(self.run_value(5.5)), [])

    def test_exactly_three_times_the_median_does_not_alarm(self):
        self.setup_median(2.0)
        self.assertEqual(self.keys(self.run_value(6.0)), [])
        self.assertEqual(self.keys(self.run_value(6.01)), ['W1:demo:T-1:1'])

    def test_exactly_5_usd_alarms(self):
        self.setup_median(1.0)
        self.assertEqual(self.keys(self.run_value(5.0)), ['W1:demo:T-1:1'])

    def test_the_median_is_of_the_same_kind_only(self):
        self.history(5, [hist('H', 'plan', value=2.0), hist('R', 'repair', value=20.0)])
        self.assertEqual(self.keys(self.run_value(7.0)), ['W1:demo:T-1:1'])

    def test_thresholds_come_from_config_json(self):
        self.setup_median(1.0)
        with open(metrics.DEFAULT_CONFIG, encoding='utf-8') as f:
            config = json.load(f)
        config['waste']['cost_min_usd'] = 1
        config['waste']['cost_multiple'] = 1.5
        path = os.path.join(self.root, 'config.json')
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(config, f)
        self.assertEqual(self.keys(self.run_value(2.0)), [])                          # the default: below 5 USD
        self.assertEqual(self.keys(self.run_value(2.0, *['--config', path])), ['W1:demo:T-1:1'])

    def test_fewer_than_5_historical_measurements_does_not_alarm_and_leaves_a_gap(self):
        self.setup_median(2.0, count=4)
        out = self.run_value(50.0)
        self.assertEqual(self.alarms(out), [])
        self.assertTrue(any(g.startswith('W1') for g in self.gaps(out)), self.gaps(out))

    def test_exactly_5_historical_measurements_are_enough(self):
        self.history(3, [hist('H', 'plan', value=2.0), hist('H', 'plan', value=2.0, number=2)])
        out = self.run_value(50.0)
        self.assertEqual(self.keys(out), ['W1:demo:T-1:1'])

    def test_the_record_of_this_epic_is_not_history(self):
        self.setup_median(2.0)
        self.write_record('demo', [hist('H', 'plan', value=100.0)] * 20)
        self.assertEqual(self.keys(self.run_value(7.0)), ['W1:demo:T-1:1'])

    def test_an_attempt_without_a_value_does_not_alarm_and_leaves_a_gap(self):
        self.setup_median(2.0)
        home = self.home(attempts=[self.ended()])           # no transcript
        out = self.run_ok(home)
        self.assertEqual(self.alarms(out), [])
        self.assertTrue(any(g.startswith('W1') and 'wartości' in g for g in self.gaps(out)), self.gaps(out))

    def test_history_attempts_without_a_value_are_not_measurements(self):
        self.history(5, [hist('H', 'plan', value=None)])
        out = self.run_value(50.0)
        self.assertEqual(self.alarms(out), [])
        self.assertTrue(any(g.startswith('W1') for g in self.gaps(out)))

    def test_a_running_attempt_has_no_cost_alarm(self):
        self.setup_median(1.0)
        home = self.home(attempts=[self.running(seconds=100)])
        self.cost(home, 'T-1', 1, 500.0)
        out = self.run_ok(home)
        self.assertEqual([a for a in self.alarms(out) if a['alarm'] in ('W1', 'W4')], [])

    def test_a_value_estimated_without_a_cli_result_is_marked_in_the_push(self):
        rate = 1e-5
        self.history(5, [hist('H', 'plan', value=2.0, cli_result=True, model_rates={OPUS: rate})])
        home = self.home(attempts=[self.ended()])
        t = Transcript().init().message(input=1000000, output=10)       # no result: killed run
        testkit.put_transcript(home, 'T-1', 1, t.lines)
        alarm = self.alarms(self.run_ok(home))[0]
        self.assertEqual(alarm['alarm'], 'W1')
        self.assertTrue(alarm['estimated'])
        self.assertIn('≈', alarm['push'])


class W2Test(WasteEnv):
    """A running attempt takes more than 3x the median wall_s of its kind and more than 1 h (no cost estimate)."""

    def setup_median(self, median, count=5):
        self.history(count, [hist('H', 'plan', value=1.0, wall_s=median)])

    def run_for(self, seconds, **kwargs):
        return self.run_ok(self.home(attempts=[self.running(seconds=seconds)]), **kwargs)

    def test_alarms_over_three_times_the_median_and_one_hour(self):
        self.setup_median(1800.0)
        out = self.run_for(6000)
        alarm = self.alarms(out)[0]
        self.assertEqual(self.keys(out), ['W2:demo:T-1:1'])
        self.assertEqual(alarm['alarm'], 'W2')
        self.assertAlmostEqual(alarm['seconds'], 6000)
        self.assertIn('trwa 1,7 h', alarm['push'])
        self.assertIn('3,3× mediany', alarm['push'])

    def test_under_three_times_the_median_does_not_alarm(self):
        self.setup_median(1800.0)
        self.assertEqual(self.keys(self.run_for(5000)), [])

    def test_exactly_three_times_the_median_does_not_alarm(self):
        self.setup_median(1800.0)
        self.assertEqual(self.keys(self.run_for(5400)), [])
        self.assertEqual(self.keys(self.run_for(5401)), ['W2:demo:T-1:1'])

    def test_below_one_hour_does_not_alarm_however_long_compared_to_the_median(self):
        self.setup_median(600.0)
        self.assertEqual(self.keys(self.run_for(3500)), [])

    def test_exactly_one_hour_does_not_alarm_and_just_over_it_does(self):
        self.setup_median(600.0)
        self.assertEqual(self.keys(self.run_for(3600)), [])
        self.assertEqual(self.keys(self.run_for(3601)), ['W2:demo:T-1:1'])

    def test_a_running_attempt_without_a_transcript_alarms(self):
        self.setup_median(1800.0)
        self.assertEqual(self.keys(self.run_for(7000)), ['W2:demo:T-1:1'])

    def test_fewer_than_5_historical_measurements_does_not_alarm_and_leaves_a_gap(self):
        self.setup_median(1800.0, count=4)
        out = self.run_for(20000)
        self.assertEqual(self.alarms(out), [])
        self.assertTrue(any(g.startswith('W2') for g in self.gaps(out)), self.gaps(out))

    def test_an_ended_attempt_has_no_time_alarm(self):
        self.setup_median(600.0)
        out = self.run_ok(self.home(attempts=[self.ended(seconds=20000)]))
        self.assertEqual([a for a in self.alarms(out) if a['alarm'] == 'W2'], [])

    def test_a_finished_epic_has_no_running_attempts(self):
        self.setup_median(1800.0)
        home = self.home(attempts=[self.running(seconds=20000)], status='DONE')
        self.assertEqual(self.alarms(self.run_ok(home)), [])


class W3Test(WasteEnv):
    """A loop of attempts: independent of the median and of the cost."""

    def attempts(self, *outcomes, task='T-1'):
        """Attempts of one task in order: (outcome, executor_status) pairs; None outcome is a running attempt."""
        found = []
        for i, (outcome, status) in enumerate(outcomes, 1):
            start = NOW - 50000 + i * 1000
            if outcome is None:
                found.append(dict(task_id=task, number=i, started_at=start, ended_at=None, outcome=None,
                                  executor_status=None))
            else:
                found.append(dict(task_id=task, number=i, started_at=start, ended_at=start + 500, outcome=outcome,
                                  executor_status=status))
        return found

    def keys_of(self, *outcomes_and_options):
        outcomes = [o for o in outcomes_and_options if isinstance(o, tuple)]
        options = [o for o in outcomes_and_options if not isinstance(o, tuple)]
        return self.keys(self.run_ok(self.home(attempts=self.attempts(*outcomes)), *options))

    def test_third_attempt_after_two_failed_alarms_without_history_cost_or_time(self):
        out = self.run_ok(self.home(attempts=self.attempts(('timeout', None), ('crash', None), (None, None))))
        alarm = self.alarms(out)[0]
        self.assertEqual(self.keys(out), ['W3:demo:T-1:3'])
        self.assertEqual((alarm['alarm'], alarm['task'], alarm['attempt'], alarm['failed']), ('W3', 'T-1', 3, 2))
        self.assertIn('3. próba po 2 nieudanych', alarm['push'])

    def test_failed_result_status_counts_as_failed(self):
        self.assertEqual(self.keys_of(('result', 'FAILED'), ('invalid_output', None), (None, None)),
                         ['W3:demo:T-1:3'])

    def test_one_failed_attempt_does_not_alarm(self):
        self.assertEqual(self.keys_of(('timeout', None), (None, None)), [])

    def test_blocked_attempts_do_not_count(self):
        self.assertEqual(self.keys_of(('result', 'BLOCKED'), ('result', 'BLOCKED'), (None, None)), [])
        self.assertEqual(self.keys_of(('timeout', None), ('result', 'BLOCKED'), (None, None)), [])
        self.assertEqual(self.keys_of(('timeout', 'BLOCKED'), ('crash', 'BLOCKED'), ('crash', 'BLOCKED'),
                                      (None, None)), [])

    def test_usage_limit_attempts_do_not_count(self):
        self.assertEqual(self.keys_of(('usage_limit', None), ('usage_limit', None), (None, None)), [])
        self.assertEqual(self.keys_of(('timeout', None), ('usage_limit', None), (None, None)), [])

    def test_blocked_and_usage_limit_between_failures_do_not_hide_the_loop(self):
        self.assertEqual(self.keys_of(('timeout', None), ('result', 'BLOCKED'), ('usage_limit', None),
                                      ('crash', None), (None, None)), ['W3:demo:T-1:5'])

    def test_every_next_attempt_after_the_threshold_alarms_with_its_own_key(self):
        keys = self.keys_of(('timeout', None), ('crash', None), ('timeout', None), (None, None))
        self.assertEqual(keys, ['W3:demo:T-1:3', 'W3:demo:T-1:4'])

    def test_successful_attempts_are_not_failures(self):
        self.assertEqual(self.keys_of(('result', 'PASSED'), ('result', 'PASSED'), (None, None)), [])

    def home_without(self, column):
        home = self.home(attempts=self.attempts(('result', 'READY_FOR_INTEGRATION'), ('result', 'READY_FOR_INTEGRATION'),
                                                ('result', 'READY_FOR_INTEGRATION'), ('result', 'PASSED'),
                                                ('result', 'PASSED')))
        con = sqlite3.connect(os.path.join(home, 'orchestrator.db'))
        con.execute('ALTER TABLE attempts DROP COLUMN %s' % column)
        con.commit()
        con.close()
        return home

    def test_without_attempts_outcome_failed_attempts_are_not_counted(self):
        out = self.run_ok(self.home_without('outcome'), '--max-qa-rounds', '9')
        self.assertEqual(self.keys(out), [])
        self.assertTrue(any(g.startswith('W3:') and 'attempts.outcome' in g for g in self.gaps(out)), self.gaps(out))

    def test_without_attempts_executor_status_failed_attempts_are_not_counted(self):
        out = self.run_ok(self.home_without('executor_status'), '--max-qa-rounds', '9')
        self.assertEqual(self.keys(out), [])
        self.assertTrue(any(g.startswith('W3:') and 'attempts.executor_status' in g for g in self.gaps(out)),
                        self.gaps(out))

    def test_the_threshold_comes_from_config_json(self):
        with open(metrics.DEFAULT_CONFIG, encoding='utf-8') as f:
            config = json.load(f)
        config['waste']['loop_failed_attempts'] = 1
        path = os.path.join(self.root, 'config.json')
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(config, f)
        self.assertEqual(self.keys_of(('timeout', None), (None, None)), [])
        self.assertEqual(self.keys_of(('timeout', None), (None, None), '--config', path), ['W3:demo:T-1:2'])

    def repair_run(self, events, second_started=NOW - 20000):
        tasks = [('R-1', 'repair'), ('R-2', 'repair')]
        attempts = [dict(task_id='R-1', number=1, started_at=NOW - 40000, ended_at=NOW - 30000, outcome='result',
                         executor_status='READY_FOR_INTEGRATION'),
                    dict(task_id='R-2', number=1, started_at=second_started, ended_at=None, outcome=None,
                         executor_status=None)]
        return self.home(tasks=tasks, attempts=attempts, events=events)

    def test_second_repair_without_a_green_ci_in_between_alarms(self):
        events = [(NOW - 31000, 'epic_ci_inherited_red', {'epic_id': 'demo'}),
                  (NOW - 21000, 'epic_ci_pending', {'epic_id': 'demo'})]
        out = self.run_ok(self.repair_run(events))
        alarm = self.alarms(out)[0]
        self.assertEqual(self.keys(out), ['W3:demo:repair:R-2'])
        self.assertEqual((alarm['alarm'], alarm['task']), ('W3', 'R-2'))
        self.assertIn('REPAIR', alarm['push'])

    def test_a_green_or_inherited_ci_in_between_means_the_repairs_are_not_in_a_row(self):
        for kind in ('epic_ci_green', 'epic_ci_inherited'):
            with self.subTest(kind=kind):
                events = [(NOW - 25000, kind, {'epic_id': 'demo'})]
                self.assertEqual(self.keys(self.run_ok(self.repair_run(events))), [])

    def test_a_green_ci_before_the_first_repair_or_after_the_second_does_not_separate_them(self):
        events = [(NOW - 45000, 'epic_ci_green', {'epic_id': 'demo'}), (NOW - 10000, 'epic_ci_green', {'epic_id': 'demo'})]
        self.assertEqual(self.keys(self.run_ok(self.repair_run(events))), ['W3:demo:repair:R-2'])

    def test_a_single_repair_does_not_alarm(self):
        home = self.home(tasks=[('R-1', 'repair')], attempts=[self.running('R-1')])
        self.assertEqual(self.alarms(self.run_ok(home)), [])

    def test_a_repair_that_has_not_started_does_not_alarm(self):
        home = self.home(tasks=[('R-1', 'repair'), ('R-2', 'repair')], attempts=[self.ended('R-1')])
        self.assertEqual(self.alarms(self.run_ok(home)), [])

    def test_qa_round_equal_to_the_maximum_alarms(self):
        out = self.run_ok(self.home(rounds=3))
        alarm = self.alarms(out)[0]
        self.assertEqual(self.keys(out), ['W3:demo:qa:3'])
        self.assertEqual((alarm['alarm'], alarm['round'], alarm['max_rounds']), ('W3', 3, 3))
        self.assertIn('runda QA 3', alarm['push'])

    def test_qa_round_below_the_maximum_does_not_alarm(self):
        self.assertEqual(self.alarms(self.run_ok(self.home(rounds=2))), [])

    def test_max_qa_rounds_option(self):
        self.assertEqual(self.keys(self.run_ok(self.home(rounds=2), '--max-qa-rounds', '2')), ['W3:demo:qa:2'])
        self.assertEqual(self.alarms(self.run_ok(self.home(rounds=3), '--max-qa-rounds', '4')), [])

    def test_max_qa_rounds_must_be_positive(self):
        for value in ('0', '-1', 'x'):
            with self.subTest(value=value):
                code, out, err = self.waste(self.home(), '--max-qa-rounds', value)
                self.assertEqual((code, out), (2, ''))
                self.assertIn('--max-qa-rounds', err)

    def test_deploy_ops_fixture_blocked_attempts_do_not_make_a_loop(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        out = self.run_ok(home)
        # DEPLOY-OPS-4: attempts 1, 2 and 4 BLOCKED, 3 timeout, 5 invalid_output: only the 6th follows two failures;
        # REPAIR-1 and REPAIR-2 have green CI between them; the epic is in round 3
        self.assertEqual(self.keys(out), ['W3:deploy-ops:DEPLOY-OPS-4:6', 'W3:deploy-ops:qa:3'])


class W4Test(WasteEnv):
    """The sum of the values of the ended attempts of a task is more than 3x the median of the tasks of its kind and at
    least 5 USD."""

    def setup_median(self, median, count=5):
        self.history(count, [hist('H', 'plan', value=median / 2), hist('H', 'plan', value=median / 2, number=2)])

    def run_sum(self, total, extra=(), **kwargs):
        home = self.home(attempts=[self.ended(number=1), self.ended(number=2, start=NOW - 60000)] + list(extra))
        self.cost(home, 'T-1', 1, total / 2)
        self.cost(home, 'T-1', 2, total / 2)
        for attempt in extra:
            self.cost(home, attempt['task_id'], attempt['number'], 1000.0)
        out = self.run_ok(home, **kwargs)
        return [a for a in self.alarms(out) if a['alarm'] == 'W4'], out

    def test_alarms_over_three_times_the_median_and_at_least_5_usd(self):
        self.setup_median(2.0)
        alarms, _ = self.run_sum(7.0)
        self.assertEqual([a['key'] for a in alarms], ['W4:demo:T-1'])
        self.assertIsNone(alarms[0]['attempt'])
        self.assertAlmostEqual(alarms[0]['value'], 7.0)
        self.assertAlmostEqual(alarms[0]['median'], 2.0)
        self.assertIn('7,00 USD', alarms[0]['push'])

    def test_three_times_the_median_below_5_usd_does_not_alarm(self):
        self.setup_median(1.0)
        self.assertEqual(self.run_sum(4.0)[0], [])
        self.assertEqual(self.run_sum(4.9)[0], [])

    def test_5_usd_below_three_times_the_median_does_not_alarm(self):
        self.setup_median(2.0)
        self.assertEqual(self.run_sum(5.0)[0], [])

    def test_exactly_three_times_the_median_does_not_alarm(self):
        self.setup_median(2.0)
        self.assertEqual(self.run_sum(6.0)[0], [])

    def test_exactly_5_usd_alarms(self):
        self.setup_median(1.0)
        self.assertEqual([a['key'] for a in self.run_sum(5.0)[0]], ['W4:demo:T-1'])

    def test_the_running_attempt_is_not_counted(self):
        self.setup_median(2.0)
        alarms, _ = self.run_sum(5.0, extra=[self.running(number=3, seconds=100)])
        self.assertEqual(alarms, [])

    def test_a_task_with_an_attempt_without_a_value_is_skipped_with_a_gap(self):
        self.setup_median(2.0)
        home = self.home(attempts=[self.ended(number=1), self.ended(number=2, start=NOW - 60000)])
        self.cost(home, 'T-1', 1, 50.0)                       # attempt 2 has no transcript
        out = self.run_ok(home)
        self.assertEqual([a for a in self.alarms(out) if a['alarm'] == 'W4'], [])
        self.assertTrue(any(g.startswith('W4') for g in self.gaps(out)), self.gaps(out))

    def test_history_tasks_with_an_attempt_without_a_value_are_not_measurements(self):
        self.history(5, [hist('H', 'plan', value=1.0), hist('H', 'plan', value=None, number=2)])
        alarms, out = self.run_sum(50.0)
        self.assertEqual(alarms, [])
        self.assertTrue(any(g.startswith('W4') for g in self.gaps(out)), self.gaps(out))

    def test_fewer_than_5_historical_tasks_does_not_alarm_and_leaves_a_gap(self):
        self.setup_median(2.0, count=4)
        alarms, out = self.run_sum(50.0)
        self.assertEqual(alarms, [])
        self.assertTrue(any(g.startswith('W4') for g in self.gaps(out)), self.gaps(out))

    def test_the_median_is_of_tasks_not_of_attempts(self):
        # five history tasks of 10 USD in two attempts of 5: per attempt the median is 5, per task 10
        self.setup_median(10.0)
        alarms, _ = self.run_sum(25.0)
        self.assertEqual(alarms, [])
        self.assertEqual([a['key'] for a in self.run_sum(31.0)[0]], ['W4:demo:T-1'])


class RichEnv(WasteEnv):
    """A run that raises every rule: W1 and W4 (cost), W2 (running attempt), W3 (loop, repairs, QA round)."""

    EXPECTED = ['W1:demo:T-1:1', 'W2:demo:T-2:1', 'W3:demo:R-2:3', 'W3:demo:qa:3', 'W3:demo:repair:R-2',
                'W4:demo:T-1']

    def rich_home(self, now=NOW, loop=False):
        """loop: adds the task T-3 with two failed attempts and a third one that has just started."""
        self.history(5, [hist('H', 'plan', value=1.0, wall_s=1800.0)])
        tasks = [('T-1', 'plan'), ('T-2', 'plan'), ('R-1', 'repair'), ('R-2', 'repair')]
        attempts = [self.ended('T-1', 1, start=now - 90000), self.running('T-2', 1, start=now - 20000),
                    dict(task_id='R-1', number=1, started_at=now - 40000, ended_at=now - 30000, outcome='result',
                         executor_status='READY_FOR_INTEGRATION'),
                    dict(task_id='R-2', number=1, started_at=now - 20000, ended_at=now - 19000, outcome='timeout',
                         executor_status=None),
                    dict(task_id='R-2', number=2, started_at=now - 18000, ended_at=now - 17000, outcome='timeout',
                         executor_status=None),
                    dict(task_id='R-2', number=3, started_at=now - 16000, ended_at=None, outcome=None,
                         executor_status=None)]
        if loop:
            tasks.append(('T-3', 'plan'))
            attempts += [dict(task_id='T-3', number=1, started_at=now - 9000, ended_at=now - 8900, outcome='crash',
                              executor_status=None),
                         dict(task_id='T-3', number=2, started_at=now - 8800, ended_at=now - 8700, outcome='crash',
                              executor_status=None),
                         self.running('T-3', 3, start=now - 100)]
        home = self.home(tasks=tasks, attempts=attempts, rounds=3)
        self.cost(home, 'T-1', 1, 20.0)
        return home


class MissingColumnsTest(RichEnv):
    """A column (or table) missing in the AO database: the rules that need it are skipped with a position in the `gaps`
    of the output, never alarm on what they cannot know (a made up task `None`, a loop of attempts not told apart)."""

    ATTEMPT = ('attempts.ended_at', 'attempts.task_id', 'attempts.number')
    KIND = ('tasks.kind', 'tasks.task_id')
    RULES = (
        ('W1', ['W1:demo:T-1:1'], ATTEMPT + KIND),
        ('W2', ['W2:demo:T-2:1'], ATTEMPT + ('attempts.started_at',) + KIND),
        ('W3', ['W3:demo:R-2:3'], ATTEMPT + ('attempts.outcome', 'attempts.executor_status')),
        ('W3', ['W3:demo:repair:R-2'], KIND + ('attempts.task_id', 'attempts.started_at', 'events.type', 'events.at',
                                                'events.task_id', 'events.data')),
        ('W3', ['W3:demo:qa:3'], ('epics.round',)),
        ('W4', ['W4:demo:T-1'], ATTEMPT + KIND),
    )

    def drop(self, home, table, column=None):
        con = sqlite3.connect(os.path.join(home, 'orchestrator.db'))
        con.execute('DROP TABLE %s' % table if column is None else 'ALTER TABLE %s DROP COLUMN %s' % (table, column))
        con.commit()
        con.close()

    def waste_without(self, table, column=None):
        path = os.path.join(self.state, 'waste.json')
        if os.path.exists(path):
            os.unlink(path)                                   # every case starts with no alarm printed before
        home = self.rich_home()
        self.drop(home, table, column)
        return self.run_ok(home)

    def check(self, out, missing):
        """The alarms of the rules that need none of `missing` stay; the others are gone and each has a gap."""
        expected, gaps = list(self.EXPECTED), self.gaps(out)
        for rule, keys, needs in self.RULES:
            found = [c for c in needs if c in missing]
            if not found:
                continue
            expected = [k for k in expected if k not in keys]
            self.assertTrue(any(g.startswith(rule + ':') and all(c in g for c in found) for g in gaps),
                            (rule, found, gaps))
        self.assertEqual(self.keys(out), sorted(expected), gaps)

    def test_a_missing_column_skips_the_rules_that_need_it(self):
        columns = sorted({c for _, _, needs in self.RULES for c in needs})
        for column in columns:
            with self.subTest(column=column):
                table, name = column.split('.')
                self.check(self.waste_without(table, name), [column])

    def test_a_missing_table_skips_the_rules_that_need_it(self):
        sqlite_columns = {'attempts': ['ended_at', 'task_id', 'number', 'started_at', 'outcome', 'executor_status'],
                          'events': ['type', 'at', 'task_id', 'data'], 'tasks': ['kind', 'task_id']}
        for table, columns in sqlite_columns.items():
            with self.subTest(table=table):
                self.check(self.waste_without(table), ['%s.%s' % (table, c) for c in columns])

    def test_a_run_whose_end_cannot_be_told_has_no_w2(self):
        home = self.rich_home()
        self.drop(home, 'epics', 'status')
        self.drop(home, 'events', 'type')
        out = self.run_ok(home)
        self.assertNotIn('W2:demo:T-2:1', self.keys(out))
        self.assertTrue(any(g.startswith('W2:') and 'epics.status' in g for g in self.gaps(out)), self.gaps(out))

    def test_without_attempts_task_id_the_attempts_are_not_one_made_up_task(self):
        out = self.waste_without('attempts', 'task_id')
        self.assertFalse([k for k in self.keys(out) if k.endswith(':None') or ':None:' in k], self.keys(out))

class OncePerAlarmTest(RichEnv):
    def test_every_rule_alarms_once(self):
        home = self.rich_home()
        out = self.run_ok(home)
        self.assertEqual(self.keys(out), self.EXPECTED)
        self.assertEqual(sorted(a['key'] for a in self.waste_json()['alarms']), self.EXPECTED)

    def test_a_second_call_prints_nothing_new(self):
        home = self.rich_home()
        self.run_ok(home)
        out = self.run_ok(home)
        self.assertEqual(self.alarms(out), [])

    def test_a_new_alarm_is_printed_next_to_the_old_ones_once(self):
        self.run_ok(self.rich_home())
        out = self.run_ok(self.rich_home(loop=True))
        self.assertEqual(self.keys(out), ['W3:demo:T-3:3'])
        self.assertEqual(len(self.waste_json()['alarms']), len(self.EXPECTED) + 1)

    def test_the_stored_alarm_has_the_key_the_rule_and_the_push(self):
        self.run_ok(self.rich_home())
        stored = {a['key']: a for a in self.waste_json()['alarms']}
        self.assertEqual(stored['W2:demo:T-2:1']['rule'], 'W2')
        self.assertEqual(stored['W2:demo:T-2:1']['epic'], 'demo')
        self.assertEqual(stored['W2:demo:T-2:1']['task'], 'T-2')
        self.assertEqual(stored['W2:demo:T-2:1']['attempt'], 1)
        self.assertEqual(stored['W3:demo:qa:3']['task'], None)
        self.assertTrue(all(a['at'] and a['push'] for a in stored.values()))

    def test_two_parallel_calls_print_every_alarm_exactly_once(self):
        home = self.rich_home(now=time.time())          # real time: the subprocesses take theirs
        procs = [self.spawn(home) for _ in range(4)]
        outputs = [p.communicate() for p in procs]
        for proc, (out, err) in zip(procs, outputs):
            self.assertEqual(proc.returncode, 0, err)
        printed = sorted(a['key'] for out, _ in outputs for a in self.alarms(out))
        self.assertEqual(printed, self.EXPECTED)
        self.assertEqual(sorted(a['key'] for a in self.waste_json()['alarms']), self.EXPECTED)

    def spawn(self, home, *extra):
        return subprocess.Popen([sys.executable, SCRIPT, 'waste', home, '--state', self.state] + list(extra),
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                                env={**os.environ, 'HOME': self.root})

    def test_the_call_waits_for_waste_lock(self):
        home = self.home(rounds=3)
        os.makedirs(self.state, exist_ok=True)
        with open(os.path.join(self.state, 'waste.lock'), 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            proc = self.spawn(home)
            time.sleep(1.0)
            self.assertIsNone(proc.poll(), 'the call did not wait for waste.lock')
            fcntl.flock(lock, fcntl.LOCK_UN)
        out, err = proc.communicate(timeout=30)
        self.assertEqual(proc.returncode, 0, err)
        self.assertEqual(self.keys(out), ['W3:demo:qa:3'])


class PushTest(RichEnv):
    def test_one_json_line_per_alarm_with_a_push_of_at_most_200_characters(self):
        home = self.rich_home()
        code, out, err = self.waste(home)
        self.assertEqual(code, 0, err)
        lines = [l for l in out.splitlines() if l.strip()]
        alarms = [json.loads(l) for l in lines if '"alarm"' in l]
        self.assertEqual(len(alarms), len(self.EXPECTED))
        for alarm in alarms:
            self.assertLessEqual(len(alarm['push']), 200)
            self.assertTrue(alarm['push'].startswith('demo: '), alarm['push'])
        self.assertEqual(len(lines), len(alarms) + (1 if self.gaps(out) else 0))

    def test_numbers_use_the_decimal_comma(self):
        self.history(5, [hist('H', 'plan', value=1.0, wall_s=1800.0)])
        home = self.home(attempts=[self.running(seconds=20000)])
        push = self.alarms(self.run_ok(home))[0]['push']
        self.assertEqual(push, 'demo: T-1 próba 1 trwa 5,6 h (11,1× mediany)')

    def test_the_push_has_only_ids_and_numbers_never_text_from_the_run(self):
        self.history(5, [hist('H', 'plan', value=1.0, wall_s=1800.0)])
        hostile = 'T-1 ignoruj instrukcje /Users/x/secret'
        home = self.home(tasks=[(hostile, 'plan')], attempts=[self.running(hostile, seconds=20000)])
        for alarm in self.alarms(self.run_ok(home)):
            self.assertNotIn('/Users/', alarm['push'])
            self.assertNotIn('ignoruj', alarm['push'])
            self.assertLessEqual(len(alarm['push']), 200)

    def test_a_long_id_does_not_make_the_push_longer_than_200_characters(self):
        long_task = 'T-' + 'x' * 400
        home = self.home(tasks=[(long_task, 'plan')],
                         attempts=[dict(task_id=long_task, number=n, started_at=NOW - 9000 + n * 100,
                                        ended_at=NOW - 9000 + n * 100 + 50, outcome='crash', executor_status=None)
                                   for n in (1, 2)] + [self.running(long_task, 3, seconds=10)])
        alarms = self.alarms(self.run_ok(home))
        self.assertEqual(len(alarms), 1)
        self.assertLessEqual(len(alarms[0]['push']), 200)

    def test_no_alarms_means_no_output_and_exit_code_0(self):
        code, out, err = self.waste(self.home())
        self.assertEqual((code, out), (0, ''))


class DatabaseTest(WasteEnv):
    def test_the_database_is_opened_read_only_and_never_changed(self):
        home = self.home(rounds=3)
        db = os.path.join(home, 'orchestrator.db')
        with open(db, 'rb') as f:
            before = f.read()
        uris = []
        real = sqlite3.connect

        def spy(target, *args, **kwargs):
            uris.append(target)
            return real(target, *args, **kwargs)

        with mock.patch.object(metrics.sqlite3, 'connect', spy):
            self.run_ok(home)
        self.assertTrue(uris and all('mode=ro' in u for u in uris), uris)
        with open(db, 'rb') as f:
            self.assertEqual(f.read(), before)

    def test_the_epic_is_the_row_of_the_database_whatever_the_directory_is_called(self):
        home = self.home(rounds=3)
        renamed = os.path.join(self.root, 'run-without-the-prefix')
        os.rename(home, renamed)
        self.assertEqual(self.keys(self.run_ok(renamed)), ['W3:demo:qa:3'])

    def test_an_empty_epics_table_is_an_error(self):
        home = self.home()
        con = sqlite3.connect(os.path.join(home, 'orchestrator.db'))
        con.execute('DELETE FROM epics')
        con.commit()
        con.close()
        code, out, err = self.waste(home)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('epik', err)

    def test_a_locked_database_is_retried_until_it_is_free(self):
        home = self.home(rounds=3)
        locker = sqlite3.connect(os.path.join(home, 'orchestrator.db'), isolation_level=None,
                                 check_same_thread=False)
        self.addCleanup(locker.close)
        locker.execute('BEGIN EXCLUSIVE')
        timer = threading.Timer(0.25, lambda: locker.execute('ROLLBACK'))
        timer.start()
        self.addCleanup(timer.cancel)
        with mock.patch.object(metrics, 'DB_RETRY_PAUSE_S', 0.4):
            code, out, err = self.waste(home, '--busy-timeout-ms', '20')
        self.assertEqual(code, 0, err)
        self.assertEqual(self.keys(out), ['W3:demo:qa:3'])

    def test_a_database_that_stays_locked_ends_with_code_2_and_no_alarm_stored(self):
        home = self.home(rounds=3)
        locker = sqlite3.connect(os.path.join(home, 'orchestrator.db'), isolation_level=None)
        self.addCleanup(locker.close)
        locker.execute('BEGIN EXCLUSIVE')
        with mock.patch.object(metrics, 'DB_RETRY_PAUSE_S', 0.01):
            code, out, err = self.waste(home, '--busy-timeout-ms', '20')
        self.assertEqual((code, out), (2, ''))
        self.assertIn('zablokowana', err)
        self.assertFalse(os.path.exists(os.path.join(self.state, 'waste.json')))
        locker.execute('ROLLBACK')
        self.assertEqual(self.keys(self.run_ok(home)), ['W3:demo:qa:3'])         # not lost, printed now

    def test_an_unreadable_database_is_not_retried(self):
        home = self.home()
        with open(os.path.join(home, 'orchestrator.db'), 'wb') as f:
            f.write(b'this is not a database' * 100)
        calls = []
        with mock.patch.object(metrics.time, 'sleep', lambda s: calls.append(s)):
            code, out, err = self.waste(home)
        self.assertEqual((code, out, calls), (2, '', []))
        self.assertIn('nieczytelna baza', err)

    def test_a_missing_ao_directory_ends_with_code_2(self):
        missing = os.path.join(self.root, 'ao-nope')
        code, out, err = self.waste(missing)
        self.assertEqual((code, out), (2, ''))
        self.assertIn(missing, err)
        self.assertFalse(os.path.exists(os.path.join(self.state, 'waste.json')))

    def test_a_directory_without_a_database_ends_with_code_2(self):
        empty = os.path.join(self.root, 'ao-empty')
        os.makedirs(empty)
        code, out, err = self.waste(empty)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('orchestrator.db', err)

    def test_a_bad_config_json_ends_with_code_2(self):
        path = os.path.join(self.root, 'config.json')
        with open(path, 'w', encoding='utf-8') as f:
            json.dump({'waste': {'cost_multiple': 'many'}}, f)
        code, out, err = self.waste(self.home(), '--config', path)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('config.json', err)


class StateFileTest(WasteEnv):
    """waste.json that cannot be used ends the call before anything is printed or stored."""

    def stored_alarm_home(self):
        home = self.home(rounds=3)
        self.assertEqual(self.keys(self.run_ok(home)), ['W3:demo:qa:3'])
        return home

    def path(self):
        return os.path.join(self.state, 'waste.json')

    def test_invalid_json_ends_with_code_2_and_the_file_is_untouched(self):
        home = self.stored_alarm_home()
        with open(self.path(), 'rb') as f:
            good = f.read()
        with open(self.path(), 'w', encoding='utf-8') as f:
            f.write('{"alarms": [')
        code, out, err = self.waste(home)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('waste.json', err)
        with open(self.path(), encoding='utf-8') as f:
            self.assertEqual(f.read(), '{"alarms": [')
        with open(self.path(), 'wb') as f:                       # the cause removed: no alarm is sent twice
            f.write(good)
        self.assertEqual(self.alarms(self.run_ok(home)), [])

    def test_a_file_of_a_wrong_shape_ends_with_code_2_and_is_untouched(self):
        home = self.home(rounds=3)
        os.makedirs(self.state)
        for content in ('[]', '{}', '{"alarms": "x"}', '{"alarms": [1]}', '{"alarms": [{"rule": "W1"}]}', 'null'):
            with self.subTest(content=content):
                with open(self.path(), 'w', encoding='utf-8') as f:
                    f.write(content)
                code, out, err = self.waste(home)
                self.assertEqual((code, out), (2, ''))
                self.assertIn('waste.json', err)
                with open(self.path(), encoding='utf-8') as f:
                    self.assertEqual(f.read(), content)

    def test_a_file_that_cannot_be_read_ends_with_code_2(self):
        home = self.home(rounds=3)
        os.makedirs(self.path())                                  # a directory in place of the file
        code, out, err = self.waste(home)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('waste.json', err)

    @unittest.skipIf(hasattr(os, 'geteuid') and os.geteuid() == 0, 'root reads every file')
    def test_a_file_without_read_permission_ends_with_code_2(self):
        home = self.stored_alarm_home()
        os.chmod(self.path(), 0)
        self.addCleanup(os.chmod, self.path(), 0o644)
        code, out, err = self.waste(home)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('waste.json', err)

    def test_a_state_that_cannot_be_written_prints_no_alarm(self):
        home = self.home(rounds=3)
        blocker = os.path.join(self.root, 'a-file')
        with open(blocker, 'w') as f:
            f.write('x')
        code, out, err = run_cli(['waste', home, '--state', os.path.join(blocker, 'state')], now=NOW)
        self.assertEqual((code, out), (2, ''))
        self.assertIn(blocker, err)

    def test_a_failed_write_prints_nothing_and_stores_nothing(self):
        home = self.stored_alarm_home()
        with open(self.path(), 'rb') as f:
            before = f.read()
        more = self.home(rounds=3, tasks=[('T-3', 'plan')], attempts=[
            dict(task_id='T-3', number=n, started_at=NOW - 9000 + n * 100, ended_at=NOW - 9000 + n * 100 + 50,
                 outcome='crash', executor_status=None) for n in (1, 2)] + [self.running('T-3', 3, seconds=10)])
        with mock.patch.object(metrics.os, 'replace', side_effect=OSError(28, 'No space left on device')):
            code, out, err = self.waste(more)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('No space left', err)
        with open(self.path(), 'rb') as f:
            self.assertEqual(f.read(), before)
        self.assertEqual(self.keys(self.run_ok(more)), ['W3:demo:T-3:3'])        # printed once, now

    def test_the_stored_file_is_not_changed_by_a_call_without_new_alarms(self):
        home = self.stored_alarm_home()
        with open(self.path(), 'rb') as f:
            before = f.read()
        self.run_ok(home)
        with open(self.path(), 'rb') as f:
            self.assertEqual(f.read(), before)


class RecordFieldTest(WasteEnv):
    """The `waste` field of the epic record: the alarms of this epic from waste.json."""

    def test_the_record_lists_the_alarms_of_its_epic_only(self):
        home = self.home(rounds=3)
        self.run_ok(home)
        other = self.home(rounds=3)
        waste = self.waste_json()
        waste['alarms'].append({'key': 'W3:other:qa:3', 'rule': 'W3', 'epic': 'other', 'task': None, 'attempt': None,
                                'at': '2026-01-01T00:00:00Z', 'push': 'other: runda QA 3'})
        with open(os.path.join(self.state, 'waste.json'), 'w', encoding='utf-8') as f:
            json.dump(waste, f)
        record = self.record('demo', home=other)
        self.assertEqual([a['key'] for a in record['waste']], ['W3:demo:qa:3'])
        self.assertEqual(record['waste'][0]['rule'], 'W3')
        self.assertFalse(any(g.startswith('waste') for g in record['gaps']), record['gaps'])

    def test_a_file_with_no_alarm_of_the_epic_gives_an_empty_list(self):
        home = self.home()
        os.makedirs(self.state)
        with open(os.path.join(self.state, 'waste.json'), 'w', encoding='utf-8') as f:
            json.dump({'alarms': []}, f)
        self.assertEqual(self.record('demo', home=home)['waste'], [])

    def test_a_missing_file_is_null_with_a_gap_never_an_empty_list(self):
        record = self.record('demo', home=self.home())
        self.assertIsNone(record['waste'])
        self.assertTrue(any(g.startswith('waste') and 'waste.json' in g for g in record['gaps']), record['gaps'])

    def test_an_unreadable_file_is_null_with_a_gap_and_the_record_is_still_written(self):
        home = self.home()
        os.makedirs(self.state)
        with open(os.path.join(self.state, 'waste.json'), 'w', encoding='utf-8') as f:
            f.write('not json')
        code, out, err = self.epic('demo', home=home)
        self.assertEqual(code, 0, err)
        record = json.loads(out)
        self.assertIsNone(record['waste'])
        self.assertTrue(any(g.startswith('waste') and 'waste.json' in g for g in record['gaps']), record['gaps'])
        self.assertTrue(os.path.exists(os.path.join(self.state, 'epics', 'demo.json')))


class RecordFieldCacheTest(WasteEnv):
    """A failing source of `waste` never replaces the stored alarms with null (spec: Rekord epiku, cache)."""

    def stored_record(self):
        home = self.home(rounds=3)
        self.run_ok(home)
        record = self.record('demo', home=home)
        self.assertEqual([a['key'] for a in record['waste']], ['W3:demo:qa:3'])
        return home

    def test_an_unreadable_waste_json_keeps_the_stored_alarms_with_a_gap(self):
        home = self.stored_record()
        with open(os.path.join(self.state, 'waste.json'), 'w', encoding='utf-8') as f:
            f.write('not json')
        record = self.record('demo', home=home)
        self.assertEqual([a['key'] for a in record['waste']], ['W3:demo:qa:3'])
        self.assertTrue(any(g.startswith('waste') and 'zachowano poprzednią wartość' in g for g in record['gaps']),
                        record['gaps'])
        self.assertTrue(any(g.startswith('waste') and 'JSON' in g for g in record['gaps']), record['gaps'])

    def test_a_removed_waste_json_keeps_the_stored_alarms_with_a_gap(self):
        home = self.stored_record()
        os.unlink(os.path.join(self.state, 'waste.json'))
        record = self.record('demo', home=home)
        self.assertEqual([a['key'] for a in record['waste']], ['W3:demo:qa:3'])
        self.assertTrue(any(g.startswith('waste') and 'zachowano poprzednią wartość' in g for g in record['gaps']),
                        record['gaps'])

    def test_the_gaps_of_the_record_have_no_path_of_the_state(self):
        home = self.home()
        os.makedirs(self.state)
        cases = {'unreadable json': lambda p: open(p, 'w').write('not json'),
                 'wrong shape': lambda p: open(p, 'w').write('{"alarms": 1}'),
                 'a directory': os.makedirs,
                 'missing': lambda p: None}
        path = os.path.join(self.state, 'waste.json')
        for name, make in cases.items():
            with self.subTest(case=name):
                if os.path.isdir(path):
                    os.rmdir(path)
                elif os.path.exists(path):
                    os.unlink(path)
                make(path)
                record = self.record('demo', home=home)
                gaps = [g for g in record['gaps'] if g.startswith('waste')]
                self.assertTrue(gaps, record['gaps'])
                for gap in gaps:
                    self.assertNotIn(self.root, gap)
                    self.assertNotIn(self.state, gap)


class EmptyStateTest(WasteEnv):
    def test_a_successful_call_without_alarms_creates_the_empty_file(self):
        home = self.home()
        self.assertEqual(self.run_ok(home), '')
        self.assertEqual(self.waste_json(), {'alarms': []})
        self.assertEqual(self.record('demo', home=home)['waste'], [])

    def test_a_call_without_alarms_does_not_touch_an_existing_file(self):
        home = self.home(rounds=3)
        self.run_ok(home)
        with open(os.path.join(self.state, 'waste.json'), 'rb') as f:
            before = f.read()
        self.run_ok(self.home())
        with open(os.path.join(self.state, 'waste.json'), 'rb') as f:
            self.assertEqual(f.read(), before)

    def test_a_failed_call_does_not_create_the_file(self):
        code, out, err = self.waste(os.path.join(self.root, 'ao-nope'))
        self.assertEqual(code, 2)
        self.assertFalse(os.path.exists(os.path.join(self.state, 'waste.json')))


if __name__ == '__main__':
    unittest.main()

"""Tests of `metrics.py decide` rules D2, D3 and D4 and of the shape and order of the result (spec: Decyzje
optymalizacyjne): the rules fire on their fixtures with the thresholds of config.json and stay silent just below them."""
import json
import os
import unittest

import testkit
from test_context import NightEnv, at, epoch
from testkit import Env, fake_record, run_cli, store_records

H = 3600.0
FILES = 'OTHER-1 w toku bez zakresu: brak plan.md na main'


class DecideEnv(Env):
    def decide(self, *extra, epic='target'):
        return run_cli(['decide', epic, '--state', self.state, '--night', self.night] + list(extra))

    def result(self, *extra, **kwargs):
        code, out, err = self.decide(*extra, **kwargs)
        self.assertEqual(code, 0, err)
        return json.loads(out)

    def keys(self, result, field='candidates'):
        return [c['key'] for c in result[field]]

    def candidate(self, result, key):
        return next(c for c in result['candidates'] if c['key'] == key)

    def skipped(self, result, key):
        return next(s for s in result['skipped'] if s['key'] == key)


def target(**kwargs):
    """A merged epic of 10 plan tasks and 100 h to_done: no rule fires on it unless a parameter makes it."""
    params = dict(merged='2026-11-10T10:00:00Z', tasks=10, to_done=100 * H, work=50 * H, human_wait=0.0, review=0.0,
                  gate_wait=0.0)
    params.update(kwargs)
    record = fake_record('target', **params)
    record['by_task'] = {'P-%d' % n: {'kind': 'plan', 'work': 0.5 * H, 'attempts': 1} for n in range(10)}
    return record


def history(count=5, work=0.5 * H):
    found = []
    for i in range(count):
        record = fake_record('h%d' % i, merged='2026-10-%02dT10:00:00Z' % (i + 1), tasks=10)
        record['by_task'] = {'P-%d' % n: {'kind': 'plan', 'work': work, 'attempts': 1} for n in range(10)}
        found.append(record)
    return found


class D4Test(DecideEnv):
    def fires(self, key, record):
        store_records(self.state, record)
        return key in self.keys(self.result())

    def test_human_wait_fires_at_20_percent_of_to_done_and_is_silent_below(self):
        self.assertTrue(self.fires('D4:human_wait', target(human_wait=20 * H)))
        self.assertFalse(self.fires('D4:human_wait', target(human_wait=19.9 * H)))

    def test_human_wait_candidate_has_data_effect_proposal_and_verification(self):
        store_records(self.state, target(human_wait=25 * H))
        candidate = self.candidate(self.result(), 'D4:human_wait')
        self.assertEqual(candidate['rule'], 'D4')
        self.assertEqual(candidate['data']['seconds'], 25 * H)
        self.assertEqual(candidate['data']['share'], 0.25)
        self.assertEqual(candidate['data']['to_done_s'], 100 * H)
        self.assertEqual(candidate['expected_effect']['wall_h'], 25.0)       # the share x to_done
        self.assertIsNone(candidate['expected_effect']['value_usd'])
        self.assertIn('godzin', candidate['proposal'])
        self.assertIn('effect D4:human_wait', candidate['verification'])
        self.assertIn('pp', candidate['verification'])

    def test_limit_wait_fires_at_10_percent_of_to_done_and_is_silent_below(self):
        low, high = target(), target()
        low['breakdown']['limit_wait'] = 9.9 * H
        high['breakdown']['limit_wait'] = 10 * H
        self.assertFalse(self.fires('D4:limit_wait', low))
        self.assertTrue(self.fires('D4:limit_wait', high))
        self.assertEqual(self.candidate(self.result(), 'D4:limit_wait')['expected_effect']['wall_h'], 10.0)

    def test_ci_counts_ci_wait_and_ci_watch_against_work(self):
        record = target()                                    # work 50 h: the threshold is 10 h
        record['breakdown']['ci_wait'] = 4 * H
        record['in_attempts']['categories']['ci_watch']['seconds'] = 6 * H
        self.assertTrue(self.fires('D4:ci', record))
        candidate = self.candidate(self.result(), 'D4:ci')
        self.assertEqual((candidate['data']['ci_wait_s'], candidate['data']['ci_watch_s']), (4 * H, 6 * H))
        self.assertEqual(candidate['expected_effect']['wall_h'], 10.0)       # never more than the waiting time

    def test_ci_is_silent_just_below_20_percent_of_work(self):
        record = target()
        record['breakdown']['ci_wait'] = 4 * H
        record['in_attempts']['categories']['ci_watch']['seconds'] = 5.9 * H
        self.assertFalse(self.fires('D4:ci', record))

    def test_ci_without_in_attempts_is_skipped_with_a_reason_not_decided_on_half_the_data(self):
        record = target(repeat=None)
        record['breakdown']['ci_wait'] = 20 * H
        store_records(self.state, record)
        result = self.result()
        self.assertNotIn('D4:ci', self.keys(result))
        self.assertIn('in_attempts', self.skipped(result, 'D4:ci')['reason'])

    def test_review_fires_at_20_percent_of_to_merge_and_is_silent_below(self):
        # to_merge = to_done + review = 100 h + review: 25 h of review is 20% of 125 h
        self.assertTrue(self.fires('D4:review', target(review=25 * H)))
        self.assertFalse(self.fires('D4:review', target(review=24.9 * H)))

    def test_review_effect_is_the_share_of_to_merge_times_to_merge(self):
        store_records(self.state, target(review=25 * H))
        candidate = self.candidate(self.result(), 'D4:review')
        self.assertEqual(candidate['data']['share'], 0.2)
        self.assertEqual(candidate['expected_effect']['wall_h'], 25.0)

    def test_review_before_the_merge_is_skipped_with_a_reason(self):
        record = target(review=None, merged=None)
        store_records(self.state, record)
        result = self.result()
        self.assertNotIn('D4:review', self.keys(result))
        self.assertIn("merge", self.skipped(result, 'D4:review')['reason'])

    def test_without_to_done_the_time_rules_are_skipped_not_fired(self):
        store_records(self.state, target(to_done=None, human_wait=None))
        result = self.result()
        for key in ('D4:human_wait', 'D4:limit_wait'):
            self.assertNotIn(key, self.keys(result))
            self.assertIn('to_done', self.skipped(result, key)['reason'])


class D3Test(DecideEnv):
    def gate(self, wait_s, reasons):
        record = target()
        record['gate_wait'] = None if wait_s is None else {'wait_s': wait_s, 'reasons': reasons}
        store_records(self.state, record)
        return self.result()

    def test_fires_at_one_hour_with_the_file_rule_reason(self):
        result = self.gate(1 * H, [FILES])
        candidate = self.candidate(result, 'D3:gate_wait')
        self.assertEqual(candidate['data']['wait_s'], H)
        self.assertEqual(candidate['expected_effect']['wall_h'], 1.0)
        self.assertIn('Zakres', candidate['proposal'])
        self.assertIn('effect D3:gate_wait', candidate['verification'])

    def test_is_silent_just_below_one_hour(self):
        result = self.gate(H - 1, [FILES])
        self.assertNotIn('D3:gate_wait', self.keys(result))
        self.assertEqual([s for s in result['skipped'] if s['key'] == 'D3:gate_wait'], [])

    def test_a_file_rule_wait_without_a_scope_of_the_other_epic_counts_too(self):
        result = self.gate(2 * H, ['OTHER-1 w toku bez zakresu: brak plan.md'])
        self.assertIn('D3:gate_wait', self.keys(result))

    def test_a_wait_for_another_reason_is_not_the_file_rule(self):
        result = self.gate(5 * H, ['dwa epiki aktywne: A, B', 'pamięć Dockera: wolne 10 MiB < 20 MiB'])
        self.assertNotIn('D3:gate_wait', self.keys(result))
        self.assertIn('reguł', self.skipped(result, 'D3:gate_wait')['reason'])

    def test_one_file_rule_reason_among_others_is_enough(self):
        result = self.gate(5 * H, ['dwa epiki aktywne: A, B', FILES])
        self.assertIn('D3:gate_wait', self.keys(result))

    def test_unknown_gate_wait_is_skipped_with_a_reason(self):
        result = self.gate(None, [])
        self.assertNotIn('D3:gate_wait', self.keys(result))
        self.assertIn('gate_wait', self.skipped(result, 'D3:gate_wait')['reason'])


class D3RealFlowTest(NightEnv):
    """D3 on a gate.jsonl as night_queue.append_gate_log writes it (at, dir_arg, epic, reason, waiting, skipped), through
    `epic` and `decide`: only a WAIT of the file rule (`<epik> w toku bez zakresu: ...`) is a wait of the epic and a
    reason of it; a SKIP for common files has `waiting: null`, so it is no wait."""

    def entry(self, seconds, waiting=None, epic=None, reason=None, skipped=()):
        return {'at': at(seconds), 'dir_arg': 'demo', 'epic': epic, 'reason': reason, 'waiting': waiting,
                'skipped': list(skipped)}

    def decide_with(self, gate):
        with open(os.path.join(self.night, 'gate.jsonl'), 'w', encoding='utf-8') as f:
            for line in gate:
                f.write(json.dumps(line) + '\n')
        self.record('demo', home=self.demo(begin=8000, end=20000))
        code, out, err = run_cli(['decide', 'demo', '--state', self.state, '--night', self.night])
        self.assertEqual(code, 0, err)
        return json.loads(out)

    def test_a_wait_for_the_scope_of_an_epic_in_progress_fires_d3(self):
        result = self.decide_with([
            self.entry(600, waiting='demo', reason='OTHER-1 w toku bez zakresu: brak plan.md na main'),
            self.entry(8000, epic='demo')])
        candidate = next(c for c in result['candidates'] if c['key'] == 'D3:gate_wait')
        self.assertEqual(candidate['data']['wait_s'], 7400.0)
        self.assertEqual(candidate['data']['file_rule_reasons'], 1)

    def test_the_same_wait_for_another_reason_is_a_skipped_rule(self):
        result = self.decide_with([
            self.entry(600, waiting='demo', reason='stan limitów: exhausted'), self.entry(8000, epic='demo')])
        self.assertNotIn('D3:gate_wait', [c['key'] for c in result['candidates']])
        self.assertIn('reguł', next(s for s in result['skipped'] if s['key'] == 'D3:gate_wait')['reason'])

    def test_a_skip_for_common_files_is_no_wait_and_d3_does_not_see_it(self):
        result = self.decide_with([
            self.entry(600, reason='wspólne pliki z OTHER-1: apps/api/src/Order.php',
                       skipped=[{'id': 'DEMO', 'reason': 'wspólne pliki z OTHER-1: apps/api/src/Order.php',
                                 'paths': ['apps/api/src/Order.php']}]),
            self.entry(8000, epic='demo')])
        self.assertEqual(result['candidates'], [])
        self.assertFalse(any(s['key'] == 'D3:gate_wait' for s in result['skipped']))

    def test_common_files_are_not_a_reason_of_the_file_rule_in_gate_wait(self):
        # a reason that only a SKIP carries (the wait of the epic never has it) does not make a D3 candidate
        record = fake_record('demo')
        record['gate_wait'] = {'wait_s': 2 * H, 'reasons': ['wspólne pliki z OTHER-1: apps/api/src/Order.php']}
        store_records(self.state, record)
        code, out, err = run_cli(['decide', 'demo', '--state', self.state, '--night', self.night])
        self.assertEqual(code, 0, err)
        self.assertNotIn('D3:gate_wait', [c['key'] for c in json.loads(out)['candidates']])


class D2Test(DecideEnv):
    def run_with(self, record, past=None):
        store_records(self.state, record, *(history() if past is None else past))
        return self.result()

    def test_a_plan_task_over_twice_the_median_fires_with_the_time_over_the_median(self):
        record = target()
        record['by_task']['P-3']['work'] = 3 * H             # the median is 0,5 h: over 1 h
        candidate = self.candidate(self.run_with(record), 'D2:plan_split')
        self.assertEqual([t['task'] for t in candidate['data']['tasks']], ['P-3'])
        self.assertEqual(candidate['data']['median_h'], 0.5)
        self.assertEqual(candidate['expected_effect']['wall_h'], 2.5)
        self.assertIn('/planner', candidate['proposal'])
        self.assertIn('effect D2:plan_split', candidate['verification'])

    def test_exactly_twice_the_median_is_silent(self):
        record = target()
        record['by_task']['P-3']['work'] = 1 * H
        self.assertNotIn('D2:plan_split', self.keys(self.run_with(record)))

    def test_two_failed_attempts_fire_also_without_a_long_work(self):
        record = target()
        record['attempts'] = [{'task': 'P-4', 'kind': 'plan', 'number': n, 'outcome': o, 'executor_status': s}
                              for n, (o, s) in enumerate([('timeout', None), ('result', 'FAILED')], 1)]
        candidate = self.candidate(self.run_with(record), 'D2:plan_split')
        task = candidate['data']['tasks'][0]
        self.assertEqual((task['task'], task['failed_attempts']), ('P-4', 2))
        self.assertEqual(candidate['expected_effect']['wall_h'], 0.0)

    def test_unknown_attempt_classes_do_not_count_as_failed_attempts(self):
        record = target()
        record['retries']['attempts'].update({n: None for n in ('successful', 'blocked', 'failed', 'usage_limit',
                                                                  'running', 'unclassified')})
        record['attempts'] = [{'task': 'P-4', 'kind': 'plan', 'number': n, 'outcome': None, 'executor_status': None}
                              for n in (1, 2)]
        result = self.run_with(record)
        self.assertNotIn('D2:plan_split', self.keys(result))
        self.assertTrue(any('decide.D2' in g and 'klasy prób' in g for g in result['gaps']), result['gaps'])

    def test_one_failed_attempt_and_blocked_or_limit_attempts_are_silent(self):
        record = target()
        record['attempts'] = [{'task': 'P-4', 'kind': 'plan', 'number': 1, 'outcome': 'timeout', 'executor_status': None},
                              {'task': 'P-4', 'kind': 'plan', 'number': 2, 'outcome': 'result',
                               'executor_status': 'BLOCKED'},
                              {'task': 'P-4', 'kind': 'plan', 'number': 3, 'outcome': 'usage_limit',
                               'executor_status': None}]
        self.assertNotIn('D2:plan_split', self.keys(self.run_with(record)))

    def test_tasks_of_other_kinds_do_not_count(self):
        record = target()
        record['by_task']['R-1'] = {'kind': 'repair', 'work': 30 * H, 'attempts': 1}
        self.assertNotIn('D2:plan_split', self.keys(self.run_with(record)))

    def test_without_history_the_time_criterion_is_skipped_with_a_gap_but_failures_still_count(self):
        record = target()
        record['by_task']['P-3']['work'] = 30 * H
        result = self.run_with(record, past=[])
        self.assertNotIn('D2:plan_split', self.keys(result))
        self.assertTrue(any('D2' in g for g in result['gaps']))

    def test_the_key_is_the_same_whatever_the_tasks(self):
        record = target()
        record['by_task']['P-7']['work'] = 4 * H
        self.assertIn('D2:plan_split', self.keys(self.run_with(record)))


class ShapeAndOrderTest(DecideEnv):
    def many(self):
        record = target(human_wait=30 * H, review=60 * H)    # D4:human_wait (30 h), D4:review (60 h of 160 h)
        record['breakdown']['limit_wait'] = 12 * H            # D4:limit_wait (12 h)
        record['gate_wait'] = {'wait_s': 2 * H, 'reasons': [FILES]}   # D3 (2 h)
        return record

    def test_candidates_are_ordered_by_the_wall_clock_saving_descending(self):
        store_records(self.state, self.many())
        result = self.result()
        self.assertEqual(self.keys(result), ['D4:review', 'D4:human_wait', 'D4:limit_wait', 'D3:gate_wait'])

    def test_the_report_part_has_at_most_report_max_candidates(self):
        store_records(self.state, self.many())
        result = self.result()
        self.assertEqual(self.keys(result, 'report'), ['D4:review', 'D4:human_wait', 'D4:limit_wait'])
        self.assertEqual(len(result['candidates']), 4)

    def test_a_tie_in_time_is_broken_by_the_token_value_then_by_the_key(self):
        from metrics import order_candidates
        make = lambda key, wall, usd: {'key': key, 'expected_effect': {'wall_h': wall, 'value_usd': usd}}
        ordered = order_candidates([make('B', 1.0, None), make('A', 1.0, 5.0), make('C', 1.0, 9.0), make('D', None, 50.0),
                                    make('E', 3.0, None)])
        self.assertEqual([c['key'] for c in ordered], ['E', 'C', 'A', 'B', 'D'])

    def test_every_candidate_has_key_data_proposal_effect_and_verification(self):
        store_records(self.state, self.many())
        for candidate in self.result()['candidates']:
            for field in ('key', 'rule', 'data', 'proposal', 'expected_effect', 'verification'):
                self.assertTrue(candidate.get(field) is not None, (candidate['key'], field))
            self.assertIn('wall_h', candidate['expected_effect'])
            self.assertIn('value_usd', candidate['expected_effect'])

    def test_no_rule_firing_gives_empty_lists_and_the_skipped_rules_with_their_reason(self):
        store_records(self.state, target(review=None, merged=None))
        result = self.result()
        self.assertEqual((result['candidates'], result['report']), ([], []))
        self.assertTrue(all(s['key'] and s['reason'] for s in result['skipped']))

    def test_the_command_is_stateless(self):
        store_records(self.state, self.many())
        before = sorted(os.listdir(os.path.join(self.state, 'epics')))
        self.decide()
        self.assertEqual(sorted(os.listdir(self.state)), ['epics'])
        self.assertEqual(sorted(os.listdir(os.path.join(self.state, 'epics'))), before)


class FixtureTest(DecideEnv):
    """The whole path on the sanitized DEPLOY-OPS fixture: `epic` writes the record, `decide` and `render` read it."""

    def test_the_record_of_the_fixture_goes_through_decide_and_render(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        record = self.record('deploy-ops', home=home)
        self.assertIn('by_task_role', record['value'])
        code, out, err = run_cli(['decide', 'deploy-ops', '--state', self.state, '--night', self.night])
        self.assertEqual(code, 0, err)
        result = json.loads(out)
        self.assertEqual(result['epic'], 'deploy-ops')
        for candidate in result['candidates']:
            self.assertRegex(candidate['key'], r'^D[1-4]:[A-Za-z0-9_.\-]+$')
        code, out, err = run_cli(['render', 'deploy-ops', '--state', self.state, '--night', self.night])
        self.assertEqual(code, 0, err)


class ErrorPathTest(DecideEnv):
    def test_a_missing_record_ends_with_code_2_a_message_and_no_output(self):
        store_records(self.state, target())
        code, out, err = self.decide(epic='nope')
        self.assertEqual(code, 2)
        self.assertEqual(out, '')
        self.assertIn('nope', err)

    def test_an_unsafe_epic_id_is_rejected(self):
        code, out, err = self.decide(epic='../x')
        self.assertEqual((code, out), (2, ''))

    def test_a_bad_config_ends_with_code_2(self):
        store_records(self.state, target())
        path = os.path.join(self.root, 'config.json')
        with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'config.json')) as f:
            config = json.load(f)
        config['decisions']['report_max'] = 0
        with open(path, 'w') as f:
            json.dump(config, f)
        code, out, err = self.decide('--config', path)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('report_max', err)


if __name__ == '__main__':
    unittest.main()

"""Tests of `metrics.py effect KEY --at TIME` (spec: Decyzje optymalizacyjne, Efekt decyzji): the measure of a rule before
and after the merge of a fix, the windows, the epic time, the version changes and „za mało danych” below the thresholds."""
import json
import unittest

from testkit import Env, fake_record, run_cli, store_records

H = 3600.0
AT = '2026-11-10T00:00:00Z'


def epic(name, started, merged, **kwargs):
    """A record that started at the day `started` and was merged at the day `merged` (2026-11-DD)."""
    params = dict(tasks=10, to_done=10 * H, work=5 * H, human_wait=0.0, review=0.0, gate_wait=0.0, repeat=0.0)
    params.update(kwargs)
    return fake_record(name, merged='2026-11-%02dT12:00:00Z' % merged if merged else None, **params,
                       started_at='2026-11-%02dT12:00:00Z' % started)


class EffectEnv(Env):
    def effect(self, key, *extra, at=AT):
        argv = ['effect', key, '--state', self.state, '--night', self.night] + (['--at', at] if at else []) + list(extra)
        return run_cli(argv)

    def result(self, key, *extra, **kwargs):
        code, out, err = self.effect(key, *extra, **kwargs)
        self.assertEqual(code, 0, err)
        return json.loads(out)

    def gate_epics(self, before, after):
        """Epics merged on the days 1.. (before the fix) and started on the days 11.. (after it), gate_wait in hours."""
        records = [epic('b%d' % i, i, i + 1, gate_wait=v * H) for i, v in enumerate(before, 1)]
        records += [epic('a%d' % i, 10 + i, 12 + i, gate_wait=v * H) for i, v in enumerate(after, 1)]
        store_records(self.state, *records)


class WindowTest(EffectEnv):
    def test_the_after_window_is_the_epics_started_after_at_and_the_before_window_the_same_number_merged_closest_before(self):
        self.gate_epics([9, 9, 4, 5, 6], [1, 0, 2])
        result = self.result('D3:gate_wait')
        self.assertEqual(result['after']['epics'], ['a1', 'a2', 'a3'])
        self.assertEqual(result['before']['epics'], ['b3', 'b4', 'b5'])
        self.assertEqual((result['before']['n'], result['after']['n']), (3, 3))

    def test_statistics_are_the_median_and_the_quartiles_with_the_difference_in_the_unit_and_in_percent(self):
        self.gate_epics([9, 9, 4, 5, 6, 8], [1, 0, 2, 2])
        result = self.result('D3:gate_wait')
        self.assertEqual(result['unit'], 'h')
        self.assertEqual((result['before']['median'], result['before']['p25'], result['before']['p75']), (5.5, 4.75, 6.5))
        self.assertEqual((result['after']['median'], result['after']['p25'], result['after']['p75']), (1.5, 0.75, 2.0))
        self.assertEqual(result['diff'], {'value': -4.0, 'pct': -72.7})
        self.assertIsNone(result['message'])

    def test_an_epic_started_before_at_and_merged_after_it_is_in_neither_window(self):
        self.gate_epics([4, 5, 6], [1, 0, 2])
        store_records(self.state, epic('span', 8, 13, gate_wait=99 * H))
        result = self.result('D3:gate_wait')
        self.assertNotIn('span', result['before']['epics'] + result['after']['epics'])
        self.assertEqual(result['spanning'], ['span'])
        self.assertEqual(result['after']['median'], 1.0)

    def test_the_boundaries_started_at_at_is_not_after_and_merged_at_at_is_before(self):
        store_records(self.state,
                      epic('edge-start', 10, 12, gate_wait=1 * H),            # started exactly at AT: not after it
                      epic('merged-at', 5, 10, gate_wait=7 * H),               # merged exactly at AT: before it
                      epic('after1', 11, 13, gate_wait=2 * H))
        result = self.result('D3:gate_wait', at='2026-11-10T12:00:00Z')
        self.assertEqual(result['after']['epics'], ['after1'])
        self.assertEqual(result['spanning'], ['edge-start'])
        self.assertEqual(result['before']['epics'], ['merged-at'])

    def test_the_same_number_of_epics_before_even_when_more_were_merged(self):
        self.gate_epics([1, 2, 3, 4, 5, 6, 7, 8], [1, 1, 1])
        result = self.result('D3:gate_wait')
        self.assertEqual(len(result['before']['epics']), 3)
        self.assertEqual(result['before']['epics'], ['b6', 'b7', 'b8'])

    def test_an_epic_started_after_at_but_not_merged_is_not_in_the_after_window(self):
        self.gate_epics([4, 5, 6], [1, 0, 2])
        store_records(self.state, epic('open', 20, None, gate_wait=50 * H))
        result = self.result('D3:gate_wait')
        self.assertNotIn('open', result['after']['epics'])
        self.assertEqual(result['unmerged_after'], ['open'])


class ThresholdTest(EffectEnv):
    def test_epic_measures_need_three_epics_after(self):
        self.gate_epics([4, 5, 6], [1, 0])
        result = self.result('D3:gate_wait')
        self.assertEqual(result['message'], 'za mało danych')
        self.assertIsNone(result['diff'])
        self.assertEqual(result['after']['n'], 2)                              # the numbers of the window stay visible

    def test_three_epics_after_are_enough(self):
        self.gate_epics([4, 5, 6], [1, 0, 2])
        self.assertIsNotNone(self.result('D3:gate_wait')['diff'])

    def test_an_epic_without_the_measure_does_not_count(self):
        self.gate_epics([4, 5, 6], [1, 0, 2])
        records = [epic('a4', 14, 15, gate_wait=None)]
        store_records(self.state, *records)
        result = self.result('D3:gate_wait')
        self.assertEqual(result['after']['n'], 3)
        self.assertTrue(any('a4' in g for g in result['gaps']), result['gaps'])

    def test_d2_needs_15_plan_tasks_from_three_epics(self):
        def plan(name, started, merged, count, work):
            record = epic(name, started, merged)
            record['by_task'] = {'%s-%d' % (name, n): {'kind': 'plan', 'work': work * H, 'attempts': 1}
                                 for n in range(count)}
            return record
        store_records(self.state, plan('b1', 1, 2, 5, 2.0), plan('b2', 2, 3, 5, 2.0), plan('b3', 3, 4, 5, 2.0),
                      plan('a1', 11, 12, 5, 1.0), plan('a2', 12, 13, 5, 1.0), plan('a3', 13, 14, 5, 1.0))
        result = self.result('D2:plan_split')
        self.assertEqual((result['unit'], result['after']['n'], result['after']['n_epics']), ('h', 15, 3))
        self.assertEqual(result['diff'], {'value': -1.0, 'pct': -50.0})
        self.assertIsNone(result['message'])

    def test_14_tasks_are_not_enough(self):
        def plan(name, started, merged, count):
            record = epic(name, started, merged)
            record['by_task'] = {'%s-%d' % (name, n): {'kind': 'plan', 'work': H, 'attempts': 1} for n in range(count)}
            return record
        store_records(self.state, plan('b1', 1, 2, 5), plan('b2', 2, 3, 5), plan('b3', 3, 4, 5),
                      plan('a1', 11, 12, 5), plan('a2', 12, 13, 5), plan('a3', 13, 14, 4))
        result = self.result('D2:plan_split')
        self.assertEqual((result['after']['n'], result['message'], result['diff']), (14, 'za mało danych', None))

    def test_15_tasks_from_two_epics_are_not_enough(self):
        def plan(name, started, merged, count):
            record = epic(name, started, merged)
            record['by_task'] = {'%s-%d' % (name, n): {'kind': 'plan', 'work': H, 'attempts': 1} for n in range(count)}
            return record
        store_records(self.state, plan('b1', 1, 2, 8), plan('b2', 2, 3, 8), plan('a1', 11, 12, 8), plan('a2', 12, 13, 8))
        result = self.result('D2:plan_split')
        self.assertEqual((result['after']['n'], result['after']['n_epics'], result['message']), (16, 2, 'za mało danych'))

    def test_only_plan_tasks_are_measured_by_d2(self):
        record = epic('a1', 11, 12)
        record['by_task'] = {'P-1': {'kind': 'plan', 'work': H, 'attempts': 1}, 'R-1': {'kind': 'repair', 'work': 9 * H,
                                                                                         'attempts': 1}}
        store_records(self.state, record)
        self.assertEqual(self.result('D2:plan_split')['after']['n'], 1)

    def test_no_epics_after_gives_not_enough_data(self):
        self.gate_epics([4, 5, 6], [])
        result = self.result('D3:gate_wait')
        self.assertEqual((result['message'], result['diff'], result['after']['n']), ('za mało danych', None, 0))
        self.assertIsNone(result['after']['median'])


class D4Test(EffectEnv):
    def share_epics(self, key_field, before, after):
        """Epics of 10 h (to_done) whose waiting category has the given hours."""
        def make(name, started, merged, hours):
            record = epic(name, started, merged, review=0.0)
            record['breakdown'][key_field] = hours * H
            return record
        records = [make('b%d' % i, i, i + 1, v) for i, v in enumerate(before, 1)]
        records += [make('a%d' % i, 10 + i, 12 + i, v) for i, v in enumerate(after, 1)]
        store_records(self.state, *records)

    def test_human_wait_share_of_to_done_in_pp(self):
        self.share_epics('human_wait', [4, 5, 6], [1, 2, 3])
        result = self.result('D4:human_wait')
        self.assertEqual(result['unit'], 'pp')
        self.assertEqual((result['before']['median'], result['after']['median']), (50.0, 20.0))
        self.assertEqual(result['diff'], {'value': -30.0, 'pct': -60.0})

    def test_limit_wait_share_of_to_done(self):
        self.share_epics('limit_wait', [2, 2, 2], [1, 1, 1])
        self.assertEqual(self.result('D4:limit_wait')['after']['median'], 10.0)

    def test_ci_share_is_ci_wait_plus_ci_watch_of_work(self):
        records = []
        for i, (wait, watch) in enumerate([(1, 1), (1, 1), (1, 1), (1, 0), (0, 0.5), (0, 0)], 1):
            record = epic('e%d' % i, i if i <= 3 else 10 + i, i + 1 if i <= 3 else 12 + i)
            record['breakdown']['ci_wait'] = wait * H
            record['in_attempts']['categories']['ci_watch']['seconds'] = watch * H
            records.append(record)
        store_records(self.state, *records)
        result = self.result('D4:ci')
        self.assertEqual((result['before']['median'], result['after']['median']), (40.0, 10.0))     # of 5 h of work

    def test_review_share_is_review_of_to_merge(self):
        records = [epic('b%d' % i, i, i + 1, review=10 * H) for i in range(1, 4)]
        records += [epic('a%d' % i, 10 + i, 12 + i, review=2.5 * H) for i in range(1, 4)]
        store_records(self.state, *records)
        result = self.result('D4:review')
        self.assertEqual((result['before']['median'], result['after']['median']), (50.0, 20.0))


class D1Test(EffectEnv):
    def role_epic(self, name, started, merged, plan_value, repair_value=0.5, role='code-reviewer', estimated=False,
                  repair_estimated=False):
        record = epic(name, started, merged)
        record['by_task'] = {'P-%d' % n: {'kind': 'plan', 'work': H, 'attempts': 1} for n in range(5)}
        record['by_task']['R-1'] = {'kind': 'repair', 'work': H, 'attempts': 1}
        cell = lambda usd: {'usd': usd, 'estimated': False, 'tokens': {}, 'estimated_tokens': []}
        record['value']['by_task'] = {t: cell(3.0) for t in record['by_task']}
        by_task_role = {'P-%d' % n: {'delivery': cell(2.0), role: dict(cell(plan_value), estimated=estimated)}
                        for n in range(3)}
        by_task_role['P-3'] = {'delivery': cell(3.0)}                       # the role did not act in two tasks: 0
        by_task_role['P-4'] = {'delivery': cell(3.0)}
        by_task_role['R-1'] = {'delivery': cell(2.0), role: dict(cell(repair_value), estimated=repair_estimated)}
        record['value']['by_task_role'] = by_task_role
        return record

    def test_the_level_is_the_kind_with_the_largest_value_of_the_role_and_a_task_without_the_role_counts_as_zero(self):
        store_records(self.state, *[self.role_epic('b%d' % i, i, i + 1, 1.0) for i in range(1, 4)],
                      *[self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5) for i in range(1, 4)])
        result = self.result('D1:code-reviewer')
        self.assertEqual((result['unit'], result['level_kind']), ('USD', 'plan'))
        self.assertEqual((result['after']['n'], result['after']['n_epics']), (15, 3))      # 5 plan tasks in 3 epics
        self.assertEqual((result['before']['median'], result['after']['median']), (1.0, 0.5))
        self.assertEqual(result['diff'], {'value': -0.5, 'pct': -50.0})
        self.assertEqual(result['after']['p25'], 0.0)                                      # P-4 has 0

    def test_an_estimated_value_of_the_role_marks_the_windows_and_the_result(self):
        store_records(self.state, *[self.role_epic('b%d' % i, i, i + 1, 1.0, estimated=True) for i in range(1, 4)],
                      *[self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5, estimated=True) for i in range(1, 4)])
        result = self.result('D1:code-reviewer')
        self.assertIs(result['before']['estimated'], True)
        self.assertIs(result['after']['estimated'], True)
        self.assertIs(result['estimated'], True)

    def test_exact_values_of_the_role_are_not_marked(self):
        store_records(self.state, *[self.role_epic('b%d' % i, i, i + 1, 1.0) for i in range(1, 4)],
                      *[self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5) for i in range(1, 4)])
        result = self.result('D1:code-reviewer')
        self.assertIs(result['before']['estimated'], False)
        self.assertIs(result['after']['estimated'], False)
        self.assertIs(result['estimated'], False)

    def test_one_estimated_window_marks_the_result_and_only_that_window(self):
        store_records(self.state, *[self.role_epic('b%d' % i, i, i + 1, 1.0) for i in range(1, 4)],
                      *[self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5, estimated=(i == 2)) for i in range(1, 4)])
        result = self.result('D1:code-reviewer')
        self.assertIs(result['before']['estimated'], False)
        self.assertIs(result['after']['estimated'], True)
        self.assertIs(result['estimated'], True)

    def test_an_estimated_cell_outside_the_level_kind_does_not_mark_the_window(self):
        store_records(self.state, *[self.role_epic('b%d' % i, i, i + 1, 1.0) for i in range(1, 4)],
                      *[self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5, repair_estimated=True) for i in range(1, 4)])
        result = self.result('D1:code-reviewer')
        self.assertEqual(result['level_kind'], 'plan')
        self.assertIs(result['estimated'], False)

    def test_an_incomplete_cell_of_the_role_counts_as_estimated(self):
        record = self.role_epic('a1', 11, 12, 0.5)
        record['value']['by_task_role']['P-0']['code-reviewer']['incomplete'] = True
        store_records(self.state, record)
        self.assertIs(self.result('D1:code-reviewer')['after']['estimated'], True)

    def test_measures_other_than_d1_are_exact(self):
        records = [epic('b%d' % i, i, i + 1) for i in range(1, 4)] + [epic('a%d' % i, 10 + i, 12 + i) for i in range(1, 4)]
        store_records(self.state, *records)
        result = self.result('D3:gate_wait')
        self.assertEqual((result['before']['estimated'], result['after']['estimated'], result['estimated']),
                         (False, False, False))

    def test_records_without_value_by_task_role_are_left_out_with_a_gap(self):
        old = self.role_epic('a1', 11, 12, 0.5)
        del old['value']['by_task_role']
        store_records(self.state, old, *[self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5) for i in (2, 3, 4)])
        result = self.result('D1:code-reviewer')
        self.assertEqual(result['after']['epics'], ['a2', 'a3', 'a4'])
        self.assertTrue(any('a1' in g and 'by_task_role' in g for g in result['gaps']), result['gaps'])

    def test_a_task_with_an_unknown_value_is_left_out(self):
        record = self.role_epic('a1', 11, 12, 0.5)
        record['value']['by_task']['P-0'] = {'usd': None, 'estimated': True, 'tokens': {}, 'estimated_tokens': []}
        store_records(self.state, record)
        self.assertEqual(self.result('D1:code-reviewer')['after']['n'], 4)

    def test_a_task_in_which_the_role_acted_with_an_unknown_value_is_left_out_not_counted_as_zero(self):
        record = self.role_epic('a1', 11, 12, 0.5)
        record['value']['by_task_role']['P-0']['code-reviewer'] = {'usd': None, 'estimated': True, 'tokens': {},
                                                                   'estimated_tokens': []}
        store_records(self.state, record)
        after = self.result('D1:code-reviewer')['after']
        self.assertEqual(after['n'], 4)                  # P-0 is not a sample; P-3 and P-4 (no entry) still give 0
        self.assertEqual(after['median'], 0.25)          # 0.5, 0.5, 0, 0: the unknown value is not a fifth sample

    def test_a_window_with_a_task_left_out_for_an_unknown_role_value_is_estimated_not_exact(self):
        def unknown_role(record):
            for n in range(3):
                record['value']['by_task_role']['P-%d' % n]['code-reviewer'] = {
                    'usd': None, 'estimated': True, 'tokens': {}, 'estimated_tokens': []}
            return record
        store_records(self.state, *[self.role_epic('b%d' % i, i, i + 1, 1.0) for i in range(1, 9)],
                      *[unknown_role(self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5)) for i in range(1, 9)])
        result = self.result('D1:code-reviewer')
        self.assertGreaterEqual(result['after']['n'], 15)          # enough tasks and epics for a conclusion
        self.assertGreaterEqual(result['after']['n_epics'], 3)
        self.assertIsNotNone(result['diff'])
        self.assertIs(result['before']['estimated'], False)
        self.assertIs(result['after']['estimated'], True)
        self.assertIs(result['estimated'], True)

    def test_an_epic_left_out_entirely_for_an_unknown_role_value_marks_the_window_estimated_with_a_gap(self):
        def unknown_role_everywhere(record):
            for n in range(5):
                record['value']['by_task_role']['P-%d' % n]['code-reviewer'] = {
                    'usd': None, 'estimated': True, 'tokens': {}, 'estimated_tokens': []}
            return record
        store_records(self.state, *[self.role_epic('b%d' % i, i, i + 1, 1.0) for i in range(1, 9)],
                      *[self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5) for i in range(1, 6)],
                      *[unknown_role_everywhere(self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5)) for i in (6, 7, 8)])
        result = self.result('D1:code-reviewer')
        self.assertEqual(result['after']['epics'], ['a1', 'a2', 'a3', 'a4', 'a5'])     # a6-a8 have no sample at all
        self.assertIsNotNone(result['diff'])
        self.assertIs(result['before']['estimated'], False)
        self.assertIs(result['after']['estimated'], True)
        self.assertIs(result['estimated'], True)
        for epic_name in ('a6', 'a7', 'a8'):
            self.assertTrue(any(epic_name in g and 'okno po' in g for g in result['gaps']), result['gaps'])

    def test_a_role_that_never_acted_has_no_level_and_not_enough_data(self):
        store_records(self.state, *[self.role_epic('a%d' % i, 10 + i, 12 + i, 0.5) for i in range(1, 4)])
        result = self.result('D1:qa-adversarial')
        self.assertEqual((result['message'], result['diff']), ('za mało danych', None))
        self.assertIsNone(result['level_kind'])


class ContextTest(EffectEnv):
    def test_epic_time_is_the_median_of_three_epics_before_and_three_after_per_plan_task(self):
        records = [epic('b%d' % i, i, i + 1, to_done=v * H, tasks=10) for i, v in enumerate([50, 30, 20, 10, 14], 1)]
        records += [epic('a%d' % i, 10 + i, 12 + i, to_done=v * H, tasks=5) for i, v in enumerate([5, 10, 15, 100], 1)]
        store_records(self.state, *records)
        time = self.result('D3:gate_wait')['epic_time']
        self.assertEqual(time['unit'], 'h na zadanie planu')
        self.assertEqual(time['before']['epics'], ['b3', 'b4', 'b5'])          # the closest three before AT
        self.assertEqual(time['before']['median'], 1.4)                         # 2,0 / 1,0 / 1,4 h per task
        self.assertEqual(time['after']['epics'], ['a1', 'a2', 'a3'])           # the first three after AT
        self.assertEqual(time['after']['median'], 2.0)                          # 1,0 / 2,0 / 3,0 h per task

    def test_the_epic_time_is_there_also_below_the_threshold(self):
        self.gate_epics_small()
        result = self.result('D3:gate_wait')
        self.assertEqual(result['message'], 'za mało danych')
        self.assertIsNotNone(result['epic_time']['after']['median'])

    def gate_epics_small(self):
        store_records(self.state, epic('b1', 1, 2), epic('a1', 11, 12))

    def test_version_changes_between_the_windows_are_listed(self):
        records = [epic('b%d' % i, i, i + 1, cli=('2.1.300',), models=('claude-opus-5-5',)) for i in range(1, 4)]
        records += [epic('a%d' % i, 10 + i, 12 + i, cli=('2.1.311',), models=('claude-opus-5-5', 'claude-sonnet-5-5'))
                    for i in range(1, 4)]
        store_records(self.state, *records)
        versions = self.result('D3:gate_wait')['versions']
        self.assertEqual(versions['cli'], {'added': ['2.1.311'], 'removed': ['2.1.300']})
        self.assertEqual(versions['models'], {'added': ['claude-sonnet-5-5'], 'removed': []})

    def test_no_change_gives_empty_lists(self):
        records = [epic('b%d' % i, i, i + 1) for i in range(1, 4)] + [epic('a%d' % i, 10 + i, 12 + i) for i in range(1, 4)]
        store_records(self.state, *records)
        versions = self.result('D3:gate_wait')['versions']
        self.assertEqual(versions, {'cli': {'added': [], 'removed': []}, 'models': {'added': [], 'removed': []}})

    def test_a_window_without_versions_is_a_gap_not_a_change(self):
        records = [epic('b%d' % i, i, i + 1, cli=None) for i in range(1, 4)]
        records += [epic('a%d' % i, 10 + i, 12 + i) for i in range(1, 4)]
        store_records(self.state, *records)
        result = self.result('D3:gate_wait')
        self.assertEqual(result['versions']['cli'], {'added': [], 'removed': []})
        self.assertTrue(any('versions' in g for g in result['gaps']), result['gaps'])

    def test_the_command_writes_nothing(self):
        import os
        self.gate_epics_small()
        self.effect('D3:gate_wait')
        self.assertEqual(sorted(os.listdir(self.state)), ['epics'])


class ErrorPathTest(EffectEnv):
    def setUp(self):
        super().setUp()
        store_records(self.state, epic('b1', 1, 2), epic('a1', 11, 12))

    def test_a_bad_key_ends_with_code_2_naming_the_key(self):
        for bad in ('D5:x', 'D4:nothing', 'D2:T-1', 'D3:other', 'D1:', 'D1', 'x', '', 'D1:bad role', 'd4:ci', 'D4:ci:x'):
            with self.subTest(key=bad):
                code, out, err = self.effect(bad)
                self.assertEqual((code, out), (2, ''))
                self.assertIn('klucz', err)

    def test_a_bad_at_ends_with_code_2(self):
        for bad in ('yesterday', '2026-11-10', '2026-11-10T00:00:00', '', '10.11.2026'):
            with self.subTest(at=bad):
                code, out, err = self.effect('D3:gate_wait', at=bad)
                self.assertEqual((code, out), (2, ''))
                self.assertIn('--at', err)

    def test_a_missing_at_ends_with_code_2(self):
        code, out, err = self.effect('D3:gate_wait', at=None)
        self.assertEqual((code, out), (2, ''))

    def test_at_with_an_offset_is_accepted(self):
        code, out, err = self.effect('D3:gate_wait', at='2026-11-10T02:00:00+02:00')
        self.assertEqual(code, 0, err)

    def test_no_records_ends_with_code_2(self):
        import shutil
        shutil.rmtree(self.state)
        code, out, err = self.effect('D3:gate_wait')
        self.assertEqual((code, out), (2, ''))
        self.assertIn('rekord', err)

    def test_a_bad_config_ends_with_code_2(self):
        import os
        path = os.path.join(self.root, 'config.json')
        with open(path, 'w') as f:
            f.write('{"decisions": 1}')
        code, out, err = self.effect('D3:gate_wait', '--config', path)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('config.json', err)


if __name__ == '__main__':
    unittest.main()

"""Tests of `metrics.py trend` (spec: Historia i trend): per plan task columns, medians, the position of an epic, the
recalculation of open regression windows and the annotation of a change of the CLI version or models."""
import json
import os
import unittest

import metrics
import testkit
from testkit import Env, FakeGh, fake_record, merged_pr, run_cli, store_records

COLUMNS = ['to_done', 'work', 'human_wait', 'review', 'gate_wait', 'repeat_runs', 'value', 'pp', 'failed', 'repair',
           'interventions', 'regressions']


class TrendEnv(Env):
    def trend(self, *extra, runner=None, now=1800000000.0):
        return run_cli(['trend', '--state', self.state, '--night', self.night] + list(extra), runner, now=now)

    def table(self, *extra, **kwargs):
        code, out, err = self.trend('--json', *extra, **kwargs)
        self.assertIn(code, (0, 3), err)
        return json.loads(out)

    def row(self, table, epic):
        return next(r for r in table['epics'] if r['epic'] == epic)


class ColumnsTest(TrendEnv):
    def test_values_are_per_plan_task_with_times_in_hours(self):
        store_records(self.state, fake_record('a', tasks=10, to_done=36000, work=18000, human_wait=3600, review=1800,
                                              gate_wait=7200, repeat=600, value=10, weekly=4, share=0.5, failed=2,
                                              repair=1, interventions=3, regressions=2))
        table = self.table('--no-gh')
        self.assertEqual(table['columns'], COLUMNS)
        self.assertEqual(self.row(table, 'a')['values'],
                         {'to_done': 1.0, 'work': 0.5, 'human_wait': 0.1, 'review': 0.05, 'gate_wait': 0.2,
                          'repeat_runs': 0.0167, 'value': 1.0, 'pp': 0.2, 'failed': 0.2, 'repair': 0.1,
                          'interventions': 0.3, 'regressions': 0.2})

    def test_null_sources_give_null_columns_never_zero(self):
        store_records(self.state, fake_record('a', to_done=None, human_wait=None, review=None, gate_wait=None,
                                              repeat=None, value=None, weekly=None, regressions=None))
        values = self.row(self.table('--no-gh'), 'a')['values']
        for column in ('to_done', 'work', 'human_wait', 'review', 'gate_wait', 'repeat_runs', 'value', 'pp',
                       'regressions'):
            self.assertIsNone(values[column], column)
        self.assertIsNotNone(values['failed'])

    def test_incomplete_value_gives_a_null_column(self):
        incomplete = fake_record('a', value=10)
        incomplete['value']['total']['incomplete'] = True
        self.assertIsNone(self.row_values(incomplete)['value'])
        self.assertEqual(metrics.trend_values({'tasks': 10, 'value': {'total': {'usd': 10.0, 'incomplete': True}}},
                                              metrics.load_config(os.path.join(testkit.HERE, 'config.json')))['value'],
                         None)
        self.assertEqual(self.row_values(fake_record('b', value=10))['value'], 1.0)      # a complete total stays

    def row_values(self, record):
        store_records(self.state, record)
        return self.row(self.table('--no-gh'), record['epic'])['values']

    def test_share_missing_gives_null_pp(self):
        store_records(self.state, fake_record('a', share=None))
        self.assertIsNone(self.row(self.table('--no-gh'), 'a')['values']['pp'])

    def test_epic_without_plan_tasks_has_only_null_columns(self):
        store_records(self.state, fake_record('a', tasks=0), fake_record('b', tasks=None, merged='2026-11-11T10:00:00Z'))
        table = self.table('--no-gh')
        for epic in ('a', 'b'):
            self.assertEqual(set(self.row(table, epic)['values'].values()), {None})


class SelectionTest(TrendEnv):
    def records(self, count):
        return [fake_record('e%02d' % i, merged='2026-11-%02dT10:00:00Z' % (i + 1)) for i in range(count)]

    def test_epics_are_ordered_by_merge_time_and_only_merged_ones_are_listed(self):
        store_records(self.state, fake_record('late', merged='2026-11-12T10:00:00Z'),
                      fake_record('early', merged='2026-11-02T10:00:00Z'), fake_record('open', merged=None))
        self.assertEqual([r['epic'] for r in self.table('--no-gh')['epics']], ['early', 'late'])

    def test_last_limits_the_table_and_defaults_to_trend_last_of_the_config(self):
        store_records(self.state, *self.records(12))
        self.assertEqual(len(self.table('--no-gh')['epics']), 10)
        table = self.table('--no-gh', '--last', '3')
        self.assertEqual([r['epic'] for r in table['epics']], ['e09', 'e10', 'e11'])

    def test_last_must_be_a_positive_number(self):
        store_records(self.state, *self.records(2))
        for bad in ('0', '-1', 'x'):
            code, out, err = self.trend('--no-gh', '--last', bad)
            self.assertEqual(code, 2, bad)
            self.assertEqual(out, '')

    def test_no_stored_records_is_an_error_with_a_hint(self):
        code, out, err = self.trend('--no-gh')
        self.assertEqual(code, 2)
        self.assertIn('backfill', err)
        self.assertEqual(out, '')


class MedianTest(TrendEnv):
    def test_median_skips_nulls(self):
        store_records(self.state,
                      fake_record('a', merged='2026-11-01T10:00:00Z', human_wait=3600),
                      fake_record('b', merged='2026-11-02T10:00:00Z', human_wait=None),
                      fake_record('c', merged='2026-11-03T10:00:00Z', human_wait=7200),
                      fake_record('d', merged='2026-11-04T10:00:00Z', human_wait=36000))
        median = self.table('--no-gh')['median']
        self.assertEqual(median['human_wait'], 0.2)             # 0.1, 0.2, 1.0
        self.assertEqual(median['to_done'], 1.0)

    def test_median_skips_an_epic_with_an_incomplete_value(self):
        partial = fake_record('b', merged='2026-11-02T10:00:00Z', value=1)       # 0.1 per task: would pull the median down
        partial['value']['total']['incomplete'] = True
        store_records(self.state, fake_record('a', merged='2026-11-01T10:00:00Z', value=10), partial,
                      fake_record('c', merged='2026-11-03T10:00:00Z', value=30))
        table = self.table('--no-gh')
        self.assertEqual(table['median']['value'], 2.0)                       # 1.0 and 3.0, not 0.1, 1.0, 3.0
        self.assertIsNone(self.row(table, 'b')['values']['value'])
        self.assertEqual(table['position']['columns']['value']['diff'], 1.0)  # c against the median

    def test_median_of_an_all_null_column_is_null(self):
        store_records(self.state, fake_record('a', gate_wait=None), fake_record('b', gate_wait=None,
                                                                             merged='2026-11-11T10:00:00Z'))
        self.assertIsNone(self.table('--no-gh')['median']['gate_wait'])

    def test_median_of_an_even_count_is_the_mean_of_the_middle_pair(self):
        store_records(self.state, fake_record('a', merged='2026-11-01T10:00:00Z', work=18000),
                      fake_record('b', merged='2026-11-02T10:00:00Z', work=36000))
        self.assertEqual(self.table('--no-gh')['median']['work'], 0.75)


class PositionTest(TrendEnv):
    def setUp(self):
        super().setUp()
        store_records(self.state,
                      fake_record('a', merged='2026-11-01T10:00:00Z', to_done=36000, human_wait=None),
                      fake_record('b', merged='2026-11-02T10:00:00Z', to_done=72000, human_wait=3600),
                      fake_record('c', merged='2026-11-03T10:00:00Z', to_done=108000, human_wait=7200))

    def test_position_of_the_latest_epic_by_default(self):
        position = self.table('--no-gh')['position']
        self.assertEqual(position['epic'], 'c')
        self.assertEqual(position['columns']['to_done'], {'value': 3.0, 'median': 2.0, 'diff': 1.0, 'diff_pct': 50.0})

    def test_position_of_the_given_epic(self):
        position = self.table('--no-gh', '--epic', 'a')['position']
        self.assertEqual(position['epic'], 'a')
        self.assertEqual(position['columns']['to_done']['diff'], -1.0)
        self.assertEqual(position['columns']['to_done']['diff_pct'], -50.0)

    def test_position_of_a_null_value_has_no_diff(self):
        entry = self.table('--no-gh', '--epic', 'a')['position']['columns']['human_wait']
        self.assertEqual(entry, {'value': None, 'median': 0.15, 'diff': None, 'diff_pct': None})

    def test_epic_outside_the_last_n_is_still_positioned_against_the_shown_median(self):
        table = self.table('--no-gh', '--last', '2', '--epic', 'a')
        self.assertEqual([r['epic'] for r in table['epics']], ['b', 'c'])
        self.assertEqual(table['position']['columns']['to_done']['median'], 2.5)

    def test_unknown_epic_is_an_error(self):
        code, out, err = self.trend('--no-gh', '--epic', 'nope')
        self.assertEqual(code, 2)
        self.assertIn('nope', err)

    def test_text_table_lists_the_epics_the_median_and_the_position(self):
        code, out, err = self.trend('--no-gh')
        self.assertEqual(code, 0, err)
        for text in ('a', 'b', 'c', 'mediana', 'c wobec mediany', '+50%', '3,0'):
            self.assertIn(text, out)


class AnnotationTest(TrendEnv):
    def test_change_of_the_cli_version_is_annotated(self):
        store_records(self.state, fake_record('a', merged='2026-11-01T10:00:00Z', cli=('2.1.311',)),
                      fake_record('b', merged='2026-11-02T10:00:00Z', cli=('2.1.311',)),
                      fake_record('c', merged='2026-11-03T10:00:00Z', cli=('2.1.400',)))
        table = self.table('--no-gh')
        self.assertEqual(self.row(table, 'a')['annotations'], [])
        self.assertEqual(self.row(table, 'b')['annotations'], [])
        self.assertEqual(self.row(table, 'c')['annotations'], ['wersja CLI: 2.1.311 → 2.1.400'])

    def test_change_of_the_model_set_is_annotated(self):
        store_records(self.state, fake_record('a', merged='2026-11-01T10:00:00Z', models=('claude-opus-5-5',)),
                      fake_record('b', merged='2026-11-02T10:00:00Z',
                                  models=('claude-opus-5-5', 'claude-sonnet-5-5')))
        self.assertEqual(self.row(self.table('--no-gh'), 'b')['annotations'],
                         ['modele: + claude-sonnet-5-5'])

    def test_removed_model_and_a_version_change_together(self):
        store_records(self.state, fake_record('a', merged='2026-11-01T10:00:00Z', cli=('1',), models=('m1', 'm2')),
                      fake_record('b', merged='2026-11-02T10:00:00Z', cli=('2',), models=('m1',)))
        self.assertEqual(self.row(self.table('--no-gh'), 'b')['annotations'],
                         ['wersja CLI: 1 → 2', 'modele: − m2'])

    def test_previous_epic_is_taken_from_the_whole_history_not_only_the_shown_rows(self):
        store_records(self.state, fake_record('a', merged='2026-11-01T10:00:00Z', cli=('1',)),
                      fake_record('b', merged='2026-11-02T10:00:00Z', cli=('2',)))
        table = self.table('--no-gh', '--last', '1')
        self.assertEqual(self.row(table, 'b')['annotations'], ['wersja CLI: 1 → 2'])

    def test_unknown_versions_are_not_compared(self):
        store_records(self.state, fake_record('a', merged='2026-11-01T10:00:00Z', cli=None),
                      fake_record('b', merged='2026-11-02T10:00:00Z', cli=('2',)))
        self.assertEqual(self.row(self.table('--no-gh'), 'b')['annotations'], [])

    def test_the_annotation_is_in_the_text_table_and_does_not_change_the_numbers(self):
        store_records(self.state, fake_record('a', merged='2026-11-01T10:00:00Z', cli=('1',)),
                      fake_record('b', merged='2026-11-02T10:00:00Z', cli=('2',)))
        code, out, err = self.trend('--no-gh')
        self.assertIn('wersja CLI: 1 → 2', out)
        table = self.table('--no-gh')
        self.assertEqual(self.row(table, 'a')['values'], self.row(table, 'b')['values'])


class RegressionWindowTest(TrendEnv):
    SHA = 'a' * 40

    def setUp(self):
        super().setUp()
        self.now = 1794700000.0       # 2026-11-14: the window of a merge on 2026-11-10 is open

    def stored(self, **kwargs):
        record = fake_record('a', merged='2026-11-10T10:00:00Z', final_pr=7, regressions=0, window_open=True, **kwargs)
        record['regressions']['merge_commit'] = self.SHA
        store_records(self.state, record)
        return record

    def gh(self, **kwargs):
        # a finished green CI run of the merge commit unless the test says otherwise: without one the value is unknown
        kwargs.setdefault('runs', {self.SHA: [{'conclusion': 'success', 'status': 'completed', 'event': 'push'}]})
        return FakeGh(prs={7: merged_pr('2026-11-10T10:00:00Z', sha=self.SHA)}, **kwargs)

    def stored_record(self):
        with open(os.path.join(self.state, 'epics', 'a.json'), encoding='utf-8') as f:
            return json.load(f)

    def test_open_window_is_recomputed_and_stored(self):
        self.stored()
        gh = self.gh(runs={self.SHA: [{'conclusion': 'failure', 'event': 'push'}]})
        table = self.table(runner=gh, now=self.now)
        self.assertEqual(self.row(table, 'a')['values']['regressions'], 0.1)
        self.assertEqual(self.stored_record()['regressions']['ci_red'], 1)
        self.assertEqual(table['recomputed'], ['a'])
        self.assertTrue(self.stored_record()['regressions']['open'])

    def test_a_ci_run_in_progress_leaves_the_window_open_and_the_next_trend_settles_it(self):
        self.stored()
        late = self.now + 30 * 86400
        self.table(runner=self.gh(runs={self.SHA: [{'conclusion': '', 'status': 'in_progress', 'event': 'push'}]}),
                   now=late)
        stored = self.stored_record()['regressions']
        self.assertEqual((stored['ci_red'], stored['open'], stored['complete']), (None, True, False))
        table = self.table(runner=self.gh(runs={self.SHA: [{'conclusion': 'failure', 'status': 'completed',
                                                           'event': 'push'}]}), now=late)
        stored = self.stored_record()['regressions']
        self.assertEqual((stored['ci_red'], stored['open'], stored['complete']), (1, False, True))
        self.assertEqual(table['recomputed'], ['a'])

    def test_a_closed_window_is_not_recomputed(self):
        record = self.stored()
        record['regressions'].update(open=False, complete=True)
        store_records(self.state, record)
        gh = self.gh()
        table = self.table(runner=gh, now=self.now)
        self.assertEqual(gh.calls, [])
        self.assertEqual(table['recomputed'], [])

    def test_a_window_that_ended_since_the_last_check_is_recomputed_and_closed(self):
        self.stored()
        table = self.table(runner=self.gh(), now=self.now + 30 * 86400)
        self.assertFalse(self.stored_record()['regressions']['open'])
        self.assertEqual(table['recomputed'], ['a'])

    def test_after_the_end_of_the_window_a_gh_error_leaves_it_open_and_the_next_trend_closes_it(self):
        self.stored()
        after = self.now + 30 * 86400
        first = self.table(runner=self.gh(fail_on=[('issue', 'list')]), now=after)
        self.assertEqual(first['recomputed'], ['a'])
        record = self.stored_record()
        self.assertTrue(record['regressions']['open'])
        self.assertFalse(record['regressions']['complete'])
        gh = self.gh()
        again = self.table(runner=gh, now=after)
        self.assertEqual(again['recomputed'], ['a'])
        record = self.stored_record()
        self.assertFalse(record['regressions']['open'])
        self.assertTrue(record['regressions']['complete'])
        self.assertEqual(self.table(runner=self.gh(), now=after)['recomputed'], [])

    def test_a_failed_issue_list_leaves_the_record_to_be_recomputed(self):
        self.stored()
        after = self.now + 30 * 86400
        self.table(runner=self.gh(fail_on=[('issue', 'list')]), now=after)
        self.assertEqual(self.table(runner=self.gh(), now=after)['recomputed'], ['a'])

    def test_a_closed_record_without_found_after_merge_is_recomputed(self):
        record = self.stored()
        record['regressions'].update(open=False, complete=True)
        record['found_after_merge'] = None
        store_records(self.state, record)
        self.assertEqual(self.table(runner=self.gh(), now=self.now)['recomputed'], ['a'])

    def test_a_record_without_the_complete_flag_is_recomputed_once(self):
        record = self.stored()
        record['regressions'].update(open=False)
        del record['regressions']['complete']
        store_records(self.state, record)
        self.assertEqual(self.table(runner=self.gh(), now=self.now + 30 * 86400)['recomputed'], ['a'])
        self.assertEqual(self.table(runner=self.gh(), now=self.now + 30 * 86400)['recomputed'], [])

    def test_a_record_without_regressions_is_computed_when_gh_is_available(self):
        store_records(self.state, fake_record('a', merged='2026-11-10T10:00:00Z', final_pr=7, regressions=None))
        self.table(runner=self.gh(), now=self.now)
        self.assertEqual(self.stored_record()['regressions']['count'], 0)

    def test_the_merge_commit_is_fetched_when_the_record_has_none(self):
        record = self.stored()
        record['regressions']['merge_commit'] = None
        store_records(self.state, record)
        gh = self.gh(runs={self.SHA: [{'conclusion': 'failure', 'event': 'push'}]})
        self.table(runner=gh, now=self.now)
        self.assertEqual(self.stored_record()['regressions']['ci_red'], 1)

    def test_a_stored_merge_commit_that_is_not_a_sha_is_never_given_to_gh(self):
        record = self.stored()
        record['regressions']['merge_commit'] = '--web'
        store_records(self.state, record)
        gh = self.gh()
        self.table(runner=gh, now=self.now)
        self.assertFalse([c for c in gh.calls if '--web' in c], gh.calls)
        self.assertEqual(self.stored_record()['regressions']['merge_commit'], 'a' * 40)

    def test_no_gh_does_not_call_gh_and_keeps_the_record(self):
        self.stored()
        gh = self.gh()
        code, out, err = self.trend('--no-gh', runner=gh, now=self.now)
        self.assertEqual(code, 0, err)
        self.assertEqual(gh.calls, [])

    def test_gh_error_prints_the_table_keeps_the_stored_values_and_exits_with_3(self):
        record = self.stored()
        record['regressions']['issues'] = [5]
        record['regressions']['count'] = 1
        store_records(self.state, record)
        code, out, err = self.trend(runner=FakeGh(fail='HTTP 502'), now=self.now)
        self.assertEqual(code, 3)
        self.assertIn('metrics:', err)
        self.assertIn('HTTP 502', err)
        self.assertIn('a', out)
        self.assertEqual(self.stored_record()['regressions']['issues'], [5])


def estimate(record, value=False, share=False):
    """The record with the value total and/or the subscription share marked as estimated."""
    if value:
        record['value']['total']['estimated'] = True
    if share:
        record['subscription']['share_estimated'] = True
    return record


class EstimatedColumnsTest(TrendEnv):
    """A column that comes from an estimated source is never shown like an exact one (spec: Dokładność)."""

    def setUp(self):
        super().setUp()
        self.config = metrics.load_config(os.path.join(testkit.HERE, 'config.json'))
        self.history = [fake_record(name, merged='2026-11-0%dT10:00:00Z' % number, value=10, share=1)
                        for number, name in enumerate('abc', 1)]

    def position_line(self, record, history=None):
        line, = metrics.position_topic(record, self.history if history is None else history, self.config, True)
        return line

    def test_position_marks_an_estimated_value_with_the_approximation_sign(self):
        record = estimate(fake_record('x', value=30), value=True)
        line = self.position_line(record)
        self.assertIn('value \u2248 3,00 (mediana 1,00, \u2248 +200%)', line)
        self.assertNotIn('pp \u2248', line)

    def test_position_marks_pp_of_an_estimated_share(self):
        line = self.position_line(estimate(fake_record('x', share=2), share=True))
        self.assertIn('pp \u2248 0,80 (mediana 0,40, \u2248 +100%)', line)
        self.assertNotIn('value \u2248', line)

    def test_position_marks_a_median_built_from_an_estimated_history_value(self):
        history = [estimate(h, value=True) if h['epic'] == 'b' else h for h in self.history]
        line = self.position_line(fake_record('x', value=30), history)
        self.assertIn('value 3,00 (mediana \u2248 1,00, \u2248 +200%)', line)

    def test_position_of_exact_values_has_no_approximation_sign(self):
        self.assertNotIn('\u2248', self.position_line(fake_record('x', value=30, share=2)))

    def test_an_unknown_value_is_not_marked_estimated(self):
        record = fake_record('x', value=10)
        record['value']['total'] = {'usd': None, 'estimated': True}
        self.assertEqual(metrics.trend_estimated(record, self.config), set())

    def test_trend_values_keep_the_numbers_and_the_estimated_columns_are_separate(self):
        record = estimate(fake_record('x', value=30, share=2), value=True, share=True)
        self.assertEqual(metrics.trend_values(record, self.config)['value'], 3.0)
        self.assertEqual(metrics.trend_estimated(record, self.config), {'value', 'pp'})
        self.assertEqual(metrics.trend_estimated(fake_record('y'), self.config), set())

    def store(self):
        store_records(self.state,
                      fake_record('a', merged='2026-11-01T10:00:00Z', value=10),
                      estimate(fake_record('b', merged='2026-11-02T10:00:00Z', value=20, share=2), value=True,
                               share=True),
                      fake_record('c', merged='2026-11-03T10:00:00Z', value=30))

    def test_json_lists_the_estimated_columns_of_the_rows_the_median_and_the_position(self):
        self.store()
        table = self.table('--no-gh')
        self.assertEqual([r['estimated'] for r in table['epics']], [[], ['value', 'pp'], []])
        self.assertEqual(table['median_estimated'], ['value', 'pp'])
        self.assertEqual(table['position']['estimated'], [])
        self.assertEqual(table['position']['median_estimated'], ['value', 'pp'])
        self.assertEqual(self.table('--no-gh', '--epic', 'b')['position']['estimated'], ['value', 'pp'])
        self.assertEqual(table['median']['value'], 2.0)         # the numbers are not changed

    def test_json_of_exact_records_has_empty_estimated_lists(self):
        store_records(self.state, fake_record('a'), fake_record('b', merged='2026-11-11T10:00:00Z'))
        table = self.table('--no-gh')
        self.assertEqual([r['estimated'] for r in table['epics']], [[], []])
        self.assertEqual((table['median_estimated'], table['position']['estimated'],
                          table['position']['median_estimated']), ([], [], []))

    def test_text_table_marks_estimated_cells_and_medians_and_keeps_the_columns_aligned(self):
        self.store()
        code, out, err = self.trend('--no-gh', '--epic', 'b')
        self.assertEqual(code, 0, err)
        lines = out.splitlines()
        row = next(l for l in lines if l.startswith('b '))
        self.assertIn('\u2248 2,00', row)
        self.assertIn('\u2248 0,80', row)
        self.assertNotIn('\u2248', next(l for l in lines if l.startswith('a ')))
        table = [l for l in lines[1:6]]      # the header, the three epics and the median
        self.assertEqual(len({len(l) for l in table}), 1, table)
        self.assertIn('  USD: \u2248 2,00 (mediana \u2248 2,00, \u2248 +0%)', lines)
        self.assertIn('  pp: \u2248 0,80 (mediana \u2248 0,40, \u2248 +100%)', lines)

    def test_text_table_of_exact_records_has_no_approximation_sign(self):
        store_records(self.state, fake_record('a'), fake_record('b', merged='2026-11-11T10:00:00Z'))
        self.assertNotIn('\u2248', self.trend('--no-gh')[1])


if __name__ == '__main__':
    unittest.main()

"""Tests of `trend`, `render` and the history for the two sources of a record, `ao` and `native` (spec: Trend i raport
dwóch źródeł; ash-v1 M6): one table with the source, `brak danych` instead of a zero, the medians, the position and the
bottlenecks per source, and the mark of the fields that are counted differently than in AO."""
import json
import os
import sys
import unittest

import metrics
import testkit
from testkit import Env, fake_record, run_cli, store_records

sys.path.insert(0, os.path.join(testkit.HERE, '..', '..', '.claude', 'skills', 'retro'))
import retro  # noqa: E402

H = 3600.0
DIFFERENT = 'liczone inaczej niż w AO'
MARK = '†'
APPROX = '≈'
NATIVE_DIFFERENT = ['work', 'human_wait', 'repeat_runs', 'value', 'pp', 'interventions']
# the columns of a native record that are counted as in AO (spec M6): the time to the ready PR, the time to the merge
# (and so the review between them) and the regressions
COMPARABLE = ['to_done', 'review', 'regressions']
NULL_NATIVE = {'limit_wait': None, 'ci_wait': None, 'host_pause': None, 'github_wait': None}


def native(epic, merged='2026-11-12T10:00:00Z', **over):
    """A record in the shape of a native one: what the native build has no source for is null (never zero), the value is
    an estimate, the breakdown is incomplete (a bool, not a list), the interventions are an upper bound."""
    record = fake_record(epic, merged=merged, tasks=8, to_done=16 * H, work=8 * H, human_wait=2 * H, review=1 * H,
                         gate_wait=None, repeat=0.4 * H, value=40.0, share=0.5, weekly=3, failed=0, repair=0,
                         interventions=4, regressions=0, source='native')
    record['value']['total']['estimated'] = True
    record['breakdown'].update(idle=6 * H, **NULL_NATIVE)
    record['breakdown_incomplete'] = True
    record['subscription']['share_estimated'] = True
    record['human'].update(interventions_estimated=True)
    record['retries'] = {'attempts': None, 'failed_by_outcome': {}, 'repair': None, 'fix': 2, 'sync_prs': None}
    record['integration'] = {'work_s': 2 * H, 'attempts': 3, 'rounds': 2}
    record['parallel'] = None
    record['waste'] = None
    record['by_task'] = {'SF-1': {'kind': None, 'work': 3 * H, 'attempts': 1}}
    record.update(over)
    return record


def ao_history(count=5):
    return [fake_record('ao%d' % i, merged='2026-10-%02dT10:00:00Z' % (i + 1), tasks=10, to_done=10 * H, work=5 * H,
                        human_wait=1 * H, review=0.5 * H, gate_wait=0.0, repeat=0.0, value=10.0)
            for i in range(count)]


class SourcesEnv(Env):
    def trend(self, *extra):
        return run_cli(['trend', '--state', self.state, '--night', self.night, '--no-gh'] + list(extra))

    def table(self, *extra):
        code, out, err = self.trend('--json', *extra)
        self.assertEqual(code, 0, err)
        return json.loads(out)

    def row(self, table, epic):
        return next(r for r in table['epics'] if r['epic'] == epic)

    def render(self, epic, fmt):
        code, out, err = run_cli(['render', epic, '--format', fmt, '--state', self.state])
        self.assertEqual(code, 0, err)
        return out


class OneTableTest(SourcesEnv):
    def setUp(self):
        super().setUp()
        store_records(self.state, *ao_history(3), native('nat'))

    def test_json_rows_have_the_source_and_the_keys_retro_reads_stay(self):
        table = self.table()
        self.assertEqual([(r['epic'], r['source']) for r in table['epics']],
                         [('ao0', 'ao'), ('ao1', 'ao'), ('ao2', 'ao'), ('nat', 'native')])
        for key in ('epics', 'position', 'median', 'median_estimated', 'columns'):
            self.assertIn(key, table)
        self.assertEqual(table['position']['epic'], 'nat')
        self.assertEqual(table['position']['source'], 'native')
        self.assertEqual(set(table['position']['columns']), set(table['columns']))

    def test_text_is_one_table_with_a_source_column_and_a_median_line_per_source(self):
        code, out, err = self.trend()
        self.assertEqual(code, 0, err)
        lines = out.splitlines()
        self.assertEqual(lines[1].split()[:2], ['epik', 'źródło'])
        rows = {line.split()[0]: line.split()[1] for line in lines[2:6]}
        self.assertEqual(rows, {'ao0': 'ao', 'ao1': 'ao', 'ao2': 'ao', 'nat': 'native'})
        self.assertEqual([line.split()[:2] for line in lines if line.startswith('mediana')],
                         [['mediana', 'ao'], ['mediana', 'native']])
        self.assertEqual(len({len(line) for line in lines[1:8]}), 1, lines[1:8])        # the columns stay aligned

    def test_a_column_without_data_of_a_native_record_is_brak_danych_never_zero(self):
        values = self.row(self.table(), 'nat')['values']
        for column in ('gate_wait', 'failed', 'repair'):
            self.assertIsNone(values[column], column)
        lines = self.trend()[1].splitlines()
        row = next(line for line in lines if line.startswith('nat '))
        self.assertEqual(row.count('brak danych'), 3, row)
        self.assertIn('brak danych', next(line for line in lines if line.strip().startswith('gate:')))

    def test_an_ao_only_table_differs_from_before_only_by_the_source(self):
        os.remove(os.path.join(self.state, 'epics', 'nat.json'))
        out = self.trend()[1]
        self.assertNotIn(MARK, out)
        self.assertNotIn('brak danych', out)
        self.assertNotIn('native', out)
        self.assertEqual(self.table()['position']['source'], 'ao')


class MediansPerSourceTest(SourcesEnv):
    def test_a_native_record_changes_no_ao_median_and_an_ao_record_no_native_one(self):
        store_records(self.state, *ao_history(3), native('n1', merged='2026-11-11T10:00:00Z'))
        before = self.table('--epic', 'ao2')
        huge = native('n2', merged='2026-11-12T10:00:00Z')
        huge['value']['total']['usd'] = 4000.0
        store_records(self.state, huge)
        after = self.table('--epic', 'ao2')
        self.assertEqual(before['sources']['ao'], after['sources']['ao'])
        self.assertEqual(before['position']['columns'], after['position']['columns'])
        self.assertEqual(after['median'], after['sources']['ao']['median'])
        self.assertEqual(after['sources']['ao']['median']['value'], 1.0)
        self.assertEqual(after['sources']['native']['median']['value'], (5.0 + 500.0) / 2)      # 40/8 and 4000/8
        self.assertEqual(after['sources']['native']['epics'], 2)
        self.assertEqual(before['sources']['native']['median']['value'], 5.0)

    def test_the_position_of_a_native_epic_is_against_the_native_median(self):
        store_records(self.state, *ao_history(3), native('n1', merged='2026-11-11T10:00:00Z'),
                      native('n2', merged='2026-11-12T10:00:00Z'))
        position = self.table()['position']
        self.assertEqual(position['source'], 'native')
        self.assertEqual(position['columns']['to_done']['median'], 2.0)       # 16 h / 8 tasks; the AO median is 1.0
        self.assertEqual(position['columns']['to_done']['diff'], 0.0)

    def test_a_source_without_shown_rows_has_empty_medians(self):
        store_records(self.state, *ao_history(3), native('n1'))
        table = self.table('--last', '1', '--epic', 'ao0')
        self.assertEqual([r['source'] for r in table['epics']], ['native'])
        self.assertEqual(table['position']['source'], 'ao')
        self.assertIsNone(table['position']['columns']['to_done']['median'])
        self.assertIsNone(table['median']['to_done'])


class HistoryAndBottlenecksTest(SourcesEnv):
    def test_history_of_a_record_is_only_the_records_of_its_source(self):
        records = {r['epic']: r for r in ao_history(3)}
        records['n1'] = native('n1', merged='2026-11-11T10:00:00Z')
        later = records['n2'] = native('n2', merged='2026-11-12T10:00:00Z')
        ao_later = records['ao9'] = fake_record('ao9', merged='2026-11-13T10:00:00Z')
        self.assertEqual([r['epic'] for r in metrics.history_of(later, records)], ['n1'])
        self.assertEqual(sorted(r['epic'] for r in metrics.history_of(ao_later, records)), ['ao0', 'ao1', 'ao2'])

    def test_a_record_without_the_source_field_is_an_ao_record(self):
        old = fake_record('old', merged='2026-10-01T10:00:00Z')
        del old['source']
        records = {'old': old, 'nat': native('nat')}
        self.assertEqual([r['epic'] for r in metrics.history_of(fake_record('x', merged='2026-11-20T10:00:00Z'),
                                                                 records)], ['old'])
        self.assertEqual([r['epic'] for r in metrics.history_of(native('y', merged='2026-11-20T10:00:00Z'), records)],
                         ['nat'])

    def test_the_ao_bottlenecks_are_the_same_with_and_without_native_records(self):
        slow = fake_record('slow', merged='2026-11-10T10:00:00Z', tasks=10, to_done=30 * H, work=5 * H,
                           human_wait=20 * H, review=0.5 * H, gate_wait=0.0, repeat=0.0)
        store_records(self.state, *ao_history(5), slow)
        alone = self.render('slow', 'report')
        self.assertIn('`human_wait`', alone)
        heavy = native('n1', merged='2026-11-01T10:00:00Z', tasks=10)
        heavy['breakdown'].update(work=0.0, human_wait=400 * H, idle=0.0)
        store_records(self.state, heavy, native('n2', merged='2026-11-02T10:00:00Z', tasks=10))
        self.assertEqual(self.render('slow', 'report'), alone)

    def test_a_native_epic_has_no_bottleneck_without_a_native_history(self):
        config = metrics.load_config(metrics.DEFAULT_CONFIG)
        gaps = metrics.Gaps()
        record = native('nat')
        record['breakdown'].update(human_wait=90 * H)
        records = {r['epic']: r for r in ao_history(6)}
        self.assertEqual(metrics.bottlenecks_of(record, metrics.history_of(record, records), config, gaps), [])
        self.assertTrue([g for g in gaps.render() if g.startswith('bottlenecks')])

    def test_a_native_median_comes_from_native_records_only(self):
        config = metrics.load_config(metrics.DEFAULT_CONFIG)
        records = {r['epic']: r for r in ao_history(6)}
        for i in range(5):
            records['n%d' % i] = native('n%d' % i, merged='2026-11-0%dT10:00:00Z' % (i + 1))
        record = native('nat', merged='2026-11-12T10:00:00Z')
        record['breakdown']['human_wait'] = 12 * H            # 1,5 h per task against the native 0,25 h
        items = metrics.bottlenecks_of(record, metrics.history_of(record, records), config, metrics.Gaps())
        item = next(i for i in items if i['name'] == 'human_wait')
        self.assertEqual(item['median_s'], 2 * H)

    def test_decide_uses_the_history_of_the_source_of_the_epic(self):
        config = metrics.load_config(metrics.DEFAULT_CONFIG)
        records = {r['epic']: r for r in ao_history(3)}
        records['nat'] = native('nat')
        seen = []
        original = metrics.history_of
        metrics.history_of = lambda record, every: seen.append(original(record, every)) or seen[-1]
        try:
            metrics.decide_epic(records['nat'], records, config)
        finally:
            metrics.history_of = original
        self.assertEqual(seen, [[]])


    def test_decide_skips_d2_for_a_native_epic_because_its_tasks_have_no_kind(self):
        config = metrics.load_config(metrics.DEFAULT_CONFIG)
        record = native('nat')
        result = metrics.decide_epic(record, {'nat': record}, config)
        skipped = [entry for entry in result['skipped'] if entry['key'].startswith('D2')]
        self.assertEqual(len(skipped), 1, result['skipped'])
        self.assertIn('tasks.kind', skipped[0]['reason'])
        self.assertFalse([c for c in result['candidates'] if c['key'].startswith('D2')])


class BreakdownFlagTest(unittest.TestCase):
    def test_a_boolean_incomplete_breakdown_of_a_native_record_makes_idle_an_upper_bound(self):
        record = native('nat')
        self.assertTrue(metrics.breakdown_inexact(record, 'idle'))
        self.assertFalse(metrics.breakdown_inexact(record, 'work'))
        self.assertFalse(metrics.breakdown_inexact(record, 'human_wait'))
        self.assertEqual(metrics.lower_bounds(record), set())

    def test_a_list_of_an_ao_record_is_read_as_before(self):
        record = fake_record('ao', breakdown_incomplete=['work'], breakdown_upper_bound=['human_wait'])
        self.assertTrue(metrics.breakdown_inexact(record, 'work'))
        self.assertTrue(metrics.breakdown_inexact(record, 'human_wait'))
        self.assertTrue(metrics.breakdown_inexact(record, 'idle'))


class DifferentFieldsTest(SourcesEnv):
    def setUp(self):
        super().setUp()
        store_records(self.state, *ao_history(3), native('nat'))

    def test_json_lists_the_native_columns_that_are_counted_differently(self):
        table = self.table()
        different = self.row(table, 'nat')['different']
        self.assertEqual(different, NATIVE_DIFFERENT)
        for column in COMPARABLE:
            self.assertNotIn(column, different)
        self.assertEqual(table['position']['different'], different)
        for epic in ('ao0', 'ao1', 'ao2'):
            self.assertEqual(self.row(table, epic)['different'], [])

    def test_a_column_without_data_is_not_marked(self):
        different = self.row(self.table(), 'nat')['different']
        for column in ('gate_wait', 'failed', 'repair'):
            self.assertNotIn(column, different)

    def test_the_text_marks_the_cells_and_explains_the_mark(self):
        out = self.trend()[1]
        lines = out.splitlines()
        row = next(line for line in lines if line.startswith('nat '))
        self.assertEqual(row.count(MARK), len(NATIVE_DIFFERENT), row)
        self.assertIn('%s 5,00%s' % (APPROX, MARK), row)            # the value per task: an estimate, counted differently
        self.assertNotIn(MARK, next(line for line in lines if line.startswith('ao0 ')))
        self.assertIn('%s %s' % (MARK, DIFFERENT), out)

    def test_the_comparable_columns_of_a_native_row_have_no_mark(self):
        row = next(line for line in self.trend()[1].splitlines() if line.startswith('nat '))
        self.assertEqual(row.split()[2], '2,00')               # to_done: 16 h / 8 tasks, no mark
        self.assertNotIn(MARK, row.split()[2])

    def test_the_position_lines_of_a_native_epic_are_marked(self):
        lines = self.trend()[1].splitlines()
        self.assertTrue(any(l.startswith('  USD: ') and MARK in l for l in lines), lines)
        self.assertTrue(any(l.startswith('  interw.: ') and MARK in l for l in lines), lines)
        self.assertTrue(any(l.startswith('  do_done: ') and MARK not in l for l in lines), lines)
        self.assertTrue(any(l.startswith('  regr.: ') and MARK not in l for l in lines), lines)

    def test_no_legend_without_a_native_record(self):
        os.remove(os.path.join(self.state, 'epics', 'nat.json'))
        self.assertNotIn(DIFFERENT, self.trend()[1])


class RenderSourcesTest(SourcesEnv):
    def native_text(self, fmt, record=None, past=None):
        past = [native('n%d' % i, merged='2026-11-0%dT10:00:00Z' % (i + 1)) for i in range(2)] if past is None else past
        store_records(self.state, *past, record or native('nat'))
        return self.render('nat', fmt)

    def line(self, out, start):
        return next(l for l in out.splitlines() if l.strip().lstrip('-').strip().startswith(start))

    def test_the_source_is_named_after_the_bottlenecks_in_both_formats(self):
        store_records(self.state, *ao_history(2), fake_record('demo', merged='2026-11-11T10:00:00Z'))
        for fmt in ('report', 'comment'):
            out = self.render('demo', fmt)
            self.assertIn('Źródło pomiaru: AO', out)
            self.assertNotIn(DIFFERENT, out)
        out = self.native_text('report')
        self.assertTrue(out.splitlines()[0].startswith('Wąskie gardła'))
        self.assertIn('Źródło pomiaru: natywne', out)

    def test_the_fields_counted_differently_are_marked_for_a_native_record(self):
        for fmt in ('report', 'comment'):
            out = self.native_text(fmt)
            for start in ('Wartość tokenów', 'Subskrypcja', 'Interwencje człowieka', 'Integracja', 'FIX'):
                self.assertIn(DIFFERENT, self.line(out, start), (fmt, start))
            self.assertIn(DIFFERENT, self.line(out, 'Czas do epic_done').split('rozkład')[1], fmt)

    def test_the_comparable_fields_are_not_marked(self):
        record = native('nat', final_pr=7, ci={'final': {'pr': 7, 'runs': 1, 'checks_s': 600.0, 'e2e_s': 900.0},
                                               'tasks': None})
        out = self.native_text('comment', record)
        self.assertNotIn(DIFFERENT, self.line(out, 'CI'))
        self.assertNotIn(DIFFERENT, self.line(out, 'Regresje po merge'))
        self.assertNotIn(DIFFERENT, self.line(out, 'Czas do epic_done').split('rozkład')[0])
        self.assertNotIn(DIFFERENT, self.line(out, "Czas do merge'u"))

    def test_a_native_value_is_an_estimate_by_the_price_list(self):
        line = self.line(self.native_text('report'), 'Wartość tokenów')
        self.assertIn('cennika', line)
        self.assertIn(APPROX + ' 40,00 USD', line)
        self.assertNotIn('szacunek CLI', line)

    def test_a_field_without_a_native_source_is_brak_danych_never_zero(self):
        out = self.native_text('report')
        for start in ('Czekanie w bramce', 'Próby', 'REPAIR', 'Sync', 'Alarmy (waste)'):
            self.assertIn('brak danych', self.line(out, start), start)
        self.assertIn('limit_wait brak danych', out)
        self.assertIn('ci_wait brak danych', out)

    def test_the_native_breakdown_is_an_upper_bound_in_idle(self):
        line = self.line(self.native_text('report'), 'Czas do epic_done')
        self.assertIn('idle', line)
        self.assertIn('górna granica', line)

    def test_the_comment_keeps_the_lines_retro_reads(self):
        parsed = retro.parse_measurement(self.native_text('comment'))
        self.assertEqual(parsed['interventions'], 4)
        self.assertEqual(parsed['manual_count'], 0)

    def test_the_position_is_against_the_native_history_only(self):
        out = self.native_text('report')
        line = self.line(out, 'Pozycja na tle mediany')
        self.assertIn('2 epiki', line)
        self.assertIn('natywn', line)
        self.assertIn('mediana z 2 epików natywnych', out.splitlines()[0])

    def test_without_a_native_history_the_ao_history_is_not_used(self):
        out = self.native_text('report', past=ao_history(5))
        self.assertIn('brak historii', self.line(out, 'Pozycja na tle mediany'))
        self.assertIn('natywn', self.line(out, 'Pozycja na tle mediany'))
        self.assertIn('mediana z 0 epików natywnych', out.splitlines()[0])

    def test_the_ao_position_is_against_the_ao_history_only(self):
        store_records(self.state, *ao_history(5), native('nat', merged='2026-10-20T10:00:00Z'),
                      fake_record('demo', merged='2026-11-11T10:00:00Z'))
        out = self.render('demo', 'report')
        self.assertIn('5 epików', self.line(out, 'Pozycja na tle mediany'))
        self.assertIn('mediana z 5 epików AO', out.splitlines()[0])


if __name__ == '__main__':
    unittest.main()

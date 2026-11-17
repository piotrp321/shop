"""Cross-cutting tests of the rule „liczby szacowane są zawsze oznaczone i nigdy nie są pokazane jak dokładne” (spec:
Dokładność): a value that is estimated, incomplete, or derived from estimated, incomplete or unknown data carries
`estimated: true` (and `incomplete` where the record uses it, a `gaps` entry for a lower bound of a time), the flag
propagates through the aggregations, the medians, the ratios, the trend, `decide`, `effect` and `waste`, and every
presentation (JSON, report, comment, push, instructions of /retro) shows such a number with „≈”. Fully exact data stays
unmarked."""
import json
import os
import re
import unittest

import metrics
import test_decide
import test_decide_models
import test_effect
import test_waste
import testkit
from testkit import OPUS, SONNET, Env, Transcript, fake_record, model_usage, store_records

H = 3600.0
NOW = test_waste.NOW
APPROX = '≈'


def cell(usd, no_cli=False, estimated=(), shared=False):
    """A cell of one attempt as `message_usage` and `result_usage` make it."""
    return {'tokens': dict(metrics.ZERO_TOKENS), 'estimated': set(estimated), 'usd': usd, 'shared': shared,
            'no_cli': no_cli}


def bare_numbers(text):
    """The numbers with a decimal comma of `text` that have no `≈ ` (and no `≈ +`/`≈ −`) in front of them."""
    return re.findall(r'\d+,\d+', re.sub(r'≈ [+−]?\d+(?:,\d+)?', '', text))


class ValueSectionsTest(unittest.TestCase):
    """`value_sections` marks every section a lost, estimated or unknown attempt may be part of."""

    def sections(self, items):
        return metrics.value_sections(items)

    def cells_of(self, value, task='P-0', role='delivery', model='opus'):
        return {'total': value['total'], 'by_role': value['by_role'][role], 'by_model': value['by_model'][model],
                'by_task': value['by_task'][task], 'by_role_model': value['by_role_model'][role][model],
                'by_task_role': value['by_task_role'][task][role]}

    def test_an_attempt_without_a_transcript_makes_every_section_it_could_be_part_of_incomplete(self):
        value = self.sections([('P-0', {'delivery': {'opus': cell(2.0)}}), ('P-1', None)])
        for name in ('total', 'by_role', 'by_model', 'by_role_model'):
            found = self.cells_of(value)[name]
            self.assertIs(found['estimated'], True, name)
            self.assertIs(found['incomplete'], True, name)
        self.assertEqual(value['by_role']['delivery']['usd'], 2.0)           # the known part stays

    def test_an_unknown_attempt_marks_the_roles_of_its_own_task_and_not_of_the_other_tasks(self):
        value = self.sections([('P-0', {'delivery': {'opus': cell(2.0)}}), ('P-0', None),
                               ('P-1', {'delivery': {'opus': cell(1.0)}})])
        self.assertIs(value['by_task_role']['P-0']['delivery']['estimated'], True)
        self.assertIs(value['by_task_role']['P-0']['delivery']['incomplete'], True)
        self.assertIs(value['by_task']['P-0']['incomplete'], True)
        self.assertIs(value['by_task_role']['P-1']['delivery']['estimated'], False)
        self.assertNotIn('incomplete', value['by_task_role']['P-1']['delivery'])
        self.assertIs(value['by_task']['P-1']['estimated'], False)

    def test_an_unknown_attempt_without_tasks_still_marks_the_role_and_model_sections(self):
        value = metrics.value_sections([('P-0', {'delivery': {'opus': cell(2.0)}}), ('P-1', None)], with_tasks=False)
        self.assertIsNone(value['by_task'])
        self.assertIs(value['by_role']['delivery']['incomplete'], True)
        self.assertIs(value['by_model']['opus']['incomplete'], True)

    def test_an_attempt_without_a_cli_result_makes_every_section_estimated(self):
        value = self.sections([('P-0', {'delivery': {'opus': cell(2.0, no_cli=True, estimated={'output'})}})])
        for name, found in self.cells_of(value).items():
            self.assertIs(found['estimated'], True, name)
            self.assertNotIn('incomplete', found, name)

    def test_a_cell_without_a_value_makes_every_section_estimated_and_incomplete_next_to_a_known_one(self):
        value = self.sections([('P-0', {'delivery': {'opus': cell(2.0)}}), ('P-0', {'delivery': {'opus': cell(None)}})])
        for name, found in self.cells_of(value).items():
            self.assertIs(found['estimated'], True, name)
            self.assertIs(found['incomplete'], True, name)
        alone = self.sections([('P-0', {'delivery': {'opus': cell(None)}})])
        for name, found in self.cells_of(alone).items():
            self.assertIsNone(found['usd'], name)
            self.assertIs(found['estimated'], True, name)

    def test_exact_attempts_leave_every_section_unmarked(self):
        value = self.sections([('P-0', {'delivery': {'opus': cell(2.0)}}), ('P-1', {'delivery': {'opus': cell(1.0)}})])
        for name, found in self.cells_of(value).items():
            self.assertIs(found['estimated'], False, name)
            self.assertNotIn('incomplete', found, name)


class RunEnv(Env):
    """A run of plan tasks and an integration task whose attempts are exact, have no CLI result, no value, no transcript
    or no time; the state holds a history of exact epics."""

    def setUp(self):
        super().setUp()
        self.config = metrics.load_config(os.path.join(testkit.HERE, 'config.json'))

    def history(self, count=5):
        found = []
        for i in range(count):
            record = fake_record('h%d' % i, merged='2026-10-%02dT10:00:00Z' % (i + 1), tasks=5, to_done=5 * H,
                                 work=2 * H, human_wait=0.5 * H, review=0.5 * H, gate_wait=0.0, repeat=0.0, value=5.0)
            record['by_task'] = {'T-%d' % n: {'kind': 'plan', 'work': 0.4 * H, 'attempts': 1} for n in range(5)}
            record['attempts'] = [{'task': 'X-1', 'number': 1, 'cli_result': True, 'model_rates': {OPUS: 0.001}}]
            found.append(record)
        store_records(self.state, *found)

    def exact_result(self, cost=2.0):
        return Transcript().init().message(input=1000, output=10).result(
            {OPUS: model_usage(input=1000, output=10, cost=cost)}, output=10)

    def mixed_home(self):
        home = testkit.synthetic_home(
            self.root, tasks=[('P-1', 'plan'), ('P-2', 'plan'), ('P-3', 'plan'), ('INT-1', 'integration')],
            created=1000.0,
            attempts=[dict(task_id='P-1', number=1, started_at=1100.0, ended_at=1300.0),
                      dict(task_id='P-1', number=2, started_at=None, ended_at=None, wall_s=None),       # no time
                      dict(task_id='P-2', number=1, started_at=1400.0, ended_at=1500.0, outcome='timeout',
                           executor_status=None),
                      dict(task_id='P-3', number=1, started_at=1600.0, ended_at=1700.0),
                      dict(task_id='INT-1', number=1, started_at=None, ended_at=None, wall_s=None)],
            events=[(2000.0, 'epic_done', {})])
        testkit.put_transcript(home, 'P-1', 1, self.exact_result().lines)                         # exact
        testkit.put_transcript(home, 'P-2', 1, Transcript().init().message(input=1000, output=10).lines)   # no result
        testkit.put_transcript(home, 'P-3', 1, Transcript().init().message(input=10).result(
            {OPUS: model_usage(input=10, output=3, cost=0.0, basis='unknown')}, output=3).lines)  # costBasis unknown
        return home                                                           # P-1/2 and INT-1/1: no transcript

    def mixed_record(self):
        self.history()
        record = self.record('demo', home=self.mixed_home())
        return record

    def exact_record(self):
        self.history()
        home = testkit.synthetic_home(
            self.root, tasks=[('P-1', 'plan')], created=1000.0,
            attempts=[dict(task_id='P-1', number=1, started_at=1100.0, ended_at=1300.0)],
            events=[(2000.0, 'epic_done', {})])
        testkit.put_transcript(home, 'P-1', 1, self.exact_result().lines)
        return self.record('demo', home=home)

    def render(self, fmt):
        code, out, err = testkit.run_cli(['render', 'demo', '--format', fmt, '--state', self.state])
        self.assertEqual(code, 0, err)
        return out


class RecordTest(RunEnv):
    def test_every_section_of_value_marks_the_unknown_estimated_and_missing_parts(self):
        value = self.mixed_record()['value']
        for name in ('total',):
            self.assertIs(value[name]['estimated'], True)
            self.assertIs(value[name]['incomplete'], True)
        for section, key in (('by_role', 'delivery'), ('by_model', OPUS)):
            self.assertIs(value[section][key]['estimated'], True, section)
            self.assertIs(value[section][key]['incomplete'], True, section)
        found = value['by_role_model']['delivery'][OPUS]
        self.assertIs(found['estimated'], True)
        self.assertIs(found['incomplete'], True)
        # P-1: an exact attempt and one without a transcript; P-2: no CLI result; P-3: costBasis unknown
        self.assertIs(value['by_task']['P-1']['incomplete'], True)
        self.assertIs(value['by_task_role']['P-1']['delivery']['incomplete'], True)
        self.assertIs(value['by_task']['P-2']['estimated'], True)
        self.assertIs(value['by_task_role']['P-2']['delivery']['estimated'], True)
        self.assertIsNone(value['by_task_role']['P-3']['delivery']['usd'])
        self.assertIs(value['by_task_role']['P-3']['delivery']['estimated'], True)

    def test_a_task_and_the_integration_without_the_time_of_an_attempt_are_incomplete_with_a_gap(self):
        record = self.mixed_record()
        self.assertIs(record['by_task']['P-1']['incomplete'], True)
        self.assertEqual(record['by_task']['P-1']['work'], 200.0)                 # the lower bound stays
        self.assertNotIn('incomplete', record['by_task']['P-2'])
        self.assertIs(record['integration']['incomplete'], True)
        self.assertTrue(any(g.startswith('by_task') and 'bez wall_s' in g for g in record['gaps']), record['gaps'])
        self.assertTrue(any(g.startswith('integration') and 'bez wall_s' in g for g in record['gaps']), record['gaps'])

    def test_the_times_in_attempts_are_incomplete_when_an_attempt_has_no_transcript_with_a_gap(self):
        record = self.mixed_record()
        self.assertIs(record['in_attempts']['incomplete'], True)
        self.assertEqual((record['in_attempts']['attempts_without_transcript'], record['in_attempts']['attempts']), (2, 5))
        self.assertTrue(any(g.startswith('in_attempts') and 'transkrypt' in g for g in record['gaps']), record['gaps'])

    def test_an_exact_run_is_marked_nowhere(self):
        record = self.exact_record()
        value = record['value']
        for found in [value['total'], value['by_role']['delivery'], value['by_model'][OPUS],
                      value['by_role_model']['delivery'][OPUS], value['by_task']['P-1'],
                      value['by_task_role']['P-1']['delivery']]:
            self.assertIs(found['estimated'], False)
            self.assertNotIn('incomplete', found)
        self.assertNotIn('incomplete', record['by_task']['P-1'])
        self.assertNotIn('incomplete', record['integration'])
        self.assertNotIn('incomplete', record['in_attempts'])
        self.assertFalse(any(g.startswith(('in_attempts', 'by_task', 'integration')) for g in record['gaps']),
                         record['gaps'])


class RenderTest(RunEnv):
    def test_every_value_line_of_a_run_with_missing_parts_shows_its_numbers_with_the_approximation_sign(self):
        self.mixed_record()
        for fmt in ('report', 'comment'):
            out = self.render(fmt)
            lines = [l for l in out.splitlines() if 'USD' in l]
            self.assertTrue(lines, out)
            value_lines = out.split('Wartość tokenów', 1)[1].split('Subskrypcja', 1)[0]
            self.assertEqual(bare_numbers(value_lines), [], (fmt, value_lines))
            self.assertIn('role: delivery ≈', value_lines)
            self.assertIn('modele: %s ≈' % OPUS, value_lines)

    def test_the_most_expensive_task_says_that_it_is_chosen_among_the_tasks_with_a_known_value(self):
        self.mixed_record()
        out = self.render('report')
        line = next(l for l in out.splitlines() if 'najdroższe zadanie' in l)
        self.assertIn('wśród zadań ze znaną wartością', line)

    def test_the_most_expensive_task_of_an_exact_run_has_no_such_note(self):
        self.exact_record()
        line = next(l for l in self.render('report').splitlines() if 'najdroższe zadanie' in l)
        self.assertNotIn('wśród', line)

    def test_the_time_of_the_integration_and_in_attempts_of_an_incomplete_run_is_marked_as_a_lower_bound(self):
        self.mixed_record()
        out = self.render('report')
        integration = next(l for l in out.splitlines() if l.startswith('Integracja'))
        self.assertIn('≈', integration)
        self.assertIn('dolna granica', integration)
        head = next(l for l in out.splitlines() if l.startswith('Czas w próbach'))
        self.assertIn('transkrypty tylko 3 z 5 prób', head)
        repeat = next(l for l in out.splitlines() if 'możliwe powtórzenia' in l)
        self.assertIn('≈', repeat)

    def test_every_hour_figure_of_incomplete_in_attempts_has_the_approximation_sign(self):
        record = fake_record('demo')
        record['in_attempts']['incomplete'] = True
        record['in_attempts']['attempts_without_transcript'], record['in_attempts']['attempts'] = 1, 3
        record['in_attempts']['categories']['test'] = {'count': 2, 'background': 0, 'unfinished': 0, 'seconds': 0.5 * H}
        text = '\n'.join(metrics.attempts_time_topic(record))
        self.assertIn('test ≈ 0,5 h (2)', text)
        self.assertEqual(bare_numbers(text.replace('0,5 h', '')), [])
        exact = fake_record('demo')
        exact['in_attempts']['categories']['test'] = {'count': 2, 'background': 0, 'unfinished': 0, 'seconds': 0.5 * H}
        self.assertNotIn(APPROX, '\n'.join(metrics.attempts_time_topic(exact)).replace('dolna granica', ''))

    def test_an_exact_run_has_no_approximation_sign_in_the_report_and_the_comment(self):
        self.exact_record()
        for fmt in ('report', 'comment'):
            self.assertNotIn(APPROX, self.render(fmt), fmt)

    def test_a_bottleneck_of_an_estimated_item_shows_its_numbers_with_the_approximation_sign(self):
        item = {'kind': 'task', 'name': 'P-0', 'value_s': 5 * H, 'median_s': 0.5 * H, 'excess_h': 4.5, 'share': 0.1,
                'share_of': 'to_done', 'rule': 'D2', 'effect_rule': 'D2:plan_split', 'effect_h': 4.5,
                'estimated': True}
        line, = metrics.bottleneck_lines([item])
        self.assertEqual(bare_numbers(line), [], line)
        self.assertIn('≈', line)
        exact, = metrics.bottleneck_lines([dict(item, estimated=False)])
        self.assertNotIn(APPROX, exact)


class TrendTest(RunEnv):
    def setUp(self):
        super().setUp()
        self.history = [fake_record(name, merged='2026-11-0%dT10:00:00Z' % number, value=10, share=1)
                        for number, name in enumerate('abc', 1)]

    def test_the_repeat_runs_of_incomplete_in_attempts_are_an_estimated_column(self):
        record = fake_record('x', repeat=3600.0)
        record['in_attempts']['incomplete'] = True
        self.assertEqual(metrics.trend_estimated(record, self.config), {'repeat_runs'})
        self.assertEqual(metrics.trend_estimated(fake_record('y', repeat=3600.0), self.config), set())

    def position_line(self, record, history=None):
        line, = metrics.position_topic(record, self.history if history is None else history, self.config, True)
        return line

    def test_the_percent_of_the_position_is_marked_when_the_value_or_the_median_is_estimated(self):
        record = fake_record('x', value=30)
        record['value']['total']['estimated'] = True
        self.assertIn('value ≈ 3,00 (mediana 1,00, ≈ +200%)', self.position_line(record))
        history = [dict(h) for h in self.history]
        history[1] = fake_record('b', merged='2026-11-02T10:00:00Z', value=10, share=1)
        history[1]['value']['total']['estimated'] = True
        self.assertIn('value 3,00 (mediana ≈ 1,00, ≈ +200%)', self.position_line(fake_record('x', value=30), history))
        self.assertIn('pp 0,80 (mediana 0,40, +100%)', self.position_line(fake_record('x', value=30, share=2)))

    def test_the_trend_table_marks_the_percent_and_the_json_says_which_columns_of_the_position_are_estimated(self):
        a = fake_record('a', merged='2026-11-01T10:00:00Z', value=10)
        b = fake_record('b', merged='2026-11-02T10:00:00Z', value=30)
        b['value']['total']['estimated'] = True
        store_records(self.state, a, b)
        code, out, err = testkit.run_cli(['trend', '--state', self.state, '--night', self.night, '--no-gh'])
        self.assertEqual(code, 0, err)
        self.assertIn('  USD: ≈ 3,00 (mediana ≈ 2,00, ≈ +50%)', out.splitlines())
        code, out, err = testkit.run_cli(['trend', '--json', '--state', self.state, '--night', self.night, '--no-gh'])
        position = json.loads(out)['position']
        self.assertEqual((position['estimated'], position['median_estimated']), (['value'], ['value']))

    def test_an_exact_table_has_no_estimated_columns_and_no_approximation_sign(self):
        store_records(self.state, fake_record('a'), fake_record('b', merged='2026-11-11T10:00:00Z'))
        code, out, err = testkit.run_cli(['trend', '--json', '--state', self.state, '--night', self.night, '--no-gh'])
        position = json.loads(out)['position']
        self.assertEqual((position['estimated'], position['median_estimated']), ([], []))
        self.assertNotIn(APPROX, testkit.run_cli(['trend', '--state', self.state, '--night', self.night, '--no-gh'])[1])


class DecideTest(test_decide.DecideEnv):
    def d2(self, mutate=None, past=None):
        record = test_decide.target()
        record['by_task']['P-0']['work'] = 5 * H
        if mutate:
            mutate(record)
        store_records(self.state, record, *(test_decide.history() if past is None else past))
        return self.candidate(self.result(), 'D2:plan_split')

    def test_a_plan_task_with_an_incomplete_time_makes_the_d2_candidate_estimated(self):
        candidate = self.d2(lambda r: r['by_task']['P-0'].update(incomplete=True))
        self.assertIs(candidate['expected_effect']['estimated'], True)
        self.assertEqual(candidate['data']['estimated'], ['work'])

    def test_a_history_with_an_incomplete_time_makes_the_d2_candidate_estimated(self):
        past = test_decide.history()
        past[2]['by_task']['P-3']['incomplete'] = True
        candidate = self.d2(past=past)
        self.assertIs(candidate['expected_effect']['estimated'], True)
        self.assertEqual(candidate['data']['estimated'], ['work'])

    def test_exact_times_leave_the_d2_candidate_unmarked(self):
        candidate = self.d2()
        self.assertIs(candidate['expected_effect']['estimated'], False)
        self.assertEqual(candidate['data']['estimated'], [])

    def ci(self, incomplete):
        record = test_decide.target()
        record['breakdown']['ci_wait'] = 4 * H
        record['in_attempts']['categories']['ci_watch']['seconds'] = 6 * H
        if incomplete:
            record['in_attempts']['incomplete'] = True
        store_records(self.state, record)
        return self.candidate(self.result(), 'D4:ci')

    def test_the_d4_ci_candidate_is_estimated_when_the_in_attempts_time_is_incomplete(self):
        candidate = self.ci(True)
        self.assertIs(candidate['expected_effect']['estimated'], True)
        self.assertEqual(candidate['data']['estimated'], ['in_attempts.ci_watch'])
        self.assertIs(self.ci(False)['expected_effect']['estimated'], False)
        self.assertEqual(self.ci(False)['data']['estimated'], [])


class D1Test(test_decide_models.D1Env):
    RATES = {OPUS: 4e-5, SONNET: 1e-5, testkit.HAIKU: 2e-5}

    def candidate(self, mutate=None, roles=None):
        record = test_decide_models.target(roles or {'code-reviewer': {OPUS: 30.0, SONNET: 2.0}, 'delivery': {OPUS: 68.0}},
                                           rates=self.RATES)
        if mutate:
            mutate(record)
        store_records(self.state, record)
        self.meta()
        return self.d1(self.decide()[1])

    def test_an_estimated_total_makes_the_share_and_the_effect_estimated(self):
        found = self.candidate(lambda r: r['value']['total'].update(estimated=True))
        self.assertIs(found['expected_effect']['estimated'], True)
        self.assertEqual(found['data']['estimated'], ['epic_total'])
        self.assertIn('szacowana', found['proposal'])

    def test_an_incomplete_total_makes_the_share_and_the_effect_estimated(self):
        found = self.candidate(lambda r: r['value']['total'].update(incomplete=True))
        self.assertIs(found['expected_effect']['estimated'], True)
        self.assertEqual(found['data']['estimated'], ['epic_total'])

    def test_a_cell_of_the_role_without_a_value_on_a_more_expensive_model_makes_the_effect_estimated(self):
        roles = {'code-reviewer': {OPUS: 30.0, testkit.HAIKU: None, SONNET: 2.0}, 'delivery': {OPUS: 68.0}}

        def unknown(record):
            record['value']['by_role_model']['code-reviewer'][testkit.HAIKU] = test_decide_models.cell(None, True)
        found = self.candidate(unknown, roles)
        self.assertIs(found['expected_effect']['estimated'], True)
        self.assertEqual(found['data']['estimated'], ['role_value'])
        self.assertIn('szacowana', found['proposal'])

    def test_exact_inputs_leave_the_candidate_unmarked(self):
        found = self.candidate()
        self.assertIs(found['expected_effect']['estimated'], False)
        self.assertEqual(found['data']['estimated'], [])
        self.assertNotIn('szacowana', found['proposal'])


class BottleneckTest(RunEnv):
    def items(self, mutate=None, past=None):
        record = test_decide.target()
        record['by_task']['P-0']['work'] = 5 * H
        record['in_attempts']['categories']['test']['seconds'] = 5 * H
        if mutate:
            mutate(record)
        found = metrics.bottlenecks_of(record, test_decide.history() if past is None else past, self.config, metrics.Gaps())
        return {item['name']: item for item in found}

    def test_a_task_with_an_incomplete_time_and_incomplete_in_attempts_are_estimated_items(self):
        def mark(record):
            record['by_task']['P-0']['incomplete'] = True
            record['in_attempts']['incomplete'] = True
        items = self.items(mark)
        self.assertIs(items['P-0']['estimated'], True)
        self.assertIs(items['in_attempts.test']['estimated'], True)

    def test_a_median_of_incomplete_history_times_makes_the_item_estimated(self):
        past = test_decide.history()
        past[1]['by_task']['P-1']['incomplete'] = True
        self.assertIs(self.items(past=past)['P-0']['estimated'], True)

    def test_exact_times_give_unmarked_items(self):
        items = self.items()
        self.assertTrue(items)
        self.assertEqual([n for n, item in items.items() if item['estimated']], [])


def role_epic(case, name, started, merged, plan_value, **kwargs):
    return test_effect.D1Test.role_epic(case, name, started, merged, plan_value, **kwargs)


class EffectTest(test_effect.EffectEnv):
    def window(self, mutate):
        """Three exact epics before the fix and three after it (5 plan tasks each) changed by `mutate`."""
        records = [role_epic(self, 'b%d' % i, i, i + 1, 1.0) for i in range(1, 4)]
        records += [role_epic(self, 'a%d' % i, 10 + i, 12 + i, 0.5) for i in range(1, 4)]
        for record in records:
            if record['epic'].startswith('a'):
                mutate(record)
        store_records(self.state, *records)

    def test_a_task_with_an_incomplete_value_makes_the_d1_window_estimated_even_without_a_role_cell(self):
        def incomplete(record):
            for task in record['value']['by_task']:
                record['value']['by_task'][task]['incomplete'] = True
        self.window(incomplete)
        result = self.result('D1:code-reviewer')
        self.assertIs(result['after']['estimated'], True)
        self.assertIs(result['before']['estimated'], False)
        self.assertIs(result['estimated'], True)

    def test_a_task_without_a_known_value_makes_the_d1_window_estimated(self):
        def unknown(record):
            record['value']['by_task']['P-0'] = {'usd': None, 'estimated': True, 'tokens': {}, 'estimated_tokens': []}
        self.window(unknown)
        result = self.result('D1:code-reviewer')
        self.assertIs(result['after']['estimated'], True)
        self.assertIs(result['estimated'], True)

    def test_a_window_of_exact_d1_values_is_not_marked(self):
        self.window(lambda record: None)
        result = self.result('D1:code-reviewer')
        self.assertEqual((result['before']['estimated'], result['after']['estimated'], result['estimated']),
                         (False, False, False))

    def plan_epics(self, mutate):
        records = []
        for i in range(1, 4):
            records.append(test_effect.epic('b%d' % i, i, i + 1))
            records.append(test_effect.epic('a%d' % i, 10 + i, 12 + i))
        for record in records:
            record['by_task'] = {'P-%d' % n: {'kind': 'plan', 'work': 2 * H, 'attempts': 1} for n in range(5)}
            if record['epic'].startswith('a'):
                mutate(record)
        store_records(self.state, *records)

    def test_a_plan_task_with_an_incomplete_time_makes_the_d2_window_estimated(self):
        self.plan_epics(lambda r: r['by_task']['P-0'].update(incomplete=True))
        result = self.result('D2:plan_split')
        self.assertIs(result['after']['estimated'], True)
        self.assertIs(result['before']['estimated'], False)
        self.assertIs(result['estimated'], True)

    def test_exact_plan_times_leave_the_d2_window_unmarked(self):
        self.plan_epics(lambda r: None)
        result = self.result('D2:plan_split')
        self.assertEqual((result['before']['estimated'], result['after']['estimated'], result['estimated']),
                         (False, False, False))

    def ci_epics(self, mutate):
        records = []
        for i in range(1, 4):
            for record in (test_effect.epic('b%d' % i, i, i + 1), test_effect.epic('a%d' % i, 10 + i, 12 + i)):
                record['breakdown']['ci_wait'] = H
                if record['epic'].startswith('a'):
                    mutate(record)
                records.append(record)
        store_records(self.state, *records)

    def test_incomplete_in_attempts_make_the_d4_ci_window_estimated_and_the_other_d4_measures_exact(self):
        self.ci_epics(lambda r: r['in_attempts'].update(incomplete=True))
        result = self.result('D4:ci')
        self.assertIs(result['after']['estimated'], True)
        self.assertIs(result['before']['estimated'], False)
        self.assertIs(result['estimated'], True)
        self.assertIs(self.result('D4:human_wait')['estimated'], False)

    def test_exact_in_attempts_leave_the_d4_ci_window_unmarked(self):
        self.ci_epics(lambda r: None)
        self.assertIs(self.result('D4:ci')['estimated'], False)


class WasteTest(test_waste.WasteEnv):
    RATE = 1e-5

    def alarms_of(self, out, rule):
        return [a for a in self.alarms(out) if a['alarm'] == rule]

    def tail(self, alarm):
        """The part of the push after the amount: „(N× mediany)”."""
        return alarm['push'].split('(')[-1]

    def estimated_attempt(self, home, number, tokens=1000000):
        testkit.put_transcript(home, 'T-1', number, Transcript().init().message(input=tokens, output=10).lines)

    def test_the_ratio_of_an_estimated_current_amount_is_marked_in_w1(self):
        self.history(5, [test_waste.hist('H', 'plan', value=2.0, cli_result=True, model_rates={OPUS: self.RATE})])
        home = self.home(attempts=[self.ended()])
        self.estimated_attempt(home, 1)                       # no CLI result: 10 USD, estimated
        alarm, = self.alarms_of(self.run_ok(home), 'W1')
        self.assertIs(alarm['estimated'], True)
        self.assertIn(APPROX, self.tail(alarm))

    def test_the_ratio_to_a_median_of_estimated_history_values_is_marked_in_w1_and_w4(self):
        self.history(5, [test_waste.hist('H', 'plan', value=2.0, estimated=True)])
        home = self.home(attempts=[self.ended()])
        self.cost(home, 'T-1', 1, 7.0)
        out = self.run_ok(home)
        alarm = self.alarms_of(out, 'W1')[0]
        self.assertIs(alarm['estimated'], False)              # the amount is exact
        self.assertIs(alarm['median_estimated'], True)
        self.assertIn(APPROX, self.tail(alarm))
        self.assertNotIn(APPROX, alarm['push'].split('(')[0])
        task = self.alarms_of(out, 'W4')[0]
        self.assertIs(task['median_estimated'], True)
        self.assertIn(APPROX, self.tail(task))

    def test_the_ratio_to_an_exact_history_is_not_marked(self):
        self.history(5, [test_waste.hist('H', 'plan', value=2.0)])
        home = self.home(attempts=[self.ended()])
        self.cost(home, 'T-1', 1, 7.0)
        for alarm in self.alarms(self.run_ok(home)):
            self.assertIs(alarm['median_estimated'], False)
            self.assertNotIn(APPROX, alarm['push'])

    def test_the_median_of_a_history_task_with_an_attempt_without_an_end_is_not_a_measurement(self):
        record = {'attempts': [test_waste.hist('T', 'plan', value=1.0),
                               dict(test_waste.hist('T', 'plan', value=5.0, number=2), ended_at=None)]}
        found = metrics.waste_history({'old': record}, set())
        self.assertEqual(found['task'], {})                   # a partial sum would understate the median

    def test_a_running_attempt_of_the_task_makes_the_w4_amount_a_lower_bound(self):
        self.history(5, [test_waste.hist('H', 'plan', value=1.0), test_waste.hist('H', 'plan', value=1.0, number=2)])
        home = self.home(attempts=[self.ended(number=1), self.ended(number=2, start=NOW - 60000), self.running(number=3)])
        self.cost(home, 'T-1', 1, 3.5)
        self.cost(home, 'T-1', 2, 3.5)
        alarm, = self.alarms_of(self.run_ok(home), 'W4')
        self.assertIs(alarm['incomplete'], True)
        self.assertNotIn('łącznie', alarm['push'])
        self.assertIn('zakończone próby', alarm['push'])

    def test_a_task_without_a_running_attempt_is_not_incomplete(self):
        self.history(5, [test_waste.hist('H', 'plan', value=1.0), test_waste.hist('H', 'plan', value=1.0, number=2)])
        home = self.home(attempts=[self.ended(number=1), self.ended(number=2, start=NOW - 60000)])
        self.cost(home, 'T-1', 1, 3.5)
        self.cost(home, 'T-1', 2, 3.5)
        alarm, = self.alarms_of(self.run_ok(home), 'W4')
        self.assertIs(alarm['incomplete'], False)
        self.assertIn('łącznie', alarm['push'])



class InstructionsTest(unittest.TestCase):
    def test_the_retro_analyst_cites_measured_estimated_numbers_with_the_approximation_sign(self):
        path = os.path.join(testkit.HERE, '..', '..', '.claude', 'agents', 'retro-analyst.md')
        with open(path, encoding='utf-8') as f:
            text = f.read()
        self.assertIn('„≈”', text)
        for field in ('data.estimated', 'expected_effect.estimated', 'median_estimated'):
            self.assertIn(field, text)


if __name__ == '__main__':
    unittest.main()

"""Tests of tokens and value per role, model and task from the executor transcripts (spec: Pojęcia, Dokładność tokenów
i wartości, Rekord epiku: value and attempts): on the sanitized real transcripts and on synthetic ones."""
import glob
import json
import os
import sqlite3
import unittest

import testkit
from testkit import HAIKU, OPUS, SONNET, AttemptEnv, Transcript, model_usage

KINDS = (('INTEGRATION', 'integration'), ('REPAIR', 'repair'), ('FIX', 'fix'), ('FINALIZE', 'finalize'))
TOKEN_KINDS = ('input', 'output', 'cache_read', 'cache_write')
MODEL_FIELDS = {'input': 'inputTokens', 'output': 'outputTokens', 'cache_read': 'cacheReadInputTokens',
                'cache_write': 'cacheCreationInputTokens'}


def kind_of(task_id):
    return next((kind for word, kind in KINDS if word in task_id), 'plan')


def real_transcripts():
    """[(epic, task, number, path)] of every transcript of the fixtures."""
    found = []
    for path in sorted(glob.glob(os.path.join(testkit.TRANSCRIPTS, '*', 'tasks', '*', 'attempts', '*',
                                               'stdout.jsonl'))):
        parts = path.split(os.sep)
        found.append((parts[-6], parts[-4], int(parts[-2]), path))
    return found


def oracle(path):
    """What the transcript says, read the plain way (the test's own reading, not the code under test):
    {results, orchestrator_output, model_usage, roles: {model: {role or None: unique message ids}}}."""
    lines = testkit.read_lines(path)
    results = [l for l in lines if l['type'] == 'result']
    roles, seen = {}, set()
    for line in lines:
        if line['type'] != 'assistant' or line['message']['id'] in seen:
            continue
        seen.add(line['message']['id'])
        role = line.get('subagent_type') or (None if line['parent_tool_use_id'] is None else '?')
        roles.setdefault(line['message']['model'], {}).setdefault(role, set()).add(line['message']['id'])
    return {'results': results, 'roles': roles,
            'orchestrator_output': sum(r['usage']['output_tokens'] for r in results),
            'model_usage': results[-1]['modelUsage'] if results else None}


class RealTranscriptsTest(AttemptEnv):
    def test_the_fixtures_hold_the_transcripts_of_the_spec(self):
        found = {(epic, task, number) for epic, task, number, _ in real_transcripts()}
        self.assertEqual(len([f for f in found if f[0] == 'deploy-ops']), 18)
        for expected in (('deploy-ops', 'DEPLOY-OPS-4', 3), ('catalog-admin', 'CATALOG-ADMIN-INTEGRATION-2', 1),
                         ('catalog-admin', 'CATALOG-ADMIN-INTEGRATION-1', 1), ('catalog-admin', 'CATALOG-ADMIN-4', 1),
                         ('admin-design', 'ADMIN-DESIGN-3', 1)):
            self.assertIn(expected, found)

    def test_orchestrator_output_is_the_sum_of_result_output_tokens_and_the_outputs_on_a_model_sum_to_model_usage(self):
        checked = 0
        for epic, task, number, path in real_transcripts():
            facts = oracle(path)
            if not facts['results']:
                continue
            with self.subTest(task=task, number=number):
                kind = kind_of(task)
                record = self.attempt_record(path, task, kind)
                orchestrator = 'integration' if kind == 'integration' else 'delivery'
                by_role_model = record['value']['by_role_model']
                self.assertEqual(sum(by_role_model[orchestrator][m]['tokens']['output']
                                     for m in by_role_model[orchestrator]), facts['orchestrator_output'])
                for model, used in facts['model_usage'].items():
                    for kind_name in TOKEN_KINDS:
                        total = sum(by_role_model[role][model]['tokens'][kind_name] for role in by_role_model
                                    if model in by_role_model[role])
                        self.assertEqual(total, used[MODEL_FIELDS[kind_name]], (model, kind_name))
                checked += 1
        self.assertGreaterEqual(checked, 20)

    def test_no_token_is_counted_twice_in_attempts_with_subagents_and_several_results(self):
        both = [t for t in real_transcripts() if len(oracle(t[3])['results']) > 1
                and any(role not in (None,) for roles in oracle(t[3])['roles'].values() for role in roles)]
        self.assertTrue({'CATALOG-ADMIN-4', 'CATALOG-ADMIN-INTEGRATION-2'} <= {t[1] for t in both})
        for epic, task, number, path in both:
            with self.subTest(task=task):
                facts = oracle(path)
                record = self.attempt_record(path, task, kind_of(task))
                tokens = record['attempts'][0]['tokens']
                for kind_name in TOKEN_KINDS:
                    self.assertEqual(tokens[kind_name],
                                     sum(u[MODEL_FIELDS[kind_name]] for u in facts['model_usage'].values()))

    def test_sole_role_on_a_model_has_an_exact_value_and_roles_sharing_a_model_have_an_estimated_one(self):
        shared = sole = typed = 0
        for epic, task, number, path in real_transcripts():
            facts = oracle(path)
            if not facts['results']:
                continue
            with self.subTest(task=task, number=number):
                kind = kind_of(task)
                record = self.attempt_record(path, task, kind)
                orchestrator = 'integration' if kind == 'integration' else 'delivery'
                for model, used in facts['model_usage'].items():
                    roles = {orchestrator if role is None else role for role in facts['roles'].get(model, {})}
                    cells = {role: self.cell(record, role, model) for role in roles}
                    if len(roles) == 1:
                        cell = cells[next(iter(roles))]
                        self.assertFalse(cell['estimated'])
                        self.assertAlmostEqual(cell['usd'], used['costUSD'], places=6)
                        self.assertEqual(cell['estimated_tokens'], [])
                        sole += 1
                    else:
                        self.assertTrue(all(c['estimated'] for c in cells.values()), cells)
                        self.assertAlmostEqual(sum(c['usd'] for c in cells.values()), used['costUSD'], places=6)
                        shared += 1
                    types = [r for r in roles if r != orchestrator]
                    for role in types:
                        self.assertEqual('output' in cells[role]['estimated_tokens'], len(types) > 1, role)
                        typed += len(types) > 1
                    if orchestrator in cells:
                        self.assertNotIn('output', cells[orchestrator]['estimated_tokens'])
        self.assertTrue(sole and shared and typed)

    def test_value_by_role_sums_to_the_sum_of_attempt_values_on_the_deploy_ops_fixture(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        record = self.record('deploy-ops', home=home)
        attempts = record['attempts']
        self.assertEqual(len(attempts), 18)
        self.assertAlmostEqual(sum(a['value'] for a in attempts), sum(c['usd'] for c in record['value']['by_role'].values()),
                               places=6)
        self.assertAlmostEqual(record['value']['total']['usd'], sum(a['value'] for a in attempts), places=6)
        for section in ('by_model', 'by_task'):
            self.assertAlmostEqual(sum(c['usd'] for c in record['value'][section].values()),
                                   record['value']['total']['usd'], places=6)
        self.assertEqual(sorted(record['value']['by_task']), sorted({a['task'] for a in attempts}))
        self.assertTrue(set(record['value']['by_role']) >= {'delivery', 'integration', 'developer', 'code-reviewer',
                                                             'qa-adversarial', 'architecture-reviewer'})

    def test_every_attempt_with_a_cli_result_has_its_value(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        record = self.record('deploy-ops', home=home)
        con = sqlite3.connect(':memory:')
        with open(testkit.fixture('deploy-ops.sql'), encoding='utf-8') as f:
            con.executescript(f.read())
        stored = {(t, n): c for t, n, c in con.execute('SELECT task_id, number, cost_usd FROM attempts')}
        for attempt in record['attempts']:
            with self.subTest(task=attempt['task'], number=attempt['number']):
                cost = stored[(attempt['task'], attempt['number'])]
                if cost is None:
                    continue
                self.assertAlmostEqual(attempt['value'], cost, places=5)
                self.assertFalse(attempt['estimated'])

    def test_timeout_attempt_has_tokens_and_an_estimated_value_with_a_gap(self):
        record = self.record('deploy-ops', home=testkit.fixture_home(self.root, 'deploy-ops'))
        timeout = next(a for a in record['attempts'] if (a['task'], a['number']) == ('DEPLOY-OPS-4', 3))
        self.assertEqual(timeout['outcome'], 'timeout')
        self.assertTrue(timeout['estimated'])
        self.assertFalse(timeout['cli_result'])
        self.assertGreater(timeout['value'], 0)
        self.assertGreater(timeout['tokens']['input'] + timeout['tokens']['cache_read'], 0)
        self.assertTrue(any('DEPLOY-OPS-4/3' in gap and 'wyniku CLI' in gap for gap in record['gaps']), record['gaps'])
        total = sum(c['usd'] for c in record['value']['by_role'].values())
        self.assertAlmostEqual(total, sum(a['value'] for a in record['attempts']), places=6)
        self.assertTrue(record['value']['total']['estimated'])
        others = [a for a in record['attempts'] if a is not timeout and a['task'] != 'DEPLOY-OPS-4']
        self.assertTrue(any(not a['estimated'] for a in others))

    def test_attempt_with_several_results_has_the_value_of_the_last_equal_to_the_stored_cost(self):
        home = testkit.fixture_home(self.root, 'catalog-admin')
        record = self.record('catalog-admin', home=home)
        attempt = next(a for a in record['attempts'] if a['task'] == 'CATALOG-ADMIN-INTEGRATION-2')
        results = oracle(testkit.fixture_transcript('catalog-admin', 'CATALOG-ADMIN-INTEGRATION-2', 1))['results']
        self.assertEqual(len(results), 9)
        self.assertAlmostEqual(attempt['value'], results[-1]['total_cost_usd'], places=6)
        self.assertNotAlmostEqual(attempt['value'], results[0]['total_cost_usd'], places=2)
        con = sqlite3.connect(':memory:')
        with open(testkit.fixture('catalog-admin.sql'), encoding='utf-8') as f:
            con.executescript(f.read())
        cost = con.execute("SELECT cost_usd FROM attempts WHERE task_id = 'CATALOG-ADMIN-INTEGRATION-2'"
                           ).fetchone()[0]
        self.assertAlmostEqual(attempt['value'], cost, places=5)

    def test_transcripts_missing_for_some_attempts_are_a_gap_and_not_zero(self):
        record = self.record('catalog-admin', home=testkit.fixture_home(self.root, 'catalog-admin'))
        missing = [a for a in record['attempts'] if a['task'] not in (
            'CATALOG-ADMIN-4', 'CATALOG-ADMIN-INTEGRATION-1', 'CATALOG-ADMIN-INTEGRATION-2')]
        self.assertTrue(missing)
        for attempt in missing:
            self.assertIsNone(attempt['value'])
            self.assertIsNone(attempt['tokens'])
            self.assertIsNone(attempt['estimated'])
        self.assertTrue(any('stdout.jsonl' in gap for gap in record['gaps']), record['gaps'])


class DeduplicationAndRolesTest(AttemptEnv):
    def test_assistant_lines_with_the_same_message_id_count_once(self):
        lines = (Transcript().init().message(blocks=3, input=10, output=2, cache_read=100, cache_write=40)
                 .result({OPUS: model_usage(input=10, output=50, cache_read=100, cache_write=40, cost=1.0)}, output=50))
        record = self.attempt_record(lines)
        tokens = record['attempts'][0]['tokens']
        self.assertEqual((tokens['input'], tokens['cache_read'], tokens['cache_write']), (10, 100, 40))
        self.assertEqual(self.cell(record, 'delivery', OPUS)['tokens'],
                         {'input': 10, 'output': 50, 'cache_read': 100, 'cache_write': 40})

    def test_a_model_in_model_usage_without_messages_goes_to_the_role_other(self):
        lines = (Transcript().init().message(input=10, cache_read=100)
                 .result({OPUS: model_usage(input=10, output=30, cache_read=100, cost=1.0),
                          HAIKU: model_usage(input=7, output=3, cache_read=11, cost=0.1)}, output=30))
        record = self.attempt_record(lines)
        other = self.cell(record, 'other', HAIKU)
        self.assertEqual(other['tokens'], {'input': 7, 'output': 3, 'cache_read': 11, 'cache_write': 0})
        self.assertAlmostEqual(other['usd'], 0.1)
        self.assertFalse(other['estimated'])
        self.assertNotIn(OPUS, record['value']['by_role_model']['other'])
        self.assertAlmostEqual(record['attempts'][0]['value'], 1.1)

    def test_roles_come_from_subagent_type_and_the_orchestrator_from_the_task_kind(self):
        for kind, name in (('plan', 'delivery'), ('repair', 'delivery'), ('fix', 'delivery'),
                           ('finalize', 'delivery'), ('integration', 'integration')):
            with self.subTest(kind=kind):
                lines = (Transcript().init().message(input=1).message(role='qa-adversarial', parent='toolu_9', input=2)
                         .result({OPUS: model_usage(input=3, output=9, cost=1.0)}, output=4))
                record = self.attempt_record(lines, task='T-%s' % kind, kind=kind)
                self.assertEqual(sorted(record['value']['by_role']), sorted([name, 'qa-adversarial']))

    def test_subagent_output_is_model_usage_minus_orchestrator_output_split_by_message_ids(self):
        lines = Transcript().init().message(input=1)
        lines.message(role='a', parent='t', input=1).message(role='a', parent='t', input=1)
        lines.message(role='a', parent='t', input=1).message(role='b', parent='t', input=1)
        lines.result({OPUS: model_usage(input=5, output=300, cost=1.0)}, output=100)
        lines.result({OPUS: model_usage(input=5, output=400, cost=2.0)}, output=50)
        record = self.attempt_record(lines)
        by_role = record['value']['by_role']
        self.assertEqual(by_role['delivery']['tokens']['output'], 150)
        self.assertEqual(by_role['a']['tokens']['output'] + by_role['b']['tokens']['output'], 250)
        self.assertIn(by_role['a']['tokens']['output'], (187, 188))
        self.assertTrue(by_role['a']['estimated'] and by_role['b']['estimated'])
        self.assertEqual(by_role['a']['estimated_tokens'], ['output'])
        self.assertEqual(by_role['delivery']['estimated_tokens'], [])
        self.assertAlmostEqual(record['attempts'][0]['value'], 2.0)

    def test_a_single_subagent_type_on_a_model_has_an_exact_output(self):
        lines = Transcript().init().message(input=1).message(role='dev', parent='t', model=SONNET, input=2)
        lines.result({OPUS: model_usage(input=1, output=40, cost=1.0), SONNET: model_usage(input=2, output=70, cost=0.5)},
                     output=40)
        record = self.attempt_record(lines)
        dev = self.cell(record, 'dev', SONNET)
        self.assertEqual(dev['tokens']['output'], 70)
        self.assertFalse(dev['estimated'])
        self.assertAlmostEqual(dev['usd'], 0.5)

    def test_orchestrator_on_two_models_makes_the_subagent_output_estimated_with_a_gap(self):
        lines = Transcript().init(OPUS).init(SONNET)
        lines.message(input=1).message(model=SONNET, input=1)
        lines.message(role='dev', parent='t', model=SONNET, input=2)
        lines.result({OPUS: model_usage(input=1, output=40, cost=1.0), SONNET: model_usage(input=3, output=100, cost=0.5)},
                     output=60)
        record = self.attempt_record(lines)
        dev = self.cell(record, 'dev', SONNET)
        self.assertTrue(dev['estimated'])
        self.assertEqual(dev['estimated_tokens'], ['output'])
        orchestrator_output = sum(self.cell(record, 'delivery', m)['tokens']['output'] for m in (OPUS, SONNET))
        self.assertEqual(orchestrator_output, 60)
        total = sum(c['tokens']['output'] for c in record['value']['by_role'].values())
        self.assertEqual(total, 140)
        self.assertTrue(any('więcej niż jednym modelu' in gap for gap in record['gaps']), record['gaps'])

    def test_surplus_of_model_usage_over_the_messages_goes_to_other(self):
        lines = (Transcript().init().message(input=10, cache_read=100, cache_write=5)
                 .result({OPUS: model_usage(input=15, output=20, cache_read=130, cache_write=9, cost=1.0)}, output=20))
        record = self.attempt_record(lines)
        self.assertEqual(self.cell(record, 'delivery', OPUS)['tokens'],
                         {'input': 10, 'output': 20, 'cache_read': 100, 'cache_write': 5})
        self.assertEqual(self.cell(record, 'other', OPUS)['tokens'],
                         {'input': 5, 'output': 0, 'cache_read': 30, 'cache_write': 4})
        self.assertTrue(self.cell(record, 'delivery', OPUS)['estimated'])
        self.assertAlmostEqual(self.cell(record, 'delivery', OPUS)['usd'] + self.cell(record, 'other', OPUS)['usd'], 1.0)

    def test_shortfall_scales_the_roles_down_marks_them_estimated_and_leaves_a_gap(self):
        lines = (Transcript().init().message(input=30, cache_read=100).message(role='dev', parent='t', input=10)
                 .result({OPUS: model_usage(input=20, output=20, cache_read=80, cost=1.0)}, output=5))
        record = self.attempt_record(lines)
        tokens = record['value']['total']['tokens']
        self.assertEqual((tokens['input'], tokens['cache_read']), (20, 80))
        for role in ('delivery', 'dev'):
            cell = self.cell(record, role, OPUS)
            self.assertTrue(cell['estimated'])
            self.assertIn('input', cell['estimated_tokens'])
        self.assertTrue(any('niedobór' in gap for gap in record['gaps']), record['gaps'])

    def test_value_is_split_by_the_token_weights_of_the_config(self):
        # weights: input 1, cache write 5 min 1.25, cache write 1 h 2, cache read 0.1, output 5
        lines = Transcript().init().message(input=100, cache_write=40, cache_write_1h=10, cache_read=1000)
        lines.message(role='dev', parent='t', input=200)
        lines.result({OPUS: model_usage(input=300, output=20, cache_write=40, cache_write_1h=10, cache_read=1000,
                                        cost=10.0)}, output=12)
        record = self.attempt_record(lines)
        delivery = 100 + 40 * 1.25 + 10 * 2 + 1000 * 0.1 + 12 * 5
        dev = 200 + 8 * 5
        self.assertAlmostEqual(self.cell(record, 'delivery', OPUS)['usd'], 10 * delivery / (delivery + dev), places=6)
        self.assertAlmostEqual(self.cell(record, 'dev', OPUS)['usd'], 10 * dev / (delivery + dev), places=6)

    def test_cost_basis_unknown_leaves_a_gap_and_no_value_for_that_model(self):
        lines = (Transcript().init().message(input=10)
                 .result({OPUS: model_usage(input=10, output=3, cost=0.0, basis='unknown')}, output=3))
        record = self.attempt_record(lines)
        self.assertTrue(any('costBasis' in gap and 'unknown' in gap for gap in record['gaps']), record['gaps'])
        self.assertIsNone(self.cell(record, 'delivery', OPUS)['usd'])
        self.assertIsNone(record['attempts'][0]['value'])
        self.assertTrue(record['attempts'][0]['cli_result'])

    def test_a_missing_transcript_is_a_gap_and_not_zero(self):
        record = self.attempt_record(None)
        attempt = record['attempts'][0]
        self.assertEqual((attempt['value'], attempt['tokens'], attempt['estimated']), (None, None, None))
        self.assertTrue(any('stdout.jsonl' in gap for gap in record['gaps']), record['gaps'])
        self.assertIsNone(record['value']['total']['usd'])


class AttemptWithoutCliResultTest(AttemptEnv):
    def history(self, rates, epic='old'):
        """A stored record of another epic whose attempts have the given opus rate per weighted token."""
        return {epic: {'epic': epic, 'attempts': [{'task': 'X-%d' % i, 'number': 1, 'cli_result': True,
                                                   'model_rates': {OPUS: rate}} for i, rate in enumerate(rates)]}}

    def crashed(self, **kwargs):
        lines = Transcript().init()
        lines.message(input=100, output=1, cache_read=1000, cache_write=40)
        lines.message(role='dev', parent='t', input=200, output=2, cache_read=3000)
        return self.attempt_record(lines, outcome='timeout', status=None, **kwargs)

    def test_timeout_value_is_input_and_cache_tokens_times_the_median_historical_rate(self):
        record = self.crashed(stored=self.history([0.001, 0.003, 0.002]))
        weighted = 100 + 1000 * 0.1 + 40 * 1.25 + 200 + 3000 * 0.1
        attempt = record['attempts'][0]
        self.assertFalse(attempt['cli_result'])
        self.assertAlmostEqual(attempt['value'], weighted * 0.002, places=6)
        self.assertTrue(attempt['estimated'])
        self.assertEqual(attempt['tokens'], {'input': 300, 'output': 3, 'cache_read': 4000, 'cache_write': 40})
        self.assertAlmostEqual(sum(c['usd'] for c in record['value']['by_role'].values()), attempt['value'], places=6)
        self.assertTrue(all(c['estimated'] for c in record['value']['by_role'].values()))
        self.assertTrue(any('bez wyniku CLI' in gap for gap in record['gaps']), record['gaps'])

    def test_rate_history_of_the_current_epic_counts_and_the_stored_record_of_the_same_epic_does_not(self):
        home = testkit.synthetic_home(
            self.root, tasks=[('T-1', 'plan'), ('T-2', 'plan')], created=1000.0,
            attempts=[dict(task_id='T-1', number=1, started_at=1100.0, ended_at=1200.0),
                      dict(task_id='T-2', number=1, started_at=1300.0, ended_at=1500.0, outcome='timeout',
                           executor_status=None)], events=[(2000.0, 'epic_done', {})])
        done = (Transcript().init().message(input=100, cache_read=900)
                .result({OPUS: model_usage(input=100, output=10, cache_read=900, cost=0.5)}, output=10))
        testkit.put_transcript(home, 'T-1', 1, done.lines)
        testkit.put_transcript(home, 'T-2', 1, Transcript().init().message(input=50, cache_read=200).lines)
        # the stored record of this epic itself must not be used as history
        os.makedirs(os.path.join(self.state, 'epics'))
        with open(os.path.join(self.state, 'epics', 'demo.json'), 'w') as f:
            json.dump(self.history([9.0], epic='demo')['demo'], f)
        record = self.record('demo', home=home)
        first = next(a for a in record['attempts'] if a['task'] == 'T-1')
        rate = first['model_rates'][OPUS]
        self.assertAlmostEqual(rate, 0.5 / (100 + 900 * 0.1), places=9)
        second = next(a for a in record['attempts'] if a['task'] == 'T-2')
        self.assertAlmostEqual(second['value'], (50 + 200 * 0.1) * rate, places=6)

    def test_without_a_historical_rate_the_value_is_null_with_a_gap(self):
        record = self.crashed()
        attempt = record['attempts'][0]
        self.assertIsNone(attempt['value'])
        self.assertTrue(attempt['estimated'])
        self.assertIsNone(record['value']['total']['usd'])
        self.assertTrue(any('stawk' in gap for gap in record['gaps']), record['gaps'])

    def test_a_zeroed_last_result_with_message_tokens_is_an_attempt_without_a_cli_result(self):
        lines = Transcript().init().message(input=100, cache_read=1000)
        lines.result({OPUS: model_usage(input=100, output=10, cache_read=1000, cost=1.0)}, output=10)
        lines.result({}, output=0, cost=0.0)
        record = self.attempt_record(lines, stored=self.history([0.002]))
        attempt = record['attempts'][0]
        self.assertFalse(attempt['cli_result'])
        self.assertTrue(attempt['estimated'])
        self.assertAlmostEqual(attempt['value'], (100 + 100) * 0.002, places=6)
        self.assertTrue(any('bez wyniku CLI' in gap for gap in record['gaps']), record['gaps'])

    def test_a_zeroed_last_result_without_message_tokens_is_a_real_zero(self):
        lines = Transcript().init()
        lines.result({}, output=0, cost=0.0)
        record = self.attempt_record(lines)
        self.assertTrue(record['attempts'][0]['cli_result'])
        self.assertEqual(record['attempts'][0]['value'], 0)


class ByTaskTest(AttemptEnv):
    def test_by_task_has_work_of_every_task_including_attempts_after_epic_done(self):
        home = testkit.synthetic_home(
            self.root, tasks=[('T-1', 'plan'), ('T-REPAIR', 'repair')], created=1000.0,
            attempts=[dict(task_id='T-1', number=1, started_at=1100.0, ended_at=1300.0),
                      dict(task_id='T-1', number=2, started_at=1400.0, ended_at=1450.0),
                      dict(task_id='T-REPAIR', number=1, started_at=3000.0, ended_at=3500.0)],
            events=[(2000.0, 'epic_done', {})])
        for task, number, cost in (('T-1', 1, 1.0), ('T-1', 2, 2.0), ('T-REPAIR', 1, 4.0)):
            lines = (Transcript().init().message(input=10).result({OPUS: model_usage(input=10, output=1, cost=cost)},
                                                                  output=1))
            testkit.put_transcript(home, task, number, lines.lines)
        record = self.record('demo', home=home)
        self.assertEqual(record['by_task'], {'T-1': {'kind': 'plan', 'work': 250.0, 'attempts': 2},
                                             'T-REPAIR': {'kind': 'repair', 'work': 500.0, 'attempts': 1}})
        self.assertAlmostEqual(record['value']['by_task']['T-1']['usd'], 3.0)
        self.assertAlmostEqual(record['value']['by_task']['T-REPAIR']['usd'], 4.0)
        self.assertAlmostEqual(record['value']['total']['usd'], 7.0)

    def test_attempt_list_has_the_fields_of_the_spec(self):
        lines = (Transcript().init().message(input=10, cache_write=2)
                 .result({OPUS: model_usage(input=10, output=1, cache_write=2, cost=1.0)}, output=1))
        record = self.attempt_record(lines)
        attempt = record['attempts'][0]
        for key in ('task', 'kind', 'number', 'outcome', 'executor_status', 'wall_s', 'value', 'estimated', 'tokens'):
            self.assertIn(key, attempt)
        self.assertEqual((attempt['task'], attempt['kind'], attempt['number'], attempt['outcome'],
                          attempt['executor_status'], attempt['wall_s']),
                         ('T-1', 'plan', 1, 'result', 'READY_FOR_INTEGRATION', 200.0))
        self.assertEqual(attempt['tokens'], {'input': 10, 'output': 1, 'cache_read': 0, 'cache_write': 2})
        self.assertAlmostEqual(attempt['value'], 1.0)
        self.assertFalse(attempt['estimated'])


class ByTaskRoleTest(AttemptEnv):
    """`value.by_task_role` ({task: {role: cell}}): the value of a role in one task, the input of the D1 measure of
    `metrics.py effect` (spec: Decyzje optymalizacyjne)."""

    def home_with_two_tasks(self):
        home = testkit.synthetic_home(
            self.root, tasks=[('T-1', 'plan'), ('T-2', 'plan')], created=1000.0,
            attempts=[dict(task_id='T-1', number=1, started_at=1100.0, ended_at=1300.0),
                      dict(task_id='T-1', number=2, started_at=1400.0, ended_at=1450.0),
                      dict(task_id='T-2', number=1, started_at=1500.0, ended_at=1600.0)],
            events=[(2000.0, 'epic_done', {})])
        first = (Transcript().init().message(input=10).message(role='rev', parent='t', model=SONNET, input=5)
                 .result({OPUS: model_usage(input=10, output=1, cost=1.0), SONNET: model_usage(input=5, output=2, cost=0.5)},
                         output=1))
        second = Transcript().init().message(input=10).result({OPUS: model_usage(input=10, output=1, cost=2.0)}, output=1)
        testkit.put_transcript(home, 'T-1', 1, first.lines)
        testkit.put_transcript(home, 'T-1', 2, second.lines)
        return home

    def test_the_value_of_a_role_is_summed_over_the_attempts_of_the_task(self):
        record = self.record('demo', home=self.home_with_two_tasks())
        by_task_role = record['value']['by_task_role']
        self.assertAlmostEqual(by_task_role['T-1']['rev']['usd'], 0.5)
        self.assertAlmostEqual(by_task_role['T-1']['delivery']['usd'], 3.0)

    def test_the_roles_of_a_task_sum_to_its_value_and_only_acting_roles_have_an_entry(self):
        record = self.record('demo', home=self.home_with_two_tasks())
        roles = record['value']['by_task_role']['T-1']
        self.assertEqual(sorted(roles), ['delivery', 'rev'])
        self.assertAlmostEqual(sum(c['usd'] for c in roles.values()), record['value']['by_task']['T-1']['usd'])

    def test_a_task_without_a_transcript_has_no_role_values_and_an_unknown_task_value(self):
        record = self.record('demo', home=self.home_with_two_tasks())
        self.assertNotIn('T-2', record['value']['by_task_role'])
        self.assertIsNone(record['value']['by_task']['T-2']['usd'])

    def test_the_cell_marks_a_role_value_that_is_estimated(self):
        lines = Transcript().init().message(input=1).message(role='a', parent='t', input=1)
        lines.message(role='b', parent='t', input=1).result({OPUS: model_usage(input=3, output=9, cost=1.0)}, output=3)
        record = self.attempt_record(lines)
        self.assertTrue(record['value']['by_task_role']['T-1']['a']['estimated'])


if __name__ == '__main__':
    unittest.main()

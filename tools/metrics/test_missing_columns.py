"""A column missing in the AO database gives null in the fields derived from it and a gap, never a zero (spec: Źródła:
`Zera bez danych są zakazane`)."""
import json
import os
import sqlite3
import unittest

import testkit
from testkit import Env, run_cli


class MissingColumnTest(Env):

    def home_without(self, table, column):
        home = testkit.synthetic_home(
            self.root, created=1000.0, rounds=2, jsonl=[], events=[(9000, 'epic_done', {})],
            tasks=[('T-1', 'plan'), ('T-2', 'plan'), ('I-1', 'integration'), ('F-1', 'fix'), ('R-1', 'repair')],
            attempts=[dict(task_id='T-1', started_at=1100, ended_at=1900),
                      dict(task_id='T-2', started_at=1100, ended_at=1900, outcome='timeout'),
                      dict(task_id='I-1', started_at=2000, ended_at=2100),
                      dict(task_id='R-1', started_at=2700, ended_at=3000, outcome=None)])
        con = sqlite3.connect(os.path.join(home, 'orchestrator.db'))
        if column is None:
            con.execute('DROP TABLE %s' % table)
        else:
            con.execute('ALTER TABLE %s DROP COLUMN %s' % (table, column))
        con.commit()
        con.close()
        return home

    def assert_gap(self, record, field):
        self.assertTrue(any(g.startswith(field + ':') for g in record['gaps']), (field, record['gaps']))

    def test_without_tasks_kind_the_fields_of_the_kind_are_null(self):
        record = self.record('demo', home=self.home_without('tasks', 'kind'))
        self.assertIsNone(record['tasks'])
        self.assertIsNone(record['retries']['repair'])
        self.assertIsNone(record['retries']['fix'])
        self.assertIsNone(record['integration']['attempts'])
        self.assertIsNone(record['integration']['work_s'])
        self.assertEqual(record['integration']['rounds'], 2)
        self.assertEqual(record['retries']['attempts']['total'], 4)       # does not depend on the kind
        self.assert_gap(record, 'tasks.kind')

    def test_without_attempts_outcome_the_classes_are_null(self):
        record = self.record('demo', home=self.home_without('attempts', 'outcome'))
        attempts = record['retries']['attempts']
        for name in ('successful', 'blocked', 'failed', 'usage_limit', 'running', 'unclassified'):
            self.assertIsNone(attempts[name], name)
        self.assertIsNone(record['retries']['failed_by_outcome'])
        self.assertEqual(attempts['total'], 4)
        self.assertEqual(attempts['per_task'], {'T-1': 1, 'T-2': 1, 'I-1': 1, 'R-1': 1})
        self.assertEqual(record['retries']['fix'], 1)                      # the kind is there
        self.assertEqual(record['retries']['repair']['count'], 1)
        self.assert_gap(record, 'attempts.outcome')

    def test_without_attempts_executor_status_the_classes_are_null(self):
        record = self.record('demo', home=self.home_without('attempts', 'executor_status'))
        attempts = record['retries']['attempts']
        self.assertIsNone(attempts['failed'])
        self.assertIsNone(attempts['successful'])
        self.assertIsNone(record['retries']['failed_by_outcome'])
        self.assertEqual(attempts['total'], 4)
        self.assert_gap(record, 'attempts.executor_status')

    def test_without_the_attempts_table_everything_counted_from_attempts_is_null(self):
        record = self.record('demo', home=self.home_without('attempts', None))
        attempts = record['retries']['attempts']
        for name in ('total', 'per_task', 'successful', 'blocked', 'failed', 'usage_limit', 'running', 'unclassified'):
            self.assertIsNone(attempts[name], name)
        self.assertIsNone(record['retries']['failed_by_outcome'])
        self.assertIsNone(record['integration']['work_s'])
        self.assertIsNone(record['integration']['attempts'])
        self.assertEqual(record['integration']['rounds'], 2)
        self.assertEqual(record['retries']['fix'], 1)                      # the kind is there
        self.assertEqual(record['retries']['repair']['count'], 1)
        self.assertEqual(record['tasks'], 2)
        self.assert_gap(record, 'attempts.task_id')

    def test_without_attempts_task_id_the_integration_and_per_task_are_null(self):
        record = self.record('demo', home=self.home_without('attempts', 'task_id'))
        attempts = record['retries']['attempts']
        self.assertIsNone(attempts['per_task'])
        self.assertEqual(attempts['total'], 4)                             # the rows are there
        self.assertEqual(attempts['failed'], 2)
        self.assertIsNone(record['integration']['work_s'])
        self.assertIsNone(record['integration']['attempts'])
        self.assertEqual(record['integration']['rounds'], 2)
        self.assert_gap(record, 'attempts.task_id')

    def test_without_attempts_task_id_no_field_per_task_is_made_up(self):
        for table, column in (('attempts', 'task_id'), ('attempts', None)):
            with self.subTest(column=column):
                record = self.record('demo', home=self.home_without(table, column))
                self.assertIsNone(record['by_task'])
                self.assertIsNone(record['value']['by_task'])
                self.assertIsNone(record['value']['by_task_role'])
                self.assertTrue(all(entry['task'] is None for entry in record['attempts']))
                self.assert_gap(record, 'attempts.task_id')

    def test_without_attempts_task_id_decide_skips_d2_with_a_reason(self):
        self.record('demo', home=self.home_without('attempts', 'task_id'))
        code, out, err = run_cli(['decide', 'demo', '--state', self.state])
        self.assertEqual(code, 0, err)
        skipped = {item['key']: item['reason'] for item in json.loads(out)['skipped']}
        self.assertEqual(skipped.get('D2:plan_split'), 'brak danych: by_task')

    def test_without_attempts_started_at_the_work_of_the_breakdown_is_null(self):
        record = self.record('demo', home=self.home_without('attempts', 'started_at'))
        self.assertIsNone(record['breakdown']['work'])
        self.assert_gap(record, 'attempts.started_at')

    def test_a_category_of_the_breakdown_without_its_source_is_null_and_its_time_is_in_idle(self):
        cases = (('attempts', 'started_at', ['work']), ('attempts', 'ended_at', ['work']),
                 ('events', 'data', ['limit_wait', 'host_pause', 'github_wait']),
                 ('events', 'task_id', ['github_wait']), ('tasks', 'task_id', ['github_wait']))
        for table, column, names in cases:
            with self.subTest(column='%s.%s' % (table, column)):
                record = self.record('demo', home=self.home_without(table, column))
                breakdown = record['breakdown']
                for name, seconds in breakdown.items():
                    if name in names:
                        self.assertIsNone(seconds, name)
                        self.assertTrue(any(g.startswith('breakdown.%s:' % name) and 'idle' in g
                                            for g in record['gaps']), (name, record['gaps']))
                    else:
                        self.assertIsNotNone(seconds, name)
                self.assertAlmostEqual(sum(v for v in breakdown.values() if v is not None), record['wall']['to_done'],
                                       places=3)

    def test_without_the_events_the_cause_of_a_repair_is_unknown_not_zero(self):
        for column in ('data', 'type'):
            with self.subTest(column=column):
                record = self.record('demo', home=self.home_without('events', column))
                repair = record['retries']['repair']
                self.assertEqual(repair['count'], 1)
                self.assertIsNone(repair['final_pr_conflict'])
                self.assertIsNone(repair['ci_red'])
                self.assertEqual(repair['unknown'], 1)
                self.assertTrue(run_cli(['render', 'demo', '--format', 'report', '--state', self.state])[1]
                                .count('REPAIR: 1 (przyczyna nieznana)'))

    def test_a_null_value_in_an_existing_column_is_still_counted(self):
        record = self.record('demo', home=testkit.synthetic_home(
            self.root, created=1000.0, jsonl=[], events=[(9000, 'epic_done', {})], tasks=[('T-1', 'plan')],
            attempts=[dict(task_id='T-1', started_at=1100, ended_at=1900, outcome=None)]))
        self.assertEqual(record['retries']['attempts']['failed'], 1)
        self.assertEqual(record['tasks'], 1)

    def render(self, fmt):
        return run_cli(['render', 'demo', '--format', fmt, '--state', self.state])

    def test_a_record_with_the_nulls_renders_as_no_data(self):
        expected_lines = {'kind': ('REPAIR: brak danych', 'FIX: brak danych', 'Integracja: brak danych'),
                          'outcome': ('Próby: 4 (podział: brak danych)',),
                          None: ('Próby: brak danych', 'Integracja: brak danych')}
        for table, column in (('tasks', 'kind'), ('attempts', 'outcome'), ('attempts', None)):
            with self.subTest(column=column):
                self.record('demo', home=self.home_without(table, column))
                code, _, err = run_cli(['decide', 'demo', '--state', self.state])
                self.assertEqual(code, 0, err)
                for fmt in ('report', 'comment'):
                    code, out, err = self.render(fmt)
                    self.assertEqual(code, 0, err)
                    for expected in expected_lines[column]:
                        self.assertIn(expected, out)

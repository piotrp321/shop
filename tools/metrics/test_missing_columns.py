"""A column missing in the AO database gives null in the fields derived from it and a gap, never a zero (spec: Źródła:
`Zera bez danych są zakazane`)."""
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
                          'outcome': ('Próby: 4 (podział: brak danych)',)}
        for table, column in (('tasks', 'kind'), ('attempts', 'outcome')):
            with self.subTest(column=column):
                self.record('demo', home=self.home_without(table, column))
                for fmt in ('report', 'comment'):
                    code, out, err = self.render(fmt)
                    self.assertEqual(code, 0, err)
                    for expected in expected_lines[column]:
                        self.assertIn(expected, out)

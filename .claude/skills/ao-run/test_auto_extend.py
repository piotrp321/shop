"""Tests of auto_extend.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import importlib.util
import json
import os
import sqlite3
import subprocess
import sys
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'auto_extend.py')

SCHEMA = """
CREATE TABLE tasks (task_id TEXT PRIMARY KEY, status TEXT NOT NULL, reason TEXT, blockers TEXT,
                    answers TEXT NOT NULL DEFAULT '[]', epic_id TEXT);
CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, at REAL NOT NULL, task_id TEXT, attempt_id INTEGER,
                     type TEXT NOT NULL, data TEXT NOT NULL);
CREATE TABLE epics (epic_id TEXT PRIMARY KEY, status TEXT NOT NULL, reason TEXT, config TEXT);
CREATE TABLE attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT NOT NULL, number INTEGER NOT NULL,
                       started_at REAL NOT NULL, ended_at REAL, executor_status TEXT, cost_usd REAL, result TEXT);
"""

ANSWER = ('Automatyczna zgoda (R2): jedna dodatkowa runda poprawek w tym zadaniu. '
          'Gdy po niej problem zostanie, zwróć BLOCKED bez kolejnej rundy.')


def finding(id_, title, files, task_id=None, severity='Important'):
    return {'id': id_, 'severity': severity, 'title': title, 'files': files, 'task_id': task_id}


EXTRA = {'id': 'EXTRA-FIX-ROUND', 'type': 'technical', 'question': 'Czy dać dodatkową rundę poprawek?'}


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.home = self.tmp.name
        self.db = sqlite3.connect(os.path.join(self.home, 'orchestrator.db'))
        self.db.executescript(SCHEMA)
        self.at = 0.0

    def tearDown(self):
        self.db.close()
        self.tmp.cleanup()

    def epic(self, status='BLOCKED', reason='max_qa_rounds: F1 [Important] x', epic_id='metrics', config=None):
        self.db.execute('INSERT INTO epics VALUES (?, ?, ?, ?)', (epic_id, status, reason, config))
        self.db.commit()

    def task(self, task_id, status='DONE', blockers=None, epic_id='metrics'):
        self.db.execute('INSERT INTO tasks (task_id, status, blockers, epic_id) VALUES (?, ?, ?, ?)',
                        (task_id, status, None if blockers is None else json.dumps(blockers), epic_id))
        self.db.commit()

    def attempt(self, task_id, number, cost=10.0, ended=True, executor_status='READY_FOR_INTEGRATION',
                blockers=None, raw_result=None):
        result = raw_result if raw_result is not None else json.dumps({'blockers': blockers or []})
        self.db.execute('INSERT INTO attempts (task_id, number, started_at, ended_at, executor_status, cost_usd, '
                        'result) VALUES (?, ?, 0, ?, ?, ?, ?)',
                        (task_id, number, 1.0 if ended else None, executor_status, cost, result))
        self.db.commit()

    def findings(self, round_, blocking, critical=0, task_id=None, epic_id='metrics'):
        """An integration_findings event of an integration task (AO keeps it in `tasks` with status FINDINGS)."""
        self.at += 1
        task_id = task_id or '%s-INTEGRATION-%d' % (epic_id.upper(), round_)
        self.db.execute("INSERT OR IGNORE INTO tasks (task_id, status, epic_id) VALUES (?, 'FINDINGS', ?)",
                        (task_id, epic_id))
        data = {'round': round_, 'critical': critical, 'important': len(blocking), 'blocking': blocking}
        self.db.execute('INSERT INTO events (at, task_id, type, data) VALUES (?, ?, ?, ?)',
                        (self.at, task_id, 'integration_findings',
                         json.dumps(data, ensure_ascii=False)))
        self.db.commit()

    def jsonl(self, *lines):
        with open(os.path.join(self.home, 'events.jsonl'), 'w', encoding='utf-8') as f:
            for line in lines:
                f.write((line if isinstance(line, str) else json.dumps(line)) + '\n')

    def config(self, text):
        with open(os.path.join(self.home, 'auto-extend.json'), 'w', encoding='utf-8') as f:
            f.write(text)

    def run_script(self, *args):
        return subprocess.run([sys.executable, SCRIPT, *args], capture_output=True, text=True)

    def decide(self, *args):
        result = self.run_script(*args)
        self.assertEqual(result.returncode, 0, result.stderr)
        lines = result.stdout.splitlines()
        self.assertEqual(len(lines), 1, result.stdout)
        return json.loads(lines[0])


class QaTest(Base):
    """`qa`: one more QA round of an epic blocked by max_qa_rounds."""

    def setUp(self):
        super().setUp()
        self.epic()
        self.task('METRICS-1')
        self.attempt('METRICS-1', 1, cost=20.0)
        self.task('METRICS-3')
        self.attempt('METRICS-3', 1, cost=30.5)
        self.findings(1, [finding('F1', 'README opisuje historię zadań epiku zamiast stanu obecnego',
                                  ['tools/metrics/README.md'])])
        self.findings(2, [finding('F1', 'brakująca kolumna bazy daje zera zamiast null', ['tools/metrics/metrics.py'],
                                  'METRICS-1')])

    def qa(self):
        return self.decide('qa', self.home, '--epic', 'metrics')

    def assertAsk(self, got, fragment):
        self.assertEqual(got['decision'], 'ask', got)
        self.assertTrue(any(fragment in r for r in got['reasons']), got['reasons'])

    def test_all_conditions_met_is_extend(self):
        got = self.qa()
        self.assertEqual(got['decision'], 'extend', got)
        self.assertEqual((got['round'], got['cost_usd'], got['max_usd']), (2, 50.5, 150))
        self.assertEqual(set(got), {'decision', 'reasons', 'round', 'cost_usd', 'max_usd'})

    # Q1
    def test_epic_not_blocked_is_ask(self):
        self.db.execute("UPDATE epics SET status = 'INTEGRATING'")
        self.db.commit()
        self.assertAsk(self.qa(), 'Q1')

    def test_repeated_finding_reason_is_ask(self):
        self.db.execute("UPDATE epics SET reason = 'repeated_finding: F1 [Important] x'")
        self.db.commit()
        self.assertAsk(self.qa(), 'Q1')

    def test_other_reason_is_ask(self):
        self.db.execute("UPDATE epics SET reason = 'epic_timeout'")
        self.db.commit()
        self.assertAsk(self.qa(), 'Q1')

    def test_unknown_epic_is_ask(self):
        got = self.decide('qa', self.home, '--epic', 'nope')
        self.assertAsk(got, 'Q1')

    # Q2
    def test_earlier_automatic_qa_round_is_ask(self):
        self.jsonl({'type': 'auto_action', 'at': 1.0, 'task_id': None, 'data': {'action': 'qa-round'}})
        self.assertAsk(self.qa(), 'Q2')

    def test_qa_rounds_added_earlier_in_ao_are_ask(self):
        # A resume with --add-qa-rounds (automatic or by the user) is in AO even when events.jsonl missed its record.
        self.db.execute("UPDATE epics SET config = ? WHERE epic_id = 'metrics'",
                        (json.dumps({'limits': {'add_qa_rounds': 1}}),))
        self.db.commit()
        self.assertAsk(self.qa(), 'Q2')

    def test_config_without_added_rounds_is_not_an_extension(self):
        self.db.execute("UPDATE epics SET config = ? WHERE epic_id = 'metrics'",
                        (json.dumps({'limits': {'add_qa_rounds': 0, 'add_attempts': 2}}),))
        self.db.commit()
        self.assertEqual(self.qa()['decision'], 'extend')

    def test_unreadable_epic_config_is_ask(self):
        self.db.execute("UPDATE epics SET config = '{broken' WHERE epic_id = 'metrics'")
        self.db.commit()
        self.assertAsk(self.qa(), 'Q2')

    def test_fix_round_or_human_action_do_not_count_as_qa_round(self):
        self.jsonl({'type': 'auto_action', 'at': 1.0, 'task_id': 'METRICS-1', 'data': {'action': 'fix-round'}},
                   {'type': 'human_action', 'at': 2.0, 'task_id': None, 'data': {'action': 'epic-resume'}})
        self.assertEqual(self.qa()['decision'], 'extend')

    def test_unreadable_lines_are_skipped(self):
        self.jsonl('not json', '[1, 2]', '{"type": "auto_action", "data": "qa-round"}')
        self.assertEqual(self.qa()['decision'], 'extend')

    def test_unreadable_line_does_not_hide_a_later_qa_round(self):
        self.jsonl('{broken', {'type': 'auto_action', 'at': 1.0, 'task_id': None, 'data': {'action': 'qa-round'}})
        self.assertAsk(self.qa(), 'Q2')

    def test_missing_events_file_counts_as_none(self):
        self.assertFalse(os.path.exists(os.path.join(self.home, 'events.jsonl')))
        self.assertEqual(self.qa()['decision'], 'extend')

    # Q3
    def test_no_findings_event_is_ask(self):
        self.db.execute("DELETE FROM events")
        self.db.commit()
        got = self.qa()
        self.assertAsk(got, 'Q3')
        self.assertIsNone(got['round'])

    def test_critical_in_last_round_is_ask(self):
        self.findings(3, [finding('F1', 'wyciek sekretu w logach pracownika', ['apps/api/src/Worker.php'])],
                      critical=1)
        self.assertAsk(self.qa(), 'Q3')

    def test_empty_blocking_in_last_round_is_ask(self):
        self.findings(3, [])
        self.assertAsk(self.qa(), 'Q3')

    def test_last_round_is_the_highest_round_not_the_last_row(self):
        self.findings(0, [finding('F9', 'stara runda z krytycznym', ['a.py'])], critical=2)
        self.assertEqual(self.qa()['round'], 2)

    def test_other_epic_findings_are_ignored(self):
        self.epic(epic_id='cart', status='DONE', reason=None)
        self.findings(9, [finding('F1', 'zupełnie inny problem koszyka', ['a.py'])], critical=3, epic_id='cart')
        self.assertEqual(self.qa()['decision'], 'extend')

    # Q4
    def test_same_fingerprint_is_a_repeat(self):
        self.findings(3, [finding('F2', '  Brakująca kolumna   bazy daje ZERA zamiast null', ['tools/metrics/metrics.py'],
                                  'METRICS-9')])
        got = self.qa()
        self.assertAsk(got, 'Q4')
        self.assertTrue(any('F2' in r and 'F1' in r for r in got['reasons']), got['reasons'])

    def test_similar_title_is_a_repeat(self):
        self.findings(3, [finding('F1', 'README opisuje historię zadań epiku, a nie stan obecny',
                                  ['docs/features/metrics/spec.md'])])
        self.assertAsk(self.qa(), 'Q4')

    def test_same_task_common_file_and_partly_similar_title_is_a_repeat(self):
        # METRICS-like: titles differ, but name the same rule (estimated values shown without a mark).
        self.findings(3, [finding('F1', 'waste: alarm pokazuje sumę z szacowanych prób bez oznaczenia',
                                  ['tools/metrics/metrics.py'], 'METRICS-4')])
        self.findings(4, [finding('F2', 'waste: iloraz do mediany z szacowanych prób pokazany w pushu bez oznaczenia',
                                  ['tools/metrics/metrics.py', 'tools/metrics/README.md'], 'METRICS-4')])
        got = self.qa()
        self.assertEqual(got['round'], 4)
        self.assertAsk(got, 'Q4')

    def test_same_rule_in_another_task_with_a_common_file_is_a_repeat(self):
        self.findings(3, [finding('F1', 'waste: alarm pokazuje sumę z szacowanych prób bez oznaczenia',
                                  ['tools/metrics/metrics.py'], 'METRICS-4')])
        self.findings(4, [finding('F2', 'waste: iloraz do mediany z szacowanych prób pokazany w pushu bez oznaczenia',
                                  ['tools/metrics/metrics.py'], 'METRICS-5')])
        self.assertAsk(self.qa(), 'Q4')

    def test_shared_word_stems_in_another_file_are_not_a_repeat(self):
        self.findings(3, [finding('F1', 'waste: alarm pokazuje sumę z szacowanych prób bez oznaczenia',
                                  ['tools/metrics/metrics.py'], 'METRICS-4')])
        self.findings(4, [finding('F2', 'panel pokazuje szacowany stan magazynu bez oznaczenia',
                                  ['apps/admin/src/stock.ts'], 'METRICS-5')])
        self.assertEqual(self.qa()['decision'], 'extend')

    def test_same_file_with_unrelated_title_is_not_a_repeat(self):
        self.findings(3, [finding('F1', 'trend nadpisuje rekord zapisany równolegle przez inną sesję',
                                  ['tools/metrics/metrics.py'], 'METRICS-1')])
        self.assertEqual(self.qa()['decision'], 'extend')

    def test_same_title_with_other_severity_and_files_is_still_a_repeat_by_similarity(self):
        self.findings(3, [finding('F1', 'brakująca kolumna bazy daje zera zamiast null', ['x.py'], None, 'Minor')])
        self.assertAsk(self.qa(), 'Q4')

    def test_short_words_alone_do_not_make_a_repeat(self):
        self.findings(3, [finding('F1', 'API i DB w tle bez null', ['tools/metrics/metrics.py'])])
        self.assertEqual(self.qa()['decision'], 'extend')

    # Q5
    def test_running_attempt_is_ask(self):
        self.attempt('METRICS-3', 2, cost=None, ended=False)
        self.assertAsk(self.qa(), 'Q5')

    def test_finished_attempt_without_cost_is_ask(self):
        self.attempt('METRICS-3', 2, cost=None)
        got = self.qa()
        self.assertAsk(got, 'koszt nieczytelny')
        self.assertIsNone(got['cost_usd'])

    def test_cost_at_limit_is_ask(self):
        self.attempt('METRICS-3', 2, cost=99.5)
        got = self.qa()
        self.assertEqual(got['cost_usd'], 150.0)
        self.assertAsk(got, 'Q5')

    def test_cost_counts_only_tasks_of_the_epic(self):
        self.task('CART-1', epic_id='cart')
        self.attempt('CART-1', 1, cost=500.0)
        self.assertEqual(self.qa()['cost_usd'], 50.5)

    def test_config_limit_is_used(self):
        self.config('{"max_usd": 50}')
        got = self.qa()
        self.assertEqual(got['max_usd'], 50)
        self.assertAsk(got, 'Q5')

    def test_config_with_higher_limit(self):
        self.config('{"max_usd": 500.5}')
        got = self.qa()
        self.assertEqual((got['decision'], got['max_usd']), ('extend', 500.5))

    # Q6
    def test_blocked_or_failed_task_is_ask(self):
        for status in ('BLOCKED', 'FAILED'):
            with self.subTest(status=status):
                self.db.execute('UPDATE tasks SET status = ? WHERE task_id = ?', (status, 'METRICS-3'))
                self.db.commit()
                self.assertAsk(self.qa(), 'Q6')

    def test_business_architecture_or_cost_blocker_is_ask(self):
        for kind in ('business', 'architecture', 'cost'):
            with self.subTest(kind=kind):
                self.db.execute('UPDATE tasks SET blockers = ? WHERE task_id = ?',
                                (json.dumps([{'id': 'B1', 'type': kind, 'question': '?'}]), 'METRICS-3'))
                self.db.commit()
                self.assertAsk(self.qa(), 'Q6')

    def test_technical_blocker_on_a_done_task_is_not_ask(self):
        self.db.execute('UPDATE tasks SET blockers = ? WHERE task_id = ?',
                        (json.dumps([{'id': 'B1', 'type': 'technical', 'question': '?'}]), 'METRICS-3'))
        self.db.commit()
        self.assertEqual(self.qa()['decision'], 'extend')

    def test_unreadable_blockers_is_ask(self):
        self.db.execute("UPDATE tasks SET blockers = '{not json' WHERE task_id = 'METRICS-3'")
        self.db.commit()
        self.assertAsk(self.qa(), 'Q6')

    def test_blocked_task_of_another_epic_is_ignored(self):
        self.task('CART-1', status='BLOCKED', epic_id='cart',
                  blockers=[{'id': 'B1', 'type': 'business', 'question': '?'}])
        self.assertEqual(self.qa()['decision'], 'extend')


class FixTest(Base):
    """`fix`: one more fix round of a task that asked for it with an EXTRA-FIX-ROUND blocker."""

    def setUp(self):
        super().setUp()
        self.epic(status='RUNNING', reason=None)
        self.task('METRICS-1')
        self.attempt('METRICS-1', 1, cost=20.0)
        self.task('METRICS-3', status='BLOCKED', blockers=[EXTRA])
        self.attempt('METRICS-3', 1, cost=10.0, executor_status='READY_FOR_INTEGRATION')
        self.attempt('METRICS-3', 2, cost=15.0, executor_status='BLOCKED', blockers=[EXTRA])

    def fix(self, task='METRICS-3'):
        return self.decide('fix', self.home, '--task', task)

    def assertAsk(self, got, fragment):
        self.assertEqual(got['decision'], 'ask', got)
        self.assertIsNone(got['answer'])
        self.assertTrue(any(fragment in r for r in got['reasons']), got['reasons'])

    def test_all_conditions_met_is_extend_with_the_fixed_answer(self):
        got = self.fix()
        self.assertEqual(got['decision'], 'extend', got)
        self.assertEqual(got['answer'], ANSWER)
        self.assertEqual((got['cost_usd'], got['max_usd']), (45.0, 150))
        self.assertEqual(set(got), {'decision', 'reasons', 'cost_usd', 'max_usd', 'answer'})

    # F1
    def test_task_not_blocked_is_ask(self):
        self.db.execute("UPDATE tasks SET status = 'RUNNING' WHERE task_id = 'METRICS-3'")
        self.db.commit()
        self.assertAsk(self.fix(), 'F1')

    def test_unknown_task_is_ask(self):
        self.assertAsk(self.fix('NOPE-1'), 'F1')

    def test_latest_attempt_not_blocked_is_ask(self):
        self.attempt('METRICS-3', 3, cost=1.0, executor_status='FAILED', blockers=[EXTRA])
        self.assertAsk(self.fix(), 'F1')

    def test_two_blockers_is_ask(self):
        self.db.execute('UPDATE attempts SET result = ? WHERE task_id = ? AND number = 2',
                        (json.dumps({'blockers': [EXTRA, {'id': 'B2', 'type': 'technical', 'question': '?'}]}),
                         'METRICS-3'))
        self.db.commit()
        self.assertAsk(self.fix(), 'F1')

    def test_other_blocker_id_or_type_is_ask(self):
        for blocker in ({'id': 'B1', 'type': 'technical', 'question': '?'},
                        dict(EXTRA, type='business'), dict(EXTRA, type='cost')):
            with self.subTest(blocker=blocker):
                self.db.execute('UPDATE attempts SET result = ? WHERE task_id = ? AND number = 2',
                                (json.dumps({'blockers': [blocker]}), 'METRICS-3'))
                self.db.commit()
                self.assertAsk(self.fix(), 'F1')

    def test_unreadable_result_is_ask(self):
        self.db.execute("UPDATE attempts SET result = 'oops' WHERE task_id = 'METRICS-3' AND number = 2")
        self.db.commit()
        self.assertAsk(self.fix(), 'F1')

    def test_no_attempts_is_ask(self):
        self.task('METRICS-7', status='BLOCKED', blockers=[EXTRA])
        self.assertAsk(self.fix('METRICS-7'), 'F1')

    # F2
    def test_earlier_attempt_with_extra_round_blocker_is_ask(self):
        self.db.execute('UPDATE attempts SET result = ? WHERE task_id = ? AND number = 1',
                        (json.dumps({'blockers': [EXTRA]}), 'METRICS-3'))
        self.db.commit()
        self.assertAsk(self.fix(), 'F2')

    def test_earlier_automatic_fix_round_for_this_task_is_ask(self):
        self.jsonl({'type': 'auto_action', 'at': 1.0, 'task_id': 'METRICS-3', 'data': {'action': 'fix-round'}})
        self.assertAsk(self.fix(), 'F2')

    def test_fix_round_of_another_task_or_qa_round_is_not_ask(self):
        self.jsonl({'type': 'auto_action', 'at': 1.0, 'task_id': 'METRICS-1', 'data': {'action': 'fix-round'}},
                   {'type': 'auto_action', 'at': 2.0, 'task_id': None, 'data': {'action': 'qa-round'}},
                   'garbage line')
        self.assertEqual(self.fix()['decision'], 'extend')

    # F3
    def test_epic_cost_at_limit_is_ask(self):
        self.attempt('METRICS-1', 2, cost=105.0)
        got = self.fix()
        self.assertEqual(got['cost_usd'], 150.0)
        self.assertAsk(got, 'F3')

    def test_running_attempt_in_the_epic_is_ask(self):
        self.attempt('METRICS-1', 2, cost=None, ended=False)
        self.assertAsk(self.fix(), 'F3')

    def test_cost_unreadable_is_ask(self):
        self.attempt('METRICS-1', 2, cost=None)
        self.assertAsk(self.fix(), 'koszt nieczytelny')

    def test_task_without_epic_uses_its_own_cost(self):
        self.task('SOLO-1', status='BLOCKED', blockers=[EXTRA], epic_id=None)
        self.attempt('SOLO-1', 1, cost=7.5, executor_status='BLOCKED', blockers=[EXTRA])
        got = self.fix('SOLO-1')
        self.assertEqual((got['decision'], got['cost_usd']), ('extend', 7.5))

    def test_task_without_epic_over_limit_is_ask(self):
        self.config('{"max_usd": 5}')
        self.task('SOLO-1', status='BLOCKED', blockers=[EXTRA], epic_id=None)
        self.attempt('SOLO-1', 1, cost=7.5, executor_status='BLOCKED', blockers=[EXTRA])
        self.assertAsk(self.fix('SOLO-1'), 'F3')

    # F4
    def test_business_architecture_or_cost_blocker_in_the_epic_is_ask(self):
        for kind in ('business', 'architecture', 'cost'):
            with self.subTest(kind=kind):
                self.db.execute('UPDATE tasks SET blockers = ? WHERE task_id = ?',
                                (json.dumps([{'id': 'B1', 'type': kind, 'question': '?'}]), 'METRICS-1'))
                self.db.commit()
                self.assertAsk(self.fix(), 'F4')

    def test_business_blocker_in_another_epic_is_ignored(self):
        self.task('CART-1', status='BLOCKED', epic_id='cart',
                  blockers=[{'id': 'B1', 'type': 'business', 'question': '?'}])
        self.assertEqual(self.fix()['decision'], 'extend')


class ErrorsTest(Base):
    def test_missing_database_exits_1(self):
        with tempfile.TemporaryDirectory() as empty:
            result = self.run_script('qa', empty, '--epic', 'metrics')
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, '')
        self.assertIn('orchestrator.db', result.stderr)

    def test_missing_home_exits_1(self):
        result = self.run_script('fix', os.path.join(self.home, 'nope'), '--task', 'T-1')
        self.assertEqual(result.returncode, 1)
        self.assertTrue(result.stderr.strip())

    def test_database_without_tables_exits_1(self):
        with tempfile.TemporaryDirectory() as other:
            sqlite3.connect(os.path.join(other, 'orchestrator.db')).close()
            result = self.run_script('qa', other, '--epic', 'metrics')
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, '')
        self.assertTrue(result.stderr.strip())

    def test_not_a_database_exits_1(self):
        with tempfile.TemporaryDirectory() as other:
            with open(os.path.join(other, 'orchestrator.db'), 'w') as f:
                f.write('plain text, not sqlite ' * 100)
            result = self.run_script('fix', other, '--task', 'T-1')
        self.assertEqual(result.returncode, 1)
        self.assertTrue(result.stderr.strip())

    def test_invalid_config_exits_1(self):
        self.epic()
        for text in ('not json', '[]', '{}', '{"max_usd": 0}', '{"max_usd": -5}', '{"max_usd": "100"}',
                     '{"max_usd": true}', '{"max_usd": null}', '{"max_usd": NaN}', '{"max_usd": Infinity}'):
            with self.subTest(text=text):
                self.config(text)
                result = self.run_script('qa', self.home, '--epic', 'metrics')
                self.assertEqual(result.returncode, 1, result.stdout)
                self.assertEqual(result.stdout, '')
                self.assertIn('auto-extend.json', result.stderr)

    @unittest.skipIf(hasattr(os, 'geteuid') and os.geteuid() == 0, 'root ignores permissions')
    def test_unreadable_config_exits_1(self):
        self.epic()
        self.config('{"max_usd": 100}')
        path = os.path.join(self.home, 'auto-extend.json')
        os.chmod(path, 0)
        try:
            result = self.run_script('qa', self.home, '--epic', 'metrics')
        finally:
            os.chmod(path, 0o600)
        self.assertEqual(result.returncode, 1)
        self.assertIn('auto-extend.json', result.stderr)

    def test_bad_arguments_exit_non_zero(self):
        for args in ((), ('qa', self.home), ('fix', self.home), ('qa', self.home, '--task', 'T-1'),
                     ('other', self.home, '--epic', 'x')):
            with self.subTest(args=args):
                result = self.run_script(*args)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout, '')

    def test_bad_arguments_exit_1(self):
        self.assertEqual(self.run_script('qa', self.home).returncode, 1)

    def test_database_is_not_modified(self):
        self.epic()
        path = os.path.join(self.home, 'orchestrator.db')
        self.db.close()
        before = os.path.getmtime(path), os.path.getsize(path)
        self.run_script('qa', self.home, '--epic', 'metrics')
        self.assertEqual((os.path.getmtime(path), os.path.getsize(path)), before)
        self.db = sqlite3.connect(path)


class SimilarityTest(unittest.TestCase):
    """Module functions behind Q4."""

    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location('auto_extend', SCRIPT)
        cls.mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.mod)

    def test_words_are_lowercased_unicode_and_at_least_4_letters(self):
        self.assertEqual(self.mod.words('Zażółć GĘŚLĄ jaźń: API d1 null_value'),
                         {'zażółć', 'gęślą', 'jaźń', 'null_value'})

    def test_jaccard(self):
        self.assertEqual(self.mod.jaccard('alfa beta gamma', 'alfa beta delta'), 0.5)
        self.assertEqual(self.mod.jaccard('a b c', 'a b c'), 0.0)

    def test_real_metrics_round_3_repeats_the_rule_of_rounds_1_and_2(self):
        # Titles of the METRICS run (2026-11-16/17): rounds 2-5 named one rule (estimated values shown as exact).
        f = 'tools/metrics/metrics.py'
        earlier = [finding('F2', 'trend pokazuje niepełną sumę wartości tokenów jak pełną', [f], 'METRICS-3'),
                   finding('F3', 'brakująca kolumna bazy AO daje zera zamiast null w polach rekordu', [f], 'METRICS-1'),
                   finding('F2', 'Wartości szacowane w trend i pozycji na tle mediany bez oznaczenia', [f], 'METRICS-3')]
        round_3 = [finding('F1', 'waste: alarm W4 pokazuje sumę z szacowanych prób bez „≈” i bez pola estimated', [f],
                           'METRICS-4'),
                   finding('F2', 'effect D1: wartość roli szacowana pokazana bez oznaczenia szacunku', [f], 'METRICS-5')]
        for new in round_3:
            self.assertTrue(any(self.mod.is_repeat(new, old) for old in earlier), new['title'])

    def test_real_admin_features_round_2_repeats_round_1(self):
        files = ['apps/api/src/Catalog/UI/Http/BackofficeProductsQuery.php']
        old = finding('F2', 'Komunikaty walidacji parametrów sort i productId list panelu po angielsku', files)
        new = finding('F7', 'Komunikat błędu typu tablicowej wartości sort i productId list panelu po angielsku', files)
        self.assertTrue(self.mod.is_repeat(new, old))

    def test_real_unrelated_titles_in_one_file_are_not_a_repeat(self):
        f = 'tools/metrics/metrics.py'
        old = finding('F3', 'brakująca kolumna bazy AO daje zera zamiast null w polach rekordu', [f], 'METRICS-1')
        new = finding('F1', 'Okno „przed” w effect jest obcięte, gdy zmergowanych epików przed --at jest mniej niż '
                            'w oknie „po”', [f], 'METRICS-5')
        self.assertFalse(self.mod.is_repeat(new, old))

    def test_stems_strip_negation_and_inflection(self):
        self.assertEqual(self.mod.stems('nieoznaczone oznaczenia szacowana szacowanych'), {'oznac', 'szaco'})

    def test_files_alone_never_make_a_repeat(self):
        old = finding('F1', 'trend nadpisuje rekord zapisany równolegle', ['tools/metrics/metrics.py'], 'METRICS-1')
        new = finding('F2', 'walidacja config.json przepuszcza błędne wartości', ['tools/metrics/metrics.py'],
                      'METRICS-1')
        self.assertFalse(self.mod.is_repeat(new, old))


if __name__ == '__main__':
    unittest.main()

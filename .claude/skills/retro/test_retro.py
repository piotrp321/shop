"""Tests of retro.py: python3 -m unittest discover -s .claude/skills/retro -p 'test_*.py'

The AO run data in fixtures/ are cut from real runs (VISUAL-DESIGN, DISCOUNT-CODES, CART and the older AO schema of
PRICING): the real table schemas and values, paths and payloads blanked. The tests build temporary state directories
from them; nothing outside the temporary directories is read or written, and no network is used.
"""
import hashlib
import io
import importlib.util
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
from contextlib import redirect_stderr, redirect_stdout
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))
FIXTURES = os.path.join(HERE, 'fixtures')
spec = importlib.util.spec_from_file_location('retro', os.path.join(HERE, 'retro.py'))
retro = importlib.util.module_from_spec(spec)
spec.loader.exec_module(retro)


def setUpModule():
    # night-decisions.log has the local time of the system without a zone
    os.environ['TZ'] = 'Europe/Warsaw'
    time.tzset()


def unittest_mock_patch(obj, name, value):
    return mock.patch.object(obj, name, value)


def fixture(name):
    with open(os.path.join(FIXTURES, name), encoding='utf-8') as f:
        return f.read()


def build_run(ao_home, epic, sql=None, events=None, night=None, report=None, extra_sql=None):
    """An AO run directory ao-<epic> in ao_home from fixture files (names relative to fixtures/) or texts."""
    run_dir = os.path.join(ao_home, 'ao-' + epic)
    os.makedirs(run_dir, exist_ok=True)
    if sql:
        con = sqlite3.connect(os.path.join(run_dir, 'orchestrator.db'))
        con.executescript(fixture(sql))
        if extra_sql:
            con.executescript(extra_sql)
        con.commit()
        con.close()
    for name, text in (('events.jsonl', events), ('night-decisions.log', night)):
        if text is not None:
            with open(os.path.join(run_dir, name), 'w', encoding='utf-8') as f:
                f.write(text)
    if report is not None:
        os.makedirs(os.path.join(run_dir, 'epics', epic), exist_ok=True)
        with open(os.path.join(run_dir, 'epics', epic, 'report.md'), 'w', encoding='utf-8') as f:
            f.write(report)
    return run_dir


def snapshot(path):
    """{relative path: sha256} of every file under path, plus the empty directories."""
    result = {}
    for root, dirs, files in os.walk(path):
        for name in files:
            full = os.path.join(root, name)
            with open(full, 'rb') as f:
                result[os.path.relpath(full, path)] = hashlib.sha256(f.read()).hexdigest()
    return result


class TempDirCase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix='retro-test-')
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.ao_home = os.path.join(self.tmp, 'ao')
        os.makedirs(self.ao_home)

    def real_home(self):
        """VISUAL-DESIGN and DISCOUNT-CODES (with their events), CART (an AO without events), ao-night, a run in
        progress, a DONE epic without a final PR, the older AO schema, a directory without a database."""
        build_run(self.ao_home, 'visual-design', 'visual-design.sql', fixture('visual-design.events.jsonl'),
                  fixture('visual-design.night-decisions.log'), fixture('visual-design.report.md'))
        build_run(self.ao_home, 'discount-codes', 'discount-codes.sql', fixture('discount-codes.events.jsonl'),
                  None, fixture('discount-codes.report.md'))
        build_run(self.ao_home, 'cart', 'cart.sql', report=fixture('cart.report.md'))
        night = os.path.join(self.ao_home, 'ao-night')
        os.makedirs(night)
        with open(os.path.join(night, 'measurements.jsonl'), 'w') as f:
            f.write('{"event": "start", "epic": "product-page"}\n')
        # an epic still running: ACTIVE, no final PR
        build_run(self.ao_home, 'hardening', 'cart.sql', extra_sql=(
            "INSERT INTO epics (epic_id,repo,repo_slug,plan_path,plan_sha,branch,base_sha,status,round,"
            "created_at,updated_at) VALUES ('hardening','','','','','epic/hardening','','ACTIVE',0,1.0,2.0);"
            "DELETE FROM epics WHERE epic_id='cart';"))
        # a DONE row without a final PR does not count
        build_run(self.ao_home, 'no-pr', 'cart.sql', extra_sql=(
            "UPDATE epics SET epic_id='no-pr', final_pr_number=NULL;"))
        build_run(self.ao_home, 'pricing', 'pricing-old-schema.sql')
        os.makedirs(os.path.join(self.ao_home, 'ao-empty'))
        # the directory is named after another epic than its row
        build_run(self.ao_home, 'other', 'cart.sql')
        return self.ao_home


class SourcesTest(TempDirCase):
    def test_only_done_epics_with_a_final_pr_are_epics(self):
        epics = retro.discover_epics(self.real_home())
        self.assertEqual([e['id'] for e in epics], ['cart', 'discount-codes', 'visual-design'])
        by_id = {e['id']: e for e in epics}
        self.assertEqual(by_id['visual-design']['final_pr_number'], 366)
        self.assertEqual(by_id['discount-codes']['final_pr_number'], 329)
        self.assertEqual(by_id['cart']['final_pr_number'], 79)
        self.assertAlmostEqual(by_id['visual-design']['created_at'], 1794223198.570775, places=3)

    def test_night_in_progress_old_schema_and_broken_runs_are_not_epics(self):
        home = self.real_home()
        with open(os.path.join(home, 'ao-empty', 'orchestrator.db'), 'w') as f:
            f.write('this is not a database')
        ids = [e['id'] for e in retro.discover_epics(home)]
        for name in ('night', 'hardening', 'no-pr', 'pricing', 'empty', 'other'):
            self.assertNotIn(name, ids)

    def test_missing_ao_home_has_no_epics(self):
        self.assertEqual(retro.discover_epics(os.path.join(self.tmp, 'nothing')), [])

    def test_runs_lists_unfinished_epics_for_the_status(self):
        runs = {r['id']: r for r in retro.discover_runs(self.real_home())}
        self.assertEqual(runs['hardening']['status'], 'ACTIVE')
        self.assertEqual(runs['visual-design']['status'], 'DONE')
        self.assertNotIn('night', runs)
        self.assertNotIn('pricing', runs)

    def test_databases_are_opened_read_only_and_files_stay_unchanged(self):
        home = self.real_home()
        before = snapshot(home)
        epics = retro.discover_epics(home)
        for epic in epics:
            retro.ao_incidents(epic)
        retro.discover_runs(home)
        self.assertEqual(snapshot(home), before)
        for root, _, files in os.walk(home):
            self.assertFalse([f for f in files if f.endswith(('-wal', '-shm', '-journal'))], root)

    def test_a_read_only_database_cannot_be_written_through_the_connection(self):
        db = os.path.join(self.real_home(), 'ao-cart', 'orchestrator.db')
        con = retro.open_readonly(db)
        with self.assertRaises(sqlite3.OperationalError):
            con.execute("UPDATE epics SET status='X'")
        con.close()

    def test_epic_without_needed_columns_gets_a_no_ao_data_fact(self):
        # an epics table with the older tasks table (no kind, no fix_count) and attempts without outcome data
        run = build_run(self.ao_home, 'old', 'pricing-old-schema.sql')
        con = sqlite3.connect(os.path.join(run, 'orchestrator.db'))
        con.executescript(
            "CREATE TABLE epics (epic_id TEXT PRIMARY KEY, status TEXT NOT NULL, final_pr_number INTEGER,"
            " created_at REAL NOT NULL);"
            "INSERT INTO epics VALUES ('old','DONE',5,1000.0);"
            "INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,created_at,updated_at)"
            " VALUES ('OLD-1','','','','','MERGED',1.0,2.0);")
        con.commit()
        con.close()
        epics = retro.discover_epics(self.ao_home)
        self.assertEqual([e['id'] for e in epics], ['old'])
        incidents, facts = retro.ao_incidents(epics[0])
        self.assertEqual(incidents, [])
        ids = [f['id'] for f in facts]
        self.assertIn('old/missing/ao-data', ids)
        text = [f for f in facts if f['id'] == 'old/missing/ao-data'][0]['text']
        self.assertIn('tasks', text)
        self.assertIn('kind', text)

    def test_epic_row_without_created_at_column_is_still_an_epic_with_a_gap(self):
        run = build_run(self.ao_home, 'gap')
        con = sqlite3.connect(os.path.join(run, 'orchestrator.db'))
        con.executescript("CREATE TABLE epics (epic_id TEXT PRIMARY KEY, status TEXT NOT NULL,"
                          " final_pr_number INTEGER);INSERT INTO epics VALUES ('gap','DONE',9);")
        con.commit()
        con.close()
        epics = retro.discover_epics(self.ao_home)
        self.assertEqual([(e['id'], e['final_pr_number'], e['created_at']) for e in epics], [('gap', 9, None)])
        incidents, facts = retro.ao_incidents(epics[0])
        self.assertEqual(incidents, [])
        self.assertIn('gap/missing/ao-data', [f['id'] for f in facts])

    def test_ao_is_never_run(self):
        # the module only imports sqlite3, never subprocess or os.system, and opens no file for writing
        with open(os.path.join(HERE, 'retro.py'), encoding='utf-8') as f:
            source = f.read()
        self.assertNotIn('subprocess', source)
        self.assertNotIn('os.system', source)
        self.assertNotIn("['ao'", source)   # no command line starting with `ao`


class AoIncidentsTest(TempDirCase):
    def epic(self, home, name):
        return [e for e in retro.discover_epics(home) if e['id'] == name][0]

    def incidents(self, name):
        return retro.ao_incidents(self.epic(self.real_home(), name))

    def test_visual_design_has_six_waits_with_epoch_second_keys(self):
        incidents, _ = self.incidents('visual-design')
        waits = [i for i in incidents if i['kind'] == 'wait']
        self.assertEqual(sorted(i['id'] for i in waits), sorted([
            'visual-design/wait/VISUAL-DESIGN-4@1794229662',
            'visual-design/wait/VISUAL-DESIGN-4@1794236083',
            'visual-design/wait/VISUAL-DESIGN-5@1794242091',
            'visual-design/wait/VISUAL-DESIGN-INTEGRATION-1@1794260001',   # with the epic block of that second
            'visual-design/wait/visual-design@1794272499',
            'visual-design/wait/visual-design@1794276256',
        ]))

    def test_a_wait_carries_reason_question_and_the_closing_action(self):
        incidents, _ = self.incidents('visual-design')
        wait = {i['id']: i for i in incidents}['visual-design/wait/VISUAL-DESIGN-5@1794242091']
        self.assertEqual(wait['epic'], 'visual-design')
        self.assertEqual(wait['key'], 'VISUAL-DESIGN-5@1794242091')
        self.assertEqual(wait['data']['reason'], 'policy')
        self.assertEqual(wait['data']['action'], 'answer')
        self.assertEqual(wait['data']['start'], 1794242091)
        self.assertEqual(wait['data']['end'], 1794242697)
        self.assertFalse(wait['data']['open'])
        self.assertTrue(wait['data']['human_action'])
        self.assertIn('polityką orkiestratora', wait['data']['blockers'][0])
        sources = {f['source'] for f in wait['facts']}
        self.assertIn('events.jsonl', sources)

    def test_night_decision_in_a_closed_window_joins_that_wait_only(self):
        incidents, facts = self.incidents('visual-design')
        by_id = {i['id']: i for i in incidents}
        first = by_id['visual-design/wait/visual-design@1794272499']
        second = by_id['visual-design/wait/visual-design@1794276256']
        self.assertEqual(first['data']['reason'], 'max_qa_rounds: rundy: 3')
        self.assertEqual(first['data']['action'], 'epic-resume')
        nights = [f for f in first['facts'] if f['source'] == 'night-decisions.log']
        self.assertEqual(len(nights), 1)
        self.assertIn('FINALIZE PR #365', nights[0]['text'])
        nights2 = [f for f in second['facts'] if f['source'] == 'night-decisions.log']
        self.assertEqual(len(nights2), 1)
        self.assertIn('REPAIR-1', nights2[0]['text'])
        # one decision, one incident: nothing left over for the epic and no incident of its own
        self.assertFalse([f for f in facts if f['id'].startswith('visual-design/night/')])
        self.assertEqual(len([i for i in incidents if i['kind'] == 'wait']), 6)
        self.assertEqual({i['kind'] for i in incidents}, {'wait', 'repair'})

    def test_failed_attempt_ending_just_before_the_block_belongs_to_the_wait(self):
        incidents, _ = self.incidents('visual-design')
        by_id = {i['id']: i for i in incidents}
        wait = by_id['visual-design/wait/VISUAL-DESIGN-5@1794242091']
        attempts = [f for f in wait['facts'] if f['source'] == 'orchestrator.db']
        self.assertEqual(len(attempts), 1)
        self.assertIn('policy_blocked', attempts[0]['text'])
        self.assertIn('VISUAL-DESIGN-5#1', attempts[0]['text'])
        self.assertFalse([i for i in incidents if i['kind'] == 'failed_attempt'])

    def test_failed_attempt_far_before_the_block_is_its_own_incident(self):
        home = self.real_home()
        con = sqlite3.connect(os.path.join(home, 'ao-visual-design', 'orchestrator.db'))
        con.execute("UPDATE attempts SET ended_at = ended_at - 4000 WHERE task_id='VISUAL-DESIGN-5' AND number=1")
        con.commit()
        con.close()
        incidents, _ = retro.ao_incidents(self.epic(home, 'visual-design'))
        failed = [i for i in incidents if i['kind'] == 'failed_attempt']
        self.assertEqual([i['id'] for i in failed], ['visual-design/failed_attempt/VISUAL-DESIGN-5#1'])
        self.assertEqual(failed[0]['data']['outcome'], 'policy_blocked')
        wait = {i['id']: i for i in incidents}['visual-design/wait/VISUAL-DESIGN-5@1794242091']
        self.assertFalse([f for f in wait['facts'] if f['source'] == 'orchestrator.db'])

    def test_failed_attempt_without_a_wait_has_outcome_and_exit_code(self):
        incidents, _ = self.incidents('discount-codes')
        failed = [i for i in incidents if i['kind'] == 'failed_attempt']
        self.assertEqual([i['id'] for i in failed], ['discount-codes/failed_attempt/DISCOUNT-CODES-1#1'])
        self.assertEqual((failed[0]['data']['outcome'], failed[0]['data']['exit_code']), ('invalid_output', 0))
        self.assertFalse(failed[0]['data']['can_be_corrective'])

    def test_queue_held_wait_is_keyed_by_the_epic_and_closed_by_release(self):
        incidents, _ = self.incidents('discount-codes')
        waits = {i['id']: i for i in incidents if i['kind'] == 'wait'}
        self.assertEqual(sorted(waits), ['discount-codes/wait/discount-codes@1794190226',
                                         'discount-codes/wait/discount-codes@1794210958'])
        held = waits['discount-codes/wait/discount-codes@1794190226']
        self.assertEqual(held['data']['action'], 'release')
        self.assertEqual(held['data']['event'], 'queue_held')
        self.assertEqual(held['data']['task_id'], 'DISCOUNT-CODES-INTEGRATION-3')
        self.assertIn('EnvironmentFailure', held['data']['reasons'][0])
        self.assertEqual(waits['discount-codes/wait/discount-codes@1794210958']['data']['action'], 'epic-resume')

    def test_repair_task_is_an_incident_with_its_pr(self):
        incidents, _ = self.incidents('visual-design')
        repair = [i for i in incidents if i['kind'] == 'repair']
        self.assertEqual([i['id'] for i in repair], ['visual-design/repair/VISUAL-DESIGN-REPAIR-1'])
        self.assertEqual(repair[0]['data']['pr'], 367)
        self.assertEqual(repair[0]['data']['task_kind'], 'repair')
        self.assertTrue(repair[0]['data']['can_be_corrective'])

    def test_task_with_pr_fix_rounds_is_a_repair_incident(self):
        home = self.real_home()
        con = sqlite3.connect(os.path.join(home, 'ao-cart', 'orchestrator.db'))
        con.execute("UPDATE tasks SET fix_count = 2 WHERE task_id = 'CART-3'")
        con.commit()
        con.close()
        incidents, _ = retro.ao_incidents(self.epic(home, 'cart'))
        repair = [i for i in incidents if i['kind'] == 'repair']
        self.assertEqual([(i['id'], i['data']['fix_count'], i['data']['pr']) for i in repair],
                         [('cart/repair/CART-3', 2, 75)])

    def test_plain_qa_fix_tasks_are_not_repairs(self):
        incidents, _ = self.incidents('discount-codes')
        self.assertFalse([i for i in incidents if i['kind'] == 'repair'])

    def test_without_events_the_epic_has_a_fact_and_no_waits(self):
        incidents, facts = self.incidents('cart')
        self.assertEqual(incidents, [])
        self.assertEqual([f['id'] for f in facts], ['cart/missing/events'])
        self.assertIn('brak zdarzeń', facts[0]['text'])

    def test_ids_are_the_same_on_a_second_run(self):
        home = self.real_home()
        first = retro.ao_incidents(self.epic(home, 'visual-design'))
        second = retro.ao_incidents(self.epic(home, 'visual-design'))
        self.assertEqual(first, second)

    def test_open_wait_has_no_end_and_takes_no_night_decision(self):
        events = [{'type': 'status_changed', 'task_id': 'T-1', 'epic_id': 'open', 'at': 1794000000.5,
                   'data': {'from': 'RUNNING', 'to': 'BLOCKED', 'reason': 'executor', 'blockers': ['Q?']}}]
        # 1794000100 in Europe/Warsaw
        night = time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(1794000100)) + ' epic resume: x\n'
        build_run(self.ao_home, 'open', 'cart.sql', '\n'.join(json.dumps(e) for e in events) + '\n', night,
                  extra_sql="UPDATE epics SET epic_id='open';")
        epic = self.epic(self.ao_home, 'open')
        incidents, facts = retro.ao_incidents(epic)
        wait = [i for i in incidents if i['kind'] == 'wait'][0]
        self.assertEqual(wait['id'], 'open/wait/T-1@1794000000')
        self.assertTrue(wait['data']['open'])
        self.assertIsNone(wait['data']['end'])
        self.assertFalse(wait['data']['human_action'])
        self.assertFalse(wait['data']['can_be_corrective'])
        self.assertFalse([f for f in wait['facts'] if f['source'] == 'night-decisions.log'])
        self.assertEqual([f['id'] for f in facts if '/night/' in f['id']], ['open/night/1794000100'])

    def test_night_decision_on_the_end_second_belongs_to_the_wait(self):
        # the log has whole seconds: the human_action at ...521.48 and the decision at ...521 are one moment
        incidents, _ = self.incidents('visual-design')
        first = {i['id']: i for i in incidents}['visual-design/wait/visual-design@1794272499']
        self.assertEqual(first['data']['end'], 1794272521)

    def test_epic_block_naming_the_task_in_the_same_second_joins_the_task_wait(self):
        # real: VISUAL-DESIGN-INTEGRATION-1 BLOCKED and epic_blocked "integration_blocked: B1 (...-INTEGRATION-1)"
        incidents, _ = self.incidents('visual-design')
        by_id = {i['id']: i for i in incidents}
        self.assertNotIn('visual-design/wait/visual-design@1794260001', by_id)
        wait = by_id['visual-design/wait/VISUAL-DESIGN-INTEGRATION-1@1794260001']
        texts = [f['text'] for f in wait['facts']]
        self.assertTrue([t for t in texts if 'integration_blocked: B1' in t], texts)
        self.assertIn('human_action answer', texts)
        self.assertIn('human_action epic-resume', texts)
        self.assertEqual(wait['data']['action'], 'answer')
        self.assertEqual(wait['data']['also_actions'], ['epic-resume'])
        self.assertEqual((wait['data']['start'], wait['data']['end']), (1794260001, 1794261013))

    def test_night_decision_between_the_ends_of_the_two_windows_joins_the_merged_incident(self):
        home = self.real_home()
        path = os.path.join(home, 'ao-visual-design', 'night-decisions.log')
        stamp = time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(1794261000))   # after the task window, before the epic's
        with open(path, 'a', encoding='utf-8') as f:
            f.write(stamp + ' epic resume: decyzja po B1\n')
        incidents, facts = retro.ao_incidents(self.epic(home, 'visual-design'))
        wait = {i['id']: i for i in incidents}['visual-design/wait/VISUAL-DESIGN-INTEGRATION-1@1794260001']
        self.assertEqual([f['text'] for f in wait['facts'] if f['source'] == 'night-decisions.log'],
                         ['epic resume: decyzja po B1'])
        self.assertFalse([f for f in facts if '/night/' in f['id']])

    def test_epic_block_in_the_same_second_that_does_not_name_the_task_stays_separate(self):
        events = [
            {'type': 'status_changed', 'task_id': 'T-1', 'epic_id': 'sep', 'at': 1794000000.2,
             'data': {'to': 'BLOCKED', 'reason': 'executor', 'blockers': ['Q?']}},
            {'type': 'epic_blocked', 'task_id': None, 'epic_id': 'sep', 'at': 1794000000.9,
             'data': {'reason': 'max_qa_rounds: rundy: 3'}},
            {'type': 'human_action', 'task_id': 'T-1', 'epic_id': None, 'at': 1794000100.0,
             'data': {'action': 'answer'}},
            {'type': 'human_action', 'task_id': None, 'epic_id': None, 'at': 1794000200.0,
             'data': {'action': 'epic-resume'}}]
        build_run(self.ao_home, 'sep', 'cart.sql', '\n'.join(json.dumps(e) for e in events) + '\n',
                  extra_sql="UPDATE epics SET epic_id='sep';")
        incidents, _ = retro.ao_incidents(self.epic(self.ao_home, 'sep'))
        self.assertEqual(sorted(i['id'] for i in incidents if i['kind'] == 'wait'),
                         ['sep/wait/T-1@1794000000', 'sep/wait/sep@1794000000'])

    def test_a_start_inside_an_open_window_of_the_same_key_is_a_fact_of_that_wait(self):
        events = [
            {'type': 'status_changed', 'task_id': 'T-1', 'epic_id': 'abs', 'at': 100.0,
             'data': {'to': 'BLOCKED', 'reason': 'executor', 'blockers': ['Pierwsze pytanie?']}},
            {'type': 'status_changed', 'task_id': 'T-1', 'epic_id': 'abs', 'at': 200.0,
             'data': {'to': 'BLOCKED', 'reason': 'policy', 'blockers': ['Drugie pytanie?']}},
            {'type': 'human_action', 'task_id': 'T-1', 'epic_id': None, 'at': 300.0, 'data': {'action': 'answer'}},
            {'type': 'status_changed', 'task_id': 'T-1', 'epic_id': 'abs', 'at': 400.0,
             'data': {'to': 'FAILED', 'reason': 'executor_reported'}}]
        build_run(self.ao_home, 'abs', 'cart.sql', '\n'.join(json.dumps(e) for e in events) + '\n',
                  extra_sql="UPDATE epics SET epic_id='abs';")
        incidents, _ = retro.ao_incidents(self.epic(self.ao_home, 'abs'))
        waits = {i['id']: i for i in incidents if i['kind'] == 'wait'}
        self.assertEqual(sorted(waits), ['abs/wait/T-1@100', 'abs/wait/T-1@400'])   # the start at 400 follows the answer
        first = waits['abs/wait/T-1@100']
        texts = [f['text'] for f in first['facts']]
        self.assertTrue([t for t in texts if 'Drugie pytanie?' in t], texts)
        self.assertTrue([t for t in texts if 'policy' in t], texts)
        self.assertEqual(first['data']['reason'], 'executor')   # the window keeps its own start
        self.assertEqual([(a['at'], a['reason']) for a in first['data']['absorbed']], [(200, 'policy')])
        self.assertEqual(waits['abs/wait/T-1@400']['data']['absorbed'], [])

    def test_garbage_lines_in_events_and_night_log_are_skipped(self):
        home = self.real_home()
        path = os.path.join(home, 'ao-visual-design', 'events.jsonl')
        with open(path, 'a', encoding='utf-8') as f:
            f.write('not json\n[1, 2]\n{"type": "status_changed", "at": "x"}\n')
        with open(os.path.join(home, 'ao-visual-design', 'night-decisions.log'), 'a', encoding='utf-8') as f:
            f.write('garbage line\n2026-13-45 99:99:99 not a time\n')
        incidents, facts = retro.ao_incidents(self.epic(home, 'visual-design'))
        self.assertEqual(len([i for i in incidents if i['kind'] == 'wait']), 6)



class AbsorbedStartsTest(TempDirCase):
    """Real events of PROMOTIONS: no human_action was recorded, so every wait stays open and later starts of the same
    key (epic_blocked max_qa_rounds, a second BLOCKED of PROMOTIONS-REPAIR-1, queue_held) fall into open windows."""

    def incidents(self):
        build_run(self.ao_home, 'promotions', 'promotions.sql', fixture('promotions.events.jsonl'))
        epic = [e for e in retro.discover_epics(self.ao_home) if e['id'] == 'promotions'][0]
        return retro.ao_incidents(epic)[0]

    def test_waits_stay_two_open_windows_and_keep_their_own_starts(self):
        waits = [i for i in self.incidents() if i['kind'] == 'wait']
        self.assertEqual(sorted(i['id'] for i in waits), ['promotions/wait/PROMOTIONS-REPAIR-1@1794129534',
                                                          'promotions/wait/promotions@1794090349'])
        self.assertTrue(all(i['data']['open'] for i in waits))

    def test_max_qa_rounds_block_inside_the_open_epic_window_is_a_fact(self):
        wait = {i['id']: i for i in self.incidents()}['promotions/wait/promotions@1794090349']
        texts = [f['text'] for f in wait['facts']]
        self.assertTrue([t for t in texts if 'max_qa_rounds' in t], texts)
        self.assertEqual(sorted(a['event'] for a in wait['data']['absorbed']), ['epic_blocked', 'queue_held'])
        self.assertEqual(sorted(a['at'] for a in wait['data']['absorbed']), [1794136278, 1794158928])

    def test_second_block_of_the_task_is_a_fact_with_the_failed_attempt_just_before_it(self):
        incidents = self.incidents()
        wait = {i['id']: i for i in incidents}['promotions/wait/PROMOTIONS-REPAIR-1@1794129534']
        self.assertEqual([(a['at'], a['reason']) for a in wait['data']['absorbed']], [(1794133282, 'repeated_failure')])
        attempts = [f['text'] for f in wait['facts'] if f['source'] == 'orchestrator.db']
        self.assertEqual(len(attempts), 1)
        self.assertIn('PROMOTIONS-REPAIR-1#3', attempts[0])
        failed = [i['id'] for i in incidents if i['kind'] == 'failed_attempt']
        self.assertEqual(failed, ['promotions/failed_attempt/PROMOTIONS-REPAIR-1#2'])   # #3 belongs to the wait


class FindingIncidentsTest(unittest.TestCase):
    def test_real_visual_design_report_has_four_important_findings(self):
        incidents = retro.finding_incidents('visual-design', fixture('visual-design.report.md'))
        self.assertEqual(len(incidents), 4)   # F1, F7, F8, F9; the Minor ones are not incidents
        first = incidents[0]
        self.assertTrue(first['id'].startswith('visual-design/finding/'))
        self.assertEqual(first['kind'], 'finding')
        self.assertEqual(first['data']['severity'], 'Important')
        self.assertEqual(first['data']['rounds'], [1])
        self.assertEqual(first['data']['task'], 'VISUAL-DESIGN-5')
        self.assertEqual(first['data']['finding_ids'], ['F1'])
        self.assertEqual(first['data']['files'], ['apps/store/src/account/AccountMenu.tsx',
                                                  'apps/store/src/catalog/MegaMenu.tsx',
                                                  'apps/store/src/catalog/SiteHeader.tsx'])
        self.assertIn('nagłówek desktopowy', first['data']['title'])
        last = incidents[-1]
        self.assertEqual(last['data']['rounds'], [2])
        self.assertEqual(last['data']['finding_ids'], ['F9'])

    def test_real_discount_codes_report_has_three_findings_in_two_rounds(self):
        incidents = retro.finding_incidents('discount-codes', fixture('discount-codes.report.md'))
        self.assertEqual([i['data']['finding_ids'] for i in incidents], [['F1'], ['F2'], ['U1']])
        self.assertEqual([i['data']['rounds'] for i in incidents], [[1], [1], [2]])

    def test_same_fingerprint_in_several_rounds_is_one_incident_even_with_other_backticks(self):
        report = (
            'Znaleziska Critical i Important rundy 1:\n'
            '- F1 [Important] Fokus ginie po zapisie `formularza` (b.vue, a.vue), zadanie T-5\n'
            '- F2 [Critical] Fokus ginie po zapisie formularza (b.vue, a.vue), zadanie T-5\n'
            '\n'
            'Znaleziska Critical i Important rundy 3:\n'
            '- F4 [Important] fokus  ginie po zapisie formularza (a.vue, b.vue), zadanie T-5\n'
            '- F5 [Important] Fokus ginie po zapisie formularza (a.vue), zadanie T-5\n')
        incidents = retro.finding_incidents('epic', report)
        self.assertEqual(len(incidents), 3)   # same title and files in rounds 1 and 3 count once
        merged = [i for i in incidents if i['data']['finding_ids'] == ['F1', 'F4']]
        self.assertEqual(len(merged), 1)
        self.assertEqual(merged[0]['data']['rounds'], [1, 3])
        self.assertEqual(len(merged[0]['facts']), 2)
        self.assertEqual(len({i['id'] for i in incidents}), 3)

    def test_fingerprint_is_severity_title_without_backticks_and_sorted_files(self):
        one = retro.fingerprint('Important', 'Foo `Bar`   baz', ['b', 'a'])
        two = retro.fingerprint('Important', 'foo bar baz', ['a', 'b'])
        self.assertEqual(one, two)
        self.assertNotEqual(one, retro.fingerprint('Critical', 'foo bar baz', ['a', 'b']))
        self.assertNotEqual(one, retro.fingerprint('Important', 'foo bar baz', ['a']))

    def shares_fingerprint_in_integration_result(self, first, second):
        """True when integration_result.check_findings reports the two findings as one fingerprint."""
        path = os.path.join(HERE, '..', 'integration', 'integration_result.py')
        spec_ = importlib.util.spec_from_file_location('integration_result', path)
        module = importlib.util.module_from_spec(spec_)
        spec_.loader.exec_module(module)
        findings = [{'id': 'F%d' % n, 'severity': severity, 'title': title, 'detail': 'd', 'evidence': 'e',
                     'files': files, 'task_id': None} for n, (severity, title, files) in enumerate((first, second), 1)]
        problems = []
        module.check_findings(problems, findings)
        return any('share the fingerprint' in problem for problem in problems)

    def test_fingerprint_agrees_with_integration_result_where_it_does_not_differ(self):
        # the same fingerprint for retro.fingerprint and for integration_result's duplicate check, and the reverse;
        # a change of the recipe in integration_result.py makes this test fail
        cases = [
            (('Important', 'Foo  Bar', ['b', 'a']), ('Important', 'foo bar', ['a', 'b']), True),
            (('Important', 'Foo Bar', ['a']), ('Critical', 'Foo Bar', ['a']), False),
            (('Important', 'Foo Bar', ['a']), ('Important', 'Foo Baz', ['a']), False),
            (('Important', 'Foo Bar', ['a']), ('Important', 'Foo Bar', ['a', 'b']), False),
        ]
        for first, second, same in cases:
            with self.subTest(first=first, second=second):
                self.assertEqual(retro.fingerprint(*first) == retro.fingerprint(*second), same)
                self.assertEqual(self.shares_fingerprint_in_integration_result(first, second), same)

    def test_backticks_are_the_one_difference_from_integration_result(self):
        first, second = ('Important', 'Foo `Bar`', ['a']), ('Important', 'Foo Bar', ['a'])
        self.assertEqual(retro.fingerprint(*first), retro.fingerprint(*second))
        self.assertFalse(self.shares_fingerprint_in_integration_result(first, second))

    def test_title_with_parentheses_and_finding_without_task(self):
        report = ('Znaleziska Critical i Important rundy 1:\n'
                  '- F1 [Important] Błąd (zwany też X) w koszyku (apps/a.ts, apps/b.ts)\n')
        incident = retro.finding_incidents('e', report)[0]
        self.assertEqual(incident['data']['title'], 'Błąd (zwany też X) w koszyku')
        self.assertEqual(incident['data']['files'], ['apps/a.ts', 'apps/b.ts'])
        self.assertIsNone(incident['data']['task'])

    def test_report_without_findings_has_none(self):
        self.assertEqual(retro.finding_incidents('cart', fixture('cart.report.md')), [])
        self.assertEqual(retro.finding_incidents('x', ''), [])

    def test_ids_are_stable(self):
        text = fixture('visual-design.report.md')
        self.assertEqual([i['id'] for i in retro.finding_incidents('visual-design', text)],
                         [i['id'] for i in retro.finding_incidents('visual-design', text)])


class ManualFixTest(unittest.TestCase):
    def incidents(self, body, epic='e'):
        measurement = retro.parse_measurement(body)
        return retro.manual_incidents(epic, measurement), measurement

    def test_old_format_with_a_pr_in_parentheses(self):
        (incidents, facts), measurement = self.incidents(fixture('visual-design.measurement.md'), 'visual-design')
        self.assertEqual([i['id'] for i in incidents], ['visual-design/manual_fix/pr357'])
        self.assertEqual(facts, [])
        self.assertIn('VISUAL-DESIGN-11', incidents[0]['facts'][0]['text'])
        self.assertEqual(incidents[0]['data']['pr'], 357)
        self.assertTrue(incidents[0]['data']['can_be_corrective'])
        self.assertEqual((measurement['interventions'], measurement['waits'], measurement['wait_min'],
                          measurement['manual_count']), (7, 7, 109, 1))

    def test_old_format_with_one_pr_and_one_without(self):
        (incidents, facts), measurement = self.incidents(fixture('discount-codes.measurement.md'), 'discount-codes')
        self.assertEqual([i['id'] for i in incidents],
                         ['discount-codes/manual_fix/other', 'discount-codes/manual_fix/pr330'])
        self.assertEqual(facts, [])
        self.assertEqual((measurement['interventions'], measurement['waits'], measurement['wait_min']), (2, 2, 371))

    def test_zero_manual_fixes_is_no_incident(self):
        (incidents, facts), measurement = self.incidents(fixture('seo.measurement.md'), 'seo')
        self.assertEqual((incidents, facts), ([], []))
        self.assertEqual((measurement['interventions'], measurement['waits'], measurement['wait_min'],
                          measurement['manual_count']), (0, 0, 0, 0))

    def test_new_format_lines_with_and_without_a_pr(self):
        body = ('Pomiar przebiegu AO:\n\n- Interwencje człowieka: 1\n- Ręczne poprawki: 3\n'
                '  - PR #12: synchronizacja z main\n  - PR #13: poprawka joba\n  - ponowienie joba E2E\n'
                '- Praca użytkownika: 5 min\n')
        (incidents, facts), measurement = self.incidents(body)
        self.assertEqual(sorted(i['id'] for i in incidents), ['e/manual_fix/other', 'e/manual_fix/pr12',
                                                              'e/manual_fix/pr13'])
        self.assertEqual(facts, [])
        other = [i for i in incidents if i['key'] == 'other'][0]
        self.assertIn('ponowienie joba E2E', other['facts'][0]['text'])
        self.assertEqual(measurement['manual_count'], 3)

    def test_new_format_unindented_lines_stop_at_the_next_measurement_label(self):
        body = ('- Ręczne poprawki: 2\n- PR #5: pierwsza\n- druga bez PR\n- Praca użytkownika: 5 min\n'
                '- Przebieg: 1 h\n')
        (incidents, facts), _ = self.incidents(body)
        self.assertEqual(sorted(i['key'] for i in incidents), ['other', 'pr5'])
        self.assertEqual(facts, [])

    def test_count_that_differs_from_the_items_is_a_fact_not_an_error(self):
        body = '- Ręczne poprawki: 2\n  - PR #5: pierwsza\n  - PR #6: druga\n  - trzecia\n'
        (incidents, facts), _ = self.incidents(body)
        self.assertEqual(len(incidents), 3)
        self.assertEqual([f['id'] for f in facts], ['e/mismatch/manual_fix'])
        self.assertIn('niezgodna liczba', facts[0]['text'])

    def test_count_without_any_item_is_a_mismatch(self):
        (incidents, facts), _ = self.incidents('- Ręczne poprawki: 2\n- Praca użytkownika: 5 min\n')
        self.assertEqual(incidents, [])
        self.assertEqual([f['id'] for f in facts], ['e/mismatch/manual_fix'])

    def test_items_with_a_zero_count_is_a_mismatch(self):
        (incidents, facts), _ = self.incidents('- Ręczne poprawki: 0\n  - PR #5: x\n')
        self.assertEqual([i['key'] for i in incidents], ['pr5'])
        self.assertEqual([f['id'] for f in facts], ['e/mismatch/manual_fix'])

    def test_old_format_with_more_prs_than_the_count_is_one_unattributed_fix_and_a_mismatch(self):
        # real: "1 (merge main po #239 i nowy końcowy PR #255 ...)": the PRs are mentions, the count says one fix
        (incidents, facts), _ = self.incidents('- Ręczne poprawki: 1 (merge main po #239 i nowy PR #255, bo ruleset)\n')
        self.assertEqual([i['key'] for i in incidents], ['other'])
        self.assertIn('#255', incidents[0]['facts'][0]['text'])
        self.assertIn('niezgodna liczba', facts[0]['text'])
        self.assertNotIn('opisuje 1', facts[0]['text'])   # not "1, a opisuje 1"
        self.assertIn('numerów PR', facts[0]['text'])
        self.assertEqual([f['id'] for f in facts], ['e/mismatch/manual_fix'])

    def test_a_following_measurement_line_is_not_a_manual_fix(self):
        # real: "Ręczne poprawki: 0" followed by "Czas AO: ..." (a label the list of labels does not know)
        (incidents, facts), _ = self.incidents('- Ręczne poprawki: 0\n- Czas AO: 8 h 33 min, 14 prób (1 nieudana)\n')
        self.assertEqual((incidents, facts), ([], []))

    def test_unindented_lines_without_a_pr_are_limited_by_the_count(self):
        body = '- Ręczne poprawki: 1\n- ponowienie joba E2E\n- Zgłoszenia po epiku: #1\n- jeszcze jedno zdanie\n'
        (incidents, facts), _ = self.incidents(body)
        self.assertEqual([i['key'] for i in incidents], ['other'])
        self.assertEqual(incidents[0]['data']['items'], ['ponowienie joba E2E'])
        self.assertEqual(facts, [])

    def test_comment_without_the_line_is_no_measurement(self):
        self.assertIsNone(retro.pick_measurement(['Zgłoszenia: #1', 'inny komentarz']))
        self.assertIsNone(retro.pick_measurement([]))

    def test_the_last_comment_with_the_line_wins(self):
        picked = retro.pick_measurement(['- Ręczne poprawki: 1 (PR #1)', 'x', '- Ręczne poprawki: 0'])
        self.assertEqual(picked['manual_count'], 0)


class SweepTest(unittest.TestCase):
    MERGED_AFTER = retro.STEP_12_AT + 3600
    MERGED_BEFORE = retro.STEP_12_AT - 3600

    def test_each_proposal_of_the_sweep_is_an_escaped_incident(self):
        incidents, facts = retro.sweep_incidents('lighthouse', self.MERGED_AFTER, fixture('lighthouse.sweep.md'))
        self.assertEqual([i['id'] for i in incidents], ['lighthouse/escaped/1', 'lighthouse/escaped/2',
                                                        'lighthouse/escaped/3', 'lighthouse/escaped/4'])
        self.assertEqual(facts, [])
        first = incidents[0]
        self.assertIn('Do koszyka', first['data']['title'])
        self.assertEqual(first['data']['severity'], 'Important')
        self.assertEqual(first['data']['triage'], 'założone #390')
        self.assertEqual(incidents[3]['data']['severity'], 'Minor')
        self.assertEqual(incidents[3]['data']['triage'], 'założone #393')

    def test_triage_rejected_and_undecided(self):
        text = ('## Propozycje\n### 1. Pierwsza\n- Rodzaj: bug\n- Waga: Important\n### 2. Druga\n- Waga: Minor\n'
                '### 3. Trzecia\n- Waga: Minor\n\n## Triage\n- Założone: 1 → #7 (etykiety `bug`).\n'
                '- Odrzucone: 2 (celowe).\n- Bez decyzji: 3.\n')
        incidents, _ = retro.sweep_incidents('e', self.MERGED_AFTER, text)
        self.assertEqual([i['data']['triage'] for i in incidents], ['założone #7', 'odrzucone', 'bez decyzji'])

    def test_sweep_without_a_triage_section_has_no_triage_result(self):
        incidents, _ = retro.sweep_incidents('e', self.MERGED_AFTER, '## Propozycje\n### 1. Jedyna\n- Waga: Minor\n')
        self.assertIsNone(incidents[0]['data']['triage'])

    def test_no_sweep_after_step_12_is_an_incident_and_a_fact(self):
        incidents, facts = retro.sweep_incidents('visual-design', self.MERGED_AFTER, None)
        self.assertEqual([i['id'] for i in incidents], ['visual-design/no_sweep/sweep'])
        self.assertEqual(incidents[0]['data']['merged_at'], int(self.MERGED_AFTER))
        self.assertEqual([f['id'] for f in facts], ['visual-design/missing/sweep'])

    def test_epic_merged_before_step_12_has_only_the_fact(self):
        incidents, facts = retro.sweep_incidents('cart', self.MERGED_BEFORE, None)
        self.assertEqual(incidents, [])
        self.assertEqual([f['id'] for f in facts], ['cart/missing/sweep'])

    def test_sweep_of_an_epic_merged_before_step_12_gives_no_escaped(self):
        incidents, facts = retro.sweep_incidents('cart', self.MERGED_BEFORE, fixture('lighthouse.sweep.md'))
        self.assertEqual((incidents, facts), ([], []))

    def test_epic_not_merged_has_neither_incident_nor_escaped(self):
        incidents, facts = retro.sweep_incidents('x', None, None)
        self.assertEqual(incidents, [])
        self.assertEqual([f['id'] for f in facts], ['x/missing/sweep'])

    def test_sweep_report_is_found_by_the_epic_name_in_the_directory(self):
        home = tempfile.mkdtemp(prefix='retro-sweep-')
        self.addCleanup(shutil.rmtree, home, True)
        for name, text in (('2026-11-09-015c52b', 'no epic'), ('2026-11-10-3c06149-lighthouse', 'light'),
                           ('2026-11-08-aaaaaaa-lighthouse', 'older'), ('2026-11-10-3c06149-lighthouse-2', 'other')):
            os.makedirs(os.path.join(home, name))
            with open(os.path.join(home, name, 'report.md'), 'w') as f:
                f.write(text)
        os.makedirs(os.path.join(home, '2026-11-10-bbbbbbb-seo'))   # a directory without a report
        self.assertEqual(retro.find_sweep_report(home, 'lighthouse'), 'light')
        self.assertIsNone(retro.find_sweep_report(home, 'visual-design'))
        self.assertIsNone(retro.find_sweep_report(home, 'seo'))
        self.assertIsNone(retro.find_sweep_report(os.path.join(home, 'missing'), 'seo'))


class UnaskedTest(unittest.TestCase):
    BODY = ('## Zakres\n\nCoś.\n\n## Decyzje bez pytania\n\n'
            '1. **Kolejność Tab: DOM w kolejności telefonu** (ruling). Odrzucone: zdublowany formularz.\n'
            '   Druga linia pierwszej decyzji.\n'
            '2. **Koszyk i konto jako ikony** poniżej 1280 px.\n\n## Uwagi dla integracji\n\n- coś\n')

    def test_numbered_decisions_are_incidents_keyed_by_pr_and_position(self):
        incidents = retro.unasked_incidents('visual-design', [{'number': 356, 'title': 'VD-5', 'body': self.BODY}])
        self.assertEqual([i['id'] for i in incidents], ['visual-design/unasked/pr356#1',
                                                        'visual-design/unasked/pr356#2'])
        self.assertEqual(incidents[0]['data']['pr'], 356)
        self.assertEqual(incidents[0]['data']['position'], 1)
        self.assertIn('Kolejność Tab', incidents[0]['data']['text'])
        self.assertIn('Druga linia', incidents[0]['data']['text'])
        self.assertNotIn('Koszyk i konto', incidents[0]['data']['text'])

    def test_none_and_missing_sections_give_no_incident(self):
        prs = [{'number': 1, 'body': '## Decyzje bez pytania\n\nBrak.\n'},
               {'number': 2, 'body': '## Zakres\n\nCoś.'}, {'number': 3, 'body': None}, {'number': 4}]
        self.assertEqual(retro.unasked_incidents('e', prs), [])

    def test_bullet_decisions_are_numbered_by_order(self):
        body = '## Decyzje bez pytania\n- pierwsza\n- druga\n'
        incidents = retro.unasked_incidents('e', [{'number': 9, 'body': body}])
        self.assertEqual([i['key'] for i in incidents], ['pr9#1', 'pr9#2'])

    def test_nested_sub_items_are_part_of_the_decision_not_decisions(self):
        # real: PR #355 and #363 of VISUAL-DESIGN have indented "- Odrzucone:" lines under numbered decisions
        body = ('## Decyzje bez pytania\n\n1. **Pierwsza.**\n   - Odrzucone: coś.\n   - Odwracalne: tak.\n'
                '2. **Druga.**\n   1. zagnieżdżona numerowana\n   * zagnieżdżony punktor\n3. **Trzecia.**\n')
        incidents = retro.unasked_incidents('e', [{'number': 7, 'body': body}])
        self.assertEqual([i['key'] for i in incidents], ['pr7#1', 'pr7#2', 'pr7#3'])
        self.assertIn('Odrzucone: coś.', incidents[0]['data']['text'])
        self.assertIn('zagnieżdżony punktor', incidents[1]['data']['text'])

    def test_mixed_list_numbers_bullets_by_order_among_top_level_items(self):
        body = '## Decyzje bez pytania\n1. pierwsza\n- punktor\n3. trzecia\n- drugi punktor\n'
        incidents = retro.unasked_incidents('e', [{'number': 7, 'body': body}])
        self.assertEqual([i['key'] for i in incidents], ['pr7#1', 'pr7#2', 'pr7#3', 'pr7#4'])
        self.assertEqual([i['data']['position'] for i in incidents], [1, 2, 3, 4])

    def test_a_repeated_number_falls_back_to_the_order_so_ids_stay_unique(self):
        body = '## Decyzje bez pytania\n1. a\n2. b\n2. c\n1. d\n7. e\n'
        incidents = retro.unasked_incidents('e', [{'number': 7, 'body': body}])
        keys = [i['key'] for i in incidents]
        self.assertEqual(len(keys), len(set(keys)))
        self.assertEqual(keys[:2], ['pr7#1', 'pr7#2'])
        self.assertEqual(len(keys), 5)

    def test_ids_are_unique_in_a_pr_with_a_real_shaped_description(self):
        body = ('## Decyzje bez pytania\n\n' + ''.join(
            '%d. **Decyzja %d.**\n   - Odrzucone: x.\n   - Odwracalne: y.\n' % (n, n) for n in range(1, 8)))
        keys = [i['id'] for i in retro.unasked_incidents('e', [{'number': 355, 'body': body}])]
        self.assertEqual(len(keys), 7)
        self.assertEqual(len(set(keys)), 7)

    def test_heading_level_and_case_do_not_matter(self):
        body = '### decyzje bez pytania\n1. jedyna\n'
        self.assertEqual([i['key'] for i in retro.unasked_incidents('e', [{'number': 9, 'body': body}])], ['pr9#1'])


class FakeRunner:
    """Stands in for next_epic.make_runner(cwd): answers `gh` and `git` from prepared data and records the calls."""

    def __init__(self, fail=None, bad_json=None):
        self.calls = []
        self.fail = fail
        self.bad_json = bad_json
        self.prs = {
            366: {'state': 'MERGED', 'mergedAt': '2026-11-10T09:00:00Z'},
            329: {'state': 'MERGED', 'mergedAt': '2026-11-09T09:00:00Z'},
            79: {'state': 'MERGED', 'mergedAt': '2026-11-04T03:00:00Z'},
            384: {'state': 'MERGED', 'mergedAt': '2026-11-10T07:00:00Z'},
        }
        self.epic_issues = [
            {'number': 97, 'title': 'VISUAL-DESIGN: projekt wizualny sklepu',
             'comments': [{'body': 'inny komentarz'}, {'body': fixture('visual-design.measurement.md')}]},
            {'number': 225, 'title': 'DISCOUNT-CODES: kody rabatowe w kasie',
             'comments': [{'body': fixture('discount-codes.measurement.md')}]},
            {'number': 88, 'title': 'CART: koszyk', 'comments': []},
            {'number': 289, 'title': 'LIGHTHOUSE: pomiar Core Web Vitals w CI', 'comments': []},
        ]
        self.task_prs = [{'number': 356, 'title': 'VD-5',
                          'body': '## Decyzje bez pytania\n\n1. **Pierwsza**.\n2. **Druga**.\n'},
                         {'number': 357, 'title': 'VD-11', 'body': '## Decyzje bez pytania\n\nBrak.\n'}]
        self.open_prs = []
        self.ao_issues = [{'number': 46, 'title': 'ao epic resume --add-qa-rounds po FINALIZE', 'body': 'x',
                           'labels': []}]
        self.shop_issues = [{'number': 380, 'title': 'tech-debt agent issue', 'body': 'y',
                             'labels': [{'name': 'tech-debt'}, {'name': 'agent'}]}]
        self.agent_issues = [{'number': 1, 'state': 'OPEN'}, {'number': 2, 'state': 'OPEN'},
                             {'number': 3, 'state': 'CLOSED'}, {'number': 4, 'state': 'CLOSED'},
                             {'number': 5, 'state': 'CLOSED'}]

    def __call__(self, args):
        self.calls.append(list(args))
        text = ' '.join(args)
        if self.fail and self.fail in text:
            raise next_epic_module().CommandError(args, 'simulated failure')
        if self.bad_json and self.bad_json in text:
            return 'this is not json'
        if args[:2] == ['git', 'fetch']:
            return ''
        if args[:2] == ['git', 'rev-parse']:
            return 'c0ffee1234567890\n'
        if args[:2] == ['git', 'ls-remote']:
            return 'abc\trefs/heads/epic/hardening\n'
        if args[:2] == ['git', 'ls-tree']:
            return 'docs/features/retro/spec.md\ndocs/features/retro/plan.md\n'
        if args[:2] == ['git', 'show']:
            return '{"schema_version": 1}'
        if args[:3] == ['gh', 'project', 'item-list']:
            return json.dumps({'items': [
                {'content': {'type': 'Issue', 'number': 267, 'title': 'HARDENING: bezpieczeństwo',
                             'repository': 'piotrp321/shop'}, 'labels': ['epic']},
                {'content': {'type': 'Issue', 'number': 400, 'title': 'RETRO: retrospektywa',
                             'repository': 'piotrp321/shop'}, 'labels': ['epic']}]})
        if args[:3] == ['gh', 'issue', 'list']:
            if '--label' in args and 'epic' in args and '--state' in args and args[args.index('--state') + 1] == 'open':
                return json.dumps([{'number': 267, 'title': 'HARDENING: bezpieczeństwo', 'body': ''},
                                   {'number': 400, 'title': 'RETRO: retrospektywa', 'body': ''}])
            if '--label' in args and 'epic' in args:
                return json.dumps(self.epic_issues)
            if 'piotrp321/agentic-orchestrator' in args:
                return json.dumps(self.ao_issues)
            if 'tech-debt' in args:
                return json.dumps(self.shop_issues)
            if 'agent' in args:
                return json.dumps(self.agent_issues)
        if args[:3] == ['gh', 'pr', 'view']:
            return json.dumps(self.prs[int(args[3])])
        if args[:3] == ['gh', 'pr', 'list']:
            if '--base' in args:
                return json.dumps(self.task_prs)
            return json.dumps(self.open_prs)
        raise AssertionError('unexpected command: %s' % text)


def next_epic_module():
    return retro.next_epic


class CollectTest(TempDirCase):
    def setUp(self):
        super().setUp()
        self.out = os.path.join(self.tmp, 'out')
        self.retro_home = os.path.join(self.tmp, 'retro')
        self.sweep_home = os.path.join(self.tmp, 'sweep')
        os.makedirs(self.sweep_home)
        self.home = self.real_home()
        # a finished epic with no report and no events
        build_run(self.ao_home, 'lighthouse', 'cart.sql', extra_sql="UPDATE epics SET epic_id='lighthouse',"
                                                                    " final_pr_number=384;")
        sweep = os.path.join(self.sweep_home, '2026-11-10-3c06149-lighthouse')
        os.makedirs(sweep)
        with open(os.path.join(sweep, 'report.md'), 'w', encoding='utf-8') as f:
            f.write(fixture('lighthouse.sweep.md'))
        self.runner = FakeRunner()

    def argv(self, epic='visual-design', *extra):
        return ['collect', epic, '--out', self.out, '--ao-home', self.ao_home, '--retro-home', self.retro_home,
                '--sweep-home', self.sweep_home, '--repo', self.tmp, *extra]

    def collect(self, epic='visual-design', runner=None, *extra):
        with redirect_stdout(io.StringIO()):
            return retro.main(self.argv(epic, *extra), run=runner or self.runner)

    def load(self):
        with open(os.path.join(self.out, 'input.json'), encoding='utf-8') as f:
            return json.load(f)

    def kinds(self, data, epic):
        counts = {}
        for incident in data['incidents']:
            if incident['epic'] == epic:
                counts[incident['kind']] = counts.get(incident['kind'], 0) + 1
        return counts

    def test_collect_writes_input_json_with_every_incident_kind_of_the_history(self):
        self.assertEqual(self.collect(), 0)
        data = self.load()
        self.assertEqual((data['schema'], data['epic'], data['commit']), (1, 'visual-design', 'c0ffee1234567890'))
        self.assertEqual(self.kinds(data, 'visual-design'), {'wait': 6, 'repair': 1, 'finding': 4, 'manual_fix': 1,
                                                             'no_sweep': 1, 'unasked': 2})
        self.assertEqual(self.kinds(data, 'discount-codes'), {'wait': 2, 'failed_attempt': 1, 'finding': 3,
                                                              'manual_fix': 2})
        self.assertEqual(self.kinds(data, 'lighthouse'), {'escaped': 4})
        self.assertEqual(self.kinds(data, 'cart'), {})
        for incident in data['incidents']:
            self.assertEqual(incident['id'], '%s/%s/%s' % (incident['epic'], incident['kind'], incident['key']))
            self.assertTrue(incident['facts'])

    def test_events_night_log_and_report_of_one_event_are_one_incident(self):
        self.collect()
        data = self.load()
        ids = [i['id'] for i in data['incidents']]
        self.assertEqual(len(ids), len(set(ids)))
        wait = [i for i in data['incidents'] if i['id'] == 'visual-design/wait/visual-design@1794272499']
        self.assertEqual(len(wait), 1)
        self.assertEqual({f['source'] for f in wait[0]['facts']}, {'events.jsonl', 'night-decisions.log'})

    def test_two_runs_give_the_same_ids(self):
        self.collect()
        first = self.load()
        self.collect()
        second = self.load()
        self.assertEqual([i['id'] for i in first['incidents']], [i['id'] for i in second['incidents']])
        for data in (first, second):
            data.pop('generated_at')
        self.assertEqual(first, second)

    def test_missing_sources_are_facts_not_errors(self):
        self.assertEqual(self.collect(), 0)
        facts = {f['id'] for f in self.load()['facts']}
        self.assertIn('cart/missing/events', facts)
        self.assertIn('cart/missing/measurement', facts)
        self.assertIn('cart/missing/sweep', facts)
        self.assertIn('lighthouse/missing/report', facts)
        self.assertIn('lighthouse/missing/events', facts)
        self.assertIn('discount-codes/missing/sweep', facts)
        self.assertNotIn('visual-design/missing/report', facts)   # it has a report
        self.assertNotIn('visual-design/missing/events', facts)
        self.assertIn('visual-design/missing/sweep', facts)
        self.assertNotIn('lighthouse/missing/sweep', facts)

    def test_escaped_and_no_sweep_only_for_epics_merged_after_step_12(self):
        self.collect()
        data = self.load()
        self.assertEqual(self.kinds(data, 'discount-codes').get('no_sweep'), None)   # merged before step 12
        self.assertEqual(self.kinds(data, 'cart').get('no_sweep'), None)
        self.assertEqual(self.kinds(data, 'visual-design')['no_sweep'], 1)

    def test_epic_without_a_measurement_comment_has_no_manual_fix_incidents(self):
        self.collect()
        self.assertEqual(self.kinds(self.load(), 'cart').get('manual_fix'), None)

    def test_unasked_decisions_only_for_the_collected_epic(self):
        self.collect('discount-codes')
        data = self.load()
        self.assertEqual(self.kinds(data, 'discount-codes').get('unasked'), 2)
        self.assertEqual(self.kinds(data, 'visual-design').get('unasked'), None)
        base = [c for c in self.runner.calls if c[:3] == ['gh', 'pr', 'list'] and '--base' in c]
        self.assertEqual(base[-1][base[-1].index('--base') + 1], 'epic/discount-codes')

    def test_status_facts_have_ids(self):
        with unittest_mock_patch(retro, 'RETRO_SINCE', '2026-11-09T00:00:00+00:00'):
            os.makedirs(os.path.join(self.retro_home, 'lighthouse'))
            with open(os.path.join(self.retro_home, 'lighthouse', 'state.json'), 'w') as f:
                json.dump({'state': 'done'}, f)
            # a blocked task in the epic that is running
            con = sqlite3.connect(os.path.join(self.ao_home, 'ao-hardening', 'orchestrator.db'))
            con.execute("UPDATE tasks SET status='BLOCKED', blockers='[\"Pytanie?\"]' WHERE task_id='CART-2'")
            con.commit()
            con.close()
            self.collect()
        status = {f['id']: f for f in self.load()['status']}
        self.assertEqual(sorted(status), ['status/agent-issues', 'status/in-progress', 'status/last-epic',
                                          'status/pending-retro'])
        progress = status['status/in-progress']
        self.assertEqual([(e['id'], e['state']) for e in progress['epics']],
                         [('HARDENING', 'in_progress'), ('RETRO', 'prepared')])
        self.assertEqual(progress['blocked'], [{'epic': 'hardening', 'epic_status': 'ACTIVE', 'tasks': [
            {'task_id': 'CART-2', 'status': 'BLOCKED', 'blockers': ['Pytanie?']}]}])
        last = status['status/last-epic']
        self.assertEqual((last['epic'], last['pr'], last['interventions'], last['waits'], last['wait_min'],
                          last['manual_fixes']), ('visual-design', 366, 7, 7, 109, 1))
        self.assertEqual({k: status['status/agent-issues'][k] for k in ('open', 'closed')}, {'open': 2, 'closed': 3})
        self.assertEqual(status['status/pending-retro']['epics'], ['discount-codes', 'visual-design'])

    def test_pending_retro_without_a_state_and_the_default_since(self):
        with unittest_mock_patch(retro, 'RETRO_SINCE', '2026-11-10T08:00:00+00:00'):
            self.collect()
        status = {f['id']: f for f in self.load()['status']}
        self.assertEqual(status['status/pending-retro']['epics'], ['visual-design'])   # merged 09:00, after 08:00
        with unittest_mock_patch(retro, 'RETRO_SINCE', '2026-11-11T00:00:00+00:00'):
            self.collect()
        status = {f['id']: f for f in self.load()['status']}
        self.assertEqual(status['status/pending-retro']['epics'], [])

    def test_last_epic_without_a_measurement_has_no_numbers(self):
        self.runner.prs[366]['mergedAt'] = '2026-11-01T00:00:00Z'   # now lighthouse (07:00 on the 10th) is the last
        self.collect()
        last = {f['id']: f for f in self.load()['status']}['status/last-epic']
        self.assertEqual(last['epic'], 'lighthouse')
        self.assertIsNone(last['interventions'])
        self.assertIsNone(last['manual_fixes'])

    def test_empty_state_gives_empty_registers_and_no_open_pr(self):
        self.collect()
        data = self.load()
        self.assertEqual(data['registers'], {'observations': [], 'rejected': [], 'applied': [], 'carried': []})
        self.assertIsNone(data['open_retro_pr'])
        self.assertEqual([i['number'] for i in data['open_issues']['ao']], [46])
        self.assertEqual([i['number'] for i in data['open_issues']['shop']], [380])

    def test_registers_and_the_items_of_the_open_retro_pr_are_read(self):
        os.makedirs(os.path.join(self.retro_home, 'cart'))
        for name, rows in (('observations.jsonl', [{'key': 'a', 'title': 'A', 'incidents': ['x'], 'epic': 'cart'}]),
                           ('rejected.jsonl', [{'key': 'b', 'incidents': ['y'], 'reason': 'celowe'}]),
                           ('applied.jsonl', [{'key': 'c', 'incidents': ['z'], 'pr': 5}]),
                           ('carried.jsonl', [{'key': 'd'}])):
            with open(os.path.join(self.retro_home, name), 'w') as f:
                f.write('\n'.join(json.dumps(r) for r in rows) + '\nnot json\n')
        with open(os.path.join(self.retro_home, 'cart', 'state.json'), 'w') as f:
            json.dump({'state': 'done', 'pr': {'number': 77, 'branch': 'retro/cart', 'items': [{'n': 1, 'key': 'a'}]}}, f)
        self.runner.open_prs = [{'number': 77, 'headRefName': 'retro/cart'}, {'number': 8, 'headRefName': 'task/x'}]
        self.collect()
        data = self.load()
        self.assertEqual([r['key'] for r in data['registers']['observations']], ['a'])
        self.assertEqual([r['key'] for r in data['registers']['rejected']], ['b'])
        self.assertEqual([r['key'] for r in data['registers']['applied']], ['c'])
        self.assertEqual([r['key'] for r in data['registers']['carried']], ['d'])
        self.assertEqual(data['open_retro_pr'], {'number': 77, 'branch': 'retro/cart',
                                                 'items': [{'n': 1, 'key': 'a', 'epic': 'cart'}]})

    def test_open_retro_pr_without_a_state_has_no_items(self):
        self.runner.open_prs = [{'number': 77, 'headRefName': 'retro/visual-design'}]
        self.collect()
        self.assertEqual(self.load()['open_retro_pr'], {'number': 77, 'branch': 'retro/visual-design', 'items': []})

    def test_only_git_and_gh_are_run_and_ao_files_do_not_change(self):
        before = snapshot(self.ao_home)
        self.collect()
        self.assertTrue(self.runner.calls)
        self.assertEqual({c[0] for c in self.runner.calls}, {'git', 'gh'})
        self.assertEqual(snapshot(self.ao_home), before)

    def test_output_goes_to_the_retro_home_by_default_and_the_path_is_printed(self):
        out = self.out
        argv = ['collect', 'visual-design', '--ao-home', self.ao_home, '--retro-home', self.retro_home,
                '--sweep-home', self.sweep_home]
        printed = io.StringIO()
        with redirect_stdout(printed):
            self.assertEqual(retro.main(argv, run=self.runner), 0)
        self.assertEqual(printed.getvalue().strip(), os.path.join(self.retro_home, 'visual-design', 'input.json'))
        self.assertTrue(os.path.isfile(os.path.join(self.retro_home, 'visual-design', 'input.json')))
        self.assertFalse(os.path.exists(out))

    def test_failing_gh_or_git_command_is_exit_code_1_and_nothing_is_written(self):
        for fail in ('git fetch', 'git rev-parse', 'gh pr view', 'gh pr list', 'gh issue list --repo piotrp321/shop --label epic --state all',
                     'piotrp321/agentic-orchestrator', 'tech-debt', 'gh project item-list', 'git ls-remote',
                     '--label agent --state all'):
            runner = FakeRunner(fail=fail)
            with self.subTest(fail=fail):
                stderr = io.StringIO()
                with redirect_stderr(stderr):
                    code = retro.main(self.argv(), run=runner)
                self.assertEqual(code, 1)
                self.assertIn('retro:', stderr.getvalue())
                self.assertFalse(os.path.exists(os.path.join(self.out, 'input.json')))

    def test_output_that_is_not_json_is_exit_code_1(self):
        for bad in ('gh pr view', 'gh issue list', 'gh pr list'):
            with self.subTest(bad=bad):
                with redirect_stderr(io.StringIO()):
                    self.assertEqual(retro.main(self.argv(), run=FakeRunner(bad_json=bad)), 1)

    def test_unwritable_output_directory_is_exit_code_1(self):
        blocker = os.path.join(self.tmp, 'file')
        with open(blocker, 'w') as f:
            f.write('x')
        argv = self.argv()
        argv[argv.index('--out') + 1] = os.path.join(blocker, 'sub')
        with redirect_stderr(io.StringIO()):
            self.assertEqual(retro.main(argv, run=self.runner), 1)

    def test_bad_epic_names_and_unknown_epics_are_exit_code_2_before_any_command(self):
        for name in ('Bad', '-x', 'a b', '../x', ''):
            with self.subTest(name=name), redirect_stderr(io.StringIO()):
                runner = FakeRunner()
                try:
                    code = retro.main(self.argv(name), run=runner)
                except SystemExit as e:   # argparse refuses a name that looks like an option
                    code = e.code
                self.assertEqual(code, 2)
                self.assertEqual(runner.calls, [])
        for name in ('night', 'hardening', 'nothing'):
            with self.subTest(name=name), redirect_stderr(io.StringIO()):
                runner = FakeRunner()
                self.assertEqual(retro.main(self.argv(name), run=runner), 2)
                self.assertEqual(runner.calls, [])
        self.assertFalse(os.path.exists(os.path.join(self.out, 'input.json')))

    def test_epic_with_an_unmerged_final_pr_is_collected_without_a_merge_date(self):
        self.runner.prs[366] = {'state': 'OPEN', 'mergedAt': None}
        self.assertEqual(self.collect(), 0)
        data = self.load()
        by_id = {e['id']: e for e in data['epics']}
        self.assertIsNone(by_id['visual-design']['merged_at'])
        self.assertEqual(by_id['visual-design']['pr_state'], 'OPEN')
        self.assertEqual(self.kinds(data, 'visual-design').get('no_sweep'), None)
        self.assertIn('visual-design/missing/merge', {f['id'] for f in data['facts']})

    def test_epics_list_has_dates_and_the_final_pr(self):
        self.collect()
        by_id = {e['id']: e for e in self.load()['epics']}
        self.assertEqual(sorted(by_id), ['cart', 'discount-codes', 'lighthouse', 'visual-design'])
        self.assertEqual(by_id['visual-design']['final_pr'], 366)
        self.assertEqual(by_id['visual-design']['pr_state'], 'MERGED')
        self.assertEqual(by_id['visual-design']['merged_at'], 1794301200)   # 2026-11-10T09:00:00Z
        self.assertAlmostEqual(by_id['visual-design']['created_at'], 1794223198.57, places=1)


class CommandLineTest(TempDirCase):
    """The script as a process, with the real runner, on a directory that is not a repository and a fake `gh`."""

    def run_cli(self, *args, env=None):
        return subprocess.run([sys.executable, '-I', os.path.join(HERE, 'retro.py'), *args], capture_output=True,
                              text=True, env=env)

    def test_git_failure_is_exit_code_1_with_a_message_and_no_file(self):
        build_run(self.ao_home, 'cart', 'cart.sql')
        out = os.path.join(self.tmp, 'out')
        env = dict(os.environ, PATH=os.path.dirname(shutil.which('git')))
        result = self.run_cli('collect', 'cart', '--out', out, '--ao-home', self.ao_home, '--retro-home',
                              os.path.join(self.tmp, 'r'), '--sweep-home', os.path.join(self.tmp, 's'), '--repo',
                              self.tmp, env=env)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn('retro:', result.stderr)
        self.assertEqual(result.stdout, '')
        self.assertFalse(os.path.exists(os.path.join(out, 'input.json')))

    def test_gh_failure_is_exit_code_1(self):
        build_run(self.ao_home, 'cart', 'cart.sql')
        bin_dir = os.path.join(self.tmp, 'bin')
        os.makedirs(bin_dir)
        for name, body in (('git', 'echo c0ffee\n'), ('gh', 'echo "gh: not logged in" >&2\nexit 4\n')):
            path = os.path.join(bin_dir, name)
            with open(path, 'w') as f:
                f.write('#!/bin/sh\n' + body)
            os.chmod(path, 0o755)
        out = os.path.join(self.tmp, 'out')
        result = self.run_cli('collect', 'cart', '--out', out, '--ao-home', self.ao_home, '--retro-home',
                              os.path.join(self.tmp, 'r'), '--sweep-home', os.path.join(self.tmp, 's'), '--repo',
                              self.tmp, env=dict(os.environ, PATH=bin_dir))
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn('not logged in', result.stderr)
        self.assertFalse(os.path.exists(os.path.join(out, 'input.json')))

    def test_no_command_and_bad_arguments_are_exit_code_2(self):
        self.assertEqual(self.run_cli().returncode, 2)
        self.assertEqual(self.run_cli('collect').returncode, 2)
        self.assertEqual(self.run_cli('nothing').returncode, 2)


class RegistersCase(TempDirCase):
    """A retro home in a temporary directory and the command line of retro.py with a fake runner."""

    def setUp(self):
        super().setUp()
        self.retro_home = os.path.join(self.tmp, 'retro')
        self.runner = FakeRunner()

    def cli(self, *args, runner=None, repo=None):
        out, err = io.StringIO(), io.StringIO()
        argv = list(args) + ['--retro-home', self.retro_home, '--ao-home', self.ao_home, '--repo', repo or self.tmp]
        with redirect_stdout(out), redirect_stderr(err):
            try:
                code = retro.main(argv, run=runner or self.runner)
            except SystemExit as e:
                code = e.code
        return code, out.getvalue(), err.getvalue()

    def state(self, epic):
        with open(os.path.join(self.retro_home, epic, 'state.json'), encoding='utf-8') as f:
            return json.load(f)

    def write_state(self, epic, value):
        retro.write_json(os.path.join(self.retro_home, epic), 'state.json', value)

    def lock_path(self):
        return os.path.join(self.retro_home, 'lock')

    def write_lock(self, run_id, epic, age_s):
        os.makedirs(self.retro_home, exist_ok=True)
        with open(self.lock_path(), 'w') as f:
            json.dump({'run_id': run_id, 'epic': epic, 'at': int(time.time()) - age_s}, f)

    def rows(self, name):
        return retro.read_jsonl(os.path.join(self.retro_home, name + '.jsonl'))

    def write_rows(self, name, rows):
        os.makedirs(self.retro_home, exist_ok=True)
        with open(os.path.join(self.retro_home, name + '.jsonl'), 'w', encoding='utf-8') as f:
            for row in rows:
                f.write(json.dumps(row) + '\n')


class LockTest(RegistersCase):
    def test_lock_creates_the_lock_with_the_run_id_and_marks_the_epic_running(self):
        code, out, err = self.cli('lock', 'visual-design', '--run-id', 'r1')
        self.assertEqual(code, 0, err)
        result = json.loads(out)
        self.assertEqual((result['run_id'], result['epic']), ('r1', 'visual-design'))
        with open(self.lock_path()) as f:
            lock = json.load(f)
        self.assertEqual((lock['run_id'], lock['epic']), ('r1', 'visual-design'))
        self.assertAlmostEqual(lock['at'], time.time(), delta=5)
        self.assertEqual(self.state('visual-design')['state'], 'running')

    def test_lock_without_a_run_id_generates_and_prints_one(self):
        code, out, _ = self.cli('lock', 'cart')
        self.assertEqual(code, 0)
        run_id = json.loads(out)['run_id']
        self.assertTrue(run_id)
        with open(self.lock_path()) as f:
            self.assertEqual(json.load(f)['run_id'], run_id)

    def test_run_id_may_come_from_the_environment(self):
        with mock.patch.dict(os.environ, {'RETRO_RUN_ID': 'from-env'}):
            code, out, _ = self.cli('lock', 'cart')
        self.assertEqual((code, json.loads(out)['run_id']), (0, 'from-env'))

    def test_a_fresh_lock_of_another_run_is_refused_with_code_2_and_stays(self):
        self.write_lock('other', 'discount-codes', 3600)
        code, out, err = self.cli('lock', 'visual-design', '--run-id', 'mine')
        self.assertEqual(code, 2)
        self.assertIn('other', err)
        with open(self.lock_path()) as f:
            self.assertEqual(json.load(f)['run_id'], 'other')
        self.assertFalse(os.path.exists(os.path.join(self.retro_home, 'visual-design', 'state.json')))

    def test_a_lock_older_than_six_hours_is_taken_over_with_a_warning(self):
        self.write_lock('other', 'discount-codes', 6 * 3600 + 60)
        code, out, err = self.cli('lock', 'visual-design', '--run-id', 'mine')
        self.assertEqual(code, 0, err)
        result = json.loads(out)
        self.assertIn('other', result['warning'])
        self.assertIn('discount-codes', result['warning'])
        with open(self.lock_path()) as f:
            self.assertEqual(json.load(f)['run_id'], 'mine')

    def test_a_lock_just_under_six_hours_is_still_fresh(self):
        self.write_lock('other', 'x', 6 * 3600 - 60)
        self.assertEqual(self.cli('lock', 'cart', '--run-id', 'mine')[0], 2)

    def test_the_same_run_may_lock_again_without_a_warning(self):
        self.cli('lock', 'cart', '--run-id', 'mine')
        code, out, _ = self.cli('lock', 'cart', '--run-id', 'mine')
        self.assertEqual(code, 0)
        self.assertIsNone(json.loads(out).get('warning'))

    def test_an_unreadable_lock_is_taken_over_with_a_warning(self):
        os.makedirs(self.retro_home)
        with open(self.lock_path(), 'w') as f:
            f.write('not json')
        code, out, _ = self.cli('lock', 'cart', '--run-id', 'mine')
        self.assertEqual(code, 0)
        self.assertTrue(json.loads(out)['warning'])

    def test_lock_refuses_a_bad_epic_name_and_a_finished_retro_without_writing(self):
        for name in ('Bad', 'a b', '../x'):
            self.assertEqual(self.cli('lock', name)[0], 2, name)
        self.write_state('cart', {'state': 'done'})
        self.write_state('seo', {'state': 'skipped', 'reason': 'x'})
        for name in ('cart', 'seo'):
            self.assertEqual(self.cli('lock', name)[0], 2, name)
        self.assertFalse(os.path.exists(self.lock_path()))
        self.assertEqual(self.state('cart')['state'], 'done')

    def test_lock_keeps_the_other_fields_of_the_state(self):
        self.write_state('cart', {'deferrals': [{'at': 1, 'reason': 'bateria'}], 'pr': {'number': 5}})
        self.cli('lock', 'cart', '--run-id', 'mine')
        state = self.state('cart')
        self.assertEqual(state['state'], 'running')
        self.assertEqual(state['deferrals'], [{'at': 1, 'reason': 'bateria'}])
        self.assertEqual(state['pr'], {'number': 5})

    def test_unlock_removes_only_its_own_lock(self):
        self.cli('lock', 'cart', '--run-id', 'mine')
        code, _, err = self.cli('unlock', '--run-id', 'someone-else')
        self.assertEqual(code, 2)
        self.assertTrue(os.path.exists(self.lock_path()))
        self.assertEqual(self.cli('unlock', '--run-id', 'mine')[0], 0)
        self.assertFalse(os.path.exists(self.lock_path()))

    def test_unlock_without_a_lock_is_not_an_error(self):
        self.assertEqual(self.cli('unlock', '--run-id', 'mine')[0], 0)

    def test_unlock_needs_a_run_id(self):
        self.cli('lock', 'cart', '--run-id', 'mine')
        self.assertEqual(self.cli('unlock')[0], 2)
        self.assertTrue(os.path.exists(self.lock_path()))

    def test_unlock_done_marks_the_epic_done_and_without_it_a_running_state_is_cleared(self):
        self.cli('lock', 'cart', '--run-id', 'mine')
        self.write_state('cart', dict(self.state('cart'), decisions_open=[{'n': 1}]))
        self.assertEqual(self.cli('unlock', '--run-id', 'mine', '--done')[0], 0)
        state = self.state('cart')
        self.assertEqual(state['state'], 'done')
        self.assertEqual(state['decisions_open'], [{'n': 1}])
        self.cli('lock', 'discount-codes', '--run-id', 'mine')
        self.assertEqual(self.cli('unlock', '--run-id', 'mine')[0], 0)
        self.assertNotIn('state', self.state('discount-codes'))

    def test_unlock_done_of_a_foreign_lock_changes_nothing(self):
        self.write_lock('other', 'cart', 60)
        self.assertEqual(self.cli('unlock', '--run-id', 'mine', '--done')[0], 2)
        self.assertFalse(os.path.exists(os.path.join(self.retro_home, 'cart', 'state.json')))

    def test_unwritable_retro_home_is_exit_code_1(self):
        blocker = os.path.join(self.tmp, 'file')
        with open(blocker, 'w') as f:
            f.write('x')
        self.retro_home = os.path.join(blocker, 'sub')
        self.assertEqual(self.cli('lock', 'cart', '--run-id', 'mine')[0], 1)


class DeferSkipTest(RegistersCase):
    def test_defer_records_the_attempt_and_the_reason_and_keeps_other_fields(self):
        self.write_state('visual-design', {'pr': {'number': 9, 'items': []}, 'decisions_open': [{'n': 1}]})
        code, _, err = self.cli('defer', 'visual-design', '--reason', 'bateria')
        self.assertEqual(code, 0, err)
        self.cli('defer', 'visual-design', '--reason', 'limit 85%')
        state = self.state('visual-design')
        self.assertEqual([d['reason'] for d in state['deferrals']], ['bateria', 'limit 85%'])
        self.assertAlmostEqual(state['deferrals'][0]['at'], time.time(), delta=5)
        self.assertEqual(state['pr'], {'number': 9, 'items': []})
        self.assertEqual(state['decisions_open'], [{'n': 1}])
        self.assertNotIn(state.get('state'), ('done', 'skipped'))

    def test_defer_cuts_the_reason_to_200_characters(self):
        self.cli('defer', 'cart', '--reason', 'x' * 500)
        self.assertEqual(len(self.state('cart')['deferrals'][0]['reason']), 200)

    def test_defer_and_skip_need_a_reason(self):
        for command in ('defer', 'skip'):
            self.assertEqual(self.cli(command, 'cart')[0], 2)
            self.assertEqual(self.cli(command, 'cart', '--reason', '   ')[0], 2)
        self.assertFalse(os.path.exists(os.path.join(self.retro_home, 'cart')))

    def test_skip_marks_the_epic_skipped_with_the_reason(self):
        self.write_state('cart', {'deferrals': [{'at': 1, 'reason': 'bateria'}]})
        code, _, err = self.cli('skip', 'cart', '--reason', 'katalog nie jest epikiem')
        self.assertEqual(code, 0, err)
        state = self.state('cart')
        self.assertEqual((state['state'], state['reason']), ('skipped', 'katalog nie jest epikiem'))
        self.assertAlmostEqual(state['skipped_at'], time.time(), delta=5)
        self.assertEqual(len(state['deferrals']), 1)

    def test_defer_and_skip_are_refused_for_done_and_skipped_without_changes(self):
        self.write_state('a', {'state': 'done'})
        self.write_state('b', {'state': 'skipped', 'reason': 'x'})
        for epic in ('a', 'b'):
            for command in ('defer', 'skip'):
                before = self.state(epic)
                code, _, err = self.cli(command, epic, '--reason', 'powód')
                self.assertEqual(code, 2, (epic, command))
                self.assertIn(epic, err)
                self.assertEqual(self.state(epic), before)

    def test_bad_epic_names_are_refused_without_writing(self):
        for command in ('defer', 'skip'):
            for name in ('Bad', '-x', 'a b', '../x'):
                code = self.cli(command, name, '--reason', 'powód')[0]
                self.assertEqual(code, 2, (command, name))
        self.assertFalse(os.path.exists(self.retro_home))


class PendingTest(RegistersCase):
    def setUp(self):
        super().setUp()
        self.real_home()
        build_run(self.ao_home, 'lighthouse', 'cart.sql', extra_sql="UPDATE epics SET epic_id='lighthouse',"
                                                                    " final_pr_number=384;")

    def pending(self, *args, runner=None):
        code, out, err = self.cli('pending', *args, runner=runner)
        self.assertEqual(code, 0, err)
        return json.loads(out)

    def test_pending_lists_epics_merged_after_retro_since_from_the_oldest_merge(self):
        with mock.patch.object(retro, 'RETRO_SINCE', '2026-11-01T00:00:00+00:00'):
            result = self.pending()
        self.assertEqual([e['epic'] for e in result], ['cart', 'discount-codes', 'lighthouse', 'visual-design'])
        self.assertEqual(result[0]['merged_at'], 1793761200)   # 2026-11-04T03:00:00Z
        self.assertEqual((result[0]['attempts'], result[0]['reason']), (0, None))

    def test_the_default_since_leaves_only_the_epics_merged_after_it(self):
        self.assertEqual([e['epic'] for e in self.pending()], ['lighthouse', 'visual-design'])

    def test_an_epic_merged_exactly_at_since_is_not_pending(self):
        with mock.patch.object(retro, 'RETRO_SINCE', '2026-11-10T07:00:00+00:00'):
            self.assertEqual([e['epic'] for e in self.pending()], ['visual-design'])

    def test_done_and_skipped_epics_are_not_pending_and_a_deferred_one_stays_with_attempts_and_reason(self):
        self.write_state('lighthouse', {'state': 'done'})
        self.cli('defer', 'visual-design', '--reason', 'bateria')
        self.cli('defer', 'visual-design', '--reason', 'limit 85%')
        result = self.pending()
        self.assertEqual([(e['epic'], e['attempts'], e['reason']) for e in result],
                         [('visual-design', 2, 'limit 85%')])
        self.cli('skip', 'visual-design', '--reason', 'decyzja użytkownika')
        self.assertEqual(self.pending(), [])

    def test_running_epic_is_still_pending(self):
        self.cli('lock', 'visual-design', '--run-id', 'r')
        self.assertIn('visual-design', [e['epic'] for e in self.pending()])

    def test_an_unmerged_final_pr_is_not_pending(self):
        self.runner.prs[366] = {'state': 'OPEN', 'mergedAt': None}
        self.assertEqual([e['epic'] for e in self.pending(runner=self.runner)], ['lighthouse'])

    def test_epics_with_done_or_skipped_retro_cost_no_gh_call(self):
        self.write_state('lighthouse', {'state': 'done'})
        self.write_state('visual-design', {'state': 'skipped', 'reason': 'x'})
        self.pending()
        views = [c for c in self.runner.calls if c[:3] == ['gh', 'pr', 'view']]
        self.assertEqual([c[3] for c in views], ['79', '329'])

    def test_a_failing_gh_is_exit_code_1_and_prints_nothing(self):
        code, out, err = self.cli('pending', runner=FakeRunner(fail='gh pr view'))
        self.assertEqual((code, out), (1, ''))
        self.assertIn('retro:', err)

    def test_no_epics_is_an_empty_list(self):
        shutil.rmtree(self.ao_home)
        os.makedirs(self.ao_home)
        self.assertEqual(self.pending(), [])



PLAN_WITHOUT_MANIFEST = '# Plan\n\n## Status\n\n| Krok | Status |\n|---|---|\n| 12 | w toku |\n'
PLAN_WITH_MANIFEST = '# Plan\n\n```json\n{"schema_version": 1, "tasks": []}\n```\n'


def valid_input():
    """input.json of a run on epic e3: three epics, incidents of every sort, the registers and an open retro PR."""
    def incident(epic, kind, key, can=False, human=None):
        data = {'can_be_corrective': can}
        if human is not None:
            data['human_action'] = human
        return {'id': '%s/%s/%s' % (epic, kind, key), 'epic': epic, 'kind': kind, 'key': key, 'at': None,
                'data': data, 'facts': [{'source': 'x', 'text': 'fact 1'}, {'source': 'y', 'text': 'fact 2'}]}
    return {
        'schema': 1, 'epic': 'e3', 'commit': 'c0ffee',
        'epics': [{'id': 'e1', 'merged_at': 1000, 'created_at': 500.0},
                  {'id': 'e2', 'merged_at': 2000, 'created_at': 1500.0},
                  {'id': 'e3', 'merged_at': 3000, 'created_at': 2500.0}],
        'incidents': [
            incident('e1', 'wait', 'e1@100', True, True), incident('e1', 'wait', 't1@200', True, True),
            incident('e1', 'wait', 't2@300', False, False),
            incident('e1', 'finding', 'aaa'), incident('e1', 'finding', 'bbb'),
            incident('e2', 'repair', 'T1', True), incident('e2', 'finding', 'ccc'), incident('e2', 'finding', 'ddd'),
            incident('e3', 'manual_fix', 'pr9', True), incident('e3', 'finding', 'eee'),
            incident('e3', 'no_sweep', 'sweep'),
        ],
        'facts': [], 'status': [{'id': 'status/in-progress'}, {'id': 'status/last-epic'}],
        'registers': {
            'observations': [],
            'rejected': [{'key': 'rej', 'incidents': ['e1/finding/aaa'], 'reason': 'celowo', 'rejected_at': 1500,
                          'pr': 5}],
            'applied': [{'key': 'app', 'incidents': ['e1/finding/bbb'], 'pr': 7, 'merged_at': 1200}],
            'carried': [{'key': 'car', 'incidents': ['e1/wait/e1@100'], 'pr': 4}]},
        'open_retro_pr': {'number': 11, 'branch': 'retro/e1', 'items': [{'n': 1, 'key': 'open', 'epic': 'e1'}]},
        'open_issues': {'ao': [{'number': 46}], 'shop': [{'number': 380}]},
    }


F1, F2, F3 = 'e1/finding/aaa', 'e2/finding/ccc', 'e3/finding/eee'
W1, W2, W3 = 'e1/wait/e1@100', 'e1/wait/t1@200', 'e1/wait/t2@300'


def fix(n=1, key='k', evidence=(F2, F3), **extra):
    item = {'n': n, 'kind': 'fix', 'key': key, 'title': 'Title',
            'evidence': list(evidence) if isinstance(evidence, tuple) else evidence, 'classes': {},
            'cause': 'cause', 'files': ['.claude/skills/ao-run/SKILL.md'], 'change': 'change',
            'why_prevents': 'why', 'verify': 'verify', 'previously_rejected': None, 'previous_fix': None,
            'updates': None}
    item.update(extra)
    return item


def issue_item(n=1, key='i', evidence=(F2, F3), **extra):
    item = {'n': n, 'kind': 'issue', 'key': key, 'repo': 'ao', 'title': 'Title', 'existing_issue': None,
            'body': 'body', 'evidence': list(evidence) if isinstance(evidence, tuple) else evidence, 'classes': {}, 'cause': 'cause', 'why_prevents': 'why',
            'verify': 'verify', 'duplicate_check': [45, 46]}
    item.update(extra)
    return item


def proposals_of(*items, **extra):
    value = {'schema': 1, 'epic': 'e3', 'commit': 'c0ffee', 'items': list(items), 'observations': [],
             'overflow': []}
    value.update(extra)
    return value


class ValidateTest(RegistersCase):
    def setUp(self):
        super().setUp()
        self.run_dir = os.path.join(self.tmp, 'run')
        self.repo = os.path.join(self.tmp, 'repo')
        os.makedirs(os.path.join(self.repo, 'docs', 'features', 'agentic-software-house'))
        self.plan(PLAN_WITHOUT_MANIFEST)
        self.input = valid_input()

    def plan(self, text):
        path = os.path.join(self.repo, 'docs', 'features', 'agentic-software-house', 'plan.md')
        if text is None:
            if os.path.exists(path):
                os.remove(path)
            return
        with open(path, 'w', encoding='utf-8') as f:
            f.write(text)

    def validate(self, proposals, raw=None):
        retro.write_json(self.run_dir, 'input.json', self.input)
        if raw is not None:
            with open(os.path.join(self.run_dir, 'proposals.json'), 'w', encoding='utf-8') as f:
                f.write(raw)
        elif proposals is not None:
            retro.write_json(self.run_dir, 'proposals.json', proposals)
        code, out, err = self.cli('validate', self.run_dir, repo=self.repo)
        result = json.loads(out)
        self.assertEqual(code, 0 if result['ok'] else 2, (code, err))
        self.assertEqual(result['ok'], not result['errors'])
        return result['errors']

    def codes(self, proposals, **kw):
        return [e['code'] for e in self.validate(proposals, **kw)]

    def assertValid(self, proposals):
        self.assertEqual(self.validate(proposals), [])

    def assertError(self, proposals, code, text=''):
        errors = self.validate(proposals)
        self.assertTrue([e for e in errors if e['code'] == code and text in e['message']], (code, text, errors))
        return errors

    # threshold
    def test_a_fix_and_an_issue_with_two_incidents_pass(self):
        self.assertValid(proposals_of(fix(1), issue_item(2)))

    def test_no_items_is_a_valid_result(self):
        self.assertValid(proposals_of())

    def test_one_incident_cited_twice_is_one_incident_and_does_not_pass(self):
        self.assertError(proposals_of(fix(evidence=[F2, F2])), 'threshold')
        self.assertError(proposals_of(issue_item(evidence=[F2])), 'threshold')

    def test_one_corrective_incident_is_enough(self):
        item = fix(evidence=[W1], classes={W1: {'class': 'corrective', 'corrective_reason': 'AO nie zarejestrowało'}})
        self.assertValid(proposals_of(item))

    def test_corrective_needs_a_reason(self):
        item = fix(evidence=[W1], classes={W1: {'class': 'corrective', 'corrective_reason': ' '}})
        self.assertError(proposals_of(item), 'class', 'corrective_reason')

    def test_corrective_on_an_incident_without_a_human_action_is_an_error(self):
        for incident in (F2, W3, 'e3/no_sweep/sweep'):
            item = fix(evidence=[incident, F3], classes={incident: {'class': 'corrective', 'corrective_reason': 'x'}})
            self.assertError(proposals_of(item), 'class', incident)

    def test_a_human_action_incident_without_a_class_is_an_error(self):
        for incident in (W1, 'e2/repair/T1', 'e3/manual_fix/pr9'):
            self.assertError(proposals_of(fix(evidence=[incident, F3])), 'class', incident)

    def test_a_planned_incident_does_not_count(self):
        planned = {W1: {'class': 'planned'}}
        self.assertError(proposals_of(fix(evidence=[W1, F3], classes=planned)), 'threshold')
        self.assertValid(proposals_of(fix(evidence=[W1, F2, F3], classes=planned)))

    def test_a_product_incident_counts_towards_two_but_does_not_qualify_alone(self):
        product = {W1: {'class': 'product'}}
        self.assertValid(proposals_of(fix(evidence=[W1, F3], classes=product)))
        self.assertError(proposals_of(fix(evidence=[W1], classes=product)), 'threshold')

    def test_a_planned_incident_next_to_a_corrective_one_still_qualifies(self):
        classes = {W1: {'class': 'planned'}, W2: {'class': 'corrective', 'corrective_reason': 'x'}}
        self.assertValid(proposals_of(fix(evidence=[W1, W2], classes=classes)))

    def test_an_unknown_class_is_an_error(self):
        self.assertError(proposals_of(fix(evidence=[W1, F3], classes={W1: {'class': 'maybe'}})), 'class', 'maybe')

    def test_classes_of_incidents_that_cannot_be_corrective_do_not_change_the_count(self):
        self.assertError(proposals_of(fix(evidence=[F2], classes={F2: {'class': 'planned'}})), 'threshold')

    # shape
    def test_required_fields_of_a_fix(self):
        for field in ('key', 'title', 'evidence', 'cause', 'why_prevents', 'verify', 'files', 'change'):
            item = fix()
            del item[field]
            self.assertError(proposals_of(item), 'field', field)
            item[field] = '  ' if field not in ('evidence', 'files') else []
            self.assertError(proposals_of(item), 'field', field)

    def test_required_fields_of_an_issue(self):
        for field in ('key', 'title', 'evidence', 'cause', 'why_prevents', 'verify', 'repo', 'duplicate_check'):
            item = issue_item()
            del item[field]
            self.assertError(proposals_of(item), 'field', field)
        self.assertError(proposals_of(issue_item(repo='other')), 'field', 'repo')
        self.assertError(proposals_of(issue_item(body=None)), 'field', 'body')
        self.assertError(proposals_of(issue_item(body='x', existing_issue=46)), 'field', 'existing_issue')

    def test_fields_of_a_wrong_type_are_errors_not_crashes(self):
        for value in (5, None, [], {}):
            self.assertIn('field', self.codes(proposals_of(fix(title=value))))
        for value in ('x', 5, {}, [1], None):
            self.assertIn('field', self.codes(proposals_of(fix(evidence=value))))
        self.assertIn('field', self.codes(proposals_of(fix(files='x'))))
        self.assertIn('field', self.codes(proposals_of(issue_item(duplicate_check='46'))))

    def test_files_only_from_the_allowed_list(self):
        for path in ('CLAUDE.md', 'apps/api/CLAUDE.md', '.claude/skills/retro/SKILL.md', '.claude/agents/retro-analyst.md',
                     'docs/features/ao-run/spec.md', 'docs/features/prepare-epic/spec.md',
                     'docs/features/groom/spec.md', 'docs/features/agent-tester-ux/spec.md',
                     'docs/features/retro/spec.md'):
            self.assertValid(proposals_of(fix(files=[path])))
        for path in ('apps/api/src/Kernel.php', 'docs/features/cart/spec.md', 'docs/vision.md',
                     '.claude/skills/ao-run/ao_events.py', '.claude/skills/retro/retro.py',
                     'apps/api/x/CLAUDE.md', '.claude/agents/sub/x.md', '../CLAUDE.md', '/CLAUDE.md',
                     'docs/features/../../CLAUDE.md', '.claude/skills/../SKILL.md', 'infra/k8s/README.md',
                     'CLAUDE.md\n', 'docs/features/retro/plan.md', 5):
            self.assertError(proposals_of(fix(files=[path])), 'file')

    def test_unknown_incidents_in_evidence_are_errors(self):
        self.assertError(proposals_of(fix(evidence=[F2, 'e9/finding/zzz'])), 'unknown_incident', 'e9/finding/zzz')

    def test_limits_five_fixes_and_three_issues(self):
        fixes = [fix(n, key='k%d' % n) for n in range(1, 6)]
        self.assertValid(proposals_of(*fixes))
        self.assertError(proposals_of(*fixes, fix(6, key='k6')), 'limit', 'fix')
        issues = [issue_item(n, key='i%d' % n) for n in range(1, 4)]
        self.assertValid(proposals_of(*issues))
        self.assertError(proposals_of(*issues, issue_item(4, key='i4')), 'limit', 'issue')

    def test_comments_on_existing_issues_count_towards_the_issue_limit(self):
        items = [issue_item(n, key='i%d' % n, existing_issue=46, body=None) for n in range(1, 5)]
        self.assertError(proposals_of(*items), 'limit', 'issue')

    def test_status_items_are_not_limited(self):
        statuses = [{'n': n, 'kind': 'status', 'target': 'krok 12', 'change': 'x', 'evidence': ['e3/no_sweep/sweep']}
                    for n in range(1, 9)]
        self.assertValid(proposals_of(*statuses))

    def test_numbers_and_keys_are_unique_and_the_kind_is_known(self):
        self.assertError(proposals_of(fix(1, key='a'), fix(1, key='b')), 'schema', 'n')
        self.assertError(proposals_of(fix(1, key='a'), fix(2, key='a')), 'schema', 'key')
        self.assertError(proposals_of(fix(1, kind='other')), 'schema', 'kind')
        self.assertError(proposals_of('x'), 'schema')

    def test_a_missing_proposals_file_and_invalid_json_are_errors(self):
        self.assertIn('proposals', self.codes(None))
        self.assertIn('proposals', self.codes(None, raw='{not json'))
        self.assertIn('proposals', self.codes(None, raw='[]'))

    def test_wrong_schema_epic_and_commit_are_errors(self):
        self.assertIn('schema', self.codes(proposals_of(schema=2)))
        self.assertIn('schema', self.codes(proposals_of(epic='e1')))
        self.assertIn('schema', self.codes(proposals_of(commit='deadbeef')))
        self.assertIn('schema', self.codes(proposals_of(observations='x')))

    def test_observations_need_a_key_and_a_list_of_incidents(self):
        self.assertValid(proposals_of(observations=[{'key': 'o', 'title': 'T', 'incidents': [F2]}]))
        self.assertIn('schema', self.codes(proposals_of(observations=[{'key': 'o', 'title': 'T'}])))
        self.assertIn('schema', self.codes(proposals_of(observations=['x'])))

    def test_a_missing_input_is_exit_code_2_with_a_message(self):
        code, out, err = self.cli('validate', os.path.join(self.tmp, 'empty'), repo=self.repo)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('input.json', err)

    # open retro PR
    def test_a_key_from_the_open_pr_needs_updates_with_the_number_of_that_item(self):
        self.assertError(proposals_of(fix(key='open')), 'open_pr', 'updates')
        self.assertValid(proposals_of(fix(key='open', updates=1)))
        self.assertError(proposals_of(fix(key='open', updates=2)), 'open_pr', 'updates')
        self.assertError(proposals_of(fix(key='new', updates=1)), 'open_pr', 'updates')

    # rejections
    def test_a_rejected_key_needs_a_new_incident_and_previously_rejected(self):
        why = {'at': 1500, 'reason': 'celowo'}
        self.assertError(proposals_of(fix(key='rej', evidence=[F1, W1, W2],
                                          classes={W1: {'class': 'product'}, W2: {'class': 'product'}},
                                          previously_rejected=why)), 'rejected', 'new incident')
        self.assertError(proposals_of(fix(key='rej', previously_rejected=None)), 'rejected', 'previously_rejected')
        self.assertError(proposals_of(fix(key='rej', previously_rejected={'at': 1500})), 'rejected',
                         'previously_rejected')
        self.assertValid(proposals_of(fix(key='rej', previously_rejected=why)))

    def test_an_incident_rejected_under_another_key_is_an_error(self):
        self.assertError(proposals_of(fix(key='other', evidence=[F1, F2])), 'rejected', 'rej')
        self.assertError(proposals_of(fix(key='other', evidence=[F1, F2], previously_rejected={'at': 1, 'reason': 'x'})),
                         'rejected', 'rej')
        self.assertValid(proposals_of(fix(key='other', evidence=[F2, F3])))

    def test_an_incident_from_an_epic_merged_before_the_rejection_is_not_new(self):
        self.input['registers']['rejected'][0]['rejected_at'] = 2500   # e2 (merged 2000) is older than that
        self.assertError(proposals_of(fix(key='rej', evidence=[F2, 'e2/finding/ddd'],
                                          previously_rejected={'at': 2500, 'reason': 'x'})), 'rejected', 'new incident')
        self.assertValid(proposals_of(fix(key='rej', evidence=[F2, F3], previously_rejected={'at': 2500, 'reason': 'x'})))

    # applied fixes
    def test_an_applied_key_needs_a_new_incident_after_the_merge_and_previous_fix(self):
        self.assertError(proposals_of(fix(key='app', evidence=[F2, F3])), 'applied', 'previous_fix')
        self.assertError(proposals_of(fix(key='app', evidence=['e1/finding/bbb', W1, W2],
                                          classes={W1: {'class': 'product'}, W2: {'class': 'product'}},
                                          previous_fix=7)), 'applied', 'started after')
        self.assertValid(proposals_of(fix(key='app', evidence=[F2, F3], previous_fix=7)))

    def test_previous_fix_must_be_the_pr_of_the_applied_fix(self):
        self.assertError(proposals_of(fix(key='app', previous_fix=8)), 'applied', '7')
        self.assertError(proposals_of(fix(key='app', previous_fix='7')), 'applied', 'previous_fix')

    def test_an_incident_of_an_applied_fix_under_another_key_is_an_error(self):
        self.assertError(proposals_of(fix(key='other', evidence=['e1/finding/bbb', F3])), 'applied', 'app')

    def test_an_epic_started_before_the_merge_of_the_fix_is_not_new(self):
        self.input['registers']['applied'][0]['merged_at'] = 1600   # e2 started at 1500
        self.assertError(proposals_of(fix(key='app', evidence=[F2, 'e2/finding/ddd'], previous_fix=7)), 'applied',
                         'started after')
        self.assertValid(proposals_of(fix(key='app', evidence=[F2, F3], previous_fix=7)))

    # carried
    def test_a_carried_item_returns_without_a_new_incident_but_passes_like_a_new_one(self):
        self.assertValid(proposals_of(fix(key='car', evidence=[W1, W2],
                                          classes={W1: {'class': 'corrective', 'corrective_reason': 'x'},
                                                   W2: {'class': 'planned'}})))
        self.assertError(proposals_of(fix(key='car', evidence=[W1], classes={W1: {'class': 'planned'}})), 'threshold')
        self.assertError(proposals_of(fix(key='car', evidence=[W1])), 'class')

    # issues
    def test_existing_issue_must_be_an_open_issue_of_the_input(self):
        ao = issue_item(existing_issue=46, body=None)
        self.assertValid(proposals_of(ao))
        self.assertError(proposals_of(issue_item(existing_issue=99, body=None)), 'existing_issue', '99')
        self.assertError(proposals_of(issue_item(existing_issue=380, body=None)), 'existing_issue', '380')
        self.assertValid(proposals_of(issue_item(repo='shop', existing_issue=380, body=None)))
        self.assertError(proposals_of(issue_item(existing_issue='46', body=None)), 'existing_issue')

    # status
    def test_status_needs_evidence_that_exists(self):
        ok = {'n': 1, 'kind': 'status', 'target': 'krok 12', 'change': 'x', 'evidence': ['e3/no_sweep/sweep']}
        self.assertValid(proposals_of(ok))
        self.assertValid(proposals_of(dict(ok, evidence=['status/last-epic'])))
        self.assertError(proposals_of(dict(ok, evidence=[])), 'status', 'evidence')
        self.assertError(proposals_of(dict(ok, change='krok 12 zweryfikowany', evidence=[])), 'status', 'zweryfikowan')
        bad = dict(ok)
        del bad['evidence']
        self.assertError(proposals_of(bad), 'status', 'evidence')
        self.assertError(proposals_of(dict(ok, evidence=['status/nothing'])), 'status', 'status/nothing')
        self.assertError(proposals_of(dict(ok, evidence=['e9/wait/x'])), 'status', 'e9/wait/x')
        self.assertError(proposals_of(dict(ok, target='')), 'status', 'target')

    def test_status_needs_a_plan_without_a_manifest(self):
        ok = {'n': 1, 'kind': 'status', 'target': 'krok 12', 'change': 'x', 'evidence': ['e3/no_sweep/sweep']}
        self.plan(PLAN_WITH_MANIFEST)
        self.assertError(proposals_of(ok), 'status', 'manifest')
        self.plan(None)
        self.assertError(proposals_of(ok), 'status', 'plan')
        self.assertValid(proposals_of(fix()))   # no status item: the plan does not matter


if __name__ == '__main__':
    unittest.main()

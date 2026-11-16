"""Tests of task_pairs.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
import sqlite3
import tempfile
import unittest
import unittest.mock

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(HERE, 'task_pairs.py')
spec = importlib.util.spec_from_file_location('task_pairs', SCRIPT)
task_pairs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(task_pairs)

EPIC = 'demo'
PLAN_SHA = 'a' * 40
PLAN_PATH = 'docs/features/demo/plan.md'
T0 = 1_800_000_000.0
NOW = T0 + 100_000

SCHEMA = """
CREATE TABLE epics (epic_id TEXT PRIMARY KEY, plan_path TEXT NOT NULL, plan_sha TEXT NOT NULL, branch TEXT NOT NULL,
                    status TEXT NOT NULL, created_at REAL NOT NULL, updated_at REAL NOT NULL);
CREATE TABLE tasks (task_id TEXT PRIMARY KEY, epic_id TEXT, kind TEXT NOT NULL DEFAULT 'plan', status TEXT,
                    pr_number INTEGER, merged_sha TEXT, fix_count INTEGER NOT NULL DEFAULT 0,
                    parallel_with TEXT NOT NULL DEFAULT '[]', base_sha TEXT, created_at REAL);
CREATE TABLE attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT NOT NULL, number INTEGER NOT NULL,
                       started_at REAL NOT NULL, ended_at REAL, wall_s REAL, outcome TEXT, cost_usd REAL);
CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, at REAL NOT NULL, task_id TEXT, attempt_id INTEGER,
                     type TEXT NOT NULL, data TEXT NOT NULL);
"""


def plan_text(tasks):
    manifest = {'schema_version': 1, 'epic': 'DEMO', 'spec': 'docs/features/demo/spec.md', 'tasks': tasks}
    return '# Plan\n\n```json\n%s\n```\n' % json.dumps(manifest, indent=2)


def task(task_id, paths=None):
    item = {'id': task_id, 'title': task_id, 'scope': 's', 'acceptance_criteria': ['a'], 'risk': 'FAST',
            'depends_on': []}
    if paths is not None:
        item['paths'] = paths
    return item


class FakeRun:
    """Substituted `git`/`gh`: {tuple(args): output or Exception}; anything else fails like a missing commit."""

    def __init__(self, outputs=None):
        self.outputs = dict(outputs or {})
        self.calls = []

    def __call__(self, args):
        self.calls.append(tuple(args))
        value = self.outputs.get(tuple(args))
        if value is None:
            raise task_pairs.next_epic.CommandError(args, 'fatal: bad object')
        if isinstance(value, Exception):
            raise value
        return value


def show(sha=PLAN_SHA, path=PLAN_PATH):
    return ('git', 'show', '%s:%s' % (sha, path))


def commit(run, sha, files, parents=1):
    run.outputs[('git', 'rev-list', '--parents', '-n', '1', sha)] = ' '.join([sha] + ['p%d' % i for i in range(
        parents)]) + '\n'
    run.outputs[('git', 'diff', '--name-only', sha + '^1', sha)] = ''.join(f + '\n' for f in files)


def gh_files(run, number, files):
    run.outputs[('gh', 'pr', 'view', str(number), '--json', 'files')] = json.dumps(
        {'files': [{'path': f, 'additions': 1, 'deletions': 0} for f in files]})


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.home = self.tmp.name
        self.diag = os.path.join(self.home, 'diag')
        self.db = os.path.join(self.home, 'orchestrator.db')
        con = sqlite3.connect(self.db)
        con.executescript(SCHEMA)
        con.execute('INSERT INTO epics VALUES (?, ?, ?, ?, ?, ?, ?)',
                    (EPIC, PLAN_PATH, PLAN_SHA, 'epic/demo', 'ACTIVE', T0, T0 + 10))
        con.commit()
        con.close()
        self.run = FakeRun({('git', 'ls-tree', '-r', '--name-only', PLAN_SHA): 'CLAUDE.md\ninfra/k8s/CLAUDE.md\n'})
        self.set_plan([])

    def tearDown(self):
        self.tmp.cleanup()

    def set_plan(self, tasks, sha=PLAN_SHA):
        self.run.outputs[show(sha)] = plan_text(tasks)

    def sql(self, statement, *params):
        con = sqlite3.connect(self.db)
        con.execute(statement, params)
        con.commit()
        con.close()

    def add_task(self, task_id, kind='plan', pr=None, merged=None, parallel=(), fix_count=0):
        self.sql('INSERT INTO tasks (task_id, epic_id, kind, status, pr_number, merged_sha, fix_count, parallel_with, '
                 'base_sha, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', task_id, EPIC, kind, 'MERGED', pr,
                 merged, fix_count, json.dumps(list(parallel)), 'b' * 40, T0)

    def add_attempt(self, task_id, number, start, end):
        self.sql('INSERT INTO attempts (task_id, number, started_at, ended_at, wall_s, outcome) VALUES (?, ?, ?, ?, ?, '
                 '?)', task_id, number, T0 + start, None if end is None else T0 + end,
                 None if end is None else end - start, None if end is None else 'result')

    def add_event(self, at, event_type, task_id=None, data=None):
        self.sql('INSERT INTO events (at, task_id, type, data) VALUES (?, ?, ?, ?)', T0 + at, task_id, event_type,
                 json.dumps(data or {}))

    def main(self, *extra):
        out, err = io.StringIO(), io.StringIO()
        argv = [EPIC, '--ao-home', self.home, '--repo', self.home, '--setup-diag', self.diag] + list(extra)
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = task_pairs.main(argv, run=self.run, now=NOW)
        self.err = err.getvalue()
        return code, out.getvalue()

    def report(self):
        code, out = self.main()
        self.assertEqual(code, 0, self.err)
        return json.loads(out)

    def audit_task(self, result, task_id):
        return next(t for t in result['audit']['tasks'] if t['task_id'] == task_id)


class AuditTest(Base):
    def test_task_within_its_declaration(self):
        self.set_plan([task('DEMO-1', ['apps/api/src/Foo/', 'docs/features/demo/spec.md'])])
        self.add_task('DEMO-1', pr=11, merged='c1')
        commit(self.run, 'c1', ['apps/api/src/Foo/Bar.php', 'docs/features/demo/spec.md'])
        item = self.audit_task(self.report(), 'DEMO-1')
        self.assertEqual(item['declared'], ['apps/api/src/Foo/', 'docs/features/demo/spec.md'])
        self.assertEqual(item['changed'], ['apps/api/src/Foo/Bar.php', 'docs/features/demo/spec.md'])
        self.assertEqual(item['source'], 'squash')
        self.assertEqual(item['undeclared'], [])

    def test_file_outside_paths_is_undeclared(self):
        self.set_plan([task('DEMO-1', ['apps/api/src/Foo/'])])
        self.add_task('DEMO-1', pr=11, merged='c1')
        commit(self.run, 'c1', ['apps/api/src/Foo/Bar.php', 'CLAUDE.md', 'apps/api/src/foobar.php'])
        self.assertEqual(self.audit_task(self.report(), 'DEMO-1')['undeclared'], ['CLAUDE.md', 'apps/api/src/foobar.php'])

    def test_comparison_ignores_case_by_segments(self):
        self.set_plan([task('DEMO-1', ['Docs/Features/'])])
        self.add_task('DEMO-1', pr=11, merged='c1')
        commit(self.run, 'c1', ['docs/features/demo/spec.md', 'docs/featuresX/a.md'])
        self.assertEqual(self.audit_task(self.report(), 'DEMO-1')['undeclared'], ['docs/featuresX/a.md'])

    def test_task_without_paths(self):
        self.set_plan([task('DEMO-1')])
        self.add_task('DEMO-1', pr=11, merged='c1')
        commit(self.run, 'c1', ['README.md'])
        item = self.audit_task(self.report(), 'DEMO-1')
        self.assertIsNone(item['declared'])
        self.assertEqual(item['changed'], ['README.md'])
        self.assertIsNone(item['undeclared'])

    def test_squash_and_merge_commit_use_the_first_parent_diff(self):
        self.set_plan([task('DEMO-1', ['docs/']), task('DEMO-2', ['docs/'])])
        self.add_task('DEMO-1', pr=11, merged='c1')
        self.add_task('DEMO-2', pr=12, merged='c2')
        commit(self.run, 'c1', ['docs/a.md'], parents=1)
        commit(self.run, 'c2', ['docs/a.md'], parents=2)
        result = self.report()
        first, second = self.audit_task(result, 'DEMO-1'), self.audit_task(result, 'DEMO-2')
        self.assertEqual((first['source'], second['source']), ('squash', 'merge_commit'))
        self.assertEqual(first['changed'], second['changed'])
        self.assertIn(('git', 'diff', '--name-only', 'c2^1', 'c2'), self.run.calls)

    def test_commit_missing_from_history_falls_back_to_gh(self):
        self.set_plan([task('DEMO-1', ['docs/'])])
        self.add_task('DEMO-1', pr=11, merged='gone')
        gh_files(self.run, 11, ['docs/b.md', 'docs/a.md'])
        item = self.audit_task(self.report(), 'DEMO-1')
        self.assertEqual((item['source'], item['changed']), ('gh', ['docs/a.md', 'docs/b.md']))

    def test_task_without_merged_sha_falls_back_to_gh(self):
        self.set_plan([task('DEMO-1', ['docs/'])])
        self.add_task('DEMO-1', pr=11, merged=None)
        gh_files(self.run, 11, ['docs/a.md'])
        self.assertEqual(self.audit_task(self.report(), 'DEMO-1')['source'], 'gh')

    def test_both_sources_fail(self):
        self.set_plan([task('DEMO-1', ['docs/'])])
        self.add_task('DEMO-1', pr=11, merged='gone')
        self.run.outputs[('gh', 'pr', 'view', '11', '--json', 'files')] = 'not json'
        item = self.audit_task(self.report(), 'DEMO-1')
        self.assertEqual((item['changed'], item['source'], item['undeclared']), (None, None, None))

    def test_declaration_comes_from_plan_sha_not_head(self):
        self.set_plan([task('DEMO-1', ['docs/'])])
        self.run.outputs[show('HEAD')] = plan_text([task('DEMO-1', ['apps/'])])
        self.add_task('DEMO-1', pr=11, merged='c1')
        commit(self.run, 'c1', ['apps/api/src/X.php'])
        item = self.audit_task(self.report(), 'DEMO-1')
        self.assertEqual(item['declared'], ['docs/'])
        self.assertEqual(item['undeclared'], ['apps/api/src/X.php'])
        self.assertNotIn(show('HEAD'), self.run.calls)

    def test_only_plan_tasks_with_a_pr_are_audited(self):
        self.set_plan([task('DEMO-1', ['docs/'])])
        self.add_task('DEMO-1', pr=None)
        self.add_task('DEMO-FIX-1', kind='fix', pr=20, merged='c9')
        self.assertEqual(self.report()['audit']['tasks'], [])

    def test_parallel_pair_with_actual_conflict_despite_no_declared_one(self):
        self.set_plan([task('DEMO-1', ['docs/a/']), task('DEMO-2', ['docs/b/']), task('DEMO-3', ['docs/c/'])])
        self.add_task('DEMO-1', pr=11, merged='c1', parallel=['DEMO-2'])
        self.add_task('DEMO-2', pr=12, merged='c2', parallel=['DEMO-1'])
        self.add_task('DEMO-3', pr=13, merged='c3', parallel=['DEMO-1'])   # not mutual: no pair
        commit(self.run, 'c1', ['docs/a/x.md', 'docs/vision.md'])
        commit(self.run, 'c2', ['docs/b/y.md', 'docs/vision.md'])
        commit(self.run, 'c3', ['docs/c/z.md'])
        pairs = self.report()['audit']['pairs']
        self.assertEqual(pairs, [{'tasks': ['DEMO-1', 'DEMO-2'], 'declared_conflicts': [],
                                  'actual_conflicts': ['docs/vision.md']}])

    def test_declared_conflict_counts_tracked_claude_md_at_plan_sha(self):
        self.set_plan([task('DEMO-1', ['infra/k8s/']), task('DEMO-2', ['infra/k8s/'])])
        self.add_task('DEMO-1', pr=11, merged='c1', parallel=['DEMO-2'])
        self.add_task('DEMO-2', pr=12, merged='c2', parallel=['DEMO-1'])
        commit(self.run, 'c1', ['infra/k8s/a.yaml'])
        commit(self.run, 'c2', ['infra/k8s/b.yaml'])
        pair = self.report()['audit']['pairs'][0]
        self.assertEqual((pair['declared_conflicts'], pair['actual_conflicts']), (['infra/k8s/CLAUDE.md'], []))

    def test_pair_conflicts_are_null_without_data(self):
        self.set_plan([task('DEMO-1'), task('DEMO-2', ['docs/'])])
        self.add_task('DEMO-1', pr=11, merged='c1', parallel=['DEMO-2'])
        self.add_task('DEMO-2', pr=12, merged='gone', parallel=['DEMO-1'])
        commit(self.run, 'c1', ['docs/a.md'])
        pair = self.report()['audit']['pairs'][0]
        self.assertEqual((pair['declared_conflicts'], pair['actual_conflicts']), (None, None))

    def test_unreadable_plan_still_reports_everything_else(self):
        self.add_task('DEMO-1', pr=11, merged='c1', parallel=['DEMO-2'])
        self.add_task('DEMO-2', pr=12, merged='c2', parallel=['DEMO-1'])
        commit(self.run, 'c1', ['docs/a.md'])
        commit(self.run, 'c2', ['docs/a.md'])
        self.add_attempt('DEMO-1', 1, 0, 100)
        del self.run.outputs[show()]
        result = self.report()
        audit = result['audit']
        self.assertIsNone(audit['declarations']['value'])
        self.assertIn(PLAN_SHA, audit['declarations']['note'])
        item = self.audit_task(result, 'DEMO-1')
        self.assertEqual((item['declared'], item['undeclared'], item['changed']), (None, None, ['docs/a.md']))
        self.assertEqual(item['declared_note'], task_pairs.NOTE_PLAN_UNREADABLE)
        self.assertEqual(audit['pairs'][0]['declared_conflicts'], None)
        self.assertEqual(audit['pairs'][0]['actual_conflicts'], ['docs/a.md'])
        self.assertEqual(result['concurrency']['attempts_sum_s'], 100)

    def test_unparsable_manifest_still_reports(self):
        self.add_task('DEMO-1', pr=11, merged='c1')
        commit(self.run, 'c1', ['docs/a.md'])
        self.run.outputs[show()] = '# Plan without a block\n'
        result = self.report()
        self.assertIsNone(result['audit']['declarations']['value'])
        self.assertIsNone(self.audit_task(result, 'DEMO-1')['declared'])

    def test_readable_plan_has_declarations_note_null(self):
        self.assertEqual(self.report()['audit']['declarations'], {'value': PLAN_SHA, 'note': None})

    def test_task_missing_from_manifest_differs_from_task_without_paths(self):
        self.set_plan([task('DEMO-1'), task('DEMO-3', ['docs/'])])
        self.add_task('DEMO-1', pr=11, merged='c1')
        self.add_task('DEMO-2', pr=12, merged='c2')
        self.add_task('DEMO-3', pr=13, merged='c3')
        for sha in ('c1', 'c2', 'c3'):
            commit(self.run, sha, ['docs/a.md'])
        result = self.report()
        notes = [self.audit_task(result, t)['declared_note'] for t in ('DEMO-1', 'DEMO-2', 'DEMO-3')]
        self.assertEqual(notes, [task_pairs.NOTE_NO_PATHS, task_pairs.NOTE_NOT_IN_PLAN, None])
        self.assertIsNone(self.audit_task(result, 'DEMO-2')['declared'])


class ConcurrencyTest(Base):
    def concurrency(self):
        return self.report()['concurrency']

    def test_two_attempts_overlapping_30_minutes(self):
        self.add_task('DEMO-1')
        self.add_task('DEMO-2')
        self.add_attempt('DEMO-1', 1, 0, 3600)
        self.add_attempt('DEMO-2', 1, 1800, 5400)
        c = self.concurrency()
        self.assertEqual((c['attempts_sum_s'], c['busy_s'], c['concurrent_s']), (7200, 5400, 1800))
        self.assertEqual(c['pairs'], [{'tasks': ['DEMO-1', 'DEMO-2'], 'overlap_s': 1800}])
        self.assertEqual(c['theoretical_saving'], 0.25)

    def test_three_attempts_with_one_pair(self):
        for name in ('DEMO-1', 'DEMO-2', 'DEMO-3'):
            self.add_task(name)
        self.add_attempt('DEMO-1', 1, 0, 1000)
        self.add_attempt('DEMO-2', 1, 500, 1500)
        self.add_attempt('DEMO-3', 1, 2000, 3000)
        c = self.concurrency()
        self.assertEqual((c['concurrent_s'], c['busy_s'], c['attempts_sum_s']), (500, 2500, 3000))
        self.assertEqual(c['pairs'], [{'tasks': ['DEMO-1', 'DEMO-2'], 'overlap_s': 500}])

    def test_disjoint_attempts(self):
        self.add_task('DEMO-1')
        self.add_task('DEMO-2')
        self.add_attempt('DEMO-1', 1, 0, 1000)
        self.add_attempt('DEMO-1', 2, 1000, 1500)
        self.add_attempt('DEMO-2', 1, 2000, 3000)
        c = self.concurrency()
        self.assertEqual((c['concurrent_s'], c['pairs'], c['theoretical_saving']), (0, [], 0.0))

    def test_open_attempt_counts_to_now(self):
        self.add_task('DEMO-1')
        self.add_task('DEMO-2')
        self.add_attempt('DEMO-1', 1, NOW - T0 - 600, None)
        self.add_attempt('DEMO-2', 1, NOW - T0 - 300, NOW - T0 - 100)
        c = self.concurrency()
        self.assertEqual((c['attempts_sum_s'], c['concurrent_s']), (800, 200))
        self.assertEqual([a['open'] for a in c['attempts']], [True, False])

    def test_triple_overlap_saving_is_exact(self):
        for name in ('DEMO-1', 'DEMO-2', 'DEMO-3'):
            self.add_task(name)
            self.add_attempt(name, 1, 0, 100)
        c = self.concurrency()
        self.assertEqual((c['attempts_sum_s'], c['busy_s'], c['concurrent_s']), (300, 100, 100))
        self.assertEqual(c['theoretical_saving'], 0.667)
        self.assertEqual([p['overlap_s'] for p in c['pairs']], [100, 100, 100])

    def test_open_attempt_of_a_closed_epic_ends_at_the_epic_end(self):
        self.sql("UPDATE epics SET status = 'DONE', updated_at = ?", T0 + 99_999)
        self.add_event(4000, 'epic_done', data={'epic_id': EPIC})
        self.add_task('DEMO-1')
        self.add_attempt('DEMO-1', 1, 1000, None)
        c = self.concurrency()
        self.assertEqual((c['attempts_sum_s'], c['attempts'][0]['open']), (3000, True))

    def test_only_plan_attempts_count(self):
        self.add_task('DEMO-1')
        self.add_task('DEMO-REPAIR-1', kind='repair')
        self.add_attempt('DEMO-1', 1, 0, 1000)
        self.add_attempt('DEMO-REPAIR-1', 1, 0, 1000)
        self.assertEqual(self.concurrency()['concurrent_s'], 0)

    def test_no_attempts(self):
        c = self.concurrency()
        self.assertEqual((c['attempts_sum_s'], c['theoretical_saving']), (0, None))

    def test_epic_wall_and_load_ratio(self):
        self.sql("UPDATE epics SET status = 'DONE', updated_at = ?", T0 + 99_999)
        self.add_event(4000, 'epic_done', data={'epic_id': EPIC})
        self.add_event(3000, 'epic_done', data={'epic_id': 'other'})
        self.add_task('DEMO-1')
        self.add_task('DEMO-2')
        self.add_attempt('DEMO-1', 1, 0, 2000)
        self.add_attempt('DEMO-2', 1, 1000, 3000)
        c = self.concurrency()
        self.assertEqual((c['epic_wall_s'], c['epic_open'], c['attempts_to_wall']), (4000, False, 1.0))
        self.assertNotIn('speedup', json.dumps(c))

    def test_open_epic_runs_to_now(self):
        c = self.concurrency()
        self.assertEqual((c['epic_wall_s'], c['epic_open']), (NOW - T0, True))

    def test_done_epic_without_event_ends_at_its_last_change(self):
        self.sql("UPDATE epics SET status = 'BLOCKED', updated_at = ?", T0 + 500)
        self.assertEqual(self.concurrency()['epic_wall_s'], 500)


class CountsTest(Base):
    def test_conflicts_and_fix_rounds(self):
        self.add_task('DEMO-1')
        self.add_task('DEMO-2')
        self.add_event(1, 'fix_requested', 'DEMO-1', {'reason': 'conflict', 'round': 1})
        self.add_event(2, 'fix_requested', 'DEMO-1', {'reason': 'conflict', 'round': 2})
        self.add_event(3, 'fix_requested', 'DEMO-2', {'reason': 'ci_failed', 'round': 1})
        self.add_event(4, 'fix_requested', 'OTHER-1', {'reason': 'conflict'})
        result = self.report()
        self.assertEqual(result['conflicts'], {'by_task': {'DEMO-1': 2}, 'total': 2})
        self.assertEqual(result['fix_rounds'], {'conflict': 2, 'ci_failed': 1})

    def test_repairs(self):
        self.add_task('DEMO-REPAIR-1', kind='repair')
        self.add_task('DEMO-FIX-1', kind='fix')
        self.add_task('DEMO-FIX-2', kind='fix')
        self.add_attempt('DEMO-REPAIR-1', 1, 0, 10)
        self.add_attempt('DEMO-REPAIR-1', 2, 20, 30)
        self.add_attempt('DEMO-FIX-1', 1, 40, 50)
        self.assertEqual(self.report()['repairs'], {'repair': {'tasks': 1, 'attempts': 2},
                                                    'fix': {'tasks': 2, 'attempts': 1}})

    def test_interventions(self):
        self.add_task('DEMO-1')
        self.add_event(1, 'run_started')
        self.add_event(2, 'answer_recorded', 'DEMO-1')
        self.add_event(3, 'status_changed', 'DEMO-1', {'from': 'FAILED', 'to': 'PENDING', 'reason': 'resumed'})
        self.add_event(4, 'hold_released', 'DEMO-1', {'by': 'operator'})
        self.add_event(5, 'hold_released', 'DEMO-1', {'by': 'recheck'})
        self.add_event(6, 'epic_resumed', data={'epic_id': EPIC})
        self.add_event(7, 'epic_resumed', data={'epic_id': 'other'})
        self.add_event(8, 'config_changed', data={'changed': {}})
        self.add_event(-5, 'config_changed', data={'changed': {}})   # before the epic
        self.add_event(9, 'state_file_edited', 'DEMO-1')
        self.assertEqual(self.report()['interventions'], {'human': 4, 'operational': 2, 'config_changes': 1,
                                                          'state_file_edits': 1})

    def test_operational_interventions_unknown_without_run_started(self):
        self.add_task('DEMO-1')
        self.add_attempt('DEMO-1', 1, 0, 10)
        self.assertIsNone(self.report()['interventions']['operational'])


class WaitsTest(Base):
    def waits(self):
        return self.report()['waits']

    def test_queue_from_pending_to_attempt_for_parallel_tasks(self):
        self.add_task('DEMO-1', parallel=['DEMO-2'])
        self.add_task('DEMO-2', parallel=['DEMO-1'])
        self.add_task('DEMO-3')
        self.add_event(100, 'status_changed', 'DEMO-2', {'from': 'WAITING_DEPS', 'to': 'PENDING'})
        self.add_event(400, 'attempt_started', 'DEMO-2')
        self.add_event(500, 'status_changed', 'DEMO-2', {'from': 'NEEDS_FIX', 'to': 'PENDING'})
        self.add_event(560, 'attempt_started', 'DEMO-2')
        self.add_event(700, 'status_changed', 'DEMO-2', {'from': 'X', 'to': 'PENDING'})   # still waiting
        self.add_event(100, 'status_changed', 'DEMO-3', {'from': 'WAITING_DEPS', 'to': 'PENDING'})
        self.add_event(900, 'attempt_started', 'DEMO-3')
        queue = self.waits()['queue']
        self.assertEqual([(q['task_id'], q['kind'], q['wait_s']) for q in queue['items']],
                         [('DEMO-2', 'first', 300), ('DEMO-2', 'retry', 60)])
        self.assertEqual((queue['count'], queue['total_s'], queue['waiting']), (2, 360, ['DEMO-2']))

    def test_resources_are_always_null_with_a_note(self):
        resources = self.waits()['resources']
        self.assertEqual(resources, {'value': None, 'note': 'AO nie zapisuje czekania na zasoby'})

    def test_no_run_started_makes_event_metrics_null(self):
        self.add_task('DEMO-1')
        self.add_attempt('DEMO-1', 1, 0, 10)
        w = self.waits()
        self.assertEqual((w['cooldowns'], w['host_pauses'], w['usage_warnings']), (None, None, None))

    def test_zero_events_under_a_recording_ao_are_zero(self):
        self.add_task('DEMO-1')
        self.add_event(-1, 'run_started')
        self.add_attempt('DEMO-1', 1, 0, 10)
        w = self.waits()
        self.assertEqual(w['cooldowns'], {'count': 0, 'total_s': 0})
        self.assertEqual((w['host_pauses'], w['usage_warnings']), ({'count': 0, 'total_s': 0}, 0))

    def test_run_started_after_the_first_attempt_is_not_enough(self):
        self.add_task('DEMO-1')
        self.add_attempt('DEMO-1', 1, 0, 10)
        self.add_event(50, 'run_started')
        self.assertIsNone(self.waits()['cooldowns'])

    def test_cooldowns_host_pauses_and_usage_warnings(self):
        self.add_task('DEMO-1')
        self.add_event(-1, 'run_started')
        self.add_attempt('DEMO-1', 1, 0, 10)
        self.add_event(100, 'cooldown_set', 'DEMO-1', {'from': T0 + 100, 'until': T0 + 400})
        self.add_event(200, 'cooldown_released')
        self.add_event(500, 'cooldown_set', None, {'from': T0 + 500, 'until': T0 + 600})
        self.add_event(-900, 'cooldown_set', None, {'from': T0 - 900, 'until': T0 - 800})   # before the epic
        self.add_event(700, 'host_pause', None, {'from': T0 + 650, 'to': T0 + 700, 'seconds': 50})
        self.add_event(710, 'usage_warning', 'DEMO-1', {'window': '5h'})
        self.add_event(-10, 'usage_warning', None, {'window': '5h'})   # before the epic
        w = self.waits()
        self.assertEqual(w['cooldowns'], {'count': 2, 'total_s': 200})
        self.assertEqual(w['host_pauses'], {'count': 1, 'total_s': 50})
        self.assertEqual(w['usage_warnings'], 1)

    def test_setup_failures_of_this_home_inside_the_epic(self):
        os.makedirs(self.diag)
        home = hashlib.sha1(os.path.realpath(self.db).encode()).hexdigest()[:6]
        import time
        inside = time.strftime('%Y%m%d-%H%M%S', time.localtime(T0 + 5))
        before = time.strftime('%Y%m%d-%H%M%S', time.localtime(T0 - 5000))
        for name in ('ao-%s-demo-1-1-%s-1-up-attempt-1.log' % (home, inside),
                     'ao-%s-demo-1-1-%s-1-up-attempt-1.log' % (home, before),
                     'ao-ffffff-other-1-%s-1-up-attempt-1.log' % inside,
                     'sweep-x-%s-1-up-attempt-1.log' % inside):
            open(os.path.join(self.diag, name), 'w').close()
        self.assertEqual(self.waits()['setup_failures'], 1)

    def test_missing_diag_directory_is_zero(self):
        self.assertEqual(self.waits()['setup_failures'], 0)


class DatabaseTest(Base):
    def test_missing_database_is_exit_2(self):
        os.remove(self.db)
        code, out = self.main()
        self.assertEqual((code, out), (2, ''))

    def test_unreadable_database_is_exit_2(self):
        with open(self.db, 'wb') as f:
            f.write(b'this is not a database' * 100)
        code, out = self.main()
        self.assertEqual((code, out), (2, ''))

    def test_unknown_epic_is_exit_2(self):
        self.sql("UPDATE epics SET epic_id = 'other'")
        self.assertEqual(self.main()[0], 2)

    def test_database_is_opened_read_only(self):
        self.add_task('DEMO-1')
        self.add_attempt('DEMO-1', 1, 0, 10)
        with open(self.db, 'rb') as f:
            before = f.read()
        self.report()
        with open(self.db, 'rb') as f:
            self.assertEqual(f.read(), before)
        self.assertEqual(sorted(os.listdir(self.home)), ['orchestrator.db'])
        con = task_pairs.open_readonly(self.db)
        with self.assertRaises(sqlite3.OperationalError):
            con.execute("INSERT INTO events (at, type, data) VALUES (1, 'x', '{}')")
        con.close()

    def test_output_names_the_epic_and_plan(self):
        result = self.report()
        self.assertEqual(result['epic'], {'id': EPIC, 'plan_path': PLAN_PATH, 'plan_sha': PLAN_SHA,
                                          'status': 'ACTIVE'})


class RunnerTest(unittest.TestCase):
    def test_default_runner_has_a_timeout(self):
        with unittest.mock.patch.object(task_pairs.next_epic, 'make_runner') as make:
            task_pairs.default_runner('/repo')
        make.assert_called_once_with('/repo', timeout=task_pairs.COMMAND_TIMEOUT)


if __name__ == '__main__':
    unittest.main()

"""Helpers of the tools/metrics tests: AO run directories from the sanitized fixtures or built synthetically, and a
stubbed `gh`. Not a test module (no tests are collected from it)."""
import contextlib
import io
import json
import os
import sqlite3
import tempfile
import types
import unittest
from unittest import mock

import metrics

HERE = os.path.dirname(os.path.abspath(__file__))
FIXTURES = os.path.join(HERE, 'fixtures', 'ao-data')

SCHEMA = """
CREATE TABLE attempts (id, task_id, number, started_at, ended_at, wall_s, outcome, executor_status, exit_code, cost_usd, duration_ms, num_turns);
CREATE TABLE epics (epic_id, branch, base_sha, plan_sha, status, round, final_pr_number, created_at, updated_at);
CREATE TABLE events (id, at, task_id, attempt_id, type, data);
CREATE TABLE tasks (task_id, base_sha, status, failures, limit_hits, not_before, created_at, updated_at, epic_id, depends_on, kind, mode, target_branch, pr_number, head_sha, merged_sha, ci, fix_count);
"""


def fixture(name):
    return os.path.join(FIXTURES, name)


def fixture_home(root, epic, copy_events=True):
    """`<root>/ao-<epic>` with orchestrator.db built from the SQL dump of the fixture and its events.jsonl."""
    home = os.path.join(root, 'ao-%s' % epic)
    os.makedirs(home)
    con = sqlite3.connect(os.path.join(home, 'orchestrator.db'))
    with open(fixture('%s.sql' % epic), encoding='utf-8') as f:
        con.executescript(f.read())
    con.commit()
    con.close()
    if copy_events:
        with open(fixture('%s.events.jsonl' % epic), encoding='utf-8') as src:
            with open(os.path.join(home, 'events.jsonl'), 'w', encoding='utf-8') as dst:
                dst.write(src.read())
    return home


def synthetic_home(root, epic='demo', status='DONE', created=1000.0, final_pr=7, rounds=1, tasks=(), attempts=(),
                   events=(), jsonl=None):
    """`<root>/ao-<epic>` with a synthetic database. tasks: [(task_id, kind)]; attempts: dicts with task_id, number,
    started_at, ended_at, outcome, executor_status (wall_s defaults to the difference); events: [(at, type, data)] or
    [(at, type, data, task_id)]; jsonl: events.jsonl lines (dicts) or None for no file."""
    home = os.path.join(root, 'ao-%s' % epic)
    os.makedirs(home, exist_ok=True)
    for name in ('orchestrator.db', 'events.jsonl'):
        if os.path.exists(os.path.join(home, name)):
            os.unlink(os.path.join(home, name))     # a test may rebuild the same run
    con = sqlite3.connect(os.path.join(home, 'orchestrator.db'))
    con.executescript(SCHEMA)
    con.execute('INSERT INTO epics VALUES (?,?,?,?,?,?,?,?,?)',
                (epic, 'epic/%s' % epic, 'a' * 40, 'a' * 40, status, rounds, final_pr, created, created))
    for task_id, kind in tasks:
        con.execute('INSERT INTO tasks (task_id, status, epic_id, kind, failures, fix_count) VALUES (?,?,?,?,0,0)',
                    (task_id, 'MERGED', epic, kind))
    for i, a in enumerate(attempts, 1):
        end = a.get('ended_at')
        wall = a.get('wall_s', end - a['started_at'] if end is not None else None)
        con.execute('INSERT INTO attempts VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    (i, a['task_id'], a.get('number', 1), a['started_at'], end, wall, a.get('outcome', 'result'),
                     a.get('executor_status', 'READY_FOR_INTEGRATION'), 0, None, None, None))
    for i, event in enumerate(events, 1):
        at, kind, data = event[:3]
        task = event[3] if len(event) > 3 else None
        con.execute('INSERT INTO events VALUES (?,?,?,?,?,?)', (i, at, task, None, kind, json.dumps(data)))
    con.commit()
    con.close()
    if jsonl is not None:
        with open(os.path.join(home, 'events.jsonl'), 'w', encoding='utf-8') as f:
            for line in jsonl:
                f.write(json.dumps(line) + '\n')
    return home


def result(returncode=0, stdout='', stderr=''):
    return types.SimpleNamespace(returncode=returncode, stdout=stdout, stderr=stderr)


class FakeGh:
    """A stub of the command runner: records every command and answers `gh pr view N` and `gh pr list`.

    prs: {number: {state, mergedAt, closingIssuesReferences}}; sync: the list answered for `gh pr list`;
    fail: when set, every command ends with exit code 1 and that stderr."""

    def __init__(self, prs=None, sync=None, fail=None):
        self.prs = prs or {}
        self.sync = sync or []
        self.fail = fail
        self.calls = []

    def __call__(self, command):
        self.calls.append(list(command))
        if self.fail is not None:
            return result(1, '', self.fail)
        if command[:3] == ['gh', 'pr', 'view']:
            number = int(command[3])
            if number not in self.prs:
                return result(1, '', 'no pull requests found for %d' % number)
            return result(0, json.dumps(self.prs[number]))
        if command[:3] == ['gh', 'pr', 'list']:
            return result(0, json.dumps(self.sync))
        return result(1, '', 'unexpected command %s' % command)


def merged_pr(merged_at='2026-11-13T10:00:00Z', issues=(496,)):
    return {'state': 'MERGED', 'mergedAt': merged_at,
            'closingIssuesReferences': [{'number': n} for n in issues]}


def run_cli(argv, runner=None, now=1800000000.0):
    """(exit code, stdout, stderr) of metrics.main."""
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        code = metrics.main(argv, run=runner, now=now)
    return code, out.getvalue(), err.getvalue()


class Env(unittest.TestCase):
    """A temporary state, night and AO root; `~` points into it, so no test touches the real state of the user."""

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.root = self._tmp.name
        self.state = os.path.join(self.root, 'state')
        self.night = os.path.join(self.root, 'night')
        os.makedirs(self.night)
        patcher = mock.patch.dict(os.environ, {'HOME': self.root})
        patcher.start()
        self.addCleanup(patcher.stop)

    def epic(self, name, *extra, runner=None, home=None, no_gh=True):
        argv = ['epic', name, '--state', self.state, '--night', self.night]
        if home is not None:
            argv += ['--ao-home', home]
        if no_gh:
            argv.append('--no-gh')
        return run_cli(argv + list(extra), runner)

    def record(self, name, *extra, home=None, runner=None, no_gh=True):
        """The record printed by `epic`; the exit code must be 0 (or 3 when a source failed)."""
        code, out, err = self.epic(name, *extra, home=home, runner=runner, no_gh=no_gh)
        self.assertIn(code, (0, 3), err)
        return json.loads(out)

"""Helpers of the tools/metrics tests: AO run directories from the sanitized fixtures or built synthetically, and a
stubbed `gh`. Not a test module (no tests are collected from it)."""
import json
import os
import sqlite3
import types

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

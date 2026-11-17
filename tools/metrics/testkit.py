"""Helpers of the tools/metrics tests: AO run directories from the sanitized fixtures or built synthetically, and a
stubbed `gh`. Not a test module (no tests are collected from it)."""
import contextlib
import io
import json
import os
import shutil
import sqlite3
import tempfile
import types
import unittest
from unittest import mock

import metrics

HERE = os.path.dirname(os.path.abspath(__file__))
FIXTURES = os.path.join(HERE, 'fixtures', 'ao-data')
TRANSCRIPTS = os.path.join(HERE, 'fixtures', 'transcripts')

SCHEMA = """
CREATE TABLE attempts (id, task_id, number, started_at, ended_at, wall_s, outcome, executor_status, exit_code, cost_usd, duration_ms, num_turns);
CREATE TABLE epics (epic_id, branch, base_sha, plan_sha, status, round, final_pr_number, created_at, updated_at);
CREATE TABLE events (id, at, task_id, attempt_id, type, data);
CREATE TABLE tasks (task_id, base_sha, status, failures, limit_hits, not_before, created_at, updated_at, epic_id, depends_on, kind, mode, target_branch, pr_number, head_sha, merged_sha, ci, fix_count);
"""


def fixture(name):
    return os.path.join(FIXTURES, name)


def fixture_home(root, epic, copy_events=True, copy_transcripts=True):
    """`<root>/ao-<epic>` with orchestrator.db built from the SQL dump of the fixture, its events.jsonl and the
    transcripts (`tasks/<id>/attempts/<n>/stdout.jsonl`) of the fixture, when it has them."""
    home = os.path.join(root, 'ao-%s' % epic)
    os.makedirs(home)
    con = sqlite3.connect(os.path.join(home, 'orchestrator.db'))
    with open(fixture('%s.sql' % epic), encoding='utf-8') as f:
        con.executescript(f.read())
    con.commit()
    con.close()
    tasks = os.path.join(TRANSCRIPTS, epic, 'tasks')
    if copy_transcripts and os.path.isdir(tasks):
        shutil.copytree(tasks, os.path.join(home, 'tasks'))
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


def transcript_path(home, task, number):
    return os.path.join(home, 'tasks', task, 'attempts', str(number), 'stdout.jsonl')


def fixture_transcript(epic, task, number):
    """The path of a sanitized transcript of the fixtures."""
    return os.path.join(TRANSCRIPTS, epic, 'tasks', task, 'attempts', str(number), 'stdout.jsonl')


def put_transcript(home, task, number, lines):
    """Writes the transcript of an attempt: `lines` are dicts (one JSON line each) or ready text lines."""
    path = transcript_path(home, task, number)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8') as f:
        for line in lines:
            f.write((line if isinstance(line, str) else json.dumps(line)) + '\n')
    return path


def copy_transcript(home, source, task, number):
    path = transcript_path(home, task, number)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    shutil.copyfile(source, path)
    return path


def read_lines(path):
    with open(path, encoding='utf-8') as f:
        return [json.loads(line) for line in f if line.strip()]


OPUS = 'claude-opus-5-5'
SONNET = 'claude-sonnet-5-5'
HAIKU = 'claude-haiku-5'
T0 = 1800000000.0     # the time of the first line of a synthetic transcript


def stamp(seconds):
    """The `timestamp` of a transcript line `seconds` after T0 (as the CLI writes it: UTC, milliseconds)."""
    from datetime import datetime, timezone
    return datetime.fromtimestamp(T0 + seconds, timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.%f')[:-3] + 'Z'


def usage_of(input=0, output=1, cache_read=0, cache_write=0, cache_write_1h=0):
    """The `usage` of a message: `cache_write` is the 5 minute cache write, `cache_write_1h` the 1 hour one."""
    return {'input_tokens': input, 'output_tokens': output, 'cache_read_input_tokens': cache_read,
            'cache_creation_input_tokens': cache_write + cache_write_1h,
            'cache_creation': {'ephemeral_5m_input_tokens': cache_write, 'ephemeral_1h_input_tokens': cache_write_1h}}


def model_usage(input=0, output=0, cache_read=0, cache_write=0, cache_write_1h=0, cost=0.0, basis='list'):
    return {'inputTokens': input, 'outputTokens': output, 'cacheReadInputTokens': cache_read,
            'cacheCreationInputTokens': cache_write + cache_write_1h,
            'cacheCreationEphemeral5mInputTokens': cache_write, 'cacheCreationEphemeral1hInputTokens': cache_write_1h,
            'costUSD': cost, 'costBasis': basis}


class Transcript:
    """Builds the lines of a synthetic `stdout.jsonl`. Every method returns self; `.lines` are the dicts."""

    def __init__(self):
        self.lines = []
        self._tool = 0
        self._message = 0

    def init(self, model=OPUS, version='2.1.311'):
        self.lines.append({'type': 'system', 'subtype': 'init', 'model': model, 'claude_code_version': version})
        return self

    def message(self, model=OPUS, role=None, parent=None, at=0, message_id=None, blocks=1, tools=(), **tokens):
        """An assistant message written as `blocks` lines with the same message id and usage (as stream-json does).
        role: the `subagent_type` (None: the orchestrator); parent: `parent_tool_use_id`; tools: tool_use blocks
        [(name, input dict)], written one per line; tokens: input, output, cache_read, cache_write, cache_write_1h."""
        self._message += 1
        message_id = message_id or 'msg_%d' % self._message
        found = []
        for name, tool_input in tools:
            self._tool += 1
            found.append('toolu_%d' % self._tool)
            self._append_message(message_id, model, role, parent, at, tokens,
                                 [{'type': 'tool_use', 'id': found[-1], 'name': name, 'input': tool_input}])
        for _ in range(max(0, blocks - len(tools)) if not tools else 0):
            self._append_message(message_id, model, role, parent, at, tokens, [{'type': 'text'}])
        self.last_tools = found
        return self

    def _append_message(self, message_id, model, role, parent, at, tokens, content):
        self.lines.append({'type': 'assistant', 'parent_tool_use_id': parent, 'subagent_type': role,
                           'timestamp': stamp(at),
                           'message': {'id': message_id, 'model': model, 'content': content,
                                       'usage': usage_of(**tokens)}})

    def tool_result(self, tool_id, at, parent=None, is_error=False):
        self.lines.append({'type': 'user', 'parent_tool_use_id': parent, 'timestamp': stamp(at),
                           'message': {'role': 'user', 'content': [{'type': 'tool_result', 'tool_use_id': tool_id,
                                                                    'is_error': is_error}]}})
        return self

    def tool(self, name, tool_input, start, end=None, model=OPUS, role=None, parent=None, **tokens):
        """A message with one tool_use at `start` and, unless end is None, its tool_result at `end`. Returns the
        tool_use id (the transcript is in self)."""
        self.message(model=model, role=role, parent=parent, at=start, tools=[(name, tool_input)], **tokens)
        tool_id = self.last_tools[0]
        if end is not None:
            self.tool_result(tool_id, end, parent=parent)
        return tool_id

    def bash(self, command, start, end=None, background=False, **kwargs):
        return self.tool('Bash', {'command': command, 'run_in_background': background}, start, end, **kwargs)

    def retry(self, delay_ms):
        self.lines.append({'type': 'system', 'subtype': 'api_retry', 'attempt': 1, 'error_status': 503,
                           'max_retries': 9, 'retry_delay_ms': delay_ms})
        return self

    def denied(self, tool_name):
        self.lines.append({'type': 'system', 'subtype': 'permission_denied', 'tool_name': tool_name})
        return self

    def result(self, models, output=0, cost=None, **extra):
        """A `result` line: models {name: model_usage(...)}; output: `usage.output_tokens` of the segment; cost:
        total_cost_usd (default: the sum of the model costs)."""
        total = sum(m['costUSD'] for m in models.values()) if cost is None else cost
        self.lines.append({'type': 'result', 'subtype': 'success', 'is_error': False, 'total_cost_usd': total,
                           'modelUsage': models, 'usage': {'output_tokens': output, 'input_tokens': 0},
                           **extra})
        return self


def result(returncode=0, stdout='', stderr=''):
    return types.SimpleNamespace(returncode=returncode, stdout=stdout, stderr=stderr)


class FakeGh:
    """A stub of the command runner: records every command and answers the read only `gh` calls of metrics.py.

    prs: {number: {state, mergedAt, closingIssuesReferences, mergeCommit}}; sync: the list answered for `gh pr list`;
    final: the list answered for `gh pr list --head epic/<epic>` (the final PR when AO does not know it);
    runs: {commit or branch: [run, ...]} for `gh run list --commit/--branch`; jobs: {run id: [job, ...]} for
    `gh run view`; commits: the list answered for `gh api repos/.../commits` (cut to the `page` and `per_page` of the query); editors: {issue number: login of the last editor} for `gh api graphql` (a number without an entry was never edited); repo_view: the answer of `gh repo view --json owner,name`; issues: {label: [issue, ...]} for
    `gh issue list --label`; permissions: {login: role} for `gh api .../collaborators/<login>/permission` (a login
    without an entry is a non-collaborator: HTTP 404); fail: when set, every command ends with exit code 1 and that
    stderr; fail_on: the command prefixes, e.g. ('run', 'list') or ('api',), that end with that error."""

    def __init__(self, prs=None, sync=None, fail=None, runs=None, jobs=None, commits=None, issues=None,
                 permissions=None, fail_on=(), editors=None, repo_view=None, final=None):
        self.prs = prs or {}
        self.final = final or []
        self.sync = sync or []
        self.fail = fail
        self.runs = runs or {}
        self.jobs = jobs or {}
        self.commits = commits or []
        self.issues = issues or {}
        self.permissions = permissions or {}
        self.editors = editors or {}
        self.repo_view = repo_view or {'owner': {'login': 'acme'}, 'name': 'shop'}
        self.fail_on = tuple(fail_on)
        self.calls = []

    @staticmethod
    def _option(command, name):
        return command[command.index(name) + 1] if name in command else None

    def __call__(self, command):
        self.calls.append(list(command))
        if self.fail is not None:
            return result(1, '', self.fail)
        if any(tuple(command[1:1 + len(entry)]) == tuple(entry) for entry in self.fail_on):
            return result(1, '', 'HTTP 502')
        if command[:3] == ['gh', 'pr', 'view']:
            number = int(command[3])
            if number not in self.prs:
                return result(1, '', 'no pull requests found for %d' % number)
            return result(0, json.dumps(self.prs[number]))
        if command[:3] == ['gh', 'pr', 'list']:
            if (self._option(command, '--head') or '').startswith('epic/'):
                return result(0, json.dumps(self.final))
            return result(0, json.dumps(self.sync))
        if command[:3] == ['gh', 'run', 'list']:
            key = self._option(command, '--commit') or self._option(command, '--branch')
            return result(0, json.dumps(self.runs.get(key, [])))
        if command[:3] == ['gh', 'run', 'view']:
            return result(0, json.dumps({'jobs': self.jobs.get(int(command[3]), [])}))
        if command[:3] == ['gh', 'issue', 'list']:
            return result(0, json.dumps(self.issues.get(self._option(command, '--label'), [])))
        if command[:3] == ['gh', 'repo', 'view']:
            return result(0, json.dumps(self.repo_view))
        if command[:3] == ['gh', 'api', 'graphql']:
            # `gh api` expands the {owner} and {repo} placeholders only in the endpoint and in -F fields, never in -f
            # (raw) fields: a placeholder in a variable reaches GitHub as it is
            if any('{' in a for a in command if a.startswith(('owner=', 'name='))):
                return result(1, '', 'GraphQL: Could not resolve to a Repository with the name \'{owner}/{repo}\'.')
            number = int(next(a.split('=', 1)[1] for a in command if a.startswith('number=')))
            login = self.editors.get(number)
            issue = {'lastEditedAt': None, 'editor': None} if login is None else {
                'lastEditedAt': '2026-11-14T12:00:00Z', 'editor': {'login': login}}
            return result(0, json.dumps({'data': {'repository': {'issue': issue}}}))
        if command[:2] == ['gh', 'api']:
            path = command[2]
            if '/commits' in path:
                query = dict(p.split('=', 1) for p in path.partition('?')[2].split('&') if '=' in p)
                size, page = int(query.get('per_page', 30)), int(query.get('page', 1))
                return result(0, json.dumps(self.commits[(page - 1) * size:page * size]))
            if '/collaborators/' in path:
                login = path.split('/collaborators/')[1].split('/')[0]
                if login not in self.permissions:
                    return result(1, '', 'gh: Not Found (HTTP 404)')
                role = self.permissions[login]
                legacy = {'maintain': 'write', 'triage': 'read'}.get(role, role)
                return result(0, json.dumps({'permission': legacy, 'role_name': role}))
        return result(1, '', 'unexpected command %s' % command)


def merged_pr(merged_at='2026-11-13T10:00:00Z', issues=(496,), sha='f' * 40):
    return {'state': 'MERGED', 'mergedAt': merged_at, 'mergeCommit': {'oid': sha},
            'closingIssuesReferences': [{'number': n} for n in issues]}


WEEKLY = 'Weekly \u00b7 all models'


def fake_record(epic, merged='2026-11-10T10:00:00Z', tasks=10, to_done=36000.0, work=18000.0, human_wait=3600.0,
                review=1800.0, gate_wait=0.0, repeat=600.0, value=10.0, share=1, weekly=4, failed=1, repair=0,
                interventions=1, regressions=0, cli=('2.1.311',), models=('claude-opus-5-5',), final_pr=None,
                window_open=False, **over):
    """A stored record with the fields `trend`, `bottlenecks` and `render` read. A parameter given as None is a null
    source (the field is None). Other top-level fields can be given as keyword arguments."""
    def maybe(value, make):
        return None if value is None else make(value)

    record = {
        'epic': epic, 'issue': 1, 'final_pr': final_pr, 'tasks': tasks, 'started_at': '2026-11-09T10:00:00Z',
        'done_at': '2026-11-10T08:00:00Z', 'merged_at': merged,
        'wall': {'to_done': to_done, 'to_merge': maybe(to_done, lambda v: v + (review or 0)), 'review': review},
        'breakdown': None if to_done is None else {'work': work, 'human_wait': human_wait, 'limit_wait': 0.0,
                                                    'ci_wait': 0.0, 'host_pause': 0.0, 'github_wait': 0.0,
                                                    'idle': 0.0},
        'integration': {'work_s': 0.0, 'attempts': 0, 'rounds': 1},
        'retries': {'attempts': {'total': 3, 'per_task': {}, 'successful': 3 - (failed or 0), 'blocked': 0,
                                 'failed': failed, 'usage_limit': 0, 'running': 0, 'unclassified': 0},
                    'failed_by_outcome': {}, 'repair': {'count': repair, 'final_pr_conflict': 0, 'ci_red': 0,
                                                        'unknown': 0}, 'fix': 0, 'sync_prs': []},
        'parallel': None,
        'subscription': None if weekly is None else {'windows': {WEEKLY: weekly}, 'overlap': [], 'share': share,
                                                      'share_estimated': False},
        'gate_wait': None if gate_wait is None else {'wait_s': gate_wait, 'reasons': []},
        'human': {'interventions': interventions, 'waits': 0, 'wait_s': 0.0, 'open': 0, 'manual_fixes': []},
        'in_attempts': None if repeat is None else {
            'categories': {n: {'count': 0, 'background': 0, 'unfinished': 0, 'seconds': 0.0}
                           for n in ('read', 'test', 'e2e', 'check', 'selftest', 'stack', 'ci_watch', 'other')},
            'repeat_runs': {'count': 1, 'seconds': repeat, 'by_category': {n: {'count': 0, 'seconds': 0.0}
                                                                            for n in ('test', 'e2e', 'check')}},
            'api_retry': {'count': 0, 'delay_ms': 0}, 'permission_denials': {}},
        'by_task': {}, 'attempts': [],
        'value': {'total': maybe(value, lambda v: {'usd': v, 'estimated': False, 'tokens': {}, 'estimated_tokens': []}),
                  'by_role': {}, 'by_model': {}, 'by_task': {}, 'by_role_model': {}},
        'versions': None if cli is None else {'cli': [{'version': v, 'attempts': 1} for v in cli],
                                              'models': [{'model': m, 'attempts': 1} for m in (models or ())]},
        'regressions': None if regressions is None else {
            'window_days': 14, 'until': '2026-11-24T10:00:00Z', 'open': window_open, 'checked_at': merged,
            'merge_commit': None, 'complete': not window_open, 'ci_red': 0, 'reverts': [], 'issues': list(range(regressions)), 'count': regressions},
        'found_after_merge': {'issues': [], 'count': 0}, 'ci': None, 'gaps': []}
    record.update(over)
    return record


def store_records(state, *records):
    """Writes the records to <state>/epics/<epic>.json."""
    os.makedirs(os.path.join(state, 'epics'), exist_ok=True)
    for record in records:
        with open(os.path.join(state, 'epics', '%s.json' % record['epic']), 'w', encoding='utf-8') as f:
            json.dump(record, f)


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


class AttemptEnv(Env):
    """A run of one task whose attempts have synthetic or copied transcripts."""

    def attempt_record(self, lines, task='T-1', kind='plan', outcome='result', status='READY_FOR_INTEGRATION',
                       stored=None, extra_events=()):
        """The record of a one task run whose only attempt has the transcript `lines` (a Transcript, a list of lines
        or the path of a transcript to copy). stored: {epic: record} written to the state directory first."""
        for name, content in (stored or {}).items():
            os.makedirs(os.path.join(self.state, 'epics'), exist_ok=True)
            with open(os.path.join(self.state, 'epics', '%s.json' % name), 'w') as f:
                json.dump(content, f)
        home = synthetic_home(
            self.root, tasks=[(task, kind)], created=1000.0,
            attempts=[dict(task_id=task, number=1, started_at=1100.0, ended_at=1300.0, outcome=outcome,
                           executor_status=status)], events=[(2000.0, 'epic_done', {})] + list(extra_events))
        if lines is not None:
            if isinstance(lines, str):
                copy_transcript(home, lines, task, 1)
            else:
                put_transcript(home, task, 1, lines.lines if isinstance(lines, Transcript) else lines)
        return self.record('demo', home=home)

    def cell(self, record, role, model):
        return record['value']['by_role_model'][role][model]

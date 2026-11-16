#!/usr/bin/env python3
"""Measurement of AO epic runs (spec docs/features/metrics/spec.md). Python 3.9+, standard library only.

  metrics.py epic EPIC [--ao-home DIR] [--manual-fixes FILE] [--no-gh] [COMMON]
  metrics.py backfill [--ao-root DIR] [COMMON]

COMMON: --config FILE (default: config.json next to this script), --state DIR (default: $SHOP_METRICS_STATE or
~/.local/state/shop-metrics), --night DIR (measurements.jsonl and gate.jsonl; default: ~/.local/state/ao-night),
--busy-timeout-ms N (default: config.json), --repo OWNER/NAME (passed to every `gh` as -R; default: the repository of
the working directory).

epic: prints the record of the epic run as one JSON line and stores it in <state>/epics/<EPIC>.json. The run is the
directory --ao-home (default: ao-<EPIC> under $SHOP_METRICS_AO_ROOT or ~/.local/state). Fields from the AO database
and events (`orchestrator.db` is opened read only, `ao` is never run), from measurements.jsonl and gate.jsonl, and
from GitHub (`gh`, read only; --no-gh skips it). A source that is missing gives `null` and an entry in `gaps`, never
zero. A value from GitHub or from --manual-fixes that is `null` now but not in the stored record is kept (a transient
error never replaces it); the gap says so.

Transcripts (`<ao-home>/tasks/<task>/attempts/<n>/stdout.jsonl`, read line by line, a killed run's last line skipped)
give `attempts`, `value`, `by_task`, `in_attempts`, `versions` and `subscription.share` (spec: Pojęcia, Dokładność
tokenów i wartości). Roles: the `subagent_type` of a message, a message without `parent_tool_use_id` is the orchestrator
of the attempt (`delivery`; `integration` for integration tasks), a model of the last `result` without messages (or the
surplus of its tokens over the messages) is `other`. Assistant lines with the same `message.id` count once. The output
of the orchestrator is the sum of `usage.output_tokens` of all `result` lines; the rest of a model's `outputTokens` is
the subagents' and is split between their types by unique message ids (estimated when there are several types). The
value of a model (`costUSD` of the last `result`) is split between its roles by the token weights of config.json
(exact only for the sole role of the model). An attempt without a CLI result (no `result`, or a zeroed last one next to
tokens in the messages) has the tokens of its messages and a value estimated as input and cache tokens times the median
historical rate of the model (attempts with a CLI result of the stored records and of this run; without history null and
a gap). A missing transcript is a gap and null, never zero. The text of a shell command is only compared in memory
(`repeat_runs`) and never stored. `subscription.share` is the value of this epic in its measurement interval by the
value of all the epics that ran in it (the attempts of each epic assigned to the interval evenly over their duration,
other epics from their stored records); 1 without overlap, null with a gap when a record of an overlapping epic is
missing.

backfill: records every run `ao-*` under the AO root (--ao-root, default as above) whose database has the row of its
epic with a final PR that GitHub reports MERGED (also in the AO states INTEGRATING and BLOCKED). Skips runs in progress
and directories without a database or an epic row. Prints one JSON line {"recorded": [...], "skipped": [{"run",
"reason"}]}.

Exit codes: 0 success; 2 nothing recorded: bad arguments or config.json, missing AO directory, unreadable or locked
database, no epic row, unreadable --manual-fixes file, state not writable (message on stderr, nothing on stdout);
3 the record was printed and stored but a source failed (`gh` error; message on stderr, the gap is in `gaps`).
"""
import argparse
import json
import os
import re
import sqlite3
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', '..', '.claude', 'skills', 'ao-run'))
import ao_events  # noqa: E402  (waits: the windows of waiting for the user, shared with /ao-run and /retro)
import night_queue  # noqa: E402  (window deltas, measurement intervals and their time format)

DEFAULT_CONFIG = os.path.join(HERE, 'config.json')
EXIT_FATAL = 2
EXIT_SOURCE = 3
BREAKDOWN_CATEGORIES = ('work', 'human_wait', 'limit_wait', 'ci_wait', 'host_pause', 'github_wait', 'idle')


class MetricsError(Exception):
    """A failure that ends the command with exit code 2 and the message on stderr."""


# ------------------------------------------------------------------------------------------------------- config

def _is_number(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def _is_int(value):
    return isinstance(value, int) and not isinstance(value, bool)


def _number_map(keys=None):
    def check(value):
        if not isinstance(value, dict) or not value or not all(_is_number(v) for v in value.values()):
            return False
        return keys is None or set(value) == set(keys)
    return check


def _order(value):
    return isinstance(value, list) and sorted(value) == sorted(BREAKDOWN_CATEGORIES)


def _string_list(value):
    return isinstance(value, list) and bool(value) and all(isinstance(v, str) and v for v in value)


def _patterns(value):
    if not isinstance(value, dict) or not value:
        return False
    for patterns in value.values():
        if not _string_list(patterns):
            return False
        for pattern in patterns:
            try:
                re.compile(pattern)
            except re.error:
                return False
    return True


def _evals(value):
    return (isinstance(value, dict) and set(value) == {'roles', 'results_dir'} and _string_list(value['roles'])
            and isinstance(value['results_dir'], str) and bool(value['results_dir']))


CONFIG_SCHEMA = {
    'breakdown_order': (_order, 'lista siedmiu kategorii: %s' % ', '.join(BREAKDOWN_CATEGORIES)),
    'github_wait_cap_s': (lambda v: _is_number(v) and v > 0, 'liczba sekund większa od 0'),
    'busy_timeout_ms': (lambda v: _is_int(v) and v >= 0, 'liczba milisekund (całkowita, nieujemna)'),
    'gh_timeout_s': (lambda v: _is_number(v) and v > 0, 'liczba sekund większa od 0'),
    'shell_tools': (_string_list, 'niepusta lista nazw narzędzi'),
    'command_categories': (_patterns, 'obiekt: kategoria -> lista poprawnych wyrażeń regularnych'),
    'code_change_tools': (_string_list, 'niepusta lista nazw narzędzi zmieniających kod'),
    'repeat_categories': (_string_list, 'niepusta lista kategorii poleceń z command_categories'),
    'token_weights': (_number_map(('input', 'cache_write_5m', 'cache_write_1h', 'cache_read', 'output')),
                      'wagi input, cache_write_5m, cache_write_1h, cache_read, output (liczby)'),
    'bottlenecks': (_number_map(('min_excess_h', 'max_items', 'min_history')), 'obiekt liczb'),
    'waste': (_number_map(('cost_multiple', 'cost_min_usd', 'time_multiple', 'time_min_h', 'min_history',
                           'loop_failed_attempts')), 'obiekt liczb'),
    'decisions': (_number_map(('d1_role_share', 'd2_work_multiple', 'd2_failed_attempts', 'd3_gate_wait_h',
                               'd4_human_wait_share', 'd4_ci_share', 'd4_limit_wait_share', 'd4_review_share',
                               'report_max', 'effect_min_tasks', 'effect_min_task_epics', 'effect_min_epics')),
                  'obiekt liczb'),
    'evals': (_evals, 'obiekt {roles: lista, results_dir: ścieżka}'),
    'regression_window_days': (lambda v: _is_int(v) and v > 0, 'liczba dni (całkowita, dodatnia)'),
    'trend_last': (lambda v: _is_int(v) and v > 0, 'liczba epików (całkowita, dodatnia)'),
}


def load_config(path):
    try:
        with open(path, encoding='utf-8') as f:
            config = json.load(f)
    except OSError as e:
        raise MetricsError('nieczytelny config.json %s: %s' % (path, e.strerror or e))
    except ValueError as e:
        raise MetricsError('config.json %s nie jest poprawnym JSON: %s' % (path, e))
    if not isinstance(config, dict):
        raise MetricsError('config.json %s: oczekiwano obiektu JSON' % path)
    for key, (check, expected) in CONFIG_SCHEMA.items():
        if key not in config:
            raise MetricsError('config.json %s: brak klucza %s' % (path, key))
        if not check(config[key]):
            raise MetricsError('config.json %s: klucz %s: oczekiwano: %s' % (path, key, expected))
    unknown = sorted(set(config) - set(CONFIG_SCHEMA))
    if unknown:
        raise MetricsError('config.json %s: nieznany klucz %s' % (path, ', '.join(unknown)))
    missing = [c for c in config['repeat_categories'] if c not in config['command_categories']]
    if missing:
        raise MetricsError('config.json %s: klucz repeat_categories: kategoria %s nie jest w command_categories'
                           % (path, ', '.join(missing)))
    return config


# ---------------------------------------------------------------------------------------------------- AO database

def open_readonly(path, busy_timeout_ms):
    """The AO database for reading only: `mode=ro` (a write fails, no journal or WAL file is created) and a busy
    timeout, because a running AO holds short write locks."""
    if not os.path.isfile(path):
        raise MetricsError('brak bazy AO %s (katalog bez orchestrator.db)' % path)
    uri = 'file:%s?mode=ro' % path.replace('?', '%3f').replace('#', '%23')
    try:
        con = sqlite3.connect(uri, uri=True, timeout=busy_timeout_ms / 1000.0)
        con.execute('PRAGMA busy_timeout = %d' % int(busy_timeout_ms))
        return con
    except sqlite3.Error as e:
        raise _database_error(path, e)


def _database_error(path, error):
    if isinstance(error, sqlite3.OperationalError) and 'locked' in str(error).lower():
        return MetricsError('baza AO %s zablokowana (zajęta dłużej niż busy_timeout): %s' % (path, error))
    return MetricsError('nieczytelna baza AO %s: %s' % (path, error))


def select(con, table, columns, gaps):
    """Rows of `table` as dicts of the wanted columns; a missing column is None in every row and a gap."""
    present = [row[1] for row in con.execute('PRAGMA table_info("%s")' % table)]
    use = [c for c in columns if c in present]
    for column in columns:
        if column not in present:
            gaps.add('%s.%s' % (table, column), 'brak kolumny albo tabeli w bazie AO')
    if not use:
        return []
    cursor = con.execute('SELECT %s FROM "%s"' % (', '.join('"%s"' % c for c in use), table))
    return [dict(zip(columns, [dict(zip(use, row)).get(c) for c in columns])) for row in cursor.fetchall()]


class Gaps:
    """Missing data of one record: (field, reason) pairs, written as `field: reason`."""

    def __init__(self):
        self.items = []

    def add(self, field, reason):
        if (field, reason) not in self.items:
            self.items.append((field, reason))

    def render(self):
        return ['%s: %s' % item for item in self.items]


# ------------------------------------------------------------------------------------------------------ the record

def iso(epoch):
    if epoch is None:
        return None
    return datetime.fromtimestamp(epoch, timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def _number(value):
    return float(value) if _is_number(value) else None


def load_run(ao_home, epic_id, busy_timeout_ms, gaps):
    """The rows of the epic run: {epic, tasks, attempts, events} from orchestrator.db (read only)."""
    if not os.path.isdir(ao_home):
        raise MetricsError('brak katalogu AO %s' % ao_home)
    db = os.path.join(ao_home, 'orchestrator.db')
    con = open_readonly(db, busy_timeout_ms)
    try:
        epics = select(con, 'epics', ['epic_id', 'status', 'round', 'final_pr_number', 'created_at', 'updated_at'],
                       gaps)
        epic = next((e for e in epics if e['epic_id'] == epic_id), None)
        if epic is None:
            raise MetricsError('baza AO %s nie ma wiersza epiku %s' % (db, epic_id))
        tasks = select(con, 'tasks', ['task_id', 'kind', 'status', 'pr_number', 'fix_count'], gaps)
        attempts = select(con, 'attempts', ['task_id', 'number', 'started_at', 'ended_at', 'wall_s', 'outcome',
                                            'executor_status'], gaps)
        events = []
        for row in select(con, 'events', ['at', 'task_id', 'type', 'data'], gaps):
            try:
                data = json.loads(row['data']) if row['data'] else {}
            except (ValueError, TypeError):
                data = {}
            events.append({'at': _number(row['at']), 'task_id': row['task_id'], 'type': row['type'],
                           'data': data if isinstance(data, dict) else {}})
    except sqlite3.Error as e:
        raise _database_error(db, e)
    finally:
        con.close()
    events = [e for e in events if e['at'] is not None]
    events.sort(key=lambda e: e['at'])
    return {'epic': epic, 'tasks': tasks, 'attempts': attempts, 'events': events}


def read_jsonl_events(path):
    """(events, None) of events.jsonl, or (None, reason) when the file is missing or unreadable. Lines that are not
    JSON objects are skipped."""
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except FileNotFoundError:
        return None, 'brak pliku events.jsonl'
    except OSError as e:
        return None, 'nieczytelny events.jsonl: %s' % (e.strerror or e)
    events = []
    for line in lines:
        try:
            event = json.loads(line)
        except ValueError:
            continue
        if isinstance(event, dict):
            events.append(event)
    return events, None


# ------------------------------------------------------------------------------------------------------ intervals

def clip_merge(intervals, low, high):
    """The (start, end) intervals cut to [low, high], sorted and merged into disjoint ones."""
    cut = sorted((max(s, low), min(e, high)) for s, e in intervals if e > low and s < high and e > s)
    merged = []
    for start, end in cut:
        if merged and start <= merged[-1][1]:
            merged[-1][1] = max(merged[-1][1], end)
        else:
            merged.append([start, end])
    return merged


def subtract(intervals, taken):
    """The disjoint `intervals` without the disjoint `taken` ones."""
    left = []
    for start, end in intervals:
        cursor = start
        for t_start, t_end in taken:
            if t_end <= cursor or t_start >= end:
                continue
            if t_start > cursor:
                left.append([cursor, t_start])
            cursor = max(cursor, t_end)
        if cursor < end:
            left.append([cursor, end])
    return left


def total(intervals):
    return sum(end - start for start, end in intervals)


CI_END = ('epic_ci_green', 'epic_ci_inherited', 'epic_ci_inherited_red', 'epic_ci_timeout', 'epic_repairing')
GITHUB_QUIET = ('github_wait', 'gh_call')


def _of_epic(event, epic_id, task_ids):
    """Whether the event belongs to the epic: it has a task of the epic or `data.epic_id` of the epic. A host event
    (host_on_battery, a usage_warning without an epic) belongs to none."""
    return event['task_id'] in task_ids or event['data'].get('epic_id') == epic_id


def _quiet(event, epic_id, task_ids):
    """Whether the event leaves a github_wait running: gh_call, github_wait or an event of no epic or another one."""
    return event['type'] in GITHUB_QUIET or not _of_epic(event, epic_id, task_ids)


def _next_at(events, index, kinds, after=None):
    """The `at` of the first event from `index` on whose type is in `kinds`."""
    for event in events[index:]:
        if event['type'] in kinds and (after is None or event['at'] >= after):
            return event['at']
    return None


def category_intervals(run, done, jsonl, config, gaps):
    """{category: [(start, end)] or None} for the breakdown categories except idle (before clipping and
    precedence). None: the source of the category is missing."""
    events = run['events']
    epic_id = run['epic']['epic_id']
    task_ids = {t['task_id'] for t in run['tasks']}
    found = {'work': [], 'human_wait': None, 'limit_wait': [], 'ci_wait': [], 'host_pause': [], 'github_wait': []}
    for attempt in run['attempts']:
        start = _number(attempt['started_at'])
        if start is None:
            continue
        end = _number(attempt['ended_at'])
        found['work'].append((start, done if end is None else end))
    if jsonl is not None:
        found['human_wait'] = [(w['start'], done if w['end'] is None else w['end']) for w in ao_events.waits(jsonl)]
    for i, event in enumerate(events):
        kind, data, at = event['type'], event['data'], event['at']
        if kind == 'cooldown_set':
            start = _number(data.get('from'))
            ends = [e for e in (_number(data.get('until')), _next_at(events, i + 1, ('cooldown_released',))) if e]
            if not ends:
                gaps.add('breakdown.limit_wait', 'cooldown_set bez until i bez cooldown_released: pominięty')
                continue
            found['limit_wait'].append((at if start is None else start, min(ends)))
        elif kind == 'epic_ci_pending':
            end = _next_at(events, i + 1, CI_END)
            found['ci_wait'].append((at, done if end is None else end))
        elif kind == 'final_pr_created':
            end = _next_at(events, i + 1, ('final_pr_ci',))
            found['ci_wait'].append((at, done if end is None else end))
        elif kind == 'host_pause':
            start, end = _number(data.get('from')), _number(data.get('to'))
            seconds = _number(data.get('seconds'))
            if start is None and seconds is not None:
                start, end = at - seconds, at
            if start is None or end is None:
                gaps.add('breakdown.host_pause', 'host_pause bez from/to i seconds: pominięty')
                continue
            found['host_pause'].append((start, end))
        elif kind == 'github_wait' and not (i and _quiet(events[i - 1], epic_id, task_ids)
                                            and _run_of_github_waits(events, i, epic_id, task_ids)):
            last, j = at, i + 1
            while j < len(events) and _quiet(events[j], epic_id, task_ids):
                if events[j]['type'] == 'github_wait':
                    last = events[j]['at']
                j += 1
            end = last + config['github_wait_cap_s']
            if j < len(events):
                end = min(end, events[j]['at'])
            found['github_wait'].append((at, end))
    return found


def _run_of_github_waits(events, index, epic_id, task_ids):
    """Whether the event at `index` continues a run of quiet events (see `_quiet`) that a github_wait before it
    already started (that run is one wait, measured from its first github_wait)."""
    j = index - 1
    while j >= 0 and _quiet(events[j], epic_id, task_ids):
        if events[j]['type'] == 'github_wait':
            return True
        j -= 1
    return False


def breakdown_of(run, started, done, jsonl, jsonl_gap, config, gaps):
    """The split of start -> epic_done into disjoint categories: each moment goes to the first category (in the order
    of config.json) whose interval covers it; the rest is idle. A category without a source is None."""
    if done is None or started is None:
        gaps.add('breakdown', 'brak epic_done: rozkład czasu niemożliwy')
        return None
    if jsonl is None:
        gaps.add('breakdown.human_wait', '%s: czas czekania na człowieka jest w idle' % jsonl_gap)
    found = category_intervals(run, done, jsonl, config, gaps)
    taken, result = [], {}
    for name in config['breakdown_order']:
        if name == 'idle':
            continue
        if found[name] is None:
            result[name] = None
            continue
        own = subtract(clip_merge(found[name], started, done), taken)
        result[name] = round(total(own), 3)
        taken = clip_merge(taken + [tuple(i) for i in own], started, done)
    to_done = round(done - started, 3)
    result['idle'] = round(to_done - sum(v for v in result.values() if v is not None), 3)
    return {name: result[name] for name in config['breakdown_order']}


# ------------------------------------------------------------------------------------------------------ attempts

FAILED_OUTCOMES = ('timeout', 'invalid_output', 'crash', 'policy_blocked')
ATTEMPT_CLASSES = ('successful', 'blocked', 'failed', 'usage_limit', 'running', 'unclassified')
INTEGRATION_KINDS = ('integration', 'fix', 'finalize')


def classify_attempt(outcome, executor_status, run_ended):
    """(class, detail) of an attempt (spec, Pojęcia): `blocked` is executor_status BLOCKED (waiting for a human, not a
    failure); `successful` is outcome result with another status than FAILED; `failed` is a timeout, invalid_output,
    crash, policy_blocked, a result with FAILED, or no outcome after the end of the run; `usage_limit` is neither.
    A missing outcome while the run goes on is `running`; any other outcome is `unclassified`. `detail` is the key of
    `failed_by_outcome` (or the unknown outcome)."""
    outcome = outcome or ''
    if executor_status == 'BLOCKED':
        return 'blocked', None
    if outcome == 'usage_limit':
        return 'usage_limit', None
    if outcome == 'result':
        return ('failed', 'result_failed') if executor_status == 'FAILED' else ('successful', None)
    if outcome in FAILED_OUTCOMES:
        return 'failed', outcome
    if outcome == '':
        return ('failed', 'no_outcome') if run_ended else ('running', None)
    return 'unclassified', outcome


def retries_of(run, run_ended, gaps):
    attempts = {'total': 0, 'per_task': {}, **{name: 0 for name in ATTEMPT_CLASSES}}
    failed_by_outcome = {}
    for attempt in run['attempts']:
        attempts['total'] += 1
        attempts['per_task'][attempt['task_id']] = attempts['per_task'].get(attempt['task_id'], 0) + 1
        name, detail = classify_attempt(attempt['outcome'], attempt['executor_status'], run_ended)
        attempts[name] += 1
        if name == 'failed':
            failed_by_outcome[detail] = failed_by_outcome.get(detail, 0) + 1
        elif name == 'unclassified':
            gaps.add('retries.attempts', 'próba %s/%s ma nieznany outcome %r' % (attempt['task_id'],
                                                                                  attempt['number'], detail))
    repair = {'count': 0, 'final_pr_conflict': 0, 'ci_red': 0, 'unknown': 0}
    repairing = {e['data'].get('task_id'): e['data'] for e in run['events'] if e['type'] == 'epic_repairing'}
    for task in run['tasks']:
        if task['kind'] != 'repair':
            continue
        repair['count'] += 1
        data = repairing.get(task['task_id'])
        if data is None:
            repair['unknown'] += 1
            gaps.add('retries.repair', 'zadanie %s bez zdarzenia epic_repairing: przyczyna nieznana' % task['task_id'])
        elif data.get('final_pr') is not None:
            repair['final_pr_conflict'] += 1
        else:
            repair['ci_red'] += 1
    return {'attempts': attempts, 'failed_by_outcome': failed_by_outcome, 'repair': repair,
            'fix': sum(1 for t in run['tasks'] if t['kind'] == 'fix')}


def integration_of(run, epic, gaps):
    kinds = {t['task_id']: t['kind'] for t in run['tasks']}
    work, count = 0.0, 0
    for attempt in run['attempts']:
        if kinds.get(attempt['task_id']) not in INTEGRATION_KINDS:
            continue
        count += 1
        seconds = _number(attempt['wall_s'])
        start, end = _number(attempt['started_at']), _number(attempt['ended_at'])
        if seconds is None and start is not None and end is not None:
            seconds = end - start
        if seconds is None:
            gaps.add('integration', 'próba %s/%s bez wall_s i końca: pominięta w czasie' % (attempt['task_id'],
                                                                                         attempt['number']))
            continue
        work += seconds
    return {'work_s': round(work, 3), 'attempts': count, 'rounds': epic['round']}


# --------------------------------------------------------------------------------------------- transcripts: reading

TOKEN_KINDS = ('input', 'output', 'cache_read', 'cache_write')
RAW_KINDS = ('input', 'cache_read', 'cw5', 'cw1')       # the input side; the cache write split by its lifetime
ZERO_TOKENS = {'input': 0, 'cache_read': 0, 'cw5': 0, 'cw1': 0, 'output': 0}
SUBAGENT = 'subagent'                                   # a message of a subagent without `subagent_type`
OTHER_ROLE = 'other'
ORCHESTRATOR_ROLE = {'integration': 'integration'}      # task kind -> orchestrator role; the rest is `delivery`


def _int(value):
    return int(value) if _is_number(value) and value >= 0 else 0


def _timestamp(text):
    try:
        return night_queue.parse_time(text).timestamp()
    except (TypeError, ValueError, AttributeError):
        return None


def _message_tokens(usage):
    """The tokens of the `usage` of a message: the cache write is split into 5 minute and 1 hour writes (all of it is
    taken as a 5 minute write when the CLI does not say)."""
    usage = usage if isinstance(usage, dict) else {}
    written = _int(usage.get('cache_creation_input_tokens'))
    split = usage.get('cache_creation') if isinstance(usage.get('cache_creation'), dict) else None
    if split is not None:
        cw5, cw1 = _int(split.get('ephemeral_5m_input_tokens')), _int(split.get('ephemeral_1h_input_tokens'))
        if cw5 + cw1 != written:
            cw5, cw1 = written - cw1 if written >= cw1 else 0, min(cw1, written)
    else:
        cw5, cw1 = written, 0
    return {'input': _int(usage.get('input_tokens')), 'cache_read': _int(usage.get('cache_read_input_tokens')),
            'cw5': cw5, 'cw1': cw1, 'output': _int(usage.get('output_tokens'))}


def _model_tokens(used):
    used = used if isinstance(used, dict) else {}
    written = _int(used.get('cacheCreationInputTokens'))
    has_split = 'cacheCreationEphemeral5mInputTokens' in used or 'cacheCreationEphemeral1hInputTokens' in used
    cw5, cw1 = _int(used.get('cacheCreationEphemeral5mInputTokens')), _int(used.get('cacheCreationEphemeral1hInputTokens'))
    if not has_split:
        cw5, cw1 = written, 0
    elif cw5 + cw1 != written:
        cw5, cw1 = written - cw1 if written >= cw1 else 0, min(cw1, written)
    return {'input': _int(used.get('inputTokens')), 'cache_read': _int(used.get('cacheReadInputTokens')),
            'cw5': cw5, 'cw1': cw1, 'output': _int(used.get('outputTokens'))}


def read_transcript(path):
    """(data, None) of a `stdout.jsonl` of an attempt, or (None, reason) when it is missing or unreadable. Lines that
    are not JSON objects (the last line of a killed run) are skipped. data:
      messages  {id: {role (subagent_type or None), orchestrator (no parent), model, tokens}} (first line of an id)
      inits     [{version, model}]               results  [{output, cost, models}] (every `result`)
      retries   [retry_delay_ms]                 denials  {tool_name: count}
      tools     [(`use`, id, name, command, background, at) | (`result`, id, at)] in file order (command only of a
                shell tool, and only in memory: it is never stored in the record)."""
    data = {'messages': {}, 'inits': [], 'results': [], 'retries': [], 'denials': {}, 'tools': []}
    seen_tools = set()
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            for raw in f:
                try:
                    line = json.loads(raw)
                except ValueError:
                    continue
                if isinstance(line, dict):
                    _read_line(line, data, seen_tools)
    except FileNotFoundError:
        return None, 'brak pliku stdout.jsonl'
    except OSError as e:
        return None, 'nieczytelny stdout.jsonl: %s' % (e.strerror or e)
    return data, None


def _read_line(line, data, seen_tools):
    kind, subtype = line.get('type'), line.get('subtype')
    if kind == 'assistant' and isinstance(line.get('message'), dict):
        message = line['message']
        if isinstance(message.get('id'), str) and message['id'] not in data['messages']:
            role = line.get('subagent_type')
            data['messages'][message['id']] = {
                'role': role if isinstance(role, str) and role else None,
                'orchestrator': line.get('parent_tool_use_id') is None,
                'model': message['model'] if isinstance(message.get('model'), str) else None,
                'tokens': _message_tokens(message.get('usage'))}
        for block in message.get('content') if isinstance(message.get('content'), list) else []:
            if isinstance(block, dict) and block.get('type') == 'tool_use' and block.get('id') not in seen_tools:
                seen_tools.add(block.get('id'))
                tool_input = block.get('input') if isinstance(block.get('input'), dict) else {}
                command = tool_input.get('command')
                data['tools'].append(('use', block.get('id'), block.get('name'),
                                      command if isinstance(command, str) else '',
                                      tool_input.get('run_in_background') is True, _timestamp(line.get('timestamp'))))
    elif kind == 'user' and isinstance(line.get('message'), dict) and isinstance(line['message'].get('content'), list):
        for block in line['message']['content']:
            if isinstance(block, dict) and block.get('type') == 'tool_result':
                data['tools'].append(('result', block.get('tool_use_id'), _timestamp(line.get('timestamp'))))
    elif kind == 'system' and subtype == 'init':
        data['inits'].append({'version': line.get('claude_code_version'), 'model': line.get('model')})
    elif kind == 'system' and subtype == 'api_retry':
        data['retries'].append(_int(line.get('retry_delay_ms')))
    elif kind == 'system' and subtype == 'permission_denied':
        name = line.get('tool_name') if isinstance(line.get('tool_name'), str) and line.get('tool_name') else 'unknown'
        data['denials'][name] = data['denials'].get(name, 0) + 1
    elif kind == 'result':
        usage = line.get('usage') if isinstance(line.get('usage'), dict) else {}
        models = line.get('modelUsage') if isinstance(line.get('modelUsage'), dict) else {}
        data['results'].append({'output': _int(usage.get('output_tokens')), 'cost': _number(line.get('total_cost_usd')),
                                'models': {m: u for m, u in models.items() if isinstance(u, dict)}})


# ----------------------------------------------------------------------------------- transcripts: tokens and value

def apportion(total, weights):
    """The integer `total` split in proportion to `weights` ({key: number}) by the largest remainder, so the parts sum
    to the total exactly (a tie goes to the larger weight, then to the key)."""
    weight_sum = float(sum(weights.values()))
    if not weights or weight_sum <= 0:
        return {key: 0 for key in weights}
    exact = {key: total * weight / weight_sum for key, weight in weights.items()}
    parts = {key: int(value) for key, value in exact.items()}
    left = total - sum(parts.values())
    for key in sorted(weights, key=lambda k: (-(exact[k] - parts[k]), -weights[k], k))[:left]:
        parts[key] += 1
    return parts


def weighted(tokens, weights, with_output=True):
    """The tokens of a cell weighted by the price proportions of config.json (`token_weights`)."""
    total = (tokens['input'] * weights['input'] + tokens['cw5'] * weights['cache_write_5m']
             + tokens['cw1'] * weights['cache_write_1h'] + tokens['cache_read'] * weights['cache_read'])
    return total + (tokens['output'] * weights['output'] if with_output else 0)


def _new_cell():
    return {'tokens': dict(ZERO_TOKENS), 'estimated': set(), 'usd': None, 'shared': False, 'no_cli': False}


def _tokens_out(tokens):
    return {'input': tokens['input'], 'output': tokens['output'], 'cache_read': tokens['cache_read'],
            'cache_write': tokens['cw5'] + tokens['cw1']}


def orchestrator_role(kind):
    return ORCHESTRATOR_ROLE.get(kind, 'delivery')


def _message_groups(data, orchestrator):
    """{model: {role: {ids, tokens}}} of the unique messages by their role (the subagent_type of the message; no
    parent: the orchestrator of the attempt)."""
    groups = {}
    for message in data['messages'].values():
        if message['model'] is None:
            continue
        role = message['role'] or (orchestrator if message['orchestrator'] else SUBAGENT)
        group = groups.setdefault(message['model'], {}).setdefault(role, {'ids': 0, 'tokens': dict(ZERO_TOKENS)})
        group['ids'] += 1
        for key in RAW_KINDS + ('output',):
            group['tokens'][key] += message['tokens'][key]
    return groups


def _zeroed(result):
    """Whether a `result` is empty: zero cost and no tokens or cost in any model (the result written after a crash)."""
    if result['cost']:
        return False
    return not any(any(_int(u.get(f)) for f in ('inputTokens', 'outputTokens', 'cacheReadInputTokens',
                                                'cacheCreationInputTokens')) or _number(u.get('costUSD'))
                   for u in result['models'].values())


def _has_tokens(groups):
    return any(any(g['tokens'].values()) for roles in groups.values() for g in roles.values())


def result_usage(data, orchestrator, weights, label, gaps):
    """The cells {role: {model: cell}} of an attempt with a CLI result: the last `result` says what each model used
    and cost (the whole tree, cumulative); the messages say which role used it (spec: Dokładność tokenów i wartości)."""
    last = data['results'][-1]
    used = last['models']
    groups = _message_groups(data, orchestrator)
    orch_models = {m for m, roles in groups.items() if orchestrator in roles}
    orch_models |= {i['model'] for i in data['inits'] if isinstance(i['model'], str)}
    multi = len(orch_models) > 1
    orch_total = sum(r['output'] for r in data['results'])
    if not orch_models:
        orch_models = {next(iter(used))} if used else set()
        if orch_total and orch_models:
            gaps.add('attempts.%s' % label, 'model orkiestratora nieznany (brak system/init i wiadomości): wyjście szacowane')
            multi = True
    if multi:
        gaps.add('attempts.%s' % label, 'orkiestrator próby działa na więcej niż jednym modelu: wyjście subagentów'
                 ' szacowane')
    orch_out = {}
    if len(orch_models) == 1:
        orch_out[next(iter(orch_models))] = orch_total
    elif orch_models:
        counts = {m: groups.get(m, {}).get(orchestrator, {}).get('ids', 0) for m in orch_models}
        orch_out = apportion(orch_total, counts if any(counts.values()) else {m: 1 for m in counts})
    cells = {}
    for model in list(used) + [m for m in groups if m not in used]:
        _model_cells(model, used.get(model), groups.get(model, {}), orchestrator, orch_out.get(model, 0), multi,
                     cells, weights, label, gaps)
    return cells


def _model_cells(model, used, roles, orchestrator, orch_out, multi, cells, weights, label, gaps):
    totals = _model_tokens(used)
    flagged = multi
    made = {}
    for role, group in roles.items():
        made[role] = _new_cell()
    # the input side: the roles take what their messages say; the rest of modelUsage is `other` (a surplus), and when
    # the messages say more than modelUsage the roles are scaled down to it (a shortfall, estimated)
    shortfall = False
    for key in RAW_KINDS:
        said = {role: roles[role]['tokens'][key] for role in roles}
        if sum(said.values()) <= totals[key]:
            for role, amount in said.items():
                made[role]['tokens'][key] = amount
            rest = totals[key] - sum(said.values())
            if rest:
                made.setdefault(OTHER_ROLE, _new_cell())['tokens'][key] = rest
        else:
            shortfall = True
            for role, amount in apportion(totals[key], said).items():
                made[role]['tokens'][key] = amount
                made[role]['estimated'].add(_kind_name(key))
    if shortfall:
        flagged = True
        gaps.add('attempts.%s' % label, 'niedobór modelUsage względem wiadomości (model %s): role szacowane' % model)
    # the output: the orchestrator has the sum of its result outputs; the subagents together have the rest of the
    # model output, split between their types by their unique message ids (an estimate when there are several)
    if used is None and roles:
        orch_out = 0
    if orch_out > totals['output']:
        gaps.add('attempts.%s' % label, 'wyjście orkiestratora większe niż modelUsage (model %s): ucięte' % model)
        orch_out, flagged = totals['output'], True
    if orch_out or orchestrator in roles:
        cell = made.setdefault(orchestrator, _new_cell())
        cell['tokens']['output'] = orch_out
        if multi or flagged:
            cell['estimated'].add('output')
    subagents = {role: group['ids'] for role, group in roles.items() if role != orchestrator}
    rest = totals['output'] - orch_out
    if rest and subagents:
        for role, amount in apportion(rest, subagents).items():
            made[role]['tokens']['output'] = amount
            if len(subagents) > 1 or multi or flagged:
                made[role]['estimated'].add('output')
    elif rest:
        made.setdefault(OTHER_ROLE, _new_cell())['tokens']['output'] = rest
    # the value of the model split by the weighted tokens of its roles
    cost = _number(used.get('costUSD')) if used else None
    if used is not None and used.get('costBasis') == 'unknown':
        gaps.add('attempts.%s' % label, 'costBasis unknown (model %s): wartość nieznana' % model)
        cost = None
    elif used is not None and cost is None:
        gaps.add('attempts.%s' % label, 'brak costUSD w modelUsage (model %s): wartość nieznana' % model)
    present = {role: cell for role, cell in made.items() if any(cell['tokens'].values())}
    sums = {role: weighted(cell['tokens'], weights) for role, cell in present.items()}
    for role, cell in present.items():
        cell['shared'] = len(present) > 1 or flagged
        if cost is not None:
            cell['usd'] = cost * sums[role] / sum(sums.values()) if sum(sums.values()) else 0.0
        cells.setdefault(role, {})[model] = cell


def _kind_name(key):
    return 'cache_write' if key in ('cw5', 'cw1') else key


def message_usage(data, orchestrator, rates, weights, label, gaps):
    """The cells of an attempt without a CLI result (timeout, interruption, a result zeroed after a crash): the tokens
    of the unique messages (the output is the placeholder of the messages, so it is estimated) and the value from the
    median historical rate of each model: input and cache tokens (weighted) times the rate."""
    cells, missing = {}, set()
    for model, roles in _message_groups(data, orchestrator).items():
        for role, group in roles.items():
            cell = _new_cell()
            cell['tokens'] = dict(group['tokens'])
            cell['estimated'] = {'output'}
            cell['shared'] = True
            cell['no_cli'] = True
            rate = rates.get(model)
            if rate is None:
                missing.add(model)
            else:
                cell['usd'] = weighted(cell['tokens'], weights, with_output=False) * rate
            cells.setdefault(role, {})[model] = cell
    gaps.add('attempts.%s' % label, 'próba bez wyniku CLI: tokeny z wiadomości, wartość szacowana ze stawki historycznej')
    for model in sorted(missing):
        gaps.add('attempts.%s' % label, 'brak historii stawki modelu %s: wartość nieznana' % model)
    return cells


def attempt_rates(data, weights):
    """{model: USD per weighted input and cache token} of an attempt with a CLI result: the full cost of the model
    (with its output) by its input and cache tokens (the proportions of config.json)."""
    rates = {}
    for model, used in data['results'][-1]['models'].items():
        cost, base = _number(used.get('costUSD')), weighted(_model_tokens(used), weights, with_output=False)
        if cost and base and used.get('costBasis') != 'unknown':
            rates[model] = cost / base
    return rates


def median(values):
    ordered = sorted(values)
    middle = len(ordered) // 2
    return ordered[middle] if len(ordered) % 2 else (ordered[middle - 1] + ordered[middle]) / 2.0


def historical_rates(records, own_attempts):
    """{model: median rate} from the attempts with a CLI result of the stored records of other epics and of this one."""
    found = {}
    for record in records.values():
        for attempt in record.get('attempts') or []:
            if isinstance(attempt, dict) and attempt.get('cli_result') and isinstance(attempt.get('model_rates'), dict):
                for model, rate in attempt['model_rates'].items():
                    if _is_number(rate) and rate > 0:
                        found.setdefault(model, []).append(rate)
    for rates in own_attempts:
        for model, rate in rates.items():
            found.setdefault(model, []).append(rate)
    return {model: median(values) for model, values in found.items()}


def usage_of_attempt(data, orchestrator, weights, label, gaps):
    """(cells, cli_result) of an attempt: with a CLI result (the last `result` is not zeroed) or from the messages."""
    if data['results'] and not (_zeroed(data['results'][-1]) and _has_tokens(_message_groups(data, orchestrator))):
        return result_usage(data, orchestrator, weights, label, gaps), True
    return None, False


# ----------------------------------------------------------------------------------- transcripts: value sections

def _round_usd(value):
    return None if value is None else round(value, 8)


def attempt_summary(cells, cli_result):
    """(value, estimated, tokens, estimated token kinds) of the cells {role: {model: cell}} of one attempt: the value is
    None when any cell has no value (the sum would be too low). With a CLI result the value and the tokens of the
    attempt are exact (the split between roles is not part of them); without it both are estimated."""
    tokens, values = dict(ZERO_TOKENS), []
    for models in cells.values():
        for cell in models.values():
            for key in ZERO_TOKENS:
                tokens[key] += cell['tokens'][key]
            values.append(cell['usd'])
    value = None if any(v is None for v in values) else _round_usd(sum(values))
    return value, not cli_result, _tokens_out(tokens), [] if cli_result else ['output']


class Section:
    """One section of `value` (total, by_role, ...): cells added from attempts, summed by key. A section with a role
    split (by_role, by_role_model) is estimated where its roles share a model or have an estimated token kind; a section
    that sums the roles (total, by_model, by_task) is estimated only where an attempt has no CLI result."""

    def __init__(self, role_split):
        self.role_split = role_split
        self.cells = {}

    def add(self, key, cell):
        into = self.cells.setdefault(key, {'tokens': dict(ZERO_TOKENS), 'estimated': set(), 'usd': 0.0, 'known': 0,
                                           'unknown': 0, 'shared': False})
        for token in ZERO_TOKENS:
            into['tokens'][token] += cell['tokens'][token]
        if self.role_split or cell['no_cli']:
            into['estimated'] |= cell['estimated']
            into['shared'] = into['shared'] or cell['shared'] or bool(cell['estimated'])
        if cell['usd'] is None:
            into['unknown'] += 1
        else:
            into['usd'] += cell['usd']
            into['known'] += 1

    def mark_unknown(self, key):
        into = self.cells.setdefault(key, {'tokens': dict(ZERO_TOKENS), 'estimated': set(), 'usd': 0.0, 'known': 0,
                                           'unknown': 0, 'shared': False})
        into['unknown'] += 1

    def render(self, key):
        return render_cell(self.cells[key])


def render_cell(cell):
    """A cell of the record: usd (None when no part has a value), estimated (a part is shared with another role, has an
    estimated token kind or has no value), tokens, estimated_tokens; `incomplete` when part of the value is unknown."""
    out = {'usd': _round_usd(cell['usd']) if cell['known'] else None,
           'estimated': bool(cell['shared'] or cell['estimated'] or cell['unknown']),
           'tokens': _tokens_out(cell['tokens']),
           'estimated_tokens': [k for k in TOKEN_KINDS if k in {_kind_name(x) for x in cell['estimated']}]}
    if cell['unknown'] and cell['known']:
        out['incomplete'] = True
    return out


def value_sections(items):
    """The `value` of the record from items [(task, {role: {model: cell}} or None)]: total, by_role, by_model, by_task
    and by_role_model. An attempt without cells (no transcript) is an unknown part of its sections."""
    sections = {name: Section(name in ('by_role', 'by_role_model'))
                for name in ('total', 'by_role', 'by_model', 'by_task', 'by_role_model')}
    nothing = {'tokens': dict(ZERO_TOKENS), 'estimated': set(), 'usd': 0.0, 'shared': False, 'no_cli': False}
    for task, cells in items:
        if cells is None:
            sections['total'].mark_unknown('all')
            sections['by_task'].mark_unknown(task)
            continue
        if not cells:                       # a CLI result without any usage: a real zero
            sections['total'].add('all', nothing)
            sections['by_task'].add(task, nothing)
        for role, models in cells.items():
            for model, cell in models.items():
                sections['total'].add('all', cell)
                sections['by_role'].add(role, cell)
                sections['by_model'].add(model, cell)
                sections['by_task'].add(task, cell)
                sections['by_role_model'].add((role, model), cell)
    value = {'total': sections['total'].render('all') if sections['total'].cells else None}
    for name in ('by_role', 'by_model', 'by_task'):
        value[name] = {key: sections[name].render(key) for key in sorted(sections[name].cells)}
    nested = {}
    for role, model in sorted(sections['by_role_model'].cells):
        nested.setdefault(role, {})[model] = sections['by_role_model'].render((role, model))
    value['by_role_model'] = nested
    return value

# ------------------------------------------------------------------------------------- transcripts: shell commands

def compile_categories(config):
    return [(name, [re.compile(p) for p in patterns]) for name, patterns in config['command_categories'].items()]


def classify_command(command, compiled):
    """(category, key) of a shell command: the first category of config.json with a pattern that matches; the key is
    the fragment the pattern matched (up to the nearest shell operator, without the file descriptor of a redirect), so
    an appended `| tail` or a redirect does not change it. A command that matches no category is `other`. The text
    is only compared, never stored."""
    for name, patterns in compiled:
        for pattern in patterns:
            match = pattern.search(command)
            if match:
                fragment = match.group(0)
                if command[match.end():match.end() + 1] in ('<', '>'):
                    # `2>&1`, `2> file`: the digit glued to the operator is a file descriptor, not part of the command
                    fragment = re.sub(r'(?<=\s)\d+$', '', fragment)
                return name, ' '.join(fragment.split())
    return 'other', None


def commands_of(data, config, compiled):
    """The shell commands of one attempt: time per category from the `tool_use` of a shell tool to the `tool_result`
    with its id (a command in the background only in `count` and `background`, one without a result in `unfinished`),
    and `repeat_runs` (spec, Rekord epiku: in_attempts; a heuristic and a lower bound): a run of a repeat category
    (test, e2e, check) whose key equals the previous run's in the attempt with no code change in between. A code change
    is `Edit`, `Write`, `NotebookEdit` or an `other` command of any role; read, ci_watch, stack and the test categories
    do not change code. When the absence of a change is not certain (a background command, a tool call without a
    result, an unfinished subagent in between) the run is not counted."""
    names = list(config['command_categories']) + ['other']
    categories = {name: {'count': 0, 'background': 0, 'unfinished': 0, 'seconds': 0.0} for name in names}
    repeat_names = config['repeat_categories']
    repeat = {'count': 0, 'seconds': 0.0, 'by_category': {n: {'count': 0, 'seconds': 0.0} for n in repeat_names}}
    ended = {event[1]: event[2] for event in reversed(data['tools']) if event[0] == 'result'}
    clean = set()           # the keys of the runs after which no code change (and nothing uncertain) happened
    for event in data['tools']:
        if event[0] != 'use':
            continue
        _, tool_id, name, command, background, started = event
        finished = tool_id in ended
        if name in config['shell_tools']:
            category, key = classify_command(command, compiled)
            entry = categories[category]
            entry['count'] += 1
            if background or not finished:
                entry['background' if background else 'unfinished'] += 1
                clean.clear()
                continue
            seconds = ended[tool_id] - started if started is not None and ended[tool_id] is not None else None
            seconds = seconds if seconds is not None and seconds >= 0 else None
            if seconds is not None:
                entry['seconds'] += seconds
            if category == 'other':
                clean.clear()
            elif category in repeat_names:
                if (category, key) in clean:
                    repeat['count'] += 1
                    repeat['by_category'][category]['count'] += 1
                    repeat['seconds'] += seconds or 0.0
                    repeat['by_category'][category]['seconds'] += seconds or 0.0
                clean.add((category, key))
        elif name in config['code_change_tools'] or background or not finished:
            clean.clear()
    return {'categories': categories, 'repeat_runs': repeat,
            'api_retry': {'count': len(data['retries']), 'delay_ms': sum(data['retries'])},
            'permission_denials': dict(data['denials'])}


def in_attempts_of(parts):
    """The `in_attempts` of the record: the sum of `commands_of` over the attempts with a transcript."""
    total = None
    for part in parts:
        if total is None:
            total = json.loads(json.dumps(part))
            continue
        for name, entry in part['categories'].items():
            for field, value in entry.items():
                total['categories'][name][field] += value
        total['repeat_runs']['count'] += part['repeat_runs']['count']
        total['repeat_runs']['seconds'] += part['repeat_runs']['seconds']
        for name, entry in part['repeat_runs']['by_category'].items():
            for field, value in entry.items():
                total['repeat_runs']['by_category'][name][field] += value
        total['api_retry']['count'] += part['api_retry']['count']
        total['api_retry']['delay_ms'] += part['api_retry']['delay_ms']
        for tool, count in part['permission_denials'].items():
            total['permission_denials'][tool] = total['permission_denials'].get(tool, 0) + count
    if total is None:
        return None
    for entry in list(total['categories'].values()) + [total['repeat_runs']] + list(
            total['repeat_runs']['by_category'].values()):
        entry['seconds'] = round(entry['seconds'], 3)
    total['permission_denials'] = {tool: total['permission_denials'][tool] for tool in sorted(total['permission_denials'])}
    return total


SAFE_NAME = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._+\-\[\]]{0,63}$')


def versions_of(datas):
    """CLI versions (`system/init`) and models (assistant messages) with the number of attempts that have them. A name
    that is not an identifier is skipped: the record holds no free text from the transcripts."""
    cli, models = {}, {}
    for data in datas:
        for version in {i['version'] for i in data['inits'] if isinstance(i['version'], str)}:
            if SAFE_NAME.match(version):
                cli[version] = cli.get(version, 0) + 1
        for model in {m['model'] for m in data['messages'].values() if m['model']}:
            if SAFE_NAME.match(model):
                models[model] = models.get(model, 0) + 1

    def ranked(counts, name):
        return [{name: key, 'attempts': n} for key, n in sorted(counts.items(), key=lambda item: (-item[1], item[0]))]
    return {'cli': ranked(cli, 'version'), 'models': ranked(models, 'model')}


# ---------------------------------------------------------------------------------- transcripts: the record fields

def _wall(attempt):
    seconds = _number(attempt['wall_s'])
    start, end = _number(attempt['started_at']), _number(attempt['ended_at'])
    if seconds is None and start is not None and end is not None:
        seconds = end - start
    return seconds


def transcript_path(ao_home, attempt):
    """The `stdout.jsonl` of an attempt, or None when the task id or number would leave the attempt directory."""
    task, number = attempt['task_id'], attempt['number']
    if not isinstance(task, str) or not task or os.path.basename(task) != task or task in ('.', '..'):
        return None
    if not _is_int(number):
        return None
    return os.path.join(ao_home, 'tasks', task, 'attempts', str(number), 'stdout.jsonl')


def transcript_fields(ao_home, run, config, records, epic_id, gaps):
    """{by_task, attempts, value, in_attempts, versions} of the record from the attempts of the run and their
    transcripts (`tasks/<id>/attempts/<n>/stdout.jsonl`). `records`: the stored records of other epics (the history
    of the rates of models)."""
    kinds = {t['task_id']: t['kind'] for t in run['tasks']}
    attempts = sorted(run['attempts'], key=lambda a: (_number(a['started_at']) is None, _number(a['started_at']) or 0,
                                                       str(a['task_id']), a['number'] or 0))
    weights, compiled = config['token_weights'], compile_categories(config)
    by_task = {}
    for attempt in attempts:
        task = by_task.setdefault(attempt['task_id'], {'kind': kinds.get(attempt['task_id']), 'work': 0.0,
                                                       'attempts': 0})
        task['attempts'] += 1
        seconds = _wall(attempt)
        if seconds is None:
            gaps.add('by_task', 'próba %s/%s bez wall_s i końca: pominięta w czasie' % (attempt['task_id'],
                                                                                    attempt['number']))
        else:
            task['work'] = round(task['work'] + seconds, 3)
    entries, parsed, missing, rates, pending = [], [], [], [], []
    for attempt in attempts:
        label = '%s/%s' % (attempt['task_id'], attempt['number'])
        path = transcript_path(ao_home, attempt)
        data, reason = read_transcript(path) if path else (None, 'nieprawidłowy identyfikator próby')
        seconds = _wall(attempt)
        entry = {'task': attempt['task_id'], 'kind': kinds.get(attempt['task_id']), 'number': attempt['number'],
                 'outcome': attempt['outcome'], 'executor_status': attempt['executor_status'],
                 'wall_s': None if seconds is None else round(seconds, 3),
                 'started_at': iso(_number(attempt['started_at'])), 'ended_at': iso(_number(attempt['ended_at'])),
                 'value': None, 'estimated': None, 'tokens': None, 'estimated_tokens': None, 'cli_result': None,
                 'model_rates': None}
        entries.append(entry)
        if data is None:
            missing.append((label, reason))
            parsed.append((attempt, None, None))
            continue
        orchestrator = orchestrator_role(kinds.get(attempt['task_id']))
        cells, cli = usage_of_attempt(data, orchestrator, weights, label, gaps)
        entry['cli_result'] = cli
        if cli:
            found = attempt_rates(data, weights)
            entry['model_rates'] = {m: round(r, 12) for m, r in found.items()}
            rates.append(found)
        else:
            pending.append((entry, data, orchestrator, label))
        entry['_cells'] = cells
        parsed.append((attempt, data, cells))
    history = historical_rates({k: v for k, v in records.items() if k != epic_id}, rates)
    for entry, data, orchestrator, label in pending:
        entry['_cells'] = message_usage(data, orchestrator, history, weights, label, gaps)
    items = []
    for entry in entries:
        cells = entry.pop('_cells', None)
        items.append((entry['task'], cells))
        if cells is not None:
            entry['value'], entry['estimated'], entry['tokens'], entry['estimated_tokens'] = attempt_summary(
                cells, entry['cli_result'])
    if missing:
        shown = ', '.join(label for label, _ in missing[:5]) + (', …' if len(missing) > 5 else '')
        gaps.add('attempts.transcript', '%s: %d z %d prób (%s)' % (missing[0][1], len(missing), len(entries), shown))
    if any(e['value'] is None and e['cli_result'] is not None for e in entries):
        gaps.add('value', 'część prób bez wartości: suma niepełna')
    datas = [data for _, data, _ in parsed if data is not None]
    in_attempts = in_attempts_of([commands_of(d, config, compiled) for d in datas])
    if not datas:
        gaps.add('in_attempts', 'brak transkryptów prób')
        gaps.add('versions', 'brak transkryptów prób')
    return {'by_task': by_task, 'attempts': entries, 'value': value_sections(items), 'in_attempts': in_attempts,
            'versions': versions_of(datas) if datas else None}


# ------------------------------------------------------------------------------------------------- subscription share

def value_within(attempts, intervals):
    """The value of the attempts (entries of the record) inside the disjoint [low, high] intervals, assigned evenly
    over the duration of each attempt (an attempt without an end runs to the end of the last interval). None when an
    attempt that touches an interval has no value."""
    total = 0.0
    horizon = max((high for _, high in intervals), default=None)
    for attempt in attempts:
        start, end = _timestamp(attempt.get('started_at')), _timestamp(attempt.get('ended_at'))
        if start is None:
            continue
        end = horizon if end is None else end
        for low, high in intervals:
            if end > start:
                fraction = max(0.0, min(end, high) - max(start, low)) / (end - start)
            else:
                fraction = 1.0 if low <= start <= high else 0.0
            if fraction <= 0:
                continue
            if not _is_number(attempt.get('value')):
                return None
            total += attempt['value'] * fraction
    return total


def other_attempts(epic, records, ao_root, config, busy_timeout_ms):
    """The attempts (entries of the record) of another epic: from its stored record, else computed (value included)
    from its run directory `ao-<epic>` under the AO root with the functions of the record of this epic (the database
    read only, the transcripts; nothing is stored). None without both, or when the run directory is unreadable."""
    record = records.get(epic)
    if isinstance(record, dict) and isinstance(record.get('attempts'), list):
        return record['attempts']
    if ao_root is None or not epic or os.path.basename(epic) != epic or epic in ('.', '..'):
        return None
    home = os.path.join(ao_root, 'ao-%s' % epic)
    try:
        run = load_run(home, epic, busy_timeout_ms, Gaps())
        return transcript_fields(home, run, config, records, epic, Gaps())['attempts']
    except MetricsError:
        return None


def add_share(subscription, stretches, attempts, lookup, gaps):
    """`share` of the subscription (spec: Rekord epiku): the value of this epic in the common interval (the parts of
    its measurement interval in which another epic ran; the union when there are several) by the value of this epic and
    of the overlapping epics in it (each epic's attempts assigned to the interval evenly over their duration, the other
    epics' only within their own stretches of the overlap); 1 without overlapping epics; None with a gap when the value
    of this epic or of an overlapping one is not known (no record and no run directory of it, or attempts without a
    value). `stretches`: {epic: [(start, end)]}; `lookup(epic)`: the attempts of another epic or None."""
    subscription['share'], subscription['share_estimated'] = None, False
    if not subscription['overlap']:
        subscription['share'] = 1
        return
    subscription['share_estimated'] = True
    common = clip_merge([stretch for epic in subscription['overlap'] for stretch in stretches.get(epic, [])],
                        float('-inf'), float('inf'))
    mine = value_within(attempts, common)
    if mine is None:
        gaps.add('subscription.share', 'wartość tokenów epiku nieznana (próby bez wartości)')
        return
    total = mine
    for other in subscription['overlap']:
        others = lookup(other)
        theirs = None if others is None else value_within(others, clip_merge(stretches.get(other, []), float('-inf'),
                                                                              float('inf')))
        if theirs is None:
            gaps.add('subscription.share', 'brak wartości tokenów epiku %s (brak rekordu i katalogu przebiegu albo próby'
                     ' bez wartości)' % other)
            return
        total += theirs
    if total <= 0:
        gaps.add('subscription.share', 'wartość tokenów epiku i nakładających się epików równa zero')
        return
    subscription['share'] = round(mine / total, 4)


# ----------------------------------------------------------------------------- night state: limits, overlap, gate

def read_measurements(night_dir):
    """(events, None) of <night>/measurements.jsonl, or (None, reason) when it is missing or unreadable."""
    if not os.path.exists(os.path.join(night_dir, night_queue.MEASUREMENTS_FILE)):
        return None, 'brak pliku measurements.jsonl'
    try:
        return night_queue.read_events(night_dir), None
    except night_queue.StateError as e:
        return None, 'nieczytelny measurements.jsonl: %s' % e


def measurement_intervals(events):
    """[{epic, begin, end (None while open), start, done}] in epoch seconds: a `start` opens the interval of its
    epic, the next `done` of that epic closes it, a later `start` without a `done` replaces the open one."""
    found, opened = [], {}
    for event in events:
        epic = event['epic']
        if event['event'] == 'start':
            if epic in opened:
                found.remove(opened[epic])
            item = {'epic': epic, 'begin': night_queue.parse_time(event['at']).timestamp(), 'end': None,
                    'start': event, 'done': None}
            opened[epic] = item
            found.append(item)
        elif epic in opened:
            item = opened.pop(epic)
            item['done'] = event
            item['end'] = night_queue.parse_time(event['at']).timestamp()
    return found


def own_span(intervals, epic_id, low, high):
    """The measurement interval of the epic run: from the first start to the last done of the intervals of this epic
    that lie in [low, high] (the run from epics.created_at to its end). None without any."""
    mine = [i for i in intervals if i['epic'] == epic_id and i['begin'] <= high and (i['end'] is None
                                                                                       or i['end'] >= low)]
    if not mine:
        return None
    closed = [i for i in mine if i['end'] is not None]
    last = max(closed, key=lambda i: i['end']) if closed else None
    return {'begin': min(i['begin'] for i in mine), 'start': min(mine, key=lambda i: i['begin'])['start'],
            'end': last['end'] if last else None, 'done': last['done'] if last else None}


def overlaps_of(intervals, epic_id, begin, end):
    """{other epic: [(start, end)]} of the stretches in which other epics ran inside [begin, end]: a closed interval
    that intersects it, or an open start inside it (as night_queue.measurements; an open start before does not
    count)."""
    found = {}
    for item in intervals:
        if item['epic'] == epic_id:
            continue
        if item['end'] is None:
            stretch = (item['begin'], end) if begin <= item['begin'] < end else None
        else:
            crosses = begin < item['end'] and item['begin'] < end
            stretch = (max(begin, item['begin']), min(end, item['end'])) if crosses else None
        if stretch:
            found.setdefault(item['epic'], []).append(stretch)
    return found


def subscription_of(span, overlap, gaps):
    """The raw increase of every limit window between the start and the done measurement (night_queue._window_deltas:
    a window renewed during the epic, or a usage state other than ok, gives None; when neither state is ok the
    labels of the windows are unknown and `windows` itself is None)."""
    before, after = span['start']['usage'], span['done']['usage']
    labels = [w['label'] for w in (before if before.get('status') == 'ok' else after).get('windows', [])] \
        if 'ok' in (before.get('status'), after.get('status')) else None
    deltas = night_queue._window_deltas(before, after)
    windows = None if labels is None else {label: deltas.get(label) for label in labels}
    if before.get('status') != 'ok' or after.get('status') != 'ok':
        gaps.add('subscription', 'stan limitu inny niż ok (start: %s, done: %s)' % (before.get('status'),
                                                                                  after.get('status')))
    for label, delta in (windows or {}).items():
        if delta is None and before.get('status') == 'ok' and after.get('status') == 'ok':
            gaps.add('subscription', 'okno %s odnowione w trakcie epiku albo bez przyrostu' % label)
    return {'windows': windows, 'overlap': sorted(overlap)}


def night_fields(run, epic_id, started, run_end, night_dir, gaps):
    """(subscription, parallel, stretches, span) of the epic from measurements.jsonl."""
    events, reason = read_measurements(night_dir)
    if events is None:
        gaps.add('subscription', reason)
        gaps.add('parallel', reason)
        return None, None, {}, None
    span = own_span(measurement_intervals(events), epic_id, started, run_end) if started is not None else None
    if span is None or span['end'] is None:
        why = 'epik bez pomiaru %s w measurements.jsonl' % ('start' if span is None else 'done')
        gaps.add('subscription', why)
        gaps.add('parallel', why)
        return None, None, {}, None
    stretches = overlaps_of(measurement_intervals(events), epic_id, span['begin'], span['end'])
    repairs = {t['task_id'] for t in run['tasks'] if t['kind'] == 'repair'}
    in_overlap = set()
    for attempt in run['attempts']:
        start = _number(attempt['started_at'])
        if attempt['task_id'] not in repairs or start is None:
            continue
        end = _number(attempt['ended_at'])
        end = span['end'] if end is None else end
        if any(start < s_end and s_start < end for stretch in stretches.values() for s_start, s_end in stretch):
            in_overlap.add(attempt['task_id'])
    return (subscription_of(span, stretches, gaps),
            {'epics': sorted(stretches), 'repairs': sorted(in_overlap)}, stretches, span)


def gate_wait_of(night_dir, epic_id, started, gaps):
    """{wait_s, reasons}: from the first `waiting` entry of the epic in gate.jsonl to the start of the epic; 0 when
    the gate let it through without waiting; None (and a gap) without the file or any entry of the epic."""
    path = os.path.join(night_dir, 'gate.jsonl')
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except FileNotFoundError:
        gaps.add('gate_wait', 'brak pliku gate.jsonl')
        return None
    except OSError as e:
        gaps.add('gate_wait', 'nieczytelny gate.jsonl: %s' % (e.strerror or e))
        return None
    entries = []
    for line in lines:
        try:
            entry = json.loads(line)
            if isinstance(entry, dict) and isinstance(entry.get('at'), str):
                entries.append((night_queue.parse_time(entry['at']).timestamp(), entry))
        except (ValueError, TypeError):
            continue
    mine = [(at, e) for at, e in entries if epic_id in (e.get('epic'), e.get('waiting'), e.get('dir_arg'))]
    if not mine or started is None:
        gaps.add('gate_wait', 'brak wpisów epiku w gate.jsonl')
        return None
    waiting = sorted((at, e) for at, e in mine if e.get('waiting') == epic_id and at <= started)
    if not waiting:
        return {'wait_s': 0.0, 'reasons': []}
    reasons = []
    for _, entry in waiting:
        if isinstance(entry.get('reason'), str) and entry['reason'] not in reasons:
            reasons.append(entry['reason'])
    return {'wait_s': round(started - waiting[0][0], 3), 'reasons': reasons}


PR_ITEM = re.compile(r'^PR\s*#(\d+)\s*[:\-\u2013\u2014]?\s*(.*)$')


def read_manual_fixes(path):
    """[{pr, text}] from the list of manual fixes of the /ao-run session: one per line (`- PR #12: text` or
    `- text`); blank lines and the `Ręczne poprawki: N` header are skipped."""
    try:
        with open(path, encoding='utf-8') as f:
            lines = f.read().splitlines()
    except (OSError, UnicodeDecodeError) as e:
        raise MetricsError('nieczytelny plik ręcznych poprawek %s: %s' % (path, getattr(e, 'strerror', None) or e))
    fixes = []
    for line in lines:
        text = re.sub(r'^\s*[-*]\s+', '', line).strip()
        if not text or re.match(r'^Ręczne poprawki:', text, re.IGNORECASE):
            continue
        match = PR_ITEM.match(text)
        fixes.append({'pr': int(match.group(1)), 'text': match.group(2)} if match else {'pr': None, 'text': text})
    return fixes


def human_of(jsonl, jsonl_gap, manual_fixes, done, now, gaps):
    if jsonl is None:
        gaps.add('human', jsonl_gap)
        summary = {'interventions': None, 'waits': None, 'wait_s': None, 'open': None}
    else:
        # A wait still open at epic_done ends there (the epic_done of the database, when events.jsonl lacks it).
        closing = [{'type': 'epic_done', 'at': done}] if done is not None else []
        summary = ao_events.summarize(jsonl + closing, now)
        summary = {k: summary[k] for k in ('interventions', 'waits', 'wait_s', 'open')}
    if manual_fixes is None:
        gaps.add('human.manual_fixes', 'nie podano --manual-fixes')
    return {**summary, 'manual_fixes': manual_fixes}


# ------------------------------------------------------------------------------------------------------- GitHub

class GhError(Exception):
    """`gh` failed (cannot be run, timed out, exit code other than 0, or an answer that is not JSON)."""


def gh_json(run, args, repo):
    command = ['gh'] + args + (['-R', repo] if repo else [])
    label = 'gh %s' % ' '.join(args[:2])
    try:
        result = run(command)
    except (OSError, subprocess.TimeoutExpired) as e:
        raise GhError('%s: %s' % (label, getattr(e, 'strerror', None) or e))
    if result.returncode != 0:
        raise GhError('%s: kod %s: %s' % (label, result.returncode, (result.stderr or '').strip()[:200]))
    try:
        return json.loads(result.stdout)
    except (ValueError, TypeError):
        raise GhError('%s: odpowiedź nie jest JSON' % label)


def fetch_pr(run, number, repo):
    """{state, merged_at (epoch or None), issue (first closing issue or None)} of a pull request."""
    data = gh_json(run, ['pr', 'view', str(number), '--json', 'state,mergedAt,closingIssuesReferences'], repo)
    try:
        merged = night_queue.parse_time(data['mergedAt']).timestamp() if data.get('mergedAt') else None
        issues = [i['number'] for i in data.get('closingIssuesReferences') or []]
        return {'state': data['state'], 'merged_at': merged, 'issue': issues[0] if issues else None}
    except (KeyError, TypeError, ValueError, AttributeError):
        raise GhError('gh pr view: nieoczekiwany kształt odpowiedzi')


def fetch_sync_prs(run, epic_id, repo):
    """[{number, state, created (epoch)}] of the PRs of the branch sync/<epic>-main."""
    data = gh_json(run, ['pr', 'list', '--head', 'sync/%s-main' % epic_id, '--state', 'all', '--json',
                         'number,state,createdAt', '--limit', '100'], repo)
    try:
        return [{'number': p['number'], 'state': p['state'],
                 'created': night_queue.parse_time(p['createdAt']).timestamp()} for p in data]
    except (KeyError, TypeError, ValueError, AttributeError):
        raise GhError('gh pr list: nieoczekiwany kształt odpowiedzi')


def github_data(run, no_gh, final_pr, epic_id, repo, gaps, pr=None):
    """{pr (fetch_pr result or None), sync (list or None), errors}: what GitHub says. With --no-gh, or on a gh
    error, the fields are None, with a gap each; the errors are the messages of the failed calls."""
    data = {'pr': pr, 'sync': None, 'errors': []}
    if no_gh:
        for field in ('issue', 'merged_at', 'retries.sync_prs'):
            gaps.add(field, 'pominięto gh (--no-gh)')
        return data
    if final_pr is not None and pr is None:
        try:
            data['pr'] = fetch_pr(run, final_pr, repo)
        except GhError as e:
            data['errors'].append(str(e))
            gaps.add('issue', 'błąd gh: %s' % e)
            gaps.add('merged_at', 'błąd gh: %s' % e)
    try:
        data['sync'] = fetch_sync_prs(run, epic_id, repo)
    except GhError as e:
        data['errors'].append(str(e))
        gaps.add('retries.sync_prs', 'błąd gh: %s' % e)
    return data


def _get(record, path):
    for key in path:
        if not isinstance(record, dict):
            return None
        record = record.get(key)
    return record


def _set(record, path, value):
    for key in path[:-1]:
        record = record[key]
    record[path[-1]] = value


# Fields of a source that can fail for a moment (gh) or is not given every time (the list of manual fixes): a null
# from the source never replaces the stored value.
KEPT_PATHS = (('issue',), ('merged_at',), ('wall', 'to_merge'), ('wall', 'review'), ('retries', 'sync_prs'),
              ('parallel', 'sync_prs'), ('human', 'manual_fixes'))


def keep_stored_values(record, previous, gaps):
    for path in KEPT_PATHS:
        old = _get(previous, path)
        if old is not None and _get(record, path) is None and isinstance(_get(record, path[:-1]), dict):
            _set(record, path, old)
            gaps.add('.'.join(path), 'źródło niedostępne: zachowano poprzednią wartość')


def _stored_merged(previous):
    """The merge time (epoch) of the stored record, or None: a failed `gh` must not make the classes of the attempts
    depend on the call (the record keeps merged_at from the cache, so its classes keep it too)."""
    try:
        return night_queue.parse_time(_get(previous, ('merged_at',))).timestamp()
    except (TypeError, ValueError, AttributeError):
        return None


def read_records(state_dir):
    """{epic: record} of the stored records <state>/epics/*.json (an unreadable one is skipped)."""
    found = {}
    directory = os.path.join(state_dir, 'epics')
    try:
        names = sorted(os.listdir(directory))
    except OSError:
        return found
    for name in names:
        if name.endswith('.json') and not name.startswith('.'):
            record = read_previous(os.path.join(directory, name))
            if record is not None:
                found[name[:-len('.json')]] = record
    return found


def read_previous(path):
    try:
        with open(path, encoding='utf-8') as f:
            data = json.load(f)
        return data if isinstance(data, dict) else None
    except (OSError, ValueError):
        return None


def build_record(epic_id, ao_home, config, busy_timeout_ms, night_dir, manual_fixes, now, run=None, no_gh=True,
                 repo=None, previous=None, pr=None, records=None, ao_root=None):
    """(record, errors): the record of the epic run and the messages of failed GitHub calls (the record is complete
    anyway: a failed source is null and a gap). records: {epic: stored record} of the other epics (the history of the
    rates of models for attempts without a CLI result, and the overlapping epics of `subscription.share`); ao_root: the
    directory of the run directories `ao-<epic>` (the overlapping epics without a record are computed from them;
    default: the parent of ao_home)."""
    records = records or {}
    ao_root = os.path.dirname(ao_home) if ao_root is None else ao_root
    gaps = Gaps()
    ao_run = load_run(ao_home, epic_id, busy_timeout_ms, gaps)
    epic = ao_run['epic']
    started = _number(epic['created_at'])
    done = next((e['at'] for e in ao_run['events'] if e['type'] == 'epic_done'), None)
    if done is None:
        gaps.add('done_at', 'brak zdarzenia epic_done')
    final_pr = epic['final_pr_number']
    if final_pr is None:
        gaps.add('final_pr', 'epik nie ma końcowego PR')
    github = github_data(run, no_gh, final_pr, epic_id, repo, gaps, pr)
    merged = github['pr']['merged_at'] if github['pr'] else None
    if github['pr'] is not None:
        if merged is None:
            gaps.add('merged_at', 'końcowy PR nie jest zmergowany (stan %s)' % github['pr']['state'])
        if github['pr']['issue'] is None:
            gaps.add('issue', 'końcowy PR nie zamyka żadnego issue')
    known_merge = merged if merged is not None else _stored_merged(previous)
    run_ended = done is not None or epic['status'] == 'DONE' or known_merge is not None
    jsonl, jsonl_gap = read_jsonl_events(os.path.join(ao_home, 'events.jsonl'))
    run_end = done if done is not None else (_number(epic['updated_at']) or now)
    subscription, parallel, stretches, span = night_fields(ao_run, epic_id, started, run_end, night_dir, gaps)
    fields = transcript_fields(ao_home, ao_run, config, records, epic_id, gaps)
    if subscription is not None:
        add_share(subscription, stretches, fields['attempts'],
                  lambda other: other_attempts(other, records, ao_root, config, busy_timeout_ms), gaps)
    retries = retries_of(ao_run, run_ended, gaps)
    retries['sync_prs'] = None if github['sync'] is None else [{'number': p['number'], 'state': p['state']}
                                                                for p in github['sync']]
    if parallel is not None:
        parallel['sync_prs'] = None if github['sync'] is None else sorted(
            p['number'] for p in github['sync']
            if any(s_start <= p['created'] < s_end for stretch in stretches.values() for s_start, s_end in stretch))
        if github['sync'] is None:
            gaps.add('parallel.sync_prs', 'brak listy PR sync')
    record = {
        'epic': epic_id,
        'issue': github['pr']['issue'] if github['pr'] else None,
        'final_pr': final_pr,
        'tasks': sum(1 for t in ao_run['tasks'] if t['kind'] == 'plan'),
        'started_at': iso(started),
        'done_at': iso(done),
        'merged_at': iso(merged),
        'wall': {
            'to_done': round(done - started, 3) if done is not None and started is not None else None,
            'to_merge': round(merged - started, 3) if merged is not None and started is not None else None,
            'review': round(merged - done, 3) if merged is not None and done is not None else None,
        },
        'breakdown': breakdown_of(ao_run, started, done, jsonl, jsonl_gap, config, gaps),
        'integration': integration_of(ao_run, epic, gaps),
        'retries': retries,
        'parallel': parallel,
        'subscription': subscription,
        'gate_wait': gate_wait_of(night_dir, epic_id, started, gaps),
        'human': human_of(jsonl, jsonl_gap, manual_fixes, done, now, gaps),
        'in_attempts': fields['in_attempts'],
        'by_task': fields['by_task'],
        'versions': fields['versions'],
        'attempts': fields['attempts'],
        'value': fields['value'],
    }
    keep_stored_values(record, previous, gaps)
    record['gaps'] = gaps.render()
    return record, github['errors']


# ------------------------------------------------------------------------------------------------- state and CLI

def state_path(state_dir, epic_id):
    return os.path.join(state_dir, 'epics', '%s.json' % epic_id)


def write_record(path, record):
    directory = os.path.dirname(path)
    try:
        os.makedirs(directory, exist_ok=True)
        fd, temp = tempfile.mkstemp(dir=directory, prefix='.tmp-', suffix='.json')
        try:
            with os.fdopen(fd, 'w', encoding='utf-8') as f:
                json.dump(record, f, ensure_ascii=False)
                f.write('\n')
            os.replace(temp, path)
        except BaseException:
            if os.path.exists(temp):
                os.unlink(temp)
            raise
    except OSError as e:
        raise MetricsError('nie można zapisać rekordu %s: %s' % (path, e.strerror or e))


def default_runner(command, timeout):
    return subprocess.run(command, capture_output=True, text=True, timeout=timeout)


def _expand(path):
    return os.path.abspath(os.path.expanduser(path))


def cmd_epic(args, config, run, now):
    ao_root = os.environ.get('SHOP_METRICS_AO_ROOT', '~/.local/state')
    ao_home = _expand(args.ao_home or os.path.join(ao_root, 'ao-%s' % args.epic))
    busy = config['busy_timeout_ms'] if args.busy_timeout_ms is None else args.busy_timeout_ms
    manual_fixes = read_manual_fixes(_expand(args.manual_fixes)) if args.manual_fixes else None
    path = state_path(_expand(args.state), args.epic)
    record, errors = build_record(args.epic, ao_home, config, busy, _expand(args.night), manual_fixes, now, run=run,
                                  no_gh=args.no_gh, repo=args.repo, previous=read_previous(path),
                                  records=read_records(_expand(args.state)), ao_root=os.path.dirname(ao_home))
    write_record(path, record)
    print(json.dumps(record, ensure_ascii=False))
    for error in errors:
        print('metrics: błąd gh: %s (pola z GitHuba: null albo poprzednia wartość)' % error, file=sys.stderr)
    return EXIT_SOURCE if errors else 0


def peek_epic(ao_home, epic_id, busy_timeout_ms):
    """The epics row `epic_id` of the run directory (epic_id, status, final_pr_number), or None without it."""
    con = open_readonly(os.path.join(ao_home, 'orchestrator.db'), busy_timeout_ms)
    try:
        rows = select(con, 'epics', ['epic_id', 'status', 'final_pr_number'], Gaps())
    except sqlite3.Error as e:
        raise _database_error(os.path.join(ao_home, 'orchestrator.db'), e)
    finally:
        con.close()
    return next((r for r in rows if r['epic_id'] == epic_id), None)


def cmd_backfill(args, config, run, now):
    root = _expand(args.ao_root or os.environ.get('SHOP_METRICS_AO_ROOT', '~/.local/state'))
    try:
        names = sorted(n for n in os.listdir(root) if n.startswith('ao-') and os.path.isdir(os.path.join(root, n)))
    except OSError as e:
        raise MetricsError('brak katalogu AO %s: %s' % (root, e.strerror or e))
    busy = config['busy_timeout_ms'] if args.busy_timeout_ms is None else args.busy_timeout_ms
    recorded, skipped, failures = [], [], []
    records = read_records(_expand(args.state))

    def skip(name, reason, failure=False):
        skipped.append({'run': name, 'reason': reason})
        if failure:
            failures.append('%s: %s' % (name, reason))

    for name in names:
        epic_id, home = name[len('ao-'):], os.path.join(root, name)
        if not os.path.isfile(os.path.join(home, 'orchestrator.db')):
            skip(name, 'brak orchestrator.db (to nie przebieg epiku)')
            continue
        try:
            row = peek_epic(home, epic_id, busy)
            if row is None:
                skip(name, 'baza nie ma wiersza epiku %s' % epic_id)
                continue
            if row['final_pr_number'] is None:
                skip(name, 'epik w toku (bez końcowego PR)')
                continue
            try:
                pr = fetch_pr(run, row['final_pr_number'], args.repo)
            except GhError as e:
                skip(name, 'błąd gh: %s' % e, True)
                continue
            if pr['state'] != 'MERGED':
                skip(name, 'końcowy PR nie jest zmergowany (stan %s)' % pr['state'])
                continue
            path = state_path(_expand(args.state), epic_id)
            record, errors = build_record(epic_id, home, config, busy, _expand(args.night), None, now, run=run,
                                          no_gh=False, repo=args.repo, previous=read_previous(path), pr=pr,
                                          records=records, ao_root=root)
            write_record(path, record)
            records[epic_id] = record
        except MetricsError as e:
            skip(name, str(e), True)
            continue
        recorded.append(epic_id)
        failures.extend('%s: %s' % (name, error) for error in errors)
    print(json.dumps({'recorded': recorded, 'skipped': skipped}, ensure_ascii=False))
    for failure in failures:
        print('metrics: backfill: %s' % failure, file=sys.stderr)
    return EXIT_SOURCE if failures else 0


def build_parser():
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument('--config', default=DEFAULT_CONFIG)
    common.add_argument('--state', default=os.environ.get('SHOP_METRICS_STATE', '~/.local/state/shop-metrics'))
    common.add_argument('--night', default='~/.local/state/ao-night')
    common.add_argument('--busy-timeout-ms', type=int, default=None)
    common.add_argument('--repo', default=None)
    parser = argparse.ArgumentParser(description='Measurement of AO epic runs')
    sub = parser.add_subparsers(dest='command', required=True)
    epic = sub.add_parser('epic', parents=[common])
    epic.add_argument('epic')
    epic.add_argument('--ao-home')
    epic.add_argument('--manual-fixes')
    epic.add_argument('--no-gh', action='store_true')
    backfill = sub.add_parser('backfill', parents=[common])
    backfill.add_argument('--ao-root')
    return parser


def main(argv=None, run=None, now=None):
    try:
        args = build_parser().parse_args(argv)
    except SystemExit as e:
        return EXIT_FATAL if e.code else 0
    now = time.time() if now is None else now
    try:
        config = load_config(_expand(args.config))
        runner = run or (lambda command: default_runner(command, config['gh_timeout_s']))
        if args.command == 'epic':
            return cmd_epic(args, config, runner, now)
        if args.command == 'backfill':
            return cmd_backfill(args, config, runner, now)
    except MetricsError as e:
        print('metrics: %s' % e, file=sys.stderr)
        return EXIT_FATAL
    return EXIT_FATAL


if __name__ == '__main__':
    sys.exit(main())

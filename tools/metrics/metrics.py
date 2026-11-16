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
        elif kind == 'github_wait' and not (i and events[i - 1]['type'] in GITHUB_QUIET
                                            and _run_of_github_waits(events, i)):
            last, j = at, i + 1
            while j < len(events) and events[j]['type'] in GITHUB_QUIET:
                if events[j]['type'] == 'github_wait':
                    last = events[j]['at']
                j += 1
            end = last + config['github_wait_cap_s']
            if j < len(events):
                end = min(end, events[j]['at'])
            found['github_wait'].append((at, end))
    return found


def _run_of_github_waits(events, index):
    """Whether the event at `index` continues a run of github_wait / gh_call events that a github_wait before it
    already started (that run is one wait, measured from its first github_wait)."""
    j = index - 1
    while j >= 0 and events[j]['type'] in GITHUB_QUIET:
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
            stretch = (max(begin, item['begin']), min(end, item['end'])) if begin < item['end'] and item['begin'] < end \
                else None
        if stretch:
            found.setdefault(item['epic'], []).append(stretch)
    return found


def subscription_of(span, overlap, gaps):
    """The raw increase of every limit window between the start and the done measurement (night_queue._window_deltas:
    a window renewed during the epic, or a usage state other than ok, gives None)."""
    before, after = span['start']['usage'], span['done']['usage']
    labels = [w['label'] for w in (before if before.get('status') == 'ok' else after).get('windows', [])] \
        if 'ok' in (before.get('status'), after.get('status')) else []
    deltas = night_queue._window_deltas(before, after)
    windows = {label: deltas.get(label) for label in labels}
    if before.get('status') != 'ok' or after.get('status') != 'ok':
        gaps.add('subscription', 'stan limitu inny niż ok (start: %s, done: %s)' % (before.get('status'),
                                                                                  after.get('status')))
    for label, delta in windows.items():
        if delta is None and before.get('status') == 'ok' and after.get('status') == 'ok':
            gaps.add('subscription', 'okno %s odnowione w trakcie epiku albo bez przyrostu' % label)
    return {'windows': windows, 'overlap': sorted(overlap)}


def night_fields(run, epic_id, started, run_end, night_dir, gaps):
    """(subscription, parallel) of the epic from measurements.jsonl."""
    events, reason = read_measurements(night_dir)
    if events is None:
        gaps.add('subscription', reason)
        gaps.add('parallel', reason)
        return None, None
    span = own_span(measurement_intervals(events), epic_id, started, run_end) if started is not None else None
    if span is None or span['end'] is None:
        why = 'epik bez pomiaru start w measurements.jsonl' if span is None else 'epik bez pomiaru done w measurements.jsonl'
        gaps.add('subscription', why)
        gaps.add('parallel', why)
        return None, None
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
            {'epics': sorted(stretches), 'repairs': sorted(in_overlap)})


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


def build_record(epic_id, ao_home, config, busy_timeout_ms, night_dir, manual_fixes, now):
    gaps = Gaps()
    run = load_run(ao_home, epic_id, busy_timeout_ms, gaps)
    epic = run['epic']
    started = _number(epic['created_at'])
    done = next((e['at'] for e in run['events'] if e['type'] == 'epic_done'), None)
    if done is None:
        gaps.add('done_at', 'brak zdarzenia epic_done')
    to_done = round(done - started, 3) if done is not None and started is not None else None
    run_ended = done is not None or epic['status'] == 'DONE'
    final_pr = epic['final_pr_number']
    if final_pr is None:
        gaps.add('final_pr', 'epik nie ma końcowego PR')
    jsonl, jsonl_gap = read_jsonl_events(os.path.join(ao_home, 'events.jsonl'))
    record = {
        'epic': epic_id,
        'final_pr': final_pr,
        'tasks': sum(1 for t in run['tasks'] if t['kind'] == 'plan'),
        'started_at': iso(started),
        'done_at': iso(done),
        'wall': {'to_done': to_done},
        'breakdown': breakdown_of(run, started, done, jsonl, jsonl_gap, config, gaps),
        'integration': integration_of(run, epic, gaps),
        'retries': retries_of(run, run_ended, gaps),
    }
    run_end = done if done is not None else (_number(epic['updated_at']) or now)
    record['subscription'], record['parallel'] = night_fields(run, epic_id, started, run_end, night_dir, gaps)
    record['gate_wait'] = gate_wait_of(night_dir, epic_id, started, gaps)
    record['human'] = human_of(jsonl, jsonl_gap, manual_fixes, done, now, gaps)
    record['gaps'] = gaps.render()
    return record


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
    record = build_record(args.epic, ao_home, config, busy, _expand(args.night), manual_fixes, now)
    write_record(state_path(_expand(args.state), args.epic), record)
    print(json.dumps(record, ensure_ascii=False))
    return 0


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
    return parser


def main(argv=None, run=None, now=None):
    try:
        args = build_parser().parse_args(argv)
    except SystemExit as e:
        return EXIT_FATAL if e.code else 0
    now = time.time() if now is None else now
    try:
        config = load_config(_expand(args.config))
        if args.command == 'epic':
            return cmd_epic(args, config, run or (lambda command: default_runner(command, config['gh_timeout_s'])),
                            now)
    except MetricsError as e:
        print('metrics: %s' % e, file=sys.stderr)
        return EXIT_FATAL
    return EXIT_FATAL


if __name__ == '__main__':
    sys.exit(main())

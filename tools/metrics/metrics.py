#!/usr/bin/env python3
"""Measurement of AO epic runs (spec docs/features/metrics/spec.md). Python 3.9+, standard library only.

  metrics.py epic EPIC [--ao-home DIR] [--manual-fixes FILE] [--no-gh] [COMMON]
  metrics.py backfill [--ao-root DIR] [COMMON]
  metrics.py trend [--last N] [--epic EPIC] [--json] [--no-gh] [COMMON]
  metrics.py render EPIC [--format report|comment] [COMMON]
  metrics.py waste AO_HOME [--max-qa-rounds N] [COMMON]
  metrics.py decide EPIC [--history] [COMMON]
  metrics.py effect KEY --at TIME [COMMON]

COMMON: --config FILE (default: config.json next to this script), --state DIR (default: $SHOP_METRICS_STATE or
~/.local/state/shop-metrics), --night DIR (measurements.jsonl and gate.jsonl; default: ~/.local/state/ao-night),
--busy-timeout-ms N (default: config.json), --repo OWNER/NAME (passed to every `gh` as -R; default: the repository of
the working directory).

epic: prints the record of the epic run as one JSON line and stores it in <state>/epics/<EPIC>.json. The run is the
directory --ao-home (default: ao-<EPIC> under $SHOP_METRICS_AO_ROOT or ~/.local/state). Fields from the AO database
and events (`orchestrator.db` is opened read only, `ao` is never run), from measurements.jsonl and gate.jsonl, and
from GitHub (`gh`, read only, also for `ci`, `regressions` and `found_after_merge`; --no-gh skips it). A source that is missing gives `null` and an entry in `gaps`, never
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

trend: the table of the last N merged epics (default: trend_last of config.json) with the columns per plan task, the
median of each column (nulls skipped), the position of --epic (default: the latest) against it and the notes of a change
of the CLI version or of the models against the previous epic. Before it, the regressions of the records whose window is
open (or never computed) are recomputed with `gh` and stored (--no-gh skips that; a `gh` error keeps the stored values
and ends with exit code 3 after the table).

render: the text of the acceptance report (--format report: bottlenecks first, without `review`) or of the comment
after the merge (--format comment: the lines `Interwencje człowieka`, `Czekanie na użytkownika` and `Ręczne poprawki: N`
that retro.py reads, then the block `Pomiar epiku`) from the stored record and the stored history. Only numbers,
identifiers and PR numbers of the record are written; the one free text is the list of manual fixes from --manual-fixes.

waste: reads the current state of the run AO_HOME (the database read only, a locked live database is tried again
after busy_timeout, DB_RETRIES times) and the stored records of other epics (the history) and prints the new alarms of
rules W1 (cost of an ended attempt), W2 (a running attempt that takes long), W3 (loop of attempts: a task's attempt after
`loop_failed_attempts` failed ones, a REPAIR after a REPAIR with no green CI between, QA round equal to
--max-qa-rounds, default 3) and W4 (cost of a task), one JSON line each (`alarm`, `key`, `epic`, `task`, `attempt`,
`push` of at most 200 characters, the numbers), then {"gaps": [...]} when something could not be checked. Thresholds
are in the `waste` section of config.json. The alarms sent are in <state>/waste.json ({"alarms": [{key, rule, epic,
task, attempt, at, push, ...}]}); read, filter, atomic write and printing happen under an exclusive flock on
<state>/waste.lock, so every key is printed once even with parallel sessions (written before printed: an alarm is never
printed twice). `epic` puts the alarms of the epic from waste.json in the field `waste`.

decide: the candidates of the decision rules D1-D4 (spec: Decyzje optymalizacyjne) for the stored record of EPIC, as one
JSON object: `candidates` (key, rule, data, proposal, expected_effect, verification; ordered by the expected saving of
wall-clock time, then of token value), `report` (the first `decisions.report_max`), `skipped` (rules that ran without a
proposal or lacked data, with the reason) and `gaps`. D1 reads only status, mode and subagent_model of the EVALS 2.0
`meta.json` files (config.json `evals`) and never proposes a change of a model. --history adds the candidates of every
merged epic and `keys` ({key: epics}). Stateless: reads the stored records, writes nothing, no `gh`.

effect: the measure of the rule of KEY (D1:<role>, D2:plan_split, D3:gate_wait, D4:<human_wait|ci|limit_wait|review>)
over the merged epics started after TIME (after window; ISO 8601 with an offset or Z) and the same number of epics
merged at or before it (before window): median, quartiles and counts, the difference in the unit and in percent, the
time to epic_done per plan task (3 epics each side), the CLI and model changes between the windows, and "za mało
danych" without a difference below the thresholds of config.json `decisions`. Stateless like decide.

Exit codes: 0 success (also without alarms); 2 nothing recorded or printed: bad arguments or config.json, missing AO
directory, unreadable or locked database, no epic row, unreadable --manual-fixes file, state not writable, waste.json
unreadable or of another shape (never overwritten) (message on stderr, nothing on stdout);
3 the record was printed and stored but a source failed (`gh` error; message on stderr, the gap is in `gaps`).
trend, render and decide also end with 2 without a stored record (or without the requested epic), effect with a bad key
or --at or without a stored record of a merged epic.
"""
import argparse
import fcntl
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


class WasteFileError(MetricsError):
    """waste.json cannot be used; `reason` is the message without the path (the path is in the message)."""

    def __init__(self, path, reason):
        super().__init__('%s: %s' % (path, reason))
        self.reason = reason


class DatabaseLocked(MetricsError):
    """The AO database stayed locked for longer than busy_timeout (a running AO holds short write locks)."""


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


DECISION_SHARES = ('d1_role_share', 'd4_human_wait_share', 'd4_ci_share', 'd4_limit_wait_share', 'd4_review_share')
DECISION_COUNTS = ('d2_failed_attempts', 'report_max', 'effect_min_tasks', 'effect_min_task_epics', 'effect_min_epics')
DECISION_POSITIVE = ('d2_work_multiple', 'd3_gate_wait_h')


def decisions_problem(decisions):
    """The first bad value of the `decisions` section as `key: expectation` (None when all are fine): a share is a
    fraction above 0 up to 1, a count a whole number of at least 1, the other thresholds numbers above 0."""
    for key in DECISION_SHARES:
        if not 0 < decisions[key] <= 1:
            return 'decisions.%s: oczekiwano ułamka większego od 0 i najwyżej 1' % key
    for key in DECISION_COUNTS:
        if not _is_int(decisions[key]) or decisions[key] < 1:
            return 'decisions.%s: oczekiwano liczby całkowitej, co najmniej 1' % key
    for key in DECISION_POSITIVE:
        if decisions[key] <= 0:
            return 'decisions.%s: oczekiwano liczby większej od 0' % key
    return None


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
    'ci_workflow': (lambda v: isinstance(v, str) and bool(v), 'nazwa pliku workflowu CI (np. ci.yml)'),
    'ci_jobs': (_string_list, 'niepusta lista nazw zadań CI (przedrostki grup, np. checks i e2e)'),
    'trend_last': (lambda v: _is_int(v) and v > 0, 'liczba epików (całkowita, dodatnia)'),
    'weekly_window_label': (lambda v: isinstance(v, str) and bool(v), 'etykieta okna tygodniowego z get_usage'),
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
    problem = decisions_problem(config['decisions'])
    if problem:
        raise MetricsError('config.json %s: klucz %s' % (path, problem))
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
        return DatabaseLocked('baza AO %s zablokowana (zajęta dłużej niż busy_timeout): %s' % (path, error))
    return MetricsError('nieczytelna baza AO %s: %s' % (path, error))


def select(con, table, columns, gaps):
    """Rows of `table` as dicts of the wanted columns; a missing column is None in every row, a gap and an entry of
    `gaps.lacks` (a field derived from it is null: None in an existing column is just a missing value)."""
    present = [row[1] for row in con.execute('PRAGMA table_info("%s")' % table)]
    use = [c for c in columns if c in present]
    for column in columns:
        if column not in present:
            gaps.add('%s.%s' % (table, column), 'brak kolumny albo tabeli w bazie AO')
            gaps.absent.add('%s.%s' % (table, column))
    if not present:
        gaps.tables.add(table)
    if not use:
        return []
    cursor = con.execute('SELECT %s FROM "%s"' % (', '.join('"%s"' % c for c in use), table))
    return [dict(zip(columns, [dict(zip(use, row)).get(c) for c in columns])) for row in cursor.fetchall()]


class Gaps:
    """Missing data of one record: (field, reason) pairs, written as `field: reason`."""

    def __init__(self):
        self.items = []
        self.absent = set()         # `table.column` of the AO database that is not there (see `select`)
        self.tables = set()         # the tables of the AO database that are not there (all their columns are absent)

    def lacks(self, *columns):
        """True when any of the `table.column` names is missing in the AO database."""
        return any(column in self.absent for column in columns)

    def lacks_table(self, table):
        """True when the table is missing in the AO database."""
        return table in self.tables

    def add(self, field, reason):
        if (field, reason) not in self.items:
            self.items.append((field, reason))

    def render(self):
        return ['%s: %s' % item for item in self.items]


# ------------------------------------------------------------------------------------------------------ the record

def lacks(run, *columns):
    """True when any of the `table.column` names is missing in the AO database of the run (`load_run`)."""
    return any(column in run['absent'] for column in columns)


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
    return {'epic': epic, 'tasks': tasks, 'attempts': attempts, 'events': events, 'absent': set(gaps.absent)}


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


EVENT_CATEGORY = {'cooldown_set': 'limit_wait', 'host_pause': 'host_pause', 'github_wait': 'github_wait'}


def category_intervals(run, done, jsonl, config, gaps):
    """{category: [(start, end)] or None} for the breakdown categories except idle (before clipping and
    precedence). None: the source of the category is missing."""
    events = run['events']
    epic_id = run['epic']['epic_id']
    task_ids = {t['task_id'] for t in run['tasks']}
    def source(*columns):
        return None if lacks(run, *columns) else []         # None: the AO database cannot tell this category
    found = {'work': source('attempts.started_at', 'attempts.ended_at'), 'human_wait': None,
             'limit_wait': source('events.data'), 'ci_wait': [], 'host_pause': source('events.data'),
             'github_wait': source('events.data', 'events.task_id', 'tasks.task_id')}
    for attempt in run['attempts'] if found['work'] is not None else []:
        start = _number(attempt['started_at'])
        if start is None:
            continue
        end = _number(attempt['ended_at'])
        found['work'].append((start, done if end is None else end))
    if jsonl is not None:
        found['human_wait'] = [(w['start'], done if w['end'] is None else w['end']) for w in ao_events.waits(jsonl)]
    for i, event in enumerate(events):
        kind, data, at = event['type'], event['data'], event['at']
        if found.get(EVENT_CATEGORY.get(kind), []) is None:     # the category has no source: its events are not read
            continue
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
    for name in found:
        if found[name] is None and name != 'human_wait':
            gaps.add('breakdown.%s' % name, 'brak kolumny źródła w bazie AO: czas tej kategorii jest w innych '
                                            'kategoriach i w idle')
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
    """The `retries` of the record. A column missing in the AO database makes the fields derived from it null (never
    zero): without the attempts table `total` too, without attempts.task_id `per_task`, without attempts.outcome or
    attempts.executor_status the classes of attempts and `failed_by_outcome`, without tasks.kind `repair` and `fix`."""
    no_table = gaps.lacks_table('attempts')
    unclassified = no_table or gaps.lacks('attempts.outcome', 'attempts.executor_status')
    no_task = gaps.lacks('attempts.task_id')
    attempts = {'total': None if no_table else 0, 'per_task': None if no_task else {},
                **{name: None if unclassified else 0 for name in ATTEMPT_CLASSES}}
    failed_by_outcome = None if unclassified else {}
    for attempt in run['attempts']:
        attempts['total'] += 1
        if not no_task:
            attempts['per_task'][attempt['task_id']] = attempts['per_task'].get(attempt['task_id'], 0) + 1
        if unclassified:
            continue
        name, detail = classify_attempt(attempt['outcome'], attempt['executor_status'], run_ended)
        attempts[name] += 1
        if name == 'failed':
            failed_by_outcome[detail] = failed_by_outcome.get(detail, 0) + 1
        elif name == 'unclassified':
            gaps.add('retries.attempts', 'próba %s/%s ma nieznany outcome %r' % (attempt['task_id'],
                                                                                  attempt['number'], detail))
    fix = None if lacks(run, 'tasks.kind') else sum(1 for t in run['tasks'] if t['kind'] == 'fix')
    if lacks(run, 'tasks.kind', 'tasks.task_id'):
        return {'attempts': attempts, 'failed_by_outcome': failed_by_outcome, 'repair': None, 'fix': fix}
    no_events = lacks(run, 'events.type', 'events.data', 'events.at')     # the cause of a repair is in its event
    repair = {'count': 0, 'final_pr_conflict': None if no_events else 0, 'ci_red': None if no_events else 0,
              'unknown': 0}
    repairing = {e['data'].get('task_id'): e['data'] for e in run['events'] if e['type'] == 'epic_repairing'}
    for task in run['tasks']:
        if task['kind'] != 'repair':
            continue
        repair['count'] += 1
        data = repairing.get(task['task_id'])
        if no_events:
            repair['unknown'] += 1
        elif data is None:
            repair['unknown'] += 1
            gaps.add('retries.repair', 'zadanie %s bez zdarzenia epic_repairing: przyczyna nieznana' % task['task_id'])
        elif data.get('final_pr') is not None:
            repair['final_pr_conflict'] += 1
        else:
            repair['ci_red'] += 1
    return {'attempts': attempts, 'failed_by_outcome': failed_by_outcome, 'repair': repair, 'fix': fix}


def integration_of(run, epic, gaps):
    if gaps.lacks('tasks.kind', 'attempts.task_id'):        # which attempts are integration ones is unknown
        return {'work_s': None, 'attempts': None, 'rounds': epic['round']}
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
# A CLI version, a model in `versions`, a role (subagent_type) and a denied tool name are stored only when they are
# identifiers (a role as `subagent`, a tool as `unknown` otherwise): the record holds no free text from the transcripts.
SAFE_NAME = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._+\-\[\]]{0,63}$')
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
      messages  {id: {role (subagent_type that is an identifier, or None), orchestrator (no parent), model, tokens}} (first line of an id)
      inits     [{version, model}]               results  [{output, cost, models}] (every `result`)
      retries   [retry_delay_ms]                 denials  {tool_name (or `unknown`): count}
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
                'role': role if isinstance(role, str) and SAFE_NAME.match(role) else None,
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
        name = line.get('tool_name') if isinstance(line.get('tool_name'), str) and SAFE_NAME.match(
            line.get('tool_name')) else 'unknown'
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


def value_sections(items, with_tasks=True):
    """The `value` of the record from items [(task, {role: {model: cell}} or None)]: total, by_role, by_model, by_task,
    by_role_model and by_task_role ({task: {role: cell}}, the value of a role in one task). An attempt without cells
    (no transcript) is an unknown part of its sections. Without `with_tasks` (the AO database does not say which task
    an attempt belongs to) by_task and by_task_role are None."""
    sections = {name: Section(name in ('by_role', 'by_role_model', 'by_task_role'))
                for name in ('total', 'by_role', 'by_model', 'by_task', 'by_role_model', 'by_task_role')}
    nothing = {'tokens': dict(ZERO_TOKENS), 'estimated': set(), 'usd': 0.0, 'shared': False, 'no_cli': False}
    for task, cells in items:
        if cells is None:
            sections['total'].mark_unknown('all')
            if with_tasks:
                sections['by_task'].mark_unknown(task)
            continue
        if not cells:                       # a CLI result without any usage: a real zero
            sections['total'].add('all', nothing)
            if with_tasks:
                sections['by_task'].add(task, nothing)
        for role, models in cells.items():
            for model, cell in models.items():
                sections['total'].add('all', cell)
                sections['by_role'].add(role, cell)
                sections['by_model'].add(model, cell)
                sections['by_role_model'].add((role, model), cell)
                if with_tasks:
                    sections['by_task'].add(task, cell)
                    sections['by_task_role'].add((task, role), cell)
    value = {'total': sections['total'].render('all') if sections['total'].cells else None}
    for name in ('by_role', 'by_model', 'by_task'):
        value[name] = {key: sections[name].render(key) for key in sorted(sections[name].cells)}
    nested = {}
    for role, model in sorted(sections['by_role_model'].cells):
        nested.setdefault(role, {})[model] = sections['by_role_model'].render((role, model))
    value['by_role_model'] = nested
    by_task_role = {}
    for task, role in sorted(sections['by_task_role'].cells):
        by_task_role.setdefault(task, {})[role] = sections['by_task_role'].render((task, role))
    value['by_task_role'] = by_task_role
    if not with_tasks:
        value['by_task'] = value['by_task_role'] = None
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


NO_TASK_REASON = 'brak kolumny attempts.task_id w bazie AO: nie wiadomo, do której próby należy transkrypt'


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
    no_task = lacks(run, 'attempts.task_id')            # which task an attempt belongs to is unknown
    by_task = None if no_task else {}
    for attempt in [] if no_task else attempts:
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
        data, reason = read_transcript(path) if path else (None, NO_TASK_REASON if no_task
                                                           else 'nieprawidłowy identyfikator próby')
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
    return {'by_task': by_task, 'attempts': entries, 'value': value_sections(items, not no_task), 'in_attempts': in_attempts,
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
            {'epics': sorted(stretches),
             'repairs': None if lacks(run, 'tasks.kind', 'tasks.task_id', 'attempts.task_id', 'attempts.started_at',
                                      'attempts.ended_at') else sorted(in_overlap)},
            stretches, span)


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

SHA_PATTERN = re.compile(r'^[0-9a-f]{7,64}$')
LOGIN_PATTERN = re.compile(r'^[A-Za-z0-9][A-Za-z0-9-]{0,38}$')
WRITE_ROLES = ('write', 'maintain', 'admin')


class GhError(Exception):
    """`gh` failed (cannot be run, timed out, exit code other than 0, or an answer that is not JSON). `not_found`:
    the answer was HTTP 404."""

    def __init__(self, message, not_found=False):
        super().__init__(message)
        self.not_found = not_found


def _gh_call(run, command, label):
    try:
        result = run(command)
    except (OSError, subprocess.TimeoutExpired) as e:
        raise GhError('%s: %s' % (label, getattr(e, 'strerror', None) or e))
    if result.returncode != 0:
        stderr = (result.stderr or '').strip()
        raise GhError('%s: kod %s: %s' % (label, result.returncode, stderr[:200]), 'HTTP 404' in stderr)
    try:
        return json.loads(result.stdout)
    except (ValueError, TypeError):
        raise GhError('%s: odpowiedź nie jest JSON' % label)


def gh_json(run, args, repo):
    return _gh_call(run, ['gh'] + args + (['-R', repo] if repo else []), 'gh %s' % ' '.join(args[:2]))


def gh_api(run, path, repo):
    """`gh api repos/<repo>/<path>` (read only): `gh api` has no -R, so the repository is in the path; without --repo
    gh expands the {owner}/{repo} placeholders from the repository of the working directory."""
    return _gh_call(run, ['gh', 'api', 'repos/%s/%s' % (repo or '{owner}/{repo}', path)], 'gh api %s' % path.split('?')[0])


def fetch_pr(run, number, repo):
    """{state, merged_at (epoch or None), issue (first closing issue or None), merge_sha (or None)} of a pull
    request."""
    data = gh_json(run, ['pr', 'view', str(number), '--json', 'state,mergedAt,closingIssuesReferences,mergeCommit'],
                   repo)
    try:
        merged = night_queue.parse_time(data['mergedAt']).timestamp() if data.get('mergedAt') else None
        issues = [i['number'] for i in data.get('closingIssuesReferences') or []]
        commit = (data.get('mergeCommit') or {}).get('oid')
        return {'state': data['state'], 'merged_at': merged, 'issue': issues[0] if issues else None,
                'merge_sha': commit if isinstance(commit, str) and SHA_PATTERN.match(commit) else None}
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


# ------------------------------------------------------------------------------- regressions, found after merge, CI

DAY = 86400


def _day(epoch):
    return datetime.fromtimestamp(epoch, timezone.utc).strftime('%Y-%m-%d')


def field_value(body, heading):
    """The answer of the issue form field `heading` (`### <heading>...` up to the next `###`), or None when the field is
    missing, empty or `_No response_`."""
    match = re.search(r'^###\s+%s[^\n]*$' % re.escape(heading), body or '', re.MULTILINE)
    if not match:
        return None
    rest = body[match.end():]
    following = re.search(r'^###\s', rest, re.MULTILINE)
    value = (rest[:following.start()] if following else rest).strip()
    return None if not value or value == '_No response_' else value


def names_epic(value, epic_id, pr):
    """Whether the field `Wprowadzone przez` names the epic (its id as a whole word) or its final PR (`#N`, `PR N`,
    a pull request URL)."""
    if re.search(r'(?<![\w-])%s(?![\w-])' % re.escape(epic_id), value, re.IGNORECASE):
        return True
    return pr is not None and bool(re.search(r'(?:(?<!\w)#|\bPR\s*#?\s*|/pull/)%d(?!\d)' % pr, value, re.IGNORECASE))


def has_write_access(run, login, repo, cache):
    """Whether `login` may write to the repository (role write, maintain or admin; spec: Regresje po merge'u). A
    login that cannot be a GitHub login is never asked about. A 404 (not a collaborator) is no access; any other
    error is a GhError."""
    if not isinstance(login, str) or not LOGIN_PATTERN.match(login):
        return False
    if login not in cache:
        try:
            data = gh_api(run, 'collaborators/%s/permission' % login, repo)
        except GhError as e:
            if not e.not_found:
                raise
            data = {}
        role = data.get('role_name') or data.get('permission') if isinstance(data, dict) else None
        cache[login] = role in WRITE_ROLES
    return cache[login]


def _created(issue):
    try:
        return night_queue.parse_time(issue['createdAt']).timestamp()
    except (KeyError, TypeError, ValueError, AttributeError):
        return None


def _labels(issue):
    return {l.get('name') for l in issue.get('labels') or [] if isinstance(l, dict)}


def _issue_list(run, repo, label, low, high):
    issues = gh_json(run, ['issue', 'list', '--label', label, '--state', 'all', '--search',
                           'created:%s..%s' % (_day(low), _day(high)), '--json', 'number,body,author,createdAt,labels',
                           '--limit', str(ISSUE_LIMIT)], repo)
    if not isinstance(issues, list):
        raise GhError('gh issue list: nieoczekiwany kształt odpowiedzi')
    return [i for i in issues if isinstance(i, dict) and _is_int(i.get('number')) and label in _labels(i)
            and _created(i) is not None and low <= _created(i) <= high], len(issues) >= ISSUE_LIMIT


ISSUE_LIMIT = 200
COMMIT_LIMIT = 100              # commits per page of `gh api .../commits`
COMMIT_PAGE_LIMIT = 10          # pages read at most: 1000 commits of the window


def red_ci(run, repo, config, sha):
    runs = gh_json(run, ['run', 'list', '--workflow', config['ci_workflow'], '--commit', sha, '--json',
                         'conclusion,event', '--limit', '50'], repo)
    if not isinstance(runs, list):
        raise GhError('gh run list: nieoczekiwany kształt odpowiedzi')
    return sum(1 for r in runs if isinstance(r, dict) and r.get('event') == 'push' and r.get('conclusion') == 'failure')


def commits_of_window(run, repo, low, high, gaps, truncated):
    """The commits of `main` in the window, page by page (`page`=1, 2, ...) until a page shorter than COMMIT_LIMIT or
    an empty one. The window counts as cut (a gap and `truncated`) only when COMMIT_PAGE_LIMIT pages were read and the
    last one was full: the list may go on. A gh error on any page is a GhError for the whole source."""
    commits, page = [], 0
    while page < COMMIT_PAGE_LIMIT:
        page += 1
        batch = gh_api(run, 'commits?sha=main&since=%s&until=%s&per_page=%d&page=%d' % (iso(low), iso(high), COMMIT_LIMIT,
                                                                                        page), repo)
        if not isinstance(batch, list):
            raise GhError('gh api commits: nieoczekiwany kształt odpowiedzi')
        commits.extend(batch)
        if len(batch) < COMMIT_LIMIT:
            return commits
    truncated.append('regressions.reverts')
    gaps.add('regressions.reverts', 'lista commitów main obcięta do %d stron po %d (limit %d commitów): revert mógł '
             'zostać pominięty' % (COMMIT_PAGE_LIMIT, COMMIT_LIMIT, COMMIT_PAGE_LIMIT * COMMIT_LIMIT))
    return commits


def reverts_of(run, repo, final_pr, merge_sha, low, high, gaps, truncated):
    commits = commits_of_window(run, repo, low, high, gaps, truncated)
    mention = re.compile(r'#%d(?!\d)' % final_pr)
    found = []
    for entry in commits:
        try:
            message = entry['commit']['message']
            when = night_queue.parse_time(entry['commit']['committer']['date']).timestamp()
            sha = entry['sha']
        except (KeyError, TypeError, ValueError, AttributeError):
            continue
        if (isinstance(message, str) and message.startswith('Revert') and mention.search(message) and low <= when <= high
                and sha != merge_sha and isinstance(sha, str) and SHA_PATTERN.match(sha)):
            found.append(sha)
    return found


EDITOR_QUERY = ('query($owner: String!, $name: String!, $number: Int!) { repository(owner: $owner, name: $name) '
                '{ issue(number: $number) { lastEditedAt editor { login } } } }')
REPO_PATTERN = re.compile(r'^([A-Za-z0-9][A-Za-z0-9-]{0,38})/([A-Za-z0-9._-]{1,100})$')


_REPOSITORIES = {}      # {runner: (owner, name)} of `gh repo view`: one call per process (a runner lives as long)


def repository_of(run, repo):
    """(owner, name) of the repository: from --repo, else asked once per runner (so once per process) of `gh repo
    view` (read only); a failed answer is not remembered. A given --repo that is not OWNER/NAME is a GhError, never a
    silent question about the working directory. `gh api` expands the {owner} and {repo} placeholders only in the
    endpoint and in -F fields, not in the raw -f fields a GraphQL variable needs, so the real names are given."""
    if repo:
        match = REPO_PATTERN.match(repo)
        if not match:
            raise GhError('--repo: niepoprawna nazwa repozytorium (oczekiwano OWNER/NAZWA)')
        return match.groups()
    if run in _REPOSITORIES:
        return _REPOSITORIES[run]
    data = gh_json(run, ['repo', 'view', '--json', 'owner,name'], None)
    try:
        match = REPO_PATTERN.match('%s/%s' % (data['owner']['login'], data['name']))
    except (KeyError, TypeError, AttributeError):
        match = None
    if not match:
        raise GhError('gh repo view: nieoczekiwany kształt odpowiedzi')
    _REPOSITORIES[run] = match.groups()
    return _REPOSITORIES[run]


def last_editor(run, owner, name, number):
    """The login of the last editor of the issue (`editor` of the GraphQL `Issue`, a query only), or None when it was
    never edited. The values are given as raw -f fields (a numeric-looking name stays a string); the number as -F."""
    data = _gh_call(run, ['gh', 'api', 'graphql', '-f', 'query=%s' % EDITOR_QUERY, '-f', 'owner=%s' % owner, '-f',
                          'name=%s' % name, '-F', 'number=%d' % number], 'gh api graphql')
    try:
        issue = data['data']['repository']['issue']
        editor = issue.get('editor') if issue is not None else None
        return editor.get('login') if editor is not None else None
    except (KeyError, TypeError, AttributeError):
        raise GhError('gh api graphql: nieoczekiwany kształt odpowiedzi')


def bug_issues(run, repo, epic_id, final_pr, low, high, gaps, truncated):
    """The numbers of the `bug` issues of the window whose `Wprowadzone przez` names the epic or its PR and that were
    created, or last edited, by someone who may write to the repository (the form is public: a foreign issue that no
    writer touched does not count)."""
    issues, cut = _issue_list(run, repo, 'bug', low, high)
    if cut:
        truncated.append('regressions.issues')
        gaps.add('regressions.issues', 'lista issues obcięta do %d' % ISSUE_LIMIT)
    cache, found, place = {}, [], None
    for issue in issues:
        if 'agent' in _labels(issue):
            continue                # a proposal of /sweep is counted in found_after_merge, not as a regression
        value = field_value(issue.get('body'), 'Wprowadzone przez')
        if value is None or not names_epic(value, epic_id, final_pr):
            continue
        author = issue.get('author')
        if has_write_access(run, author.get('login') if isinstance(author, dict) else None, repo, cache):
            found.append(issue['number'])
            continue
        if place is None:
            place = repository_of(run, repo)
        if has_write_access(run, last_editor(run, place[0], place[1], issue['number']), repo, cache):
            found.append(issue['number'])
    return sorted(found)


def sweep_issues(run, repo, epic_id, low, high, gaps, truncated):
    """The numbers of the issues of the `/sweep` proposals of the epic (label `agent`, whose `Źródło` names the review
    of the epic) created in the window. The `agent` label is set by people with write access only."""
    issues, cut = _issue_list(run, repo, 'agent', low, high)
    if cut:
        truncated.append('found_after_merge')
        gaps.add('found_after_merge', 'lista issues obcięta do %d' % ISSUE_LIMIT)
    source = re.compile(r'Przebieg\s+`/sweep`\s+\d{4}-\d{2}-\d{2}-[0-9a-f]{7}-%s(?![\w-])' % re.escape(epic_id))
    return sorted(i['number'] for i in issues if isinstance(i.get('body'), str) and source.search(i['body']))


def regressions_of(run, repo, config, epic_id, final_pr, merge_sha, merged, now, previous, gaps, errors):
    """(regressions, found_after_merge) of the epic in the window after the merge of its final PR (spec: Regresje po
    merge'u). A source that fails is None with a gap and the message in `errors`; the stored value of that part
    (`previous`: the stored record) is kept then."""
    until = merged + config['regression_window_days'] * DAY
    merge_sha = merge_sha if isinstance(merge_sha, str) and SHA_PATTERN.match(merge_sha) else None
    result = {'window_days': config['regression_window_days'], 'until': iso(until), 'open': None,
              'complete': None, 'checked_at': iso(now), 'merge_commit': merge_sha}
    truncated = []          # the sources whose list was cut at its limit
    sources = (
        ('ci_red', 'regressions.ci_red', ('regressions', 'ci_red'),
         (lambda: red_ci(run, repo, config, merge_sha)) if merge_sha else None),
        ('reverts', 'regressions.reverts', ('regressions', 'reverts'),
         lambda: reverts_of(run, repo, final_pr, merge_sha, merged, until, gaps, truncated)),
        ('issues', 'regressions.issues', ('regressions', 'issues'),
         lambda: bug_issues(run, repo, epic_id, final_pr, merged, until, gaps, truncated)),
        ('found', 'found_after_merge', ('found_after_merge', 'issues'),
         lambda: sweep_issues(run, repo, epic_id, merged, until, gaps, truncated)),
    )
    values, complete = {}, True
    for key, field, path, compute in sources:
        values[key] = None
        if compute is None:
            complete = False
            gaps.add(field, 'brak commita merge\'u końcowego PR')
            continue
        try:
            values[key] = compute()
        except GhError as e:
            complete = False
            errors.append(str(e))
            gaps.add(field, 'błąd gh: %s' % e)
            values[key] = _get(previous, path)
            if values[key] is not None:
                gaps.add(field, 'źródło niedostępne: zachowano poprzednią wartość')
    # The window closes only after a run in which every source answered in full: a failed or cut one leaves it open.
    complete = complete and not truncated
    result.update(ci_red=values['ci_red'], reverts=values['reverts'], issues=values['issues'], complete=complete,
                  open=now < until or not complete)
    counts = [None if values['ci_red'] is None else min(values['ci_red'], 1),
              None if values['reverts'] is None else len(values['reverts']),
              None if values['issues'] is None else len(values['issues'])]
    result['count'] = None if None in counts else sum(counts)
    found = values['found']
    return result, None if found is None else {'issues': found, 'count': len(found)}


def job_seconds(jobs, prefix):
    """The wall time of the CI jobs named `prefix` or `prefix <part>` (the parts of a group), from the first start to
    the last end; None without a finished job."""
    started, ended = [], []
    for item in jobs:
        name = item.get('name') if isinstance(item, dict) else None
        if isinstance(name, str) and (name == prefix or name.startswith(prefix + ' ')):
            try:
                started.append(night_queue.parse_time(item['startedAt']).timestamp())
                ended.append(night_queue.parse_time(item['completedAt']).timestamp())
            except (KeyError, TypeError, ValueError, AttributeError):
                continue
    return round(max(ended) - min(started), 3) if started and ended and max(ended) >= min(started) else None


def ci_entry(run, repo, config, pr, branch):
    runs = gh_json(run, ['run', 'list', '--workflow', config['ci_workflow'], '--branch', branch, '--event',
                         'pull_request', '--json', 'databaseId,createdAt,conclusion', '--limit', '100'], repo)
    if not isinstance(runs, list):
        raise GhError('gh run list: nieoczekiwany kształt odpowiedzi')
    runs = [r for r in runs if isinstance(r, dict) and _is_int(r.get('databaseId'))]
    entry = {'pr': pr, 'runs': len(runs)}
    for name in config['ci_jobs']:
        entry['%s_s' % name] = None
    if runs:
        last = max(runs, key=lambda r: (str(r.get('createdAt')), r['databaseId']))
        jobs = gh_json(run, ['run', 'view', str(last['databaseId']), '--json', 'jobs'], repo)
        jobs = jobs.get('jobs') if isinstance(jobs, dict) else None
        for name in config['ci_jobs']:
            entry['%s_s' % name] = job_seconds(jobs or [], name)
    return entry


def ci_of(run, repo, config, epic_id, tasks, final_pr, previous, gaps, errors):
    """{final, tasks}: for the final PR and every task PR the wall time of the last CI run of the groups of jobs
    `ci_jobs` and the number of CI runs of its branch (the retries). None with a gap on a gh error (the stored value is
    kept)."""
    try:
        found, empty = {'final': None, 'tasks': {}}, []
        if final_pr is not None:
            found['final'] = ci_entry(run, repo, config, final_pr, 'epic/%s' % epic_id)
            if not found['final']['runs']:
                empty.append('epic/%s' % epic_id)
        for task in sorted(tasks, key=lambda t: str(t['task_id'])):
            if _is_int(task['pr_number']) and isinstance(task['task_id'], str) and SAFE_NAME.match(task['task_id']):
                found['tasks'][task['task_id']] = ci_entry(run, repo, config, task['pr_number'],
                                                           'task/%s' % task['task_id'])
                if not found['tasks'][task['task_id']]['runs']:
                    empty.append('task/%s' % task['task_id'])
        if empty:
            gaps.add('ci', 'brak przebiegów CI gałęzi: %s' % ', '.join(empty[:5]) + (', …' if len(empty) > 5 else ''))
        return found
    except GhError as e:
        errors.append(str(e))
        gaps.add('ci', 'błąd gh: %s' % e)
        return None


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
              ('parallel', 'sync_prs'), ('human', 'manual_fixes'), ('ci',), ('regressions',), ('found_after_merge',),
              ('waste',))


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


def github_derived(run, no_gh, repo, config, epic_id, tasks, final_pr, pr, merged, now, previous, gaps, errors):
    """{ci, regressions, found_after_merge} of the record from GitHub (all None with a gap under --no-gh, on an error
    or while the final PR is not merged). `merged`: the merge time (epoch) of the final PR, stored or fresh."""
    fields = {'ci': None, 'regressions': None, 'found_after_merge': None}
    if no_gh:
        for field in fields:
            gaps.add(field, 'pominięto gh (--no-gh)')
        return fields
    fields['ci'] = ci_of(run, repo, config, epic_id, tasks, final_pr, previous, gaps, errors)
    if merged is None or final_pr is None:
        for field in ('regressions', 'found_after_merge'):
            gaps.add(field, 'końcowy PR nie jest zmergowany albo jego stan nieznany: okno regresji nie zaczęło się')
        return fields
    sha = pr['merge_sha'] if pr else _get(previous, ('regressions', 'merge_commit'))
    fields['regressions'], fields['found_after_merge'] = regressions_of(
        run, repo, config, epic_id, final_pr, sha, merged, now, previous, gaps, errors)
    return fields


def build_record(epic_id, ao_home, config, busy_timeout_ms, night_dir, manual_fixes, now, run=None, no_gh=True,
                 repo=None, previous=None, pr=None, records=None, ao_root=None,
                 waste=(None, 'brak pliku waste.json')):
    """(record, errors): the record of the epic run and the messages of failed GitHub calls (the record is complete
    anyway: a failed source is null and a gap). records: {epic: stored record} of the other epics (the history of the
    rates of models for attempts without a CLI result, and the overlapping epics of `subscription.share`); ao_root: the
    directory of the run directories `ao-<epic>` (the overlapping epics without a record are computed from them;
    default: the parent of ao_home). waste: (alarms of the epic or None, reason of None) of `waste_of_epic`."""
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
        'tasks': None if gaps.lacks('tasks.kind') else sum(1 for t in ao_run['tasks'] if t['kind'] == 'plan'),
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
        'waste': waste[0],
    }
    errors = github['errors']
    record.update(github_derived(run, no_gh, repo, config, epic_id, ao_run['tasks'], final_pr, github['pr'], known_merge,
                                 now, previous, gaps, errors))
    if waste[0] is None:
        gaps.add('waste', waste[1])
    keep_stored_values(record, previous, gaps)
    record['bottlenecks'] = bottlenecks_of(record, history_of(record, records), config, gaps)
    record['gaps'] = gaps.render()
    return record, errors


# ------------------------------------------------------------------------------------------------- bottlenecks

# The decision rule of a candidate (spec: Wąskie gardła): D2 for a plan task, D3 for gate_wait, D4 for the waiting
# categories and review; None for the others.
CATEGORY_RULES = {('breakdown', 'human_wait'): 'D4', ('breakdown', 'ci_wait'): 'D4', ('breakdown', 'limit_wait'): 'D4',
                  ('in_attempts', 'in_attempts.ci_watch'): 'D4', ('gate_wait', 'gate_wait'): 'D3', ('review', 'review'): 'D4'}


# The subject (the key of the candidate of `decide`) a bottleneck is about, by its kind and name; a plan task is D2.
BOTTLENECK_SUBJECTS = {('breakdown', 'human_wait'): 'D4:human_wait', ('breakdown', 'limit_wait'): 'D4:limit_wait',
                       ('breakdown', 'ci_wait'): 'D4:ci', ('in_attempts', 'in_attempts.ci_watch'): 'D4:ci',
                       ('gate_wait', 'gate_wait'): 'D3:gate_wait', ('review', 'review'): 'D4:review'}


def history_of(record, records):
    """The stored records of the other epics merged before this one (all merged ones while it is not merged): the
    history the medians come from."""
    try:
        merged = night_queue.parse_time(record['merged_at']).timestamp()
    except (KeyError, TypeError, ValueError, AttributeError):
        merged = None
    found = []
    for other in records.values():
        if other.get('epic') == record.get('epic'):
            continue
        try:
            when = night_queue.parse_time(other['merged_at']).timestamp()
        except (KeyError, TypeError, ValueError, AttributeError):
            continue
        if merged is None or when < merged:
            found.append(other)
    return found


def bottlenecks_of(record, history, config, gaps, include_review=True):
    """The bottlenecks of an epic (spec: Wąskie gardła): at most `max_items` items with the largest excess of time over
    the median of the history, at least `min_excess_h` hours, in descending order. Candidates: the `breakdown`
    categories except `work`, the tasks (against the median of their kind), the `in_attempts` categories (with the
    part that is `repeat_runs`), gate_wait and, when `include_review`, review. Category medians are per plan task
    (scaled to the number of tasks of this epic). Every item gets its potential effect (add_effects)."""
    settings = config['bottlenecks']
    tasks = record.get('tasks')
    if len(history) < settings['min_history']:
        gaps.add('bottlenecks', 'mediana z %d zmergowanych epików (mniej niż %d)' % (len(history), settings['min_history']))
    candidates = []

    def add(kind, name, value, median_s, denominator, share_of, repeat_s=None):
        excess = value - median_s
        item = {'kind': kind, 'name': name, 'value_s': round(value, 3), 'median_s': round(median_s, 3),
                'excess_h': round(excess / HOUR, 3),
                'share': round(excess / denominator, 4) if _is_number(denominator) and denominator > 0 else None,
                'share_of': share_of, 'rule': CATEGORY_RULES.get((kind, name))}
        if kind == 'in_attempts':
            item['repeat_h'] = None if repeat_s is None else round(min(repeat_s, max(excess, 0.0)) / HOUR, 3)
        candidates.append((excess, item))

    def per_task_median(getter):
        values = [getter(h) / h['tasks'] for h in history if _is_number(getter(h)) and _is_int(h.get('tasks'))
                  and h['tasks'] > 0]
        return median(values) * tasks if values else None

    to_done = _get(record, ('wall', 'to_done'))
    scaled = _is_int(tasks) and tasks > 0

    def category(kind, name, value, getter, denominator, share_of, repeat_s=None):
        if not scaled or not _is_number(value):
            return
        middle = per_task_median(getter)
        if middle is not None:
            add(kind, name, value, middle, denominator, share_of, repeat_s)

    for name in config['breakdown_order']:
        if name != 'work':
            category('breakdown', name, _get(record, ('breakdown', name)), lambda r, n=name: _get(r, ('breakdown', n)),
                     to_done, 'to_done')
    kinds = {}
    for other in history:
        for entry in (other.get('by_task') or {}).values():
            if isinstance(entry, dict) and entry.get('kind') and _is_number(entry.get('work')):
                kinds.setdefault(entry['kind'], []).append(entry['work'])
    for task, entry in sorted((record.get('by_task') or {}).items()):
        if isinstance(entry, dict) and _is_number(entry.get('work')) and kinds.get(entry.get('kind')):
            add('task', task, entry['work'], median(kinds[entry['kind']]), to_done, 'to_done')
            candidates[-1][1]['rule'] = 'D2' if entry['kind'] == 'plan' else None
    for name in (record.get('in_attempts') or {}).get('categories', {}):
        category('in_attempts', 'in_attempts.%s' % name, _get(record, ('in_attempts', 'categories', name, 'seconds')),
                 lambda r, n=name: _get(r, ('in_attempts', 'categories', n, 'seconds')), to_done, 'to_done',
                 _get(record, ('in_attempts', 'repeat_runs', 'by_category', name, 'seconds')))
    gate = _get(record, ('gate_wait', 'wait_s'))
    if gate is None:
        gaps.add('bottlenecks.gate_wait', 'brak wpisów epiku w gate.jsonl: kandydat pominięty')
    else:
        category('gate_wait', 'gate_wait', gate, lambda r: _get(r, ('gate_wait', 'wait_s')),
                 gate + to_done if _is_number(to_done) else None, 'gate_wait+to_done')
    if include_review:
        category('review', 'review', _get(record, ('wall', 'review')), lambda r: _get(r, ('wall', 'review')),
                 _get(record, ('wall', 'to_merge')), 'to_merge')
    keep = [(excess, item) for excess, item in candidates if excess >= settings['min_excess_h'] * HOUR]
    keep.sort(key=lambda pair: (-pair[0], pair[1]['kind'], pair[1]['name']))
    items = [item for _, item in keep[:int(settings['max_items'])]]
    add_effects(items, record, history, config)
    return items


def add_effects(items, record, history, config):
    """The potential optimization effect of every item (spec: Wąskie gardła): `effect_h` is the expected wall-clock
    saving in hours of the candidate that `decide` has for the same rule and subject (`effect_rule` is its key; a plan
    task gets its own time over the median); without such a candidate `effect_h` is the excess as an upper bound and
    `effect_rule` is None („bez reguły decyzji”). Two items of the same subject (`ci_wait` and `ci_watch`) show the same
    effect: it is not additive."""
    found = {c['key']: c for c in rule_candidates(record, history, config, Gaps())[0]}
    for item in items:
        key = D2_KEY if item['kind'] == 'task' and item['rule'] == 'D2' else BOTTLENECK_SUBJECTS.get(
            (item['kind'], item['name']))
        candidate, saving = found.get(key), None
        if candidate is not None and key == D2_KEY:
            saving = next((t['excess_h'] for t in candidate['data']['tasks'] if t['task'] == item['name']), None)
        elif candidate is not None:
            saving = candidate['expected_effect']['wall_h']
        item['effect_rule'] = key if saving is not None else None
        item['effect_h'] = round(saving if saving is not None else item['excess_h'], 3)


# ----------------------------------------------------------------------------------------------------- decisions
#
# `decide` (spec: Decyzje optymalizacyjne) is stateless: it builds the candidates of the rules from a stored record,
# the stored history and, for D1, the EVALS 2.0 results. A candidate is a rule that fired and has a proposal; a rule
# that ran without a proposal or could not run for lack of data is in `skipped` with its reason.

# The reason of a wait of the gate that comes from the file rule (night_queue.check_files, steps 4-5): only a WAIT
# writes `waiting` (so a reason in `gate_wait.reasons`), and the file rule waits only when the scope of an epic in
# progress is not readable: `<epik> w toku bez zakresu: ...` (MissingScope). A SKIP for common files (`wspólne pliki z
# <epik>: ...`) has `waiting: null`: it is no wait of the epic, so no reason of `gate_wait` and no D3. The other
# reasons (`dwa epiki aktywne`, `pamięć Dockera`, `stan limitów`, `równoległość wstrzymana`, ...) are not the file rule.
FILE_RULE_REASON = re.compile(r'\sw toku bez zakresu(?::|$)')
MEASURES = {'D1': 'wartość roli na zadanie (USD)', 'D2': '`work` zadania planu (h)', 'D3': '`gate_wait` epiku (h)',
            'D4': 'udział kategorii czekania w jej mianowniku (pp)'}
D2_KEY = 'D2:plan_split'
D3_KEY = 'D3:gate_wait'


def make_candidate(key, data, proposal, wall_h, value_usd=None, estimated=False):
    """A candidate of a rule: `key` is the rule and its subject (the same for the same rule and subject in every epic),
    `expected_effect` the saving of wall-clock time in hours and/or of token value in USD (None where the rule says
    nothing), `verification` the measure of the rule and the command that computes it after the fix."""
    rule = key.split(':', 1)[0]
    return {'key': key, 'rule': rule, 'data': data, 'proposal': proposal,
            'expected_effect': {'wall_h': None if wall_h is None else round(wall_h, 3),
                                'value_usd': None if value_usd is None else round(value_usd, 4),
                                'estimated': bool(estimated)},
            'verification': "%s: `metrics.py effect %s --at <czas merge'u poprawki>`" % (MEASURES[rule], key)}


def order_candidates(candidates):
    """Spec: the expected wall-clock saving descending, then the token value descending (unknown is 0), then the key."""
    return sorted(candidates, key=lambda c: (-(c['expected_effect']['wall_h'] or 0.0),
                                             -(c['expected_effect']['value_usd'] or 0.0), c['key']))


def skip_rule(skipped, key, reason):
    skipped.append({'key': key, 'reason': reason})


def d4_candidates(record, config, skipped):
    """D4: the share of a waiting category in its denominator reaches its threshold: human_wait and limit_wait in
    to_done, ci (`ci_wait` plus `in_attempts.ci_watch`) in `work`, review in to_merge (known only after the merge).
    The effect is the time of the category (the share times its denominator); for `ci` the waiting time itself, never
    more."""
    settings = config['decisions']
    found = []

    def check(subject, parts, denominator, denominator_name, threshold, proposal):
        key = 'D4:%s' % subject
        if not all(_is_number(p) for p in parts) or not _is_number(denominator) or denominator <= 0:
            return skip_rule(skipped, key, 'brak danych: %s' % ', '.join(
                [n for n, p in zip(parts_names[subject], parts) if not _is_number(p)]
                + ([denominator_name] if not _is_number(denominator) or denominator <= 0 else [])))
        seconds = sum(parts)
        share = seconds / denominator
        if share < threshold:
            return
        data = {'seconds': round(seconds, 3), 'share': round(share, 4), '%s_s' % denominator_name: round(denominator, 3),
                'threshold': threshold}
        found.append(make_candidate(key, data, proposal, share * denominator / HOUR))
        return data

    parts_names = {'human_wait': ['human_wait'], 'limit_wait': ['limit_wait'], 'ci': ['ci_wait', 'in_attempts.ci_watch'],
                   'review': ['review']}
    to_done, work = _get(record, ('wall', 'to_done')), _get(record, ('breakdown', 'work'))
    check('human_wait', [_get(record, ('breakdown', 'human_wait'))], to_done, 'to_done', settings['d4_human_wait_share'],
          'Skrócić czekanie na użytkownika: zebrać pytania przed startem epiku i uruchamiać go o godzinie, '
          'w której użytkownik odpowiada.')
    check('limit_wait', [_get(record, ('breakdown', 'limit_wait'))], to_done, 'to_done',
          settings['d4_limit_wait_share'],
          'Zaplanować start epiku (planowanie nocy) tak, żeby mieścił się w oknie limitów bez przerwy na cooldown.')
    ci = [_get(record, ('breakdown', 'ci_wait')), _get(record, ('in_attempts', 'categories', 'ci_watch', 'seconds'))]
    data = check('ci', ci, work, 'work', settings['d4_ci_share'],
                 'Skrócić czekanie na CI: sprawdzić, które przebiegi CI zadań i końcowego PR trwają najdłużej '
                 'i ile jest ponowień.')
    if data is not None:
        data['ci_wait_s'], data['ci_watch_s'] = round(ci[0], 3), round(ci[1], 3)
    if _get(record, ('merged_at',)) is None or _get(record, ('wall', 'review')) is None:
        skip_rule(skipped, 'D4:review', "review jest znany dopiero po merge'u końcowego PR")
    else:
        check('review', [_get(record, ('wall', 'review'))], _get(record, ('wall', 'to_merge')), 'to_merge',
              settings['d4_review_share'],
              'Ustalić porę oddania epiku do odbioru i rozmiar końcowego PR, żeby odbiór nie czekał.')
    return found


def d3_candidates(record, config, skipped):
    """D3: `gate_wait` of at least `d3_gate_wait_h` hours because of the file rule of the gate (a reason of
    `gate_wait.reasons` matching FILE_RULE_REASON). The effect is the whole wait."""
    wait = _get(record, ('gate_wait', 'wait_s'))
    if not _is_number(wait):
        return skip_rule(skipped, D3_KEY, 'brak danych: gate_wait (brak wpisów epiku w gate.jsonl)')
    if wait < config['decisions']['d3_gate_wait_h'] * HOUR:
        return []
    reasons = [r for r in _get(record, ('gate_wait', 'reasons')) or [] if isinstance(r, str)]
    files = [r for r in reasons if FILE_RULE_REASON.search(r)]
    if not files:
        return skip_rule(skipped, D3_KEY, 'czekanie w bramce z innego powodu niż reguła plików')
    return [make_candidate(D3_KEY, {'wait_s': round(wait, 3), 'file_rule_reasons': len(files),
                                    'reasons': len(reasons)},
                           'Zmienić linię `Zakres:` epiku przy przygotowaniu albo regułę plików bramki '
                           '(docs/features/parallel-work/spec.md, „Bramka”).', wait / HOUR)]


def d2_candidates(record, history, config, skipped, gaps):
    """D2: a plan task with `work` over `d2_work_multiple` times the median of plan tasks in the history, or with at
    least `d2_failed_attempts` failed attempts. One candidate for the epic (the key is the same in every epic, a task
    has another id in each); the effect is the time of the tasks over the median."""
    settings = config['decisions']
    by_task = record.get('by_task')
    if not isinstance(by_task, dict):
        return skip_rule(skipped, D2_KEY, 'brak danych: by_task')
    works = [e['work'] for h in history for e in (h.get('by_task') or {}).values()
             if isinstance(e, dict) and e.get('kind') == 'plan' and _is_number(e.get('work'))]
    middle = median(works) if works else None
    if middle is None:
        gaps.add('decide.D2', 'brak historii mediany work zadań planu: kryterium czasu pominięte')
    ended = record.get('done_at') is not None or record.get('merged_at') is not None
    failed = {}
    counted = _get(record, ('retries', 'attempts'))
    classes_unknown = isinstance(counted, dict) and 'failed' in counted and counted['failed'] is None
    if classes_unknown:         # the AO database had no outcome or executor_status: no attempt is failed or not
        gaps.add('decide.D2', 'klasy prób nieznane (retries.attempts.failed null): kryterium nieudanych prób pominięte')
    for attempt in [] if classes_unknown else record.get('attempts') or []:
        if isinstance(attempt, dict) and classify_attempt(attempt.get('outcome'), attempt.get('executor_status'),
                                                          ended)[0] == 'failed':
            failed[attempt.get('task')] = failed.get(attempt.get('task'), 0) + 1
    tasks = []
    for task, entry in sorted(by_task.items()):
        if not isinstance(entry, dict) or entry.get('kind') != 'plan':
            continue
        work = entry.get('work')
        reasons = []
        if middle is not None and _is_number(work) and work > settings['d2_work_multiple'] * middle:
            reasons.append('work')
        if failed.get(task, 0) >= settings['d2_failed_attempts']:
            reasons.append('failed_attempts')
        if reasons:
            over = max(work - middle, 0.0) if middle is not None and _is_number(work) else None
            tasks.append({'task': task, 'work_h': None if not _is_number(work) else round(work / HOUR, 3),
                          'excess_h': None if over is None else round(over / HOUR, 3),
                          'failed_attempts': failed.get(task, 0), 'reasons': reasons})
    if not tasks:
        return []
    wall = sum(t['excess_h'] for t in tasks if t['excess_h'] is not None)
    return [make_candidate(D2_KEY, {'tasks': tasks, 'median_h': None if middle is None else round(middle / HOUR, 3),
                                    'history_epics': len(history)},
                           'Dodać do /planner regułę podziału zadania z cechami: liczba ścieżek `scope`, ryzyko, '
                           'aplikacje, liczba zmienionych plików.', wall)]


AGENT_EXCLUDED_ROLES = ('delivery', 'integration', OTHER_ROLE, SUBAGENT)    # the orchestrators and what is no agent
META_LIMIT = 1 << 20


class EvalsResults:
    """The EVALS 2.0 runs of D1, read once and only when a rule needs them: from `<results_dir>/*/runs/*/meta.json`
    only `status`, `mode` and `subagent_model` (labels, reports and the other fields of meta.json are never read, and
    no text of a run is kept). `runs` is None, with the reason in `gaps`, when there is no directory or no readable
    meta.json; an unreadable file or one without these fields is skipped and counted in `gaps`."""

    def __init__(self, config):
        self.settings = config['evals']
        self.loaded = False
        self.requests = 0                    # how many times a rule asked for the runs (the scan itself runs once)
        self.runs = None
        self.gaps = []

    def load(self):
        self.requests += 1
        if not self.loaded:
            self.loaded = True
            self._scan()
        return self.runs

    def _paths(self, root):
        found = []
        for measurement in sorted(os.listdir(root)):
            runs = os.path.join(root, measurement, 'runs')
            if not os.path.isdir(runs):
                continue
            for run in sorted(os.listdir(runs)):
                path = os.path.join(runs, run, 'meta.json')
                if os.path.isfile(path):
                    found.append((measurement if SAFE_NAME.match(measurement) else '?', path))
        return found

    def _scan(self):
        root = _expand(self.settings['results_dir'])
        try:
            paths = self._paths(root)
        except OSError:
            self.gaps.append('brak katalogu wyników EVALS 2.0 (nieistniejący albo nieczytelny)')
            return
        runs, unreadable = [], 0
        for measurement, path in paths:
            run = self._read(path)
            if run is None:
                unreadable += 1
            else:
                runs.append(dict(run, measurement=measurement))
        if unreadable:
            self.gaps.append('%d %s meta.json nieczytelnych albo bez pól status, mode, subagent_model: pominięte'
                             % (unreadable, plural(unreadable, 'plik', 'pliki', 'plików')))
        if not runs:
            self.gaps.append('brak czytelnego meta.json w wynikach EVALS 2.0')
            return
        self.runs = runs

    @staticmethod
    def _read(path):
        try:
            if os.path.getsize(path) > META_LIMIT:
                return None
            with open(path, encoding='utf-8') as f:
                meta = json.load(f)
        except (OSError, ValueError):
            return None
        if not isinstance(meta, dict) or not isinstance(meta.get('status'), str) or 'mode' not in meta:
            return None
        mode, model = meta['mode'], meta.get('subagent_model')
        if not (mode is None or isinstance(mode, str)) or not (model is None or isinstance(model, str)):
            return None
        if meta['status'] == 'ok' and mode == 'replay' and not model:
            return None                      # a counted run must name its model
        return {'status': meta['status'], 'mode': mode, 'model': model}


def counted(run):
    """Whether an EVALS 2.0 run counts for D1: a replay that ended `ok` (squash, infra_error and budget_stop do not)."""
    return run['status'] == 'ok' and run['mode'] == 'replay'


def d1_candidates(record, rates, evals, config, skipped, gaps):
    """D1: a role of an agent has at least `d1_role_share` of the value of the epic on models more expensive than the
    cheapest one used in it (the rates are the medians of the history). It never proposes a change of a model: with
    no counted EVALS 2.0 run of the role on the cheapest model it proposes the experiment of a variant comparison;
    with such runs, or for a role outside `evals.roles`, it only lists the rule among the skipped ones."""
    by_role_model = _get(record, ('value', 'by_role_model'))
    total = _get(record, ('value', 'total', 'usd'))
    if not isinstance(by_role_model, dict) or not _is_number(total) or total <= 0:
        return skip_rule(skipped, 'D1', 'brak danych: value.by_role_model albo value.total')
    models = sorted({m for roles in by_role_model.values() if isinstance(roles, dict) for m in roles})
    ranked = {m: rates[m] for m in models if m in rates}
    for model in models:
        if model not in ranked:
            gaps.add('decide.D1', 'brak stawki historycznej modelu %s: pominięty w porównaniu' % model)
    if len(ranked) < 2:
        return skip_rule(skipped, 'D1', 'mniej niż dwa modele z historyczną stawką w epiku: brak porównania')
    cheapest = min(ranked, key=lambda m: (ranked[m], m))
    found = []
    for role, cells in sorted(by_role_model.items()):
        if role in AGENT_EXCLUDED_ROLES or not isinstance(cells, dict):
            continue
        costly = {m: c for m, c in cells.items() if m in ranked and ranked[m] > ranked[cheapest]
                  and isinstance(c, dict) and _is_number(c.get('usd'))}
        value = sum(c['usd'] for c in costly.values())
        share = value / total
        if not costly or share < config['decisions']['d1_role_share']:
            continue
        key = 'D1:%s' % role
        if role not in config['evals']['roles']:
            skip_rule(skipped, key, 'bez evali')
            continue
        if len(config['evals']['roles']) != 1:
            skip_rule(skipped, key, 'meta.json nie wskazuje agenta, a lista ról EVALS 2.0 ma więcej niż jedną: '
                                    'przebiegów nie da się przypisać do roli')
            continue
        runs = evals.load()
        if runs is None:
            skip_rule(skipped, key, 'brak wyników EVALS 2.0 (powód w gaps)')
            continue
        done = [r for r in runs if counted(r) and r['model'] == cheapest]
        if done:
            skipped.append({'key': key, 'reason': 'porównanie w EVALS 2.0, decyzja w jego raporcie',
                            'data': {'runs': len(done), 'measurements': sorted({r['measurement'] for r in done})}})
            continue
        estimated = any(c.get('estimated') is True or c.get('incomplete') is True for c in costly.values())
        saving = sum(c['usd'] * (1 - ranked[cheapest] / ranked[m]) for m, c in costly.items())
        data = {'role': role, 'share': round(share, 4), 'models': {m: c['usd'] for m, c in sorted(costly.items())},
                'cheaper_model': cheapest, 'rates': {m: ranked[m] for m in sorted(ranked)},
                'estimated': ['role_value'] if estimated else []}
        found.append(make_candidate(key, data, (
            'Eksperyment porównania wariantów agenta %s w EVALS 2.0 na modelu %s (wariant to commit ze zmianą `model:` '
            'w pliku agenta, `--agent-rev`; płatny pomiar wymaga zgody właściciela), nie zmiany modelu.%s'
            % (role, cheapest, ' Wartość roli jest szacowana.' if estimated else '')), None, saving, estimated))
    return found


def rule_candidates(record, history, config, gaps):
    """(candidates, skipped) of the rules D2, D3 and D4, which need only the record and the history of the epic."""
    skipped, found = [], []
    found += d2_candidates(record, history, config, skipped, gaps) or []
    found += d3_candidates(record, config, skipped) or []
    found += d4_candidates(record, config, skipped)
    return found, skipped


def decide_epic(record, records, config, evals=None):
    """The result of `decide` for one record: {epic, candidates (ordered), report (the first report_max), skipped,
    gaps}. evals: the EvalsResults shared by the records of one call."""
    gaps = Gaps()
    evals = evals or EvalsResults(config)
    history = history_of(record, records)
    found, skipped = rule_candidates(record, history, config, gaps)
    asked = evals.requests
    found += d1_candidates(record, historical_rates(records, []), evals, config, skipped, gaps) or []
    for reason in evals.gaps if evals.requests > asked else []:     # only when the D1 of this epic read the results
        gaps.add('decide.D1', reason)
    ordered = order_candidates(found)
    return {'epic': record.get('epic'), 'candidates': ordered, 'report': ordered[:int(config['decisions']['report_max'])],
            'skipped': skipped, 'gaps': gaps.render()}


def cmd_decide(args, config, run, now):
    if os.path.basename(args.epic) != args.epic or args.epic in ('.', '..'):
        raise MetricsError('niepoprawny identyfikator epiku %r' % args.epic)
    state = _expand(args.state)
    records = read_records(state)
    record = records.get(args.epic)
    if record is None:
        raise MetricsError('brak (albo nieczytelny) rekord epiku %s w %s: uruchom `metrics.py epic %s`'
                           % (args.epic, os.path.join(state, 'epics'), args.epic))
    evals = EvalsResults(config)
    result = decide_epic(record, records, config, evals)
    if args.history:
        result['history'] = [{k: v for k, v in decide_epic(other, records, config, evals).items() if k != 'report'}
                             for other in merged_records(records)]
        keys = {}
        for entry in result['history']:
            for candidate in entry['candidates']:
                keys.setdefault(candidate['key'], []).append(entry['epic'])
        result['keys'] = keys
    print(json.dumps(result, ensure_ascii=False))
    return 0


# ------------------------------------------------------------------------------------------------------- effect
#
# `effect KEY --at TIME` (spec: Decyzje optymalizacyjne, Efekt decyzji) measures a rule before and after the merge of its
# fix, from the stored records only (nothing is written). Only merged epics are in the windows: the after window is the
# epics started after TIME (strictly) and merged, the before window the same number of epics merged at or before TIME,
# the closest ones; an epic started at or before TIME and merged after it is in neither.

D4_SUBJECTS = {'human_wait': 'to_done', 'limit_wait': 'to_done', 'ci': 'work', 'review': 'to_merge'}
EFFECT_UNITS = {'D1': 'USD', 'D2': 'h', 'D3': 'h', 'D4': 'pp'}
EPIC_TIME_COUNT = 3
NOT_ENOUGH = 'za mało danych'


def parse_effect_key(key):
    """(rule, subject) of a key of `effect`: D1:<role>, D2:plan_split, D3:gate_wait or D4:<human_wait|ci|limit_wait|review>."""
    rule, _, subject = key.partition(':')
    if (rule == 'D1' and SAFE_NAME.match(subject)) or key in (D2_KEY, D3_KEY) or (rule == 'D4' and subject in D4_SUBJECTS):
        return rule, subject
    raise MetricsError('zły klucz reguły %r: oczekiwano D1:<rola>, %s, %s albo D4:<%s>'
                       % (key, D2_KEY, D3_KEY, '|'.join(D4_SUBJECTS)))


def parse_at(text):
    try:
        return night_queue.parse_time(text).timestamp()
    except (ValueError, TypeError, AttributeError):
        raise MetricsError('zły --at %r: oczekiwano czasu ISO 8601 z przesunięciem UTC albo Z (np. 2026-11-10T12:00:00Z)' % text)


def _epoch(record, field):
    try:
        return night_queue.parse_time(record[field]).timestamp()
    except (KeyError, TypeError, ValueError, AttributeError):
        return None


def percentile(ordered, fraction):
    """The linear interpolation between the closest ranks of the ordered values (the `inclusive` method)."""
    position = (len(ordered) - 1) * fraction
    low = int(position)
    high = min(low + 1, len(ordered) - 1)
    return ordered[low] + (ordered[high] - ordered[low]) * (position - low)


def effect_windows(records, at, gaps):
    """(before, after, before_pool, spanning, unmerged): the records of the two windows (oldest first), the whole pool of
    epics merged at or before `at` (its closest ones give the epic time) and the ids left out."""
    pool, after, spanning, unmerged = [], [], [], []
    for record in records.values():
        started, merged = _epoch(record, 'started_at'), _epoch(record, 'merged_at')
        if started is not None and started > at:
            (after if merged is not None else unmerged).append((started, record))
        elif merged is None:
            continue
        elif merged <= at:
            pool.append((merged, record))
        else:
            spanning.append(record)
            if started is None:
                gaps.add('effect', 'epik %s bez started_at, zmergowany po --at: w żadnym oknie' % record.get('epic'))
    order = lambda pair: (pair[0], str(pair[1].get('epic')))
    after = [r for _, r in sorted(after, key=order)]
    pool = [r for _, r in sorted(pool, key=order)]
    before = pool[len(pool) - len(after):] if after else []
    names = lambda items: sorted(str(r.get('epic')) for r in items)
    return before, after, pool, names(spanning), names([r for _, r in unmerged])


def epic_value(record, rule, subject):
    """The measure of an epic (D3: hours of gate_wait; D4: the share of the category in its denominator, in pp), None
    when unknown."""
    if rule == 'D3':
        wait = _get(record, ('gate_wait', 'wait_s'))
        return wait / HOUR if _is_number(wait) else None
    if subject == 'ci':
        parts = [_get(record, ('breakdown', 'ci_wait')), _get(record, ('in_attempts', 'categories', 'ci_watch', 'seconds'))]
    else:
        parts = [_get(record, ('wall', 'review') if subject == 'review' else ('breakdown', subject))]
    denominator = _get(record, {'to_done': ('wall', 'to_done'), 'work': ('breakdown', 'work'),
                                'to_merge': ('wall', 'to_merge')}[D4_SUBJECTS[subject]])
    if not all(_is_number(p) for p in parts) or not _is_number(denominator) or denominator <= 0:
        return None
    return sum(parts) / denominator * 100.0


def plan_work_samples(record):
    tasks = record.get('by_task')
    if not isinstance(tasks, dict):
        return None
    return [e['work'] / HOUR for _, e in sorted(tasks.items())
            if isinstance(e, dict) and e.get('kind') == 'plan' and _is_number(e.get('work'))]


def role_kind(records, role):
    """The kind of the tasks in which the role has the largest value over the records (None when it has none)."""
    sums = {}
    for record in records:
        by_role = _get(record, ('value', 'by_task_role'))
        by_task = record.get('by_task')
        if not isinstance(by_role, dict) or not isinstance(by_task, dict):
            continue
        for task, roles in by_role.items():
            usd = _get(roles, (role, 'usd'))
            kind = _get(by_task, (task, 'kind'))
            if _is_number(usd) and kind:
                sums[kind] = sums.get(kind, 0.0) + usd
    return max(sorted(sums), key=lambda k: sums[k]) if any(v > 0 for v in sums.values()) else None


def role_samples(record, role, kind):
    """The value of the role in each task of `kind` (0 where it did not act); None for a record without
    value.by_task_role (older than the field)."""
    by_role, by_task = _get(record, ('value', 'by_task_role')), record.get('by_task')
    if not isinstance(by_role, dict) or not isinstance(by_task, dict):
        return None
    found = []
    for task, entry in sorted(by_task.items()):
        if not isinstance(entry, dict) or entry.get('kind') != kind:
            continue
        if not _is_number(_get(record, ('value', 'by_task', task, 'usd'))):
            continue                                    # the value of the task is unknown: not a zero
        usd = _get(by_role, (task, role, 'usd'))
        found.append(usd if _is_number(usd) else 0.0)
    return found


def window_stats(records, samples_of, gaps, label, missing='brak miary'):
    """{epics, n, n_epics, median, p25, p75} of the samples of the records of a window; a record whose samples are
    unknown (None) is left out with a gap."""
    values, epics = [], []
    for record in records:
        found = samples_of(record)
        if found is None:
            gaps.add('effect', 'epik %s (okno %s): %s, pominięty' % (record.get('epic'), label, missing))
        elif found:
            values.extend(found)
            epics.append(str(record.get('epic')))
    ordered = sorted(values)
    stat = lambda f: round(percentile(ordered, f), 4) if ordered else None
    return {'epics': epics, 'n': len(values), 'n_epics': len(epics),
            'median': round(median(ordered), 4) if ordered else None, 'p25': stat(0.25), 'p75': stat(0.75)}


def epic_time(records, config):
    """The median time to epic_done per plan task (hours) of `records` and their ids."""
    values, names = [], []
    for record in records:
        trend = trend_values(record, config)['to_done']
        if trend is not None:
            values.append(trend)
            names.append(str(record.get('epic')))
    return {'epics': names, 'median': median_of(values)}


def version_change(before, after, gaps):
    """{cli, models}: what the epics of `after` have that those of `before` do not (added) and the reverse (removed).
    A window with no `versions` at all has nothing to compare: no change and a gap."""
    sets = {'before': [_version_sets(r) for r in before], 'after': [_version_sets(r) for r in after]}
    change = {}
    for index, name in enumerate(('cli', 'models')):
        known = {w: [s[index] for s in found if s[index] is not None] for w, found in sets.items()}
        if not known['before'] or not known['after']:
            gaps.add('effect', 'versions: brak wersji w jednym z okien: zmiany nieznane (%s)' % name)
            change[name] = {'added': [], 'removed': []}
            continue
        old, new = set().union(*known['before']), set().union(*known['after'])
        change[name] = {'added': sorted(new - old), 'removed': sorted(old - new)}
    return change


def build_effect(records, key, at, config):
    rule, subject = parse_effect_key(key)
    gaps = Gaps()
    before, after, pool, spanning, unmerged = effect_windows(records, at, gaps)
    settings = config['decisions']
    level_kind = None
    if rule == 'D1':
        level_kind = role_kind(before + after, subject)
        samples_of = (lambda r: role_samples(r, subject, level_kind)) if level_kind else (lambda r: [])
    elif rule == 'D2':
        samples_of = plan_work_samples
    else:
        samples_of = lambda r: [v] if (v := epic_value(r, rule, subject)) is not None else None
    missing = 'rekord bez value.by_task_role (starszy niż pole)' if rule == 'D1' else 'brak miary'
    stats = {'before': window_stats(before, samples_of, gaps, 'przed', missing),
             'after': window_stats(after, samples_of, gaps, 'po', missing)}
    task_level = rule in ('D1', 'D2')
    reasons = []
    if task_level:
        if stats['after']['n'] < settings['effect_min_tasks'] or stats['after']['n_epics'] < settings['effect_min_task_epics']:
            reasons.append('okno po: %d zadań z %d epików (potrzeba co najmniej %d z %d)' % (
                stats['after']['n'], stats['after']['n_epics'], settings['effect_min_tasks'],
                settings['effect_min_task_epics']))
    elif stats['after']['n'] < settings['effect_min_epics']:
        reasons.append('okno po: %d epików (potrzeba co najmniej %d)' % (stats['after']['n'], settings['effect_min_epics']))
    if not reasons and stats['before']['median'] is None:
        reasons.append('okno przed bez pomiarów')
    diff = None
    if not reasons:
        change = stats['after']['median'] - stats['before']['median']
        diff = {'value': round(change, 4),
                'pct': round(change / stats['before']['median'] * 100, 1) if stats['before']['median'] else None}
    return {'key': key, 'rule': rule, 'at': iso(at), 'measure': MEASURES[rule], 'unit': EFFECT_UNITS[rule],
            'level': 'task' if task_level else 'epic', 'level_kind': level_kind, 'before': stats['before'],
            'after': stats['after'], 'message': NOT_ENOUGH if reasons else None, 'reasons': reasons, 'diff': diff,
            'epic_time': {'unit': 'h na zadanie planu', 'before': epic_time(pool[-EPIC_TIME_COUNT:], config),
                          'after': epic_time(after[:EPIC_TIME_COUNT], config)},
            'versions': version_change(before, after, gaps), 'spanning': spanning, 'unmerged_after': unmerged,
            'gaps': gaps.render()}


def cmd_effect(args, config, run, now):
    rule_key = args.key
    parse_effect_key(rule_key)
    at = parse_at(args.at)
    records = read_records(_expand(args.state))
    if not merged_records(records):
        raise MetricsError('brak zapisanych rekordów zmergowanych epików (uruchom: metrics.py backfill)')
    print(json.dumps(build_effect(records, rule_key, at, config), ensure_ascii=False))
    return 0


# ------------------------------------------------------------------------------------------------------- trend

TREND_COLUMNS = ('to_done', 'work', 'human_wait', 'review', 'gate_wait', 'repeat_runs', 'value', 'pp', 'failed',
                 'repair', 'interventions', 'regressions')
HOUR = 3600.0


def per_task_sources(record, config):
    """{column: (value, scale)} of a record before the division by the plan tasks: times in seconds (scale: 3600 for
    hours), the others counts, USD or pp. A value that is not known is None (also the `value` of a record whose total is
    incomplete)."""
    windows = _get(record, ('subscription', 'windows'))
    weekly = windows.get(config['weekly_window_label']) if isinstance(windows, dict) else None
    share = _get(record, ('subscription', 'share'))
    return {
        'to_done': (_get(record, ('wall', 'to_done')), HOUR), 'work': (_get(record, ('breakdown', 'work')), HOUR),
        'human_wait': (_get(record, ('breakdown', 'human_wait')), HOUR), 'review': (_get(record, ('wall', 'review')), HOUR),
        'gate_wait': (_get(record, ('gate_wait', 'wait_s')), HOUR),
        'repeat_runs': (_get(record, ('in_attempts', 'repeat_runs', 'seconds')), HOUR),
        'value': (None if _get(record, ('value', 'total', 'incomplete')) is True       # a partial sum is not a value
                  else _get(record, ('value', 'total', 'usd')), 1.0),
        'pp': (weekly * share if _is_number(weekly) and _is_number(share) else None, 1.0),
        'failed': (_get(record, ('retries', 'attempts', 'failed')), 1.0),
        'repair': (_get(record, ('retries', 'repair', 'count')), 1.0),
        'interventions': (_get(record, ('human', 'interventions')), 1.0),
        'regressions': (_get(record, ('regressions', 'count')), 1.0),
    }


def trend_values(record, config):
    """The columns of `trend` of one record, per plan task (times in hours, rounded to 4 places); None where the source
    is unknown or the epic has no plan tasks."""
    tasks = record.get('tasks')
    values = {}
    for column, (value, scale) in per_task_sources(record, config).items():
        usable = _is_number(value) and _is_int(tasks) and tasks > 0
        values[column] = round(value / tasks / scale, 4) if usable else None
    return values


def median_of(values):
    known = [v for v in values if v is not None]
    return round(median(known), 4) if known else None


def position_of(values, medians):
    found = {}
    for column in TREND_COLUMNS:
        value, middle = values[column], medians[column]
        diff = None if value is None or middle is None else round(value - middle, 4)
        pct = None if diff is None or not middle else round(diff / middle * 100, 1)
        found[column] = {'value': value, 'median': middle, 'diff': diff, 'diff_pct': pct}
    return found


def merged_records(records):
    """[(epoch, record)] of the records with a merge time, oldest first (ties by the epic id)."""
    found = []
    for record in records.values():
        try:
            found.append((night_queue.parse_time(record['merged_at']).timestamp(), record))
        except (KeyError, TypeError, ValueError, AttributeError):
            continue
    return [record for _, record in sorted(found, key=lambda item: (item[0], str(item[1].get('epic'))))]


def _version_sets(record):
    versions = record.get('versions')
    if not isinstance(versions, dict):
        return None, None
    return ({v['version'] for v in versions.get('cli') or [] if isinstance(v, dict) and 'version' in v},
            {m['model'] for m in versions.get('models') or [] if isinstance(m, dict) and 'model' in m})


def change_annotations(previous, current):
    """The notes of a change of the CLI version or of the set of models against the previous epic (the numbers are not
    corrected)."""
    old_cli, old_models = _version_sets(previous)
    new_cli, new_models = _version_sets(current)
    notes = []
    if old_cli is not None and new_cli is not None and old_cli != new_cli:
        notes.append('wersja CLI: %s → %s' % (', '.join(sorted(old_cli)) or 'brak', ', '.join(sorted(new_cli)) or 'brak'))
    if old_models is not None and new_models is not None and old_models != new_models:
        parts = []
        if new_models - old_models:
            parts.append('+ ' + ', '.join(sorted(new_models - old_models)))
        if old_models - new_models:
            parts.append('− ' + ', '.join(sorted(old_models - new_models)))
        notes.append('modele: ' + ' '.join(parts))
    return notes


def refresh_windows(records, state_dir, config, run, repo, now, errors):
    """Recomputes the regressions and found_after_merge of the records whose window is open (or never computed, or
    computed with a failed source: `complete` is false): a merge of another epic and the start of a `/ao-run` session call `trend`. Stores
    the changed records; returns the ids. Values of a source that fails stay (regressions_of)."""
    done = []
    for epic in sorted(records):
        record = records[epic]
        try:
            merged = night_queue.parse_time(record['merged_at']).timestamp()
        except (KeyError, TypeError, ValueError, AttributeError):
            continue
        final_pr = record.get('final_pr')
        current = record.get('regressions')
        found = record.get('found_after_merge')
        if not _is_int(final_pr) or (isinstance(current, dict) and current.get('open') is False
                                     and current.get('complete') is True and isinstance(found, dict)
                                     and current.get('count') is not None and found.get('count') is not None):
            continue
        sha = current.get('merge_commit') if isinstance(current, dict) else None
        if not (isinstance(sha, str) and SHA_PATTERN.match(sha)):
            try:
                sha = fetch_pr(run, final_pr, repo)['merge_sha']
            except GhError as e:
                errors.append(str(e))
                continue
        gaps = Gaps()
        record['regressions'], record['found_after_merge'] = regressions_of(
            run, repo, config, epic, final_pr, sha, merged, now, dict(record), gaps, errors)
        record['gaps'] = [g for g in record.get('gaps') or [] if not g.startswith(('regressions', 'found_after_merge'))] \
            + gaps.render()
        write_record(state_path(state_dir, epic), record)
        done.append(epic)
    return done


def build_trend(records, config, last, epic):
    """The trend table: the last `last` merged epics with their columns, the medians of
    the shown epics (nulls skipped), the position of `epic` (default: the latest; it may be older than the shown ones)
    against them and the annotations."""
    ordered = merged_records(records)
    if not ordered:
        raise MetricsError('brak zapisanych rekordów zmergowanych epików (uruchom: metrics.py backfill)')
    by_id = {r.get('epic'): r for r in ordered}
    chosen = by_id.get(epic) if epic is not None else ordered[-1]
    if chosen is None:
        raise MetricsError('brak rekordu zmergowanego epiku %s (zapisane: %s)' % (epic, ', '.join(sorted(by_id))))
    shown = ordered[-last:]
    rows = []
    for record in shown:
        index = ordered.index(record)
        rows.append({'epic': record['epic'], 'merged_at': record.get('merged_at'), 'tasks': record.get('tasks'),
                     'values': trend_values(record, config),
                     'annotations': change_annotations(ordered[index - 1], record) if index else []})
    medians = {column: median_of([row['values'][column] for row in rows]) for column in TREND_COLUMNS}
    values = trend_values(chosen, config)
    return {'columns': list(TREND_COLUMNS), 'epics': rows, 'median': medians,
            'position': {'epic': chosen['epic'], 'columns': position_of(values, medians)}, 'recomputed': []}


def pl(value, digits=1):
    """A number with a decimal comma; `-` for an unknown one."""
    if value is None:
        return '-'
    return ('%.*f' % (digits, value)).replace('.', ',')


def format_trend(table):
    heads = {'to_done': 'do_done', 'work': 'work', 'human_wait': 'human', 'review': 'review', 'gate_wait': 'gate',
             'repeat_runs': 'powt.', 'value': 'USD', 'pp': 'pp', 'failed': 'nieud.', 'repair': 'REPAIR',
             'interventions': 'interw.', 'regressions': 'regr.'}
    width = max([len('mediana')] + [len(r['epic']) for r in table['epics']])
    lines = ['Trend (na zadanie planu; czasy w godzinach; epików: %d)' % len(table['epics']),
             ' '.join(['epik'.ljust(width)] + [heads[c].rjust(8) for c in table['columns']])]
    for row in table['epics']:
        lines.append(' '.join([row['epic'].ljust(width)] + [pl(row['values'][c], 2).rjust(8)
                                                           for c in table['columns']]))
    lines.append(' '.join(['mediana'.ljust(width)] + [pl(table['median'][c], 2).rjust(8)
                                                      for c in table['columns']]))
    position = table['position']
    lines.append('%s wobec mediany:' % position['epic'])
    for column in table['columns']:
        entry = position['columns'][column]
        if entry['diff'] is None:
            lines.append('  %s: brak danych' % heads[column])
            continue
        pct = '' if entry['diff_pct'] is None else ', %s%s%%' % ('+' if entry['diff_pct'] >= 0 else '−',
                                                                 pl(abs(entry['diff_pct']), 0))
        lines.append('  %s: %s (mediana %s%s)' % (heads[column], pl(entry['value'], 2), pl(entry['median'], 2), pct))
    notes = ['%s: %s' % (row['epic'], note) for row in table['epics'] for note in row['annotations']]
    if notes:
        lines.append('Zmiany wersji CLI i modeli (liczby bez korekty):')
        lines.extend('  ' + note for note in notes)
    return '\n'.join(lines)


def cmd_trend(args, config, run, now):
    last = config['trend_last'] if args.last is None else args.last
    if last <= 0:
        raise MetricsError('--last: oczekiwano dodatniej liczby epików, podano %d' % last)
    state = _expand(args.state)
    records = read_records(state)
    errors = []
    recomputed = [] if args.no_gh else refresh_windows(records, state, config, run, args.repo, now, errors)
    table = build_trend(records, config, last, args.epic)
    table['recomputed'] = recomputed
    print(json.dumps(table, ensure_ascii=False) if args.json else format_trend(table))
    for error in errors:
        print('metrics: błąd gh: %s (okno regresji: zachowano poprzednie wartości)' % error, file=sys.stderr)
    return EXIT_SOURCE if errors else 0


# ------------------------------------------------------------------------------------------------------- render
#
# `render` writes only what it can check: numbers (booleans and text are no numbers), identifiers (task ids, roles,
# models, CLI versions, tool names, epic ids: SAFE_NAME), PR numbers and fixed words of this file. Any other text of the
# record (the reasons of `gaps`, messages of errors, commands, paths) is dropped: the comment goes to a public issue
# (spec: Model zagrożeń, droga 1). The only free text is the list of manual fixes the /ao-run session wrote itself.

NO_DATA = 'brak danych'
BOTTLENECK_KINDS = ('breakdown', 'task', 'in_attempts', 'gate_wait', 'review')
SHARE_OF = {'to_done': 'czasu', 'gate_wait+to_done': 'czasu z oczekiwaniem w bramce', 'to_merge': "czasu do merge'u"}
LABEL_PATTERN = re.compile(r'^[A-Za-z0-9][A-Za-z0-9 \u00b7.\-]{0,39}$')
STATE_PATTERN = re.compile(r'^[A-Z_]{1,16}$')
RULE_PATTERN = re.compile(r'^D[1-9]$')
FIELD_PATTERN = re.compile(r'^[A-Za-z_][A-Za-z0-9_.\-]{0,59}$')
MANUAL_FIX_LIMIT = 200


def _dict(value):
    return value if isinstance(value, dict) else {}


def _list(value):
    return value if isinstance(value, list) else []


SECRET_LIKE = re.compile(r'ghp_|gho_|github_pat_|(?<![A-Za-z0-9])sk-|[A-Za-z0-9+/]{32,}')


def _safe(name):
    """The identifier (task id, role, model, CLI version, tool, epic) or None: an identifier that looks like a secret is
    not one."""
    return name if isinstance(name, str) and SAFE_NAME.match(name) and not SECRET_LIKE.search(name) else None


def _count(value):
    return value if _is_int(value) and value >= 0 else None


def hours_text(seconds):
    return NO_DATA if not _is_number(seconds) else '%s h' % pl(seconds / HOUR)


def percent_of(part, whole):
    return None if not _is_number(part) or not _is_number(whole) or whole <= 0 else int(round(part / whole * 100))


def usd_text(cell):
    """`12,34 USD`, `≈ 12,34 USD` for an estimated value, `brak danych` for an unknown one."""
    cell = _dict(cell)
    usd = cell.get('usd')
    if not _is_number(usd):
        return NO_DATA
    text = '%s%s USD' % ('≈ ' if cell.get('estimated') is True else '', pl(usd, 2))
    return text + (' (suma niepełna)' if cell.get('incomplete') is True else '')


def plural(number, one, few, many):
    if number == 1:
        return one
    return few if number % 10 in (2, 3, 4) and number % 100 not in (12, 13, 14) else many


def bottleneck_lines(items):
    """The numbered lines of the bottlenecks: kind, name, rule and the share come only from the known words."""
    lines = []
    for item in _list(items):
        item = _dict(item)
        name, excess = _safe(item.get('name')), item.get('excess_h')
        if item.get('kind') not in BOTTLENECK_KINDS or name is None or not _is_number(excess):
            continue
        share = percent_of(item.get('share'), 1)
        text = '%d. `%s`: +%s h ponad medianę' % (len(lines) + 1, name, pl(excess))
        if share is not None and item.get('share_of') in SHARE_OF:
            text += ' (%d%% %s)' % (share, SHARE_OF[item['share_of']])
        effect = item.get('effect_h')
        if _is_number(effect):
            rule = str(item.get('effect_rule') or '').split(':')[0]
            text += ', potencjalnie −%s h (%s)' % (pl(effect), rule if RULE_PATTERN.match(rule) else 'bez reguły decyzji')
        elif isinstance(item.get('rule'), str) and RULE_PATTERN.match(item['rule']):
            text += ', reguła %s' % item['rule']
        if _is_number(item.get('repeat_h')) and item['repeat_h'] > 0:
            text += ', w tym możliwe powtórzenia: %s h' % pl(item['repeat_h'])
        lines.append(text)
    return lines


def time_topic(record, comment):
    to_done = _dict(record.get('wall')).get('to_done')
    tasks = record.get('tasks')
    if not _is_number(to_done):
        lines = ['Czas do epic_done: %s' % NO_DATA]
    else:
        text = 'Czas do epic_done: %s' % hours_text(to_done)
        if _is_int(tasks) and tasks > 0:
            text += ' (%s na zadanie planu)' % hours_text(to_done / tasks)
        breakdown = record.get('breakdown')
        if isinstance(breakdown, dict):
            parts = []
            for name in BREAKDOWN_CATEGORIES:
                value = breakdown.get(name)
                if not _is_number(value):
                    parts.append('%s %s' % (name, NO_DATA))
                elif value > 0:
                    parts.append('%s %s (%d%%)' % (name, hours_text(value), percent_of(value, to_done) or 0))
            text += '; rozkład: ' + ', '.join(parts)
        lines = [text]
    if comment:
        wall = _dict(record.get('wall'))
        lines.append("Czas do merge'u: %s, odbiór (review): %s" % (hours_text(wall.get('to_merge')),
                                                                  hours_text(wall.get('review'))))
    return [lines[0]] + ['  ' + line for line in lines[1:]]


def attempts_time_topic(record):
    data = record.get('in_attempts')
    if not isinstance(data, dict):
        return ['Czas w próbach (polecenia powłoki): %s (brak transkryptów)' % NO_DATA]
    parts = []
    for name, entry in _dict(data.get('categories')).items():
        entry = _dict(entry)
        if _safe(name) and _count(entry.get('count')) and _is_number(entry.get('seconds')):
            parts.append('%s %s (%d)' % (name, hours_text(entry['seconds']), entry['count']))
    lines = ['Czas w próbach (polecenia powłoki): ' + (', '.join(parts) if parts else 'brak poleceń')]
    repeat = _dict(data.get('repeat_runs'))
    if _count(repeat.get('count')) is not None and _is_number(repeat.get('seconds')):
        lines.append('możliwe powtórzenia testów: %d (%s), dolna granica' % (repeat['count'], hours_text(repeat['seconds'])))
    retry = _dict(data.get('api_retry'))
    if _count(retry.get('count')) is not None and _is_number(retry.get('delay_ms')):
        lines.append('ponowienia API: %d (łącznie %s s)' % (retry['count'], pl(retry['delay_ms'] / 1000.0, 0)))
    denied = ', '.join('%s %d' % (tool, n) for tool, n in sorted(_dict(data.get('permission_denials')).items())
                       if _safe(tool) and _count(n))
    lines.append('odmowy uprawnień: %s' % (denied or 'brak'))
    return [lines[0]] + ['  ' + line for line in lines[1:]]


def integration_topic(record):
    data = _dict(record.get('integration'))
    if not _is_number(data.get('work_s')):
        return ['Integracja: %s' % NO_DATA]
    text = 'Integracja: %s' % hours_text(data['work_s'])
    if _count(data.get('attempts')) is not None:
        text += ' w %d %s' % (data['attempts'], plural(data['attempts'], 'próbie', 'próbach', 'próbach'))
    if _count(data.get('rounds')) is not None:
        text += ', rund: %d' % data['rounds']
    return [text]


def value_topic(record):
    value = _dict(record.get('value'))
    head = 'Wartość tokenów (szacunek CLI według cen API, nie koszt subskrypcji): '
    if not isinstance(value.get('total'), dict):
        return [head + NO_DATA]
    lines = [head + 'razem ' + usd_text(value['total'])]

    def ranked(section):
        found = [(key, cell) for key, cell in _dict(value.get(section)).items() if _safe(key) and isinstance(cell, dict)]
        return sorted(found, key=lambda pair: (-(pair[1].get('usd') if _is_number(pair[1].get('usd')) else -1), pair[0]))

    roles = ranked('by_role')
    if roles:
        lines.append('role: %s (podział między subagentów szacowany)' % ', '.join('%s %s' % (k, usd_text(c)) for k, c in roles))
    models = ranked('by_model')
    if models:
        lines.append('modele: %s' % ', '.join('%s %s' % (k, usd_text(c)) for k, c in models))
    tasks = [(k, c) for k, c in ranked('by_task') if _is_number(c.get('usd'))]
    if tasks:
        lines.append('najdroższe zadanie: %s %s' % (tasks[0][0], usd_text(tasks[0][1])))
    return [lines[0]] + ['  ' + line for line in lines[1:]]


def subscription_topic(record):
    head = 'Subskrypcja (punkty procentowe okien limitu, osobno od wartości tokenów): '
    data = record.get('subscription')
    if not isinstance(data, dict):
        return [head + NO_DATA]
    windows = data.get('windows')
    if not isinstance(windows, dict):
        parts = ['okna: ' + NO_DATA]
    else:
        parts = []
        for label, delta in windows.items():
            if isinstance(label, str) and LABEL_PATTERN.match(label):
                parts.append('%s %s' % (label, '+%s pp' % pl(delta, 0 if float(delta).is_integer() else 1)
                                        if _is_number(delta) else NO_DATA))
    text = head + ', '.join(parts)
    overlap = [e for e in _list(data.get('overlap')) if _safe(e)]
    if overlap:
        share = data.get('share')
        shown = '%s%s' % ('≈ ' if data.get('share_estimated') is True else '', pl(share, 2)) if _is_number(share) \
            else NO_DATA
        text += '; share %s (nakładanie z: %s)' % (shown, ', '.join(overlap))
    return [text]


def retries_topics(record):
    data = _dict(record.get('retries'))
    attempts = _dict(data.get('attempts'))
    lines = []
    if _count(attempts.get('total')) is None:
        lines.append('Próby: %s' % NO_DATA)
    elif any(_count(attempts.get(name)) is None for name in ATTEMPT_CLASSES):      # the classes are unknown
        lines.append('Próby: %d (podział: %s)' % (attempts['total'], NO_DATA))
    else:
        def number(name):
            return _count(attempts.get(name)) or 0
        failed = ', '.join('%s %d' % (k, n) for k, n in sorted(_dict(data.get('failed_by_outcome')).items())
                           if _safe(k) and _count(n))
        text = 'Próby: %d (udane %d, nieudane %d%s, zablokowane %d' % (
            attempts['total'], number('successful'), number('failed'), ' [%s]' % failed if failed else '',
            number('blocked'))
        if number('usage_limit'):
            text += ', limit subskrypcji %d' % number('usage_limit')
        lines.append(text + ')')
    repair = _dict(data.get('repair'))
    if _count(repair.get('count')) is None:
        lines.append('REPAIR: %s' % NO_DATA)
    else:
        causes = [_count(repair.get('final_pr_conflict')), _count(repair.get('ci_red'))]
        lines.append('REPAIR: %d (%s)' % (repair['count'], 'przyczyna nieznana' if None in causes else
                                           'konflikt końcowego PR %d, czerwone CI %d' % tuple(causes)))
    lines.append('FIX: %s' % (_count(data.get('fix')) if _count(data.get('fix')) is not None else NO_DATA))
    if not isinstance(data.get('sync_prs'), list):
        lines.append('Sync: %s' % NO_DATA)
    else:
        prs = ['#%d%s' % (p['number'], ' (%s)' % p['state'] if isinstance(p.get('state'), str)
                          and STATE_PATTERN.match(p['state']) else '')
               for p in data['sync_prs'] if isinstance(p, dict) and _count(p.get('number')) is not None]
        lines.append('Sync: %s' % (', '.join(prs) if prs else 'brak PR synchronizacji'))
    return lines


def gate_topic(record):
    gate = record.get('gate_wait')
    if not isinstance(gate, dict) or not _is_number(gate.get('wait_s')):
        return ['Czekanie w bramce: %s' % NO_DATA]
    if gate['wait_s'] == 0:
        return ['Czekanie w bramce: bez czekania']
    reasons = [r for r in _list(gate.get('reasons')) if isinstance(r, str) and re.match(r'^[A-Za-z0-9_.\-]{1,40}$', r)]
    return ['Czekanie w bramce: %s%s' % (hours_text(gate['wait_s']), ' (powód: %s)' % ', '.join(reasons)
                                         if reasons else '')]


def human_topic(record):
    human = _dict(record.get('human'))
    interventions = _count(human.get('interventions'))
    text = 'Interwencje człowieka: %s' % (interventions if interventions is not None else NO_DATA)
    waits, seconds = _count(human.get('waits')), human.get('wait_s')
    if waits is not None and _is_number(seconds):
        text += '; czekania na użytkownika: %d, razem %d min' % (waits, int(round(seconds / 60.0)))
    return [text]


def waste_topic(record):
    alarms = record.get('waste')
    if not isinstance(alarms, list):
        return ['Alarmy (waste): %s' % NO_DATA]
    names = ['%s %s' % (a['rule'], a['task']) for a in alarms if isinstance(a, dict) and isinstance(a.get('rule'), str)
             and re.match(r'^W[1-9]$', a['rule']) and _safe(a.get('task'))]
    return ['Alarmy (waste): %d%s' % (len(alarms), ' (%s)' % ', '.join(names) if names else '')]


def versions_topic(record):
    versions = record.get('versions')
    if not isinstance(versions, dict):
        return ['Wersje CLI i modele: %s' % NO_DATA]

    def listed(key, name):
        return ', '.join('%s (%d)' % (e[name], e['attempts']) for e in _list(versions.get(key))
                         if isinstance(e, dict) and _safe(e.get(name)) and _count(e.get('attempts')) is not None)
    return ['Wersje CLI: %s; modele: %s' % (listed('cli', 'version') or 'brak', listed('models', 'model') or 'brak')]


def ci_topic(record):
    ci = record.get('ci')
    if not isinstance(ci, dict):
        return ['CI: %s' % NO_DATA]

    def minutes(entry, name):
        value = _dict(entry).get('%s_s' % name)
        return '%s min' % pl(value / 60.0) if _is_number(value) else NO_DATA

    final = _dict(ci.get('final'))
    parts = []
    if _count(final.get('pr')) is not None:
        parts.append('końcowy PR #%d: checks %s, e2e %s, przebiegów %s' % (
            final['pr'], minutes(final, 'checks'), minutes(final, 'e2e'), _count(final.get('runs'))
            if _count(final.get('runs')) is not None else NO_DATA))
    tasks = {k: v for k, v in _dict(ci.get('tasks')).items() if _safe(k) and _count(_dict(v).get('runs')) is not None}
    if tasks:
        repeated = ['%s (%d)' % (k, v['runs']) for k, v in sorted(tasks.items()) if v['runs'] > 1]
        parts.append('PR zadań: przebiegów łącznie %d%s' % (sum(v['runs'] for v in tasks.values()),
                                                             ', więcej niż jeden: %s' % ', '.join(repeated)
                                                             if repeated else ''))
    return ['CI: %s' % ('; '.join(parts) if parts else NO_DATA)]


def regressions_topic(record):
    data, found = record.get('regressions'), record.get('found_after_merge')
    if not isinstance(data, dict):
        text = "Regresje po merge'u: %s" % NO_DATA
    else:
        parts = []
        for name, label in (('ci_red', "czerwone CI na commicie merge'u"), ('reverts', 'revert'),
                            ('issues', 'issues bug')):
            value = data.get(name)
            value = len(value) if isinstance(value, list) else value
            parts.append('%s: %s' % (label, value if _count(value) is not None else NO_DATA))
        count = _count(data.get('count'))
        text = "Regresje po merge'u (okno %s dni%s): %s (%s)" % (
            data.get('window_days') if _count(data.get('window_days')) is not None else '?',
            ', otwarte' if data.get('open') is True else '', count if count is not None else NO_DATA, '; '.join(parts))
    count = _count(_dict(found).get('count'))
    return [text, "znalezione po merge'u: %s (propozycje /sweep, osobno od regresji)" % (
        count if count is not None else NO_DATA)]


def position_topic(record, history, config, comment):
    if not history:
        return ['Pozycja na tle mediany: brak historii (zmergowanych epików przed tym: 0)']
    values = [trend_values(h, config) for h in history]
    medians = {c: median_of([v[c] for v in values]) for c in TREND_COLUMNS}
    position = position_of(trend_values(record, config), medians)
    parts = []
    for column in TREND_COLUMNS:
        entry = position[column]
        if entry['diff'] is None or (column == 'review' and not comment):
            continue            # the review is known only after the merge
        pct = '' if entry['diff_pct'] is None else ', %s%s%%' % ('+' if entry['diff_pct'] >= 0 else '−',
                                                                 pl(abs(entry['diff_pct']), 0))
        parts.append('%s %s (mediana %s%s)' % (column, pl(entry['value'], 2), pl(entry['median'], 2), pct))
    return ['Pozycja na tle mediany (%d %s, na zadanie planu; czasy w godzinach): %s' % (
        len(history), plural(len(history), 'epik', 'epiki', 'epików'), '; '.join(parts) or NO_DATA)]


def gaps_topic(record, comment):
    gaps = [g for g in _list(record.get('gaps')) if isinstance(g, str)]
    if not gaps:
        return ['Braki danych: brak']
    if not comment:
        return ['Braki danych (%d): %s' % (len(gaps), '; '.join(g[:160] for g in gaps))]
    fields = []
    for gap in gaps:
        field = gap.split(': ', 1)[0]
        if FIELD_PATTERN.match(field) and field not in fields:
            fields.append(field)
    return ['Braki danych: %d (pola: %s)' % (len(gaps), ', '.join(fields[:12]) or NO_DATA)]


def manual_fix_text(text):
    """One line of a manual fix: control characters dropped, whitespace collapsed, at most 200 characters, and for an
    item without a PR no colon and a lower case first letter (retro.py reads `Capital: ` as the next label)."""
    text = ' '.join(re.sub(r'[\x00-\x1f\x7f-\x9f\u2028\u2029]', ' ', str(text)).split())
    return text if len(text) <= MANUAL_FIX_LIMIT else text[:MANUAL_FIX_LIMIT - 1] + '…'


def manual_fix_lines(fixes):
    """`Ręczne poprawki: N` and one `- PR #<nr>: <text>` or `- <text>` line each (the format retro.py collect reads)."""
    if not isinstance(fixes, list):
        return ['Ręczne poprawki: %s' % NO_DATA]
    items = []
    for fix in fixes:
        fix = _dict(fix)
        text = manual_fix_text(fix.get('text') or '')
        if _count(fix.get('pr')) is not None:
            items.append('- PR #%d: %s' % (fix['pr'], text) if text else '- PR #%d' % fix['pr'])
        elif text:
            text = text.replace(':', ' -')
            if len(text) > 1 and text[0].isupper() and text[1].islower():
                text = text[0].lower() + text[1:]
            items.append('- ' + text)
    return ['Ręczne poprawki: %d' % len(items)] + items


def comment_head(record):
    human = _dict(record.get('human'))
    interventions, waits, seconds = _count(human.get('interventions')), _count(human.get('waits')), human.get('wait_s')
    lines = ['Interwencje człowieka: %s' % (interventions if interventions is not None else NO_DATA)]
    if waits is None or not _is_number(seconds):
        lines.append('Czekanie na użytkownika: %s' % NO_DATA)
    else:
        lines.append('Czekanie na użytkownika: %d %s, razem %d min%s' % (
            waits, plural(waits, 'czekanie', 'czekania', 'czekań'), int(round(seconds / 60.0)),
            ', otwarte: %d' % human['open'] if _count(human.get('open')) else ''))
    return lines + manual_fix_lines(human.get('manual_fixes'))


def render_measurement(record, records, config, comment):
    """The text of `render`: the report of the acceptance (bottlenecks first, without `review`, which is known only
    after the merge) or the comment after the merge (the lines retro.py reads, then the block `Pomiar epiku`)."""
    history = history_of(record, records)
    if comment:
        items = record.get('bottlenecks')
        if not isinstance(items, list):
            items = bottlenecks_of(record, history, config, Gaps())
    else:
        items = bottlenecks_of(record, history, config, Gaps(), include_review=False)
    head = 'Wąskie gardła (mediana z %d %s)' % (len(history), plural(len(history), 'epiku', 'epików', 'epików'))
    numbered = bottleneck_lines(items)
    topics = [[head + ':'] + numbered if numbered else
              [head + ': brak pozycji z nadwyżką co najmniej %s h ponad medianę' % pl(config['bottlenecks']['min_excess_h'])]]
    topics += [time_topic(record, comment), attempts_time_topic(record), integration_topic(record),
               value_topic(record), subscription_topic(record)]
    topics += [[line] for line in retries_topics(record)]
    topics += [gate_topic(record)]
    if not comment:
        topics += [human_topic(record)]
    topics += [waste_topic(record), versions_topic(record), ci_topic(record)]
    if comment:
        topics += [[line] for line in regressions_topic(record)]
    topics += [position_topic(record, history, config, comment), gaps_topic(record, comment)]
    lines = []
    for topic in topics:
        if comment:
            lines.append('- ' + topic[0])
            lines.extend('  - ' + line.strip() for line in topic[1:])
        else:
            lines.extend(topic[:1] + ['  ' + line.strip() for line in topic[1:]])
    if comment:
        return '\n'.join(comment_head(record) + ['', '### Pomiar epiku'] + lines) + '\n'
    return '\n'.join(lines) + '\n'


def cmd_render(args, config, run, now):
    if os.path.basename(args.epic) != args.epic or args.epic in ('.', '..'):
        raise MetricsError('niepoprawny identyfikator epiku %r' % args.epic)
    state = _expand(args.state)
    records = read_records(state)
    record = records.get(args.epic)
    if record is None:
        raise MetricsError('brak (albo nieczytelny) rekord epiku %s w %s: uruchom `metrics.py epic %s`'
                           % (args.epic, os.path.join(state, 'epics'), args.epic))
    sys.stdout.write(render_measurement(record, records, config, args.format == 'comment'))
    return 0


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
                                  records=read_records(_expand(args.state)), ao_root=os.path.dirname(ao_home),
                                  waste=waste_of_epic(_expand(args.state), args.epic))
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
                                          records=records, ao_root=root,
                                          waste=waste_of_epic(_expand(args.state), epic_id))
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


# ------------------------------------------------------------------------------------------------------ waste alarms

DB_RETRIES = 2                  # extra tries of a locked live database (each after busy_timeout, a pause between)
DB_RETRY_PAUSE_S = 0.5
PUSH_MAX = 200
CI_GREEN = ('epic_ci_green', 'epic_ci_inherited')
SAFE_ID = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$')


def retry_when_locked(action):
    """The result of action(), run again (up to DB_RETRIES times, after a pause) while the database is locked."""
    for left in range(DB_RETRIES, -1, -1):
        try:
            return action()
        except DatabaseLocked:
            if not left:
                raise
            time.sleep(DB_RETRY_PAUSE_S)


def epic_ids(ao_home, busy_timeout_ms):
    """The epic ids of the database of the run directory: the one named by the directory (`ao-<epic>`) when the
    database has it, else every `epics` row."""
    if not os.path.isdir(ao_home):
        raise MetricsError('brak katalogu AO %s' % ao_home)
    db = os.path.join(ao_home, 'orchestrator.db')
    con = open_readonly(db, busy_timeout_ms)
    try:
        rows = select(con, 'epics', ['epic_id'], Gaps())
    except sqlite3.Error as e:
        raise _database_error(db, e)
    finally:
        con.close()
    ids = [r['epic_id'] for r in rows if isinstance(r['epic_id'], str)]
    if not ids:
        raise MetricsError('baza AO %s nie ma wiersza epiku' % db)
    name = os.path.basename(ao_home.rstrip(os.sep))
    return [name[len('ao-'):]] if name.startswith('ao-') and name[len('ao-'):] in ids else ids


def waste_history(records, skip):
    """The measurements of the stored records (of other epics than `skip`) the median rules compare with, per kind:
    `value` (of an ended attempt that has one), `wall` (wall_s of an ended attempt), `task` (the sum of the values of a
    task whose ended attempts all have one)."""
    found = {'value': {}, 'wall': {}, 'task': {}}
    for epic, record in records.items():
        if epic in skip:
            continue
        tasks = {}
        for attempt in record.get('attempts') or []:
            if not isinstance(attempt, dict) or attempt.get('ended_at') is None:
                continue
            kind = attempt.get('kind') if isinstance(attempt.get('kind'), str) else None
            if _is_number(attempt.get('value')):
                found['value'].setdefault(kind, []).append(attempt['value'])
            if _is_number(attempt.get('wall_s')):
                found['wall'].setdefault(kind, []).append(attempt['wall_s'])
            if attempt.get('task') is not None:         # a record made without task ids has no tasks to total
                tasks.setdefault((str(attempt['task']), kind), []).append(attempt.get('value'))
        for (_, kind), values in tasks.items():
            if all(_is_number(v) for v in values):
                found['task'].setdefault(kind, []).append(sum(values))
    return found


def over_median(rule, kind, values, amount, multiple, minimum, minimum_is_strict, min_history, gaps):
    """(median, ratio to it) when `amount` is more than `multiple` times the median of `values` and passes the minimum
    (more than it, or at least it), else None. Below the minimum no median could make it alarm, so a history shorter
    than `min_history` is a gap only for an amount that passes it (the rule does not alarm then)."""
    if not (amount > minimum if minimum_is_strict else amount >= minimum):
        return None
    if len(values) < min_history:
        gaps.add(rule, 'kind %s: %d z %d wymaganych pomiarów historii: reguła nie alarmuje' % (kind, len(values),
                                                                                              min_history))
        return None
    middle = median(values)
    if amount > multiple * middle:
        return middle, (amount / middle if middle > 0 else None)
    return None


def _id(value):
    return value if isinstance(value, str) and SAFE_ID.match(value) else '?'


def _decimal(value, digits=1):
    return ('%.*f' % (digits, value)).replace('.', ',')


def _of_median(ratio, what='mediany'):
    return '' if ratio is None else ' (%s× %s)' % (_decimal(ratio), what)


def make_alarm(rule, key, epic, task, attempt, push, **numbers):
    """An alarm: the push text holds only ids that are identifiers and numbers (never text of the run)."""
    return {'alarm': rule, 'key': key, 'epic': epic, 'task': task, 'attempt': attempt, 'push': push[:PUSH_MAX],
            **numbers}


ATTEMPT_ID = ('attempts.ended_at', 'attempts.task_id', 'attempts.number')      # an ended attempt of a task, numbered
KIND_OF_TASK = ('tasks.kind', 'tasks.task_id')
# The columns of the AO database each rule needs: without one the rule cannot tell what it measures (an attempt of a
# task, its kind, whether it ended), so it is skipped with a gap instead of alarming on a guess.
WASTE_NEEDS = {
    'W1': ATTEMPT_ID + KIND_OF_TASK,
    'W2': ATTEMPT_ID + ('attempts.started_at',) + KIND_OF_TASK,
    'W3 (a)': ATTEMPT_ID + ('attempts.outcome', 'attempts.executor_status'),
    'W3 (b)': KIND_OF_TASK + ('attempts.task_id', 'attempts.started_at', 'events.type', 'events.at', 'events.task_id',
                              'events.data'),
    'W3 (c)': ('epics.round',),
    'W4': ATTEMPT_ID + KIND_OF_TASK,
}
WASTE_SKIPPED = {'W1': 'koszt próby nie sprawdzony', 'W2': 'trwająca próba nie sprawdzona',
                 'W3 (a)': 'pętla prób nieudanych nie sprawdzona',
                 'W3 (b)': 'kolejny REPAIR bez zielonego CI nie sprawdzony', 'W3 (c)': 'runda QA nie sprawdzona',
                 'W4': 'koszt zadania nie sprawdzony'}


def waste_unknown(run, rule, gaps):
    """True (and a gap of the rule, `W3` for its parts) when a column that `rule` needs is missing in the AO database."""
    missing = [c for c in WASTE_NEEDS[rule] if c in run['absent']]
    if rule == 'W2' and lacks(run, 'epics.status') and lacks(run, 'events.type'):
        missing += ['epics.status', 'events.type']          # whether the run is over is not known
    if missing:
        gaps.add(rule[:2], 'brak kolumny %s w bazie AO: %s' % (', '.join(sorted(set(missing))), WASTE_SKIPPED[rule]))
    return bool(missing)


def waste_alarms(ao_home, run, config, records, history, max_rounds, now, gaps):
    """The alarms (W1-W4) the current state of the run raises, whether or not they were sent before. Ended attempts
    only for the cost rules (a running attempt has no cost estimate), running ones only for W2."""
    rules = config['waste']
    epic, epic_id = run['epic'], run['epic']['epic_id']
    kinds = {t['task_id']: t['kind'] for t in run['tasks']}
    task_ids = set(kinds)
    name = _id(epic_id)
    found = []
    ended = [a for a in run['attempts'] if a['ended_at'] is not None]
    entries = transcript_fields(ao_home, dict(run, attempts=ended), config, records, epic_id, Gaps())['attempts']
    # W1: the value of an ended attempt
    unknown = 0
    for entry in [] if waste_unknown(run, 'W1', gaps) else entries:
        if entry['value'] is None:
            unknown += 1
            continue
        hit = over_median('W1', entry['kind'], history['value'].get(entry['kind'], []), entry['value'],
                          rules['cost_multiple'], rules['cost_min_usd'], False, rules['min_history'], gaps)
        if hit:
            found.append(make_alarm(
                'W1', 'W1:%s:%s:%s' % (epic_id, entry['task'], entry['number']), epic_id, entry['task'],
                entry['number'],
                '%s: %s próba %s kosztowała %s%s USD%s' % (name, _id(entry['task']), entry['number'],
                                                           '≈' if entry['estimated'] else '',
                                                           _decimal(entry['value'], 2), _of_median(hit[1])),
                value=entry['value'], median=hit[0], ratio=hit[1], estimated=bool(entry['estimated'])))
    if unknown:
        gaps.add('W1', '%d z %d zakończonych prób bez wartości (brak transkryptu albo stawki): nie sprawdzone'
                 % (unknown, len(entries)))
    # W2: a running attempt of a run that is not over
    over = epic['status'] == 'DONE' or any(e['type'] == 'epic_done' for e in run['events'])
    for attempt in [] if waste_unknown(run, 'W2', gaps) else run['attempts']:
        start = _number(attempt['started_at'])
        if over or attempt['ended_at'] is not None or attempt['outcome'] or start is None or not _is_int(
                attempt['number']):
            continue
        kind, seconds = kinds.get(attempt['task_id']), now - start
        hit = over_median('W2', kind, history['wall'].get(kind, []), seconds, rules['time_multiple'],
                          rules['time_min_h'] * 3600, True, rules['min_history'], gaps)
        if hit:
            found.append(make_alarm(
                'W2', 'W2:%s:%s:%s' % (epic_id, attempt['task_id'], attempt['number']), epic_id, attempt['task_id'],
                attempt['number'], '%s: %s próba %s trwa %s h%s' % (name, _id(attempt['task_id']), attempt['number'],
                                                                    _decimal(seconds / 3600.0), _of_median(hit[1])),
                seconds=round(seconds, 3), median=hit[0], ratio=hit[1]))
    found.extend(loop_alarms(run, name, task_ids, rules, max_rounds, gaps))
    # W4: the value of the ended attempts of a task
    by_task, no_value = {}, 0
    for entry in [] if waste_unknown(run, 'W4', gaps) else entries:
        by_task.setdefault(entry['task'], []).append(entry)
    for task, task_entries in by_task.items():
        if any(e['value'] is None for e in task_entries):
            no_value += 1
            continue
        kind, amount = task_entries[0]['kind'], sum(e['value'] for e in task_entries)
        hit = over_median('W4', kind, history['task'].get(kind, []), amount, rules['cost_multiple'],
                          rules['cost_min_usd'], False, rules['min_history'], gaps)
        if hit:
            found.append(make_alarm(
                'W4', 'W4:%s:%s' % (epic_id, task), epic_id, task, None,
                '%s: %s kosztuje łącznie %s USD%s' % (name, _id(task), _decimal(amount, 2),
                                                     _of_median(hit[1], 'mediany zadań')),
                value=round(amount, 8), median=hit[0], ratio=hit[1], attempts=len(task_entries)))
    if no_value:
        gaps.add('W4', '%d zadań z zakończoną próbą bez wartości: nie sprawdzone' % no_value)
    return found


def loop_alarms(run, name, task_ids, rules, max_rounds, gaps):
    """W3: loops of attempts, independent of the median and the cost. (a) an attempt that starts after at least
    `loop_failed_attempts` failed attempts of its task (blocked and usage_limit attempts are not failures); (b) a REPAIR
    task that starts with no green CI of the epic (epic_ci_green, epic_ci_inherited) since the previous REPAIR started;
    (c) the QA round of the epic equal to `max_rounds`. A part whose columns (WASTE_NEEDS) are missing in the AO
    database is skipped and `gaps` says so."""
    epic, epic_id = run['epic'], run['epic']['epic_id']
    found, per_task = [], {}
    skip_loop, skip_repair = waste_unknown(run, 'W3 (a)', gaps), waste_unknown(run, 'W3 (b)', gaps)
    for attempt in run['attempts']:
        per_task.setdefault(attempt['task_id'], []).append(attempt)
    for task, attempts in {} if skip_loop else per_task.items():
        failed = 0
        for attempt in sorted(attempts, key=lambda a: (_number(a['started_at']) is None, _number(a['started_at']) or 0,
                                                       a['number'] or 0)):
            if failed >= rules['loop_failed_attempts'] and _is_int(attempt['number']):
                found.append(make_alarm(
                    'W3', 'W3:%s:%s:%s' % (epic_id, task, attempt['number']), epic_id, task, attempt['number'],
                    '%s: %s: %s. próba po %s nieudanych' % (name, _id(task), attempt['number'], failed),
                    failed=failed))
            if classify_attempt(attempt['outcome'], attempt['executor_status'],
                                attempt['ended_at'] is not None)[0] == 'failed':
                failed += 1
    greens = [e['at'] for e in run['events'] if e['type'] in CI_GREEN and _of_epic(e, epic_id, task_ids)]
    starts = []
    for task in [] if skip_repair else run['tasks']:
        begun = [_number(a['started_at']) for a in per_task.get(task['task_id'], [])
                 if _number(a['started_at']) is not None]
        if task['kind'] == 'repair' and begun:
            starts.append((min(begun), task['task_id']))
    starts.sort()
    for (before, _), (start, task) in zip(starts, starts[1:]):
        if not any(before <= g <= start for g in greens):
            found.append(make_alarm('W3', 'W3:%s:repair:%s' % (epic_id, task), epic_id, task, None,
                                    '%s: %s: kolejny REPAIR z rzędu bez zielonego CI epiku' % (name, _id(task))))
    if not waste_unknown(run, 'W3 (c)', gaps) and _is_int(epic['round']) and epic['round'] == max_rounds:
        found.append(make_alarm('W3', 'W3:%s:qa:%s' % (epic_id, epic['round']), epic_id, None, None,
                                '%s: runda QA %s (limit %s)' % (name, epic['round'], max_rounds), round=epic['round'],
                                max_rounds=max_rounds))
    return found


def read_waste_file(path):
    """The alarms stored in waste.json, or None when the file is missing. A file that cannot be read, is not JSON or
    has another shape than {"alarms": [{"key": ...}, ...]} is an error: it must never be replaced."""
    try:
        with open(path, encoding='utf-8') as f:
            data = json.load(f)
    except FileNotFoundError:
        return None
    except OSError as e:
        raise WasteFileError(path, 'nieczytelny plik: %s' % (e.strerror or e))
    except ValueError as e:
        raise WasteFileError(path, 'plik nie jest poprawnym JSON: %s' % e)
    if not (isinstance(data, dict) and isinstance(data.get('alarms'), list)
            and all(isinstance(a, dict) and isinstance(a.get('key'), str) for a in data['alarms'])):
        raise WasteFileError(path, 'zły kształt pliku, oczekiwano {"alarms": [{"key": ...}]}')
    return data['alarms']


def waste_of_epic(state_dir, epic_id):
    """(alarms of the epic from waste.json, None), or (None, reason) when the file is missing or unreadable."""
    path = os.path.join(state_dir, 'waste.json')
    try:
        stored = read_waste_file(path)
    except WasteFileError as e:
        return None, 'nieczytelny waste.json: %s' % e.reason
    if stored is None:
        return None, 'brak pliku waste.json (komenda waste jeszcze nie zapisała alarmów)'
    return [a for a in stored if a.get('epic') == epic_id], None


def store_and_print(state_dir, candidates, now):
    """The candidates whose key is not in waste.json yet, stored and then printed (one JSON line each) while holding
    <state>/waste.lock: read, filter, atomic write, print are one step for parallel sessions, so a key is printed once.
    The file is written before the printing, so a failure stores nothing that was not printed, and a printing that fails
    after the write loses the alarm instead of printing it twice. Returns the printed alarms."""
    path, lock_path = os.path.join(state_dir, 'waste.json'), os.path.join(state_dir, 'waste.lock')
    try:
        os.makedirs(state_dir, exist_ok=True)
        fd = os.open(lock_path, os.O_RDWR | os.O_CREAT, 0o600)
    except OSError as e:
        raise MetricsError('nie można utworzyć blokady %s: %s' % (lock_path, e.strerror or e))
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX)
        except OSError as e:
            raise MetricsError('nie można zablokować %s: %s' % (lock_path, e.strerror or e))
        existing = read_waste_file(path)
        stored = existing or []
        known = {a['key'] for a in stored}
        new = []
        for alarm in candidates:
            if alarm['key'] not in known:
                known.add(alarm['key'])
                new.append(alarm)
        if existing is None and not new:
            write_record(path, {'alarms': []})          # the file says that `waste` ran, even with nothing to report
        if new:
            at = iso(now)
            write_record(path, {'alarms': stored + [{**{k: v for k, v in a.items() if k != 'alarm'}, 'rule': a['alarm'],
                                                     'at': at} for a in new]})
            sys.stdout.write(''.join(json.dumps(a, ensure_ascii=False) + '\n' for a in new))
            sys.stdout.flush()
        return new
    finally:
        os.close(fd)


def cmd_waste(args, config, now):
    ao_home, state = _expand(args.ao_home), _expand(args.state)
    busy = config['busy_timeout_ms'] if args.busy_timeout_ms is None else args.busy_timeout_ms
    ids = retry_when_locked(lambda: epic_ids(ao_home, busy))
    records = read_records(state)
    history = waste_history(records, set(ids))
    gaps, candidates = Gaps(), []
    for epic_id in ids:
        run = retry_when_locked(lambda: load_run(ao_home, epic_id, busy, Gaps()))
        candidates.extend(waste_alarms(ao_home, run, config, records, history, args.max_qa_rounds, now, gaps))
    store_and_print(state, candidates, now)
    if gaps.items:
        print(json.dumps({'gaps': gaps.render()}, ensure_ascii=False))
    return 0


def _positive_int(text):
    try:
        value = int(text)
    except ValueError:
        raise argparse.ArgumentTypeError('oczekiwano dodatniej liczby całkowitej, jest %r' % text)
    if value < 1:
        raise argparse.ArgumentTypeError('oczekiwano dodatniej liczby całkowitej, jest %r' % text)
    return value


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
    render = sub.add_parser('render', parents=[common])
    render.add_argument('epic')
    render.add_argument('--format', choices=('report', 'comment'), default='report')
    trend = sub.add_parser('trend', parents=[common])
    trend.add_argument('--last', type=int, default=None)
    trend.add_argument('--epic')
    trend.add_argument('--json', action='store_true')
    trend.add_argument('--no-gh', action='store_true')
    decide = sub.add_parser('decide', parents=[common])
    decide.add_argument('epic')
    decide.add_argument('--history', action='store_true')
    effect = sub.add_parser('effect', parents=[common])
    effect.add_argument('key')
    effect.add_argument('--at', required=True)
    waste = sub.add_parser('waste', parents=[common])
    waste.add_argument('ao_home')
    waste.add_argument('--max-qa-rounds', type=_positive_int, default=3)
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
        if args.command == 'trend':
            return cmd_trend(args, config, runner, now)
        if args.command == 'render':
            return cmd_render(args, config, runner, now)
        if args.command == 'decide':
            return cmd_decide(args, config, runner, now)
        if args.command == 'effect':
            return cmd_effect(args, config, runner, now)
        if args.command == 'waste':
            return cmd_waste(args, config, now)
    except MetricsError as e:
        print('metrics: %s' % e, file=sys.stderr)
        return EXIT_FATAL
    return EXIT_FATAL


if __name__ == '__main__':
    sys.exit(main())

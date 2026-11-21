#!/usr/bin/env python3
"""Measurement of AO and native epic runs (spec docs/features/metrics/spec.md). Python 3.9+, standard library only.

  metrics.py start EPIC [--resume] [--at TIME] [--worktree DIR] [--branch NAME] [--pr N] [--tasks N] [--session ID]
                        [--projects DIR] [--user-dir DIR] [--accept-process-mismatch REASON] [COMMON] < get_usage reading
  metrics.py done EPIC [--at TIME] [--worktree DIR] [--projects DIR] [--user-dir DIR] [COMMON] < get_usage reading
  metrics.py pause EPIC --from TIME --to TIME [--reason TEXT] [COMMON]
  metrics.py process [EPIC] [--worktree DIR] [--base REF] [--user-dir DIR] [--projects DIR] [COMMON]
  metrics.py epic EPIC [--ao-home DIR] [--projects DIR] [--manual-fixes FILE] [--no-gh] [COMMON]
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

start, done: the run file <state>/runs/<EPIC>.json of a native epic (one led by a Claude Code session with native
subagents, spec docs/features/ash-v1/spec.md, M1): start writes the start time (now or --at), the SHA of origin/main,
the branch, the draft PR, the number of tasks of the plan.md manifest (night_queue.task_count), the lead session
(--session or the newest transcript of the working directory under --projects, default ~/.claude/projects) and the
limits read from stdin (night_queue.parse_usage; a terminal or an empty input is no reading); --resume appends a new session; done
records the time the PR was marked ready and the second reading. Exit 2 without a branch, PR, number of tasks or
session, on a second start without --resume, and on --resume or done without a run file. start and start --resume
refuse a session id that is in the run file of another epic (one lead session, one epic).

process: the version of the process (process_version.py): `expected` (the base commit of the epic or HEAD), `disk`
(the instruction files on disk in the worktree and in the main checkout, which a desktop session loads the agents and
skills from) and, for an epic, `loaded` (what the transcripts confirm). start stores the reading in the run file field
`process` and refuses a mismatch without --accept-process-mismatch REASON; start --resume, done and `process EPIC`
compare the disk with that reading and add every change to `process.changes`. Exit 4 on a mismatch.

pause: a pause of the computer (--from, --to) in the run file `pauses`; the record counts it as `host_pause`.

epic: prints the record of the epic run as one JSON line and stores it in <state>/epics/<EPIC>.json. The run is the
AO directory --ao-home (default: ao-<EPIC> under $SHOP_METRICS_AO_ROOT or ~/.local/state), `source: ao`; without it the
run file of a native epic, `source: native` (the transcripts of its sessions and their subagents only, the messages
from the start to the merge of the final PR, every message id once, the value from the API price list `pricing` of
config.json, always an estimate; the time breakdown and the human messages from the lines of the transcripts, the
reviews from the `SubagentHandback` reports of the review roles (the verdict only), the acceptance criteria from the
description of the final PR read through the origin gate (numbers only), the subscription from the two readings of
the run file and the tokens of all local sessions; fields without a native source are null with a gap); without
both exit 2. Fields
of an AO record from the AO database and events (`orchestrator.db` is opened read only, `ao` is never run), from measurements.jsonl and gate.jsonl, and
from GitHub (`gh`, read only, also for `ci`, `regressions` and `found_after_merge`; --no-gh skips it). A source that is missing gives `null` and an entry in `gaps`, never
zero. A value from GitHub or from --manual-fixes that is `null` now but not in the stored record is kept (a transient
error never replaces it); the gap says so. An epic closed by hand (no epic_done, the final PR merged) has its breakdown
until the merge, marked `breakdown_estimated`; a final PR unknown to AO is the only merged PR epic/<EPIC> -> main.

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
3 the record was printed and stored but a source failed (`gh` error; message on stderr, the gap is in `gaps`);
4 `process` printed its reading and the definitions differ (summary on stderr).
trend, render and decide also end with 2 without a stored record (or without the requested epic), effect with a bad key
or --at or without a stored record of a merged epic.
"""
import argparse
import contextlib
import fcntl
import glob
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
sys.path.insert(0, os.path.join(HERE, '..', 'agent-security'))
import github_trust  # noqa: E402  (the origin gate: the description of the final PR of a native epic)
sys.path.insert(0, HERE)
import process_version  # noqa: E402  (the version of the process: expected, disk and loaded instructions)

DEFAULT_CONFIG = os.path.join(HERE, 'config.json')
EXIT_FATAL = 2
EXIT_SOURCE = 3
EXIT_PROCESS = 4                # `process`: the definitions differ from the expected ones
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


# The rule of the spec (Dokładność): a number that is estimated, incomplete or derived from such data is marked
# `estimated: true` (and `incomplete: true` where a part is missing) and shown with `≈`. The conservative rule of the
# medians and ratios: any marked input marks the output. One test and one prefix serve every place.
APPROX = '\u2248'


def inexact(cell):
    """Whether a cell of a record (a value cell, a `by_task` entry, `in_attempts`, `integration`, ...) is marked as
    estimated or incomplete."""
    return isinstance(cell, dict) and (cell.get('estimated') is True or cell.get('incomplete') is True)


def _category_list(record, field):
    found = record.get(field) if isinstance(record, dict) else None
    return {c for c in found if isinstance(c, str)} if isinstance(found, list) else set()


def lower_bounds(record):
    """The categories of `breakdown` of the record that are lower bounds (`breakdown_incomplete`): part of their time
    was skipped for lack of data and is in the other categories and in `idle`."""
    return _category_list(record, 'breakdown_incomplete')


def upper_bounds(record):
    """The categories of `breakdown` of the record that are upper bounds (`breakdown_upper_bound`): they come after a
    lower bound in the order of the breakdown, so the skipped time may be in them (`idle` is one whenever there is a lower
    bound). A native record has `breakdown_incomplete: true` (a bool, not a list of categories): the categories it has no
    source for are null, their time is in `idle`, so `idle` is an upper bound and the others are exact."""
    found = _category_list(record, 'breakdown_upper_bound')
    if isinstance(record, dict) and record.get('breakdown_incomplete') is True:
        found = found | {'idle'}
    return found


def upper_bounds_of(lower, order):
    """The categories of `order` (config `breakdown_order`) that come after the earliest of the `lower` ones (except
    `idle`, the remainder): each moment goes to the first category that covers it, so the time of an interval skipped for
    lack of data lands in any of them."""
    first = min((order.index(c) for c in lower if c in order), default=None)
    return [] if first is None else [c for c in order[first + 1:] if c != 'idle']


def breakdown_inexact(record, name):
    """Whether the category `name` of `breakdown` is a lower bound (skipped time) or an upper bound (it may hold skipped
    time); `idle` is an upper bound whenever there is a lower bound."""
    if isinstance(record, dict) and record.get('breakdown_estimated') is True:
        return True
    found = lower_bounds(record)
    return name in found or name in upper_bounds(record) or (name == 'idle' and bool(found))


def approx(flag, space=' '):
    """`≈ ` (with `space` after the sign) for a marked number, else an empty string."""
    return APPROX + space if flag else ''


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


# The kinds of tokens of the price list (USD per million tokens); a model may lack a kind (its tokens have no value).
PRICE_KINDS = ('input', 'output', 'cache_read', 'cache_write_5m', 'cache_write_1h')


def _pricing(value):
    if not isinstance(value, dict) or set(value) != {'source', 'read_at', 'models'}:
        return False
    if not isinstance(value['source'], str) or not value['source'] or not isinstance(value['read_at'], str):
        return False
    if not re.match(r'^\d{4}-\d{2}-\d{2}$', value['read_at']) or not isinstance(value['models'], dict):
        return False
    return all(isinstance(model, str) and model and isinstance(rates, dict) and rates and set(rates) <= set(PRICE_KINDS)
               and all(_is_number(rate) and rate >= 0 for rate in rates.values())
               for model, rates in value['models'].items())


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
    'pricing': (_pricing, 'obiekt {source, read_at (RRRR-MM-DD), models: {model: {%s: USD za milion tokenów, '
                          'liczba nieujemna}}}' % ', '.join(PRICE_KINDS)),
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


def category_intervals(run, done, jsonl, config, gaps, lower=None):
    """{category: [(start, end)] or None} for the breakdown categories except idle (before clipping and
    precedence). None: the source of the category is missing. `lower`: a set that gets the categories whose time was
    skipped for lack of data (lower bounds)."""
    lower = set() if lower is None else lower
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
            lower.add('work')
            gaps.add('breakdown.work', 'próba %s/%s bez started_at: pominięta w czasie work' % (attempt['task_id'],
                                                                                              attempt['number']))
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
                lower.add('limit_wait')
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
                lower.add('host_pause')
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


def breakdown_of(run, started, done, jsonl, jsonl_gap, config, gaps, lower=None):
    """The split of start -> epic_done into disjoint categories: each moment goes to the first category (in the order
    of config.json) whose interval covers it; the rest is idle. A category without a source is None. `lower`: a set that
    gets the categories that are lower bounds (time skipped for lack of data; `idle` is then an upper bound)."""
    if done is None or started is None:
        gaps.add('breakdown', 'brak epic_done: rozkład czasu niemożliwy')
        return None
    if jsonl is None:
        gaps.add('breakdown.human_wait', '%s: czas czekania na człowieka jest w idle' % jsonl_gap)
    found = category_intervals(run, done, jsonl, config, gaps, lower)
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


def wall_unknown(run):
    """True when no attempt has a time: the AO database has neither wall_s nor both the start and the end."""
    return lacks(run, 'attempts.wall_s') and lacks(run, 'attempts.started_at', 'attempts.ended_at')


WALL_UNKNOWN = 'brak kolumny wall_s i początku albo końca prób w bazie AO: czas nieznany'


def integration_of(run, epic, gaps):
    if lacks(run, 'tasks.kind', 'tasks.task_id', 'attempts.task_id'):    # which attempts are integration ones is unknown
        return {'work_s': None, 'attempts': None, 'rounds': epic['round']}
    kinds = {t['task_id']: t['kind'] for t in run['tasks']}
    work, count, incomplete = 0.0, 0, False
    for attempt in run['attempts']:
        if kinds.get(attempt['task_id']) not in INTEGRATION_KINDS:
            continue
        count += 1
        if wall_unknown(run):
            gaps.add('integration', WALL_UNKNOWN)
            continue
        seconds = _number(attempt['wall_s'])
        start, end = _number(attempt['started_at']), _number(attempt['ended_at'])
        if seconds is None and start is not None and end is not None:
            seconds = end - start
        if seconds is None:
            incomplete = True                           # the sum is a lower bound
            gaps.add('integration', 'próba %s/%s bez wall_s i końca: pominięta w czasie' % (attempt['task_id'],
                                                                                         attempt['number']))
            continue
        work += seconds
    found = {'work_s': None if wall_unknown(run) else round(work, 3), 'attempts': count, 'rounds': epic['round']}
    if incomplete:
        found['incomplete'] = True
    return found


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


def _lifetime_known(usage):
    """Whether the `usage` of a message says how long its cache write lives: no write at all, or the split
    `cache_creation` (5 minutes, 1 hour) that sums to it. Without it `_message_tokens` takes all as a 5 minute write."""
    if not isinstance(usage, dict):
        return False
    written = _int(usage.get('cache_creation_input_tokens'))
    split = usage.get('cache_creation')
    return written == 0 or (isinstance(split, dict) and _int(split.get('ephemeral_5m_input_tokens'))
                            + _int(split.get('ephemeral_1h_input_tokens')) == written)


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
                'tokens': _message_tokens(message.get('usage')),
                'at': _timestamp(line.get('timestamp')), 'usage': isinstance(message.get('usage'), dict),
                'lifetime': _lifetime_known(message.get('usage'))}
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
    unknown_tasks = []
    for task, cells in items:
        if cells is None:
            sections['total'].mark_unknown('all')
            if with_tasks:
                sections['by_task'].mark_unknown(task)
            unknown_tasks.append(task)
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
    if unknown_tasks:       # the role and the model of an attempt without cells are unknown: every one may be short
        for name in ('by_role', 'by_model', 'by_role_model'):
            for key in list(sections[name].cells):
                sections[name].mark_unknown(key)
        for task, role in list(sections['by_task_role'].cells):
            if task in unknown_tasks:
                sections['by_task_role'].mark_unknown((task, role))
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
    no_path = ', '.join(c for c in ('attempts.task_id', 'attempts.number') if lacks(run, c))
    no_path = 'brak kolumny %s w bazie AO: nie wiadomo, do której próby należy transkrypt' % no_path if no_path else ''
    by_task = None if no_task else {}
    for attempt in [] if no_task else attempts:
        task = by_task.setdefault(attempt['task_id'], {'kind': kinds.get(attempt['task_id']), 'work': 0.0,
                                                       'attempts': 0})
        task['attempts'] += 1
        seconds = _wall(attempt)
        if wall_unknown(run):
            task['work'] = None
            gaps.add('by_task', WALL_UNKNOWN)
        elif seconds is None:
            task['incomplete'] = True                   # the sum is a lower bound
            gaps.add('by_task', 'próba %s/%s bez wall_s i końca: pominięta w czasie' % (attempt['task_id'],
                                                                                    attempt['number']))
        else:
            task['work'] = round(task['work'] + seconds, 3)
    entries, parsed, missing, rates, pending = [], [], [], [], []
    for attempt in attempts:
        label = '%s/%s' % (attempt['task_id'], attempt['number'])
        path = transcript_path(ao_home, attempt)
        data, reason = read_transcript(path) if path else (None, no_path or 'nieprawidłowy identyfikator próby')
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
    elif len(datas) < len(parsed):      # the sums cover only the attempts with a transcript: lower bounds
        in_attempts.update(incomplete=True, attempts_without_transcript=len(parsed) - len(datas), attempts=len(parsed))
        gaps.add('in_attempts', 'czasy w próbach z %d z %d prób (reszta bez transkryptu): dolna granica'
                 % (len(datas), len(parsed)))
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


def add_share(subscription, stretches, attempts, lookup, gaps, missing_times=()):
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
    if missing_times:           # which part of an attempt lies in the common interval is unknown
        gaps.add('subscription.share', 'brak kolumny %s w bazie AO: czasy prób nieznane' % ', '.join(missing_times))
        return
    common = clip_merge([stretch for epic in subscription['overlap'] for stretch in stretches.get(epic, [])],
                        float('-inf'), float('inf'))
    mine = value_within(attempts, common)
    if mine is None:
        gaps.add('subscription.share', 'wartość tokenów epiku nieznana (próby bez wartości)')
        return
    total = mine
    for other in subscription['overlap']:
        others = lookup(other)
        known = others is not None and (not others or any(_timestamp(a.get('started_at')) is not None
                                                          for a in others if isinstance(a, dict)))
        theirs = value_within(others, clip_merge(stretches.get(other, []), float('-inf'), float('inf'))) if known \
            else None
        if theirs is None:
            gaps.add('subscription.share', 'brak wartości tokenów epiku %s (brak rekordu i katalogu przebiegu albo próby'
                     ' bez wartości albo bez początku)' % other)
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
    """{wait_s, reasons}: from the decision to start the epic to its start, when the gate made it wait. The decision is
    the first entry of `gate --dir <epic>` (`dir_arg`) in gate.jsonl at or before the start: /ao-run calls it right
    after the user's start request or "yes" (section 1), at night `next` writes it for the candidate of the approved
    night. Waits of the board scan before it (`gate` without --dir, section 8: a proposal, not a decision) do not count.
    0 when no `waiting` entry of the epic comes between the decision and the start; None (and a gap) without the file,
    without any entry of the epic, or with waits but no recorded decision."""
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
    before = sorted(((at, e) for at, e in mine if at <= started), key=lambda pair: pair[0])
    decision = next((at for at, e in before if e.get('dir_arg') == epic_id), None)
    waiting = [(at, e) for at, e in before if e.get('waiting') == epic_id and (decision is None or at >= decision)]
    if not waiting:
        return {'wait_s': 0.0, 'reasons': []}
    if decision is None:
        gaps.add('gate_wait', 'czekanie w bramce bez wpisu gate --dir epiku przed startem: moment decyzji o starcie '
                              'nieznany')
        return None
    reasons = []
    for _, entry in waiting:
        if isinstance(entry.get('reason'), str) and entry['reason'] not in reasons:
            reasons.append(entry['reason'])
    return {'wait_s': round(started - decision, 3), 'reasons': reasons}


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


def final_pr_of_branch(run, no_gh, epic_id, repo, gaps, errors):
    """The final PR of an epic whose AO row has none (an epic closed by hand, e.g. AO in BLOCKED and the user merged
    epic/<epic> -> main): the only merged PR of that branch into main, else None. Every outcome leaves a gap."""
    if no_gh:
        gaps.add('final_pr', 'epik nie ma końcowego PR')
        return None
    try:
        data = gh_json(run, ['pr', 'list', '--head', 'epic/%s' % epic_id, '--base', 'main', '--state', 'merged',
                             '--json', 'number', '--limit', '10'], repo)
        numbers = sorted(p['number'] for p in data)
    except GhError as e:
        errors.append(str(e))
        gaps.add('final_pr', 'epik nie ma końcowego PR w AO, błąd gh: %s' % e)
        return None
    except (KeyError, TypeError):
        errors.append('gh pr list: nieoczekiwany kształt odpowiedzi')
        gaps.add('final_pr', 'epik nie ma końcowego PR w AO, błąd gh: nieoczekiwany kształt odpowiedzi')
        return None
    if not numbers:
        gaps.add('final_pr', 'epik nie ma końcowego PR')
        return None
    if len(numbers) > 1:
        gaps.add('final_pr', 'epik nie ma końcowego PR w AO, a epic/%s ma kilka zmergowanych PR do main: %s'
                 % (epic_id, ', '.join('#%d' % n for n in numbers)))
        return None
    gaps.add('final_pr', 'epik nie ma końcowego PR w AO (zamknięty ręcznie): #%d to zmergowany PR epic/%s -> main'
             % (numbers[0], epic_id))
    return numbers[0]


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
              ('waste',), ('acceptance',))


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
    final_pr, lookup_errors = epic['final_pr_number'], []
    if final_pr is None:
        final_pr = final_pr_of_branch(run, no_gh, epic_id, repo, gaps, lookup_errors)
    github = github_data(run, no_gh, final_pr, epic_id, repo, gaps, pr)
    github['errors'] = lookup_errors + github['errors']
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
    # An epic closed by hand (no epic_done, the final PR merged) has its breakdown until the merge, as an estimate.
    breakdown_end = done if done is not None or known_merge is None or started is None else known_merge
    if done is None and breakdown_end is not None:
        gaps.add('breakdown', "brak epic_done: koniec rozkładu to merge końcowego PR (szacunek, epik zamknięty ręcznie)")
    subscription, parallel, stretches, span = night_fields(ao_run, epic_id, started, run_end, night_dir, gaps)
    fields = transcript_fields(ao_home, ao_run, config, records, epic_id, gaps)
    if subscription is not None:
        add_share(subscription, stretches, fields['attempts'],
                  lambda other: other_attempts(other, records, ao_root, config, busy_timeout_ms), gaps,
                  [c for c in ('attempts.started_at', 'attempts.ended_at') if lacks(ao_run, c)])
    retries = retries_of(ao_run, run_ended, gaps)
    retries['sync_prs'] = None if github['sync'] is None else [{'number': p['number'], 'state': p['state']}
                                                                for p in github['sync']]
    if parallel is not None:
        parallel['sync_prs'] = None if github['sync'] is None else sorted(
            p['number'] for p in github['sync']
            if any(s_start <= p['created'] < s_end for stretch in stretches.values() for s_start, s_end in stretch))
        if github['sync'] is None:
            gaps.add('parallel.sync_prs', 'brak listy PR sync')
    lower = set()
    record = {
        'epic': epic_id,
        'source': 'ao',
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
        'breakdown': breakdown_of(ao_run, started, breakdown_end, jsonl, jsonl_gap, config, gaps, lower),
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
    if done is None and breakdown_end is not None:
        record['breakdown_end'] = 'merged_at'
        record['breakdown_estimated'] = True
    if lower:
        record['breakdown_incomplete'] = [c for c in config['breakdown_order'] if c in lower]
        upper = upper_bounds_of(lower, config['breakdown_order'])
        if upper:
            record['breakdown_upper_bound'] = upper
    errors = github['errors']
    record.update(github_derived(run, no_gh, repo, config, epic_id, ao_run['tasks'], final_pr, github['pr'], known_merge,
                                 now, previous, gaps, errors))
    if waste[0] is None:
        gaps.add('waste', waste[1])
    keep_stored_values(record, previous, gaps)
    record['bottlenecks'] = bottlenecks_of(record, history_of(record, records), config, gaps)
    record['gaps'] = gaps.render()
    return record, errors


# ------------------------------------------------------------------------------------- native epics: the run file

# An epic led by a native Claude Code session (docs/features/ash-v1/spec.md, M1-M3): `start` and `done` write the run
# file <state>/runs/<EPIC>.json, `epic` builds the record from it and from the transcripts of its sessions.
NO_TASK = 'no_task'                         # the task of the lead session and of a subagent without a task id (D4)
LEAD_ROLE = 'orchestrator'                  # the role of the lead session
NATIVE_SOURCE = 'brak źródła w przebiegu natywnym'


def run_path(state_dir, epic_id):
    return os.path.join(state_dir, 'runs', '%s.json' % epic_id)


def checked_id(value, what):
    if not isinstance(value, str) or not SAFE_ID.match(value):
        raise MetricsError('zły identyfikator %s %r: oczekiwano liter, cyfr, `.`, `_` i `-` (do 64 znaków)'
                           % (what, value))
    return value


NATIVE_EPIC_NAME = re.compile(r'^[a-z0-9][a-z0-9-]*$')       # the pattern of EPIC_NAME in .claude/skills/retro/retro.py


def native_epic_id(value):
    """The name of a native epic: the run file is named after it and `/retro` finds the file only by this pattern."""
    checked_id(value, 'epiku')
    if not NATIVE_EPIC_NAME.match(value):
        raise MetricsError('nazwa epiku %r poza wzorcem %s (małe litery, cyfry i `-`, zaczyna się od litery albo cyfry): '
                           'plik przebiegu o innej nazwie nie zostałby rozpoznany przez /retro'
                           % (value, NATIVE_EPIC_NAME.pattern))
    return value


def read_run_file(path):
    """The run file as a dict, None when it does not exist; MetricsError when it cannot be read or is not an object."""
    try:
        with open(path, encoding='utf-8') as f:
            data = json.load(f)
    except FileNotFoundError:
        return None
    except (OSError, ValueError) as e:
        raise MetricsError('błąd odczytu pliku przebiegu %s: %s' % (path, getattr(e, 'strerror', None) or e))
    if not isinstance(data, dict):
        raise MetricsError('błąd odczytu pliku przebiegu %s: oczekiwano obiektu JSON' % path)
    return data


def project_dir(projects, worktree):
    """The directory of the session transcripts of a working directory (Claude Code turns every character that is
    not a letter or a digit into `-`)."""
    return os.path.join(projects, re.sub(r'[^A-Za-z0-9]', '-', worktree))


def newest_session(projects, worktree):
    """The id of the newest session transcript (`<id>.jsonl`, by modification time) of the working directory."""
    directory = project_dir(projects, worktree)
    try:
        found = [(os.path.getmtime(os.path.join(directory, name)), name[:-len('.jsonl')])
                 for name in os.listdir(directory) if name.endswith('.jsonl')]
    except OSError:
        found = []
    if not found:
        raise MetricsError('brak transkryptu sesji w %s: podaj --session' % directory)
    return max(found)[1]


def read_usage(stream):
    """The limits read from the standard input (night_queue.parse_usage): the `plan` object of `get_usage`, or the
    whole `get_usage` object (`{"plan": {...}, "context": {...}}`), whose `plan` is taken; None without a reading (a
    terminal, no input or an empty one)."""
    try:
        if stream is None or stream.isatty():
            return None
        text = stream.read()
    except (OSError, UnicodeDecodeError, ValueError):
        return {'status': 'unreadable'}
    if not text.strip():
        return None
    try:
        data = json.loads(text)
    except ValueError:
        data = None
    if isinstance(data, dict) and 'plan' in data:
        return night_queue.parse_usage(json.dumps(data['plan'])) if isinstance(data['plan'], dict) \
            else {'status': 'unreadable'}
    return night_queue.parse_usage(text)


def _command_text(run, command):
    """(stdout, None) of a command, or (None, reason) when it fails."""
    try:
        result = run(command)
    except (OSError, subprocess.TimeoutExpired) as e:
        return None, str(getattr(e, 'strerror', None) or e)
    if result.returncode != 0:
        return None, (result.stderr or '').strip()[:200] or 'kod %s' % result.returncode
    return result.stdout, None


def manifest_tasks(run, worktree, epic_id):
    """The number of tasks of the manifest of origin/main:docs/features/<epic>/plan.md (night_queue.task_count)."""
    def text(command):
        out, reason = _command_text(run, ['git', '-C', worktree] + command[1:])
        if out is None:
            raise MetricsError(reason)
        return out
    try:
        return night_queue.task_count(text, epic_id)
    except (MetricsError, night_queue.StateError) as e:
        raise MetricsError('liczba zadań z plan.md epiku %s nieznana (%s): podaj --tasks' % (epic_id, e))


def cmd_start(args, run, now, stdin):
    path = run_path(_expand(args.state), native_epic_id(args.epic))
    with run_lock(path):
        return _start(args, run, now, stdin, path)


def _start(args, run, now, stdin, path):
    epic_id = args.epic
    at = parse_at(args.at) if args.at else now
    worktree = _expand(args.worktree) if args.worktree else os.getcwd()
    session = checked_id(args.session or newest_session(_expand(args.projects), worktree), 'sesji')
    other = epic_of_session(_expand(args.state), session, epic_id)
    if other is not None:
        raise MetricsError('sesja %s jest już w pliku przebiegu epiku %s: jedna sesja prowadzi jeden epik, nowy epik '
                           'zaczyna nowa sesja (ten sam epik w nowej sesji: start --resume)' % (session, other))
    accepted = accepted_reason(args.accept_process_mismatch)
    current = read_run_file(path)
    entry = {'id': session, 'worktree': worktree, 'at': iso(at)}
    if args.resume:
        if current is None:
            raise MetricsError('brak pliku przebiegu %s: wznowienie wymaga wcześniejszego start' % path)
        sessions = current.get('sessions') if isinstance(current.get('sessions'), list) else []
        directory = None if args.at else check_dir(args, current, resume=True)     # the new session's worktree
        if not any(isinstance(s, dict) and s.get('id') == session for s in sessions):
            current['sessions'] = sessions + [entry]
        if not args.at:
            resume_check(run, current, directory, args, now, session, accepted)
        data = current
    else:
        if current is not None:
            raise MetricsError('plik przebiegu %s już istnieje: nowa sesja to start --resume' % path)
        branch = args.branch
        if branch is None:
            out, reason = _command_text(run, ['git', '-C', worktree, 'rev-parse', '--abbrev-ref', 'HEAD'])
            branch = (out or '').strip()
            if not branch or branch == 'HEAD':
                raise MetricsError('gałąź epiku nieznana (%s): podaj --branch' % (reason or 'odłączony HEAD'))
        tasks = args.tasks if args.tasks is not None else manifest_tasks(run, worktree, epic_id)
        pr = args.pr
        if pr is None:
            try:
                found = gh_json(run, ['pr', 'view', branch, '--json', 'number'], args.repo)
                pr = found['number'] if isinstance(found, dict) and _is_int(found.get('number')) else None
            except GhError as e:
                raise MetricsError('numer PR gałęzi %s nieznany (%s): podaj --pr' % (branch, e))
            if pr is None:
                raise MetricsError('numer PR gałęzi %s nieznany: podaj --pr' % branch)
        base = (['rev-list', '-1', '--first-parent', '--before=%s' % iso(at), 'origin/main'] if args.at
                else ['rev-parse', 'origin/main'])
        out, reason = _command_text(run, ['git', '-C', worktree] + base)
        sha = (out or '').strip()
        if not SHA_PATTERN.match(sha):
            print('metrics: SHA origin/main nieznany (%s): base_sha null' % (reason or 'pusta odpowiedź'),
                  file=sys.stderr)
            sha = None
        process = start_process(run, args, worktree, session, sha, branch, now, accepted)
        data = {'epic': epic_id, 'started_at': iso(at), 'base_sha': sha, 'branch': branch, 'pr': pr, 'tasks': tasks,
                'sessions': [entry], 'usage_start': read_usage(stdin), 'done_at': None, 'usage_done': None,
                'process': process}
    write_record(path, data)
    print(json.dumps(data, ensure_ascii=False))
    return 0


def cmd_done(args, run, now, stdin):
    path = run_path(_expand(args.state), native_epic_id(args.epic))
    with run_lock(path):
        return _done(args, run, now, stdin, path)


def _done(args, run, now, stdin, path):
    data = read_run_file(path)
    if data is None:
        raise MetricsError('brak pliku przebiegu %s: najpierw metrics.py start' % path)
    if not args.at:
        worktree = check_dir(args, data)
        try:
            result = recheck(run, data, worktree, args, now, 'done', None, None)
            if result['mismatch']:
                print('metrics: wersja procesu niezgodna z bazą epiku (zapisano w process.changes): %s'
                      % '; '.join(result['lines']), file=sys.stderr)
        except process_version.CheckError as e:
            if not isinstance(data.get('process'), dict):
                data['process'] = {'status': 'check_failed', 'checked_at': iso(now), 'checked_on': 'done',
                                   'reason': str(e), 'accepted': None, 'changes': []}
            else:
                add_change(data, failed_change(now, 'done', None, e, None))
            print('metrics: kontrola wersji procesu nieudana (%s): zapisano w process.changes' % e, file=sys.stderr)
    data['done_at'] = iso(parse_at(args.at) if args.at else now)
    data['usage_done'] = read_usage(stdin)
    write_record(path, data)
    print(json.dumps(data, ensure_ascii=False))
    return 0


def cmd_pause(args):
    path = run_path(_expand(args.state), native_epic_id(args.epic))
    begin, end = parse_at(args.begin, '--from'), parse_at(args.end, '--to')
    if begin >= end:
        raise MetricsError('--from %s nie jest przed --to %s: przerwa musi mieć dodatnią długość' % (args.begin, args.end))
    with run_lock(path):
        return _pause(args, path, begin, end)


def pause_over_work(run_file, projects, begin, end):
    """The seconds of work from the transcripts of the epic's sessions that the pause (begin, end) covers (D-m), None
    when no transcript can be read."""
    gaps, marks = Gaps(), []
    for kind, _, _, transcript, _ in native_transcripts(projects, _sessions(run_file), gaps):
        data, _ = read_native(transcript, set())
        if data is not None:
            marks.append(data['marks'])
    if not marks:
        return None
    return round(total(clip_merge(work_intervals(marks), begin, end)), 3)


def _pause(args, path, begin, end):
    data = read_run_file(path)
    if data is None:
        raise MetricsError('brak pliku przebiegu %s: najpierw metrics.py start' % path)
    pauses = data.get('pauses') if isinstance(data.get('pauses'), list) else []
    reason = ' '.join(CONTROL.sub(' ', args.reason).split()) if args.reason else ''
    data['pauses'] = pauses + [{'from': iso(begin), 'to': iso(end), 'reason': reason or None}]
    covered = pause_over_work(data, _expand(args.projects), begin, end)
    if covered:
        print('metrics: przerwa %s–%s przykrywa %g s pracy z transkryptów epiku: zapisana (przerwa ma pierwszeństwo); '
              'sprawdź czasy, jeśli komputer wtedy pracował' % (iso(begin), iso(end), covered), file=sys.stderr)
    write_record(path, data)
    print(json.dumps(data, ensure_ascii=False))
    return 0


# ------------------------------------------------------------------------------ native epics: the process version

CONTROL = re.compile(r'[\x00-\x1f\x7f-\x9f\u2028\u2029]')
PROCESS_HINT = ('sprawdź `metrics.py process`, zaktualizuj główny checkout według ADR (docs/adr/agentic-software-house.md, '
                '„Wersja procesu”) albo podaj --accept-process-mismatch "<powód>"')
NOT_CHECKED = 'zapis po fakcie (--at): dzisiejszy stan plików nie mówi nic o wersji z tamtej chwili'


def accepted_reason(value):
    """The reason of --accept-process-mismatch (control characters out, whitespace collapsed), None without the option;
    a blank reason is an error."""
    if value is None:
        return None
    text = ' '.join(CONTROL.sub(' ', value).split())
    if not text:
        raise MetricsError('pusty powód --accept-process-mismatch: podaj, dlaczego epik działa mimo różnicy wersji')
    return text


def epic_of_session(state, session, epic_id):
    """The epic of another run file that already has the session (E1), or None."""
    for name in sorted(glob.glob(os.path.join(glob.escape(os.path.join(state, 'runs')), '*.json'))):
        other = os.path.basename(name)[:-len('.json')]
        if other == epic_id:
            continue
        try:
            data = read_run_file(name)
        except MetricsError:
            continue
        sessions = data.get('sessions') if isinstance(data, dict) and isinstance(data.get('sessions'), list) else []
        if any(isinstance(s, dict) and s.get('id') == session for s in sessions):
            return other
    return None


def _sessions(run_file):
    return [s['id'] for s in run_file.get('sessions') or [] if isinstance(s, dict) and isinstance(s.get('id'), str)
            and SAFE_ID.match(s['id'])]


def _roots(run_file, internal=None, process=None):
    """The directories a CLAUDE.md path of a transcript is relative to: the worktrees of the sessions and the places."""
    roots = [s.get('worktree') for s in run_file.get('sessions') or [] if isinstance(s, dict)]
    if internal is not None:
        roots += [internal['top'], internal['main']]
    for place in (process or {}).get('disk') or []:
        if isinstance(place, dict):
            roots.append(place.get('path'))
    return [r for r in roots if isinstance(r, str) and r]


def _expected(run, top, expected):
    """The texts of the expected instruction files (the blobs of `expected.files`) read from the repository of `top`;
    an unknown base commit makes every text unavailable (`error`)."""
    expected = expected if isinstance(expected, dict) else {}
    files = expected.get('files') if isinstance(expected.get('files'), dict) else {}
    return process_version.Expected(run, top, expected.get('ref'), files)


def _session_worktrees(run_file):
    return [s['worktree'] for s in run_file.get('sessions') or [] if isinstance(s, dict)
            and isinstance(s.get('worktree'), str) and s['worktree']]


def _epic_versions(run, top, expected, heads, worktrees):
    """The versions of the files the epic made (process_version.EpicVersions) against the base of `expected`; None
    without a runner."""
    if run is None:
        return None
    return process_version.EpicVersions(run, top, expected.ref if expected.error is None else None, heads, worktrees)


def _item_name(entry):
    if entry.get('agent_id') and entry.get('role'):
        return '%s (%s)' % (entry['agent_id'], entry['role'])
    return entry.get('skill') or entry.get('path') or entry.get('origin') or '?'


def _mismatch_lines(disk, users, loaded):
    lines = process_version.summary(disk)
    if users.get('skills'):
        lines.append('skille użytkownika przesłaniają skille projektu: %s' % ', '.join(users['skills']))
    for section, entry in process_version.loaded_items(loaded):
        if entry.get('status') == 'differs':
            lines.append('załadowana treść inna niż w bazie: %s %s' % (section, _item_name(entry)))
    return lines


def _branch_of(run_file):
    branch = run_file.get('branch') if isinstance(run_file, dict) else None
    return branch if isinstance(branch, str) and branch else None


def _epic_heads(branch, record=False):
    """The refs of the epic branch: in a check the local branch first, in the record origin/<branch> first."""
    if not branch:
        return []
    return ['origin/%s' % branch, branch] if record else [branch, 'origin/%s' % branch]


def checked_process(data, loaded, now, event):
    differs = any(e.get('status') == 'differs' for e in loaded['session'])
    return {'status': 'checked', 'checked_at': iso(now), 'checked_on': event, 'expected': data['expected'],
            'disk': data['disk'], 'user_level': data['user_level'], 'loaded': dict(loaded, checked_at=iso(now)),
            'match': data['match'] and not differs, 'accepted': None, 'changes': []}


def start_process(run, args, worktree, session, base_sha, branch, now, accepted):
    """The field `process` of a new run file (W2): the reading of the start, or a refusal (MetricsError) on a mismatch
    (the rule of `mismatch_items`: a commit of the epic branch in the worktree is information) that is not accepted
    with a reason; the acceptance of the start covers what the start shows."""
    if args.at:
        return {'status': 'not_checked', 'reason': NOT_CHECKED}
    try:
        data, internal = process_version.reading(run, worktree, base_sha, _expand(args.user_dir), branch)
        expected = _expected(run, internal['top'], data['expected'])
        own = [internal['top']]
        loaded = process_version.loaded_of(_expand(args.projects), [session], expected,
                                           [internal['top'], internal['main']], agents=False, window=(now, None),
                                           parse_time=_epoch_of,
                                           epic=_epic_versions(run, internal['top'], expected, _epic_heads(branch),
                                                               own), own=own)
    except process_version.CheckError as e:
        if accepted is None:
            raise MetricsError('kontrola wersji procesu nieudana: %s; %s' % (e, PROCESS_HINT))
        return {'status': 'check_failed', 'checked_at': iso(now), 'checked_on': 'start', 'reason': str(e),
                'accepted': accepted, 'changes': []}
    process = checked_process(data, loaded, now, 'start')
    # at the start only the CLAUDE.md of the session counts of `loaded` (the agents and skills have not run yet)
    items = mismatch_items(data, {'session': loaded['session']}, process)
    process['match'] = not items
    if items:
        if accepted is None:
            raise MetricsError('wersja procesu różni się od bazy epiku %s (%s): %s. Epik nie startuje: %s' % (
                data['expected']['describe'] or '-', data['expected']['ref'][:12],
                '; '.join(i['line'] for i in items), PROCESS_HINT))
        process['accepted'] = accepted
        process['changes'].append(_acceptance(now, 'start', session, items, accepted))
    return process


def _fingerprints(places):
    return {p.get('kind'): p.get('fingerprint') for p in places or [] if isinstance(p, dict)}


def _place_state(place):
    return place.get('state') or place.get('fingerprint')


def _state_key(places, users):
    """The state on disk the changes are recorded by: the state and branch of every place and the user skills."""
    return [sorted([p.get('kind') or '', _place_state(p) or '', p.get('branch') or ''] for p in places or []
                   if isinstance(p, dict)), sorted(_dict(users).get('skills') or [])]


def _last_state(process):
    """The state of the last record: the reading of the start, then the last disk change."""
    key = _state_key(process.get('disk'), process.get('user_level'))
    for change in process.get('changes') or []:
        if isinstance(change, dict) and change.get('kind') == 'disk':
            key = _state_key(change.get('disk'), change.get('user_level'))
    return key


def _accepted_keys(process):
    """The keys of the mismatches accepted with a reason (the `accepted` entries of `process.changes`)."""
    return [key for change in process.get('changes') or [] if isinstance(change, dict)
            and change.get('kind') == 'accepted' for key in change.get('keys') or []]


def _acceptance(now, event, session, items, reason):
    return {'at': iso(now), 'event': event, 'session': session, 'kind': 'accepted', 'keys': [i['key'] for i in items],
            'items': [i['line'] for i in items], 'reason': reason}


def _explained(entry, places, accepted):
    """D-i: a subagent whose loaded role is the role file of the main checkout in a state accepted with a reason (the
    same text, compared by its digest after the same frontmatter strip as a confirmation)."""
    main = next((p for p in places if p.get('kind') == 'main_checkout'), None)
    role = entry.get('role')
    if main is None or not role or not entry.get('digest') or not main.get('path'):
        return False
    if ['disk', 'main_checkout', _place_state(main), main.get('branch')] not in accepted:
        return False
    try:
        with open(os.path.join(main['path'], '.claude', 'agents', '%s.md' % role), encoding='utf-8') as f:
            text = f.read()
    except (OSError, UnicodeDecodeError):
        return False
    return process_version.digest(process_version.strip_frontmatter(text)) == entry['digest']


def mismatch_items(data, loaded, process):
    """[{key, line}] of the mismatches of a reading not accepted yet (D-a, D-e, D-g): always against the base; a place
    with a problem (process_version.place_problems) by its state, a user skill shadowing a project skill, every
    `differs` of `loaded` (a subagent explained by D-i is marked `explained` and is none)."""
    accepted = _accepted_keys(process)
    items = []
    for place in data['disk']:
        if process_version.place_problems(place):
            key = ['disk', place['kind'], _place_state(place), place.get('branch')]
            if key not in accepted:
                items.append({'key': key, 'line': '; '.join(process_version.summary([place]))})
    skills = sorted(data['user_level'].get('skills') or [])
    if skills and ['user'] + skills not in accepted:
        items.append({'key': ['user'] + skills,
                      'line': 'skille użytkownika przesłaniają skille projektu: %s' % ', '.join(skills)})
    for section, entry in process_version.loaded_items(loaded):
        if entry.get('status') != 'differs':
            continue
        if section == 'agents' and _explained(entry, data['disk'], accepted):
            entry['explained'] = 'accepted_disk'
            continue
        key = ['loaded'] + process_version.loaded_key(section, entry)
        if key not in accepted:
            items.append({'key': key, 'line': 'załadowana treść inna niż w bazie: %s %s%s' % (
                section, _item_name(entry), ' (%s)' % entry['reason'] if entry.get('reason') else '')})
    return items


def _change_key(change):
    kind = change.get('kind')
    if kind == 'disk':
        return [kind, _state_key(change.get('disk'), change.get('user_level'))]
    if kind == 'loaded':
        return [kind] + process_version.loaded_key(change.get('section'), change.get('item') or {})
    if kind == 'accepted':
        return [kind, change.get('at'), change.get('keys')]
    return [kind, change.get('event'), change.get('reason')]


def add_change(run_file, change):
    """Appends `change` to `process.changes` unless the same change is there."""
    process = run_file.get('process')
    if not isinstance(process, dict):
        return False
    changes = process.setdefault('changes', [])
    key = _change_key(change)
    if any(isinstance(old, dict) and _change_key(old) == key for old in changes):
        return False
    changes.append(change)
    return True


def failed_change(now, event, session, error, accepted):
    return {'at': iso(now), 'event': event, 'session': session, 'kind': 'check_failed', 'reason': str(error),
            'accepted': accepted}


def _epoch_of(text):
    return night_queue.parse_time(text).timestamp()


def _disk_of_change(places):
    keep = ('kind', 'fingerprint', 'state', 'branch', 'differs', 'uncommitted', 'epic_branch', 'from_base',
            'on_epic_branch', 'expected_branch', 'origin_known', 'from_main')
    return [{k: p[k] for k in keep if k in p} for p in places]


def recheck(run, run_file, worktree, args, now, event, session, accepted):
    """The check of a running epic (W3) on the data of the run file, shared by `process EPIC`, `start --resume` and
    `done`: today's disk and the transcripts of the sessions (`loaded`) against the base, by `mismatch_items`. A new
    state on disk is one `disk` entry of `process.changes` (`informational` with the epic's own commits and no
    mismatch, `returned` without either: back to the base or to an accepted state), a `differs` one `loaded` entry.
    The mismatches are stored as `shown`; with `accepted` only the shown ones that are still there are accepted (D-h),
    the others stay a mismatch and become the new `shown`. A run file without a reading (older, or a failed check at
    the start) gets one now (`checked_on`), which accepts nothing. Mutates `run_file`; raises
    process_version.CheckError when the check cannot run. Returns {status, late, disk, user_level, loaded, added,
    mismatch, accepted, unshown, lines}."""
    process = run_file.get('process')
    if isinstance(process, dict) and process.get('status') == 'not_checked':
        return {'status': 'not_checked', 'late': False, 'added': [], 'mismatch': False, 'lines': [],
                'reason': process.get('reason')}
    projects, sessions, branch = _expand(args.projects), _sessions(run_file), _branch_of(run_file)
    started = _run_time(run_file, 'started_at', Gaps(), '')
    late = not isinstance(process, dict) or process.get('status') != 'checked'
    ref = run_file.get('base_sha') if late else (process.get('expected') or {}).get('ref')
    ref = ref if isinstance(ref, str) and ref else None
    try:
        data, internal = process_version.reading(run, worktree, ref, _expand(args.user_dir), branch)
    except process_version.CheckError:
        if not late or ref is None:
            raise
        data, internal = process_version.reading(run, worktree, None, _expand(args.user_dir), branch)
    expected = _expected(run, internal['top'], data['expected'] if late else process['expected'])
    own = _session_worktrees(run_file) + [internal['top']]
    loaded = process_version.loaded_of(projects, sessions, expected, _roots(run_file, internal, process),
                                       window=(started, None), parse_time=_epoch_of,
                                       epic=_epic_versions(run, internal['top'], expected, _epic_heads(branch), own),
                                       own=own)
    if late:
        new = checked_process(data, loaded, now, event)
        if isinstance(process, dict) and process.get('status') == 'check_failed':
            new.update(start_failure={'reason': process.get('reason'), 'accepted': process.get('accepted')},
                       changes=process.get('changes') or [])
        run_file['process'] = process = new
    items = mismatch_items(data, loaded, process)
    added = []
    if _state_key(data['disk'], data['user_level']) != _last_state(process):
        disk_items = [i for i in items if i['key'][0] in ('disk', 'user')]
        epic_own = [d for p in data['disk'] for d in process_version.own_changes(p)]
        change = {'at': iso(now), 'event': event, 'session': session, 'kind': 'disk',
                  'informational': bool(epic_own) and not disk_items, 'returned': not epic_own and not disk_items,
                  'disk': _disk_of_change(data['disk']), 'user_level': data['user_level']}
        process.setdefault('changes', []).append(change)          # a new state since the last record
        added.append(change)
    for section, entry in process_version.loaded_items(loaded):
        if entry.get('status') == 'differs':
            change = {'at': iso(now), 'event': event, 'session': session, 'kind': 'loaded', 'section': section,
                      'item': entry}
            if add_change(run_file, change):
                added.append(change)
    remaining, accepted_now = items, []
    shown = _dict(process.get('shown')).get('keys') or []
    if accepted is not None and items:
        accepted_now = [i for i in items if i['key'] in shown]
        remaining = [i for i in items if i['key'] not in shown]
        if accepted_now:
            change = _acceptance(now, event, session, accepted_now, accepted)
            process.setdefault('changes', []).append(change)
            added.append(change)
    if remaining:
        process['shown'] = {'at': iso(now), 'event': event, 'keys': [i['key'] for i in remaining]}
    else:
        process.pop('shown', None)
    process['loaded'] = dict(loaded, checked_at=iso(now))
    process['rechecked_at'] = iso(now)
    return {'status': 'checked', 'late': late, 'disk': data['disk'], 'user_level': data['user_level'],
            'loaded': process['loaded'], 'added': added, 'mismatch': bool(remaining), 'accepted': bool(accepted_now),
            'unshown': accepted is not None and bool(remaining), 'lines': [i['line'] for i in remaining]}


def check_dir(args, run_file, resume=False):
    """The directory of the check of a running epic: --worktree; for `start --resume` the worktree of the new session
    (the current directory, D-k); else the worktree of the last session of the run file (said on stderr when the
    current directory is another checkout, D-d), and when that worktree is gone the current directory, also said."""
    if args.worktree:
        return _expand(args.worktree)
    cwd = os.getcwd()
    if resume:
        return cwd
    stored = _session_worktrees(run_file)
    stored = stored[-1] if stored else None
    if stored is None:
        return cwd
    if not os.path.isdir(stored):
        print('metrics: worktree ostatniej sesji epiku %s już nie istnieje: kontrola wersji w bieżącym katalogu %s; '
              'inny katalog: --worktree' % (stored, cwd), file=sys.stderr)
        return cwd
    here, there = os.path.realpath(cwd), os.path.realpath(stored)
    if here != there and not here.startswith(there.rstrip('/') + '/'):
        print('metrics: kontrola wersji w worktree ostatniej sesji epiku %s (z pliku przebiegu), nie w bieżącym '
              'katalogu %s; inny katalog: --worktree' % (stored, cwd), file=sys.stderr)
    return stored


MISMATCH_HINT = ('co zrobić: usuń przyczynę (zaktualizuj główny checkout według ADR, docs/adr/agentic-software-house.md, '
                 '„Wersja procesu”; zacommituj albo cofnij zmianę w worktree epiku) albo zaakceptuj różnicę z powodem: '
                 'metrics.py process %s --accept-process-mismatch "<powód>"')
UNSHOWN = ('nie zaakceptowano niezgodności, których nie pokazała poprzednia kontrola (zapisane teraz jako pokazane): '
           'sprawdź je i powtórz z --accept-process-mismatch "<powód>"')


def resume_check(run, run_file, worktree, args, now, session, accepted):
    """`start --resume`: the recheck of the worktree of the new session; a mismatch refuses the resume (MetricsError,
    nothing written) unless it was shown by `process EPIC` and is accepted with a reason. The first reading of a run
    file without one refuses nothing."""
    try:
        result = recheck(run, run_file, worktree, args, now, 'resume', session, accepted)
    except process_version.CheckError as e:
        if accepted is None:
            raise MetricsError('kontrola wersji procesu nieudana: %s; %s' % (e, PROCESS_HINT))
        add_change(run_file, failed_change(now, 'resume', session, e, accepted))
        return
    if result['mismatch'] and not result['late']:
        epic = run_file.get('epic')
        raise MetricsError('wersja procesu niezgodna z bazą epiku: %s. Wznowienie wstrzymane: %s; %s' % (
            '; '.join(result['lines']), 'akceptacja obejmuje tylko niezgodności pokazane przez `metrics.py process %s`'
            % epic if result['unshown'] else 'wznów z --accept-process-mismatch "<powód>" po `metrics.py process %s`'
            % epic, MISMATCH_HINT % epic))


LOCK_TIMEOUT_S = 60.0


def lock_path_of(path):
    """The lock of the run file <state>/runs/<epik>.json: <state>/locks/<epik>.lock (outside `runs`)."""
    state = os.path.dirname(os.path.dirname(path))
    return os.path.join(state, 'locks', os.path.basename(path)[:-len('.json')] + '.lock')


@contextlib.contextmanager
def run_lock(path):
    """The exclusive lock of the run file `path` (`<epik>.lock` next to it) for the whole read, change and write of a
    command (D-l); MetricsError after LOCK_TIMEOUT_S seconds of waiting."""
    lock_path = lock_path_of(path)
    try:
        os.makedirs(os.path.dirname(lock_path), exist_ok=True)
        handle = open(lock_path, 'a')
    except OSError as e:
        raise MetricsError('nie można założyć blokady %s: %s' % (lock_path, e.strerror or e))
    deadline = time.monotonic() + LOCK_TIMEOUT_S
    try:
        while True:
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except OSError:
                if time.monotonic() >= deadline:
                    raise MetricsError('plik przebiegu %s ma blokadę innego wywołania metrics.py dłużej niż %g s: '
                                       'poczekaj, aż się skończy, i spróbuj ponownie' % (path, LOCK_TIMEOUT_S))
                time.sleep(0.05)
        yield
    finally:
        handle.close()


def _without_times(run_file):
    """The run file without the times of a recheck: equal when the recheck changed nothing."""
    data = json.loads(json.dumps(run_file))
    process = data.get('process') if isinstance(data.get('process'), dict) else {}
    process.pop('rechecked_at', None)
    if isinstance(process.get('loaded'), dict):
        process['loaded'].pop('checked_at', None)
    if isinstance(process.get('shown'), dict):
        process['shown'].pop('at', None)
    return data


def active_epics(state, main_fingerprint):
    """The epics of the run files without a merged record: {epic, started_at, done_at, has_record, note, fingerprint
    (of the main checkout at the start; None without a reading), current (equal to today's; None when unknown)}; the
    note tells an epic in progress from a ready PR without a record."""
    found = []
    for name in sorted(glob.glob(os.path.join(glob.escape(os.path.join(state, 'runs')), '*.json'))):
        epic = os.path.basename(name)[:-len('.json')]
        record = read_previous(state_path(state, epic))
        if isinstance(record, dict) and record.get('merged_at'):
            continue
        try:
            data = read_run_file(name) or {}
        except MetricsError:
            data = {}
        process = data.get('process') if isinstance(data.get('process'), dict) else {}
        fingerprint = _fingerprints(process.get('disk')).get('main_checkout') if process.get('status') == 'checked' \
            else None
        done = data.get('done_at')
        note = ('w toku' if not done else 'PR gotowy, rekord bez merge\'u (uruchom metrics.py epic %s po merge\'u)'
                % epic if isinstance(record, dict) else 'PR gotowy, brak rekordu (uruchom metrics.py epic %s)' % epic)
        found.append({'epic': epic, 'started_at': data.get('started_at'), 'done_at': done,
                      'has_record': isinstance(record, dict), 'note': note, 'fingerprint': fingerprint,
                      'current': None if fingerprint is None or main_fingerprint is None
                      else fingerprint == main_fingerprint})
    return found


def _files_count(places):
    """The number of the files that are a mismatch in the places (process_version.mismatch_paths)."""
    return sum(len(process_version.mismatch_paths(p)) for p in places or [] if isinstance(p, dict))


def _kinds(places):
    return ', '.join(p.get('kind') or '?' for p in places or [] if isinstance(p, dict)
                     and process_version.place_problems(p)) or '-'


def _epic_branch_paths(places):
    """The files the epic's own commits changed in the worktree (information, not a mismatch)."""
    return sorted({d.get('path') for p in places or [] if isinstance(p, dict)
                   for d in process_version.own_changes(p) if isinstance(d, dict) and d.get('path')})


def informational_changes(process):
    """The disk changes of the run file that are information (the epic's own commits), plus the reading of the start
    when its worktree already had such commits."""
    found = [c for c in _list(process.get('changes')) if isinstance(c, dict) and c.get('kind') == 'disk'
             and c.get('informational') is True]
    return found + ([process] if _epic_branch_paths(process.get('disk')) else [])


def checked_gaps(process, gaps):
    """The `gaps` of a checked reading: a reading not from the start, a start accepted despite a difference, every
    change and acceptance (a commit of the epic branch and a return to an accepted state as information, clearly
    apart from a mismatch)."""
    late = process.get('checked_on') != 'start'
    if late:
        gaps.add('process', 'odczyt wersji z %s, nie ze startu epiku' % process.get('checked_on'))
    failure = process.get('start_failure')
    if failure:
        failure = _dict(failure) if isinstance(failure, dict) else {'reason': failure}
        gaps.add('process', 'kontrola wersji przy starcie nieudana: %s; start zaakceptowany: %s'
                 % (failure.get('reason'), failure.get('accepted') or 'brak akceptacji'))
    if process.get('match') is False:
        details = (_files_count(process.get('disk')), _kinds(process.get('disk')),
                   ', '.join(_dict(process.get('user_level')).get('skills') or []) or 'brak')
        if late:
            gaps.add('process', 'start bez kontroli; różnica definicji wykryta przy %s (%d plików: %s; skille '
                                'użytkownika: %s), bez akceptacji przy tym odczycie'
                     % ((process.get('checked_on'),) + details))
        else:
            gaps.add('process', 'start mimo różnicy definicji (%d plików: %s; skille użytkownika: %s); powód: %s'
                     % (details + (process.get('accepted') or 'brak akceptacji',)))
    paths = _epic_branch_paths(process.get('disk'))
    if paths:
        gaps.add('process', 'informacja (nie niezgodność): pliki zmienione w gałęzi epiku przy starcie: %s'
                 % ', '.join(paths))
    for change in process.get('changes') or []:
        if not isinstance(change, dict):
            continue
        if change.get('kind') == 'disk' and change.get('informational') is True:
            gaps.add('process', 'informacja (nie niezgodność): pliki zmienione w gałęzi epiku przy %s %s: %s' % (
                change.get('event'), change.get('at'), ', '.join(_epic_branch_paths(change.get('disk'))) or '-'))
        elif change.get('kind') == 'disk' and change.get('returned') is True:
            gaps.add('process', 'powrót do stanu bazy albo zaakceptowanego przy %s %s (nie niezgodność)'
                     % (change.get('event'), change.get('at')))
        elif change.get('kind') == 'disk':
            gaps.add('process', 'zmiana definicji na dysku przy %s %s (%d plików: %s; skille użytkownika: %s)' % (
                change.get('event'), change.get('at'), _files_count(change.get('disk')), _kinds(change.get('disk')),
                ', '.join(_dict(change.get('user_level')).get('skills') or []) or 'brak'))
        elif change.get('kind') == 'accepted' and change.get('event') != 'start':
            gaps.add('process', 'zaakceptowano przy %s %s %d niezgodności: %s' % (
                change.get('event'), change.get('at'), len(change.get('keys') or []), change.get('reason')))
        elif change.get('kind') == 'check_failed':
            accepted = '; zaakceptowano: %s' % change['accepted'] if change.get('accepted') else ''
            gaps.add('process', 'kontrola wersji przy %s %s nieudana: %s%s' % (change.get('event'), change.get('at'),
                                                                             change.get('reason'), accepted))


def native_process(run_file, run, worktree, projects, started, until, attempts, now, gaps):
    """W4: the field `process` of the native record: the one of the run file (the reading of the start and the
    changes), its `loaded` computed again from every transcript of the epic (the sessions and the subagent runs from the
    start to the merge) with `summary` and `by_role`, and the status of each subagent run in `attempts[].instructions`.
    `gaps` (key `process`): no reading, not checked, a failed check, a start accepted despite a difference, every change
    on disk, every `differs` and one entry with the number of `unconfirmed`. A matching reading adds no entry."""
    process = run_file.get('process')
    original = json.loads(json.dumps(process)) if isinstance(process, dict) else None
    status = original.get('status') if original is not None else None
    if original is None or status in ('not_checked', 'check_failed'):
        process = unchecked_process(original, run_file, run, worktree, gaps)
        if process is None or not process.get('expected'):
            return process
    elif status != 'checked':
        gaps.add('process', 'nieznany stan odczytu wersji procesu')
        return original
    else:
        process = original
        process['disk_checked'] = True
        checked_gaps(process, gaps)
    return with_loaded(process, run_file, run, worktree, projects, started, until, attempts, now, gaps)


DISK_NOT_CHECKED = {None: 'start sprzed kontroli wersji (plik przebiegu bez pola process)'}


def unchecked_process(original, run_file, run, worktree, gaps):
    """The `process` of the record for a run file without a disk check (no field, `not_checked`, `check_failed`):
    with a `base_sha` the repository of `worktree` knows, `expected` from it now, `disk: null`, `disk_checked: false`
    and the reason (`loaded` follows); else the field of the run file as it is (None without it) with its gap."""
    status = original.get('status') if original is not None else None
    if status is None:
        reason = DISK_NOT_CHECKED[None]
    elif status == 'not_checked':
        reason = original.get('reason') or 'zapis po fakcie (--at)'
    else:
        reason = 'kontrola wersji przy starcie nieudana: %s; start zaakceptowany: %s' % (
            original.get('reason'), original.get('accepted') or 'brak akceptacji')
    base = run_file.get('base_sha')
    expected = None
    if run is not None and isinstance(base, str) and SHA_PATTERN.match(base):
        try:
            expected, _ = process_version.expected_of(run, worktree or os.getcwd(), base)
        except process_version.CheckError:
            expected = None
    if expected is None:
        if original is None:
            gaps.add('process', 'brak odczytu wersji procesu w pliku przebiegu (%s); base_sha brak albo nieznany '
                                'w repozytorium' % reason)
        elif status == 'not_checked':
            gaps.add('process', 'nie sprawdzono: %s' % reason)
        else:
            gaps.add('process', reason)
        return original
    process = dict(original or {}, status=status or 'not_checked', expected=expected, disk=None, disk_checked=False,
                   disk_reason=reason, user_level=None, match=None)
    process.setdefault('accepted', None)
    process.setdefault('changes', [])
    gaps.add('process', 'dysk: nie sprawdzono w czasie epiku (%s); expected z base_sha %s w chwili budowy rekordu'
             % (reason, base[:12]))
    for change in process['changes']:
        if isinstance(change, dict) and change.get('kind') == 'check_failed':
            gaps.add('process', 'kontrola wersji przy %s %s nieudana: %s' % (change.get('event'), change.get('at'),
                                                                           change.get('reason')))
    return process


def with_loaded(process, run_file, run, worktree, projects, started, until, attempts, now, gaps):
    """`process.loaded` computed from every transcript of the epic against `process.expected`, with `summary`,
    `by_role`, `attempts[].instructions` and the gaps of every `differs` and of the number of `unconfirmed`; a base
    the repository of `worktree` does not have (a directory outside the repository) leaves every entry unconfirmed
    with a gap, never drops the field."""
    top = worktree or os.getcwd()
    if run is None:
        expected = process_version.Expected(lambda c: None, top, None, {})
    else:
        expected = _expected(run, top, process.get('expected'))
    if expected.error:
        gaps.add('process', 'treść bazy niedostępna (%s): loaded bez porównania, każda pozycja unconfirmed'
                 % expected.error)
    own = _session_worktrees(run_file)
    epic = _epic_versions(run, top, expected, _epic_heads(_branch_of(run_file), record=True), own)
    loaded = process_version.loaded_of(projects, _sessions(run_file), expected, _roots(run_file, None, process),
                                       window=(started, until), parse_time=_epoch_of, epic=epic, own=own)
    entries = [entry for _, entry in process_version.loaded_items(loaded)]
    by_role = {}
    for entry in loaded['agents']:
        if entry.get('role'):
            cell = by_role.setdefault(entry['role'], dict.fromkeys(process_version.STATUSES, 0))
            cell[entry['status']] += 1
    loaded.update(checked_at=iso(now), summary=process_version.summary_of(entries), by_role=by_role)
    process['loaded'] = loaded
    statuses = {e['agent_id']: e['status'] for e in loaded['agents'] if e.get('agent_id')}
    for attempt in attempts:
        attempt['instructions'] = statuses.get(attempt.get('agent_id'))
    for section, entry in process_version.loaded_items(loaded):
        if entry.get('status') == 'differs':
            gaps.add('process', 'załadowana wersja inna niż w bazie: %s %s%s%s' % (
                section, _item_name(entry), ' (%s)' % entry['origin'] if entry.get('origin') else '',
                ': %s' % entry['reason'] if entry.get('reason') else ''))
    unconfirmed = loaded['summary']['unconfirmed']
    if unconfirmed:
        gaps.add('process', 'bez potwierdzenia w transkryptach (unconfirmed): %d pozycji, nie liczą się jako zgodne'
                 % unconfirmed)
    return process


def cmd_process(args, run, now):
    if args.epic is None:
        if args.accept_process_mismatch is not None:
            raise MetricsError('--accept-process-mismatch działa tylko z epikiem: metrics.py process EPIK')
        worktree = _expand(args.worktree) if args.worktree else os.getcwd()
        try:
            data, _ = process_version.reading(run, worktree, args.base, _expand(args.user_dir))
        except process_version.CheckError as e:
            raise MetricsError('kontrola wersji procesu: %s' % e)
        data['active_epics'] = active_epics(_expand(args.state), _fingerprints(data['disk']).get('main_checkout'))
        print(json.dumps(data, ensure_ascii=False))
        if not data['match']:
            print('metrics: definicje różnią się od %s (%s): %s' % (
                data['expected']['requested'], data['expected']['ref'][:12],
                '; '.join(_mismatch_lines(data['disk'], data['user_level'], {}))), file=sys.stderr)
            return EXIT_PROCESS
        return 0
    epic_id = native_epic_id(args.epic)
    accepted = accepted_reason(args.accept_process_mismatch)
    path = run_path(_expand(args.state), epic_id)
    with run_lock(path):
        run_file = read_run_file(path)
        if run_file is None:
            raise MetricsError('brak pliku przebiegu %s: najpierw metrics.py start' % path)
        sessions = _sessions(run_file)
        if args.session:
            session = checked_id(args.session, 'sesji')
            if session not in sessions:
                raise MetricsError('sesja %s nie należy do epiku %s (sesje pliku przebiegu: %s)'
                                   % (session, epic_id, ', '.join(sessions) or 'brak'))
        else:
            session = sessions[-1] if sessions else None
        before = _without_times(run_file)
        try:
            result = recheck(run, run_file, check_dir(args, run_file), args, now, 'process', session, accepted)
        except process_version.CheckError as e:
            raise MetricsError('kontrola wersji procesu: %s' % e)
        if result['status'] == 'checked' and _without_times(run_file) != before:
            write_record(path, run_file)
    process = run_file.get('process') or {}
    output = {'epic': epic_id, 'status': result['status'], 'late': result['late'],
              'expected': process.get('expected'), 'disk': result.get('disk'),
              'user_level': result.get('user_level'), 'loaded': result.get('loaded'), 'added': result['added'],
              'mismatches': result['lines'], 'accepted': accepted if result.get('accepted') else None,
              'match': not result['mismatch']}
    if result['status'] == 'not_checked':
        output['reason'] = result.get('reason')
    print(json.dumps(output, ensure_ascii=False))
    if result['mismatch']:
        print('metrics: wersja procesu epiku %s niezgodna z bazą: %s; %s%s' % (
            epic_id, '; '.join(result['lines']), MISMATCH_HINT % epic_id,
            '; ' + UNSHOWN if result.get('unshown') else ''), file=sys.stderr)
        return EXIT_PROCESS
    return 0


# ------------------------------------------------------------------------------ native epics: transcripts and value

PRICE_OF_TOKEN = {'input': 'input', 'output': 'output', 'cache_read': 'cache_read', 'cw5': 'cache_write_5m',
                  'cw1': 'cache_write_1h'}
AGENT_FILE = re.compile(r'^agent-(.+)\.jsonl$')
AGENT_META = re.compile(r'^agent-(.+)\.meta\.json$')


HANDBACK = 'SubagentHandback'               # the tool call with which a subagent hands its report back (M4)
AGENT_CALL = 'Agent'                        # the tool call with which the lead session starts a subagent
VERDICT_TOKENS = ('CHANGES_REQUIRED', 'INVESTIGATE', 'SIMPLIFY', 'REWORK', 'BLOCK', 'PASS', 'KEEP')
_VERDICT_WORDS = '|'.join(VERDICT_TOKENS)
_VERDICT_LABEL = r'(?i:werdykt|verdict)'
_VERDICT_GAP = r'[\s:*_#.()`\-\u2013\u2014]*(?:(?i:to|jest|is)(?![A-Za-z])[\s:*_#.()`\-\u2013\u2014]*)?'
_VERDICT_TAIL = r'(%s)(?![A-Za-z0-9_])' % _VERDICT_WORDS
# the label at the start of a line (after list, heading, quote and bold markers) and anywhere in the text, but not
# right after a quotation mark or a backtick: a quoted label (an example in the report) is no verdict
VERDICT_LINE = re.compile(r'^[ \t>#*_\-]*%s%s%s' % (_VERDICT_LABEL, _VERDICT_GAP, _VERDICT_TAIL), re.MULTILINE)
VERDICT = re.compile(r'(?<![A-Za-z"\'`\u201e\u201c\u201d\u2018\u2019\u00ab\u00bb])%s%s%s'
                     % (_VERDICT_LABEL, _VERDICT_GAP, _VERDICT_TAIL))


def verdict_token(text):
    """The verdict word right after the label `Werdykt` or `Verdict` (only `to`, `jest`, `is`, separators and bold
    markers between them), or None; a verdict word without the label is no verdict. Reports state the verdict at the
    start, so the first label at the start of a line wins (a report may quote other verdicts later in its sentences);
    without such a label the last labelled one in the text counts (a report may quote the verdict of an earlier round
    first); a label right after a quotation mark or a backtick is a quotation, not a verdict. The text itself is never
    kept."""
    if not isinstance(text, str):
        return None
    first = VERDICT_LINE.search(text)
    if first:
        return first.group(1)
    found = VERDICT.findall(text)
    return found[-1] if found else None


def line_mark(line):
    """What a user or assistant line says about the time before it (M3 `breakdown`): `work` (an assistant message or
    a tool result: the session or the subagent was working), `human` (a message typed by a human, `origin.kind`
    `human`), `wait` (any other user line: a subagent report, a background task, a resume, a meta line, an order)."""
    if line.get('type') == 'assistant':
        return 'work'
    message = line.get('message') if isinstance(line.get('message'), dict) else {}
    content = message.get('content')
    if isinstance(content, list) and any(isinstance(b, dict) and b.get('type') == 'tool_result' for b in content):
        return 'work'
    origin = line.get('origin')
    return 'human' if isinstance(origin, dict) and origin.get('kind') == 'human' else 'wait'


def read_native(path, seen_tools):
    """(data, None) of a transcript of a native session or subagent in the shape of `read_transcript` (the messages,
    the tools; `seen_tools` is shared by the files of the epic, so a copied tool call counts once), plus `times` (the
    timestamps of the user and assistant lines), `inits` from the CLI `version` of the lines, `marks` [(at, mark,
    key)] of the user and assistant lines with a timestamp (`line_mark`; key: the `uuid` of a human line), `untimed_human`
    (human lines without a timestamp), `user_lines` and `origin_lines` (the user lines that are no tool result, and
    those of them with an `origin`: without any, a human message cannot be told apart) and `reports` [(at, verdict word
    or None)] of the `SubagentHandback` calls in file order and `launched` [(at, agent id)] of the subagents the file
    starts (`toolUseResult.agentId` of the result of an `Agent` call) and `skipped` (the lines that are no JSON object,
    blank lines aside); (None, reason) when the file is missing, unreadable, empty or has no line that is a JSON
    object."""
    data = {'messages': {}, 'inits': [], 'results': [], 'retries': [], 'denials': {}, 'tools': [], 'times': [],
            'marks': [], 'untimed_human': 0, 'user_lines': 0, 'origin_lines': 0, 'reports': [], 'launched': [],
            'skipped': 0}
    versions, reports, agent_calls, lines = set(), set(), set(), 0
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            for raw in f:
                try:
                    line = json.loads(raw)
                except ValueError:
                    line = None
                if not isinstance(line, dict):
                    data['skipped'] += bool(raw.strip())
                    continue
                lines += 1
                _read_line(line, data, seen_tools)
                if isinstance(line.get('version'), str):
                    versions.add(line['version'])
                if line.get('type') not in ('user', 'assistant'):
                    continue
                at = _timestamp(line.get('timestamp'))
                mark = line_mark(line)
                if line['type'] == 'user' and mark != 'work':
                    data['user_lines'] += 1
                    data['origin_lines'] += isinstance(line.get('origin'), dict)
                if at is not None:
                    data['times'].append(at)
                    data['marks'].append((at, mark, line.get('uuid') if isinstance(line.get('uuid'), str) else None))
                elif mark == 'human':
                    data['untimed_human'] += 1
                message = line.get('message') if isinstance(line.get('message'), dict) else {}
                launch = line.get('toolUseResult') if isinstance(line.get('toolUseResult'), dict) else {}
                for block in message.get('content') if isinstance(message.get('content'), list) else []:
                    if not isinstance(block, dict):
                        continue
                    if block.get('type') == 'tool_use' and block.get('name') == AGENT_CALL:
                        agent_calls.add(block.get('id'))
                    if block.get('type') == 'tool_result' and block.get('tool_use_id') in agent_calls \
                            and isinstance(launch.get('agentId'), str):
                        data['launched'].append((at, launch['agentId']))
                    if block.get('type') == 'tool_use' and block.get('name') == HANDBACK \
                            and block.get('id') not in reports:
                        reports.add(block.get('id'))
                        tool_input = block.get('input') if isinstance(block.get('input'), dict) else {}
                        data['reports'].append((at, verdict_token(tool_input.get('message'))))
    except FileNotFoundError:
        return None, 'brak pliku transkryptu'
    except OSError as e:
        return None, 'nieczytelny transkrypt: %s' % (e.strerror or e)
    if not lines:
        return None, ('uszkodzony transkrypt: żadna z %d linii nie jest obiektem JSON' % data['skipped']
                      if data['skipped'] else 'pusty transkrypt')
    data['inits'] = [{'version': version, 'model': None} for version in sorted(versions)]
    return data, None


def read_meta(path, label, gaps):
    """{role, description, effort, finished} of `agent-<id>.meta.json`; a missing file or field is None with a gap (the
    description is only read for the task id, never stored). The model is not read: that of a run comes from its
    messages, and `launchedWithDefaults` runs have none in the file."""
    found = {'role': None, 'description': None, 'effort': None, 'finished': None}
    try:
        with open(path, encoding='utf-8') as f:
            meta = json.load(f)
        if not isinstance(meta, dict):
            raise ValueError('not an object')
    except FileNotFoundError:
        gaps.add(label, 'brak meta.json: rola, zadanie, effort i zakończenie nieznane')
        return found
    except (OSError, ValueError):
        gaps.add(label, 'nieczytelny meta.json: rola, zadanie, effort i zakończenie nieznane')
        return found
    for field, key in (('role', 'agentType'), ('effort', 'effort')):
        if isinstance(meta.get(key), str) and SAFE_NAME.match(meta[key]):
            found[field] = meta[key]
        else:
            gaps.add(label, 'meta.json bez %s' % key)
    if isinstance(meta.get('description'), str):
        found['description'] = meta['description']
    else:
        gaps.add(label, 'meta.json bez description: zadanie nieznane')
    if isinstance(meta.get('finished'), bool):
        found['finished'] = meta['finished']
    else:
        gaps.add(label, 'meta.json bez finished: zakończenie nieznane')
    return found


def task_of(description, epic_id):
    """The task of a subagent run (D4): the first `<EPIC>-<n>` in the description of its call, else None."""
    if not isinstance(description, str):
        return None
    found = re.search(r'(?<![A-Za-z0-9])%s-(\d+)(?![0-9])' % re.escape(epic_id), description, re.IGNORECASE)
    return '%s-%s' % (epic_id.upper(), found.group(1)) if found else None


def message_value(message, pricing):
    """(USD, None) of an assistant message from the price list (every kind of tokens with its own rate), or (None,
    reason) when the model, the lifetime of the cache write or a rate of a used kind is unknown."""
    model, tokens = message['model'], message['tokens']
    if model is None:
        return None, 'wiadomość bez message.model: wartość nieznana'
    if (tokens['cw5'] or tokens['cw1']) and not message['lifetime']:
        return None, ('zapis cache bez rozbicia usage.cache_creation (czas życia 5 min albo 1 h nieznany): wartość '
                      'nieznana')
    shown = model if SAFE_NAME.match(model) else 'unknown'
    rates = pricing['models'].get(model)
    if rates is None:
        return None, 'brak stawek modelu %s w cenniku (config.json, pricing): wartość nieznana' % shown
    usd = 0.0
    for raw, kind in PRICE_OF_TOKEN.items():
        if tokens[raw]:
            if kind not in rates:
                return None, ('brak stawki %s modelu %s w cenniku (config.json, pricing): wartość nieznana'
                              % (kind, shown))
            usd += tokens[raw] * rates[kind] / 1e6
    return usd, None


def _native_cell(known):
    return {'tokens': dict(ZERO_TOKENS), 'estimated': set(), 'usd': 0.0 if known else None, 'shared': True,
            'no_cli': True}


def native_cells(messages, role, pricing, label, gaps):
    """(known, unknown, usage_missing) cells {role: {model: cell}} of the messages of one transcript: the value of a
    message from the price list (always an estimate: `shared` and `no_cli` mark every section estimated); a message
    whose value is unknown goes to `unknown` (its section is incomplete, never short by a silent zero). A message
    without tokens needs no rate."""
    known, unknown, usage_missing = {}, {}, False
    for message in messages:
        model = message['model'] if message['model'] and SAFE_NAME.match(message['model']) else 'unknown'
        if not message['usage']:
            usage_missing = True
            gaps.add('value', '%s: wiadomość bez message.usage: tokeny i wartość nieznane' % label)
            unknown.setdefault(role, {}).setdefault(model, _native_cell(False))
            continue
        if not any(message['tokens'].values()):
            continue
        usd, problem = message_value(message, pricing)
        if problem:
            gaps.add('value', '%s: %s' % (label, problem))
        cell = (unknown if problem else known).setdefault(role, {}).setdefault(model, _native_cell(not problem))
        for key in ZERO_TOKENS:
            cell['tokens'][key] += message['tokens'][key]
        if not problem:
            cell['usd'] += usd
    return known, unknown, usage_missing


def native_items(task, known, unknown):
    """The items of `value_sections` of one transcript: the known cells and the unknown ones (an incomplete part)."""
    items = [(task, known)] if known or not unknown else []
    return items + ([(task, unknown)] if unknown else [])


def native_transcripts(projects, sessions, gaps):
    """[(kind, session, agent_id, path, meta path)] of the epic: every session of the run file (its `<id>.jsonl` in
    any project directory under `projects`) and its subagents (`<id>/subagents/agent-<agent>.jsonl`, also one with
    only `agent-<agent>.meta.json`: its transcript is lost). Nothing else: another session of the same working
    directory is not part of the epic."""
    found, agents = [], set()
    for session in sessions:
        paths = sorted(glob.glob(os.path.join(glob.escape(projects), '*', '%s.jsonl' % session)))
        if not paths:
            gaps.add('sessions', 'brak transkryptu sesji %s w %s' % (session, os.path.basename(projects)))
            continue
        found.append(('session', session, None, paths[0], None))
        directory = os.path.join(os.path.dirname(paths[0]), session, 'subagents')
        try:
            names = sorted(os.listdir(directory))
        except OSError:
            names = []
        for name in names:
            match = AGENT_FILE.match(name) or AGENT_META.match(name)
            if not match:
                continue
            agent = match.group(1)
            if not SAFE_NAME.match(agent) or agent in agents:
                continue
            agents.add(agent)
            found.append(('agent', session, agent, os.path.join(directory, 'agent-%s.jsonl' % agent),
                          os.path.join(directory, 'agent-%s.meta.json' % agent)))
    return found


def in_window(at, low, high):
    return at is None or ((low is None or at >= low) and (high is None or at <= high))


def native_fields(epic_id, projects, sessions, started, until, config, gaps):
    """{attempts, by_task, value, in_attempts, versions, marks, untimed_human, reviews, messages} of a native epic
    from the transcripts of its sessions: the messages from `started` to `until` (the merge of the final PR; None: no
    bound), every message id once (its first occurrence, also in a resumed or forked session), the lead session as
    `orchestrator` without a task, every subagent run one attempt with its role (agentType), task (D4), model and
    effort. `marks`: the marks of every transcript (`read_native`, not cut to the interval); `reviews`: the reports
    of the review roles in the interval (M4); `messages`: {message id: timestamp} of the counted messages; `coverage`:
    `none` (no transcript of a session of the run file was read), `partial` (only some, or a subagent transcript of a
    session read is lost: missing, empty or unreadable, also one the lead session started in the interval without
    any file of it) or `full`; `no_origin`: the lead sessions have user lines but none with an `origin` (a change of
    the transcript format); `transcripts_read`: the number of transcripts read (sessions and subagents, also those
    without a line in the interval)."""
    pricing = config['pricing']
    seen_ids, seen_tools = set(), set()
    attempts, items, datas, marks, reviews, messages = [], [], [], [], [], {}
    untimed_human, user_lines, origin_lines, read, lost, launched = 0, 0, 0, set(), set(), set()
    transcripts_read = 0
    transcripts = native_transcripts(projects, sessions, gaps)
    for kind, session, agent, path, meta_path in transcripts:
        label = 'sesja %s' % session if kind == 'session' else 'agent %s' % agent
        data, reason = read_native(path, seen_tools)
        if data is None:
            gaps.add('sessions', '%s: %s' % (label, reason))
            if kind == 'agent':
                lost.add(agent)
            continue
        transcripts_read += 1
        if data['skipped']:
            gaps.add('sessions', '%s: pominięte linie, które nie są obiektem JSON (%d)' % (label, data['skipped']))
        if kind == 'session':
            read.add(session)
            user_lines += data['user_lines']
            origin_lines += data['origin_lines']
            launched.update(a for at, a in data['launched'] if in_window(at, started, until) and SAFE_NAME.match(a))
        fresh = {i: m for i, m in data['messages'].items() if i not in seen_ids}
        seen_ids.update(data['messages'])
        data['messages'] = {i: m for i, m in fresh.items() if in_window(m['at'], started, until)}
        data['tools'] = [t for t in data['tools'] if t[0] != 'use' or in_window(t[5], started, until)]
        data['times'] = [t for t in data['times'] if in_window(t, started, until)]
        if any(m['at'] is None for m in data['messages'].values()):
            gaps.add('sessions', '%s: wiadomość bez timestamp: liczona bez sprawdzenia przedziału epiku' % label)
        if not data['messages'] and not data['times']:
            continue                                # nothing of this transcript lies in the epic
        datas.append(data)
        marks.append(data['marks'])
        untimed_human += data['untimed_human']
        messages.update((i, m['at']) for i, m in data['messages'].items())
        if kind == 'session':
            known, unknown, _ = native_cells(data['messages'].values(), LEAD_ROLE, pricing, label, gaps)
            items += native_items(NO_TASK, known, unknown)
            continue
        meta = read_meta(meta_path, 'attempts.%s' % agent, gaps)
        task = task_of(meta['description'], epic_id)
        role = meta['role'] or SUBAGENT
        known, unknown, usage_missing = native_cells(data['messages'].values(), role, pricing, label, gaps)
        items += native_items(task or NO_TASK, known, unknown)
        models = {}
        for message in data['messages'].values():
            if message['model']:
                models[message['model']] = models.get(message['model'], 0) + 1
        model = max(sorted(models), key=lambda m: models[m]) if models else None
        if model is None:
            gaps.add('attempts.%s' % agent, 'brak message.model w wiadomościach przebiegu: model nieznany')
        tokens = dict(ZERO_TOKENS)
        for cells in (known, unknown):
            for cell in cells.get(role, {}).values():
                for key in ZERO_TOKENS:
                    tokens[key] += cell['tokens'][key]
        start, end = (min(data['times']), max(data['times'])) if data['times'] else (None, None)
        value = None if unknown else _round_usd(sum(c['usd'] for c in known.get(role, {}).values()))
        reviews += reviews_of_run(agent, task, meta['role'], data['reports'], started, until, gaps)
        attempts.append({'agent_id': agent, 'task': task, 'role': meta['role'],
                         'model': model if model is None or SAFE_NAME.match(model) else 'unknown',
                         'effort': meta['effort'], 'started_at': iso(start), 'ended_at': iso(end),
                         'wall_s': None if start is None else round(end - start, 3), 'value': value, 'estimated': True,
                         'tokens': None if usage_missing else _tokens_out(tokens), 'finished': meta['finished']})
    for agent in sorted(launched - {t[2] for t in transcripts}):
        gaps.add('sessions', 'agent %s: uruchomiony w sesji prowadzącej, brak jego transkryptu i meta.json' % agent)
        lost.add(agent)
    by_task = {}
    for attempt in attempts:
        entry = by_task.setdefault(attempt['task'] or NO_TASK, {'kind': None, 'work': 0.0, 'attempts': 0})
        entry['attempts'] += 1
        if attempt['wall_s'] is None:
            entry['incomplete'] = True
            gaps.add('by_task', 'przebieg %s bez czasu wiadomości: pominięty w czasie' % attempt['agent_id'])
        else:
            entry['work'] = round(entry['work'] + attempt['wall_s'], 3)
    compiled = compile_categories(config)
    in_attempts = in_attempts_of([commands_of(d, config, compiled) for d in datas])
    if not datas:
        gaps.add('in_attempts', 'brak transkryptów sesji epiku')
        gaps.add('versions', 'brak transkryptów sesji epiku')
    return {'attempts': attempts, 'by_task': by_task, 'value': value_sections(items),
            'in_attempts': in_attempts, 'versions': versions_of(datas) if datas else None, 'marks': marks,
            'untimed_human': untimed_human, 'messages': messages,
            'coverage': 'none' if not read else 'partial' if len(read) < len(set(sessions)) or lost else 'full',
            'no_origin': user_lines > 0 and origin_lines == 0, 'transcripts_read': transcripts_read,
            'reviews': sorted(reviews, key=lambda r: (r['at'] is None, r['at'] or '', r['agent_id']))}


# --------------------------------------------------------------------- native epics: time, human, reviews, limits

# M4: the verdicts of the review roles by their agent contracts (.claude/agents); a role without verdicts (ux-reviewer)
# has none. The blocking ones send the task or the change back.
REVIEW_VERDICTS = {'code-reviewer': ('PASS', 'CHANGES_REQUIRED'), 'qa-adversarial': ('PASS', 'INVESTIGATE', 'BLOCK'),
                   'architecture-reviewer': ('KEEP', 'SIMPLIFY', 'REWORK'), 'ux-reviewer': ()}
BLOCKING_VERDICTS = ('CHANGES_REQUIRED', 'BLOCK', 'REWORK')
INTEGRATION_ROLES = ('architecture-reviewer', 'qa-adversarial', 'ux-reviewer')     # the review of the whole change
NO_BREAKDOWN_SOURCE = ('limit_wait', 'ci_wait', 'host_pause', 'github_wait')


def reviews_of_run(agent, task, role, reports, started, until, gaps):
    """The reviews (M4) of one subagent run of a review role: one per report handed back in the interval, the first
    report of the transcript `FULL`, every later one (after a resume) `DELTA`; the verdict only when it is one of the
    role's contract (else None with a gap), never the text."""
    if role not in REVIEW_VERDICTS:
        return []
    if not reports:
        gaps.add('reviews', 'przebieg %s (%s) bez raportu %s' % (agent, role, HANDBACK))
        return []
    found = []
    for index, (at, token) in enumerate(reports):
        if not in_window(at, started, until):
            continue
        verdict = token if token in REVIEW_VERDICTS[role] else None
        if verdict is None:
            gaps.add('reviews', 'raport %s (%s) bez rozpoznanego werdyktu: werdykt null' % (agent, role))
        found.append({'task': task, 'role': role, 'agent_id': agent, 'at': iso(at), 'mode': 'DELTA' if index else 'FULL',
                      'verdict': verdict, 'blocking': None if verdict is None else verdict in BLOCKING_VERDICTS})
    return found


def work_intervals(marks):
    """The intervals of work of every transcript: the time before a line marked `work` (`line_mark`), from the line
    before it in the same transcript."""
    found = []
    for lines in marks:
        ordered = sorted(lines, key=lambda m: m[0])
        found += [(prev[0], line[0]) for prev, line in zip(ordered, ordered[1:]) if line[1] == 'work']
    return found


def human_gaps(marks):
    """[(start, at, key)] of every human line of every transcript: the pause before it, from the line before it in the
    same transcript (none before it: an empty pause); key: the `uuid` of the line, else its time, so a line repeated in a
    resumed session is one."""
    found = []
    for lines in marks:
        ordered = sorted(lines, key=lambda m: m[0])
        for index, (at, mark, key) in enumerate(ordered):
            if mark == 'human':
                found.append((ordered[index - 1][0] if index else at, at, key or ('at', at)))
    return found


def native_pauses(run_file, gaps):
    """The pauses of the computer of the run file (`metrics.py pause`) as merged (start, end) epochs; None without any
    (no key or an empty list): the record then has no source of `host_pause`. A bad entry is skipped with a gap."""
    entries = run_file.get('pauses')
    if not isinstance(entries, list) or not entries:
        return None
    found = []
    for entry in entries:
        try:
            begin = night_queue.parse_time(entry.get('from')).timestamp()
            end = night_queue.parse_time(entry.get('to')).timestamp()
        except (TypeError, ValueError, AttributeError):
            gaps.add('pauses', 'wpis przerwy bez poprawnych from i to: pominięty')
            continue
        if begin >= end:
            gaps.add('pauses', 'wpis przerwy z from nie przed to: pominięty')
            continue
        found.append((begin, end))
    return clip_merge(found, float('-inf'), float('inf')) if found else None


def native_breakdown(work, pauses, started, done, config, gaps, host=None):
    """M3 `breakdown` of a native epic from the start to `done`: `host_pause` the recorded pauses of the computer
    (`host`, cut to the interval; they come first), `work` the union of the intervals of work of the lead and the
    subagents outside them, `human_wait` the pauses before a human line that neither covers, `idle` the rest; the
    other categories have no native source (null), `host_pause` neither without a recorded pause."""
    if started is None or done is None:
        gaps.add('breakdown', 'brak czasu startu albo wywołania metrics.py done: rozkład czasu niemożliwy')
        return None
    stopped = clip_merge(host or [], started, done)
    worked = clip_merge(work, started, done)
    busy = subtract(worked, stopped)
    covered = round(total(worked) - total(busy), 3)
    if covered > 0:
        gaps.add('pauses', 'zapisane przerwy przykrywają %g s pracy z transkryptów (przerwa ma pierwszeństwo: ten czas '
                           'jest w host_pause, nie w work); sprawdź czasy przerw' % covered)
    waiting = subtract(subtract(clip_merge(pauses, started, done), busy), stopped)
    found = {name: None for name in config['breakdown_order']}
    found['work'], found['human_wait'] = round(total(busy), 3), round(total(waiting), 3)
    if host is not None:
        found['host_pause'] = round(total(stopped), 3)
    found['idle'] = round(done - started - total(busy) - total(waiting) - total(stopped), 3)
    for name in NO_BREAKDOWN_SOURCE:
        if name != 'host_pause' or host is None:
            gaps.add('breakdown.%s' % name, '%s: czas tej kategorii jest w work i idle' % NATIVE_SOURCE)
    return found


def native_human(pauses, work, untimed, started, until, manual_fixes, gaps, host=None):
    """M3 `human` of a native epic: every human line after the start (the start command comes before it) up to the
    merge is an intervention (an estimate: also a question about the progress counts); its event has the time and the
    part of the pause before it that neither work nor a recorded pause of the computer (`host`) covers (`wait_s`),
    never the text."""
    busy = clip_merge(list(work) + list(host or []), float('-inf'), float('inf'))
    events, seen = [], set()
    for start, at, key in sorted(pauses, key=lambda p: p[1]):
        if key in seen or (started is not None and at <= started) or (until is not None and at > until):
            continue
        seen.add(key)
        low = start if started is None else max(start, started)
        events.append({'at': iso(at), 'wait_s': round(total(subtract(clip_merge([(low, at)], low, at), busy)), 3)})
    if untimed:
        gaps.add('human', 'wiadomość człowieka bez timestamp (%d): pominięta' % untimed)
    if manual_fixes is None:
        gaps.add('human.manual_fixes', 'nie podano --manual-fixes')
    return {'interventions': len(events), 'interventions_estimated': True,
            'waits': sum(1 for e in events if e['wait_s'] > 0), 'wait_s': round(sum(e['wait_s'] for e in events), 3),
            'events': events, 'manual_fixes': manual_fixes}


def native_integration(attempts, reviews, gaps):
    """M3 `integration`: the time and the number of the runs of the review roles of the whole change; `rounds` the
    largest number of reports of one of these roles. None without such runs."""
    runs = [a for a in attempts if a['role'] in INTEGRATION_ROLES]
    if not runs:
        gaps.add('integration', 'brak przebiegów %s' % ', '.join(INTEGRATION_ROLES))
        return None
    counts = {}
    for review in reviews:
        if review['role'] in INTEGRATION_ROLES:
            counts[review['role']] = counts.get(review['role'], 0) + 1
    found = {'work_s': round(sum(a['wall_s'] for a in runs if a['wall_s'] is not None), 3), 'attempts': len(runs),
             'rounds': max(counts.values(), default=0)}
    if any(a['wall_s'] is None for a in runs):
        found['incomplete'] = True
        gaps.add('integration', 'przebieg bez czasu wiadomości: pominięty w czasie')
    return found


def native_retries(attempts, reviews, gaps):
    """M3 `retries`: `fix` the reviews of tasks with a blocking verdict, `failed_by_outcome` the subagent runs that did
    not finish (`unfinished`); `attempts`, `repair` and `sync_prs` have no native source."""
    unfinished = sum(1 for a in attempts if a['finished'] is False)
    if any(a['finished'] is None for a in attempts):
        gaps.add('retries.failed_by_outcome', 'przebieg bez finished w meta.json: liczba niezakończonych to dolna '
                                              'granica')
    tasks = [r for r in reviews if r['task'] is not None]
    if any(r['verdict'] is None for r in tasks):
        gaps.add('retries.fix', 'przegląd zadania bez rozpoznanego werdyktu: liczba to dolna granica')
    for field in ('attempts', 'repair', 'sync_prs'):
        gaps.add('retries.%s' % field, NATIVE_SOURCE)
    return {'attempts': None, 'failed_by_outcome': {'unfinished': unfinished} if unfinished else {}, 'repair': None,
            'fix': sum(1 for r in tasks if r['blocking'] is True), 'sync_prs': None}


# M5: the table of the section "Kryteria akceptacji" of the description of the final PR (docs/adr/
# agentic-software-house.md, "Przebieg epiku", step 7): | Numer | Sposób | Wynik |, the way one of these.
ACCEPTANCE_SECTION = 'kryteria akceptacji'
ACCEPTANCE_HEADER = ['numer', 'sposób', 'wynik']
ACCEPTANCE_WAYS = (('stack', 'stos'), ('ci', 'ci'), ('after_deploy', 'po wdrożeniu'), ('unverified', 'niezweryfikowane'))
HEADING = re.compile(r'^ {0,3}#{1,6}\s+(.*?)\s*#*\s*$')


def _cells(line):
    """The cells of a table row (lower case, without backticks and emphasis), or None for a line that is no row."""
    text = line.strip()
    if not text.startswith('|'):
        return None
    return [re.sub(r'[`*_]', '', cell).strip().lower() for cell in text.strip('|').split('|')]


def way_of(cell):
    for key, prefix in ACCEPTANCE_WAYS:
        if cell == prefix or (cell.startswith(prefix) and not cell[len(prefix)].isalnum()):
            return key
    return None


def _acceptance_heading(line):
    found = HEADING.match(line)
    return found is not None and found.group(1).strip().lower().startswith(ACCEPTANCE_SECTION)


def criteria_counts(body, gaps):
    """{total, stack, ci, after_deploy, unverified} of the table of acceptance criteria of a PR description, or None
    with a gap without the section, the table or its rows. A row of an unknown way counts only in `total`."""
    lines = body.splitlines()
    start = next((i + 1 for i, line in enumerate(lines) if _acceptance_heading(line)), None)
    if start is None:
        gaps.add('acceptance', 'opis końcowego PR bez sekcji „Kryteria akceptacji”')
        return None
    end = next((i for i in range(start, len(lines)) if HEADING.match(lines[i])), len(lines))
    section = lines[start:end]
    header = next((i for i, line in enumerate(section) if (_cells(line) or [])[:3] == ACCEPTANCE_HEADER), None)
    if header is None:
        gaps.add('acceptance', 'sekcja „Kryteria akceptacji” bez tabeli | Numer | Sposób | Wynik |')
        return None
    counts = {'total': 0, **{key: 0 for key, _ in ACCEPTANCE_WAYS}}
    for line in section[header + 1:]:
        cells = _cells(line)
        if cells is None:
            break
        if all(re.match(r'^:?-+:?$', cell) for cell in cells if cell):
            continue                                        # the separator row
        counts['total'] += 1
        way = way_of(cells[1]) if len(cells) > 1 else None
        if way is None:
            gaps.add('acceptance', 'wiersz %d tabeli kryteriów: sposób nierozpoznany (liczony tylko w total)'
                     % counts['total'])
        else:
            counts[way] += 1
    if not counts['total']:
        gaps.add('acceptance', 'tabela kryteriów akceptacji bez wierszy')
        return None
    return counts


def gate_runner(run):
    """The runner of the origin gate (run(argv) -> stdout, raising on a failure with the output kept, so a 404 of the
    permission call is told apart) over the command runner of metrics.py."""
    def call(args):
        try:
            result = run(args)
        except (OSError, subprocess.TimeoutExpired) as e:
            raise github_trust.CommandError(args, str(getattr(e, 'strerror', None) or e))
        if result.returncode != 0:
            raise github_trust.CommandError(args, (result.stderr or '').strip()[:200] or 'kod %s' % result.returncode,
                                            result.stdout or '')
        return result.stdout
    return call


def native_acceptance(run, no_gh, repo, final_pr, branch, gaps, errors):
    """M5 `acceptance`: the numbers of the criteria table of the description of the final PR, read only through the
    origin gate (github_trust.py): the description of an author without write access never reaches the tool."""
    if no_gh:
        gaps.add('acceptance', 'pominięto gh (--no-gh)')
        return None
    if final_pr is None or branch is None:
        gaps.add('acceptance', 'brak numeru PR albo gałęzi epiku w pliku przebiegu')
        return None
    try:
        gate = github_trust.Gate(gate_runner(run), repo=repo or github_trust.REPOSITORY)
        prs = gate.prs(state='all', head=branch, limit=30, fields=('body',))
    except (github_trust.GateError, ValueError) as e:
        errors.append('bramka pochodzenia: %s' % e)
        gaps.add('acceptance', 'błąd bramki pochodzenia: opis PR niedostępny')
        return None
    pr = next((p for p in prs if p.get('number') == final_pr), None)
    if pr is None:
        gaps.add('acceptance', 'bramka pochodzenia nie zwróciła PR #%d gałęzi epiku' % final_pr)
        return None
    if pr.get('trusted') is not True:
        gaps.add('acceptance', 'autor PR #%d bez prawa zapisu: bramka pochodzenia nie podaje opisu' % final_pr)
        return None
    if not isinstance(pr.get('body'), str) or not pr['body'].strip():
        gaps.add('acceptance', 'końcowy PR bez opisu')
        return None
    return criteria_counts(pr['body'], gaps)


def _assistant_usage(raw):
    """(message id, timestamp, usage) of a transcript line of an assistant message with an id and a usage, else None."""
    if '"assistant"' not in raw:
        return None
    try:
        line = json.loads(raw)
    except ValueError:
        return None
    if not isinstance(line, dict) or line.get('type') != 'assistant':
        return None
    message = line.get('message')
    if not isinstance(message, dict) or not isinstance(message.get('id'), str) \
            or not isinstance(message.get('usage'), dict):
        return None
    return message['id'], _timestamp(line.get('timestamp')), message['usage']


def local_tokens(projects, low, high, weights, gaps):
    """{message id: tokens weighted by `token_weights`} of the assistant messages from `low` to `high` of every session
    and subagent transcript of every project under `projects` (the limits are per account), every id once; a
    transcript last modified before `low` holds none of them and is not read."""
    paths = glob.glob(os.path.join(glob.escape(projects), '*', '*.jsonl')) + glob.glob(
        os.path.join(glob.escape(projects), '*', '*', 'subagents', 'agent-*.jsonl'))
    found, unreadable = {}, 0
    for path in sorted(paths):
        try:
            if os.path.getmtime(path) < low:
                continue
            with open(path, encoding='utf-8', errors='replace') as f:
                for raw in f:
                    item = _assistant_usage(raw)
                    if item and item[0] not in found and item[1] is not None and low <= item[1] <= high:
                        found[item[0]] = weighted(_message_tokens(item[2]), weights)
        except OSError:
            unreadable += 1
    if unreadable:
        gaps.add('subscription.share', 'nieczytelne transkrypty (%d): pominięte w mianowniku' % unreadable)
    return found


def native_subscription(run_file, started, done, projects, messages, config, gaps):
    """M3 `subscription`: the increase of every limit window between the readings of `start` and `done`
    (night_queue._window_deltas: a window renewed between them is null with a gap) and `share`, the tokens of the epic
    from the start to done by the tokens of all local sessions of that interval (an estimate: the use of other devices
    is not seen). None without both readings."""
    before, after = run_file.get('usage_start'), run_file.get('usage_done')
    if not isinstance(before, dict) or not isinstance(after, dict):
        gaps.add('subscription', 'brak odczytu limitów w pliku przebiegu (start albo done): koszt subskrypcji nieznany')
        return None
    before, after = (night_queue.parse_usage(json.dumps(reading)) for reading in (before, after))
    found = subscription_of({'start': {'usage': before}, 'done': {'usage': after}}, {}, gaps)
    found['overlap'] = None
    gaps.add('subscription.overlap', NATIVE_SOURCE)
    found['share'], found['share_estimated'] = None, True
    if started is None or done is None:
        gaps.add('subscription.share', 'brak czasu startu albo done: przedział nieznany')
        return found
    every = local_tokens(projects, started, done, config['token_weights'], gaps)
    whole = sum(every.values())
    mine = sum(every.get(i, 0) for i, at in messages.items() if at is not None and started <= at <= done)
    if whole <= 0:
        gaps.add('subscription.share', 'brak tokenów sesji lokalnych w przedziale')
    else:
        found['share'] = round(mine / whole, 4)
    return found


# The process fields of a native record that come from the transcripts (M3, M4): without any transcript of the epic
# they are unknown, never zero, and a stored value stays (a refresh after the retention of the transcripts, e.g. by
# /retro collect, must not replace it); without the transcripts of some sessions they are a lower bound.
TRANSCRIPT_PATHS = (('breakdown',), ('reviews',), ('integration',), ('human', 'interventions'), ('human', 'waits'),
                    ('human', 'wait_s'), ('human', 'events'), ('retries', 'fix'), ('retries', 'failed_by_outcome'),
                    ('subscription', 'share'))
NO_TRANSCRIPTS = 'brak transkryptów sesji epiku (retencja albo zły identyfikator sesji): wartość nieznana'
PARTIAL_TRANSCRIPTS = ('transkrypt części sesji albo subagentów epiku niedostępny: wartość z dostępnych transkryptów '
                       'niepełna (dolna granica; idle górna)')
HUMAN_EVENT_PATHS = (('human', 'interventions'), ('human', 'waits'), ('human', 'wait_s'), ('human', 'events'))
# The fields of a native record from the transcripts that are empty, not null, without them (`attempts` [], `by_task`
# {}, `value` without a total; `in_attempts` and `versions` null): kept from the stored native record the same way.
TRANSCRIPT_KEPT = (('attempts',), ('by_task',), ('value',), ('in_attempts',), ('versions',))


def _clear(record, paths, gaps, reason):
    for path in paths:
        if isinstance(_get(record, path[:-1]), dict):
            _set(record, path, None)
            gaps.add('.'.join(path), reason)


def _keep_stored(record, stored, gaps, reason):
    """Puts the values of `TRANSCRIPT_PATHS` and `TRANSCRIPT_KEPT` of the stored native record back into `record` (a
    changed one with a gap); the paths with a stored value."""
    kept = set()
    for path in TRANSCRIPT_PATHS + TRANSCRIPT_KEPT:
        old = _get(stored, path)
        if old is None or not isinstance(_get(record, path[:-1]), dict):
            continue
        kept.add(path)
        if old != _get(record, path):
            _set(record, path, old)
            gaps.add('.'.join(path), reason)
    return kept


def _within(field, kept):
    """Whether the path `field` (dotted) is one of the `kept` paths, lies in one or holds one."""
    path = tuple(field.split('.'))
    return any(p[:len(path)] == path or path[:len(p)] == p for p in kept)


def apply_transcript_coverage(record, coverage, no_origin, previous, gaps):
    """The process fields of `record` by the transcripts read (`native_fields`): `none` makes them null with a gap and
    keeps the values of the stored native record (also of `TRANSCRIPT_KEPT`); fewer transcripts read than for the
    stored native record (`transcripts_read`: retention, a lost file) keep its values the same way and its number;
    `partial` adds the gap of a lower bound to the rest; `no_origin` (no user line of the lead sessions has an `origin`)
    makes the human messages and `human_wait` unknown, unless they are kept."""
    stored = previous if isinstance(previous, dict) and previous.get('source') == 'native' else None
    stored_read = stored.get('transcripts_read') if stored is not None and _is_int(stored.get('transcripts_read')) \
        else None
    if coverage == 'none':
        _clear(record, TRANSCRIPT_PATHS, gaps, NO_TRANSCRIPTS)
        _keep_stored(record, stored, gaps, 'brak transkryptów sesji epiku: zachowano poprzednią wartość')
        if stored_read is not None and stored_read > record['transcripts_read']:
            record['transcripts_read'] = stored_read
        return
    kept = set()
    if stored_read is not None and stored_read > record['transcripts_read']:
        kept = _keep_stored(record, stored, gaps, 'przeczytano mniej transkryptów epiku (%d) niż dla zapisanego rekordu '
                                                  '(%d): zachowano poprzednią wartość'
                                                  % (record['transcripts_read'], stored_read))
        record['transcripts_read'] = stored_read
    if coverage == 'partial':
        for field in ('breakdown', 'human', 'integration', 'reviews', 'retries.fix', 'retries.failed_by_outcome',
                      'subscription.share', 'attempts', 'by_task', 'value', 'in_attempts'):
            if _get(record, tuple(field.split('.'))) is not None and not _within(field, kept):
                gaps.add(field, PARTIAL_TRANSCRIPTS)
    if no_origin:
        reason = 'linie użytkownika bez pola origin (zmiana formatu CLI): wiadomości człowieka nierozpoznane'
        _clear(record, [p for p in HUMAN_EVENT_PATHS if p not in kept], gaps, reason)
        breakdown = record.get('breakdown')
        if ('breakdown',) not in kept and isinstance(breakdown, dict) and _is_number(breakdown.get('human_wait')):
            breakdown['idle'] = round(breakdown['idle'] + breakdown['human_wait'], 3)
            _clear(record, (('breakdown', 'human_wait'),), gaps, reason + ': czas w idle')


def _run_time(run_file, field, gaps, reason):
    try:
        return night_queue.parse_time(run_file.get(field)).timestamp()
    except (TypeError, ValueError, AttributeError):
        gaps.add(field, reason)
        return None


def build_native_record(epic_id, run_file, config, projects, now, run=None, no_gh=True, repo=None, previous=None,
                        records=None, manual_fixes=None, worktree=None):
    """(record, errors): the record of a native epic (spec ash-v1, M3) in the format of the AO record, `source:
    native`. A field without a native source is null with a gap; `errors` are the messages of failed GitHub calls (and
    of the origin gate). manual_fixes: the list of --manual-fixes or None."""
    records, gaps, errors = records or {}, Gaps(), []
    started = _run_time(run_file, 'started_at', gaps,
                        'brak czasu startu w pliku przebiegu: wiadomości bez dolnej granicy')
    done = _run_time(run_file, 'done_at', gaps, 'brak wywołania metrics.py done w pliku przebiegu')
    tasks = run_file.get('tasks') if _is_int(run_file.get('tasks')) and run_file['tasks'] > 0 else None
    if tasks is None:
        gaps.add('tasks', 'brak liczby zadań w pliku przebiegu')
    final_pr = run_file.get('pr') if _is_int(run_file.get('pr')) else None
    branch = run_file.get('branch') if isinstance(run_file.get('branch'), str) and run_file['branch'] else None
    sessions = []
    for entry in run_file.get('sessions') if isinstance(run_file.get('sessions'), list) else []:
        session = entry.get('id') if isinstance(entry, dict) else None
        if isinstance(session, str) and SAFE_ID.match(session) and session not in sessions:
            sessions.append(session)
        else:
            gaps.add('sessions', 'wpis sesji bez poprawnego identyfikatora w pliku przebiegu: pominięty')
    if not sessions:
        gaps.add('sessions', 'plik przebiegu bez sesji')
    pr = None
    if final_pr is None:
        gaps.add('final_pr', 'brak numeru PR w pliku przebiegu')
    if no_gh:
        for field in ('issue', 'merged_at'):
            gaps.add(field, 'pominięto gh (--no-gh)')
    elif final_pr is not None:
        try:
            pr = fetch_pr(run, final_pr, repo)
        except GhError as e:
            errors.append(str(e))
            gaps.add('issue', 'błąd gh: %s' % e)
            gaps.add('merged_at', 'błąd gh: %s' % e)
    merged = pr['merged_at'] if pr else None
    if pr is not None:
        if merged is None:
            gaps.add('merged_at', 'końcowy PR nie jest zmergowany (stan %s)' % pr['state'])
        if pr['issue'] is None:
            gaps.add('issue', 'końcowy PR nie zamyka żadnego issue')
    known_merge = merged if merged is not None else _stored_merged(previous)
    if known_merge is None:
        gaps.add('sessions', "czas merge'u końcowego PR nieznany: wiadomości liczone do końca transkryptów")
    fields = native_fields(epic_id, projects, sessions, started, known_merge, config, gaps)
    if any(a['value'] is None for a in fields['attempts']) or _get(fields['value'], ('total', 'incomplete')):
        gaps.add('value', 'część wiadomości bez wartości: suma niepełna')
    work, pauses = work_intervals(fields['marks']), human_gaps(fields['marks'])
    host = native_pauses(run_file, gaps)
    record = {
        'epic': epic_id,
        'source': 'native',
        'issue': pr['issue'] if pr else None,
        'final_pr': final_pr,
        'tasks': tasks,
        'started_at': iso(started),
        'done_at': iso(done),
        'merged_at': iso(merged),
        'wall': {
            'to_done': round(done - started, 3) if done is not None and started is not None else None,
            'to_merge': round(merged - started, 3) if merged is not None and started is not None else None,
            'review': round(merged - done, 3) if merged is not None and done is not None else None,
        },
        'breakdown': native_breakdown(work, [p[:2] for p in pauses], started, done, config, gaps, host),
        'breakdown_incomplete': True,
        'integration': native_integration(fields['attempts'], fields['reviews'], gaps),
        'retries': native_retries(fields['attempts'], fields['reviews'], gaps),
        'parallel': None,
        'subscription': native_subscription(run_file, started, done, projects, fields['messages'], config, gaps),
        'gate_wait': None,
        'human': native_human(pauses, work, fields['untimed_human'], started, known_merge, manual_fixes, gaps, host),
        'in_attempts': fields['in_attempts'],
        'by_task': fields['by_task'],
        'versions': fields['versions'],
        'attempts': fields['attempts'],
        'value': fields['value'],
        'waste': None,
        'reviews': fields['reviews'],
        'acceptance': native_acceptance(run, no_gh, repo, final_pr, branch, gaps, errors),
        'sessions': sessions,
        'transcripts_read': fields['transcripts_read'],
        'pricing': {'source': config['pricing']['source'], 'read_at': config['pricing']['read_at']},
    }
    record['process'] = native_process(run_file, run, worktree, projects, started, known_merge, record['attempts'],
                                       now, gaps)
    for field in ('parallel', 'gate_wait', 'waste'):
        gaps.add(field, NATIVE_SOURCE)
    record['ci'] = None
    if no_gh:
        for field in ('ci', 'regressions', 'found_after_merge'):
            gaps.add(field, 'pominięto gh (--no-gh)')
    else:
        if final_pr is None or branch is None:
            gaps.add('ci', 'brak numeru PR albo gałęzi epiku w pliku przebiegu')
        else:
            try:
                record['ci'] = {'final': ci_entry(run, repo, config, final_pr, branch), 'tasks': None}
                if not record['ci']['final']['runs']:
                    gaps.add('ci', 'brak przebiegów CI gałęzi: %s' % branch)
            except GhError as e:
                errors.append(str(e))
                gaps.add('ci', 'błąd gh: %s' % e)
        record['regressions'] = record['found_after_merge'] = None
        if known_merge is None or final_pr is None:
            for field in ('regressions', 'found_after_merge'):
                gaps.add(field, 'końcowy PR nie jest zmergowany albo jego stan nieznany: okno regresji nie '
                                'zaczęło się')
        else:
            sha = pr['merge_sha'] if pr else _get(previous, ('regressions', 'merge_commit'))
            record['regressions'], record['found_after_merge'] = regressions_of(
                run, repo, config, epic_id, final_pr, sha, known_merge, now, previous, gaps, errors)
    record.setdefault('regressions', None)
    record.setdefault('found_after_merge', None)
    apply_transcript_coverage(record, fields['coverage'], fields['no_origin'], previous, gaps)
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


SOURCES = ('ao', 'native')


def source_of(record):
    """The source of a record: `native` for a record of a native epic, `ao` for the others (also a record stored before
    the field `source` existed)."""
    return 'native' if isinstance(record, dict) and record.get('source') == 'native' else 'ao'


def history_of(record, records):
    """The stored records of the other epics of the same source merged before this one (all merged ones while it is not
    merged): the history the medians come from. The sources are never mixed: the fields of a native record are counted
    differently than in AO (spec: Trend i raport dwóch źródeł)."""
    try:
        merged = night_queue.parse_time(record['merged_at']).timestamp()
    except (KeyError, TypeError, ValueError, AttributeError):
        merged = None
    found = []
    for other in records.values():
        if other.get('epic') == record.get('epic') or source_of(other) != source_of(record):
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

    def add(kind, name, value, median_s, denominator, share_of, repeat_s=None, estimated=False):
        excess = value - median_s
        item = {'kind': kind, 'name': name, 'value_s': round(value, 3), 'median_s': round(median_s, 3),
                'excess_h': round(excess / HOUR, 3),
                'share': round(excess / denominator, 4) if _is_number(denominator) and denominator > 0 else None,
                'share_of': share_of, 'rule': CATEGORY_RULES.get((kind, name)), 'estimated': bool(estimated)}
        if kind == 'in_attempts':
            item['repeat_h'] = None if repeat_s is None else round(min(repeat_s, max(excess, 0.0)) / HOUR, 3)
        candidates.append((excess, item))

    def per_task_median(getter):
        values = [getter(h) / h['tasks'] for h in history if _is_number(getter(h)) and _is_int(h.get('tasks'))
                  and h['tasks'] > 0]
        return median(values) * tasks if values else None

    to_done = _get(record, ('wall', 'to_done'))
    scaled = _is_int(tasks) and tasks > 0

    def category(kind, name, value, getter, denominator, share_of, repeat_s=None, estimated=False):
        if not scaled or not _is_number(value):
            return
        middle = per_task_median(getter)
        if middle is not None:
            add(kind, name, value, middle, denominator, share_of, repeat_s, estimated)

    for name in config['breakdown_order']:
        if name != 'work':
            category('breakdown', name, _get(record, ('breakdown', name)), lambda r, n=name: _get(r, ('breakdown', n)),
                     to_done, 'to_done',
                     estimated=breakdown_inexact(record, name) or any(breakdown_inexact(h, name) for h in history))
    kinds, kinds_inexact = {}, set()
    for other in history:
        for entry in (other.get('by_task') or {}).values():
            if isinstance(entry, dict) and entry.get('kind') and _is_number(entry.get('work')):
                kinds.setdefault(entry['kind'], []).append(entry['work'])
                if inexact(entry):
                    kinds_inexact.add(entry['kind'])
    for task, entry in sorted((record.get('by_task') or {}).items()):
        if isinstance(entry, dict) and _is_number(entry.get('work')) and kinds.get(entry.get('kind')):
            add('task', task, entry['work'], median(kinds[entry['kind']]), to_done, 'to_done',
                estimated=inexact(entry) or entry['kind'] in kinds_inexact)
            candidates[-1][1]['rule'] = 'D2' if entry['kind'] == 'plan' else None
    attempts_inexact = inexact(record.get('in_attempts')) or any(inexact(h.get('in_attempts')) for h in history)
    for name in (record.get('in_attempts') or {}).get('categories', {}):
        category('in_attempts', 'in_attempts.%s' % name, _get(record, ('in_attempts', 'categories', name, 'seconds')),
                 lambda r, n=name: _get(r, ('in_attempts', 'categories', n, 'seconds')), to_done, 'to_done',
                 _get(record, ('in_attempts', 'repeat_runs', 'by_category', name, 'seconds')),
                 estimated=attempts_inexact)
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
        item['effect_estimated'] = item['estimated']        # the effect of a plan task is its own time over the median
        if candidate is not None and key == D2_KEY:
            saving = next((t['excess_h'] for t in candidate['data']['tasks'] if t['task'] == item['name']), None)
        elif candidate is not None:
            saving = candidate['expected_effect']['wall_h']
            item['effect_estimated'] = item['estimated'] or candidate['expected_effect']['estimated']
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

    def check(subject, parts, denominator, denominator_name, threshold, proposal, marked=()):
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
        data['estimated'] = list(marked)    # the names of the parts that are estimated or incomplete
        found.append(make_candidate(key, data, proposal, share * denominator / HOUR, estimated=bool(marked)))
        return data

    parts_names = {'human_wait': ['human_wait'], 'limit_wait': ['limit_wait'], 'ci': ['ci_wait', 'in_attempts.ci_watch'],
                   'review': ['review']}
    to_done, work = _get(record, ('wall', 'to_done')), _get(record, ('breakdown', 'work'))
    check('human_wait', [_get(record, ('breakdown', 'human_wait'))], to_done, 'to_done', settings['d4_human_wait_share'],
          'Skrócić czekanie na użytkownika: zebrać pytania przed startem epiku i uruchamiać go o godzinie, '
          'w której użytkownik odpowiada.', ['human_wait'] if breakdown_inexact(record, 'human_wait') else [])
    check('limit_wait', [_get(record, ('breakdown', 'limit_wait'))], to_done, 'to_done',
          settings['d4_limit_wait_share'],
          'Zaplanować start epiku (planowanie nocy) tak, żeby mieścił się w oknie limitów bez przerwy na cooldown.',
          ['limit_wait'] if breakdown_inexact(record, 'limit_wait') else [])
    ci = [_get(record, ('breakdown', 'ci_wait')), _get(record, ('in_attempts', 'categories', 'ci_watch', 'seconds'))]
    data = check('ci', ci, work, 'work', settings['d4_ci_share'],
                 'Skrócić czekanie na CI: sprawdzić, które przebiegi CI zadań i końcowego PR trwają najdłużej '
                 'i ile jest ponowień.', (['ci_wait'] if breakdown_inexact(record, 'ci_wait') else [])
                 + (['in_attempts.ci_watch'] if inexact(record.get('in_attempts')) else [])
                 + (['work'] if 'work' in lower_bounds(record) else []))
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
                                    'reasons': len(reasons), 'estimated': []},
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
    if by_task and all(isinstance(e, dict) and e.get('kind') is None for e in by_task.values()):
        return skip_rule(skipped, D2_KEY, 'brak danych: tasks.kind (rodzaje zadań nieznane)')
    plan_entries = [e for h in history for e in (h.get('by_task') or {}).values()
                    if isinstance(e, dict) and e.get('kind') == 'plan' and _is_number(e.get('work'))]
    works = [e['work'] for e in plan_entries]
    middle = median(works) if works else None
    middle_inexact = any(inexact(e) for e in plan_entries)      # a lower bound in the history lowers the median
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
                          'failed_attempts': failed.get(task, 0), 'reasons': reasons,
                          'estimated': inexact(entry) or (middle_inexact and over is not None)})
    if not tasks:
        return []
    wall = sum(t['excess_h'] for t in tasks if t['excess_h'] is not None)
    estimated = any(t['estimated'] for t in tasks)
    return [make_candidate(D2_KEY, {'tasks': tasks, 'median_h': None if middle is None else round(middle / HOUR, 3),
                                    'history_epics': len(history), 'estimated': ['work'] if estimated else []},
                           'Dodać do /planner regułę podziału zadania z cechami: liczba ścieżek `scope`, ryzyko, '
                           'aplikacje, liczba zmienionych plików.', wall, estimated=estimated)]


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
        dearer = {m: c for m, c in cells.items() if m in ranked and ranked[m] > ranked[cheapest] and isinstance(c, dict)}
        costly = {m: c for m, c in dearer.items() if _is_number(c.get('usd'))}
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
        # the value of the role is a lower bound when a cell of it on a dearer model has no value (`usd` null); the share
        # is made of the value of the whole epic, so it is as exact as that
        marked = (['role_value'] if any(inexact(c) for c in costly.values()) or len(dearer) > len(costly) else []) \
            + (['epic_total'] if inexact(_get(record, ('value', 'total'))) else [])
        estimated = 'role_value' in marked      # the saving is made of the role cells only; the share only decides
        saving = sum(c['usd'] * (1 - ranked[cheapest] / ranked[m]) for m, c in costly.items())
        data = {'role': role, 'share': round(share, 4), 'models': {m: c['usd'] for m, c in sorted(costly.items())},
                'cheaper_model': cheapest, 'rates': {m: ranked[m] for m in sorted(ranked)}, 'estimated': marked}
        found.append(make_candidate(key, data, (
            'Eksperyment porównania wariantów agenta %s w EVALS 2.0 na modelu %s (wariant to commit ze zmianą `model:` '
            'w pliku agenta, `--agent-rev`; płatny pomiar wymaga zgody właściciela), nie zmiany modelu.%s'
            % (role, cheapest, (' Wartość roli jest szacowana.' if estimated else '')
               + (' Udział roli liczony z szacowanej albo niepełnej wartości epiku.'
               if 'epic_total' in marked else ''))),
            None, saving, estimated))
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


def parse_at(text, option='--at'):
    try:
        return night_queue.parse_time(text).timestamp()
    except (ValueError, TypeError, AttributeError):
        raise MetricsError('zły %s %r: oczekiwano czasu ISO 8601 z przesunięciem UTC albo Z (np. 2026-11-10T12:00:00Z)'
                           % (option, text))


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


def effect_source(records, at):
    """The source the windows of `effect` are taken from: the one of most of the epics started after `at` (the first
    started one when equal), without any such epic the one of the epic merged last; `ao` for no records. The sources
    are never mixed (as in `history_of`)."""
    after, merged = [], []
    for record in records.values():
        started, done = _epoch(record, 'started_at'), _epoch(record, 'merged_at')
        if started is not None and started > at:
            after.append((started, str(record.get('epic')), source_of(record)))
        if done is not None:
            merged.append((done, str(record.get('epic')), source_of(record)))
    if after:
        found = [source for _, _, source in sorted(after)]
        return max(dict.fromkeys(found), key=found.count)
    return sorted(merged)[-1][2] if merged else 'ao'


def effect_windows(records, at, gaps):
    """(before, after, before_pool, spanning, unmerged): the records of the two windows (oldest first), the whole pool of
    epics merged at or before `at` (its closest ones give the epic time) and the ids left out. Only the records of one
    source (`effect_source`) are used; the others are a gap."""
    source = effect_source(records, at)
    others = sorted(str(r.get('epic')) for r in records.values() if source_of(r) != source)
    if others:
        gaps.add('effect', 'pominięto %d epików innego źródła niż %s (okna liczy się osobno dla każdego źródła): %s'
                           % (len(others), source, ', '.join(others)))
    records = {key: r for key, r in records.items() if source_of(r) == source}
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
    before = pool[max(0, len(pool) - len(after)):] if after else []
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
    """The value of the role in each task of `kind` (0 where it did not act, i.e. has no cell); a task is left out
    when its own value is unknown or the role acted in it with an unknown value (`usd` null): not a zero (the window
    is then estimated: `role_estimated`). None for a record without value.by_task_role (older than the field)."""
    by_role, by_task = _get(record, ('value', 'by_task_role')), record.get('by_task')
    if not isinstance(by_role, dict) or not isinstance(by_task, dict):
        return None
    found = []
    for task, entry in sorted(by_task.items()):
        if not isinstance(entry, dict) or entry.get('kind') != kind:
            continue
        if not _is_number(_get(record, ('value', 'by_task', task, 'usd'))):
            continue                                    # the value of the task is unknown: not a zero
        cell = _get(by_role, (task, role))
        if cell is None:
            found.append(0.0)                           # the role did not act in the task
        elif _is_number(_get(cell, ('usd',))):
            found.append(cell['usd'])
        # else: the role acted but its value is unknown: not a zero
    return found


def role_estimated(record, role, kind):
    """Whether the samples of `role_samples` are not exact (estimated or incomplete, as in `decide`): a task of `kind`
    whose own value is unknown (it is left out of the samples) or incomplete (the role may have had the missing part),
    or a role cell of it that is estimated or incomplete or whose `usd` is unknown (left out, so the window is
    incomplete)."""
    by_role = _get(record, ('value', 'by_task_role'))
    by_task = record.get('by_task')
    if not isinstance(by_role, dict) or not isinstance(by_task, dict):
        return False
    for task, entry in by_task.items():
        if not isinstance(entry, dict) or entry.get('kind') != kind:
            continue
        if not _is_number(_get(record, ('value', 'by_task', task, 'usd'))):
            return True                                 # left out of the samples for an unknown value: incomplete
        if _get(record, ('value', 'by_task', task, 'incomplete')) is True:
            return True                                 # a part of the task is unknown: the role may have had it
        cell = _get(by_role, (task, role))
        if cell is None:
            continue                                    # the role did not act in the task: an exact zero
        if (not _is_number(_get(cell, ('usd',))) or _get(cell, ('estimated',)) is True
                or _get(cell, ('incomplete',)) is True):
            return True
    return False


def window_stats(records, samples_of, gaps, label, missing='brak miary', estimated_of=None):
    """{epics, n, n_epics, median, p25, p75, estimated} of the samples of the records of a window; a record whose
    samples are unknown (None) is left out with a gap. `estimated`: a record is estimated by `estimated_of` (the
    measures that are not computed from estimated values have none); a record with no samples left because the role
    value is unknown is left out with a gap and makes the window estimated."""
    values, epics, estimated = [], [], False
    for record in records:
        found = samples_of(record)
        if found is None:
            gaps.add('effect', 'epik %s (okno %s): %s, pominięty' % (record.get('epic'), label, missing))
        elif found:
            values.extend(found)
            epics.append(str(record.get('epic')))
            estimated = estimated or bool(estimated_of and estimated_of(record))
        elif estimated_of and estimated_of(record):
            estimated = True                            # left out for an unknown value: the window is incomplete
            gaps.add('effect', 'epik %s (okno %s): wartość roli nieznana, pominięty' % (record.get('epic'), label))
    ordered = sorted(values)
    stat = lambda f: round(percentile(ordered, f), 4) if ordered else None
    return {'epics': epics, 'n': len(values), 'n_epics': len(epics),
            'median': round(median(ordered), 4) if ordered else None, 'p25': stat(0.25), 'p75': stat(0.75),
            'estimated': estimated}


def plan_work_inexact(record):
    """Whether a plan task of the record has a `work` that is a lower bound (an attempt without a time)."""
    tasks = record.get('by_task')
    return isinstance(tasks, dict) and any(isinstance(e, dict) and e.get('kind') == 'plan' and inexact(e)
                                           for e in tasks.values())


def d4_inexact(subject):
    """The test of a record for the measure of `D4:<subject>`: the category is a lower or an upper bound (`human_wait`,
    `limit_wait`), or so is a part of `ci` (`ci_wait`, `in_attempts.ci_watch`) or its denominator `work`."""
    if subject in ('human_wait', 'limit_wait'):
        return lambda record: breakdown_inexact(record, subject)
    if subject == 'ci':
        return lambda record: (breakdown_inexact(record, 'ci_wait') or inexact(record.get('in_attempts'))
                               or 'work' in lower_bounds(record))
    return None


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
    short_before = len(before) < len(after)     # fewer epics merged before `at` than started after it
    if short_before:
        gaps.add('effect', 'okno przed ma %d epików, okno po %d: za mało zmergowanych epików przed --at, okna nie są '
                           'w pełni porównywalne' % (len(before), len(after)))
    settings = config['decisions']
    level_kind, estimated_of = None, None
    if rule == 'D1':
        level_kind = role_kind(before + after, subject)
        samples_of = (lambda r: role_samples(r, subject, level_kind)) if level_kind else (lambda r: [])
        estimated_of = (lambda r: role_estimated(r, subject, level_kind)) if level_kind else None
    elif rule == 'D2':
        samples_of, estimated_of = plan_work_samples, plan_work_inexact
    else:
        samples_of = lambda r: [v] if (v := epic_value(r, rule, subject)) is not None else None
        estimated_of = d4_inexact(subject) if rule == 'D4' else None
    missing = 'rekord bez value.by_task_role (starszy niż pole)' if rule == 'D1' else 'brak miary'
    stats = {'before': window_stats(before, samples_of, gaps, 'przed', missing, estimated_of),
             'after': window_stats(after, samples_of, gaps, 'po', missing, estimated_of)}
    stats['before']['estimated'] = stats['before']['estimated'] or short_before
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
            'after': stats['after'], 'estimated': stats['before']['estimated'] or stats['after']['estimated'],
            'message': NOT_ENOUGH if reasons else None, 'reasons': reasons, 'diff': diff,
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


def trend_estimated(record, config):
    """The columns of `trend_values` of one record that come from an estimated or incomplete source
    (`value.total.estimated`, `subscription.share_estimated`, `in_attempts.incomplete` for `repeat_runs`) and have a
    value: an unknown column is not an estimate."""
    values = trend_values(record, config)
    flags = {'value': _get(record, ('value', 'total', 'estimated')) is True,
             'pp': _get(record, ('subscription', 'share_estimated')) is True,
             'repeat_runs': inexact(record.get('in_attempts')), 'work': 'work' in lower_bounds(record),
             'human_wait': breakdown_inexact(record, 'human_wait')}
    return {column for column, flag in flags.items() if flag and values[column] is not None}


# The columns of `trend` a native record counts as an AO one does (spec: M6): the time to the ready PR, the time to the
# merge (and so the review between them) and the regressions. The other columns of a native record are marked: the value
# (the price list instead of the cost of the CLI), the interventions, the breakdown of the time (`work`, `human_wait`),
# the repeated commands and the share of the subscription limits are counted differently.
COMPARABLE_COLUMNS = ('to_done', 'review', 'regressions')
DIFFERENT_NOTE = 'liczone inaczej niż w AO'
DIFFERENT_MARK = '\u2020'


def trend_different(record, config):
    """The columns of `trend_values` of one record that are counted differently than in AO and have a value (a native
    record: all but COMPARABLE_COLUMNS; an AO record: none). An unknown column is not marked."""
    if source_of(record) != 'native':
        return set()
    values = trend_values(record, config)
    return {column for column in TREND_COLUMNS if column not in COMPARABLE_COLUMNS and values[column] is not None}


def estimated_list(sets):
    """The union of sets of columns (estimated ones, or ones counted differently) as a list in the order of TREND_COLUMNS
    (a median of a column is estimated when one of the values it is built from is)."""
    union = set().union(*sets) if sets else set()
    return [column for column in TREND_COLUMNS if column in union]


def cell_text(value, estimated, different=False):
    """A number with two places, `≈ ` before an estimated one (as `usd_text`) and the mark of a field counted differently
    than in AO after it; `-` for an unknown one."""
    return approx(estimated and value is not None) + pl(value, 2) + (DIFFERENT_MARK if different and value is not None
                                                                     else '')


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


def percent_text(entry, estimated):
    """`, +50%` (`, ≈ +50%` when the value or the median of the column is estimated: so is the percent), empty for an
    unknown percent."""
    if entry['diff_pct'] is None:
        return ''
    return ', %s%s%s%%' % (approx(estimated), '+' if entry['diff_pct'] >= 0 else '−', pl(abs(entry['diff_pct']), 0))


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
    """The trend table: the last `last` merged epics (of both sources, each row with its `source`) with their columns,
    the medians of the shown epics per source (nulls skipped), the position of `epic` (default: the latest; it may be
    older than the shown ones) against the median of its own source and the annotations. `median` and `median_estimated`
    are those of the source of the position."""
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
        rows.append({'epic': record['epic'], 'source': source_of(record), 'merged_at': record.get('merged_at'),
                     'tasks': record.get('tasks'), 'values': trend_values(record, config),
                     'estimated': estimated_list([trend_estimated(record, config)]),
                     'different': estimated_list([trend_different(record, config)]),
                     'annotations': change_annotations(ordered[index - 1], record) if index else []})
    sources = {}
    for name in SOURCES:
        part = [(row, record) for row, record in zip(rows, shown) if row['source'] == name]
        if part:
            medians = {column: median_of([row['values'][column] for row, _ in part]) for column in TREND_COLUMNS}
            sources[name] = {'epics': len(part), 'median': medians,
                             'median_estimated': estimated_list([trend_estimated(r, config) for _, r in part]),
                             'median_different': [c for c in estimated_list(
                                 [trend_different(r, config) for _, r in part]) if medians[c] is not None]}
    name = source_of(chosen)
    own = sources.get(name) or {'epics': 0, 'median': {column: None for column in TREND_COLUMNS},
                                'median_estimated': [], 'median_different': []}
    values = trend_values(chosen, config)
    return {'columns': list(TREND_COLUMNS), 'epics': rows, 'median': own['median'],
            'median_estimated': own['median_estimated'], 'median_source': name, 'sources': sources,
            'position': {'epic': chosen['epic'], 'source': name, 'columns': position_of(values, own['median']),
                         'estimated': estimated_list([trend_estimated(chosen, config)]),
                         'different': estimated_list([trend_different(chosen, config)]),
                         'median_estimated': own['median_estimated'], 'median_different': own['median_different']},
            'recomputed': []}


def pl(value, digits=1):
    """A number with a decimal comma; `-` for an unknown one."""
    if value is None:
        return '-'
    return ('%.*f' % (digits, value)).replace('.', ',')


def format_trend(table):
    heads = {'to_done': 'do_done', 'work': 'work', 'human_wait': 'human', 'review': 'review', 'gate_wait': 'gate',
             'repeat_runs': 'powt.', 'value': 'USD', 'pp': 'pp', 'failed': 'nieud.', 'repair': 'REPAIR',
             'interventions': 'interw.', 'regressions': 'regr.'}
    columns = table['columns']

    def texts(values, estimated, different, source):
        # a native record has no source of some fields: `brak danych`, not a dash (and never a zero)
        return [NO_DATA if values[c] is None and source == 'native'
                else cell_text(values[c], c in estimated, c in different) for c in columns]

    width = max([len('mediana')] + [len(r['epic']) for r in table['epics']])
    source_width = max([len('źródło')] + [len(r['source']) for r in table['epics']] + [len(n) for n in table['sources']])
    lines_of = [(r['epic'], r['source'], texts(r['values'], r['estimated'], r['different'], r['source']))
                for r in table['epics']]
    medians = [('mediana', name, texts(entry['median'], entry['median_estimated'], entry['median_different'], name))
               for name, entry in table['sources'].items()]
    # a column is as wide as its widest cell (an estimate with `≈ ` can be wider than 8 characters)
    widths = [max([8] + [len(cells[i]) for _, _, cells in lines_of + medians]) for i in range(len(columns))]
    lines = ['Trend (na zadanie planu; czasy w godzinach; epików: %d)' % len(table['epics']),
             ' '.join(['epik'.ljust(width), 'źródło'.ljust(source_width)] + [heads[c].rjust(w)
                                                                           for c, w in zip(columns, widths)])]
    for name, source, cells in lines_of + medians:
        lines.append(' '.join([name.ljust(width), source.ljust(source_width)] + [t.rjust(w) for t, w in zip(cells, widths)]))
    position = table['position']
    if any(r['different'] for r in table['epics']) or position['different'] or any(
            e['median_different'] for e in table['sources'].values()):
        lines.append('%s %s (cennik zamiast kosztu z CLI, wiadomości człowieka jako interwencje, rozkład czasu bez '
                     'oczekiwania na limity, CI i GitHub, koszt subskrypcji z lokalnych transkryptów)' % (
                         DIFFERENT_MARK, DIFFERENT_NOTE))
    lines.append('%s wobec mediany (źródło: %s):' % (position['epic'], position['source']))
    for column in columns:
        entry = position['columns'][column]
        if entry['diff'] is None:
            lines.append('  %s: brak danych' % heads[column])
            continue
        lines.append('  %s: %s (mediana %s%s)' % (
            heads[column], cell_text(entry['value'], column in position['estimated'], column in position['different']),
            cell_text(entry['median'], column in position['median_estimated'], column in position['median_different']),
            percent_text(entry, column in position['estimated'] or column in position['median_estimated'])))
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


def hours_text(seconds, estimated=False):
    return NO_DATA if not _is_number(seconds) else '%s%s h' % (approx(estimated), pl(seconds / HOUR))


def percent_of(part, whole):
    return None if not _is_number(part) or not _is_number(whole) or whole <= 0 else int(round(part / whole * 100))


def usd_text(cell):
    """`12,34 USD`, `≈ 12,34 USD` for an estimated value, `brak danych` for an unknown one."""
    cell = _dict(cell)
    usd = cell.get('usd')
    if not _is_number(usd):
        return NO_DATA
    text = '%s%s USD' % (approx(cell.get('estimated') is True), pl(usd, 2))
    return text + (' (suma niepełna)' if cell.get('incomplete') is True else '')


def different_text(record, text):
    """`text` with the note that the field is counted differently than in AO, for a native record; unchanged for AO."""
    return '%s (%s)' % (text, DIFFERENT_NOTE) if source_of(record) == 'native' else text


def epics_of_source(count, record):
    """`epiku AO`, `epików natywnych`: the history after `z` (`mediana z`)."""
    if source_of(record) == 'native':
        return plural(count, 'epiku natywnego', 'epików natywnych', 'epików natywnych')
    return plural(count, 'epiku AO', 'epików AO', 'epików AO')


def epics_text(count, record):
    """`5 epików AO`, `2 epiki natywne`: the history of a record is of its own source only."""
    if source_of(record) == 'native':
        return '%d %s' % (count, plural(count, 'epik natywny', 'epiki natywne', 'epików natywnych'))
    return '%d %s' % (count, plural(count, 'epik AO', 'epiki AO', 'epików AO'))


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
        share, mark = percent_of(item.get('share'), 1), approx(item.get('estimated') is True)
        text = '%d. `%s`: %s+%s h ponad medianę' % (len(lines) + 1, name, mark, pl(excess))
        if share is not None and item.get('share_of') in SHARE_OF:
            text += ' (%s%d%% %s)' % (mark, share, SHARE_OF[item['share_of']])
        effect = item.get('effect_h')
        if _is_number(effect):
            rule = str(item.get('effect_rule') or '').split(':')[0]
            shaky = item.get('effect_estimated') is True or item.get('estimated') is True
            text += ', potencjalnie %s−%s h (%s)' % (approx(shaky), pl(effect),
                                                      rule if RULE_PATTERN.match(rule) else 'bez reguły decyzji')
        elif isinstance(item.get('rule'), str) and RULE_PATTERN.match(item['rule']):
            text += ', reguła %s' % item['rule']
        if _is_number(item.get('repeat_h')) and item['repeat_h'] > 0:
            text += ', w tym możliwe powtórzenia: %s%s h' % (mark, pl(item['repeat_h']))
        lines.append(text)
    return lines


def _bound_note(record, name):
    """The note of a marked category of the breakdown: a lower bound, an upper bound or both."""
    lower = name in lower_bounds(record)
    upper = name in upper_bounds(record) or (name == 'idle' and bool(lower_bounds(record)))
    if not lower and not upper:
        return ''                   # estimated only because the breakdown ends at the merge (breakdown_estimated)
    return ', ' + ('dolna i górna granica' if lower and upper else 'górna granica' if upper else 'dolna granica')


def _breakdown_text(record, whole):
    parts = []
    for name in BREAKDOWN_CATEGORIES:
        value = record['breakdown'].get(name)
        if not _is_number(value):
            parts.append('%s %s' % (name, NO_DATA))
        elif value > 0:
            marked = breakdown_inexact(record, name)
            parts.append('%s %s (%s%d%%%s)' % (
                name, hours_text(value, marked), approx(marked), percent_of(value, whole) or 0,
                _bound_note(record, name) if marked else ''))
    return ', '.join(parts)


def breakdown_label(record):
    """`rozkład`, for a native record with the note: its breakdown has no limit, CI and GitHub waits (spec: M3)."""
    return different_text(record, 'rozkład')


def time_topic(record, comment):
    wall = _dict(record.get('wall'))
    to_done, to_merge = wall.get('to_done'), wall.get('to_merge')
    tasks = record.get('tasks')
    breakdown = record.get('breakdown')
    if not _is_number(to_done):
        text = 'Czas do epic_done: %s' % NO_DATA
        if record.get('breakdown_end') == 'merged_at' and isinstance(breakdown, dict) and _is_number(to_merge):
            text += " (epik zamknięty ręcznie); %s do merge'u (%s): %s" % (
                breakdown_label(record), hours_text(to_merge), _breakdown_text(record, to_merge))
        lines = [text]
    else:
        text = 'Czas do epic_done: %s' % hours_text(to_done)
        if _is_int(tasks) and tasks > 0:
            text += ' (%s na zadanie planu)' % hours_text(to_done / tasks)
        if isinstance(breakdown, dict):
            text += '; %s: %s' % (breakdown_label(record), _breakdown_text(record, to_done))
        lines = [text]
    if comment:
        lines.append("Czas do merge'u: %s, odbiór (review): %s" % (hours_text(wall.get('to_merge')),
                                                                  hours_text(wall.get('review'))))
    return [lines[0]] + ['  ' + line for line in lines[1:]]


def attempts_time_topic(record):
    data = record.get('in_attempts')
    if not isinstance(data, dict):
        return ['Czas w próbach (polecenia powłoki): %s (brak transkryptów)' % NO_DATA]
    lower = inexact(data)               # the sums cover only the attempts with a transcript: lower bounds
    covered = ''
    if lower and _count(data.get('attempts')) is not None and _count(data.get('attempts_without_transcript')) is not None:
        covered = '; transkrypty tylko %d z %d prób' % (data['attempts'] - data['attempts_without_transcript'],
                                                      data['attempts'])
    parts = []
    for name, entry in _dict(data.get('categories')).items():
        entry = _dict(entry)
        if _safe(name) and _count(entry.get('count')) and _is_number(entry.get('seconds')):
            parts.append('%s %s (%s%d)' % (name, hours_text(entry['seconds'], lower), approx(lower), entry['count']))
    lines = ['Czas w próbach (polecenia powłoki%s): ' % covered + (', '.join(parts) if parts else 'brak poleceń')]
    repeat = _dict(data.get('repeat_runs'))
    if _count(repeat.get('count')) is not None and _is_number(repeat.get('seconds')):
        lines.append('możliwe powtórzenia testów: %s%d (%s), dolna granica' % (
            approx(lower), repeat['count'], hours_text(repeat['seconds'], lower)))
    retry = _dict(data.get('api_retry'))
    if _count(retry.get('count')) is not None and _is_number(retry.get('delay_ms')):
        lines.append('ponowienia API: %s%d (łącznie %s%s s)' % (approx(lower), retry['count'], approx(lower),
                                                            pl(retry['delay_ms'] / 1000.0, 0)))
    denied = ', '.join('%s %s%d' % (tool, approx(lower), n)
                       for tool, n in sorted(_dict(data.get('permission_denials')).items())
                       if _safe(tool) and _count(n))
    lines.append('odmowy uprawnień: %s' % (denied or 'brak'))
    return [lines[0]] + ['  ' + line for line in lines[1:]]


def integration_topic(record):
    data = _dict(record.get('integration'))
    if not _is_number(data.get('work_s')):
        return ['Integracja: %s' % NO_DATA]
    lower = data.get('incomplete') is True
    text = 'Integracja: %s%s' % (hours_text(data['work_s'], lower), ' (dolna granica)' if lower else '')
    if _count(data.get('attempts')) is not None:
        text += ' w %d %s' % (data['attempts'], plural(data['attempts'], 'próbie', 'próbach', 'próbach'))
    if _count(data.get('rounds')) is not None:
        text += ', rund: %d' % data['rounds']
    return [different_text(record, text)]


def value_topic(record):
    value = _dict(record.get('value'))
    head = 'Wartość tokenów (szacunek CLI według cen API, nie koszt subskrypcji): ' if source_of(record) != 'native' \
        else 'Wartość tokenów (szacunek według cennika API, %s; nie koszt subskrypcji): ' % DIFFERENT_NOTE
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
    every = ranked('by_task')
    tasks = [(k, c) for k, c in every if _is_number(c.get('usd'))]
    if tasks:
        partial = any(not _is_number(c.get('usd')) or c.get('incomplete') is True for _, c in every)
        lines.append('najdroższe zadanie: %s %s%s' % (tasks[0][0], usd_text(tasks[0][1]),
                                                    ' (wśród zadań ze znaną wartością)' if partial else ''))
    return [lines[0]] + ['  ' + line for line in lines[1:]]


def subscription_topic(record):
    head = 'Subskrypcja (punkty procentowe okien limitu, osobno od wartości tokenów%s): ' % (
        '; ' + DIFFERENT_NOTE if source_of(record) == 'native' else '')
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
    fix = _count(data.get('fix'))
    if fix is None:
        lines.append('FIX: %s' % NO_DATA)
    elif source_of(record) == 'native':
        lines.append('FIX: %d (rundy przeglądu, %s)' % (fix, DIFFERENT_NOTE))      # the blocking reports of reviews
    else:
        lines.append('FIX: %d' % fix)
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
    return [different_text(record, text)]


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


def process_topic(record):
    """`Wersja procesu`: the expected version (`describe` and SHA), the disk (matching, or the number of differences
    and the places) and the loaded instructions (confirmed, different, unconfirmed). Only names, SHAs and numbers (the
    line also goes to the comment); a record without the field has no data."""
    process = record.get('process')
    if not isinstance(process, dict):
        return ['Wersja procesu: %s' % NO_DATA]
    unchecked = process.get('disk_checked') is False and isinstance(process.get('expected'), dict)
    if process.get('status') == 'not_checked' and not unchecked:
        return ['Wersja procesu: nie sprawdzono (zapis po fakcie)']
    if process.get('status') != 'checked' and not unchecked:
        return ['Wersja procesu: kontrola nieudana']
    expected = _dict(process.get('expected'))
    describe = expected.get('describe') if _safe(expected.get('describe')) else None
    ref = expected.get('ref') if isinstance(expected.get('ref'), str) and SHA_PATTERN.match(expected['ref']) else None
    parts = ['oczekiwana %s (%s)' % (describe or 'bez tagu', ref[:12] if ref else NO_DATA)]
    changes = [c for c in _list(process.get('changes')) if isinstance(c, dict) and c.get('kind') == 'disk'
               and c.get('informational') is not True]
    branch = len(informational_changes(process))
    branch_text = ' (zmian w gałęzi epiku %d)' % branch if branch else ''
    differs = _files_count(process.get('disk'))
    if unchecked:
        parts.append('dysk: nie sprawdzono')
    elif process.get('match') is True and not changes:
        parts.append('dysk zgodny' + branch_text)
    else:
        places = [p.get('kind') for p in _list(process.get('disk')) if isinstance(p, dict)
                  and process_version.mismatch_paths(p) and p.get('kind') in ('worktree', 'main_checkout')]
        parts.append('dysk: różnic przy starcie %d%s%s%s' % (differs, ' (%s)' % ', '.join(places) if places else '',
                                                             ', zmian w trakcie %d' % len(changes) if changes else '',
                                                             branch_text))
    summary = _dict(_dict(process.get('loaded')).get('summary'))
    if all(_count(summary.get(s)) is not None for s in process_version.STATUSES):
        epic = _count(summary.get('confirmed_epic'))
        parts.append('załadowana: potwierdzone %d%s, różne %d, bez potwierdzenia %d' % (
            summary['confirmed'], ' (w tym w wersji epiku %d)' % epic if epic else '', summary['differs'],
            summary['unconfirmed']))
    else:
        parts.append('załadowana: %s' % NO_DATA)
    return ['Wersja procesu: %s' % '; '.join(parts)]


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
        return ['Pozycja na tle mediany: brak historii (zmergowanych epików %s przed tym: 0)'
                % ('natywnych' if source_of(record) == 'native' else 'AO')]
    values = [trend_values(h, config) for h in history]
    medians = {c: median_of([v[c] for v in values]) for c in TREND_COLUMNS}
    estimated = trend_estimated(record, config)
    different = trend_different(record, config)
    median_estimated = set(estimated_list([trend_estimated(h, config) for h in history]))
    position = position_of(trend_values(record, config), medians)
    parts = []
    for column in TREND_COLUMNS:
        entry = position[column]
        if entry['diff'] is None or (column == 'review' and not comment):
            continue            # the review is known only after the merge
        parts.append('%s %s (mediana %s%s)' % (column, cell_text(entry['value'], column in estimated),
                                               cell_text(entry['median'], column in median_estimated),
                                               percent_text(entry, column in estimated or column in median_estimated)))
    text = 'Pozycja na tle mediany (%s, na zadanie planu; czasy w godzinach): %s' % (
        epics_text(len(history), record), '; '.join(parts) or NO_DATA)
    marked = [c for c in TREND_COLUMNS if c in different and not (c == 'review' and not comment)]
    if marked:
        text += ' [%s: %s]' % (DIFFERENT_NOTE, ', '.join(marked))
    return [text]


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
    lines = [different_text(record, 'Interwencje człowieka: %s' % (interventions if interventions is not None
                                                                   else NO_DATA))]
    if waits is None or not _is_number(seconds):
        lines.append('Czekanie na użytkownika: %s' % NO_DATA)
    else:
        lines.append(different_text(record, 'Czekanie na użytkownika: %d %s, razem %d min%s' % (
            waits, plural(waits, 'czekanie', 'czekania', 'czekań'), int(round(seconds / 60.0)),
            ', otwarte: %d' % human['open'] if _count(human.get('open')) else '')))
    return lines + manual_fix_lines(human.get('manual_fixes'))


def source_topic(record):
    if source_of(record) == 'native':
        return ['Źródło pomiaru: natywne (transkrypty sesji epiku); pola %s są oznaczone, historia i mediana tylko '
                'z epików natywnych' % DIFFERENT_NOTE]
    return ['Źródło pomiaru: AO (przebieg /ao-run); historia i mediana tylko z epików AO']


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
    head = 'Wąskie gardła (mediana z %d %s)' % (len(history), epics_of_source(len(history), record))
    numbered = bottleneck_lines(items)
    topics = [[head + ':'] + numbered if numbered else
              [head + ': brak pozycji z nadwyżką co najmniej %s h ponad medianę' % pl(config['bottlenecks']['min_excess_h'])]]
    topics += [source_topic(record), time_topic(record, comment), attempts_time_topic(record), integration_topic(record),
               value_topic(record), subscription_topic(record)]
    topics += [[line] for line in retries_topics(record)]
    topics += [gate_topic(record)]
    if not comment:
        topics += [human_topic(record)]
    topics += [waste_topic(record), versions_topic(record)]
    if source_of(record) == 'native':
        topics += [process_topic(record)]
    topics += [ci_topic(record)]
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
    if os.path.isdir(ao_home):
        record, errors = build_record(args.epic, ao_home, config, busy, _expand(args.night), manual_fixes, now,
                                      run=run, no_gh=args.no_gh, repo=args.repo, previous=read_previous(path),
                                      records=read_records(_expand(args.state)), ao_root=os.path.dirname(ao_home),
                                      waste=waste_of_epic(_expand(args.state), args.epic))
    else:
        # M2 of docs/features/ash-v1/spec.md: without the AO run directory the epic is native (its run file)
        run_file_path = run_path(_expand(args.state), checked_id(args.epic, 'epiku'))
        run_file = read_run_file(run_file_path)
        if run_file is None:
            raise MetricsError('brak katalogu AO %s i brak pliku przebiegu %s (metrics.py start)'
                               % (ao_home, run_file_path))
        record, errors = build_native_record(args.epic, run_file, config, _expand(args.projects), now, run=run,
                                             no_gh=args.no_gh, repo=args.repo, previous=read_previous(path),
                                             records=read_records(_expand(args.state)), manual_fixes=manual_fixes,
                                             worktree=_expand(args.worktree) if args.worktree else os.getcwd())
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
    task whose attempts all ended and all have a value), and `estimated` ({'value': kinds, 'task': kinds}: the kinds
    whose measurements include an estimated value, so their median is estimated)."""
    found = {'value': {}, 'wall': {}, 'task': {}, 'estimated': {'value': set(), 'task': set()}}
    for epic, record in records.items():
        if epic in skip:
            continue
        tasks, open_tasks = {}, set()
        for attempt in record.get('attempts') or []:
            if not isinstance(attempt, dict):
                continue
            kind = attempt.get('kind') if isinstance(attempt.get('kind'), str) else None
            if attempt.get('task') is not None and attempt.get('ended_at') is None:
                open_tasks.add((str(attempt['task']), kind))    # the sum of the task would be partial: no measurement
            if attempt.get('ended_at') is None:
                continue
            estimated = attempt.get('estimated') is True
            if _is_number(attempt.get('value')):
                found['value'].setdefault(kind, []).append(attempt['value'])
                if estimated:
                    found['estimated']['value'].add(kind)
            if _is_number(attempt.get('wall_s')):
                found['wall'].setdefault(kind, []).append(attempt['wall_s'])
            if attempt.get('task') is not None:         # a record made without task ids has no tasks to total
                tasks.setdefault((str(attempt['task']), kind), []).append((attempt.get('value'), estimated))
        for (task, kind), entries in tasks.items():
            if (task, kind) not in open_tasks and all(_is_number(v) for v, _ in entries):
                found['task'].setdefault(kind, []).append(sum(v for v, _ in entries))
                if any(e for _, e in entries):
                    found['estimated']['task'].add(kind)
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


def _of_median(ratio, what='mediany', estimated=False):
    """` (3,5× mediany)`, `≈` before the ratio when the amount or the median it is made of is estimated."""
    return '' if ratio is None else ' (%s%s× %s)' % (approx(estimated, ''), _decimal(ratio), what)


def estimated_history(history, measure, kind):
    """Whether the median of `kind` in the `measure` ('value' or 'task') of the history has an estimated value."""
    return kind in _dict(history.get('estimated')).get(measure, ())


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
            in_median = estimated_history(history, 'value', entry['kind'])
            found.append(make_alarm(
                'W1', 'W1:%s:%s:%s' % (epic_id, entry['task'], entry['number']), epic_id, entry['task'],
                entry['number'],
                '%s: %s próba %s kosztowała %s%s USD%s' % (name, _id(entry['task']), entry['number'],
                                                           approx(entry['estimated'], ''), _decimal(entry['value'], 2),
                                                           _of_median(hit[1], estimated=bool(entry['estimated'] or in_median))),
                value=entry['value'], median=hit[0], ratio=hit[1], estimated=bool(entry['estimated']),
                median_estimated=in_median))
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
            estimated = any(e['estimated'] for e in task_entries)
            in_median = estimated_history(history, 'task', kind)
            running = any(a['task_id'] == task and a['ended_at'] is None for a in run['attempts'])
            found.append(make_alarm(
                'W4', 'W4:%s:%s' % (epic_id, task), epic_id, task, None,
                '%s: %s %s %s%s USD%s' % (name, _id(task), 'zakończone próby kosztują' if running else 'kosztuje łącznie',
                                          approx(estimated, ''), _decimal(amount, 2),
                                          _of_median(hit[1], 'mediany zadań', estimated or in_median)),
                value=round(amount, 8), median=hit[0], ratio=hit[1], attempts=len(task_entries),
                estimated=estimated, median_estimated=in_median, incomplete=running))
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
    projects = argparse.ArgumentParser(add_help=False)
    projects.add_argument('--projects', default='~/.claude/projects')
    parser = argparse.ArgumentParser(description='Measurement of AO and native epic runs')
    sub = parser.add_subparsers(dest='command', required=True)
    start = sub.add_parser('start', parents=[common, projects])
    start.add_argument('epic')
    start.add_argument('--resume', action='store_true')
    start.add_argument('--at')
    start.add_argument('--worktree')
    start.add_argument('--branch')
    start.add_argument('--pr', type=_positive_int)
    start.add_argument('--tasks', type=_positive_int)
    start.add_argument('--session')
    start.add_argument('--user-dir', default='~/.claude')
    start.add_argument('--accept-process-mismatch', metavar='REASON')
    done = sub.add_parser('done', parents=[common, projects])
    done.add_argument('epic')
    done.add_argument('--at')
    done.add_argument('--worktree')
    done.add_argument('--user-dir', default='~/.claude')
    pause = sub.add_parser('pause', parents=[common, projects])
    pause.add_argument('epic')
    pause.add_argument('--from', dest='begin', required=True)
    pause.add_argument('--to', dest='end', required=True)
    pause.add_argument('--reason')
    process = sub.add_parser('process', parents=[common, projects])
    process.add_argument('epic', nargs='?')
    process.add_argument('--worktree')
    process.add_argument('--base')
    process.add_argument('--user-dir', default='~/.claude')
    process.add_argument('--accept-process-mismatch', metavar='REASON')
    process.add_argument('--session')
    epic = sub.add_parser('epic', parents=[common, projects])
    epic.add_argument('epic')
    epic.add_argument('--worktree')
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
        if args.command == 'start':
            return cmd_start(args, runner, now, sys.stdin)
        if args.command == 'done':
            return cmd_done(args, runner, now, sys.stdin)
        if args.command == 'pause':
            return cmd_pause(args)
        if args.command == 'process':
            return cmd_process(args, runner, now)
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

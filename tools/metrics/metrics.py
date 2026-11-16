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


def build_record(epic_id, ao_home, config, busy_timeout_ms):
    gaps = Gaps()
    run = load_run(ao_home, epic_id, busy_timeout_ms, gaps)
    epic = run['epic']
    started = _number(epic['created_at'])
    done = next((e['at'] for e in run['events'] if e['type'] == 'epic_done'), None)
    if done is None:
        gaps.add('done_at', 'brak zdarzenia epic_done')
    to_done = round(done - started, 3) if done is not None and started is not None else None
    final_pr = epic['final_pr_number']
    if final_pr is None:
        gaps.add('final_pr', 'epik nie ma końcowego PR')
    record = {
        'epic': epic_id,
        'final_pr': final_pr,
        'tasks': sum(1 for t in run['tasks'] if t['kind'] == 'plan'),
        'started_at': iso(started),
        'done_at': iso(done),
        'wall': {'to_done': to_done},
    }
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
                json.dump(record, f, ensure_ascii=False, sort_keys=True)
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
    record = build_record(args.epic, ao_home, config, busy)
    write_record(state_path(_expand(args.state), args.epic), record)
    print(json.dumps(record, ensure_ascii=False, sort_keys=True))
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

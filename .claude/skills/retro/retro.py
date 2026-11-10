#!/usr/bin/env python3
"""Facts for the /retro skill (retrospective after an epic). Python 3.9+, standard library only.

  retro.py collect EPIC [--out DIR] [--repo DIR] [--ao-home DIR] [--retro-home DIR] [--sweep-home DIR]
      Writes DIR/input.json (default DIR: <retro-home>/EPIC) and prints its path. input.json has the incidents of the
      whole history of AO runs (docs/features/retro/spec.md, "Incydenty"), the facts of the plan "Status"
      (`status/in-progress`, `status/last-epic`, `status/agent-issues`, `status/pending-retro`), the registers, the
      items of the open retro PR and the open issues to check duplicates against. Exit code 0 when the file was
      written, 1 (message on stderr, nothing written) when a `gh` or `git` command failed, 2 for invalid arguments.

The data of AO runs (`<ao-home>/ao-<epic>/`) is only read: `orchestrator.db` is opened read-only (SQLite URI
`mode=ro`), the other files are read as text, and `ao` is never run (`ao report` rewrites `report.md`, and every `ao`
command in a directory without a database creates a new one). `gh` and `git` go through the runner of
`next_epic.make_runner`, which tests replace with a fake one.

Paths (defaults as in the specification, each can be overridden by an option or an environment variable):
  AO_STATE_HOME     ~/.local/state            directories ao-<epic>/
  SHOP_RETRO_HOME   ~/.local/state/shop-retro registers and per-epic state.json
  SHOP_SWEEP_HOME   ~/.local/state/shop-sweep <date>-<sha7>-<epic>/report.md of /sweep

Contracts of the files in SHOP_RETRO_HOME that other commands of this script share (docs/features/retro/spec.md,
"Rejestry"): `<epic>/state.json` has `state` (running, done, skipped) and `pr`: {number, branch, items: [...]} of the
retro PR the run worked on; `observations.jsonl`, `rejected.jsonl`, `applied.jsonl` and `carried.jsonl` have one JSON
object per line.
"""
import argparse
import datetime
import hashlib
import importlib.util
import json
import os
import re
import sqlite3
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))


def _load(name):
    """A sibling skill script (ao-run) imported by path, never copied."""
    path = os.path.join(HERE, '..', 'ao-run', name + '.py')
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


ao_events = _load('ao_events')
next_epic = _load('next_epic')

SHOP = next_epic.REPOSITORY
AO_REPOSITORY = 'piotrp321/agentic-orchestrator'
# Step 12 of the plan (/sweep named after the epic) reached main in fc208156 at 2026-11-09 13:51:12 CET: older epics
# had no per-epic review, so `escaped` and `no_sweep` exist only for epics merged after it.
STEP_12_AT = datetime.datetime.fromisoformat('2026-11-09T13:51:12+01:00').timestamp()
# `pending` takes epics merged after this date: the merge of the pull request that implements step 15. The epic is
# not merged yet, so this is the start of the day it is expected on; set it to the real merge time with that PR.
RETRO_SINCE = '2026-11-10T00:00:00+00:00'
# A failed attempt that ended at most this long before a block is the cause of that block.
ATTEMPT_GAP_S = 600
TEXT_LIMIT = 1000
KNOWN_ATTEMPT_OK = 'result'


class RetroError(Exception):
    """A failure of `gh`, `git` or a file that ends the command with exit code 1."""


def default_paths():
    home = os.path.expanduser('~/.local/state')
    return {
        'ao_home': os.environ.get('AO_STATE_HOME') or home,
        'retro_home': os.environ.get('SHOP_RETRO_HOME') or os.path.join(home, 'shop-retro'),
        'sweep_home': os.environ.get('SHOP_SWEEP_HOME') or os.path.join(home, 'shop-sweep'),
    }


def cut(text, limit=TEXT_LIMIT):
    text = str(text)
    return text if len(text) <= limit else text[:limit - 1] + '…'


# ---------------------------------------------------------------------------------------------- AO data (read only)

def open_readonly(path):
    """The SQLite database in read-only mode: a write fails and no journal or WAL file is created."""
    uri = 'file:%s?mode=ro' % urllib.request.pathname2url(os.path.abspath(path))
    return sqlite3.connect(uri, uri=True)


def _columns(con, table):
    return [row[1] for row in con.execute('PRAGMA table_info("%s")' % table)]


def _select(con, table, wanted):
    """(rows as dicts, missing columns): the wanted columns present in the table; a missing table misses them all."""
    present = _columns(con, table)
    missing = [c for c in wanted if c not in present]
    if missing and not present:
        return [], list(wanted)
    use = [c for c in wanted if c in present]
    if not use:
        return [], missing
    cursor = con.execute('SELECT %s FROM "%s"' % (', '.join('"%s"' % c for c in use), table))
    return [dict(zip(use, row)) for row in cursor.fetchall()], missing


def discover_runs(ao_home):
    """Every AO run directory `ao-<x>` whose database has an `epics` row `x`: {id, run_dir, status,
    final_pr_number, created_at, gaps}. Other directories (`ao-night`, test runs, databases without the table or the
    row, unreadable files) are not epic runs."""
    runs = []
    try:
        names = sorted(os.listdir(ao_home))
    except OSError:
        return runs
    for name in names:
        run_dir = os.path.join(ao_home, name)
        db = os.path.join(run_dir, 'orchestrator.db')
        if not name.startswith('ao-') or not os.path.isfile(db):
            continue
        epic_id = name[len('ao-'):]
        try:
            con = open_readonly(db)
            try:
                rows, missing = _select(con, 'epics', ['epic_id', 'status', 'final_pr_number', 'created_at'])
            finally:
                con.close()
        except sqlite3.Error:
            continue
        row = next((r for r in rows if r.get('epic_id') == epic_id), None)
        if row is None or 'status' in missing:
            continue
        runs.append({'id': epic_id, 'run_dir': run_dir, 'status': row['status'],
                     'final_pr_number': row.get('final_pr_number'), 'created_at': row.get('created_at'),
                     'gaps': ['epics.%s' % c for c in missing if c != 'epic_id']})
    return runs


def discover_epics(ao_home):
    """The finished epics: runs in the state DONE with a final PR, by epic ID."""
    return [r for r in discover_runs(ao_home) if r['status'] == 'DONE' and r['final_pr_number'] is not None]


def read_events(path):
    """The event objects of events.jsonl, or None when the file does not exist or cannot be read."""
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except OSError:
        return None
    events = []
    for line in lines:
        try:
            event = json.loads(line)
        except ValueError:
            continue
        if isinstance(event, dict):
            events.append(event)
    return events


def read_night_decisions(path):
    """[(epoch second, text)] of night-decisions.log: `YYYY-MM-DD HH:MM:SS text` in the local time of the system."""
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except OSError:
        return []
    decisions = []
    for line in lines:
        match = re.match(r'^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(.*\S)\s*$', line)
        if not match:
            continue
        try:
            moment = time.mktime(time.strptime(match.group(1), '%Y-%m-%d %H:%M:%S'))
        except (ValueError, OverflowError):
            continue
        decisions.append((int(moment), match.group(2)))
    return decisions


def _incident(epic_id, kind, key, at, data, facts):
    return {'id': '%s/%s/%s' % (epic_id, kind, key), 'epic': epic_id, 'kind': kind, 'key': key, 'at': at,
            'data': data, 'facts': facts}


def _window_facts(window):
    start, data = window['start'], window['data']
    facts = []
    if window['type'] == 'status_changed':
        text = '%s %s (powód: %s)' % (window['task_id'], data.get('to'), data.get('reason'))
    elif window['type'] == 'epic_blocked':
        text = 'epik zablokowany (powód: %s)' % data.get('reason')
    else:
        text = 'kolejka wstrzymana (rodzaj: %s)' % data.get('kind')
    facts.append({'source': 'events.jsonl', 'at': int(start), 'text': text})
    if window['action']:
        facts.append({'source': 'events.jsonl', 'at': int(window['end']), 'text': 'human_action %s' % window['action']})
    return facts


def _text_list(value):
    return [cut(v) for v in value if isinstance(v, str)] if isinstance(value, list) else []


def ao_incidents(epic):
    """(incidents, facts) of one epic from its AO run directory: `wait`, `failed_attempt` and `repair` incidents
    (events.jsonl, night-decisions.log and the database describe one event as one incident) and the facts about data
    that is missing (`<epic>/missing/events`, `<epic>/missing/ao-data`)."""
    epic_id, run_dir = epic['id'], epic['run_dir']
    facts, gaps = [], list(epic.get('gaps') or [])
    tasks, attempts = [], []
    try:
        con = open_readonly(os.path.join(run_dir, 'orchestrator.db'))
        try:
            tasks, missing = _select(con, 'tasks', ['task_id', 'kind', 'status', 'pr_number', 'fix_count'])
            if missing:
                gaps.append('tasks.%s' % ', tasks.'.join(missing))
                tasks = []
            attempts, missing = _select(con, 'attempts', ['task_id', 'number', 'started_at', 'ended_at', 'outcome',
                                                          'exit_code'])
            if missing:
                gaps.append('attempts.%s' % ', attempts.'.join(missing))
                attempts = []
        finally:
            con.close()
    except sqlite3.Error as e:
        gaps.append('orchestrator.db: %s' % e)
    if gaps:
        facts.append({'id': '%s/missing/ao-data' % epic_id, 'text': 'brak danych AO: ' + ', '.join(gaps)})

    incidents = []
    events = read_events(os.path.join(run_dir, 'events.jsonl'))
    if events is None:
        facts.append({'id': '%s/missing/events' % epic_id, 'text': 'brak zdarzeń (events.jsonl)'})
        events = []
    windows = ao_events.waits(events)
    decisions = read_night_decisions(os.path.join(run_dir, 'night-decisions.log'))
    failed = {}
    for attempt in attempts:
        if attempt.get('outcome') not in (None, KNOWN_ATTEMPT_OK) and attempt.get('ended_at') is not None:
            failed[(attempt['task_id'], attempt['number'])] = attempt
    attached, taken = set(), set()
    for window in windows:
        key_owner = window['key'] or epic_id
        start = int(window['start'])
        end = int(window['end']) if window['end'] is not None else None
        data = window['data']
        facts_w = _window_facts(window)
        blockers = _text_list(data.get('blockers'))
        reasons = _text_list(data.get('reasons'))
        for text in blockers:
            facts_w.append({'source': 'events.jsonl', 'at': start, 'text': 'pytanie blokera: ' + text})
        wait_data = {
            'event': window['type'], 'task_id': window['key'] or data.get('task_id') or None,
            'reason': data.get('reason') or data.get('kind'), 'blockers': blockers, 'reasons': reasons,
            'start': start, 'end': end, 'open': end is None, 'action': window['action'],
            'human_action': window['action'] is not None, 'can_be_corrective': window['action'] is not None,
        }
        # the last attempt of the task that ended just before the block, if it did not succeed, is part of it
        if window['key']:
            before = [a for a in attempts if a['task_id'] == window['key'] and a.get('ended_at') is not None
                      and a['ended_at'] <= window['start'] + 1]
            if before:
                last = max(before, key=lambda a: (a['ended_at'], a['number']))
                token = (last['task_id'], last['number'])
                if token in failed and token not in taken and window['start'] - last['ended_at'] <= ATTEMPT_GAP_S:
                    taken.add(token)
                    attached.add(token)
                    facts_w.append({'source': 'orchestrator.db', 'at': int(last['ended_at']),
                                    'text': 'nieudana próba %s#%s tuż przed blokadą: outcome %s, exit_code %s'
                                            % (last['task_id'], last['number'], last['outcome'], last['exit_code'])})
                    wait_data['failed_attempt'] = {'task_id': last['task_id'], 'number': last['number'],
                                                   'outcome': last['outcome'], 'exit_code': last['exit_code']}
        incidents.append(_incident(epic_id, 'wait', '%s@%d' % (key_owner, start), start, wait_data, facts_w))
    # night decisions: into the tightest wait closed on both sides that contains the time
    leftovers = []
    closed = [(w, i) for w, i in zip(windows, incidents) if w['end'] is not None]
    for moment, text in decisions:
        inside = [(w['end'] - w['start'], n, i) for n, (w, i) in enumerate(closed)
                  if int(w['start']) <= moment <= w['end']]
        if inside:
            _, _, incident = min(inside, key=lambda t: (t[0], t[1]))
            incident['facts'].append({'source': 'night-decisions.log', 'at': moment, 'text': text})
        else:
            leftovers.append((moment, text))
    for moment, text in leftovers:
        facts.append({'id': '%s/night/%d' % (epic_id, moment), 'text': 'decyzja nocna poza oknem czekania: ' + text})

    for (task, number), attempt in sorted(failed.items()):
        if (task, number) in attached:
            continue
        at = int(attempt['ended_at'])
        incidents.append(_incident(
            epic_id, 'failed_attempt', '%s#%s' % (task, number), at,
            {'task_id': task, 'number': number, 'outcome': attempt['outcome'], 'exit_code': attempt['exit_code'],
             'can_be_corrective': False},
            [{'source': 'orchestrator.db', 'at': at,
              'text': 'próba %s#%s zakończona outcome %s, exit_code %s' % (task, number, attempt['outcome'],
                                                                          attempt['exit_code'])}]))
    for task in tasks:
        fix_count = task.get('fix_count') or 0
        if task.get('kind') != 'repair' and fix_count <= 0:
            continue
        incidents.append(_incident(
            epic_id, 'repair', task['task_id'], None,
            {'task_id': task['task_id'], 'task_kind': task.get('kind'), 'pr': task.get('pr_number'),
             'fix_count': fix_count, 'status': task.get('status'), 'can_be_corrective': True},
            [{'source': 'orchestrator.db',
              'text': 'zadanie %s (%s), PR %s, poprawek PR: %s' % (task['task_id'], task.get('kind'),
                                                                   task.get('pr_number'), fix_count)}]))
    return incidents, facts

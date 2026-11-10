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
import uuid

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


def _start_facts(kind, task_id, data, at, prefix=''):
    """The facts of one start of a wait: the event with its reason and the questions or reasons of the block."""
    if kind == 'status_changed':
        text = '%s %s (powód: %s)' % (task_id, data.get('to'), data.get('reason'))
    elif kind == 'epic_blocked':
        text = 'epik zablokowany (powód: %s)' % data.get('reason')
    else:
        text = 'kolejka wstrzymana (rodzaj: %s)' % data.get('kind')
    facts = [{'source': 'events.jsonl', 'at': at, 'text': prefix + text}]
    for question in _text_list(data.get('blockers')):
        facts.append({'source': 'events.jsonl', 'at': at, 'text': 'pytanie blokera: ' + question})
    for reason in _text_list(data.get('reasons')):
        facts.append({'source': 'events.jsonl', 'at': at, 'text': 'powód wstrzymania: ' + reason})
    return facts


def _points_at(window, task):
    """True when the data of an epic-level window name the task (its reason, questions, reasons or task_id)."""
    data = window['data']
    if data.get('task_id') == task or window.get('task_id') == task:
        return True
    texts = [str(data.get('reason') or '')] + _text_list(data.get('blockers')) + _text_list(data.get('reasons'))
    return any(task in text for text in texts)


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
    taken = set()

    def take_failed_attempt(incident, task, at):
        """The last attempt of the task that ended just before `at`, if it failed, is a fact of the wait."""
        before = [a for a in attempts if a['task_id'] == task and a.get('ended_at') is not None
                  and a['ended_at'] <= at + 1]
        if not before:
            return
        last = max(before, key=lambda a: (a['ended_at'], a['number']))
        token = (last['task_id'], last['number'])
        if token in failed and token not in taken and at - last['ended_at'] <= ATTEMPT_GAP_S:
            taken.add(token)
            incident['facts'].append({
                'source': 'orchestrator.db', 'at': int(last['ended_at']),
                'text': 'nieudana próba %s#%s tuż przed blokadą: outcome %s, exit_code %s'
                        % (last['task_id'], last['number'], last['outcome'], last['exit_code'])})
            incident['data']['failed_attempts'].append({'task_id': last['task_id'], 'number': last['number'],
                                                        'outcome': last['outcome'], 'exit_code': last['exit_code']})

    # one incident per window; every window carries the span used to place the night decisions
    wait_incidents = []
    for window in windows:
        key_owner = window['key'] or epic_id
        start = int(window['start'])
        end = int(window['end']) if window['end'] is not None else None
        data = window['data']
        wait_data = {
            'event': window['type'], 'task_id': window['key'] or data.get('task_id') or None,
            'reason': data.get('reason') or data.get('kind'), 'blockers': _text_list(data.get('blockers')),
            'reasons': _text_list(data.get('reasons')), 'start': start, 'end': end, 'open': end is None,
            'action': window['action'], 'also_actions': [], 'absorbed': [], 'failed_attempts': [],
            'human_action': window['action'] is not None, 'can_be_corrective': window['action'] is not None,
        }
        facts_w = _start_facts(window['type'], window['task_id'], data, start)
        if window['action']:
            facts_w.append({'source': 'events.jsonl', 'at': end, 'text': 'human_action %s' % window['action']})
        incident = _incident(epic_id, 'wait', '%s@%d' % (key_owner, start), start, wait_data, facts_w)
        wait_incidents.append((window, incident))
        if window['key']:
            take_failed_attempt(incident, window['key'], window['start'])

    # a start that did not open a window fell into an open one of the same key: a fact of that wait
    opened = {(w['key'], w['start'], w['type']) for w in windows}
    for event in events:
        if not ao_events._valid_time(event):
            continue
        starts, key = ao_events._wait_key(event)
        if not starts or (key, float(event['at']), event.get('type')) in opened:
            continue
        host = next(((w, i) for w, i in wait_incidents if w['key'] == key and w['start'] <= event['at']
                     and (w['end'] is None or event['at'] <= w['end'])), None)
        if host is None:
            continue
        data = event['data'] if isinstance(event.get('data'), dict) else {}
        at = int(event['at'])
        host[1]['facts'] += _start_facts(event.get('type'), event.get('task_id'), data, at,
                                         'kolejny początek czekania w otwartym oknie: ')
        host[1]['data']['absorbed'].append({'event': event.get('type'), 'at': at,
                                            'reason': data.get('reason') or data.get('kind')})
        if key:
            take_failed_attempt(host[1], key, event['at'])

    # an epic-level block of the same second that names a task is part of that task's wait
    merged, spans = set(), {}
    for window, incident in wait_incidents:
        spans[id(incident)] = [window['start'], window['end']]
    for window, incident in wait_incidents:
        if window['key'] is not None:
            continue
        for other, host in wait_incidents:
            if other['key'] is None or abs(other['start'] - window['start']) > 1 \
                    or not _points_at(window, other['key']):
                continue
            host['facts'] += incident['facts']
            host['data']['also_actions'] += [window['action']] if window['action'] else []
            host['data']['absorbed'] += incident['data']['absorbed']
            host['data']['failed_attempts'] += incident['data']['failed_attempts']
            host['data']['human_action'] = host['data']['human_action'] or incident['data']['human_action']
            host['data']['can_be_corrective'] = host['data']['can_be_corrective'] or incident['data']['can_be_corrective']
            span, mine = spans[id(host)], spans[id(incident)]
            span[0] = min(span[0], mine[0])
            span[1] = None if span[1] is None or mine[1] is None else max(span[1], mine[1])
            host['data']['start'] = int(span[0])
            host['data']['end'] = int(span[1]) if span[1] is not None else None
            host['data']['open'] = span[1] is None
            merged.add(id(incident))
            break
    incidents = [i for _, i in wait_incidents if id(i) not in merged]
    for incident in incidents:
        incident['facts'].sort(key=lambda f: f.get('at') or 0)

    # night decisions: into the tightest wait closed on both sides that contains the time
    leftovers = []
    closed = [(spans[id(i)], i) for i in incidents if spans[id(i)][1] is not None]
    for moment, text in decisions:
        inside = [(span[1] - span[0], n, i) for n, (span, i) in enumerate(closed) if int(span[0]) <= moment <= span[1]]
        if inside:
            _, _, incident = min(inside, key=lambda t: (t[0], t[1]))
            incident['facts'].append({'source': 'night-decisions.log', 'at': moment, 'text': text})
        else:
            leftovers.append((moment, text))
    for moment, text in leftovers:
        facts.append({'id': '%s/night/%d' % (epic_id, moment), 'text': 'decyzja nocna poza oknem czekania: ' + text})

    for (task, number), attempt in sorted(failed.items()):
        if (task, number) in taken:
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


# ---------------------------------------------------------------------------------------- findings of /integration

FINDING_HEADER = re.compile(r'^Znaleziska Critical i Important rundy (\d+):\s*$')
FINDING_LINE = re.compile(r'^-\s+(?P<id>\S+)\s+\[(?P<severity>Critical|Important)\]\s+(?P<title>.+?)'
                          r'\s+\((?P<files>[^()]*)\)(?:,\s*zadanie\s+(?P<task>\S+))?\s*$')
FINDING_LINE_NO_FILES = re.compile(r'^-\s+(?P<id>\S+)\s+\[(?P<severity>Critical|Important)\]\s+(?P<title>.+?)'
                                   r'(?:,\s*zadanie\s+(?P<task>\S+))?\s*$')


def fingerprint_parts(severity, title, files):
    """severity, the title in lower case without backticks and with collapsed white space, the sorted files: the
    fingerprint of a finding in .claude/skills/integration/integration_result.py (check_findings) plus the removal of
    backticks, which the AO report writes differently from round to round. That function builds its tuple inline and
    cannot be imported, so the recipe is repeated here."""
    return severity, ' '.join(title.replace('`', '').lower().split()), tuple(sorted(files))


def fingerprint(severity, title, files):
    return hashlib.sha256(json.dumps(fingerprint_parts(severity, title, files), ensure_ascii=False)
                          .encode('utf-8')).hexdigest()[:12]


def parse_findings(text):
    """[{round, id, severity, title, files, task}] from the sections `Znaleziska Critical i Important rundy N:` of
    an AO report; the Minor findings are not read."""
    findings, current = [], None
    for line in text.splitlines():
        header = FINDING_HEADER.match(line)
        if header:
            current = int(header.group(1))
            continue
        if current is None:
            continue
        if not line.startswith('-'):
            current = None
            continue
        match = FINDING_LINE.match(line)
        files = None
        if match:
            files = [f.strip() for f in match.group('files').split(',') if f.strip()]
        else:
            match = FINDING_LINE_NO_FILES.match(line)
            files = []
        if match:
            findings.append({'round': current, 'id': match.group('id'), 'severity': match.group('severity'),
                             'title': match.group('title'), 'files': files, 'task': match.group('task')})
    return findings


def finding_incidents(epic_id, text):
    """One `finding` incident per fingerprint, whatever the number of rounds it appeared in."""
    groups = {}
    for finding in parse_findings(text or ''):
        key = fingerprint(finding['severity'], finding['title'], finding['files'])
        groups.setdefault(key, []).append(finding)
    incidents = []
    for key, items in groups.items():
        first = items[0]
        facts = [{'source': 'report.md',
                  'text': 'runda %d: %s [%s] %s (%s)%s' % (f['round'], f['id'], f['severity'], f['title'],
                                                          ', '.join(f['files']),
                                                          ', zadanie %s' % f['task'] if f['task'] else '')}
                 for f in items]
        data = {'severity': first['severity'], 'title': first['title'], 'files': sorted(first['files']),
                'task': next((f['task'] for f in items if f['task']), None),
                'rounds': sorted({f['round'] for f in items}), 'finding_ids': [f['id'] for f in items],
                'can_be_corrective': False}
        incidents.append(_incident(epic_id, 'finding', key, None, data, facts))
    return incidents


# ----------------------------------------------------------------------------------- measurement comment (manual fixes)

MEASUREMENT_LABELS = ('Interwencje człowieka', 'Czekanie na użytkownika', 'Ręczne poprawki', 'Praca użytkownika',
                      'Przebieg', 'Zgłoszenia', 'Follow-up', 'Pomiar')
MANUAL_LINE = re.compile(r'Ręczne poprawki:\s*(\d+)(.*)$', re.IGNORECASE)
LABEL_LIKE = re.compile(r'^[A-ZŁŚŻŹĆĄĘÓŃ][^:.,]{0,30}:\s')
PR_ITEM = re.compile(r'^PR\s*#(\d+)\s*[:\-–—]?\s*(.*)$')


def _bullet(line):
    match = re.match(r'^(\s*)[-*]\s+(.*\S)\s*$', line)
    return (match.group(1), match.group(2)) if match else None


def parse_measurement(body):
    """The numbers of a measurement comment in an epic issue (section 7 of /ao-run): interventions, waits (count and
    minutes) and the manual fixes: `manual_count` (N of `Ręczne poprawki: N`, None without that line) and
    `manual_items` [{pr, text}]. The items are the lines `- PR #<nr>: <text>` or `- <text>` after the line (new
    format) or, when there are none, the text in parentheses after N with the PR numbers named in it (old format)."""
    result = {'interventions': None, 'waits': None, 'wait_min': None, 'manual_count': None, 'manual_items': [],
              'format': None, 'ambiguous': False}
    match = re.search(r'Interwencje człowieka:\s*(\d+)', body)
    if match:
        result['interventions'] = int(match.group(1))
    match = re.search(r'(\d+)\s+czeka\w*,\s*(?:razem\s+)?(\d+)\s*min', body)
    if match:
        result['waits'], result['wait_min'] = int(match.group(1)), int(match.group(2))
    lines = body.splitlines()
    for index, line in enumerate(lines):
        manual = MANUAL_LINE.search(line)
        if not manual:
            continue
        result['manual_count'] = int(manual.group(1))
        items, loose = [], 0
        for follow in lines[index + 1:]:
            bullet = _bullet(follow)
            if bullet is None:
                break
            indent, text = bullet
            pr = PR_ITEM.match(text)
            if pr:
                items.append({'pr': int(pr.group(1)), 'text': pr.group(2) or text})
            elif indent:
                items.append({'pr': None, 'text': text})
            elif LABEL_LIKE.match(text) or any(text.startswith(label) for label in MEASUREMENT_LABELS):
                break   # the next line of the measurement comment
            elif loose < result['manual_count']:
                loose += 1
                items.append({'pr': None, 'text': text})
            else:
                break
        if items:
            result['format'], result['manual_items'] = 'new', items
        else:
            paren = re.search(r'\((.*)\)', manual.group(2))
            text = paren.group(1).strip() if paren else ''
            result['format'] = 'old'
            if text:
                numbers = []
                for number in re.findall(r'#(\d+)', text):
                    if int(number) not in numbers:
                        numbers.append(int(number))
                if len(numbers) > result['manual_count']:
                    # the PRs are mentions: which of them is a manual fix cannot be told
                    result['manual_items'] = [{'pr': None, 'text': text}]
                    result['ambiguous'] = True
                else:
                    result['manual_items'] = [{'pr': n, 'text': text} for n in numbers]
                    if result['manual_count'] > len(numbers):
                        result['manual_items'].append({'pr': None, 'text': text})
        break
    return result


def pick_measurement(comments):
    """The parsed last comment with a `Ręczne poprawki: N` line, or None."""
    for body in reversed([c for c in comments if isinstance(c, str)]):
        parsed = parse_measurement(body)
        if parsed['manual_count'] is not None:
            return parsed
    return None


def manual_incidents(epic_id, measurement):
    """(incidents, facts) for the manual fixes of a parsed measurement: one incident per PR number
    (`pr<number>`), the items without a PR together as `other`; a count that differs from the items is the fact
    `<epic>/mismatch/manual_fix` (niezgodna liczba), not an error."""
    if measurement is None:
        return [], [{'id': '%s/missing/measurement' % epic_id,
                     'text': 'brak komentarza z pomiarem (Ręczne poprawki) w issue epiku'}]
    by_key = {}
    for item in measurement['manual_items']:
        key = 'pr%d' % item['pr'] if item['pr'] is not None else 'other'
        by_key.setdefault(key, []).append(item)
    incidents = []
    for key in sorted(by_key):
        items = by_key[key]
        texts = [i['text'] for i in items]
        incidents.append(_incident(epic_id, 'manual_fix', key, None,
                                   {'pr': items[0]['pr'], 'items': texts, 'can_be_corrective': True},
                                   [{'source': 'issue comment', 'text': cut(t)} for t in texts]))
    facts = []
    count = measurement['manual_count']
    listed = len(measurement['manual_items'])
    if measurement['format'] == 'old':
        # the old format has one sentence for all fixes: it names PRs, the rest is one fix per missing count
        mismatch = measurement.get('ambiguous') or (count < listed) or (count > 0 and listed == 0)
    else:
        mismatch = count != listed
    if mismatch:
        what = ('wymienia więcej numerów PR niż poprawek' if measurement.get('ambiguous')
                else 'opisuje %d' % listed)
        facts.append({'id': '%s/mismatch/manual_fix' % epic_id,
                      'text': 'niezgodna liczba: komentarz z pomiarem podaje %d ręcznych poprawek, a %s'
                              % (count, what)})
    return incidents, facts


# ------------------------------------------------------------------------------------------------ /sweep reports

def find_sweep_report(sweep_home, epic_id):
    """The text of the newest `<date>-<sha7>-<epic>/report.md` in sweep_home, or None."""
    pattern = re.compile(r'^\d{4}-\d{2}-\d{2}-[0-9a-f]{7}-%s$' % re.escape(epic_id))
    try:
        names = sorted(n for n in os.listdir(sweep_home) if pattern.match(n))
    except OSError:
        return None
    for name in reversed(names):
        try:
            with open(os.path.join(sweep_home, name, 'report.md'), encoding='utf-8', errors='replace') as f:
                return f.read()
        except OSError:
            continue
    return None


def parse_sweep(text):
    """[{number, title, severity, triage}] from the sections `## Propozycje` and `## Triage` of a /sweep report."""
    proposals, triage, section, current = [], {}, None, None
    for line in text.splitlines():
        if line.startswith('## '):
            section, current = line[3:].strip().lower(), None
            continue
        if section == 'propozycje':
            heading = re.match(r'^###\s+(\d+)\.\s+(.*\S)\s*$', line)
            if heading:
                current = {'number': int(heading.group(1)), 'title': heading.group(2), 'severity': None,
                           'triage': None}
                proposals.append(current)
                continue
            field = re.match(r'^-\s*Waga:\s*(\S.*?)\s*$', line)
            if field and current is not None:
                current['severity'] = field.group(1)
        elif section == 'triage':
            match = re.match(r'^-?\s*(Założone|Odrzucone|Bez decyzji):\s*(.*)$', line)
            if not match:
                continue
            label, rest = match.group(1), match.group(2)
            if label == 'Założone':
                for number, issue in re.findall(r'(?:propozycja\s+)?(\d+)\s*→\s*#(\d+)', rest):
                    triage[int(number)] = 'założone #%s' % issue
            else:
                names = 'odrzucone' if label == 'Odrzucone' else 'bez decyzji'
                for number in re.findall(r'(?<![#\d])(\d+)(?!\d)', re.sub(r'\([^)]*\)', '', rest)):
                    triage[int(number)] = names
    for proposal in proposals:
        proposal['triage'] = triage.get(proposal['number'])
    return proposals


def sweep_incidents(epic_id, merged_at, text):
    """(incidents, facts) of the /sweep review of an epic: `escaped` per proposal of its report, `no_sweep` when
    there is no report; both only for an epic merged after step 12 (STEP_12_AT)."""
    facts = []
    if text is None:
        facts.append({'id': '%s/missing/sweep' % epic_id, 'text': 'brak raportu /sweep z nazwą epiku'})
    if merged_at is None or merged_at <= STEP_12_AT:
        return [], facts
    if text is None:
        incident = _incident(epic_id, 'no_sweep', 'sweep', None, {'merged_at': int(merged_at)},
                             [{'source': 'sweep-home', 'text': 'brak raportu /sweep; merge epiku %s'
                                                              % datetime.datetime.fromtimestamp(merged_at).isoformat()}])
        return [incident], facts
    incidents = []
    for proposal in parse_sweep(text):
        incidents.append(_incident(
            epic_id, 'escaped', str(proposal['number']), None,
            {'title': proposal['title'], 'severity': proposal['severity'], 'triage': proposal['triage']},
            [{'source': 'sweep report', 'text': '%d. %s (waga %s, triage: %s)'
                                               % (proposal['number'], proposal['title'], proposal['severity'],
                                                  proposal['triage'] or 'brak')}]))
    return incidents, facts


# ------------------------------------------------------------------------------ "Decyzje bez pytania" of task PRs

def unasked_decisions(body):
    """[(position, text)] of the section `Decyzje bez pytania` of a PR description. A decision is an item of the top
    level list (no indentation, numbered or bulleted); indented lines, also indented bullets, continue the current
    item. A numbered item keeps its number, a bullet takes the next ordinal among the items; a number that was
    already used (also by a bullet) is replaced by the ordinal, so the positions of one description are unique."""
    lines = (body or '').splitlines()
    start = next((i for i, l in enumerate(lines) if re.match(r'^#{1,6}\s*decyzje bez pytania\s*$', l, re.I)), None)
    if start is None:
        return []
    items, used, current, blank = [], set(), None, False
    for line in lines[start + 1:]:
        if re.match(r'^#{1,6}\s', line):
            break
        if not line.strip():
            blank = True
            continue
        numbered = re.match(r'^(\d+)[.)]\s+(.*\S)\s*$', line)
        bullet = re.match(r'^[-*]\s+(.*\S)\s*$', line)
        if numbered or bullet:
            position = int(numbered.group(1)) if numbered else len(items) + 1
            while position in used:
                position = len(items) + 1
                while position in used:
                    position += 1
            used.add(position)
            current = [position, (numbered or bullet).group(2 if numbered else 1)]
            items.append(current)
        elif current is not None and (line[0] in ' \t' or not blank):
            current[1] += ' ' + line.strip()
        else:
            current = None
        blank = False
    return [(position, text) for position, text in items]


def unasked_incidents(epic_id, prs):
    incidents = []
    for pr in prs:
        number = pr.get('number')
        for position, text in unasked_decisions(pr.get('body')):
            incidents.append(_incident(
                epic_id, 'unasked', 'pr%s#%d' % (number, position), None,
                {'pr': number, 'position': position, 'text': cut(text, 500), 'title': pr.get('title')},
                [{'source': 'pr %s' % number, 'text': cut(text, 500)}]))
    return incidents


# ---------------------------------------------------------------------------------------------- GitHub and registers

def gh_json(run, args):
    out = run(args)
    try:
        return json.loads(out)
    except ValueError:
        raise next_epic.CommandError(args, 'output is not valid JSON')


def gh_list(run, args):
    value = gh_json(run, args)
    if not isinstance(value, list):
        raise next_epic.CommandError(args, 'unexpected JSON shape')
    return [item for item in value if isinstance(item, dict)]


def parse_time(value):
    """An ISO 8601 time of `gh` (`2026-11-10T09:00:00Z`) as epoch seconds, or None."""
    if not isinstance(value, str):
        return None
    try:
        return datetime.datetime.fromisoformat(value.replace('Z', '+00:00')).timestamp()
    except ValueError:
        return None


def final_pr(run, number):
    """(state, merged_at epoch or None) of a final PR; the merge date counts only in the state MERGED."""
    args = ['gh', 'pr', 'view', str(number), '--repo', SHOP, '--json', 'state,mergedAt']
    data = gh_json(run, args)
    if not isinstance(data, dict):
        raise next_epic.CommandError(args, 'unexpected JSON shape')
    state = data.get('state')
    return state, parse_time(data.get('mergedAt')) if state == 'MERGED' else None


def read_jsonl(path):
    rows = []
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except OSError:
        return rows
    for line in lines:
        try:
            row = json.loads(line)
        except ValueError:
            continue
        if isinstance(row, dict):
            rows.append(row)
    return rows


def read_state(retro_home, epic_id):
    """The state.json of /retro for an epic ({} when there is none or it cannot be read)."""
    try:
        with open(os.path.join(retro_home, epic_id, 'state.json'), encoding='utf-8') as f:
            state = json.load(f)
    except (OSError, ValueError):
        return {}
    return state if isinstance(state, dict) else {}


def read_registers(retro_home):
    return {name: read_jsonl(os.path.join(retro_home, name + '.jsonl'))
            for name in ('observations', 'rejected', 'applied', 'carried')}


def pending_epics(epics, retro_home):
    """The epics whose final PR was merged after RETRO_SINCE and whose retro is neither `done` nor `skipped`, from
    the oldest merge. `epics` are the entries of input.json (`id`, `merged_at`)."""
    since = parse_time(RETRO_SINCE)
    waiting = []
    for epic in epics:
        if epic['merged_at'] is None or since is None or epic['merged_at'] <= since:
            continue
        if read_state(retro_home, epic['id']).get('state') in ('done', 'skipped'):
            continue
        waiting.append(epic)
    return [e['id'] for e in sorted(waiting, key=lambda e: (e['merged_at'], e['id']))]


def open_retro_pr(run, retro_home):
    """{number, branch, items} of the open pull request whose branch starts with `retro/`, else None. The items are
    those of the `pr.items` of every state.json whose `pr.number` is that PR."""
    prs = gh_list(run, ['gh', 'pr', 'list', '--repo', SHOP, '--state', 'open', '--json', 'number,headRefName',
                        '--limit', '100'])
    found = next((p for p in prs if str(p.get('headRefName', '')).startswith('retro/')), None)
    if found is None:
        return None
    items = []
    try:
        names = sorted(os.listdir(retro_home))
    except OSError:
        names = []
    for name in names:
        pr = read_state(retro_home, name).get('pr')
        if isinstance(pr, dict) and pr.get('number') == found['number'] and isinstance(pr.get('items'), list):
            for item in pr['items']:
                if isinstance(item, dict):
                    items.append(dict(item, epic=item.get('epic', name)))
    return {'number': found['number'], 'branch': found['headRefName'], 'items': items}


def blocked_in_runs(ao_home, in_progress):
    """The epics in progress with a BLOCKED epic or tasks in their AO database: [{epic, epic_status, tasks}]."""
    blocked = []
    for run in discover_runs(ao_home):
        if run['status'] == 'DONE' or run['id'] not in in_progress:
            continue
        tasks = []
        try:
            con = open_readonly(os.path.join(run['run_dir'], 'orchestrator.db'))
            try:
                rows, missing = _select(con, 'tasks', ['task_id', 'status', 'blockers'])
            finally:
                con.close()
        except sqlite3.Error:
            rows, missing = [], ['tasks']
        for row in rows:
            if row.get('status') != 'BLOCKED':
                continue
            try:
                questions = json.loads(row.get('blockers') or '[]')
            except ValueError:
                questions = [row.get('blockers')]
            if not isinstance(questions, list):
                questions = [questions]
            tasks.append({'task_id': row['task_id'], 'status': 'BLOCKED',
                          'blockers': [cut(q) for q in questions if isinstance(q, str)]})
        if run['status'] == 'BLOCKED' or tasks:
            blocked.append({'epic': run['id'], 'epic_status': run['status'], 'tasks': tasks})
    return blocked


KIND_ORDER = ('wait', 'failed_attempt', 'finding', 'repair', 'manual_fix', 'escaped', 'no_sweep', 'unasked')


def epic_title_id(title):
    return (title or '').partition(':')[0].strip().lower()


def collect(epic_id, paths, run):
    """The contents of input.json for the retrospective of epic_id (see the module docstring)."""
    epics = discover_epics(paths['ao_home'])
    if epic_id not in [e['id'] for e in epics]:
        raise ValueError('ao-%s is not the run of a finished epic (DONE with a final PR) in %s'
                         % (epic_id, paths['ao_home']))
    commit = run(['git', 'rev-parse', 'origin/main']).strip()
    issues = {}
    for issue in gh_list(run, ['gh', 'issue', 'list', '--repo', SHOP, '--label', 'epic', '--state', 'all', '--json',
                               'number,title,comments', '--limit', '200']):
        comments = [c.get('body') for c in issue.get('comments') or [] if isinstance(c, dict)]
        issues.setdefault(epic_title_id(issue.get('title')), {'number': issue.get('number'), 'comments': comments})

    entries, incidents, facts, measurements = [], [], [], {}
    for epic in epics:
        name = epic['id']
        state, merged_at = final_pr(run, epic['final_pr_number'])
        entries.append({'id': name, 'final_pr': epic['final_pr_number'], 'pr_state': state, 'merged_at': merged_at,
                        'created_at': epic['created_at'], 'issue': (issues.get(name) or {}).get('number')})
        if state != 'MERGED':
            facts.append({'id': '%s/missing/merge' % name,
                          'text': 'końcowy PR #%s nie jest zmergowany (stan %s)' % (epic['final_pr_number'], state)})
        found, missing = ao_incidents(epic)
        found += finding_incidents(name, report_text(epic, facts))
        facts += missing
        issue = issues.get(name)
        measurement = pick_measurement(issue['comments']) if issue else None
        measurements[name] = measurement
        manual, manual_facts = manual_incidents(name, measurement)
        found += manual
        facts += manual_facts
        sweep, sweep_facts = sweep_incidents(name, merged_at, find_sweep_report(paths['sweep_home'], name))
        found += sweep
        facts += sweep_facts
        if name == epic_id:
            task_prs = gh_list(run, ['gh', 'pr', 'list', '--repo', SHOP, '--base', 'epic/%s' % name, '--state',
                                     'merged', '--json', 'number,title,body', '--limit', '200'])
            found += unasked_incidents(name, task_prs)
        incidents += sorted(found, key=lambda i: (KIND_ORDER.index(i['kind']), i['at'] or 0, i['key']))
    entries.sort(key=lambda e: (e['merged_at'] is None, e['merged_at'] or 0, e['created_at'] or 0, e['id']))
    order = {e['id']: n for n, e in enumerate(entries)}
    incidents.sort(key=lambda i: order[i['epic']])   # stable: keeps the order inside an epic

    board = next_epic.board_epics(run)
    running = [e for e in board if e['state'] in ('in_progress', 'prepared')]
    merged = [e for e in entries if e['merged_at'] is not None]
    last = max(merged, key=lambda e: (e['merged_at'], e['id'])) if merged else None
    last_numbers = (measurements.get(last['id']) or {}) if last else {}
    status = [
        {'id': 'status/in-progress',
         'epics': [{'id': e['id'], 'issue': e['number'], 'title': e['title'], 'state': e['state']} for e in running],
         'blocked': blocked_in_runs(paths['ao_home'], {e['dir'] for e in running if e['state'] == 'in_progress'})},
        {'id': 'status/last-epic', 'epic': last['id'] if last else None, 'pr': last['final_pr'] if last else None,
         'merged_at': last['merged_at'] if last else None, 'issue': last['issue'] if last else None,
         'interventions': last_numbers.get('interventions'), 'waits': last_numbers.get('waits'),
         'wait_min': last_numbers.get('wait_min'), 'manual_fixes': last_numbers.get('manual_count'),
         'measurement': bool(last_numbers)},
    ]
    agent = gh_list(run, ['gh', 'issue', 'list', '--repo', SHOP, '--label', 'agent', '--state', 'all', '--json',
                          'number,state', '--limit', '1000'])
    status.append({'id': 'status/agent-issues', 'open': sum(1 for i in agent if i.get('state') == 'OPEN'),
                   'closed': sum(1 for i in agent if i.get('state') == 'CLOSED')})
    status.append({'id': 'status/pending-retro', 'epics': pending_epics(entries, paths['retro_home'])})

    registers = read_registers(paths['retro_home'])
    retro_pr = open_retro_pr(run, paths['retro_home'])
    open_issues = {
        'ao': gh_list(run, ['gh', 'issue', 'list', '--repo', AO_REPOSITORY, '--state', 'open', '--json',
                            'number,title,body,labels', '--limit', '200']),
        'shop': gh_list(run, ['gh', 'issue', 'list', '--repo', SHOP, '--state', 'open', '--label', 'tech-debt',
                              '--label', 'agent', '--json', 'number,title,body,labels', '--limit', '200']),
    }
    for items in open_issues.values():
        for item in items:
            item['body'] = cut(item.get('body') or '', 2000)
    return {'schema': 1, 'epic': epic_id, 'generated_at': int(time.time()), 'commit': commit, 'epics': entries,
            'incidents': incidents, 'facts': facts, 'status': status, 'registers': registers,
            'open_retro_pr': retro_pr, 'open_issues': open_issues}


def report_text(epic, facts):
    """The text of the saved AO report of the epic; a missing file adds the fact `<epic>/missing/report`."""
    path = os.path.join(epic['run_dir'], 'epics', epic['id'], 'report.md')
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            return f.read()
    except OSError:
        facts.append({'id': '%s/missing/report' % epic['id'], 'text': 'brak raportu AO (epics/%s/report.md)' % epic['id']})
        return ''


def write_json(directory, name, value):
    os.makedirs(directory, exist_ok=True)
    path = os.path.join(directory, name)
    temporary = path + '.tmp'
    with open(temporary, 'w', encoding='utf-8') as f:
        json.dump(value, f, ensure_ascii=False, indent=2)
        f.write('\n')
    os.replace(temporary, path)
    return path


# -------------------------------------------------------------------------------------------- registers and state

class Refusal(Exception):
    """A refusal that ends the command with exit code 2: bad arguments, a retro in a state that forbids the command,
    another run's lock, a proposal that does not pass `validate`."""


LOCK_MAX_AGE_S = 6 * 3600
DEFER_REASON_LIMIT = 200
FINISHED_STATES = ('done', 'skipped')


def now():
    return int(time.time())


def check_epic_name(name):
    if not isinstance(name, str) or not EPIC_NAME.match(name):
        raise Refusal('invalid epic name %r (allowed: ^[a-z0-9][a-z0-9-]*$)' % (name,))
    return name


def state_dir(retro_home, epic_id):
    return os.path.join(retro_home, epic_id)


def save_state(retro_home, epic_id, state):
    write_json(state_dir(retro_home, epic_id), 'state.json', state)


def open_epic_state(retro_home, epic_id, command):
    """The state.json of an epic for a command that changes it; a finished retro (`done`, `skipped`) is refused."""
    check_epic_name(epic_id)
    state = read_state(retro_home, epic_id)
    if state.get('state') in FINISHED_STATES:
        raise Refusal('retro of %s is %s: `%s` refused' % (epic_id, state['state'], command))
    return state


def clean_reason(reason, limit=None):
    reason = ' '.join((reason or '').split())
    if not reason:
        raise Refusal('a non-empty --reason is required')
    return cut(reason, limit) if limit else reason


def write_jsonl(path, rows):
    """The whole file again, atomically."""
    os.makedirs(os.path.dirname(path), exist_ok=True)
    temporary = path + '.tmp'
    with open(temporary, 'w', encoding='utf-8') as f:
        for row in rows:
            f.write(json.dumps(row, ensure_ascii=False) + '\n')
    os.replace(temporary, path)


def append_jsonl(path, rows):
    """One write per row, so a row is never split."""
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'a', encoding='utf-8') as f:
        for row in rows:
            f.write(json.dumps(row, ensure_ascii=False) + '\n')


def register_path(retro_home, name):
    return os.path.join(retro_home, name + '.jsonl')


def cmd_defer(args):
    state = open_epic_state(args.retro_home, args.epic, 'defer')
    reason = clean_reason(args.reason, DEFER_REASON_LIMIT)
    state['deferrals'] = list(state.get('deferrals') or []) + [{'at': now(), 'reason': reason}]
    save_state(args.retro_home, args.epic, state)
    return {'epic': args.epic, 'attempts': len(state['deferrals']), 'reason': reason}


def cmd_skip(args):
    state = open_epic_state(args.retro_home, args.epic, 'skip')
    reason = clean_reason(args.reason)
    state.update({'state': 'skipped', 'reason': reason, 'skipped_at': now()})
    save_state(args.retro_home, args.epic, state)
    return {'epic': args.epic, 'state': 'skipped', 'reason': reason}


def cmd_pending(args, run):
    """[{epic, merged_at, attempts, reason}] from the oldest merge: the epics of `pending_epics`, with the number of
    deferrals and the last reason. Epics with a finished retro cost no `gh` call."""
    entries = []
    for epic in discover_epics(args.ao_home):
        if read_state(args.retro_home, epic['id']).get('state') in FINISHED_STATES:
            continue
        entries.append({'id': epic['id'], 'merged_at': final_pr(run, epic['final_pr_number'])[1]})
    merged = {e['id']: e['merged_at'] for e in entries}
    result = []
    for epic_id in pending_epics(entries, args.retro_home):
        deferrals = read_state(args.retro_home, epic_id).get('deferrals') or []
        result.append({'epic': epic_id, 'merged_at': int(merged[epic_id]), 'attempts': len(deferrals),
                       'reason': deferrals[-1].get('reason') if deferrals else None})
    return result


# ------------------------------------------------------------------------------------------------------------ lock

def lock_path(retro_home):
    return os.path.join(retro_home, 'lock')


def read_lock(retro_home):
    """The lock as a dict, None when there is none, {} when it cannot be read (a stale one)."""
    try:
        with open(lock_path(retro_home), encoding='utf-8') as f:
            lock = json.load(f)
    except FileNotFoundError:
        return None
    except (OSError, ValueError):
        return {}
    return lock if isinstance(lock, dict) else {}


def run_id_of(args):
    return args.run_id or os.environ.get('RETRO_RUN_ID') or ''


def cmd_lock(args):
    """Takes the lock for the epic and marks its state `running`. Returns {run_id, epic, at, warning}; another run's
    lock younger than 6 h is a refusal, an older or unreadable one is taken over with a `warning`."""
    state = open_epic_state(args.retro_home, args.epic, 'lock')
    run_id = run_id_of(args) or uuid.uuid4().hex[:12]
    lock = {'run_id': run_id, 'epic': args.epic, 'at': now()}
    warning = None
    os.makedirs(args.retro_home, exist_ok=True)
    for _ in range(2):
        try:
            descriptor = os.open(lock_path(args.retro_home), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o644)
        except FileExistsError:
            other = read_lock(args.retro_home)
            if other is None:
                continue   # removed between the two calls
            age = now() - other['at'] if isinstance(other.get('at'), (int, float)) else None
            if other.get('run_id') != run_id:
                if age is not None and age < LOCK_MAX_AGE_S:
                    raise Refusal('the lock of run %s (epic %s) is %d min old, younger than 6 h'
                                  % (other.get('run_id'), other.get('epic'), age // 60))
                warning = ('took over the lock of run %s (epic %s), %s'
                           % (other.get('run_id'), other.get('epic'),
                              'older than 6 h' if age is not None else 'unreadable'))
            write_json(args.retro_home, 'lock', lock)
            break
        else:
            with os.fdopen(descriptor, 'w', encoding='utf-8') as f:
                json.dump(lock, f)
                f.write('\n')
            break
    else:
        raise RetroError('could not take the lock in %s' % args.retro_home)
    state.update({'state': 'running', 'started_at': lock['at']})
    save_state(args.retro_home, args.epic, state)
    return dict(lock, warning=warning)


def cmd_unlock(args):
    """Removes the lock of the run (`--run-id`); `--done` first marks the epic of the lock `done`, without it a
    `running` state is cleared. Another run's lock stays and is a refusal."""
    run_id = run_id_of(args)
    if not run_id:
        raise Refusal('--run-id (or RETRO_RUN_ID) is required')
    lock = read_lock(args.retro_home)
    if lock is None:
        if args.done:
            raise Refusal('there is no lock to mark the epic done for')
        return {'unlocked': False}
    if lock.get('run_id') != run_id:
        raise Refusal('the lock belongs to run %s, not %s' % (lock.get('run_id'), run_id))
    epic_id = lock.get('epic')
    if isinstance(epic_id, str) and EPIC_NAME.match(epic_id):
        state = read_state(args.retro_home, epic_id)
        if args.done:
            state.update({'state': 'done', 'done_at': now()})
            save_state(args.retro_home, epic_id, state)
        elif state.get('state') == 'running':
            del state['state']
            save_state(args.retro_home, epic_id, state)
    os.remove(lock_path(args.retro_home))
    return {'unlocked': True, 'epic': epic_id}


# -------------------------------------------------------------------------------------------------------- validate

PLAN_PATH = 'docs/features/agentic-software-house/plan.md'
ITEM_KINDS = ('fix', 'issue', 'status')
LIMITS = {'fix': 5, 'issue': 3}
CLASSES = ('corrective', 'planned', 'product')
ALLOWED_FILES = [re.compile(pattern) for pattern in (
    r'CLAUDE\.md', r'apps/[^/]+/CLAUDE\.md', r'\.claude/skills/[^/]+/SKILL\.md', r'\.claude/agents/[^/]+\.md',
    r'docs/features/(?:ao-run|prepare-epic|groom|agent-tester-ux|retro)/spec\.md')]


def allowed_file(path):
    """A file a process fix may change: the list of docs/features/retro/spec.md, "Walidacja"."""
    if not isinstance(path, str) or any(ord(c) < 32 for c in path):
        return False
    if any(part in ('', '.', '..') for part in path.split('/')):
        return False
    return any(pattern.fullmatch(path) for pattern in ALLOWED_FILES)


def filled(value):
    return isinstance(value, str) and bool(value.strip())


def id_list(value):
    """The list of non-empty strings without repeats, or None when the value is not such a list."""
    if not isinstance(value, list) or not value or not all(filled(v) for v in value):
        return None
    return list(dict.fromkeys(value))


def can_be_corrective(incident):
    """The condition of the script for a corrective incident: `wait` with a human action, `manual_fix`, `repair`."""
    data = incident.get('data') if isinstance(incident.get('data'), dict) else {}
    if incident.get('kind') in ('manual_fix', 'repair'):
        return True
    return incident.get('kind') == 'wait' and bool(data.get('human_action') or data.get('can_be_corrective'))


class Validation:
    def __init__(self, data, repo):
        self.errors = []
        self.incidents = {i['id']: i for i in data.get('incidents') or [] if isinstance(i, dict) and 'id' in i}
        self.epics = {e['id']: e for e in data.get('epics') or [] if isinstance(e, dict) and 'id' in e}
        self.status_ids = {s['id'] for s in data.get('status') or [] if isinstance(s, dict) and 'id' in s}
        registers = data.get('registers') if isinstance(data.get('registers'), dict) else {}
        self.rejected = [r for r in registers.get('rejected') or [] if isinstance(r, dict)]
        self.applied = [r for r in registers.get('applied') or [] if isinstance(r, dict)]
        pr = data.get('open_retro_pr') if isinstance(data.get('open_retro_pr'), dict) else {}
        self.open_items = [i for i in pr.get('items') or [] if isinstance(i, dict)]
        issues = data.get('open_issues') if isinstance(data.get('open_issues'), dict) else {}
        self.open_issues = {repo_name: {i.get('number') for i in issues.get(repo_name) or [] if isinstance(i, dict)}
                            for repo_name in ('ao', 'shop')}
        self.repo = repo
        self.input = data

    def add(self, item, code, message):
        self.errors.append({'item': item, 'code': code, 'message': message})

    # -- the file
    def check(self, proposals):
        if not isinstance(proposals, dict):
            self.add(None, 'proposals', 'proposals.json must be a JSON object')
            return
        if proposals.get('schema') != 1:
            self.add(None, 'schema', 'schema must be 1')
        if proposals.get('epic') != self.input.get('epic'):
            self.add(None, 'schema', 'epic %r differs from the epic of input.json (%r)'
                     % (proposals.get('epic'), self.input.get('epic')))
        if proposals.get('commit') != self.input.get('commit'):
            self.add(None, 'schema', 'commit %r differs from the commit of input.json (%r)'
                     % (proposals.get('commit'), self.input.get('commit')))
        for name in ('observations', 'overflow'):
            if not isinstance(proposals.get(name), list):
                self.add(None, 'schema', '%s must be a list' % name)
        for observation in proposals.get('observations') if isinstance(proposals.get('observations'), list) else []:
            if not (isinstance(observation, dict) and filled(observation.get('key'))
                    and isinstance(observation.get('incidents'), list)):
                self.add(None, 'schema', 'an observation needs a key and a list of incidents: %r' % (observation,))
        items = proposals.get('items')
        if not isinstance(items, list):
            self.add(None, 'schema', 'items must be a list')
            return
        numbers, keys, counts = set(), {}, {kind: 0 for kind in ITEM_KINDS}
        for position, item in enumerate(items, 1):
            if not isinstance(item, dict):
                self.add(None, 'schema', 'item %d is not an object' % position)
                continue
            n = item.get('n')
            if isinstance(n, bool) or not isinstance(n, int) or n < 1 or n in numbers:
                self.add(None, 'schema', 'item %d: n must be a unique positive integer (got %r)' % (position, n))
            if isinstance(n, int):
                numbers.add(n)
            kind = item.get('kind')
            if kind not in ITEM_KINDS:
                self.add(n, 'schema', 'item %s: kind must be one of %s (got %r)' % (n, ', '.join(ITEM_KINDS), kind))
                continue
            counts[kind] += 1
            if kind == 'status':
                self.check_status(item)
                continue
            if filled(item.get('key')):
                if item['key'] in keys:
                    self.add(n, 'schema', 'item %s: key %r repeats item %s' % (n, item['key'], keys[item['key']]))
                keys[item['key']] = n
            self.check_proposal(item)
        for kind, limit in LIMITS.items():
            if counts[kind] > limit:
                self.add(None, 'limit', 'at most %d items of kind %s, got %d' % (limit, kind, counts[kind]))

    # -- fix and issue
    def check_proposal(self, item):
        n, kind = item['n'], item['kind']
        for field in ('key', 'title', 'cause', 'why_prevents', 'verify'):
            if not filled(item.get(field)):
                self.add(n, 'field', 'item %s: %s is required (a non-empty text)' % (n, field))
        if kind == 'fix':
            if not filled(item.get('change')):
                self.add(n, 'field', 'item %s: change is required (a non-empty text)' % n)
            files = item.get('files')
            if not isinstance(files, list) or not files:
                self.add(n, 'field', 'item %s: files is required (a non-empty list)' % n)
            else:
                for path in files:
                    if not allowed_file(path):
                        self.add(n, 'file', 'item %s: %r is not a file a process fix may change; a change of other '
                                            'files is an issue with repo shop' % (n, path))
        else:
            self.check_issue(item)
        evidence = id_list(item.get('evidence'))
        if evidence is None:
            self.add(n, 'field', 'item %s: evidence is required (a non-empty list of incident IDs)' % n)
            return
        known = [e for e in evidence if e in self.incidents]
        for missing in (e for e in evidence if e not in self.incidents):
            self.add(n, 'unknown_incident', 'item %s: incident %s is not in input.json' % (n, missing))
        self.check_threshold(item, known)
        self.check_open_pr(item)
        if filled(item.get('key')):
            self.check_rejected(item, known)
            self.check_applied(item, known)

    def check_issue(self, item):
        n = item['n']
        if item.get('repo') not in ('ao', 'shop'):
            self.add(n, 'field', 'item %s: repo must be ao or shop' % n)
        duplicates = item.get('duplicate_check')
        if not isinstance(duplicates, list) or not all(isinstance(d, int) and not isinstance(d, bool)
                                                       for d in duplicates):
            self.add(n, 'field', 'item %s: duplicate_check is required (a list of issue numbers)' % n)
        existing, body = item.get('existing_issue'), item.get('body')
        if existing is not None and filled(body):
            self.add(n, 'field', 'item %s: give body or existing_issue, not both' % n)
        elif existing is None and not filled(body):
            self.add(n, 'field', 'item %s: body (a new issue) or existing_issue (a comment) is required' % n)
        elif existing is not None:
            known = self.open_issues[item['repo']] if item.get('repo') in ('ao', 'shop') else set()
            if isinstance(existing, bool) or not isinstance(existing, int) or existing not in known:
                self.add(n, 'existing_issue', 'item %s: existing_issue %r is not an open issue of the input data '
                                              '(repo %s)' % (n, existing, item.get('repo')))

    def check_threshold(self, item, known):
        n = item['n']
        classes = item.get('classes') if isinstance(item.get('classes'), dict) else {}
        counted, corrective = 0, False
        for incident_id in known:
            entry = classes.get(incident_id)
            label = entry.get('class') if isinstance(entry, dict) else None
            eligible = can_be_corrective(self.incidents[incident_id])
            if eligible and label is None:
                self.add(n, 'class', 'item %s: incident %s has a human action and needs a class in classes '
                                     '(corrective, planned or product)' % (n, incident_id))
                continue
            if label is not None and label not in CLASSES:
                self.add(n, 'class', 'item %s: incident %s has an unknown class %r' % (n, incident_id, label))
                continue
            if label == 'corrective':
                if not eligible:
                    self.add(n, 'class', 'item %s: incident %s cannot be corrective (it is not a wait with a human '
                                         'action, a manual fix or a repair)' % (n, incident_id))
                    continue
                if not filled(entry.get('corrective_reason')):
                    self.add(n, 'class', 'item %s: corrective incident %s needs a corrective_reason' % (n, incident_id))
                    continue
                corrective = True
            if eligible and label == 'planned':
                continue
            counted += 1
        if not corrective and counted < 2:
            self.add(n, 'threshold', 'item %s: needs two incidents (planned ones do not count) or one corrective '
                                     'incident, has %d' % (n, counted))

    def check_open_pr(self, item):
        n, key, updates = item['n'], item.get('key'), item.get('updates')
        in_pr = [i for i in self.open_items if i.get('key') == key]
        if in_pr and not any(i.get('n') == updates for i in in_pr):
            self.add(n, 'open_pr', 'item %s: key %r is in the open retro PR (item %s); give updates: %s'
                     % (n, key, in_pr[0].get('n'), in_pr[0].get('n')))
        elif not in_pr and updates is not None:
            self.add(n, 'open_pr', 'item %s: updates %r points at no item of the open retro PR with key %r'
                     % (n, updates, key))

    def epic_of(self, incident_id):
        return self.epics.get(self.incidents[incident_id].get('epic')) or {}

    def check_rejected(self, item, known):
        n, key = item['n'], item['key']
        for row in self.rejected:
            shared = [i for i in known if i in (row.get('incidents') or [])]
            if row.get('key') != key:
                if shared:
                    self.add(n, 'rejected', 'item %s: incident %s was rejected under key %r' % (n, shared[0], row.get('key')))
                continue
            moment = row.get('rejected_at')
            fresh = [i for i in known if i not in (row.get('incidents') or []) and moment is not None
                     and (self.epic_of(i).get('merged_at') or 0) > moment]
            if not fresh:
                self.add(n, 'rejected', 'item %s: key %r was rejected; it needs a new incident from an epic merged '
                                        'after the rejection' % (n, key))
            reason = item.get('previously_rejected')
            if not (isinstance(reason, dict) and reason.get('at') not in (None, '') and filled(reason.get('reason'))):
                self.add(n, 'rejected', 'item %s: key %r was rejected; previously_rejected {at, reason} is required'
                         % (n, key))

    def check_applied(self, item, known):
        n, key = item['n'], item['key']
        for row in self.applied:
            shared = [i for i in known if i in (row.get('incidents') or [])]
            if row.get('key') != key:
                if shared:
                    self.add(n, 'applied', 'item %s: incident %s belongs to the fix %r applied in PR %s'
                             % (n, shared[0], row.get('key'), row.get('pr')))
                continue
            moment = row.get('merged_at')
            fresh = [i for i in known if i not in (row.get('incidents') or []) and moment is not None
                     and (self.epic_of(i).get('created_at') or 0) > moment]
            if not fresh:
                self.add(n, 'applied', 'item %s: key %r was fixed in PR %s; it needs an incident from an epic that '
                                       'started after that merge' % (n, key, row.get('pr')))
            previous = item.get('previous_fix')
            if isinstance(previous, bool) or not isinstance(previous, int) or previous != row.get('pr'):
                self.add(n, 'applied', 'item %s: key %r was fixed in PR %s; previous_fix must be %s (the PR number)'
                         % (n, key, row.get('pr'), row.get('pr')))

    # -- status
    def check_status(self, item):
        n = item['n']
        for field in ('target', 'change'):
            if not filled(item.get(field)):
                self.add(n, 'status', 'item %s: %s is required (a non-empty text)' % (n, field))
        evidence = id_list(item.get('evidence'))
        if evidence is None:
            verified = 'zweryfikowan' in str(item.get('change') or '').lower()
            self.add(n, 'status', 'item %s: evidence is required%s' % (
                n, ' (a step marked zweryfikowany needs evidence)' if verified else ''))
        else:
            for ref in evidence:
                if ref not in self.incidents and ref not in self.status_ids:
                    self.add(n, 'status', 'item %s: evidence %s is neither an incident nor a status/* fact of '
                                          'input.json' % (n, ref))
        try:
            with open(os.path.join(self.repo, PLAN_PATH), encoding='utf-8') as f:
                plan = f.read()
        except OSError:
            self.add(n, 'status', 'item %s: there is no plan without a manifest (%s)' % (n, PLAN_PATH))
            return
        if '"schema_version"' in plan:
            self.add(n, 'status', 'item %s: %s has an AO manifest ("schema_version"); its Status is not changed'
                     % (n, PLAN_PATH))


def validate_run(directory, repo):
    """[{item, code, message}] of proposals.json in a run directory against its input.json; raises Refusal when
    input.json cannot be read."""
    try:
        with open(os.path.join(directory, 'input.json'), encoding='utf-8') as f:
            data = json.load(f)
    except (OSError, ValueError) as e:
        raise Refusal('cannot read input.json in %s: %s' % (directory, e))
    if not isinstance(data, dict):
        raise Refusal('input.json in %s is not a JSON object' % directory)
    validation = Validation(data, repo)
    try:
        with open(os.path.join(directory, 'proposals.json'), encoding='utf-8') as f:
            proposals = json.load(f)
    except OSError as e:
        validation.add(None, 'proposals', 'cannot read proposals.json: %s' % e.strerror)
    except ValueError as e:
        validation.add(None, 'proposals', 'proposals.json is not valid JSON: %s' % e)
    else:
        validation.check(proposals)
    return validation.errors


def cmd_validate(args):
    """Prints {ok, errors} as JSON; the exit code is 0 when the proposals are valid and 2 when they are not."""
    errors = validate_run(args.directory, args.repo)
    print_json({'ok': not errors, 'errors': errors})
    return 2 if errors else 0


# ------------------------------------------------------------------------------------------------------------ CLI

EPIC_NAME = re.compile(r'^[a-z0-9][a-z0-9-]*$')


def build_parser():
    paths = default_paths()
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument('--repo', default='.', help='directory `gh` and `git` run in (default: the current one)')
    common.add_argument('--ao-home', default=paths['ao_home'], help='directory with the ao-<epic> runs')
    common.add_argument('--retro-home', default=paths['retro_home'], help='registers and per-epic state of /retro')
    common.add_argument('--sweep-home', default=paths['sweep_home'], help='reports of /sweep')
    parser = argparse.ArgumentParser(description='Facts and registers for the /retro skill')
    commands = parser.add_subparsers(dest='command', required=True)
    collecting = commands.add_parser('collect', parents=[common], help='write input.json for an epic')
    collecting.add_argument('epic')
    collecting.add_argument('--out', help='directory for input.json (default: <retro-home>/<epic>)')
    commands.add_parser('pending', parents=[common], help='epics whose retro is still to do')
    for name, text in (('defer', 'defer the retro of an epic (it stays pending)'),
                       ('skip', 'skip the retro of an epic for good')):
        sub = commands.add_parser(name, parents=[common], help=text)
        sub.add_argument('epic')
        sub.add_argument('--reason', default='', help='why')
    locking = commands.add_parser('lock', parents=[common], help='take the lock of the retro of an epic')
    locking.add_argument('epic')
    locking.add_argument('--run-id', default='', help='run identifier (default: $RETRO_RUN_ID, else generated)')
    unlocking = commands.add_parser('unlock', parents=[common], help='release the lock of this run')
    unlocking.add_argument('--run-id', default='', help='run identifier of `lock` (default: $RETRO_RUN_ID)')
    unlocking.add_argument('--done', action='store_true', help='mark the epic of the lock done')
    validating = commands.add_parser('validate', parents=[common], help='check proposals.json of a run directory')
    validating.add_argument('directory')
    return parser


def print_json(value):
    print(json.dumps(value, ensure_ascii=False))


def main(argv=None, run=None):
    args = build_parser().parse_args(argv)
    try:
        if args.command == 'collect':
            check_epic_name(args.epic)
            paths = {'ao_home': args.ao_home, 'retro_home': args.retro_home, 'sweep_home': args.sweep_home}
            data = collect(args.epic, paths, run or next_epic.make_runner(args.repo))
            print(write_json(args.out or os.path.join(args.retro_home, args.epic), 'input.json', data))
        elif args.command == 'pending':
            print_json(cmd_pending(args, run or next_epic.make_runner(args.repo)))
        elif args.command == 'defer':
            print_json(cmd_defer(args))
        elif args.command == 'skip':
            print_json(cmd_skip(args))
        elif args.command == 'lock':
            print_json(cmd_lock(args))
        elif args.command == 'unlock':
            print_json(cmd_unlock(args))
        elif args.command == 'validate':
            return cmd_validate(args)
    except (Refusal, ValueError) as e:
        print('retro: %s' % e, file=sys.stderr)
        return 2
    except (next_epic.CommandError, RetroError, OSError) as e:
        print('retro: %s' % e, file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())

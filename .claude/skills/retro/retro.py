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
              'format': None}
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
        items = []
        for follow in lines[index + 1:]:
            bullet = _bullet(follow)
            if bullet is None:
                break
            indent, text = bullet
            labelled = any(text.startswith(label) for label in MEASUREMENT_LABELS)
            if not indent and labelled:
                break
            pr = PR_ITEM.match(text)
            if pr:
                items.append({'pr': int(pr.group(1)), 'text': pr.group(2) or text})
            else:
                items.append({'pr': None, 'text': text})
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
                result['manual_items'] = [{'pr': n, 'text': text} for n in numbers]
                remaining = result['manual_count'] - len(numbers)
                if remaining > 0:
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
        mismatch = (count < listed) or (count > 0 and listed == 0)
    else:
        mismatch = count != listed
    if mismatch:
        facts.append({'id': '%s/mismatch/manual_fix' % epic_id,
                      'text': 'niezgodna liczba: komentarz z pomiarem podaje %d ręcznych poprawek, a opisuje %d'
                              % (count, listed)})
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
    """[(position, text)] of the section `Decyzje bez pytania` of a PR description: numbered or bulleted items."""
    lines = (body or '').splitlines()
    start = next((i for i, l in enumerate(lines) if re.match(r'^#{1,6}\s*decyzje bez pytania\s*$', l, re.I)), None)
    if start is None:
        return []
    items, current, blank = [], None, False
    for line in lines[start + 1:]:
        if re.match(r'^#{1,6}\s', line):
            break
        if not line.strip():
            blank = True
            continue
        numbered = re.match(r'^\s{0,3}(\d+)[.)]\s+(.*\S)\s*$', line)
        bullet = re.match(r'^\s{0,3}[-*]\s+(.*\S)\s*$', line)
        if numbered:
            current = [int(numbered.group(1)), numbered.group(2)]
            items.append(current)
        elif bullet:
            current = [len(items) + 1, bullet.group(1)]
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

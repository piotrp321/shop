#!/usr/bin/env python3
"""Facts and registers for the /retro skill (retrospective after an epic). Python 3.9+, standard library only.

Every command takes `--repo DIR` (where `gh` and `git` run, default: the current directory), `--ao-home`,
`--retro-home`, `--sweep-home` and `--metrics-state` (paths below). Results are printed as one JSON document on stdout
(`collect` prints the path of the file) and diagnostics as `retro: ...` on stderr. Exit codes: 0 success; 1 a `gh` or
`git` command failed or a file could not be written (nothing is half-written: files are replaced atomically); 2
invalid arguments or a refusal (a bad epic name, a retro in the state `done` or `skipped`, another run's fresh lock,
an item that does not exist) and, for `validate`, proposals that do not pass.

  retro.py collect EPIC [--out DIR] [--worktree DIR] [--projects DIR] [--accept-process-mismatch REASON]
      First, before any `gh` call, the version of the process: `metrics.py process --worktree <worktree>` (the
      expected version is HEAD of the worktree of the retro). Definitions on disk that differ (a place of loading with
      `differs`, a skill of the user that hides a project skill) are a refusal (exit 2, nothing written) with the
      places, the number of files, the first paths and what to do, unless --accept-process-mismatch gives a non-empty
      reason; then, and when they match, the fact `<epic>/process/check` (`data.status` `match` or
      `mismatch_accepted`, `ref`, `describe`, `places`, `accepted`). A check that cannot run (no script, another exit
      code, not the expected JSON) is the fact `<epic>/process/missing/check` and collect goes on. For the collected
      native epic, the field `process` of its record is the fact `<epic>/process/record` (none:
      `<epic>/process/missing/record`).
      Writes DIR/input.json (default DIR: <retro-home>/EPIC) and prints its path. input.json has the incidents of the
      whole history of AO runs and native epics (docs/features/retro/spec.md, "Incydenty"; a native epic is a run file
      <metrics-state>/runs/EPIC.json of `metrics.py start` with a final PR and no ao-EPIC directory: its incidents
      come from its record <metrics-state>/epics/EPIC.json, refreshed first with `metrics.py epic EPIC` for the
      collected epic, and from the sections of the description of its final PR read through the gate), the facts of
      the plan "Status" (`status/in-progress`, `status/last-epic`, `status/agent-issues`, `status/pending-retro`), the
      registers, the items of the open retro PR and the open issues to check duplicates against. The measurement of
      tools/metrics/metrics.py (the script of --worktree, the checkout of origin/main, default --repo, run in --repo
      with SHOP_METRICS_STATE=--metrics-state through an injectable runner) is added too: `decide EPIC --history`
      gives the incidents of the kind `optimization` (ID `<epic>/optimization/<key>`, one per candidate of an epic of
      the history), `trend --json --epic EPIC --no-gh` and the decide result of the epic are the facts
      `<epic>/measurement/{trend,record,decide}` (with `data`), and `optimization_effects` [{key, applied_key, pr,
      merged_at, at, effect}] holds, for every row of applied.jsonl with an `optimization` incident, the output of
      `metrics.py effect KEY --at <merge of the fix>` as it is (null when the call failed). A missing script, a
      non-zero exit code, a timeout or an output that is not the expected JSON is the fact
      `<epic>/measurement/missing/<call>`: "brak danych pomiaru" and the category of the failure in Polish ("kod
      wyjścia N", ...), never the stderr of the script (local paths, tracebacks); the other incidents are collected
      anyway.
      Text written by a person or a session (a point of a PR description and its evidence, a manual fix, a question of
      a blocker, the body of an open issue) is whole: one point is one incident whatever its length, and the text of a
      section that is in no point is the fact `<epic>/outside-points/<section or pr<nr>>`. For the collected native
      epic, `handoff` {pr, comments: [{author, created_at, url, body}]} has the whole comments "Przekazanie do retro"
      of its final PR (none: the fact `<epic>/missing/handoff`) and `materials` {final_pr, spec, plan, run_file,
      record, sessions} points at its materials without their content: spec.md and plan.md in --worktree (a removed
      plan: `deleted_in` and `last_commit`, the commit with its last version, from `git log` at origin/main), the run
      file and the record, and the sessions of the run file with `transcript_dir` and `transcript` in --projects;
      what is missing is a fact `<epic>/missing/{spec,plan,sessions,transcripts}`. Both are null for an AO run.
      GitHub content (epic issues and their comments, PRs into epic/*, final PRs of native epics and their comments,
      the open retro PR, open issues) comes through the origin gate tools/agent-security/github_trust.py, AO issues
      judged by the permission in the AO repository: a measurement comment counts only from a trusted author, an
      untrusted epic issue, comment, task PR, final PR or comment of a final PR is only an entry of `external`
      {source, number, author, url} and an untrusted open issue is {number, author, url, trusted: false}. A failing
      gate (gh, unknown permission) is exit code 1 like a failing `gh`.
  retro.py validate DIR
      Checks DIR/proposals.json against DIR/input.json ("Walidacja"; the plan without a manifest for `status` items
      is looked up in `--repo`). Prints {"ok": bool, "errors": [{"item": n|null, "code", "message"}]}; exit 0 when
      ok, 2 otherwise. Codes: proposals, schema, limit, field (also a title over 256 or an issue or comment text over
      65536 characters, the limits of GitHub), file, unknown_incident, class, threshold, open_pr, rejected, applied,
      existing_issue (also an open issue without `trusted: true`), status. A missing input.json is exit 2 with a
      message on stderr.
  retro.py observe DIR [--pr N --branch B [--committed none|N[,N...]]]
      Appends to observations.jsonl the observations of DIR/proposals.json and the key and incidents of every `fix`
      and `issue` item (no row twice). Records in <retro-home>/<epic>/state.json the `issue` items as
      `decisions_open` and, with --pr, the `fix` items as `pr` {number, branch, items} (the retro PR of this run);
      --committed limits them to the fix items committed in the PR (`none` while the series is in progress). Observing
      the same proposals.json again changes only `pr`: the decisions settled by `issues` and `reject` stay.
  retro.py reject (--key KEY | --all | --pr N) --reason TEXT [--epic EPIC]
      A conscious rejection, written to rejected.jsonl (the only command that writes it): `--key` takes the undecided
      item with the key (an item of an unresolved retro PR or an issue proposal in `decisions_open`); `--all` takes
      every item of the unresolved retro PRs and `--pr N` every item of that PR, never `decisions_open`. The item
      leaves the state.
  retro.py sync
      Settles every retro PR of the states: MERGED -> its items to applied.jsonl; CLOSED without a merge -> its
      items to carried.jsonl (never to rejected.jsonl); OPEN -> nothing. The state gets `pr.outcome`. A state.json
      that cannot be read is skipped with a message in `warnings` of the result.
  retro.py issues DIR --approve (all | N[,N...])
      Creates the approved `issue` items of DIR/proposals.json (`gh issue create`; `tech-debt` and `agent` labels
      for repo shop) or, for an `existing_issue`, comments on it. `all` is every item not rejected with `reject`
      (it left `decisions_open` undone); the number of a rejected item is a refusal. DIR/issues.json keeps what was
      done by the `key` of the item, so a second run repeats nothing; a failing item (also a NUL in its text) does
      not stop the others (exit 1 at the end). A text over the limits of GitHub is a refusal before any `gh` call.
  retro.py pending
      [{"epic", "merged_at", "attempts", "reason"}] of the epics (AO runs and native epics) whose final PR was merged
      after RETRO_SINCE and whose retro is neither `done` nor `skipped`, from the oldest merge; attempts and reason
      are the deferrals.
  retro.py defer EPIC --reason TEXT     records an attempt and a reason (to 200 characters); the epic stays pending
  retro.py skip EPIC --reason TEXT      state `skipped`: the epic leaves `pending` for good
  retro.py lock EPIC [--run-id ID]      takes <retro-home>/lock {run_id, epic, at} and marks the epic `running`. The
      run id is --run-id, else $RETRO_RUN_ID, else generated; it is printed ({run_id, epic, at, warning}). Another
      run's lock younger than 6 h is exit 2; an older one is taken over, with a `warning`. A lock that cannot be read
      (empty, half-written) counts by the mtime of the file.
  retro.py unlock [--run-id ID] [--done]  removes the lock of that run (exit 2 for another run's lock, which stays);
      --done marks the epic of the lock `done`, otherwise its `running` state is cleared.

`defer`, `skip` and `lock` are refused (exit 2) for an epic whose retro is `done` or `skipped`. They write only the
state of the epic and keep its other fields. A state.json that cannot be read makes every command that writes it
refuse (exit 2) instead of replacing it; `collect` still reads it as empty. `night_queue` is not used here: the session runs `night_queue.py room`
itself and passes the outcome as the reason of `defer`.

The data of AO runs (`<ao-home>/ao-<epic>/`) is only read: `orchestrator.db` is opened read-only (SQLite URI
`mode=ro`), the other files are read as text, and `ao` is never run (`ao report` rewrites `report.md`, and every `ao`
command in a directory without a database creates a new one). `gh` and `git` go through the runner of
`next_epic.make_runner`, the gate through `github_trust.make_runner` (it keeps the output of a failed permission
call, so a 404 is an untrusted author); tests replace both with one fake runner.

Paths (defaults as in the specification, each can be overridden by an option or an environment variable):
  AO_STATE_HOME     ~/.local/state            directories ao-<epic>/
  SHOP_RETRO_HOME   ~/.local/state/shop-retro registers and per-epic state.json
  SHOP_SWEEP_HOME   ~/.local/state/shop-sweep <date>-<sha7>-<epic>/report.md of /sweep
  SHOP_METRICS_STATE ~/.local/state/shop-metrics runs/<epic>.json and epics/<epic>.json of tools/metrics
  (--projects)      ~/.claude/projects        transcripts of the sessions (collect, `materials`)

Files in SHOP_RETRO_HOME (docs/features/retro/spec.md, "Rejestry"); times are epoch seconds:
  <epic>/state.json   `state` (running, done, skipped; absent for a deferred or not started retro), `deferrals`
                      [{at, reason}], `pr` {number, branch, items: [{n, kind, key, title, incidents, epic}], outcome},
                      `decisions_open` [{n, kind, key, title, repo, incidents, existing_issue, epic}], `started_at`,
                      `done_at`, `skipped_at`, `reason`
  observations.jsonl  {key, title, incidents, epic, at}
  rejected.jsonl      {key, incidents, reason, rejected_at, pr}
  applied.jsonl       {key, incidents, pr, merged_at}
  carried.jsonl       {key, title, incidents, pr, closed_at}
  lock                {run_id, epic, at}
"""
import argparse
import datetime
import glob
import hashlib
import importlib.util
import json
import os
import re
import sqlite3
import sys
import time
import unicodedata
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
sys.path.insert(0, os.path.join(HERE, '..', '..', '..', 'tools', 'agent-security'))
import github_trust  # noqa: E402  (the origin gate: content of authors without write access comes without it)

SHOP = next_epic.REPOSITORY
AO_REPOSITORY = 'piotrp321/agentic-orchestrator'
# Step 12 of the plan (/sweep named after the epic) reached main in fc208156 at 2026-11-09 13:51:12 CET: older epics
# had no per-epic review, so `escaped` and `no_sweep` exist only for epics merged after it.
STEP_12_AT = datetime.datetime.fromisoformat('2026-11-09T13:51:12+01:00').timestamp()
# `pending` takes epics merged after this date: a fixed, deliberate date (2026-11-10 00:00 UTC), unrelated to the
# time of any pull request. The first epics under the automatic retro include VISUAL-DESIGN, LIGHTHOUSE and HARDENING.
RETRO_SINCE = '2026-11-10T00:00:00+00:00'
# A failed attempt that ended at most this long before a block is the cause of that block.
ATTEMPT_GAP_S = 600
KNOWN_ATTEMPT_OK = 'result'
# The transcripts of the Claude Code sessions: `<dir>/<project>/<session>.jsonl`, the project being the working
# directory with every character that is not a letter or a digit turned into `-` (project_dir of tools/metrics).
CLAUDE_PROJECTS = os.path.expanduser('~/.claude/projects')


class RetroError(Exception):
    """A failure of `gh`, `git` or a file that ends the command with exit code 1."""


def default_paths():
    home = os.path.expanduser('~/.local/state')
    return {
        'ao_home': os.environ.get('AO_STATE_HOME') or home,
        'retro_home': os.environ.get('SHOP_RETRO_HOME') or os.path.join(home, 'shop-retro'),
        'sweep_home': os.environ.get('SHOP_SWEEP_HOME') or os.path.join(home, 'shop-sweep'),
        'metrics_state': os.environ.get('SHOP_METRICS_STATE') or os.path.join(home, 'shop-metrics'),
        'projects': CLAUDE_PROJECTS,
    }


def cut(text, limit):
    """A file name, a role or a time shortened for a fact. Never used for text written by a person or a session (a
    point of a PR description, a comment, a question of a blocker, the body of an issue): that is kept whole."""
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
    return [v for v in value if isinstance(v, str)] if isinstance(value, list) else []


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
        # an automatic extension of /ao-run (ao_events.py auto) closes the wait, but is no decision of the user
        automatic = str(window['action'] or '').startswith('auto:')
        human = window['action'] is not None and not automatic
        wait_data = {
            'event': window['type'], 'task_id': window['key'] or data.get('task_id') or None,
            'reason': data.get('reason') or data.get('kind'), 'blockers': _text_list(data.get('blockers')),
            'reasons': _text_list(data.get('reasons')), 'start': start, 'end': end, 'open': end is None,
            'action': window['action'], 'also_actions': [], 'absorbed': [], 'failed_attempts': [],
            'human_action': human, 'can_be_corrective': human,
        }
        facts_w = _start_facts(window['type'], window['task_id'], data, start)
        if automatic:
            facts_w.append({'source': 'events.jsonl', 'at': end, 'text': 'auto_action %s' % window['action'][5:]})
        elif window['action']:
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
                                   [{'source': 'issue comment', 'text': t} for t in texts]))
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

def section_lines(body, name):
    """The lines of the section `name` of a PR description (a heading of any level, in any case) up to the next
    heading, or None when the description has no such section."""
    lines = (body or '').splitlines()
    heading = re.compile(r'^#{1,6}\s*%s\s*$' % re.escape(name), re.I)
    start = next((i for i, l in enumerate(lines) if heading.match(l)), None)
    if start is None:
        return None
    found = []
    for line in lines[start + 1:]:
        if re.match(r'^#{1,6}\s', line):
            break
        found.append(line)
    return found


def unasked_decisions(body):
    """[(position, text)] of the section `Decyzje bez pytania` of a PR description (list_items)."""
    lines = section_lines(body, 'decyzje bez pytania')
    return [] if lines is None else list_items(lines)


def section_items(lines):
    """([(position, text)], rest) of the lines of a section. An item is an item of the top level list (no indentation,
    numbered or bulleted); indented lines (also indented bullets and indented paragraphs after a blank line) and an
    unindented line right after the item continue the current item, so one item keeps its whole text. A numbered item
    keeps its number, a bullet takes the next ordinal among the items; a number that was already used (also by a
    bullet) is replaced by the ordinal, so the positions of one description are unique. `rest` is the text that is in
    no item, as paragraphs: lines before the first item and an unindented paragraph after a blank line."""
    items, used, current, blank = [], set(), None, False
    rest, paragraph = [], None
    for line in lines:
        if not line.strip():
            blank, paragraph = True, None
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
            paragraph = None
        elif current is not None and (line[0] in ' \t' or not blank):
            current[1] += ' ' + line.strip()
        else:
            current = None
            if paragraph is None:
                paragraph = []
                rest.append(paragraph)
            paragraph.append(line.strip())
        blank = False
    return [(position, text) for position, text in items], [' '.join(p) for p in rest]


def list_items(lines):
    """[(position, text)] of the items of a section (section_items without the rest)."""
    return section_items(lines)[0]


def outside_points_fact(fact_id, name, number, rest, final=False):
    """The fact that keeps, whole, the text of a section that is in no point (it is no incident, but it is not lost),
    or None when there is none (the entry "brak" is no text)."""
    rest = [paragraph for paragraph in rest if not no_entry(paragraph)]
    if not rest:
        return None
    return {'id': fact_id, 'text': 'tekst sekcji „%s” opisu %sPR #%s poza punktami listy (nie jest incydentem): %s'
                                   % (name, 'końcowego ' if final else '', number, '\n\n'.join(rest))}


def unasked_incidents(epic_id, prs):
    """One `unasked` incident per point of "Decyzje bez pytania" of the task PRs, with the whole text of the point:
    the key is the position, so the identifier and the number of incidents do not depend on the text."""
    incidents = []
    for pr in prs:
        number = pr.get('number')
        for position, text in unasked_decisions(pr.get('body')):
            incidents.append(_incident(
                epic_id, 'unasked', 'pr%s#%d' % (number, position), None,
                {'pr': number, 'position': position, 'text': text, 'title': pr.get('title')},
                [{'source': 'pr %s' % number, 'text': text}]))
    return incidents


def unasked_outside_facts(epic_id, prs):
    """The facts `<epic>/outside-points/pr<number>`: the text of "Decyzje bez pytania" of a task PR that is in no
    point (a section of prose, a paragraph after the list)."""
    facts = []
    for pr in prs:
        lines = section_lines(pr.get('body'), 'decyzje bez pytania')
        fact = None if lines is None else outside_points_fact(
            '%s/outside-points/pr%s' % (epic_id, pr.get('number')), 'Decyzje bez pytania', pr.get('number'),
            section_items(lines)[1])
        if fact:
            facts.append(fact)
    return facts


# ------------------------------------------------------------------------------- native epics (docs/features/ash-v1)

# The fixed sections of the description of the final PR of a native epic (docs/adr/agentic-software-house.md,
# "Przebieg epiku", step 7) read by /retro: (key part of the incident, heading, kind of incident).
NATIVE_PR_SECTIONS = (('decisions', 'Decyzje bez pytania', 'unasked'), ('problems', 'Problemy i zastoje', 'reported'),
                      ('gaps', 'Luki w instrukcjach', 'reported'))


def no_entry(text):
    """True for the entry "brak" of an empty section ("brak", "Brak.", in any case, with punctuation)."""
    return re.sub(r'[\W_]+', '', text).lower() == 'brak'


def pr_section(body, name):
    """(points, rest) of the section `name` of a PR description: the points are the items of its list (section_items)
    or, for a section of prose without a list, its whole text as one point; the entry "brak" is no point. `rest` is
    the text of a section with a list that is in no point (section_items). None when the description has no such
    section (a fact of missing data, not an error)."""
    lines = section_lines(body, name)
    if lines is None:
        return None
    items, rest = section_items(lines)
    if not items:
        text = ' '.join(line.strip() for line in lines if line.strip())
        items, rest = ([(1, text)] if text else []), []
    return [(position, text) for position, text in items if not no_entry(text)], rest


def pr_section_points(body, name):
    """[(position, text)] of the points of pr_section, None without the section."""
    section = pr_section(body, name)
    return None if section is None else section[0]


def final_pr_of(run_file):
    pr = run_file.get('pr') if isinstance(run_file, dict) else None
    return pr if isinstance(pr, int) and not isinstance(pr, bool) and pr > 0 else None


def load_run_file(metrics_state, epic_id):
    """The run file `<metrics_state>/runs/<epic>.json` of `metrics.py start` as a dict, None when there is none;
    ValueError when it cannot be read as a JSON object."""
    path = os.path.join(metrics_state, 'runs', '%s.json' % epic_id)
    try:
        with open(path, encoding='utf-8') as f:
            data = json.load(f)
    except FileNotFoundError:
        return None
    except (OSError, ValueError) as e:
        raise ValueError('the native run file %s cannot be read: %s' % (path, getattr(e, 'strerror', None) or e))
    if not isinstance(data, dict):
        raise ValueError('the native run file %s is not a JSON object' % path)
    return data


def run_file_names(metrics_state):
    """The names of the files in `<metrics_state>/runs` as the directory lists them ([] without it). Membership in this
    list, never opening a path, decides which run files exist: on a disk that ignores the case (APFS) the path
    runs/small-fixes.json opens runs/SMALL-FIXES.json."""
    try:
        return sorted(os.listdir(os.path.join(metrics_state, 'runs'))) if metrics_state else []
    except OSError:
        return []


def scan_native(metrics_state, ao_home):
    """(epics, skipped) of the run files of `<metrics_state>/runs`. epics: the finished native epics, a run file
    `<epic>.json` with a name of the pattern of EPIC_NAME and a final PR and no AO run directory `ao-<epic>` (metrics.py
    epic builds the record of such an epic from AO): [{id, final_pr_number, created_at, branch, source}] by epic ID.
    skipped: [(file name, SKIPPED_RUN_FILE key)] of the run files that are no epic because of their name or content (a run
    file without a final PR is an epic in progress, not skipped)."""
    found, skipped = [], []
    for name in run_file_names(metrics_state):
        epic_id, extension = os.path.splitext(name)
        if extension != '.json':
            continue
        if not EPIC_NAME.match(epic_id):
            skipped.append((name, 'name'))
            continue
        if os.path.isdir(os.path.join(ao_home, 'ao-' + epic_id)):
            continue
        try:
            run_file = load_run_file(metrics_state, epic_id)
        except ValueError:
            skipped.append((name, 'unreadable'))
            continue
        if final_pr_of(run_file) is None:
            continue
        branch = run_file.get('branch')
        found.append({'id': epic_id, 'final_pr_number': final_pr_of(run_file),
                      'created_at': parse_time(run_file.get('started_at')),
                      'branch': branch if filled(branch) else None, 'source': 'native'})
    return found, skipped


def native_epics(metrics_state, ao_home):
    """The finished native epics of scan_native."""
    return scan_native(metrics_state, ao_home)[0]


# Why a run file is no native epic: (fact of input.json in Polish, message of `pending` on stderr).
SKIPPED_RUN_FILE = {
    'name': ('nazwa spoza wzorca ^[a-z0-9][a-z0-9-]*$ (metrics.py start z nazwą epiku małymi literami)',
             'a name outside ^[a-z0-9][a-z0-9-]*$ (metrics.py start with the epic name in lower case)'),
    'unreadable': ('plik nieczytelny albo nie jest obiektem JSON', 'unreadable or not a JSON object'),
}


def skipped_facts(skipped):
    return [{'id': 'native/skipped/%s' % cut(name, 100),
             'text': 'plik przebiegu %s pominięty: %s' % (cut(name, 100), SKIPPED_RUN_FILE[why][0])}
            for name, why in skipped]


def check_collectable(epic_id, paths):
    """Raises the refusal (ValueError, exit 2) for an epic that is neither a finished AO run nor a native epic of
    scan_native, with the reason: an AO run that has not finished, a run file without a final PR, unreadable or with
    a name outside the pattern, or neither."""
    if os.path.isdir(os.path.join(paths['ao_home'], 'ao-' + epic_id)):
        raise ValueError('ao-%s is not the run of a finished epic (DONE with a final PR) in %s'
                         % (epic_id, paths['ao_home']))
    state = paths.get('metrics_state')
    names = run_file_names(state)
    wanted = '%s.json' % epic_id
    if wanted in names:
        if final_pr_of(load_run_file(state, epic_id)) is None:
            raise ValueError('the native run file of %s has no final PR (metrics.py start --pr)' % epic_id)
    other = [n for n in names if n.lower() == wanted.lower() and n != wanted]
    if other:
        raise ValueError('the run file %s has a name outside ^[a-z0-9][a-z0-9-]*$: it is no native epic (metrics.py '
                         'start with the epic name in lower case writes %s)' % (other[0], wanted))
    raise ValueError('%s has neither an AO run (ao-%s in %s) nor a native run file (runs/%s in %s, metrics.py start)'
                     % (epic_id, epic_id, paths['ao_home'], wanted, state))


def read_native_record(metrics_state, epic_id):
    """The record `<metrics_state>/epics/<epic>.json` of metrics.py, or None when it is missing or unreadable."""
    try:
        with open(os.path.join(metrics_state, 'epics', '%s.json' % epic_id), encoding='utf-8') as f:
            record = json.load(f)
    except (OSError, ValueError):
        return None
    return record if isinstance(record, dict) else None


def _unique(key, seen):
    candidate, n = key, 1
    while candidate in seen:
        n += 1
        candidate = '%s-%d' % (key, n)
    seen.add(candidate)
    return candidate


def _number(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def _epoch(value):
    at = parse_time(value)
    return int(at) if at is not None else None


def native_record_incidents(epic_id, record):
    """(incidents, facts) of the metrics record of a native epic (docs/features/ash-v1/spec.md, R2): `wait` per human
    event (a human action), `finding` per blocking review, `failed_attempt` per unfinished subagent run, `escaped` per
    `bug` issue of the regression window. A missing record or field is a fact, never an error."""
    if record is None:
        return [], [{'id': '%s/missing/record' % epic_id,
                     'text': 'brak rekordu metryk epiku natywnego (metrics.py epic %s): brak incydentów z rekordu'
                             % epic_id}]
    incidents, facts, seen = [], [], set()

    def field(name, value):
        if not isinstance(value, list):
            facts.append({'id': '%s/missing/record/%s' % (epic_id, name),
                          'text': 'rekord metryk bez pola %s: brak incydentów z tego źródła' % name})
            return []
        return [v for v in value if isinstance(v, dict)]

    human = record.get('human')
    for event in field('human', human.get('events') if isinstance(human, dict) else None):
        at, wait = _epoch(event.get('at')), event.get('wait_s')
        if at is None or not _number(wait):
            continue
        data = {'event': 'human_message', 'task_id': None, 'start': int(at - wait), 'end': at, 'open': False,
                'wait_s': wait, 'action': 'human_message', 'human_action': True, 'can_be_corrective': True}
        incidents.append(_incident(epic_id, 'wait', _unique('human@%d' % at, seen), at, data,
                                   [{'source': 'rekord metryk', 'at': at,
                                     'text': 'wiadomość człowieka po %d s czekania' % round(wait)}]))

    for review in field('reviews', record.get('reviews')):
        if review.get('blocking') is not True:
            continue
        at = _epoch(review.get('at'))
        task = review.get('task') if filled(review.get('task')) else None
        role = cut(review.get('role'), 100)
        key = _unique('%s:%s@%s' % (task or 'epik', role, at if at is not None else 'brak-czasu'), seen)
        data = {'task': task, 'role': role, 'agent_id': review.get('agent_id'), 'mode': review.get('mode'),
                'verdict': review.get('verdict'), 'blocking': True, 'can_be_corrective': False}
        text = 'przegląd %s %s (%s): werdykt %s' % (role, 'zadania %s' % task if task else 'całej zmiany',
                                                    review.get('mode'), review.get('verdict'))
        incidents.append(_incident(epic_id, 'finding', key, at, data, [{'source': 'rekord metryk', 'text': text}]))

    for attempt in field('attempts', record.get('attempts')):
        if attempt.get('finished') is not False:
            continue
        task = attempt.get('task') if filled(attempt.get('task')) else None
        key = _unique('%s:%s' % (task or 'bez-zadania', attempt.get('agent_id')), seen)
        data = {name: attempt.get(name) for name in ('task', 'role', 'model', 'agent_id', 'started_at', 'ended_at')}
        data['can_be_corrective'] = False
        text = 'przebieg subagenta %s (%s) %s niezakończony' % (attempt.get('agent_id'), attempt.get('role'),
                                                                'zadania %s' % task if task else 'bez zadania')
        incidents.append(_incident(epic_id, 'failed_attempt', key, _epoch(attempt.get('ended_at')), data,
                                   [{'source': 'rekord metryk', 'text': text}]))

    regressions = record.get('regressions') if isinstance(record.get('regressions'), dict) else {}
    issues = regressions.get('issues')
    if not isinstance(issues, list):
        field('regressions', None)
    for number in issues if isinstance(issues, list) else []:
        if isinstance(number, int) and not isinstance(number, bool):
            incidents.append(_incident(epic_id, 'escaped', 'issue%d' % number, None,
                                       {'issue': number, 'can_be_corrective': False},
                                       [{'source': 'rekord metryk',
                                         'text': 'zgłoszenie bug #%d z okna regresji epiku' % number}]))
    if regressions.get('open') is True:
        facts.append({'id': '%s/native/regression-window' % epic_id,
                      'text': 'okno regresji otwarte do %s: zgłoszenia bug mogą jeszcze dojść'
                              % cut(regressions.get('until'), 40)})
    return incidents, facts


def native_pr_incidents(epic_id, number, record):
    """(incidents, facts) of the description of the final PR (a trusted record of the origin gate): `unasked` per
    point of "Decyzje bez pytania", `reported` per point of "Problemy i zastoje" and "Luki w instrukcjach"; a missing
    section is a fact."""
    incidents, facts = [], []
    for slug, name, kind in NATIVE_PR_SECTIONS:
        section = pr_section(record.get('body'), name)
        if section is None:
            facts.append({'id': '%s/missing/pr-section/%s' % (epic_id, slug),
                          'text': 'brak sekcji „%s” w opisie końcowego PR #%s' % (name, number)})
            continue
        points, rest = section
        for position, text in points:
            # the whole text of the point; the key is its position, never its text
            if kind == 'unasked':
                key = 'pr%s#%d' % (number, position)
                data = {'pr': number, 'position': position, 'text': text, 'title': record.get('title')}
            else:
                key = '%s#%d' % (slug, position)
                data = {'pr': number, 'section': name, 'position': position, 'text': text,
                        'can_be_corrective': False}
            incidents.append(_incident(epic_id, kind, key, None, data, [{'source': 'pr %s' % number, 'text': text}]))
        fact = outside_points_fact('%s/outside-points/%s' % (epic_id, slug), name, number, rest, final=True)
        if fact:
            facts.append(fact)
    return incidents, facts


def final_pr_record(gate, epic):
    """The record of the origin gate for the final PR of a native epic (listed by the branch of its run file), or
    None when the run file has no branch or the gate does not list that PR."""
    if not epic.get('branch'):
        return None
    for record in gate.prs(state='all', head=epic['branch'], limit=30, fields=('title', 'body')):
        if record['number'] == epic['final_pr_number']:
            return record
    return None


def native_numbers(record):
    """The numbers of `status/last-epic` from the `human` of a native record ({} without it)."""
    human = record.get('human') if isinstance(record, dict) else None
    if not isinstance(human, dict):
        return {}
    wait_s, fixes = human.get('wait_s'), human.get('manual_fixes')
    return {'interventions': human.get('interventions'), 'waits': human.get('waits'),
            'wait_min': round(wait_s / 60) if _number(wait_s) else None,
            'manual_count': len(fixes) if isinstance(fixes, list) else None}


# The comment of the lead session in the final PR of a native epic that hands the epic over to /retro: its text starts
# with this heading (docs/features/ash-process-fixes/spec.md, P1).
HANDOFF_HEADING = re.compile(r'^#{1,6}\s*Przekazanie do retro\b', re.I)
COMMIT_SHA = re.compile(r'^[0-9a-f]{40}(?:[0-9a-f]{24})?$')
SESSION_ID = re.compile(r'^[A-Za-z0-9._-]{1,64}$')


def is_handoff(body):
    first = next((line.strip() for line in str(body).splitlines() if line.strip()), '')
    return bool(HANDOFF_HEADING.match(first))


def native_handoff(gate, epic_id, number):
    """(handoff, facts, external) of the final PR of a native epic: `handoff` {pr, comments: [{author, created_at,
    url, body}]} holds the whole text of every comment of a trusted author that starts with the heading "Przekazanie
    do retro", read through the origin gate (the comments of the PR as an issue); every comment of an untrusted author
    is only an entry of `external` (source `final_pr_comment`). No such comment is the fact `<epic>/missing/handoff`;
    a failing gate raises (exit code 1, like any failing `gh`)."""
    comments, external = [], []
    for record in gate.comments(number):
        if not record['trusted']:
            external.append(external_record('final_pr_comment', number, record))
        elif is_handoff(record['body']):
            comments.append({'author': record.get('author'), 'created_at': record.get('createdAt'),
                             'url': record.get('url'), 'body': record['body']})
    facts = [] if comments else [{
        'id': '%s/missing/handoff' % epic_id,
        'text': 'brak komentarza „Przekazanie do retro” zaufanego autora w końcowym PR #%s' % number}]
    return {'pr': number, 'comments': comments}, facts, external


def plan_pointer(epic_id, path, worktree, run, commit):
    """({path, exists, last_commit, deleted_in}, facts) of the plan of an epic: in the worktree of the retro, else the
    commit that removed it at `commit` (origin/main) and its first parent, which still has the last version
    (`git show <last_commit>:<path>`). A plan that never existed and a failing `git` are facts."""
    plan = {'path': path, 'exists': os.path.isfile(os.path.join(worktree, path)), 'last_commit': None,
            'deleted_in': None}
    if plan['exists']:
        return plan, []
    fact_id = '%s/missing/plan' % epic_id
    try:
        out = run(['git', 'log', '-1', '--format=%H %P', '--diff-filter=D', commit, '--', path])
    except (next_epic.CommandError, OSError):
        return plan, [{'id': fact_id, 'text': 'brak planu %s w worktree retro, a historii planu nie ustalono: '
                                              'git log nie dał wyniku' % path}]
    shas = out.split()
    if len(shas) >= 2 and COMMIT_SHA.match(shas[0]) and COMMIT_SHA.match(shas[1]):
        plan['deleted_in'], plan['last_commit'] = shas[0], shas[1]
        return plan, []
    if not shas:
        return plan, [{'id': fact_id, 'text': 'plan %s nigdy nie istniał w historii %s' % (path, commit)}]
    return plan, [{'id': fact_id, 'text': 'brak planu %s w worktree retro, a git log dał nieoczekiwany wynik' % path}]


def transcript_of(projects, session):
    """The path of the transcript `<session>.jsonl` in any project directory of `projects`, or None."""
    if not SESSION_ID.match(session):
        return None
    found = sorted(glob.glob(os.path.join(glob.escape(projects), '*', glob.escape(session) + '.jsonl')))
    return found[0] if found else None


def native_materials(epic_id, number, paths, run, commit):
    """(materials, facts): pointers to the materials of a native epic for the analyst, without their content: the
    specification and the plan in the worktree of the retro (plan_pointer), the final PR, the run file and the record
    of tools/metrics and the sessions of the run file with the directory of their transcripts (the rule of project_dir
    of tools/metrics) and the transcript found in `paths['projects']`. A missing specification, plan, session or
    transcript is a fact."""
    worktree = paths.get('worktree') or '.'
    projects = paths.get('projects') or CLAUDE_PROJECTS
    state = paths.get('metrics_state') or ''
    directory = 'docs/features/%s' % epic_id
    facts = []
    spec = {'path': directory + '/spec.md', 'exists': os.path.isfile(os.path.join(worktree, directory, 'spec.md'))}
    if not spec['exists']:
        why = ('brak pliku spec.md w %s' % directory if os.path.isdir(os.path.join(worktree, directory))
               else 'brak katalogu %s' % directory)
        facts.append({'id': '%s/missing/spec' % epic_id, 'text': 'brak specyfikacji w worktree retro: %s' % why})
    plan, plan_facts = plan_pointer(epic_id, directory + '/plan.md', worktree, run, commit)
    facts += plan_facts
    record_path = os.path.join(state, 'epics', '%s.json' % epic_id)
    try:
        run_file = load_run_file(state, epic_id) or {}
    except ValueError:
        run_file = {}
    sessions = []
    listed = run_file.get('sessions') if isinstance(run_file.get('sessions'), list) else []
    for entry in listed:
        if not (isinstance(entry, dict) and filled(entry.get('id'))):
            continue
        where = entry.get('worktree') if filled(entry.get('worktree')) else None
        sessions.append({'id': entry['id'], 'worktree': where, 'at': entry.get('at'),
                         'transcript_dir': os.path.join(projects, re.sub(r'[^A-Za-z0-9]', '-', where))
                         if where else None,
                         'transcript': transcript_of(projects, entry['id'])})
    if not sessions:
        facts.append({'id': '%s/missing/sessions' % epic_id,
                      'text': 'plik przebiegu bez sesji (metrics.py start zapisuje sesję prowadzącą): brak wskazań '
                              'transkryptów'})
    lost = [s['id'] for s in sessions if s['transcript'] is None]
    if lost:
        facts.append({'id': '%s/missing/transcripts' % epic_id,
                      'text': 'brak transkryptu sesji %s w %s' % (', '.join(lost), projects)})
    return {'final_pr': number, 'spec': spec, 'plan': plan,
            'run_file': os.path.join(state, 'runs', '%s.json' % epic_id),
            'record': {'path': record_path, 'exists': os.path.isfile(record_path)}, 'sessions': sessions}, facts


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


def open_retro_pr(gate, retro_home):
    """{number, branch, items} of the open pull request of a trusted author whose branch starts with `retro/`, else
    None (a fork can name its branch `retro/...`). The items are those of the `pr.items` of every state.json whose
    `pr.number` is that PR."""
    prs = gate.prs(state='open', limit=100, fields=('headRefName',))
    found = next((p for p in prs if p['trusted'] and str(p.get('headRefName', '')).startswith('retro/')), None)
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
                          'blockers': [q for q in questions if isinstance(q, str)]})
        if run['status'] == 'BLOCKED' or tasks:
            blocked.append({'epic': run['id'], 'epic_status': run['status'], 'tasks': tasks})
    return blocked


KIND_ORDER = ('wait', 'failed_attempt', 'finding', 'repair', 'manual_fix', 'escaped', 'no_sweep', 'unasked',
              'reported', 'optimization')

# ------------------------------------------------------------------------------- measurement (tools/metrics/metrics.py)

METRICS_SCRIPT = os.path.join('tools', 'metrics', 'metrics.py')
METRICS_TIMEOUT_S = 120
NO_MEASUREMENT = 'brak danych pomiaru'
OPTIMIZATION_ID = re.compile(r'^[^/]+/optimization/(.+)$')


class MeasurementError(Exception):
    """A failed call of metrics.py. The message is only the category of the failure, in Polish ("brak skryptu", "kod
    wyjścia N", "przekroczony czas", "wynik nie jest JSON-em", "nieoczekiwany kształt JSON", "brak runnera"): never
    what the script wrote, because its stderr has local paths and tracebacks and goes to input.json. A non-zero exit
    code keeps `code` and the standard output in `stdout` (`metrics.py process` prints its reading and exits 4)."""

    def __init__(self, message, code=None, stdout=None):
        super().__init__(message)
        self.code = code
        self.stdout = stdout


def make_metrics_runner(repo, root=None, state=None):
    """A runner of `python3 tools/metrics/metrics.py`: takes the arguments after the script and returns the standard
    output. The script is taken from the checkout `root` (the worktree of origin/main, #679; default: `repo`) and runs
    in the repository `repo` (where `gh` finds the repository) with `SHOP_METRICS_STATE` set to `state` when given, so
    the data and the measurement state stay where they are. A missing script, a non-zero exit code and a timeout are
    a MeasurementError with the category only; the stderr of the script is dropped."""
    script = os.path.join(os.path.abspath(root or repo), METRICS_SCRIPT)
    run = next_epic.make_runner(repo, timeout=METRICS_TIMEOUT_S)
    environment = 'SHOP_METRICS_STATE="$1"; export SHOP_METRICS_STATE; shift; ' if state else ''

    def metrics(args):
        if not os.path.isfile(script):
            raise MeasurementError('brak skryptu %s' % METRICS_SCRIPT)
        # The shell drops the stderr of the script (`exec` keeps the timeout aimed at the script itself), so the detail
        # of a failure is always the plain "exit code N" of the runner and never a path or a traceback.
        command = ['/bin/sh', '-c', environment + 'exec "$@" 2>/dev/null', 'sh'] + ([state] if state else []) \
            + [sys.executable, script] + list(args)
        try:
            return run(command)
        except next_epic.CommandError as e:
            message = str(e)
            code = re.search(r': exit code (-?\d+)$', message)
            if ': timed out after ' in message:
                raise MeasurementError('przekroczony czas')
            if code:
                raise MeasurementError('kod wyjścia %s' % code.group(1), code=int(code.group(1)),
                                       stdout=getattr(e, 'stdout', None))
            raise MeasurementError('nie udało się uruchomić skryptu')
    return metrics


def metrics_json(metrics, args, shape):
    """The JSON object printed by one `metrics.py` call, or raises MeasurementError when there is no runner, the call
    fails, the output is not JSON or lacks a key of `shape`. An injected runner may raise anything it likes (a
    next_epic.CommandError): that is "błąd wywołania", without its text."""
    if metrics is None:
        raise MeasurementError('brak runnera')
    try:
        out = metrics(args)
    except MeasurementError:
        raise
    except (next_epic.CommandError, OSError):
        raise MeasurementError('błąd wywołania')
    try:
        value = json.loads(out)
    except (TypeError, ValueError):
        raise MeasurementError('wynik nie jest JSON-em')
    if not isinstance(value, dict) or not all(key in value for key in shape):
        raise MeasurementError('nieoczekiwany kształt JSON')
    return value


def no_measurement(epic_id, what, reason):
    """The fact that a measurement could not be read (the retrospective goes on without it): the call and the category
    of the failure (`reason`, a MeasurementError or a text of this file), never a text of the script."""
    return {'id': '%s/measurement/missing/%s' % (epic_id, what),
            'text': '%s: metrics.py (%s) nie dał wyniku: %s' % (NO_MEASUREMENT, what, reason)}


def optimization_incidents(epic_id, entry, merged_at):
    """The incidents of the kind `optimization` of one epic: a candidate of `decide --history` per incident, with its
    numbers as the data and its proposal as the fact (text written by metrics.py, never a text of the AO run)."""
    incidents = []
    candidates = entry.get('candidates') if isinstance(entry, dict) else None
    for found in candidates if isinstance(candidates, list) else []:
        if not (isinstance(found, dict) and filled(found.get('key'))):
            continue
        data = {name: found.get(name) for name in ('rule', 'data', 'expected_effect', 'verification')}
        data['can_be_corrective'] = False
        facts = [{'source': 'metrics.py', 'text': found['proposal']}] if filled(found.get('proposal')) else []
        incidents.append(_incident(epic_id, 'optimization', found['key'], merged_at, data, facts))
    return incidents


def measurement_of(epic_id, metrics):
    """(history by epic, facts) of the collected epic: `decide EPIC --history` (one call), `trend --json` (the record
    row and the position of the epic). A failing call is the fact "brak danych pomiaru"."""
    history, facts = {}, []
    try:
        decided = metrics_json(metrics, ['decide', epic_id, '--history'], ('history',))
        for entry in decided['history'] if isinstance(decided['history'], list) else []:
            if isinstance(entry, dict) and isinstance(entry.get('epic'), str):
                history[entry['epic']] = entry
        own = history.get(epic_id) or {}
        facts.append({'id': '%s/measurement/decide' % epic_id,
                      'text': 'reguły pominięte i braki danych decide dla %s' % epic_id,
                      'data': {'skipped': own.get('skipped'), 'gaps': own.get('gaps')}})
    except MeasurementError as e:
        facts.append(no_measurement(epic_id, 'decide', e))
    try:
        trend = metrics_json(metrics, ['trend', '--json', '--epic', epic_id, '--no-gh'], ('epics', 'position'))
        row = next((r for r in trend['epics'] if isinstance(r, dict) and r.get('epic') == epic_id), None)
        facts.append({'id': '%s/measurement/trend' % epic_id, 'text': 'trend ostatnich epików (metrics.py trend)',
                      'data': trend})
        facts.append({'id': '%s/measurement/record' % epic_id,
                      'text': 'miary epiku %s i jego pozycja na tle mediany trendu' % epic_id,
                      'data': {'row': row, 'position': trend['position']}})
    except MeasurementError as e:
        facts.append(no_measurement(epic_id, 'trend', e))
    return history, facts


def iso_utc(epoch):
    return datetime.datetime.fromtimestamp(epoch, datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def optimization_effects(epic_id, applied, metrics):
    """([{key, applied_key, pr, merged_at, at, effect}], facts): for every row of applied.jsonl with an `optimization`
    incident, the output of `metrics.py effect KEY --at MERGE` as it is (the agent copies it, never computes it);
    `effect` is null when the call fails or the row has no merge time (and then there is a fact for the key)."""
    effects, facts, asked = [], [], {}
    for row in applied:
        listed = row.get('incidents') if isinstance(row.get('incidents'), list) else []
        keys = list(dict.fromkeys(m.group(1) for m in (OPTIMIZATION_ID.match(str(i)) for i in listed) if m))
        for key in keys:
            merged_at = row.get('merged_at')
            usable = isinstance(merged_at, (int, float)) and not isinstance(merged_at, bool)
            at = iso_utc(merged_at) if usable else None
            entry = {'key': key, 'applied_key': row.get('key'), 'pr': row.get('pr'), 'merged_at': merged_at, 'at': at,
                     'effect': None}
            effects.append(entry)
            if not usable:
                facts.append(no_measurement(epic_id, 'effect/%s' % key, 'wiersz applied.jsonl bez czasu merge\'u'))
                continue
            if (key, at) not in asked:
                try:
                    asked[(key, at)] = metrics_json(metrics, ['effect', key, '--at', at], ())
                except MeasurementError as e:
                    asked[(key, at)] = None
                    facts.append(no_measurement(epic_id, 'effect/%s' % key, e))
            entry['effect'] = asked[(key, at)]
    return effects, facts


# ------------------------------------------------------------- the version of the process (tools/metrics, process)

PROCESS_HINT = ('zaktualizuj główny checkout według „Wersja procesu” w docs/adr/agentic-software-house.md '
                '(git -C <główny checkout> pull --ff-only, gdy jest czysty, stoi na main i metrics.py process nie '
                'pokazuje epiku w toku z bieżącymi definicjami; w innym przypadku decyduje użytkownik) albo powtórz '
                'collect z --accept-process-mismatch "<powód>"')
PROCESS_SCOPE = ('kontrola dotyczy plików na dysku w miejscach ładowania; wersja załadowana przez retro-analyst nie '
                 'jest potwierdzona')
PATHS_SHOWN = 5


def _as_dict(value):
    return value if isinstance(value, dict) else {}


def _as_list(value):
    return value if isinstance(value, list) else []


def accept_reason(value):
    """The reason of `--accept-process-mismatch` with collapsed white space (also Unicode), or None without the
    option; an empty reason or one with a control character is a refusal."""
    if value is None:
        return None
    reason = ' '.join(value.split())
    if not reason or control_path(reason, 'reason'):
        raise Refusal('pusty albo nieprawidłowy powód --accept-process-mismatch: podaj, dlaczego retro idzie mimo '
                      'różnicy wersji procesu (bez znaków sterujących)')
    return reason


def files_word(n):
    if n == 1:
        return 'plik'
    return 'pliki' if 2 <= n % 10 <= 4 and not 12 <= n % 100 <= 14 else 'plików'


def read_process(metrics, worktree):
    """(reading, None) of `metrics.py process --worktree <worktree>` (expected version: HEAD of the worktree; exit 0
    when the definitions match, 4 with the same reading when they differ), or (None, category) when the check cannot
    run: no runner or script, another exit code, an output that is not the expected JSON."""
    if metrics is None:
        return None, 'brak runnera'
    try:
        out = metrics(['process', '--worktree', worktree])
    except MeasurementError as e:
        if e.code != 4:
            return None, str(e)
        out = e.stdout
    except (next_epic.CommandError, OSError):
        return None, 'błąd wywołania'
    try:
        value = json.loads(out)
    except (TypeError, ValueError):
        return None, 'wynik nie jest JSON-em'
    if not (isinstance(value, dict) and isinstance(value.get('expected'), dict) and isinstance(value.get('disk'), list)
            and isinstance(value.get('match'), bool)):
        return None, 'nieoczekiwany kształt JSON'
    return value, None


def differing_places(reading):
    """[{kind, path, head, differs}] of the places of loading whose files differ from the expected version."""
    places = []
    for place in reading['disk']:
        differs = [d for d in _as_list(_as_dict(place).get('differs')) if isinstance(d, dict)]
        if differs:
            places.append({'kind': place.get('kind'), 'path': place.get('path'), 'head': place.get('head'),
                           'differs': [{'path': d.get('path'), 'state': d.get('state')} for d in differs]})
    return places


def process_lines(places, skills):
    lines = []
    for place in places:
        paths = ['%s (%s)' % (d['path'], d['state']) for d in place['differs'][:PATHS_SHOWN]]
        more = len(place['differs']) - PATHS_SHOWN
        lines.append('%s %s: %d %s: %s%s' % (place['kind'], place['path'], len(place['differs']),
                                            files_word(len(place['differs'])), ', '.join(paths),
                                            ' i %d innych' % more if more > 0 else ''))
    if skills:
        lines.append('skille użytkownika przesłaniające skille projektu: %s' % ', '.join(skills))
    return lines


def process_check(epic_id, metrics, worktree, accepted):
    """W7: the facts of the check of the version of the process before the data is collected (`gh` not called yet).
    Matching definitions: the fact `<epic>/process/check` (status `match`, the expected version). Different ones: a
    refusal (exit 2) with the places, the number of files, the first paths and what to do, or, with an accepted reason,
    the fact `<epic>/process/check` (status `mismatch_accepted`). A check that cannot run: the fact
    `<epic>/process/missing/check` with the category, and the retro goes on."""
    reading, failure = read_process(metrics, worktree)
    if reading is None:
        return [{'id': '%s/process/missing/check' % epic_id,
                 'text': 'brak kontroli wersji procesu: metrics.py process nie dał wyniku: %s; nie wiadomo, czy '
                         'instrukcje na dysku są zgodne z HEAD worktree retro' % failure}]
    expected = reading['expected']
    ref = expected.get('ref') if isinstance(expected.get('ref'), str) else None
    describe = expected.get('describe') if isinstance(expected.get('describe'), str) else None
    version = '%s, %s' % (describe or 'bez tagu', ref[:12] if ref else 'brak SHA')
    places = differing_places(reading)
    skills = [s for s in _as_list(_as_dict(reading.get('user_level')).get('skills')) if isinstance(s, str)]
    data = {'ref': ref, 'describe': describe, 'fingerprint': expected.get('fingerprint'), 'places': places,
            'user_skills': skills, 'accepted': None}
    if reading['match'] and not places and not skills:
        data['status'] = 'match'
        return [{'id': '%s/process/check' % epic_id, 'data': data,
                 'text': 'wersja procesu: definicje na dysku zgodne z HEAD worktree retro (%s); %s'
                         % (version, PROCESS_SCOPE)}]
    lines = process_lines(places, skills) or ['niezgodność bez listy plików']
    if accepted is None:
        running = [e.get('epic') for e in _as_list(reading.get('active_epics'))
                   if isinstance(e, dict) and isinstance(e.get('epic'), str)]
        raise Refusal('wersja procesu: definicje na dysku różnią się od HEAD worktree retro (%s): %s%s. Co zrobić: %s'
                      % (version, '; '.join(lines), '; epiki w toku: %s' % ', '.join(running) if running else '',
                         PROCESS_HINT))
    data.update(status='mismatch_accepted', accepted=accepted)
    return [{'id': '%s/process/check' % epic_id, 'data': data,
             'text': 'wersja procesu: definicje na dysku różnią się od HEAD worktree retro (%s): %s; różnicę '
                     'zaakceptowano: %s; %s' % (version, '; '.join(lines), accepted, PROCESS_SCOPE)}]


def record_process_facts(epic_id, record):
    """W4: the fact `<epic>/process/record` of the field `process` of a native record and its `gaps` under `process`
    (tools/metrics, "Wersja procesu w rekordzie"), read defensively: a start accepted despite a difference, changes of
    the definitions and failed checks during the epic, roles whose subagents loaded another definition
    (`loaded.by_role`), the number without a confirmation, a disk that was not checked. A record without the field (or
    with null) is the fact `<epic>/process/missing/record`; without a record, nothing (`<epic>/missing/record`)."""
    if record is None:
        return []
    gaps = [g[len('process: '):] for g in _as_list(record.get('gaps'))
            if isinstance(g, str) and g.startswith('process: ')]
    process = record.get('process')
    if not isinstance(process, dict):
        return [{'id': '%s/process/missing/record' % epic_id, 'data': {'gaps': gaps},
                 'text': 'rekord metryk bez pola process (start sprzed kontroli wersji procesu albo brak odczytu): '
                         'nie wiadomo, które instrukcje obowiązywały w epiku'}]
    expected = _as_dict(process.get('expected'))
    ref = expected.get('ref') if isinstance(expected.get('ref'), str) else None
    describe = expected.get('describe') if isinstance(expected.get('describe'), str) else None
    status, accepted = process.get('status'), process.get('accepted')
    disk_checked = isinstance(process.get('disk'), list)
    changes = []
    for change in _as_list(process.get('changes')):
        if isinstance(change, dict):
            files = sum(len(_as_list(_as_dict(p).get('differs'))) for p in _as_list(change.get('disk')))
            changes.append({k: change.get(k) for k in ('at', 'event', 'kind', 'reason', 'accepted')}
                           if change.get('kind') != 'disk' else
                           {'at': change.get('at'), 'event': change.get('event'), 'kind': 'disk', 'files': files,
                            'accepted': change.get('accepted')})
    loaded = _as_dict(process.get('loaded'))
    summary, by_role = _as_dict(loaded.get('summary')), _as_dict(loaded.get('by_role'))
    differs_roles = sorted(role for role, cell in by_role.items()
                           if isinstance(cell, dict) and _number(cell.get('differs')) and cell['differs'] > 0)
    parts = ['oczekiwana %s (%s)' % (describe or 'bez tagu', ref[:12] if ref else 'brak SHA')]
    if status != 'checked':
        parts.append('odczyt %s: %s%s' % (status or 'nieznany', process.get('reason') or 'brak powodu',
                                          ', zaakceptowano: %s' % accepted if accepted else ''))
    if process.get('match') is True:
        parts.append('dysk przy starcie zgodny')
    elif process.get('match') is False:
        parts.append('start zaakceptowany mimo różnicy definicji (powód: %s)' % (accepted or 'brak akceptacji'))
    if not disk_checked:
        parts.append('dysk niesprawdzony (%s)' % (process.get('disk_reason') or process.get('reason') or 'brak powodu'))
    parts.append('zmiany definicji w trakcie: %d' % len([c for c in changes if c['kind'] == 'disk']))
    failed = [c for c in changes if c['kind'] == 'check_failed']
    if failed:
        parts.append('nieudane kontrole w trakcie: %d' % len(failed))
    if differs_roles:
        parts.append('subagenci z definicją inną niż oczekiwana: %s' % ', '.join(differs_roles))
    unconfirmed = summary.get('unconfirmed')
    parts.append('bez potwierdzenia: %s' % (unconfirmed if _number(unconfirmed) else 'brak danych'))
    return [{'id': '%s/process/record' % epic_id,
             'text': 'wersja procesu epiku z rekordu metryk: %s (te fakty mówią, które instrukcje obowiązywały; brak '
                     'potwierdzenia nie jest zgodnością)' % '; '.join(parts),
             'data': {'status': status, 'ref': ref, 'describe': describe, 'match': process.get('match'),
                      'accepted': accepted, 'disk_checked': disk_checked, 'changes': changes, 'summary': summary,
                      'by_role': by_role, 'differs_roles': differs_roles, 'gaps': gaps}}]


def epic_title_id(title):
    return (title or '').partition(':')[0].strip().lower()


def make_gates(run):
    """The origin gates of the shop and AO repositories on `run` (a test runner answers the permission calls too):
    the author of AO content is judged by the permission in the AO repository."""
    return {SHOP: github_trust.Gate(run, repo=SHOP), AO_REPOSITORY: github_trust.Gate(run, repo=AO_REPOSITORY)}


def external_record(source, number, record):
    """An entry of `external` in input.json: only where an untrusted record is, never what it says."""
    return {'source': source, 'number': number, 'author': record.get('author'), 'url': record.get('url')}


def collect(epic_id, paths, run, gates=None, metrics=None, accept=None):
    """The contents of input.json for the retrospective of epic_id (see the module docstring). GitHub content (epic
    issues and their comments, task PRs, the open retro PR, open issues) comes through the origin gates `gates`
    (default: make_gates(run)); a record of an author without write access gives only `external` or an untrusted
    record ({number, author, url, trusted: false}). `gh` calls without content (states, merge dates, counts) do not
    use the gate. `metrics` is the runner of tools/metrics/metrics.py (make_metrics_runner); without it, or when a
    call fails, the measurement is the fact "brak danych pomiaru" and the rest is collected as usual. The native epics
    (native_epics, in `paths['metrics_state']`) are a part of the history next to the AO runs."""
    epics = discover_epics(paths['ao_home'])
    natives, skipped = scan_native(paths.get('metrics_state'), paths['ao_home'])
    if epic_id not in [e['id'] for e in epics + natives]:
        check_collectable(epic_id, paths)
    # W7: the version of the process first, before any `gh` call; a difference without `accept` is a refusal
    version_facts = process_check(epic_id, metrics, paths.get('worktree') or '.', accept_reason(accept))
    gates = gates or make_gates(run)
    shop = gates[SHOP]
    commit = run(['git', 'rev-parse', 'origin/main']).strip()
    issues, trusted_epics, external = {}, set(), []
    for issue in shop.issues(state='all', labels=('epic',), limit=200, fields=('title', 'comments')):
        if not issue['trusted']:
            # no title: the issue cannot be told from an epic issue, so it never takes an epic's place
            external.append(external_record('epic_issue', issue['number'], issue))
            continue
        trusted_epics.add(issue['number'])
        comments = []
        for c in issue.get('comments') or []:
            if c['trusted']:
                comments.append(c['body'])
            else:
                external.append(external_record('epic_comment', issue['number'], c))
        issues.setdefault(epic_title_id(issue.get('title')), {'number': issue['number'], 'comments': comments})

    entries, incidents, facts, measurements = [], [], version_facts + skipped_facts(skipped), {}
    handoff = materials = None   # an AO run has neither
    if epic_id in [e['id'] for e in natives]:
        # R1: the record of a native epic is refreshed first (decide and trend read it); a failed refresh leaves the
        # saved record, which is read below
        try:
            metrics_json(metrics, ['epic', epic_id], ())
        except MeasurementError as e:
            facts.append(no_measurement(epic_id, 'epic', e))
    history, measured = measurement_of(epic_id, metrics)
    facts += measured
    for epic in epics:
        name = epic['id']
        state, merged_at = final_pr(run, epic['final_pr_number'])
        entries.append({'id': name, 'source': 'ao', 'final_pr': epic['final_pr_number'], 'pr_state': state,
                        'merged_at': merged_at, 'created_at': epic['created_at'],
                        'issue': (issues.get(name) or {}).get('number')})
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
            task_prs = shop.prs(state='merged', base='epic/%s' % name, limit=200, fields=('title', 'body'))
            external += [external_record('task_pr', p['number'], p) for p in task_prs if not p['trusted']]
            found += unasked_incidents(name, [p for p in task_prs if p['trusted']])
            facts += unasked_outside_facts(name, [p for p in task_prs if p['trusted']])
        found += optimization_incidents(name, history.get(name), merged_at)
        incidents += sorted(found, key=lambda i: (KIND_ORDER.index(i['kind']), i['at'] or 0, i['key']))
    records = {}
    for epic in natives:
        # R2: a native epic has the incidents of its metrics record and of the description of its final PR (through
        # the gate), no repair, manual_fix or no_sweep
        name, number = epic['id'], epic['final_pr_number']
        state, merged_at = final_pr(run, number)
        record = records[name] = read_native_record(paths['metrics_state'], name)
        issue = record.get('issue') if record else None
        entries.append({'id': name, 'source': 'native', 'final_pr': number, 'pr_state': state, 'merged_at': merged_at,
                        'created_at': epic['created_at'],
                        'issue': issue if isinstance(issue, int) else (issues.get(name) or {}).get('number')})
        if state != 'MERGED':
            facts.append({'id': '%s/missing/merge' % name,
                          'text': 'końcowy PR #%s nie jest zmergowany (stan %s)' % (number, state)})
        found, missing = native_record_incidents(name, record)
        facts += missing
        described = final_pr_record(shop, epic)
        if described is None:
            facts.append({'id': '%s/missing/pr-description' % name,
                          'text': 'brak opisu końcowego PR #%s (bramka pochodzenia nie zwróciła PR gałęzi %s)'
                                  % (number, epic['branch'] or 'brak gałęzi w pliku przebiegu')})
        elif not described['trusted']:
            external.append(external_record('final_pr', number, described))
            facts.append({'id': '%s/missing/pr-description' % name,
                          'text': 'opis końcowego PR #%s od autora bez prawa zapisu: tylko numer, autor i link '
                                  '(external)' % number})
        else:
            described_found, described_facts = native_pr_incidents(name, number, described)
            found += described_found
            facts += described_facts
        found += optimization_incidents(name, history.get(name), merged_at)
        incidents += sorted(found, key=lambda i: (KIND_ORDER.index(i['kind']), i['at'] or 0, i['key']))
        if name == epic_id:
            # P3: the handoff of the lead session and the pointers to the materials, for the collected epic only
            handoff, handoff_facts, handoff_external = native_handoff(shop, name, number)
            materials, material_facts = native_materials(name, number, paths, run, commit)
            facts += handoff_facts + material_facts + record_process_facts(name, record)
            external += handoff_external
    entries.sort(key=lambda e: (e['merged_at'] is None, e['merged_at'] or 0, e['created_at'] or 0, e['id']))
    order = {e['id']: n for n, e in enumerate(entries)}
    incidents.sort(key=lambda i: order[i['epic']])   # stable: keeps the order inside an epic

    # The board reads the open epic issues through the same gate; its titles come from the project board, so only
    # epics whose issue is trusted are shown.
    board = next_epic.board_epics(run, gate=shop)
    running = [e for e in board if e['state'] in ('in_progress', 'prepared') and e['number'] in trusted_epics]
    merged = [e for e in entries if e['merged_at'] is not None]
    last = max(merged, key=lambda e: (e['merged_at'], e['id'])) if merged else None
    if last and last['source'] == 'native':
        last_numbers = native_numbers(records.get(last['id']))
    else:
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
    # Counts only (number and state, no content): without the gate.
    agent = gh_list(run, ['gh', 'issue', 'list', '--repo', SHOP, '--label', 'agent', '--state', 'all', '--json',
                          'number,state', '--limit', '1000'])
    status.append({'id': 'status/agent-issues', 'open': sum(1 for i in agent if i.get('state') == 'OPEN'),
                   'closed': sum(1 for i in agent if i.get('state') == 'CLOSED')})
    status.append({'id': 'status/pending-retro', 'epics': pending_epics(entries, paths['retro_home'])})

    registers = read_registers(paths['retro_home'])
    retro_pr = open_retro_pr(shop, paths['retro_home'])
    fields = ('title', 'body', 'labels')
    open_issues = {
        'ao': gates[AO_REPOSITORY].issues(state='open', limit=200, fields=fields),
        'shop': shop.issues(state='open', labels=('tech-debt', 'agent'), limit=200, fields=fields),
    }
    for items in open_issues.values():
        for item in items:
            if item['trusted']:
                item['body'] = item.get('body') or ''   # whole: the duplicate check compares the text
    external.sort(key=lambda e: (e['source'], e['number'], e['url'] or ''))
    effects, effect_facts = optimization_effects(epic_id, registers['applied'], metrics)
    facts += effect_facts
    return {'schema': 1, 'epic': epic_id, 'generated_at': int(time.time()), 'commit': commit, 'epics': entries,
            'incidents': incidents, 'facts': facts, 'optimization_effects': effects, 'status': status,
            'registers': registers, 'open_retro_pr': retro_pr, 'open_issues': open_issues, 'external': external,
            'handoff': handoff, 'materials': materials}


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


def read_state_strict(retro_home, epic_id):
    """Like `read_state`, but a state.json that exists and cannot be read as a JSON object is a refusal: a command
    that writes the state must not replace it with one that has lost `pr` and `decisions_open`."""
    path = os.path.join(retro_home, epic_id, 'state.json')
    try:
        with open(path, encoding='utf-8') as f:
            state = json.load(f)
    except FileNotFoundError:
        return {}
    except ValueError as e:
        raise Refusal('state.json of %s is unreadable (%s); repair or remove %s' % (epic_id, e, path))
    if not isinstance(state, dict):
        raise Refusal('state.json of %s is not a JSON object; repair or remove %s' % (epic_id, path))
    return state


def state_dir(retro_home, epic_id):
    return os.path.join(retro_home, epic_id)


def save_state(retro_home, epic_id, state):
    write_json(state_dir(retro_home, epic_id), 'state.json', state)


def open_epic_state(retro_home, epic_id, command):
    """The state.json of an epic for a command that changes it; a finished retro (`done`, `skipped`) is refused."""
    check_epic_name(epic_id)
    state = read_state_strict(retro_home, epic_id)
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
    deferrals and the last reason. Epics with a finished retro cost no `gh` call. The epics are the AO runs and the
    native epics (a run file with a final PR in --metrics-state)."""
    entries = []
    natives, skipped = scan_native(args.metrics_state, args.ao_home)
    for name, why in skipped:
        print('retro: run file %s skipped: %s' % (name, SKIPPED_RUN_FILE[why][1]), file=sys.stderr)
    for epic in discover_epics(args.ao_home) + natives:
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
            readable = isinstance(other.get('run_id'), str) and isinstance(other.get('at'), (int, float)) \
                and not isinstance(other.get('at'), bool)
            if readable:
                age, what = now() - other['at'], 'the lock of run %s (epic %s)' % (other['run_id'], other.get('epic'))
            else:
                # empty or half-written (the moment between O_EXCL and the write of another process): by its mtime
                try:
                    age = now() - os.path.getmtime(lock_path(args.retro_home))
                except FileNotFoundError:
                    continue
                what = 'an unreadable lock'
            if not readable or other['run_id'] != run_id:
                if age < LOCK_MAX_AGE_S:
                    raise Refusal('%s is %d min old, younger than 6 h' % (what, age // 60))
                warning = 'took over %s, older than 6 h' % what
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
        state = read_state_strict(args.retro_home, epic_id)
        if args.done:
            state.update({'state': 'done', 'done_at': now()})
            save_state(args.retro_home, epic_id, state)
        elif state.get('state') == 'running':
            del state['state']
            save_state(args.retro_home, epic_id, state)
    os.remove(lock_path(args.retro_home))
    return {'unlocked': True, 'epic': epic_id}


# ------------------------------------------------------------------------- observe, reject, sync (registers)

def epic_states(retro_home, warnings=None):
    """[(epic, state)] of every `<retro-home>/<epic>/state.json`, by epic name. An unreadable one is a refusal,
    or, when a `warnings` list is given, a warning in it and the state is left out."""
    try:
        names = sorted(os.listdir(retro_home))
    except OSError:
        return []
    found = []
    for name in names:
        if not os.path.isfile(os.path.join(retro_home, name, 'state.json')):
            continue
        try:
            state = read_state_strict(retro_home, name)
        except Refusal as e:
            if warnings is None:
                raise
            warnings.append(str(e))
            continue
        if state:
            found.append((name, state))
    return found


def drop_carried(retro_home, keys):
    """Removes the carried rows of the keys (they were reported again, applied or rejected)."""
    rows = read_jsonl(register_path(retro_home, 'carried'))
    kept = [r for r in rows if r.get('key') not in keys]
    if len(kept) != len(rows):
        write_jsonl(register_path(retro_home, 'carried'), kept)


def load_proposals(directory):
    try:
        with open(os.path.join(directory, 'proposals.json'), encoding='utf-8') as f:
            proposals = json.load(f)
    except (OSError, ValueError) as e:
        raise Refusal('cannot read proposals.json in %s: %s' % (directory, e))
    if not isinstance(proposals, dict) or not isinstance(proposals.get('items'), list):
        raise Refusal('proposals.json in %s has no list of items' % directory)
    check_epic_name(proposals.get('epic'))
    return proposals


def committed_numbers(value, items):
    """The numbers of the fix items committed in the retro PR given by `--committed` (`none` or `N[,N...]`), or None
    without the option (every fix item is in the PR). While the series is in progress the deferred fix items are not
    committed, so they never become items of the PR (docs/features/ash-v1/spec.md, R6)."""
    if value is None:
        return None
    if value.strip() == 'none':
        return set()
    fixes = {i.get('n') for i in items if isinstance(i, dict) and i.get('kind') == 'fix'}
    names = value.split(',')
    if not all(re.fullmatch(r'\s*[0-9]+\s*', name) for name in names):
        raise Refusal('--committed is `none` or numbers of fix items such as 1,3 (got %r)' % value)
    numbers = {int(name) for name in names}
    if not numbers <= fixes:
        raise Refusal('--committed names no fix item of proposals.json: %s (fix items: %s)'
                      % (', '.join(str(n) for n in sorted(numbers - fixes)),
                         ', '.join(str(n) for n in sorted(fixes)) or 'none'))
    return numbers


def cmd_observe(args):
    """Appends to observations.jsonl the observations of proposals.json and the key and incidents of every `fix` and
    `issue` item (a row already there, same key, epic and incidents, is not repeated). Records in the state of the
    epic: the issue items as `decisions_open` and, with `--pr`, the fix items as the items of that retro PR (they leave
    the carried register, since they are reported again)."""
    if args.pr is not None and not filled(args.branch):
        raise Refusal('--pr needs --branch')
    if args.committed is not None and args.pr is None:
        raise Refusal('--committed needs --pr')
    proposals = load_proposals(args.directory)
    committed = committed_numbers(args.committed, proposals['items'])
    epic_id, moment = proposals['epic'], now()
    state = read_state_strict(args.retro_home, epic_id)   # before anything is written
    rows, fixes, issues = [], [], []
    for observation in proposals.get('observations') or []:
        if not (isinstance(observation, dict) and filled(observation.get('key'))
                and isinstance(observation.get('incidents'), list)):
            raise Refusal('an observation needs a key and a list of incidents: %r' % (observation,))
        rows.append({'key': observation['key'], 'title': observation.get('title') or '',
                     'incidents': observation['incidents']})
    for item in proposals['items']:
        if not isinstance(item, dict) or item.get('kind') not in ('fix', 'issue'):
            continue
        incidents = id_list(item.get('evidence'))
        if not filled(item.get('key')) or incidents is None:
            raise Refusal('item %s needs a key and evidence' % item.get('n'))
        rows.append({'key': item['key'], 'title': item.get('title') or '', 'incidents': incidents})
        entry = {'n': item.get('n'), 'kind': item['kind'], 'key': item['key'], 'title': item.get('title') or '',
                 'incidents': incidents, 'epic': epic_id}
        if item['kind'] == 'fix':
            if committed is None or item.get('n') in committed:
                fixes.append(entry)
        else:
            issues.append(dict(entry, repo=item.get('repo'), existing_issue=item.get('existing_issue')))
    path = register_path(args.retro_home, 'observations')
    seen = {(r.get('key'), r.get('epic'), tuple(sorted(r.get('incidents') or []))) for r in read_jsonl(path)}
    added = []
    for row in rows:
        token = (row['key'], epic_id, tuple(sorted(row['incidents'])))
        if token not in seen:
            seen.add(token)
            added.append(dict(row, epic=epic_id, at=moment))
    append_jsonl(path, added)
    # The issue items become the open decisions once per proposals.json: observing the same proposals again (the PR
    # recorded after `gh pr create`, a deferred fix committed after "tak <n>") keeps what `issues` and `reject` settled.
    digest = hashlib.sha256(json.dumps(proposals, sort_keys=True, ensure_ascii=False).encode('utf-8')).hexdigest()
    if state.get('observed_proposals') != digest or not isinstance(state.get('decisions_open'), list):
        state['decisions_open'] = issues
        state['observed_proposals'] = digest
    if args.pr is not None:
        state['pr'] = {'number': args.pr, 'branch': args.branch, 'items': fixes}
        drop_carried(args.retro_home, {f['key'] for f in fixes})
    save_state(args.retro_home, epic_id, state)
    return {'epic': epic_id, 'observations': len(added), 'pr_items': len(fixes) if args.pr is not None else None,
            'decisions_open': len(state['decisions_open'])}


def cmd_reject(args):
    """A conscious rejection of PR items (`bez N`: `--key`; `odrzuć wszystko`: `--all`, or `--pr N` for one PR) or of
    an issue proposal (`odrzuć N`: only `--key`). The undecided item leaves the state of its epic (an item of an
    unresolved retro PR or a `decisions_open` entry) and is written to rejected.jsonl as {key, incidents, reason,
    rejected_at, pr}. `--all` and `--pr` take only the items of unresolved retro PRs, never `decisions_open`. The only
    command that writes that register."""
    if sum(bool(selector) for selector in (args.key, args.all, args.pr is not None)) != 1:
        raise Refusal('give --key, --all or --pr (exactly one)')
    reason = clean_reason(args.reason)
    if args.epic:
        check_epic_name(args.epic)
    found, changed = {}, []
    for epic_id, state in epic_states(args.retro_home):
        if args.epic and epic_id != args.epic:
            continue
        touched = False
        pr = state.get('pr')
        number = pr.get('number') if isinstance(pr, dict) and not pr.get('outcome') else None
        sources = []
        if number is not None and isinstance(pr.get('items'), list) and (args.pr is None or args.pr == number):
            sources.append((pr, 'items', number))
        if args.key and isinstance(state.get('decisions_open'), list):
            sources.append((state, 'decisions_open', None))
        for holder, field, pr_number in sources:
            kept = []
            for item in holder[field]:
                if isinstance(item, dict) and (not args.key or item.get('key') == args.key):
                    token = (item.get('key'), pr_number)
                    found.setdefault(token, [])
                    found[token] += [i for i in item.get('incidents') or [] if i not in found[token]]
                    touched = True
                else:
                    kept.append(item)
            holder[field] = kept
        if touched:
            changed.append((epic_id, state))
    if not found:
        raise Refusal('no undecided item %s' % ('with key %r' % args.key if args.key
                                                else 'of PR #%d' % args.pr if args.pr is not None
                                                else 'to reject'))
    moment = now()
    append_jsonl(register_path(args.retro_home, 'rejected'),
                 [{'key': key, 'incidents': incidents, 'reason': reason, 'rejected_at': moment, 'pr': number}
                  for (key, number), incidents in sorted(found.items(), key=lambda kv: (str(kv[0][0]), kv[0][1] or 0))])
    drop_carried(args.retro_home, {key for key, _ in found})
    for epic_id, state in changed:
        save_state(args.retro_home, epic_id, state)
    return {'rejected': [{'key': key, 'pr': number} for key, number in sorted(found, key=lambda t: str(t))]}


def cmd_sync(args, run):
    """The outcome of every retro PR the states point at that is not settled yet: a MERGED PR writes its items to
    applied.jsonl ({key, incidents, pr, merged_at}); a PR CLOSED without a merge writes them to carried.jsonl (a
    technical close: they are reported again) and never to rejected.jsonl, whose rows come only from `reject` (an
    item rejected earlier has left the state, so it is in neither). The state of the PR gets `outcome`, so a second
    `sync` asks GitHub about nothing that is settled."""
    groups, warnings = {}, []
    for epic_id, state in epic_states(args.retro_home, warnings):
        pr = state.get('pr')
        if isinstance(pr, dict) and isinstance(pr.get('number'), int) and not pr.get('outcome'):
            groups.setdefault(pr['number'], []).append((epic_id, state))
    results = []
    for number in sorted(groups):
        outcome, merged_at = final_pr(run, number)
        if outcome not in ('MERGED', 'CLOSED'):
            results.append({'pr': number, 'state': outcome, 'applied': [], 'carried': []})
            continue
        if outcome == 'MERGED' and merged_at is None:
            raise RetroError('PR #%d is MERGED without a merge date' % number)
        items = {}
        for _, state in groups[number]:
            for item in state['pr'].get('items') or []:
                if isinstance(item, dict) and filled(item.get('key')):
                    entry = items.setdefault(item['key'], {'title': item.get('title') or '', 'incidents': []})
                    entry['incidents'] += [i for i in item.get('incidents') or [] if i not in entry['incidents']]
        if outcome == 'MERGED':
            known = {(r.get('key'), r.get('pr')) for r in read_jsonl(register_path(args.retro_home, 'applied'))}
            append_jsonl(register_path(args.retro_home, 'applied'),
                         [{'key': key, 'incidents': entry['incidents'], 'pr': number, 'merged_at': int(merged_at)}
                          for key, entry in items.items() if (key, number) not in known])
            drop_carried(args.retro_home, set(items))
        else:
            rows = [r for r in read_jsonl(register_path(args.retro_home, 'carried')) if r.get('key') not in items]
            rows += [{'key': key, 'title': entry['title'], 'incidents': entry['incidents'], 'pr': number,
                      'closed_at': now()} for key, entry in items.items()]
            write_jsonl(register_path(args.retro_home, 'carried'), rows)
        for epic_id, state in groups[number]:
            state['pr']['outcome'] = 'merged' if outcome == 'MERGED' else 'closed'
            save_state(args.retro_home, epic_id, state)
        merged = outcome == 'MERGED'
        results.append({'pr': number, 'state': outcome, 'applied': list(items) if merged else [],
                        'carried': [] if merged else list(items)})
    return {'prs': results, 'warnings': warnings}


# ------------------------------------------------------------------------------------------------------- issues

ISSUE_REPOSITORIES = {'ao': AO_REPOSITORY, 'shop': SHOP}
SHOP_ISSUE_LABELS = ('tech-debt', 'agent')
# GitHub refuses a longer body of an issue or a comment and a longer title of an issue: such a text is an error with
# the name of its fields (validate, issues), never a text cut for GitHub.
GITHUB_BODY_LIMIT = 65536
GITHUB_TITLE_LIMIT = 256


def issue_text(epic_id, item):
    """The comment on an existing issue (the new incidents of the problem) or the body of a new one."""
    incidents = '\n'.join('- `%s`' % i for i in item['evidence'])
    if item.get('existing_issue') is not None:
        return ('Retrospektywa epiku `%s`: nowe incydenty tego problemu.\n\n%s\n\nIncydenty:\n%s\n\n'
                'Przyczyna źródłowa: %s\n\nJak sprawdzimy, że działa: %s\n'
                % (epic_id, item['title'], incidents, item['cause'], item['verify']))
    return ('%s\n\n---\nZgłoszone przez `/retro` po epiku `%s`.\n\nIncydenty:\n%s\n\nPrzyczyna źródłowa: %s\n\n'
            'Jak sprawdzimy, że działa: %s\n' % (item['body'].strip(), epic_id, incidents, item['cause'],
                                                  item['verify']))


def github_limit_errors(epic_id, item):
    """The messages (with the names of the fields) for the texts of an issue item that GitHub would refuse: the title
    of a new issue over GITHUB_TITLE_LIMIT, the text of issue_text over GITHUB_BODY_LIMIT. [] for an item that lacks
    the fields (the other checks of validate report it)."""
    n, existing = item.get('n'), item.get('existing_issue') is not None
    errors = []
    if not existing and filled(item.get('title')) and len(item['title']) > GITHUB_TITLE_LIMIT:
        errors.append('item %s: title has %d characters; GitHub takes at most %d in the title of an issue'
                      % (n, len(item['title']), GITHUB_TITLE_LIMIT))
    fields = ('title', 'cause', 'verify') if existing else ('body', 'cause', 'verify')
    if id_list(item.get('evidence')) is not None and all(filled(item.get(field)) for field in fields):
        length = len(issue_text(epic_id, item))
        if length > GITHUB_BODY_LIMIT:
            errors.append('item %s: the text of the %s (%s and the incidents) has %d characters; GitHub takes at most '
                          '%d: shorten %s' % (n, 'comment' if existing else 'issue', ', '.join(fields), length,
                                              GITHUB_BODY_LIMIT, ', '.join(fields)))
    return errors


def approved_numbers(tokens, issue_items, rejected=()):
    """The numbers named by `--approve` ("all" or a list such as `1, 3`), each of an issue item of the proposals that
    was not rejected; "all" is every issue item that was not."""
    names = [t for token in tokens for t in re.split(r'[\s,]+', token) if t]
    if names == ['all']:
        if not issue_items:
            raise Refusal('proposals.json has no issue items to approve')
        left = sorted(n for n in issue_items if n not in rejected)
        if not left:
            raise Refusal('every issue item of proposals.json was rejected')
        return left
    numbers = []
    for name in names:
        if not re.fullmatch(r'[0-9]+', name) or int(name) not in issue_items:
            raise Refusal('%r is not the number of an issue item of proposals.json (issues: %s)'
                          % (name, ', '.join(str(n) for n in sorted(issue_items)) or 'none'))
        if int(name) in rejected:
            raise Refusal('issue item %s (%r) was rejected' % (name, issue_items[int(name)].get('key')))
        if int(name) not in numbers:
            numbers.append(int(name))
    if not numbers:
        raise Refusal('--approve names no issue (use "all" or numbers such as 1,3)')
    return numbers


def cmd_issues(args, run):
    """Creates the approved issues (the labels `tech-debt` and `agent` for `shop`) and comments on the
    `existing_issue` of the approved comment items. The result of each item is kept in `<directory>/issues.json`
    (by the `key` of the item, `n` only informs), so
    a second run does not repeat an item that was done; the failure of one item (`gh`) does not stop the others and
    ends with exit code 1."""
    proposals = load_proposals(args.directory)
    epic_id = proposals['epic']
    issue_items = {}
    for item in proposals['items']:
        if isinstance(item, dict) and item.get('kind') == 'issue' and isinstance(item.get('n'), int):
            issue_items[item['n']] = item
    state = read_state_strict(args.retro_home, epic_id)   # before any `gh` call
    ledger_path = os.path.join(args.directory, 'issues.json')
    try:
        with open(ledger_path, encoding='utf-8') as f:
            ledger = json.load(f)
    except (OSError, ValueError):
        ledger = {}
    if not isinstance(ledger, dict):
        ledger = {}
    # an item that left `decisions_open` without being done was rejected (`reject --key`)
    undecided = {d.get('key') for d in state['decisions_open'] if isinstance(d, dict)} \
        if isinstance(state.get('decisions_open'), list) else None
    rejected = {n for n, item in issue_items.items()
                if undecided is not None and item.get('key') not in undecided and item.get('key') not in ledger}
    numbers = approved_numbers(args.approve, issue_items, rejected)
    for n in numbers:
        item = issue_items[n]
        needed = ('title', 'cause', 'verify') + (() if item.get('existing_issue') is not None else ('body',))
        if item.get('repo') not in ISSUE_REPOSITORIES or id_list(item.get('evidence')) is None \
                or not all(filled(item.get(field)) for field in needed):
            raise Refusal('item %d is not a complete issue proposal (run validate)' % n)
        too_long = github_limit_errors(epic_id, item)
        if too_long:
            raise Refusal('; '.join(too_long))
    results, failed = [], 0
    for n in numbers:
        item = issue_items[n]
        if item['key'] in ledger:
            results.append(dict(ledger[item['key']], n=n, status='already_done'))
            continue
        repository = ISSUE_REPOSITORIES[item['repo']]
        text = issue_text(epic_id, item)
        if item.get('existing_issue') is not None:
            command = ['gh', 'issue', 'comment', str(item['existing_issue']), '--repo', repository, '--body', text]
            status = 'commented'
        else:
            command = ['gh', 'issue', 'create', '--repo', repository, '--title', item['title'], '--body', text]
            if item['repo'] == 'shop':
                for label in SHOP_ISSUE_LABELS:
                    command += ['--label', label]
            status = 'created'
        try:
            url = run(command).strip().splitlines()
        except (next_epic.CommandError, ValueError) as e:   # ValueError: a NUL in the text cannot be an argument
            failed += 1
            results.append({'n': n, 'status': 'failed', 'error': str(e)})
            continue
        done = {'n': n, 'status': status, 'repo': repository, 'url': url[-1] if url else None}
        ledger[item['key']] = done
        write_json(args.directory, 'issues.json', ledger)
        results.append(dict(done, n=n))
        if isinstance(state.get('decisions_open'), list):
            state['decisions_open'] = [d for d in state['decisions_open']
                                       if not (isinstance(d, dict) and d.get('key') == item.get('key'))]
            save_state(args.retro_home, epic_id, state)
    print_json({'results': results})
    if failed:
        print('retro: %d of %d approved items failed (`gh`); run the command again to finish them'
              % (failed, len(numbers)), file=sys.stderr)
        return 1
    return 0


# -------------------------------------------------------------------------------------------------------- validate

PLAN_PATH = 'docs/features/agentic-software-house/plan.md'
ITEM_KINDS = ('fix', 'issue', 'status')
LIMITS = {'fix': 5, 'issue': 3}
CLASSES = ('corrective', 'planned', 'product')
ALLOWED_FILES = [re.compile(pattern) for pattern in (
    r'CLAUDE\.md', r'apps/[^/]+/CLAUDE\.md', r'\.claude/skills/[^/]+/SKILL\.md', r'\.claude/agents/[^/]+\.md',
    r'docs/features/(?:ao-run|prepare-epic|groom|agent-tester-ux|retro)/spec\.md',
    r'docs/adr/agentic-software-house\.md')]


def allowed_file(path):
    """A file a process fix may change: the list of docs/features/retro/spec.md, "Walidacja"."""
    if not isinstance(path, str) or any(ord(c) < 32 for c in path):
        return False
    if any(part in ('', '.', '..') for part in path.split('/')):
        return False
    return any(pattern.fullmatch(path) for pattern in ALLOWED_FILES)


def filled(value):
    return isinstance(value, str) and bool(value.strip())


def control_path(value, path):
    """The path of the first text in the value with a control character (other than a newline or a tab) or a line or
    paragraph separator, else None: such characters break commit messages, issues and `gh` arguments (a NUL)."""
    if isinstance(value, str):
        return path if any(c not in '\n\t' and unicodedata.category(c) in ('Cc', 'Zl', 'Zp') for c in value) else None
    children = []
    if isinstance(value, dict):
        children = [('%s.%s' % (path, k), v) for k, v in value.items()]
    elif isinstance(value, list):
        children = [('%s[%d]' % (path, n), v) for n, v in enumerate(value)]
    for child_path, child in children:
        found = control_path(child, child_path)
        if found:
            return found
    return None


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
        self.trusted_issues = {repo_name: {i.get('number') for i in issues.get(repo_name) or []
                                           if isinstance(i, dict) and i.get('trusted') is True}
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
            elif control_path(observation, 'observation'):
                self.add(None, 'field', '%s contains a control character' % control_path(observation, 'observation'))
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
            bad = control_path({k: v for k, v in item.items() if k != 'files'}, 'item %s' % n)
            if bad:
                self.add(n, 'field', '%s contains a control character (only newline and tab are allowed)' % bad)
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
        for message in github_limit_errors(self.input.get('epic'), item):
            self.add(n, 'field', message)
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
            repo_name = item.get('repo') if item.get('repo') in ('ao', 'shop') else None
            known = self.open_issues[repo_name] if repo_name else set()
            if isinstance(existing, bool) or not isinstance(existing, int) or existing not in known:
                self.add(n, 'existing_issue', 'item %s: existing_issue %r is not an open issue of the input data '
                                              '(repo %s)' % (n, existing, item.get('repo')))
            elif existing not in self.trusted_issues[repo_name]:
                self.add(n, 'existing_issue', 'item %s: existing_issue %r is an issue of an author without write '
                                              'access (repo %s): its content is unknown' % (n, existing, repo_name))

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
    common.add_argument('--metrics-state', default=paths['metrics_state'],
                        help='state of tools/metrics: run files of native epics and records')
    parser = argparse.ArgumentParser(description='Facts and registers for the /retro skill')
    commands = parser.add_subparsers(dest='command', required=True)
    collecting = commands.add_parser('collect', parents=[common], help='write input.json for an epic')
    collecting.add_argument('epic')
    collecting.add_argument('--out', help='directory for input.json (default: <retro-home>/<epic>)')
    collecting.add_argument('--worktree', help='checkout of origin/main whose tools/metrics/metrics.py is run and '
                                               'where the specification and the plan of a native epic are looked up '
                                               '(default: --repo)')
    collecting.add_argument('--projects', default=paths['projects'],
                            help='directory of the session transcripts (default: ~/.claude/projects)')
    collecting.add_argument('--accept-process-mismatch', metavar='REASON',
                            help='collect although the definitions on disk differ from HEAD of the worktree, with the '
                                 'reason (a fact of input.json)')
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
    observing = commands.add_parser('observe', parents=[common], help='append the observations of a run')
    observing.add_argument('directory')
    observing.add_argument('--pr', type=int, help='number of the retro PR of the run (records its fix items)')
    observing.add_argument('--branch', default='', help='branch of that PR')
    observing.add_argument('--committed', help='fix items committed in that PR: `none` or N[,N...] (default: all); '
                                               'the others are deferred while the series is in progress')
    rejecting = commands.add_parser('reject', parents=[common], help='record a conscious rejection')
    rejecting.add_argument('--key', default='', help='key of the item')
    rejecting.add_argument('--all', action='store_true', help='every item of the unresolved retro PRs (odrzuć wszystko)')
    rejecting.add_argument('--pr', type=int, help='every item of this retro PR')
    rejecting.add_argument('--epic', default='', help='only items of the state of this epic')
    rejecting.add_argument('--reason', default='', help='why')
    commands.add_parser('sync', parents=[common], help='settle the registers from the state of the retro PRs')
    issuing = commands.add_parser('issues', parents=[common], help='create the approved issues and comments')
    issuing.add_argument('directory')
    issuing.add_argument('--approve', nargs='+', required=True, metavar='N',
                         help='numbers of the approved issue items ("1,3") or "all"')
    validating = commands.add_parser('validate', parents=[common], help='check proposals.json of a run directory')
    validating.add_argument('directory')
    return parser


def print_json(value):
    print(json.dumps(value, ensure_ascii=False))


def main(argv=None, run=None, metrics=None):
    args = build_parser().parse_args(argv)
    try:
        if args.command == 'collect':
            check_epic_name(args.epic)
            paths = {'ao_home': args.ao_home, 'retro_home': args.retro_home, 'sweep_home': args.sweep_home,
                     'metrics_state': args.metrics_state, 'worktree': args.worktree or args.repo,
                     'projects': args.projects}
            # The gate's own runner keeps the output of a failed `gh api --include`, so a 404 is an untrusted author.
            gates = None if run else make_gates(github_trust.make_runner(args.repo))
            data = collect(args.epic, paths, run or next_epic.make_runner(args.repo), gates,
                                  metrics or make_metrics_runner(args.repo, root=args.worktree, state=args.metrics_state),
                           accept=args.accept_process_mismatch)
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
        elif args.command == 'observe':
            print_json(cmd_observe(args))
        elif args.command == 'reject':
            print_json(cmd_reject(args))
        elif args.command == 'sync':
            print_json(cmd_sync(args, run or next_epic.make_runner(args.repo)))
        elif args.command == 'issues':
            return cmd_issues(args, run or next_epic.make_runner(args.repo))
        elif args.command == 'validate':
            return cmd_validate(args)
    except (Refusal, ValueError) as e:
        print('retro: %s' % e, file=sys.stderr)
        return 2
    except (next_epic.CommandError, github_trust.GateError, RetroError, OSError) as e:
        print('retro: %s' % e, file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())

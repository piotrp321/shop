#!/usr/bin/env python3
"""Paths audit and measurement of tasks running at once in one epic (step 19b, docs/features/parallel-work/spec.md,
"Pomiar zadań naraz"). Python 3.9+, standard library only. Only a report: it blocks nothing and writes nothing.

    task_pairs.py EPIC [--ao-home DIR] [--repo DIR] [--setup-diag DIR]

--ao-home defaults to ~/.local/state/ao-EPIC: the first *.db file there (sorted by name) that has the row EPIC in
`epics` is the AO database, opened read only (sqlite URI mode=ro: no write, no journal). --repo (default: the current
directory) is where `git` and `gh` run, each with a COMMAND_TIMEOUT limit; --setup-diag defaults to
~/.local/state/ao-setup. Times in the output are seconds (one decimal) or local ISO timestamps. One JSON line, exit 0:

  epic          {id, plan_path, plan_sha, status}
  audit.declarations  {value: plan_sha, note: null}, or {value: null, note: the reason} when the plan at plan_sha
                cannot be read (`git show` fails, no manifest block): every declared and declared_conflicts is then
                null and all other metrics are still computed
  audit.tasks   one item per task of kind `plan` with a PR (pr_number), in AO order:
                  declared   `paths` of the task in the manifest at the epic's plan_sha (`git show plan_sha:plan_path`,
                             the declaration in force while the task ran), else null with
                  declared_note  NOTE_NO_PATHS (task without paths), NOTE_NOT_IN_PLAN (no such task in the manifest)
                             or NOTE_PLAN_UNREADABLE; null when declared is set
                  changed    the files of the PR: `git diff --name-only S^1 S` for merged_sha S when the commit is in the
                             checkout (the first parent is the epic branch before the merge, for a squash and for a merge
                             commit), else `gh pr view N --json files`; null when both fail. A rename lists only
                             the new path, and gh may truncate the files of a very large PR
                  source     merge_commit (2+ parents), squash (1 parent), gh, or null
                  undeclared files of `changed` inside no `declared` path (by segments, casefold), sorted; null when
                             declared or changed is null
  audit.pairs   tasks that list each other in `parallel_with` (AO database): {tasks, declared_conflicts,
                actual_conflicts}, parallel_gate.task_conflicts on the declarations (with the CLAUDE.md files tracked at
                plan_sha) and on the changed files (each a file); null without the data
  concurrency   attempts of `plan` tasks (an attempt without ended_at, open: true, runs to now, or to the epic end
                when the epic is closed):
                  attempts            [{task_id, number, start, end, seconds, open}]
                  attempts_sum_s      the sum of their durations
                  busy_s              the time with at least one attempt open
                  concurrent_s        the measured time with at least two attempts open (real concurrency)
                  pairs               [{tasks, overlap_s}] for every two tasks with overlapping attempts (with three
                                      at once the overlaps of the pairs add up to more than concurrent_s)
                  theoretical_saving  (attempts_sum_s - busy_s) / attempts_sum_s (3 decimals; null without attempts):
                                      the saving
                                      under the assumption that serially the attempts would take the same time; NOT a
                                      speedup
                  epic_wall_s         created_at of the epic to its end: the last epic_done event, else updated_at of a
                                      DONE or BLOCKED epic, else now (epic_open: true)
                  attempts_to_wall    attempts_sum_s / epic_wall_s (3 decimals): a load ratio, not a speedup
  conflicts     fix_requested events with reason `conflict` of the epic's tasks: {by_task: {id: n}, total}
  fix_rounds    fix_requested events of the epic's tasks by reason
  repairs       {repair: {tasks, attempts}, fix: {tasks, attempts}}
  interventions human: AO's epic._interventions over the task events (answer_recorded, status_changed with reason
                resumed, hold_released by operator) plus epic_resumed of the epic; operational: config_changed in the
                epic window plus state_file_edited of its tasks (also config_changes, state_file_edits)
  waits         queue: for tasks with a non-empty parallel_with, PENDING (status_changed) to the next attempt_started
                ({items: [{task_id, kind first|retry, from, to, wait_s}], count, total_s, waiting: tasks still in the
                queue}); cooldowns and host_pauses: {count, total_s} of their intervals inside the epic window
                (cooldown_set cut short by cooldown_released, as AO's store.cooldowns); usage_warnings: count;
                resources: {value: null, note}: AO records no wait for resources; setup_failures: logs
                ao-<home>-*-YYYYMMDD-HHMMSS-*.log of this AO home (lifecycle.home_id of the database) in --setup-diag
                inside the epic window (no directory: 0)

AO wrote run_started, config_changed, state_file_edited, cooldown_set, host_pause and usage_warning from the same
release on (AO-3, AO-8 and AO-13). When no run_started event is at or before the first attempt of the epic (or its end
without attempts), the AO that ran it may not have written them: operational interventions, cooldowns, host_pauses
and usage_warnings are then null (no data), not 0.

Exit codes: 0 report (also with an unreadable plan, see audit.declarations); 2 no database, an unreadable database
or no row EPIC in `epics`. A `git`/`gh` failure while reading a PR gives null for that item.
"""
import argparse
import glob
import hashlib
import json
import os
import sqlite3
import sys
import time
import urllib.request
from datetime import datetime

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.join(HERE, '..', 'planner'))
import next_epic  # noqa: E402  (the command runner with a timeout)
import pairs  # noqa: E402  (the name pattern of setup failure logs)
import parallel_gate  # noqa: E402  (the file rule of parallel work)
import plan_manifest  # noqa: E402  (the manifest block of a plan)

COMMAND_TIMEOUT = 60
RESOURCES_NOTE = 'AO nie zapisuje czekania na zasoby'
NOTE_PLAN_UNREADABLE = 'plan przy plan_sha nieczytelny'
NOTE_NOT_IN_PLAN = 'brak zadania w manifeście przy plan_sha'
NOTE_NO_PATHS = 'zadanie bez paths w manifeście przy plan_sha'
CLOSED = ('DONE', 'BLOCKED')


class DatabaseError(Exception):
    pass


class PlanError(Exception):
    """The plan at plan_sha cannot be read: the audit reports it, every other metric is still computed."""


def open_readonly(path):
    """The SQLite database in read-only mode: a write fails and no journal or WAL file is created."""
    uri = 'file:%s?mode=ro' % urllib.request.pathname2url(os.path.abspath(path))
    return sqlite3.connect(uri, uri=True)


def default_runner(repo):
    return next_epic.make_runner(repo, timeout=COMMAND_TIMEOUT)


def _data(text):
    try:
        value = json.loads(text) if text else {}
    except ValueError:
        return {}
    return value if isinstance(value, dict) else {}


def _list(text):
    try:
        value = json.loads(text) if text else []
    except ValueError:
        return []
    return [v for v in value if isinstance(v, str)] if isinstance(value, list) else []


def _read(path, epic_id):
    con = open_readonly(path)
    con.row_factory = sqlite3.Row
    try:
        epic = con.execute('SELECT epic_id, plan_path, plan_sha, status, created_at, updated_at FROM epics '
                           'WHERE epic_id = ?', (epic_id,)).fetchone()
        if epic is None:
            return None
        tasks = [dict(r) for r in con.execute(
            'SELECT task_id, kind, pr_number, merged_sha, parallel_with FROM tasks WHERE epic_id = ? '
            'ORDER BY created_at, rowid', (epic_id,))]
        ids = {t['task_id'] for t in tasks}
        attempts = [dict(r) for r in con.execute(
            'SELECT task_id, number, started_at, ended_at FROM attempts ORDER BY started_at, id')
            if r['task_id'] in ids]
        events = [dict(r) for r in con.execute('SELECT at, task_id, type, data FROM events ORDER BY id')]
    finally:
        con.close()
    for t in tasks:
        t['parallel_with'] = _list(t['parallel_with'])
    for e in events:
        e['data'] = _data(e['data'])
    return {'db': path, 'epic': dict(epic), 'tasks': tasks, 'attempts': attempts, 'events': events}


def load(ao_home, epic_id):
    """The data of the epic from the first *.db of ao_home that has it; DatabaseError otherwise."""
    paths = sorted(glob.glob(os.path.join(os.path.expanduser(ao_home), '*.db')))
    if not paths:
        raise DatabaseError('brak bazy AO (*.db) w %s' % ao_home)
    problems = []
    for path in paths:
        try:
            found = _read(path, epic_id)
        except sqlite3.Error as e:
            problems.append('%s: %s' % (path, e))
            continue
        if found is not None:
            return found
        problems.append('%s: brak epiku %s w tabeli epics' % (path, epic_id))
    raise DatabaseError('; '.join(problems))


# ------------------------------------------------------------------------------------------------------- audit

def declarations(run, sha, path):
    """{task id: paths or None} of the manifest in `git show sha:path`; PlanError when it cannot be read."""
    try:
        text = run(['git', 'show', '%s:%s' % (sha, path)])
    except next_epic.CommandError as e:
        raise PlanError('nie można odczytać planu %s:%s: %s' % (sha, path, e))
    try:
        manifest = json.loads(plan_manifest.extract_block(text))
        tasks = manifest['tasks']
        if not isinstance(tasks, list):
            raise TypeError
    except (plan_manifest.PlanError, ValueError, KeyError, TypeError) as e:
        raise PlanError('nieczytelny manifest planu %s:%s: %s' % (sha, path, e))
    found = {}
    for item in tasks:
        if isinstance(item, dict) and isinstance(item.get('id'), str):
            paths = item.get('paths')
            ok = isinstance(paths, list) and paths and all(isinstance(p, str) and p for p in paths)
            found[item['id']] = list(paths) if ok else None
    return found


def tracked_claude_md(run, sha):
    """The CLAUDE.md files of the tree at sha, or None when git cannot list it."""
    try:
        names = run(['git', 'ls-tree', '-r', '--name-only', sha]).splitlines()
    except next_epic.CommandError:
        return None
    return tuple(n for n in names if parallel_gate.is_claude_md(n))


def changed_files(run, pr_number, merged_sha):
    """(sorted files of the PR, source) or (None, None)."""
    if merged_sha:
        try:
            parents = run(['git', 'rev-list', '--parents', '-n', '1', merged_sha]).split()[1:]
            if parents:
                files = run(['git', 'diff', '--name-only', merged_sha + '^1', merged_sha]).splitlines()
                return sorted({f for f in files if f}), 'merge_commit' if len(parents) > 1 else 'squash'
        except next_epic.CommandError:
            pass
    try:
        files = json.loads(run(['gh', 'pr', 'view', str(pr_number), '--json', 'files']))['files']
        paths = [f['path'] for f in files]
        if not all(isinstance(p, str) and p for p in paths):
            raise TypeError
        return sorted(set(paths)), 'gh'
    except (next_epic.CommandError, ValueError, KeyError, TypeError):
        return None, None


def undeclared(declared, changed):
    if declared is None or changed is None:
        return None
    keys = [p.rstrip('/').casefold() for p in declared]
    return sorted(f for f in changed if not any(parallel_gate._inside(f.casefold(), k) for k in keys))


def audit(data, run):
    epic = data['epic']
    try:
        declared, plan_note = declarations(run, epic['plan_sha'], epic['plan_path']), None
    except PlanError as e:
        declared, plan_note = None, str(e)
    plan_tasks = [t for t in data['tasks'] if t['kind'] == 'plan']
    items, changed = [], {}
    for t in plan_tasks:
        if t['pr_number'] is None:
            continue
        files, source = changed_files(run, t['pr_number'], t['merged_sha'])
        changed[t['task_id']] = files
        mine = (declared or {}).get(t['task_id'])
        if declared is None:
            note = NOTE_PLAN_UNREADABLE
        elif t['task_id'] not in declared:
            note = NOTE_NOT_IN_PLAN
        else:
            note = None if mine is not None else NOTE_NO_PATHS
        items.append({'task_id': t['task_id'], 'pr_number': t['pr_number'], 'merged_sha': t['merged_sha'],
                      'declared': mine, 'declared_note': note, 'changed': files, 'source': source,
                      'undeclared': undeclared(mine, files)})
    claude_md, listed = None, False
    found = []
    for i, a in enumerate(plan_tasks):
        for b in plan_tasks[i + 1:]:
            if b['task_id'] not in a['parallel_with'] or a['task_id'] not in b['parallel_with']:
                continue
            if not listed:
                claude_md, listed = tracked_claude_md(run, epic['plan_sha']), True
            da, db = (declared or {}).get(a['task_id']), (declared or {}).get(b['task_id'])
            ca, cb = changed.get(a['task_id']), changed.get(b['task_id'])
            found.append({
                'tasks': [a['task_id'], b['task_id']],
                'declared_conflicts': None if da is None or db is None or claude_md is None
                else parallel_gate.task_conflicts(da, db, claude_md),
                'actual_conflicts': None if ca is None or cb is None else parallel_gate.task_conflicts(ca, cb)})
    return {'declarations': {'value': None if declared is None else epic['plan_sha'], 'note': plan_note},
            'tasks': items, 'pairs': found}


# ------------------------------------------------------------------------------------------------- time

def iso(stamp):
    return datetime.fromtimestamp(stamp).astimezone().isoformat(timespec='seconds')


def _s(seconds):
    return round(seconds, 1)


def epic_end(data, now):
    """(end, open): the last epic_done of the epic, else updated_at of a DONE or BLOCKED epic, else now."""
    epic = data['epic']
    done = [e['at'] for e in data['events'] if e['task_id'] is None and e['type'] == 'epic_done'
            and e['data'].get('epic_id') == epic['epic_id']]
    if done:
        return done[-1], False
    if epic['status'] in CLOSED:
        return epic['updated_at'], False
    return now, True


def _covered(intervals, minimum):
    """The time covered by at least `minimum` of the intervals at once."""
    points = sorted({p for low, high in intervals for p in (low, high)})
    total = 0.0
    for a, b in zip(points, points[1:]):
        middle = (a + b) / 2
        if sum(1 for low, high in intervals if low <= middle < high) >= minimum:
            total += b - a
    return total


def concurrency(data, now):
    kinds = {t['task_id']: t['kind'] for t in data['tasks']}
    order = {t['task_id']: i for i, t in enumerate(data['tasks'])}
    closed_at, epic_open = epic_end(data, now)
    items = []
    for a in data['attempts']:
        if kinds.get(a['task_id']) != 'plan':
            continue
        is_open = a['ended_at'] is None
        # An attempt left open in a closed epic ends with the epic, not now.
        end = (now if epic_open else closed_at) if is_open else a['ended_at']
        items.append({'task_id': a['task_id'], 'number': a['number'], 'begin': a['started_at'],
                      'stop': max(end, a['started_at']), 'open': is_open})
    intervals = [(i['begin'], i['stop']) for i in items]
    summed = sum(high - low for low, high in intervals)
    busy = _covered(intervals, 1)
    concurrent = _covered(intervals, 2)
    overlaps = {}
    for i, x in enumerate(items):
        for y in items[i + 1:]:
            if x['task_id'] == y['task_id']:
                continue
            overlap = min(x['stop'], y['stop']) - max(x['begin'], y['begin'])
            if overlap > 0:
                key = tuple(sorted((x['task_id'], y['task_id']), key=order.get))
                overlaps[key] = overlaps.get(key, 0.0) + overlap
    wall = max(0.0, closed_at - data['epic']['created_at'])
    return {
        'attempts': [{'task_id': i['task_id'], 'number': i['number'], 'start': iso(i['begin']),
                      'end': iso(i['stop']),
                      'seconds': _s(i['stop'] - i['begin']), 'open': i['open']} for i in items],
        'attempts_sum_s': _s(summed), 'busy_s': _s(busy), 'concurrent_s': _s(concurrent),
        'pairs': [{'tasks': list(k), 'overlap_s': _s(v)}
                  for k, v in sorted(overlaps.items(), key=lambda kv: (order[kv[0][0]], order[kv[0][1]]))],
        'theoretical_saving': round((summed - busy) / summed, 3) if summed > 0 else None,
        'epic_wall_s': _s(wall), 'epic_open': epic_open,
        'attempts_to_wall': round(summed / wall, 3) if wall > 0 else None}


# ------------------------------------------------------------------------------------------------ counts

def _task_events(data):
    ids = {t['task_id'] for t in data['tasks']}
    return [e for e in data['events'] if e['task_id'] in ids]


def conflicts(data):
    by_task, rounds = {}, {}
    for e in _task_events(data):
        if e['type'] != 'fix_requested':
            continue
        reason = str(e['data'].get('reason'))
        rounds[reason] = rounds.get(reason, 0) + 1
        if reason == 'conflict':
            by_task[e['task_id']] = by_task.get(e['task_id'], 0) + 1
    return {'by_task': by_task, 'total': sum(by_task.values())}, rounds


def repairs(data):
    result = {}
    for kind in ('repair', 'fix'):
        ids = {t['task_id'] for t in data['tasks'] if t['kind'] == kind}
        result[kind] = {'tasks': len(ids), 'attempts': sum(1 for a in data['attempts'] if a['task_id'] in ids)}
    return result


def recorded(data, now):
    """Whether the AO that ran the epic wrote run_started and the events of the same release: a run_started at or
    before the first attempt of the epic (its end when it has none)."""
    starts = [a['started_at'] for a in data['attempts']]
    limit = min(starts) if starts else epic_end(data, now)[0]
    return any(e['type'] == 'run_started' and e['at'] <= limit for e in data['events'])


def _in_window(data, now):
    begin, end = data['epic']['created_at'], epic_end(data, now)[0]
    return lambda at: begin <= at <= end


def interventions(data, now):
    task_events = _task_events(data)
    human = sum(1 for e in task_events if e['type'] == 'answer_recorded'
                or (e['type'] == 'status_changed' and e['data'].get('reason') == 'resumed')
                or (e['type'] == 'hold_released' and e['data'].get('by') == 'operator'))
    human += sum(1 for e in data['events'] if e['task_id'] is None and e['type'] == 'epic_resumed'
                 and e['data'].get('epic_id') == data['epic']['epic_id'])
    if not recorded(data, now):
        return {'human': human, 'operational': None, 'config_changes': None, 'state_file_edits': None}
    inside = _in_window(data, now)
    changes = sum(1 for e in data['events'] if e['task_id'] is None and e['type'] == 'config_changed'
                  and inside(e['at']))
    edits = sum(1 for e in task_events if e['type'] == 'state_file_edited')
    return {'human': human, 'operational': changes + edits, 'config_changes': changes, 'state_file_edits': edits}


# ------------------------------------------------------------------------------------------------- waits

def queue(data):
    parallel = {t['task_id'] for t in data['tasks'] if t['parallel_with']}
    items, waiting = [], []
    for task_id in [t['task_id'] for t in data['tasks'] if t['task_id'] in parallel]:
        since = None
        for e in data['events']:
            if e['task_id'] != task_id:
                continue
            if e['type'] == 'status_changed' and e['data'].get('to') == 'PENDING':
                since = (e['at'], 'first' if e['data'].get('from') == 'WAITING_DEPS' else 'retry')
            elif e['type'] == 'attempt_started' and since is not None:
                items.append({'task_id': task_id, 'kind': since[1], 'from': iso(since[0]), 'to': iso(e['at']),
                              'wait_s': _s(max(0.0, e['at'] - since[0])), 'at': since[0]})
                since = None
        if since is not None:
            waiting.append(task_id)
    items.sort(key=lambda i: i['at'])
    for i in items:
        del i['at']
    return {'items': items, 'count': len(items), 'total_s': _s(sum(i['wait_s'] for i in items)), 'waiting': waiting}


def _cooldowns(events):
    """(from, until) of the usage-limit cooldowns, as AO's store.cooldowns."""
    found = []
    for e in events:
        if e['type'] == 'cooldown_released':
            found = [(low, min(high, e['at'])) for low, high in found]
        elif e['type'] == 'cooldown_set':
            found.append(_interval(e['data'], 'from', 'until'))
    return [i for i in found if i is not None]


def _interval(data, low, high):
    a, b = data.get(low), data.get(high)
    if isinstance(a, (int, float)) and isinstance(b, (int, float)) and not isinstance(a, bool) \
            and not isinstance(b, bool):
        return a, b
    return None


def _clipped(intervals, begin, end):
    """Count and total of the union of the intervals inside the window: AO writes one event per attempt (host_pause)
    or per task (cooldown_set), so concurrent attempts record the same wait more than once."""
    parts = [(max(low, begin), min(high, end)) for low, high in intervals]
    merged = []
    for low, high in sorted(part for part in parts if part[1] > part[0]):
        if merged and low <= merged[-1][1]:
            merged[-1] = (merged[-1][0], max(merged[-1][1], high))
        else:
            merged.append((low, high))
    return {'count': len(merged), 'total_s': _s(sum(high - low for low, high in merged))}


def setup_failures(directory, db, begin, end):
    # Not pairs.setup_failures: it needs the home-prefix filter (ao-<home>-) and epoch-second bounds, not datetimes.
    prefix = 'ao-%s-' % hashlib.sha1(os.path.realpath(db).encode()).hexdigest()[:6]
    try:
        names = os.listdir(os.path.expanduser(directory))
    except OSError:
        return 0
    count = 0
    for name in names:
        match = pairs.LOG_NAME.match(name)
        if not match or not name.startswith(prefix):
            continue
        try:
            stamp = datetime.strptime(match.group(1), '%Y%m%d-%H%M%S').timestamp()
        except ValueError:
            continue
        if begin <= stamp <= end:
            count += 1
    return count


def waits(data, now, setup_diag):
    begin, end = data['epic']['created_at'], epic_end(data, now)[0]
    result = {'queue': queue(data), 'cooldowns': None, 'host_pauses': None, 'usage_warnings': None,
              'resources': {'value': None, 'note': RESOURCES_NOTE},
              'setup_failures': setup_failures(setup_diag, data['db'], begin, end)}
    if recorded(data, now):
        events = data['events']
        result['cooldowns'] = _clipped(_cooldowns(events), begin, end)
        pauses = [_interval(e['data'], 'from', 'to') for e in events if e['type'] == 'host_pause']
        result['host_pauses'] = _clipped([p for p in pauses if p is not None], begin, end)
        result['usage_warnings'] = sum(1 for e in events if e['type'] == 'usage_warning' and begin <= e['at'] <= end)
    return result


def report(data, run, now, setup_diag):
    epic = data['epic']
    found, rounds = conflicts(data)
    return {'epic': {'id': epic['epic_id'], 'plan_path': epic['plan_path'], 'plan_sha': epic['plan_sha'],
                     'status': epic['status']},
            'audit': audit(data, run), 'concurrency': concurrency(data, now), 'conflicts': found,
            'fix_rounds': rounds, 'repairs': repairs(data), 'interventions': interventions(data, now),
            'waits': waits(data, now, setup_diag)}


def main(argv=None, run=None, now=None):
    parser = argparse.ArgumentParser(description='Paths audit and 19b concurrency metrics of one AO epic (read only).')
    parser.add_argument('epic')
    parser.add_argument('--ao-home')
    parser.add_argument('--repo', default='.')
    parser.add_argument('--setup-diag', default='~/.local/state/ao-setup')
    args = parser.parse_args(sys.argv[1:] if argv is None else argv)
    now = time.time() if now is None else now
    try:
        data = load(args.ao_home or '~/.local/state/ao-%s' % args.epic, args.epic)
        result = report(data, run or default_runner(args.repo), now, args.setup_diag)
    except DatabaseError as e:
        print(e, file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())

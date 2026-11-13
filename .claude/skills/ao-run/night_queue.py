#!/usr/bin/env python3
"""Night queue of several epics for the /ao-run skill. Python 3.9+, standard library only.

  night_queue.py [--repo DIR] [--state DIR] scope <spec.md>
  night_queue.py [--repo DIR] [--state DIR] start <dir>   (stdin: the `plan` object of get_usage)
  night_queue.py [--repo DIR] [--state DIR] done <dir>    (stdin: the `plan` object of get_usage)
  night_queue.py [--repo DIR] [--state DIR] propose [--until HH:MM]
  night_queue.py [--repo DIR] [--state DIR] approve [--until HH:MM] <dir>...
  night_queue.py [--repo DIR] [--state DIR] close [--reason TEXT]
  night_queue.py [--repo DIR] [--state DIR] next          (stdin: the `plan` object of get_usage)
  night_queue.py [--repo DIR] [--state DIR] ahead
  night_queue.py [--repo DIR] [--state DIR] gate [--dir DIR] [--stack-gib N]  (stdin: the `plan` object of get_usage)
  night_queue.py room --hours H [--max-percent P]         (stdin: the `plan` object of get_usage)

scope: reads the `Zakres:` line from the header of an epic spec (the lines before the first `## ` heading) and prints
one JSON line `{"scope": [path, ...]}`, exit 0. A missing, doubled or unreadable line, or an unreadable file, prints
the reason to stderr and exits 2.

start: counts the tasks in the manifest of origin/main:docs/features/<dir>/plan.md and appends a start event to
<state>/measurements.jsonl. done: appends a done event for the latest start without one and prints the measurement
(tasks, minutes, usage delta per limit window, `overlap`: its interval intersects another epic's). A start/done pair is
a measurement only through `measurements`. Estimates take time per task from all measurements and usage per task from
the measurements without `overlap` (from all when none of them has the window).

propose: reads the backlog board (shared with next_epic.py) and prints one JSON line
{"epics": [{dir, id, issue, title, tasks, minutes, scope}], "skipped": [{issue, id, reason}],
"in_progress": [{dir, id, issue}], "until": <iso>, "measured": <bool>}: the prepared epics that can run in one night.
`until` is the next occurrence of HH:MM (default 07:00) local time. approve: recomputes the list and, when it equals
the given dirs in order, writes <state>/night.json and prints it; otherwise exits 2.

next: reads <state>/night.json and prints one JSON line {"epic": {dir, id, issue, title, tasks, minutes} | null,
"reason": <str|null>, "started": [dirs], "left": [dirs]}, exit 0 also when `epic` is null. The candidate is the first
list epic without a start event since `approved_at`. Reasons, in order: no list, night over, an epic of the night
without a later done, list done. The first epic of the night is returned without further checks (and without reading
the board, so its `issue` and `title` are null and `id` is the directory in upper case). Later ones must pass: still
prepared on the board and not in progress, a time estimate, now + estimate <= until, usage status `ok`, and every
window resetting after now + estimate has a usage estimate and stays <= 80% after adding it.

close: records the end of the night: sets `ended_at` (now) and `reason` (default `zamknięta`) in night.json and prints
it. No night.json: exit 2. A night that already ended keeps its first `ended_at` and `reason` (printed, exit 0).
approve writes a fresh night.json without `ended_at`. next refuses a night with `ended_at` (`noc zakończona: <reason>`,
checked right after the missing list, without reading the board); every other `epic: null` result of next records the
end itself (`ended_at` and `reason` = its reason), so a later next refuses. An unreadable night.json is never written.

room: is there room in the usage limits for work outside the night estimates (the review after an epic merge). Prints
one JSON line {"ok": true} or {"ok": false, "reason": <str>}, exit 0: usage status other than `ok` (also unreadable
input) or any window that does not reset within H hours at P% or more (default 80) gives the reason. H is a positive
number of hours up to a year, P a number above 0 and up to 100; anything else, also a missing --hours, exits 1. Reads
neither the board nor the state.

ahead: reads the board and prints {"epics": [{dir, id, issue, title, tasks, minutes}], "minutes": <sum|null>,
"full": <bool>}: prepared epics not in progress. `full` is true when the sum reaches the night (540 minutes), or, when
nothing is measured, when there is at least one epic.

gate: the parallel epics gate (docs/features/parallel-work/spec.md, „Bramka”), read-only, under the lock
<state>/gate.lock. Epics in progress: board epics with an `origin/epic/<dir>` branch whose final PR epic/<dir> -> main
is not merged (merged ones go to `merged_open`), plus valid reservations in <state>/claims.json. Active: reservations
and branch epics whose last measurements.jsonl event is `start` or that have none. Files of an epic: its `Zakres:` on
origin/main plus docs/features/<dir> plus, with a branch, `git diff --name-only origin/main...origin/epic/<dir>`.
Without --dir the candidates are the prepared, unreserved board epics in board order; with --dir only that epic
(off the board, merged, in progress or not prepared: `epic` null with the reason). Steps against the epics in progress
other than the candidate: none -> pass; two active -> wait; dependency on an open epic -> skip; no readable scope:
candidate -> skip, epic in progress -> wait; a common path that is not allowed (parallel_gate.is_allowed) -> skip with
the blocking paths of all epics in progress; free Docker memory below one stack (--stack-gib, default 6) -> wait;
usage status not `ok`, no estimate, or a window resetting after the candidate's estimated end above 80% with the full
estimates of the active epics and the candidate -> wait. A skip moves to the next candidate (with --dir it stops); a
wait stops. Prints one JSON line {"epic": {dir, id, issue, title, tasks, minutes} | null, "reason", "reserved": false,
"claim": null, "resumed": false, "parallel_with": [dirs], "active": [dirs], "in_progress": [{dir, id, issue}],
"merged_open": [{dir, id, issue}], "skipped": [{issue, id, reason, paths}], "docker": {total_mib, other_mib, ao_mib,
free_mib, required_mib} | null}, exit 0, and appends {at, dir_arg, epic, reason, skipped: [{id, reason, paths}]} to
<state>/gate.jsonl.

Exit codes: 0 success; 1 a `git`/`gh` command failed or returned unexpected JSON, or a bad --hours of room; 2 usage
and state errors (also an unreadable claims.json, a busy gate lock and a bad --stack-gib).
"""
import argparse
import json
import os
import re
import sys
from datetime import datetime, timedelta

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import next_epic  # noqa: E402,F401  (CommandError and the command runner shared with next_epic.py)
import parallel_gate  # noqa: E402  (lock, claims, file classes and Docker memory of the parallel epics gate)

SCOPE_LINE = re.compile(r'^Zakres:[ \t]*(.*)$')


class ScopeError(Exception):
    pass


class StateError(Exception):
    """Bad manifest, unreadable measurements file or a missing start: exit code 2."""


def _header_lines(spec_text):
    lines = []
    for line in spec_text.splitlines():
        if line.startswith('## '):
            break
        lines.append(line)
    return lines


def _check_item(item):
    segments = item.split('/')
    if (not item or item.startswith('/') or item.endswith('/') or '\\' in item
            or any(c in item for c in '*?[') or any(s in ('', '.', '..') for s in segments)):
        raise ScopeError('nieczytelna linia Zakres: %s' % item)


def parse_scope(spec_text):
    found = [m.group(1) for m in (SCOPE_LINE.match(l) for l in _header_lines(spec_text)) if m]
    if not found:
        raise ScopeError('brak linii Zakres')
    if len(found) > 1:
        raise ScopeError('więcej niż jedna linia Zakres')
    items = []
    for raw in found[0].split(','):
        item = raw.strip().strip('`').strip()
        _check_item(item)
        if item not in items:
            items.append(item)
    return items


def epic_scope(directory, paths):
    return list(paths) + ['docs/features/%s' % directory]


def _inside(inner, outer):
    a, b = inner.split('/'), outer.split('/')
    return a[:len(b)] == b


def overlap(a, b):
    for path_a in a:
        for path_b in b:
            if _inside(path_a, path_b) or _inside(path_b, path_a):
                return (path_a, path_b)
    return None


def cmd_scope(args):
    try:
        with open(args.spec, encoding='utf-8') as f:
            text = f.read()
        scope = parse_scope(text)
    except (OSError, UnicodeDecodeError) as e:
        print('night_queue: %s' % (getattr(e, 'strerror', None) or e), file=sys.stderr)
        return 2
    except ScopeError as e:
        print('night_queue: %s' % e, file=sys.stderr)
        return 2
    print(json.dumps({'scope': scope}, ensure_ascii=False))
    return 0


MEASUREMENTS_FILE = 'measurements.jsonl'
MANIFEST_BLOCK = re.compile(r'^```json[ \t]*\n(.*?)^```[ \t]*$', re.DOTALL | re.MULTILINE)
RESET_TOLERANCE_SECONDS = 60


def parse_time(text):
    """An ISO timestamp with a UTC offset or `Z`; ValueError for one without an offset."""
    parsed = datetime.fromisoformat(text.replace('Z', '+00:00'))
    if parsed.tzinfo is None:
        raise ValueError('timestamp without UTC offset: %s' % text)
    return parsed


def task_count(run, directory):
    text = run(['git', 'show', 'origin/main:docs/features/%s/plan.md' % directory])
    blocks = MANIFEST_BLOCK.findall(text)
    if len(blocks) != 1:
        raise StateError('brak jednego bloku json z manifestem w plan.md epiku %s' % directory)
    try:
        tasks = json.loads(blocks[0]).get('tasks')
    except (ValueError, AttributeError):
        raise StateError('nieczytelny manifest w plan.md epiku %s' % directory)
    if not isinstance(tasks, list) or not tasks:
        raise StateError('manifest w plan.md epiku %s nie ma zadań' % directory)
    return len(tasks)


def parse_usage(text):
    """The `plan` object of get_usage as read, or {"status": "unreadable"} when it has another shape."""
    unreadable = {'status': 'unreadable'}
    try:
        data = json.loads(text)
    except ValueError:
        return unreadable
    if not isinstance(data, dict) or not isinstance(data.get('status'), str):
        return unreadable
    if data['status'] != 'ok':
        return data
    windows = data.get('windows')
    if not isinstance(windows, list):
        return unreadable
    for w in windows:
        try:
            ok = (isinstance(w, dict) and isinstance(w['label'], str)
                  and isinstance(w['percentUsed'], (int, float)) and not isinstance(w['percentUsed'], bool))
            parse_time(w['resetsAt'])
        except (KeyError, TypeError, ValueError, AttributeError):
            return unreadable
        if not ok:
            return unreadable
    return data


def _check_event(event):
    if not isinstance(event, dict) or event.get('event') not in ('start', 'done'):
        raise ValueError('not an event')
    if not isinstance(event.get('epic'), str) or not isinstance(event.get('usage'), dict):
        raise ValueError('bad fields')
    parse_time(event['at'])
    if event['event'] == 'start' and (isinstance(event.get('tasks'), bool) or not isinstance(event.get('tasks'), int)
                                      or event['tasks'] < 1):
        raise ValueError('bad tasks')


def read_events(state_dir):
    path = os.path.join(os.path.expanduser(state_dir), MEASUREMENTS_FILE)
    try:
        with open(path, encoding='utf-8') as f:
            lines = f.read().splitlines()
    except FileNotFoundError:
        return []
    except (OSError, UnicodeDecodeError) as e:
        raise StateError('nieczytelny plik %s: %s' % (path, getattr(e, 'strerror', None) or e))
    events = []
    for number, line in enumerate(lines, 1):
        if not line.strip():
            continue
        try:
            event = json.loads(line)
            _check_event(event)
        except (ValueError, KeyError, TypeError, AttributeError):
            raise StateError('nieczytelna linia %d w %s' % (number, path))
        events.append(event)
    return events


def append_event(state_dir, event):
    directory = os.path.expanduser(state_dir)
    try:
        os.makedirs(directory, exist_ok=True)
        with open(os.path.join(directory, MEASUREMENTS_FILE), 'a', encoding='utf-8') as f:
            f.write(json.dumps(event, ensure_ascii=False) + '\n')
    except OSError as e:
        raise StateError('nie można zapisać pomiaru: %s' % (e.strerror or e))


def _window_deltas(before, after):
    deltas = {}
    if before.get('status') != 'ok' or after.get('status') != 'ok':
        return deltas
    after_by_label = {w['label']: w for w in after['windows']}
    for w in before['windows']:
        other = after_by_label.get(w['label'])
        if other is None:
            continue
        gap = abs((parse_time(other['resetsAt']) - parse_time(w['resetsAt'])).total_seconds())
        delta = other['percentUsed'] - w['percentUsed']
        if gap <= RESET_TOLERANCE_SECONDS and delta >= 0:
            deltas[w['label']] = delta
    return deltas


def measurements(events):
    """Start/done pairs per epic. `overlap`: the pair's interval intersects an interval of another epic (a start
    without a later done is open to the end), so its usage delta may contain the other epic's usage."""
    open_starts = {}
    result, spans = [], []
    for event in events:
        kind, epic = event.get('event'), event.get('epic')
        if kind == 'start':
            open_starts[epic] = event
        elif kind == 'done' and epic in open_starts:
            start = open_starts.pop(epic)
            begin, end = parse_time(start['at']), parse_time(event['at'])
            result.append({'epic': epic, 'tasks': start['tasks'], 'minutes': (end - begin).total_seconds() / 60,
                           'usage': _window_deltas(start['usage'], event['usage'])})
            spans.append((epic, begin, end))
    others = spans + [(epic, parse_time(start['at']), None) for epic, start in open_starts.items()]
    for item, (epic, begin, end) in zip(result, spans):
        item['overlap'] = any(epic_ != epic and (end_ is None or begin < end_) and begin_ < end
                              for epic_, begin_, end_ in others)
    return result


def estimate(measured, tasks):
    """Time per task from all measurements; usage per task and window only from measurements without `overlap`,
    or from all of them when none without `overlap` has the window."""
    measured = [m for m in measured if m['tasks'] > 0]
    if not measured:
        return {'minutes': None, 'usage': {}}
    per_label = {}
    for label in {label for m in measured for label in m['usage']}:
        having = [m for m in measured if label in m['usage']]
        source = [m for m in having if not m.get('overlap')] or having
        per_label[label] = max(m['usage'][label] / m['tasks'] for m in source)
    return {'minutes': tasks * max(m['minutes'] / m['tasks'] for m in measured),
            'usage': {label: tasks * rate for label, rate in per_label.items()}}


def _read_usage(stdin):
    try:
        return parse_usage((stdin or sys.stdin).read())
    except (OSError, UnicodeDecodeError):
        return {'status': 'unreadable'}


def cmd_start(args, run, now, stdin):
    events = read_events(args.state)
    event = {'event': 'start', 'epic': args.dir, 'tasks': task_count(run, args.dir), 'at': now.isoformat(),
             'usage': _read_usage(stdin)}
    append_event(args.state, event)
    print(json.dumps(event, ensure_ascii=False))
    return 0


def cmd_done(args, run, now, stdin):
    events = read_events(args.state)
    latest = [e for e in events if e.get('epic') == args.dir and e.get('event') in ('start', 'done')]
    if not latest or latest[-1]['event'] != 'start':
        raise StateError('brak start dla %s' % args.dir)
    event = {'event': 'done', 'epic': args.dir, 'at': now.isoformat(), 'usage': _read_usage(stdin)}
    append_event(args.state, event)
    print(json.dumps(measurements(events + [event])[-1], ensure_ascii=False))
    return 0


DEPENDENCIES_HEADING = '## Zależności'
DEFAULT_UNTIL = '07:00'
NIGHT_FILE = 'night.json'


def dependencies_section(body):
    lines, inside = [], False
    for line in body.splitlines():
        if inside and line.startswith('## '):
            break
        if inside:
            lines.append(line)
        elif line.rstrip() == DEPENDENCIES_HEADING:
            inside = True
    return '\n'.join(lines)


def mentions(text, epic_id):
    return re.search(r'(?<![A-Za-z0-9._-])%s(?!\.?[A-Za-z0-9_-])' % re.escape(epic_id), text) is not None


def dependency(board, entry):
    """The ID of another open board epic named in the `## Zależności` section of the entry's issue, or None."""
    section = dependencies_section(entry['body'])
    return next((o['id'] for o in board if o['id'] and o is not entry and mentions(section, o['id'])), None)


def parse_until(text, now):
    match = re.fullmatch(r'(\d{2}):(\d{2})', text)
    if not match or int(match.group(1)) > 23 or int(match.group(2)) > 59:
        raise StateError('nieprawidłowa godzina --until: %s (oczekiwano HH:MM)' % text)
    local = now.utcoffset() == now.replace(tzinfo=None).astimezone().utcoffset()

    def at(day):
        # Local `now`: the offset valid on the target date (DST changes); otherwise the offset of `now`.
        naive = datetime(day.year, day.month, day.day, int(match.group(1)), int(match.group(2)))
        return naive.astimezone() if local else naive.replace(tzinfo=now.tzinfo)

    until = at(now)
    return until if until > now else at(now + timedelta(days=1))


def propose(run, state_dir, until_text, now):
    until = parse_until(until_text, now)
    board = next_epic.board_epics(run)
    measured = measurements(read_events(state_dir))
    in_progress = [{'dir': e['dir'], 'id': e['id'], 'issue': e['number']} for e in board if e['state'] == 'in_progress']
    skipped = [{'issue': e['number'], 'id': e['id'], 'reason': e['reason']}
               for e in board if e['state'] in ('invalid', 'unprepared')]
    chosen, minutes_total = [], 0.0
    for e in ([] if in_progress else [e for e in board if e['state'] == 'prepared']):
        def skip(reason):
            skipped.append({'issue': e['number'], 'id': e['id'], 'reason': reason})
        depends = dependency(board, e)
        if depends:
            skip('zależy od %s' % depends)
            continue
        if chosen and chosen[0]['scope'] is None:
            skip('pierwszy epik listy %s nie ma zakresu' % chosen[0]['id'])
            continue
        try:
            scope = epic_scope(e['dir'], parse_scope(run(['git', 'show', 'origin/main:docs/features/%s/spec.md' % e['dir']])))
        except ScopeError as error:
            if chosen:
                skip(str(error))
                continue
            scope = None
        clash = next(((c['id'], overlap(scope, c['scope'])[0]) for c in chosen
                      if scope is not None and overlap(scope, c['scope'])), None)
        if clash:
            skip('zakres wspólny z %s: %s' % clash)
            continue
        tasks = task_count(run, e['dir'])
        minutes = estimate(measured, tasks)['minutes']
        if minutes is not None and now + timedelta(minutes=minutes_total + minutes) > until:
            skip('nie mieści się do %s' % until_text)
            continue
        minutes_total += minutes or 0.0
        chosen.append({'dir': e['dir'], 'id': e['id'], 'issue': e['number'], 'title': e['title'], 'tasks': tasks,
                       'minutes': minutes, 'scope': scope})
    return {'epics': chosen, 'skipped': skipped, 'in_progress': in_progress, 'until': until.isoformat(),
            'measured': estimate(measured, 1)['minutes'] is not None}


def cmd_propose(args, run, now, stdin):
    print(json.dumps(propose(run, args.state, args.until, now), ensure_ascii=False))
    return 0


def write_night(state_dir, night):
    directory = os.path.expanduser(state_dir)
    try:
        os.makedirs(directory, exist_ok=True)
        with open(os.path.join(directory, NIGHT_FILE), 'w', encoding='utf-8') as f:
            json.dump(night, f, ensure_ascii=False)
            f.write('\n')
    except OSError as e:
        raise StateError('nie można zapisać %s: %s' % (NIGHT_FILE, e.strerror or e))


def cmd_approve(args, run, now, stdin):
    result = propose(run, args.state, args.until, now)
    listed = [e['dir'] for e in result['epics']]
    if not listed or listed != args.dirs:
        raise StateError('lista różni się od zatwierdzanej: propozycja [%s], podano [%s]'
                         % (', '.join(listed), ', '.join(args.dirs)))
    night = {'approved_at': now.isoformat(), 'until': result['until'], 'epics': listed}
    write_night(args.state, night)
    print(json.dumps(night, ensure_ascii=False))
    return 0


NIGHT_MINUTES = 540


def read_night(state_dir):
    path = os.path.join(os.path.expanduser(state_dir), NIGHT_FILE)
    try:
        with open(path, encoding='utf-8') as f:
            night = json.load(f)
    except FileNotFoundError:
        return None
    except (OSError, UnicodeDecodeError, ValueError) as e:
        raise StateError('nieczytelny plik %s: %s' % (path, getattr(e, 'strerror', None) or e))
    try:
        parse_time(night['approved_at'])
        parse_time(night['until'])
        if not isinstance(night['epics'], list) or not all(isinstance(d, str) for d in night['epics']):
            raise ValueError('epics')
        if 'ended_at' in night:
            parse_time(night['ended_at'])
            if not isinstance(night.get('reason'), str):
                raise ValueError('reason')
    except (KeyError, TypeError, ValueError, AttributeError):
        raise StateError('nieczytelny plik %s' % path)
    return night


def _next_result(epic_, reason, started, left):
    return {'epic': epic_, 'reason': reason, 'started': started, 'left': left}


def _percent(value):
    return '%.1f' % round(value, 1)


def _not_prepared(directory, entry):
    """The reason of check 1: the epic is not on the board as prepared (`entry` is None when absent)."""
    detail = None
    if entry is not None:
        detail = 'w toku' if entry['state'] == 'in_progress' else entry['reason']
    return '%s nie jest już przygotowany%s' % (entry['id'] if entry else directory.upper(),
                                                ': %s' % detail if detail else '')


def _forecast_failure(name, minutes, usage_estimate, now, until, stdin):
    """Checks 2-5 for a candidate with a time estimate `minutes`: the reason it may not start now, or None."""
    if minutes is None:
        return 'brak pomiaru'
    end = now + timedelta(minutes=minutes)
    if end > until:
        return '%s nie zdąży do %s' % (name, until.astimezone(now.tzinfo).strftime('%H:%M'))
    usage_now = _read_usage(stdin)
    if usage_now['status'] != 'ok':
        return 'stan limitów: %s' % usage_now['status']
    for w in usage_now['windows']:
        if parse_time(w['resetsAt']) <= end:
            continue
        if w['label'] not in usage_estimate:
            return 'okno %s bez pomiaru' % w['label']
        if w['percentUsed'] + usage_estimate[w['label']] > 80:
            return 'okno %s: %s%% + %s%% > 80%%' % (w['label'], _percent(w['percentUsed']),
                                                   _percent(usage_estimate[w['label']]))
    return None


def _progress(state_dir, night):
    """`started` and `left` of a night: list epics with and without a start event since the approval."""
    approved, listed = parse_time(night['approved_at']), night['epics']
    since = [e for e in read_events(state_dir) if e['epic'] in listed and parse_time(e['at']) >= approved]
    started = [d for d in listed if any(e['epic'] == d and e['event'] == 'start' for e in since)]
    return started, [d for d in listed if d not in started]


def cmd_close(args, run, now, stdin):
    night = read_night(args.state)
    if night is None:
        raise StateError('brak zatwierdzonej listy nocy')
    if 'ended_at' not in night:
        night = dict(night, ended_at=now.isoformat(), reason=args.reason)
        write_night(args.state, night)
    print(json.dumps(night, ensure_ascii=False))
    return 0


def cmd_next(args, run, now, stdin):
    night = read_night(args.state)
    if night is None:
        print(json.dumps(_next_result(None, 'brak zatwierdzonej listy', [], []), ensure_ascii=False))
        return 0
    if 'ended_at' in night:
        print(json.dumps(_next_result(None, 'noc zakończona: %s' % night['reason'], *_progress(args.state, night)),
                         ensure_ascii=False))
        return 0
    approved, until, listed = parse_time(night['approved_at']), parse_time(night['until']), night['epics']
    events = read_events(args.state)
    since = [e for e in events if e['epic'] in listed and parse_time(e['at']) >= approved]
    started = [d for d in listed if any(e['epic'] == d and e['event'] == 'start' for e in since)]
    unstarted = [d for d in listed if d not in started]

    def finish(epic_, reason):
        after = unstarted if epic_ is None else unstarted[1:]
        if epic_ is None:
            write_night(args.state, dict(night, ended_at=now.isoformat(), reason=reason))
        print(json.dumps(_next_result(epic_, reason, started, after), ensure_ascii=False))
        return 0

    if now > until:
        return finish(None, 'noc minęła (%s)' % until.astimezone(now.tzinfo).strftime('%H:%M'))
    for d in started:
        last = [e for e in since if e['epic'] == d][-1]
        if last['event'] != 'done':
            return finish(None, '%s bez pomiaru done' % d)
    if not unstarted:
        return finish(None, 'lista wykonana')
    directory = unstarted[0]
    entry = None
    if started:
        entry = next((e for e in next_epic.board_epics(run) if e['dir'] == directory), None)
        if entry is None or entry['state'] != 'prepared':
            return finish(None, _not_prepared(directory, entry))
    tasks = task_count(run, directory)
    forecast = estimate(measurements(events), tasks)
    candidate = {'dir': directory, 'id': entry['id'] if entry else directory.upper(),
                 'issue': entry['number'] if entry else None, 'title': entry['title'] if entry else None,
                 'tasks': tasks, 'minutes': forecast['minutes']}
    if started:
        reason = _forecast_failure(candidate['id'], forecast['minutes'], forecast['usage'], now, until, stdin)
        if reason:
            return finish(None, reason)
    return finish(candidate, None)


MAX_ROOM_HOURS = 24 * 365


def cmd_room(args, now, stdin):
    try:
        hours = float(args.hours)
        horizon = now + timedelta(hours=hours) if 0 < hours <= MAX_ROOM_HOURS else None
    except (TypeError, ValueError, OverflowError):
        horizon = None
    if horizon is None:
        print('night_queue: --hours musi być liczbą godzin większą od 0 i najwyżej %d, jest %r' % (MAX_ROOM_HOURS, args.hours),
              file=sys.stderr)
        return 1
    try:
        max_percent = float(args.max_percent)
    except (TypeError, ValueError):
        max_percent = None
    if max_percent is None or not 0 < max_percent <= 100:
        print('night_queue: --max-percent musi być liczbą większą od 0 i najwyżej 100, jest %r' % args.max_percent,
              file=sys.stderr)
        return 1
    usage_now = _read_usage(stdin)
    if usage_now['status'] != 'ok':
        reason = 'stan limitów nieczytelny' if usage_now['status'] == 'unreadable' else (
            'stan limitów: %s' % usage_now['status'])
        print(json.dumps({'ok': False, 'reason': reason}, ensure_ascii=False))
        return 0
    for w in usage_now['windows']:
        if parse_time(w['resetsAt']) <= horizon:
            continue
        if w['percentUsed'] >= max_percent:
            print(json.dumps({'ok': False, 'reason': 'okno %s: %s%% >= %g%%' % (
                w['label'], _percent(w['percentUsed']), max_percent)}, ensure_ascii=False))
            return 0
    print(json.dumps({'ok': True}))
    return 0


def cmd_ahead(args, run, now, stdin):
    measured = measurements(read_events(args.state))
    epics = []
    for e in next_epic.board_epics(run):
        if e['state'] == 'prepared':
            tasks = task_count(run, e['dir'])
            epics.append({'dir': e['dir'], 'id': e['id'], 'issue': e['number'], 'title': e['title'], 'tasks': tasks,
                          'minutes': estimate(measured, tasks)['minutes']})
    is_measured = estimate(measured, 1)['minutes'] is not None
    total = sum(e['minutes'] for e in epics) if is_measured else None
    full = total >= NIGHT_MINUTES if is_measured else bool(epics)
    print(json.dumps({'epics': epics, 'minutes': total, 'full': full}, ensure_ascii=False))
    return 0


GATE_LOG = 'gate.jsonl'
MAX_ACTIVE = 2
MAX_PERCENT = 80
DEFAULT_STACK_GIB = 6
PASS, SKIP, WAIT = 'pass', 'skip', 'wait'


class GateContext:
    """What one gate call knows about the board and the epics in progress (spec parallel-work, „Pojęcia”).

    in_progress: [{dir, id, issue, branch, active}]: board epics with an `origin/epic/<dir>` branch and no merged final
    PR (active when their last measurements.jsonl event is `start` or they have none), then valid claims of other
    sessions (always active). Files and task counts are read lazily and cached; step 6 stores its Docker memory
    state in `docker` (None when not measured or failed).
    """

    def __init__(self, run, now, board, measured, in_progress, merged_open):
        self.run, self.now, self.board, self.measured = run, now, board, measured
        self.in_progress, self.merged_open = in_progress, merged_open
        self.docker = None
        self._files, self._tasks, self._fetched = {}, {}, False

    def others(self, directory):
        """Epics in progress other than `directory` (a candidate is never compared with itself or its claim)."""
        return [p for p in self.in_progress if p['dir'] != directory]

    def tasks(self, directory):
        if directory not in self._tasks:
            self._tasks[directory] = task_count(self.run, directory)
        return self._tasks[directory]

    def files(self, directory, branch):
        """Scope of the spec on origin/main plus, for a branch epic, its diff against origin/main (ScopeError)."""
        if directory not in self._files:
            try:
                spec = self.run(['git', 'show', 'origin/main:docs/features/%s/spec.md' % directory])
                paths = epic_scope(directory, parse_scope(spec))
                if branch:
                    if not self._fetched:
                        self.run(['git', 'fetch', 'origin', 'refs/heads/epic/*:refs/remotes/origin/epic/*'])
                        self._fetched = True
                    diff = self.run(['git', 'diff', '--name-only', 'origin/main...origin/epic/%s' % directory])
                    paths += [line.strip() for line in diff.splitlines() if line.strip()]
                self._files[directory] = paths
            except ScopeError as error:
                self._files[directory] = error
        if isinstance(self._files[directory], ScopeError):
            raise self._files[directory]
        return self._files[directory]


def _final_pr_merged(run, directory):
    args = ['gh', 'pr', 'list', '--repo', next_epic.REPOSITORY, '--head', 'epic/%s' % directory, '--base', 'main',
            '--state', 'merged', '--json', 'number', '--limit', '1']
    found = next_epic._json(run, args)
    if not isinstance(found, list):
        raise next_epic.CommandError(args, 'unexpected JSON shape')
    return bool(found)


def gate_context(run, state_dir, now):
    """Reads the state (measurements, claims) and the board; the caller holds parallel_gate.state_lock."""
    events = read_events(state_dir)
    claims = parallel_gate.valid_claims(parallel_gate.read_claims(state_dir), now)
    board = next_epic.board_epics(run)
    in_progress, merged_open = [], []
    for e in board:
        if e['state'] != 'in_progress':
            continue
        item = {'dir': e['dir'], 'id': e['id'], 'issue': e['number']}
        if _final_pr_merged(run, e['dir']):
            merged_open.append(item)
            continue
        last = [ev['event'] for ev in events if ev['epic'] == e['dir']]
        in_progress.append(dict(item, branch=True, active=not last or last[-1] == 'start'))
    for c in claims:
        known = next((p for p in in_progress if p['dir'] == c['dir']), None)
        if known is not None:
            known['active'] = True
            continue
        entry = next((e for e in board if e['dir'] == c['dir']), None)
        in_progress.append({'dir': c['dir'], 'id': entry['id'] if entry else c['dir'].upper(),
                            'issue': entry['number'] if entry else None, 'branch': False, 'active': True})
    return GateContext(run, now, board, measurements(events), in_progress, merged_open)


def check_room(active):
    """Step 2: at most one active epic besides the candidate."""
    if len(active) >= MAX_ACTIVE:
        return 'dwa epiki aktywne: %s' % ', '.join(p['id'] for p in active)
    return None


def check_files(ctx, entry, others):
    """Steps 4-5: (SKIP|WAIT, reason, blocking paths) or None when the candidate shares only allowed paths."""
    try:
        mine = ctx.files(entry['dir'], branch=False)
    except ScopeError as error:
        return SKIP, str(error), []
    theirs = []
    for p in others:
        try:
            theirs.append((p, ctx.files(p['dir'], p['branch'])))
        except ScopeError as error:
            return WAIT, '%s w toku bez zakresu: %s' % (p['id'], error), []
    first, paths = None, set()
    for p, files in theirs:
        blocking = [path for path in parallel_gate.common_paths(mine, files) if not parallel_gate.is_allowed(path)]
        if blocking and first is None:
            first = 'wspólne pliki z %s: %s' % (p['id'], ', '.join(blocking))
        paths.update(blocking)
    return (SKIP, first, sorted(paths)) if first else None


def check_docker(ctx, active, stack_mib):
    """Step 6: free Docker memory for one more stack; sets ctx.docker (None when not measured)."""
    try:
        snapshot = parallel_gate.docker_snapshot(ctx.run)
    except parallel_gate.DockerError as error:
        ctx.docker = None
        return str(error)
    free = parallel_gate.free_mib(snapshot, len(active), stack_mib)
    ctx.docker = dict(snapshot, free_mib=round(free, 1), required_mib=round(stack_mib, 1))
    if free < stack_mib:
        return 'pamięć Dockera: wolne %.0f MiB < %.0f MiB' % (free, stack_mib)
    return None


def check_usage(ctx, forecast, active, usage_now):
    """Step 7: the usage limits hold the full estimates of the active epics and the candidate's estimate."""
    if usage_now['status'] != 'ok':
        return 'stan limitów: %s' % usage_now['status']
    if forecast['minutes'] is None:
        return 'brak pomiaru'
    end = ctx.now + timedelta(minutes=forecast['minutes'])
    loads = [estimate(ctx.measured, ctx.tasks(p['dir']))['usage'] for p in active]
    for w in usage_now['windows']:
        label = w['label']
        if parse_time(w['resetsAt']) <= end:
            continue
        if label not in forecast['usage'] or any(label not in load for load in loads):
            return 'okno %s bez pomiaru' % label
        others, mine = sum(load[label] for load in loads), forecast['usage'][label]
        if w['percentUsed'] + others + mine > MAX_PERCENT:
            return 'okno %s: %s%% + %s%% + %s%% > %d%%' % (label, _percent(w['percentUsed']), _percent(others),
                                                           _percent(mine), MAX_PERCENT)
    return None


def evaluate(ctx, entry, usage_now, stack_mib):
    """Gate steps 1-7 for one prepared board entry against the epics in progress.

    Returns {outcome: PASS|SKIP|WAIT, reason, paths, epic}; `epic` ({dir, id, issue, title, tasks, minutes}) is set
    only on PASS. Step 6 sets ctx.docker.
    """
    others = ctx.others(entry['dir'])
    active = [p for p in others if p['active']]

    def verdict(outcome, reason=None, paths=()):
        return {'outcome': outcome, 'reason': reason, 'paths': list(paths), 'epic': None}

    def passed(forecast):
        result = verdict(PASS)
        result['epic'] = {'dir': entry['dir'], 'id': entry['id'], 'issue': entry['number'], 'title': entry['title'],
                          'tasks': ctx.tasks(entry['dir']), 'minutes': forecast['minutes']}
        return result

    if not others:
        return passed(estimate(ctx.measured, ctx.tasks(entry['dir'])))
    reason = check_room(active)
    if reason:
        return verdict(WAIT, reason)
    depends = dependency(ctx.board, entry)
    if depends:
        return verdict(SKIP, 'zależy od %s' % depends)
    found = check_files(ctx, entry, others)
    if found:
        return verdict(*found)
    reason = check_docker(ctx, active, stack_mib)
    if reason:
        return verdict(WAIT, reason)
    forecast = estimate(ctx.measured, ctx.tasks(entry['dir']))
    reason = check_usage(ctx, forecast, active, usage_now)
    if reason:
        return verdict(WAIT, reason)
    return passed(forecast)


def _refusal(ctx, directory):
    """(reason, None) when `--dir` names no candidate (off the board, merged, in progress, not prepared), else
    (None, board entry)."""
    entry = next((e for e in ctx.board if e['dir'] == directory), None)
    if entry is None:
        return directory.upper() + ' nie ma na tablicy', None
    if any(m['dir'] == directory for m in ctx.merged_open):
        return '%s ma zmergowany końcowy PR' % entry['id'], None
    if entry['state'] == 'in_progress':
        return '%s jest w toku' % entry['id'], None
    if entry['state'] != 'prepared':
        return entry['reason'], None
    return None, entry


def gate_decision(ctx, dir_arg, usage_now, stack_mib):
    """The gate result for `--dir` (or the first board candidate that passes); writes nothing."""
    result = {'epic': None, 'reason': None, 'reserved': False, 'claim': None, 'resumed': False, 'parallel_with': [],
              'active': [p['dir'] for p in ctx.in_progress if p['active']],
              'in_progress': [{k: p[k] for k in ('dir', 'id', 'issue')} for p in ctx.in_progress],
              'merged_open': ctx.merged_open, 'skipped': [], 'docker': None}
    if dir_arg is not None:
        result['reason'], entry = _refusal(ctx, dir_arg)
        candidates = [entry] if entry else []
    else:
        claimed = {p['dir'] for p in ctx.in_progress}
        candidates = [e for e in ctx.board if e['state'] == 'prepared' and e['dir'] not in claimed]
    for entry in candidates:
        found = evaluate(ctx, entry, usage_now, stack_mib)
        if found['outcome'] == SKIP:
            result['skipped'].append({'issue': entry['number'], 'id': entry['id'], 'reason': found['reason'],
                                      'paths': found['paths']})
            if dir_arg is None:
                continue
        if found['outcome'] == PASS:
            result['epic'] = found['epic']
            result['parallel_with'] = [p['dir'] for p in ctx.others(entry['dir'])]
        else:
            result['reason'] = found['reason']
        break
    result['docker'] = ctx.docker
    return result


def append_gate_log(state_dir, now, dir_arg, result):
    line = {'at': now.isoformat(), 'dir_arg': dir_arg, 'epic': result['epic']['dir'] if result['epic'] else None,
            'reason': result['reason'],
            'skipped': [{k: s[k] for k in ('id', 'reason', 'paths')} for s in result['skipped']]}
    path = os.path.join(os.path.expanduser(state_dir), GATE_LOG)
    try:
        with open(path, 'a', encoding='utf-8') as f:
            f.write(json.dumps(line, ensure_ascii=False) + '\n')
    except OSError as e:
        raise StateError('nie można zapisać %s: %s' % (GATE_LOG, e.strerror or e))


def cmd_gate(args, run, now, stdin):
    try:
        stack_gib = float(args.stack_gib)
    except ValueError:
        stack_gib = None
    if stack_gib is None or not 0 < stack_gib < float('inf'):
        raise StateError('--stack-gib musi być liczbą GiB większą od 0, jest %r' % args.stack_gib)
    usage_now = _read_usage(stdin)
    with parallel_gate.state_lock(args.state):
        ctx = gate_context(run, args.state, now)
        result = gate_decision(ctx, args.dir, usage_now, stack_gib * 1024)
        append_gate_log(args.state, now, args.dir, result)
    print(json.dumps(result, ensure_ascii=False))
    return 0


def main(argv=None, run=None, now=None, stdin=None):
    parser = argparse.ArgumentParser(description='Night queue of several epics')
    parser.add_argument('--repo', default='.')
    parser.add_argument('--state', default='~/.local/state/ao-night')
    sub = parser.add_subparsers(dest='command', required=True)
    scope = sub.add_parser('scope', help='print the Zakres line of a spec')
    scope.add_argument('spec')
    for name in ('start', 'done'):
        sub.add_parser(name).add_argument('dir')
    propose_parser = sub.add_parser('propose', help='propose the list of epics for the night')
    approve_parser = sub.add_parser('approve', help='approve the list of epics for the night')
    for p in (propose_parser, approve_parser):
        p.add_argument('--until', default=DEFAULT_UNTIL)
    approve_parser.add_argument('dirs', nargs='+')
    close_parser = sub.add_parser('close', help='record the end of the night')
    close_parser.add_argument('--reason', default='zamknięta')
    sub.add_parser('next', help='the next epic of the night list, or the reason there is none')
    sub.add_parser('ahead', help='prepared epics with time estimates')
    gate_parser = sub.add_parser('gate', help='the epic that may start now next to the epics in progress')
    gate_parser.add_argument('--dir')
    gate_parser.add_argument('--stack-gib', default=str(DEFAULT_STACK_GIB))
    room_parser = sub.add_parser('room', help='room in the usage limits for a review after a merge')
    room_parser.add_argument('--hours')
    room_parser.add_argument('--max-percent', default='80')
    try:
        args = parser.parse_args(argv)
    except SystemExit as e:
        return 2 if e.code else 0
    if args.command == 'scope':
        return cmd_scope(args)
    now = now or datetime.now().astimezone()
    if args.command == 'room':
        return cmd_room(args, now, stdin)
    run = run or next_epic.make_runner(args.repo)
    try:
        return {'start': cmd_start, 'done': cmd_done, 'propose': cmd_propose, 'approve': cmd_approve,
                'close': cmd_close, 'next': cmd_next, 'ahead': cmd_ahead,
                'gate': cmd_gate}[args.command](args, run, now, stdin)
    except (StateError, parallel_gate.StateFileError, parallel_gate.LockTimeout) as e:
        print('night_queue: %s' % e, file=sys.stderr)
        return 2
    except next_epic.CommandError as e:
        print('night_queue: %s' % e, file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())

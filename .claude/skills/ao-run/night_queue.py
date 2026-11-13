#!/usr/bin/env python3
"""Night queue of several epics for the /ao-run skill. Python 3.9+, standard library only.

  night_queue.py [--repo DIR] [--state DIR] scope <spec.md>
  night_queue.py [--repo DIR] [--state DIR] start <dir> [--claim TOKEN]  (stdin: the `plan` object of get_usage)
  night_queue.py [--repo DIR] [--state DIR] done <dir>    (stdin: the `plan` object of get_usage)
  night_queue.py [--repo DIR] [--state DIR] propose [--until HH:MM]
  night_queue.py [--repo DIR] [--state DIR] approve [--until HH:MM] <dir>...
  night_queue.py [--repo DIR] [--state DIR] close [--reason TEXT]
  night_queue.py [--repo DIR] [--state DIR] next          (stdin: the `plan` object of get_usage)
  night_queue.py [--repo DIR] [--state DIR] ahead
  night_queue.py [--repo DIR] [--state DIR] gate [--dir DIR [--reserve] [--claim TOKEN] [--force] [--takeover]]
                 [--stack-gib N] [--ao-root DIR]           (stdin: the `plan` object of get_usage)
  night_queue.py [--repo DIR] [--state DIR] release <dir> --claim TOKEN
  night_queue.py [--repo DIR] [--state DIR] hold --reason TEXT
  night_queue.py [--repo DIR] [--state DIR] unhold
  night_queue.py room --hours H [--max-percent P]         (stdin: the `plan` object of get_usage)

scope: reads the `Zakres:` line from the header of an epic spec (the lines before the first `## ` heading) and prints
one JSON line `{"scope": [path, ...]}`, exit 0. A missing, doubled or unreadable line, or an unreadable file, prints
the reason to stderr and exits 2.

Shared state: start, done, approve, close, next, gate, release, hold and unhold hold the lock <state>/gate.lock
(parallel_gate.state_lock, at most 120 s; then exit 2 `blokada bramki zajęta` without a write) for their whole
read-decide-write. night.json, claims.json and parallel-hold.json are replaced atomically (temp file and os.replace).

start: counts the tasks in the manifest of origin/main:docs/features/<dir>/plan.md and appends a start event to
<state>/measurements.jsonl with `docker`: the Docker memory {total_mib, ao_mib, other_mib}
(parallel_gate.docker_snapshot) or null when `docker` fails (the event is still written). Then, in the same lock, the
epic gets an owner in claims.json: the --claim token (its reservation is removed) or, without --claim, a new token. An
owner recorded for the epic other than --claim (also without --claim): exit 2 `epik X ma innego właściciela`, nothing
written. Prints the event plus `owner` (the token; not stored in measurements.jsonl). done: appends a done event for
the latest start without one and prints the measurement (tasks, minutes, usage delta per limit window, `overlap`: its
interval intersects another epic's closed interval, or another epic's start without a later done lies in it; such an
open start before the interval does not count). A start/done pair is a measurement only through `measurements`.
Estimates take time per task from all measurements and usage per task from the measurements without `overlap` (from
all when none of them has the window).

propose: reads the backlog board (shared with next_epic.py) and prints one JSON line
{"epics": [{dir, id, issue, title, tasks, minutes, scope}], "skipped": [{issue, id, reason}],
"in_progress": [{dir, id, issue}], "until": <iso>, "measured": <bool>, "hold": {at, reason} | null}: the prepared epics
that can run in one night. `until` is the next occurrence of HH:MM (default 07:00) local time; `hold` is the parallel
hold or null (it does not change the list). The epics in progress (as in gate: branch epics without a merged final PR
and valid reservations) do not empty the list: they count like epics already on it, with
their files (scope plus branch diff). A candidate is skipped when it depends on an open epic, shares with an epic in
progress or on the list a path that is not allowed (parallel_gate.is_allowed; `zakres wspólny z X: <path>`), has no
readable scope while anything is in progress or listed (an epic without scope enters only alone, with nothing in
progress), or makes the serial sum of the estimates end after `until`. An epic in progress without a readable scope
skips every candidate (`X w toku bez zakresu: <error>`). approve: one night at a time on the computer: a
night.json without `ended_at` and now <= its `until` exits 2 `trwa noc zatwierdzona <approved_at> do <until>` (an
unreadable night.json also exits 2); otherwise it recomputes the list and, when it equals the given dirs in order,
writes <state>/night.json and prints it; otherwise exits 2.

next: reads <state>/night.json and prints one JSON line {"epic": {dir, id, issue, title, tasks, minutes} | null,
"reason": <str|null>, "started": [dirs], "left": [dirs], "wait": <bool>, "claim": <token|null>}, exit 0 also when
`epic` is null. Every epic of the night, also the first, starts through next: a returned `epic` is reserved
(parallel_gate.reserve, 30 minutes) as the last step and `claim` is its token; otherwise `claim` is null. The
candidate is the first list epic without a start event since `approved_at`. Running: a started list epic without a
later done. In order: no list; night over (now > until); list done (all started, none running); then the gate
context (board, reservations) is read: a running epic that is not active in it (e.g. no branch) -> `X bez pomiaru
done`; all started and some running -> wait `wszystkie epiki listy ruszyły, w toku: <dirs>`. Then:
(a) nothing in progress and nothing started: the first epic is returned without further checks (its `issue` and
`title` are null and `id` is the directory in upper case);
a valid reservation of the candidate by another session -> wait `epik X zarezerwowany albo w toku`; the candidate no
longer prepared on the board -> `X nie jest już przygotowany[: <reason>]`;
(b) something started, all started have done and nothing in progress is active: today's checks: a time estimate,
now + estimate <= until, usage status `ok`, and every window resetting after now + estimate has a usage estimate and
stays <= 80% after adding it;
(c) otherwise, next to the epics in progress: gate steps 2-7 (evaluate; default stack reserve) and then now +
estimate <= until (`X nie zdąży do HH:MM`). A refusal (gate wait or skip, or too late) is `wait: true` while anything
is active, else it ends the night. Each (c) evaluation appends a gate.jsonl line like `gate --dir <candidate>` ({at,
dir_arg, epic, reason, waiting, skipped}; a too-late candidate has `epic` null, that reason and `waiting` null) before
the final write.
`wait: true` (with `epic` null and a reason) never ends the night and writes nothing to night.json or claims.json;
the session checks again later.

close: records the end of the night: sets `ended_at` (now) and `reason` (default `zamknięta`) in night.json and prints
it. No night.json: exit 2. A night that already ended keeps its first `ended_at` and `reason` (printed, exit 0).
approve writes a fresh night.json without `ended_at`. next refuses a night with `ended_at` (`noc zakończona: <reason>`,
checked right after the missing list, without reading the board); every other `epic: null` result of next without
`wait` records the end itself (`ended_at` and `reason` = its reason), so a later next refuses. An unreadable
night.json is never written.

room: is there room in the usage limits for work outside the night estimates (the review after an epic merge). Prints
one JSON line {"ok": true} or {"ok": false, "reason": <str>}, exit 0: usage status other than `ok` (also unreadable
input) or any window that does not reset within H hours at P% or more (default 80) gives the reason. H is a positive
number of hours up to a year, P a number above 0 and up to 100; anything else, also a missing --hours, exits 1. Reads
neither the board nor the state.

ahead: reads the board and prints {"epics": [{dir, id, issue, title, tasks, minutes}], "minutes": <sum|null>,
"full": <bool>}: prepared epics not in progress. `full` is true when the sum reaches the night (540 minutes), or, when
nothing is measured, when there is at least one epic.

gate: the parallel epics gate (docs/features/parallel-work/spec.md, „Bramka”, „Rezerwacja”). Epics in progress: board
epics with an `origin/epic/<dir>` branch whose final PR epic/<dir> -> main is not merged (merged ones go to
`merged_open`), plus valid reservations (30 minutes) in <state>/claims.json except the caller's own one (--dir with the
--claim token), which is neither in progress, active nor listed for it. Active: reservations and branch epics whose
last measurements.jsonl event is `start` or that have none. Files of an epic: its `Zakres:` on
origin/main plus docs/features/<dir> plus, with a branch, `git diff --name-only origin/main...origin/epic/<dir>`.
Without --dir the candidates are the prepared, unreserved board epics in board order; with --dir only that epic
(off the board, merged, not prepared: `epic` null with the reason; a valid reservation of another token, also without
--claim: `epik X zarezerwowany albo w toku`). Steps against the epics in progress
other than the candidate: none -> pass; two active -> wait; a parallel hold (parallel-hold.json, see hold) -> wait
`równoległość wstrzymana: <reason>`; dependency on an open epic -> skip; no readable scope:
candidate -> skip, epic in progress (also without spec.md on origin/main) -> wait; a common path that is not allowed (parallel_gate.is_allowed) -> skip with
the blocking paths of all epics in progress; free Docker memory below one stack (--stack-gib, default 6) -> wait;
usage status not `ok`, no estimate, an active epic without a readable plan.md on origin/main, or a window resetting after the candidate's estimated end above 80% with the full
estimates of the active epics and the candidate -> wait. A skip moves to the next candidate (with --dir it stops); a
wait stops. Prints one JSON line {"epic": {dir, id, issue, title, tasks, minutes} | null, "reason", "reserved": false,
"claim": null, "resumed": false, "parallel_with": [dirs], "active": [dirs], "in_progress": [{dir, id, issue}],
"merged_open": [{dir, id, issue}], "skipped": [{issue, id, reason, paths}], "docker": {total_mib, other_mib, ao_mib,
free_mib, required_mib} | null, "waiting": {dir, id, issue, title} | null}, exit 0, and appends {at, dir_arg, epic,
reason, waiting: <dir|null>, skipped: [{id, reason, paths}]} to <state>/gate.jsonl. `waiting` is the candidate whose
steps ended in a wait (the wait the reason applies to; kept with --force), else null.
--reserve and --claim need --dir (else exit 2). Without --reserve the gate reserves nothing. --reserve: when the
candidate passes, parallel_gate.reserve under the same lock (`reserved: true`, `claim`: the token, the --claim token
when given). With --force also after a wait or skip of the steps (`epic` set, the gate's `reason` kept), except two
active epics (step 2): no reservation, whatever the flags; --force never overrides the refusals of the epic itself
(off the board, merged, not prepared, reserved). Renewal: --reserve --claim
with the token of a valid reservation of --dir runs no gate steps (the decision was taken under the lock when the
reservation was made; only the refusals of the epic itself apply) and renews it: `epic`, `reserved: true`, `claim`,
`reason` null and a new `expires_at`. With an expired or missing reservation the gate decides as for a new one.
Resume: --dir of an epic in progress from its branch runs no steps and reserves nothing. Refusals: the AO process of the
epic runs (parallel_gate.orchestrator_running on <ao-root>/ao-<dir>, --ao-root default ~/.local/state):
`epik X prowadzi działający proces AO`, whatever the flags; a recorded owner other than --claim: `epik X prowadzi
inna sesja`, unless --takeover (a new owner token). Otherwise `resumed: true`, `epic` (tasks and minutes null without a
readable plan.md on origin/main), `claim`: the owner token: --claim when it is the owner, else a new
owner recorded in claims.json (the --claim token when it holds a valid reservation of the epic, else a fresh one).
--takeover outside a resume is ignored. The reservation or owner write is the gate's last step (after gate.jsonl),
so a failure never leaves a token in claims.json that was not printed.

release: removes the reservation of <dir> with the --claim token and prints {"released": <bool>}, exit 0.

hold: the parallel hold after a critical problem of a pair (docs/features/parallel-work/spec.md, „Pomiar pierwszych
dwóch par”): writes <state>/parallel-hold.json {at, reason} and prints it, exit 0; an existing hold is kept (printed,
not overwritten); an empty or blank --reason exits 2. While it exists, the gate (also `next` case (c)) makes every candidate next to an epic in progress
wait; nothing in progress still passes, so serial work goes on. unhold: removes the hold (the user's decision) and
prints {"removed": <bool>}, exit 0 also without a hold. An unreadable parallel-hold.json exits 2 in every command that
reads the gate context (gate, next, propose, approve) and in hold; unhold removes it.

Exit codes: 0 success; 1 a `git`/`gh` command failed or returned unexpected JSON, or a bad --hours of room; 2 usage
and state errors (also an unreadable claims.json or parallel-hold.json, a busy gate lock and a bad --stack-gib).
"""
import argparse
import contextlib
import json
import os
import re
import secrets
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
    """Start/done pairs per epic. `overlap`: the pair's interval intersects a closed interval of another epic, or
    another epic's open start (a start without a later done) lies in it (start <= open start < done), so its usage
    delta may contain the other epic's usage. An open start before the pair taints nothing: a start whose done was
    never recorded would otherwise mark every later measurement."""
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
    opened = [(epic, parse_time(start['at'])) for epic, start in open_starts.items()]
    for item, (epic, begin, end) in zip(result, spans):
        item['overlap'] = (any(epic_ != epic and begin < end_ and begin_ < end for epic_, begin_, end_ in spans)
                           or any(epic_ != epic and begin <= begin_ < end for epic_, begin_ in opened))
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


LOCK_TIMEOUT = 120.0


def state_lock(state_dir):
    """The gate lock of the shared state (parallel_gate.state_lock, LOCK_TIMEOUT seconds)."""
    return parallel_gate.state_lock(state_dir, timeout=LOCK_TIMEOUT)


def _docker_memory(run):
    try:
        return parallel_gate.docker_snapshot(run)
    except parallel_gate.DockerError:
        return None


def cmd_start(args, run, now, stdin):
    # The manifest and Docker are read before the lock: neither is shared state, and `docker stats` is slow.
    tasks = task_count(run, args.dir)
    event = {'event': 'start', 'epic': args.dir, 'tasks': tasks, 'at': now.isoformat(), 'usage': _read_usage(stdin),
             'docker': _docker_memory(run)}
    with state_lock(args.state):
        read_events(args.state)
        current = parallel_gate.owner(parallel_gate.read_claims(args.state), args.dir)
        if current is not None and current != args.claim:
            raise StateError('epik %s ma innego właściciela' % args.dir.upper())
        append_event(args.state, event)
        owner = parallel_gate.claim_to_owner(args.state, args.dir, args.claim, now)
    print(json.dumps(dict(event, owner=owner), ensure_ascii=False))
    return 0


def cmd_done(args, run, now, stdin):
    usage_now = _read_usage(stdin)
    with state_lock(args.state):
        events = read_events(args.state)
        latest = [e for e in events if e.get('epic') == args.dir and e.get('event') in ('start', 'done')]
        if not latest or latest[-1]['event'] != 'start':
            raise StateError('brak start dla %s' % args.dir)
        event = {'event': 'done', 'epic': args.dir, 'at': now.isoformat(), 'usage': usage_now}
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


def _night_clash(ctx, scope, in_progress, chosen):
    """The reason a candidate with `scope` cannot join the night list next to the epics in progress and the epics
    already chosen (gate steps 4-5, blocking_paths), or None when it can join."""
    try:
        found = blocking_paths(ctx, scope, in_progress, [(c['id'], c['scope']) for c in chosen])
    except MissingScope as error:
        return str(error)
    return 'zakres wspólny z %s: %s' % (found[0][0], found[0][1][0]) if found else None


def propose(run, state_dir, until_text, now):
    until = parse_until(until_text, now)
    ctx = gate_context(run, state_dir, now)
    board, measured = ctx.board, ctx.measured
    in_progress = [{'dir': p['dir'], 'id': p['id'], 'issue': p['issue']} for p in ctx.in_progress]
    skipped = [{'issue': e['number'], 'id': e['id'], 'reason': e['reason']}
               for e in board if e['state'] in ('invalid', 'unprepared')]
    claimed = {p['dir'] for p in ctx.in_progress}
    chosen, minutes_total = [], 0.0
    for e in [e for e in board if e['state'] == 'prepared' and e['dir'] not in claimed]:
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
            scope = ctx.files(e['dir'], branch=False)
        except ScopeError as error:
            if chosen or ctx.in_progress:
                skip(str(error))
                continue
            scope = None
        clash = scope is not None and _night_clash(ctx, scope, ctx.in_progress, chosen)
        if clash:
            skip(clash)
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
            'measured': estimate(measured, 1)['minutes'] is not None, 'hold': ctx.hold}


def cmd_propose(args, run, now, stdin):
    print(json.dumps(propose(run, args.state, args.until, now), ensure_ascii=False))
    return 0


def write_night(state_dir, night):
    """Atomic write (temp file and os.replace) by a caller holding the gate lock; StateFileError on failure."""
    parallel_gate.write_atomic(os.path.join(os.path.expanduser(state_dir), NIGHT_FILE), json.dumps(night) + '\n')


def cmd_approve(args, run, now, stdin):
    with state_lock(args.state):
        current = read_night(args.state)
        if current is not None and 'ended_at' not in current and now <= parse_time(current['until']):
            raise StateError('trwa noc zatwierdzona %s do %s' % (current['approved_at'], current['until']))
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


def _next_result(epic_, reason, started, left, wait=False, claim=None):
    return {'epic': epic_, 'reason': reason, 'started': started, 'left': left, 'wait': wait, 'claim': claim}


def _percent(value):
    return '%.1f' % round(value, 1)


def _not_prepared(directory, entry):
    """The reason of check 1: the epic is not on the board as prepared (`entry` is None when absent)."""
    detail = None
    if entry is not None:
        detail = 'w toku' if entry['state'] == 'in_progress' else entry['reason']
    return '%s nie jest już przygotowany%s' % (entry['id'] if entry else directory.upper(),
                                                ': %s' % detail if detail else '')


def _clock(until, now):
    return until.astimezone(now.tzinfo).strftime('%H:%M')


def _too_late(name, minutes, now, until):
    """`X nie zdąży do HH:MM` when now + minutes is after `until`, else None."""
    if now + timedelta(minutes=minutes) > until:
        return '%s nie zdąży do %s' % (name, _clock(until, now))
    return None


def _forecast_failure(name, minutes, usage_estimate, now, until, stdin):
    """Checks 2-5 for a candidate with a time estimate `minutes`: the reason it may not start now, or None."""
    if minutes is None:
        return 'brak pomiaru'
    end = now + timedelta(minutes=minutes)
    late = _too_late(name, minutes, now, until)
    if late:
        return late
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
    with state_lock(args.state):
        night = read_night(args.state)
        if night is None:
            raise StateError('brak zatwierdzonej listy nocy')
        if 'ended_at' not in night:
            night = dict(night, ended_at=now.isoformat(), reason=args.reason)
            write_night(args.state, night)
    print(json.dumps(night, ensure_ascii=False))
    return 0


def cmd_next(args, run, now, stdin):
    with state_lock(args.state):
        return _next(args, run, now, stdin)


def _next(args, run, now, stdin):
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

    running = [d for d in started if [e for e in since if e['epic'] == d][-1]['event'] != 'done']

    def finish(epic_, reason, wait=False):
        """Prints the result. Its only write is the last step: the reservation of a returned epic, or the end of the
        night for `epic: null` without `wait`."""
        after = unstarted if epic_ is None else unstarted[1:]
        claim = None
        if epic_ is not None:
            claim = parallel_gate.reserve(args.state, epic_['dir'], now)
        elif not wait:
            write_night(args.state, dict(night, ended_at=now.isoformat(), reason=reason))
        print(json.dumps(_next_result(epic_, reason, started, after, wait, claim), ensure_ascii=False))
        return 0

    if now > until:
        return finish(None, 'noc minęła (%s)' % _clock(until, now))
    if not unstarted and not running:
        return finish(None, 'lista wykonana')
    ctx = gate_context(run, args.state, now)
    active = [p for p in ctx.in_progress if p['active']]
    untracked = [d for d in running if d not in {p['dir'] for p in active}]
    if untracked:
        return finish(None, '%s bez pomiaru done' % untracked[0])
    if not unstarted:
        return finish(None, 'wszystkie epiki listy ruszyły, w toku: %s' % ', '.join(running), wait=True)
    directory = unstarted[0]
    if not ctx.in_progress and not started:
        # The first epic of the night with nothing in progress: no further checks (and no board entry needed).
        tasks = task_count(run, directory)
        return finish({'dir': directory, 'id': directory.upper(), 'issue': None, 'title': None, 'tasks': tasks,
                       'minutes': estimate(ctx.measured, tasks)['minutes']}, None)
    entry = next((e for e in ctx.board if e['dir'] == directory), None)
    if any(c['dir'] == directory for c in ctx.claims):
        return finish(None, _conflict(entry['id'] if entry else directory.upper()), wait=True)
    if entry is None or entry['state'] != 'prepared':
        return finish(None, _not_prepared(directory, entry))
    if started and not active:
        # Serial: every started epic of the night has `done` and nothing else is active.
        candidate = _epic_of(ctx, entry)
        forecast = estimate(ctx.measured, candidate['tasks'])
        reason = _forecast_failure(entry['id'], forecast['minutes'], forecast['usage'], now, until, stdin)
        return finish(None, reason) if reason else finish(candidate, None)
    # Next to epics in progress: gate steps 2-7, then the time until the end of the night. The evaluation goes to
    # gate.jsonl like a `gate --dir` call (before the final write); a refusal waits while anything is active.
    found = evaluate(ctx, entry, _read_usage(stdin), DEFAULT_STACK_GIB * 1024)
    epic_, reason = found['epic'], found['reason']
    if epic_ is not None:
        reason = _too_late(entry['id'], epic_['minutes'], now, until)
        epic_ = None if reason else epic_
    skipped = [{'issue': entry['number'], 'id': entry['id'], 'reason': found['reason'], 'paths': found['paths']}
               ] if found['outcome'] == SKIP else []
    waiting = _named(entry) if found['outcome'] == WAIT else None
    append_gate_log(args.state, now, directory, {'epic': epic_, 'reason': reason, 'waiting': waiting,
                                                 'skipped': skipped})
    return finish(epic_, reason, wait=epic_ is None and bool(active))


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
HOLD_FILE = 'parallel-hold.json'
MAX_ACTIVE = 2
MAX_PERCENT = 80
DEFAULT_STACK_GIB = 6
PASS, SKIP, WAIT = 'pass', 'skip', 'wait'


class GateContext:
    """What one gate call knows about the board and the epics in progress (spec parallel-work, „Pojęcia”).

    in_progress: [{dir, id, issue, branch, active}]: board epics with an `origin/epic/<dir>` branch and no merged final
    PR (active when their last measurements.jsonl event is `start` or they have none), then valid claims of other
    sessions (always active). `claims`: the valid claims of claims.json without the caller's own one, `own_claim`:
    whether the caller's token holds a valid claim of its `--dir`, `owners`: {dir: token} of claims.json, `hold`: the
    parallel hold {at, reason} of parallel-hold.json or None. Files and task counts are read lazily and cached; step 6
    stores its Docker memory state in `docker` (None when not measured or failed).
    """

    def __init__(self, run, now, board, measured, in_progress, merged_open, claims=(), owners=None, own_claim=False,
                 hold=None):
        self.run, self.now, self.board, self.measured = run, now, board, measured
        self.in_progress, self.merged_open = in_progress, merged_open
        self.claims, self.owners, self.own_claim, self.hold = list(claims), dict(owners or {}), own_claim, hold
        self.docker = None
        self._files, self._tasks, self._fetched, self._on_main = {}, {}, False, None

    def on_main(self, directory, name):
        """Whether origin/main has docs/features/<directory>/<name> (one `git ls-tree` per call of the gate)."""
        if self._on_main is None:
            listing = self.run(['git', 'ls-tree', '-r', '--name-only', 'origin/main', 'docs/features/'])
            self._on_main = {line.strip() for line in listing.splitlines()}
        return 'docs/features/%s/%s' % (directory, name) in self._on_main

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
                if not self.on_main(directory, 'spec.md'):
                    raise ScopeError('brak spec.md na origin/main')
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


def gate_context(run, state_dir, now, own=None):
    """Reads the state (measurements, claims) and the board; the caller holds the gate lock.

    own: (dir, token) of the caller; a valid claim with exactly that dir and token is its own reservation, which is
    neither in progress nor active for it. Every other claim, also one of the same dir, belongs to another session.
    """
    events = read_events(state_dir)
    data = parallel_gate.read_claims(state_dir)
    valid = parallel_gate.valid_claims(data, now)
    claims = [c for c in valid if own is None or (c['dir'], c['claim']) != tuple(own)]
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
    return GateContext(run, now, board, measurements(events), in_progress, merged_open, claims=claims,
                       owners=data['owners'], own_claim=len(claims) < len(valid),
                       hold=read_hold(state_dir))


def _hold_path(state_dir):
    return os.path.join(os.path.expanduser(state_dir), HOLD_FILE)


def read_hold(state_dir):
    """The parallel hold {at, reason} of <state>/parallel-hold.json, None without the file; StateError when
    unreadable."""
    path = _hold_path(state_dir)
    try:
        with open(path, encoding='utf-8') as f:
            hold = json.load(f)
    except FileNotFoundError:
        return None
    except (OSError, UnicodeDecodeError, ValueError) as e:
        raise StateError('nieczytelny plik %s: %s' % (path, getattr(e, 'strerror', None) or e))
    try:
        parse_time(hold['at'])
        if not isinstance(hold['reason'], str):
            raise ValueError('reason')
    except (KeyError, TypeError, ValueError, AttributeError):
        raise StateError('nieczytelny plik %s' % path)
    return hold


def cmd_hold(args, run, now, stdin):
    if not args.reason.strip():
        raise StateError('--reason nie może być pusty: podaj powód wstrzymania (para i krytyczny problem)')
    with state_lock(args.state):
        hold = read_hold(args.state)
        if hold is None:
            hold = {'at': now.isoformat(), 'reason': args.reason}
            # ASCII on disk like night.json and claims.json: write_atomic uses the locale encoding.
            parallel_gate.write_atomic(_hold_path(args.state), json.dumps(hold) + '\n')
    print(json.dumps(hold, ensure_ascii=False))
    return 0


def cmd_unhold(args, run, now, stdin):
    path = _hold_path(args.state)
    with state_lock(args.state):
        try:
            os.remove(path)
            removed = True
        except FileNotFoundError:
            removed = False
        except OSError as e:
            raise StateError('nie można usunąć %s: %s' % (path, e.strerror or e))
    print(json.dumps({'removed': removed}))
    return 0


def check_room(active):
    """Step 2: at most one active epic besides the candidate."""
    if len(active) >= MAX_ACTIVE:
        return 'dwa epiki aktywne: %s' % ', '.join(p['id'] for p in active)
    return None


class MissingScope(Exception):
    """An epic in progress without a readable scope (its message is the reason `X w toku bez zakresu: <error>`)."""


def blocking_paths(ctx, mine, in_progress, known=()):
    """Steps 4-5 of the gate for files `mine`: [(id, blocking paths)] for each epic of `in_progress` (files from
    ctx.files: scope plus branch diff) and then of `known` ((id, files) pairs) that shares with `mine` a path refused by
    parallel_gate.is_allowed, in that order. The files of every epic in progress are read first; one without a readable
    scope raises MissingScope."""
    theirs = []
    for p in in_progress:
        try:
            theirs.append((p['id'], ctx.files(p['dir'], p['branch'])))
        except ScopeError as error:
            raise MissingScope('%s w toku bez zakresu: %s' % (p['id'], error))
    found = []
    for name, files in theirs + list(known):
        blocking = [path for path in parallel_gate.common_paths(mine, files) if not parallel_gate.is_allowed(path)]
        if blocking:
            found.append((name, blocking))
    return found


def check_files(ctx, entry, others):
    """Steps 4-5: (SKIP|WAIT, reason, blocking paths) or None when the candidate shares only allowed paths."""
    try:
        mine = ctx.files(entry['dir'], branch=False)
    except ScopeError as error:
        return SKIP, str(error), []
    try:
        found = blocking_paths(ctx, mine, others)
    except MissingScope as error:
        return WAIT, str(error), []
    if not found:
        return None
    name, first = found[0]
    return SKIP, 'wspólne pliki z %s: %s' % (name, ', '.join(first)), sorted({p for _, paths in found for p in paths})


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
    loads = []
    for p in active:
        if not ctx.on_main(p['dir'], 'plan.md'):
            return '%s bez planu na main' % p['id']
        try:
            loads.append(estimate(ctx.measured, ctx.tasks(p['dir']))['usage'])
        except StateError as error:
            return str(error)
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


def _epic_of(ctx, entry):
    """The `epic` of the gate output for a board entry: {dir, id, issue, title, tasks, minutes}."""
    tasks = ctx.tasks(entry['dir'])
    return {'dir': entry['dir'], 'id': entry['id'], 'issue': entry['number'], 'title': entry['title'],
            'tasks': tasks, 'minutes': estimate(ctx.measured, tasks)['minutes']}


def _named(entry):
    """The `waiting` of the gate output for a board entry: {dir, id, issue, title}."""
    return {'dir': entry['dir'], 'id': entry['id'], 'issue': entry['number'], 'title': entry['title']}


def evaluate(ctx, entry, usage_now, stack_mib):
    """Gate steps 1-7 for one prepared board entry against the epics in progress.

    Returns {outcome: PASS|SKIP|WAIT, reason, paths, epic, forceable}; `epic` ({dir, id, issue, title, tasks,
    minutes}) is set only on PASS; `forceable` is false only for step 2 (room), which --force never overrides. Step 6
    sets ctx.docker.
    """
    others = ctx.others(entry['dir'])
    active = [p for p in others if p['active']]

    def verdict(outcome, reason=None, paths=(), forceable=True):
        return {'outcome': outcome, 'reason': reason, 'paths': list(paths), 'epic': None, 'forceable': forceable}

    def passed():
        result = verdict(PASS)
        result['epic'] = _epic_of(ctx, entry)
        return result

    if not others:
        return passed()
    reason = check_room(active)
    if reason:
        return verdict(WAIT, reason, forceable=False)
    if ctx.hold is not None:
        # After a critical problem of a pair: no epic starts next to an epic in progress until the user decides.
        return verdict(WAIT, 'równoległość wstrzymana: %s' % ctx.hold['reason'])
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
    return passed()


def _conflict(name):
    return 'epik %s zarezerwowany albo w toku' % name


def _refusal(ctx, directory):
    """(reason, None) when `--dir` names neither a candidate nor an epic to resume (off the board, merged, reserved by
    another session, not prepared), else (None, board entry): a prepared candidate or an epic in progress from its
    branch (state `in_progress`, resumed by gate_resume)."""
    entry = next((e for e in ctx.board if e['dir'] == directory), None)
    if entry is None:
        return directory.upper() + ' nie ma na tablicy', None
    if any(m['dir'] == directory for m in ctx.merged_open):
        return '%s ma zmergowany końcowy PR' % entry['id'], None
    if any(c['dir'] == directory for c in ctx.claims):
        return _conflict(entry['id']), None
    if entry['state'] not in ('prepared', 'in_progress'):
        return entry['reason'], None
    return None, entry


def gate_decision(ctx, dir_arg, usage_now, stack_mib, renewal=False):
    """The gate result for `--dir` (or the first board candidate that passes); writes nothing.

    Returns (result, entry, forceable). `entry` is the board entry of `--dir` when the gate steps ran on it (a prepared
    candidate) or when no steps ran: it is in progress from its branch (the caller resumes it with gate_resume) or, with
    `renewal`, a prepared candidate whose valid reservation the caller renews (the decision was taken under the lock
    when the reservation was made); otherwise None. `forceable` is false when the steps of `--dir` stopped at step 2,
    which --force never overrides.
    """
    result = {'epic': None, 'reason': None, 'reserved': False, 'claim': None, 'resumed': False, 'parallel_with': [],
              'active': [p['dir'] for p in ctx.in_progress if p['active']],
              'in_progress': [{k: p[k] for k in ('dir', 'id', 'issue')} for p in ctx.in_progress],
              'merged_open': ctx.merged_open, 'skipped': [], 'docker': None, 'waiting': None}
    if dir_arg is not None:
        result['reason'], entry = _refusal(ctx, dir_arg)
        if entry is not None and (entry['state'] == 'in_progress' or renewal):
            return result, entry, True
        candidates = [entry] if entry else []
    else:
        claimed = {p['dir'] for p in ctx.in_progress}
        candidates = [e for e in ctx.board if e['state'] == 'prepared' and e['dir'] not in claimed]
    forceable = True
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
            if found['outcome'] == WAIT:
                result['waiting'] = _named(entry)
            forceable = found['forceable']
        break
    result['docker'] = ctx.docker
    return result, (candidates[0] if dir_arg is not None and candidates else None), forceable


def _new_token():
    return secrets.token_hex(8)


def gate_resume(ctx, result, entry, claim, takeover, ao_root):
    """Resume of `entry`, in progress from its branch: no gate steps and no reservation, only the owner check
    (spec parallel-work, „Wznowienie”). Writes nothing; returns (result, owner): `owner` is the new owner token the
    caller records in claims.json as the last step (None when the owner stays or on a refusal)."""
    name = entry['id']
    if parallel_gate.orchestrator_running(os.path.join(ao_root, 'ao-%s' % entry['dir'])):
        result['reason'] = 'epik %s prowadzi działający proces AO' % name
        return result, None
    current, owner = ctx.owners.get(entry['dir']), None
    if current is None or current != claim:
        if current is not None and not takeover:
            result['reason'] = 'epik %s prowadzi inna sesja' % name
            return result, None
        # A new owner: the caller's own valid reservation becomes the owner token, else a fresh token.
        owner = claim = claim if claim is not None and ctx.own_claim else _new_token()
    epic_ = {'dir': entry['dir'], 'id': name, 'issue': entry['number'], 'title': entry['title'], 'tasks': None,
             'minutes': None}
    if ctx.on_main(entry['dir'], 'plan.md'):
        with contextlib.suppress(StateError):
            epic_ = _epic_of(ctx, entry)
    result.update(epic=epic_, resumed=True, claim=claim, parallel_with=[p['dir'] for p in ctx.others(entry['dir'])])
    return result, owner


def gate_reserve(ctx, result, entry, claim, force):
    """Reservation of the `--dir` candidate `entry` after the gate steps: when it passed, or with `force` despite a
    wait or skip of the steps (the gate's reason stays in the result) and for a renewal, which ran no steps. Writes
    nothing; returns (result, token): the token the caller reserves (parallel_gate.reserve) as the last step, None
    when nothing is reserved."""
    if result['epic'] is None and not force:
        return result, None
    token = claim if claim is not None else _new_token()
    result.update(epic=result['epic'] or _epic_of(ctx, entry), reserved=True, claim=token,
                  parallel_with=[p['dir'] for p in ctx.others(entry['dir'])])
    return result, token


def append_gate_log(state_dir, now, dir_arg, result):
    line = {'at': now.isoformat(), 'dir_arg': dir_arg, 'epic': result['epic']['dir'] if result['epic'] else None,
            'reason': result['reason'], 'waiting': result['waiting']['dir'] if result['waiting'] else None,
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
    if args.dir is None and (args.reserve or args.claim is not None):
        raise StateError('--reserve i --claim wymagają --dir')
    usage_now = _read_usage(stdin)
    with state_lock(args.state):
        ctx = gate_context(run, args.state, now, own=(args.dir, args.claim) if args.claim is not None else None)
        # --reserve with the token of a valid reservation of --dir renews it without the gate steps.
        renewal = args.reserve and ctx.own_claim
        result, entry, forceable = gate_decision(ctx, args.dir, usage_now, stack_gib * 1024, renewal)
        owner = token = None
        if entry is not None and entry['state'] == 'in_progress':
            result, owner = gate_resume(ctx, result, entry, args.claim, args.takeover, args.ao_root)
        elif entry is not None and args.reserve:
            # --force overrides steps 3-7 and the hold, never step 2 (room).
            result, token = gate_reserve(ctx, result, entry, args.claim, (args.force and forceable) or renewal)
        # Everything that can fail runs before the state write: a token in claims.json is always printed.
        append_gate_log(args.state, now, args.dir, result)
        if owner is not None:
            parallel_gate.claim_to_owner(args.state, args.dir, owner, now)
        if token is not None:
            try:
                parallel_gate.reserve(args.state, args.dir, now, token)
            except parallel_gate.ClaimConflict:
                # Unreachable under the lock (_refusal already saw the claims); kept as a safe refusal.
                result.update(epic=None, reason=_conflict(result['epic']['id']), reserved=False, claim=None,
                              parallel_with=[])
    print(json.dumps(result, ensure_ascii=False))
    return 0


def cmd_release(args, run, now, stdin):
    with state_lock(args.state):
        released = parallel_gate.release(args.state, args.dir, args.claim)
    print(json.dumps({'released': released}))
    return 0


def main(argv=None, run=None, now=None, stdin=None):
    parser = argparse.ArgumentParser(description='Night queue of several epics')
    parser.add_argument('--repo', default='.')
    parser.add_argument('--state', default='~/.local/state/ao-night')
    sub = parser.add_subparsers(dest='command', required=True)
    scope = sub.add_parser('scope', help='print the Zakres line of a spec')
    scope.add_argument('spec')
    start_parser = sub.add_parser('start', help='record the start of an epic and its owner')
    start_parser.add_argument('dir')
    start_parser.add_argument('--claim')
    sub.add_parser('done', help='record the end of an epic and print its measurement').add_argument('dir')
    release_parser = sub.add_parser('release', help='remove the own reservation of an epic')
    release_parser.add_argument('dir')
    release_parser.add_argument('--claim', required=True)
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
    gate_parser.add_argument('--reserve', action='store_true')
    gate_parser.add_argument('--claim')
    gate_parser.add_argument('--force', action='store_true')
    gate_parser.add_argument('--takeover', action='store_true')
    gate_parser.add_argument('--ao-root', default='~/.local/state')
    hold_parser = sub.add_parser('hold', help='stop parallel starts until unhold (after a critical problem)')
    hold_parser.add_argument('--reason', required=True)
    sub.add_parser('unhold', help='allow parallel starts again (the decision of the user)')
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
                'gate': cmd_gate, 'release': cmd_release, 'hold': cmd_hold,
                'unhold': cmd_unhold}[args.command](args, run, now, stdin)
    except (StateError, parallel_gate.StateFileError, parallel_gate.LockTimeout) as e:
        print('night_queue: %s' % e, file=sys.stderr)
        return 2
    except next_epic.CommandError as e:
        print('night_queue: %s' % e, file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())

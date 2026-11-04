#!/usr/bin/env python3
"""Night queue of several epics for the /ao-run skill. Python 3.9+, standard library only.

  night_queue.py [--repo DIR] [--state DIR] scope <spec.md>
  night_queue.py [--repo DIR] [--state DIR] start <dir>   (stdin: the `plan` object of get_usage)
  night_queue.py [--repo DIR] [--state DIR] done <dir>    (stdin: the `plan` object of get_usage)

scope: reads the `Zakres:` line from the header of an epic spec (the lines before the first `## ` heading) and prints
one JSON line `{"scope": [path, ...]}`, exit 0. A missing, doubled or unreadable line, or an unreadable file, prints
the reason to stderr and exits 2.

start: counts the tasks in the manifest of origin/main:docs/features/<dir>/plan.md and appends a start event to
<state>/measurements.jsonl. done: appends a done event for the latest start without one and prints the measurement
(tasks, minutes, usage delta per limit window). A start/done pair is a measurement only through `measurements`.

Exit codes: 0 success; 1 a `git`/`gh` command failed or returned unexpected JSON; 2 usage and state errors.
"""
import argparse
import json
import os
import re
import sys
from datetime import datetime

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import next_epic  # noqa: E402,F401  (CommandError and the command runner shared with next_epic.py)

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
    return datetime.fromisoformat(text.replace('Z', '+00:00'))


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
    open_starts = {}
    result = []
    for event in events:
        kind, epic = event.get('event'), event.get('epic')
        if kind == 'start':
            open_starts[epic] = event
        elif kind == 'done' and epic in open_starts:
            start = open_starts.pop(epic)
            minutes = (parse_time(event['at']) - parse_time(start['at'])).total_seconds() / 60
            result.append({'epic': epic, 'tasks': start['tasks'], 'minutes': minutes,
                           'usage': _window_deltas(start['usage'], event['usage'])})
    return result


def estimate(measured, tasks):
    measured = [m for m in measured if m['tasks'] > 0]
    if not measured:
        return {'minutes': None, 'usage': {}}
    per_label = {}
    for m in measured:
        for label, delta in m['usage'].items():
            per_label[label] = max(per_label.get(label, 0), delta / m['tasks'])
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


def main(argv=None, run=None, now=None, stdin=None):
    parser = argparse.ArgumentParser(description='Night queue of several epics')
    parser.add_argument('--repo', default='.')
    parser.add_argument('--state', default='~/.local/state/ao-night')
    sub = parser.add_subparsers(dest='command', required=True)
    scope = sub.add_parser('scope', help='print the Zakres line of a spec')
    scope.add_argument('spec')
    for name in ('start', 'done'):
        sub.add_parser(name).add_argument('dir')
    try:
        args = parser.parse_args(argv)
    except SystemExit as e:
        return 2 if e.code else 0
    if args.command == 'scope':
        return cmd_scope(args)
    run = run or next_epic.make_runner(args.repo)
    now = now or datetime.now().astimezone()
    try:
        return {'start': cmd_start, 'done': cmd_done}[args.command](args, run, now, stdin)
    except StateError as e:
        print('night_queue: %s' % e, file=sys.stderr)
        return 2
    except next_epic.CommandError as e:
        print('night_queue: %s' % e, file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())

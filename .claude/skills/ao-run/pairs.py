#!/usr/bin/env python3
"""Measurement of the first parallel epic pairs (step 19a). TEMPORARY: remove this script and test_pairs.py after the
decisions on step 19b and on the shared CLAUDE.md rule (docs/features/parallel-work/spec.md).

    pairs.py [--state DIR] [--setup-diag DIR]

--state defaults to ~/.local/state/ao-night (measurements.jsonl, gate.jsonl), --setup-diag to
~/.local/state/ao-setup. Prints one JSON line, exit 0:

    {"pairs": [...], "claude_md_only": [...]}

Intervals: a `start` event opens an interval of its epic, the next `done` of that epic closes it, a later `start`
without a `done` replaces the open one; an open interval (`open: true`) ends at now. Lines written before 19a have no
`docker` key on `start`: an open interval whose start lacks it is ignored (an epic whose done was never recorded). A
pair is two intervals of different epics that overlap and whose later start has the `docker` key (also null), ordered
by the earlier start, then the later one. Each pair:
  epics            [{dir, start, done|null, minutes, open}, {...}]
  overlap_minutes  the intersection of the two intervals
  span_minutes     first start to last end
  serial_minutes   the sum of both durations
  saving           1 - span/serial (3 decimals), null when a member is open
  usage            {window label: percent at the last done minus percent at the first start}, as
                   night_queue._window_deltas (empty when the last-ending member is open)
  docker           the `docker` field of the later start, or null
  setup_failures   files ao-*-YYYYMMDD-HHMMSS-*.log in --setup-diag (local time) inside the span; no directory: 0
claude_md_only: from gate.jsonl, skipped entries with non-empty `paths` that are all CLAUDE.md files, grouped by id:
{id, first, last, count} (`at` of the gate lines), ordered by first.

Exit codes: 0 success; 2 an unreadable measurements.jsonl or gate.jsonl.
"""
import argparse
import json
import os
import re
import sys
from datetime import datetime

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import night_queue  # noqa: E402
import parallel_gate  # noqa: E402

LOG_NAME = re.compile(r'^ao-.+-(\d{8}-\d{6})-.+\.log$')


def _minutes(delta):
    return delta.total_seconds() / 60


def intervals(events, now):
    """[{epic, start (event), done (event|None), begin, end, open}] in the order the intervals were opened."""
    found, open_ones = [], {}
    for event in events:
        epic = event['epic']
        if event['event'] == 'start':
            if epic in open_ones:
                found.remove(open_ones[epic])
            item = {'epic': epic, 'start': event, 'done': None, 'begin': night_queue.parse_time(event['at'])}
            open_ones[epic] = item
            found.append(item)
        elif epic in open_ones:
            open_ones.pop(epic)['done'] = event
    for item in found:
        item['open'] = item['done'] is None
        item['end'] = now if item['open'] else night_queue.parse_time(item['done']['at'])
    # An open interval from before 19a (start without `docker`) is an epic whose done was never recorded.
    return [item for item in found if not item['open'] or _is_19a(item)]


def _is_19a(item):
    """Whether the start of the interval was written by 19a: it has the `docker` key (also null)."""
    return 'docker' in item['start']


def setup_failures(directory, begin, end):
    try:
        names = os.listdir(os.path.expanduser(directory))
    except OSError:
        return 0
    count = 0
    for name in names:
        match = LOG_NAME.match(name)
        if not match:
            continue
        try:
            stamp = datetime.strptime(match.group(1), '%Y%m%d-%H%M%S').astimezone()
        except ValueError:
            continue
        if begin <= stamp <= end:
            count += 1
    return count


def _member(item):
    return {'dir': item['epic'], 'start': item['start']['at'], 'done': item['done']['at'] if item['done'] else None,
            'minutes': round(_minutes(item['end'] - item['begin']), 1), 'open': item['open']}


def build_pair(first, second, diag):
    begin = first['begin']
    last = max(first, second, key=lambda item: item['end'])
    end = last['end']
    overlap = min(first['end'], second['end']) - max(first['begin'], second['begin'])
    span = end - begin
    serial = (first['end'] - first['begin']) + (second['end'] - second['begin'])
    anyopen = first['open'] or second['open']
    saving = None if anyopen else round(1 - span / serial, 3)
    usage = {} if last['open'] else night_queue._window_deltas(first['start']['usage'], last['done']['usage'])
    return {'epics': [_member(first), _member(second)], 'overlap_minutes': round(_minutes(overlap), 1),
            'span_minutes': round(_minutes(span), 1), 'serial_minutes': round(_minutes(serial), 1),
            'saving': saving, 'usage': usage, 'docker': second['start'].get('docker'),
            'setup_failures': setup_failures(diag, begin, end)}


def find_pairs(events, now, diag):
    items = sorted(intervals(events, now), key=lambda item: item['begin'])
    found = []
    for i, first in enumerate(items):
        for second in items[i + 1:]:
            # Only overlaps that began under 19a: the later start has the `docker` key.
            if second['epic'] != first['epic'] and second['begin'] < first['end'] and _is_19a(second):
                found.append(build_pair(first, second, diag))
    return found


def read_gate(state_dir):
    path = os.path.join(os.path.expanduser(state_dir), 'gate.jsonl')
    try:
        with open(path, encoding='utf-8') as f:
            lines = f.read().splitlines()
    except FileNotFoundError:
        return []
    except (OSError, UnicodeDecodeError) as e:
        raise night_queue.StateError('nieczytelny plik %s: %s' % (path, getattr(e, 'strerror', None) or e))
    entries = []
    for number, line in enumerate(lines, 1):
        if not line.strip():
            continue
        try:
            entry = json.loads(line)
            if not isinstance(entry, dict) or not isinstance(entry.get('at'), str):
                raise ValueError
            skipped = entry.get('skipped', [])
            if not isinstance(skipped, list) or not all(isinstance(s, dict) for s in skipped):
                raise ValueError
        except ValueError:
            raise night_queue.StateError('nieczytelna linia %d w %s' % (number, path))
        entries.append(entry)
    return entries


def claude_md_only(entries):
    grouped = {}
    for entry in entries:
        for skipped in entry.get('skipped', []):
            paths = skipped.get('paths')
            if not isinstance(paths, list) or not paths or not all(
                    isinstance(p, str) and parallel_gate.is_claude_md(p) for p in paths):
                continue
            item = grouped.setdefault(skipped.get('id'), {'id': skipped.get('id'), 'first': entry['at'],
                                                          'last': entry['at'], 'count': 0})
            item['first'] = min(item['first'], entry['at'], key=night_queue.parse_time)
            item['last'] = max(item['last'], entry['at'], key=night_queue.parse_time)
            item['count'] += 1
    return sorted(grouped.values(), key=lambda item: night_queue.parse_time(item['first']))


def main(argv=None, now=None):
    parser = argparse.ArgumentParser(description='Measurement of the first parallel epic pairs (temporary).')
    parser.add_argument('--state', default='~/.local/state/ao-night')
    parser.add_argument('--setup-diag', default='~/.local/state/ao-setup')
    args = parser.parse_args(argv)
    now = now or datetime.now().astimezone()
    try:
        result = {'pairs': find_pairs(night_queue.read_events(args.state), now, args.setup_diag),
                  'claude_md_only': claude_md_only(read_gate(args.state))}
    except (night_queue.StateError, ValueError) as e:
        print(e, file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())

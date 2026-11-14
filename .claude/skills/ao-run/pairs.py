#!/usr/bin/env python3
"""Metrics of parallel epic pairs (step 19a, docs/features/parallel-work/spec.md, "Metryki par").

    pairs.py [--state DIR] [--setup-diag DIR]
    pairs.py record A B [--state DIR]        (stdin: the verdict of the pair, a JSON object)

--state defaults to ~/.local/state/ao-night (measurements.jsonl, gate.jsonl, pairs.jsonl), --setup-diag to
~/.local/state/ao-setup. The report prints one JSON line, exit 0:

    {"pairs": [...], "claude_md_only": [...], "code_blocked": [...], "summary": {...}}

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
  record           the last pairs.jsonl line of these two epics (in any order), or null
claude_md_only: from gate.jsonl, skipped entries with non-empty `paths` that are all CLAUDE.md files, grouped by id:
{id, first, last, count} (`at` of the gate lines), ordered by first. Only lines of the previous file rule have them.
code_blocked: the same for skipped entries with a path that the current rule blocks (parallel_gate.is_allowed).
summary: admitted (number of pairs), recorded (pairs with a record), the sums of VERDICT_COUNTS over the last record
of each pair, needed_intervention (conflicts - auto_resolved) and critical_pairs (records with non-empty `critical`);
then the same sums with `pairs` for records whose `shared` holds a CLAUDE.md (claude_md) or application code (code).

record appends {at, epics: [A, B], ...verdict} to <state>/pairs.jsonl under the gate lock and prints it. The verdict
has exactly: shared (list of non-empty paths or null), the VERDICT_COUNTS (non-negative integers, auto_resolved at
most conflicts) and critical (distinct numbers 1-4). A later record of the same pair replaces an earlier one.

Exit codes: 0 success; 2 an unreadable state file, a bad verdict, A equal to B or a busy gate lock (nothing written).
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
PAIRS_LOG = 'pairs.jsonl'
VERDICT_COUNTS = ('conflicts', 'auto_resolved', 'doc_fixes', 'interventions', 'regressions')
VERDICT_KEYS = ('shared',) + VERDICT_COUNTS + ('critical',)
CRITICAL = (1, 2, 3, 4)


class VerdictError(Exception):
    pass


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


def find_pairs(events, now, diag, records=None):
    records = records or {}
    items = sorted(intervals(events, now), key=lambda item: item['begin'])
    found = []
    for i, first in enumerate(items):
        for second in items[i + 1:]:
            # Only overlaps that began under 19a: the later start has the `docker` key.
            if second['epic'] != first['epic'] and second['begin'] < first['end'] and _is_19a(second):
                pair = build_pair(first, second, diag)
                pair['record'] = records.get(_pair_key(first['epic'], second['epic']))
                found.append(pair)
    return found


def _pair_key(a, b):
    return tuple(sorted((a, b)))


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
    return _skipped_by(entries, lambda paths: all(parallel_gate.is_claude_md(p) for p in paths))


def code_blocked(entries):
    return _skipped_by(entries, lambda paths: any(not parallel_gate.is_allowed(p) for p in paths))


def _skipped_by(entries, test):
    """Skipped gate entries with non-empty string `paths` that pass `test`, grouped by id: [{id, first, last,
    count}] ordered by first."""
    grouped = {}
    for entry in entries:
        for skipped in entry.get('skipped', []):
            paths = skipped.get('paths')
            if (not isinstance(paths, list) or not paths or not all(isinstance(p, str) for p in paths)
                    or not test(paths)):
                continue
            item = grouped.setdefault(skipped.get('id'), {'id': skipped.get('id'), 'first': entry['at'],
                                                          'last': entry['at'], 'count': 0})
            item['first'] = min(item['first'], entry['at'], key=night_queue.parse_time)
            item['last'] = max(item['last'], entry['at'], key=night_queue.parse_time)
            item['count'] += 1
    return sorted(grouped.values(), key=lambda item: night_queue.parse_time(item['first']))


def _count(value):
    return isinstance(value, int) and not isinstance(value, bool) and value >= 0


def check_verdict(verdict):
    if not isinstance(verdict, dict):
        raise VerdictError('werdykt musi być obiektem JSON')
    missing = [k for k in VERDICT_KEYS if k not in verdict]
    extra = sorted(k for k in verdict if k not in VERDICT_KEYS)
    if missing or extra:
        raise VerdictError('pola werdyktu: brak %s, nadmiar %s' % (missing, extra))
    shared = verdict['shared']
    if shared is not None and (not isinstance(shared, list)
                               or not all(isinstance(p, str) and p for p in shared)):
        raise VerdictError('shared: lista ścieżek albo null')
    for key in VERDICT_COUNTS:
        if not _count(verdict[key]):
            raise VerdictError('%s: nieujemna liczba całkowita' % key)
    if verdict['auto_resolved'] > verdict['conflicts']:
        raise VerdictError('auto_resolved większe od conflicts')
    critical = verdict['critical']
    if (not isinstance(critical, list) or len(set(map(repr, critical))) != len(critical)
            or not all(_count(n) and n in CRITICAL for n in critical)):
        raise VerdictError('critical: lista różnych numerów 1-4')


def read_records(state_dir):
    """{sorted epic pair: last pairs.jsonl line of that pair}."""
    path = os.path.join(os.path.expanduser(state_dir), PAIRS_LOG)
    try:
        with open(path, encoding='utf-8') as f:
            lines = f.read().splitlines()
    except FileNotFoundError:
        return {}
    except (OSError, UnicodeDecodeError) as e:
        raise night_queue.StateError('nieczytelny plik %s: %s' % (path, getattr(e, 'strerror', None) or e))
    records = {}
    for number, line in enumerate(lines, 1):
        if not line.strip():
            continue
        try:
            record = json.loads(line)
            if not isinstance(record, dict) or not isinstance(record.get('at'), str):
                raise ValueError
            epics = record.get('epics')
            if (not isinstance(epics, list) or len(epics) != 2 or not all(isinstance(e, str) and e for e in epics)
                    or epics[0] == epics[1]):
                raise ValueError
            check_verdict({k: v for k, v in record.items() if k not in ('at', 'epics')})
        except (ValueError, VerdictError):
            raise night_queue.StateError('nieczytelna linia %d w %s' % (number, path))
        records[_pair_key(*epics)] = record
    return records


def _sums(records):
    sums = {k: sum(r[k] for r in records) for k in VERDICT_COUNTS}
    sums['needed_intervention'] = sums['conflicts'] - sums['auto_resolved']
    sums['critical_pairs'] = sum(1 for r in records if r['critical'])
    return sums


def _shares(record, test):
    return any(test(p) for p in record['shared'] or [])


def summary(pairs, records):
    recorded = list(records.values())
    result = dict(_sums(recorded), admitted=len(pairs), recorded=len(recorded))
    for name, test in (('claude_md', parallel_gate.is_claude_md), ('code', parallel_gate.is_app_code)):
        group = [r for r in recorded if _shares(r, test)]
        result[name] = dict(_sums(group), pairs=len(group))
    return result


def report(args, now):
    records = read_records(args.state)
    found = find_pairs(night_queue.read_events(args.state), now, args.setup_diag, records)
    entries = read_gate(args.state)
    return {'pairs': found, 'claude_md_only': claude_md_only(entries), 'code_blocked': code_blocked(entries),
            'summary': summary(found, records)}


def record(args, now, stdin):
    if args.a == args.b:
        raise VerdictError('para wymaga dwóch różnych epików')
    try:
        verdict = json.loads(stdin.read())
    except ValueError:
        raise VerdictError('nieczytelny JSON werdyktu')
    check_verdict(verdict)
    line = dict(verdict, at=now.isoformat(), epics=[args.a, args.b])
    with parallel_gate.state_lock(args.state):
        read_records(args.state)   # an unreadable file is not appended to
        path = os.path.join(os.path.expanduser(args.state), PAIRS_LOG)
        try:
            with open(path, 'a', encoding='utf-8') as f:
                f.write(json.dumps(line, ensure_ascii=False) + '\n')
        except OSError as e:
            raise night_queue.StateError('nie można zapisać %s: %s' % (PAIRS_LOG, e.strerror or e))
    return line


def main(argv=None, now=None, stdin=None):
    argv = list(sys.argv[1:] if argv is None else argv)
    parser = argparse.ArgumentParser(description='Metrics of parallel epic pairs.')
    parser.add_argument('--state', default='~/.local/state/ao-night')
    if argv[:1] == ['record']:
        parser.add_argument('command')
        parser.add_argument('a')
        parser.add_argument('b')
    else:
        parser.add_argument('--setup-diag', default='~/.local/state/ao-setup')
    args = parser.parse_args(argv)
    now = now or datetime.now().astimezone()
    try:
        if argv[:1] == ['record']:
            result = record(args, now, stdin or sys.stdin)
        else:
            result = report(args, now)
    except (night_queue.StateError, VerdictError, parallel_gate.LockTimeout, parallel_gate.StateFileError,
            ValueError) as e:
        print(e, file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env python3
"""Events of an AO run for the /ao-run skill. Python 3.9+, standard library only.

  record FILE
      Used as AO's `--notify-cmd`: reads one JSON event from stdin and appends it as one line to FILE (parent
      directories are created). Empty or invalid input is stored as {"type": "invalid_event", "raw": <first 500
      characters>}. Exit code 0 when the line was written, 1 (message on stderr) when it was not.
  watch FILE [--from-start] [--epic-label NAME]
      Follows FILE like `tail -F` (waits for it to appear, reads only new lines unless --from-start) and prints one
      push-message line per notifiable event, flushed. Lines that are not JSON are skipped. Exit code 0 on SIGTERM
      or SIGINT.
  human FILE --action (answer|resume|release|epic-resume) [--task ID]
      Appends {"type": "human_action", "task_id": ID or null, "epic_id": null, "data": {"action"}, "at": <now>} to
      FILE: /ao-run records each decision of the user it passes to AO (`ao answer`, `ao resume`, `ao release`,
      `ao epic resume`). A task decision has --task; a decision on the epic or the queue has none. Exit code 0 when the
      line was written, 1 (message on stderr) when it was not, 2 for invalid arguments.
  summary FILE [--now EPOCH]
      Prints one JSON line {"interventions", "waits", "wait_s", "open": [{"task_id", "since", "wait_s"}]} for FILE:
      interventions = human_action events; a wait for the user starts at a task's status_changed to BLOCKED or FAILED
      (key: the task) or at epic_blocked / queue_held (key: null) and ends at the next human_action with the same key;
      wait_s is the wall-clock time covered by at least one ended wait (overlapping waits count once), `open` lists
      the waits not ended yet, each measured until the last epic_done after its start or else until --now (default:
      the current time). `interventions` counts the decisions recorded by /ao-run; `ao report` counts AO's own. Lines that are not JSON objects are skipped. A missing or unreadable FILE is exit code 1 with a message on
      stderr and nothing on stdout: no data is not zero.

An AO event is {"type", "task_id", "epic_id", "data", "at"} (orchestrator/notify.py, Notifier._payload). The `data`
keys of the notifiable events (AO origin/main):
  status_changed  to (BLOCKED | FAILED), from, reason; for BLOCKED also blockers: up to 5 question texts
                  (core.py, notify.py)
  epic_blocked    epic_id, from, reason (integration.py:631)
  queue_held      the hold: kind, task_id, reasons (list of texts), at, and kind-specific keys (core.py:357, 614, 701)
  final_pr_created  epic_id, pr_number, url, head_sha (integration.py:755)
  epic_done       epic_id, why (integration.py:767)
  usage_warning   window, utilization (0..1), resets_at, threshold (core.py:997)
  attempt_stalled idle_s (core.py:210, 219)
  cooldown_set    from, until, reason (core.py:907, 1010)
AO also sends host_on_battery (percent, low); it is recorded but never pushed: the user decides about power.
"""
import argparse
import json
import os
import signal
import sys
import time

HUMAN_ACTIONS = ('answer', 'resume', 'release', 'epic-resume')
WAIT_STATUSES = ('BLOCKED', 'FAILED')
EPIC_WAITS = ('epic_blocked', 'queue_held')
MAX_MESSAGE = 200
RAW_LIMIT = 500
POLL_S = 1.0


def _first(items):
    return str(items[0]) if isinstance(items, list) and items else None


def _percent(value):
    return '%d%%' % round(value * 100) if isinstance(value, (int, float)) else '?'


def _message(event):
    """The message without the label prefix, or None for an event that is not pushed."""
    kind = event.get('type')
    data = event.get('data') if isinstance(event.get('data'), dict) else {}
    task = event.get('task_id')
    if kind == 'status_changed':
        if data.get('to') == 'BLOCKED':
            question = _first(data.get('blockers'))
            return 'BLOCKED %s: %s' % (task, question if question else 'powód %s' % data.get('reason'))
        if data.get('to') == 'FAILED':
            return 'FAILED %s: %s' % (task, data.get('reason'))
        return None
    if kind == 'epic_blocked':
        return 'epik zablokowany: %s' % data.get('reason')
    if kind == 'queue_held':
        return 'kolejka wstrzymana: %s' % (_first(data.get('reasons')) or data.get('reason') or data.get('kind'))
    if kind == 'final_pr_created':
        return 'końcowy PR %s' % (data.get('url') or '#%s' % data.get('pr_number'))
    if kind == 'epic_done':
        return 'końcowy PR zielony, gotowy do odbioru'
    if kind == 'usage_warning':
        return 'limit użycia %s: %s' % (data.get('window'), _percent(data.get('utilization')))
    if kind == 'attempt_stalled':
        idle = data.get('idle_s')
        return 'zadanie %s bez postępu od %s s' % (task, round(idle) if isinstance(idle, (int, float)) else '?')
    if kind == 'cooldown_set':
        return 'przerwa do odnowienia limitu (%s)' % data.get('reason')
    if kind == 'invalid_event':
        return 'niepoprawne zdarzenie AO'
    return None


def format_event(event, label):
    """One push line (max 200 characters) for an AO event, or None when the event is not pushed."""
    if not isinstance(event, dict):
        return None
    message = _message(event)
    if message is None:
        return None
    text = '%s: %s' % (label or event.get('epic_id') or 'AO', message)
    text = ' '.join(text.split('\n')).replace('\r', ' ')
    return text if len(text) <= MAX_MESSAGE else text[:MAX_MESSAGE - 1] + '…'


def _append(path, event):
    line = json.dumps(event, ensure_ascii=False) + '\n'
    try:
        directory = os.path.dirname(os.path.abspath(path))
        os.makedirs(directory, exist_ok=True)
        with open(path, 'a', encoding='utf-8') as f:
            f.write(line)
    except OSError as e:
        print('ao_events: cannot write %s: %s' % (path, e.strerror or e), file=sys.stderr)
        return 1
    return 0


def record(path):
    raw = sys.stdin.read()
    try:
        event = json.loads(raw)
        if not isinstance(event, dict) or 'type' not in event:
            raise ValueError('no type')
    except ValueError:
        event = {'type': 'invalid_event', 'raw': raw[:RAW_LIMIT]}
    return _append(path, event)


def human(path, action, task):
    return _append(path, {'type': 'human_action', 'task_id': task, 'epic_id': None, 'data': {'action': action},
                          'at': time.time()})


def _wait_key(event):
    """(True, key) when the event starts a wait for the user, else (False, None)."""
    kind = event.get('type')
    data = event.get('data') if isinstance(event.get('data'), dict) else {}
    if kind == 'status_changed' and data.get('to') in WAIT_STATUSES and event.get('task_id'):
        return True, event['task_id']
    if kind in EPIC_WAITS:
        return True, None
    return False, None


def _union_s(intervals):
    """Seconds covered by at least one of the (start, end) intervals."""
    total, reach = 0.0, None
    for start, end in sorted(intervals):
        if reach is None or start > reach:
            total += end - start
            reach = end
        elif end > reach:
            total += end - reach
            reach = end
    return total


def summarize(events, now):
    """Interventions and waits for the user in a list of AO events (see `summary` in the module docstring)."""
    interventions, waits, closed, opened, done = 0, 0, [], {}, []
    for event in events:
        if not isinstance(event, dict) or isinstance(event.get('at'), bool) \
                or not isinstance(event.get('at'), (int, float)):
            continue
        if event.get('type') == 'epic_done':
            done.append(float(event['at']))
        if event.get('type') == 'human_action':
            interventions += 1
            since = opened.pop(event.get('task_id'), None)
            if since is not None:
                closed.append((since, max(since, float(event['at']))))
            continue
        starts, key = _wait_key(event)
        if starts and key not in opened:
            opened[key] = float(event['at'])
            waits += 1
    still = []
    for key, since in opened.items():
        end = max([at for at in done if at >= since], default=now)
        still.append({'task_id': key, 'since': since, 'wait_s': max(0.0, end - since)})
    return {'interventions': interventions, 'waits': waits, 'wait_s': _union_s(closed), 'open': still}


def summary(path, now):
    events = []
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            for line in f:
                try:
                    events.append(json.loads(line))
                except ValueError:
                    continue
    except OSError as e:
        print('ao_events: cannot read %s: %s' % (path, e.strerror or e), file=sys.stderr)
        return 1
    print(json.dumps(summarize(events, time.time() if now is None else now), ensure_ascii=False))
    return 0


def watch(path, from_start, label):
    def stop(signum, frame):
        sys.exit(0)

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    # Only a file that already exists is skipped to its end; one created later is read from its start.
    handle, inode, buffer, skip_existing = None, None, '', not from_start and os.path.exists(path)
    ready = False
    while True:
        try:
            if handle is not None and (os.stat(path).st_ino != inode):
                handle.close()   # the file was replaced: read the new one from its start
                handle, buffer = None, ''
            if handle is None:
                handle = open(path, encoding='utf-8', errors='replace')
                inode = os.fstat(handle.fileno()).st_ino
                if skip_existing:
                    handle.seek(0, os.SEEK_END)
                skip_existing = False
                if not ready:
                    ready = True
                    print('watching %s' % path, file=sys.stderr, flush=True)
            elif os.fstat(handle.fileno()).st_size < handle.tell():
                handle.seek(0)   # truncated
                buffer = ''
        except OSError:
            if handle is not None:
                handle.close()
            handle = None
            if not ready and not skip_existing:
                ready = True   # nothing to skip: a file created later is read from its start
                print('watching %s' % path, file=sys.stderr, flush=True)
            time.sleep(POLL_S)
            continue
        chunk = handle.read()
        if chunk:
            buffer += chunk
            *lines, buffer = buffer.split('\n')
            for line in lines:
                try:
                    message = format_event(json.loads(line), label)
                except ValueError:
                    continue
                if message:
                    print(message, flush=True)
        else:
            time.sleep(POLL_S)


def main():
    parser = argparse.ArgumentParser(description='AO events for /ao-run')
    commands = parser.add_subparsers(dest='command', required=True)
    rec = commands.add_parser('record')
    rec.add_argument('file')
    wat = commands.add_parser('watch')
    wat.add_argument('file')
    wat.add_argument('--from-start', action='store_true')
    wat.add_argument('--epic-label')
    hum = commands.add_parser('human')
    hum.add_argument('file')
    hum.add_argument('--action', required=True, choices=HUMAN_ACTIONS)
    hum.add_argument('--task')
    summ = commands.add_parser('summary')
    summ.add_argument('file')
    summ.add_argument('--now', type=float)
    args = parser.parse_args()
    if args.command == 'record':
        return record(args.file)
    if args.command == 'human':
        return human(args.file, args.action, args.task)
    if args.command == 'summary':
        return summary(args.file, args.now)
    return watch(args.file, args.from_start, args.epic_label)


if __name__ == '__main__':
    sys.exit(main())

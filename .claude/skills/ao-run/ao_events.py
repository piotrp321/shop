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
  host_on_battery percent, low (only when true), percent is 0..100 (notify.py:151, 162)
"""
import argparse
import json
import os
import signal
import sys
import time

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
    if kind == 'host_on_battery':
        percent = data.get('percent')
        level = '%d%%' % percent if isinstance(percent, (int, float)) else '?'
        return 'komputer na baterii: %s%s' % (level, ', niski poziom' if data.get('low') else '')
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


def record(path):
    raw = sys.stdin.read()
    try:
        event = json.loads(raw)
        if not isinstance(event, dict) or 'type' not in event:
            raise ValueError('no type')
    except ValueError:
        event = {'type': 'invalid_event', 'raw': raw[:RAW_LIMIT]}
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
    args = parser.parse_args()
    if args.command == 'record':
        return record(args.file)
    return watch(args.file, args.from_start, args.epic_label)


if __name__ == '__main__':
    sys.exit(main())

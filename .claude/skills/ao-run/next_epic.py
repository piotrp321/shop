#!/usr/bin/env python3
"""Picks the next prepared epic from the GitHub Projects backlog board for the /ao-run skill.
Python 3.9+, standard library only.

  next_epic.py [--repo DIR]

Runs `git fetch origin main`, then reads the board (`gh project item-list`), the open epic issues, the
`origin/epic/*` branches and `docs/features/` on origin/main (all with cwd=DIR, default: the current directory).
Prints one JSON line and exits 0:

  {"epic": {"dir", "id", "issue", "title"} | null,
   "in_progress": [{"dir", "id", "issue"}], "skipped": [{"issue", "id", "reason"}]}

When a `git` or `gh` command fails, or its output is not the JSON expected, the message goes to stderr, nothing to
stdout, and the exit code is 1.

Rules, over the board items from the top:
  - only open issues of piotrp321/shop labelled `epic` and not `needs-info` count (others are dropped silently);
  - ID = title prefix before the first ":", directory = ID in lower case; a title without a valid prefix is skipped;
  - `origin/epic/<dir>` exists: the epic is in progress;
  - otherwise it is prepared when origin/main has docs/features/<dir>/spec.md and plan.md with a manifest
    (the text "schema_version"); the first prepared epic is the candidate;
  - unprepared epics above the candidate are `skipped` with a reason; those below it are dropped;
  - any epic in progress (anywhere on the board) makes `epic` null.
"""
import argparse
import json
import re
import subprocess
import sys

OWNER = 'piotrp321'
REPOSITORY = 'piotrp321/shop'
ID_PATTERN = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._-]*$')


class CommandError(Exception):
    def __init__(self, args, detail):
        super().__init__('%s: %s' % (' '.join(args), detail))


def make_runner(cwd, timeout=None):
    """A command runner with cwd; `timeout` (seconds, None: none) kills a command that runs longer (CommandError)."""
    def run(args):
        try:
            result = subprocess.run(args, cwd=cwd, capture_output=True, text=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            raise CommandError(args, 'timed out after %g s' % timeout)
        except OSError as e:
            raise CommandError(args, e.strerror or str(e))
        if result.returncode != 0:
            raise CommandError(args, result.stderr.strip() or 'exit code %d' % result.returncode)
        return result.stdout
    return run


def _json(run, args):
    out = run(args)
    try:
        return json.loads(out)
    except ValueError:
        raise CommandError(args, 'output is not valid JSON')


def _epic_id(title):
    prefix, colon, _ = title.partition(':')
    prefix = prefix.strip()
    return prefix if colon and ID_PATTERN.match(prefix) else None


def board_epics(run, check_all=True):
    """Fetches origin/main and returns the open epic issues of the board in board order with their state.

    Each entry: {number, title, body, id, dir, state, reason}; state is `invalid` (title without ID prefix),
    `in_progress` (origin/epic/<dir> exists), `prepared`, `unprepared` (reason set) or `unchecked` (only when
    check_all is false: after the first prepared epic the plan files are not read).
    """
    run(['git', 'fetch', 'origin', 'main'])
    board_args = ['gh', 'project', 'item-list', '1', '--owner', OWNER, '--format', 'json', '--limit', '200']
    items = _json(run, board_args)
    open_args = ['gh', 'issue', 'list', '--repo', REPOSITORY, '--label', 'epic', '--state', 'open',
                 '--json', 'number,title,body', '--limit', '200']
    open_issues = _json(run, open_args)
    try:
        board = items['items']
        bodies = {int(i['number']): i.get('body') or '' for i in open_issues}
    except (KeyError, TypeError, ValueError, AttributeError):
        raise CommandError(board_args, 'unexpected JSON shape')

    prefix = 'refs/heads/epic/'
    branches = {line.split('\t')[-1][len(prefix):] for line in run(['git', 'ls-remote', '--heads', 'origin', 'epic/*']).splitlines()
                if line.split('\t')[-1].startswith(prefix)}
    files = {}
    for path in run(['git', 'ls-tree', '-r', '--name-only', 'origin/main', 'docs/features/']).splitlines():
        parts = path.strip().split('/')
        if len(parts) == 4 and parts[:2] == ['docs', 'features']:
            files.setdefault(parts[2], set()).add(parts[3])

    entries, found_prepared = [], False
    for entry in board:
        content = entry.get('content') or {}
        number = content.get('number')
        if (content.get('type') != 'Issue' or content.get('repository') != REPOSITORY
                or 'epic' not in (entry.get('labels') or []) or 'needs-info' in entry['labels']
                or number not in bodies):
            continue
        title = content.get('title') or ''
        epic_id = _epic_id(title)
        result = {'number': number, 'title': title, 'body': bodies[number], 'id': epic_id,
                  'dir': epic_id.lower() if epic_id else None, 'state': None, 'reason': None}
        entries.append(result)
        if epic_id is None:
            result.update(state='invalid', reason='tytuł bez prefiksu ID')
            continue
        directory = result['dir']
        if directory in branches:
            result['state'] = 'in_progress'
            continue
        if found_prepared and not check_all:
            result['state'] = 'unchecked'
            continue
        present = files.get(directory, set())
        has_spec, has_plan = 'spec.md' in present, 'plan.md' in present
        reason = None
        if not has_spec and not has_plan:
            reason = 'brak spec.md i plan.md z manifestem na origin/main'
        elif not has_spec:
            reason = 'brak spec.md'
        elif not has_plan:
            reason = 'brak plan.md'
        elif '"schema_version"' not in run(['git', 'show', 'origin/main:docs/features/%s/plan.md' % directory]):
            reason = 'plan bez manifestu'
        if reason:
            result.update(state='unprepared', reason=reason)
        else:
            result['state'] = 'prepared'
            found_prepared = True
    return entries


def select(run):
    epic, in_progress, skipped = None, [], []
    for e in board_epics(run, check_all=False):
        if e['state'] == 'invalid':
            skipped.append({'issue': e['number'], 'id': None, 'reason': e['reason']})
        elif e['state'] == 'in_progress':
            in_progress.append({'dir': e['dir'], 'id': e['id'], 'issue': e['number']})
        elif e['state'] == 'unprepared' and epic is None:
            skipped.append({'issue': e['number'], 'id': e['id'], 'reason': e['reason']})
        elif e['state'] == 'prepared' and epic is None:
            epic = {'dir': e['dir'], 'id': e['id'], 'issue': e['number'], 'title': e['title']}
    return {'epic': None if in_progress else epic, 'in_progress': in_progress, 'skipped': skipped}


def main(argv=None, run=None):
    parser = argparse.ArgumentParser(description='Next prepared epic from the backlog board')
    parser.add_argument('--repo', default='.')
    args = parser.parse_args(argv)
    try:
        result = select(run or make_runner(args.repo))
    except CommandError as e:
        print('next_epic: %s' % e, file=sys.stderr)
        return 1
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())

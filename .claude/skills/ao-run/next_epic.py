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
stdout, and the exit code is 1. The same holds for the origin gate: the open epic issues are read through
tools/agent-security/github_trust.py, and a permission lookup it cannot judge fails closed (exit 1).

Rules, over the board items from the top:
  - only open issues of piotrp321/shop labelled `epic` and not `needs-info` count (others are dropped silently);
  - an issue whose author has no write access (github_trust: read, triage, 404, bot, ghost) is never an epic of the
    queue: wherever it is on the board it is `skipped` as {issue, id: null, reason, author, url}, without its title
    or body (nor the title the board shows) and without an ID derived from them;
  - ID = title prefix before the first ":", directory = ID in lower case; a title without a valid prefix is skipped;
  - `origin/epic/<dir>` exists: the epic is in progress;
  - otherwise it is prepared when origin/main has docs/features/<dir>/spec.md and plan.md with a manifest
    (the text "schema_version"); the first prepared epic is the candidate;
  - unprepared epics above the candidate are `skipped` with a reason; those below it are dropped;
  - any epic in progress (anywhere on the board) makes `epic` null.
"""
import argparse
import json
import os
import re
import subprocess
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', 'tools', 'agent-security'))
import github_trust  # noqa: E402  (the origin gate: issues of authors without write access come without content)

OWNER = 'piotrp321'
REPOSITORY = 'piotrp321/shop'
ID_PATTERN = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._-]*$')
ISSUE_LIMIT = 200
UNTRUSTED_REASON = ('autor bez prawa zapisu do repozytorium: issue pominięte bez czytania tytułu i treści, '
                    'przepisz je we własne issue')


class CommandError(Exception):
    """A failed command; `stdout` keeps its output, so the gate reads the HTTP status of `gh api --include`."""

    def __init__(self, args, detail, stdout=''):
        super().__init__('%s: %s' % (' '.join(args), detail))
        self.stdout = stdout


def make_runner(cwd, timeout=None):
    """A command runner with cwd; `timeout` (seconds, None: none) kills a command that runs longer (CommandError).
    A failure keeps the command's stdout in CommandError.stdout (github_trust tells a 404 apart by it)."""
    def run(args):
        try:
            result = subprocess.run(args, cwd=cwd, capture_output=True, text=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            raise CommandError(args, 'timed out after %g s' % timeout)
        except OSError as e:
            raise CommandError(args, e.strerror or str(e))
        if result.returncode != 0:
            raise CommandError(args, result.stderr.strip() or 'exit code %d' % result.returncode, result.stdout)
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


def skipped_entry(entry):
    """The `skipped` record of a board entry: {issue, id, reason}, plus author and url for an untrusted issue."""
    record = {'issue': entry['number'], 'id': entry['id'], 'reason': entry['reason']}
    if entry['state'] == 'untrusted':
        record.update(author=entry['author'], url=entry['url'])
    return record


def board_epics(run, check_all=True, gate=None):
    """Fetches origin/main and returns the open epic issues of the board in board order with their state.

    Each entry: {number, title, body, id, dir, state, reason, author, url}; state is `untrusted` (the author has no
    write access: title, body, id and dir are None, so nothing of its text is ever used), `invalid` (title without ID
    prefix), `in_progress` (origin/epic/<dir> exists), `prepared`, `unprepared` (reason set) or `unchecked` (only
    when check_all is false: after the first prepared epic the plan files are not read).

    gate: the github_trust.Gate that reads the open epic issues (default: one on `run`, whose failures must keep the
    command's stdout, as make_runner does). A failure of the gate is a CommandError: fail closed, as for gh.
    """
    gate = gate or github_trust.Gate(run, repo=REPOSITORY)
    run(['git', 'fetch', 'origin', 'main'])
    board_args = ['gh', 'project', 'item-list', '1', '--owner', OWNER, '--format', 'json', '--limit', '200']
    items = _json(run, board_args)
    try:
        open_issues = gate.issues(state='open', labels=('epic',), limit=ISSUE_LIMIT, fields=('title', 'body'))
    except github_trust.GateError as e:
        raise CommandError(['github_trust', 'issues', '--label', 'epic'], str(e))
    try:
        board = items['items']
        issues = {i['number']: i for i in open_issues}
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
                or number not in issues):
            continue
        issue = issues[number]
        if not issue['trusted']:
            # Neither the issue's text nor the title the board shows for it: only number, author and link.
            entries.append({'number': number, 'title': None, 'body': None, 'id': None, 'dir': None,
                            'state': 'untrusted', 'reason': UNTRUSTED_REASON, 'author': issue['author'],
                            'url': issue['url']})
            continue
        title = content.get('title') or ''
        epic_id = _epic_id(title)
        result = {'number': number, 'title': title, 'body': issue.get('body') or '', 'id': epic_id,
                  'dir': epic_id.lower() if epic_id else None, 'state': None, 'reason': None,
                  'author': issue['author'], 'url': issue['url']}
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
        if e['state'] in ('invalid', 'untrusted'):
            skipped.append(skipped_entry(e))
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

#!/usr/bin/env python3
"""Backlog grooming for the /groom skill. Python 3.9+, standard library only.

  groom.py collect <run_dir> --worktree <dir> [--out FILE] [--state DIR]
  groom.py validate <run_dir> [--current FILE] [--state DIR]
  groom.py apply <run_dir> --approve <1,3,5|all|none> [--reject N=reason]... [--current FILE] [--state DIR]
  groom.py expire <run_dir>
  groom.py fail <run_dir> [--empty]

State directory (--state, default ~/.local/state/shop-groom): `rejected.jsonl`.

collect: reads the open issues of piotrp321/shop (`gh issue list --limit 500`; a result of exactly 500 is an error),
the open epics (board state from next_epic.py, specs from the worktree on disk), the taken epic IDs and the rejected
entries, and writes <run_dir>/input.json (or --out FILE, e.g. input-now.json for a fresh look at a pending list). Prints one JSON line {input, issues, epics, commit}. The commands run with
cwd = the worktree.

validate: checks <run_dir>/proposals.json against input.json (schema, kinds, limits, the rejected entries, the fix
epic rules), computes the scope collisions of the fix epic with the prepared and in-progress epics and writes them to
`fix_epic.collisions` and to its `## Zależności` section. Prints one JSON line {ok, errors, collisions}; every error
is also a line on stderr. With --current FILE (a fresh `collect --out`) assignments to open epics, open -FIXES epics,
taken IDs and labels are checked against that file; issue numbers and the earlier rejections stay with input.json.

apply: executes only the approved items of a validated list, in the order label, needs-info, duplicate, obsolete,
fix_epic. The fix epic is numbered after the last item (the word `epik` works too). Items neither approved nor
rejected are dropped (they may come back). Before each item its issues must be open with the `updatedAt` of
input.json, otherwise the item is skipped. A failing `gh` command ends that item only (status `error`, not recorded
as rejected). Writes `decided_at` and the result of each item to proposals.json and the rejected items to
rejected.jsonl. Prints one JSON line {decided_at, results: [{n, kind, issues, status, detail?, urls, manual?}],
errors}. Status: done, skipped, error, rejected.

expire: marks an undecided list as expired (`decided_at`, `result` = "wygasła").
fail: marks an undecided list as failed (`result` = "błąd walidacji", or "brak propozycji" with --empty); works also
when proposals.json is missing or is not valid JSON.

Exit codes: 0 success; 1 a `git`/`gh` command failed, returned unexpected JSON or the limit of 500 issues was
reached (collect); 2 usage and state errors (missing files, a list that is already decided, bad --approve/--reject);
3 the list does not validate.
"""
import argparse
import json
import os
import re
import shlex
import sys
from datetime import datetime

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'ao-run'))
import next_epic  # noqa: E402  (CommandError, the command runner and the board shared with next_epic.py)
import night_queue  # noqa: E402  (parse_scope, overlap, epic_scope, dependencies_section)

REPOSITORY = next_epic.REPOSITORY
OWNER = next_epic.OWNER
ISSUE_LIMIT = 500
ITEM_LIMIT = 10
FIX_MIN, FIX_MAX = 3, 8
STATE_DEFAULT = '~/.local/state/shop-groom'
REJECTED_FILE = 'rejected.jsonl'
INPUT_FILE = 'input.json'
PROPOSALS_FILE = 'proposals.json'
KIND_LABELS = ('bug', 'ux', 'tech-debt')
NOT_REVIEWED_LABELS = ('epic', 'enhancement')
LABEL_CHOICES = ('bug', 'ux', 'tech-debt', 'enhancement')
KINDS = ('duplicate', 'obsolete', 'needs-info', 'label')
EXECUTION_ORDER = ('label', 'needs-info', 'duplicate', 'obsolete')
CLOSE_REASONS = ('completed', 'not planned')
EPIC_SECTIONS = ('## Punkt „Zakresu MVP”', '## Zakres', '## Poza zakresem', '## Zależności', '## Uwagi do przygotowania')
FIXES_ID = re.compile(r'-FIXES(-\d+)?$')
WEIGHT = re.compile(r'\bwaga[ \t:*_-]*(Critical|Important|Minor)\b', re.IGNORECASE)
WEIGHT_RANK = {'Minor': 0, 'Important': 1, 'Critical': 2}
BLOCK = re.compile(r'\n?<!-- groom:collisions -->.*?<!-- /groom:collisions -->\n?', re.DOTALL)


class StateError(Exception):
    """Bad usage, a missing file or a list that is already decided: exit code 2."""


class ValidationFailed(Exception):
    """The list does not validate: exit code 3."""

    def __init__(self, errors):
        super().__init__('; '.join(errors))
        self.errors = errors


# ---------------------------------------------------------------- small helpers

def weight_of(body):
    """The most severe `waga <Critical|Important|Minor>` in the text, or None."""
    found = [m.group(1).capitalize() for m in WEIGHT.finditer(body or '')]
    return max(found, key=WEIGHT_RANK.get) if found else None


def _read_json(path, name):
    try:
        with open(path, encoding='utf-8') as f:
            return json.load(f)
    except FileNotFoundError:
        raise StateError('brak pliku %s' % name)
    except (OSError, UnicodeDecodeError) as e:
        raise StateError('nie można odczytać %s: %s' % (name, getattr(e, 'strerror', None) or e))
    except ValueError:
        raise StateError('%s: nieprawidłowy JSON' % name)


def _write_json(path, data):
    temp = path + '.tmp'
    try:
        with open(temp, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
            f.write('\n')
        os.replace(temp, path)
    except OSError as e:
        raise StateError('nie można zapisać %s: %s' % (os.path.basename(path), e.strerror or e))


def _is_int(value):
    return isinstance(value, int) and not isinstance(value, bool)


def _text(value):
    return isinstance(value, str) and value.strip() != ''


def _text_list(value):
    return isinstance(value, list) and bool(value) and all(_text(v) for v in value)


def read_rejected(state_dir):
    path = os.path.join(os.path.expanduser(state_dir), REJECTED_FILE)
    try:
        with open(path, encoding='utf-8') as f:
            lines = f.read().splitlines()
    except FileNotFoundError:
        return []
    except (OSError, UnicodeDecodeError) as e:
        raise StateError('nie można odczytać %s: %s' % (REJECTED_FILE, getattr(e, 'strerror', None) or e))
    entries = []
    for number, line in enumerate(lines, 1):
        if not line.strip():
            continue
        try:
            entry = json.loads(line)
        except ValueError:
            entry = None
        if not isinstance(entry, dict):
            raise StateError('%s: nieczytelna linia %d' % (REJECTED_FILE, number))
        entries.append(entry)
    return entries


# ---------------------------------------------------------------- collect

def _section_free_text(body):
    """The epic issue body without the sections `Poza zakresem` and `Zależności`."""
    kept, skipping = [], False
    for line in (body or '').splitlines():
        if line.startswith('## '):
            skipping = line.rstrip() in ('## Poza zakresem', '## Zależności')
        if not skipping:
            kept.append(line)
    return '\n'.join(kept)


def _refs(text):
    return [int(n) for n in re.findall(r'#(\d+)', text)]


def _spec_issue_refs(spec_text):
    refs = []
    for line in night_queue._header_lines(spec_text):
        if line.startswith('Issues:'):
            refs += _refs(line)
    return refs


def _read_spec(worktree, directory):
    try:
        with open(os.path.join(worktree, 'docs', 'features', directory, 'spec.md'), encoding='utf-8') as f:
            return f.read()
    except (OSError, UnicodeDecodeError):
        return None


def collect_input(run, worktree, rejected, now):
    commit = run(['git', 'rev-parse', 'HEAD']).strip()
    list_args = ['gh', 'issue', 'list', '--repo', REPOSITORY, '--state', 'open', '--limit', str(ISSUE_LIMIT),
                 '--json', 'number,title,body,labels,author,updatedAt']
    raw = next_epic._json(run, list_args)
    if not isinstance(raw, list):
        raise next_epic.CommandError(list_args, 'unexpected JSON shape')
    if len(raw) >= ISSUE_LIMIT:
        raise next_epic.CommandError(list_args, 'osiągnięto limit %d zgłoszeń, przegląd byłby niepełny' % ISSUE_LIMIT)

    board = {e['number']: e for e in next_epic.board_epics(run)}
    epic_args = ['gh', 'issue', 'list', '--repo', REPOSITORY, '--label', 'epic', '--state', 'open',
                 '--json', 'number,title,body', '--limit', '200']
    open_epics = next_epic._json(run, epic_args)
    try:
        epics, order = [], list(board) + [int(i['number']) for i in open_epics if int(i['number']) not in board]
        by_number = {int(i['number']): i for i in open_epics}
        for number in order:
            source = board.get(number) or by_number[number]
            title = source.get('title') or ''
            body = source.get('body') or ''
            epic_id = next_epic._epic_id(title)
            directory = epic_id.lower() if epic_id else None
            state = board[number]['state'] if number in board else ('unlisted' if epic_id else 'invalid')
            spec_text = _read_spec(worktree, directory) if directory else None
            scope = None
            if spec_text is not None:
                try:
                    scope = night_queue.parse_scope(spec_text)
                except night_queue.ScopeError:
                    scope = None
            refs = _refs(_section_free_text(body)) + (_spec_issue_refs(spec_text) if spec_text is not None else [])
            epics.append({'id': epic_id, 'number': number, 'title': title, 'dir': directory, 'state': state,
                          'issues': sorted({r for r in refs if r != number}), 'scope': scope})
    except (KeyError, TypeError, ValueError, AttributeError):
        raise next_epic.CommandError(epic_args, 'unexpected JSON shape')

    assigned = {}
    for e in epics:
        for number in e['issues']:
            assigned.setdefault(number, e['id'] or '#%d' % e['number'])
    issues = []
    try:
        for i in raw:
            labels = [l['name'] for l in i.get('labels') or []]
            if any(l in NOT_REVIEWED_LABELS for l in labels):
                continue    # every other issue has a kind label or none: both are reviewed
            body = i.get('body') or ''
            issues.append({'number': int(i['number']), 'title': i.get('title') or '', 'body': body, 'labels': labels,
                           'author': (i.get('author') or {}).get('login'), 'updatedAt': i['updatedAt'],
                           'weight': weight_of(body), 'assigned_epic': assigned.get(int(i['number']))})
    except (KeyError, TypeError, ValueError, AttributeError):
        raise next_epic.CommandError(list_args, 'unexpected JSON shape')

    prefix = 'refs/heads/epic/'
    branches = {line.split('\t')[-1][len(prefix):] for line in
                run(['git', 'ls-remote', '--heads', 'origin', 'epic/*']).splitlines()
                if line.split('\t')[-1].startswith(prefix)}
    try:
        directories = [d for d in os.listdir(os.path.join(worktree, 'docs', 'features'))
                       if os.path.isdir(os.path.join(worktree, 'docs', 'features', d))]
    except OSError:
        directories = []
    taken = sorted({d.lower() for d in directories} | {b.lower() for b in branches}
                   | {e['id'].lower() for e in epics if e['id']})
    return {'schema': 1, 'collected_at': now.isoformat(), 'commit': commit, 'issues': issues, 'epics': epics,
            'taken_ids': taken, 'rejected': rejected}


def cmd_collect(args, run, now):
    worktree = os.path.abspath(os.path.expanduser(args.worktree))
    if not os.path.isdir(worktree):
        raise StateError('brak katalogu worktree: %s' % args.worktree)
    rejected = read_rejected(args.state)
    data = collect_input(run or next_epic.make_runner(worktree), worktree, rejected, now)
    run_dir = os.path.expanduser(args.run_dir)
    path = os.path.expanduser(args.out) if args.out else os.path.join(run_dir, INPUT_FILE)
    try:
        os.makedirs(run_dir, exist_ok=True)
        os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
    except OSError as e:
        raise StateError('nie można utworzyć katalogu przebiegu: %s' % (e.strerror or e))
    _write_json(path, data)
    print(json.dumps({'input': path, 'issues': len(data['issues']), 'epics': len(data['epics']),
                      'commit': data['commit']}, ensure_ascii=False))
    return 0


# ---------------------------------------------------------------- validate

def _used(item):
    return list(item.get('issues') or []) if isinstance(item, dict) else []


def _expected_fix_id(scope_first, taken):
    base = scope_first.rstrip('/').split('/')[-1].upper() + '-FIXES'
    candidate, suffix = base, 1
    while candidate.lower() in taken:
        suffix += 1
        candidate = '%s-%d' % (base, suffix)
    return candidate


def _check_item(errors, item, position, original, by_number, assigned, rejected):
    def err(text):
        errors.append('pozycja %d: %s' % (position, text))

    if not isinstance(item, dict):
        err('oczekiwano obiektu')
        return
    kind = item.get('kind')
    if item.get('n') != position:
        err('n powinno być %d' % position)
    if kind not in KINDS:
        err('rodzaj %r jest nieznany (dozwolone: %s)' % (kind, ', '.join(KINDS)))
        return
    issues = item.get('issues')
    if not (isinstance(issues, list) and issues and all(_is_int(n) for n in issues)):
        err('issues: oczekiwano niepustej listy numerów')
        return
    if len(set(issues)) != len(issues):
        err('issues: numery się powtarzają')
    for number in issues:
        if number not in original:
            err('issue #%d nie jest w danych wejściowych' % number)
        if number in assigned:
            err('issue #%d jest przypisane do otwartego epiku %s' % (number, assigned[number]))
    if not _text(item.get('reason')):
        err('reason: oczekiwano niepustego tekstu')
    if kind != 'duplicate' and len(issues) != 1:
        err('%s dotyczy jednego issue, podano %d (jednego issue na pozycję)' % (kind, len(issues)))
    known = [by_number[n] for n in issues if n in by_number]
    if kind == 'duplicate':
        if len(issues) < 2:
            err('duplicate wymaga co najmniej dwóch issues')
        if not _is_int(item.get('keep')) or item['keep'] not in issues:
            err('keep: oczekiwano numeru jednego z issues')
    elif kind == 'obsolete':
        if item.get('close_reason') not in CLOSE_REASONS:
            err('close_reason: dozwolone %s' % ', '.join(CLOSE_REASONS))
        if not _text_list(item.get('evidence')):
            err('evidence: oczekiwano niepustej listy dowodów (plik:linia i zdanie)')
    elif kind == 'needs-info':
        if not _text_list(item.get('missing')):
            err('missing: oczekiwano niepustej listy braków')
    elif kind == 'label':
        if item.get('label') not in LABEL_CHOICES:
            err('label: dozwolone %s' % ', '.join(LABEL_CHOICES))
        for issue in known:
            present = [l for l in issue['labels'] if l in KIND_LABELS + NOT_REVIEWED_LABELS]
            if present:
                err('issue #%d ma już etykietę rodzaju (%s)' % (issue['number'], present[0]))
        extra = item.get('needs_info')
        if extra not in (None, False) and not _text_list(extra):
            err('needs_info: oczekiwano null albo niepustej listy braków')
    if kind in ('needs-info', 'label') and (kind == 'needs-info' or item.get('needs_info')):
        for issue in known:
            if 'needs-info' in issue['labels']:
                err('issue #%d ma już etykietę needs-info' % issue['number'])
    _check_previous_shape(err, item)
    _check_rejected(err, item, kind, issues, original, rejected)


def _valid_previous(value):
    return isinstance(value, dict) and _text(value.get('date')) and _text(value.get('reason'))


def _check_previous_shape(err, item):
    if 'previously_rejected' in item and not _valid_previous(item['previously_rejected']):
        err('previously_rejected: oczekiwano obiektu z niepustymi tekstami date i reason')


def _check_rejected(err, item, kind, issues, by_number, rejected):
    """by_number: the issues of the original input.json (the state the earlier rejection is compared with)."""
    matching = [e for e in rejected if e.get('kind') == kind and isinstance(e.get('issues'), list)
                and set(e['issues']) == set(issues)]
    if not matching:
        return
    entry = max(matching, key=lambda e: str(e.get('rejected_at') or ''))
    saved = entry.get('updated_at') or {}
    changed = any(n in by_number and saved.get(str(n)) != by_number[n]['updatedAt'] for n in issues)
    if not changed:
        err('odrzucona wcześniej (%s), zgłoszenia się nie zmieniły' % (entry.get('reason') or 'bez powodu'))
    elif not _valid_previous(item.get('previously_rejected')):
        err('zgłoszenia zmieniły się po odrzuceniu: brak poprawnego pola previously_rejected')


def _check_fix_epic(errors, fix, data, original, by_number, assigned, in_items, rejected):
    """data: the epics and taken IDs to check against; by_number: current issue state; original: input.json issues."""
    def err(text):
        errors.append('epik poprawek: %s' % text)

    if not isinstance(fix, dict):
        err('oczekiwano obiektu albo null')
        return
    open_fixes = [e['id'] for e in data.get('epics', []) if e.get('id') and FIXES_ID.search(e['id'].upper())]
    if open_fixes:
        err('istnieje otwarty epik poprawek %s (-FIXES), fix_epic powinien być null' % open_fixes[0])
    epic_id, title = fix.get('id'), fix.get('title')
    scope, issues, body = fix.get('scope'), fix.get('issues'), fix.get('body')
    scope_ok = (isinstance(scope, list) and 1 <= len(scope) <= 2 and all(isinstance(p, str) for p in scope))
    if scope_ok:
        try:
            for path in scope:
                night_queue._check_item(path)
        except night_queue.ScopeError as e:
            scope_ok = False
            err('scope: %s' % e)
    elif scope is not None:
        err('scope: oczekiwano 1-2 modułów (lista ścieżek)')
    if scope is None or not isinstance(scope, list):
        err('scope: oczekiwano listy modułów')
    if not isinstance(epic_id, str) or not next_epic.ID_PATTERN.match(epic_id):
        err('ID %r nie pasuje do wzorca ID' % (epic_id,))
    elif scope_ok:
        expected = _expected_fix_id(scope[0], set(data.get('taken_ids', [])))
        if epic_id != expected:
            err('ID powinno być %s (jest %s)' % (expected, epic_id))
    if not isinstance(title, str) or next_epic._epic_id(title) != epic_id or not title.partition(':')[2].strip():
        err('title: tytuł musi mieć postać "<ID>: <opis>" z tym samym ID')
    if not _text(body):
        err('body: oczekiwano treści issue epiku')
    else:
        lines = [l.rstrip() for l in body.splitlines()]
        for heading in EPIC_SECTIONS:
            if heading not in lines:
                err('body: brak sekcji %s' % heading)
    if not (isinstance(issues, list) and all(_is_int(n) for n in issues)):
        err('issues: oczekiwano listy numerów')
        return
    if len(set(issues)) != len(issues):
        err('issues: numery się powtarzają')
    if not FIX_MIN <= len(set(issues)) <= FIX_MAX:
        err('wymagane %d-%d issues, podano %d' % (FIX_MIN, FIX_MAX, len(set(issues))))
    _check_previous_shape(err, fix)
    _check_rejected(err, fix, 'fix_epic', issues, original, rejected)
    for number in dict.fromkeys(issues):
        issue = by_number.get(number)
        if issue is None or number not in original:
            err('issue #%d nie jest w danych wejściowych' % number)
            continue
        problem = None
        if not any(l in KIND_LABELS for l in issue['labels']):
            problem = 'nie ma etykiety bug, ux ani tech-debt'
        elif 'needs-info' in issue['labels']:
            problem = 'ma etykietę needs-info'
        elif number in assigned:
            problem = 'jest przypisane do otwartego epiku %s' % assigned[number]
        elif weight_of(issue.get('body')) in ('Critical', 'Important'):
            problem = 'ma wagę %s' % weight_of(issue.get('body'))
        elif number in in_items:
            problem = 'jest w pozycji %d listy' % in_items[number]
        if problem:
            err('issue #%d nie jest kandydatem: %s' % (number, problem))


def find_collisions(fix, data):
    collisions = []
    scope = fix['scope']
    for e in data.get('epics', []):
        if e.get('state') not in ('prepared', 'in_progress') or not e.get('scope') or not e.get('dir'):
            continue
        clash = night_queue.overlap(scope, night_queue.epic_scope(e['dir'], e['scope']))
        if clash:
            collisions.append({'epic': e['id'], 'issue': e['number'], 'state': e['state'], 'paths': list(clash)})
    return collisions


def body_with_collisions(body, collisions):
    body = BLOCK.sub('\n', body).rstrip('\n') + '\n'
    if not collisions:
        return body
    state_pl = {'prepared': 'przygotowany', 'in_progress': 'w toku'}
    lines = ['<!-- groom:collisions -->']
    for c in collisions:
        lines.append('- Zakres pokrywa się z %s (#%d, %s): `%s` i `%s`. Epik rusza po nim, nie tej samej nocy.'
                     % (c['epic'], c['issue'], state_pl.get(c['state'], c['state']), c['paths'][0], c['paths'][1]))
    lines.append('<!-- /groom:collisions -->')
    block = '\n'.join(lines)
    source = body.splitlines()
    start = next((i for i, l in enumerate(source) if l.rstrip() == '## Zależności'), None)
    if start is None:
        return body + '\n## Zależności\n\n' + block + '\n'
    end = next((i for i in range(start + 1, len(source)) if source[i].startswith('## ')), len(source))
    section = source[start:end]
    while section and not section[-1].strip():
        section.pop()
    tail = [''] + source[end:] if end < len(source) else []
    return '\n'.join(source[:start] + section + ['', block] + tail) + '\n'


def check(props, data, rejected, current=None):
    """Returns (errors, collisions) of a parsed proposals.json against input.json (`data`).

    With `current` (a fresh input file) the epics, taken IDs and labels are taken from it; issue numbers and the
    `updatedAt` of earlier rejections stay with `data`, the state the agent saw."""
    if not isinstance(props, dict):
        return ['proposals.json: oczekiwano obiektu JSON'], []
    errors = []
    if props.get('schema') != 1:
        errors.append('schema: oczekiwano 1')
    if props.get('commit') != data.get('commit'):
        errors.append('commit: powinien być %s (commit z input.json)' % data.get('commit'))
    for name in ('overflow', 'hypotheses'):
        if name in props and not (isinstance(props[name], list) and all(isinstance(v, str) for v in props[name])):
            errors.append('%s: oczekiwano listy tekstów' % name)
    view = current if current is not None else data
    original = {i['number']: i for i in data.get('issues', [])}
    by_number = dict(original)
    by_number.update({i['number']: i for i in view.get('issues', [])})
    assigned = {}
    for e in view.get('epics', []):
        for number in e.get('issues', []):
            assigned.setdefault(number, e.get('id') or '#%d' % e['number'])
    items = props.get('items')
    if not isinstance(items, list):
        errors.append('items: oczekiwano listy')
        items = []
    if len(items) > ITEM_LIMIT:
        errors.append('items: za dużo pozycji (%d, limit %d)' % (len(items), ITEM_LIMIT))
    for position, item in enumerate(items, 1):
        _check_item(errors, item, position, original, by_number, assigned, rejected)
    in_items = {}
    for position, item in enumerate(items, 1):
        for number in dict.fromkeys(_used(item)):
            if number in in_items:
                errors.append('issue #%d występuje w pozycjach %d i %d' % (number, in_items[number], position))
            else:
                in_items[number] = position
        if isinstance(item, dict) and item.get('kind') == 'duplicate' and _is_int(item.get('keep')) \
                and item['keep'] not in _used(item) and item['keep'] in in_items:
            errors.append('issue #%d (keep) występuje w pozycji %d' % (item['keep'], position))
    fix = props.get('fix_epic')
    collisions = []
    if 'fix_epic' in props and fix is not None:
        _check_fix_epic(errors, fix, view, original, by_number, assigned, in_items, rejected)
        if not errors and fix.get('scope'):
            collisions = find_collisions(fix, view)
    return errors, collisions


def _load_undecided(run_dir):
    props = _read_json(os.path.join(run_dir, PROPOSALS_FILE), PROPOSALS_FILE)
    if isinstance(props, dict) and props.get('decided_at'):
        raise StateError('lista jest już rozstrzygnięta (%s)' % (props.get('result') or props['decided_at']))
    return props


def _read_current(args):
    if not args.current:
        return None
    path = os.path.expanduser(args.current)
    return _read_json(path, path)


def cmd_validate(args):
    run_dir = os.path.expanduser(args.run_dir)
    data = _read_json(os.path.join(run_dir, INPUT_FILE), INPUT_FILE)
    current = _read_current(args)
    try:
        props = _load_undecided(run_dir)
    except StateError as e:
        if 'nieprawidłowy JSON' in str(e):
            return _report([PROPOSALS_FILE + ': nieprawidłowy JSON'], [])
        raise
    rejected = read_rejected(args.state)
    errors, collisions = check(props, data, rejected, current)
    if errors:
        return _report(errors, [])
    fix = props.get('fix_epic')
    if fix:
        fix['collisions'] = collisions
        fix['body'] = body_with_collisions(fix['body'], collisions)
        _write_json(os.path.join(run_dir, PROPOSALS_FILE), props)
    return _report([], collisions)


def _report(errors, collisions):
    for error in errors:
        print('groom: %s' % error, file=sys.stderr)
    print(json.dumps({'ok': not errors, 'errors': errors, 'collisions': collisions}, ensure_ascii=False))
    return 3 if errors else 0


# ---------------------------------------------------------------- apply

def issue_url(number):
    return 'https://github.com/%s/issues/%d' % (REPOSITORY, number)


def _gh(sub, number, *rest):
    return ['gh', 'issue', sub, str(number), '--repo', REPOSITORY] + list(rest)


def _missing_text(items, reason):
    return 'Brakuje informacji potrzebnych do realizacji zgłoszenia:\n\n%s\n\n%s' % (
        '\n'.join('- %s' % m for m in items), reason)


def _epic_number(props):
    return len(props['items']) + 1


def parse_approvals(approve, rejects, props):
    items = props['items']
    epic_n = _epic_number(props) if props.get('fix_epic') else None
    valid = set(range(1, len(items) + 1)) | ({epic_n} if epic_n else set())

    def number_of(token, what):
        token = token.strip()
        if token.lower() in ('epik', 'epic'):
            if epic_n is None:
                raise StateError('%s: lista nie ma epiku poprawek' % what)
            return epic_n
        if not token.isdigit() or int(token) not in valid:
            raise StateError('%s: nieznany numer %r' % (what, token))
        return int(token)

    approved = []
    text = approve.strip()
    if text.lower() == 'all':
        approved = sorted(valid)
    elif text.lower() != 'none':
        if not text:
            raise StateError('--approve: podaj numery, all albo none')
        for token in re.split(r'[,\s]+', text):
            if token:
                approved.append(number_of(token, '--approve'))
    if len(set(approved)) != len(approved):
        raise StateError('--approve: numery się powtarzają')
    rejections = {}
    for raw in rejects:
        key, sep, reason = raw.partition('=')
        if not sep:
            raise StateError('--reject: oczekiwano N=powód, podano %r' % raw)
        number = number_of(key, '--reject')
        if number in rejections:
            raise StateError('--reject: numer %d podany dwa razy' % number)
        rejections[number] = reason.strip()
    both = set(approved) & set(rejections)
    if both:
        raise StateError('numer %d jest jednocześnie zatwierdzony i odrzucony' % min(both))
    return approved, rejections


def _unchanged(run, numbers, by_number):
    for number in numbers:
        view = next_epic._json(run, ['gh', 'issue', 'view', str(number), '--repo', REPOSITORY,
                                     '--json', 'state,updatedAt'])
        if (str(view.get('state')).upper() != 'OPEN' or view.get('updatedAt') != by_number[number]['updatedAt']):
            return False
    return True


def _run_item(run, item, commit):
    kind = item['kind']
    number = item['issues'][0]
    if kind == 'label':
        labels = ['--add-label', item['label']]
        if item.get('needs_info'):
            run(_gh('comment', number, '--body', _missing_text(item['needs_info'], item['reason'])))
            labels += ['--add-label', 'needs-info']
        run(_gh('edit', number, *labels))
    elif kind == 'needs-info':
        run(_gh('comment', number, '--body', _missing_text(item['missing'], item['reason'])))
        run(_gh('edit', number, '--add-label', 'needs-info'))
    elif kind == 'duplicate':
        keep = item['keep']
        for other in (n for n in item['issues'] if n != keep):
            run(_gh('comment', other, '--body', 'Duplikat #%d: %s' % (keep, item['reason'])))
            run(_gh('close', other, '--duplicate-of', str(keep)))
    elif kind == 'obsolete':
        why = ('kryterium akceptacji jest już spełnione' if item['close_reason'] == 'completed'
               else 'zgłoszenie jest sprzeczne z obecną specyfikacją')
        body = 'Zamykam jako nieaktualne (%s).\n\n%s\n\nDowód (origin/main %s):\n%s' % (
            why, item['reason'], commit, '\n'.join('- %s' % e for e in item['evidence']))
        run(_gh('comment', number, '--body', body))
        run(_gh('close', number, '--reason', item['close_reason']))
    return {'urls': [issue_url(n) for n in item['issues']]}


def _run_fix_epic(run, fix):
    out = run(['gh', 'issue', 'create', '--repo', REPOSITORY, '--title', fix['title'], '--body', fix['body'],
               '--label', 'epic'])
    url = (out.strip().splitlines() or [''])[-1].strip()
    result = {'urls': [url]}
    add = ['gh', 'project', 'item-add', '1', '--owner', OWNER, '--url', url]
    manual_add = shlex.join(add)
    try:
        item_id = next_epic._json(run, add + ['--format', 'json'])['id']
    except (next_epic.CommandError, KeyError, TypeError) as e:
        return dict(result, detail='nie dodano do tablicy: %s' % e, manual=manual_add)
    try:
        project = next_epic._json(run, ['gh', 'project', 'view', '1', '--owner', OWNER, '--format', 'json'])['id']
        fields = next_epic._json(run, ['gh', 'project', 'field-list', '1', '--owner', OWNER, '--format', 'json'])['fields']
        field = next(f for f in fields if f.get('name') == 'Status')
        option = next(o for o in field['options'] if o.get('name') == 'Todo')['id']
    except (next_epic.CommandError, KeyError, TypeError, StopIteration) as e:
        return dict(result, detail='nie ustawiono statusu: %s; ustaw Status=Todo na tablicy ręcznie' % (
            e if isinstance(e, next_epic.CommandError) else 'brak pola Status albo opcji Todo'))
    edit = ['gh', 'project', 'item-edit', '--id', item_id, '--project-id', project, '--field-id', field['id'],
            '--single-select-option-id', option]
    try:
        run(edit)
    except next_epic.CommandError as e:
        return dict(result, detail='nie ustawiono statusu Todo: %s' % e, manual=shlex.join(edit))
    return result


def cmd_apply(args, run, now):
    run_dir = os.path.expanduser(args.run_dir)
    proposals_path = os.path.join(run_dir, PROPOSALS_FILE)
    props = _load_undecided(run_dir)
    data = _read_json(os.path.join(run_dir, INPUT_FILE), INPUT_FILE)
    current = _read_current(args)
    rejected_before = read_rejected(args.state)
    errors, _ = check(props, data, rejected_before, current)
    if errors:
        raise ValidationFailed(errors)
    approved, rejections = parse_approvals(args.approve, args.reject, props)
    run = run or next_epic.make_runner(run_dir)
    by_number = {i['number']: i for i in data['issues']}
    decided_at = now.isoformat()
    props['decided_at'] = decided_at
    _write_json(proposals_path, props)    # claims the list: a crash never leads to a second execution

    items = props['items']
    fix = props.get('fix_epic')
    entries = [(i + 1, it['kind'], it) for i, it in enumerate(items)]
    if fix:
        entries.append((_epic_number(props), 'fix_epic', fix))
    results, rejected_results, rejected_rows = [], [], []
    order = {k: i for i, k in enumerate(EXECUTION_ORDER + ('fix_epic',))}
    for n, kind, item in sorted(entries, key=lambda e: (order[e[1]], e[0])):
        issues = item['issues']
        if n in rejections:
            rejected_results.append({'n': n, 'kind': kind, 'issues': issues, 'status': 'rejected',
                                     'detail': rejections[n]})
            rejected_rows.append({'kind': kind, 'issues': issues,
                                  'updated_at': {str(k): by_number[k]['updatedAt'] for k in issues},
                                  'reason': rejections[n], 'rejected_at': decided_at})
            item['result'] = {'status': 'rejected', 'detail': rejections[n]}
            continue
        if n not in approved:
            item['result'] = {'status': 'no_decision'}
            continue
        row = {'n': n, 'kind': kind, 'issues': issues}
        try:
            if not _unchanged(run, issues, by_number):
                row.update(status='skipped', detail='zmienione od przeglądu')
            else:
                row.update(status='done', **(_run_fix_epic(run, item) if kind == 'fix_epic' else _run_item(run, item, props['commit'])))
        except next_epic.CommandError as e:
            row.update(status='error', detail=str(e))
        results.append(row)
        item['result'] = {k: v for k, v in row.items() if k not in ('n', 'kind', 'issues')}
    results.extend(sorted(rejected_results, key=lambda r: r['n']))
    _append_rejected(args.state, rejected_rows)
    _write_json(proposals_path, props)
    print(json.dumps({'decided_at': decided_at, 'results': results,
                      'errors': sum(1 for r in results if r['status'] == 'error')}, ensure_ascii=False))
    return 0


def _append_rejected(state_dir, rows):
    if not rows:
        return
    directory = os.path.expanduser(state_dir)
    try:
        os.makedirs(directory, exist_ok=True)
        with open(os.path.join(directory, REJECTED_FILE), 'a', encoding='utf-8') as f:
            for row in rows:
                f.write(json.dumps(row, ensure_ascii=False) + '\n')
    except OSError as e:
        raise StateError('nie można zapisać %s: %s' % (REJECTED_FILE, e.strerror or e))


# ---------------------------------------------------------------- expire and fail

def _close_list(run_dir, result, now, minimal_ok):
    run_dir = os.path.expanduser(run_dir)
    if not os.path.isdir(run_dir):
        raise StateError('brak katalogu przebiegu: %s' % run_dir)
    path = os.path.join(run_dir, PROPOSALS_FILE)
    try:
        props = _load_undecided(run_dir)
        if not isinstance(props, dict):
            raise StateError('%s: oczekiwano obiektu JSON' % PROPOSALS_FILE)
    except StateError as e:
        if not minimal_ok or 'rozstrzygnięta' in str(e):
            raise
        props = {'schema': 1}
    props['decided_at'] = now.isoformat()
    props['result'] = result
    _write_json(path, props)
    print(json.dumps({'decided_at': props['decided_at'], 'result': result}, ensure_ascii=False))
    return 0


# ---------------------------------------------------------------- CLI

def build_parser():
    parser = argparse.ArgumentParser(description='Backlog grooming: collect, validate, apply')
    sub = parser.add_subparsers(dest='command', required=True)
    collect = sub.add_parser('collect', help='write input.json')
    collect.add_argument('run_dir')
    collect.add_argument('--worktree', required=True)
    collect.add_argument('--out', help='output file (default <run_dir>/input.json)')
    validate = sub.add_parser('validate', help='check proposals.json')
    validate.add_argument('run_dir')
    apply = sub.add_parser('apply', help='execute the approved items')
    apply.add_argument('run_dir')
    apply.add_argument('--approve', required=True)
    apply.add_argument('--reject', action='append', default=[])
    for p in (collect, validate, apply):
        p.add_argument('--state', default=STATE_DEFAULT)
    for p in (validate, apply):
        p.add_argument('--current', help='fresh input file (collect --out): epics, taken IDs and labels are checked '
                                         'against it')
    sub.add_parser('expire', help='mark the list as expired').add_argument('run_dir')
    fail = sub.add_parser('fail', help='mark the list as failed or empty')
    fail.add_argument('run_dir')
    fail.add_argument('--empty', action='store_true')
    return parser


def main(argv=None, run=None, now=None):
    try:
        args = build_parser().parse_args(argv)
    except SystemExit as e:
        return 2 if e.code else 0
    now = now or datetime.now().astimezone()
    try:
        if args.command == 'collect':
            return cmd_collect(args, run, now)
        if args.command == 'validate':
            return cmd_validate(args)
        if args.command == 'apply':
            return cmd_apply(args, run, now)
        if args.command == 'expire':
            return _close_list(args.run_dir, 'wygasła', now, minimal_ok=False)
        return _close_list(args.run_dir, 'brak propozycji' if args.empty else 'błąd walidacji', now, minimal_ok=True)
    except StateError as e:
        print('groom: %s' % e, file=sys.stderr)
        return 2
    except ValidationFailed as e:
        for error in e.errors:
            print('groom: %s' % error, file=sys.stderr)
        return 3
    except next_epic.CommandError as e:
        print('groom: %s' % e, file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())

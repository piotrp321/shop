#!/usr/bin/env python3
"""Night queue of several epics for the /ao-run skill. Python 3.9+, standard library only.

  night_queue.py scope <spec.md>

scope: reads the `Zakres:` line from the header of an epic spec (the lines before the first `## ` heading) and prints
one JSON line `{"scope": [path, ...]}`, exit 0. A missing, doubled or unreadable line, or an unreadable file, prints
the reason to stderr and exits 2.

Exit codes: 0 success; 1 a `git`/`gh` command failed or returned unexpected JSON; 2 usage and state errors.
"""
import argparse
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import next_epic  # noqa: E402,F401  (CommandError and the command runner shared with next_epic.py)

SCOPE_LINE = re.compile(r'^Zakres:[ \t]*(.*)$')


class ScopeError(Exception):
    pass


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


def main(argv=None, run=None, now=None, stdin=None):
    parser = argparse.ArgumentParser(description='Night queue of several epics')
    parser.add_argument('--repo', default='.')
    parser.add_argument('--state', default='~/.local/state/ao-night')
    sub = parser.add_subparsers(dest='command', required=True)
    scope = sub.add_parser('scope', help='print the Zakres line of a spec')
    scope.add_argument('spec')
    try:
        args = parser.parse_args(argv)
    except SystemExit as e:
        return 2 if e.code else 0
    return {'scope': cmd_scope}[args.command](args)


if __name__ == '__main__':
    sys.exit(main())

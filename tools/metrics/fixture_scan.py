#!/usr/bin/env python3
"""Scan of the fixtures of tools/metrics (spec docs/features/metrics/spec.md, Model zagrożeń path 3). Python 3.9+,
standard library only.

  fixture_scan.py [DIR]      (default: the fixtures directory next to this script)

Reads every file under DIR and prints one JSON line {"findings": [{file, line, rule}]}; exit code 0 without findings,
1 with findings (the value itself is never printed), 2 when DIR cannot be read. Rules:

  absolute-path  /Users/, /private/ (not inside a longer path) or ~ as a path (`~/...`) or a whole value
  email          an e-mail address
  token          ghp_, gho_, github_pat_, sk-, `Bearer <x>`, `Basic <x>`
  base64         a run of 32 or more base64 characters that is not a SHA (pure hex) and not a path of plain words;
                 it needs a digit or mixed case (so a long lowercase word is not taken for a token)
  long-text      a text value (a JSON string, an SQL literal, a line of another file) longer than 120 characters
  unreadable     a file that is not UTF-8 or not valid JSON / JSON lines

.json and .jsonl files are read as JSON (every string and key is a value), .sql files by their quoted literals (a
literal that is JSON is read as JSON; the other rules also run over the raw text), other files line by line.
UUIDs and SHAs pass: dashes break a UUID into short runs and a SHA is hex.
"""
import json
import os
import re
import sys

MAX_TEXT = 120
PATH = re.compile(r'(?:^|[^A-Za-z0-9_.-])/(?:Users|private)/|(?:^|[\s"\'=:(,\[])~/')
EMAIL = re.compile(r'[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+')
TOKEN = re.compile(r'ghp_[A-Za-z0-9]|gho_[A-Za-z0-9]|github_pat_[A-Za-z0-9]|(?<![A-Za-z0-9])sk-[A-Za-z0-9]'
                   r'|\bBearer\s+\S|\bBasic\s+[A-Za-z0-9+/=]{8,}')
BASE64 = re.compile(r'[A-Za-z0-9+/]{32,}={0,2}')
HEX = re.compile(r'[0-9a-fA-F]+')
WORD_PATH = re.compile(r'[A-Za-z][A-Za-z]*(?:/[A-Za-z][A-Za-z]*)+')
SQL_LITERAL = re.compile(r"'((?:[^']|'')*)'")


def _looks_like_base64(candidate):
    run = candidate.rstrip('=')
    if HEX.fullmatch(run) or WORD_PATH.fullmatch(run):
        return False
    return any(c.isdigit() for c in run) or (any(c.isupper() for c in run) and any(c.islower() for c in run))


def check_value(value, long_text=True):
    """The rules a text value breaks."""
    found = []
    if PATH.search(value) or value.strip() == '~':
        found.append('absolute-path')
    if EMAIL.search(value):
        found.append('email')
    if TOKEN.search(value):
        found.append('token')
    if any(_looks_like_base64(m.group(0)) for m in BASE64.finditer(value)):
        found.append('base64')
    if long_text and len(value) > MAX_TEXT:
        found.append('long-text')
    return found


def _strings(node):
    """Every string and every key of a parsed JSON value."""
    if isinstance(node, str):
        yield node
    elif isinstance(node, list):
        for item in node:
            yield from _strings(item)
    elif isinstance(node, dict):
        for key, item in node.items():
            yield key
            yield from _strings(item)


def _json_findings(text, line):
    return [(line, rule) for value in _strings(json.loads(text)) for rule in check_value(value)]


def _sql_findings(text):
    found = []
    for i, line in enumerate(text.splitlines(), 1):
        found.extend((i, rule) for rule in check_value(line, long_text=False))
    for match in SQL_LITERAL.finditer(text):
        line = text.count('\n', 0, match.start()) + 1
        literal = match.group(1).replace("''", "'")
        if literal[:1] in '{[':
            try:
                found.extend(_json_findings(literal, line))
                continue
            except ValueError:
                pass
        found.extend((line, rule) for rule in check_value(literal) if rule == 'long-text')
    return found


def scan_file(path, name):
    try:
        with open(path, encoding='utf-8') as f:
            text = f.read()
    except (OSError, UnicodeDecodeError):
        return [{'file': name, 'line': 1, 'rule': 'unreadable'}]
    found = []
    try:
        if name.endswith('.json'):
            found = _json_findings(text, 1)
        elif name.endswith('.jsonl'):
            for i, line in enumerate(text.splitlines(), 1):
                if line.strip():
                    found.extend(_json_findings(line, i))
        elif name.endswith('.sql'):
            found = _sql_findings(text)
        else:
            for i, line in enumerate(text.splitlines(), 1):
                found.extend((i, rule) for rule in check_value(line))
    except ValueError:
        found = [(1, 'unreadable')]
    seen = []
    for line, rule in found:
        item = {'file': name, 'line': line, 'rule': rule}
        if item not in seen:
            seen.append(item)
    return seen


def scanned_files(root):
    """The files under `root` as paths relative to it, sorted. OSError when `root` is not a directory."""
    if not os.path.isdir(root):
        raise FileNotFoundError('brak katalogu %s' % root)
    names = []
    for directory, _, files in os.walk(root):
        names.extend(os.path.relpath(os.path.join(directory, f), root) for f in files)
    return sorted(names)


def scan_directory(root):
    findings = []
    for name in scanned_files(root):
        findings.extend(scan_file(os.path.join(root, name), name))
    return findings


def main(argv=None):
    argv = sys.argv[1:] if argv is None else argv
    root = argv[0] if argv else os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures')
    try:
        findings = scan_directory(root)
    except OSError as e:
        print('fixture_scan: %s' % (e.strerror or e), file=sys.stderr)
        return 2
    print(json.dumps({'findings': findings}))
    return 1 if findings else 0


if __name__ == '__main__':
    sys.exit(main())

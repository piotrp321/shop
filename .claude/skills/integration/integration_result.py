#!/usr/bin/env python3
"""Result of the /integration skill (see SKILL.md, "Kontrakt wyniku"). Python 3.9+, standard library only.

  validate FILE [--head SHA] [--epic ID] [--mode FULL|DELTA]
      Reads the last ```json block of FILE (the final message of the skill; a file holding bare JSON works too),
      checks it against the contract and prints it as JSON. With --head, --epic and --mode the values must equal
      those of the prompt. The block must be the last thing in the message.

Exit code 2: unreadable input, no or broken JSON block, or an invalid result (every problem on stderr).
"""
import argparse
import json
import re
import sys

SCHEMA_VERSION = 1
STATUSES = ('PASSED', 'FINDINGS', 'BLOCKED')
MODES = ('FULL', 'DELTA')
SEVERITIES = ('Critical', 'Important', 'Minor')
CHECK_STATUSES = ('passed', 'failed', 'skipped')
BLOCKER_TYPES = ('business', 'architecture', 'cost', 'technical')
# Quality gates every round runs; PASSED needs all of them green.
REQUIRED_CHECKS = ('make check', 'make test', 'make e2e')
KEYS = ('schema_version', 'epic_id', 'status', 'mode', 'head_sha', 'findings', 'checks', 'blockers', 'report_file')
FINDING_KEYS = ('id', 'severity', 'title', 'detail', 'evidence', 'files', 'task_id')
BLOCKER_KEYS = ('id', 'type', 'question', 'options', 'recommendation', 'evidence')
SHA = re.compile(r'^[0-9a-f]{40}$')
OPENING = re.compile(r'^```json[ \t]*$')
FENCE = re.compile(r'^```[ \t]*$')


def last_block(text):
    """The text of the last ```json block, which must be followed by nothing but blank lines."""
    blocks, current, end = [], None, 0
    lines = text.splitlines()
    for number, line in enumerate(lines):
        if current is None:
            if OPENING.match(line):
                current = []
        elif FENCE.match(line):
            blocks.append('\n'.join(current))
            current, end = None, number
        else:
            current.append(line)
    if current is not None:
        raise ValueError('the ```json block is not closed')
    if not blocks:
        raise ValueError('no ```json block found')
    if any(line.strip() for line in lines[end + 1:]):
        raise ValueError('the ```json block must be the last thing in the message')
    return blocks[-1]


def load(text):
    stripped = text.strip()
    if stripped.startswith('{'):
        return json.loads(stripped)
    return json.loads(last_block(text))


def text_field(problems, where, obj, key):
    value = obj.get(key)
    if not isinstance(value, str) or not value.strip():
        problems.append('%s.%s must be a non-empty string' % (where, key))


def unique_ids(problems, where, items, key):
    seen = set()
    for item in items:
        value = item.get(key)
        if not isinstance(value, str):
            continue
        if value in seen:
            problems.append('%s: duplicate %s %r' % (where, key, value))
        seen.add(value)


def exact_keys(problems, where, obj, keys):
    for key in keys:
        if key not in obj:
            problems.append('%s.%s is missing' % (where, key))
    for key in obj:
        if key not in keys:
            problems.append('%s.%s is not part of the contract' % (where, key))


def check_findings(problems, findings):
    for i, f in enumerate(findings):
        where = 'findings[%d]' % i
        if not isinstance(f, dict):
            problems.append('%s must be an object' % where)
            continue
        exact_keys(problems, where, f, FINDING_KEYS)
        for key in ('id', 'title', 'detail', 'evidence'):
            text_field(problems, where, f, key)
        if f.get('severity') in ('Critical', 'Important') and f.get('files') == []:
            problems.append('%s.files must name the files to fix for a %s finding' % (where, f['severity']))
        if f.get('severity') not in SEVERITIES:
            problems.append('%s.severity must be one of %s' % (where, ', '.join(SEVERITIES)))
        files = f.get('files')
        if not isinstance(files, list) or not all(isinstance(x, str) and x for x in files):
            problems.append('%s.files must be a list of non-empty strings' % where)
        task_id = f.get('task_id')
        if task_id is not None and not (isinstance(task_id, str) and task_id.strip()):
            problems.append('%s.task_id must be a non-empty string or null' % where)
    dicts = [f for f in findings if isinstance(f, dict)]
    unique_ids(problems, 'findings', dicts, 'id')
    seen = set()
    for f in dicts:
        files = f.get('files')
        if not (isinstance(f.get('title'), str) and isinstance(files, list) and all(isinstance(x, str) for x in files)):
            continue
        # The orchestrator's fingerprint: severity, normalized title and sorted files.
        print_ = (f.get('severity'), ' '.join(f['title'].lower().split()), tuple(sorted(files)))
        if print_ in seen:
            problems.append('findings: two findings share the fingerprint of %r' % f.get('id'))
        seen.add(print_)


def check_checks(problems, checks):
    for i, c in enumerate(checks):
        where = 'checks[%d]' % i
        if not isinstance(c, dict):
            problems.append('%s must be an object' % where)
            continue
        exact_keys(problems, where, c, ('name', 'status'))
        text_field(problems, where, c, 'name')
        if c.get('status') not in CHECK_STATUSES:
            problems.append('%s.status must be one of %s' % (where, ', '.join(CHECK_STATUSES)))
    unique_ids(problems, 'checks', [c for c in checks if isinstance(c, dict)], 'name')


def check_blockers(problems, blockers):
    for i, b in enumerate(blockers):
        where = 'blockers[%d]' % i
        if not isinstance(b, dict):
            problems.append('%s must be an object' % where)
            continue
        exact_keys(problems, where, b, BLOCKER_KEYS)
        for key in ('id', 'question', 'recommendation', 'evidence'):
            text_field(problems, where, b, key)
        if b.get('type') not in BLOCKER_TYPES:
            problems.append('%s.type must be one of %s' % (where, ', '.join(BLOCKER_TYPES)))
        options = b.get('options')
        if not isinstance(options, list) or not options:
            problems.append('%s.options must be a non-empty list' % where)
            continue
        for j, o in enumerate(options):
            if not isinstance(o, dict):
                problems.append('%s.options[%d] must be an object' % (where, j))
                continue
            exact_keys(problems, '%s.options[%d]' % (where, j), o, ('option', 'consequences'))
            for key in ('option', 'consequences'):
                text_field(problems, '%s.options[%d]' % (where, j), o, key)
    unique_ids(problems, 'blockers', [b for b in blockers if isinstance(b, dict)], 'id')


def check_status(problems, value):
    """PASSED, FINDINGS and BLOCKED are mutually exclusive outcomes of the lists."""
    findings = [f for f in value['findings'] if isinstance(f, dict)]
    checks = {c.get('name'): c.get('status') for c in value['checks'] if isinstance(c, dict) and isinstance(c.get('name'), str)}
    blocking = [f for f in findings if f.get('severity') in ('Critical', 'Important')]
    status = value['status']
    if status == 'PASSED':
        if blocking:
            problems.append('PASSED cannot carry Critical or Important findings')
        if value['blockers']:
            problems.append('PASSED cannot carry blockers')
        for name in REQUIRED_CHECKS:
            if checks.get(name) != 'passed':
                problems.append('PASSED needs %r passed, got %s' % (name, checks.get(name, 'no entry')))
        for name, state in checks.items():
            if state == 'failed':
                problems.append('PASSED cannot carry the failed check %r' % name)
    elif status == 'FINDINGS':
        if not blocking:
            problems.append('FINDINGS needs at least one Critical or Important finding (a failed gate is one too)')
        if value['blockers']:
            problems.append('FINDINGS cannot carry blockers; a blocker makes the result BLOCKED')
    elif status == 'BLOCKED':
        if not value['blockers']:
            problems.append('BLOCKED needs at least one blocker')


def validate(value, head=None, epic=None, mode=None):
    problems = []
    if not isinstance(value, dict):
        return ['the result must be a JSON object']
    exact_keys(problems, 'result', value, KEYS)
    if type(value.get('schema_version')) is not int or value['schema_version'] != SCHEMA_VERSION:
        problems.append('schema_version must be %d' % SCHEMA_VERSION)
    text_field(problems, 'result', value, 'epic_id')
    text_field(problems, 'result', value, 'report_file')
    if value.get('status') not in STATUSES:
        problems.append('status must be one of %s' % ', '.join(STATUSES))
    if value.get('mode') not in MODES:
        problems.append('mode must be one of %s' % ', '.join(MODES))
    if not isinstance(value.get('head_sha'), str) or not SHA.fullmatch(value['head_sha']):
        problems.append('head_sha must be 40 lowercase hex characters')
    lists = True
    for key, checker in (('findings', check_findings), ('checks', check_checks), ('blockers', check_blockers)):
        if isinstance(value.get(key), list):
            checker(problems, value[key])
        else:
            problems.append('%s must be a list' % key)
            lists = False
    for key, expected in (('head_sha', head), ('epic_id', epic), ('mode', mode)):
        if expected is not None and value.get(key) != expected:
            problems.append('%s is %r, the prompt says %r' % (key, value.get(key), expected))
    if lists and value.get('status') in STATUSES:
        check_status(problems, value)
    return problems


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='command', required=True)
    p = sub.add_parser('validate')
    p.add_argument('file')
    p.add_argument('--head')
    p.add_argument('--epic')
    p.add_argument('--mode', choices=MODES)
    args = parser.parse_args(argv)
    try:
        with open(args.file, encoding='utf-8') as f:
            value = load(f.read())
    except (OSError, ValueError) as e:
        print('integration_result: %s' % e, file=sys.stderr)
        return 2
    problems = validate(value, args.head, args.epic, args.mode)
    if problems:
        for problem in problems:
            print('integration_result: %s' % problem, file=sys.stderr)
        return 2
    print(json.dumps(value, indent=2, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))

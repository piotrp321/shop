#!/usr/bin/env python3
"""Task manifest of an epic plan (see SKILL.md, "Manifest"). Python 3.9+, standard library only.

  validate PLAN
      Reads the single ```json block of PLAN (docs/features/<epic>/plan.md), checks it and prints the manifest as
      JSON with "waves" added: task ids grouped by dependency depth, in plan order. Wave 1 has no dependencies;
      a task of wave N depends only on tasks of earlier waves. The spec path is relative to the root of the git
      repository that contains PLAN and must point to a file in the plan's directory.

Exit code 2: unreadable plan, missing or broken block, or an invalid manifest (every problem on stderr).
"""
import argparse
import json
import os
import posixpath
import re
import subprocess
import sys

SCHEMA_VERSION = 1
# Same rule as the orchestrator's task ids: the manifest's ids become AO task ids and branch names.
TASK_ID = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._-]*$')
RISKS = ('FAST', 'STANDARD', 'CRITICAL')
MANIFEST_KEYS = ('schema_version', 'epic', 'spec', 'tasks')
TASK_KEYS = ('id', 'title', 'scope', 'acceptance_criteria', 'risk', 'depends_on')
OPENING = re.compile(r'^```json[ \t]*$')
FENCE = re.compile(r'^```[ \t]*$')


class PlanError(Exception):
    pass


def extract_block(text):
    """The text of the only ```json block; a block is closed by a line holding only ```."""
    blocks, current = [], None
    for line in text.splitlines():
        if current is None:
            if OPENING.match(line):
                current = []
        elif FENCE.match(line):
            blocks.append('\n'.join(current))
            current = None
        else:
            current.append(line)
    if current is not None:
        raise PlanError('the ```json block is not closed')
    if len(blocks) != 1:
        raise PlanError('the plan must contain exactly one ```json block, found %d' % len(blocks))
    return blocks[0]


def check(manifest, root, plan_dir):
    """Problems of the manifest, empty when it is valid."""
    if not isinstance(manifest, dict):
        return ['the manifest must be a JSON object']
    problems = _keys('manifest', manifest, MANIFEST_KEYS)
    version = manifest.get('schema_version')
    if 'schema_version' in manifest and (type(version) is not int or version != SCHEMA_VERSION):
        problems.append('schema_version must be %d' % SCHEMA_VERSION)
    epic = manifest.get('epic')
    if 'epic' in manifest and not (isinstance(epic, str) and TASK_ID.fullmatch(epic)):
        problems.append('epic must match %s' % TASK_ID.pattern)
        epic = None
    if 'spec' in manifest:
        problems.extend(_spec_problems(manifest['spec'], root, plan_dir))
    tasks = manifest.get('tasks')
    if 'tasks' not in manifest:
        return problems
    if not isinstance(tasks, list) or not tasks:
        return problems + ['tasks must be a non-empty list']
    ids = []
    for i, item in enumerate(tasks):
        if not isinstance(item, dict):
            problems.append('tasks[%d] must be a JSON object' % i)
            continue
        problems.extend(_task_problems(i, item, epic, ids))
    if not problems:
        problems.extend(_dependency_problems(tasks))
    return problems


def waves(tasks):
    """Task ids grouped by dependency depth, each group in plan order. Expects a valid, acyclic manifest."""
    depth = {}

    def level(task_id):
        if task_id not in depth:
            depends_on = by_id[task_id]['depends_on']
            depth[task_id] = 1 + max((level(d) for d in depends_on), default=0)
        return depth[task_id]

    by_id = {t['id']: t for t in tasks}
    result = []
    for t in tasks:
        n = level(t['id'])
        while len(result) < n:
            result.append([])
    for t in tasks:
        result[depth[t['id']] - 1].append(t['id'])
    return result


def _keys(where, value, expected):
    problems = []
    unknown = sorted(set(value) - set(expected))
    missing = [k for k in expected if k not in value]
    if unknown:
        problems.append('%s: unknown keys: %s' % (where, ', '.join(unknown)))
    if missing:
        problems.append('%s: missing keys: %s' % (where, ', '.join(missing)))
    return problems


def _spec_problems(spec, root, plan_dir):
    if not isinstance(spec, str) or not spec or spec.startswith('/') or '\\' in spec:
        return ['spec must be a relative path inside the repository']
    normalized = posixpath.normpath(spec)
    if normalized == '..' or normalized.startswith('../'):
        return ['spec must be a relative path inside the repository']
    path = os.path.realpath(os.path.join(root, normalized))
    if os.path.commonpath([path, os.path.realpath(root)]) != os.path.realpath(root):
        return ['spec must be a relative path inside the repository']
    if not os.path.isfile(path):
        return ['spec %s not found in %s' % (spec, root)]
    if posixpath.dirname(normalized) != plan_dir:
        return ['spec must be in %s, the directory of the plan' % plan_dir]
    return []


def _task_problems(i, item, epic, ids):
    task_id = item.get('id')
    where = 'tasks[%d] (%s)' % (i, task_id) if isinstance(task_id, str) else 'tasks[%d]' % i
    problems = _keys(where, item, TASK_KEYS)
    if 'id' in item:
        if not (isinstance(task_id, str) and TASK_ID.fullmatch(task_id)):
            problems.append('%s: id must match %s' % (where, TASK_ID.pattern))
        elif epic is not None and not (task_id.startswith(epic + '-') and len(task_id) > len(epic) + 1):
            problems.append('%s: id must start with %s-' % (where, epic))
        elif task_id in ids:
            problems.append('duplicate task id %s' % task_id)
        else:
            ids.append(task_id)
    for key in ('title', 'scope'):
        if key in item and not (isinstance(item[key], str) and item[key].strip()):
            problems.append('%s: %s must be a non-empty string' % (where, key))
    criteria = item.get('acceptance_criteria')
    if 'acceptance_criteria' in item and not (
            isinstance(criteria, list) and criteria
            and all(isinstance(c, str) and c.strip() for c in criteria)):
        problems.append('%s: acceptance_criteria must be a non-empty list of non-empty strings' % where)
    if 'risk' in item and item['risk'] not in RISKS:
        problems.append('%s: risk must be one of %s' % (where, ', '.join(RISKS)))
    depends_on = item.get('depends_on')
    if 'depends_on' in item and not (isinstance(depends_on, list) and all(isinstance(d, str) for d in depends_on)):
        problems.append('%s: depends_on must be a list of task ids' % where)
    return problems


def _dependency_problems(tasks):
    problems = []
    known = {t['id'] for t in tasks}
    for t in tasks:
        seen = set()
        for d in t['depends_on']:
            if d == t['id']:
                problems.append('%s depends on itself' % d)
            elif d not in known:
                problems.append('%s depends on unknown task %s' % (t['id'], d))
            elif d in seen:
                problems.append('%s lists %s twice in depends_on' % (t['id'], d))
            seen.add(d)
    if problems:
        return problems
    # Kahn's algorithm: whatever cannot be ordered is on a cycle or depends on one.
    remaining = {t['id']: set(t['depends_on']) for t in tasks}
    while True:
        ready = [k for k, deps in remaining.items() if not deps]
        if not ready:
            break
        for k in ready:
            del remaining[k]
        for deps in remaining.values():
            deps.difference_update(ready)
    if remaining:
        problems.append('dependency cycle: %s' % ', '.join(t['id'] for t in tasks if t['id'] in remaining))
    return problems


def _repo_root(path):
    try:
        r = subprocess.run(['git', '-C', os.path.dirname(path) or '.', 'rev-parse', '--show-toplevel'],
                           capture_output=True, text=True, timeout=60)
    except (OSError, subprocess.TimeoutExpired) as e:
        raise PlanError('cannot run git: %s' % e) from None
    if r.returncode != 0:
        raise PlanError('%s is not inside a git repository' % path)
    return r.stdout.strip()


def validate(path):
    try:
        with open(path, encoding='utf-8') as f:
            text = f.read()
    except (OSError, UnicodeDecodeError) as e:
        raise PlanError('cannot read %s: %s' % (path, e)) from None
    root = _repo_root(os.path.abspath(path))
    plan_dir = os.path.relpath(os.path.dirname(os.path.realpath(path)), os.path.realpath(root)).replace(os.sep, '/')
    try:
        manifest = json.loads(extract_block(text))
    except ValueError as e:
        raise PlanError('the ```json block is not valid JSON: %s' % e) from None
    problems = check(manifest, root, plan_dir)
    if problems:
        raise PlanError('\n'.join(problems))
    return dict(manifest, waves=waves(manifest['tasks']))


def main(argv=None):
    parser = argparse.ArgumentParser(description='Validates the task manifest of an epic plan.')
    sub = parser.add_subparsers(dest='command', required=True)
    sub.add_parser('validate', help='check PLAN and print its manifest with waves').add_argument('plan')
    args = parser.parse_args(argv)
    try:
        result = validate(args.plan)
    except PlanError as e:
        print('plan_manifest: %s' % e, file=sys.stderr)
        return 2
    print(json.dumps(result, indent=2, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())

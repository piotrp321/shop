#!/usr/bin/env python3
"""Task manifest of an epic plan (see SKILL.md, "Manifest"). Python 3.9+, standard library only.

  validate PLAN
      Reads the single ```json block of PLAN (docs/features/<epic>/plan.md), checks it and prints the manifest as
      JSON with "waves" added: task ids grouped by dependency depth, in plan order. Wave 1 has no dependencies;
      a task of wave N depends only on tasks of earlier waves. The spec path is relative to the root of the git
      repository that contains PLAN and must point to a file in the plan's directory.

  parallel PLAN
      Checks PLAN like validate, without its `parallel_with` lists, and prints a JSON object {task id: [task ids]} in
      plan order: for every task the maximal `parallel_with`, i.e. every other task it may run at once with. A task
      without `paths` gets []. Exit code 2 also when git cannot list the tracked CLAUDE.md files.
      With --blocked the output is instead {"parallel_with": {that object}, "blocked": [...]}, where "blocked" lists
      {"tasks": [A, B], "paths": [...]} for every pair of tasks of one wave (as in `waves`) that both have `paths`,
      but may not run at once because those paths are shared (what task_conflicts refuses), in wave order, then
      plan order; [] when there is none. Without the flag the output is unchanged.

Optional task keys (step 19b, docs/features/parallel-work/spec.md): `paths`, a non-empty list of paths relative to
the repository root that the task changes (a file, or a directory ending with /; no absolute path, no `.` or `..`
segment, no control character or Unicode separator other than the space, no glob character `*?[]{}`, no leading `~`,
a first segment that exists in the repository root, compared case-sensitively, other than .git; no duplicate), and
`parallel_with`, the ids of tasks that may run at once with it. A listed pair must be symmetric, both tasks must
have `paths`, neither may depend on the other (also through depends_on of another task) and their `paths` must not
share a path that task_conflicts refuses (executable code: application and tool code, a CLAUDE.md or
a file, or a directory holding a CLAUDE.md tracked by git, `git ls-files`; other directories outside executable code
may overlap). When
git cannot list the tracked CLAUDE.md files, every listed pair is refused. Without `parallel_with` the task runs
alone.

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
OPTIONAL_TASK_KEYS = ('paths', 'parallel_with')
OPENING = re.compile(r'^```json[ \t]*$')
FENCE = re.compile(r'^```[ \t]*$')
# Glob metacharacters: a manifest path names a file or a directory, never a pattern.
GLOB = '*?[]{}'

# The file rule of parallel work lives here and nowhere else; the AO parallel gate imports it from this file.
GENERATED = ('apps/api/openapi.json', 'apps/api/openapi-admin.json', 'apps/store/src/api/schema.d.ts',
             'apps/admin/src/api/schema.d.ts')
# Application code files that epics only append entries to; REPAIR merges them like any other shared file.
REGISTRATION = ('apps/admin/src/layout/AppMenu.vue', 'apps/admin/src/router/index.ts')
# Directories of application code inside apps/<app>/.
CODE_DIRS = ('src', 'app')
# Directories of executable repository code outside the applications (step 19b only): tools, skills, AO hooks and
# scripts of CI and infrastructure. Compared by segments.
EXECUTABLE_DIRS = ('tools', '.claude/skills', '.ao', '.github/scripts', 'infra/scripts')


class PlanError(Exception):
    pass


class ClaudeMdUnknown(PlanError):
    """git could not list the tracked CLAUDE.md files: no pair of tasks may run at once."""


def is_app_code(path):
    """Whether a path of the common part holds application code: it lies in apps/<app>/src or apps/<app>/app (by
    segments), or contains such a directory (`apps`, `apps/<app>`). Generated and registration files, CLAUDE.md and
    README.md are not code."""
    if path in GENERATED or path in REGISTRATION or path.split('/')[-1] in ('CLAUDE.md', 'README.md'):
        return False
    segments = path.split('/')
    if segments[0] != 'apps':
        return False
    return len(segments) <= 2 or segments[2] in CODE_DIRS


def is_executable_code(path):
    """Whether a path of the common part holds executable code (step 19b): application code (is_app_code), or a path in
    one of EXECUTABLE_DIRS or containing one (`.claude`, `.github`, `infra`), compared by segments and without case.
    Generated and registration files, CLAUDE.md and README.md are not code. Step 19a does not use it."""
    if is_app_code(path):
        return True
    segments = path.casefold().split('/')
    if path in GENERATED or path in REGISTRATION or segments[-1] in ('claude.md', 'readme.md'):
        return False
    for directory in EXECUTABLE_DIRS:
        parts = directory.split('/')
        if segments[:len(parts)] == parts or parts[:len(segments)] == segments:
            return True
    return False


def is_allowed(path):
    """Step 5 of the gate: a shared path blocks the candidate only when it is application code."""
    return not is_app_code(path)


def is_claude_md(path):
    return path.split('/')[-1] == 'CLAUDE.md'


def _inside(inner, outer):
    return inner == outer or inner.startswith(outer + '/')


def common_paths(a, b):
    found = set()
    for x in a:
        for y in b:
            if _inside(x, y):
                found.add(x)
            elif _inside(y, x):
                found.add(y)
    return sorted(found)


def task_conflicts(a, b, claude_md=()):
    """Paths that keep two tasks of one wave from running at once (step 19b), sorted: the common part of their
    manifest `paths` (a file, or a directory with a trailing /) that is executable code (is_executable_code:
    application code and the tool directories), a CLAUDE.md or a file, and every file of `claude_md` (the tracked
    CLAUDE.md files of the repository) inside a common path. Two directories outside executable code may overlap (the
    exceptions of step 5) when they hold no tracked CLAUDE.md.
    A common path counts as a directory only when every path of both tasks that contains it is declared as a
    directory. Paths are compared without case (casefold, as the file system of the host does); a found path keeps
    the spelling it was declared or tracked with."""
    declared, spelling = [{}, {}], {}
    for d, paths in zip(declared, (a, b)):
        for p in paths:
            key = p.rstrip('/').casefold()
            spelling.setdefault(key, p.rstrip('/'))
            d[key] = d.get(key, True) and p.endswith('/')

    def directory(path):
        return all(is_dir for d in declared for p, is_dir in d.items() if _inside(path, p))

    common = common_paths(*declared)
    found = {spelling[path] for path in common
             if is_executable_code(path) or path.split('/')[-1] == 'claude.md' or not directory(path)}
    found.update(f for f in claude_md for path in common if _inside(f.casefold(), path))
    return sorted(found)


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


def check(manifest, root, plan_dir, claude_md=()):
    """Problems of the manifest, empty when it is valid. `claude_md` is the tuple of tracked CLAUDE.md files of the
    repository, or a ClaudeMdUnknown when git could not list them (then every parallel pair is refused)."""
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
    ids, top = [], _root_entries(root)
    for i, item in enumerate(tasks):
        if not isinstance(item, dict):
            problems.append('tasks[%d] must be a JSON object' % i)
            continue
        problems.extend(_task_problems(i, item, epic, ids, top))
    if not problems:
        problems.extend(_dependency_problems(tasks))
    if not problems:
        problems.extend(_parallel_problems(tasks, claude_md))
    return problems


def parallel_lists(tasks, claude_md):
    """The maximal `parallel_with` of every task: the other tasks it may run at once with, in plan order. Expects a
    valid, acyclic manifest and the tuple of tracked CLAUDE.md files."""
    ancestors = _ancestors(tasks)
    lists = {t['id']: [] for t in tasks}
    for i, a in enumerate(tasks):
        for b in tasks[i + 1:]:
            if not _pair_problem(a, b, ancestors, claude_md):
                lists[a['id']].append(b['id'])
                lists[b['id']].append(a['id'])
    return lists


def blocked_pairs(tasks, claude_md):
    """Pairs of tasks of one wave that both have `paths` and may not run at once because of shared paths, as
    {"tasks": [a, b], "paths": [...]}, in wave order, then plan order. Expects a valid, acyclic manifest and the
    tuple of tracked CLAUDE.md files. Tasks of one wave never depend on each other."""
    by_id = {t['id']: t for t in tasks}
    result = []
    for wave in waves(tasks):
        for i, first in enumerate(wave):
            for second in wave[i + 1:]:
                a, b = by_id[first], by_id[second]
                if 'paths' in a and 'paths' in b:
                    shared = task_conflicts(a['paths'], b['paths'], claude_md=claude_md)
                    if shared:
                        result.append({'tasks': [first, second], 'paths': list(shared)})
    return result


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


def _keys(where, value, expected, optional=()):
    problems = []
    unknown = sorted(set(value) - set(expected) - set(optional))
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


def _task_problems(i, item, epic, ids, top):
    task_id = item.get('id')
    where = 'tasks[%d] (%s)' % (i, task_id) if isinstance(task_id, str) else 'tasks[%d]' % i
    problems = _keys(where, item, TASK_KEYS, OPTIONAL_TASK_KEYS)
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
    if 'paths' in item:
        problems.extend(_paths_problems(where, item['paths'], top))
    parallel_with = item.get('parallel_with')
    if 'parallel_with' in item:
        if not (isinstance(parallel_with, list) and all(isinstance(p, str) for p in parallel_with)):
            problems.append('%s: parallel_with must be a list of task ids' % where)
        else:
            seen = set()
            for p in parallel_with:
                if p == task_id:
                    problems.append('%s lists itself in parallel_with' % p)
                elif p in seen:
                    problems.append('%s lists %s twice in parallel_with' % (task_id, p))
                seen.add(p)
    return problems


def _is_relative_path(path):
    if not isinstance(path, str) or not path or path != path.strip() or path.startswith('/') or '\\' in path:
        return False
    if not path.isprintable():
        # Control characters and Unicode separators other than the ASCII space (NUL, U+2028, NBSP).
        return False
    if path.startswith('~') or any(c in GLOB for c in path):
        # A path, not a pattern: the gate compares paths literally.
        return False
    segments = (path[:-1] if path.endswith('/') else path).split('/')
    return all(s not in ('', '.', '..') for s in segments)


def _root_entries(root):
    """Names in the repository root (case-sensitive, also on a case-insensitive file system), without .git."""
    try:
        return set(os.listdir(root)) - {'.git'}
    except OSError:
        return set()


def _paths_problems(where, paths, top):
    if not (isinstance(paths, list) and paths and all(_is_relative_path(p) for p in paths)):
        return ['%s: paths must be a non-empty list of relative paths (a file, or a directory ending with /)' % where]
    problems, seen = [], set()
    for p in paths:
        name = p.rstrip('/')
        first = name.split('/')[0]
        if first not in top:
            problems.append('%s: paths %s: %s does not exist in the repository root' % (where, p, first))
        if name in seen:
            problems.append('%s: paths lists %s twice' % (where, name))
        seen.add(name)
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


def _ancestors(tasks):
    """Every task id with the set of tasks it depends on, directly or through other tasks."""
    by_id = {t['id']: t for t in tasks}
    found = {}

    def of(task_id):
        if task_id not in found:
            found[task_id] = set()
            for d in by_id[task_id]['depends_on']:
                found[task_id] |= {d} | of(d)
        return found[task_id]

    for t in tasks:
        of(t['id'])
    return found


def _pair_problem(a, b, ancestors, claude_md):
    """Why tasks a and b may not run at once (a before b in the plan), None when they may."""
    if 'paths' not in a or 'paths' not in b:
        return '%s and %s are parallel without paths in both' % (a['id'], b['id'])
    for first, second in ((a, b), (b, a)):
        if first['id'] in ancestors[second['id']]:
            return '%s and %s cannot run in parallel: %s depends on %s' % (a['id'], b['id'], second['id'],
                                                                           first['id'])
    if isinstance(claude_md, ClaudeMdUnknown):
        return '%s and %s cannot run in parallel: %s' % (a['id'], b['id'], claude_md)
    shared = task_conflicts(a['paths'], b['paths'], claude_md=claude_md)
    if shared:
        return '%s and %s cannot run in parallel: shared paths %s' % (a['id'], b['id'], ', '.join(shared))
    return None


def _parallel_problems(tasks, claude_md):
    problems = []
    by_id = {t['id']: t for t in tasks}
    order = [t['id'] for t in tasks]
    ancestors = _ancestors(tasks)
    pairs = set()
    for t in tasks:
        for other in t.get('parallel_with', ()):
            if other not in by_id:
                problems.append('%s is parallel with unknown task %s' % (t['id'], other))
                continue
            if t['id'] not in by_id[other].get('parallel_with', ()):
                problems.append('%s is parallel with %s, but %s does not list %s' % (t['id'], other, other, t['id']))
            pairs.add(tuple(sorted((t['id'], other), key=order.index)))
    for first, second in sorted(pairs, key=lambda p: (order.index(p[0]), order.index(p[1]))):
        problem = _pair_problem(by_id[first], by_id[second], ancestors, claude_md)
        if problem:
            problems.append(problem)
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


def _tracked_claude_md(root):
    """The tracked CLAUDE.md files of the repository (paths relative to its root), or a ClaudeMdUnknown when git
    cannot list them."""
    try:
        # Every tracked file, filtered here: a pathspec would depend on the pathspec settings of git (e.g.
        # GIT_LITERAL_PATHSPECS lists none and exits 0).
        r = subprocess.run(['git', '-C', root, 'ls-files', '-z'], capture_output=True, text=True, timeout=60)
    except (OSError, UnicodeDecodeError, subprocess.TimeoutExpired) as e:
        error = str(e)
    else:
        if r.returncode == 0:
            return tuple(p for p in r.stdout.split('\0') if p and is_claude_md(p))
        error = r.stderr.strip() or 'exit code %d' % r.returncode
    return ClaudeMdUnknown('tracked CLAUDE.md files are unknown (git ls-files failed: %s)' % error)


def validate(path, ignore_parallel_with=False):
    """The checked manifest of the plan at `path` with its waves; with `ignore_parallel_with` the `parallel_with`
    lists are dropped before the check (the manifest is returned without them)."""
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
    if ignore_parallel_with and isinstance(manifest, dict) and isinstance(manifest.get('tasks'), list):
        manifest['tasks'] = [{k: v for k, v in t.items() if k != 'parallel_with'} if isinstance(t, dict) else t
                             for t in manifest['tasks']]
    problems = check(manifest, root, plan_dir, _tracked_claude_md(root))
    if problems:
        raise PlanError('\n'.join(problems))
    return dict(manifest, waves=waves(manifest['tasks']))


def parallel(path, with_blocked=False):
    """The maximal `parallel_with` of every task of the plan at `path`, its current lists ignored; with
    `with_blocked` {"parallel_with": the lists, "blocked": blocked_pairs}. PlanError when the plan is invalid or the tracked
    CLAUDE.md files are unknown."""
    manifest = validate(path, ignore_parallel_with=True)
    claude_md = _tracked_claude_md(_repo_root(os.path.abspath(path)))
    if isinstance(claude_md, ClaudeMdUnknown):
        raise claude_md
    lists = parallel_lists(manifest['tasks'], claude_md)
    if with_blocked:
        return {'parallel_with': lists, 'blocked': blocked_pairs(manifest['tasks'], claude_md)}
    return lists


def main(argv=None):
    parser = argparse.ArgumentParser(description='Validates the task manifest of an epic plan.')
    sub = parser.add_subparsers(dest='command', required=True)
    sub.add_parser('validate', help='check PLAN and print its manifest with waves').add_argument('plan')
    parallel_parser = sub.add_parser('parallel', help='print the maximal parallel_with of every task of PLAN')
    parallel_parser.add_argument('plan')
    parallel_parser.add_argument('--blocked', action='store_true',
                                 help='also list same-wave pairs blocked by shared paths')
    args = parser.parse_args(argv)
    try:
        if args.command == 'parallel':
            result = parallel(args.plan, args.blocked)
        else:
            result = validate(args.plan)
    except PlanError as e:
        print('plan_manifest: %s' % e, file=sys.stderr)
        return 2
    print(json.dumps(result, indent=2, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())

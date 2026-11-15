"""`evals.py lint`: everything that can be fixed without a model. Python 3.9+, standard library only.

- the format of the registry, the config, the cases and the history files;
- the scan of tools/evals/suites/** and tools/evals/history/** (and of the decoded payloads) for home paths, e-mail
  addresses and token patterns (the repository is public; threat model path 5);
- the injection payloads (path 6): `payload_b64` is decoded and its text must not appear in plain in any file of
  tools/evals; a payload may only ask for the canary actions of the registry, and destructive patterns are rejected in
  decoded payloads only (the other case data describes a process and may mention `rm -rf` or `gh pr merge`);
- the commits of the cases (reachable from main or epic/*; skipped with a warning in a shallow clone or outside git)
  and the task diff of a diff case, which must not touch the overlay paths.
"""
import base64
import binascii
import json
import os
import re

import evals_history as history
import evals_registry as registry
import evals_repo as repo
from evals_report import Report

KINDS = history.KINDS
ASSERTION_TYPES = ('verdict', 'finding', 'max_severity', 'validator', 'json', 'canary_absent', 'tree_unchanged')
OVERLAY_PREFIXES = ('.claude/agents/', '.claude/skills/')
MIN_PLAIN_LINE = 24   # a line of a payload shorter than this is not searched for on its own

EMAIL_RE = r'[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}'
SCAN_PATTERNS = (
    ('home path (/Users/)', re.compile(re.escape('/Users/'))),
    ('home path (/home/)', re.compile(re.escape('/home/'))),
    ('e-mail address', re.compile(EMAIL_RE)),
    ('GitHub token (ghp_)', re.compile(re.escape('ghp_'))),
    ('GitHub token (github_pat_)', re.compile(re.escape('github_pat_'))),
    ('Anthropic key (sk-ant-)', re.compile(re.escape('sk-ant-'))),
)
DESTRUCTIVE_PATTERNS = (
    ('rm -rf', re.compile(r'\brm\s+(?:-\S*[rRfF]\S*|--recursive|--force)')),
    # a forced push: a --force flag anywhere (--force-with-lease too), a short flag group with f (-f, -fu, -uf) and
    # a refspec with `+`
    ('push --force', re.compile(r'(?<!\S)--force\S*')),
    ('push -f', re.compile(r'\bpush\b[^\n]*\s-[A-Za-z]*f[A-Za-z]*(?=\s|$)')),
    ('push +refspec', re.compile(r'\bpush\b[^\n]*\s["\']?\+\S')),
    # the file system of the developer machine ignores the case of a name
    ('reading .env', re.compile(r'\.env\b', re.IGNORECASE)),
    ('reading ~/.ssh', re.compile(r'\.ssh\b', re.IGNORECASE)),
)
# a call of gh by name, by any path (./gh, /opt/homebrew/bin/gh) or in quotes ("gh")
GH_CALL_RE = re.compile(r'(?<![\w.-])gh["\']?\s+(\S+)(?:\s+(\S+))?')


# ----------------------------------------------------------------------------------------------------- the cases

def validate_case(data, dir_name, suite, canary_actions):
    """Problems of a parsed case.json. Fields of the runner that are not listed here are free."""
    if not isinstance(data, dict):
        return ['must be an object']
    problems = []
    if data.get('id') != dir_name or not registry.CASE_ID_RE.match(str(data.get('id'))):
        problems.append('id must be the name of the case directory (%s), lowercase letters, digits and `-`' % dir_name)
    if 'suite' in data and data['suite'] != suite:
        problems.append('suite must be %s' % suite)
    kind = data.get('kind')
    if kind not in KINDS:
        problems.append('kind must be one of %s' % ', '.join(KINDS))
    sha = history.COMMIT_RE
    has_commit = 'commit' in data
    has_diff = 'base_sha' in data or 'head_sha' in data
    if has_commit == has_diff:
        problems.append('a case has either commit or base_sha and head_sha (a case with a diff), not both or none')
    elif has_commit and not (isinstance(data['commit'], str) and sha.match(data['commit'])):
        problems.append('commit must be a 40-character SHA')
    elif has_diff:
        for key in ('base_sha', 'head_sha'):
            if key not in data:
                problems.append('%s is missing (a case with a diff needs base_sha and head_sha)' % key)
            elif not (isinstance(data[key], str) and sha.match(data[key])):
                problems.append('%s must be a 40-character SHA' % key)
    assertions = data.get('assertions')
    types = []
    if not isinstance(assertions, list) or not assertions \
            or not all(isinstance(a, dict) and 'type' in a for a in assertions):
        problems.append('assertions must be a non-empty list of objects with a type')
    else:
        for item in assertions:
            if item['type'] not in ASSERTION_TYPES:
                problems.append('assertion type %r is unknown (%s)' % (item['type'], ', '.join(ASSERTION_TYPES)))
            else:
                types.append(item['type'])
    payload = data.get('payload_b64')
    if kind == 'injection':
        if not isinstance(payload, str) or not payload:
            problems.append('an injection case needs payload_b64')
        for needed in ('canary_absent', 'tree_unchanged'):
            if needed not in types:
                problems.append('an injection case needs the assertion %s' % needed)
        actions = data.get('payload_actions')
        if not isinstance(actions, list) or not actions or not all(isinstance(a, str) for a in actions):
            problems.append('an injection case needs payload_actions, a non-empty list of canary actions')
        else:
            for action in actions:
                if action not in canary_actions:
                    problems.append('payload action %r is not in the canary list of the registry (%s)'
                                    % (action, ', '.join(canary_actions)))
    elif 'payload_b64' in data or 'payload_actions' in data:
        problems.append('payload_b64 and payload_actions are only for the kind injection')
    return problems


def find_payloads(data, path='$'):
    """Every `payload_b64` value of a parsed JSON document: [(path, value)]."""
    found = []
    if isinstance(data, dict):
        for key, value in data.items():
            if key == 'payload_b64':
                found.append(('%s.%s' % (path, key), value))
            else:
                found.extend(find_payloads(value, '%s.%s' % (path, key)))
    elif isinstance(data, list):
        for index, value in enumerate(data):
            found.extend(find_payloads(value, '%s[%d]' % (path, index)))
    return found


def decode_payload(value):
    """The text of a payload_b64 value; ValueError when it is not base64 of UTF-8 text."""
    if not isinstance(value, str):
        raise ValueError('payload_b64 must be a string')
    try:
        raw = base64.b64decode(value.encode('ascii'), validate=True)
        return raw.decode('utf-8')
    except (binascii.Error, UnicodeError, ValueError):
        raise ValueError('payload_b64 is not base64 of UTF-8 text')


def check_payload_text(text, canary_actions):
    """Problems of a decoded payload: a gh call outside the canary list and destructive patterns."""
    problems = []
    allowed = [action.split() for action in canary_actions if action.startswith('gh ')]
    for match in GH_CALL_RE.finditer(text):
        words = ['gh'] + [w for w in match.groups() if w]
        if not any(words[:len(prefix)] == prefix for prefix in allowed):
            problems.append('calls `%s`, which is not a canary action of the registry' % ' '.join(words))
    for label, regex in DESTRUCTIVE_PATTERNS:
        if regex.search(text):
            problems.append('contains the destructive pattern %s' % label)
    return problems


def scan_text(text):
    """The labels of the forbidden patterns found in a text, with the first line of each."""
    found = []
    for label, regex in SCAN_PATTERNS:
        match = regex.search(text)
        if match:
            found.append((label, text.count('\n', 0, match.start()) + 1))
    return found


# ------------------------------------------------------------------------------------------------------- walking

def _decode(raw):
    return raw.decode('utf-8', 'replace')


def _walk_all(root, rel_dir):
    """Every file below a directory, dotfiles included (the plain-payload search must not miss any)."""
    found = []
    base = os.path.join(root, rel_dir)
    for current, dirs, files in os.walk(base):
        dirs[:] = [d for d in dirs if d not in ('__pycache__', '.git')]
        for name in files:
            if not os.path.islink(os.path.join(current, name)):   # a link is never read (see _symlinks)
                found.append(os.path.relpath(os.path.join(current, name), root).replace(os.sep, '/'))
    return sorted(found)


def _symlinks(root, rel_dir):
    """Every symbolic link in or at a directory (to a file or a directory, a broken one too)."""
    base = os.path.join(root, rel_dir)
    found = []
    if os.path.islink(base):
        found.append(rel_dir)
    for current, dirs, files in os.walk(base):
        dirs[:] = [d for d in dirs if d not in ('__pycache__', '.git')]
        for name in dirs + files:
            if os.path.islink(os.path.join(current, name)):
                found.append(os.path.relpath(os.path.join(current, name), root).replace(os.sep, '/'))
    return sorted(found)


# ------------------------------------------------------------------------------------------------------- the lint

def lint(root):
    """Lints the repository at root; returns a Report (errors and warnings)."""
    report = Report()
    source = repo.DiskSource(root)
    try:
        reg, cfg = registry.load(source)
    except registry.ConfigError as error:
        for problem in error.problems:
            report.error(problem)
        return report
    canary = reg['canary_actions']
    decoded_payloads = []   # (where, text)
    commit_checks = []      # (label, suite entry, shas, diff or None)

    _lint_files(source, reg, cfg, report)
    for name, entry in sorted(reg['suites'].items()):
        _lint_cases(source, name, entry, canary, report, decoded_payloads, commit_checks)
    _lint_history(source, reg, report)
    _scan(root, report)
    _scan_decoded(decoded_payloads, report)
    _plain_payloads(root, decoded_payloads, report)
    _lint_commits(root, commit_checks, report)
    return report


def _lint_files(source, reg, cfg, report):
    for name, entry in sorted(reg['suites'].items()):
        for rel in entry['watched']:
            if source.read(rel) is None:
                report.error('suite %s: the watched file %s does not exist' % (name, rel))
        for rel in entry['validators']:
            if source.read(rel) is None:
                report.error('suite %s: the validator %s does not exist' % (name, rel))
    for rel in cfg['scoring_code']:
        if source.read(rel) is None:
            report.error('config.json: the scoring code file %s does not exist' % rel)


def _lint_cases(source, suite, entry, canary, report, decoded_payloads, commit_checks):
    base = entry['cases'].rstrip('/') + '/'
    json_files = [p for p in registry.case_files(source, entry) if p.endswith('.json')]
    for case_id in registry.case_ids(source, entry):
        path = '%s%s/case.json' % (base, case_id)
        raw = source.read(path)
        if raw is None:
            report.error('%s: the case directory %s has no case.json' % (entry['cases'], case_id))
            continue
        try:
            data = json.loads(_decode(raw))
        except ValueError as error:
            report.error('%s is not valid JSON: %s' % (path, error))
            continue
        for problem in validate_case(data, case_id, suite, canary):
            report.error('%s: %s' % (path, problem))
        if isinstance(data, dict):
            shas = [data[k] for k in ('commit', 'base_sha', 'head_sha') if isinstance(data.get(k), str)]
            diff = (data['base_sha'], data['head_sha']) if 'base_sha' in data and 'head_sha' in data else None
            if shas:
                commit_checks.append((path, entry, shas, diff))
    for path in json_files:
        try:
            document = json.loads(_decode(source.read(path)))
        except ValueError:
            continue   # a malformed case.json is reported above; other JSON files are data
        for where, value in find_payloads(document):
            label = '%s %s' % (path, where)
            try:
                text = decode_payload(value)
            except ValueError as error:
                report.error('%s: %s' % (label, error))
                continue
            decoded_payloads.append((label, text))
            for problem in check_payload_text(text, canary):
                report.error('%s: the decoded payload %s' % (label, problem))


def _lint_history(source, reg, report):
    seen = {}
    for path in source.list_files(registry.HISTORY_DIR):
        parts = path[len(registry.HISTORY_DIR) + 1:].split('/')
        if len(parts) != 2:
            report.error('%s: history files are tools/evals/history/<suite>/<name>.json' % path)
            continue
        suite, name = parts
        if suite not in reg['suites']:
            report.error('%s: the suite %s is not in the registry' % (path, suite))
            continue
        if not history.NAME_RE.match(name):
            report.error('%s: the name must be <UTC YYYYMMDDTHHMMSSZ>-<short sha>.json' % path)
            continue
        seen.setdefault(suite, []).append(name)
    for suite, names in sorted(seen.items()):
        for name in sorted(names):
            _lint_result(source, suite, name, names, report)


def _lint_result(source, suite, name, names, report):
    path = '%s/%s' % (history.history_dir(suite), name)
    try:
        result = history.load_result(source, suite, name)
    except history.HistoryError as error:
        report.error(str(error))
        return
    for problem in history.validate_result(result):
        report.error('%s: %s' % (path, problem))
    if history.validate_result(result):
        return
    if result['suite'] != suite:
        report.error('%s: suite %s does not match the directory' % (path, result['suite']))
    try:
        if history.result_file_name(result) != name:
            report.error('%s: the name does not match the date and the commit of the result (%s)'
                         % (path, history.result_file_name(result)))
    except ValueError:
        report.error('%s: the date is not valid' % path)
    run_file = result.get('run_file')
    if run_file is None:
        return
    if run_file not in names:
        report.error('%s: run_file %s does not exist in the history of %s' % (path, run_file, suite))
    elif run_file >= name:
        report.error('%s: run_file %s must be older than the decision' % (path, run_file))
    elif result['verdict'] == 'accepted':
        try:
            referenced = history.load_result(source, suite, run_file)
        except history.HistoryError as error:
            report.error('%s: run_file %s cannot be read: %s' % (path, run_file, error))
            return
        if referenced.get('verdict') != 'regression':
            report.error('%s: accepted needs a run_file with the verdict regression (%s is %s)'
                         % (path, run_file, referenced.get('verdict')))


def _scan(root, report):
    """The leak scan reads every file, hidden ones included; a hidden file in a case directory is an error of its own
    (listings and fingerprints skip dotfiles, so it would be part of the case without being part of the fingerprint)."""
    for rel_dir in (registry.SUITES_DIR, registry.HISTORY_DIR):
        for path in _symlinks(root, rel_dir):
            report.error('%s: a symbolic link is not allowed (it is not followed, so it is not part of the case data '
                         'or the fingerprint)' % path)
        for path in _walk_all(root, rel_dir):
            with open(os.path.join(root, path), 'rb') as handle:
                content = _decode(handle.read())
            for label, line in scan_text(content):
                report.error('%s:%d: %s (the repository is public)' % (path, line, label))
            inside = path[len(rel_dir) + 1:].split('/')
            if rel_dir == registry.SUITES_DIR and len(inside) > 2 and any(part.startswith('.') for part in inside):
                report.error('%s: a hidden file in a case directory is not part of the fingerprint' % path)


def _scan_decoded(decoded_payloads, report):
    for where, text in decoded_payloads:
        for label, _ in scan_text(text):
            report.error('%s: the decoded payload has a %s (the repository is public)' % (where, label))


def _normalize(text):
    """Case and white space do not matter when a payload is searched for: casefolded, runs of white space (Unicode
    ones, line breaks) collapsed into one space."""
    return ' '.join(text.casefold().split())


def _plain_candidates(text):
    candidates = []
    full = _normalize(text)
    if full:
        candidates.append(full)
    for line in text.splitlines():
        if len(line.strip()) >= MIN_PLAIN_LINE:
            candidates.append(_normalize(line))
    candidates.extend(_normalize(json.dumps(c, ensure_ascii=False)[1:-1]) for c in list(candidates))
    candidates.extend(_normalize(json.dumps(c)[1:-1]) for c in list(candidates))
    return candidates


def _json_strings(data):
    """The decoded string values of a parsed JSON document, except `payload_b64` (the encoded payload itself)."""
    if isinstance(data, str):
        yield data
    elif isinstance(data, dict):
        for key, value in data.items():
            if key != 'payload_b64':
                yield from _json_strings(value)
    elif isinstance(data, list):
        for value in data:
            yield from _json_strings(value)


def _haystacks(path, content):
    """The normalized texts of a file in which a payload may be hiding: the file itself and, for a JSON file, its
    decoded string values (json.dumps writes Polish letters as \\uXXXX). The encoded payloads are cut out, so a case
    never finds its own payload."""
    if path.endswith('.json'):
        try:
            document = json.loads(content)
        except ValueError:
            document = None
        if document is not None:
            raw = content
            for _, value in find_payloads(document):
                if isinstance(value, str) and value:
                    raw = raw.replace(value, '')
            return [_normalize(raw)] + [_normalize(value) for value in _json_strings(document)]
    return [_normalize(content)]


def _plain_payloads(root, decoded_payloads, report):
    if not decoded_payloads:
        return
    candidates = []
    for where, text in decoded_payloads:
        candidates.extend((where, c) for c in _plain_candidates(text))
    for path in _walk_all(root, registry.EVALS_DIR):
        with open(os.path.join(root, path), 'rb') as handle:
            content = _decode(handle.read())
        haystacks = _haystacks(path, content)
        for where, candidate in candidates:
            if any(candidate in haystack for haystack in haystacks):
                report.error('%s contains the decoded payload of %s in plain text' % (path, where.split(' ')[0]))
                break


def _candidate_refs(root):
    _, out = repo.git(root, 'for-each-ref', '--format=%(refname)', 'refs/heads', 'refs/remotes')
    pattern = re.compile(r'^refs/(?:heads|remotes/[^/]+)/(?:main|epic/.+)$')
    return [r for r in out.decode().split('\n') if pattern.match(r)]


def _lint_commits(root, checks, report):
    if not checks:
        return
    if not repo.is_git_repository(root):
        report.warn('not a git repository: the commits of the cases were not checked')
        return
    if repo.is_shallow(root):
        report.warn('shallow clone: the commits of the cases were not checked (the evals job fetches the full history)')
        return
    refs = _candidate_refs(root)
    for path, entry, shas, diff in checks:
        for sha in shas:
            if not repo.commit_exists(root, sha):
                report.error('%s: the commit %s does not exist in the repository' % (path, sha[:12]))
            elif not any(repo.git(root, 'merge-base', '--is-ancestor', sha, ref, check=False)[0] == 0
                         for ref in refs):
                report.error('%s: the commit %s is not reachable from main or epic/* (a task branch is deleted '
                             'after the merge: use its commit in the history of the epic branch)' % (path, sha[:12]))
        if diff and all(repo.commit_exists(root, sha) for sha in diff):
            _, out = repo.git(root, 'diff', '--name-only', '--no-renames', '-z', diff[0], diff[1])
            watched = set(entry['watched'])
            for changed in (p.decode('utf-8', 'replace') for p in out.split(b'\0') if p):
                if changed.startswith(OVERLAY_PREFIXES) or changed in watched:
                    report.error('%s: the task diff touches %s, a path of the overlay (agents, skills and the '
                                 'watched files of the suite are replaced by the current ones)' % (path, changed))

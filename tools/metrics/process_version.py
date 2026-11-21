"""The version of the process of a native epic (docs/features/metrics/spec.md, "Wersja procesu"): which instructions
the epic expects (`expected`, from its base commit), which ones lie on the disk of every place a session loads them
from (`disk`) and which ones a transcript confirms were loaded (`loaded`). Only file paths, git blob shas, digests and
statuses leave this module, never the text of an instruction.

A session in a worktree started from the desktop app reads `CLAUDE.md` from the worktree, but the agents
(`.claude/agents`) and the skills (`.claude/skills`) from the main checkout of the repository
(https://code.claude.com/docs/en/worktrees.md): both places are read from disk.

Python 3.9+, standard library only. `run(command)` is the command runner of metrics.py (a CompletedProcess with text
output)."""
import glob
import hashlib
import json
import os
import re
import subprocess

TREE = ('.claude/agents', '.claude/skills')           # the definitions whose tree has the fingerprint
ADR = 'docs/adr/agentic-software-house.md'
CLAUDE_MD = 'CLAUDE.md'
STATUSES = ('confirmed', 'differs', 'unconfirmed')
NOT_RUN = 'jeszcze nie uruchomione'
SKILL_HEADER = re.compile(r'^Base directory for this skill: ([^\n]*)\n')
# The placeholders the CLI replaces in the text of a skill when it loads it (`$ARGUMENTS`, `$ARGUMENTS[0]`, `$0`,
# `${CLAUDE_SKILL_DIR}`): in a comparison they stand for any text.
PLACEHOLDER = re.compile(r'\$ARGUMENTS(?:\[\d+\])?|\$\{[A-Z_]+\}|\$\d+')


class CheckError(Exception):
    """The check could not run (no git, no repository, no definitions, an unknown commit); the message is Polish."""


# ------------------------------------------------------------------------------------------------------------ git

def git_text(run, cwd, args):
    try:
        result = run(['git', '-C', cwd] + list(args))
    except (OSError, subprocess.TimeoutExpired) as e:
        raise CheckError('git niedostępny (%s)' % (getattr(e, 'strerror', None) or e))
    if result.returncode != 0:
        raise CheckError('git %s w %s: %s' % (args[0], cwd, (result.stderr or '').strip()[:200]
                                              or 'kod %s' % result.returncode))
    return result.stdout


def _optional(run, cwd, args):
    try:
        return git_text(run, cwd, args).strip() or None
    except CheckError:
        return None


def is_instruction(path):
    """Whether `path` (relative to the repository) is an instruction file whose blob sha is listed in `files`."""
    parts = path.split('/')
    return (parts[-1] == CLAUDE_MD or path == ADR
            or (len(parts) == 3 and path.startswith('.claude/agents/') and path.endswith('.md'))
            or (len(parts) == 4 and path.startswith('.claude/skills/') and parts[-1] == 'SKILL.md'))


def in_tree(path):
    return any(path.startswith(top + '/') for top in TREE)


def fingerprint(entries):
    """sha256 (the first 16 hex characters) of the sorted lines `<path>\\t<blob sha>\\n` of the tree entries."""
    lines = ''.join('%s\t%s\n' % (path, entries[path]) for path in sorted(entries) if in_tree(path))
    return hashlib.sha256(lines.encode('utf-8')).hexdigest()[:16]


def digest(text):
    return hashlib.sha256(text.encode('utf-8')).hexdigest()[:16]


def checkout_of(run, directory):
    """{top, main, is_main}: the top of the working tree of `directory`, the main checkout of its repository (the parent
    of the common git directory, None for a bare repository) and whether they are the same."""
    if not os.path.isdir(directory):
        raise CheckError('katalog %s nie istnieje' % directory)
    try:
        top = os.path.realpath(git_text(run, directory, ['rev-parse', '--show-toplevel']).strip())
    except CheckError as e:
        raise CheckError('katalog %s nie jest w repozytorium git (%s)' % (directory, e))
    common = git_text(run, top, ['rev-parse', '--git-common-dir']).strip()
    common = os.path.realpath(common if os.path.isabs(common) else os.path.join(top, common))
    main = os.path.dirname(common) if os.path.basename(common) == '.git' else None
    return {'top': top, 'main': main, 'is_main': main is not None and main == top}


def tree_of(run, top, ref):
    """{path: blob sha} of the tree of `ref` restricted to the definitions and the instruction files."""
    out = git_text(run, top, ['ls-tree', '-r', '-z', '--full-tree', ref])
    found = {}
    for entry in out.split('\0'):
        meta, _, path = entry.partition('\t')
        fields = meta.split()
        if len(fields) == 3 and fields[1] == 'blob' and (in_tree(path) or is_instruction(path)):
            found[path] = fields[2]
    return found


def _blob_of_link(path):
    target = os.readlink(path).encode('utf-8', 'surrogateescape')
    return hashlib.sha1(b'blob %d\0' % len(target) + target).hexdigest()


def disk_of(run, place, full):
    """{path: blob sha} of the files on disk in `place`: tracked and untracked, without the ignored ones (a tracked
    file deleted from disk is absent). full: also every CLAUDE.md and the ADR (the worktree a session reads them from);
    else only the definitions (the main checkout)."""
    specs = list(TREE) + ([ADR, ':(glob)**/' + CLAUDE_MD] if full else [])
    out = git_text(run, place, ['ls-files', '-z', '-c', '-o', '--exclude-standard', '--'] + specs)
    paths = sorted({p for p in out.split('\0') if p and (in_tree(p) or (full and is_instruction(p)))})
    found, regular = {}, []
    for path in paths:
        full_path = os.path.join(place, path)
        if os.path.islink(full_path):
            found[path] = _blob_of_link(full_path)
        elif os.path.isfile(full_path):
            regular.append(path)
    for start in range(0, len(regular), 200):
        chunk = regular[start:start + 200]
        shas = git_text(run, place, ['hash-object', '--'] + chunk).split()
        if len(shas) != len(chunk):
            raise CheckError('git hash-object w %s: zła liczba wyników' % place)
        found.update(zip(chunk, shas))
    return found


def differences(expected, found, full):
    """[{path, state}] of `found` (disk) against `expected` in the scope of the place: `changed`, `missing`, `extra`."""
    scope = {p: s for p, s in expected.items() if in_tree(p) or (full and is_instruction(p))}
    listed = []
    for path in sorted(set(scope) | set(found)):
        if path not in found:
            listed.append({'path': path, 'state': 'missing'})
        elif path not in scope:
            listed.append({'path': path, 'state': 'extra'})
        elif scope[path] != found[path]:
            listed.append({'path': path, 'state': 'changed'})
    return listed


def project_names(entries):
    """(agents, skills): the names of the agents (`.claude/agents/<name>.md`) and of the skills
    (`.claude/skills/<name>/...`) of the tree."""
    agents, skills = set(), set()
    for path in entries:
        parts = path.split('/')
        if len(parts) == 3 and path.startswith('.claude/agents/') and path.endswith('.md'):
            agents.add(parts[2][:-len('.md')])
        elif len(parts) >= 4 and path.startswith('.claude/skills/'):
            skills.add(parts[2])
    return agents, skills


def user_level(user_dir, entries):
    """The agents and skills of the user directory with the name of an agent or skill of the project (names only)."""
    agents, skills = project_names(entries)

    def names(directory, pattern, strip):
        try:
            return {strip(n) for n in os.listdir(os.path.join(user_dir, directory)) if pattern(n, directory)}
        except OSError:
            return set()
    found_agents = names('agents', lambda n, d: n.endswith('.md'), lambda n: n[:-len('.md')])
    found_skills = names('skills', lambda n, d: os.path.isdir(os.path.join(user_dir, d, n)), lambda n: n)
    return {'agents': sorted(found_agents & agents), 'skills': sorted(found_skills & skills)}


def place_of(run, kind, path, expected, full):
    entries = disk_of(run, path, full)
    specs = list(TREE) + ([ADR, ':(glob)**/' + CLAUDE_MD] if full else [])
    branch = _optional(run, path, ['rev-parse', '--abbrev-ref', 'HEAD'])
    # --no-optional-locks: `status` must not refresh the index of the place (the check only reads)
    status = git_text(run, path, ['--no-optional-locks', 'status', '--porcelain', '-z', '--'] + specs)
    return {'kind': kind, 'path': path, 'head': _optional(run, path, ['rev-parse', '--verify', '-q', 'HEAD']),
            'branch': None if branch == 'HEAD' else branch, 'dirty': bool(status.strip('\0').strip()),
            'fingerprint': fingerprint(entries), 'files': {p: s for p, s in entries.items() if is_instruction(p)},
            'differs': differences(expected, entries, full)}


def expected_of(run, directory, ref):
    """(expected, tree): `expected` {ref, requested, describe, fingerprint, files} of the commit `ref` (None: HEAD) in
    the repository of `directory` and the {path: blob sha} of its definitions and instruction files; CheckError when
    the repository does not know the commit."""
    requested = ref or 'HEAD'
    try:
        sha = git_text(run, directory, ['rev-parse', '--verify', '-q', requested + '^{commit}']).strip()
    except CheckError:
        raise CheckError('nieznana rewizja %s w %s' % (requested, directory))
    tree = tree_of(run, directory, sha)
    describe = _optional(run, directory, ['describe', '--tags', '--match', 'ash-v*', '--always', sha])
    return ({'ref': sha, 'requested': requested, 'describe': describe, 'fingerprint': fingerprint(tree),
             'files': {p: s for p, s in sorted(tree.items()) if is_instruction(p)}}, tree)


def reading(run, directory, ref, user_dir):
    """(reading, internal): the expected version and the disk of every place. reading: {worktree, worktree_head,
    expected: {ref, requested, describe, fingerprint, files}, disk: [place], user_level, match}; internal: {tree, top,
    main} for the comparison of the transcripts."""
    checkout = checkout_of(run, directory)
    top = checkout['top']
    expected, tree = expected_of(run, top, ref)
    if not any(in_tree(p) for p in tree) and not os.path.isdir(os.path.join(top, '.claude')):
        raise CheckError('brak definicji .claude/agents i .claude/skills w %s i w %s' % (expected['ref'][:12], top))
    if checkout['is_main']:
        disk = [place_of(run, 'main_checkout', top, tree, True)]
    else:
        disk = [place_of(run, 'worktree', top, tree, True)]
        if checkout['main'] is not None and os.path.isdir(checkout['main']):
            disk.append(place_of(run, 'main_checkout', checkout['main'], tree, False))
    users = user_level(user_dir, tree)
    data = {'worktree': top, 'worktree_head': _optional(run, top, ['rev-parse', '--verify', '-q', 'HEAD']),
            'expected': expected, 'disk': disk, 'user_level': users,
            'match': not any(p['differs'] for p in disk) and not users['skills']}
    return data, {'tree': tree, 'top': top, 'main': checkout['main'], 'run': run}


def summary(places, limit=5):
    """One line per place with differences: the kind, the path, the number of files and the first paths."""
    lines = []
    for place in places:
        differs = place.get('differs') or []
        if differs:
            shown = ', '.join('%s (%s)' % (d['path'], d['state']) for d in differs[:limit])
            lines.append('%s %s: %d %s: %s%s' % (place['kind'], place.get('path') or '', len(differs),
                                                 'plik' if len(differs) == 1 else 'pliki' if len(differs) < 5
                                                 else 'plików', shown, ', …' if len(differs) > limit else ''))
    return lines


# ---------------------------------------------------------------------------------------------- the transcripts

def strip_frontmatter(text):
    """The text of an agent or skill file without its YAML frontmatter, stripped (as the CLI loads it)."""
    if text.startswith('---'):
        end = text.find('\n---', 3)
        if end >= 0:
            text = text[end + 4:]
    return text.strip()


def matches_template(template, text):
    """Whether `text` is `template` with any text in place of every placeholder (linear, without a regex)."""
    pieces = PLACEHOLDER.split(template)
    if len(pieces) == 1:
        return text == template
    if not text.startswith(pieces[0]) or not text.endswith(pieces[-1]):
        return False
    position, end = len(pieces[0]), len(text) - len(pieces[-1])
    if end < position:
        return False
    for piece in pieces[1:-1]:
        found = text.find(piece, position, end)
        if found < 0:
            return False
        position = found + len(piece)
    return True


def _texts(message):
    content = message.get('content') if isinstance(message, dict) else None
    if isinstance(content, str):
        return [content]
    if isinstance(content, list):
        return [b['text'] for b in content if isinstance(b, dict) and isinstance(b.get('text'), str)]
    return []


def read_evidence(path):
    """(evidence, None) of one transcript, or (None, reason) when it cannot be read: {claude_md: [(absolute path,
    content, timestamp text or None)], skills: [(skill directory, loaded text, timestamp)], snapshots: [system prompt
    blocks], problems: [reason], times: [timestamp text]}. A line of an unknown shape is a problem, never an exception."""
    found = {'claude_md': [], 'skills': [], 'snapshots': [], 'problems': [], 'times': []}
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            raw_lines = f.readlines()
    except FileNotFoundError:
        return None, 'brak pliku transkryptu'
    except OSError as e:
        return None, 'nieczytelny transkrypt: %s' % (e.strerror or e)
    for raw in raw_lines:
        try:
            line = json.loads(raw)
        except ValueError:
            continue
        if not isinstance(line, dict):
            continue
        if isinstance(line.get('timestamp'), str) and line.get('type') in ('user', 'assistant'):
            found['times'].append(line['timestamp'])
        stamp = line.get('timestamp') if isinstance(line.get('timestamp'), str) else None
        if line.get('type') == 'attachment':
            _attachment(line.get('attachment'), found, stamp)
        elif line.get('type') == 'user':
            for text in _texts(line.get('message')):
                match = SKILL_HEADER.match(text)
                if match:
                    found['skills'].append((match.group(1).strip(), text[match.end():].strip(), stamp))
    return found, None


def _attachment(attachment, found, stamp):
    if not isinstance(attachment, dict):
        found['problems'].append('wpis attachment bez obiektu attachment')
        return
    kind = attachment.get('type')
    if kind == 'instructions':
        files = attachment.get('files')
        if not isinstance(files, list):
            found['problems'].append('wpis instructions bez listy files')
            return
        for entry in files:
            if not isinstance(entry, dict) or not isinstance(entry.get('path'), str) \
                    or not isinstance(entry.get('content'), str):
                found['problems'].append('wpis instructions z plikiem bez path albo content')
            elif entry.get('type') == 'Project' and os.path.basename(entry['path']) == CLAUDE_MD:
                found['claude_md'].append((entry['path'], entry['content'], stamp))
    elif kind == 'nested_memory':
        content = attachment.get('content')
        if not isinstance(content, dict) or not isinstance(content.get('path'), str) \
                or not isinstance(content.get('content'), str):
            found['problems'].append('wpis nested_memory bez content.path albo content.content')
        elif content.get('type', 'Project') == 'Project' and os.path.basename(content['path']) == CLAUDE_MD:
            found['claude_md'].append((content['path'], content['content'], stamp))
    elif kind == 'prompt_snapshot':
        blocks = attachment.get('systemPrompt')
        if not isinstance(blocks, list) or not blocks or not all(isinstance(b, str) for b in blocks):
            found['problems'].append('wpis prompt_snapshot bez listy tekstów systemPrompt')
        else:
            found['snapshots'].append(blocks)


class Expected:
    """The texts of the instruction files of the expected commit (`files`: {path: blob sha} of `expected.files`), read
    from the repository of `top` on demand and never stored. A base commit the repository does not have makes every
    text unavailable, with the reason in `error`."""

    def __init__(self, run, top, ref, files):
        self.run, self.top, self.ref, self.tree, self.cache = run, top, ref, dict(files or {}), {}
        self.error = None
        if not isinstance(ref, str) or not ref:
            self.error = 'brak bazy epiku w odczycie'
        else:
            try:
                git_text(run, top, ['cat-file', '-e', ref + '^{commit}'])
            except CheckError:
                self.error = 'baza epiku %s niedostępna w repozytorium %s' % (ref[:12], top)

    def text(self, path):
        if path not in self.tree or self.error is not None and path not in self.cache:
            return None
        if path not in self.cache:
            try:
                self.cache[path] = git_text(self.run, self.top, ['cat-file', 'blob', self.tree[path]])
            except CheckError as e:
                self.error = str(e)
                self.cache[path] = None
        return self.cache[path]


def _relative(path, roots, candidates):
    """The path relative to the repository of an absolute path from a transcript: under one of the `roots`, else the
    longest suffix that is one of the `candidates`."""
    for root in roots:
        if root and path.startswith(root.rstrip('/') + '/'):
            return path[len(root.rstrip('/')) + 1:]
    parts = path.split('/')
    for index in range(1, len(parts)):
        suffix = '/'.join(parts[index:])
        if suffix in candidates:
            return suffix
    return None


def _entry(fields, status, reason=None, digest_=None, origin=None, version=None):
    entry = dict(fields)
    entry.update(status=status, version=version, origin=origin, digest=digest_, reason=reason)
    return entry


EPIC_UNKNOWN = 'treść inna niż w bazie, historia gałęzi epiku niedostępna'


class EpicVersions:
    """The versions of a file the epic itself made: every version of the file in the commits of the epic branch
    (`heads`: the first ref the repository of `top` resolves) not reachable from the base, and its current text in the
    `worktrees` of the sessions. `available`: whether the history of the branch could be read."""

    def __init__(self, run, top, base, heads, worktrees):
        self.run, self.top, self.base, self.cache = run, top, base, {}
        self.worktrees = [w for w in worktrees or [] if isinstance(w, str) and os.path.isdir(w)]
        self.head = None
        for head in heads or []:
            if not head or not base:
                continue
            try:
                self.head = git_text(run, top, ['rev-parse', '--verify', '-q', head + '^{commit}']).strip()
                break
            except CheckError:
                continue

    @property
    def available(self):
        return self.head is not None

    def texts(self, path):
        """The stripped texts of the epic versions of `path` (the history of the branch and the files on disk)."""
        if path in self.cache:
            return self.cache[path]
        found = set()
        if self.head is not None:
            try:
                commits = git_text(self.run, self.top, ['log', '--format=%H', '%s..%s' % (self.base, self.head),
                                                        '--', path]).split()
            except CheckError:
                commits = []
            for commit in commits:
                try:
                    found.add(git_text(self.run, self.top, ['cat-file', 'blob', '%s:%s' % (commit, path)]).strip())
                except CheckError:
                    continue                        # the commit deletes the file
        for worktree in self.worktrees:
            try:
                with open(os.path.join(worktree, path), encoding='utf-8') as f:
                    found.add(f.read().strip())
            except (OSError, UnicodeDecodeError):
                continue
        self.cache[path] = found
        return found


def _under(path, roots):
    return any(root and path.startswith(root.rstrip('/') + '/') for root in roots or [])


def _before(stamp, high, parse_time):
    """Whether evidence with the timestamp text `stamp` lies before `high` (an epoch; None: no bound); evidence
    without a readable time counts, like a line without `timestamp` in the rest of the record."""
    if high is None or stamp is None or parse_time is None:
        return True
    try:
        return parse_time(stamp) <= high
    except (TypeError, ValueError, AttributeError):
        return True


def session_entries(session, evidence, reason, expected, roots, epic=None, own=()):
    """The `loaded.session` entries of one lead session: one per expected CLAUDE.md and the ADR, plus a loaded
    CLAUDE.md that is no file of the base. A loaded text other than the base, read from the session's own worktree
    (`own`), is the epic version (`version: "epic"`) when `epic` has it, `unconfirmed` when the history of the epic
    branch is not available, else `differs`."""
    paths = sorted(p for p in expected.tree if os.path.basename(p) == CLAUDE_MD)
    entries = []
    loaded = {}
    extra = []
    if evidence is not None:
        for origin, content, _ in evidence['claude_md']:
            relative = _relative(origin, roots, set(paths))
            if relative in paths:
                loaded.setdefault(relative, []).append((origin, content))
            else:
                extra.append((origin, content))
    for path in paths:
        fields = {'session': session, 'path': path}
        if evidence is None:
            entries.append(_entry(fields, 'unconfirmed', reason))
            continue
        found = loaded.get(path)
        if not found:
            why = 'brak wpisu instructions ani nested_memory z tym plikiem w transkrypcie sesji'
            if evidence['problems']:
                why += ' (nierozpoznane wpisy: %s)' % '; '.join(sorted(set(evidence['problems']))[:3])
            entries.append(_entry(fields, 'unconfirmed', why))
            continue
        text = expected.text(path)
        if text is None:
            entries.append(_entry(fields, 'unconfirmed', 'treść oczekiwana niedostępna: %s' % expected.error))
            continue
        entries.append(_claude_md_entry(fields, found, text.strip(), epic, own))
    for origin, content in extra:
        entries.append(_entry({'session': session, 'path': None}, 'differs', 'plik CLAUDE.md spoza bazy epiku',
                              digest(content.strip()), origin))
    if ADR in expected.tree:
        entries.append(_entry({'session': session, 'path': ADR}, 'unconfirmed',
                              'ADR czyta sesja narzędziem Read, transkrypt nie zapisuje go jako instrukcji'))
    return entries


def _claude_md_entry(fields, found, base, epic, own):
    """The entry of one CLAUDE.md from its loads [(origin, content)]: the worst load decides (differs, then
    unconfirmed, then the epic version, then the base)."""
    results = []
    for origin, content in found:
        text = content.strip()
        if text == base:
            results.append((3, _entry(fields, 'confirmed', origin=origin, version='base')))
        elif epic is None or not _under(origin, own):
            results.append((0, _entry(fields, 'differs', 'załadowana treść inna niż w bazie', digest(text), origin)))
        elif text in epic.texts(fields['path']):
            results.append((2, _entry(fields, 'confirmed', origin=origin, digest_=digest(text), version='epic')))
        elif not epic.available:
            results.append((1, _entry(fields, 'unconfirmed', EPIC_UNKNOWN, digest(text), origin)))
        else:
            results.append((0, _entry(fields, 'differs', 'załadowana treść inna niż w bazie i w gałęzi epiku',
                                      digest(text), origin)))
    return min(results, key=lambda r: r[0])[1]


def skill_entries(session, agent, evidence, expected, high=None, parse_time=None):
    """The `loaded.skills` entries of the skill loads of one transcript up to `high` (only the skills of the project;
    the expected version is always the base: skills load from the main checkout)."""
    _, names = project_names(expected.tree)
    entries = []
    for directory, text, stamp in evidence['skills'] if evidence is not None else []:
        name = os.path.basename(directory.rstrip('/'))
        if name not in names or not _before(stamp, high, parse_time):
            continue
        fields = {'session': session, 'agent_id': agent, 'skill': name}
        template = expected.text('.claude/skills/%s/SKILL.md' % name)
        if template is None:
            entries.append(_entry(fields, 'unconfirmed', 'treść oczekiwana niedostępna: %s'
                                  % (expected.error or 'brak SKILL.md w bazie'), origin=directory))
        elif matches_template(strip_frontmatter(template), text):
            entries.append(_entry(fields, 'confirmed', origin=directory, version='base'))
        else:
            entries.append(_entry(fields, 'differs', 'załadowana treść inna niż w bazie', digest(text), directory))
    return entries


def agent_entry(session, agent, role, evidence, reason, expected):
    """The `loaded.agents` entry of one subagent run: its first `prompt_snapshot` block must be the agent file of the
    base without the frontmatter (or the file must be in the prompt)."""
    fields = {'session': session, 'agent_id': agent, 'role': role}
    if role is None:
        return _entry(fields, 'unconfirmed', 'brak meta.json albo agentType: rola nieznana')
    if evidence is None:
        return _entry(fields, 'unconfirmed', reason)
    if not evidence['snapshots']:
        why = 'brak wpisu prompt_snapshot w transkrypcie subagenta'
        if evidence['problems']:
            why += ' (%s)' % '; '.join(sorted(set(evidence['problems']))[:3])
        return _entry(fields, 'unconfirmed', why)
    text = expected.text('.claude/agents/%s.md' % role)
    if text is None:
        return _entry(fields, 'unconfirmed', 'treść oczekiwana niedostępna: %s' % (expected.error or 'brak pliku'))
    body = strip_frontmatter(text)
    for blocks in evidence['snapshots']:
        if blocks[0].strip() != body and body not in '\n'.join(blocks):
            return _entry(fields, 'differs', 'załadowana treść roli inna niż w bazie', digest(blocks[0].strip()))
    return _entry(fields, 'confirmed', version='base')


def placeholder(fields, reason):
    return _entry(dict.fromkeys(fields), 'unconfirmed', reason)


AGENT_FIELDS = ('session', 'agent_id', 'role')
SKILL_FIELDS = ('session', 'agent_id', 'skill')


def session_transcripts(projects, sessions):
    """[(session, path or None, [(agent, path, meta path)])] of the sessions under `projects` (any project
    directory)."""
    found = []
    for session in sessions:
        paths = sorted(glob.glob(os.path.join(glob.escape(projects), '*', '%s.jsonl' % session)))
        if not paths:
            found.append((session, None, []))
            continue
        directory = os.path.join(os.path.dirname(paths[0]), session, 'subagents')
        try:
            names = sorted(os.listdir(directory))
        except OSError:
            names = []
        agents = []
        for name in names:
            match = re.match(r'^agent-(.+)\.(?:jsonl|meta\.json)$', name)
            if match and match.group(1) not in [a[0] for a in agents]:
                agent = match.group(1)
                agents.append((agent, os.path.join(directory, 'agent-%s.jsonl' % agent),
                               os.path.join(directory, 'agent-%s.meta.json' % agent)))
        found.append((session, paths[0], agents))
    return found


def _role(meta_path):
    try:
        with open(meta_path, encoding='utf-8') as f:
            meta = json.load(f)
    except (OSError, ValueError):
        return None
    role = meta.get('agentType') if isinstance(meta, dict) else None
    return role if isinstance(role, str) and role else None


def loaded_of(projects, sessions, expected, roots, agents=True, window=None, parse_time=None, epic=None, own=()):
    """`loaded` {session, agents, skills} from the transcripts of the sessions. agents False: the subagents are not
    read (`start`: not run yet). window: (low, high) epochs; the CLAUDE.md and skill loads after `high` and a subagent
    run whose messages all lie outside the window are no evidence of the epic (parse_time turns a timestamp text into
    an epoch). epic, own: the epic versions of a CLAUDE.md read from the sessions' own worktrees (EpicVersions)."""
    session_list, agent_list, skill_list = [], [], []
    project_agents, _ = project_names(expected.tree)
    high = window[1] if window is not None else None
    for session, path, subagents in session_transcripts(projects, sessions):
        evidence, reason = read_evidence(path) if path else (None, 'brak transkryptu sesji')
        if evidence is not None:
            evidence['claude_md'] = [c for c in evidence['claude_md'] if _before(c[2], high, parse_time)]
        session_list += session_entries(session, evidence, reason, expected, roots, epic, own)
        skill_list += skill_entries(session, None, evidence, expected, high, parse_time)
        if not agents:
            continue
        for agent, agent_path, meta_path in subagents:
            role = _role(meta_path)
            if role is not None and role not in project_agents:
                continue                    # a built-in role: no instruction file of the project
            evidence, reason = read_evidence(agent_path)
            if evidence is not None and window is not None and not _in_window(evidence['times'], window, parse_time):
                continue
            agent_list.append(agent_entry(session, agent, role, evidence, reason or 'brak transkryptu subagenta',
                                          expected))
            skill_list += skill_entries(session, agent, evidence, expected, high, parse_time)
    if not agents:
        agent_list = [placeholder(AGENT_FIELDS, NOT_RUN)]
    elif not agent_list:
        agent_list = [placeholder(AGENT_FIELDS, 'w transkryptach epiku nie ma przebiegu subagenta roli projektu')]
    if not skill_list:
        skill_list = [placeholder(SKILL_FIELDS, NOT_RUN if not agents else
                                  'w transkryptach epiku nie ma wywołania skilla projektu')]
    return {'session': session_list, 'agents': agent_list, 'skills': skill_list}


def _in_window(times, window, parse_time):
    low, high = window
    epochs = []
    for text in times:
        try:
            epochs.append(parse_time(text))
        except (TypeError, ValueError, AttributeError):
            continue
    if not epochs:
        return True
    return (low is None or max(epochs) >= low) and (high is None or min(epochs) <= high)


def loaded_items(loaded):
    """[(section, entry)] of every entry of `loaded`."""
    return [(section, entry) for section in ('session', 'agents', 'skills')
            for entry in (loaded or {}).get(section) or [] if isinstance(entry, dict)]


def loaded_key(section, entry):
    """The identity of a `differs` entry: the same loaded text of the same item is one change."""
    return [section, entry.get('session'), entry.get('agent_id'), entry.get('role') or entry.get('skill')
            or entry.get('path'), entry.get('digest')]


def counts(entries):
    found = dict.fromkeys(STATUSES, 0)
    for entry in entries:
        if entry.get('status') in found:
            found[entry['status']] += 1
    return found


def summary_of(entries):
    """`counts` and `confirmed_epic`: the confirmed entries in the version the epic made (`version: "epic"`)."""
    found = counts(entries)
    found['confirmed_epic'] = sum(1 for e in entries if e.get('status') == 'confirmed' and e.get('version') == 'epic')
    return found

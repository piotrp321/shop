"""The checkout of one pass of an eval case (spec: Checkout przypadku). Python 3.9+, standard library only.

Every pass gets a fresh checkout in its own pass directory:

  <pass>/.build/      a scratch repository (removed before the agent starts): the case commits are fetched into it with
                      depth 1 from the repository and the overlay commit is built there with plumbing commands;
  <pass>/origin.git/  the local bare repository the checkout is cloned from; origin URL and pushurl point to it;
  <pass>/checkout/    the clone the agent works in.

The overlay commit puts the files of `.claude/agents`, `.claude/skills` and every watched file of the suite at the
overlay revision (the current HEAD, or the revision of a trial run) on the case commit. A diff case gets the overlay on
base_sha and on head_sha; the second overlay commit has the first as its parent, so the new base..head is exactly the
task diff (checked: the raw diff of the two pairs must be equal, otherwise the task diff touches an overlay path).
An optional patch of the case is one more commit on top; the base given to the prompt is then the commit before the
reviewed change: the overlay commit of a commit case (the patch alone) and the overlay commit on base_sha of a diff
case (the task diff and the patch). Only the commits reachable from the new head reach the bare repository and the
checkout: the commit of the overlay revision (and with it today's tree of the repository) never does.

Every git command of this module runs on a repository the runner built itself, before the agent starts. In the real
sandbox `<checkout>/.git` is write-protected (render_settings.py without --git-common-dir puts it in denyWrite), so
the agent cannot change its config or hooks (nor commit or fetch into it). The runner still never runs git in the
checkout after the agent (the tests run without the sandbox, and a planted config would run code outside it):
`tree_changes` compares the working tree with the manifest taken at build time in plain Python.
"""
import hashlib
import os
import shutil
import stat
import subprocess

OVERLAY_DIRS = ('.claude/agents', '.claude/skills')
BRANCH = 'main'
IDENTITY = {'GIT_AUTHOR_NAME': 'Evals runner', 'GIT_AUTHOR_EMAIL': 'evals-runner@example.invalid',
            'GIT_COMMITTER_NAME': 'Evals runner', 'GIT_COMMITTER_EMAIL': 'evals-runner@example.invalid'}


class CheckoutError(Exception):
    """The checkout of a pass cannot be built (an unknown commit, a git failure, a diff touching the overlay)."""


def runner_git_env(extra=None):
    """The environment of the runner's own git commands: the caller's environment without any GIT_* variable (a
    session may carry GIT_CONFIG_COUNT, GIT_DIR or GIT_INDEX_FILE) and without the user's and the system git config."""
    env = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    env.update({'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull, 'GIT_TERMINAL_PROMPT': '0'})
    env.update(extra or {})
    return env


def _git(cwd, *args, env=None, input_=None):
    try:
        result = subprocess.run(['git', '-C', cwd, '-c', 'core.hooksPath=' + os.devnull] + list(args),
                                input=input_, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                env=env or runner_git_env())
    except OSError as error:
        raise CheckoutError('git is not available: %s' % error)
    if result.returncode != 0:
        raise CheckoutError('git %s failed: %s' % (' '.join(args[:3]),
                                                   result.stderr.decode('utf-8', 'replace').strip()[:500]))
    return result.stdout


def _text(cwd, *args, env=None):
    return _git(cwd, *args, env=env).decode().strip()


def _overlay_tree(scratch, env, case_commit, overlay, watched):
    """The tree of case_commit with the overlay paths taken from the overlay revision."""
    _git(scratch, 'read-tree', case_commit, env=env)
    paths = list(OVERLAY_DIRS) + [p for p in watched if not any(p.startswith(d + '/') for d in OVERLAY_DIRS)]
    _git(scratch, 'rm', '--cached', '-r', '-q', '--ignore-unmatch', '--', *paths, env=env)
    for directory in OVERLAY_DIRS:
        entry = _git(scratch, 'ls-tree', overlay, '--', directory, env=env).decode().split()
        if entry and entry[1] == 'tree':
            _git(scratch, 'read-tree', '--prefix=%s/' % directory, entry[2], env=env)
    for path in paths[len(OVERLAY_DIRS):]:
        entry = _git(scratch, 'ls-tree', overlay, '--', path, env=env).decode().split(None, 3)
        if entry and entry[1] == 'blob':
            _git(scratch, 'update-index', '--add', '--cacheinfo', '%s,%s,%s' % (entry[0], entry[2], path), env=env)
    return _text(scratch, 'write-tree', env=env)


def _commit(scratch, env, tree, parent, message, date):
    dated = dict(env, GIT_AUTHOR_DATE=date, GIT_COMMITTER_DATE=date)
    return _text(scratch, 'commit-tree', tree, '-p', parent, '-m', message, env=dated)


def _touches_overlay(paths, watched):
    return sorted(p for p in paths if p in watched or any(p.startswith(d + '/') for d in OVERLAY_DIRS))


def _changed_paths(scratch, env, old, new):
    out = _git(scratch, 'diff-tree', '-r', '-z', '--name-only', '--no-commit-id', old, new, env=env)
    return [p.decode('utf-8', 'surrogateescape') for p in out.split(b'\0') if p]


def manifest(scratch, env, commit):
    """{path: (mode, blob sha)} of the tree of a commit (gitlinks are skipped)."""
    out = _git(scratch, 'ls-tree', '-r', '-z', '--full-tree', commit, env=env)
    files = {}
    for item in out.split(b'\0'):
        if not item:
            continue
        meta, path = item.split(b'\t', 1)
        mode, kind, sha = meta.decode().split()
        if kind == 'blob':
            files[path.decode('utf-8', 'surrogateescape')] = (mode, sha)
    return files


def build(root, pass_dir, case_state, overlay_rev, watched, patch=None):
    """Builds the bare repository and the checkout of one pass in pass_dir (an existing, empty directory created by
    the runner). case_state: {'commit': sha} or {'base_sha': sha, 'head_sha': sha}; overlay_rev: the commit whose
    agents, skills and watched files are put on the case; patch: the bytes of a patch applied as one more commit.
    Returns {'pass_dir', 'checkout', 'origin', 'base', 'head', 'overlay', 'manifest'}; CheckoutError otherwise."""
    pass_dir = os.path.realpath(pass_dir)
    scratch = os.path.join(pass_dir, '.build')
    origin = os.path.join(pass_dir, 'origin.git')
    work = os.path.join(pass_dir, 'checkout')
    env = runner_git_env(dict(IDENTITY, GIT_INDEX_FILE=os.path.join(scratch, 'index.build')))
    try:
        _git(pass_dir, 'init', '-q', scratch)
        diff_case = 'base_sha' in case_state
        commits = [case_state['base_sha'], case_state['head_sha']] if diff_case else [case_state['commit']]
        _git(scratch, 'fetch', '-q', '--no-tags', '--depth=1', 'file://' + root, *(commits + [overlay_rev]), env=env)
        date = _text(scratch, 'log', '-1', '--format=%cI', commits[-1], env=env)
        base_tree = _overlay_tree(scratch, env, commits[0], overlay_rev, watched)
        overlay = _commit(scratch, env, base_tree, commits[0], 'evals: overlay of the agents and watched files', date)
        base, head = overlay, overlay
        if diff_case:
            head_tree = _overlay_tree(scratch, env, commits[1], overlay_rev, watched)
            head = _commit(scratch, env, head_tree, overlay, 'evals: the task diff %s..%s' % tuple(c[:12] for c in commits),
                           date)
            original = _git(scratch, 'diff-tree', '-r', '--no-commit-id', commits[0], commits[1], env=env)
            if original != _git(scratch, 'diff-tree', '-r', '--no-commit-id', overlay, head, env=env):
                raise CheckoutError('the task diff %s..%s touches an overlay path (.claude/agents, .claude/skills or a '
                                    'watched file): such a case is rejected by evals.py lint' % tuple(commits))
        if patch is not None:
            _git(scratch, 'read-tree', head, env=env)
            _git(scratch, 'apply', '--cached', '--binary', '-', env=env, input_=patch)
            patched = _commit(scratch, env, _text(scratch, 'write-tree', env=env), head, 'evals: the patch of the case',
                              date)
            touched = _touches_overlay(_changed_paths(scratch, env, head, patched), watched)
            if touched:
                raise CheckoutError('the patch of the case touches overlay paths: %s' % ', '.join(touched))
            # the reviewed change: the patch alone on a commit case, the task diff and the patch on a diff case
            base, head = (overlay if diff_case else head), patched
        files = manifest(scratch, env, head)
        _git(pass_dir, 'init', '-q', '--bare', '--initial-branch=' + BRANCH, origin)
        _git(origin, 'config', 'receive.shallowUpdate', 'true')
        _git(scratch, 'push', '-q', origin, '%s:refs/heads/%s' % (head, BRANCH), env=env)
        _git(pass_dir, 'clone', '-q', '--no-hardlinks', origin, work)
        _git(work, 'config', 'remote.origin.pushurl', origin)
    finally:
        shutil.rmtree(scratch, ignore_errors=True)
    return {'pass_dir': pass_dir, 'checkout': work, 'origin': origin, 'base': base, 'head': head, 'overlay': overlay,
            'manifest': files}


# --------------------------------------------------------------------------------------------- after the agent

def _blob_sha(path, size):
    sha = hashlib.sha1(b'blob %d\0' % size)
    with open(path, 'rb') as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b''):
            sha.update(chunk)
    return sha.hexdigest()


def _worktree(root):
    """{path: (mode, sha or None)} of the working tree without .git; nothing is followed or opened except regular
    files (a FIFO planted by the agent must not block the runner)."""
    found = {}
    stack = ['']
    while stack:
        rel_dir = stack.pop()
        try:
            names = os.listdir(os.path.join(root, rel_dir) if rel_dir else root)
        except OSError:
            continue
        for name in names:
            rel = rel_dir + '/' + name if rel_dir else name
            if rel == '.git':
                continue
            full = os.path.join(root, rel)
            try:
                info = os.lstat(full)
            except OSError:
                continue
            if stat.S_ISDIR(info.st_mode):
                stack.append(rel)
            elif stat.S_ISLNK(info.st_mode):
                target = os.fsencode(os.readlink(full))
                found[rel] = ('120000', hashlib.sha1(b'blob %d\0' % len(target) + target).hexdigest())
            elif stat.S_ISREG(info.st_mode):
                mode = '100755' if info.st_mode & stat.S_IXUSR else '100644'
                try:
                    found[rel] = (mode, _blob_sha(full, info.st_size))
                except OSError:
                    found[rel] = (mode, None)
            else:
                found[rel] = ('special', None)
    return found


def tree_changes(checkout_dir, files):
    """The changed paths of the checkout against the manifest of its start commit: `A`, `M` or `D`, a tab and the
    path, sorted by path."""
    current = _worktree(checkout_dir)
    lines = []
    for path in sorted(set(current) | set(files)):
        if path not in files:
            lines.append('A\t' + path)
        elif path not in current:
            lines.append('D\t' + path)
        elif tuple(current[path]) != tuple(files[path]):
            lines.append('M\t' + path)
    return lines

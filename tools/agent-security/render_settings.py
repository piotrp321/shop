#!/usr/bin/env python3
"""Settings of a sandboxed Claude Code executor (`claude --settings`), rendered from executor-settings.json next to
this script. Python 3.9+, standard library only.

  render_settings.py --workdir DIR [--git-common-dir GITDIR] [--state-dir DIR]... [--read-dir DIR]...
                     [--deny-write PATH]... [--no-network] [--no-docker]

Prints the settings JSON on stdout and exits 0. The security rules (sandbox flags, denyRead, the deny rules,
disableAllHooks, the denyWrite paths derived from the directories) come from the template and this script; no option
removes them. Every option only adds to them:
  --workdir          the executor's working directory: read and write; its .ao and .git (the gitdir file of a
                     worktree) are write-protected.
  --git-common-dir   the .git directory of the main clone (git rev-parse --git-common-dir): read and write for
                     commits; hooks, config*, worktrees/*/config*, worktrees/*/commondir and worktrees/*/gitdir are
                     write-protected.
  --state-dir        read and write (a task directory of AO); also a working directory of the file tools.
  --read-dir         read only; also a working directory of the file tools.
  --deny-write       write-protected (absolute path; need not exist).
  --open-temp        read and write, re-opening a path under a denyRead temp region (the sandbox runtime scratch
                     /tmp/claude-<uid> and its /private form). Absolute, no glob, not /, ~ or a protected directory;
                     the symlink and existence checks are skipped (the path is a fixed, wrapper-computed temp path,
                     not user input, and /tmp is a symlink on macOS).
  --no-network       no GitHub domains and no allowLocalBinding (strictAllowlist stays: no network at all).
  --no-docker        no Docker socket.

Access options widen what the executor reads or writes, so their values come only from callers outside the sandbox,
from values the executor does not control. Each access path must be absolute, normalised, an existing directory with
no symlink component, not /, ~ or an ancestor of ~, and not equal to, below or above a protected directory (~/.ssh,
~/.kube, ~/.config, ~/Library, ~/.claude, ~/.docker, ~/.local/state/shop-ops); no glob or control characters. ~ is
$HOME. The template's ~ paths are made absolute in the sandbox lists.

Exit codes: 0 rendered; 1 a path or the template is invalid; 2 usage error. Nothing goes to stdout on an error.

Module interface (EVALS runs its cases in the same sandbox):
  render(template, home, workdir, git_common_dir=None, state_dirs=(), read_dirs=(), deny_writes=(), network=True,
         docker=True) -> dict
  load_template(path=TEMPLATE) -> dict
  SettingsError: an invalid path or template.
"""
import argparse
import copy
import json
import os
import re
import sys

TEMPLATE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'executor-settings.json')
PROTECTED = ('.ssh', '.kube', '.config', 'Library', '.claude', '.docker', '.local/state/shop-ops')
GIT_PROTECTED = ('hooks', 'config*', 'worktrees/*/config*', 'worktrees/*/commondir', 'worktrees/*/gitdir')
FORBIDDEN_CHARACTERS = re.compile(r'[*?\[\]{}\x00-\x1f\x7f  ]')
DOCKER_SOCKET_SUFFIX = 'docker.sock'


class SettingsError(Exception):
    """An access path or the template is not acceptable: nothing is rendered."""


def load_template(path=TEMPLATE):
    try:
        with open(path) as f:
            settings = json.load(f)
    except (OSError, ValueError) as e:
        raise SettingsError('nie można wczytać szablonu %s: %s' % (path, e))
    try:
        settings['sandbox']['filesystem']['denyRead']
        settings['sandbox']['network']
        settings['permissions']['deny']
    except (KeyError, TypeError):
        raise SettingsError('szablon %s nie ma sekcji sandbox.filesystem, sandbox.network i permissions' % path)
    return settings


def _inside(path, directory):
    return path == directory or path.startswith(directory.rstrip('/') + '/')


def access_path(path, home, option, git_dir=False):
    """The path when it may widen the executor's access; SettingsError otherwise."""
    if not isinstance(path, str) or not path:
        raise SettingsError('%s: pusta ścieżka' % option)
    if FORBIDDEN_CHARACTERS.search(path):
        raise SettingsError('%s %r: ścieżka ze znakiem globu albo sterującym' % (option, path))
    if not os.path.isabs(path):
        raise SettingsError('%s %s: ścieżka musi być bezwzględna' % (option, path))
    if os.path.normpath(path) != path or path.startswith('//'):
        raise SettingsError('%s %s: ścieżka nie jest znormalizowana' % (option, path))
    if not os.path.isdir(path):
        raise SettingsError('%s %s: brak katalogu' % (option, path))
    if os.path.realpath(path) != path:
        raise SettingsError('%s %s: ścieżka zawiera dowiązanie symboliczne (prowadzi do %s)'
                            % (option, path, os.path.realpath(path)))
    if path == '/' or _inside(home, path):
        raise SettingsError('%s %s: to katalog główny, katalog domowy albo jego przodek' % (option, path))
    for name in PROTECTED:
        protected = os.path.join(home, name)
        if _inside(path, protected) or _inside(protected, path):
            raise SettingsError('%s %s: nakłada się na chroniony katalog %s' % (option, path, protected))
    if git_dir and os.path.basename(path) != '.git':
        raise SettingsError('%s %s: to nie katalog .git' % (option, path))
    return path


def deny_path(path):
    if not isinstance(path, str) or not os.path.isabs(path) or FORBIDDEN_CHARACTERS.search(path):
        raise SettingsError('--deny-write %r: ścieżka musi być bezwzględna, bez znaków globu i sterujących' % (path,))
    return os.path.normpath(path)


def temp_path(path, home):
    """A fixed, wrapper-computed temp path to re-open (no symlink or existence check; /tmp is a symlink on macOS)."""
    if not isinstance(path, str) or not os.path.isabs(path) or FORBIDDEN_CHARACTERS.search(path):
        raise SettingsError('--open-temp %r: ścieżka musi być bezwzględna, bez znaków globu i sterujących' % (path,))
    path = os.path.normpath(path)
    if path == '/' or _inside(home, path):
        raise SettingsError('--open-temp %s: to katalog główny, domowy albo jego przodek' % path)
    for name in PROTECTED:
        protected = os.path.join(home, name)
        if _inside(path, protected) or _inside(protected, path):
            raise SettingsError('--open-temp %s: nakłada się na chroniony katalog %s' % (path, protected))
    return path


def _expand(path, home):
    if path == '~':
        return home
    if path.startswith('~/'):
        return os.path.join(home, path[2:])
    return path


def _add(items, values):
    for value in values:
        if value not in items:
            items.append(value)


def render(template, home, workdir, git_common_dir=None, state_dirs=(), read_dirs=(), deny_writes=(), temp_dirs=(),
           network=True, docker=True):
    """The settings of one executor; the template is not changed. SettingsError for an invalid path."""
    home = os.path.realpath(home)
    workdir = access_path(workdir, home, '--workdir')
    git_dir = access_path(git_common_dir, home, '--git-common-dir', git_dir=True) if git_common_dir else None
    state = [access_path(p, home, '--state-dir') for p in state_dirs]
    read = [access_path(p, home, '--read-dir') for p in read_dirs]
    denied = [deny_path(p) for p in deny_writes]
    temp = [temp_path(p, home) for p in temp_dirs]

    settings = copy.deepcopy(template)
    sandbox = settings['sandbox']
    fs = sandbox.setdefault('filesystem', {})
    net = sandbox.setdefault('network', {})
    permissions = settings.setdefault('permissions', {})
    for key in ('denyRead', 'allowRead', 'allowWrite', 'denyWrite'):
        fs[key] = [_expand(p, home) for p in fs.get(key, [])]
    net['allowUnixSockets'] = [_expand(p, home) for p in net.get('allowUnixSockets', [])]

    _add(fs['allowRead'], [workdir] + ([git_dir] if git_dir else []) + state + read + temp)
    _add(fs['allowWrite'], [workdir] + ([git_dir] if git_dir else []) + state + temp)
    protected = [os.path.join(workdir, '.ao')]
    if git_dir != os.path.join(workdir, '.git'):
        protected.append(os.path.join(workdir, '.git'))
    if git_dir:
        protected.extend(os.path.join(git_dir, name) for name in GIT_PROTECTED)
    protected.extend(denied)
    _add(fs['denyWrite'], protected)

    deny = permissions.setdefault('deny', [])
    for path in protected:
        _add(deny, ['Edit(/%s)' % path, 'Edit(/%s/**)' % path])
    _add(permissions.setdefault('additionalDirectories', []), state + read)

    if not network:
        net['allowedDomains'] = []
        net['allowLocalBinding'] = False
    if not docker:
        net['allowUnixSockets'] = [p for p in net['allowUnixSockets'] if not p.endswith(DOCKER_SOCKET_SUFFIX)]
    return settings


class _Parser(argparse.ArgumentParser):
    def error(self, message):
        self.print_usage(sys.stderr)
        print('render_settings.py: %s' % message, file=sys.stderr)
        sys.exit(2)


def main(argv=None):
    parser = _Parser(description='Settings of a sandboxed executor, rendered from executor-settings.json.',
                     allow_abbrev=False)
    parser.add_argument('--workdir', required=True)
    parser.add_argument('--git-common-dir')
    parser.add_argument('--state-dir', action='append', default=[])
    parser.add_argument('--read-dir', action='append', default=[])
    parser.add_argument('--deny-write', action='append', default=[])
    parser.add_argument('--open-temp', action='append', default=[])
    parser.add_argument('--no-network', action='store_true')
    parser.add_argument('--no-docker', action='store_true')
    args = parser.parse_args(argv)
    try:
        settings = render(load_template(), os.path.expanduser('~'), args.workdir, args.git_common_dir,
                          args.state_dir, args.read_dir, args.deny_write, args.open_temp,
                          network=not args.no_network, docker=not args.no_docker)
    except SettingsError as e:
        print('render_settings.py: %s' % e, file=sys.stderr)
        return 1
    print(json.dumps(settings, indent=None, separators=(',', ':')))
    return 0


if __name__ == '__main__':
    sys.exit(main())

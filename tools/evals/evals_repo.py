"""Access to the files of a repository, from the disk or from a git revision, and the git helpers of tools/evals.
Python 3.9+, standard library only.

A source answers two questions: the bytes of a path (None when it does not exist) and the files below a directory.
The fingerprint of a suite is computed from a source, so the same code gives the digest of the working tree and of any
revision (the base and the head of a pull request).
"""
import os
import re
import subprocess

CACHE_DIRS = ('__pycache__',)
SHA_RE = re.compile(r'^[0-9a-f]{40}$')


class GitError(Exception):
    """A git command failed or git is not available."""


def git(root, *args, check=True):
    """Runs `git -C root args` and returns (exit code, stdout bytes). With check, a failure raises GitError."""
    try:
        result = subprocess.run(['git', '-C', root] + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    except OSError as error:
        raise GitError('git is not available: %s' % error)
    if check and result.returncode != 0:
        raise GitError('git %s failed: %s' % (' '.join(args), result.stderr.decode('utf-8', 'replace').strip()))
    return result.returncode, result.stdout


def is_git_repository(root):
    try:
        code, out = git(root, 'rev-parse', '--is-inside-work-tree', check=False)
    except GitError:
        return False
    return code == 0 and out.strip() == b'true'


def is_shallow(root):
    code, out = git(root, 'rev-parse', '--is-shallow-repository', check=False)
    return code == 0 and out.strip() == b'true'


def head_commit(root):
    return git(root, 'rev-parse', '--verify', 'HEAD^{commit}')[1].decode().strip()


def resolve_commit(root, rev):
    """The full SHA of a revision; GitError when it is not a commit of the repository."""
    code, out = git(root, 'rev-parse', '--verify', '--quiet', rev + '^{commit}', check=False)
    if code != 0:
        raise GitError('%r is not a commit of the repository' % rev)
    return out.decode().strip()


def commit_exists(root, sha):
    return git(root, 'cat-file', '-e', sha + '^{commit}', check=False)[0] == 0


def _skipped(name):
    return name.startswith('.') or name in CACHE_DIRS


class DiskSource:
    """The files of the working tree. Dotfiles and cache directories are not part of any listing (so .DS_Store never
    changes a fingerprint)."""

    description = 'the working tree'

    def __init__(self, root):
        self.root = root

    def read(self, rel):
        try:
            with open(os.path.join(self.root, rel), 'rb') as handle:
                return handle.read()
        except (FileNotFoundError, NotADirectoryError, IsADirectoryError):
            return None

    def list_files(self, rel_dir):
        """Paths (relative to the root, with `/`) of the files below a directory, sorted."""
        base = os.path.join(self.root, rel_dir)
        found = []
        for current, dirs, files in os.walk(base):
            dirs[:] = [d for d in dirs if not _skipped(d)]
            for name in files:
                if not _skipped(name):
                    found.append(os.path.relpath(os.path.join(current, name), self.root).replace(os.sep, '/'))
        return sorted(found)


class GitSource:
    """The files of a git revision (commit SHA or any revision git understands)."""

    def __init__(self, root, rev):
        self.root = root
        self.rev = rev
        self.description = 'revision ' + rev

    def read(self, rel):
        code, out = git(self.root, 'show', '%s:%s' % (self.rev, rel), check=False)
        return out if code == 0 else None

    def list_files(self, rel_dir):
        code, out = git(self.root, 'ls-tree', '-r', '-z', '--name-only', self.rev, '--', rel_dir, check=False)
        if code != 0:
            return []
        paths = [p.decode('utf-8', 'surrogateescape') for p in out.split(b'\0') if p]
        return sorted(p for p in paths if not any(_skipped(part) for part in p.split('/')))

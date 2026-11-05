#!/usr/bin/env python3
"""Claude Code binary of the version pinned by AO, for `ao run --claude-bin`. Python 3.9+, standard library only.

  claude_pin.py [--ao-dir DIR] [--candidate TEMPLATE]...

Reads the pinned version (`LifecycleConfig.claude_version`) from origin/main:orchestrator/lifecycle.py of the AO
checkout (no fetch: /ao-run fetches and checks the checkout before), then checks the candidate paths in order
(TEMPLATE with `{version}`; by default the native installer's ~/.local/share/claude/versions/{version} and the
Homebrew cask's /opt/homebrew/Caskroom/claude-code/{version}/claude). The first executable file whose `--version`
output starts with the pinned version wins: its absolute path is printed, exit 0.

Exit codes: 0 the binary is there; 1 the pin cannot be read (git failed, the file or the field is missing or
unreadable); 2 the binary is missing, not executable, fails or reports another version. The reason, with how to
install the pinned version, goes to stderr.
"""
import argparse
import ast
import os
import re
import signal
import subprocess
import sys

DEFAULT_AO_DIR = os.path.join(os.path.expanduser('~'), 'Development', 'claude', 'agentic-orchestrator')
DEFAULT_CANDIDATES = (os.path.join(os.path.expanduser('~'), '.local', 'share', 'claude', 'versions', '{version}'),
                      '/opt/homebrew/Caskroom/claude-code/{version}/claude')
LIFECYCLE = 'origin/main:orchestrator/lifecycle.py'
VERSION = re.compile(r'\d+\.\d+\.\d+')
VERSION_TIMEOUT_S = 60


class PinError(Exception):
    """The pinned version cannot be read from AO."""


class BinaryError(Exception):
    """The binary of the pinned version is not usable."""


def pinned_version(ao_dir):
    args = ['git', '-C', ao_dir, 'show', LIFECYCLE]
    try:
        result = subprocess.run(args, capture_output=True, text=True)
    except OSError as e:
        raise PinError('%s: %s' % (' '.join(args), e.strerror or e))
    if result.returncode != 0:
        raise PinError('%s: %s' % (' '.join(args), result.stderr.strip() or 'exit code %d' % result.returncode))
    try:
        tree = ast.parse(result.stdout)
    except SyntaxError as e:
        raise PinError('nieczytelny %s: %s' % (LIFECYCLE, e.msg))
    values = [node.value.value
              for cls in tree.body if isinstance(cls, ast.ClassDef) and cls.name == 'LifecycleConfig'
              for node in cls.body
              if isinstance(node, ast.AnnAssign) and isinstance(node.target, ast.Name)
              and node.target.id == 'claude_version'
              and isinstance(node.value, ast.Constant) and isinstance(node.value.value, str)]
    if len(values) != 1:
        raise PinError('brak jednego LifecycleConfig.claude_version w %s' % LIFECYCLE)
    if not VERSION.fullmatch(values[0]):
        raise PinError('LifecycleConfig.claude_version w %s to nie wersja: %r' % (LIFECYCLE, values[0]))
    return values[0]


def _version_output(path):
    """(exit code, stdout, stderr) of `<path> --version`; the process group is killed on timeout."""
    try:
        process = subprocess.Popen([path, '--version'], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                                   start_new_session=True)
    except OSError as e:
        raise BinaryError('%s --version: %s' % (path, e.strerror or e))
    try:
        out, err = process.communicate(timeout=VERSION_TIMEOUT_S)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.communicate()
        raise BinaryError('%s --version nie odpowiedział w %g s' % (path, VERSION_TIMEOUT_S))
    return process.returncode, out, err


def check_binary(path, version):
    if not os.path.isfile(path):
        raise BinaryError('brak binarki %s' % path)
    if not os.access(path, os.X_OK):
        raise BinaryError('binarka %s nie jest wykonywalna' % path)
    code, out, err = _version_output(path)
    words = out.split()
    if code != 0 or not words or words[0] != version:
        raise BinaryError('%s --version zgłasza %r (kod %d)' % (path, (out.strip() or err.strip())[:80], code))
    return path


def pinned_binary(candidates, version):
    """Absolute path of the first candidate that is the pinned version; BinaryError with every reason otherwise."""
    reasons = []
    for template in candidates:
        try:
            return check_binary(os.path.abspath(template.replace('{version}', version)), version)
        except BinaryError as e:
            reasons.append(str(e))
    raise BinaryError('; '.join(reasons))


def _candidate(text):
    if '{version}' not in text:
        raise argparse.ArgumentTypeError('no {version} in %r' % text)
    return text


def main(argv=None):
    parser = argparse.ArgumentParser(description='Claude Code binary of the version pinned by AO')
    parser.add_argument('--ao-dir', default=DEFAULT_AO_DIR)
    parser.add_argument('--candidate', action='append', type=_candidate,
                        help='path of the binary with {version}; repeatable, replaces the defaults')
    args = parser.parse_args(argv)
    candidates = args.candidate or DEFAULT_CANDIDATES
    try:
        version = pinned_version(args.ao_dir)
    except PinError as e:
        print('claude_pin: %s' % e, file=sys.stderr)
        return 1
    try:
        path = pinned_binary(candidates, version)
    except BinaryError as e:
        print('claude_pin: przypięta wersja AO to %s, żadna binarka jej nie zgłasza (%s). Zainstaluj ją: '
              '`claude install %s --force` (instalator natywny zapisuje ją w %s), potem uruchom ten skrypt ponownie.'
              % (version, e, version, os.path.dirname(candidates[0].replace('{version}', version))), file=sys.stderr)
        return 2
    print(path)
    return 0


if __name__ == '__main__':
    sys.exit(main())

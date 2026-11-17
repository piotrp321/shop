"""Whether a test may be skipped because the executor's sandbox does not allow what it needs. Python 3.9+, standard
library only.

The executor's sandbox allows neither writing the system temp (/private/tmp on macOS: the executor works in an
isolated TMPDIR), nor binding Unix sockets (allowUnixSockets lists only Docker's), nor running ps. A test that starts
the real claude-agent (which makes /private/tmp/agent.XXXXXX), makes run directories there, listens on a Unix socket
or finds processes through ps cannot run in that sandbox. Such a test calls require_system_tmp(),
require_unix_sockets() or require_command() and is skipped, with the reason, only when all of these hold:
  - AGENT_EXECUTOR_SANDBOX is 1, which claude-agent sets for the executor (the sandbox is recognised, not guessed),
  - the thing really cannot be done (a directory cannot be made in the system temp; a socket cannot be bound),
  - CI is not set.
On a host and in CI the test always runs; an unusable /tmp or socket there is a failure of the test, not a skip.
"""
import os
import socket
import subprocess
import tempfile

MARKER = 'AGENT_EXECUTOR_SANDBOX'
SYSTEM_TMP = os.path.realpath('/tmp')   # /private/tmp on macOS


def writable(parent=SYSTEM_TMP):
    """True when a directory can be made (and removed) in parent."""
    try:
        os.rmdir(tempfile.mkdtemp(prefix='agent.writable-', dir=parent))
    except OSError:
        return False
    return True


def can_bind_unix_socket(directory=None):
    """True when a Unix socket can be bound (and is removed again) in directory (default: a new directory in the
    temp of the process)."""
    owned = None
    path = None
    try:
        if directory is None:
            directory = owned = tempfile.mkdtemp(prefix='sk-')
        path = os.path.join(directory, 'probe.sock')
        sock = socket.socket(socket.AF_UNIX)
        try:
            sock.bind(path)
        finally:
            sock.close()
        return True
    except OSError:
        return False
    finally:
        if path and os.path.lexists(path):
            os.unlink(path)
        if owned:
            os.rmdir(owned)


def can_run(command):
    """True when the command can be started (whatever its exit status)."""
    try:
        subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)
    except (OSError, subprocess.SubprocessError):
        return False
    return True


def _in_executor_sandbox(environ):
    return environ.get(MARKER) == '1' and not environ.get('CI')


def tmp_skip_reason(environ=None, parent=SYSTEM_TMP):
    """The reason to skip a test that needs the system temp, or None when it must run."""
    environ = os.environ if environ is None else environ
    if not _in_executor_sandbox(environ) or writable(parent):
        return None
    return ('the executor sandbox (%s=1) does not allow writing %s; this test runs on a host and in CI'
            % (MARKER, parent))


def socket_skip_reason(environ=None, directory=None):
    """The reason to skip a test that binds a Unix socket, or None when it must run."""
    environ = os.environ if environ is None else environ
    if not _in_executor_sandbox(environ) or can_bind_unix_socket(directory):
        return None
    return ('the executor sandbox (%s=1) does not allow binding Unix sockets; this test runs on a host and in CI'
            % MARKER)


def command_skip_reason(environ=None, command=('ps', '-A', '-o', 'pid=')):
    """The reason to skip a test that runs command, or None when it must run."""
    environ = os.environ if environ is None else environ
    if not _in_executor_sandbox(environ) or can_run(list(command)):
        return None
    return ('the executor sandbox (%s=1) does not allow running %s; this test runs on a host and in CI'
            % (MARKER, command[0]))


def require_system_tmp(testcase, environ=None, parent=SYSTEM_TMP):
    """Skips testcase with the reason inside the executor sandbox; returns normally anywhere else."""
    reason = tmp_skip_reason(environ, parent)
    if reason:
        testcase.skipTest(reason)


def require_unix_sockets(testcase, environ=None, directory=None):
    """Skips testcase with the reason inside the executor sandbox; returns normally anywhere else."""
    reason = socket_skip_reason(environ, directory)
    if reason:
        testcase.skipTest(reason)


def require_command(testcase, command=('ps', '-A', '-o', 'pid='), environ=None):
    """Skips testcase with the reason inside the executor sandbox; returns normally anywhere else."""
    reason = command_skip_reason(environ, command)
    if reason:
        testcase.skipTest(reason)

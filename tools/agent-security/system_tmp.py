"""Whether a test that needs the system temp (/private/tmp on macOS) may be skipped. Python 3.9+, standard library only.

The executor's sandbox does not allow writing /private/tmp: the executor works in an isolated TMPDIR. A test that
starts the real claude-agent (which makes /private/tmp/agent.XXXXXX) or makes run directories there cannot run in
that sandbox. Such a test calls require() and is skipped, with the reason, only when all of these hold:
  - AGENT_EXECUTOR_SANDBOX is 1, which claude-agent sets for the executor (the sandbox is recognised, not guessed),
  - the system temp cannot be written (a directory cannot be made in it),
  - CI is not set.
On a host and in CI the test always runs; an unusable /tmp there is a failure of the test, not a skip.
"""
import os
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


def skip_reason(environ=None, parent=SYSTEM_TMP):
    """The reason to skip a test that needs the system temp, or None when it must run."""
    environ = os.environ if environ is None else environ
    if environ.get(MARKER) != '1' or environ.get('CI'):
        return None
    if writable(parent):
        return None
    return ('the executor sandbox (%s=1) does not allow writing %s; this test runs on a host and in CI'
            % (MARKER, parent))


def require(testcase, environ=None, parent=SYSTEM_TMP):
    """Skips testcase with the reason inside the executor sandbox; returns normally anywhere else."""
    reason = skip_reason(environ, parent)
    if reason:
        testcase.skipTest(reason)

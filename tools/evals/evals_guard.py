"""The guarded environment of an executor and its sandbox settings (spec: Sandbox; threat model paths 2, 3, 4, 7, 8).
Python 3.9+, standard library only.

Layout of a pass directory (the only --state-dir of the sandbox):

  <pass>/runner/         written by the runner only and write-protected in the sandbox (--deny-write): the stubs
                         (bin/gh, bin/ssh), the global git config, the empty GH_CONFIG_DIR, the gh data of the case,
                         the sandbox settings and, during the run, the stream of events and the tool_use calls;
  <pass>/gh.log          every call of the gh stub, one JSON object per line;
  <pass>/ssh.log         every attempt to reach a remote: calls of the ssh stub (GIT_SSH_COMMAND) and git commands
                         refused because of GIT_ALLOW_PROTOCOL=file (from the git trace, collected after the pass);
  <pass>/tmp/            TMPDIR of the executor;
  <pass>/git-trace.jsonl the trace2 events of the git commands of the agent (GIT_TRACE2_EVENT).

The environment is built from an allowlist, never copied: no token, no agent socket, no GIT_CONFIG_* override of the
caller and no variable of a paid API or a model override reaches the executor.
"""
import json
import os
import shlex
import stat
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
RENDER_SCRIPT = os.path.join(os.path.dirname(HERE), 'agent-security', 'render_settings.py')
RENDER_TIMEOUT_S = 60

# Passed from the environment of the runner (nothing else is): the user's identity and locale for the CLI (the
# subscription login lives in the Keychain and ~/.claude, not in a variable) and the proxy and CA settings the CLI
# may need to reach its own API. The agent's commands have no network in the sandbox anyway.
PASSTHROUGH = ('HOME', 'USER', 'LOGNAME', 'SHELL', 'LANG', 'LC_ALL', 'LC_CTYPE', 'LC_MESSAGES', 'TERM', 'TZ',
               'CLAUDE_CONFIG_DIR', 'HTTPS_PROXY', 'HTTP_PROXY', 'NO_PROXY', 'https_proxy', 'http_proxy', 'no_proxy',
               'SSL_CERT_FILE', 'NODE_EXTRA_CA_CERTS')
DEFAULT_PATH = '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin'
# Paid API access, other providers and model overrides (the runner never overrides the model of the agent): never
# passed, and a final check refuses an environment that has one.
PAID_API_VARIABLES = (
    'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'ANTHROPIC_BASE_URL', 'ANTHROPIC_CUSTOM_HEADERS',
    'ANTHROPIC_BEDROCK_BASE_URL', 'ANTHROPIC_VERTEX_BASE_URL', 'ANTHROPIC_VERTEX_PROJECT_ID',
    'ANTHROPIC_FOUNDRY_API_KEY', 'ANTHROPIC_FOUNDRY_RESOURCE', 'ANTHROPIC_FOUNDRY_BASE_URL',
    'ANTHROPIC_MODEL', 'ANTHROPIC_SMALL_FAST_MODEL', 'ANTHROPIC_DEFAULT_OPUS_MODEL', 'ANTHROPIC_DEFAULT_SONNET_MODEL',
    'ANTHROPIC_DEFAULT_HAIKU_MODEL', 'CLAUDE_CODE_SUBAGENT_MODEL',
    'CLAUDE_CODE_USE_BEDROCK', 'CLAUDE_CODE_USE_VERTEX', 'CLAUDE_CODE_USE_FOUNDRY', 'CLAUDE_CODE_SKIP_BEDROCK_AUTH',
    'CLAUDE_CODE_SKIP_VERTEX_AUTH', 'CLAUDE_CODE_OAUTH_TOKEN', 'CLAUDE_CODE_API_KEY_HELPER_TTL_MS',
    'AWS_BEARER_TOKEN_BEDROCK', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_PROFILE',
    'AWS_REGION', 'CLOUD_ML_REGION', 'GOOGLE_APPLICATION_CREDENTIALS', 'OPENAI_API_KEY', 'OPENAI_BASE_URL',
)
FORBIDDEN = ('GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN', 'SSH_AUTH_SOCK',
             'GIT_ASKPASS', 'SSH_ASKPASS', 'CLAUDECODE') + PAID_API_VARIABLES
TRACE_LIMIT = 32 << 20
BLOCKED_TRANSPORT = "transport '%s' not allowed"

GITCONFIG = """# The global git config of an eval pass (GIT_CONFIG_GLOBAL): an identity for commits and no credential helper.
[user]
\tname = Evals agent
\temail = evals-agent@example.invalid
[credential]
\thelper =
[init]
\tdefaultBranch = main
"""

GH_STUB = r'''#!%(python)s -I
"""gh of an eval pass: serves the gh data of the case, fails for every other call; every call goes to gh.log."""
import datetime, json, sys
args = sys.argv[1:]
with open(%(data)r, encoding='utf-8') as handle:
    responses = json.load(handle)
answer = next((r for r in responses if r.get('args') == args), None)
code = int(answer.get('exit_code', 0)) if answer else 1
try:
    with open(%(log)r, 'a', encoding='utf-8') as log:
        log.write(json.dumps({'args': args, 'served': answer is not None, 'exit_code': code,
                              'time': datetime.datetime.utcnow().strftime('%%Y-%%m-%%dT%%H:%%M:%%SZ')}) + '\n')
except OSError:
    pass
if answer is None:
    sys.stderr.write('gh (evals stub): no case data for `gh %%s`; GitHub is not reachable in an eval pass\n'
                     %% ' '.join(args))
    sys.exit(1)
sys.stdout.write(answer.get('stdout', ''))
sys.exit(code)
'''

SSH_STUB = r'''#!%(python)s -I
"""ssh of an eval pass (GIT_SSH_COMMAND): never connects; every attempt goes to ssh.log."""
import datetime, json, sys
try:
    with open(%(log)r, 'a', encoding='utf-8') as log:
        log.write(json.dumps({'via': 'ssh', 'args': sys.argv[1:],
                              'time': datetime.datetime.utcnow().strftime('%%Y-%%m-%%dT%%H:%%M:%%SZ')}) + '\n')
except OSError:
    pass
sys.stderr.write('ssh (evals stub): no network in an eval pass\n')
sys.exit(255)
'''


class SandboxError(Exception):
    """The sandbox settings cannot be rendered or are not a valid AGENT-SECURITY profile: nothing may start."""


class GuardError(Exception):
    """The environment of the executor is not safe."""


def load_json(path):
    with open(path, encoding='utf-8') as handle:
        return json.load(handle)


def replace_file(path, content, mode=0o644):
    """Writes a new regular file at path; whatever is there (a file, a symlink, a FIFO planted by the agent) is
    removed first and never followed."""
    try:
        info = os.lstat(path)
    except FileNotFoundError:
        info = None
    if info is not None:
        if stat.S_ISDIR(info.st_mode):
            import shutil
            shutil.rmtree(path)
        else:
            os.unlink(path)
    data = content.encode('utf-8') if isinstance(content, str) else content
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), mode)
    with os.fdopen(fd, 'wb') as handle:
        handle.write(data)


def _python():
    exe = os.path.realpath(sys.executable)
    if not exe or any(c.isspace() for c in exe):
        return '/usr/bin/env python3'
    return exe


def prepare(pass_dir, gh_responses):
    """Creates the runner directory, the stubs, the logs and the temp directory of a pass; returns the layout."""
    pass_dir = os.path.realpath(pass_dir)
    runner = os.path.join(pass_dir, 'runner')
    layout = {'pass_dir': pass_dir, 'runner': runner, 'bin': os.path.join(runner, 'bin'),
              'gitconfig': os.path.join(runner, 'gitconfig'), 'gh_config': os.path.join(runner, 'gh-config'),
              'gh_data': os.path.join(runner, 'gh-data.json'), 'docker_socket': os.path.join(runner, 'docker.sock'),
              'gh_log': os.path.join(pass_dir, 'gh.log'), 'ssh_log': os.path.join(pass_dir, 'ssh.log'),
              'tmp': os.path.join(pass_dir, 'tmp'), 'trace': os.path.join(pass_dir, 'git-trace.jsonl')}
    for directory in (runner, layout['bin'], layout['gh_config'], layout['tmp']):
        os.makedirs(directory, exist_ok=True)
    replace_file(layout['gitconfig'], GITCONFIG)
    replace_file(layout['gh_data'], json.dumps(gh_responses, ensure_ascii=False))
    values = {'python': _python(), 'data': layout['gh_data'], 'log': layout['gh_log']}
    replace_file(os.path.join(layout['bin'], 'gh'), GH_STUB % values, 0o755)
    replace_file(os.path.join(layout['bin'], 'ssh'), SSH_STUB % dict(values, log=layout['ssh_log']), 0o755)
    for log in (layout['gh_log'], layout['ssh_log']):
        replace_file(log, b'')
    return layout


def environment(layout, base=None):
    """The environment of the executor of a pass, built from the allowlist PASSTHROUGH and the guards."""
    base = os.environ if base is None else base
    env = {key: base[key] for key in PASSTHROUGH if key in base}
    env['PATH'] = layout['bin'] + os.pathsep + base.get('PATH', DEFAULT_PATH)
    env.update({
        'TMPDIR': layout['tmp'],
        'GH_CONFIG_DIR': layout['gh_config'],
        'GH_PROMPT_DISABLED': '1',
        'GIT_SSH_COMMAND': shlex.quote(os.path.join(layout['bin'], 'ssh')),
        'GIT_CONFIG_GLOBAL': layout['gitconfig'],
        'GIT_CONFIG_NOSYSTEM': '1',
        'GIT_TERMINAL_PROMPT': '0',
        'GIT_ALLOW_PROTOCOL': 'file',
        'GIT_TRACE2_EVENT': layout['trace'],
        'DOCKER_HOST': 'unix://' + layout['docker_socket'],
    })
    leaked = sorted(key for key in env if key in FORBIDDEN)
    if leaked:
        raise GuardError('the executor environment would have %s' % ', '.join(leaked))
    return env


def _regular_file_text(path, limit):
    """The text of a regular file (not a symlink, FIFO or directory), at most limit bytes; '' otherwise."""
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0))
    except OSError:
        return ''
    with os.fdopen(fd, 'rb') as handle:
        if not stat.S_ISREG(os.fstat(handle.fileno()).st_mode):
            return ''
        return handle.read(limit).decode('utf-8', 'replace')


def collect_git_attempts(layout):
    """After the pass: the git commands refused because of GIT_ALLOW_PROTOCOL (they never reach the ssh stub) are
    added to ssh.log from the trace2 events, with their arguments. ssh.log is rewritten as a regular file (a symlink
    planted by the agent is not followed). Returns the added entries."""
    starts, added = {}, []
    for line in _regular_file_text(layout['trace'], TRACE_LIMIT).splitlines():
        try:
            event = json.loads(line)
        except ValueError:
            continue
        if not isinstance(event, dict):
            continue
        if event.get('event') == 'start' and isinstance(event.get('argv'), list):
            starts[event.get('sid')] = [str(a) for a in event['argv']]
        elif event.get('event') == 'error' and event.get('fmt') == BLOCKED_TRANSPORT:
            message = str(event.get('msg', ''))
            transport = message.split("'")[1] if message.count("'") >= 2 else message
            added.append({'via': 'git', 'transport': transport, 'args': starts.get(event.get('sid'), []),
                          'time': event.get('time')})
    existing = _regular_file_text(layout['ssh_log'], TRACE_LIMIT)
    lines = [line for line in existing.splitlines() if line.strip()]
    lines.extend(json.dumps(entry) for entry in added)
    replace_file(layout['ssh_log'], ''.join(line + '\n' for line in lines))
    return added


# ------------------------------------------------------------------------------------------------- the sandbox

def validate_profile(settings):
    """Problems of rendered settings for a real pass: the sandbox on, stopping when it is unavailable, no commands
    outside it, no network domain and no Docker socket."""
    if not isinstance(settings, dict) or not isinstance(settings.get('sandbox'), dict):
        return ['the settings have no sandbox section']
    sandbox = settings['sandbox']
    problems = []
    if sandbox.get('enabled') is not True:
        problems.append('sandbox.enabled is not true')
    if sandbox.get('failIfUnavailable') is not True:
        problems.append('sandbox.failIfUnavailable is not true (the CLI would run without the sandbox)')
    if sandbox.get('allowUnsandboxedCommands') is not False:
        problems.append('sandbox.allowUnsandboxedCommands is not false (commands could run outside the sandbox)')
    network = sandbox.get('network')
    if not isinstance(network, dict) or network.get('allowedDomains') != []:
        problems.append('sandbox.network.allowedDomains is not an empty list (an eval pass has no network)')
    else:
        sockets = network.get('allowUnixSockets') or []
        if not isinstance(sockets, list) or any(str(s).endswith('docker.sock') for s in sockets):
            problems.append('sandbox.network.allowUnixSockets opens the docker socket')
        if network.get('allowLocalBinding'):
            problems.append('sandbox.network.allowLocalBinding is on')
    return problems


def render_settings(workdir, state_dir, deny_writes, script=None):
    """The sandbox settings of one pass from the AGENT-SECURITY profile (render_settings.py, run with this Python).
    Every path is the runner's own and resolved with realpath. SandboxError when the script is missing, fails or
    gives settings that are not a valid profile for a real pass."""
    script = script or RENDER_SCRIPT
    if not os.path.isfile(script):
        raise SandboxError('the AGENT-SECURITY profile is missing: %s does not exist' % script)
    args = [sys.executable, '-I', script, '--workdir', os.path.realpath(workdir),
            '--state-dir', os.path.realpath(state_dir)]
    for path in deny_writes:
        args += ['--deny-write', os.path.realpath(path)]
    args += ['--no-network', '--no-docker']
    try:
        result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True,
                                timeout=RENDER_TIMEOUT_S, env={k: v for k, v in os.environ.items()
                                                               if k in PASSTHROUGH or k == 'PATH'})
    except (OSError, subprocess.TimeoutExpired) as error:
        raise SandboxError('render_settings.py could not run: %s' % error)
    if result.returncode != 0:
        raise SandboxError('render_settings.py failed (exit %d): %s' % (result.returncode, result.stderr.strip()[:500]))
    try:
        settings = json.loads(result.stdout)
    except ValueError:
        raise SandboxError('render_settings.py did not print JSON settings')
    problems = validate_profile(settings)
    if problems:
        raise SandboxError('the sandbox settings are not a valid AGENT-SECURITY profile: %s' % '; '.join(problems))
    return settings

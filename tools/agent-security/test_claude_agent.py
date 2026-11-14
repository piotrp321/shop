"""Tests of the claude-agent wrapper: python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

Each test builds a temporary HOME (resolved, without symlinks) with a fake AO home, a local bare `origin`, a main
clone and a task worktree, and puts a fake `security` first in PATH. The fake Claude binary records its arguments and
environment; git is the real one (the origin is local, so `git ls-remote origin` works offline).
"""
import importlib.util
import json
import os
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('system_tmp', os.path.join(HERE, 'system_tmp.py'))
system_tmp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(system_tmp)
WRAPPER = os.path.join(HERE, 'claude-agent')
OVERLAY = os.path.join(HERE, 'bin', 'mktemp')
TOKEN = 'github_pat_fake_executor_token'

FAKE_CLAUDE = '''#!%s
import json, os, sys, time
TEMP_VARIABLES = ('TMPDIR', 'BUILDX_CONFIG', 'GH_CONFIG_DIR', 'XDG_CACHE_HOME')
stdin = sys.stdin.read() if os.environ.get('FAKE_READ_STDIN') else None
fds = {}
for fd in range(3, 10):
    try:
        os.lseek(fd, 0, 0)
        fds[str(fd)] = os.read(fd, 100).decode()
    except OSError:
        pass
claude_tmp = os.environ.get('CLAUDE_CODE_TMPDIR')
claude_tmp_state = None
if claude_tmp and os.path.lexists(claude_tmp):
    info = os.lstat(claude_tmp)
    claude_tmp_state = {'mode': info.st_mode & 0o7777, 'uid': info.st_uid, 'link': os.path.islink(claude_tmp),
                        'entries': sorted(os.listdir(claude_tmp)) if os.path.isdir(claude_tmp) else None}
mktemp = None
if os.environ.get('FAKE_RUN_MKTEMP'):
    import subprocess
    mktemp = {'command': subprocess.run(['sh', '-c', 'command -v mktemp'], capture_output=True, text=True).stdout.strip()}
    for key, args in (('file', ['mktemp']), ('dir', ['mktemp', '-d']), ('prefix', ['mktemp', '-t', 'probe'])):
        result = subprocess.run(args, capture_output=True, text=True)
        path = result.stdout.strip()
        mktemp[key] = {'code': result.returncode, 'path': path, 'stderr': result.stderr, 'exists': os.path.exists(path)}
with open(os.environ['FAKE_CLAUDE_RECORD'], 'w') as f:
    json.dump({'argv': sys.argv[1:], 'env': dict(os.environ), 'pid': os.getpid(), 'stdin': stdin, 'fds': fds,
               'dirs': {k: os.path.isdir(os.environ.get(k, '')) for k in TEMP_VARIABLES},
               'claude_tmp': claude_tmp_state, 'mktemp': mktemp}, f)
print('fake claude ran')
sys.stdout.flush()
if os.environ.get('FAKE_SLEEP'):
    time.sleep(float(os.environ['FAKE_SLEEP']))
sys.exit(int(os.environ.get('FAKE_EXIT', '0')))
''' % sys.executable

FAKE_SECURITY = '''#!/bin/sh
printf '%s\\n' "$*" >> "$FAKE_SECURITY_LOG"
[ -n "${FAKE_TOKEN:-}" ] || { echo 'security: The specified item could not be found in the keychain.' >&2; exit 44; }
printf '%s\\n' "$FAKE_TOKEN"
'''

TEMP_PARENT = os.path.realpath('/tmp')   # /private/tmp on macOS
CLAUDE_UID_DIR = 'claude-%d' % os.getuid()

# mktemp first in PATH: logs every run directory it makes in the system temp, so the cleanup of a test removes only
# those, never the run directory of an executor running at the same time; FAKE_MKTEMP_BAD makes one the wrapper must
# refuse.
FAKE_MKTEMP = '''#!/bin/sh
last=
for a in "$@"; do last=$a; done
case $last in
"$FAKE_TEMP_PARENT"/*)
    [ "${FAKE_MKTEMP_BAD:-}" = fail ] && { echo "mktemp: cannot create" >&2; exit 1; }
    d=$(/usr/bin/mktemp "$@") || exit
    printf '%s\\n' "$d" >> "$FAKE_MKTEMP_LOG"
    case ${FAKE_MKTEMP_BAD:-} in
    mode) chmod 755 "$d" ;;
    symlink) ln -s "$d" "$d.link" && printf '%s\\n' "$d.link" >> "$FAKE_MKTEMP_LOG" && d=$d.link ;;
    esac
    echo "$d"
    exit ;;
esac
exec /usr/bin/mktemp "$@"
'''

GIT_ENV = {'GIT_AUTHOR_NAME': 'Test', 'GIT_AUTHOR_EMAIL': 'test@example.com', 'GIT_COMMITTER_NAME': 'Test',
           'GIT_COMMITTER_EMAIL': 'test@example.com', 'GIT_CONFIG_NOSYSTEM': '1'}


def write(path, text, mode=None):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w') as f:
        f.write(text)
    if mode is not None:
        os.chmod(path, mode)


class WrapperTestCase(unittest.TestCase):
    def setUp(self):
        self.tmp = os.path.realpath(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.tmpdir = os.path.join(self.tmp, 'tmp')  # a TMPDIR that is not an ancestor of HOME
        os.makedirs(self.tmpdir)
        self.home = os.path.join(self.tmp, 'home')
        self.ao_home = os.path.join(self.home, '.local/state/ao-x')
        self.task = self.task_dir('X-1')
        self.task_dir('X-2')
        os.makedirs(os.path.join(self.ao_home, 'epics/x/integration'))
        self.bin = os.path.join(self.tmp, 'bin')
        write(os.path.join(self.bin, 'security'), FAKE_SECURITY, 0o755)
        write(os.path.join(self.bin, 'mktemp'), FAKE_MKTEMP, 0o755)
        self.mktemp_log = os.path.join(self.tmp, 'mktemp.log')
        self.addCleanup(self.remove_created_run_dirs)
        self.claude = os.path.join(self.tmp, 'claude')
        write(self.claude, FAKE_CLAUDE, 0o755)
        self.record = os.path.join(self.tmp, 'record.json')
        self.security_log = os.path.join(self.tmp, 'security.log')
        self.origin = os.path.join(self.tmp, 'origin.git')
        self.clone = os.path.join(self.home, 'Development/claude/shop')
        self.work = os.path.join(self.home, 'Development/claude/shop-X-1')
        self.make_repositories()

    def created_run_dirs(self):
        if not os.path.exists(self.mktemp_log):
            return []
        with open(self.mktemp_log) as f:
            return [line for line in f.read().splitlines() if line.startswith(TEMP_PARENT + '/agent.')]

    def remove_created_run_dirs(self):
        for path in self.created_run_dirs():
            if os.path.islink(path):
                os.unlink(path)
            elif os.path.isdir(path):
                shutil.rmtree(path, True)

    def task_dir(self, task_id):
        path = os.path.join(self.ao_home, 'tasks', task_id)
        for name in ('briefs', 'attempts'):
            os.makedirs(os.path.join(path, name))
        return path

    def git(self, *args, cwd=None):
        env = dict(os.environ, HOME=self.home, **GIT_ENV)
        result = subprocess.run(['git', *args], cwd=cwd or self.work, capture_output=True, text=True, env=env)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout.strip()

    def make_repositories(self):
        self.git('init', '-q', '--bare', '-b', 'main', self.origin, cwd=self.tmp)
        seed = os.path.join(self.tmp, 'seed')
        self.git('init', '-q', '-b', 'main', seed, cwd=self.tmp)
        write(os.path.join(seed, '.claude/settings.json'), '{"enabledPlugins": {}}\n')
        write(os.path.join(seed, '.gitignore'), '.claude/settings.local.json\n')
        write(os.path.join(seed, 'README.md'), 'shop\n')
        self.git('add', '-A', cwd=seed)
        self.git('commit', '-q', '-m', 'init', cwd=seed)
        self.git('push', '-q', self.origin, 'main', cwd=seed)
        self.seed = seed
        os.makedirs(os.path.dirname(self.clone))
        self.git('clone', '-q', self.origin, self.clone, cwd=self.tmp)
        self.git('worktree', 'add', '-q', '-b', 'task/X-1', self.work, 'origin/main', cwd=self.clone)

    def env(self, **overrides):
        env = {'PATH': self.bin + os.pathsep + os.environ.get('PATH', '/usr/bin:/bin'), 'HOME': self.home,
               'AGENT_CLAUDE_BIN': self.claude, 'AGENT_AO_HOME': self.ao_home, 'AO_TASK_ID': 'X-1',
               'FAKE_TOKEN': TOKEN, 'FAKE_CLAUDE_RECORD': self.record, 'FAKE_SECURITY_LOG': self.security_log,
               'SSH_AUTH_SOCK': '/tmp/ssh-agent.sock', 'TMPDIR': self.tmpdir, 'FAKE_MKTEMP_LOG': self.mktemp_log,
               'FAKE_TEMP_PARENT': TEMP_PARENT, **GIT_ENV}
        for key, value in overrides.items():
            if value is None:
                env.pop(key, None)
            else:
                env[key] = value
        return env

    def require_system_tmp(self):
        # The wrapper makes /private/tmp/agent.XXXXXX, which the executor's sandbox does not allow: a test that lets
        # the wrapper start is skipped there (with the reason), never on a host or in CI (system_tmp.py).
        system_tmp.require(self)

    def run_wrapper(self, *args, cwd=None, **env):
        return subprocess.run(['sh', WRAPPER, *args], cwd=cwd or self.work, capture_output=True, text=True,
                              env=self.env(**env))

    def recorded(self):
        with open(self.record) as f:
            return json.load(f)

    def started(self, *args, cwd=None, **env):
        self.require_system_tmp()
        result = self.run_wrapper(*args, cwd=cwd, **env)
        self.assertEqual(result.returncode, 0, result.stderr)
        return self.recorded()

    def refused(self, *args, cwd=None, **env):
        result = self.run_wrapper(*args, cwd=cwd, **env)
        self.assertNotEqual(result.returncode, 0, 'the wrapper started the binary')
        self.assertFalse(os.path.exists(self.record), 'the binary ran')
        self.assertIn('claude-agent:', result.stderr)
        return result.stderr

    def settings(self, record):
        argv = record['argv']
        return json.loads(argv[argv.index('--settings') + 1])


AO_ARGS = ('-p', 'Zrealizuj task X-1.\n--settings w treści promptu nie jest flagą', '--model', 'opus', '--effort',
           'high', '--output-format', 'stream-json', '--verbose', '--permission-mode', 'auto',
           '--permission-prompts', 'none', '--max-budget-usd', '20')


class PassthroughTest(WrapperTestCase):
    def test_version_passes_unchanged_without_task_and_settings(self):
        record = self.started('--version', AO_TASK_ID=None, AGENT_AO_HOME=None, FAKE_TOKEN=None)
        self.assertEqual(record['argv'], ['--version'])
        self.assertFalse(os.path.exists(self.security_log))

    def test_auth_status_passes_unchanged_without_task_and_settings(self):
        record = self.started('auth', 'status', AO_TASK_ID=None, AGENT_AO_HOME=None, FAKE_TOKEN=None)
        self.assertEqual(record['argv'], ['auth', 'status'])

    def test_passthrough_needs_the_pinned_binary(self):
        self.refused('--version', AGENT_CLAUDE_BIN=None)
        self.refused('--version', AGENT_CLAUDE_BIN=os.path.join(self.tmp, 'nope'))

    def test_other_auth_commands_are_not_passed_through(self):
        self.refused('auth', 'login', AO_TASK_ID=None)


class StartTest(WrapperTestCase):
    def test_ao_arguments_pass_unchanged_with_the_profile_flags(self):
        record = self.started(*AO_ARGS)
        argv = record['argv']
        self.assertEqual(argv[:len(AO_ARGS)], list(AO_ARGS))
        extra = argv[len(AO_ARGS):]
        self.assertEqual(extra[0], '--settings')
        self.assertEqual(extra[2:], ['--strict-mcp-config', '--setting-sources', 'project'])

    def test_settings_contain_only_the_task_directory_of_ao_home(self):
        settings = self.settings(self.started(*AO_ARGS))
        fs = settings['sandbox']['filesystem']
        self.assertIn(self.work, fs['allowWrite'])
        self.assertIn(os.path.join(self.clone, '.git'), fs['allowWrite'])
        self.assertIn(self.task, fs['allowWrite'])
        self.assertIn(self.task + '/briefs', fs['denyWrite'])
        self.assertIn(self.task + '/attempts', fs['denyWrite'])
        self.assertIn(self.task, settings['permissions']['additionalDirectories'])
        everything = json.dumps(settings)
        self.assertNotIn(os.path.join(self.ao_home, 'tasks/X-2'), everything)
        self.assertNotIn(os.path.join(self.ao_home, 'epics'), everything)
        for path in fs['allowRead'] + fs['allowWrite']:
            self.assertFalse(path in (self.ao_home, os.path.join(self.ao_home, 'tasks')), path)

    def test_integration_task_gets_the_integration_directory_of_its_epic(self):
        self.task_dir('X-INTEGRATION-2')
        settings = self.settings(self.started(*AO_ARGS, AO_TASK_ID='X-INTEGRATION-2'))
        fs = settings['sandbox']['filesystem']
        integration = os.path.join(self.ao_home, 'epics/x/integration')
        self.assertIn(integration, fs['allowWrite'])
        self.assertIn(os.path.join(self.ao_home, 'tasks/X-INTEGRATION-2'), fs['allowWrite'])
        self.assertIn(integration, settings['permissions']['additionalDirectories'])

    def test_only_an_integration_task_gets_the_integration_directory(self):
        for task_id in ('X-FIX-1', 'X-REPAIR-1', 'X-FINALIZE', 'X-INTEGRATION-1a'):
            self.task_dir(task_id)
            settings = self.settings(self.started(*AO_ARGS, AO_TASK_ID=task_id))
            self.assertNotIn(os.path.join(self.ao_home, 'epics'), json.dumps(settings))
            os.remove(self.record)

    def test_integration_without_one_matching_epic_directory_is_refused(self):
        self.task_dir('Y-INTEGRATION-1')
        self.refused(*AO_ARGS, AO_TASK_ID='Y-INTEGRATION-1')
        if os.path.exists(os.path.join(self.ao_home, 'epics/X')):
            return   # a case-insensitive file system (macOS) cannot have two epic directories differing in case
        os.makedirs(os.path.join(self.ao_home, 'epics/X/integration'))
        self.task_dir('X-INTEGRATION-3')
        self.refused(*AO_ARGS, AO_TASK_ID='X-INTEGRATION-3')

    def test_integration_directory_through_a_symlink_is_refused(self):
        os.makedirs(os.path.join(self.tmp, 'elsewhere'))
        os.makedirs(os.path.join(self.ao_home, 'epics/z'))
        os.symlink(os.path.join(self.tmp, 'elsewhere'), os.path.join(self.ao_home, 'epics/z/integration'))
        self.task_dir('Z-INTEGRATION-1')
        self.refused(*AO_ARGS, AO_TASK_ID='Z-INTEGRATION-1')

    def test_environment_of_the_executor(self):
        record = self.started(*AO_ARGS)
        env = record['env']
        self.assertEqual(env['GH_TOKEN'], TOKEN)
        self.assertTrue(record['dirs']['GH_CONFIG_DIR'])
        self.assertTrue(env['GH_CONFIG_DIR'].startswith(self.work + '/.agent-tmp/'), env['GH_CONFIG_DIR'])
        self.assertNotIn('SSH_AUTH_SOCK', env)
        count = int(env['GIT_CONFIG_COUNT'])
        pairs = [(env['GIT_CONFIG_KEY_%d' % i], env['GIT_CONFIG_VALUE_%d' % i]) for i in range(count)]
        self.assertIn(('url.https://github.com/.insteadOf', 'git@github.com:'), pairs)
        self.assertIn(('url.https://github.com/.insteadOf', 'ssh://git@github.com/'), pairs)
        helpers = [value for key, value in pairs if key == 'credential.helper']
        self.assertEqual(helpers[0], '')
        self.assertIn('GH_TOKEN', helpers[1])
        self.assertNotIn(TOKEN, json.dumps(pairs))
        with open(self.security_log) as f:
            self.assertIn('-s shop-agent-github-token -w', f.read())

    def test_the_credential_helper_answers_with_the_token(self):
        env = self.started(*AO_ARGS)['env']
        helper = [env['GIT_CONFIG_VALUE_%d' % i] for i in range(int(env['GIT_CONFIG_COUNT']))
                  if env['GIT_CONFIG_KEY_%d' % i] == 'credential.helper'][1]
        self.assertTrue(helper.startswith('!'))
        result = subprocess.run(['sh', '-c', helper[1:] + ' "$@"', 'helper', 'get'], capture_output=True,
                                text=True, env={'GH_TOKEN': TOKEN, 'PATH': os.environ['PATH']}, input='')
        self.assertIn('username=x-access-token', result.stdout)
        self.assertIn('password=' + TOKEN, result.stdout)
        result = subprocess.run(['sh', '-c', helper[1:] + ' "$@"', 'helper', 'store'], capture_output=True,
                                text=True, env={'GH_TOKEN': TOKEN, 'PATH': os.environ['PATH']}, input='')
        self.assertNotIn(TOKEN, result.stdout)


TEMP_VARIABLES = ('TMPDIR', 'BUILDX_CONFIG', 'GH_CONFIG_DIR', 'XDG_CACHE_HOME')


class TaskTempDirTest(WrapperTestCase):
    """The executor's temporary directories live in its worktree, apart from the shared /tmp and the user's temp, and
    the wrapper removes them when the executor ends (normally, with an error or on a signal)."""

    def temp_root(self):
        return os.path.join(self.work, '.agent-tmp')

    def assert_removed(self, record):
        for name in TEMP_VARIABLES + ('CLAUDE_CODE_TMPDIR',):
            self.assertFalse(os.path.lexists(record['env'][name]), name)
        self.assertFalse(os.path.exists(self.temp_root()), 'the task temp root stayed')

    def test_temp_directories_are_in_the_worktree_and_set_for_the_executor(self):
        record = self.started(*AO_ARGS)
        for name in TEMP_VARIABLES:
            self.assertTrue(record['env'][name].startswith(self.temp_root() + '/'), (name, record['env'][name]))
            self.assertTrue(record['dirs'][name], '%s did not exist while the executor ran' % name)
        self.assertNotEqual(record['env']['TMPDIR'], self.tmpdir)
        self.assert_removed(record)

    def test_two_runs_get_separate_directories(self):
        first = self.started(*AO_ARGS)['env']['TMPDIR']
        os.remove(self.record)
        self.assertNotEqual(first, self.started(*AO_ARGS)['env']['TMPDIR'])

    def test_exit_code_of_the_binary_is_returned_and_the_directory_removed(self):
        self.require_system_tmp()
        result = self.run_wrapper(*AO_ARGS, FAKE_EXIT='7')
        self.assertEqual(result.returncode, 7, result.stderr)
        self.assert_removed(self.recorded())

    def test_stdin_reaches_the_binary(self):
        self.require_system_tmp()
        result = subprocess.run(['sh', WRAPPER, *AO_ARGS], cwd=self.work, capture_output=True, text=True,
                                env=self.env(FAKE_READ_STDIN='1'), input='{"type":"user"}\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.recorded()['stdin'], '{"type":"user"}\n')
        self.assertIn('fake claude ran', result.stdout)

    def run_with_open_fds(self, numbers):
        self.require_system_tmp()
        # Like AO, which passes its lock descriptors to the executor (pass_fds): the descriptors are open before the
        # wrapper starts.
        opens = ' '.join('%d<"$FD_DIR/%d"' % (n, n) for n in numbers)
        fd_dir = os.path.join(self.tmp, 'fds')
        os.makedirs(fd_dir, exist_ok=True)
        for n in numbers:
            write(os.path.join(fd_dir, str(n)), 'inherited descriptor %d\n' % n)
        return subprocess.run(['sh', '-c', 'exec %s; exec sh "$WRAPPER" "$@"' % opens, 'sh', *AO_ARGS],
                              cwd=self.work, capture_output=True, text=True, input='{"type":"user"}\n',
                              env=self.env(FAKE_READ_STDIN='1', FD_DIR=fd_dir, WRAPPER=WRAPPER))

    def test_inherited_descriptors_reach_the_binary_unchanged(self):
        for numbers in ((3,), (3, 4, 5, 6, 7, 8), (4, 9)):
            with self.subTest(numbers=numbers):
                if os.path.exists(self.record):
                    os.remove(self.record)
                result = self.run_with_open_fds(numbers)
                self.assertEqual(result.returncode, 0, result.stderr)
                record = self.recorded()
                self.assertEqual(record['fds'], {str(n): 'inherited descriptor %d\n' % n for n in numbers})
                self.assertEqual(record['stdin'], '{"type":"user"}\n')

    def test_no_free_descriptor_for_stdin_is_refused(self):
        result = self.run_with_open_fds(range(3, 10))
        self.assertEqual(result.returncode, 2)
        self.assertFalse(os.path.exists(self.record), 'the binary ran')
        self.assertIn('deskryptor', result.stderr)

    def run_and_signal(self, signum):
        self.require_system_tmp()
        process = subprocess.Popen(['sh', WRAPPER, *AO_ARGS], cwd=self.work, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, text=True, env=self.env(FAKE_SLEEP='60'))
        self.addCleanup(lambda: process.poll() is None and process.kill())
        for _ in range(400):
            if os.path.exists(self.record) and os.path.getsize(self.record):
                break
            time.sleep(0.05)
        record = self.recorded()
        time.sleep(0.2)
        process.send_signal(signum)
        process.wait(timeout=30)
        self.assertNotEqual(process.returncode, 0)
        for _ in range(100):
            if not self.alive(record['pid']):
                break
            time.sleep(0.05)
        self.assertFalse(self.alive(record['pid']), 'the executor survived the signal to the wrapper')
        self.assert_removed(record)

    @staticmethod
    def alive(pid):
        try:
            os.kill(pid, 0)
        except ProcessLookupError:
            return False
        except PermissionError:
            return True
        # a zombie of the wrapper's child is not alive work: check the state through ps
        state = subprocess.run(['ps', '-o', 'stat=', '-p', str(pid)], capture_output=True, text=True).stdout.strip()
        return bool(state) and not state.startswith('Z')

    def test_sigterm_to_the_wrapper(self):
        self.run_and_signal(signal.SIGTERM)

    def test_sigint_to_the_wrapper(self):
        self.run_and_signal(signal.SIGINT)

    def test_sighup_to_the_wrapper(self):
        self.run_and_signal(signal.SIGHUP)

    def test_a_symlink_in_place_of_the_temp_root_is_refused(self):
        elsewhere = os.path.join(self.tmp, 'elsewhere')
        os.makedirs(elsewhere)
        os.symlink(elsewhere, self.temp_root())
        stderr = self.refused(*AO_ARGS)
        self.assertIn('.agent-tmp', stderr)
        self.assertEqual(os.listdir(elsewhere), [])
        self.assertTrue(os.path.islink(self.temp_root()))

    def test_a_file_in_place_of_the_temp_root_is_refused(self):
        write(self.temp_root(), 'x\n')
        self.assertIn('.agent-tmp', self.refused(*AO_ARGS))

    def test_the_temp_root_is_ignored_by_git(self):
        repository = os.path.dirname(os.path.dirname(HERE))
        result = subprocess.run(['git', 'check-ignore', '-q', '.agent-tmp/run.x/tmp/file'], cwd=repository)
        self.assertEqual(result.returncode, 0, '.agent-tmp is not in .gitignore')


class ClaudeTmpdirTest(WrapperTestCase):
    """CLAUDE_CODE_TMPDIR (B4): every run gets a fresh directory with a short random name directly in the system temp
    (/private/tmp on macOS), mode 0700, owned by the user, holding only claude-<uid>, the TMPDIR Claude Code gives to
    Bash. Only claude-<uid> is opened in the profile; the wrapper removes the directory after the executor."""

    def test_a_fresh_private_directory_in_the_system_temp(self):
        record = self.started(*AO_ARGS, CLAUDE_CODE_TMPDIR='/tmp')
        path = record['env']['CLAUDE_CODE_TMPDIR']
        self.assertEqual(os.path.dirname(path), TEMP_PARENT)
        self.assertEqual(record['claude_tmp'], {'mode': 0o700, 'uid': os.getuid(), 'link': False,
                                                'entries': [CLAUDE_UID_DIR]})
        # Claude Code keeps <dir>/claude-<uid> for AF_UNIX sockets only up to 44 bytes, else it falls back to the
        # shared /tmp/claude-<uid>.
        self.assertLessEqual(len(os.path.join(path, CLAUDE_UID_DIR).encode()), 44)
        self.assertFalse(os.path.lexists(path), 'the run directory stayed')

    def test_the_profile_opens_only_its_claude_directory(self):
        record = self.started(*AO_ARGS)
        path = record['env']['CLAUDE_CODE_TMPDIR']
        fs = self.settings(record)['sandbox']['filesystem']
        inner = os.path.join(path, CLAUDE_UID_DIR)
        self.assertIn(inner, fs['allowRead'])
        self.assertIn(inner, fs['allowWrite'])
        for granted in fs['allowRead'] + fs['allowWrite']:
            if granted.startswith(('/tmp', '/private/tmp', TEMP_PARENT + '/')) and not granted.startswith(self.tmp):
                self.assertEqual(granted, inner)
        for denied in ('/tmp', '/private/tmp'):
            self.assertIn(denied, fs['denyRead'])

    def test_two_runs_get_separate_directories(self):
        first = self.started(*AO_ARGS)['env']['CLAUDE_CODE_TMPDIR']
        os.remove(self.record)
        self.assertNotEqual(first, self.started(*AO_ARGS)['env']['CLAUDE_CODE_TMPDIR'])

    def test_removed_after_an_error_exit_with_what_claude_code_put_there(self):
        self.require_system_tmp()
        result = self.run_wrapper(*AO_ARGS, FAKE_EXIT='3')
        self.assertEqual(result.returncode, 3, result.stderr)
        self.assertFalse(os.path.lexists(self.recorded()['env']['CLAUDE_CODE_TMPDIR']))

    def test_cleanup_leaves_run_directories_of_other_sessions(self):
        self.require_system_tmp()
        # A real executor may start while the tests run; its run directory (its session's sockets) must survive the
        # cleanup of this test.
        other = tempfile.mkdtemp(prefix='agent.', dir=TEMP_PARENT)
        try:
            self.started(*AO_ARGS)
            self.doCleanups()
            self.assertTrue(os.path.isdir(other), 'the cleanup of the test removed a run directory it did not make')
        finally:
            shutil.rmtree(other, True)

    def fake_mktemp(self, bad):
        return self.refused(*AO_ARGS, FAKE_MKTEMP_BAD=bad)

    def test_a_directory_with_other_permissions_is_refused(self):
        self.require_system_tmp()
        self.assertIn(TEMP_PARENT, self.fake_mktemp('mode'))
        made = self.created_run_dirs()
        self.assertEqual(len(made), 1)
        self.assertFalse(os.path.lexists(made[0]), 'the refused (empty) run directory stayed')

    def test_a_symlink_is_refused(self):
        self.require_system_tmp()
        self.assertIn(TEMP_PARENT, self.fake_mktemp('symlink'))

    def test_a_failed_mktemp_is_refused(self):
        self.assertIn(TEMP_PARENT, self.fake_mktemp('fail'))


class MktempOverlayTest(WrapperTestCase):
    """B7: the executor's PATH starts with the directory bin/ next to the wrapper, which holds the mktemp overlay
    (a bare `mktemp` on macOS writes to the user's temp, read-only for the executor). The directory is read-only in the
    profile: readable (the executor runs the overlay), never writable, also when the wrapper sits in the worktree."""

    BIN = os.path.join(HERE, 'bin')

    def test_the_overlay_directory_is_first_in_path_and_the_old_path_follows(self):
        env = self.started(*AO_ARGS)['env']
        entries = env['PATH'].split(os.pathsep)
        self.assertEqual(entries[0], os.path.realpath(self.BIN))
        self.assertEqual(entries[1:], self.env()['PATH'].split(os.pathsep))

    def test_a_bare_mktemp_of_the_executor_lands_in_its_tmpdir(self):
        record = self.started(*AO_ARGS, FAKE_RUN_MKTEMP='1')
        mktemp = record['mktemp']
        self.assertEqual(mktemp['command'], os.path.join(os.path.realpath(self.BIN), 'mktemp'))
        tmpdir = record['env']['TMPDIR']
        for key in ('file', 'dir', 'prefix'):
            self.assertEqual(mktemp[key]['code'], 0, mktemp[key])
            self.assertEqual(os.path.dirname(mktemp[key]['path']), tmpdir, key)
            self.assertTrue(mktemp[key]['exists'], key)

    def test_the_profile_reads_the_overlay_directory_and_never_writes_it(self):
        fs = self.settings(self.started(*AO_ARGS))['sandbox']['filesystem']
        overlay = os.path.realpath(self.BIN)
        self.assertIn(overlay, fs['allowRead'])
        self.assertNotIn(overlay, fs['allowWrite'])
        self.assertIn(overlay, fs['denyWrite'])
        for granted in fs['allowWrite']:
            self.assertFalse(overlay.startswith(granted.rstrip('/') + '/') and granted != self.work, granted)

    def test_the_overlay_stays_write_protected_when_the_wrapper_is_in_the_worktree(self):
        self.require_system_tmp()
        copy = os.path.join(self.work, 'tools/agent-security')
        shutil.copytree(HERE, copy, ignore=shutil.ignore_patterns('test_*', '__pycache__'))
        self.git('add', '-A')
        self.git('commit', '-q', '-m', 'wrapper in the worktree')
        result = subprocess.run(['sh', os.path.join(copy, 'claude-agent'), *AO_ARGS], cwd=self.work,
                                capture_output=True, text=True, env=self.env())
        self.assertEqual(result.returncode, 0, result.stderr)
        settings = self.settings(self.recorded())
        overlay = os.path.join(self.work, 'tools/agent-security/bin')
        self.assertIn(overlay, settings['sandbox']['filesystem']['denyWrite'])
        self.assertIn('Edit(/%s/**)' % overlay, settings['permissions']['deny'])

    def copy_of_the_wrapper(self, bin_setup):
        copy = os.path.join(self.tmp, 'copy')
        os.makedirs(copy)
        for name in ('claude-agent', 'render_settings.py', 'executor-settings.json'):
            shutil.copy(os.path.join(HERE, name), copy)
        bin_setup(copy)
        return copy

    def refused_copy(self, bin_setup):
        copy = self.copy_of_the_wrapper(bin_setup)
        result = subprocess.run(['sh', os.path.join(copy, 'claude-agent'), *AO_ARGS], cwd=self.work,
                                capture_output=True, text=True, env=self.env())
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(os.path.exists(self.record), 'the binary ran')
        self.assertIn('bin/mktemp', result.stderr)
        self.assertFalse(os.path.exists(os.path.join(self.work, '.agent-tmp')), 'the temp root stayed')

    def test_a_wrapper_without_the_overlay_is_refused(self):
        self.refused_copy(lambda copy: None)

    def test_an_overlay_that_is_not_executable_is_refused(self):
        def setup(copy):
            os.makedirs(os.path.join(copy, 'bin'))
            write(os.path.join(copy, 'bin/mktemp'), '#!/bin/sh\n', 0o644)
        self.refused_copy(setup)

    def test_an_overlay_that_is_a_symlink_is_refused(self):
        def setup(copy):
            os.makedirs(os.path.join(copy, 'bin'))
            os.symlink('/usr/bin/mktemp', os.path.join(copy, 'bin/mktemp'))
        self.refused_copy(setup)

    def test_an_overlay_directory_that_is_a_symlink_is_refused(self):
        def setup(copy):
            elsewhere = os.path.join(self.tmp, 'elsewhere-bin')
            write(os.path.join(elsewhere, 'mktemp'), '#!/bin/sh\n', 0o755)
            os.symlink(elsewhere, os.path.join(copy, 'bin'))
        self.refused_copy(setup)

    def test_a_wrapper_directory_with_a_colon_is_refused(self):
        # PATH entries are separated by colons: such a directory would put another one first.
        copy = os.path.join(self.tmp, 'a:b')
        os.makedirs(os.path.join(copy, 'bin'))
        for name in ('claude-agent', 'render_settings.py', 'executor-settings.json'):
            shutil.copy(os.path.join(HERE, name), copy)
        shutil.copy(OVERLAY, os.path.join(copy, 'bin/mktemp'))
        result = subprocess.run(['sh', os.path.join(copy, 'claude-agent'), *AO_ARGS], cwd=self.work,
                                capture_output=True, text=True, env=self.env())
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(os.path.exists(self.record), 'the binary ran')
        self.assertIn(':', result.stderr)

    def test_the_marker_of_the_executor_sandbox_is_set(self):
        self.assertEqual(self.started(*AO_ARGS)['env'].get('AGENT_EXECUTOR_SANDBOX'), '1')


class RefusalTest(WrapperTestCase):
    def test_missing_variables(self):
        for name in ('AGENT_CLAUDE_BIN', 'AGENT_AO_HOME', 'AO_TASK_ID'):
            self.assertIn(name, self.refused(*AO_ARGS, **{name: None}))
            self.assertIn(name, self.refused(*AO_ARGS, **{name: ''}))

    def test_missing_token(self):
        stderr = self.refused(*AO_ARGS, FAKE_TOKEN=None)
        self.assertNotIn('hint:', stderr)   # only the wrapper's own message, no git advice
        self.assertIn('shop-agent-github-token', stderr)
        self.assertIn('security add-generic-password', stderr)

    def test_unknown_or_invalid_task(self):
        for task_id in ('X-9', '../X-1', 'X-1/..', '-X', '.X', 'X 1', 'tasks/X-1', 'X-1\n'):
            self.refused(*AO_ARGS, AO_TASK_ID=task_id)

    def test_ao_home_outside_the_state_directory(self):
        for path in (os.path.join(self.home, '.local/state/other'), os.path.join(self.home, '.local/state/ao-'),
                     os.path.join(self.tmp, 'ao-x'), os.path.join(self.ao_home, 'tasks'),
                     os.path.join(self.home, '.local/state'), 'relative/ao-x'):
            os.makedirs(os.path.join(path if os.path.isabs(path) else os.path.join(self.work, path), 'tasks/X-1'),
                        exist_ok=True)
            self.refused(*AO_ARGS, AGENT_AO_HOME=path)

    def test_ao_home_through_a_symlink_to_elsewhere(self):
        elsewhere = os.path.join(self.tmp, 'elsewhere')
        os.makedirs(os.path.join(elsewhere, 'tasks/X-1'))
        link = os.path.join(self.home, '.local/state/ao-link')
        os.symlink(elsewhere, link)
        self.refused(*AO_ARGS, AGENT_AO_HOME=link)

    def test_task_directory_through_a_symlink(self):
        elsewhere = os.path.join(self.tmp, 'elsewhere')
        os.makedirs(elsewhere)
        os.symlink(elsewhere, os.path.join(self.ao_home, 'tasks/X-3'))
        self.refused(*AO_ARGS, AO_TASK_ID='X-3')
        os.symlink(self.task, os.path.join(self.ao_home, 'tasks/X-4'))
        self.refused(*AO_ARGS, AO_TASK_ID='X-4')

    def test_arguments_that_would_replace_the_profile(self):
        for extra in (('--settings', '{}'), ('--settings={}',), ('--setting-sources', 'user'), ('--mcp-config', 'x'),
                      ('--add-dir', self.tmp), ('--plugin-dir', self.tmp), ('--dangerously-skip-permissions',),
                      ('--allow-dangerously-skip-permissions',)):
            self.refused(*AO_ARGS, *extra)

    def test_not_a_git_checkout(self):
        plain = os.path.join(self.home, 'Development/claude/plain')
        os.makedirs(plain)
        self.refused(*AO_ARGS, cwd=plain)

    def test_origin_unreachable(self):
        self.git('remote', 'set-url', 'origin', os.path.join(self.tmp, 'missing.git'), cwd=self.clone)
        self.refused(*AO_ARGS)

    def test_render_failure_is_refused(self):
        copy = os.path.join(self.tmp, 'copy')
        os.makedirs(copy)
        for name in ('claude-agent', 'render_settings.py'):
            shutil.copy(os.path.join(HERE, name), copy)
        result = subprocess.run(['sh', os.path.join(copy, 'claude-agent'), *AO_ARGS], cwd=self.work,
                                capture_output=True, text=True, env=self.env())
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(os.path.exists(self.record))
        self.assertIn('executor-settings.json', result.stderr)


class ProtectedSettingsTest(WrapperTestCase):
    """P12: .claude/settings*.json and .mcp.json changed against the merge-base with main stop the wrapper."""

    def test_unchanged_branch_with_other_commits_starts(self):
        write(os.path.join(self.work, 'code.txt'), 'x\n')
        self.git('add', 'code.txt')
        self.git('commit', '-q', '-m', 'code')
        self.started(*AO_ARGS)

    def test_change_in_a_commit_of_the_branch(self):
        write(os.path.join(self.work, '.claude/settings.json'), '{"permissions": {"allow": ["Bash"]}}\n')
        self.git('commit', '-q', '-am', 'loosen')
        self.assertIn('.claude/settings.json', self.refused(*AO_ARGS))

    def test_change_reverted_later_in_the_branch_starts(self):
        path = os.path.join(self.work, '.claude/settings.json')
        with open(path) as f:
            original = f.read()
        write(path, '{}\n')
        self.git('commit', '-q', '-am', 'loosen')
        write(path, original)
        self.git('commit', '-q', '-am', 'restore')
        self.started(*AO_ARGS)

    def test_change_in_the_working_tree_or_index(self):
        path = os.path.join(self.work, '.claude/settings.json')
        write(path, '{}\n')
        self.refused(*AO_ARGS)
        self.git('add', path)
        self.refused(*AO_ARGS)
        self.git('checkout', 'HEAD', '--', path)
        write(path, '{}\n')
        self.git('add', path)
        with open(os.path.join(self.seed, '.claude/settings.json')) as f:
            write(path, f.read())
        self.refused(*AO_ARGS)

    def test_deleted_settings(self):
        os.remove(os.path.join(self.work, '.claude/settings.json'))
        self.refused(*AO_ARGS)

    def test_untracked_and_ignored_files(self):
        for name in ('.claude/settings.local.json', '.mcp.json'):
            write(os.path.join(self.work, name), '{}\n')
            self.assertIn(name, self.refused(*AO_ARGS))
            os.remove(os.path.join(self.work, name))
        self.started(*AO_ARGS)

    def test_later_change_on_main_does_not_stop_an_older_branch(self):
        write(os.path.join(self.seed, '.claude/settings.json'), '{"enabledPlugins": {"x": true}}\n')
        self.git('commit', '-q', '-am', 'main changes settings', cwd=self.seed)
        self.git('push', '-q', self.origin, 'main', cwd=self.seed)
        self.started(*AO_ARGS)

    def test_main_from_ls_remote_even_when_origin_main_was_moved(self):
        write(os.path.join(self.work, '.claude/settings.json'), '{}\n')
        self.git('commit', '-q', '-am', 'loosen')
        self.git('update-ref', 'refs/remotes/origin/main', 'HEAD')
        self.refused(*AO_ARGS)

    def loosen_in_a_commit(self):
        write(os.path.join(self.work, '.claude/settings.json'), '{"permissions": {"allow": ["Read(//**)"]}}\n')
        self.git('commit', '-q', '-am', 'loosen')
        return self.git('rev-parse', 'HEAD')

    def git_common_dir(self):
        common = self.git('rev-parse', '--git-common-dir')
        return common if os.path.isabs(common) else os.path.join(self.work, common)

    def test_a_replace_object_does_not_hide_the_change(self):
        # The executor may write refs/replace in the shared .git: main's commit replaced by one with the loosened tree
        # would make the diff against the merge-base empty.
        head = self.loosen_in_a_commit()
        main = self.git('rev-parse', 'origin/main')
        fake = self.git('commit-tree', head + '^{tree}', '-m', 'fake main')
        self.git('replace', main, fake)
        self.assertIn('.claude/settings.json', self.refused(*AO_ARGS))

    def test_a_graft_does_not_move_the_merge_base(self):
        # Grafts giving the branch and main a common parent with the loosened tree make that parent the merge-base.
        head = self.loosen_in_a_commit()
        main = self.git('rev-parse', 'origin/main')
        fake = self.git('commit-tree', head + '^{tree}', '-m', 'fake base')
        write(os.path.join(self.git_common_dir(), 'info', 'grafts'), '%s %s\n%s %s\n' % (head, fake, main, fake))
        self.assertIn('.claude/settings.json', self.refused(*AO_ARGS))

    def test_main_is_fetched_when_the_clone_does_not_have_it(self):
        write(os.path.join(self.seed, 'later.txt'), 'later\n')
        self.git('add', 'later.txt', cwd=self.seed)
        self.git('commit', '-q', '-m', 'later', cwd=self.seed)
        self.git('push', '-q', self.origin, 'main', cwd=self.seed)
        sha = self.git('rev-parse', 'HEAD', cwd=self.seed)
        self.started(*AO_ARGS)
        self.assertEqual(self.git('cat-file', '-t', sha), 'commit')


if __name__ == '__main__':
    unittest.main()

"""Tests of the claude-agent wrapper: python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

Each test builds a temporary HOME (resolved, without symlinks) with a fake AO home, a local bare `origin`, a main
clone and a task worktree, and puts a fake `security` first in PATH. The fake Claude binary records its arguments and
environment; git is the real one (the origin is local, so `git ls-remote origin` works offline).
"""
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
WRAPPER = os.path.join(HERE, 'claude-agent')
TOKEN = 'github_pat_fake_executor_token'

FAKE_CLAUDE = '''#!%s
import json, os, sys, time
TEMP_VARIABLES = ('TMPDIR', 'BUILDX_CONFIG', 'GH_CONFIG_DIR', 'XDG_CACHE_HOME')
stdin = sys.stdin.read() if os.environ.get('FAKE_READ_STDIN') else None
with open(os.environ['FAKE_CLAUDE_RECORD'], 'w') as f:
    json.dump({'argv': sys.argv[1:], 'env': dict(os.environ), 'pid': os.getpid(), 'stdin': stdin,
               'dirs': {k: os.path.isdir(os.environ.get(k, '')) for k in TEMP_VARIABLES}}, f)
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
        self.claude = os.path.join(self.tmp, 'claude')
        write(self.claude, FAKE_CLAUDE, 0o755)
        self.record = os.path.join(self.tmp, 'record.json')
        self.security_log = os.path.join(self.tmp, 'security.log')
        self.origin = os.path.join(self.tmp, 'origin.git')
        self.clone = os.path.join(self.home, 'Development/claude/shop')
        self.work = os.path.join(self.home, 'Development/claude/shop-X-1')
        self.make_repositories()

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
               'SSH_AUTH_SOCK': '/tmp/ssh-agent.sock', 'TMPDIR': self.tmpdir, **GIT_ENV}
        for key, value in overrides.items():
            if value is None:
                env.pop(key, None)
            else:
                env[key] = value
        return env

    def run_wrapper(self, *args, cwd=None, **env):
        return subprocess.run(['sh', WRAPPER, *args], cwd=cwd or self.work, capture_output=True, text=True,
                              env=self.env(**env))

    def recorded(self):
        with open(self.record) as f:
            return json.load(f)

    def started(self, *args, cwd=None, **env):
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
        for name in TEMP_VARIABLES:
            self.assertFalse(os.path.exists(record['env'][name]), name)
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
        result = self.run_wrapper(*AO_ARGS, FAKE_EXIT='7')
        self.assertEqual(result.returncode, 7, result.stderr)
        self.assert_removed(self.recorded())

    def test_stdin_reaches_the_binary(self):
        result = subprocess.run(['sh', WRAPPER, *AO_ARGS], cwd=self.work, capture_output=True, text=True,
                                env=self.env(FAKE_READ_STDIN='1'), input='{"type":"user"}\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.recorded()['stdin'], '{"type":"user"}\n')
        self.assertIn('fake claude ran', result.stdout)

    def run_and_signal(self, signum):
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


class RefusalTest(WrapperTestCase):
    def test_missing_variables(self):
        for name in ('AGENT_CLAUDE_BIN', 'AGENT_AO_HOME', 'AO_TASK_ID'):
            self.assertIn(name, self.refused(*AO_ARGS, **{name: None}))
            self.assertIn(name, self.refused(*AO_ARGS, **{name: ''}))

    def test_missing_token(self):
        stderr = self.refused(*AO_ARGS, FAKE_TOKEN=None)
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

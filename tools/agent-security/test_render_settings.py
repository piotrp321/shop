"""Tests of executor-settings.json and render_settings.py: python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

The CLI runs with HOME set to a temporary directory (resolved, so it has no symlink component), so the protected
directories and the template's ~ paths are inside it.
"""
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))
TEMPLATE = os.path.join(HERE, 'executor-settings.json')
SCRIPT = os.path.join(HERE, 'render_settings.py')


def load_sandbox_skip():
    spec = importlib.util.spec_from_file_location('sandbox_skip', os.path.join(HERE, 'sandbox_skip.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


sandbox_skip = load_sandbox_skip()


def load_module():
    spec = importlib.util.spec_from_file_location('render_settings', SCRIPT)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def template():
    with open(TEMPLATE) as f:
        return json.load(f)


class ProfileTest(unittest.TestCase):
    """The template itself (spec R2)."""

    def setUp(self):
        self.settings = template()
        self.sandbox = self.settings['sandbox']
        self.permissions = self.settings['permissions']

    def test_sandbox_cannot_be_escaped(self):
        self.assertIs(self.sandbox['enabled'], True)
        self.assertIs(self.sandbox['failIfUnavailable'], True)
        self.assertIs(self.sandbox['allowUnsandboxedCommands'], False)
        self.assertNotIn('excludedCommands', self.sandbox)
        self.assertIs(self.sandbox['enableWeakerNetworkIsolation'], True)

    def test_home_and_shared_temporary_directories_are_not_readable(self):
        deny = self.sandbox['filesystem']['denyRead']
        for path in ('~', '/Volumes', '/private/tmp', '/tmp'):
            self.assertIn(path, deny)

    def test_only_listed_home_paths_are_readable(self):
        allow = self.sandbox['filesystem']['allowRead']
        for path in allow:
            self.assertTrue(path.startswith('~/'), path)
        for secret in ('~/.ssh', '~/.kube', '~/.config', '~/Library', '~/.claude', '~/.local'):
            self.assertNotIn(secret, allow)

    def test_network_only_to_github_and_the_local_stack(self):
        network = self.sandbox['network']
        self.assertTrue(network['allowedDomains'])
        for domain in network['allowedDomains']:
            self.assertTrue(domain == 'github.com' or domain.endswith('.github.com')
                            or domain.endswith('.githubusercontent.com'), domain)
        self.assertIs(network['strictAllowlist'], True)
        self.assertIs(network['allowLocalBinding'], True)
        self.assertNotIn('allowAllUnixSockets', network)
        self.assertIn('~/.docker/run/docker.sock', network['allowUnixSockets'])

    def test_deny_rules(self):
        deny = self.permissions['deny']
        for rule in ('WebFetch', 'WebSearch', 'Edit(.ao/**)', 'Read(~/.ssh/**)', 'Read(~/.kube/**)',
                     'Read(~/.config/**)'):
            self.assertIn(rule, deny)
        self.assertNotIn('allow', self.permissions)

    def test_permission_mode_and_hooks(self):
        self.assertIs(self.permissions['blockReadsOutsideWorkingDirectories'], True)
        self.assertEqual(self.permissions['disableBypassPermissionsMode'], 'disable')
        self.assertIs(self.settings['disableAllHooks'], True)

    def test_paths_of_a_task_come_only_from_the_render(self):
        self.assertEqual(self.sandbox['filesystem']['allowWrite'], [])
        self.assertEqual(self.sandbox['filesystem']['denyWrite'], [])
        self.assertEqual(self.permissions['additionalDirectories'], [])


class RenderTestCase(unittest.TestCase):
    def setUp(self):
        self.tmp = os.path.realpath(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.home = os.path.join(self.tmp, 'home')
        self.clone = self.mkdir('home/Development/claude/shop')
        self.git = self.mkdir('home/Development/claude/shop/.git')
        self.work = self.mkdir('home/Development/claude/shop-task')
        self.task = self.mkdir('home/.local/state/ao-x/tasks/X-1')
        self.integration = self.mkdir('home/.local/state/ao-x/epics/x/integration')
        self.brief = self.mkdir('home/.local/state/ao-x/tasks/X-1/briefs')
        for name in ('.ssh', '.kube', '.config/git', 'Library/Keychains', '.claude', '.docker',
                     '.local/state/shop-ops'):
            self.mkdir('home/' + name)

    def mkdir(self, relative):
        path = os.path.join(self.tmp, relative)
        os.makedirs(path, exist_ok=True)
        return path

    def run_cli(self, *args):
        env = dict(os.environ, HOME=self.home)
        return subprocess.run([sys.executable, SCRIPT, *args], capture_output=True, text=True, env=env)

    def render(self, *args):
        result = self.run_cli(*args)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def refused(self, *args):
        result = self.run_cli(*args)
        self.assertNotEqual(result.returncode, 0, 'accepted: %r' % (args,))
        self.assertEqual(result.stdout, '')
        self.assertTrue(result.stderr.strip())
        return result.stderr


class RenderPathsTest(RenderTestCase):
    def full(self, *extra):
        return self.render('--workdir', self.work, '--git-common-dir', self.git, '--state-dir', self.task,
                           '--state-dir', self.integration, '--read-dir', self.clone + '/apps',
                           '--deny-write', self.task + '/briefs', '--deny-write', self.task + '/attempts', *extra)

    def setUp(self):
        super().setUp()
        self.mkdir('home/Development/claude/shop/apps')

    def test_options_give_the_expected_paths(self):
        settings = self.full()
        fs = settings['sandbox']['filesystem']
        apps = self.clone + '/apps'
        self.assertEqual([p for p in fs['allowRead'] if p.startswith(self.tmp)][-5:],
                         [self.work, self.git, self.task, self.integration, apps])
        self.assertEqual(fs['allowWrite'], [self.work, self.git, self.task, self.integration])
        for path in (self.work + '/.ao', self.work + '/.git', self.git + '/hooks', self.git + '/config*',
                     self.git + '/worktrees/*/config*', self.git + '/worktrees/*/commondir',
                     self.git + '/worktrees/*/gitdir', self.task + '/briefs', self.task + '/attempts'):
            self.assertIn(path, fs['denyWrite'])
        self.assertEqual(settings['permissions']['additionalDirectories'], [self.task, self.integration, apps])

    def test_deny_write_paths_are_also_edit_deny_rules(self):
        deny = self.full()['permissions']['deny']
        for path in (self.work + '/.ao', self.git + '/hooks', self.task + '/briefs'):
            self.assertIn('Edit(/%s)' % path, deny)
            self.assertIn('Edit(/%s/**)' % path, deny)

    def test_template_home_paths_are_made_absolute(self):
        settings = self.full()
        fs = settings['sandbox']['filesystem']
        self.assertIn(self.home, fs['denyRead'])
        self.assertIn(self.home + '/.gitconfig', fs['allowRead'])
        self.assertNotIn('~', fs['denyRead'])
        self.assertIn(self.home + '/.docker/run/docker.sock', settings['sandbox']['network']['allowUnixSockets'])

    def test_only_the_workdir_is_required(self):
        settings = self.render('--workdir', self.work)
        fs = settings['sandbox']['filesystem']
        self.assertEqual(fs['allowWrite'], [self.work])
        self.assertIn(self.work + '/.git', fs['denyWrite'])
        self.assertEqual(settings['permissions']['additionalDirectories'], [])
        self.refused('--git-common-dir', self.git)

    def test_a_main_clone_keeps_its_git_directory_writable(self):
        fs = self.render('--workdir', self.clone, '--git-common-dir', self.git)['sandbox']['filesystem']
        self.assertNotIn(self.clone + '/.git', fs['denyWrite'])
        self.assertIn(self.git + '/hooks', fs['denyWrite'])

    def test_no_network_drops_the_domains_and_local_binding(self):
        network = self.full('--no-network')['sandbox']['network']
        self.assertEqual(network['allowedDomains'], [])
        self.assertIs(network['allowLocalBinding'], False)
        self.assertIs(network['strictAllowlist'], True)
        self.assertTrue(network['allowUnixSockets'])

    def test_no_docker_drops_the_docker_socket(self):
        settings = self.full('--no-docker')
        self.assertEqual(settings['sandbox']['network']['allowUnixSockets'], [])
        self.assertTrue(settings['sandbox']['network']['allowedDomains'])

    def test_no_option_opens_a_shared_temporary_directory(self):
        # Only --claude-tmpdir touches the system temp, and only for a fresh run directory (ClaudeTmpdirTest); nothing
        # re-opens /tmp, /private/tmp or the user's /var/folders temp, so a symlink planted there cannot redirect access.
        for value in ('/tmp/claude-501', '/private/tmp/claude-501', '/private/var/folders/x/T'):
            self.refused('--workdir', self.work, '--open-temp', value)
        for args in (('--workdir', self.work), ('--workdir', self.work, '--git-common-dir', self.git,
                                                '--state-dir', self.task)):
            fs = self.render(*args)['sandbox']['filesystem']
            for path in fs['allowRead'] + fs['allowWrite']:
                if path.startswith(self.tmp + '/'):
                    continue   # the test's own directories (HOME) happen to live in the system temp
                self.assertFalse(path.startswith(('/tmp', '/private/tmp', '/var/folders', '/private/var')), path)

    def test_the_module_has_no_other_temp_option(self):
        module = load_module()
        with self.assertRaises(TypeError):
            module.render(template(), self.home, self.work, temp_dirs=['/tmp/claude-501'])

    def test_the_module_renders_the_same(self):
        module = load_module()
        settings = module.render(template(), self.home, self.work, git_common_dir=self.git, state_dirs=[self.task])
        self.assertEqual(settings, self.render('--workdir', self.work, '--git-common-dir', self.git,
                                               '--state-dir', self.task))


class RenderValidationTest(RenderTestCase):
    def access_options(self, path):
        return (('--workdir', path), ('--state-dir', path), ('--read-dir', path))

    def assert_refused_everywhere(self, path):
        for option, value in self.access_options(path):
            args = ('--workdir', self.work, option, value) if option != '--workdir' else (option, value)
            self.refused(*args)

    def test_relative_path(self):
        self.assert_refused_everywhere('home/Development')

    def test_missing_path(self):
        self.assert_refused_everywhere(self.tmp + '/nope')

    def test_file_instead_of_a_directory(self):
        path = os.path.join(self.work, 'file')
        open(path, 'w').close()
        self.assert_refused_everywhere(path)

    def test_symlink_as_the_last_component(self):
        link = os.path.join(self.tmp, 'link')
        os.symlink(self.task, link)
        self.assert_refused_everywhere(link)

    def test_symlink_in_a_middle_component(self):
        os.symlink(os.path.join(self.home, '.local'), os.path.join(self.tmp, 'local'))
        self.assert_refused_everywhere(os.path.join(self.tmp, 'local/state/ao-x/tasks/X-1'))

    def test_dot_dot_and_redundant_separators(self):
        self.assert_refused_everywhere(self.task + '/../X-1')
        self.assert_refused_everywhere(self.task + '/')

    def test_root_home_and_ancestors_of_home(self):
        for path in ('/', self.home, self.tmp, os.path.dirname(self.tmp)):
            self.assert_refused_everywhere(path)

    def test_protected_directories_their_descendants_and_ancestors(self):
        for relative in ('.ssh', '.kube', '.config', '.config/git', 'Library', 'Library/Keychains', '.claude',
                         '.docker', '.local/state/shop-ops', '.local/state', '.local'):
            self.assert_refused_everywhere(os.path.join(self.home, relative))

    def test_glob_and_control_characters(self):
        for name in ('a*', 'b?', 'c[1]', 'd\nx'):
            path = self.mkdir('home/Development/' + name)
            self.assert_refused_everywhere(path)

    def test_git_common_dir_must_be_a_git_directory(self):
        self.refused('--workdir', self.work, '--git-common-dir', self.clone)
        self.refused('--workdir', self.work, '--git-common-dir', self.home + '/.ssh')
        link = os.path.join(self.tmp, '.git')
        os.symlink(self.git, link)
        self.refused('--workdir', self.work, '--git-common-dir', link)

    def test_deny_write_must_be_absolute(self):
        self.refused('--workdir', self.work, '--deny-write', 'briefs')
        self.refused('--workdir', self.work, '--deny-write', self.task + '/a\nb')

    def test_missing_workdir_and_unknown_options(self):
        self.refused()
        self.refused('--workdir', self.work, '--allow-read', self.task)
        self.refused('--workdir', self.work, '--template', TEMPLATE)

    def test_missing_or_broken_template(self):
        tmp = os.path.realpath(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, tmp, True)
        script = os.path.join(tmp, 'render_settings.py')
        shutil.copy(SCRIPT, script)
        env = dict(os.environ, HOME=self.home)
        result = subprocess.run([sys.executable, script, '--workdir', self.work], capture_output=True, text=True,
                                env=env)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')
        with open(os.path.join(tmp, 'executor-settings.json'), 'w') as f:
            f.write('{"sandbox": ')
        result = subprocess.run([sys.executable, script, '--workdir', self.work], capture_output=True, text=True,
                                env=env)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')


TEMP_PARENT = os.path.realpath('/tmp')   # /private/tmp on macOS, /tmp on Linux
CLAUDE_UID_DIR = 'claude-%d' % os.getuid()


class ClaudeTmpdirTest(RenderTestCase):
    """--claude-tmpdir: the CLAUDE_CODE_TMPDIR of one executor run, a fresh directory the wrapper made directly in the
    system temp. Only its claude-<uid> subdirectory (the TMPDIR Claude Code gives to Bash) is opened, never the run
    directory itself (the session's messaging sockets), /tmp, or the per-uid directory shared by other sessions."""

    def run_dir(self, mode=0o700, inner_mode=0o700, inner=True, inner_name=CLAUDE_UID_DIR):
        # Run directories are made in the system temp, which the executor's sandbox does not allow: skipped there
        # (with the reason), never on a host or in CI (sandbox_skip.py).
        sandbox_skip.require_system_tmp(self)
        path = tempfile.mkdtemp(prefix='agent.', dir=TEMP_PARENT)
        self.addCleanup(shutil.rmtree, path, True)
        if inner:
            os.mkdir(os.path.join(path, inner_name))
            os.chmod(os.path.join(path, inner_name), inner_mode)
        os.chmod(path, mode)
        return path

    def test_only_the_claude_directory_of_the_run_is_opened(self):
        # Compared with the same render without --claude-tmpdir, so the paths of the test's own directory (in the temp
        # of the process, which may itself be under /tmp, as on Linux without TMPDIR) do not count, wherever it is.
        run = self.run_dir()
        fs = self.render('--workdir', self.work, '--claude-tmpdir', run)['sandbox']['filesystem']
        without = self.render('--workdir', self.work)['sandbox']['filesystem']
        inner = os.path.join(run, CLAUDE_UID_DIR)
        for key in ('allowRead', 'allowWrite'):
            self.assertEqual(set(fs[key]) - set(without[key]), {inner}, key)
            self.assertEqual(set(without[key]) - set(fs[key]), set(), key)
        shared = {'/tmp', '/private/tmp', TEMP_PARENT, run, os.path.join(TEMP_PARENT, CLAUDE_UID_DIR)}
        for path in fs['allowRead'] + fs['allowWrite']:
            self.assertNotIn(path, shared)
            self.assertFalse(path != inner and (inner + '/').startswith(path.rstrip('/') + '/'),
                             '%s opens the run directory or above it' % path)
            self.assertFalse(path != inner and path.startswith(run + '/'), path)
        for path in ('/tmp', '/private/tmp'):
            self.assertIn(path, fs['denyRead'])

    def test_the_module_renders_the_same(self):
        run = self.run_dir()
        settings = load_module().render(template(), self.home, self.work, claude_tmpdir=run)
        self.assertEqual(settings, self.render('--workdir', self.work, '--claude-tmpdir', run))

    def test_the_system_temp_itself_is_refused(self):
        for path in ('/tmp', '/private/tmp', TEMP_PARENT):
            self.refused('--workdir', self.work, '--claude-tmpdir', path)

    def test_the_shared_per_uid_directory_of_other_sessions_is_refused(self):
        shared = os.path.join(TEMP_PARENT, CLAUDE_UID_DIR)
        self.refused('--workdir', self.work, '--claude-tmpdir', shared)
        self.refused('--workdir', self.work, '--claude-tmpdir', os.path.join(TEMP_PARENT, 'cc-socks'))

    def test_a_directory_another_session_already_uses_is_refused(self):
        used = self.run_dir()
        os.mkdir(os.path.join(used, 'cc-socks'))
        self.refused('--workdir', self.work, '--claude-tmpdir', used)
        busy = self.run_dir()
        os.mkdir(os.path.join(busy, CLAUDE_UID_DIR, 'project'))
        self.refused('--workdir', self.work, '--claude-tmpdir', busy)

    def test_a_directory_without_its_claude_subdirectory_is_refused(self):
        self.refused('--workdir', self.work, '--claude-tmpdir', self.run_dir(inner=False))

    def test_other_permissions_are_refused(self):
        for mode, inner_mode in ((0o755, 0o700), (0o750, 0o700), (0o700, 0o755), (0o700, 0o777)):
            self.refused('--workdir', self.work, '--claude-tmpdir', self.run_dir(mode, inner_mode))

    def test_a_symlink_is_refused(self):
        run = self.run_dir()
        link = os.path.join(TEMP_PARENT, 'agent.link-%d' % os.getpid())
        os.symlink(run, link)
        self.addCleanup(os.unlink, link)
        self.refused('--workdir', self.work, '--claude-tmpdir', link)
        if TEMP_PARENT != '/tmp':   # macOS: /tmp is a symlink to /private/tmp
            self.refused('--workdir', self.work, '--claude-tmpdir', '/tmp/' + os.path.basename(run))
        inner_target = self.run_dir()
        swapped = self.run_dir(inner=False)
        os.symlink(os.path.join(inner_target, CLAUDE_UID_DIR), os.path.join(swapped, CLAUDE_UID_DIR))
        self.refused('--workdir', self.work, '--claude-tmpdir', swapped)

    def test_a_directory_deeper_or_outside_the_system_temp_is_refused(self):
        run = self.run_dir()
        self.refused('--workdir', self.work, '--claude-tmpdir', os.path.join(run, CLAUDE_UID_DIR))
        elsewhere = self.mkdir('home/Development/claude/run')
        os.mkdir(os.path.join(elsewhere, CLAUDE_UID_DIR), 0o700)
        os.chmod(elsewhere, 0o700)
        self.refused('--workdir', self.work, '--claude-tmpdir', elsewhere)
        self.refused('--workdir', self.work, '--claude-tmpdir', 'tmp/agent.x')

    def test_another_owner_is_refused(self):
        # As another user: the directory and its claude-<uid> subdirectory are named for that user but owned by us.
        other = os.getuid() + 1
        run = self.run_dir(inner_name='claude-%d' % other)
        module = load_module()
        with mock.patch.object(module.os, 'getuid', return_value=other):
            with self.assertRaises(module.SettingsError):
                module.render(template(), self.home, self.work, claude_tmpdir=run)


TELEMETRY_ATTRIBUTES = ('--telemetry-attribute', 'shop.task_kind=plan', '--telemetry-attribute', 'shop.task=X-1',
                        '--telemetry-attribute', 'shop.source=ao', '--telemetry-attribute', 'shop.epic=x')
TELEMETRY_ENV = {
    'CLAUDE_CODE_ENABLE_TELEMETRY': '1',
    'OTEL_METRICS_EXPORTER': 'otlp',
    'OTEL_LOGS_EXPORTER': 'otlp',
    'OTEL_EXPORTER_OTLP_PROTOCOL': 'http/protobuf',
    'OTEL_EXPORTER_OTLP_ENDPOINT': 'http://127.0.0.1:4318',
    'OTEL_METRIC_EXPORT_INTERVAL': '10000',
    'OTEL_LOGS_EXPORT_INTERVAL': '5000',
    'OTEL_LOG_USER_PROMPTS': '0',
    'OTEL_LOG_ASSISTANT_RESPONSES': '0',
    'OTEL_LOG_TOOL_DETAILS': '0',
    'OTEL_LOG_TOOL_CONTENT': '0',
    'OTEL_LOG_RAW_API_BODIES': '0',
    'OTEL_RESOURCE_ATTRIBUTES': 'shop.source=ao,shop.epic=x,shop.task=X-1,shop.task_kind=plan',
}


class TelemetryTest(RenderTestCase):
    """The telemetry block (spec TELEMETRY R1): an env block of the settings, only with the --telemetry-* options."""

    def base(self):
        return ('--workdir', self.work, '--git-common-dir', self.git, '--state-dir', self.task)

    def test_the_options_give_the_env_block(self):
        settings = self.render(*self.base(), '--telemetry-endpoint', 'http://127.0.0.1:4318', *TELEMETRY_ATTRIBUTES)
        self.assertEqual(settings['env'], TELEMETRY_ENV)
        self.assertEqual(list(settings['env']), list(TELEMETRY_ENV))

    def test_another_port_and_a_subset_of_attributes(self):
        settings = self.render(*self.base(), '--telemetry-endpoint', 'http://127.0.0.1:65535',
                               '--telemetry-attribute', 'shop.task=X-1', '--telemetry-attribute', 'shop.source=ao')
        self.assertEqual(settings['env']['OTEL_EXPORTER_OTLP_ENDPOINT'], 'http://127.0.0.1:65535')
        self.assertEqual(settings['env']['OTEL_RESOURCE_ATTRIBUTES'], 'shop.source=ao,shop.task=X-1')
        self.assertEqual(self.render(*self.base(), '--telemetry-endpoint', 'http://127.0.0.1:1')['env']
                         ['OTEL_EXPORTER_OTLP_ENDPOINT'], 'http://127.0.0.1:1')

    def test_attributes_without_an_endpoint_go_to_the_default_endpoint(self):
        module = load_module()
        self.assertEqual(module.DEFAULT_TELEMETRY_ENDPOINT, 'http://127.0.0.1:4318')
        settings = self.render(*self.base(), *TELEMETRY_ATTRIBUTES)
        self.assertEqual(settings['env'], TELEMETRY_ENV)

    def test_with_telemetry_only_the_env_key_is_added(self):
        without = self.render(*self.base())
        with_telemetry = self.render(*self.base(), '--telemetry-endpoint', 'http://127.0.0.1:4318',
                                     *TELEMETRY_ATTRIBUTES)
        self.assertNotIn('env', without)
        del with_telemetry['env']
        self.assertEqual(with_telemetry, without)

    def test_without_telemetry_the_output_is_unchanged(self):
        # The exact bytes of the CLI without the options: the render of the module (which has no env key) in the
        # compact form the CLI always printed.
        result = self.run_cli(*self.base())
        self.assertEqual(result.returncode, 0, result.stderr)
        module = load_module()
        expected = module.render(template(), self.home, self.work, git_common_dir=self.git, state_dirs=[self.task])
        self.assertNotIn('env', expected)
        self.assertEqual(result.stdout, json.dumps(expected, indent=None, separators=(',', ':')) + '\n')

    def test_the_module_renders_the_same(self):
        module = load_module()
        attributes = {'shop.task_kind': 'plan', 'shop.epic': 'x', 'shop.task': 'X-1', 'shop.source': 'ao'}
        settings = module.render(template(), self.home, self.work, git_common_dir=self.git, state_dirs=[self.task],
                                 telemetry={'endpoint': 'http://127.0.0.1:4318', 'attributes': attributes})
        self.assertEqual(settings, self.render(*self.base(), '--telemetry-endpoint', 'http://127.0.0.1:4318',
                                               *TELEMETRY_ATTRIBUTES))
        self.assertEqual(list(settings['env']), list(TELEMETRY_ENV))
        self.assertNotIn('env', module.render(template(), self.home, self.work))
        self.assertNotIn('env', module.render(template(), self.home, self.work, telemetry=None))

    def test_the_variables_are_module_constants(self):
        # The wrapper and tools/telemetry use them without a copy; the snippet of interactive sessions has no intervals.
        module = load_module()
        self.assertEqual(module.telemetry_env('http://127.0.0.1:4318', {'shop.source': 'ao', 'shop.epic': 'x',
                                                                         'shop.task': 'X-1', 'shop.task_kind': 'plan'}),
                         TELEMETRY_ENV)
        without_intervals = module.telemetry_env('http://127.0.0.1:4318', {'shop.source': 'interactive'},
                                                 intervals=False)
        expected = {k: v for k, v in TELEMETRY_ENV.items() if not k.endswith('_EXPORT_INTERVAL')}
        expected['OTEL_RESOURCE_ATTRIBUTES'] = 'shop.source=interactive'
        self.assertEqual(without_intervals, expected)
        self.assertEqual(list(without_intervals), list(expected))
        self.assertEqual(module.TELEMETRY_ATTRIBUTE_KEYS, ('shop.source', 'shop.epic', 'shop.task', 'shop.task_kind'))
        for name in ('OTEL_LOG_USER_PROMPTS', 'OTEL_LOG_ASSISTANT_RESPONSES', 'OTEL_LOG_TOOL_DETAILS',
                     'OTEL_LOG_TOOL_CONTENT', 'OTEL_LOG_RAW_API_BODIES'):
            self.assertIn((name, '0'), module.TELEMETRY_NO_CONTENT_ENV)

    def test_security_rules_stay_with_telemetry(self):
        settings = self.render(*self.base(), '--no-network', *TELEMETRY_ATTRIBUTES)
        without = self.render(*self.base(), '--no-network')
        del settings['env']
        self.assertEqual(settings, without)


class TelemetryValidationTest(RenderTestCase):
    """Only http://127.0.0.1:<port> and attributes of the list with values [A-Za-z0-9._-]{1,128}: anything else is
    exit code 1 (SettingsError), nothing on stdout, also for attempts to get around the check."""

    BAD_ENDPOINTS = (
        'http://localhost.evil:4318', 'http://127.0.0.1.nip.io:4318', 'http://127.0.0.1@host:4318',
        'http://user@127.0.0.1:4318', 'http://127.0.0.1:4318@evil:80', 'https://127.0.0.1:4318', 'http://[::1]:4318',
        'http://127.0.0.1:0', 'http://127.0.0.1:65536', 'http://127.0.0.1:99999', 'http://127.0.0.1:04318',
        'http://127.0.0.1:00', 'http://127.0.0.1:', 'http://127.0.0.1', 'http://127.0.0.1:4318/v1',
        'http://127.0.0.1:4318/', 'http://127.0.0.1:4318?x=1', 'http://127.0.0.1:4318#f', 'http://127.0.0.1:4318\n',
        'http://127.0.0.1:4318 ', ' http://127.0.0.1:4318', 'http://127.0.0.1:４３１８',
        'HTTP://127.0.0.1:4318', 'http://localhost:4318', 'http://127.1:4318', 'http://0.0.0.0:4318',
        'http://127.0.0.2:4318', 'http://2130706433:4318', 'http://127.0.0.1:+4318', 'http://127.0.0.1:-1',
        'http:/127.0.0.1:4318', '//127.0.0.1:4318', '127.0.0.1:4318', '', 'off',
    )
    BAD_ATTRIBUTES = (
        'shop.task=X-1,shop.task=other', 'shop.task=a=b', 'shop.task=', 'shop.task=' + 'a' * 129, 'shop.task=a b',
        'shop.task=a\nb', 'shop.task=X-1\n', 'shop.task=a ', 'shop.task=zadanie-ż',
        'shop.task=a ', 'shop.task=a/b', 'shop.task=a%2Cb', 'shop.other=x', 'service.name=x', 'SHOP.TASK=x',
        'shop.task', '=x', 'shop.task =x', ' shop.task=x',
    )

    def base(self):
        return ('--workdir', self.work)

    def test_bad_endpoints_are_refused_with_code_1(self):
        for endpoint in self.BAD_ENDPOINTS:
            with self.subTest(endpoint=endpoint):
                result = self.run_cli(*self.base(), '--telemetry-endpoint', endpoint)
                self.assertEqual(result.returncode, 1, (endpoint, result.stderr))
                self.assertEqual(result.stdout, '')
                self.assertIn('telemetry', result.stderr)

    def test_bad_attributes_are_refused_with_code_1(self):
        for attribute in self.BAD_ATTRIBUTES:
            with self.subTest(attribute=attribute):
                result = self.run_cli(*self.base(), '--telemetry-attribute', 'shop.source=ao',
                                      '--telemetry-attribute', attribute)
                self.assertEqual(result.returncode, 1, (attribute, result.stderr))
                self.assertEqual(result.stdout, '')
                self.assertIn('telemetry', result.stderr)

    def test_a_duplicate_key_is_refused(self):
        result = self.run_cli(*self.base(), '--telemetry-attribute', 'shop.task=X-1',
                              '--telemetry-attribute', 'shop.task=X-2')
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertEqual(result.stdout, '')

    def test_boundary_values_are_accepted(self):
        value = 'A.z_0-' + 'a' * 122
        settings = self.render(*self.base(), '--telemetry-endpoint', 'http://127.0.0.1:65535',
                               '--telemetry-attribute', 'shop.task=' + value, '--telemetry-attribute', 'shop.epic=a')
        self.assertEqual(settings['env']['OTEL_RESOURCE_ATTRIBUTES'], 'shop.epic=a,shop.task=' + value)

    def test_the_module_refuses_the_same(self):
        module = load_module()
        for telemetry in ({'endpoint': 'http://127.0.0.1.nip.io:4318', 'attributes': {}},
                          {'endpoint': 'http://127.0.0.1:4318', 'attributes': {'shop.task': 'a,b'}},
                          {'endpoint': 'http://127.0.0.1:4318', 'attributes': {'other': 'a'}},
                          {'endpoint': 'http://127.0.0.1:4318', 'attributes': {'shop.task': 1}},
                          {'endpoint': 4318, 'attributes': {}},
                          {'endpoint': 'http://127.0.0.1:4318', 'attributes': [('shop.task', 'a')]},
                          {'endpoint': 'http://127.0.0.1:4318', 'attributes': {}, 'headers': 'x'},
                          # NUL cannot be passed in argv, only through the module
                          {'endpoint': 'http://127.0.0.1:43\x0018', 'attributes': {}},
                          {'endpoint': 'http://127.0.0.1:4318', 'attributes': {'shop.task': 'a\x00'}},
                          'http://127.0.0.1:4318'):
            with self.subTest(telemetry=telemetry):
                with self.assertRaises(module.SettingsError):
                    module.render(template(), self.home, self.work, telemetry=telemetry)


class SecurityRulesStayTest(RenderTestCase):
    """No combination of options removes or weakens a rule of the template."""

    def combinations(self):
        base = ('--workdir', self.work)
        return [base, base + ('--git-common-dir', self.git), base + ('--no-network',), base + ('--no-docker',),
                base + ('--no-network', '--no-docker', '--state-dir', self.task, '--read-dir', self.integration,
                        '--deny-write', self.task + '/briefs', '--git-common-dir', self.git)]

    def test_rules_of_the_template_stay(self):
        source = template()
        for args in self.combinations():
            settings = self.render(*args)
            sandbox, fs = settings['sandbox'], settings['sandbox']['filesystem']
            self.assertIs(sandbox['enabled'], True)
            self.assertIs(sandbox['failIfUnavailable'], True)
            self.assertIs(sandbox['allowUnsandboxedCommands'], False)
            self.assertNotIn('excludedCommands', sandbox)
            self.assertIs(settings['disableAllHooks'], True)
            self.assertIs(settings['permissions']['blockReadsOutsideWorkingDirectories'], True)
            self.assertEqual(settings['permissions']['disableBypassPermissionsMode'], 'disable')
            self.assertEqual(len(fs['denyRead']), len(source['sandbox']['filesystem']['denyRead']))
            self.assertIn(self.home, fs['denyRead'])
            for rule in source['permissions']['deny']:
                self.assertIn(rule, settings['permissions']['deny'])
            self.assertIn(self.work + '/.ao', fs['denyWrite'])
            self.assertNotIn('allow', settings['permissions'])
            self.assertIs(sandbox['network']['strictAllowlist'], True)

    def test_read_dirs_are_not_writable(self):
        settings = self.render('--workdir', self.work, '--state-dir', self.task, '--read-dir', self.integration)
        fs = settings['sandbox']['filesystem']
        self.assertNotIn(self.task, fs['denyRead'])
        self.assertNotIn(self.integration, fs['allowWrite'])


if __name__ == '__main__':
    unittest.main()

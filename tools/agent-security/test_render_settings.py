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

HERE = os.path.dirname(os.path.abspath(__file__))
TEMPLATE = os.path.join(HERE, 'executor-settings.json')
SCRIPT = os.path.join(HERE, 'render_settings.py')


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

    def test_open_temp_reopens_a_denied_temp_path(self):
        # a /tmp subpath that need not exist and whose /tmp form is a symlink is still accepted (trusted wrapper input)
        settings = self.render('--workdir', self.work, '--open-temp', '/tmp/claude-501',
                               '--open-temp', '/private/tmp/claude-501')
        fs = settings['sandbox']['filesystem']
        self.assertIn('/tmp/claude-501', fs['allowRead'])
        self.assertIn('/tmp/claude-501', fs['allowWrite'])
        self.assertIn('/private/tmp/claude-501', fs['allowRead'])
        self.assertIn(self.home, fs['denyRead'])  # denyRead ~ (expanded) still present

    def test_open_temp_rejects_home_and_protected_paths(self):
        self.refused('--workdir', self.work, '--open-temp', self.home)
        self.refused('--workdir', self.work, '--open-temp', os.path.join(self.home, '.ssh'))
        self.refused('--workdir', self.work, '--open-temp', '/')
        self.refused('--workdir', self.work, '--open-temp', 'relative/tmp')
        self.refused('--workdir', self.work, '--open-temp', '/tmp/a*')

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

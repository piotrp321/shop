"""Tests of the guarded executor environment and of the sandbox settings (threat model paths 2, 3, 4, 7 and 8)."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest

from testutil import TempRepo

import evals_checkout as checkout
import evals_guard as guard

# assembled at run time: the scan of the repository must not find token-like literals in a test file
SECRETS = {'GH_TOKEN': 'gh' + 'p_secret', 'GITHUB_TOKEN': 'gh' + 's_secret', 'SSH_AUTH_SOCK': '/private/agent.sock',
           'GIT_CONFIG_COUNT': '1', 'GIT_CONFIG_KEY_0': 'url.https://github.com/.insteadOf',
           'GIT_CONFIG_VALUE_0': 'git@github.com:', 'GIT_CONFIG_PARAMETERS': "'credential.helper=store'",
           'GIT_ASKPASS': '/bin/echo', 'SSH_ASKPASS': '/bin/echo', 'DOCKER_HOST': 'unix:///var/run/docker.sock',
           'CLAUDECODE': '1', 'ANTHROPIC_MODEL': 'some-model', 'CLAUDE_CODE_SUBAGENT_MODEL': 'some-model'}


class GuardTestCase(unittest.TestCase):
    def setUp(self):
        self.repo = TempRepo()
        self.addCleanup(self.repo.cleanup)
        self.repo.write('src/app.txt', 'app\n')
        self.commit = self.repo.commit('state')
        self.state = os.path.realpath(tempfile.mkdtemp(prefix='evals-guard-'))
        self.addCleanup(shutil.rmtree, self.state, True)
        self.pass_dir = os.path.join(self.state, 'pass')
        os.makedirs(self.pass_dir)

    def layout(self, responses=()):
        return guard.prepare(self.pass_dir, list(responses))

    def base_env(self):
        base = dict(os.environ)
        base.update(SECRETS)
        for name in guard.PAID_API_VARIABLES:
            base[name] = 'set'
        return base


class EnvironmentTest(GuardTestCase):
    def test_tokens_agent_sockets_git_overrides_and_paid_api_variables_are_not_passed(self):
        env = guard.environment(self.layout(), self.base_env())
        for name in list(SECRETS) + list(guard.PAID_API_VARIABLES):
            if name != 'DOCKER_HOST':
                self.assertNotIn(name, env)
        for name in ('ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'ANTHROPIC_BASE_URL', 'CLAUDE_CODE_USE_BEDROCK',
                     'CLAUDE_CODE_USE_VERTEX', 'CLAUDE_CODE_USE_FOUNDRY', 'AWS_BEARER_TOKEN_BEDROCK', 'OPENAI_API_KEY',
                     'CLAUDE_CODE_OAUTH_TOKEN'):
            self.assertIn(name, guard.PAID_API_VARIABLES)
            self.assertNotIn(name, env)
        self.assertFalse([key for key in env if key.startswith('GIT_CONFIG_') and key not in
                          ('GIT_CONFIG_GLOBAL', 'GIT_CONFIG_NOSYSTEM')])

    def test_the_guards_of_git_gh_and_docker_are_set(self):
        layout = self.layout()
        env = guard.environment(layout, self.base_env())
        self.assertEqual('file', env['GIT_ALLOW_PROTOCOL'])
        self.assertEqual('1', env['GIT_CONFIG_NOSYSTEM'])
        self.assertEqual('0', env['GIT_TERMINAL_PROMPT'])
        self.assertTrue(env['GIT_CONFIG_GLOBAL'].startswith(self.pass_dir + os.sep))
        self.assertTrue(os.path.isfile(env['GIT_CONFIG_GLOBAL']))
        self.assertTrue(env['GH_CONFIG_DIR'].startswith(self.pass_dir + os.sep))
        self.assertEqual([], os.listdir(env['GH_CONFIG_DIR']))
        self.assertTrue(env['DOCKER_HOST'].startswith('unix://' + self.pass_dir + os.sep))
        self.assertFalse(os.path.exists(env['DOCKER_HOST'][len('unix://'):]))
        self.assertEqual(layout['bin'], env['PATH'].split(os.pathsep)[0])
        self.assertEqual(os.environ['HOME'], env['HOME'])

    def test_the_global_git_config_has_no_credential_helper(self):
        env = guard.environment(self.layout(), self.base_env())
        result = subprocess.run(['git', 'config', '--get-all', 'credential.helper'], env=env, stdout=subprocess.PIPE,
                                universal_newlines=True, cwd=self.pass_dir)
        self.assertEqual('', result.stdout.strip())


class PushTest(GuardTestCase):
    def setUp(self):
        GuardTestCase.setUp(self)
        self.layout_ = self.layout()
        self.built = checkout.build(self.repo.root, self.pass_dir, {'commit': self.commit}, self.commit,
                                    ['.claude/agents/code-reviewer.md'])
        self.env = guard.environment(self.layout_, self.base_env())

    def git(self, *args, env=None):
        return subprocess.run(['git'] + list(args), cwd=self.built['checkout'], env=env or self.env,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, timeout=60)

    def log(self, name):
        with open(os.path.join(self.pass_dir, name), encoding='utf-8') as handle:
            return [json.loads(line) for line in handle if line.strip()]

    def test_push_over_ssh_and_https_fails_without_a_connection_and_is_in_ssh_log(self):
        urls = ['git@github.com:piotrp321/shop.git', 'https://github.com/piotrp321/shop.git',
                'ssh://git@github.com/piotrp321/shop.git']
        for url in urls:
            result = self.git('push', url, 'HEAD:refs/heads/leak')
            self.assertNotEqual(0, result.returncode, url)
            self.assertIn('not allowed', result.stderr, url)   # refused by git before any connection
        guard.collect_git_attempts(self.layout_)
        logged = self.log('ssh.log')
        for url in urls:
            self.assertTrue(any(url in entry['args'] for entry in logged), (url, logged))

    def test_ssh_with_the_protocol_allowed_reaches_only_the_failing_stub(self):
        env = dict(self.env, GIT_ALLOW_PROTOCOL='file:ssh')
        result = self.git('push', 'git@github.com:piotrp321/shop.git', 'HEAD:refs/heads/leak', env=env)
        self.assertNotEqual(0, result.returncode)
        logged = self.log('ssh.log')
        self.assertEqual('ssh', logged[0]['via'])
        self.assertTrue(any('github.com' in arg for arg in logged[0]['args']))

    def test_push_to_origin_lands_in_the_bare_repository(self):
        result = self.git('push', 'origin', 'HEAD:refs/heads/agent-branch')
        self.assertEqual(0, result.returncode, result.stderr)
        branches = subprocess.run(['git', '-C', self.built['origin'], 'branch', '--list', 'agent-branch'],
                                  stdout=subprocess.PIPE, universal_newlines=True).stdout
        self.assertIn('agent-branch', branches)
        guard.collect_git_attempts(self.layout_)
        self.assertEqual([], self.log('ssh.log'))

    def test_a_planted_trace_or_log_symlink_is_not_followed(self):
        outside = os.path.join(self.state, 'outside.txt')
        with open(outside, 'w') as handle:
            handle.write('keep\n')
        os.remove(os.path.join(self.pass_dir, 'ssh.log'))
        os.symlink(outside, os.path.join(self.pass_dir, 'ssh.log'))
        self.git('push', 'https://github.com/piotrp321/shop.git', 'HEAD')
        guard.collect_git_attempts(self.layout_)
        with open(outside) as handle:
            self.assertEqual('keep\n', handle.read())
        self.assertFalse(os.path.islink(os.path.join(self.pass_dir, 'ssh.log')))
        self.assertEqual(1, len(self.log('ssh.log')))


class GhStubTest(GuardTestCase):
    RESPONSES = [{'args': ['issue', 'view', '7', '--json', 'title,body'], 'stdout': '{"title": "t", "body": "b"}\n'}]

    def gh(self, *args):
        if not hasattr(self, 'env'):
            self.env = guard.environment(self.layout(self.RESPONSES), self.base_env())
        return subprocess.run(['gh'] + list(args), env=self.env, cwd=self.pass_dir, stdout=subprocess.PIPE,
                              stderr=subprocess.PIPE, universal_newlines=True)

    def log(self):
        with open(os.path.join(self.pass_dir, 'gh.log'), encoding='utf-8') as handle:
            return [json.loads(line) for line in handle if line.strip()]

    def test_case_data_is_served_and_logged(self):
        result = self.gh('issue', 'view', '7', '--json', 'title,body')
        self.assertEqual(0, result.returncode)
        self.assertEqual('{"title": "t", "body": "b"}\n', result.stdout)
        self.assertEqual([{'args': ['issue', 'view', '7', '--json', 'title,body'], 'served': True, 'exit_code': 0}],
                         [{k: e[k] for k in ('args', 'served', 'exit_code')} for e in self.log()])

    def test_a_write_fails_and_is_logged(self):
        for args in (['pr', 'merge', '5', '--squash'], ['issue', 'close', '7'], ['label', 'create', 'x']):
            result = self.gh(*args)
            self.assertNotEqual(0, result.returncode, args)
            self.assertIn('evals', result.stderr)
        logged = self.log()
        self.assertEqual([['pr', 'merge', '5', '--squash'], ['issue', 'close', '7'], ['label', 'create', 'x']],
                         [entry['args'] for entry in logged])
        self.assertTrue(all(not entry['served'] and entry['exit_code'] != 0 for entry in logged))

    def test_the_logs_are_empty_without_calls(self):
        self.layout()
        for name in ('gh.log', 'ssh.log'):
            self.assertEqual(0, os.path.getsize(os.path.join(self.pass_dir, name)))


class SandboxSettingsTest(GuardTestCase):
    def setUp(self):
        GuardTestCase.setUp(self)
        self.checkout = os.path.join(self.pass_dir, 'checkout')
        os.makedirs(self.checkout)
        self.scripts = tempfile.mkdtemp(prefix='evals-render-')
        self.addCleanup(shutil.rmtree, self.scripts, True)

    def fake_render(self, settings=None, code=0, record=None):
        path = os.path.join(self.scripts, 'render_settings.py')
        with open(path, 'w') as handle:
            handle.write('import json, sys\n')
            if record:
                handle.write('open(%r, "w").write(json.dumps(sys.argv[1:]))\n' % record)
            handle.write('print(json.dumps(%r))\nsys.exit(%d)\n' % (settings, code))
        return path

    def valid(self):
        return {'sandbox': {'enabled': True, 'failIfUnavailable': True, 'allowUnsandboxedCommands': False,
                            'network': {'allowedDomains': [], 'allowUnixSockets': []}}}

    def render(self, script):
        return guard.render_settings(self.checkout, self.pass_dir, [], script=script)

    def assertRefused(self, script, fragment):
        with self.assertRaises(guard.SandboxError) as raised:
            self.render(script)
        self.assertIn(fragment, str(raised.exception))

    def test_without_the_profile_script_the_run_is_refused(self):
        self.assertRefused(os.path.join(self.scripts, 'missing.py'), 'AGENT-SECURITY')

    def test_a_failing_render_is_refused(self):
        self.assertRefused(self.fake_render(code=1), 'render_settings')

    def test_a_profile_without_fail_if_unavailable_is_refused(self):
        settings = self.valid()
        del settings['sandbox']['failIfUnavailable']
        self.assertRefused(self.fake_render(settings), 'failIfUnavailable')
        settings['sandbox']['failIfUnavailable'] = False
        self.assertRefused(self.fake_render(settings), 'failIfUnavailable')

    def test_a_profile_allowing_unsandboxed_commands_is_refused(self):
        settings = self.valid()
        settings['sandbox']['allowUnsandboxedCommands'] = True
        self.assertRefused(self.fake_render(settings), 'allowUnsandboxedCommands')

    def test_a_disabled_sandbox_is_refused(self):
        settings = self.valid()
        settings['sandbox']['enabled'] = False
        self.assertRefused(self.fake_render(settings), 'enabled')

    def test_network_domains_or_the_docker_socket_are_refused(self):
        settings = self.valid()
        settings['sandbox']['network']['allowedDomains'] = ['github.com']
        self.assertRefused(self.fake_render(settings), 'allowedDomains')
        settings = self.valid()
        settings['sandbox']['network']['allowUnixSockets'] = ['/var/run/docker.sock']
        self.assertRefused(self.fake_render(settings), 'docker')

    def test_the_arguments_are_runner_paths_after_realpath(self):
        record = os.path.join(self.scripts, 'argv.json')
        link = os.path.join(self.scripts, 'link-to-pass')
        os.symlink(self.pass_dir, link)
        guard.render_settings(os.path.join(link, 'checkout'), link, [os.path.join(link, 'runner')],
                              script=self.fake_render(self.valid(), record=record))
        with open(record) as handle:
            argv = json.load(handle)
        self.assertEqual(['--workdir', self.checkout, '--state-dir', self.pass_dir,
                          '--deny-write', os.path.join(self.pass_dir, 'runner'), '--no-network', '--no-docker'], argv)

    def test_the_real_profile_gives_only_the_paths_of_this_pass_and_no_network(self):
        settings = self.render(guard.RENDER_SCRIPT)
        sandbox = settings['sandbox']
        self.assertEqual([], sandbox['network']['allowedDomains'])
        self.assertTrue(sandbox['failIfUnavailable'])
        self.assertFalse(sandbox['allowUnsandboxedCommands'])
        template = guard.load_json(os.path.join(os.path.dirname(guard.RENDER_SCRIPT), 'executor-settings.json'))
        added = [p for p in sandbox['filesystem']['allowWrite'] if p not in template['sandbox']['filesystem']['allowWrite']]
        self.assertTrue(added)
        for path in added:
            self.assertTrue(path == self.pass_dir or path.startswith(self.pass_dir + os.sep), path)


if __name__ == '__main__':
    unittest.main()

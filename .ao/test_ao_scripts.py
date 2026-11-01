"""Tests of .ao/setup and .ao/teardown: python3 -m unittest discover -s .ao -p 'test_*.py'

The scripts run against fake `docker`, `make` and `curl` executables, so no stack is started.
"""
import os
import shutil
import socket
import stat
import subprocess
import tempfile
import unittest

AO_DIR = os.path.dirname(os.path.abspath(__file__))

FAKES = {
    'docker': '''#!/bin/sh
echo "docker $*" >> "$FAKE_LOG"
[ "$1" = info ] && [ -n "$FAKE_DOCKER_INFO_FAIL" ] && exit 1
case " $* " in *" down "*) [ -n "$FAKE_DOCKER_DOWN_FAIL" ] && exit 1;; esac
exit 0
''',
    'make': '''#!/bin/sh
echo "make $* project=$COMPOSE_PROJECT_NAME" >> "$FAKE_LOG"
[ "$1" = "$FAKE_MAKE_FAIL" ] && exit 2
exit 0
''',
    'curl': '''#!/bin/sh
echo "curl $*" >> "$FAKE_LOG"
[ -n "$FAKE_CURL_FAIL" ] && exit 22
exit 0
''',
}


def free_port_block(size=7):
    """A base port whose next `size` ports are free right now."""
    for _ in range(50):
        s = socket.socket()
        s.bind(('127.0.0.1', 0))
        base = s.getsockname()[1]
        s.close()
        if base + size > 65000:
            continue
        socks = []
        try:
            for p in range(base, base + size):
                t = socket.socket()
                t.bind(('127.0.0.1', p))
                socks.append(t)
            return base
        except OSError:
            continue
        finally:
            for t in socks:
                t.close()
    raise RuntimeError('no free port block')


class AoScriptsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = os.path.join(self.tmp.name, 'worktree')
        os.makedirs(os.path.join(self.root, '.ao'))
        for name in ('setup', 'teardown'):
            dest = os.path.join(self.root, '.ao', name)
            if os.path.exists(os.path.join(AO_DIR, name)):
                shutil.copy(os.path.join(AO_DIR, name), dest)
        self.bin = os.path.join(self.tmp.name, 'bin')
        os.makedirs(self.bin)
        for name, body in FAKES.items():
            path = os.path.join(self.bin, name)
            with open(path, 'w') as f:
                f.write(body)
            os.chmod(path, 0o755)
        self.log = os.path.join(self.tmp.name, 'calls.log')
        open(self.log, 'w').close()
        self.base = free_port_block()

    def tearDown(self):
        self.tmp.cleanup()

    def run_script(self, name, **env):
        full = {
            'PATH': self.bin + os.pathsep + os.environ['PATH'],
            'HOME': self.tmp.name,
            'FAKE_LOG': self.log,
            'AO_SETUP_READY_TIMEOUT': '3',
        }
        full.update({k: v for k, v in env.items() if v is not None})
        return subprocess.run(['sh', os.path.join(self.root, '.ao', name)], cwd=self.root, env=full,
                              capture_output=True, text=True, timeout=60)

    def setup(self, **env):
        env.setdefault('AO_COMPOSE_PROJECT', 'ao-t1-a1')
        env.setdefault('AO_PORT_BASE', str(self.base))
        return self.run_script('setup', **env)

    def teardown_(self, **env):
        env.setdefault('AO_COMPOSE_PROJECT', 'ao-t1-a1')
        return self.run_script('teardown', **env)

    def calls(self):
        with open(self.log) as f:
            return f.read().splitlines()

    def env_file(self):
        with open(os.path.join(self.root, '.env')) as f:
            return dict(line.split('=', 1) for line in f.read().splitlines() if '=' in line)

    def test_scripts_are_executable(self):
        for name in ('setup', 'teardown'):
            mode = os.stat(os.path.join(AO_DIR, name)).st_mode
            self.assertTrue(mode & stat.S_IXUSR, name)

    # setup

    def test_setup_writes_env_starts_the_stack_and_waits(self):
        r = self.setup()
        self.assertEqual(0, r.returncode, r.stderr)
        env = self.env_file()
        self.assertEqual('ao-t1-a1', env['COMPOSE_PROJECT_NAME'])
        ports = [int(env[k]) for k in ('STORE_PORT', 'ADMIN_PORT', 'API_PORT', 'KEYCLOAK_PORT', 'IMGPROXY_PORT',
                                       'MAILPIT_PORT', 'RABBITMQ_MANAGEMENT_PORT')]
        self.assertEqual(list(range(self.base, self.base + 7)), ports)
        calls = self.calls()
        self.assertIn('make up project=ao-t1-a1', calls)
        self.assertIn('make seed project=ao-t1-a1', calls)
        self.assertLess(calls.index('make up project=ao-t1-a1'), calls.index('make seed project=ao-t1-a1'))
        self.assertTrue(any(c.startswith('curl') and ':%d/health' % ports[2] in c for c in calls), calls)

    def test_setup_without_project_fails_before_touching_anything(self):
        r = self.setup(AO_COMPOSE_PROJECT='')
        self.assertNotEqual(0, r.returncode)
        self.assertIn('AO_COMPOSE_PROJECT', r.stderr)
        self.assertEqual([], self.calls())
        self.assertFalse(os.path.exists(os.path.join(self.root, '.env')))

    def test_setup_without_port_base_fails(self):
        r = self.setup(AO_PORT_BASE='')
        self.assertNotEqual(0, r.returncode)
        self.assertIn('AO_PORT_BASE', r.stderr)
        self.assertEqual([], self.calls())

    def test_setup_rejects_invalid_values(self):
        for name, value in (('AO_PORT_BASE', 'abc'), ('AO_PORT_BASE', '80'), ('AO_PORT_BASE', '65529'),
                            ('AO_COMPOSE_PROJECT', 'Bad Name'), ('AO_COMPOSE_PROJECT', '../x')):
            with self.subTest(name=name, value=value):
                open(self.log, 'w').close()
                r = self.setup(**{name: value})
                self.assertNotEqual(0, r.returncode)
                self.assertIn(name, r.stderr)
                self.assertEqual([], self.calls())

    def test_setup_fails_on_a_busy_port_without_starting_the_stack(self):
        busy = socket.socket()
        busy.bind(('127.0.0.1', self.base + 3))
        try:
            r = self.setup()
        finally:
            busy.close()
        self.assertNotEqual(0, r.returncode)
        self.assertIn(str(self.base + 3), r.stderr)
        self.assertNotIn('make up project=ao-t1-a1', self.calls())
        self.assertFalse(os.path.exists(os.path.join(self.root, '.env')))

    def test_setup_fails_when_docker_is_unavailable(self):
        r = self.setup(FAKE_DOCKER_INFO_FAIL='1')
        self.assertNotEqual(0, r.returncode)
        self.assertIn('docker', r.stderr.lower())
        self.assertFalse(any(c.startswith('make') for c in self.calls()))

    def test_setup_propagates_a_failing_make_up(self):
        r = self.setup(FAKE_MAKE_FAIL='up')
        self.assertNotEqual(0, r.returncode)
        self.assertIn('make up', r.stderr)
        self.assertNotIn('make seed project=ao-t1-a1', self.calls())

    def test_setup_fails_when_the_api_never_becomes_ready(self):
        r = self.setup(FAKE_CURL_FAIL='1', AO_SETUP_READY_TIMEOUT='2')
        self.assertNotEqual(0, r.returncode)
        self.assertIn('ready', r.stderr)

    # teardown

    def test_teardown_removes_the_project_with_its_volumes(self):
        r = self.teardown_()
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertIn('docker compose -p ao-t1-a1 down -v --remove-orphans', self.calls())

    def test_teardown_is_idempotent(self):
        self.assertEqual(0, self.teardown_().returncode)
        self.assertEqual(0, self.teardown_().returncode)
        self.assertEqual(2, sum(1 for c in self.calls() if ' down ' in c))

    def test_teardown_without_project_fails_and_does_not_call_docker(self):
        r = self.teardown_(AO_COMPOSE_PROJECT='')
        self.assertNotEqual(0, r.returncode)
        self.assertIn('AO_COMPOSE_PROJECT', r.stderr)
        self.assertEqual([], self.calls())

    def test_teardown_rejects_an_invalid_project_name(self):
        r = self.teardown_(AO_COMPOSE_PROJECT='-x; rm -rf')
        self.assertNotEqual(0, r.returncode)
        self.assertEqual([], self.calls())

    def test_teardown_fails_when_docker_is_unavailable(self):
        r = self.teardown_(FAKE_DOCKER_INFO_FAIL='1')
        self.assertNotEqual(0, r.returncode)
        self.assertFalse(any(' down ' in c for c in self.calls()))

    def test_teardown_propagates_a_failing_down(self):
        r = self.teardown_(FAKE_DOCKER_DOWN_FAIL='1')
        self.assertNotEqual(0, r.returncode)


if __name__ == '__main__':
    unittest.main()

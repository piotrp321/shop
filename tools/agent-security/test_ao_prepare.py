"""Tests of ao-prepare: python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

ao-prepare runs as a subprocess with HOME in a temporary directory, a real temporary git repository with a bare
`origin`, a fake claude_pin.py (--pin-script) and a fake probe.py committed to origin/main (the copy runs it). The
telemetry endpoint (AGENT_OTEL_ENDPOINT) is a local listening socket of the test, or a port nobody listens on. No
model, no Docker, no network.
"""
import fcntl
import importlib.machinery
import importlib.util
import json
import os
import shutil
import signal
import socket
import stat
import subprocess
import sys
import tempfile
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
AO_PREPARE = os.path.join(HERE, 'ao-prepare')
FILES = ('claude-agent', 'render_settings.py', 'executor-settings.json', 'probe.py', 'probe-cases.sh', 'bin/mktemp',
         'otlp_receiver.py')
EXECUTABLE = {'claude-agent', 'render_settings.py', 'probe.py', 'probe-cases.sh', 'bin/mktemp'}
loader = importlib.machinery.SourceFileLoader('ao_prepare', AO_PREPARE)
spec = importlib.util.spec_from_loader('ao_prepare', loader)
ao_prepare = importlib.util.module_from_spec(spec)
loader.exec_module(ao_prepare)
spec = importlib.util.spec_from_file_location('render_settings', os.path.join(HERE, 'render_settings.py'))
render_settings = importlib.util.module_from_spec(spec)
spec.loader.exec_module(render_settings)

# The probe committed to origin/main. FAKE_PROBE_MODE: pass, fail1, fail2, hang, noresult, badjson, failedcase,
# telemetry_warn, telemetry_warn_fail1, telemetry_fail, telemetry_bad, telemetry_pass, odd_status.
# FAKE_PROBE_STDERR=1: the probe prints a line of its own on stderr.
FAKE_PROBE = r'''#!/usr/bin/env python3
import json, os, sys, time
args = sys.argv[1:]
result = args[args.index('--result') + 1] if '--result' in args else None
with open(os.environ['FAKE_PROBE_RECORD'], 'w') as f:
    json.dump({'file': os.path.abspath(__file__), 'argv': args, 'cwd': os.getcwd(), 'pid': os.getpid(),
               'claude_env': sorted(k for k in os.environ if k.startswith('CLAUDE')), 'version': VERSION}, f)
mode = os.environ.get('FAKE_PROBE_MODE', 'pass')
if os.environ.get('FAKE_PROBE_STDERR'):
    sys.stderr.write('probe: own output\n')
    sys.stderr.flush()
if mode == 'noisy':
    print('AGENT_CLAUDE_BIN=/tmp/evil')
    print('probe diagnostics on stdout')
    sys.stdout.flush()
if mode == 'hang':
    time.sleep(600)
if mode == 'kubedir':
    # An operation starts while the probe runs: kubeconfig records a new KUBE_DIR.
    os.makedirs(os.environ['FAKE_KUBE_DIR'])
    with open(os.environ['FAKE_KUBE_REGISTRY'], 'a') as f:
        f.write(os.environ['FAKE_KUBE_DIR'] + '\n')
cases = [{'name': 'bash_cat_canary', 'layer': 'sandbox', 'kind': 'bypass', 'status': 'pass'}]
payload = {'mode': 'default', 'cases': cases, 'total_cost_usd': 0.0041, 'elapsed_s': 12.5, 'reason': None}
code = 0
if mode == 'fail1':
    payload.update(reason='przypadek bash_cat_canary nie przeszedł')
    cases[0]['status'] = 'fail'
    code = 1
elif mode == 'fail2':
    payload = {'mode': 'default', 'cases': [], 'total_cost_usd': None, 'reason': 'brak tokenu wykonawcy w Keychain'}
    code = 2
elif mode == 'failedcase':
    cases[0]['status'] = 'fail'
elif mode == 'notdict':
    payload['cases'] = ['bash_cat_canary']
elif mode == 'odd_status':
    cases[0]['status'] = 'no_export'
elif mode.startswith('telemetry_'):
    export = {'name': 'telemetry_export', 'layer': 'telemetry', 'kind': 'work', 'status': 'pass', 'reason': None}
    content = {'name': 'telemetry_no_content', 'layer': 'telemetry', 'kind': 'bypass', 'status': 'pass',
               'reason': None}
    cases += [export, content]
    if mode in ('telemetry_warn', 'telemetry_warn_fail1'):
        export.update(status='no_export', reason='odbiornik sondy nie dostał żądania /v1/logs')
        content.update(status='unverifiable', reason="ciało /v1/logs nie do sprawdzenia: nieznane Content-Encoding 'br'")
    if mode == 'telemetry_warn_fail1':
        cases[0]['status'] = 'fail'
        payload.update(reason='przypadek bash_cat_canary nie przeszedł')
        code = 1
    elif mode == 'telemetry_fail':
        content.update(status='fail', reason='ciało /v1/logs zawiera: znacznik promptu')
        payload.update(reason='przypadek telemetry_no_content nie przeszedł (ciało /v1/logs zawiera: znacznik promptu)')
        code = 1
    elif mode == 'telemetry_bad':
        export.update(status='unverifiable', reason='x')
if mode == 'badjson':
    open(result, 'w').write('{not json')
elif mode != 'noresult':
    with open(result, 'w') as f:
        json.dump(payload, f)
sys.exit(code)
'''

FAKE_PIN = r'''#!/usr/bin/env python3
import os, sys
mode = os.environ.get('FAKE_PIN_MODE', 'ok')
if mode == 'missing':
    sys.stderr.write('claude_pin: brak binarki Claude Code 2.1.311: zainstaluj ją komendą X\n')
    sys.exit(2)
if mode == 'pin':
    sys.stderr.write('claude_pin: brak jednego LifecycleConfig.claude_version\n')
    sys.exit(1)
if mode == 'relative':
    print('claude')
    sys.exit(0)
print(os.environ['FAKE_PIN_BINARY'])
'''


def git(cwd, *args):
    return subprocess.run(['git', '-C', cwd] + list(args), check=True, capture_output=True, text=True).stdout.strip()


def write(path, text, mode=0o644):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w') as f:
        f.write(text)
    os.chmod(path, mode)


def origin_content(name, version):
    if name == 'probe.py':
        return FAKE_PROBE.replace("'version': VERSION", "'version': %r" % version)
    return '#!/bin/sh\n# %s %s\n' % (name, version)


class AoPrepareTest(unittest.TestCase):
    def setUp(self):
        self.tmp = os.path.realpath(tempfile.mkdtemp(prefix='ao-prepare-test.'))
        self.addCleanup(shutil.rmtree, self.tmp, ignore_errors=True)
        # The telemetry stack: a socket that accepts connections (the kernel completes them in the backlog).
        self.stack = socket.socket()
        self.addCleanup(self.stack.close)
        self.stack.bind(('127.0.0.1', 0))
        self.stack.listen(16)
        self.stack_endpoint = 'http://127.0.0.1:%d' % self.stack.getsockname()[1]
        self.home = os.path.join(self.tmp, 'home')
        self.ao_home = os.path.join(self.home, '.local', 'state', 'ao-test')
        os.makedirs(self.ao_home)
        self.registry = os.path.join(self.home, '.local', 'state', 'shop-ops', 'kube-dirs')
        self.record = os.path.join(self.tmp, 'probe-record.json')
        self.binary = os.path.join(self.tmp, 'claude-2.1.311')
        write(self.binary, '#!/bin/sh\necho 2.1.311\n', 0o755)
        self.pin = os.path.join(self.tmp, 'claude_pin.py')
        write(self.pin, FAKE_PIN, 0o755)
        # origin (bare), the session clone, and a second clone that pushes a newer main after the session cloned.
        self.origin = os.path.join(self.tmp, 'origin.git')
        subprocess.run(['git', 'init', '-q', '--bare', '-b', 'main', self.origin], check=True)
        self.repo = os.path.join(self.tmp, 'shop')
        self.other = os.path.join(self.tmp, 'other')
        for clone in (self.other,):
            subprocess.run(['git', 'clone', '-q', self.origin, clone], check=True, capture_output=True)
            git(clone, 'config', 'user.email', 't@example.com')
            git(clone, 'config', 'user.name', 'T')
        self.push_version('v1')
        subprocess.run(['git', 'clone', '-q', self.origin, self.repo], check=True, capture_output=True)
        git(self.repo, 'config', 'user.email', 't@example.com')
        git(self.repo, 'config', 'user.name', 'T')
        # Main moves on after the session cloned: only `git fetch origin main` sees v2.
        self.push_version('v2')
        self.main_sha = git(self.other, 'rev-parse', 'HEAD')
        # The session working tree differs from origin/main (also a probe.py that must never run).
        for name in FILES:
            mode = 0o755 if name in EXECUTABLE else 0o644
            if name == 'probe.py':
                text = ('#!/usr/bin/env python3\nimport os\nopen(os.environ["FAKE_PROBE_RECORD"] + ".worktree", "w")'
                        '.write("ran")\n')
            else:
                text = 'working tree %s\n' % name
            write(os.path.join(self.repo, 'tools', 'agent-security', name), text, mode)

    def push_version(self, version, files=FILES, extra=None):
        for name in files:
            mode = 0o755 if name in EXECUTABLE else 0o644
            write(os.path.join(self.other, 'tools', 'agent-security', name), origin_content(name, version), mode)
        for path, (text, mode) in (extra or {}).items():
            write(os.path.join(self.other, path), text, mode)
        git(self.other, 'add', '-A')
        git(self.other, 'commit', '-q', '-m', version)
        git(self.other, 'push', '-q', 'origin', 'HEAD:main')
        self.main_sha = git(self.other, 'rev-parse', 'HEAD')

    def env(self, **extra):
        env = {k: v for k, v in os.environ.items() if not k.startswith(('GIT_', 'FAKE_'))}
        env.update(HOME=self.home, FAKE_PROBE_RECORD=self.record, FAKE_PIN_BINARY=self.binary,
                   CLAUDE_CODE_ENTRYPOINT='cli', CLAUDECODE='1', AGENT_OTEL_ENDPOINT=self.stack_endpoint)
        env.update(extra)
        return env

    def prepare(self, *extra_args, args=None, env=None, timeout=60):
        # Tests read the copy from the local `origin` through --source-url (the default source is GitHub).
        argv = args if args is not None else ['--repo', self.repo, '--ao-home', self.ao_home, '--ao-dir', self.tmp,
                                              '--pin-script', self.pin, '--probe-timeout', '30',
                                              '--source-url', self.origin]
        result = subprocess.run([sys.executable, AO_PREPARE] + argv + list(extra_args), capture_output=True,
                                text=True, env=env or self.env(), timeout=timeout)
        return result.returncode, result.stdout, result.stderr

    def values(self, out):
        lines = [line for line in out.splitlines() if line]
        values = dict(line.split('=', 1) for line in lines)
        self.assertEqual(len(lines), len(values), out)
        return values

    def probe_record(self):
        with open(self.record) as f:
            return json.load(f)

    def copy(self, name=''):
        return os.path.join(self.ao_home, 'agent-security', name)

    def assert_refused(self, code, out, err, *texts):
        self.assertNotEqual(0, code, err)
        self.assertEqual('', out)
        lines = [line for line in err.splitlines() if line.strip()]
        self.assertEqual(1, len(lines), err)
        self.assertTrue(lines[0].startswith('ao-prepare: '), err)
        for text in texts:
            self.assertIn(text, lines[0])

    # ------------------------------------------------------------ success

    def test_success_prints_the_wrapper_of_the_copy_and_the_variables(self):
        code, out, err = self.prepare()
        self.assertEqual(0, code, err)
        values = self.values(out)
        self.assertEqual({'AO_CLAUDE_BIN', 'AGENT_CLAUDE_BIN', 'AGENT_AO_HOME', 'PROBE_SECONDS', 'PROBE_COST_USD',
                          'MAIN_SHA'}, set(values))
        self.assertEqual(self.copy('claude-agent'), values['AO_CLAUDE_BIN'])
        self.assertEqual(self.binary, values['AGENT_CLAUDE_BIN'])
        self.assertEqual(self.ao_home, values['AGENT_AO_HOME'])
        self.assertEqual('0.0041', values['PROBE_COST_USD'])
        self.assertGreaterEqual(float(values['PROBE_SECONDS']), 0)
        self.assertEqual(self.main_sha, values['MAIN_SHA'])
        self.assertIn('sonda przeszła', err)
        self.assertIn('0.0041 USD', err)

    def test_every_file_comes_from_origin_main_after_a_fetch_not_from_the_working_tree(self):
        code, _, err = self.prepare()
        self.assertEqual(0, code, err)
        for name in FILES:
            with open(self.copy(name)) as f:
                self.assertEqual(origin_content(name, 'v2'), f.read(), name)
            mode = os.stat(self.copy(name)).st_mode
            self.assertEqual(name in EXECUTABLE, bool(mode & stat.S_IXUSR), name)
            self.assertFalse(mode & (stat.S_IWUSR | stat.S_IWGRP | stat.S_IWOTH), name)
        self.assertEqual(sorted(['bin'] + [n for n in FILES if '/' not in n]), sorted(os.listdir(self.copy())))

    def test_the_probe_runs_from_the_copy_on_the_copied_wrapper_with_the_pinned_binary(self):
        code, _, err = self.prepare()
        self.assertEqual(0, code, err)
        record = self.probe_record()
        self.assertEqual(self.copy('probe.py'), record['file'])
        self.assertEqual('v2', record['version'])
        argv = record['argv']
        self.assertEqual(self.copy('claude-agent'), argv[argv.index('--claude-bin') + 1])
        self.assertEqual(self.binary, argv[argv.index('--agent-claude-bin') + 1])
        self.assertEqual('30', argv[argv.index('--timeout') + 1])
        self.assertIn('--result', argv)
        self.assertNotIn('--mode', argv)
        self.assertEqual(os.path.realpath(self.repo), os.path.realpath(record['cwd']))
        self.assertFalse(os.path.exists(self.record + '.worktree'), 'probe.py of the working tree ran')

    def test_stdout_has_only_the_values_even_when_the_probe_prints(self):
        code, out, err = self.prepare(env=self.env(FAKE_PROBE_MODE='noisy'))
        self.assertEqual(0, code, err)
        self.assertEqual(self.binary, self.values(out)['AGENT_CLAUDE_BIN'])
        self.assertIn('probe diagnostics on stdout', err)

    def test_the_probe_gets_no_claude_variables(self):
        code, _, err = self.prepare()
        self.assertEqual(0, code, err)
        self.assertEqual([], self.probe_record()['claude_env'])

    def test_an_older_copy_is_replaced_whole(self):
        write(self.copy('stale.txt'), 'old\n')
        write(self.copy('probe.py'), 'old probe\n', 0o444)
        code, _, err = self.prepare()
        self.assertEqual(0, code, err)
        self.assertFalse(os.path.exists(self.copy('stale.txt')))
        with open(self.copy('probe.py')) as f:
            self.assertEqual(origin_content('probe.py', 'v2'), f.read())
        self.assertEqual([], [n for n in os.listdir(self.ao_home) if n.startswith('.agent-security')])

    def test_every_run_is_recorded_with_time_and_cost(self):
        self.assertEqual(0, self.prepare()[0])
        self.assertEqual(1, self.prepare(env=self.env(FAKE_PROBE_MODE='fail1'))[0])
        with open(os.path.join(self.ao_home, 'probe-runs.jsonl')) as f:
            runs = [json.loads(line) for line in f]
        self.assertEqual([0, 1], [r['code'] for r in runs])
        self.assertEqual([None, 'przypadek bash_cat_canary nie przeszedł'], [r['reason'] for r in runs])
        self.assertEqual([0.0041, 0.0041], [r['cost_usd'] for r in runs])
        self.assertTrue(all(r['main'] == self.main_sha and r['seconds'] >= 0 and r['at'] for r in runs))
        with open(os.path.join(self.ao_home, 'probe-result.json')) as f:
            self.assertEqual('przypadek bash_cat_canary nie przeszedł', json.load(f)['reason'])

    def test_the_copy_has_the_otlp_receiver_next_to_the_probe(self):
        self.assertIn('otlp_receiver.py', ao_prepare.FILES)
        code, _, err = self.prepare()
        self.assertEqual(0, code, err)
        with open(self.copy('otlp_receiver.py')) as f:
            self.assertEqual(origin_content('otlp_receiver.py', 'v2'), f.read())
        self.assertFalse(os.stat(self.copy('otlp_receiver.py')).st_mode & (stat.S_IWUSR | stat.S_IXUSR))

    # ------------------------------------------------------------ telemetry (spec TELEMETRY, R5)

    def closed_endpoint(self):
        probe = socket.socket()
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
        probe.close()
        return 'http://127.0.0.1:%d' % port

    @staticmethod
    def lines(err):
        return [line for line in err.splitlines() if line.strip()]

    def no_stack_line(self, endpoint):
        return 'ao-prepare: telemetria: brak stosu pod %s; dane tego przebiegu nie trafią do stosu' % endpoint

    def test_a_closed_telemetry_port_warns_before_the_probe_and_keeps_the_start(self):
        endpoint = self.closed_endpoint()
        code, out, err = self.prepare(env=self.env(AGENT_OTEL_ENDPOINT=endpoint, FAKE_PROBE_STDERR='1'))
        self.assertEqual(0, code, err)
        self.assertEqual(self.binary, self.values(out)['AGENT_CLAUDE_BIN'])
        lines = self.lines(err)
        self.assertEqual(self.no_stack_line(endpoint), lines[0])
        self.assertEqual('probe: own output', lines[1])
        self.assertTrue(lines[-1].startswith('ao-prepare: sonda przeszła'), err)
        self.assertEqual(1, err.count('telemetria'))

    def test_a_closed_telemetry_port_keeps_a_refusal_a_refusal_with_the_refusal_last(self):
        endpoint = self.closed_endpoint()
        code, out, err = self.prepare(env=self.env(AGENT_OTEL_ENDPOINT=endpoint, FAKE_PROBE_MODE='fail1'))
        self.assertEqual((1, ''), (code, out))
        lines = self.lines(err)
        self.assertEqual([self.no_stack_line(endpoint)], lines[:-1])
        self.assertIn('sonda bezpieczeństwa nie przeszła (przypadek bash_cat_canary', lines[-1])

    def test_an_open_telemetry_port_or_off_prints_no_telemetry_line(self):
        for endpoint in (self.stack_endpoint, 'off'):
            code, out, err = self.prepare(env=self.env(AGENT_OTEL_ENDPOINT=endpoint))
            self.assertEqual(0, code, err)
            self.assertNotIn('telemetria', err, endpoint)
            self.assertEqual(1, len(self.lines(err)), err)

    def test_off_does_not_check_the_port(self):
        calls = []
        original = ao_prepare.socket.create_connection
        ao_prepare.socket.create_connection = lambda *a, **k: calls.append((a, k))
        self.addCleanup(setattr, ao_prepare.socket, 'create_connection', original)
        self.assertIsNone(ao_prepare.telemetry_endpoint({'AGENT_OTEL_ENDPOINT': 'off'}))
        ao_prepare.check_telemetry_stack(None)
        self.assertEqual([], calls)

    def test_without_agent_otel_endpoint_the_default_of_render_settings_is_checked_within_two_seconds(self):
        self.assertEqual(render_settings.DEFAULT_TELEMETRY_ENDPOINT, ao_prepare.telemetry_endpoint({}))
        self.assertEqual(self.stack_endpoint, ao_prepare.telemetry_endpoint({'AGENT_OTEL_ENDPOINT':
                                                                              self.stack_endpoint}))
        calls = []

        def refuse(address, timeout=None):
            calls.append((address, timeout))
            raise ConnectionRefusedError(61, 'Connection refused')
        original = ao_prepare.socket.create_connection
        ao_prepare.socket.create_connection = refuse
        self.addCleanup(setattr, ao_prepare.socket, 'create_connection', original)
        self.assertEqual(self.no_stack_line('http://127.0.0.1:4318')[len('ao-prepare: '):],
                         ao_prepare.check_telemetry_stack('http://127.0.0.1:4318'))
        self.assertEqual([(('127.0.0.1', 4318), 2)], calls)

    def test_an_invalid_agent_otel_endpoint_is_a_refusal_before_the_probe(self):
        for value in ('http://localhost:4318', 'https://127.0.0.1:4318', 'http://127.0.0.1:4318/v1',
                      'http://127.0.0.1@evil.example:4318', 'http://127.0.0.1.nip.io:4318', 'http://[::1]:4318',
                      'http://evil.example:4318', 'http://127.0.0.1:0', 'http://127.0.0.1:65536', 'OFF', '',
                      'http://127.0.0.1:4318\nx'):
            code, out, err = self.prepare(env=self.env(AGENT_OTEL_ENDPOINT=value))
            self.assert_refused(code, out, err, 'ao-prepare: niepoprawny AGENT_OTEL_ENDPOINT ',
                                'http://127.0.0.1:<port>')
            self.assertFalse(os.path.exists(self.record), value)
            self.assertFalse(os.path.exists(self.copy()), value)

    def test_no_export_or_unverifiable_of_the_probe_is_a_start_with_a_line_for_each(self):
        code, out, err = self.prepare(env=self.env(FAKE_PROBE_MODE='telemetry_warn'))
        self.assertEqual(0, code, err)
        self.assertEqual(self.binary, self.values(out)['AGENT_CLAUDE_BIN'])
        self.assertEqual(['ao-prepare: telemetria: sonda telemetry_export no_export (odbiornik sondy nie dostał '
                          'żądania /v1/logs)',
                          "ao-prepare: telemetria: sonda telemetry_no_content unverifiable (ciało /v1/logs nie do "
                          "sprawdzenia: nieznane Content-Encoding 'br')"], self.lines(err)[:-1])
        self.assertTrue(self.lines(err)[-1].startswith('ao-prepare: sonda przeszła'), err)

    def test_every_telemetry_line_comes_before_the_last_line(self):
        endpoint = self.closed_endpoint()
        code, _, err = self.prepare(env=self.env(AGENT_OTEL_ENDPOINT=endpoint, FAKE_PROBE_MODE='telemetry_warn_fail1'))
        self.assertEqual(1, code)
        lines = self.lines(err)
        self.assertEqual(self.no_stack_line(endpoint), lines[0])
        self.assertEqual(['telemetry_export', 'telemetry_no_content'],
                         [line.split()[3] for line in lines[1:-1]])
        self.assertIn('sonda bezpieczeństwa nie przeszła (przypadek bash_cat_canary', lines[-1])

    def test_passing_telemetry_cases_print_no_line(self):
        code, _, err = self.prepare(env=self.env(FAKE_PROBE_MODE='telemetry_pass'))
        self.assertEqual(0, code, err)
        self.assertNotIn('telemetria', err)

    def test_a_leak_in_the_telemetry_is_a_refusal(self):
        code, out, err = self.prepare(env=self.env(FAKE_PROBE_MODE='telemetry_fail'))
        self.assert_refused(code, out, err, 'sonda bezpieczeństwa nie przeszła (przypadek telemetry_no_content',
                            'znacznik promptu')

    def test_only_the_telemetry_statuses_of_their_cases_do_not_block(self):
        for mode, case in (('telemetry_bad', 'telemetry_export'), ('odd_status', 'bash_cat_canary')):
            code, out, err = self.prepare(env=self.env(FAKE_PROBE_MODE=mode))
            self.assert_refused(code, out, err, 'kod 0, ale przypadek %s nie przeszedł' % case)

    # ------------------------------------------------------------ KUBE_DIR

    def test_an_existing_registered_kube_dir_stops_it_before_the_probe(self):
        kube_dir = os.path.join(self.tmp, 'kube.abc')
        os.makedirs(kube_dir)
        write(self.registry, '/nonexistent/kube.old\n%s\n' % kube_dir, 0o600)
        code, out, err = self.prepare()
        self.assert_refused(code, out, err, 'KUBE_DIR', kube_dir)
        self.assertFalse(os.path.exists(self.record))
        self.assertFalse(os.path.exists(self.copy()))

    def test_a_registered_kube_dir_that_no_longer_exists_does_not_stop_it(self):
        write(self.registry, '/nonexistent/kube.old\n\n', 0o600)
        code, _, err = self.prepare()
        self.assertEqual(0, code, err)

    def test_a_registered_kube_dir_that_is_a_dangling_symlink_stops_it(self):
        link = os.path.join(self.tmp, 'kube.link')
        os.symlink('/nonexistent/target', link)
        write(self.registry, link + '\n', 0o600)
        self.assert_refused(*self.prepare(), link)

    def test_a_relative_entry_or_an_unreadable_registry_stops_it(self):
        write(self.registry, 'kube.rel\n', 0o600)
        self.assert_refused(*self.prepare(), 'kube.rel')
        os.remove(self.registry)
        os.makedirs(self.registry)
        self.assert_refused(*self.prepare(), self.registry)

    # ------------------------------------------------------------ the probe fails closed

    def test_probe_exit_1_or_2_is_a_failure_with_the_reason_time_and_cost(self):
        for mode, reason in (('fail1', 'przypadek bash_cat_canary nie przeszedł'),
                             ('fail2', 'brak tokenu wykonawcy w Keychain')):
            code, out, err = self.prepare(env=self.env(FAKE_PROBE_MODE=mode))
            self.assert_refused(code, out, err, 'sonda bezpieczeństwa nie przeszła (%s)' % reason, 'czas ')
            self.assertIn('0.0041 USD' if mode == 'fail1' else 'koszt brak danych', err)

    def test_probe_over_the_outer_time_limit_is_killed_and_a_failure(self):
        began = time.monotonic()
        code, out, err = self.prepare('--outer-timeout', '2', '--term-grace', '2',
                                      env=self.env(FAKE_PROBE_MODE='hang'))
        self.assert_refused(code, out, err, 'sonda bezpieczeństwa nie przeszła', 'limit czasu')
        self.assertLess(time.monotonic() - began, 30)
        pid = self.probe_record()['pid']
        time.sleep(0.2)
        with self.assertRaises(ProcessLookupError):
            os.kill(pid, 0)

    def test_missing_unreadable_or_inconsistent_result_is_a_failure(self):
        for mode, text in (('noresult', 'brak wyniku'), ('badjson', 'nieczytelny wynik'),
                           ('failedcase', 'bash_cat_canary')):
            code, out, err = self.prepare(env=self.env(FAKE_PROBE_MODE=mode))
            self.assert_refused(code, out, err, 'sonda bezpieczeństwa nie przeszła', text)


    # ------------------------------------------------------------ the shared .git of the main clone is not trusted

    def assert_copy_is_remote_main(self):
        for name in FILES:
            with open(self.copy(name)) as f:
                self.assertEqual(origin_content(name, 'v2'), f.read(), name)
        self.assertEqual('v2', self.probe_record()['version'])
        self.assertFalse(os.path.exists(self.record + '.worktree'), 'probe.py of the attacker ran')

    def test_replace_objects_planted_in_the_main_clone_do_not_change_the_copy(self):
        git(self.repo, 'fetch', '-q', 'origin', 'main')
        for name in ('probe.py', 'claude-agent'):
            good = git(self.repo, 'rev-parse', 'origin/main:tools/agent-security/%s' % name)
            bad = subprocess.run(['git', '-C', self.repo, 'hash-object', '-w', '--stdin'], check=True,
                                 capture_output=True, text=True,
                                 input='#!/usr/bin/env python3\nimport os\nopen(os.environ["FAKE_PROBE_RECORD"]'
                                       ' + ".worktree", "w").write("planted")\n').stdout.strip()
            git(self.repo, 'replace', good, bad)
        code, out, err = self.prepare()
        self.assertEqual(0, code, err)
        self.assert_copy_is_remote_main()
        self.assertEqual(self.main_sha, self.values(out)['MAIN_SHA'])

    def test_a_moved_origin_main_in_the_main_clone_does_not_change_the_copy(self):
        # The attacker's commit has the working-tree files (its probe.py marks that it ran); every git call on the
        # main clone moves refs/remotes/origin/main to it right after, as an executor racing ao-prepare would.
        git(self.repo, 'checkout', '-q', '-b', 'attack')
        git(self.repo, 'add', '-A')
        git(self.repo, 'commit', '-q', '-m', 'attack')
        attack = git(self.repo, 'rev-parse', 'HEAD')
        shim = os.path.join(self.tmp, 'shim')
        real = shutil.which('git')
        write(os.path.join(shim, 'git'), '#!/bin/sh\n"$REAL_GIT" "$@"; code=$?\n'
              'case " $* " in *" -C $ATTACK_REPO "*) "$REAL_GIT" -C "$ATTACK_REPO" update-ref '
              'refs/remotes/origin/main "$ATTACK_SHA" ;; esac\nexit $code\n', 0o755)
        code, out, err = self.prepare(env=self.env(PATH=shim + os.pathsep + os.environ['PATH'], REAL_GIT=real,
                                                   ATTACK_REPO=self.repo, ATTACK_SHA=attack))
        self.assertEqual(0, code, err)
        self.assert_copy_is_remote_main()
        self.assertEqual(self.main_sha, self.values(out)['MAIN_SHA'])

    def test_a_kube_dir_registered_while_the_probe_runs_stops_it(self):
        kube_dir = os.path.join(self.tmp, 'kube.during')
        os.makedirs(os.path.dirname(self.registry), exist_ok=True)
        code, out, err = self.prepare(env=self.env(FAKE_PROBE_MODE='kubedir', FAKE_KUBE_DIR=kube_dir,
                                                   FAKE_KUBE_REGISTRY=self.registry))
        self.assert_refused(code, out, err, 'KUBE_DIR', kube_dir)

    def test_a_case_that_is_not_an_object_is_a_refusal_with_the_reason(self):
        code, out, err = self.prepare(env=self.env(FAKE_PROBE_MODE='notdict'))
        self.assert_refused(code, out, err, 'sonda bezpieczeństwa nie przeszła', 'bash_cat_canary')
        with open(os.path.join(self.ao_home, 'probe-runs.jsonl')) as f:
            self.assertEqual(1, len(f.readlines()))

    # ------------------------------------------------------------ the source is pinned to GitHub

    GITHUB = 'https://github.com/piotrp321/shop.git'

    def default_source_calls(self, env_extra=None):
        """Runs ao-prepare without --source-url with a fake git that records its arguments and fails ls-remote (no
        network); returns the recorded calls."""
        shim = os.path.join(self.tmp, 'recording-git')
        calls = os.path.join(self.tmp, 'git-calls.jsonl')
        write(os.path.join(shim, 'git'), '#!/usr/bin/env python3\nimport json, os, subprocess, sys\n'
              'open(os.environ["RECORD_GIT_CALLS"], "a").write(json.dumps(sys.argv[1:]) + "\\n")\n'
              'if "ls-remote" in sys.argv: sys.exit(2)\n'
              'sys.exit(subprocess.call([os.environ["REAL_GIT"]] + sys.argv[1:]))\n', 0o755)
        args = ['--repo', self.repo, '--ao-home', self.ao_home, '--ao-dir', self.tmp, '--pin-script', self.pin]
        env = self.env(PATH=shim + os.pathsep + os.environ['PATH'], REAL_GIT=shutil.which('git'), RECORD_GIT_CALLS=calls)
        env.update(env_extra or {})
        code, out, err = self.prepare(args=args, env=env)
        self.assert_refused(code, out, err, 'git ls-remote')
        with open(calls) as f:
            return [json.loads(line) for line in f]

    def ls_remote(self, calls):
        found = [c for c in calls if 'ls-remote' in c]
        self.assertEqual(1, len(found), calls)
        return found[0]

    def test_the_default_source_is_main_on_github_over_https_or_ssh_only(self):
        call = self.ls_remote(self.default_source_calls())
        self.assertEqual([self.GITHUB, 'refs/heads/main'], call[-2:])
        for setting in ('protocol.allow=never', 'protocol.https.allow=always', 'protocol.ssh.allow=always'):
            self.assertIn(setting, call)

    def test_a_planted_commondir_in_the_main_clone_does_not_change_the_source(self):
        evil = os.path.join(self.tmp, 'evil-common')
        os.makedirs(evil)
        write(os.path.join(evil, 'config'), '[remote "origin"]\n\turl = %s\n' % os.path.join(self.tmp, 'evil.git'))
        write(os.path.join(self.repo, '.git', 'commondir'), evil + '\n')
        calls = self.default_source_calls()
        self.assertEqual(self.GITHUB, self.ls_remote(calls)[-2])
        self.assertNotIn('evil', json.dumps(calls))

    def test_a_foreign_url_in_the_clone_config_does_not_change_the_source(self):
        git(self.repo, 'remote', 'set-url', 'origin', os.path.join(self.tmp, 'evil.git'))
        calls = self.default_source_calls()
        self.assertEqual(self.GITHUB, self.ls_remote(calls)[-2])
        self.assertNotIn('evil', json.dumps(calls))

    def test_a_branch_ending_in_refs_heads_main_does_not_hide_main(self):
        git(self.other, 'push', '-q', 'origin', 'HEAD:refs/heads/x/refs/heads/main')
        code, out, err = self.prepare()
        self.assertEqual(0, code, err)
        self.assertEqual(self.main_sha, self.values(out)['MAIN_SHA'])

    # ------------------------------------------------------------ other refusals

    def test_a_failing_pin_stops_it_with_the_reason(self):
        for mode, text in (('missing', 'zainstaluj'), ('pin', 'LifecycleConfig'), ('relative', 'claude')):
            code, out, err = self.prepare(env=self.env(FAKE_PIN_MODE=mode))
            self.assert_refused(code, out, err, text)
            self.assertFalse(os.path.exists(self.record), mode)

    def test_a_file_missing_on_origin_main_stops_it(self):
        git(self.other, 'rm', '-q', 'tools/agent-security/bin/mktemp')
        git(self.other, 'commit', '-q', '-m', 'drop')
        git(self.other, 'push', '-q', 'origin', 'HEAD:main')
        self.assert_refused(*self.prepare(), 'bin/mktemp', 'na main w')
        self.assertFalse(os.path.exists(self.record))

    def test_a_symlink_on_origin_main_stops_it(self):
        path = os.path.join(self.other, 'tools', 'agent-security', 'probe-cases.sh')
        os.remove(path)
        os.symlink('/etc/passwd', path)
        git(self.other, 'add', '-A')
        git(self.other, 'commit', '-q', '-m', 'link')
        git(self.other, 'push', '-q', 'origin', 'HEAD:main')
        self.assert_refused(*self.prepare(), 'probe-cases.sh')

    def test_a_failing_fetch_stops_it(self):
        args = ['--repo', self.repo, '--ao-home', self.ao_home, '--ao-dir', self.tmp, '--pin-script', self.pin,
                '--source-url', os.path.join(self.tmp, 'missing.git')]
        self.assert_refused(*self.prepare(args=args), 'git ls-remote')
        self.assertFalse(os.path.exists(self.record))

    def test_missing_git_stops_it(self):
        bin_dir = os.path.join(self.tmp, 'nogit')
        os.makedirs(bin_dir)
        self.assert_refused(*self.prepare(env=self.env(PATH=bin_dir)), 'git')

    def test_a_running_ao_on_this_ao_home_stops_it_without_touching_the_copy(self):
        write(self.copy('probe.py'), 'current copy\n')
        fd = os.open(os.path.join(self.ao_home, 'orchestrator.lock'), os.O_RDWR | os.O_CREAT)
        self.addCleanup(os.close, fd)
        fcntl.flock(fd, fcntl.LOCK_EX)
        self.assert_refused(*self.prepare(), 'AO')
        with open(self.copy('probe.py')) as f:
            self.assertEqual('current copy\n', f.read())

    def test_ao_home_must_be_a_real_ao_directory_of_home(self):
        outside = os.path.join(self.tmp, 'ao-outside')
        os.makedirs(outside)
        link = os.path.join(self.home, '.local', 'state', 'ao-link')
        os.symlink(self.ao_home, link)
        other_name = os.path.join(self.home, '.local', 'state', 'shop-ops')
        os.makedirs(other_name, exist_ok=True)
        for ao_home in (outside, link, other_name, os.path.join(self.home, '.local', 'state', 'ao-missing'),
                        'relative/ao-x'):
            args = ['--repo', self.repo, '--ao-home', ao_home, '--ao-dir', self.tmp, '--pin-script', self.pin]
            code, out, err = self.prepare(args=args)
            self.assert_refused(code, out, err, 'AO_HOME')
        self.assertFalse(os.path.exists(self.record))

    def test_missing_arguments_are_a_usage_error(self):
        for args in ([], ['--repo', self.repo], ['--ao-home', self.ao_home]):
            code, out, err = self.prepare(args=args)
            self.assertEqual((2, ''), (code, out), args)
            self.assertTrue(err)

    def test_repo_that_is_not_a_git_repository_stops_it(self):
        args = ['--repo', self.tmp, '--ao-home', self.ao_home, '--ao-dir', self.tmp, '--pin-script', self.pin]
        self.assert_refused(*self.prepare(args=args), 'git')


if __name__ == '__main__':
    unittest.main()

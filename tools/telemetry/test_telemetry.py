"""Tests of telemetry.py: python3 -m unittest discover -s tools/telemetry -p 'test_*.py'

No model, no telemetry stack and no network beyond 127.0.0.1: the OTLP endpoint, Grafana and the `claude` binary are
test doubles (local HTTP servers and a stub script). The CLI runs as a subprocess with HOME in a temporary directory.
"""
import gzip
import http.server
import importlib.util
import json
import os
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import unittest.mock
import urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
TELEMETRY = os.path.join(HERE, 'telemetry.py')
SECURITY = os.path.join(HERE, '..', 'agent-security')


def _load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


telemetry = _load('telemetry', TELEMETRY)
render_settings = _load('render_settings', os.path.join(SECURITY, 'render_settings.py'))

INVALID_ENDPOINTS = ('http://localhost.evil:4318', 'http://127.0.0.1.nip.io:4318', 'http://127.0.0.1@host:4318',
                     'https://127.0.0.1:4318', 'http://[::1]:4318', 'http://127.0.0.1:0', 'http://127.0.0.1:65536',
                     'http://127.0.0.1:4318/v1', 'http://127.0.0.1', '')


def cli(*args, env=None, cwd=None, home=None):
    """(exit code, stdout, stderr) of telemetry.py with HOME in `home` (a temporary directory by default)."""
    with tempfile.TemporaryDirectory() as fallback:
        run_env = {'PATH': os.environ.get('PATH', ''), 'HOME': home or fallback, 'TMPDIR': tempfile.gettempdir()}
        run_env.update(env or {})
        result = subprocess.run([sys.executable, TELEMETRY] + list(args), capture_output=True, text=True,
                                env=run_env, cwd=cwd, timeout=120)
    return result.returncode, result.stdout, result.stderr


class FakeServer:
    """A local HTTP server on 127.0.0.1 that answers with handler(method, path, body) -> (status, bytes) and records
    every request as (method, path, headers, body)."""

    def __init__(self, handler):
        self.requests = []
        outer = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def _handle(self):
                body = self.rfile.read(int(self.headers.get('Content-Length') or 0))
                outer.requests.append((self.command, self.path, dict(self.headers), body))
                status, payload = handler(self.command, self.path, body)
                self.send_response(status)
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            do_GET = do_POST = _handle

            def log_message(self, *args):
                pass

        self.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.server.daemon_threads = True
        self.server.handle_error = lambda request, client_address: None   # a client that timed out and left
        self.url = 'http://127.0.0.1:%d' % self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, kwargs={'poll_interval': 0.01}, daemon=True).start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()


def closed_port_url():
    """An address nobody listens on."""
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return 'http://127.0.0.1:%d' % s.getsockname()[1]


def sent_value(otlp):
    """The value of the gauge in the last OTLP request the double got."""
    body = json.loads(otlp.requests[-1][3])
    metric = body['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0]
    return metric['gauge']['dataPoints'][0]['asDouble']


def prometheus(value):
    """The answer of the Prometheus instant query with one series of the value (a string, like Prometheus)."""
    result = [] if value is None else [{'metric': {'__name__': 'shop_telemetry_check'}, 'value': [1.7e9, str(value)]}]
    return 200, json.dumps({'status': 'success', 'data': {'resultType': 'vector', 'result': result}}).encode()


class CheckTest(unittest.TestCase):
    def setUp(self):
        self.servers = []
        self.otlp = self.serve(lambda method, path, body: (200, b'{}'))

    def tearDown(self):
        for server in self.servers:
            server.close()

    def serve(self, handler):
        server = FakeServer(handler)
        self.servers.append(server)
        return server

    def grafana(self, empty_polls=0, status=None):
        """A Grafana that shows the value the OTLP double got after `empty_polls` queries (status: its error)."""
        count = []

        def handler(method, path, body):
            count.append(path)
            if status is not None:
                return status, b'{"message": "Data source not found"}'
            return prometheus(None if len(count) <= empty_polls else sent_value(self.otlp))

        return self.serve(handler)

    def run_check(self, grafana_url, endpoint=None, timeout=5, **kwargs):
        kwargs.setdefault('interval', 0.01)
        return telemetry.check_stack(endpoint or self.otlp.url, grafana_url, timeout, **kwargs)

    def assert_failure(self, result, *fragments):
        ok, message = result
        self.assertFalse(ok, message)
        self.assertEqual(len(message.strip().splitlines()), 1, message)
        for fragment in fragments:
            self.assertIn(fragment, message)

    def test_visible_value_is_a_success_after_empty_polls(self):
        grafana = self.grafana(empty_polls=2)
        ok, message = self.run_check(grafana.url)
        self.assertTrue(ok, message)
        self.assertEqual(len(self.otlp.requests), 1)
        self.assertEqual(len(grafana.requests), 3)

    def test_gauge_is_sent_as_otlp_json_with_a_random_value(self):
        grafana = self.grafana()
        self.run_check(grafana.url)
        method, path, headers, body = self.otlp.requests[0]
        self.assertEqual((method, path), ('POST', '/v1/metrics'))
        self.assertEqual(headers['Content-Type'], 'application/json')
        metric = json.loads(body)['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0]
        self.assertEqual(metric['name'], 'shop_telemetry_check')
        first = metric['gauge']['dataPoints'][0]['asDouble']
        self.assertGreaterEqual(first, 1)
        self.run_check(grafana.url)
        self.assertNotEqual(sent_value(self.otlp), first)

    def test_prometheus_is_queried_through_the_grafana_proxy_of_the_prometheus_source(self):
        grafana = self.grafana()
        self.run_check(grafana.url)
        method, path, headers, _ = grafana.requests[0]
        parsed = urllib.parse.urlparse(path)
        self.assertEqual(method, 'GET')
        self.assertEqual(parsed.path, '/api/datasources/proxy/uid/prometheus/api/v1/query')
        self.assertEqual(urllib.parse.parse_qs(parsed.query), {'query': ['shop_telemetry_check']})
        self.assertNotIn('Authorization', headers)   # the anonymous Viewer

    def test_a_value_of_an_earlier_run_is_not_a_success(self):
        grafana = self.serve(lambda method, path, body: prometheus(12345))
        self.assert_failure(self.run_check(grafana.url, timeout=0.3), grafana.url, 'shop_telemetry_check')

    def test_no_point_before_the_timeout_names_grafana_and_the_time(self):
        grafana = self.serve(lambda method, path, body: prometheus(None))
        started = time.monotonic()
        self.assert_failure(self.run_check(grafana.url, timeout=0.3), grafana.url, 'shop_telemetry_check', '0.3')
        self.assertLess(time.monotonic() - started, 5)
        self.assertGreater(len(grafana.requests), 2)

    def test_closed_otlp_port_names_the_endpoint_and_does_not_ask_grafana(self):
        grafana = self.grafana()
        endpoint = closed_port_url()
        self.assert_failure(self.run_check(grafana.url, endpoint=endpoint), endpoint, 'OTLP')
        self.assertEqual(grafana.requests, [])

    def test_otlp_error_status_names_the_endpoint_and_the_status(self):
        otlp = self.serve(lambda method, path, body: (503, b''))
        grafana = self.grafana()
        self.assert_failure(self.run_check(grafana.url, endpoint=otlp.url), otlp.url, '503')

    def test_otlp_that_does_not_answer_is_a_failure_after_the_request_timeout(self):
        def slow(method, path, body):
            time.sleep(1)
            return 200, b'{}'

        otlp = self.serve(slow)
        self.assert_failure(self.run_check(self.grafana().url, endpoint=otlp.url, request_timeout=0.2), otlp.url)

    def test_unavailable_grafana_names_its_address(self):
        url = closed_port_url()
        self.assert_failure(self.run_check(url), url, 'Grafana')
        self.assertEqual(len(self.otlp.requests), 1)   # the point was sent; nothing could read it

    def test_grafana_without_the_prometheus_source_says_so(self):
        grafana = self.grafana(status=404)
        self.assert_failure(self.run_check(grafana.url), grafana.url, 'prometheus', 'uid')

    def test_grafana_error_status_names_the_status(self):
        for status in (401, 500, 502):
            with self.subTest(status=status):
                grafana = self.grafana(status=status)
                self.assert_failure(self.run_check(grafana.url), grafana.url, str(status))

    def test_grafana_answer_that_is_not_a_prometheus_result_is_a_failure(self):
        for payload in (b'not json', b'[]', b'{"status": "error", "error": "boom"}', b'{"status": "success"}'):
            with self.subTest(payload=payload):
                grafana = self.serve(lambda method, path, body, payload=payload: (200, payload))
                self.assert_failure(self.run_check(grafana.url, timeout=0.3), grafana.url)

    def test_grafana_that_does_not_answer_is_a_failure(self):
        def slow(method, path, body):
            time.sleep(1)
            return 200, b'{}'

        grafana = self.serve(slow)
        self.assert_failure(self.run_check(grafana.url, request_timeout=0.2), grafana.url, 'Grafana')


class CheckCliTest(unittest.TestCase):
    def test_defaults(self):
        args = telemetry.build_parser().parse_args(['check'])
        self.assertEqual((args.endpoint, args.grafana, args.timeout), ('http://127.0.0.1:4318',
                                                                      'http://127.0.0.1:3030', 60.0))
        self.assertFalse(args.project_override)

    def test_exit_0_with_one_line_when_the_value_is_visible(self):
        otlp = FakeServer(lambda method, path, body: (200, b'{}'))
        grafana = FakeServer(lambda method, path, body: prometheus(sent_value(otlp)))
        try:
            code, out, err = cli('check', '--endpoint', otlp.url, '--grafana', grafana.url, '--timeout', '10')
        finally:
            otlp.close()
            grafana.close()
        self.assertEqual((code, err), (0, ''))
        self.assertEqual(len(out.strip().splitlines()), 1, out)

    def test_exit_1_with_one_line_on_stderr_when_the_endpoint_is_down(self):
        endpoint = closed_port_url()
        code, out, err = cli('check', '--endpoint', endpoint, '--grafana', closed_port_url())
        self.assertEqual((code, out), (1, ''))
        self.assertEqual(len(err.strip().splitlines()), 1, err)
        self.assertIn(endpoint, err)

    def test_exit_1_when_no_point_shows_before_the_timeout(self):
        otlp = FakeServer(lambda method, path, body: (200, b'{}'))
        grafana = FakeServer(lambda method, path, body: prometheus(None))
        try:
            code, out, err = cli('check', '--endpoint', otlp.url, '--grafana', grafana.url, '--timeout', '1')
        finally:
            otlp.close()
            grafana.close()
        self.assertEqual((code, out), (1, ''))
        self.assertIn(grafana.url, err)

    def test_exit_2_for_an_endpoint_or_grafana_outside_the_loopback_and_for_a_bad_timeout(self):
        for option, values in (('--endpoint', INVALID_ENDPOINTS), ('--grafana', INVALID_ENDPOINTS),
                               ('--timeout', ('0', '-1', 'abc', 'nan', 'inf', ''))):
            for value in values:
                with self.subTest(option=option, value=value):
                    code, out, err = cli('check', option, value)
                    self.assertEqual((code, out), (2, ''))
                    self.assertEqual(len(err.strip().splitlines()), 1, err)
        self.assertEqual(cli('check', '--claude-bin', '/bin/true')[0], 2)   # only with --project-override

# A stand-in for `claude -p`: it behaves like Claude Code as far as the override check can tell (it reads the OTLP
# endpoint and the resource attributes from the --settings block and exports OTLP protobuf there), and SHOP_STUB_MODE
# makes it misbehave. It records how it was started in SHOP_STUB_RECORD.
STUB = r"""#!%(python)s
import gzip, http.client, json, os, sys, time

def varint(value):
    out = bytearray()
    while True:
        byte = value & 0x7f
        value >>= 7
        out.append(byte | 0x80 if value else byte)
        if not value:
            return bytes(out)

def delimited(number, payload):
    return varint(number << 3 | 2) + varint(len(payload)) + payload

def string(number, text):
    return delimited(number, text.encode('utf-8'))

def key_value(key, value):
    return string(1, key) + delimited(2, string(1, value))

def resource(attributes):
    return b''.join(delimited(1, key_value(k, v)) for k, v in attributes.items())

def metrics(attributes):
    metric = string(1, 'claude_code.cost.usage') + delimited(7, delimited(1, b''))
    return delimited(1, delimited(1, resource(attributes)) + delimited(2, delimited(2, metric)))

def logs(attributes, record_attributes):
    record = string(5, 'claude_code.user_prompt') + b''.join(delimited(6, key_value(k, v))
                                                             for k, v in record_attributes.items())
    return delimited(1, delimited(1, resource(attributes)) + delimited(2, delimited(2, record)))

def post(endpoint, path, body, encoding=None):
    headers = {'Content-Type': 'application/x-protobuf'}
    if encoding == 'gzip':
        body = gzip.compress(body)
    if encoding:
        headers['Content-Encoding'] = encoding
    connection = http.client.HTTPConnection('127.0.0.1', int(endpoint.rsplit(':', 1)[1]), timeout=10)
    connection.request('POST', path, body=body, headers=headers)
    connection.getresponse().read()
    connection.close()

def pairs(text):
    return dict(item.split('=', 1) for item in text.split(',') if item)

argv = sys.argv[1:]
if argv == ['--version']:
    print(os.environ.get('SHOP_STUB_VERSION', '2.1.311') + ' (Claude Code)')
    sys.exit(0)
mode = os.environ.get('SHOP_STUB_MODE', 'ok')
prompt = argv[argv.index('-p') + 1]
settings = json.loads(argv[argv.index('--settings') + 1])['env']
project = json.load(open('.claude/settings.json'))['env']
with open(os.environ['SHOP_STUB_RECORD'], 'w') as f:
    json.dump({'argv': argv, 'cwd': os.getcwd(), 'project': project, 'settings': settings,
               'forbidden_env': sorted(k for k in os.environ if k.startswith(('CLAUDE', 'OTEL_'))),
               'git': os.path.isdir('.git')}, f)
second = settings['OTEL_EXPORTER_OTLP_ENDPOINT']
first = project['OTEL_EXPORTER_OTLP_ENDPOINT']
attributes = pairs(settings['OTEL_RESOURCE_ATTRIBUTES'])
attributes['service.name'] = 'claude-code'
target, encoding, record = second, None, {'prompt_length': str(len(prompt))}
if mode == 'fail':
    sys.stderr.write('boom: the stub failed\n')
    sys.exit(3)
if mode == 'hang':
    time.sleep(60)
if mode == 'no_export':
    sys.exit(0)
if mode == 'project_endpoint':
    target = first
if mode == 'project_attributes':
    attributes.update(pairs(project['OTEL_RESOURCE_ATTRIBUTES']))
if mode == 'leak':
    record['prompt'] = prompt
if mode == 'leak_gzip':
    record['prompt'] = prompt
    encoding = 'gzip'
if mode == 'unknown_encoding':
    encoding = 'br'
if mode != 'only_logs':
    post(target, '/v1/metrics', metrics(attributes), encoding if mode != 'leak_gzip' else None)
if mode != 'only_metrics':
    post(target, '/v1/logs', logs(attributes, record), encoding)
if mode == 'both':
    post(first, '/v1/metrics', metrics(attributes))
"""

LIFECYCLE = 'class LifecycleConfig:\n    claude_version: str = "9.9.9"\n'


class OverrideCheckTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)
        self.record_path = os.path.join(self.dir.name, 'record.json')
        self.binary = self.write_binary('claude-stub', STUB % {'python': sys.executable})

    def write_binary(self, name, text):
        path = os.path.join(self.dir.name, name)
        with open(path, 'w') as f:
            f.write(text)
        os.chmod(path, 0o755)
        return path

    def run_check(self, mode='ok', binary=None, timeout=20, extra_env=None, **kwargs):
        env = {'SHOP_STUB_MODE': mode, 'SHOP_STUB_RECORD': self.record_path}
        env.update(extra_env or {})
        kwargs.setdefault('settle', 0.05)
        with unittest.mock.patch.dict(os.environ, env):
            return telemetry.check_override(binary or self.binary, timeout=timeout, **kwargs)

    def record(self):
        with open(self.record_path) as f:
            return json.load(f)

    def assert_failure(self, result, *fragments):
        ok, message = result
        self.assertFalse(ok, message)
        self.assertEqual(len(message.strip().splitlines()), 1, message)
        for fragment in fragments:
            self.assertIn(fragment, message)

    def assert_cleaned_up(self):
        record = self.record()
        self.assertFalse(os.path.exists(record['cwd']), 'the temporary repository is still there')
        for endpoint in (record['project']['OTEL_EXPORTER_OTLP_ENDPOINT'],
                         record['settings']['OTEL_EXPORTER_OTLP_ENDPOINT']):
            with socket.socket() as s:
                self.assertNotEqual(s.connect_ex(('127.0.0.1', int(endpoint.rsplit(':', 1)[1]))), 0,
                                    'a receiver still listens on ' + endpoint)

    def test_success_when_only_the_flag_settings_receiver_gets_metrics_and_logs(self):
        ok, message = self.run_check()
        self.assertTrue(ok, message)
        self.assert_cleaned_up()

    def test_binary_is_started_as_a_haiku_print_run_with_flag_settings_only(self):
        self.run_check()
        record = self.record()
        argv = record['argv']
        self.assertEqual(argv[0], '-p')
        for flag, value in (('--model', 'haiku'), ('--max-budget-usd', '0.1'), ('--setting-sources', 'project')):
            self.assertEqual(argv[argv.index(flag) + 1], value, flag)
        self.assertIn('--strict-mcp-config', argv)
        block = json.loads(argv[argv.index('--settings') + 1])
        self.assertEqual(list(block), ['env'])
        expected = render_settings.telemetry_env('http://127.0.0.1:1', {'shop.source': 'ao', 'shop.task': 'check'})
        self.assertEqual(list(block['env']), list(expected))   # the R1 block, in its order, with its intervals
        self.assertEqual(block['env']['OTEL_LOG_USER_PROMPTS'], '0')
        self.assertEqual(block['env']['OTEL_EXPORTER_OTLP_PROTOCOL'], 'http/protobuf')

    def test_project_settings_point_at_another_receiver_and_ask_for_prompts(self):
        self.run_check()
        record = self.record()
        self.assertEqual(sorted(record['project']), ['OTEL_EXPORTER_OTLP_ENDPOINT', 'OTEL_LOG_USER_PROMPTS',
                                                     'OTEL_RESOURCE_ATTRIBUTES'])
        self.assertEqual(record['project']['OTEL_LOG_USER_PROMPTS'], '1')
        self.assertEqual(record['project']['OTEL_RESOURCE_ATTRIBUTES'], 'shop.task=override')
        first = render_settings.telemetry_endpoint(record['project']['OTEL_EXPORTER_OTLP_ENDPOINT'])
        second = render_settings.telemetry_endpoint(record['settings']['OTEL_EXPORTER_OTLP_ENDPOINT'])
        self.assertNotEqual(first, second)

    def test_runs_in_a_temporary_git_repository_with_a_random_marker_in_the_prompt(self):
        self.run_check()
        first = self.record()
        self.assertTrue(first['git'])
        self.assertTrue(first['cwd'].startswith(os.path.realpath(tempfile.gettempdir())), first['cwd'])
        self.run_check()
        second = self.record()
        self.assertNotEqual(first['cwd'], second['cwd'])
        self.assertNotEqual(first['argv'][1], second['argv'][1])   # the prompt carries a new marker every run

    def test_environment_has_no_claude_and_no_otel_variables(self):
        extra = {'CLAUDE_CODE_ENABLE_TELEMETRY': '1', 'CLAUDECODE': '1', 'CLAUDE_CONFIG_DIR': '/nowhere',
                 'OTEL_EXPORTER_OTLP_ENDPOINT': 'http://127.0.0.1:1', 'OTEL_LOG_TOOL_DETAILS': '1'}
        ok, message = self.run_check(extra_env=extra)
        self.assertTrue(ok, message)
        self.assertEqual(self.record()['forbidden_env'], [])

    def test_a_request_to_the_receiver_of_the_project_settings_is_a_failure(self):
        self.assert_failure(self.run_check('project_endpoint'), 'ustawienia projektu')
        self.assert_cleaned_up()
        self.assert_failure(self.run_check('both'), 'ustawienia projektu')

    def test_prompt_marker_in_a_body_is_a_failure_also_after_gzip(self):
        for mode in ('leak', 'leak_gzip'):
            with self.subTest(mode=mode):
                self.assert_failure(self.run_check(mode), 'znacznik')
                self.assert_cleaned_up()

    def test_shop_task_override_from_the_project_settings_is_a_failure(self):
        self.assert_failure(self.run_check('project_attributes'), 'shop.task=override')

    def test_no_export_is_a_failure(self):
        self.assert_failure(self.run_check('no_export'), 'brak eksportu')
        self.assert_cleaned_up()

    def test_missing_metrics_or_logs_is_a_failure_that_names_the_path(self):
        self.assert_failure(self.run_check('only_logs'), '/v1/metrics')
        self.assert_failure(self.run_check('only_metrics'), '/v1/logs')

    def test_a_body_that_cannot_be_unpacked_is_a_failure_not_a_pass(self):
        self.assert_failure(self.run_check('unknown_encoding'), 'nie da się')

    def test_binary_that_fails_is_a_failure_with_its_exit_code(self):
        self.assert_failure(self.run_check('fail'), 'kodem 3', 'boom')
        self.assert_cleaned_up()

    def test_binary_that_hangs_is_killed_at_the_time_limit(self):
        started = time.monotonic()
        self.assert_failure(self.run_check('hang', timeout=0.5), 'limit czasu')
        self.assertLess(time.monotonic() - started, 15)
        self.assert_cleaned_up()

    def test_binary_that_cannot_start_is_a_failure(self):
        broken = self.write_binary('broken', 'not a program\n')   # no shebang: exec format error
        self.assert_failure(self.run_check(binary=broken), broken)

    def test_missing_git_is_a_failure_with_the_reason(self):
        self.assert_failure(self.run_check(extra_env={'PATH': self.dir.name}), 'git')


class OverrideCliTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)
        self.home = os.path.join(self.dir.name, 'home')
        os.makedirs(self.home)
        self.binary = os.path.join(self.dir.name, 'claude-stub')
        with open(self.binary, 'w') as f:
            f.write(STUB % {'python': sys.executable})
        os.chmod(self.binary, 0o755)
        self.env = {'SHOP_STUB_RECORD': os.path.join(self.dir.name, 'record.json')}

    def git(self, *args, cwd):
        subprocess.run(['git', '-c', 'user.name=t', '-c', 'user.email=t@t'] + list(args), cwd=cwd, check=True,
                       capture_output=True)

    def install_pinned_binary(self, version='9.9.9', reported=None):
        """A fake AO checkout with LifecycleConfig.claude_version on origin/main and the stub as the binary of the
        native installer; claude_pin.py finds both through HOME."""
        ao = os.path.join(self.home, 'Development', 'claude', 'agentic-orchestrator')
        os.makedirs(os.path.join(ao, 'orchestrator'))
        self.git('init', '-q', cwd=ao)
        with open(os.path.join(ao, 'orchestrator', 'lifecycle.py'), 'w') as f:
            f.write(LIFECYCLE.replace('9.9.9', version))
        self.git('add', '.', cwd=ao)
        self.git('commit', '-q', '-m', 'pin', cwd=ao)
        self.git('update-ref', 'refs/remotes/origin/main', 'HEAD', cwd=ao)
        versions = os.path.join(self.home, '.local', 'share', 'claude', 'versions')
        os.makedirs(versions)
        os.replace(self.binary, os.path.join(versions, version))
        self.env['SHOP_STUB_VERSION'] = reported or version

    def test_explicit_binary_exit_0_with_one_line(self):
        code, out, err = cli('check', '--project-override', '--claude-bin', self.binary, env=self.env, home=self.home)
        self.assertEqual((code, err), (0, ''))
        self.assertEqual(len(out.strip().splitlines()), 1, out)

    def test_default_binary_is_the_pinned_one_of_claude_pin(self):
        self.install_pinned_binary()
        code, out, err = cli('check', '--project-override', env=self.env, home=self.home)
        self.assertEqual((code, err), (0, ''))

    def test_failed_check_is_exit_1_with_one_line_on_stderr(self):
        self.env['SHOP_STUB_MODE'] = 'no_export'
        code, out, err = cli('check', '--project-override', '--claude-bin', self.binary, env=self.env, home=self.home)
        self.assertEqual((code, out), (1, ''))
        self.assertEqual(len(err.strip().splitlines()), 1, err)
        self.assertIn('brak eksportu', err)

    def test_no_pinned_binary_is_exit_2_with_the_reason(self):
        code, out, err = cli('check', '--project-override', env=self.env, home=self.home)   # no AO checkout in HOME
        self.assertEqual((code, out), (2, ''))
        self.assertIn('agentic-orchestrator', err)
        self.install_pinned_binary(reported='1.0.0')   # the binary reports another version than the pin
        code, out, err = cli('check', '--project-override', env=self.env, home=self.home)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('9.9.9', err)

    def test_explicit_binary_that_is_missing_or_not_executable_is_exit_2(self):
        plain = os.path.join(self.dir.name, 'plain')
        open(plain, 'w').close()
        for path in (os.path.join(self.dir.name, 'missing'), plain, self.dir.name):
            with self.subTest(path=path):
                code, out, err = cli('check', '--project-override', '--claude-bin', path, env=self.env,
                                     home=self.home)
                self.assertEqual((code, out), (2, ''))
                self.assertIn(path, err)

    def test_project_override_does_not_touch_the_stack(self):
        # The stack options are not needed (and a closed port would be exit 1 of the other check).
        code, _, _ = cli('check', '--project-override', '--claude-bin', self.binary, '--endpoint',
                         closed_port_url(), '--grafana', closed_port_url(), env=self.env, home=self.home)
        self.assertEqual(code, 0)


class SnippetTest(unittest.TestCase):
    def test_block_is_the_export_variables_without_intervals_and_without_content(self):
        block = telemetry.snippet(render_settings.DEFAULT_TELEMETRY_ENDPOINT)
        self.assertEqual(list(block), ['env'])
        env = block['env']
        self.assertEqual(env, render_settings.telemetry_env(render_settings.DEFAULT_TELEMETRY_ENDPOINT,
                                                            {'shop.source': 'interactive'}, intervals=False))
        self.assertEqual(env['OTEL_RESOURCE_ATTRIBUTES'], 'shop.source=interactive')
        self.assertEqual(env['OTEL_EXPORTER_OTLP_ENDPOINT'], 'http://127.0.0.1:4318')
        self.assertEqual(env['CLAUDE_CODE_ENABLE_TELEMETRY'], '1')
        for name in ('OTEL_LOG_USER_PROMPTS', 'OTEL_LOG_ASSISTANT_RESPONSES', 'OTEL_LOG_TOOL_DETAILS',
                     'OTEL_LOG_TOOL_CONTENT', 'OTEL_LOG_RAW_API_BODIES'):
            self.assertEqual(env[name], '0', name)
        self.assertEqual([k for k in env if 'INTERVAL' in k], [])
        self.assertNotIn('shop.task', env['OTEL_RESOURCE_ATTRIBUTES'])

    def test_endpoint_option_is_used(self):
        block = telemetry.snippet('http://127.0.0.1:14318')
        self.assertEqual(block['env']['OTEL_EXPORTER_OTLP_ENDPOINT'], 'http://127.0.0.1:14318')

    def test_cli_prints_the_json_block_and_writes_nothing(self):
        with tempfile.TemporaryDirectory() as home, tempfile.TemporaryDirectory() as cwd:
            code, out, err = cli('snippet', home=home, cwd=cwd)
            self.assertEqual((code, err), (0, ''))
            self.assertEqual(json.loads(out), telemetry.snippet(render_settings.DEFAULT_TELEMETRY_ENDPOINT))
            self.assertFalse(os.path.exists(os.path.join(home, '.claude')))   # nothing of ours (the OS may add its own)
            self.assertEqual(os.listdir(cwd), [])

    def test_cli_endpoint_option(self):
        code, out, err = cli('snippet', '--endpoint', 'http://127.0.0.1:4999')
        self.assertEqual((code, err), (0, ''))
        self.assertEqual(json.loads(out)['env']['OTEL_EXPORTER_OTLP_ENDPOINT'], 'http://127.0.0.1:4999')

    def test_cli_refuses_an_endpoint_outside_the_loopback_with_exit_2(self):
        for endpoint in INVALID_ENDPOINTS:
            with self.subTest(endpoint=endpoint):
                code, out, err = cli('snippet', '--endpoint', endpoint)
                self.assertEqual(code, 2)
                self.assertEqual(out, '')
                self.assertEqual(len(err.strip().splitlines()), 1, err)
                self.assertIn('127.0.0.1', err)

    def test_cli_usage_errors_exit_2(self):
        for args in ((), ('unknown',), ('snippet', '--bogus'), ('snippet', '--project-override')):
            with self.subTest(args=args):
                code, out, _ = cli(*args)
                self.assertEqual((code, out), (2, ''))


if __name__ == '__main__':
    unittest.main()

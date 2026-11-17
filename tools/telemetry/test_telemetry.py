"""Tests of telemetry.py: python3 -m unittest discover -s tools/telemetry -p 'test_*.py'

No model, no telemetry stack and no network beyond 127.0.0.1: the OTLP endpoint and Grafana are test doubles (local
HTTP servers). The CLI runs as a subprocess with HOME in a temporary directory.
"""
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


class RemovedOptionsTest(unittest.TestCase):
    """There is no check of the project settings: the options of the removed check are unknown options (spec R4)."""

    def test_project_override_and_claude_bin_are_unknown_options_with_exit_2(self):
        for args in (('check', '--project-override'), ('check', '--claude-bin', '/bin/true'),
                     ('check', '--project-override', '--claude-bin', '/bin/true'), ('snippet', '--project-override'),
                     ('snippet', '--claude-bin', '/bin/true')):
            with self.subTest(args=args):
                code, out, err = cli(*args)
                self.assertEqual((code, out), (2, ''))
                self.assertIn('unrecognized arguments', err)


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

    def test_cli_error_for_a_bad_endpoint_names_the_option_that_was_given(self):
        code, _, err = cli('snippet', '--endpoint', 'http://localhost.evil:4318')
        self.assertEqual(code, 2)
        self.assertIn('--endpoint', err)
        self.assertNotIn('--telemetry-endpoint', err)

    def test_cli_usage_errors_exit_2(self):
        for args in ((), ('unknown',), ('snippet', '--bogus')):
            with self.subTest(args=args):
                code, out, _ = cli(*args)
                self.assertEqual((code, out), (2, ''))


if __name__ == '__main__':
    unittest.main()

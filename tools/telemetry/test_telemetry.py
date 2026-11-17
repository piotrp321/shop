"""Tests of telemetry.py: python3 -m unittest discover -s tools/telemetry -p 'test_*.py'

No model, no telemetry stack and no network beyond 127.0.0.1: the OTLP endpoint, Grafana and the `claude` binary are
test doubles (local HTTP servers and a stub script). The CLI runs as a subprocess with HOME in a temporary directory.
"""
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest

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
        run_env = {'PATH': os.environ.get('PATH', ''), 'HOME': home or fallback}
        run_env.update(env or {})
        result = subprocess.run([sys.executable, TELEMETRY] + list(args), capture_output=True, text=True,
                                env=run_env, cwd=cwd, timeout=120)
    return result.returncode, result.stdout, result.stderr


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

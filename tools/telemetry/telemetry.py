#!/usr/bin/env python3
"""Telemetry of Claude Code in the local stack (spec docs/features/telemetry/spec.md, R4). Python 3.9+, standard
library only.

  telemetry.py snippet [--endpoint URL]
  telemetry.py check [--endpoint URL] [--grafana URL] [--timeout S]
  telemetry.py check --project-override [--claude-bin BIN]

snippet: prints the JSON block with the key `env` for ~/.claude/settings.json of interactive sessions (the export
variables and OTEL_LOG_*=0 of render_settings.py, shop.source=interactive, no export intervals); writes nothing.
The endpoint is only http://127.0.0.1:<port> (default: DEFAULT_TELEMETRY_ENDPOINT of render_settings.py).

check: sends the gauge shop_telemetry_check with a random value to the OTLP endpoint (OTLP/HTTP, JSON) and asks
Prometheus every 2 s through the Grafana proxy of the data source with the uid `prometheus` (the anonymous Viewer,
GET <grafana>/api/datasources/proxy/uid/prometheus/api/v1/query) until the value shows up or --timeout (default 60 s)
passes. Both addresses are only http://127.0.0.1:<port> (defaults: the endpoint 4318 and Grafana 3030 of the stack
agent-observability). Exit 0 when the value is visible; exit 1 with one line on stderr when the OTLP endpoint is
closed or answers with an error, Grafana is down or answers with an error, there is no data source `prometheus`, or
the point is not there in time (the line says which address and what does not answer).

check --project-override [--claude-bin BIN]: the check that Claude Code ignores OTEL_* of the project settings (spec
R4). Runs a real `claude -p` (BIN, by default the binary of the version pinned by AO, found with claude_pin.py of the
skill ao-run) with Haiku, --max-budget-usd 0.1, --setting-sources project and --strict-mcp-config, with no CLAUDE* and
no OTEL_* variable in the environment, in a temporary git repository whose .claude/settings.json has an `env` with
OTEL_EXPORTER_OTLP_ENDPOINT (the first local receiver of the script), OTEL_LOG_USER_PROMPTS=1 and
OTEL_RESOURCE_ATTRIBUTES=shop.task=override. --settings carries the env block of render_settings.py aimed at the second
receiver. Exit 0 only when the first receiver got no request, the second got metrics (/v1/metrics) and logs
(/v1/logs), no body (after Content-Encoding) holds the marker of the prompt and shop.task has no value `override`. The
receiver and the decoder are the ones of agent-security/otlp_receiver.py. The stack is not touched; --endpoint,
--grafana and --timeout are ignored. The temporary repository and the receivers are removed on every path. A binary
that is not there (or not the pinned version) is exit 2; a binary that fails, hangs or exports wrongly is exit 1.

Exit codes: 0 success, 1 the check failed (the reason is one line on stderr), 2 a usage error.
"""
import argparse
import http.client
import importlib.util
import json
import math
import os
import random
import secrets
import shutil
import signal
import subprocess
import sys
import tempfile
import time

HERE = os.path.dirname(os.path.abspath(__file__))
SECURITY = os.path.join(HERE, '..', 'agent-security')
DEFAULT_GRAFANA = 'http://127.0.0.1:3030'
DEFAULT_TIMEOUT = 60.0         # seconds `check` waits for the point
POLL_INTERVAL = 2.0            # seconds between the queries of `check`
REQUEST_TIMEOUT = 5.0          # seconds of one HTTP request
PROMETHEUS_UID = 'prometheus'  # uid of the Prometheus data source in the Grafana of the stack
CHECK_METRIC = 'shop_telemetry_check'
BINARY_TIMEOUT = 180.0         # seconds of the `claude -p` run of the override check
SETTLE = 0.5                   # seconds the receivers still get after the binary ends, before they are read
MODEL = 'haiku'
BUDGET_USD = '0.1'
PROJECT_ATTRIBUTES = 'shop.task=override'   # what the project settings try to set, and the check must not see


def _load(name, directory):
    spec = importlib.util.spec_from_file_location('_telemetry_' + name, os.path.join(directory, name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


render_settings = _load('render_settings', SECURITY)
otlp_receiver = _load('otlp_receiver', SECURITY)


def snippet(endpoint):
    """The block for ~/.claude/settings.json; SettingsError for an endpoint other than http://127.0.0.1:<port>."""
    return {'env': render_settings.telemetry_env(endpoint, {'shop.source': 'interactive'}, intervals=False)}


def _request(base, method, path, timeout, body=None, headers=None):
    """(status, body bytes) of one request to base (http://127.0.0.1:<port>); OSError or HTTPException when the
    address does not answer."""
    connection = http.client.HTTPConnection('127.0.0.1', int(base.rsplit(':', 1)[1]), timeout=timeout)
    try:
        connection.request(method, path, body=body, headers=headers or {})
        response = connection.getresponse()
        return response.status, response.read()
    finally:
        connection.close()


def _why(error):
    return ' '.join(str(error).split()) or type(error).__name__   # one line, whatever the error text holds


def _gauge(value):
    """The OTLP/HTTP JSON body of ExportMetricsServiceRequest with the gauge of the check."""
    point = {'asDouble': value, 'timeUnixNano': str(time.time_ns())}
    return json.dumps({'resourceMetrics': [{
        'resource': {'attributes': [{'key': 'service.name', 'value': {'stringValue': 'shop-telemetry-check'}}]},
        'scopeMetrics': [{'scope': {'name': 'shop.telemetry.check'},
                          'metrics': [{'name': CHECK_METRIC, 'gauge': {'dataPoints': [point]}}]}]}]})


def _visible(grafana, value, request_timeout):
    """(True, None) when Prometheus has the value, (False, None) when it has not yet, (False, reason) when the
    answer is an error (Grafana down, no data source, a bad answer)."""
    path = '/api/datasources/proxy/uid/%s/api/v1/query?query=%s' % (PROMETHEUS_UID, CHECK_METRIC)
    try:
        status, body = _request(grafana, 'GET', path, request_timeout)
    except (OSError, http.client.HTTPException) as e:
        return False, 'Grafana %s nie odpowiada: %s' % (grafana, _why(e))
    if status == 404:
        return False, 'Grafana %s nie ma źródła danych prometheus (uid %s): odpowiedź 404' % (grafana, PROMETHEUS_UID)
    if status != 200:
        return False, 'Grafana %s odpowiada kodem %d na zapytanie do źródła prometheus' % (grafana, status)
    try:
        answer = json.loads(body)
        if answer['status'] != 'success':
            raise ValueError(answer.get('error', answer['status']))
        series = answer['data']['result']
        return any(float(item['value'][1]) == value for item in series), None
    except (ValueError, KeyError, TypeError, IndexError, AttributeError) as e:
        return False, 'Grafana %s: odpowiedź źródła prometheus nie jest wynikiem zapytania (%s)' % (grafana, _why(e))


def check_stack(endpoint, grafana, timeout, interval=POLL_INTERVAL, request_timeout=REQUEST_TIMEOUT, value=None):
    """(True, a line of the result) when the value sent to the OTLP endpoint shows up in Prometheus within `timeout`
    seconds; (False, the one-line reason) otherwise. The value is random unless given (an integer, so that the text
    of Prometheus and the number compare exactly)."""
    value = random.randint(10 ** 6, 10 ** 9) if value is None else value
    started = time.monotonic()
    try:
        status, _ = _request(endpoint, 'POST', '/v1/metrics', request_timeout, body=_gauge(value),
                             headers={'Content-Type': 'application/json'})
    except (OSError, http.client.HTTPException) as e:
        return False, 'punkt OTLP %s nie odpowiada: %s' % (endpoint, _why(e))
    if not 200 <= status < 300:
        return False, 'punkt OTLP %s odrzucił punkt kodem %d' % (endpoint, status)
    deadline = started + timeout
    while True:
        seen, reason = _visible(grafana, value, request_timeout)
        if reason:
            return False, reason
        if seen:
            return True, 'wartość %d metryki %s jest w Prometheusie (%s) po %.1f s' \
                % (value, CHECK_METRIC, grafana, time.monotonic() - started)
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            return False, 'po %g s brak punktu %s z wartością %d w Prometheusie (Grafana %s, źródło %s); punkt wysłano ' \
                'do %s' % (timeout, CHECK_METRIC, value, grafana, PROMETHEUS_UID, endpoint)
        time.sleep(min(interval, remaining))


class UsageError(Exception):
    """The check cannot be run (no binary): exit code 2."""


def resolve_binary(claude_bin):
    """The binary of the override check: claude_bin when it is an executable file, else the pinned one of AO."""
    if claude_bin:
        if not os.path.isfile(claude_bin) or not os.access(claude_bin, os.X_OK):
            raise UsageError('--claude-bin %s: to nie jest wykonywalny plik' % claude_bin)
        return os.path.abspath(claude_bin)
    pin = _load('claude_pin', os.path.join(HERE, '..', '..', '.claude', 'skills', 'ao-run'))
    try:
        version = pin.pinned_version(pin.DEFAULT_AO_DIR)
        return pin.pinned_binary(pin.DEFAULT_CANDIDATES, version)
    except pin.PinError as e:
        raise UsageError('nie można odczytać wersji przypiętej przez AO: %s' % _why(e))
    except pin.BinaryError as e:
        raise UsageError('przypięta wersja AO to %s, żadna binarka jej nie zgłasza (%s); zainstaluj ją '
                         '`claude install %s --force` albo podaj --claude-bin' % (version, _why(e), version))


def _kill(process):
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except OSError:
        pass


def _run_binary(command, cwd, env, timeout):
    """None when the binary ended with exit code 0, else the one-line reason."""
    try:
        process = subprocess.Popen(command, cwd=cwd, env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, text=True, errors='replace', start_new_session=True)
    except OSError as e:
        return 'nie można uruchomić %s: %s' % (command[0], _why(e))
    try:
        _, err = process.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        _kill(process)
        process.communicate()
        return 'limit czasu %g s: binarka %s nie skończyła, zabita' % (timeout, command[0])
    except BaseException:
        _kill(process)
        raise
    finally:
        _kill(process)   # whatever the binary left in its process group
    if process.returncode != 0:
        lines = err.strip().splitlines()
        return 'binarka %s zakończyła się kodem %d%s' % (command[0], process.returncode,
                                                         ': ' + _why(lines[-1])[:200] if lines else '')
    return None


def _override_verdict(first, second, marker):
    """None when the receivers show that the project settings were ignored, else the one-line reason."""
    if first.requests:
        return 'ustawienia projektu przekierowały eksport: odbiornik z .claude/settings.json dostał %d żądań' \
            % len(first.requests)
    paths = {request['path'] for request in second.requests}
    missing = [path for path in ('/v1/metrics', '/v1/logs') if path not in paths]
    if missing:
        return 'brak eksportu: odbiornik z --settings nie dostał %s' % ' ani '.join(missing)
    bodies = [request['body'] for request in second.requests]
    unreadable = [request for request in second.requests if request['body'] is None]
    for body in bodies:
        if body is not None and otlp_receiver.find_markers(body, {'prompt': marker}):
            return 'znacznik promptu jest w ciele żądania do odbiornika z --settings: treść promptu trafia do eksportu'
    if unreadable:
        return 'nie da się sprawdzić %d ciał żądań (%s)' % (len(unreadable), unreadable[0]['problem'])
    for request in second.requests:
        try:
            pairs = otlp_receiver.key_values(request['body'])
        except otlp_receiver.DecodeError:
            return 'nie da się sprawdzić atrybutów: ciało żądania %s nie jest wiadomością protobuf' % request['path']
        if ('shop.task', 'override') in pairs:
            return 'shop.task=override z ustawień projektu jest w danych żądania %s' % request['path']
    return None


def check_override(claude_bin, timeout=BINARY_TIMEOUT, settle=SETTLE):
    """(True, a line of the result) when `claude -p` ignores OTEL_* of the project settings; (False, the one-line
    reason) otherwise. See the module docstring; the repository and the receivers are removed on every path."""
    marker = 'OVERRIDE-' + secrets.token_hex(8)
    prompt = 'Reply with the single word OK. Run id (no action needed): %s' % marker
    receivers = []
    work = tempfile.mkdtemp(prefix='telemetry-override-')
    try:
        try:
            subprocess.run(['git', 'init', '-q', '--template=', work], check=True, capture_output=True, text=True)
        except (OSError, subprocess.CalledProcessError) as e:
            return False, 'nie można utworzyć tymczasowego repozytorium git: %s' % _why(getattr(e, 'stderr', None) or e)
        for _ in range(2):
            receivers.append(otlp_receiver.Receiver().start())
        first, second = receivers
        os.makedirs(os.path.join(work, '.claude'))
        project = {'env': {render_settings.TELEMETRY_ENDPOINT_VARIABLE: first.endpoint, 'OTEL_LOG_USER_PROMPTS': '1',
                           render_settings.TELEMETRY_ATTRIBUTES_VARIABLE: PROJECT_ATTRIBUTES}}
        with open(os.path.join(work, '.claude', 'settings.json'), 'w') as f:
            json.dump(project, f)
        block = {'env': render_settings.telemetry_env(second.endpoint, {'shop.source': 'ao', 'shop.task': 'check'})}
        command = [claude_bin, '-p', prompt, '--model', MODEL, '--max-budget-usd', BUDGET_USD,
                   '--setting-sources', 'project', '--strict-mcp-config', '--settings', json.dumps(block)]
        env = {k: v for k, v in os.environ.items() if not k.startswith(('CLAUDE', 'OTEL_'))}
        reason = _run_binary(command, work, env, timeout)
        if reason:
            return False, reason
        time.sleep(settle)   # a receiver may still be taking the last request when the binary has just ended
        reason = _override_verdict(first, second, marker)
        if reason:
            return False, reason
        return True, 'ustawienia projektu są ignorowane: %d żądań do odbiornika z --settings, 0 do odbiornika z ' \
            '.claude/settings.json' % len(second.requests)
    finally:
        for receiver in receivers:
            receiver.stop()
        shutil.rmtree(work, ignore_errors=True)


class _Parser(argparse.ArgumentParser):
    def error(self, message):
        self.exit(2, '%s: %s\n' % (self.prog, message))


def _seconds(text):
    try:
        value = float(text)
    except ValueError:
        raise argparse.ArgumentTypeError('%r nie jest liczbą sekund' % text)
    if not math.isfinite(value) or value <= 0:
        raise argparse.ArgumentTypeError('%r: limit czasu musi być dodatni i skończony' % text)
    return value


def build_parser():
    parser = _Parser(prog='telemetry.py', description='Telemetry of Claude Code in the local stack')
    commands = parser.add_subparsers(dest='command', required=True, parser_class=_Parser)
    snippet_parser = commands.add_parser('snippet', help='JSON block for ~/.claude/settings.json')
    snippet_parser.add_argument('--endpoint', default=render_settings.DEFAULT_TELEMETRY_ENDPOINT)
    check_parser = commands.add_parser('check', help='a point through the stack, or the override check')
    check_parser.add_argument('--endpoint', default=render_settings.DEFAULT_TELEMETRY_ENDPOINT)
    check_parser.add_argument('--grafana', default=DEFAULT_GRAFANA)
    check_parser.add_argument('--timeout', type=_seconds, default=DEFAULT_TIMEOUT)
    check_parser.add_argument('--project-override', action='store_true')
    check_parser.add_argument('--claude-bin', help='the binary of --project-override (default: the pinned one of AO)')
    return parser


def main(argv=None):
    parser = build_parser()
    args = parser.parse_args(argv)
    if args.command == 'check' and args.claude_bin and not args.project_override:
        parser.error('--claude-bin tylko z --project-override')
    try:
        if args.command == 'snippet':
            print(json.dumps(snippet(args.endpoint), indent=2))
            return 0
        if args.command == 'check' and args.project_override:
            ok, message = check_override(resolve_binary(args.claude_bin))
        elif args.command == 'check':
            endpoint = render_settings.telemetry_endpoint(args.endpoint, option='--endpoint')
            grafana = render_settings.telemetry_endpoint(args.grafana, option='--grafana')
            ok, message = check_stack(endpoint, grafana, args.timeout)
        if args.command == 'check':
            print('telemetry: %s' % message, file=sys.stdout if ok else sys.stderr)
            return 0 if ok else 1
    except (render_settings.SettingsError, UsageError) as e:
        print('telemetry: %s' % _why(e), file=sys.stderr)
        return 2
    return 2


if __name__ == '__main__':
    sys.exit(main())

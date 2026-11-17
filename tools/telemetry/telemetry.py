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

Exit codes: 0 success, 1 the check failed (the reason is one line on stderr), 2 a usage error.
"""
import argparse
import http.client
import importlib.util
import json
import math
import os
import random
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
SECURITY = os.path.join(HERE, '..', 'agent-security')
DEFAULT_GRAFANA = 'http://127.0.0.1:3030'
DEFAULT_TIMEOUT = 60.0         # seconds `check` waits for the point
POLL_INTERVAL = 2.0            # seconds between the queries of `check`
REQUEST_TIMEOUT = 5.0          # seconds of one HTTP request
PROMETHEUS_UID = 'prometheus'  # uid of the Prometheus data source in the Grafana of the stack
CHECK_METRIC = 'shop_telemetry_check'


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


def check_override(claude_bin):
    return None


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
    return parser


def main(argv=None):
    args = build_parser().parse_args(argv)
    try:
        if args.command == 'snippet':
            print(json.dumps(snippet(args.endpoint), indent=2))
            return 0
        if args.command == 'check':
            endpoint = render_settings.telemetry_endpoint(args.endpoint, option='--endpoint')
            grafana = render_settings.telemetry_endpoint(args.grafana, option='--grafana')
            ok, message = check_stack(endpoint, grafana, args.timeout)
            print('telemetry: %s' % message, file=sys.stdout if ok else sys.stderr)
            return 0 if ok else 1
    except render_settings.SettingsError as e:
        print('telemetry: %s' % e, file=sys.stderr)
        return 2
    return 2


if __name__ == '__main__':
    sys.exit(main())

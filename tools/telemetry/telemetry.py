#!/usr/bin/env python3
"""Telemetry of Claude Code in the local stack (spec docs/features/telemetry/spec.md, R4). Python 3.9+, standard
library only.

  telemetry.py snippet [--endpoint URL]
  telemetry.py check [--endpoint URL] [--grafana URL] [--timeout S]
  telemetry.py check --project-override [--claude-bin BIN]

snippet: prints the JSON block with the key `env` for ~/.claude/settings.json of interactive sessions (the export
variables and OTEL_LOG_*=0 of render_settings.py, shop.source=interactive, no export intervals); writes nothing.
The endpoint is only http://127.0.0.1:<port> (default: DEFAULT_TELEMETRY_ENDPOINT of render_settings.py).

Exit codes: 0 success, 1 the check failed (the reason is one line on stderr), 2 a usage error.
"""
import argparse
import importlib.util
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SECURITY = os.path.join(HERE, '..', 'agent-security')


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


def check_stack(endpoint, grafana, timeout):
    return None


def check_override(claude_bin):
    return None


class _Parser(argparse.ArgumentParser):
    def error(self, message):
        self.exit(2, '%s: %s\n' % (self.prog, message))


def main(argv=None):
    parser = _Parser(prog='telemetry.py', description='Telemetry of Claude Code in the local stack')
    commands = parser.add_subparsers(dest='command', required=True, parser_class=_Parser)
    snippet_parser = commands.add_parser('snippet', help='JSON block for ~/.claude/settings.json')
    snippet_parser.add_argument('--endpoint', default=render_settings.DEFAULT_TELEMETRY_ENDPOINT)
    args = parser.parse_args(argv)
    try:
        if args.command == 'snippet':
            print(json.dumps(snippet(args.endpoint), indent=2))
            return 0
    except render_settings.SettingsError as e:
        print('telemetry: %s' % e, file=sys.stderr)
        return 2
    return 2


if __name__ == '__main__':
    sys.exit(main())

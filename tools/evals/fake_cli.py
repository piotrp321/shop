#!/usr/bin/env python3
"""A fake Claude Code CLI for the tests of the eval runner: no model, no network. Python 3.9+, standard library only.

  fake_cli.py --scenario FILE <the arguments of claude>

The scenario is JSON {"runs": [[step, ...], ...]}: invocation i (counted in FILE.count) plays runs[i] (the last one
when there are fewer). Steps:

  {"init": {...}}          the init event (defaults: no MCP servers, model, version, the superpowers plugin) merged
                           with the given fields
  {"bash": "command"}      an assistant tool_use of Bash, then the command run with sh in the working directory and
                           the environment of the process, and its tool_result
  {"rate_limit": {...}}    a rate_limit_event with that rate_limit_info
  {"result": {...}}        the result event (defaults: success, result "", total_cost_usd 0.1)
  {"raw": "text"}          a raw line on stdout
  {"stderr": "text"}       a line on stderr
  {"sleep": seconds}
  {"exit": code}           exits at once

Before the first step it writes <cwd>/../fake-record.json (inside the pass directory): argv, environment, cwd and the
content of the --settings file.
"""
import json
import os
import subprocess
import sys
import time


def emit(event):
    sys.stdout.write(json.dumps(event) + '\n')
    sys.stdout.flush()


def main(argv):
    scenario_path = argv[argv.index('--scenario') + 1]
    args = argv[argv.index('--scenario') + 2:]
    with open(scenario_path) as handle:
        runs = json.load(handle)['runs']
    count_path = scenario_path + '.count'
    count = int(open(count_path).read()) if os.path.exists(count_path) else 0
    with open(count_path, 'w') as handle:
        handle.write(str(count + 1))
    settings = None
    if '--settings' in args:
        with open(args[args.index('--settings') + 1]) as handle:
            settings = json.load(handle)
    with open(os.path.join(os.path.dirname(os.getcwd()), 'fake-record.json'), 'w') as handle:
        json.dump({'argv': args, 'env': dict(os.environ), 'cwd': os.getcwd(), 'settings': settings,
                   'invocation': count}, handle)
    for step in runs[min(count, len(runs) - 1)]:
        if 'init' in step:
            event = {'type': 'system', 'subtype': 'init', 'mcp_servers': [], 'model': 'claude-fake-model',
                     'claude_code_version': '0.0.0-fake', 'plugins': [{'name': 'superpowers', 'path': '/p'}],
                     'tools': ['Bash', 'Read']}
            event.update(step['init'])
            emit(event)
        elif 'bash' in step:
            emit({'type': 'assistant', 'message': {'content': [
                {'type': 'tool_use', 'id': 'toolu_%d' % time.monotonic_ns(), 'name': 'Bash',
                 'input': {'command': step['bash']}}]}})
            result = subprocess.run(['sh', '-c', step['bash']], stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                    universal_newlines=True)
            emit({'type': 'user', 'message': {'content': [{'type': 'tool_result', 'content': result.stdout}]}})
        elif 'rate_limit' in step:
            emit({'type': 'rate_limit_event', 'rate_limit_info': step['rate_limit']})
        elif 'result' in step:
            event = {'type': 'result', 'subtype': 'success', 'is_error': False, 'result': '', 'total_cost_usd': 0.1}
            event.update(step['result'])
            emit(event)
        elif 'raw' in step:
            sys.stdout.write(step['raw'] + '\n')
            sys.stdout.flush()
        elif 'stderr' in step:
            sys.stderr.write(step['stderr'] + '\n')
            sys.stderr.flush()
        elif 'sleep' in step:
            time.sleep(step['sleep'])
        elif 'exit' in step:
            return step['exit']
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))

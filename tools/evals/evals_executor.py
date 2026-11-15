"""The executors of the eval runner: how an agent is started and how its output is read (spec: Uruchomienie agenta).
Python 3.9+, standard library only.

An executor has a name (the `executor` of a suite in the registry, default `claude-cli`) and two parts:

  command(binary, prompt, agent, budget_usd, settings_path, add_dirs) -> argv
      started by the runner in the case checkout, with the guarded environment of evals_guard;
  stream(stop_percent) -> an object with
      feed(line) -> None or a stop signal ('infra_error', 'limit_stop'): the runner stops the process on a signal;
      take_tool_uses() -> the tool calls seen since the last call (the runner stores them outside the agent's reach);
      outcome(exit_code, timed_out, budget_usd, stderr='') -> {status, reason, cost_usd, output, cli_version, model,
          plugins, mcp_servers}, status one of done, failed, limit_stop, infra_error.

The runner, the checkout, the sandbox and the artefacts do not depend on the executor, so another executor (the
benchmark of EVALS-BENCH, a Codex adapter) is one more class in EXECUTORS.
"""
import json

ALLOWED_LIMIT_STATUSES = ('allowed', 'allowed_warning')


def _budget(value):
    text = ('%.4f' % value).rstrip('0').rstrip('.')
    return text or '0'


class ClaudeCliExecutor:
    """Claude Code in print mode with the stream-json output. The agent's frontmatter decides the model, the effort
    and the tools: the command never has --model, --allowedTools or --tools. --strict-mcp-config without an MCP
    config and --setting-sources project cut the servers, connectors, hooks and plugins of the user's configuration;
    only the project configuration of the overlay applies."""

    name = 'claude-cli'

    def command(self, binary, prompt, agent, budget_usd, settings_path, add_dirs=()):
        cmd = [binary, '-p', prompt, '--agent', agent, '--output-format', 'stream-json', '--verbose',
               '--permission-mode', 'auto', '--permission-prompts', 'none', '--max-budget-usd', _budget(budget_usd),
               '--strict-mcp-config', '--setting-sources', 'project', '--settings', settings_path]
        for directory in add_dirs:
            cmd += ['--add-dir', directory]
        return cmd

    def stream(self, stop_percent):
        return ClaudeStream(stop_percent)


def _percent(value):
    if not isinstance(value, (int, float)) or isinstance(value, bool):
        return None
    return value * 100 if value <= 1 else value


class ClaudeStream:
    """The state of one stream-json output."""

    def __init__(self, stop_percent):
        self.stop_percent = stop_percent
        self.init = None
        self.result = None
        self.stop = None          # (status, reason) once the run must stop
        self._tool_uses = []

    # ------------------------------------------------------------------------------------------------ events

    def feed(self, line):
        try:
            event = json.loads(line)
        except ValueError:
            return None
        if not isinstance(event, dict):
            return None
        kind = event.get('type')
        if kind == 'system' and event.get('subtype') == 'init' and self.init is None:
            self.init = event
            servers = event.get('mcp_servers')
            if not isinstance(servers, list):
                return self._stop('infra_error', 'the init event has no list of MCP servers: cannot tell that none '
                                                 'is connected')
            if servers:
                names = [str(s.get('name') if isinstance(s, dict) else s) for s in servers]
                return self._stop('infra_error', 'MCP servers in the init event (%s): the pass must run without any'
                                  % ', '.join(names))
        elif kind == 'assistant':
            message = event.get('message') if isinstance(event.get('message'), dict) else {}
            content = message.get('content') if isinstance(message.get('content'), list) else []
            for block in content:
                if isinstance(block, dict) and block.get('type') == 'tool_use':
                    self._tool_uses.append({'id': block.get('id'), 'name': block.get('name'),
                                            'input': block.get('input'),
                                            'parent_tool_use_id': event.get('parent_tool_use_id')})
        elif kind == 'rate_limit_event':
            return self._rate_limit(event.get('rate_limit_info'))
        elif kind == 'result':
            self.result = event
        return None

    def _stop(self, status, reason):
        if self.stop is None:
            self.stop = (status, reason)
        return status

    def _rate_limit(self, info):
        if not isinstance(info, dict):
            return None
        windows = [('', info)]
        unified = info.get('unifiedWindows')
        if isinstance(unified, dict):
            windows += [(name, w) for name, w in sorted(unified.items()) if isinstance(w, dict)]
        for name, window in windows:
            label = name or info.get('rateLimitType') or 'the window'
            status = window.get('status')
            if status is not None and status not in ALLOWED_LIMIT_STATUSES:
                return self._stop('limit_stop', 'rate_limit_event: %s has the status %s' % (label, status))
            percent = _percent(window.get('utilization'))
            if percent is not None and percent >= self.stop_percent:
                return self._stop('limit_stop', 'rate_limit_event: %s at %.0f%% (stop at %s%%)'
                                  % (label, percent, self.stop_percent))
        return None

    def take_tool_uses(self):
        calls, self._tool_uses = self._tool_uses, []
        return calls

    # ------------------------------------------------------------------------------------------------ outcome

    def outcome(self, exit_code, timed_out, budget_usd, stderr=''):
        init = self.init or {}
        result = self.result or {}
        cost = result.get('total_cost_usd')
        if not isinstance(cost, (int, float)) or isinstance(cost, bool):
            cost = None
        plugins = init.get('plugins') if isinstance(init.get('plugins'), list) else []
        out = {'cost_usd': cost, 'output': result.get('result') if isinstance(result.get('result'), str) else '',
               'cli_version': init.get('claude_code_version'), 'model': init.get('model'),
               'plugins': [str(p.get('name') if isinstance(p, dict) else p) for p in plugins],
               'mcp_servers': init.get('mcp_servers') if isinstance(init.get('mcp_servers'), list) else None}
        out['status'], out['reason'] = self._status(exit_code, timed_out, budget_usd, cost, result, stderr)
        return out

    def _status(self, exit_code, timed_out, budget_usd, cost, result, stderr):
        if self.stop is not None:
            return self.stop
        tail = (stderr or '').strip()[-300:]
        if self.init is None:
            if timed_out:
                return 'infra_error', 'the CLI did not send the init event before the pass timeout'
            return 'infra_error', 'the CLI ended before the init event (exit %s)%s' % (
                exit_code, ': ' + tail if tail else '')
        if timed_out:
            return 'failed', 'the pass timeout was reached'
        if not self.result:
            return 'failed', 'incomplete stream: no result event (exit %s)' % exit_code
        subtype = str(result.get('subtype'))
        if 'budget' in subtype or (cost is not None and cost > budget_usd):
            return 'failed', 'the pass budget of %s USD was exceeded (%s)' % (_budget(budget_usd), subtype)
        if result.get('is_error') or subtype != 'success':
            return 'failed', 'CLI error after init: %s' % subtype
        if exit_code != 0:
            return 'failed', 'the CLI exit code %s after init' % exit_code
        return 'done', ''


EXECUTORS = {ClaudeCliExecutor.name: ClaudeCliExecutor}

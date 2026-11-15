"""Tests of the Claude Code executor: the command (spec: Uruchomienie agenta) and the stream-json events."""
import json
import unittest

import evals_executor as executor


def line(event):
    return json.dumps(event) + '\n'


INIT = {'type': 'system', 'subtype': 'init', 'mcp_servers': [], 'model': 'claude-opus-5-5',
        'claude_code_version': '2.1.311', 'plugins': [{'name': 'superpowers', 'path': '/x'}], 'tools': ['Read']}


def result(cost=0.4, subtype='success', is_error=False, text='PASS'):
    return {'type': 'result', 'subtype': subtype, 'is_error': is_error, 'result': text, 'total_cost_usd': cost}


def rate(utilization=None, status='allowed', windows=None):
    info = {'status': status, 'rateLimitType': 'five_hour', 'overageStatus': 'rejected'}
    if utilization is not None:
        info['utilization'] = utilization
    if windows:
        info['unifiedWindows'] = windows
    return {'type': 'rate_limit_event', 'rate_limit_info': info}


class CommandTest(unittest.TestCase):
    def command(self, add_dirs=()):
        return executor.ClaudeCliExecutor().command('/opt/claude', 'review this', 'code-reviewer', 3,
                                                    '/state/p/runner/settings.json', add_dirs)

    def test_the_command_runs_the_agent_with_project_settings_only_and_the_sandbox_settings(self):
        cmd = self.command()
        self.assertEqual(['/opt/claude', '-p', 'review this', '--agent', 'code-reviewer'], cmd[:5])
        for pair in (['--output-format', 'stream-json'], ['--permission-mode', 'auto'],
                     ['--permission-prompts', 'none'], ['--max-budget-usd', '3'], ['--setting-sources', 'project'],
                     ['--settings', '/state/p/runner/settings.json']):
            index = cmd.index(pair[0])
            self.assertEqual(pair, cmd[index:index + 2])
        self.assertIn('--verbose', cmd)
        self.assertIn('--strict-mcp-config', cmd)
        self.assertNotIn('--add-dir', cmd)

    def test_the_command_never_overrides_the_model_or_the_tools(self):
        for cmd in (self.command(), self.command(['/state/p/files'])):
            for flag in ('--model', '--allowedTools', '--allowed-tools', '--tools', '--disallowedTools',
                         '--mcp-config', '--dangerously-skip-permissions'):
                self.assertNotIn(flag, cmd)

    def test_agents_writing_results_get_the_files_directory(self):
        cmd = self.command(['/state/p/files'])
        index = cmd.index('--add-dir')
        self.assertEqual(['--add-dir', '/state/p/files'], cmd[index:index + 2])

    def test_a_fractional_budget_is_kept(self):
        cmd = executor.ClaudeCliExecutor().command('/c', 'p', 'a', 1.25, '/s', ())
        self.assertEqual('1.25', cmd[cmd.index('--max-budget-usd') + 1])


class StreamTest(unittest.TestCase):
    def feed(self, *events, stop_percent=90):
        stream = executor.ClaudeCliExecutor().stream(stop_percent)
        signals = [stream.feed(line(e) if isinstance(e, dict) else e) for e in events]
        return stream, signals

    def outcome(self, *events, code=0, timed_out=False, budget=3):
        stream, _ = self.feed(*events)
        return stream.outcome(code, timed_out, budget, stderr='boom')

    def test_a_complete_stream_is_done_with_cost_result_model_version_and_plugins(self):
        out = self.outcome(INIT, result(cost=0.42, text='CHANGES_REQUIRED'))
        self.assertEqual('done', out['status'])
        self.assertEqual(0.42, out['cost_usd'])
        self.assertEqual('CHANGES_REQUIRED', out['output'])
        self.assertEqual('claude-opus-5-5', out['model'])
        self.assertEqual('2.1.311', out['cli_version'])
        self.assertEqual(['superpowers'], out['plugins'])
        self.assertEqual([], out['mcp_servers'])

    def test_an_mcp_server_in_init_is_an_infra_error_and_stops_the_cli(self):
        init = dict(INIT, mcp_servers=[{'name': 'github', 'status': 'connected'}])
        stream, signals = self.feed(init)
        self.assertEqual(['infra_error'], signals)
        out = stream.outcome(0, False, 3)
        self.assertEqual('infra_error', out['status'])
        self.assertIn('github', out['reason'])

    def test_an_init_without_the_mcp_server_list_is_an_infra_error(self):
        init = {k: v for k, v in INIT.items() if k != 'mcp_servers'}
        out = self.outcome(init, result())
        self.assertEqual('infra_error', out['status'])

    def test_an_error_before_init_is_an_infra_error(self):
        out = self.outcome('not json at all\n', code=1)
        self.assertEqual('infra_error', out['status'])
        self.assertIn('init', out['reason'])
        self.assertIn('boom', out['reason'])
        self.assertEqual('infra_error', self.outcome(timed_out=True)['status'])

    def test_over_the_pass_budget_is_failed(self):
        out = self.outcome(INIT, result(cost=3.2, subtype='error_max_budget_usd', is_error=True))
        self.assertEqual(('failed', 3.2), (out['status'], out['cost_usd']))
        self.assertIn('budget', out['reason'])
        out = self.outcome(INIT, result(cost=3.5))
        self.assertEqual('failed', out['status'])
        self.assertIn('budget', out['reason'])

    def test_the_pass_timeout_after_init_is_failed(self):
        out = self.outcome(INIT, timed_out=True)
        self.assertEqual('failed', out['status'])
        self.assertIn('timeout', out['reason'])

    def test_a_cli_error_after_init_is_failed(self):
        out = self.outcome(INIT, result(subtype='error_during_execution', is_error=True))
        self.assertEqual('failed', out['status'])
        self.assertIn('error_during_execution', out['reason'])
        out = self.outcome(INIT, result(), code=3)
        self.assertEqual('failed', out['status'])
        self.assertIn('exit', out['reason'])

    def test_an_incomplete_stream_is_failed(self):
        out = self.outcome(INIT, {'type': 'assistant', 'message': {'content': []}})
        self.assertEqual('failed', out['status'])
        self.assertIn('incomplete', out['reason'])

    def test_a_rate_limit_event_at_the_stop_threshold_stops_the_run(self):
        for event in (rate(0.9), rate(windows={'seven_day': {'utilization': 0.95, 'status': 'allowed'}}),
                      rate(status='rejected'), rate(windows={'five_hour': {'status': 'rate_limited'}})):
            stream, signals = self.feed(INIT, event)
            self.assertEqual([None, 'limit_stop'], signals, event)
            out = stream.outcome(-15, False, 3)
            self.assertEqual('limit_stop', out['status'])
        stream, signals = self.feed(rate(0.91))
        self.assertEqual(['limit_stop'], signals)
        self.assertEqual('limit_stop', stream.outcome(-15, False, 3)['status'])

    def test_a_rate_limit_event_below_the_threshold_goes_on(self):
        for event in (rate(0.89), rate(0.5, status='allowed_warning'),
                      rate(windows={'seven_day': {'utilization': 0.58, 'status': 'allowed'}})):
            stream, signals = self.feed(INIT, event, result())
            self.assertEqual([None, None, None], signals, event)
            self.assertEqual('done', stream.outcome(0, False, 3)['status'])
        stream, signals = self.feed(INIT, rate(0.85), stop_percent=80)
        self.assertEqual([None, 'limit_stop'], signals)

    def test_tool_use_calls_of_the_agent_and_its_subagents_are_collected(self):
        bash = {'type': 'tool_use', 'id': 't1', 'name': 'Bash', 'input': {'command': 'gh pr merge 1'}}
        nested = {'type': 'tool_use', 'id': 't2', 'name': 'Write', 'input': {'file_path': 'x'}}
        stream, _ = self.feed(INIT, {'type': 'assistant', 'message': {'content': [{'type': 'text', 'text': 'hi'}, bash]}},
                              {'type': 'assistant', 'parent_tool_use_id': 't0', 'message': {'content': [nested]}})
        calls = stream.take_tool_uses()
        self.assertEqual([('t1', 'Bash', {'command': 'gh pr merge 1'}, None), ('t2', 'Write', {'file_path': 'x'}, 't0')],
                         [(c['id'], c['name'], c['input'], c['parent_tool_use_id']) for c in calls])
        self.assertEqual([], stream.take_tool_uses())


if __name__ == '__main__':
    unittest.main()

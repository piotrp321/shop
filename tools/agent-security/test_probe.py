"""Tests of probe.py without a model: python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

The evaluation of a stream-json transcript is a pure function fed synthetic events. The run of the executor is driven
with a fake binary (a small script) in place of the wrapper, so no Keychain, Docker or model is needed; the fake
emits a canned transcript, sleeps (timeout), or is missing (start failure). Cleanup is checked on the artifacts the
probe creates.
"""
import importlib.util
import json
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(HERE, 'probe.py')
spec = importlib.util.spec_from_file_location('probe', SCRIPT)
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)

CANARY = 'CANARY-abc123-secret'


def result_event(cost=0.004):
    return {'type': 'result', 'subtype': 'success', 'total_cost_usd': cost, 'is_error': False}


def verdict_tool_result(verdicts):
    """A tool_result carrying the JSON lines probe-cases.sh prints (one verdict per line)."""
    text = '\n'.join(json.dumps(v) for v in verdicts)
    return {'type': 'user', 'message': {'content': [{'type': 'tool_result',
            'content': [{'type': 'text', 'text': text}]}]}}


def all_pass_verdicts():
    return [{'case': name, 'status': 'pass'} for name in probe.CASE_NAMES]


def full_transcript(verdicts=None, cost=0.004, final=True):
    events = [{'type': 'system', 'subtype': 'init'},
              verdict_tool_result(verdicts if verdicts is not None else all_pass_verdicts())]
    if final:
        events.append(result_event(cost))
    return events


class EvaluateTest(unittest.TestCase):
    def evaluate(self, events, cost_limit=1.0):
        return probe.evaluate(events, probe.CASE_NAMES, CANARY, cost_limit)

    def test_all_cases_pass(self):
        code, result = self.evaluate(full_transcript())
        self.assertEqual(code, 0, result.get('reason'))
        self.assertTrue(all(c['status'] == 'pass' for c in result['cases']))
        self.assertIsNone(result['reason'])
        self.assertEqual(result['total_cost_usd'], 0.004)

    def test_a_canary_in_a_tool_result_fails(self):
        events = full_transcript()
        events[1]['message']['content'][0]['content'][0]['text'] += '\nleaked: ' + CANARY
        code, result = self.evaluate(events)
        self.assertEqual(code, 1)
        self.assertIn('kanark', result['reason'].lower())
        self.assertNotIn(CANARY, json.dumps(result))

    def test_a_canary_deep_in_an_event_fails(self):
        events = full_transcript()
        events.insert(1, {'type': 'assistant', 'message': {'content': [{'type': 'text', 'text': CANARY}]}})
        code, result = self.evaluate(events)
        self.assertEqual(code, 1)
        self.assertNotIn(CANARY, json.dumps(result))

    def test_a_failed_case_fails_with_its_name(self):
        verdicts = all_pass_verdicts()
        verdicts[3]['status'] = 'fail'
        name = verdicts[3]['case']
        code, result = self.evaluate(full_transcript(verdicts))
        self.assertEqual(code, 1)
        self.assertIn(name, result['reason'])

    def test_a_missing_case_fails(self):
        verdicts = [v for v in all_pass_verdicts() if v['case'] != probe.CASE_NAMES[0]]
        code, result = self.evaluate(full_transcript(verdicts))
        self.assertEqual(code, 1)
        self.assertIn(probe.CASE_NAMES[0], result['reason'])
        self.assertEqual([c['status'] for c in result['cases'] if c['name'] == probe.CASE_NAMES[0]], ['missing'])

    def test_cost_over_the_limit_fails(self):
        code, result = self.evaluate(full_transcript(cost=1.5), cost_limit=1.0)
        self.assertEqual(code, 1)
        self.assertIn('koszt', result['reason'].lower())

    def test_truncated_output_without_a_final_result_fails(self):
        code, result = self.evaluate(full_transcript(final=False))
        self.assertEqual(code, 1)
        self.assertTrue(result['reason'])

    def test_an_error_result_event_fails(self):
        events = full_transcript()
        events[-1] = {'type': 'result', 'subtype': 'error_max_turns', 'total_cost_usd': 0.004, 'is_error': True}
        code, result = self.evaluate(events)
        self.assertEqual(code, 1)

    def test_the_first_failure_is_the_reason(self):
        verdicts = all_pass_verdicts()
        verdicts[2]['status'] = 'fail'
        verdicts[5]['status'] = 'fail'
        code, result = self.evaluate(full_transcript(verdicts))
        self.assertIn(probe.CASE_NAMES[2], result['reason'])

    def test_layers_are_reported(self):
        _, result = self.evaluate(full_transcript())
        layers = {c['layer'] for c in result['cases']}
        self.assertTrue({'sandbox', 'permissions', 'docker', 'token', 'wrapper'} <= layers)


class ProbeRunTestCase(unittest.TestCase):
    def setUp(self):
        self.tmp = os.path.realpath(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.bin = os.path.join(self.tmp, 'fake-wrapper')

    def write_fake(self, body):
        with open(self.bin, 'w') as f:
            f.write(body)
        os.chmod(self.bin, 0o755)

    def transcript_fake(self, events):
        transcript = os.path.join(self.tmp, 'transcript.jsonl')
        with open(transcript, 'w') as f:
            for event in events:
                f.write(json.dumps(event) + '\n')
        self.write_fake('#!/bin/sh\ncat %s\nexit 0\n' % transcript)

    def make_probe(self, **kwargs):
        defaults = dict(claude_bin=self.bin, canary=CANARY, work_root=self.tmp, timeout_s=30, cost_limit=1.0,
                        mode='default')
        defaults.update(kwargs)
        return probe.Probe(**defaults)

    def artifacts(self, p):
        return [p.worktree, p.ao_home] + list(p.canary_paths)


class ProbeRunTest(ProbeRunTestCase):
    def test_a_clean_transcript_exits_zero_and_cleans_up(self):
        self.transcript_fake(full_transcript())
        p = self.make_probe()
        code, result = p.run()
        self.assertEqual(code, 0, result.get('reason'))
        for path in self.artifacts(p):
            self.assertFalse(os.path.exists(path), path)

    def test_a_leak_exits_one_and_cleans_up(self):
        events = full_transcript()
        events[1]['message']['content'][0]['content'][0]['text'] += '\n' + CANARY
        self.transcript_fake(events)
        p = self.make_probe()
        code, result = p.run()
        self.assertEqual(code, 1)
        self.assertNotIn(CANARY, json.dumps(result))
        for path in self.artifacts(p):
            self.assertFalse(os.path.exists(path), path)

    def test_a_missing_binary_exits_two(self):
        p = self.make_probe(claude_bin=os.path.join(self.tmp, 'nope'))
        code, result = p.run()
        self.assertEqual(code, 2)
        self.assertTrue(result['reason'])

    def test_a_binary_that_never_ends_times_out_with_exit_one(self):
        self.write_fake('#!/bin/sh\nwhile true; do sleep 1; done\n')
        p = self.make_probe(timeout_s=2)
        start = time.time()
        code, result = p.run()
        self.assertEqual(code, 1)
        self.assertIn('czas', result['reason'].lower())
        self.assertLess(time.time() - start, 25)
        for path in self.artifacts(p):
            self.assertFalse(os.path.exists(path), path)

    def test_a_non_zero_binary_exits_two(self):
        self.write_fake('#!/bin/sh\necho boom >&2\nexit 3\n')
        p = self.make_probe()
        code, result = p.run()
        self.assertEqual(code, 2)

    def test_the_child_runs_without_claude_variables(self):
        self.write_fake('#!/bin/sh\n[ -z "${CLAUDE_CODE_SESSION_ID:-}${CLAUDECODE:-}" ] || { echo set >&2; exit 9; }\n'
                        "printf '%s\\n' '" + json.dumps(result_event()) + "'\n")
        # a transcript with only the result event means every case is missing -> exit 1, but the child saw no CLAUDE*
        env = dict(os.environ, CLAUDECODE='1', CLAUDE_CODE_SESSION_ID='abc')
        p = self.make_probe()
        code, result = p.run(env=env)
        self.assertNotEqual(code, 2)
        self.assertNotIn('set', result.get('reason') or '')

    def test_cleanup_after_a_signal(self):
        self.write_fake('#!/bin/sh\necho started > "%s/started"\nwhile true; do sleep 1; done\n'
                        % self.tmp)
        script = os.path.join(self.tmp, 'driver.py')
        with open(script, 'w') as f:
            f.write(
                'import importlib.util, json, os, sys\n'
                'spec = importlib.util.spec_from_file_location("probe", %r)\n' % SCRIPT +
                'probe = importlib.util.module_from_spec(spec); spec.loader.exec_module(probe)\n'
                'p = probe.Probe(claude_bin=%r, canary=%r, work_root=%r, timeout_s=60, cost_limit=1.0, mode="default")\n'
                % (self.bin, CANARY, self.tmp) +
                'open(%r, "w").write(json.dumps([p.worktree, p.ao_home] + list(p.canary_paths)))\n'
                % os.path.join(self.tmp, 'paths.json') +
                'p.run()\n')
        driver = subprocess.Popen([sys.executable, script])
        started = os.path.join(self.tmp, 'started')
        for _ in range(200):
            if os.path.exists(started):
                break
            time.sleep(0.05)
        self.assertTrue(os.path.exists(started), 'the fake executor never started')
        time.sleep(0.2)
        driver.send_signal(signal.SIGINT)
        driver.wait(timeout=30)
        with open(os.path.join(self.tmp, 'paths.json')) as f:
            paths = json.load(f)
        for path in paths:
            self.assertFalse(os.path.exists(path), path)
        self.assertFalse(self.running_fakes(), 'a fake executor survived the signal')

    def running_fakes(self):
        out = subprocess.run(['pgrep', '-f', self.bin], capture_output=True, text=True).stdout.strip()
        return out


class ProbeArtifactsTest(ProbeRunTestCase):
    def test_setup_builds_the_fake_ao_home_and_canaries(self):
        p = self.make_probe()
        p.setup()
        self.addCleanup(p.cleanup)
        for name in ('tasks', 'epics'):
            self.assertTrue(os.path.isdir(os.path.join(p.ao_home, name)))
        self.assertTrue(os.path.isdir(os.path.join(p.ao_home, 'agent-security')))
        self.assertTrue(os.path.isfile(os.path.join(p.ao_home, 'events.jsonl')))
        task = os.path.join(p.ao_home, 'tasks', p.task_id)
        self.assertTrue(os.path.isdir(os.path.join(task, 'briefs')))
        self.assertTrue(os.path.isdir(os.path.join(task, 'attempts')))
        self.assertTrue(p.canary_paths)
        for path in p.canary_paths:
            with open(path) as f:
                self.assertIn(CANARY, f.read())

    def test_cleanup_is_idempotent(self):
        p = self.make_probe()
        p.setup()
        p.cleanup()
        p.cleanup()
        for path in self.artifacts(p):
            self.assertFalse(os.path.exists(path), path)


if __name__ == '__main__':
    unittest.main()

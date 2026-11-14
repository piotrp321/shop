"""Tests of pairs.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import contextlib
import importlib.util
import io
import json
import os
import tempfile
import unittest
from datetime import datetime, timezone
from unittest import mock

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pairs.py')
spec = importlib.util.spec_from_file_location('pairs', SCRIPT)
pairs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pairs)

NOW = datetime(2026, 11, 14, 12, 0, tzinfo=timezone.utc)
RESET = '2026-11-14T18:00:00Z'


def usage(percent, reset=RESET, status='ok'):
    if status != 'ok':
        return {'status': status}
    return {'status': 'ok', 'windows': [{'label': '5h', 'percentUsed': percent, 'resetsAt': reset}]}


def start(epic, at, percent=10, docker=None, **extra):
    """A start of the 19a format: it has the `docker` key (null when not given)."""
    event = {'event': 'start', 'epic': epic, 'at': at, 'usage': usage(percent), 'tasks': 3, 'docker': docker}
    event.update(extra)
    return event


def old_start(epic, at, percent=10):
    """A start written before 19a: no `docker` key."""
    event = start(epic, at, percent)
    del event['docker']
    return event


def done(epic, at, percent=20, **extra):
    event = {'event': 'done', 'epic': epic, 'at': at, 'usage': usage(percent)}
    event.update(extra)
    return event


SUMS = {'conflicts': 0, 'auto_resolved': 0, 'needed_intervention': 0, 'doc_fixes': 0, 'interventions': 0,
        'regressions': 0, 'critical_pairs': 0}
EMPTY_SUMMARY = dict(SUMS, admitted=0, recorded=0, claude_md=dict(SUMS, pairs=0), code=dict(SUMS, pairs=0))


def verdict(**extra):
    item = {'shared': [], 'conflicts': 0, 'auto_resolved': 0, 'doc_fixes': 0, 'interventions': 0, 'regressions': 0,
            'critical': []}
    item.update(extra)
    return item


class PairsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.state = os.path.join(self.tmp.name, 'state')
        self.diag = os.path.join(self.tmp.name, 'diag')
        os.makedirs(self.state)

    def write(self, name, lines, directory=None):
        with open(os.path.join(directory or self.state, name), 'w', encoding='utf-8') as f:
            for line in lines:
                f.write((line if isinstance(line, str) else json.dumps(line)) + '\n')

    def run_main(self, now=NOW):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = pairs.main(['--state', self.state, '--setup-diag', self.diag], now=now)
        return code, out.getvalue(), err.getvalue()

    def result(self, now=NOW):
        code, out, _ = self.run_main(now)
        self.assertEqual(0, code)
        self.assertEqual(1, len(out.splitlines()))
        return json.loads(out)

    def test_no_files(self):
        self.assertEqual({'pairs': [], 'claude_md_only': [], 'code_blocked': [], 'summary': EMPTY_SUMMARY},
                         self.result())

    def record(self, a, b, payload, now=NOW):
        out, err = io.StringIO(), io.StringIO()
        text = payload if isinstance(payload, str) else json.dumps(payload)
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = pairs.main(['record', a, b, '--state', self.state], now=now, stdin=io.StringIO(text))
        return code, out.getvalue(), err.getvalue()

    def pairs_file(self):
        path = os.path.join(self.state, 'pairs.jsonl')
        if not os.path.exists(path):
            return None
        with open(path, encoding='utf-8') as f:
            return [json.loads(line) for line in f if line.strip()]

    def test_record_writes_a_line(self):
        code, out, err = self.record('a', 'b', verdict(conflicts=2, auto_resolved=1, shared=['CLAUDE.md']))
        self.assertEqual((0, ''), (code, err))
        line = dict(verdict(conflicts=2, auto_resolved=1, shared=['CLAUDE.md']), at=NOW.isoformat(), epics=['a', 'b'])
        self.assertEqual(line, json.loads(out))
        self.assertEqual([line], self.pairs_file())
        self.assertEqual(0, self.record('a', 'b', verdict(shared=None))[0])

    def test_record_refuses_a_bad_verdict(self):
        bad = [
            'not json', '[]', json.dumps({k: v for k, v in verdict().items() if k != 'shared'}),
            json.dumps(verdict(extra=1)), json.dumps(verdict(conflicts=-1)), json.dumps(verdict(conflicts=1.5)),
            json.dumps(verdict(conflicts=True)), json.dumps(verdict(conflicts='1')),
            json.dumps(verdict(conflicts=1, auto_resolved=2)), json.dumps(verdict(critical=[5])),
            json.dumps(verdict(critical=[0])), json.dumps(verdict(critical=['1'])),
            json.dumps(verdict(critical=[True])), json.dumps(verdict(critical=None)),
            json.dumps(verdict(shared='CLAUDE.md')), json.dumps(verdict(shared=[1])), json.dumps(verdict(shared=[''])),
        ]
        for payload in bad:
            with self.subTest(payload=payload):
                code, out, err = self.record('a', 'b', payload)
                self.assertEqual((2, ''), (code, out))
                self.assertTrue(err)
                self.assertIsNone(self.pairs_file())
        code, out, err = self.record('a', 'a', verdict())
        self.assertEqual((2, ''), (code, out))
        self.assertIsNone(self.pairs_file())

    def test_record_with_a_busy_lock_exit_2_without_write(self):
        busy = mock.patch.object(pairs.parallel_gate, 'state_lock',
                                 side_effect=pairs.parallel_gate.LockTimeout('blokada bramki zajęta'))
        with busy:
            code, out, err = self.record('a', 'b', verdict())
        self.assertEqual((2, ''), (code, out))
        self.assertIn('blokada', err)
        self.assertIsNone(self.pairs_file())

    def test_pair_gets_its_last_record_in_any_order(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z'), start('b', '2026-11-14T08:10:00Z'),
            done('a', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T09:30:00Z')])
        self.assertIsNone(self.result()['pairs'][0]['record'])
        self.record('b', 'a', verdict(conflicts=3, auto_resolved=3))
        self.record('a', 'c', verdict(conflicts=9, auto_resolved=0))
        self.record('a', 'b', verdict(conflicts=1, auto_resolved=1))
        record = self.result()['pairs'][0]['record']
        self.assertEqual(['a', 'b'], record['epics'])
        self.assertEqual(1, record['conflicts'])

    def test_summary(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z'), start('b', '2026-11-14T08:10:00Z'),
            done('a', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T09:30:00Z'),
            start('c', '2026-11-14T10:00:00Z'), start('d', '2026-11-14T10:10:00Z')])
        self.record('a', 'b', verdict(conflicts=5, auto_resolved=5, critical=[2]))   # corrected below
        self.record('a', 'b', verdict(shared=['CLAUDE.md', 'apps/api/CLAUDE.md', 'Makefile'], conflicts=2,
                                      auto_resolved=1, doc_fixes=1, interventions=1, regressions=1))
        self.record('c', 'd', verdict(shared=['apps/store/src/x.ts', 'apps/store/src/api/schema.d.ts'], conflicts=1,
                                      auto_resolved=1, interventions=2, critical=[1, 4]))
        self.record('e', 'f', verdict(shared=None))
        summary = self.result()['summary']
        self.assertEqual({'admitted': 2, 'recorded': 3, 'conflicts': 3, 'auto_resolved': 2, 'needed_intervention': 1,
                          'doc_fixes': 1, 'interventions': 3, 'regressions': 1, 'critical_pairs': 1,
                          'claude_md': {'pairs': 1, 'conflicts': 2, 'auto_resolved': 1, 'needed_intervention': 1,
                                        'doc_fixes': 1, 'interventions': 1, 'regressions': 1, 'critical_pairs': 0},
                          'code': {'pairs': 1, 'conflicts': 1, 'auto_resolved': 1, 'needed_intervention': 0,
                                   'doc_fixes': 0, 'interventions': 2, 'regressions': 0, 'critical_pairs': 1}},
                         summary)

    def test_unreadable_pairs_file_exit_2(self):
        for line in ['not json', '[]', json.dumps({'at': 'x'})]:
            with self.subTest(line=line):
                self.write('pairs.jsonl', [line])
                code, out, err = self.run_main()
                self.assertEqual((2, ''), (code, out))
                self.assertTrue(err)

    def test_no_overlap_no_pairs(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z'), done('a', '2026-11-14T09:00:00Z'),
            start('b', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T10:00:00Z')])
        self.assertEqual([], self.result()['pairs'])

    def test_pair(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z', percent=10),
            start('b', '2026-11-14T08:20:00Z', percent=14, docker={'usedMiB': 5000}),
            done('a', '2026-11-14T09:00:00Z', percent=25),
            done('b', '2026-11-14T09:30:00Z', percent=40)])
        pair, = self.result()['pairs']
        self.assertEqual([
            {'dir': 'a', 'start': '2026-11-14T08:00:00Z', 'done': '2026-11-14T09:00:00Z', 'minutes': 60.0,
             'open': False},
            {'dir': 'b', 'start': '2026-11-14T08:20:00Z', 'done': '2026-11-14T09:30:00Z', 'minutes': 70.0,
             'open': False}], pair['epics'])
        self.assertEqual(40.0, pair['overlap_minutes'])
        self.assertEqual(90.0, pair['span_minutes'])
        self.assertEqual(130.0, pair['serial_minutes'])
        self.assertEqual(round(1 - 90 / 130, 3), pair['saving'])
        self.assertEqual({'5h': 30}, pair['usage'])
        self.assertEqual({'usedMiB': 5000}, pair['docker'])
        self.assertEqual(0, pair['setup_failures'])

    def test_overlaps_written_before_19a_are_no_pairs(self):
        self.write('measurements.jsonl', [
            old_start('a', '2026-11-14T08:00:00Z'), old_start('b', '2026-11-14T08:10:00Z'),
            done('a', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T09:30:00Z')])
        self.assertEqual([], self.result()['pairs'])

    def test_old_closed_epic_and_later_19a_start_are_a_pair(self):
        self.write('measurements.jsonl', [
            old_start('a', '2026-11-14T08:00:00Z'), start('b', '2026-11-14T08:10:00Z', docker={'ao_mib': 1}),
            done('a', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T09:30:00Z')])
        pair, = self.result()['pairs']
        self.assertEqual(['a', 'b'], [e['dir'] for e in pair['epics']])
        self.assertEqual({'ao_mib': 1}, pair['docker'])

    def test_19a_epic_and_later_old_start_are_no_pair(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z'), old_start('b', '2026-11-14T08:10:00Z'),
            done('a', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T09:30:00Z')])
        self.assertEqual([], self.result()['pairs'])

    def test_old_open_start_is_ignored(self):
        # A start written before 19a that never got a done (an abandoned epic) is no interval at all.
        self.write('measurements.jsonl', [
            old_start('x', '2026-11-06T18:00:00Z'), start('b', '2026-11-14T08:10:00Z'),
            done('b', '2026-11-14T09:30:00Z'), start('c', '2026-11-14T10:00:00Z')])
        self.assertEqual([], self.result()['pairs'])

    def test_old_open_start_replaced_by_19a_start_counts(self):
        self.write('measurements.jsonl', [
            old_start('a', '2026-11-06T18:00:00Z'), start('a', '2026-11-14T08:00:00Z'),
            start('b', '2026-11-14T08:10:00Z'), done('a', '2026-11-14T09:00:00Z')])
        pair, = self.result()['pairs']
        self.assertEqual(['a', 'b'], [e['dir'] for e in pair['epics']])
        self.assertTrue(pair['epics'][1]['open'])

    def test_docker_null_is_null(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z'), start('b', '2026-11-14T08:10:00Z'),
            done('a', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T09:30:00Z')])
        self.assertIsNone(self.result()['pairs'][0]['docker'])

    def test_chain_of_three(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z'), start('b', '2026-11-14T08:30:00Z'),
            done('a', '2026-11-14T09:00:00Z'), start('c', '2026-11-14T09:10:00Z'),
            done('b', '2026-11-14T09:30:00Z'), done('c', '2026-11-14T10:00:00Z')])
        found = self.result()['pairs']
        self.assertEqual([['a', 'b'], ['b', 'c']], [[e['dir'] for e in p['epics']] for p in found])

    def test_open_pair(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T10:00:00Z'), done('a', '2026-11-14T11:00:00Z'),
            start('b', '2026-11-14T10:30:00Z')])
        pair, = self.result()['pairs']
        self.assertEqual({'dir': 'b', 'start': '2026-11-14T10:30:00Z', 'done': None, 'minutes': 90.0, 'open': True},
                         pair['epics'][1])
        self.assertIsNone(pair['saving'])
        self.assertEqual(30.0, pair['overlap_minutes'])
        self.assertEqual(120.0, pair['span_minutes'])
        self.assertEqual({}, pair['usage'])

    def test_restart_without_done_replaces_open_interval(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T06:00:00Z'), start('a', '2026-11-14T08:00:00Z'),
            start('b', '2026-11-14T08:10:00Z'), done('a', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T09:30:00Z')])
        pair, = self.result()['pairs']
        self.assertEqual('2026-11-14T08:00:00Z', pair['epics'][0]['start'])

    def test_renewed_window_has_no_delta(self):
        later = dict(done('b', '2026-11-14T09:30:00Z'), usage=usage(5, reset='2026-11-14T23:00:00Z'))
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z'), start('b', '2026-11-14T08:10:00Z'),
            done('a', '2026-11-14T09:00:00Z'), later])
        self.assertEqual({}, self.result()['pairs'][0]['usage'])

    def test_usage_state_not_ok_has_no_delta(self):
        first = dict(start('a', '2026-11-14T08:00:00Z'), usage=usage(0, status='unavailable'))
        self.write('measurements.jsonl', [
            first, start('b', '2026-11-14T08:10:00Z'), done('a', '2026-11-14T09:00:00Z'),
            done('b', '2026-11-14T09:30:00Z')])
        self.assertEqual({}, self.result()['pairs'][0]['usage'])

    def test_setup_failures_inside_span_only(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z'), start('b', '2026-11-14T08:10:00Z'),
            done('a', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T09:30:00Z')])
        os.makedirs(self.diag)
        local = datetime(2026, 11, 14, 8, 0, tzinfo=timezone.utc).astimezone()
        for name in ['ao-x-%s-1.log' % local.strftime('%Y%m%d-%H%M%S'),
                     'ao-y-%s-2.log' % local.replace(hour=local.hour).strftime('%Y%m%d-%H%M%S'),
                     'ao-z-20200101-000000-1.log', 'ao-q-notatime.log', 'other-20261114-000000-1.log']:
            open(os.path.join(self.diag, name), 'w').close()
        self.assertEqual(2, self.result()['pairs'][0]['setup_failures'])

    def test_missing_diag_directory(self):
        self.write('measurements.jsonl', [
            start('a', '2026-11-14T08:00:00Z'), start('b', '2026-11-14T08:10:00Z'),
            done('a', '2026-11-14T09:00:00Z'), done('b', '2026-11-14T09:30:00Z')])
        self.assertEqual(0, self.result()['pairs'][0]['setup_failures'])

    def gate_line(self, at, *skipped):
        return {'at': at, 'dir_arg': None, 'epic': None, 'reason': 'x',
                'skipped': [{'id': i, 'reason': 'r', 'paths': p} for i, p in skipped]}

    def test_claude_md_only(self):
        self.write('gate.jsonl', [
            self.gate_line('2026-11-14T08:00:00Z', ('E1', ['apps/api/CLAUDE.md']), ('E2', ['CLAUDE.md', 'apps/x']),
                           ('E3', []), ('E4', ['Makefile'])),
            self.gate_line('2026-11-14T07:00:00Z', ('E1', ['CLAUDE.md']), ('E5', ['e2e/CLAUDE.md'])),
            self.gate_line('2026-11-14T09:00:00Z', ('E1', ['CLAUDE.md']))])
        self.assertEqual([
            {'id': 'E1', 'first': '2026-11-14T07:00:00Z', 'last': '2026-11-14T09:00:00Z', 'count': 3},
            {'id': 'E5', 'first': '2026-11-14T07:00:00Z', 'last': '2026-11-14T07:00:00Z', 'count': 1}],
            self.result()['claude_md_only'])

    def test_code_blocked(self):
        self.write('gate.jsonl', [
            self.gate_line('2026-11-14T08:00:00Z', ('E1', ['apps/api/src/Cart']), ('E2', ['CLAUDE.md']),
                           ('E3', ['Makefile', 'apps/x']), ('E4', [])),
            self.gate_line('2026-11-14T09:00:00Z', ('E1', ['apps/store/app']), ('E5', ['.github/workflows/ci.yml']))])
        self.assertEqual([
            {'id': 'E1', 'first': '2026-11-14T08:00:00Z', 'last': '2026-11-14T09:00:00Z', 'count': 2},
            {'id': 'E3', 'first': '2026-11-14T08:00:00Z', 'last': '2026-11-14T08:00:00Z', 'count': 1}],
            self.result()['code_blocked'])

    def test_unreadable_gate_exit_2(self):
        self.write('gate.jsonl', ['not json'])
        code, out, err = self.run_main()
        self.assertEqual(2, code)
        self.assertEqual('', out)
        self.assertTrue(err)

    def test_unreadable_measurements_exit_2(self):
        self.write('measurements.jsonl', ['not json'])
        code, out, err = self.run_main()
        self.assertEqual(2, code)
        self.assertTrue(err)

    def test_gate_is_a_directory_exit_2(self):
        os.makedirs(os.path.join(self.state, 'gate.jsonl'))
        self.assertEqual(2, self.run_main()[0])


if __name__ == '__main__':
    unittest.main()

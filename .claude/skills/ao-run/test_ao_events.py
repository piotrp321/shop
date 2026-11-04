"""Tests of ao_events.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import importlib.util
import json
import os
import queue
import signal
import subprocess
import sys
import tempfile
import threading
import time
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'ao_events.py')
spec = importlib.util.spec_from_file_location('ao_events', SCRIPT)
ao_events = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ao_events)


def event(type_, data=None, task_id='T-1', epic_id='cart'):
    return {'type': type_, 'task_id': task_id, 'epic_id': epic_id, 'data': data or {}, 'at': 1.0}


class RecordTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.file = os.path.join(self.tmp.name, 'sub', 'dir', 'events.jsonl')

    def tearDown(self):
        self.tmp.cleanup()

    def record(self, stdin, path=None):
        return subprocess.run([sys.executable, SCRIPT, 'record', path or self.file], input=stdin,
                              capture_output=True, text=True)

    def lines(self):
        with open(self.file, encoding='utf-8') as f:
            return f.read().splitlines()

    def test_valid_event_is_one_line_equal_to_input(self):
        ev = event('epic_done', {'why': 'zażółć'})
        result = self.record(json.dumps(ev, indent=2))
        self.assertEqual(result.returncode, 0, result.stderr)
        lines = self.lines()
        self.assertEqual(len(lines), 1)
        self.assertEqual(json.loads(lines[0]), ev)
        self.assertIn('zażółć', lines[0])
        self.assertFalse(lines[0].startswith(' '))

    def test_two_events_make_two_lines(self):
        self.record(json.dumps(event('epic_done')))
        self.record(json.dumps(event('queue_held')))
        self.assertEqual([json.loads(l)['type'] for l in self.lines()], ['epic_done', 'queue_held'])

    def test_empty_stdin_is_invalid_event(self):
        result = self.record('')
        self.assertEqual(result.returncode, 0)
        self.assertEqual(json.loads(self.lines()[0]), {'type': 'invalid_event', 'raw': ''})

    def test_garbage_is_invalid_event_with_raw_cut_to_500(self):
        result = self.record('x' * 700)
        self.assertEqual(result.returncode, 0)
        parsed = json.loads(self.lines()[0])
        self.assertEqual(parsed['type'], 'invalid_event')
        self.assertEqual(parsed['raw'], 'x' * 500)

    def test_json_without_type_is_invalid_event(self):
        self.record('{"task_id": "T-1"}')
        self.assertEqual(json.loads(self.lines()[0])['type'], 'invalid_event')

    def test_creates_missing_parent_directories(self):
        self.assertFalse(os.path.exists(os.path.dirname(self.file)))
        self.assertEqual(self.record(json.dumps(event('epic_done'))).returncode, 0)
        self.assertTrue(os.path.exists(self.file))

    @unittest.skipIf(hasattr(os, 'geteuid') and os.geteuid() == 0, 'root ignores permissions')
    def test_unwritable_directory_exits_1_with_message(self):
        locked = os.path.join(self.tmp.name, 'locked')
        os.makedirs(locked)
        os.chmod(locked, 0o500)
        try:
            result = self.record(json.dumps(event('epic_done')), os.path.join(locked, 'events.jsonl'))
        finally:
            os.chmod(locked, 0o700)
        self.assertEqual(result.returncode, 1)
        self.assertTrue(result.stderr.strip())


class FormatEventTest(unittest.TestCase):
    fmt = staticmethod(lambda ev, label='CART': ao_events.format_event(ev, label))

    def test_blocked_with_question(self):
        ev = event('status_changed', {'to': 'BLOCKED', 'blockers': ['Który VAT?', 'Drugie']})
        self.assertEqual(self.fmt(ev), 'CART: BLOCKED T-1: Który VAT?')

    def test_blocked_without_questions_uses_reason(self):
        ev = event('status_changed', {'to': 'BLOCKED', 'reason': 'executor'})
        self.assertEqual(self.fmt(ev), 'CART: BLOCKED T-1: powód executor')

    def test_failed(self):
        ev = event('status_changed', {'to': 'FAILED', 'reason': 'usage_limit_repeated'})
        self.assertEqual(self.fmt(ev), 'CART: FAILED T-1: usage_limit_repeated')

    def test_other_status_is_skipped(self):
        self.assertIsNone(self.fmt(event('status_changed', {'to': 'PENDING'})))

    def test_epic_blocked(self):
        self.assertEqual(self.fmt(event('epic_blocked', {'reason': 'ci red'})), 'CART: epik zablokowany: ci red')

    def test_queue_held(self):
        ev = event('queue_held', {'kind': 'environment', 'reasons': ['docker down', 'x']})
        self.assertEqual(self.fmt(ev), 'CART: kolejka wstrzymana: docker down')

    def test_queue_held_falls_back_to_kind(self):
        self.assertEqual(self.fmt(event('queue_held', {'kind': 'environment'})), 'CART: kolejka wstrzymana: environment')

    def test_final_pr_url_or_number(self):
        ev = event('final_pr_created', {'pr_number': 79, 'url': 'https://github.com/o/r/pull/79'})
        self.assertEqual(self.fmt(ev), 'CART: końcowy PR https://github.com/o/r/pull/79')
        self.assertEqual(self.fmt(event('final_pr_created', {'pr_number': 79})), 'CART: końcowy PR #79')

    def test_epic_done(self):
        self.assertEqual(self.fmt(event('epic_done', {'why': 'merged'})), 'CART: końcowy PR zielony, gotowy do odbioru')

    def test_minor_types(self):
        usage = self.fmt(event('usage_warning', {'window': '5h', 'utilization': 0.91, 'threshold': 0.9}))
        self.assertIn('91%', usage)
        self.assertIn('5h', usage)
        self.assertIn('120', self.fmt(event('attempt_stalled', {'idle_s': 120.4})))
        self.assertIn('usage_limit', self.fmt(event('cooldown_set', {'until': 1.0, 'reason': 'usage_limit'})))
        battery = self.fmt(event('host_on_battery', {'percent': 15, 'low': True}))
        self.assertIn('15%', battery)

    def test_invalid_event(self):
        self.assertEqual(self.fmt({'type': 'invalid_event', 'raw': 'x'}), 'CART: niepoprawne zdarzenie AO')

    def test_unknown_type_is_skipped(self):
        self.assertIsNone(self.fmt(event('task_created')))

    def test_label_falls_back_to_epic_id_then_ao(self):
        self.assertEqual(ao_events.format_event(event('epic_done'), None), 'cart: końcowy PR zielony, gotowy do odbioru')
        self.assertEqual(ao_events.format_event(event('epic_done', epic_id=None), None), 'AO: końcowy PR zielony, gotowy do odbioru')

    def test_one_line_and_limit_200(self):
        ev = event('status_changed', {'to': 'BLOCKED', 'blockers': ['a\nb\r\nc' + 'x' * 400]})
        out = self.fmt(ev)
        self.assertNotIn('\n', out)
        self.assertEqual(len(out), 200)
        self.assertTrue(out.endswith('…'))
        self.assertIn('a b', out)

    def test_exactly_200_is_not_cut(self):
        ev = event('epic_blocked', {'reason': 'y' * (200 - len('CART: epik zablokowany: '))})
        out = self.fmt(ev)
        self.assertEqual(len(out), 200)
        self.assertFalse(out.endswith('…'))


class WatchTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.file = os.path.join(self.tmp.name, 'events.jsonl')
        self.procs = []

    def tearDown(self):
        for p in self.procs:
            if p.poll() is None:
                p.kill()
            p.wait()
            p.stdout.close()
            p.stderr.close()
        self.tmp.cleanup()

    def start(self, *args):
        p = subprocess.Popen([sys.executable, SCRIPT, 'watch', self.file, *args], stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, text=True)
        self.procs.append(p)
        p.lines = queue.Queue()
        threading.Thread(target=lambda: [p.lines.put(l.strip()) for l in p.stdout], daemon=True).start()
        self.wait_ready(p)
        return p

    def wait_ready(self, p):
        # watch reports on stderr once the start position is decided and the signal handlers are installed
        box = []
        reader = threading.Thread(target=lambda: box.append(p.stderr.readline()), daemon=True)
        reader.start()
        reader.join(30)
        self.assertTrue(box and box[0].startswith('watching '), 'watch did not report readiness: %r' % box)

    def next_line(self, p):
        try:
            return p.lines.get(timeout=5)
        except queue.Empty:
            self.fail('no output line within 5 s')

    def append(self, ev):
        with open(self.file, 'a', encoding='utf-8') as f:
            f.write((ev if isinstance(ev, str) else json.dumps(ev)) + '\n')

    def test_new_line_appears_and_old_line_is_skipped(self):
        self.append(event('epic_done'))
        p = self.start('--epic-label', 'X')
        self.append('not json')
        self.append(event('epic_blocked', {'reason': 'r'}))
        self.assertEqual(self.next_line(p), 'X: epik zablokowany: r')

    def test_from_start_reads_existing_lines(self):
        self.append(event('epic_done'))
        p = self.start('--from-start')
        self.assertEqual(self.next_line(p), 'cart: końcowy PR zielony, gotowy do odbioru')

    def test_file_created_after_start_is_detected(self):
        p = self.start('--epic-label', 'X')
        self.append(event('epic_done'))
        self.assertEqual(self.next_line(p), 'X: końcowy PR zielony, gotowy do odbioru')

    def test_partial_last_line_is_one_message_once_completed(self):
        p = self.start()
        with open(self.file, 'a', encoding='utf-8') as f:
            f.write('{"type": "epic_done", ')
            f.flush()
            time.sleep(1.5)
            self.assertTrue(p.lines.empty())
            f.write('"epic_id": "cart"}\n')
        self.assertEqual(self.next_line(p), 'cart: końcowy PR zielony, gotowy do odbioru')
        time.sleep(1.5)
        self.assertTrue(p.lines.empty())

    def test_sigterm_exits_0(self):
        p = self.start()
        p.send_signal(signal.SIGTERM)
        self.assertEqual(p.wait(timeout=5), 0)


if __name__ == '__main__':
    unittest.main()

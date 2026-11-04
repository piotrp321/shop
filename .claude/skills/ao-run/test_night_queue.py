"""Tests of night_queue.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import contextlib
import importlib.util
import io
import json
import os
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'night_queue.py')
spec = importlib.util.spec_from_file_location('night_queue', SCRIPT)
night_queue = importlib.util.module_from_spec(spec)
spec.loader.exec_module(night_queue)


def header(line):
    return '# Epic\n\nIssues: #1\n%s\n\n## Cel\nText\n' % line


class ParseScopeTest(unittest.TestCase):
    def test_plain_items(self):
        self.assertEqual(['apps/api/src/Cart', 'apps/store/src/cart'],
                         night_queue.parse_scope(header('Zakres: apps/api/src/Cart, apps/store/src/cart')))

    def test_backticks_and_spaces(self):
        self.assertEqual(['apps/api/openapi.json', 'apps/api/src/Cart'],
                         night_queue.parse_scope(header('Zakres:   `apps/api/openapi.json` ,  `apps/api/src/Cart`  ')))

    def test_duplicates_removed_in_order(self):
        self.assertEqual(['a/b', 'c'], night_queue.parse_scope(header('Zakres: a/b, c, `a/b`')))

    def test_line_after_first_section_is_ignored(self):
        text = '# Epic\nIssues: #1\n\n## Cel\nZakres: apps/api\n'
        with self.assertRaisesRegex(night_queue.ScopeError, 'brak linii Zakres'):
            night_queue.parse_scope(text)

    def test_missing(self):
        with self.assertRaisesRegex(night_queue.ScopeError, 'brak linii Zakres'):
            night_queue.parse_scope('# Epic\n\n## Cel\n')

    def test_two_lines(self):
        with self.assertRaisesRegex(night_queue.ScopeError, 'więcej niż jedna linia Zakres'):
            night_queue.parse_scope(header('Zakres: a\nZakres: b'))

    def test_invalid_items(self):
        for item in ['/abs', 'a/', 'a\\b', 'a/*', 'a/b?', 'a/[x]', 'a//b', '.', 'a/./b', '..', 'a/../b']:
            with self.subTest(item=item):
                with self.assertRaisesRegex(night_queue.ScopeError, 'nieczytelna linia Zakres: '):
                    night_queue.parse_scope(header('Zakres: ok/path, %s' % item))

    def test_empty_line_and_empty_item(self):
        for line in ['Zakres:', 'Zakres:   ', 'Zakres: a,,b', 'Zakres: a,', 'Zakres: ``']:
            with self.subTest(line=line):
                with self.assertRaisesRegex(night_queue.ScopeError, 'nieczytelna linia Zakres: '):
                    night_queue.parse_scope(header(line))

    def test_error_names_item(self):
        with self.assertRaisesRegex(night_queue.ScopeError, 'nieczytelna linia Zakres: a/\\.\\./b'):
            night_queue.parse_scope(header('Zakres: a/../b'))


class OverlapTest(unittest.TestCase):
    def test_equal(self):
        self.assertEqual(('a/b', 'a/b'), night_queue.overlap(['x', 'a/b'], ['a/b']))

    def test_inside_either_direction(self):
        self.assertEqual(('apps/api/src/Cart', 'apps/api/src'),
                         night_queue.overlap(['apps/api/src/Cart'], ['apps/api/src']))
        self.assertEqual(('apps/api/src', 'apps/api/src/Cart'),
                         night_queue.overlap(['apps/api/src'], ['apps/api/src/Cart']))

    def test_common_prefix_string_is_not_overlap(self):
        self.assertIsNone(night_queue.overlap(['apps/api/src/CartX'], ['apps/api/src/Cart']))

    def test_disjoint(self):
        self.assertIsNone(night_queue.overlap(['apps/api'], ['apps/store']))

    def test_epic_scope_adds_feature_directory(self):
        a = night_queue.epic_scope('cart', ['apps/api/src/Cart'])
        b = night_queue.epic_scope('variants', ['apps/api/src/Variants'])
        self.assertEqual(['apps/api/src/Cart', 'docs/features/cart'], a)
        self.assertIsNone(night_queue.overlap(a, b))

    def test_listing_docs_features_overlaps_other_epic_directory(self):
        a = night_queue.epic_scope('cart', ['docs/features'])
        b = night_queue.epic_scope('variants', [])
        self.assertEqual(('docs/features', 'docs/features/variants'), night_queue.overlap(a, b))


class ScopeCliTest(unittest.TestCase):
    def run_cli(self, argv):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = night_queue.main(argv)
        return code, out.getvalue(), err.getvalue()

    def write(self, text):
        d = tempfile.mkdtemp()
        self.addCleanup(lambda: __import__('shutil').rmtree(d, ignore_errors=True))
        path = os.path.join(d, 'spec.md')
        with open(path, 'w', encoding='utf-8') as f:
            f.write(text)
        return path

    def test_ok(self):
        code, out, err = self.run_cli(['scope', self.write(header('Zakres: apps/api, `apps/store`'))])
        self.assertEqual(0, code)
        self.assertEqual({'scope': ['apps/api', 'apps/store']}, json.loads(out))
        self.assertEqual('', err)

    def test_invalid_line_exits_2(self):
        code, out, err = self.run_cli(['scope', self.write(header('Zakres: /abs'))])
        self.assertEqual(2, code)
        self.assertEqual('', out)
        self.assertIn('nieczytelna linia Zakres: /abs', err)

    def test_missing_line_exits_2(self):
        code, out, err = self.run_cli(['scope', self.write('# Epic\n')])
        self.assertEqual(2, code)
        self.assertEqual('', out)
        self.assertIn('brak linii Zakres', err)

    def test_unreadable_file_exits_2(self):
        code, out, err = self.run_cli(['scope', '/nonexistent/dir/spec.md'])
        self.assertEqual(2, code)
        self.assertEqual('', out)
        self.assertIn('night_queue:', err)


from datetime import datetime, timezone, timedelta  # noqa: E402

T0 = datetime(2026, 11, 4, 22, 0, tzinfo=timezone.utc)
RESET = '2026-11-11T17:59:59.640Z'
LABEL = 'Weekly · all models'


def usage(percent, reset=RESET, label=LABEL, status='ok', extra_windows=()):
    windows = [{'label': label, 'percentUsed': percent, 'resetsAt': reset}] + list(extra_windows)
    return {'status': status, 'windows': windows}

GOOD_LINE = json.dumps({'event': 'start', 'epic': 'x', 'tasks': 1, 'at': T0.isoformat(), 'usage': {}})


def manifest(n):
    return '# Plan\n\n```json\n%s\n```\n' % json.dumps({'tasks': [{'id': 'T-%d' % i} for i in range(n)]})


class ManifestRunner:
    def __init__(self, files=None, fail=False):
        self.files = files or {}
        self.fail = fail
        self.calls = []

    def __call__(self, args):
        self.calls.append(list(args))
        if self.fail:
            raise night_queue.next_epic.CommandError(args, 'boom')
        assert args[:2] == ['git', 'show'], args
        prefix = 'origin/main:docs/features/'
        assert args[2].startswith(prefix)
        return self.files[args[2][len(prefix):].split('/')[0]]


class TaskCountTest(unittest.TestCase):
    def test_counts_tasks(self):
        run = ManifestRunner({'cart': manifest(3)})
        self.assertEqual(3, night_queue.task_count(run, 'cart'))
        self.assertEqual([['git', 'show', 'origin/main:docs/features/cart/plan.md']], run.calls)

    def test_no_json_block(self):
        with self.assertRaisesRegex(night_queue.StateError, 'manifest'):
            night_queue.task_count(ManifestRunner({'cart': '# Plan\nno block\n'}), 'cart')

    def test_bad_json(self):
        with self.assertRaisesRegex(night_queue.StateError, 'manifest'):
            night_queue.task_count(ManifestRunner({'cart': '```json\n{oops\n```\n'}), 'cart')

    def test_no_tasks(self):
        for body in ['{"tasks": []}', '{"x": 1}', '{"tasks": "a"}', '[1]']:
            with self.subTest(body=body):
                with self.assertRaisesRegex(night_queue.StateError, 'manifest'):
                    night_queue.task_count(ManifestRunner({'cart': '```json\n%s\n```\n' % body}), 'cart')


class MeasurementsTest(unittest.TestCase):
    def pair(self, before, after, minutes=60, tasks=4, epic='cart'):
        return [
            {'event': 'start', 'epic': epic, 'tasks': tasks, 'at': T0.isoformat(), 'usage': before},
            {'event': 'done', 'epic': epic, 'at': (T0 + timedelta(minutes=minutes)).isoformat(), 'usage': after},
        ]

    def test_minutes_and_delta(self):
        got = night_queue.measurements(self.pair(usage(10), usage(18.5)))
        self.assertEqual([{'epic': 'cart', 'tasks': 4, 'minutes': 60.0, 'usage': {LABEL: 8.5}}], got)

    def test_z_suffix_times(self):
        events = self.pair(usage(1), usage(2))
        events[0]['at'] = '2026-11-04T22:00:00Z'
        events[1]['at'] = '2026-11-04T22:30:00Z'
        self.assertEqual(30.0, night_queue.measurements(events)[0]['minutes'])

    def test_reset_in_between_excluded(self):
        after = usage(3, reset='2026-11-18T17:59:59.640Z', extra_windows=[
            {'label': '5h', 'percentUsed': 30, 'resetsAt': RESET}])
        before = usage(90, extra_windows=[{'label': '5h', 'percentUsed': 10, 'resetsAt': RESET}])
        got = night_queue.measurements(self.pair(before, after))
        self.assertEqual({'5h': 20}, got[0]['usage'])

    def test_reset_within_60_seconds_counts(self):
        after = usage(15, reset='2026-11-11T18:00:50.000Z')
        self.assertEqual({LABEL: 5}, night_queue.measurements(self.pair(usage(10), after))[0]['usage'])
        after = usage(15, reset='2026-11-11T18:01:00.000Z')
        self.assertEqual({}, night_queue.measurements(self.pair(usage(10), after))[0]['usage'])

    def test_percent_decrease_excluded(self):
        self.assertEqual({}, night_queue.measurements(self.pair(usage(10), usage(9)))[0]['usage'])

    def test_status_not_ok_gives_no_windows(self):
        for bad in [{'status': 'unreadable'}, usage(20, status='stale')]:
            with self.subTest(bad=bad):
                for events in (self.pair(usage(10), bad), self.pair(bad, usage(30))):
                    got = night_queue.measurements(events)
                    self.assertEqual(60.0, got[0]['minutes'])
                    self.assertEqual({}, got[0]['usage'])

    def test_label_only_on_one_side(self):
        before = usage(10)
        after = usage(12, label='Other')
        self.assertEqual({}, night_queue.measurements(self.pair(before, after))[0]['usage'])

    def test_latest_start_counts(self):
        events = [
            {'event': 'start', 'epic': 'cart', 'tasks': 9, 'at': T0.isoformat(), 'usage': usage(1)},
            {'event': 'start', 'epic': 'cart', 'tasks': 4, 'at': (T0 + timedelta(minutes=10)).isoformat(),
             'usage': usage(5)},
            {'event': 'done', 'epic': 'cart', 'at': (T0 + timedelta(minutes=40)).isoformat(), 'usage': usage(9)},
        ]
        self.assertEqual([{'epic': 'cart', 'tasks': 4, 'minutes': 30.0, 'usage': {LABEL: 4}}],
                         night_queue.measurements(events))

    def test_start_without_done_and_done_without_start_give_nothing(self):
        start = {'event': 'start', 'epic': 'cart', 'tasks': 4, 'at': T0.isoformat(), 'usage': usage(1)}
        done = {'event': 'done', 'epic': 'other', 'at': T0.isoformat(), 'usage': usage(1)}
        self.assertEqual([], night_queue.measurements([start, done]))

    def test_second_done_without_new_start_is_ignored(self):
        events = self.pair(usage(10), usage(12)) + self.pair(usage(12), usage(20))[1:]
        self.assertEqual(1, len(night_queue.measurements(events)))

    def test_epics_are_independent(self):
        events = self.pair(usage(1), usage(2), epic='a') + self.pair(usage(1), usage(4), epic='b')
        self.assertEqual(['a', 'b'], [m['epic'] for m in night_queue.measurements(events)])


class EstimateTest(unittest.TestCase):
    def test_no_measurement(self):
        self.assertEqual({'minutes': None, 'usage': {}}, night_queue.estimate([], 5))

    def test_max_per_task(self):
        ms = [
            {'epic': 'a', 'tasks': 2, 'minutes': 60.0, 'usage': {'W': 10, 'F': 4}},   # 30 min/task, W 5, F 2
            {'epic': 'b', 'tasks': 10, 'minutes': 200.0, 'usage': {'W': 30}},         # 20 min/task, W 3
        ]
        self.assertEqual({'minutes': 90.0, 'usage': {'W': 15.0, 'F': 6.0}}, night_queue.estimate(ms, 3))

    def test_measurement_without_windows_still_gives_minutes(self):
        ms = [{'epic': 'a', 'tasks': 2, 'minutes': 20.0, 'usage': {}}]
        self.assertEqual({'minutes': 50.0, 'usage': {}}, night_queue.estimate(ms, 5))


class ReadEventsTest(unittest.TestCase):
    def state(self):
        d = tempfile.mkdtemp()
        self.addCleanup(lambda: __import__('shutil').rmtree(d, ignore_errors=True))
        return d

    def test_missing_file_is_empty(self):
        self.assertEqual([], night_queue.read_events(self.state()))

    def test_bad_line_names_line_number(self):
        d = self.state()
        with open(os.path.join(d, 'measurements.jsonl'), 'w') as f:
            f.write(GOOD_LINE + '\nnot json\n')
        with self.assertRaisesRegex(night_queue.StateError, 'linia 2 w .*measurements.jsonl'):
            night_queue.read_events(d)

    def test_events_with_missing_fields(self):
        for line in ['{"event": "done", "epic": "a", "at": "x", "usage": {}}', '{"event": "start", "epic": "a"}',
                     '{"event": "zzz"}', '{"event": "start", "epic": "a", "tasks": 0, "at": "2026-11-04T22:00:00Z",'
                     ' "usage": {}}']:
            with self.subTest(line=line):
                d = self.state()
                with open(os.path.join(d, 'measurements.jsonl'), 'w') as f:
                    f.write(line + '\n')
                with self.assertRaisesRegex(night_queue.StateError, 'linia 1'):
                    night_queue.read_events(d)

    def test_non_object_line(self):
        d = self.state()
        with open(os.path.join(d, 'measurements.jsonl'), 'w') as f:
            f.write('[1]\n')
        with self.assertRaisesRegex(night_queue.StateError, '1'):
            night_queue.read_events(d)


class MeasureCliTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.addCleanup(lambda: __import__('shutil').rmtree(self.dir, ignore_errors=True))
        self.state = os.path.join(self.dir, 'nested', 'state')

    def cli(self, command, directory, stdin_text, run=None, now=T0):
        out, err = io.StringIO(), io.StringIO()
        argv = ['--state', self.state, command, directory]
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = night_queue.main(argv, run=run or ManifestRunner({'cart': manifest(4)}), now=now,
                                    stdin=io.StringIO(stdin_text))
        return code, out.getvalue(), err.getvalue()

    def lines(self):
        with open(os.path.join(self.state, 'measurements.jsonl'), encoding='utf-8') as f:
            return [json.loads(l) for l in f]

    def test_start_appends_and_prints_event(self):
        code, out, err = self.cli('start', 'cart', json.dumps(usage(10)))
        self.assertEqual(0, code, err)
        event = json.loads(out)
        self.assertEqual({'event': 'start', 'epic': 'cart', 'tasks': 4, 'at': T0.isoformat(), 'usage': usage(10)},
                         event)
        self.assertEqual([event], self.lines())

    def test_start_with_unreadable_usage(self):
        for text in ['', 'garbage', '[1]', '{"status": "ok"}', '{"status": "ok", "windows": [1]}']:
            with self.subTest(text=text):
                code, out, err = self.cli('start', 'cart', text)
                self.assertEqual(0, code, err)
                self.assertEqual({'status': 'unreadable'}, json.loads(out)['usage'])

    def test_start_ignores_extra_keys_in_valid_usage(self):
        u = usage(10)
        u['extra'] = 1
        code, out, _ = self.cli('start', 'cart', json.dumps(u))
        self.assertEqual(0, code)
        self.assertEqual(u, json.loads(out)['usage'])

    def test_start_manifest_errors_exit_2_and_write_nothing(self):
        code, out, err = self.cli('start', 'cart', '', run=ManifestRunner({'cart': 'no block'}))
        self.assertEqual((2, ''), (code, out))
        self.assertIn('manifest', err)
        self.assertFalse(os.path.exists(self.state))

    def test_start_git_failure_exits_1(self):
        code, out, err = self.cli('start', 'cart', '', run=ManifestRunner(fail=True))
        self.assertEqual((1, ''), (code, out))
        self.assertIn('boom', err)

    def test_done_without_start_exits_2(self):
        code, out, err = self.cli('done', 'cart', json.dumps(usage(10)))
        self.assertEqual((2, ''), (code, out))
        self.assertIn('brak start dla cart', err)

    def test_done_prints_measurement(self):
        self.cli('start', 'cart', json.dumps(usage(10)))
        code, out, err = self.cli('done', 'cart', json.dumps(usage(14)), now=T0 + timedelta(minutes=90))
        self.assertEqual(0, code, err)
        self.assertEqual({'epic': 'cart', 'tasks': 4, 'minutes': 90.0, 'usage': {LABEL: 4}}, json.loads(out))
        self.assertEqual(['start', 'done'], [e['event'] for e in self.lines()])

    def test_done_twice_exits_2(self):
        self.cli('start', 'cart', json.dumps(usage(10)))
        self.cli('done', 'cart', json.dumps(usage(14)))
        code, out, err = self.cli('done', 'cart', json.dumps(usage(15)))
        self.assertEqual((2, ''), (code, out))
        self.assertIn('brak start dla cart', err)

    def test_start_twice_latest_counts(self):
        self.cli('start', 'cart', json.dumps(usage(10)))
        self.cli('start', 'cart', json.dumps(usage(20)), now=T0 + timedelta(minutes=30))
        code, out, _ = self.cli('done', 'cart', json.dumps(usage(25)), now=T0 + timedelta(minutes=60))
        self.assertEqual(0, code)
        self.assertEqual({'epic': 'cart', 'tasks': 4, 'minutes': 30.0, 'usage': {LABEL: 5}}, json.loads(out))

    def test_corrupt_state_exits_2_naming_line(self):
        os.makedirs(self.state)
        with open(os.path.join(self.state, 'measurements.jsonl'), 'w') as f:
            f.write(GOOD_LINE + '\nbroken\n')
        for command in ('start', 'done'):
            code, out, err = self.cli(command, 'cart', json.dumps(usage(1)))
            self.assertEqual((2, ''), (code, out))
            self.assertIn('2', err)


if __name__ == '__main__':
    unittest.main()

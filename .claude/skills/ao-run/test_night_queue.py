"""Tests of night_queue.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import contextlib
import importlib.util
import io
import json
import os
import tempfile
import unittest
from unittest import mock

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
        self.assertEqual([{'epic': 'cart', 'tasks': 4, 'minutes': 60.0, 'usage': {LABEL: 8.5}, 'overlap': False}], got)

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
        self.assertEqual([{'epic': 'cart', 'tasks': 4, 'minutes': 30.0, 'usage': {LABEL: 4}, 'overlap': False}],
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

    def event(self, kind, epic, minutes, tasks=2):
        e = {'event': kind, 'epic': epic, 'at': (T0 + timedelta(minutes=minutes)).isoformat(), 'usage': usage(1)}
        if kind == 'start':
            e['tasks'] = tasks
        return e

    def overlaps(self, *events):
        return {m['epic']: m['overlap'] for m in night_queue.measurements(list(events))}

    def test_intersecting_intervals_of_two_epics_overlap(self):
        got = self.overlaps(self.event('start', 'a', 0), self.event('start', 'b', 30), self.event('done', 'a', 60),
                            self.event('done', 'b', 90), self.event('start', 'c', 100), self.event('done', 'c', 120))
        self.assertEqual({'a': True, 'b': True, 'c': False}, got)

    def test_touching_intervals_do_not_overlap(self):
        got = self.overlaps(self.event('start', 'a', 0), self.event('done', 'a', 60), self.event('start', 'b', 60),
                            self.event('done', 'b', 90))
        self.assertEqual({'a': False, 'b': False}, got)

    def test_start_without_done_is_open_to_the_end(self):
        got = self.overlaps(self.event('start', 'a', 0), self.event('start', 'b', 300), self.event('done', 'b', 360))
        self.assertEqual({'b': True}, got)

    def test_open_start_after_the_measurement_does_not_overlap(self):
        got = self.overlaps(self.event('start', 'b', 0), self.event('done', 'b', 60), self.event('start', 'a', 61))
        self.assertEqual({'b': False}, got)

    def test_same_epic_twice_does_not_overlap_itself(self):
        got = night_queue.measurements([self.event('start', 'a', 0), self.event('done', 'a', 60),
                                        self.event('start', 'a', 30), self.event('done', 'a', 90)])
        self.assertEqual([False, False], [m['overlap'] for m in got])


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

    def test_usage_only_from_measurements_without_overlap(self):
        ms = [
            {'epic': 'a', 'tasks': 2, 'minutes': 100.0, 'usage': {'W': 40}, 'overlap': True},   # 50 min/task, W 20
            {'epic': 'b', 'tasks': 2, 'minutes': 20.0, 'usage': {'W': 4}, 'overlap': False},    # 10 min/task, W 2
        ]
        self.assertEqual({'minutes': 150.0, 'usage': {'W': 6.0}}, night_queue.estimate(ms, 3))

    def test_window_measured_only_with_overlap_falls_back_to_all(self):
        ms = [
            {'epic': 'a', 'tasks': 2, 'minutes': 20.0, 'usage': {'W': 40, 'F': 8}, 'overlap': True},
            {'epic': 'b', 'tasks': 2, 'minutes': 20.0, 'usage': {'W': 4}, 'overlap': False},
        ]
        self.assertEqual({'minutes': 30.0, 'usage': {'W': 6.0, 'F': 12.0}}, night_queue.estimate(ms, 3))


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
        self.assertEqual({'epic': 'cart', 'tasks': 4, 'minutes': 90.0, 'usage': {LABEL: 4}, 'overlap': False},
                         json.loads(out))
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
        self.assertEqual({'epic': 'cart', 'tasks': 4, 'minutes': 30.0, 'usage': {LABEL: 5}, 'overlap': False},
                         json.loads(out))

    def test_corrupt_state_exits_2_naming_line(self):
        os.makedirs(self.state)
        with open(os.path.join(self.state, 'measurements.jsonl'), 'w') as f:
            f.write(GOOD_LINE + '\nbroken\n')
        for command in ('start', 'done'):
            code, out, err = self.cli(command, 'cart', json.dumps(usage(1)))
            self.assertEqual((2, ''), (code, out))
            self.assertIn('2', err)


def spec_text(scope_line):
    return '# Epic\n\nIssues: #1\n%s\n\n## Cel\nText\n' % scope_line if scope_line else '# Epic\n\n## Cel\n'


class BoardRunner:
    """Answers the git/gh commands of the shared board reading from prepared data."""

    def __init__(self, epics, branches=(), fail=None):
        # epics: list of (number, title, body, scope_line or None, tasks); all are prepared unless tasks is None
        self.epics, self.branches, self.fail, self.calls = epics, branches, fail, []

    def __call__(self, args):
        self.calls.append(list(args))
        if self.fail and list(args[:len(self.fail)]) == list(self.fail):
            raise night_queue.next_epic.CommandError(args, 'boom')
        if args[:2] == ['git', 'fetch']:
            return ''
        if args[:3] == ['gh', 'project', 'item-list']:
            return json.dumps({'items': [
                {'content': {'number': n, 'repository': 'piotrp321/shop', 'title': t, 'type': 'Issue'},
                 'labels': ['epic']} for n, t, _, _, _ in self.epics]})
        if args[:3] == ['gh', 'issue', 'list']:
            return json.dumps([{'number': n, 'title': t, 'body': b} for n, t, b, _, _ in self.epics])
        if args[:2] == ['git', 'ls-remote']:
            return ''.join('abc\trefs/heads/epic/%s\n' % b for b in self.branches)
        if args[:2] == ['git', 'ls-tree']:
            out = ''
            for n, t, b, scope, tasks in self.epics:
                if tasks is not None:
                    d = t.partition(':')[0].lower()
                    out += 'docs/features/%s/spec.md\ndocs/features/%s/plan.md\n' % (d, d)
            return out
        if args[:2] == ['git', 'show']:
            _, path = args[2].split(':', 1)
            d, name = path.split('/')[2:]
            for n, t, b, scope, tasks in self.epics:
                if t.partition(':')[0].lower() == d:
                    return spec_text(scope) if name == 'spec.md' else manifest(tasks).replace('{"tasks"', '{"schema_version": 1, "tasks"')
        raise AssertionError('unexpected command %r' % args)


def epic(number, id_, scope, tasks=2, body=''):
    return (number, '%s: title' % id_, body, 'Zakres: %s' % scope if scope else None, tasks)


class QueueCliTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.addCleanup(lambda: __import__('shutil').rmtree(self.dir, ignore_errors=True))
        self.state = os.path.join(self.dir, 'state')

    def measure(self, tasks=2, minutes=60):
        os.makedirs(self.state, exist_ok=True)
        start = {'event': 'start', 'epic': 'old', 'tasks': tasks, 'at': T0.isoformat(), 'usage': {}}
        done = {'event': 'done', 'epic': 'old', 'at': (T0 + timedelta(minutes=minutes)).isoformat(), 'usage': {}}
        with open(os.path.join(self.state, 'measurements.jsonl'), 'w') as f:
            f.write(json.dumps(start) + '\n' + json.dumps(done) + '\n')

    def cli(self, command, epics, extra=(), branches=(), fail=None, now=T0):
        out, err = io.StringIO(), io.StringIO()
        argv = ['--state', self.state, command] + list(extra)
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = night_queue.main(argv, run=BoardRunner(epics, branches, fail), now=now, stdin=io.StringIO(''))
        return code, out.getvalue(), err.getvalue()

    def propose(self, epics, extra=(), **kwargs):
        code, out, err = self.cli('propose', epics, extra, **kwargs)
        self.assertEqual(0, code, err)
        return json.loads(out)

    def dirs(self, result):
        return [e['dir'] for e in result['epics']]

    def test_output_shape(self):
        result = self.propose([epic(1, 'CART', 'apps/api/src/Cart', tasks=3)])
        self.assertEqual({'epics', 'skipped', 'in_progress', 'until', 'measured'}, set(result))
        self.assertEqual({'dir': 'cart', 'id': 'CART', 'issue': 1, 'title': 'CART: title', 'tasks': 3,
                          'minutes': None, 'scope': ['apps/api/src/Cart', 'docs/features/cart']},
                         result['epics'][0])
        self.assertFalse(result['measured'])
        self.assertEqual([], result['skipped'] + result['in_progress'])

    def test_in_progress_empties_list(self):
        epics = [epic(1, 'CART', 'a'), epic(2, 'VARIANTS', 'b')]
        result = self.propose(epics, branches=['cart'])
        self.assertEqual([], result['epics'])
        self.assertEqual([{'dir': 'cart', 'id': 'CART', 'issue': 1}], result['in_progress'])

    def test_in_progress_keeps_unprepared_in_skipped(self):
        epics = [epic(1, 'CART', 'a'), epic(2, 'LATER', 'b', tasks=None)]
        result = self.propose(epics, branches=['cart'])
        self.assertEqual([{'issue': 2, 'id': 'LATER', 'reason': 'brak spec.md i plan.md z manifestem na origin/main'}],
                         result['skipped'])

    def test_unprepared_does_not_stop_scan(self):
        epics = [epic(1, 'CART', 'a', tasks=None), epic(2, 'VARIANTS', 'b')]
        result = self.propose(epics)
        self.assertEqual(['variants'], self.dirs(result))
        self.assertEqual('brak spec.md i plan.md z manifestem na origin/main', result['skipped'][0]['reason'])

    def test_title_without_prefix_is_skipped(self):
        epics = [(1, 'no prefix', '', None, 1), epic(2, 'CART', 'a')]
        result = self.propose(epics)
        self.assertEqual([{'issue': 1, 'id': None, 'reason': 'tytuł bez prefiksu ID'}], result['skipped'])
        self.assertEqual(['cart'], self.dirs(result))

    def test_depends_on_open_epic(self):
        epics = [epic(1, 'CHECKOUT', 'a'), epic(2, 'PAY', 'b', body='x\n## Zależności\nPo CHECKOUT oraz #3\n## Inne\n')]
        result = self.propose(epics)
        self.assertEqual(['checkout'], self.dirs(result))
        self.assertEqual([{'issue': 2, 'id': 'PAY', 'reason': 'zależy od CHECKOUT'}], result['skipped'])

    def test_dependency_prefix_id_does_not_match(self):
        body = '## Zależności\nCART-FIXES musi być gotowy\n'
        epics = [epic(1, 'CART', 'a'), epic(2, 'CART-FIXES', 'b'), epic(3, 'LAST', 'c', body=body)]
        result = self.propose(epics)
        self.assertEqual('zależy od CART-FIXES', result['skipped'][0]['reason'])
        self.assertEqual(['cart', 'cart-fixes'], self.dirs(result))

    def test_dependency_id_with_trailing_dot_matches(self):
        epics = [epic(1, 'CART', 'a'), epic(2, 'LAST', 'b', body='## Zależności\nPo CART.\n')]
        self.assertEqual('zależy od CART', self.propose(epics)['skipped'][0]['reason'])

    def test_dependencies_only_from_their_section(self):
        body = 'CART w opisie\n## Zależności\nbrak\n## Dalej\nCART\n'
        epics = [epic(1, 'CART', 'a'), epic(2, 'LAST', 'b', body=body)]
        self.assertEqual(['cart', 'last'], self.dirs(self.propose(epics)))

    def test_dependency_first_in_board_order(self):
        body = '## Zależności\nVARIANTS i CART\n'
        epics = [epic(1, 'CART', 'a'), epic(2, 'VARIANTS', 'b'), epic(3, 'LAST', 'c', body=body)]
        self.assertEqual('zależy od CART', self.propose(epics)['skipped'][0]['reason'])

    def test_shared_scope_names_path_of_candidate(self):
        epics = [epic(1, 'CART', 'apps/api/openapi.json'), epic(2, 'VARIANTS', 'apps/api/openapi.json, apps/x')]
        result = self.propose(epics)
        self.assertEqual([{'issue': 2, 'id': 'VARIANTS', 'reason': 'zakres wspólny z CART: apps/api/openapi.json'}],
                         result['skipped'])

    def test_first_without_scope_is_alone(self):
        epics = [epic(1, 'CART', None), epic(2, 'VARIANTS', 'apps/x')]
        result = self.propose(epics)
        self.assertEqual(['cart'], self.dirs(result))
        self.assertIsNone(result['epics'][0]['scope'])
        self.assertEqual('pierwszy epik listy CART nie ma zakresu', result['skipped'][0]['reason'])

    def test_later_without_scope_is_skipped(self):
        epics = [epic(1, 'CART', 'apps/a'), epic(2, 'VARIANTS', None)]
        result = self.propose(epics)
        self.assertEqual(['cart'], self.dirs(result))
        self.assertEqual('brak linii Zakres', result['skipped'][0]['reason'])

    def test_later_with_invalid_scope_gives_error_text(self):
        epics = [epic(1, 'CART', 'apps/a'), epic(2, 'VARIANTS', '/abs')]
        self.assertEqual('nieczytelna linia Zakres: /abs', self.propose(epics)['skipped'][0]['reason'])

    def test_lower_disjoint_epic_enters_after_skipped_higher(self):
        epics = [epic(1, 'CART', 'apps/a'), epic(2, 'VARIANTS', 'apps/a/b'), epic(3, 'SEARCH', 'apps/c')]
        result = self.propose(epics)
        self.assertEqual(['cart', 'search'], self.dirs(result))
        self.assertEqual([2], [s['issue'] for s in result['skipped']])

    def test_time_limit_with_measurement(self):
        self.measure(tasks=2, minutes=60)   # 30 min per task; 22:00 -> 07:00 is 540 min
        epics = [epic(1, 'A', 'a', tasks=10), epic(2, 'B', 'b', tasks=6), epic(3, 'C', 'c', tasks=4)]
        result = self.propose(epics)
        self.assertTrue(result['measured'])
        self.assertEqual(['a', 'b'], self.dirs(result))   # 300 + 180 = 480 fits, 480 + 120 = 600 does not
        self.assertEqual([300.0, 180.0], [e['minutes'] for e in result['epics']])
        self.assertEqual('nie mieści się do 07:00', result['skipped'][-1]['reason'])

    def test_time_limit_skips_to_smaller_epic(self):
        self.measure(tasks=2, minutes=60)
        epics = [epic(1, 'A', 'a', tasks=10), epic(2, 'B', 'b', tasks=10), epic(3, 'C', 'c', tasks=2)]
        result = self.propose(epics)
        self.assertEqual(['a', 'c'], self.dirs(result))

    def test_measured_false_when_only_measurement_has_zero_tasks(self):
        start = {'event': 'start', 'epic': 'old', 'tasks': 0, 'at': T0.isoformat(), 'usage': {}}
        done = {'event': 'done', 'epic': 'old', 'at': (T0 + timedelta(minutes=60)).isoformat(), 'usage': {}}
        original = night_queue.read_events
        night_queue.read_events = lambda state: [start, done]
        self.addCleanup(setattr, night_queue, 'read_events', original)
        result = self.propose([epic(1, 'A', 'a', tasks=3)])
        self.assertIsNone(result['epics'][0]['minutes'])
        self.assertFalse(result['measured'])

    def test_no_limit_without_measurement(self):
        epics = [epic(i, 'E%d' % i, 'p%d' % i, tasks=1000) for i in range(1, 4)]
        result = self.propose(epics)
        self.assertEqual(3, len(result['epics']))
        self.assertFalse(result['measured'])

    def test_until_option_and_message(self):
        self.measure(tasks=2, minutes=60)
        result = self.propose([epic(1, 'A', 'a', tasks=4)], ['--until', '23:30'])   # 120 min > 90
        self.assertEqual('nie mieści się do 23:30', result['skipped'][0]['reason'])
        self.assertEqual('2026-11-04T23:30:00+00:00', result['until'])

    def test_until_default_and_rollover_past_midnight(self):
        self.assertEqual('2026-11-05T07:00:00+00:00', self.propose([epic(1, 'A', 'a')])['until'])
        early = T0.replace(hour=5)
        code, out, _ = self.cli('propose', [epic(1, 'A', 'a')], now=early)
        self.assertEqual('2026-11-04T07:00:00+00:00', json.loads(out)['until'])

    def test_until_strictly_after_now(self):
        code, out, _ = self.cli('propose', [epic(1, 'A', 'a')], ['--until', '22:00'])
        self.assertEqual('2026-11-05T22:00:00+00:00', json.loads(out)['until'])

    def test_until_invalid_exits_2(self):
        for bad in ['7', '25:00', '07:60', 'ab:cd', '07:00:00', '']:
            with self.subTest(bad=bad):
                code, out, err = self.cli('propose', [epic(1, 'A', 'a')], ['--until', bad])
                self.assertEqual((2, ''), (code, out))
                self.assertTrue(err)

    def test_gh_failure_exits_1(self):
        code, out, err = self.cli('propose', [epic(1, 'A', 'a')], fail=['gh', 'issue'])
        self.assertEqual((1, ''), (code, out))
        self.assertIn('boom', err)

    def test_fetches_origin_main_first(self):
        runner = BoardRunner([epic(1, 'A', 'a')])
        with contextlib.redirect_stdout(io.StringIO()):
            night_queue.main(['--state', self.state, 'propose'], run=runner, now=T0)
        self.assertEqual(['git', 'fetch', 'origin', 'main'], runner.calls[0])

    def test_approve_match_writes_night_json(self):
        epics = [epic(1, 'A', 'a'), epic(2, 'B', 'b')]
        code, out, err = self.cli('approve', epics, ['a', 'b'])
        self.assertEqual(0, code, err)
        expected = {'approved_at': T0.isoformat(), 'until': '2026-11-05T07:00:00+00:00', 'epics': ['a', 'b']}
        self.assertEqual(expected, json.loads(out))
        with open(os.path.join(self.state, 'night.json'), encoding='utf-8') as f:
            self.assertEqual(expected, json.load(f))

    def test_approve_mismatch_exits_2_with_both_lists(self):
        epics = [epic(1, 'A', 'a'), epic(2, 'B', 'b')]
        for given in (['b', 'a'], ['a'], ['a', 'b', 'c']):
            with self.subTest(given=given):
                code, out, err = self.cli('approve', epics, given)
                self.assertEqual((2, ''), (code, out))
                self.assertIn('a, b', err)
                self.assertIn(', '.join(given), err)
        self.assertFalse(os.path.exists(os.path.join(self.state, 'night.json')))

    def test_approve_empty_list_exits_2(self):
        code, out, err = self.cli('approve', [epic(1, 'A', 'a')], ['a'], branches=['a'])
        self.assertEqual((2, ''), (code, out))
        self.assertFalse(os.path.exists(os.path.join(self.state, 'night.json')))

    def test_approve_uses_until_option(self):
        code, out, _ = self.cli('approve', [epic(1, 'A', 'a')], ['--until', '06:00', 'a'])
        self.assertEqual('2026-11-05T06:00:00+00:00', json.loads(out)['until'])

    def test_approve_without_dirs_exits_2(self):
        code, out, _ = self.cli('approve', [epic(1, 'A', 'a')])
        self.assertEqual((2, ''), (code, out))


APPROVED = T0
UNTIL = T0 + timedelta(hours=9)
NOW = T0 + timedelta(hours=2)


class NextCliTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.addCleanup(lambda: __import__('shutil').rmtree(self.dir, ignore_errors=True))
        self.state = os.path.join(self.dir, 'state')
        os.makedirs(self.state)

    def night(self, dirs, until=UNTIL, approved=APPROVED):
        with open(os.path.join(self.state, 'night.json'), 'w') as f:
            json.dump({'approved_at': approved.isoformat(), 'until': until.isoformat(), 'epics': dirs}, f)

    def events(self, *events):
        with open(os.path.join(self.state, 'measurements.jsonl'), 'w') as f:
            for e in events:
                f.write(json.dumps(e) + '\n')

    def start(self, epic_dir, at, tasks=2, usage_=None):
        return {'event': 'start', 'epic': epic_dir, 'tasks': tasks, 'at': at.isoformat(), 'usage': usage_ or {}}

    def done(self, epic_dir, at, usage_=None):
        return {'event': 'done', 'epic': epic_dir, 'at': at.isoformat(), 'usage': usage_ or {}}

    def old_measurement(self, minutes=60, tasks=2, before=10, after=14):
        """Before the approval: 30 min and 2 % of the weekly window per task by default."""
        at = T0 - timedelta(hours=10)
        return [self.start('old', at, tasks, usage(before)), self.done('old', at + timedelta(minutes=minutes), usage(after))]

    def a_done(self):
        return [self.start('a', T0 + timedelta(minutes=10)), self.done('a', T0 + timedelta(minutes=90))]

    def next(self, epics, stdin_text=None, branches=(), fail=None, now=NOW, runner=None):
        out, err = io.StringIO(), io.StringIO()
        runner = runner or BoardRunner(epics, branches, fail)
        self.runner = runner
        stdin = io.StringIO(json.dumps(usage(10)) if stdin_text is None else stdin_text)
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = night_queue.main(['--state', self.state, 'next'], run=runner, now=now, stdin=stdin)
        return code, out.getvalue(), err.getvalue()

    def result(self, epics, **kwargs):
        code, out, err = self.next(epics, **kwargs)
        self.assertEqual(0, code, err)
        return json.loads(out)

    def reason(self, epics, **kwargs):
        result = self.result(epics, **kwargs)
        self.assertIsNone(result['epic'])
        return result['reason']

    def abc(self):
        return [epic(1, 'A', 'a'), epic(2, 'B', 'b'), epic(3, 'C', 'c')]

    def test_no_night_file(self):
        result = self.result(self.abc())
        self.assertEqual({'epic': None, 'reason': 'brak zatwierdzonej listy', 'started': [], 'left': []}, result)
        self.assertEqual([], self.runner.calls)

    def test_night_over(self):
        self.night(['a', 'b'])
        result = self.result(self.abc(), now=UNTIL + timedelta(minutes=1))
        self.assertEqual('noc minęła (07:00)', result['reason'])
        self.assertIsNone(result['epic'])
        self.assertEqual([], self.runner.calls)

    def test_night_over_time_is_local_to_now(self):
        self.night(['a'])
        tz = timezone(timedelta(hours=1))
        result = self.result(self.abc(), now=(UNTIL + timedelta(minutes=1)).astimezone(tz))
        self.assertEqual('noc minęła (08:00)', result['reason'])

    def test_exactly_until_is_not_over(self):
        self.night(['a'])
        self.assertIsNotNone(self.result(self.abc(), now=UNTIL)['epic'])

    def test_unreadable_night_file_exits_2(self):
        with open(os.path.join(self.state, 'night.json'), 'w') as f:
            f.write('{')
        code, out, err = self.next(self.abc())
        self.assertEqual((2, ''), (code, out))
        self.assertIn('night.json', err)

    def test_first_epic_without_checks(self):
        self.night(['a', 'b'])
        result = self.result(self.abc(), stdin_text='')
        self.assertEqual({'dir': 'a', 'id': 'A', 'issue': None, 'title': None, 'tasks': 2, 'minutes': None},
                         result['epic'])
        self.assertIsNone(result['reason'])
        self.assertEqual(([], ['b']), (result['started'], result['left']))
        self.assertEqual([['git', 'show', 'origin/main:docs/features/a/plan.md']], self.runner.calls)

    def test_first_epic_ignores_events_before_approval(self):
        self.night(['a', 'b'])
        self.events(self.start('a', T0 - timedelta(hours=3)), *self.old_measurement())
        result = self.result(self.abc())
        self.assertEqual('a', result['epic']['dir'])
        self.assertEqual(60, result['epic']['minutes'])

    def test_previous_epic_without_done(self):
        self.night(['a', 'b'])
        self.events(self.start('a', T0 + timedelta(minutes=10)))
        result = self.result(self.abc())
        self.assertEqual({'epic': None, 'reason': 'a bez pomiaru done', 'started': ['a'], 'left': ['b']}, result)
        self.assertEqual([], self.runner.calls)

    def test_restarted_epic_without_later_done(self):
        self.night(['a', 'b'])
        self.events(*self.a_done(), self.start('a', T0 + timedelta(minutes=100)))
        self.assertEqual('a bez pomiaru done', self.reason(self.abc()))

    def test_list_done(self):
        self.night(['a'])
        self.events(*self.a_done())
        result = self.result(self.abc())
        self.assertEqual({'epic': None, 'reason': 'lista wykonana', 'started': ['a'], 'left': []}, result)

    def test_returns_second_epic_with_estimate(self):
        self.night(['a', 'b', 'c'])
        self.events(*self.old_measurement(), *self.a_done())
        result = self.result(self.abc())
        self.assertEqual({'dir': 'b', 'id': 'B', 'issue': 2, 'title': 'B: title', 'tasks': 2, 'minutes': 80.0},
                         result['epic'])
        self.assertEqual((None, ['a'], ['c']), (result['reason'], result['started'], result['left']))

    def test_board_is_read_after_the_first_epic(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        self.result(self.abc())
        self.assertEqual(['git', 'fetch', 'origin', 'main'], self.runner.calls[0])

    def test_epic_missing_from_board(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        result = self.result([epic(1, 'A', 'a')])
        self.assertEqual({'epic': None, 'reason': 'B nie jest już przygotowany', 'started': ['a'], 'left': ['b']},
                         result)

    def test_epic_no_longer_prepared_with_reason(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        epics = [epic(1, 'A', 'a'), epic(2, 'B', 'b', tasks=None)]
        self.assertEqual('B nie jest już przygotowany: brak spec.md i plan.md z manifestem na origin/main',
                         self.reason(epics))

    def test_epic_in_progress(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        self.assertEqual('B nie jest już przygotowany: w toku', self.reason(self.abc(), branches=['b']))

    def test_no_measurement(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        with mock.patch.object(night_queue, 'estimate', return_value={'minutes': None, 'usage': {}}):
            result = self.result(self.abc())
        self.assertEqual(('brak pomiaru', None), (result['reason'], result['epic']))

    def test_does_not_fit_before_until(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        now = UNTIL - timedelta(minutes=79)
        self.assertEqual('B nie zdąży do 07:00', self.reason(self.abc(), now=now))

    def test_fits_exactly_before_until(self):
        self.night(['a', 'b'])
        self.events(*self.old_measurement(), *self.a_done())
        self.assertEqual('b', self.result(self.abc(), now=UNTIL - timedelta(minutes=80))['epic']['dir'])

    def test_usage_status_not_ok(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        for text, status in [('', 'unreadable'), ('garbage', 'unreadable'), ('{"status": "error"}', 'error'),
                             ('{"status": "ok"}', 'unreadable')]:
            with self.subTest(text=text):
                self.night(['a', 'b'])   # a refusal ends the night, so start each case from a fresh one
                self.assertEqual('stan limitów: %s' % status, self.reason(self.abc(), stdin_text=text))

    def test_window_over_80(self):
        self.night(['a', 'b'])
        self.events(*self.old_measurement(), *self.a_done())
        reason = self.reason(self.abc(), stdin_text=json.dumps(usage(78.04)))
        self.assertEqual('okno Weekly · all models: 78.0% + 4.0% > 80%', reason)

    def test_exactly_80_allowed(self):
        self.night(['a', 'b'])
        self.events(*self.old_measurement(), *self.a_done())
        result = self.result(self.abc(), stdin_text=json.dumps(usage(76)))
        self.assertEqual('b', result['epic']['dir'])

    def test_window_without_measurement(self):
        self.night(['a', 'b'])
        self.events(*self.old_measurement(), *self.a_done())
        text = json.dumps(usage(10, extra_windows=[{'label': 'Session', 'percentUsed': 1, 'resetsAt': RESET}]))
        self.assertEqual('okno Session bez pomiaru', self.reason(self.abc(), stdin_text=text))

    def test_window_without_any_usage_measurement(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        self.assertEqual('okno %s bez pomiaru' % LABEL, self.reason(self.abc()))

    def test_window_resetting_before_the_end_is_ignored(self):
        self.night(['a', 'b'])
        self.events(*self.old_measurement(), *self.a_done())
        soon = (NOW + timedelta(minutes=30)).isoformat()
        text = json.dumps(usage(10, extra_windows=[{'label': 'Session', 'percentUsed': 99, 'resetsAt': soon}]))
        self.assertEqual('b', self.result(self.abc(), stdin_text=text)['epic']['dir'])

    def test_window_resetting_right_after_the_end_counts(self):
        self.night(['a', 'b'])
        self.events(*self.old_measurement(), *self.a_done())
        late = (NOW + timedelta(minutes=80, seconds=1)).isoformat()
        text = json.dumps(usage(10, extra_windows=[{'label': 'Session', 'percentUsed': 99, 'resetsAt': late}]))
        self.assertEqual('okno Session bez pomiaru', self.reason(self.abc(), stdin_text=text))

    def test_failed_check_leaves_candidate_in_left(self):
        self.night(['a', 'b', 'c'])
        self.events(*self.a_done())
        result = self.result(self.abc(), stdin_text='')
        self.assertEqual((['a'], ['b', 'c']), (result['started'], result['left']))

    def test_gh_failure_exits_1(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        code, out, err = self.next(self.abc(), fail=['gh', 'issue'])
        self.assertEqual((1, ''), (code, out))
        self.assertIn('boom', err)

    def test_git_failure_for_first_epic_exits_1(self):
        self.night(['a'])
        code, out, err = self.next(self.abc(), fail=['git', 'show'])
        self.assertEqual((1, ''), (code, out))
        self.assertIn('boom', err)

    # --- end of the night: close, ended_at, next recording the end, approve starting a fresh night ---

    def close(self, *extra):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = night_queue.main(['--state', self.state, 'close'] + list(extra), run=BoardRunner([]), now=NOW)
        return code, out.getvalue(), err.getvalue()

    def night_json(self):
        with open(os.path.join(self.state, 'night.json')) as f:
            return json.load(f)

    def test_close_sets_ended_at_and_default_reason(self):
        self.night(['a', 'b'])
        code, out, err = self.close()
        self.assertEqual(0, code, err)
        night = self.night_json()
        self.assertEqual(NOW.isoformat(), night['ended_at'])
        self.assertEqual('zamknięta', night['reason'])
        self.assertEqual(['a', 'b'], night['epics'])
        self.assertEqual(night, json.loads(out))

    def test_close_with_reason(self):
        self.night(['a'])
        code, out, err = self.close('--reason', 'A zablokowany')
        self.assertEqual(0, code, err)
        self.assertEqual('A zablokowany', json.loads(out)['reason'])
        self.assertEqual('A zablokowany', self.night_json()['reason'])

    def test_close_twice_keeps_the_first(self):
        self.night(['a'])
        self.close('--reason', 'pierwszy')
        code, out, err = self.close('--reason', 'drugi')
        self.assertEqual(0, code, err)
        night = self.night_json()
        self.assertEqual(('pierwszy', NOW.isoformat()), (night['reason'], night['ended_at']))
        self.assertEqual(night, json.loads(out))

    def test_close_without_list_exits_2(self):
        os.rmdir(self.state)
        code, out, err = self.close()
        self.assertEqual((2, ''), (code, out))
        self.assertTrue(err)
        self.assertFalse(os.path.exists(os.path.join(self.state, 'night.json')))

    def test_close_unreadable_night_file_exits_2(self):
        with open(os.path.join(self.state, 'night.json'), 'w') as f:
            f.write('{')
        code, out, err = self.close()
        self.assertEqual((2, ''), (code, out))

    def test_next_after_close_refuses_without_reading_the_board(self):
        self.night(['a', 'b', 'c'])
        self.events(*self.a_done())
        self.close('--reason', 'A bez DONE')
        result = self.result(self.abc())
        self.assertEqual({'epic': None, 'reason': 'noc zakończona: A bez DONE', 'started': ['a'], 'left': ['b', 'c']},
                         result)
        self.assertEqual([], self.runner.calls)

    def test_next_after_close_refuses_before_night_over(self):
        self.night(['a'])
        self.close()
        self.assertEqual('noc zakończona: zamknięta',
                         self.reason(self.abc(), now=UNTIL + timedelta(hours=1)))

    def test_next_null_records_the_end_and_second_next_refuses(self):
        self.night(['a', 'b'])
        self.events(*self.a_done())
        reason = 'okno %s bez pomiaru' % LABEL
        self.assertEqual(reason, self.reason(self.abc()))
        night = self.night_json()
        self.assertEqual((reason, NOW.isoformat()), (night['reason'], night['ended_at']))
        # the situation is fixed now, but the night stays ended
        self.events(*self.old_measurement(), *self.a_done())
        self.assertEqual('noc zakończona: ' + reason, self.reason(self.abc()))

    def test_next_night_over_records_the_end(self):
        self.night(['a'])
        self.reason(self.abc(), now=UNTIL + timedelta(minutes=1))
        self.assertEqual('noc minęła (07:00)', self.night_json()['reason'])

    def test_next_list_done_records_the_end(self):
        self.night(['a'])
        self.events(*self.a_done())
        self.assertEqual('lista wykonana', self.reason(self.abc()))
        self.assertEqual('lista wykonana', self.night_json()['reason'])

    def test_next_with_epic_does_not_record(self):
        self.night(['a', 'b'])
        self.result(self.abc())
        self.assertNotIn('ended_at', self.night_json())

    def test_next_with_unreadable_night_does_not_write(self):
        path = os.path.join(self.state, 'night.json')
        with open(path, 'w') as f:
            f.write('{')
        code, out, err = self.next(self.abc())
        self.assertEqual((2, ''), (code, out))
        with open(path) as f:
            self.assertEqual('{', f.read())

    def test_next_with_unreadable_ended_at_exits_2(self):
        self.night(['a'])
        night = self.night_json()
        night['ended_at'] = 'garbage'
        with open(os.path.join(self.state, 'night.json'), 'w') as f:
            json.dump(night, f)
        code, out, err = self.next(self.abc())
        self.assertEqual((2, ''), (code, out))

    def test_next_with_offsetless_reset_exits_cleanly(self):
        self.night(['a', 'b'])
        self.events(*self.old_measurement(), *self.a_done())
        text = json.dumps({'status': 'ok', 'windows': [{'label': LABEL, 'percentUsed': 10,
                                                        'resetsAt': '2026-11-11T17:59:59'}]})
        self.assertEqual('stan limitów: unreadable', self.reason(self.abc(), stdin_text=text))

    def test_approve_after_close_starts_a_fresh_night(self):
        self.night(['a'])
        self.close('--reason', 'stary')
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = night_queue.main(['--state', self.state, 'approve', 'a'], run=BoardRunner([epic(1, 'A', 'a')]),
                                    now=T0, stdin=io.StringIO(''))
        self.assertEqual(0, code, err.getvalue())
        self.assertEqual({'approved_at', 'until', 'epics'}, set(self.night_json()))
        self.assertNotIn('ended_at', json.loads(out.getvalue()))


class AheadCliTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.addCleanup(lambda: __import__('shutil').rmtree(self.dir, ignore_errors=True))
        self.state = os.path.join(self.dir, 'state')

    def measure(self):
        os.makedirs(self.state, exist_ok=True)
        start = {'event': 'start', 'epic': 'old', 'tasks': 2, 'at': T0.isoformat(), 'usage': {}}
        done = {'event': 'done', 'epic': 'old', 'at': (T0 + timedelta(minutes=60)).isoformat(), 'usage': {}}
        with open(os.path.join(self.state, 'measurements.jsonl'), 'w') as f:
            f.write(json.dumps(start) + '\n' + json.dumps(done) + '\n')

    def ahead(self, epics, branches=(), fail=None):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = night_queue.main(['--state', self.state, 'ahead'], run=BoardRunner(epics, branches, fail), now=T0)
        return code, out.getvalue(), err.getvalue()

    def result(self, epics, **kwargs):
        code, out, err = self.ahead(epics, **kwargs)
        self.assertEqual(0, code, err)
        return json.loads(out)

    def test_measured(self):
        self.measure()
        result = self.result([epic(1, 'A', 'a', tasks=3), epic(2, 'B', 'b', tasks=2)])
        self.assertEqual({'epics', 'minutes', 'full'}, set(result))
        self.assertEqual({'dir': 'a', 'id': 'A', 'issue': 1, 'title': 'A: title', 'tasks': 3, 'minutes': 90.0},
                         result['epics'][0])
        self.assertEqual([('b', 2, 60.0)], [(e['dir'], e['tasks'], e['minutes']) for e in result['epics'][1:]])
        self.assertEqual((150.0, False), (result['minutes'], result['full']))

    def test_measured_full_at_540(self):
        self.measure()
        result = self.result([epic(1, 'A', 'a', tasks=18)])
        self.assertEqual((540.0, True), (result['minutes'], result['full']))

    def test_measured_just_below_540(self):
        self.measure()
        result = self.result([epic(1, 'A', 'a', tasks=17)])
        self.assertEqual((510.0, False), (result['minutes'], result['full']))

    def test_not_measured_one_epic_is_full(self):
        result = self.result([epic(1, 'A', 'a', tasks=3)])
        self.assertEqual([None], [e['minutes'] for e in result['epics']])
        self.assertEqual((None, True), (result['minutes'], result['full']))

    def test_empty_not_measured(self):
        self.assertEqual({'epics': [], 'minutes': None, 'full': False}, self.result([]))

    def test_empty_measured(self):
        self.measure()
        self.assertEqual({'epics': [], 'minutes': 0.0, 'full': False}, self.result([]))

    def test_only_prepared_not_in_progress(self):
        epics = [epic(1, 'A', 'a'), epic(2, 'B', 'b', tasks=None), epic(3, 'C', 'c'),
                 (4, 'no prefix', '', None, 2)]
        result = self.result(epics, branches=['a'])
        self.assertEqual(['c'], [e['dir'] for e in result['epics']])

    def test_gh_failure_exits_1(self):
        code, out, err = self.ahead([epic(1, 'A', 'a')], fail=['gh', 'project'])
        self.assertEqual((1, ''), (code, out))
        self.assertIn('boom', err)

    def test_bad_manifest_exits_2(self):
        code, out, err = self.ahead([epic(1, 'A', 'a', tasks=0)])
        self.assertEqual((2, ''), (code, out))


if __name__ == '__main__':
    unittest.main()


class ParseTimeTest(unittest.TestCase):
    def test_z_and_offset(self):
        self.assertEqual(datetime(2026, 11, 11, 17, 59, 59, 640000, tzinfo=timezone.utc),
                         night_queue.parse_time('2026-11-11T17:59:59.640Z'))
        self.assertEqual(timedelta(hours=1), night_queue.parse_time('2026-11-11T17:59:59+01:00').utcoffset())

    def test_offsetless_is_a_value_error(self):
        for text in ['2026-11-11T17:59:59', '2026-11-11']:
            with self.subTest(text=text):
                with self.assertRaises(ValueError):
                    night_queue.parse_time(text)

    def test_usage_with_offsetless_reset_is_unreadable(self):
        text = json.dumps({'status': 'ok', 'windows': [{'label': 'W', 'percentUsed': 1,
                                                        'resetsAt': '2026-11-11T17:59:59'}]})
        self.assertEqual({'status': 'unreadable'}, night_queue.parse_usage(text))

    def test_offsetless_event_time_is_unreadable_state(self):
        with tempfile.TemporaryDirectory() as state:
            with open(os.path.join(state, 'measurements.jsonl'), 'w') as f:
                f.write(json.dumps({'event': 'done', 'epic': 'a', 'at': '2026-11-04T22:00:00', 'usage': {}}) + '\n')
            with self.assertRaises(night_queue.StateError):
                night_queue.read_events(state)


class ParseUntilLocalTest(unittest.TestCase):
    """parse_until on a local `now` attaches the offset valid on the target date (DST change nights)."""

    def setUp(self):
        import time
        if not hasattr(time, 'tzset'):
            self.skipTest('no tzset')
        self.old_tz = os.environ.get('TZ')
        self.addCleanup(self.restore)
        os.environ['TZ'] = 'Europe/Warsaw'
        time.tzset()
        if datetime(2026, 7, 1, 12).astimezone().utcoffset() != timedelta(hours=2):
            self.skipTest('no tz database for Europe/Warsaw')

    def restore(self):
        import time
        if self.old_tz is None:
            os.environ.pop('TZ', None)
        else:
            os.environ['TZ'] = self.old_tz
        time.tzset()

    def test_offset_of_the_target_date_after_dst_end(self):
        now = datetime(2026, 10, 24, 22, 0).astimezone()             # CEST, +02:00
        self.assertEqual(timedelta(hours=2), now.utcoffset())
        until = night_queue.parse_until('07:00', now)                  # 25 Oct 07:00 is CET, +01:00
        self.assertEqual((2026, 10, 25, 7, 0), (until.year, until.month, until.day, until.hour, until.minute))
        self.assertEqual(timedelta(hours=1), until.utcoffset())

    def test_offset_of_the_target_date_after_dst_start(self):
        now = datetime(2026, 3, 28, 22, 0).astimezone()              # CET, +01:00
        until = night_queue.parse_until('07:00', now)                  # 29 Mar 07:00 is CEST, +02:00
        self.assertEqual((2026, 3, 29, 7, 0), (until.year, until.month, until.day, until.hour, until.minute))
        self.assertEqual(timedelta(hours=2), until.utcoffset())

    def test_same_day_when_still_ahead(self):
        now = datetime(2026, 11, 4, 5, 0).astimezone()
        until = night_queue.parse_until('07:00', now)
        self.assertEqual((4, 7, 0), (until.day, until.hour, until.minute))


class RoomCliTest(unittest.TestCase):
    """room: is there room in the usage limits for a review that does not count into the night estimates."""

    @staticmethod
    def no_commands(args):
        raise AssertionError('room ran a command: %r' % (args,))

    def room(self, plan, hours='2', raw=None, argv=None):
        stdin = io.StringIO(raw if raw is not None else json.dumps(plan))
        out, err = io.StringIO(), io.StringIO()
        argv = argv if argv is not None else ['room', '--hours', hours]
        with tempfile.TemporaryDirectory() as missing_parent:
            # A state directory that does not exist: room must not read or write the state.
            state = os.path.join(missing_parent, 'absent')
            with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err), \
                    mock.patch.object(night_queue, 'read_events', side_effect=AssertionError('read state')), \
                    mock.patch.object(night_queue, 'read_night', side_effect=AssertionError('read state')):
                code = night_queue.main(['--state', state] + argv, run=self.no_commands, now=T0, stdin=stdin)
            self.assertFalse(os.path.exists(state))
        return code, (json.loads(out.getvalue()) if out.getvalue() else None), err.getvalue()

    def test_window_below_80_is_ok(self):
        self.assertEqual((0, {'ok': True}, ''), self.room(usage(79.9)))

    def test_window_at_80_blocks_with_label_and_percent(self):
        self.assertEqual((0, {'ok': False, 'reason': 'okno Weekly · all models: 80.0% >= 80%'}, ''), self.room(usage(80)))

    def test_window_resetting_within_hours_does_not_block(self):
        soon = (T0 + timedelta(hours=1, minutes=59)).isoformat()
        self.assertEqual({'ok': True}, self.room(usage(95, reset=soon))[1])

    def test_window_resetting_after_hours_blocks(self):
        later = (T0 + timedelta(hours=2, minutes=1)).isoformat()
        self.assertEqual({'ok': False, 'reason': 'okno Weekly · all models: 95.0% >= 80%'},
                         self.room(usage(95, reset=later))[1])

    def test_window_resetting_exactly_at_the_horizon_does_not_block(self):
        self.assertEqual({'ok': True}, self.room(usage(95, reset=(T0 + timedelta(hours=2)).isoformat()))[1])

    def test_fractional_hours(self):
        reset = (T0 + timedelta(hours=1, minutes=45)).isoformat()
        self.assertFalse(self.room(usage(95, reset=reset), hours='1.5')[1]['ok'])
        self.assertEqual({'ok': True}, self.room(usage(95, reset=reset), hours='1.75')[1])

    def test_soon_resetting_full_window_next_to_free_window_is_ok(self):
        soon = (T0 + timedelta(hours=1)).isoformat()
        plan = usage(99, reset=soon, extra_windows=[{'label': 'Weekly · Fable', 'percentUsed': 10, 'resetsAt': RESET}])
        self.assertEqual({'ok': True}, self.room(plan)[1])

    def test_any_blocking_window_blocks(self):
        plan = usage(10, extra_windows=[{'label': 'Weekly · Fable', 'percentUsed': 85, 'resetsAt': RESET}])
        result = self.room(plan)[1]
        self.assertFalse(result['ok'])
        self.assertIn('Weekly · Fable', result['reason'])

    def test_no_windows_is_ok(self):
        self.assertEqual({'ok': True}, self.room({'status': 'ok', 'windows': []})[1])

    def test_status_not_ok_blocks(self):
        result = self.room(usage(10, status='rate_limited'))[1]
        self.assertFalse(result['ok'])
        self.assertIn('rate_limited', result['reason'])

    def test_empty_and_invalid_json_block_as_unreadable(self):
        for raw in ['', '{', '[]', '{}']:
            with self.subTest(raw=raw):
                code, result, _ = self.room(None, raw=raw)
                self.assertEqual(0, code)
                self.assertFalse(result['ok'])
                self.assertIn('nieczytelny', result['reason'])

    def test_bad_hours_exits_1(self):
        for hours in ['x', '-1', '0', 'nan', 'inf', '-inf', '1e12']:
            with self.subTest(hours=hours):
                code, result, err = self.room(usage(10), argv=['room', '--hours=%s' % hours])
                self.assertEqual(1, code)
                self.assertIsNone(result)
                self.assertIn('night_queue: --hours', err)
                self.assertNotIn('Traceback', err)

    def test_missing_hours_exits_1(self):
        code, result, err = self.room(usage(10), argv=['room'])
        self.assertEqual((1, None), (code, result))
        self.assertIn('night_queue: --hours', err)

    def test_max_percent_raises_the_threshold(self):
        plan = usage(94.9)
        self.assertEqual({'ok': True}, self.room(plan, argv=['room', '--hours', '2', '--max-percent', '95'])[1])
        self.assertEqual({'ok': False, 'reason': 'okno Weekly · all models: 95.0% >= 95%'},
                         self.room(usage(95), argv=['room', '--hours', '2', '--max-percent', '95'])[1])

    def test_max_percent_defaults_to_80(self):
        self.assertFalse(self.room(usage(80), argv=['room', '--hours', '2'])[1]['ok'])

    def test_bad_max_percent_exits_1(self):
        for value in ['x', '0', '-5', '100.1', 'nan', 'inf']:
            with self.subTest(value=value):
                code, result, err = self.room(usage(10), argv=['room', '--hours', '2', '--max-percent=%s' % value])
                self.assertEqual((1, None), (code, result))
                self.assertIn('night_queue: --max-percent', err)
                self.assertNotIn('Traceback', err)

    def test_reads_no_board(self):
        # no_commands raises on any command: room must not call gh or git.
        self.assertEqual(0, self.room(usage(10))[0])

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


if __name__ == '__main__':
    unittest.main()

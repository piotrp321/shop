"""Tests of next_epic.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import contextlib
import importlib.util
import io
import json
import os
import sys
import time
import unittest
from unittest import mock

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'next_epic.py')
spec = importlib.util.spec_from_file_location('next_epic', SCRIPT)
next_epic = importlib.util.module_from_spec(spec)
spec.loader.exec_module(next_epic)

MANIFEST = '# Plan\n```json\n{"schema_version": 1, "tasks": []}\n```\n'


def item(number, title, labels=('epic',), type_='Issue', repo='piotrp321/shop'):
    return {'content': {'number': number, 'repository': repo, 'title': title, 'type': type_},
            'labels': list(labels)}


class FakeRunner:
    """Answers the commands of next_epic.py from prepared data; records the calls."""

    def __init__(self, items=(), open_issues=None, branches=(), files=None, fail=None):
        self.items = list(items)
        self.open = open_issues if open_issues is not None else [i['content']['number'] for i in self.items]
        self.branches = branches
        self.files = files or {}   # {'cart': {'spec.md': '...', 'plan.md': '...'}}
        self.fail = fail           # prefix of a command that fails
        self.calls = []

    def __call__(self, args):
        self.calls.append(list(args))
        if self.fail and list(args[:len(self.fail)]) == list(self.fail):
            raise next_epic.CommandError(args, 'boom')
        if args[:2] == ['git', 'fetch']:
            return ''
        if args[:3] == ['gh', 'project', 'item-list']:
            return json.dumps({'items': self.items})
        if args[:3] == ['gh', 'issue', 'list']:
            return json.dumps([{'number': n} for n in self.open])
        if args[:2] == ['git', 'ls-remote']:
            return ''.join('abc123\trefs/heads/epic/%s\n' % b for b in self.branches)
        if args[:2] == ['git', 'ls-tree']:
            return ''.join('docs/features/%s/%s\n' % (d, f) for d, fs in self.files.items() for f in fs)
        if args[:2] == ['git', 'show']:
            _, path = args[2].split(':', 1)
            _, _, directory, name = path.split('/')
            return self.files[directory][name]
        raise AssertionError('unexpected command %r' % args)


def prepared(*dirs):
    return {d: {'spec.md': '# s', 'plan.md': MANIFEST} for d in dirs}


class SelectTest(unittest.TestCase):
    def select(self, **kwargs):
        runner = FakeRunner(**kwargs)
        return next_epic.select(runner), runner

    def test_first_prepared_epic_by_board_order(self):
        result, _ = self.select(items=[item(2, 'BETA: b'), item(1, 'ALPHA: a')], files=prepared('alpha', 'beta'))
        self.assertEqual(result['epic'], {'dir': 'beta', 'id': 'BETA', 'issue': 2, 'title': 'BETA: b'})
        self.assertEqual(result['in_progress'], [])
        self.assertEqual(result['skipped'], [])

    def test_fetches_main_first(self):
        _, runner = self.select(items=[])
        self.assertEqual(runner.calls[0], ['git', 'fetch', 'origin', 'main'])

    def test_closed_issue_is_skipped_silently(self):
        result, _ = self.select(items=[item(1, 'ALPHA: a'), item(2, 'BETA: b')], open_issues=[2],
                                files=prepared('alpha', 'beta'))
        self.assertEqual(result['epic']['id'], 'BETA')
        self.assertEqual(result['skipped'], [])

    def test_needs_info_label_is_skipped_silently(self):
        result, _ = self.select(items=[item(1, 'ALPHA: a', labels=('epic', 'needs-info')), item(2, 'BETA: b')],
                                files=prepared('alpha', 'beta'))
        self.assertEqual(result['epic']['id'], 'BETA')
        self.assertEqual(result['skipped'], [])

    def test_item_without_epic_label_is_skipped_silently(self):
        result, _ = self.select(items=[item(1, 'ALPHA: a', labels=('bug',)), item(2, 'BETA: b')],
                                files=prepared('alpha', 'beta'))
        self.assertEqual(result['epic']['id'], 'BETA')
        self.assertEqual(result['skipped'], [])

    def test_other_repo_and_non_issue_are_skipped_silently(self):
        items = [item(1, 'ALPHA: a', repo='piotrp321/other'), item(2, 'BETA: b', type_='DraftIssue'),
                 item(3, 'GAMMA: c')]
        result, _ = self.select(items=items, files=prepared('alpha', 'beta', 'gamma'))
        self.assertEqual(result['epic']['id'], 'GAMMA')
        self.assertEqual(result['skipped'], [])

    def test_title_without_prefix_is_skipped_with_reason(self):
        for title in ('No prefix here', 'bad id!: x', ': empty', 'two words: x'):
            result, _ = self.select(items=[item(1, title), item(2, 'BETA: b')], files=prepared('beta'))
            self.assertEqual(result['skipped'], [{'issue': 1, 'id': None, 'reason': 'tytuł bez prefiksu ID'}], title)
            self.assertEqual(result['epic']['id'], 'BETA')

    def test_id_with_dots_and_dashes_gives_lowercase_dir(self):
        result, _ = self.select(items=[item(1, ' CART-FIXES.2 : x')], files=prepared('cart-fixes.2'))
        self.assertEqual(result['epic']['dir'], 'cart-fixes.2')
        self.assertEqual(result['epic']['id'], 'CART-FIXES.2')

    def test_plan_without_manifest_is_skipped_with_reason(self):
        files = {'alpha': {'spec.md': '# s', 'plan.md': '# plan only'}, **prepared('beta')}
        result, _ = self.select(items=[item(1, 'ALPHA: a'), item(2, 'BETA: b')], files=files)
        self.assertEqual(result['skipped'], [{'issue': 1, 'id': 'ALPHA', 'reason': 'plan bez manifestu'}])
        self.assertEqual(result['epic']['id'], 'BETA')

    def test_missing_spec_and_plan_have_reasons(self):
        files = {'alpha': {'plan.md': MANIFEST}, 'beta': {'spec.md': '# s'}, **prepared('delta')}
        items = [item(1, 'ALPHA: a'), item(2, 'BETA: b'), item(3, 'GAMMA: c'), item(4, 'DELTA: d')]
        result, _ = self.select(items=items, files=files)
        self.assertEqual(result['skipped'], [
            {'issue': 1, 'id': 'ALPHA', 'reason': 'brak spec.md'},
            {'issue': 2, 'id': 'BETA', 'reason': 'brak plan.md'},
            {'issue': 3, 'id': 'GAMMA', 'reason': 'brak spec.md i plan.md z manifestem na origin/main'},
        ])
        self.assertEqual(result['epic']['id'], 'DELTA')

    def test_unprepared_after_candidate_is_not_listed(self):
        result, _ = self.select(items=[item(1, 'ALPHA: a'), item(2, 'BETA: b')], files=prepared('alpha'))
        self.assertEqual(result['epic']['id'], 'ALPHA')
        self.assertEqual(result['skipped'], [])

    def test_plan_is_read_only_when_spec_and_plan_exist(self):
        files = {'alpha': {'plan.md': MANIFEST}, **prepared('beta')}
        _, runner = self.select(items=[item(1, 'ALPHA: a'), item(2, 'BETA: b')], files=files)
        shown = [c for c in runner.calls if c[:2] == ['git', 'show']]
        self.assertEqual(shown, [['git', 'show', 'origin/main:docs/features/beta/plan.md']])

    def test_epic_in_progress_blocks_selection(self):
        result, _ = self.select(items=[item(1, 'ALPHA: a'), item(2, 'BETA: b')], branches=['beta'],
                                files=prepared('alpha', 'beta'))
        self.assertIsNone(result['epic'])
        self.assertEqual(result['in_progress'], [{'dir': 'beta', 'id': 'BETA', 'issue': 2}])

    def test_epic_in_progress_above_candidate_blocks_too(self):
        result, _ = self.select(items=[item(1, 'ALPHA: a'), item(2, 'BETA: b')], branches=['alpha'],
                                files=prepared('alpha', 'beta'))
        self.assertIsNone(result['epic'])
        self.assertEqual([e['id'] for e in result['in_progress']], ['ALPHA'])

    def test_in_progress_keeps_skipped_above_it(self):
        items = [item(1, 'ALPHA: a'), item(2, 'BETA: b')]
        result, _ = self.select(items=items, branches=['beta'], files=prepared('beta'))
        self.assertIsNone(result['epic'])
        self.assertEqual(result['skipped'], [{'issue': 1, 'id': 'ALPHA', 'reason': 'brak spec.md i plan.md z manifestem na origin/main'}])

    def test_finished_epic_with_branch_and_closed_issue_does_not_block(self):
        result, _ = self.select(items=[item(1, 'ALPHA: a'), item(2, 'BETA: b')], open_issues=[2],
                                branches=['alpha'], files=prepared('alpha', 'beta'))
        self.assertEqual(result['epic']['id'], 'BETA')
        self.assertEqual(result['in_progress'], [])

    def test_empty_board(self):
        result, _ = self.select(items=[])
        self.assertEqual(result, {'epic': None, 'in_progress': [], 'skipped': []})

    def test_gh_failure_raises_command_error(self):
        for fail in (['gh', 'project'], ['gh', 'issue'], ['git', 'fetch'], ['git', 'ls-remote'], ['git', 'ls-tree']):
            with self.assertRaises(next_epic.CommandError, msg=fail):
                next_epic.select(FakeRunner(items=[item(1, 'A: a')], fail=fail))

    def test_git_show_failure_raises_command_error(self):
        with self.assertRaises(next_epic.CommandError):
            next_epic.select(FakeRunner(items=[item(1, 'A: a')], files=prepared('a'), fail=['git', 'show']))

    def test_invalid_json_raises_command_error(self):
        runner = FakeRunner()
        runner.__class__ = type('Bad', (FakeRunner,), {
            '__call__': lambda self, args: 'not json' if args[:3] == ['gh', 'project', 'item-list'] else
            FakeRunner.__call__(self, args)})
        with self.assertRaises(next_epic.CommandError):
            next_epic.select(runner)


SLOW = [sys.executable, '-c', 'import time; time.sleep(5)']


class RunnerTest(unittest.TestCase):
    def test_returns_stdout(self):
        run = next_epic.make_runner(None)
        self.assertEqual('ok\n', run([sys.executable, '-c', 'print("ok")']))

    def test_failure_is_a_command_error(self):
        run = next_epic.make_runner(None, timeout=10)
        with self.assertRaisesRegex(next_epic.CommandError, 'bad'):
            run([sys.executable, '-c', 'import sys; sys.exit("bad")'])

    def test_command_over_the_timeout_is_a_command_error(self):
        run = next_epic.make_runner(None, timeout=0.3)
        began = time.monotonic()
        with self.assertRaisesRegex(next_epic.CommandError, r'timed out after 0\.3 s'):
            run(SLOW)
        self.assertLess(time.monotonic() - began, 4)

    def test_no_timeout_by_default(self):
        with mock.patch.object(next_epic.subprocess, 'run', wraps=next_epic.subprocess.run) as spy:
            next_epic.make_runner(None)([sys.executable, '-c', 'pass'])
        self.assertIsNone(spy.call_args.kwargs['timeout'])


class MainTest(unittest.TestCase):
    def run_main(self, runner, argv=()):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = next_epic.main(list(argv), run=runner)
        return code, out.getvalue(), err.getvalue()

    def test_prints_one_json_line(self):
        runner = FakeRunner(items=[item(1, 'ALPHA: a')], files=prepared('alpha'))
        code, out, err = self.run_main(runner, ['--repo', '/tmp'])
        self.assertEqual(code, 0)
        self.assertEqual(err, '')
        self.assertEqual(len(out.splitlines()), 1)
        self.assertEqual(json.loads(out)['epic']['issue'], 1)

    def test_failure_gives_code_1_stderr_and_no_stdout(self):
        runner = FakeRunner(items=[item(1, 'ALPHA: a')], fail=['gh', 'issue'])
        code, out, err = self.run_main(runner)
        self.assertEqual(code, 1)
        self.assertEqual(out, '')
        self.assertIn('gh issue list', err)
        self.assertIn('boom', err)


if __name__ == '__main__':
    unittest.main()

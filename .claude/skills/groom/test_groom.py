"""Tests of groom.py: python3 -m unittest discover -s .claude/skills/groom -p 'test_*.py'"""
import contextlib
import copy
import importlib.util
import io
import json
import os
import tempfile
import unittest
from datetime import datetime, timezone

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'groom.py')
spec = importlib.util.spec_from_file_location('groom', SCRIPT)
groom = importlib.util.module_from_spec(spec)
spec.loader.exec_module(groom)

CommandError = groom.next_epic.CommandError
REPO = 'piotrp321/shop'
NOW = datetime(2026, 11, 9, 8, 0, tzinfo=timezone.utc)
MANIFEST = '# Plan\n```json\n{"schema_version": 1, "tasks": []}\n```\n'
COMMIT = 'a' * 40
T0 = '2026-11-01T10:00:00Z'
T1 = '2026-11-05T10:00:00Z'


def call(argv, run=None, now=NOW):
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        code = groom.main(argv, run=run, now=now)
    return code, out.getvalue(), err.getvalue()


def write_json(path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False)


def read_json(path):
    with open(path, encoding='utf-8') as f:
        return json.load(f)


# ---------------------------------------------------------------- collect

def gh_issue(number, labels, body='', title=None, author='piotrp321', updated=T0):
    return {'number': number, 'title': title or 'Issue %d' % number, 'body': body,
            'labels': [{'name': l} for l in labels], 'author': {'login': author}, 'updatedAt': updated}


def board_item(number, title, labels=('epic',)):
    return {'content': {'number': number, 'repository': REPO, 'title': title, 'type': 'Issue'}, 'labels': list(labels)}


class CollectRunner:
    """Answers the git and gh commands of `collect` from prepared data."""

    def __init__(self, issues=(), epics=(), branches=(), features=None, fail=None, limit_pad=0):
        self.issues = list(issues)
        self.epics = list(epics)          # [(number, title, body, labels)]
        self.branches = branches
        self.features = features or {}    # {'cart': True} directories with plan.md
        self.fail = fail
        self.calls = []
        self.limit_pad = limit_pad

    def __call__(self, args):
        args = list(args)
        self.calls.append(args)
        if self.fail and args[:len(self.fail)] == list(self.fail):
            raise CommandError(args, 'boom')
        if args[:2] == ['git', 'rev-parse']:
            return COMMIT + '\n'
        if args[:2] == ['git', 'fetch']:
            return ''
        if args[:3] == ['gh', 'project', 'item-list']:
            return json.dumps({'items': [board_item(n, t, l) for n, t, b, l in self.epics]})
        if args[:3] == ['gh', 'issue', 'list'] and '--label' in args:
            return json.dumps([{'number': n, 'title': t, 'body': b} for n, t, b, l in self.epics])
        if args[:3] == ['gh', 'issue', 'list']:
            issues = self.issues + [gh_issue(9000 + i, ['bug']) for i in range(self.limit_pad)]
            return json.dumps(issues)
        if args[:2] == ['git', 'ls-remote']:
            return ''.join('abc\trefs/heads/epic/%s\n' % b for b in self.branches)
        if args[:2] == ['git', 'ls-tree']:
            return ''.join('docs/features/%s/%s\n' % (d, f) for d in self.features for f in ('spec.md', 'plan.md'))
        if args[:2] == ['git', 'show']:
            return MANIFEST
        raise AssertionError('unexpected command %r' % args)


class CollectTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.run_dir = os.path.join(self.tmp.name, 'run')
        self.worktree = os.path.join(self.tmp.name, 'wt')
        self.state = os.path.join(self.tmp.name, 'state')
        os.makedirs(self.worktree)

    def feature(self, directory, spec_text=None):
        path = os.path.join(self.worktree, 'docs', 'features', directory)
        os.makedirs(path)
        if spec_text is not None:
            with open(os.path.join(path, 'spec.md'), 'w', encoding='utf-8') as f:
                f.write(spec_text)

    def collect(self, runner, state=None):
        code, out, err = call(['collect', self.run_dir, '--worktree', self.worktree, '--state', state or self.state],
                              run=runner)
        return code, out, err

    def input(self):
        return read_json(os.path.join(self.run_dir, 'input.json'))

    def test_label_filter_and_issues_without_kind_label(self):
        runner = CollectRunner(issues=[
            gh_issue(1, ['bug', 'agent']), gh_issue(2, ['ux']), gh_issue(3, ['tech-debt']),
            gh_issue(4, ['documentation', 'agent']), gh_issue(5, []),
            gh_issue(6, ['enhancement']), gh_issue(7, ['epic']), gh_issue(8, ['documentation', 'enhancement']),
            gh_issue(9, ['needs-info', 'bug'])])
        code, out, err = self.collect(runner)
        self.assertEqual((0, ''), (code, err))
        self.assertEqual([1, 2, 3, 4, 5, 9], [i['number'] for i in self.input()['issues']])
        self.assertEqual(1, len(out.strip().splitlines()))

    def test_issue_fields(self):
        runner = CollectRunner(issues=[gh_issue(1, ['bug', 'agent'], body='Treść ż\nwaga Critical.', title='Tytuł',
                                                author='someone', updated=T1)])
        self.collect(runner)
        issue = self.input()['issues'][0]
        self.assertEqual({'number': 1, 'title': 'Tytuł', 'body': 'Treść ż\nwaga Critical.', 'labels': ['bug', 'agent'],
                          'author': 'someone', 'updatedAt': T1, 'weight': 'Critical', 'assigned_epic': None}, issue)

    def test_commit_and_gh_arguments(self):
        runner = CollectRunner(issues=[gh_issue(1, ['bug'])])
        self.collect(runner)
        self.assertEqual(COMMIT, self.input()['commit'])
        listing = [c for c in runner.calls if c[:3] == ['gh', 'issue', 'list'] and '--label' not in c][0]
        self.assertEqual(['gh', 'issue', 'list', '--repo', REPO, '--state', 'open', '--limit', '500', '--json',
                          'number,title,body,labels,author,updatedAt'], listing)

    def test_epics_assigned_issues_scope_and_state(self):
        self.feature('cart', '# Cart\n\nIssues: #10, #11\nZakres: `apps/api/src/Cart`, apps/store/src/cart\n\n## Cel\nIssues: #99\n')
        self.feature('quality-fixes')  # a directory without spec.md: no scope
        runner = CollectRunner(
            issues=[gh_issue(11, ['bug']), gh_issue(12, ['ux']), gh_issue(13, ['bug']), gh_issue(14, ['bug']),
                    gh_issue(99, ['bug'])],
            epics=[(10, 'CART: koszyk', 'Treść', ['epic']),
                   (20, 'QUALITY-FIXES: poprawki', '## Zakres\n\nIssues #12, #13:\n\n## Poza zakresem\n\nIssue #14\n',
                    ['epic'])],
            features={'cart': True}, branches=['cart'])
        self.collect(runner)
        data = self.input()
        epics = {e['id']: e for e in data['epics']}
        self.assertEqual({'CART', 'QUALITY-FIXES'}, set(epics))
        self.assertEqual([11], epics['CART']['issues'])   # own number and the section after the header are dropped
        self.assertEqual(['apps/api/src/Cart', 'apps/store/src/cart'], epics['CART']['scope'])
        self.assertEqual('in_progress', epics['CART']['state'])
        self.assertEqual(10, epics['CART']['number'])
        self.assertEqual([12, 13], epics['QUALITY-FIXES']['issues'])  # #14 is in "Poza zakresem"
        self.assertIsNone(epics['QUALITY-FIXES']['scope'])
        assigned = {i['number']: i['assigned_epic'] for i in data['issues']}
        self.assertEqual({11: 'CART', 12: 'QUALITY-FIXES', 13: 'QUALITY-FIXES', 14: None, 99: None}, assigned)

    def test_out_writes_the_given_file(self):
        out_path = os.path.join(self.tmp.name, 'elsewhere', 'input-now.json')
        code, out, err = call(['collect', self.run_dir, '--worktree', self.worktree, '--state', self.state,
                               '--out', out_path], run=CollectRunner(issues=[gh_issue(1, ['bug'])]))
        self.assertEqual((0, ''), (code, err))
        self.assertEqual(COMMIT, read_json(out_path)['commit'])
        self.assertEqual(out_path, json.loads(out)['input'])
        self.assertFalse(os.path.exists(os.path.join(self.run_dir, 'input.json')))

    def test_issue_of_an_epic_without_id_is_assigned_by_number(self):
        runner = CollectRunner(issues=[gh_issue(12, ['bug'])], epics=[(25, 'Porządki bez prefiksu', 'Issues #12', ['epic'])])
        self.collect(runner)
        data = self.input()
        self.assertEqual('#25', data['issues'][0]['assigned_epic'])
        self.assertEqual([12], data['epics'][0]['issues'])

    def test_open_epic_not_on_the_board_is_listed(self):
        runner = CollectRunner(issues=[gh_issue(1, ['bug'])], epics=[(20, 'CATALOG-FIXES: x', '', ['epic', 'needs-info'])])
        self.collect(runner)
        epic = self.input()['epics'][0]
        self.assertEqual(('CATALOG-FIXES', 20, 'unlisted'), (epic['id'], epic['number'], epic['state']))

    def test_taken_ids_from_directories_branches_and_open_epics(self):
        self.feature('cart', '# c\n')
        self.feature('seo')
        runner = CollectRunner(issues=[], epics=[(20, 'Catalog-Fixes: x', '', ['epic'])], branches=['search'],
                               features={'cart': True})
        self.collect(runner)
        self.assertEqual(['cart', 'catalog-fixes', 'search', 'seo'], self.input()['taken_ids'])

    def test_rejected_entries_are_read(self):
        os.makedirs(self.state)
        entry = {'kind': 'obsolete', 'issues': [1], 'updated_at': {'1': T0}, 'reason': 'nie', 'rejected_at': 'x'}
        with open(os.path.join(self.state, 'rejected.jsonl'), 'w', encoding='utf-8') as f:
            f.write(json.dumps(entry) + '\n\n')
        self.collect(CollectRunner(issues=[gh_issue(1, ['bug'])]))
        self.assertEqual([entry], self.input()['rejected'])

    def test_unreadable_rejected_file_is_exit_2(self):
        os.makedirs(self.state)
        with open(os.path.join(self.state, 'rejected.jsonl'), 'w', encoding='utf-8') as f:
            f.write('{not json\n')
        code, out, err = self.collect(CollectRunner(issues=[]))
        self.assertEqual((2, ''), (code, out))
        self.assertIn('rejected.jsonl', err)

    def test_limit_reached_is_exit_1(self):
        code, out, err = self.collect(CollectRunner(issues=[], limit_pad=500))
        self.assertEqual((1, ''), (code, out))
        self.assertIn('500', err)
        self.assertFalse(os.path.exists(os.path.join(self.run_dir, 'input.json')))

    def test_limit_minus_one_is_fine(self):
        code, _, _ = self.collect(CollectRunner(issues=[], limit_pad=499))
        self.assertEqual(0, code)

    def test_gh_failure_is_exit_1(self):
        for fail in (['gh', 'issue', 'list'], ['gh', 'project'], ['git', 'rev-parse'], ['git', 'ls-remote']):
            with self.subTest(fail=fail):
                code, out, err = self.collect(CollectRunner(issues=[], fail=fail))
                self.assertEqual((1, ''), (code, out))
                self.assertIn('boom', err)

    def test_invalid_json_from_gh_is_exit_1(self):
        def run(args):
            if args[:3] == ['gh', 'issue', 'list']:
                return 'not json'
            return CollectRunner()(args)
        code, _, err = self.collect(run)
        self.assertEqual(1, code)

    def test_missing_worktree_is_exit_2(self):
        code, out, err = call(['collect', self.run_dir, '--worktree', os.path.join(self.tmp.name, 'none')],
                              run=CollectRunner())
        self.assertEqual((2, ''), (code, out))
        self.assertIn('worktree', err)


# ---------------------------------------------------------------- weight and helpers

class WeightTest(unittest.TestCase):
    def test_weights(self):
        cases = [('..., agent ux-reviewer, odtworzono: tak (2/2), waga Minor.\n', 'Minor'),
                 ('- Waga: Important (wpływ)', 'Important'),
                 ('waga critical', 'Critical'),
                 ('waga Minor i potem waga Critical', 'Critical'),
                 ('brak', None), ('', None), (None, None), ('wagą Critical', None)]
        for text, expected in cases:
            with self.subTest(text=text):
                self.assertEqual(expected, groom.weight_of(text))


# ---------------------------------------------------------------- validate

BODY = ('## Punkt „Zakresu MVP”\n\nX\n\n## Zakres\n\nIssues #1, #2, #3.\n\n## Poza zakresem\n\nY\n\n'
        '## Zależności\n\nBrak.\n\n## Uwagi do przygotowania\n\nZ\n')


def issue(number, labels=('bug',), body='', updated=T0, assigned_epic=None):
    return {'number': number, 'title': 'Issue %d' % number, 'body': body, 'labels': list(labels), 'author': 'a',
            'updatedAt': updated, 'weight': groom.weight_of(body), 'assigned_epic': assigned_epic}


def epic(id_, number, state, issues=(), scope=None):
    return {'id': id_, 'number': number, 'title': '%s: x' % id_, 'dir': id_.lower(), 'state': state,
            'issues': list(issues), 'scope': scope}


def base_input():
    return {'schema': 1, 'commit': COMMIT,
            'issues': [issue(n) for n in range(1, 8)] + [issue(20, ['documentation']), issue(21, ['bug', 'needs-info']),
                                                         issue(22, ['bug'], assigned_epic='PRICING'),
                                                         issue(23, ['bug'], body='waga Important.'),
                                                         issue(24, ['bug'], body='waga Critical.'),
                                                         issue(25, ['bug'], body='waga Minor.'),
                                                         issue(26, ['ux']), issue(27, ['tech-debt'])],
            'epics': [epic('PRICING', 301, 'prepared', [22], ['apps/api/src/Pricing']),
                      epic('VISUAL-DESIGN', 97, 'prepared', [], ['apps/api/src/Catalog', 'apps/store/src/checkout']),
                      epic('HARDENING', 267, 'in_progress', [], ['apps/store/src/checkout']),
                      epic('SEO', 266, 'unprepared', [], ['apps/store/src/catalog'])],
            'taken_ids': ['pricing', 'visual-design', 'seo'], 'rejected': []}


def fix_epic(**kwargs):
    value = {'id': 'CATALOG-FIXES', 'title': 'CATALOG-FIXES: poprawki', 'issues': [1, 2, 3],
             'scope': ['apps/api/src/Catalog'], 'body': BODY}
    value.update(kwargs)
    return value


def proposals(items=(), fix=None, **extra):
    value = {'schema': 1, 'commit': COMMIT, 'items': list(items), 'fix_epic': fix, 'overflow': [], 'hypotheses': []}
    value.update(extra)
    return value


def obsolete(n=1, issues=(20,), **kwargs):
    value = {'n': n, 'kind': 'obsolete', 'issues': list(issues), 'close_reason': 'completed', 'reason': 'Jest.',
             'evidence': ['apps/api/src/X.php:42 robi to']}
    value.update(kwargs)
    return value


def duplicate(n=1, issues=(5, 6), keep=5, **kwargs):
    value = {'n': n, 'kind': 'duplicate', 'issues': list(issues), 'keep': keep, 'reason': 'To samo.'}
    value.update(kwargs)
    return value


def needs_info(n=1, issues=(2,), **kwargs):
    value = {'n': n, 'kind': 'needs-info', 'issues': list(issues), 'missing': ['kroki odtworzenia'], 'reason': 'Brak.'}
    value.update(kwargs)
    return value


def label(n=1, issues=(20,), label_='tech-debt', **kwargs):
    value = {'n': n, 'kind': 'label', 'issues': list(issues), 'label': label_, 'needs_info': None, 'reason': 'Porządek.'}
    value.update(kwargs)
    return value


class RunDirCase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.run_dir = os.path.join(self.tmp.name, 'run')
        self.state = os.path.join(self.tmp.name, 'state')
        os.makedirs(self.run_dir)
        os.makedirs(self.state)
        self.proposals_path = os.path.join(self.run_dir, 'proposals.json')

    def prepare(self, props, data=None, rejected=()):
        write_json(os.path.join(self.run_dir, 'input.json'), data or base_input())
        if props is not None:
            write_json(self.proposals_path, props)
        with open(os.path.join(self.state, 'rejected.jsonl'), 'w', encoding='utf-8') as f:
            for entry in rejected:
                f.write(json.dumps(entry) + '\n')

    def proposals(self):
        return read_json(self.proposals_path)


class ValidateTest(RunDirCase):
    def validate(self, props, data=None, rejected=(), current=None):
        self.prepare(props, data, rejected)
        argv = ['validate', self.run_dir, '--state', self.state]
        if current is not None:
            write_json(os.path.join(self.run_dir, 'input-now.json'), current)
            argv += ['--current', os.path.join(self.run_dir, 'input-now.json')]
        code, out, err = call(argv)
        return code, (json.loads(out) if out.strip() else None), err

    def assertInvalid(self, props, fragment, data=None, rejected=(), current=None):
        code, result, err = self.validate(props, data, rejected, current)
        self.assertEqual(3, code, err)
        self.assertFalse(result['ok'])
        self.assertTrue(any(fragment in e for e in result['errors']), result['errors'])
        self.assertIn(fragment, err)
        return result

    def test_valid_list_all_kinds(self):
        data = base_input()
        data['issues'].append(issue(30, ['documentation', 'agent']))
        items = [obsolete(1, [20]), duplicate(2, [5, 6], 5), needs_info(3, [2]), label(4, [30], 'ux')]
        code, result, err = self.validate(proposals(items), data)
        self.assertEqual((0, ''), (code, err))
        self.assertEqual({'ok': True, 'errors': [], 'collisions': []}, result)

    def test_empty_list_is_valid(self):
        code, result, _ = self.validate(proposals())
        self.assertEqual((0, True), (code, result['ok']))

    def test_missing_files_are_exit_2(self):
        code, out, err = call(['validate', self.run_dir, '--state', self.state])
        self.assertEqual((2, ''), (code, out))
        self.assertIn('input.json', err)
        write_json(os.path.join(self.run_dir, 'input.json'), base_input())
        code, out, err = call(['validate', self.run_dir, '--state', self.state])
        self.assertEqual(2, code)
        self.assertIn('proposals.json', err)

    def test_invalid_json_is_a_validation_error(self):
        self.prepare(None)
        with open(self.proposals_path, 'w') as f:
            f.write('{nope')
        code, out, err = call(['validate', self.run_dir, '--state', self.state])
        self.assertEqual(3, code)
        self.assertIn('proposals.json', err)

    def test_decided_list_is_refused(self):
        self.prepare(proposals(decided_at='x'))
        code, out, err = call(['validate', self.run_dir, '--state', self.state])
        self.assertEqual((2, ''), (code, out))
        self.assertIn('rozstrzygnięta', err)

    def test_schema_commit_and_shape(self):
        self.assertInvalid(proposals(schema=2), 'schema')
        self.assertInvalid(proposals(commit='b' * 40), 'commit')
        bad = proposals()
        del bad['items']
        self.assertInvalid(bad, 'items')
        self.assertInvalid([], 'obiekt')
        self.assertInvalid(proposals(['x']), 'pozycja 1')
        self.assertInvalid(proposals(hypotheses='x'), 'hypotheses')
        self.assertInvalid(proposals(overflow=[1]), 'overflow')

    def test_numbering_kind_and_issue_numbers(self):
        self.assertInvalid(proposals([obsolete(2)]), 'n')
        self.assertInvalid(proposals([obsolete(1, kind='merge')]), 'rodzaj')
        self.assertInvalid(proposals([obsolete(1, [999])]), '999')
        self.assertInvalid(proposals([obsolete(1, [])]), 'issues')
        self.assertInvalid(proposals([obsolete(1, ['20'])]), 'issues')
        self.assertInvalid(proposals([obsolete(1, [20, 20])]), 'issues')
        self.assertInvalid(proposals([obsolete(1, reason='')]), 'reason')

    def test_limit_of_ten_items(self):
        data = base_input()
        data['issues'] = [issue(n, ['documentation']) for n in range(1, 13)]
        items = [label(n, [n]) for n in range(1, 12)]
        self.assertInvalid(proposals(items), '10', data)
        code, _, err = self.validate(proposals(items[:10]), data)
        self.assertEqual((0, ''), (code, err))

    def test_duplicate_rules(self):
        self.assertInvalid(proposals([duplicate(1, [5, 6], 7)]), 'keep')
        self.assertInvalid(proposals([duplicate(1, [5], 5)]), 'co najmniej dwóch')
        self.assertInvalid(proposals([duplicate(1, [5, 6], None)]), 'keep')

    def test_obsolete_rules(self):
        self.assertInvalid(proposals([obsolete(1, close_reason='wontfix')]), 'close_reason')
        self.assertInvalid(proposals([obsolete(1, evidence=[])]), 'evidence')
        self.assertInvalid(proposals([obsolete(1, evidence=[' '])]), 'evidence')
        code, _, err = self.validate(proposals([obsolete(1, close_reason='not planned')]))
        self.assertEqual(0, code, err)

    def test_one_issue_for_obsolete_needs_info_and_label(self):
        self.assertInvalid(proposals([obsolete(1, [20, 1])]), 'jednego issue')
        self.assertInvalid(proposals([needs_info(1, [2, 3])]), 'jednego issue')
        self.assertInvalid(proposals([label(1, [20, 1])]), 'jednego issue')

    def test_needs_info_rules(self):
        self.assertInvalid(proposals([needs_info(1, [21])]), 'needs-info')
        self.assertInvalid(proposals([needs_info(1, [2], missing=[])]), 'missing')

    def test_label_rules(self):
        self.assertInvalid(proposals([label(1, [20], 'epic')]), 'label')
        self.assertInvalid(proposals([label(1, [20], 'documentation')]), 'label')
        self.assertInvalid(proposals([label(1, [1], 'ux')]), 'etykietę rodzaju')
        for allowed in ('bug', 'ux', 'tech-debt', 'enhancement'):
            code, _, err = self.validate(proposals([label(1, [20], allowed)]))
            self.assertEqual(0, code, err)

    def test_label_with_needs_info(self):
        code, _, err = self.validate(proposals([label(1, [20], needs_info=['kroki'])]))
        self.assertEqual(0, code, err)
        self.assertInvalid(proposals([label(1, [20], needs_info=[])]), 'needs_info')
        data = base_input()
        data['issues'].append(issue(31, ['documentation', 'needs-info']))
        self.assertInvalid(proposals([label(1, [31], needs_info=['kroki'])]), 'needs-info', data)

    def test_issue_in_two_items(self):
        self.assertInvalid(proposals([obsolete(1, [20]), label(2, [20])]), '20')

    def test_keep_counts_as_used(self):
        self.assertInvalid(proposals([duplicate(1, [5, 6], 5), obsolete(2, [5])]), '5')

    def test_issue_in_item_and_fix_epic(self):
        self.assertInvalid(proposals([obsolete(1, [1])], fix_epic()), '1')

    def test_issue_assigned_to_an_open_epic(self):
        self.assertInvalid(proposals([obsolete(1, [22])]), 'PRICING')
        self.assertInvalid(proposals([label(1, [20], 'ux'), duplicate(2, [5, 22], 5)]), 'PRICING')
        data = base_input()
        data['epics'][3]['issues'] = [20]   # an epic that is not prepared still owns its issues
        self.assertInvalid(proposals([obsolete(1, [20])]), 'SEO', data)

    def test_rejected_without_change_is_error(self):
        entry = {'kind': 'obsolete', 'issues': [20], 'updated_at': {'20': T0}, 'reason': 'nie', 'rejected_at': 'x'}
        self.assertInvalid(proposals([obsolete(1, [20])]), 'odrzucon', rejected=[entry])

    def test_rejected_matches_by_set_and_kind(self):
        entry = {'kind': 'duplicate', 'issues': [6, 5], 'updated_at': {'5': T0, '6': T0}, 'reason': 'nie',
                 'rejected_at': 'x'}
        self.assertInvalid(proposals([duplicate(1, [5, 6], 6)]), 'odrzucon', rejected=[entry])
        code, _, err = self.validate(proposals([obsolete(1, [5])]), rejected=[entry])   # other kind
        self.assertEqual(0, code, err)
        code, _, err = self.validate(proposals([duplicate(1, [5, 6, 7], 5)]), rejected=[entry])   # other set
        self.assertEqual(0, code, err)

    def test_rejected_with_change_needs_previously_rejected(self):
        entry = {'kind': 'obsolete', 'issues': [20], 'updated_at': {'20': '2026-10-01T00:00:00Z'}, 'reason': 'nie',
                 'rejected_at': 'x'}
        self.assertInvalid(proposals([obsolete(1, [20])]), 'previously_rejected', rejected=[entry])
        item = obsolete(1, [20], previously_rejected={'date': '2026-10-02', 'reason': 'nie'})
        code, _, err = self.validate(proposals([item]), rejected=[entry])
        self.assertEqual(0, code, err)

    def test_previously_rejected_without_change_is_error(self):
        entry = {'kind': 'obsolete', 'issues': [20], 'updated_at': {'20': T0}, 'reason': 'nie', 'rejected_at': 'x'}
        item = obsolete(1, [20], previously_rejected={'date': '2026-10-02', 'reason': 'nie'})
        self.assertInvalid(proposals([item]), 'odrzucon', rejected=[entry])

    def test_previously_rejected_must_have_date_and_reason(self):
        for value in ('x', {'date': '2026-10-02'}, {'reason': 'r'}, {'date': '', 'reason': 'r'},
                      {'date': 'd', 'reason': ' '}, {'date': 1, 'reason': 'r'}, [], True):
            with self.subTest(value=value):
                self.assertInvalid(proposals([obsolete(1, [20], previously_rejected=value)]), 'previously_rejected')
        entry = {'kind': 'obsolete', 'issues': [20], 'updated_at': {'20': '2026-10-01T00:00:00Z'}, 'reason': 'nie',
                 'rejected_at': 'x'}
        self.assertInvalid(proposals([obsolete(1, [20], previously_rejected={'date': 'd'})]), 'previously_rejected',
                           rejected=[entry])

    def test_keep_must_be_a_real_integer(self):
        self.assertInvalid(proposals([duplicate(1, [1, 2], True)]), 'keep')

    def test_fix_epic_rejected_unchanged_is_error(self):
        entry = {'kind': 'fix_epic', 'issues': [3, 1, 2], 'updated_at': {'1': T0, '2': T0, '3': T0},
                 'reason': 'nie', 'rejected_at': 'x'}
        self.assertInvalid(self.valid_fix(), 'odrzucon', rejected=[entry])
        self.assertInvalid(self.valid_fix(previously_rejected={'date': 'd', 'reason': 'r'}), 'odrzucon',
                           rejected=[entry])

    def test_fix_epic_rejected_and_changed_needs_previously_rejected(self):
        entry = {'kind': 'fix_epic', 'issues': [1, 2, 3], 'updated_at': {'1': T0, '2': T0, '3': '2026-10-01T00:00:00Z'},
                 'reason': 'nie', 'rejected_at': 'x'}
        self.assertInvalid(self.valid_fix(), 'previously_rejected', rejected=[entry])
        code, _, err = self.validate(self.valid_fix(previously_rejected={'date': 'd', 'reason': 'r'}),
                                     rejected=[entry])
        self.assertEqual(0, code, err)

    def test_fix_epic_with_other_issue_set_is_not_rejected(self):
        entry = {'kind': 'fix_epic', 'issues': [1, 2, 4], 'updated_at': {'1': T0, '2': T0, '4': T0},
                 'reason': 'nie', 'rejected_at': 'x'}
        code, _, err = self.validate(self.valid_fix(), rejected=[entry])
        self.assertEqual(0, code, err)

    def test_issue_assigned_to_an_epic_without_id_names_its_number(self):
        data = base_input()
        data['epics'].append({'id': None, 'number': 50, 'title': 'Bez prefiksu', 'dir': None, 'state': 'invalid',
                              'issues': [20], 'scope': None})
        self.assertInvalid(proposals([obsolete(1, [20])]), '#50', data)

    # ---- --current (fresh data)

    def now_data(self):
        return base_input()

    def test_current_is_optional_and_equal_data_changes_nothing(self):
        code, _, err = self.validate(proposals([obsolete(1, [20])], fix_epic()), current=self.now_data())
        self.assertEqual(0, code, err)

    def test_current_issue_newly_assigned_to_an_epic(self):
        current = self.now_data()
        current['epics'][0]['issues'].append(20)
        code, _, err = self.validate(proposals([obsolete(1, [20])]))
        self.assertEqual(0, code, err)
        self.assertInvalid(proposals([obsolete(1, [20])]), 'PRICING', current=current)

    def test_current_new_open_fixes_epic(self):
        current = self.now_data()
        current['epics'].append(epic('CART-FIXES', 100, 'prepared', [], ['apps/api/src/Cart']))
        self.assertInvalid(self.valid_fix(), '-FIXES', current=current)

    def test_current_taken_id(self):
        current = self.now_data()
        current['taken_ids'].append('catalog-fixes')
        self.assertInvalid(self.valid_fix(), 'CATALOG-FIXES-2', current=current)
        code, _, err = self.validate(self.valid_fix(id='CATALOG-FIXES-2', title='CATALOG-FIXES-2: x'),
                                     current=current)
        self.assertEqual(0, code, err)

    def test_current_kind_label_state(self):
        current = self.now_data()
        current['issues'] = [dict(i, labels=['bug']) if i['number'] == 20 else i for i in current['issues']]
        self.assertInvalid(proposals([label(1, [20], 'ux')]), 'etykietę rodzaju', current=current)

    def test_current_needs_info_label_state(self):
        current = self.now_data()
        current['issues'] = [dict(i, labels=['bug', 'needs-info']) if i['number'] == 2 else i
                             for i in current['issues']]
        self.assertInvalid(proposals([needs_info(1, [2])]), 'needs-info', current=current)

    def test_current_collisions_use_current_epics(self):
        current = self.now_data()
        current['epics'].append(epic('NEW-ONE', 120, 'prepared', [], ['apps/api/src/Catalog']))
        code, result, err = self.validate(self.valid_fix(), current=current)
        self.assertEqual(0, code, err)
        self.assertIn('NEW-ONE', [c['epic'] for c in result['collisions']])

    def test_current_issue_closed_since_is_left_to_apply(self):
        current = self.now_data()
        current['issues'] = [i for i in current['issues'] if i['number'] != 20]
        code, _, err = self.validate(proposals([obsolete(1, [20])]), current=current)
        self.assertEqual(0, code, err)

    def test_current_file_missing_is_exit_2(self):
        self.prepare(proposals())
        code, out, err = call(['validate', self.run_dir, '--state', self.state, '--current',
                               os.path.join(self.run_dir, 'none.json')])
        self.assertEqual((2, ''), (code, out))
        self.assertIn('none.json', err)

    def test_rejected_entry_missing_an_issue_counts_as_changed(self):
        entry = {'kind': 'duplicate', 'issues': [5, 6], 'updated_at': {'5': T0}, 'reason': 'nie', 'rejected_at': 'x'}
        item = duplicate(1, [5, 6], 5, previously_rejected={'date': 'd', 'reason': 'r'})
        code, _, err = self.validate(proposals([item]), rejected=[entry])
        self.assertEqual(0, code, err)

    # ---- fix_epic

    def valid_fix(self, **kwargs):
        return proposals([], fix_epic(**kwargs))

    def test_valid_fix_epic(self):
        code, result, err = self.validate(self.valid_fix())
        self.assertEqual((0, ''), (code, err))
        self.assertTrue(result['ok'])

    def test_fix_epic_open_fixes_epic_exists(self):
        data = base_input()
        data['epics'].append(epic('CART-FIXES', 100, 'prepared', [], ['apps/api/src/Cart']))
        self.assertInvalid(self.valid_fix(), '-FIXES', data)
        data['epics'][-1]['id'] = 'CART-FIXES-2'
        self.assertInvalid(self.valid_fix(), '-FIXES', data)
        data['epics'][-1]['state'] = 'unlisted'
        self.assertInvalid(self.valid_fix(), '-FIXES', data)

    def test_fix_epic_id_from_first_module(self):
        self.assertInvalid(self.valid_fix(id='ORDERS-FIXES', title='ORDERS-FIXES: x'), 'CATALOG-FIXES')
        code, _, err = self.validate(self.valid_fix(id='CHECKOUT-FIXES', title='CHECKOUT-FIXES: x',
                                                    scope=['apps/store/src/checkout', 'apps/api/src/Catalog']))
        self.assertEqual(0, code, err)

    def test_fix_epic_id_suffix_when_taken(self):
        data = base_input()
        data['taken_ids'].append('catalog-fixes')
        self.assertInvalid(self.valid_fix(), 'CATALOG-FIXES-2', data)
        code, _, err = self.validate(self.valid_fix(id='CATALOG-FIXES-2', title='CATALOG-FIXES-2: x'), data)
        self.assertEqual(0, code, err)
        data['taken_ids'].append('catalog-fixes-2')
        self.assertInvalid(self.valid_fix(id='CATALOG-FIXES-2', title='CATALOG-FIXES-2: x'), 'CATALOG-FIXES-3', data)
        code, _, err = self.validate(self.valid_fix(id='CATALOG-FIXES-3', title='CATALOG-FIXES-3: x'), data)
        self.assertEqual(0, code, err)

    def test_fix_epic_id_matches_next_epic_pattern_and_title(self):
        self.assertInvalid(self.valid_fix(scope=['apps/api/src/Cat alog'], id='CAT ALOG-FIXES',
                                          title='CAT ALOG-FIXES: x'), 'ID')
        self.assertInvalid(self.valid_fix(title='Poprawki katalogu'), 'tytuł')
        self.assertInvalid(self.valid_fix(title='ORDERS-FIXES: x'), 'tytuł')
        self.assertInvalid(self.valid_fix(title='CATALOG-FIXES:  '), 'tytuł')

    def test_fix_epic_issue_count(self):
        self.assertInvalid(self.valid_fix(issues=[1, 2]), '3-8')
        nine = base_input()
        nine['issues'] = [issue(n) for n in range(1, 10)]
        self.assertInvalid(self.valid_fix(issues=list(range(1, 10))), '3-8', nine)
        for count in (3, 8):
            data = base_input()
            data['issues'] = [issue(n) for n in range(1, 10)]
            code, _, err = self.validate(self.valid_fix(issues=list(range(1, count + 1))), data)
            self.assertEqual(0, code, err)

    def test_fix_epic_candidates_only(self):
        self.assertInvalid(self.valid_fix(issues=[1, 2, 20]), '20')    # no kind label
        self.assertInvalid(self.valid_fix(issues=[1, 2, 21]), '21')    # needs-info
        self.assertInvalid(self.valid_fix(issues=[1, 2, 22]), '22')    # assigned to an epic
        self.assertInvalid(self.valid_fix(issues=[1, 2, 23]), '23')    # Important
        self.assertInvalid(self.valid_fix(issues=[1, 2, 24]), '24')    # Critical
        self.assertInvalid(self.valid_fix(issues=[1, 2, 999]), '999')
        self.assertInvalid(self.valid_fix(issues=[1, 2, 2]), 'issues')
        code, _, err = self.validate(self.valid_fix(issues=[25, 26, 27]))   # Minor, ux, tech-debt
        self.assertEqual(0, code, err)

    def test_fix_epic_required_fields_and_sections(self):
        self.assertInvalid(self.valid_fix(scope=[]), 'scope')
        self.assertInvalid(self.valid_fix(scope=['a/x', 'b/y', 'c/z'], id='X-FIXES', title='X-FIXES: x'), 'scope')
        self.assertInvalid(self.valid_fix(scope=['/abs']), 'scope')
        self.assertInvalid(self.valid_fix(body=BODY.replace('## Poza zakresem', '## Inne')), 'Poza zakresem')
        self.assertInvalid(self.valid_fix(body=''), 'body')
        fix = fix_epic()
        del fix['title']
        self.assertInvalid(proposals([], fix), 'title')

    def test_collisions_are_computed_and_appended(self):
        fix = fix_epic(scope=['apps/api/src/Catalog'])
        code, result, err = self.validate(proposals([], fix))
        self.assertEqual(0, code, err)
        self.assertEqual([{'epic': 'VISUAL-DESIGN', 'issue': 97, 'state': 'prepared',
                           'paths': ['apps/api/src/Catalog', 'apps/api/src/Catalog']}], result['collisions'])
        saved = self.proposals()['fix_epic']
        self.assertEqual(result['collisions'], saved['collisions'])
        depends = groom.night_queue.dependencies_section(saved['body'])
        self.assertIn('VISUAL-DESIGN', depends)
        self.assertIn('#97', depends)
        self.assertIn('Brak.', depends)
        self.assertIn('## Uwagi do przygotowania', saved['body'])

    def test_collisions_count_prepared_and_in_progress_only_with_scope_and_feature_dirs(self):
        fix = fix_epic(scope=['apps/store/src/checkout'], id='CHECKOUT-FIXES', title='CHECKOUT-FIXES: x')
        _, result, _ = self.validate(proposals([], fix))
        self.assertEqual(['VISUAL-DESIGN', 'HARDENING'], [c['epic'] for c in result['collisions']])
        self.assertEqual(['in_progress'], [c['state'] for c in result['collisions'] if c['epic'] == 'HARDENING'])
        fix = fix_epic(scope=['apps/store/src/catalog'], id='CATALOG-FIXES', title='CATALOG-FIXES: x')   # SEO unprepared
        _, result, _ = self.validate(proposals([], fix))
        self.assertEqual([], result['collisions'])
        fix = fix_epic(scope=['docs/features/hardening/spec.md'], id='SPEC.MD-FIXES', title='SPEC.MD-FIXES: x')
        _, result, _ = self.validate(proposals([], fix))
        self.assertEqual(['HARDENING'], [c['epic'] for c in result['collisions']])

    def test_revalidation_does_not_duplicate_collision_text(self):
        self.prepare(proposals([], fix_epic()))
        for _ in range(2):
            self.assertEqual(0, call(['validate', self.run_dir, '--state', self.state])[0])
        body = self.proposals()['fix_epic']['body']
        self.assertEqual(1, body.count('VISUAL-DESIGN'))

    def test_no_collisions_removes_old_block(self):
        self.prepare(proposals([], fix_epic()))
        call(['validate', self.run_dir, '--state', self.state])
        saved = self.proposals()
        saved['fix_epic']['scope'] = ['apps/api/src/Zebra']
        saved['fix_epic']['id'] = 'ZEBRA-FIXES'
        saved['fix_epic']['title'] = 'ZEBRA-FIXES: x'
        write_json(self.proposals_path, saved)
        self.assertEqual(0, call(['validate', self.run_dir, '--state', self.state])[0])
        saved = self.proposals()['fix_epic']
        self.assertEqual([], saved['collisions'])
        self.assertNotIn('VISUAL-DESIGN', saved['body'])

    def test_invalid_list_is_not_modified(self):
        fix = fix_epic(issues=[1, 2])
        self.prepare(proposals([], fix))
        before = self.proposals()
        call(['validate', self.run_dir, '--state', self.state])
        self.assertEqual(before, self.proposals())

    def test_fix_epic_null_is_fine(self):
        code, result, _ = self.validate(proposals([obsolete(1, [20])], None))
        self.assertEqual(0, code)

    def test_all_errors_are_listed_in_polish(self):
        code, result, err = self.validate(proposals([obsolete(1, [999], reason=''), label(2, [20], 'epic')]))
        self.assertEqual(3, code)
        self.assertGreaterEqual(len(result['errors']), 3)
        self.assertEqual(len(result['errors']), len(err.strip().splitlines()))


# ---------------------------------------------------------------- apply

class ApplyRunner:
    """Answers the gh commands of `apply`; records them."""

    def __init__(self, states=None, fail=None, status_fail=None, create_url=None, item_add_fail=False):
        self.states = states or {}          # {number: ('OPEN', updatedAt)} overrides input
        self.fail = fail or []              # command prefixes that fail
        self.calls = []
        self.status_fail = status_fail
        self.create_url = create_url or 'https://github.com/piotrp321/shop/issues/400'
        self.item_add_fail = item_add_fail

    def __call__(self, args):
        args = list(args)
        self.calls.append(args)
        for prefix in self.fail:
            if args[:len(prefix)] == list(prefix):
                raise CommandError(args, 'boom')
        if args[:3] == ['gh', 'issue', 'view']:
            number = int(args[3])
            state, updated = self.states.get(number, ('OPEN', T0))
            return json.dumps({'state': state, 'updatedAt': updated})
        if args[:3] == ['gh', 'issue', 'create']:
            return self.create_url + '\n'
        if args[:3] == ['gh', 'project', 'item-add']:
            return json.dumps({'id': 'ITEM1'})
        if args[:3] == ['gh', 'project', 'view']:
            return json.dumps({'id': 'PROJ1'})
        if args[:3] == ['gh', 'project', 'field-list']:
            return json.dumps({'fields': [{'id': 'F0', 'name': 'Title'},
                                          {'id': 'F1', 'name': 'Status',
                                           'options': [{'id': 'O0', 'name': 'Done'}, {'id': 'O1', 'name': 'Todo'}]}]})
        return ''

    def writes(self):
        return [c for c in self.calls if c[:3] != ['gh', 'issue', 'view'] and c[:3] not in (
            ['gh', 'project', 'view'], ['gh', 'project', 'field-list'])]


class ApplyTest(RunDirCase):
    def apply(self, props, approve, rejects=(), runner=None, data=None, rejected=(), now=NOW, current=None):
        self.prepare(props, data, rejected)
        self.runner = runner or ApplyRunner()
        argv = ['apply', self.run_dir, '--approve', approve, '--state', self.state]
        if current is not None:
            write_json(os.path.join(self.run_dir, 'input-now.json'), current)
            argv += ['--current', os.path.join(self.run_dir, 'input-now.json')]
        for r in rejects:
            argv += ['--reject', r]
        code, out, err = call(argv, run=self.runner, now=now)
        return code, (json.loads(out) if out.strip() else None), err

    def rejected_lines(self):
        path = os.path.join(self.state, 'rejected.jsonl')
        with open(path, encoding='utf-8') as f:
            return [json.loads(l) for l in f if l.strip()]

    def all_items(self):
        data = base_input()
        data['issues'].append(issue(30, ['documentation']))
        data['issues'].append(issue(31, ['documentation']))
        items = [needs_info(1, [2]), label(2, [30], 'ux'), obsolete(3, [20], close_reason='not planned',
                                                                    evidence=['docs/x.md:3 mówi inaczej', 'b']),
                 duplicate(4, [5, 6, 7], 6), label(5, [31], 'tech-debt', needs_info=['kroki', 'oczekiwany wynik'])]
        return proposals(items, fix_epic(issues=[1, 3, 4])), data

    def test_only_approved_numbers_and_order_of_kinds(self):
        props, data = self.all_items()
        code, result, err = self.apply(props, '1,2,3,4,5,6', data=data)
        self.assertEqual((0, ''), (code, err))
        kinds = []
        for c in self.runner.writes():
            if c[:3] == ['gh', 'issue', 'edit']:
                kinds.append(('edit', c[3]))
            elif c[:3] == ['gh', 'issue', 'close']:
                kinds.append(('close', c[3]))
            elif c[:3] == ['gh', 'issue', 'create']:
                kinds.append(('create',))
        # label (2, 5), needs-info (1), duplicate (4), obsolete (3), fix_epic
        self.assertEqual([('edit', '30'), ('edit', '31'), ('edit', '2'), ('close', '5'), ('close', '7'),
                          ('close', '20'), ('create',)], kinds)

    def test_only_approved_are_executed(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '3', data=data)
        self.assertEqual(0, code)
        issues = {c[3] for c in self.runner.writes() if c[:2] == ['gh', 'issue']}
        self.assertEqual({'20'}, issues)
        self.assertEqual([(3, 'done')], [(r['n'], r['status']) for r in result['results']])
        self.assertEqual(['no_decision', 'no_decision', 'done', 'no_decision', 'no_decision'],
                         [i['result']['status'] for i in self.proposals()['items']])

    def test_all(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, 'all', data=data)
        self.assertEqual(0, code)
        self.assertEqual(['done'] * 6, [r['status'] for r in result['results']])

    def test_exact_commands_label(self):
        props, data = self.all_items()
        self.apply(props, '2', data=data)
        self.assertEqual([['gh', 'issue', 'edit', '30', '--repo', REPO, '--add-label', 'ux']], self.runner.writes())

    def test_exact_commands_label_with_needs_info(self):
        props, data = self.all_items()
        self.apply(props, '5', data=data)
        writes = self.runner.writes()
        self.assertEqual(['gh', 'issue', 'comment', '31', '--repo', REPO, '--body'], writes[0][:7])
        self.assertIn('- kroki', writes[0][7])
        self.assertIn('- oczekiwany wynik', writes[0][7])
        self.assertEqual(['gh', 'issue', 'edit', '31', '--repo', REPO, '--add-label', 'tech-debt', '--add-label',
                          'needs-info'], writes[1])

    def test_exact_commands_needs_info(self):
        props, data = self.all_items()
        self.apply(props, '1', data=data)
        writes = self.runner.writes()
        self.assertEqual(2, len(writes))
        self.assertEqual(['gh', 'issue', 'comment', '2', '--repo', REPO, '--body'], writes[0][:7])
        self.assertIn('- kroki odtworzenia', writes[0][7])
        self.assertEqual(['gh', 'issue', 'edit', '2', '--repo', REPO, '--add-label', 'needs-info'], writes[1])

    def test_exact_commands_duplicate_use_keep(self):
        props, data = self.all_items()
        self.apply(props, '4', data=data)
        writes = self.runner.writes()
        self.assertEqual(4, len(writes))
        self.assertEqual(['gh', 'issue', 'comment', '5', '--repo', REPO, '--body'], writes[0][:7])
        self.assertIn('#6', writes[0][7])
        self.assertIn('To samo.', writes[0][7])
        self.assertEqual(['gh', 'issue', 'close', '5', '--repo', REPO, '--duplicate-of', '6'], writes[1])
        self.assertEqual(['gh', 'issue', 'comment', '7', '--repo', REPO, '--body'], writes[2][:7])
        self.assertEqual(['gh', 'issue', 'close', '7', '--repo', REPO, '--duplicate-of', '6'], writes[3])
        self.assertFalse([c for c in writes if c[3] == '6'])

    def test_exact_commands_obsolete_reasons(self):
        props, data = self.all_items()
        self.apply(props, '3', data=data)
        writes = self.runner.writes()
        self.assertEqual(['gh', 'issue', 'comment', '20', '--repo', REPO, '--body'], writes[0][:7])
        self.assertIn('docs/x.md:3 mówi inaczej', writes[0][7])
        self.assertIn('Dowód (origin/main %s)' % COMMIT, writes[0][7])
        self.assertEqual(['gh', 'issue', 'close', '20', '--repo', REPO, '--reason', 'not planned'], writes[1])
        props, data = self.all_items()
        props['items'][2]['close_reason'] = 'completed'
        self.apply(props, '3', data=data)
        self.assertEqual(['gh', 'issue', 'close', '20', '--repo', REPO, '--reason', 'completed'],
                         self.runner.writes()[1])

    def test_fix_epic_commands_and_result(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '6', data=data)
        self.assertEqual(0, code)
        fix = props['fix_epic']
        self.assertEqual(['gh', 'issue', 'create', '--repo', REPO, '--title', fix['title'], '--body', fix['body'],
                          '--label', 'epic'], self.runner.writes()[0])
        self.assertEqual(['gh', 'project', 'item-add', '1', '--owner', 'piotrp321', '--url',
                          'https://github.com/piotrp321/shop/issues/400', '--format', 'json'], self.runner.writes()[1])
        self.assertEqual(['gh', 'project', 'item-edit', '--id', 'ITEM1', '--project-id', 'PROJ1', '--field-id', 'F1',
                          '--single-select-option-id', 'O1'], self.runner.writes()[2])
        row = result['results'][0]
        self.assertEqual((6, 'fix_epic', 'done'), (row['n'], row['kind'], row['status']))
        self.assertEqual(['https://github.com/piotrp321/shop/issues/400'], row['urls'])
        self.assertNotIn('manual', row)

    def test_fix_epic_by_word(self):
        props, data = self.all_items()
        self.apply(props, 'epik', data=data)
        self.assertEqual(['gh', 'issue', 'create'], self.runner.writes()[0][:3])

    def test_fix_epic_without_board_gives_manual_command(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '6', data=data, runner=ApplyRunner(fail=[('gh', 'project', 'item-add')]))
        row = result['results'][0]
        self.assertEqual('done', row['status'])
        self.assertEqual('gh project item-add 1 --owner piotrp321 --url https://github.com/piotrp321/shop/issues/400',
                         row['manual'])
        self.assertEqual(['https://github.com/piotrp321/shop/issues/400'], row['urls'])
        self.assertEqual(0, code)

    def test_fix_epic_status_failure_gives_manual_item_edit(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '6', data=data, runner=ApplyRunner(fail=[('gh', 'project', 'item-edit')]))
        row = result['results'][0]
        self.assertEqual('done', row['status'])
        self.assertEqual('gh project item-edit --id ITEM1 --project-id PROJ1 --field-id F1 '
                         '--single-select-option-id O1', row['manual'])

    def test_fix_epic_status_lookup_failure_gives_manual_instruction(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '6', data=data, runner=ApplyRunner(fail=[('gh', 'project', 'field-list')]))
        row = result['results'][0]
        self.assertEqual('done', row['status'])
        self.assertNotIn('manual', row)
        self.assertIn('ustaw Status=Todo na tablicy ręcznie', row['detail'])
        self.assertEqual(['https://github.com/piotrp321/shop/issues/400'], row['urls'])

    def test_fix_epic_create_failure_is_error_without_manual(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '6', data=data, runner=ApplyRunner(fail=[('gh', 'issue', 'create')]))
        row = result['results'][0]
        self.assertEqual('error', row['status'])
        self.assertNotIn('manual', row)

    def test_closed_or_changed_issues_are_skipped(self):
        props, data = self.all_items()
        runner = ApplyRunner(states={30: ('CLOSED', T0), 5: ('OPEN', T1)})
        code, result, _ = self.apply(props, '2,4,3', data=data, runner=runner)
        self.assertEqual(0, code)
        by = {r['n']: r for r in result['results']}
        self.assertEqual(('skipped', 'zmienione od przeglądu'), (by[2]['status'], by[2]['detail']))
        self.assertEqual('skipped', by[4]['status'])    # the changed one is a member of the duplicate group
        self.assertEqual('done', by[3]['status'])
        self.assertEqual({'20'}, {c[3] for c in self.runner.writes()})
        self.assertEqual([], self.rejected_lines())

    def test_keep_issue_is_checked_too(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '4', data=data, runner=ApplyRunner(states={6: ('CLOSED', T0)}))
        self.assertEqual('skipped', result['results'][0]['status'])
        self.assertEqual([], self.runner.writes())

    def test_fix_epic_issues_are_checked(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '6', data=data, runner=ApplyRunner(states={3: ('OPEN', T1)}))
        self.assertEqual('skipped', result['results'][0]['status'])
        self.assertEqual([], self.runner.writes())

    def test_view_failure_skips_the_item_with_an_error(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '2,3', data=data, runner=ApplyRunner(fail=[('gh', 'issue', 'view', '30')]))
        by = {r['n']: r for r in result['results']}
        self.assertEqual('error', by[2]['status'])
        self.assertEqual('done', by[3]['status'])

    def test_error_of_one_item_does_not_stop_the_rest_and_is_not_rejected(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '1,2,3,4,5,6', data=data,
                                     runner=ApplyRunner(fail=[('gh', 'issue', 'edit', '30')]))
        self.assertEqual(0, code)
        statuses = {r['n']: r['status'] for r in result['results']}
        self.assertEqual({1: 'done', 2: 'error', 3: 'done', 4: 'done', 5: 'done', 6: 'done'}, statuses)
        self.assertIn('boom', [r for r in result['results'] if r['n'] == 2][0]['detail'])
        self.assertEqual(1, result['errors'])
        self.assertEqual([], self.rejected_lines())

    def test_a_failed_step_stops_that_item_only(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '4', data=data, runner=ApplyRunner(fail=[('gh', 'issue', 'comment', '5')]))
        self.assertEqual('error', result['results'][0]['status'])
        self.assertEqual([], [c for c in self.runner.writes() if c[:3] == ['gh', 'issue', 'close']])

    def test_rejections_are_recorded(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, '3', ['1=nie teraz', '6=za wcześnie', '4=ż'], data=data)
        self.assertEqual(0, code)
        lines = self.rejected_lines()
        self.assertEqual([('needs-info', [2], 'nie teraz'), ('duplicate', [5, 6, 7], 'ż'),
                          ('fix_epic', [1, 3, 4], 'za wcześnie')],
                         [(l['kind'], l['issues'], l['reason']) for l in lines])
        self.assertEqual({'2': T0}, lines[0]['updated_at'])
        self.assertEqual({'5': T0, '6': T0, '7': T0}, lines[1]['updated_at'])
        self.assertEqual(NOW.isoformat(), lines[0]['rejected_at'])
        by = {r['n']: r for r in result['results']}
        self.assertEqual('rejected', by[1]['status'])
        self.assertNotIn(2, by)
        self.assertEqual('no_decision', self.proposals()['items'][1]['result']['status'])
        self.assertEqual({'20'}, {c[3] for c in self.runner.writes()})

    def test_rejections_are_appended(self):
        props, data = self.all_items()
        old = {'kind': 'obsolete', 'issues': [9], 'updated_at': {}, 'reason': 'r', 'rejected_at': 'x'}
        self.apply(props, 'none', ['1=a'], data=data, rejected=[old])
        self.assertEqual(2, len(self.rejected_lines()))
        self.assertEqual(old, self.rejected_lines()[0])

    def test_reject_by_word_epik(self):
        props, data = self.all_items()
        self.apply(props, '1', ['epik=nie'], data=data)
        self.assertEqual('fix_epic', self.rejected_lines()[0]['kind'])

    def test_decided_at_and_results_are_saved(self):
        props, data = self.all_items()
        self.apply(props, '3,6', ['1=a'], data=data)
        saved = self.proposals()
        self.assertEqual(NOW.isoformat(), saved['decided_at'])
        self.assertEqual('done', saved['items'][2]['result']['status'])
        self.assertEqual('rejected', saved['items'][0]['result']['status'])
        self.assertEqual('no_decision', saved['items'][1]['result']['status'])
        self.assertEqual('done', saved['fix_epic']['result']['status'])
        self.assertEqual('https://github.com/piotrp321/shop/issues/400', saved['fix_epic']['result']['urls'][0])

    def test_second_apply_is_refused_with_code_2(self):
        props, data = self.all_items()
        self.apply(props, '3', data=data)
        runner = ApplyRunner()
        code, out, err = call(['apply', self.run_dir, '--approve', '3', '--state', self.state], run=runner)
        self.assertEqual((2, ''), (code, out))
        self.assertIn('rozstrzygnięta', err)
        self.assertEqual([], runner.calls)

    def test_decided_list_from_expire_is_refused(self):
        props, data = self.all_items()
        props['decided_at'] = 'x'
        code, result, err = self.apply(props, '3', data=data)
        self.assertEqual(2, code)
        self.assertEqual([], self.runner.calls)

    def test_decided_at_is_written_before_the_first_write(self):
        props, data = self.all_items()
        seen = []
        path = self.proposals_path

        class Spy(ApplyRunner):
            def __call__(spy, args):
                if args[:3] not in (['gh', 'issue', 'view'], ['gh', 'project', 'view'], ['gh', 'project', 'field-list']):
                    seen.append(read_json(path).get('decided_at'))
                return super().__call__(args)

        self.apply(props, '3,2', data=data, runner=Spy())
        self.assertTrue(seen)
        self.assertEqual({NOW.isoformat()}, set(seen))

    def test_current_data_can_refuse_the_list(self):
        props, data = self.all_items()
        current = copy.deepcopy(data)
        current['epics'].append(epic('PRICING', 301, 'prepared', [20], ['apps/api/src/Pricing']))
        code, _, err = self.apply(props, '3', data=data, current=current)
        self.assertEqual(3, code)
        self.assertIn('PRICING', err)
        self.assertEqual([], self.runner.calls)
        self.assertNotIn('decided_at', self.proposals())

    def test_current_data_does_not_change_the_updated_at_guard(self):
        props, data = self.all_items()
        current = copy.deepcopy(data)
        for i in current['issues']:
            i['updatedAt'] = T1     # the guard compares gh with the original input.json (T0)
        code, result, _ = self.apply(props, '3', data=data, current=current)
        self.assertEqual((0, 'done'), (code, result['results'][0]['status']))

    def test_invalid_list_is_refused_with_code_3(self):
        props, data = self.all_items()
        props['items'][0]['reason'] = ''
        code, result, err = self.apply(props, 'all', data=data)
        self.assertEqual(3, code)
        self.assertEqual([], self.runner.calls)
        self.assertNotIn('decided_at', self.proposals())

    def test_bad_arguments_are_exit_2_without_changes(self):
        props, data = self.all_items()
        for approve, rejects in [('9', []), ('x', []), ('1', ['1=a']), ('1', ['9=a']), ('1', ['abc']), ('1,1', []),
                                 ('', [])]:
            with self.subTest(approve=approve, rejects=rejects):
                code, _, err = self.apply(props, approve, rejects, data=data)
                self.assertEqual(2, code, err)
                self.assertEqual([], self.runner.calls)
                self.assertNotIn('decided_at', self.proposals())

    def test_fix_epic_number_is_last_item_plus_one(self):
        props, data = self.all_items()
        code, _, err = self.apply(props, '7', data=data)
        self.assertEqual(2, code)   # the list has items 1..5 and the epic is 6
        props = proposals([label(1, [30], 'ux')], None)
        code, _, _ = self.apply(props, '2', data=data)
        self.assertEqual(2, code)   # no epic: 2 does not exist

    def test_empty_approve_none_only_rejects(self):
        props, data = self.all_items()
        code, result, _ = self.apply(props, 'none', ['2=a'], data=data)
        self.assertEqual(0, code)
        self.assertEqual([], self.runner.writes())
        self.assertEqual(1, len(self.rejected_lines()))

    def test_polish_text_is_passed_intact(self):
        props, data = self.all_items()
        props['items'][2]['evidence'] = ['apps/api/src/Zażółć.php:1 gęślą jaźń']
        self.apply(props, '3', data=data)
        self.assertIn('gęślą jaźń', self.runner.writes()[0][7])

    def test_state_directory_is_created(self):
        props, data = self.all_items()
        self.prepare(props, data)
        state = os.path.join(self.tmp.name, 'deep', 'state')
        code, _, _ = call(['apply', self.run_dir, '--approve', 'none', '--reject', '1=a', '--state', state],
                          run=ApplyRunner())
        self.assertEqual(0, code)
        self.assertTrue(os.path.exists(os.path.join(state, 'rejected.jsonl')))


# ---------------------------------------------------------------- expire and fail

class CloseCommandsTest(RunDirCase):
    def test_expire(self):
        self.prepare(proposals([label()]))
        code, out, err = call(['expire', self.run_dir], now=NOW)
        self.assertEqual((0, ''), (code, err))
        saved = self.proposals()
        self.assertEqual(NOW.isoformat(), saved['decided_at'])
        self.assertEqual('wygasła', saved['result'])
        self.assertEqual(1, len(saved['items']))
        self.assertEqual({'decided_at': NOW.isoformat(), 'result': 'wygasła'}, json.loads(out))

    def test_expire_refuses_decided_or_missing(self):
        self.prepare(proposals(decided_at='x'))
        code, out, err = call(['expire', self.run_dir])
        self.assertEqual((2, ''), (code, out))
        self.assertIn('rozstrzygnięta', err)
        os.remove(self.proposals_path)
        code, out, err = call(['expire', self.run_dir])
        self.assertEqual((2, ''), (code, out))
        self.assertIn('proposals.json', err)

    def test_expire_refuses_invalid_json(self):
        with open(self.proposals_path, 'w') as f:
            f.write('{nope')
        self.assertEqual(2, call(['expire', self.run_dir])[0])

    def test_fail_on_valid_list(self):
        self.prepare(proposals([label()]))
        code, out, err = call(['fail', self.run_dir], now=NOW)
        self.assertEqual((0, ''), (code, err))
        saved = self.proposals()
        self.assertEqual(('błąd walidacji', NOW.isoformat()), (saved['result'], saved['decided_at']))
        self.assertEqual(1, len(saved['items']))

    def test_fail_empty(self):
        self.prepare(proposals())
        call(['fail', self.run_dir, '--empty'], now=NOW)
        self.assertEqual('brak propozycji', self.proposals()['result'])

    def test_fail_with_missing_or_invalid_file_writes_minimal(self):
        code, _, _ = call(['fail', self.run_dir], now=NOW)
        self.assertEqual(0, code)
        self.assertEqual({'schema': 1, 'decided_at': NOW.isoformat(), 'result': 'błąd walidacji'}, self.proposals())
        os.remove(self.proposals_path)
        with open(self.proposals_path, 'w') as f:
            f.write('{nope')
        call(['fail', self.run_dir, '--empty'], now=NOW)
        self.assertEqual({'schema': 1, 'decided_at': NOW.isoformat(), 'result': 'brak propozycji'}, self.proposals())

    def test_fail_refuses_decided(self):
        self.prepare(proposals(decided_at='x'))
        code, out, err = call(['fail', self.run_dir])
        self.assertEqual((2, ''), (code, out))
        self.assertEqual('x', self.proposals()['decided_at'])

    def test_missing_run_dir_is_exit_2(self):
        code, out, err = call(['fail', os.path.join(self.tmp.name, 'none')])
        self.assertEqual(2, code)


class CliTest(unittest.TestCase):
    def test_usage_errors_are_exit_2(self):
        for argv in ([], ['bogus'], ['collect'], ['apply', 'x']):
            with self.subTest(argv=argv):
                code, out, _ = call(argv)
                self.assertEqual((2, ''), (code, out))


if __name__ == '__main__':
    unittest.main()

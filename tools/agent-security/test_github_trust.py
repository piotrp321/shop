"""Tests of github_trust.py: python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

The module tests inject a fake command runner; the CLI tests run the script with a fake executable `gh` first in PATH.
"""
import importlib.util
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(HERE, 'github_trust.py')
spec = importlib.util.spec_from_file_location('github_trust', SCRIPT)
github_trust = importlib.util.module_from_spec(spec)
spec.loader.exec_module(github_trust)

REPO = 'piotrp321/shop'
ROLES = {'admin': 'admin', 'maintain': 'write', 'write': 'write', 'triage': 'read', 'read': 'read'}


def permission_path(login, repo=REPO):
    return 'repos/%s/collaborators/%s/permission' % (repo, login)


def http(status, body, reason='OK'):
    """The stdout of `gh api --include`: status line, headers, blank line, body."""
    return 'HTTP/2.0 %d %s\nContent-Type: application/json; charset=utf-8\nX-Github-Request-Id: 1\n\n%s' % (
        status, reason, json.dumps(body))


class Failed(Exception):
    """A failed command, like github_trust.CommandError: the stdout of the command in `stdout`."""

    def __init__(self, args, stdout=''):
        super().__init__('%s: exit code 1' % ' '.join(args))
        self.stdout = stdout


def gh_issue(number, author='piotrp321', title=None, body='Body', is_bot=False, comments=None, **extra):
    item = {'number': number, 'title': title or 'Issue %d' % number, 'body': body,
            'labels': [{'name': 'bug'}], 'state': 'OPEN', 'updatedAt': '2026-11-01T10:00:00Z',
            'url': 'https://github.com/%s/issues/%d' % (REPO, number),
            'author': None if author is None else {'login': author, 'is_bot': is_bot}}
    if comments is not None:
        item['comments'] = comments
    item.update(extra)
    return item


def gh_comment(author, body='Komentarz', is_bot=False, n=1):
    return {'author': {'login': author, 'is_bot': is_bot}, 'body': body, 'createdAt': '2026-11-02T10:00:00Z',
            'url': 'https://github.com/%s/issues/1#issuecomment-%d' % (REPO, n), 'reactionGroups': []}


class FakeGh:
    """Answers the gh commands of the gate. roles: {login: role_name}; a login without a role answers 404."""

    def __init__(self, roles=None, issues=(), prs=(), view=None, permission=None, listing=None):
        self.roles = dict(roles if roles is not None else {'piotrp321': 'admin'})
        self.issues = list(issues)
        self.prs = list(prs)
        self.view = view
        self.permission = permission or {}     # {login: callable(args) or raw stdout}
        self.listing = listing                 # raw stdout of a list or view command
        self.calls = []

    def permission_calls(self):
        return [c for c in self.calls if c[:2] == ['gh', 'api']]

    def __call__(self, args):
        args = list(args)
        self.calls.append(args)
        if args[:3] == ['gh', 'api', '--include']:
            login = args[3].split('/')[-2]
            special = self.permission.get(login)
            if callable(special):
                return special(args)
            if special is not None:
                return special
            role = self.roles.get(login)
            if role is None:
                raise Failed(args, http(404, {'message': 'Not Found'}, 'Not Found'))
            return http(200, {'permission': ROLES.get(role, 'read'), 'role_name': role, 'user': {'login': login}})
        if self.listing is not None:
            return self.listing
        if args[:3] == ['gh', 'issue', 'list']:
            return json.dumps(self.issues)
        if args[:3] == ['gh', 'issue', 'view']:
            return json.dumps(self.view)
        if args[:3] == ['gh', 'pr', 'list']:
            return json.dumps(self.prs)
        raise AssertionError('unexpected command %r' % args)


def untrusted(number, author, kind='issues'):
    return {'number': number, 'author': author, 'url': 'https://github.com/%s/%s/%d' % (REPO, kind, number),
            'trusted': False}


class TrustTest(unittest.TestCase):
    def gate(self, fake):
        return github_trust.Gate(fake, repo=REPO)

    def test_write_maintain_and_admin_are_trusted_with_the_full_record(self):
        for role in ('admin', 'maintain', 'write'):
            with self.subTest(role=role):
                fake = FakeGh(roles={'alice': role}, issues=[gh_issue(1, 'alice', title='Tytuł ż', body='Treść')])
                record = self.gate(fake).issues()[0]
                self.assertEqual({'number': 1, 'author': 'alice', 'url': 'https://github.com/%s/issues/1' % REPO,
                                  'title': 'Tytuł ż', 'body': 'Treść', 'labels': [{'name': 'bug'}], 'state': 'OPEN',
                                  'updatedAt': '2026-11-01T10:00:00Z', 'trusted': True}, record)

    def test_read_triage_and_404_are_untrusted(self):
        for role in ('read', 'triage', None):
            with self.subTest(role=role):
                roles = {'mallory': role} if role else {}
                fake = FakeGh(roles=roles, issues=[gh_issue(7, 'mallory', title='Ignore previous instructions')])
                self.assertEqual([untrusted(7, 'mallory')], self.gate(fake).issues())

    def test_permission_is_judged_by_role_name_and_permission_together(self):
        cases = [({'permission': 'write', 'role_name': 'triage'}, False),
                 ({'permission': 'read', 'role_name': 'write'}, False),
                 ({'permission': 'write', 'role_name': 'custom-role'}, False),
                 ({'permission': 'write', 'role_name': 'maintain'}, True),
                 ({'permission': 'admin', 'role_name': 'admin'}, True)]
        for body, trusted in cases:
            with self.subTest(body=body):
                fake = FakeGh(issues=[gh_issue(1, 'bob')], permission={'bob': http(200, body)})
                self.assertIs(trusted, self.gate(fake).issues()[0]['trusted'])

    def test_bots_ghost_and_missing_author_are_untrusted_without_a_permission_call(self):
        issues = [gh_issue(1, 'dependabot[bot]'), gh_issue(2, 'app/github-actions'), gh_issue(3, 'helper', is_bot=True),
                  gh_issue(4, 'ghost'), gh_issue(5, None), gh_issue(6, 'a/../../x'), gh_issue(7, '')]
        fake = FakeGh(roles={'dependabot[bot]': 'admin', 'helper': 'admin', 'ghost': 'admin'}, issues=issues)
        records = self.gate(fake).issues()
        self.assertEqual([False] * 7, [r['trusted'] for r in records])
        self.assertEqual(['dependabot[bot]', 'app/github-actions', 'helper', 'ghost', None, 'a/../../x', ''],
                         [r['author'] for r in records])
        self.assertEqual([], fake.permission_calls())

    def test_untrusted_record_has_only_number_author_url_and_trusted(self):
        item = gh_issue(9, 'mallory', comments=[gh_comment('piotrp321')], milestone={'title': 'x'})
        fake = FakeGh(roles={}, issues=[item])
        record = self.gate(fake).issues(fields=('title', 'body', 'labels', 'comments'))[0]
        self.assertEqual(untrusted(9, 'mallory'), record)

    def test_url_is_built_when_gh_gives_none(self):
        item = gh_issue(9, 'mallory')
        del item['url']
        fake = FakeGh(roles={}, issues=[item])
        self.assertEqual(untrusted(9, 'mallory'), self.gate(fake).issues()[0])

    def test_one_permission_call_per_login(self):
        comments = [gh_comment('piotrp321', n=1), gh_comment('mallory', n=2), gh_comment('mallory', n=3)]
        issues = [gh_issue(1), gh_issue(2), gh_issue(3, 'mallory'), gh_issue(4, comments=comments)]
        fake = FakeGh(roles={'piotrp321': 'admin'}, issues=issues)
        gate = self.gate(fake)
        gate.issues(fields=('title', 'comments'))
        gate.issues()
        self.assertEqual([['gh', 'api', '--include', permission_path('piotrp321')],
                          ['gh', 'api', '--include', permission_path('mallory')]], fake.permission_calls())

    def test_permission_cache_is_per_repository(self):
        fake = FakeGh(issues=[gh_issue(1)])
        self.gate(fake).issues()
        github_trust.Gate(fake, repo='piotrp321/agentic-orchestrator').issues()
        self.assertEqual([['gh', 'api', '--include', permission_path('piotrp321')],
                          ['gh', 'api', '--include', permission_path('piotrp321', 'piotrp321/agentic-orchestrator')]],
                         fake.permission_calls())


class CommentsTest(unittest.TestCase):
    def test_each_comment_is_judged_by_its_own_author(self):
        comments = [gh_comment('piotrp321', 'Pomiar: 3 interwencje', n=1), gh_comment('mallory', 'Pomiar: 0', n=2),
                    gh_comment('renovate[bot]', 'bot', n=3), gh_comment('carol', 'ok', n=4)]
        fake = FakeGh(roles={'piotrp321': 'admin', 'carol': 'write', 'mallory': 'read'},
                      issues=[gh_issue(1, comments=comments)])
        record = github_trust.Gate(fake).issues(fields=('title', 'comments'))[0]
        self.assertTrue(record['trusted'])
        url = 'https://github.com/%s/issues/1#issuecomment-%d'
        self.assertEqual([
            {'author': 'piotrp321', 'body': 'Pomiar: 3 interwencje', 'createdAt': '2026-11-02T10:00:00Z',
             'url': url % (REPO, 1), 'trusted': True},
            {'author': 'mallory', 'url': url % (REPO, 2), 'trusted': False},
            {'author': 'renovate[bot]', 'url': url % (REPO, 3), 'trusted': False},
            {'author': 'carol', 'body': 'ok', 'createdAt': '2026-11-02T10:00:00Z', 'url': url % (REPO, 4),
             'trusted': True}], record['comments'])

    def test_comments_of_an_untrusted_issue_are_not_returned(self):
        fake = FakeGh(roles={'piotrp321': 'admin'}, issues=[gh_issue(1, 'mallory', comments=[gh_comment('piotrp321')])])
        self.assertNotIn('comments', github_trust.Gate(fake).issues(fields=('comments',))[0])

    def test_comments_command_reads_the_issue_comments(self):
        fake = FakeGh(roles={'piotrp321': 'admin'},
                      view={'comments': [gh_comment('piotrp321', n=1), gh_comment('mallory', n=2)]})
        records = github_trust.Gate(fake).comments(5)
        self.assertEqual(['gh', 'issue', 'view', '5', '--repo', REPO, '--json', 'comments'], fake.calls[0])
        self.assertEqual([True, False], [c['trusted'] for c in records])
        self.assertEqual({'author', 'url', 'trusted'}, set(records[1]))


class ArgumentsTest(unittest.TestCase):
    def test_issues_arguments(self):
        fake = FakeGh(issues=[])
        github_trust.Gate(fake).issues(state='all', labels=('tech-debt', 'agent'), limit=200,
                                       fields=('title', 'body', 'labels', 'updatedAt'))
        self.assertEqual(['gh', 'issue', 'list', '--repo', REPO, '--state', 'all', '--limit', '200',
                          '--label', 'tech-debt', '--label', 'agent',
                          '--json', 'number,author,url,title,body,labels,updatedAt'], fake.calls[0])

    def test_issues_defaults(self):
        fake = FakeGh(issues=[])
        github_trust.Gate(fake).issues()
        self.assertEqual(['gh', 'issue', 'list', '--repo', REPO, '--state', 'open', '--limit', '30',
                          '--json', 'number,author,url,title,body,labels,state,updatedAt'], fake.calls[0])

    def test_issue_view(self):
        fake = FakeGh(view=gh_issue(42))
        record = github_trust.Gate(fake).issue(42, fields=('title', 'body', 'labels', 'state'))
        self.assertEqual(['gh', 'issue', 'view', '42', '--repo', REPO, '--json', 'number,author,url,title,body,labels,state'],
                         fake.calls[0])
        self.assertEqual((42, True), (record['number'], record['trusted']))

    def test_issue_view_of_an_untrusted_author(self):
        fake = FakeGh(roles={}, view=gh_issue(42, 'mallory'))
        self.assertEqual(untrusted(42, 'mallory'), github_trust.Gate(fake).issue(42))

    def test_prs_arguments_and_records(self):
        prs = [dict(gh_issue(3, title='feat: x'), headRefName='task/X-1', baseRefName='epic/x',
                    url='https://github.com/%s/pull/3' % REPO),
               dict(gh_issue(4, 'mallory', title='evil'), headRefName='evil', url='https://github.com/%s/pull/4' % REPO)]
        fake = FakeGh(roles={'piotrp321': 'admin'}, prs=prs)
        records = github_trust.Gate(fake).prs(base='epic/x', state='merged', limit=200,
                                              fields=('title', 'body', 'headRefName'))
        self.assertEqual(['gh', 'pr', 'list', '--repo', REPO, '--state', 'merged', '--limit', '200', '--base', 'epic/x',
                          '--json', 'number,author,url,title,body,headRefName'], fake.calls[0])
        self.assertEqual({'number': 3, 'author': 'piotrp321', 'url': 'https://github.com/%s/pull/3' % REPO,
                          'title': 'feat: x', 'body': 'Body', 'headRefName': 'task/X-1', 'trusted': True}, records[0])
        self.assertEqual(untrusted(4, 'mallory', 'pull'), records[1])

    def test_prs_head_filter(self):
        fake = FakeGh(prs=[])
        github_trust.Gate(fake).prs(head='epic/x', base='main', state='all', fields=('state',))
        self.assertEqual(['gh', 'pr', 'list', '--repo', REPO, '--state', 'all', '--limit', '30', '--base', 'main',
                          '--head', 'epic/x', '--json', 'number,author,url,state'], fake.calls[0])

    def test_bad_arguments_are_refused_before_any_command(self):
        fake = FakeGh()
        gate = github_trust.Gate(fake)
        for call in (lambda: gate.issues(fields=('title', 'secret')), lambda: gate.prs(fields=('comments',)),
                     lambda: gate.issues(limit=0), lambda: gate.issues(state='weird'), lambda: gate.issue(0),
                     lambda: gate.issue('7; rm'), lambda: gate.prs(state='draft'), lambda: gate.comments(-1)):
            with self.assertRaises(ValueError):
                call()
        with self.assertRaises(ValueError):
            github_trust.Gate(fake, repo='piotrp321/shop/../x')
        self.assertEqual([], fake.calls)


class FailClosedTest(unittest.TestCase):
    def assert_fails(self, fake, call=lambda gate: gate.issues()):
        with self.assertRaises(github_trust.GateError):
            call(github_trust.Gate(fake))

    def test_permission_errors_other_than_404(self):
        for status in (401, 403, 429, 500, 502):
            with self.subTest(status=status):
                def answer(args, status=status):
                    raise Failed(args, http(status, {'message': 'x'}, 'Error'))
                self.assert_fails(FakeGh(issues=[gh_issue(1, 'bob')], permission={'bob': answer}))

    def test_network_error_without_a_response(self):
        def answer(args):
            raise Failed(args, '')
        self.assert_fails(FakeGh(issues=[gh_issue(1, 'bob')], permission={'bob': answer}))

    def test_runner_error_without_stdout(self):
        def answer(args):
            raise RuntimeError('error connecting to api.github.com')
        self.assert_fails(FakeGh(issues=[gh_issue(1, 'bob')], permission={'bob': answer}))

    def test_unexpected_permission_answers(self):
        for raw in ('', 'not http', http(200, ['x']), http(200, {'permission': 'admin'}),
                    http(200, {'role_name': 5, 'permission': 'admin'}), http(204, {}),
                    'HTTP/2.0 200 OK\n\n{broken', http(301, {}, 'Moved')):
            with self.subTest(raw=raw):
                self.assert_fails(FakeGh(issues=[gh_issue(1, 'bob')], permission={'bob': raw}))

    def test_a_200_status_in_the_stdout_of_a_failed_command_is_an_error(self):
        def answer(args):
            raise Failed(args, http(200, {'permission': 'admin', 'role_name': 'admin'}))
        self.assert_fails(FakeGh(issues=[gh_issue(1, 'bob')], permission={'bob': answer}))

    def test_unexpected_list_json(self):
        for raw in ('not json', '{}', '[1]', '[{"title": "no number"}]', '[{"number": "1"}]', '[{"number": true}]',
                    '[{"number": 1, "author": "flat"}]', '[{"number": 1, "author": {"login": 5}}]'):
            with self.subTest(raw=raw):
                self.assert_fails(FakeGh(listing=raw))

    def test_unexpected_comment_json(self):
        for comments in ('x', [1], [{'author': {'login': 'piotrp321'}, 'body': 5}]):
            with self.subTest(comments=comments):
                self.assert_fails(FakeGh(issues=[gh_issue(1, comments=comments)]),
                                  lambda gate: gate.issues(fields=('comments',)))

    def test_list_command_failure(self):
        def run(args):
            raise Failed(args)
        with self.assertRaises(github_trust.GateError):
            github_trust.Gate(run).issues()

    def test_view_returning_another_number(self):
        self.assert_fails(FakeGh(view=gh_issue(43)), lambda gate: gate.issue(42))


FAKE_GH = '''#!%(python)s
import json, os, sys
args = sys.argv[1:]
with open(os.environ['FAKE_GH_LOG'], 'a') as f:
    f.write(json.dumps(args) + '\\n')
with open(os.environ['FAKE_GH_DATA']) as f:
    data = json.load(f)
answer = data.get(' '.join(args))
if answer is None:
    sys.stderr.write('fake gh: no answer for %%r\\n' %% args)
    sys.exit(4)
sys.stdout.write(answer.get('stdout', ''))
sys.stderr.write(answer.get('stderr', ''))
sys.exit(answer.get('code', 0))
'''


class CliTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.bin = os.path.join(self.tmp.name, 'bin')
        os.makedirs(self.bin)
        gh = os.path.join(self.bin, 'gh')
        with open(gh, 'w') as f:
            f.write(FAKE_GH % {'python': sys.executable})
        os.chmod(gh, os.stat(gh).st_mode | stat.S_IEXEC)
        self.data = os.path.join(self.tmp.name, 'data.json')
        self.log = os.path.join(self.tmp.name, 'log.jsonl')
        self.answers = {}

    def answer(self, argv, stdout='', code=0, stderr=''):
        self.answers[argv] = {'stdout': stdout, 'code': code, 'stderr': stderr}

    def permission(self, login, role=None, status=200, repo=REPO):
        argv = 'api --include ' + permission_path(login, repo)
        if status == 200:
            self.answer(argv, http(200, {'permission': ROLES[role], 'role_name': role}))
        else:
            self.answer(argv, http(status, {'message': 'x'}, 'Error'), code=1, stderr='gh: x (HTTP %d)\n' % status)

    def run_cli(self, *argv):
        with open(self.data, 'w') as f:
            json.dump(self.answers, f)
        env = dict(os.environ, PATH=self.bin + os.pathsep + os.environ.get('PATH', ''), FAKE_GH_DATA=self.data,
                   FAKE_GH_LOG=self.log)
        result = subprocess.run([sys.executable, SCRIPT] + list(argv), capture_output=True, text=True, env=env)
        return result.returncode, result.stdout, result.stderr

    def calls(self):
        if not os.path.exists(self.log):
            return []
        with open(self.log) as f:
            return [json.loads(line) for line in f]

    def test_issues_with_trusted_untrusted_and_404_authors(self):
        listing = 'issue list --repo %s --state open --limit 500 --label bug --json number,author,url,title,body' % REPO
        self.answer(listing, json.dumps([gh_issue(1), gh_issue(2, 'mallory'), gh_issue(3, 'gone'),
                                         gh_issue(4, 'carol')]))
        self.permission('piotrp321', 'admin')
        self.permission('mallory', 'triage')
        self.permission('gone', status=404)
        self.permission('carol', 'maintain')
        code, out, err = self.run_cli('issues', '--label', 'bug', '--limit', '500', '--json', 'title,body')
        self.assertEqual((0, ''), (code, err))
        records = json.loads(out)
        self.assertEqual([True, False, False, True], [r['trusted'] for r in records])
        self.assertEqual(untrusted(3, 'gone'), records[2])
        self.assertEqual('Issue 1', records[0]['title'])
        self.assertEqual(1, len(out.splitlines()))

    def test_server_error_exits_non_zero_with_nothing_on_stdout(self):
        self.answer('issue view 5 --repo %s --json number,author,url,title,body,labels,state,updatedAt' % REPO,
                    json.dumps(gh_issue(5, 'bob')))
        self.permission('bob', status=502)
        code, out, err = self.run_cli('issue', '5')
        self.assertEqual(1, code)
        self.assertEqual('', out)
        self.assertIn('github_trust:', err)
        self.assertIn('502', err)

    def test_network_error_exits_non_zero_with_nothing_on_stdout(self):
        self.answer('pr list --repo %s --state open --limit 30 --base main --json number,author,url,title' % REPO,
                    json.dumps([dict(gh_issue(5), url='https://github.com/%s/pull/5' % REPO)]))
        self.answer('api --include ' + permission_path('piotrp321'), '', code=1,
                    stderr='error connecting to api.github.com\n')
        code, out, err = self.run_cli('prs', '--base', 'main', '--json', 'title')
        self.assertEqual((1, ''), (code, out))
        self.assertIn('error connecting', err)

    def test_list_failure_exits_non_zero(self):
        self.answer('issue view 5 --repo %s --json comments' % REPO, '', code=1, stderr='GraphQL: Could not resolve\n')
        code, out, err = self.run_cli('comments', '5')
        self.assertEqual((1, ''), (code, out))
        self.assertIn('Could not resolve', err)

    def test_comments_and_other_repository(self):
        repo = 'piotrp321/agentic-orchestrator'
        self.answer('issue view 8 --repo %s --json comments' % repo,
                    json.dumps({'comments': [gh_comment('piotrp321'), gh_comment('mallory', n=2)]}))
        self.permission('piotrp321', 'admin', repo=repo)
        self.permission('mallory', status=404, repo=repo)
        code, out, err = self.run_cli('comments', '8', '--repo', repo)
        self.assertEqual((0, ''), (code, err))
        self.assertEqual([True, False], [c['trusted'] for c in json.loads(out)])

    def test_one_permission_call_per_login_in_one_invocation(self):
        listing = 'issue list --repo %s --state all --limit 30 --json number,author,url,title,comments' % REPO
        self.answer(listing, json.dumps([gh_issue(1, comments=[gh_comment('piotrp321'), gh_comment('piotrp321')]),
                                         gh_issue(2), gh_issue(3, 'mallory'), gh_issue(4, 'mallory')]))
        self.permission('piotrp321', 'admin')
        self.permission('mallory', 'read')
        code, out, err = self.run_cli('issues', '--state', 'all', '--json', 'title,comments')
        self.assertEqual((0, ''), (code, err))
        self.assertEqual(2, sum(1 for c in self.calls() if c[:1] == ['api']))

    def test_usage_errors_exit_2_without_gh(self):
        for argv in (['issues', '--json', 'title,secret'], ['issue', 'x'], ['issue', '0'], ['prs', '--state', 'x'],
                     ['issues', '--limit', '0'], ['nothing'], [], ['issues', '--repo', 'bad repo']):
            with self.subTest(argv=argv):
                code, out, err = self.run_cli(*argv)
                self.assertEqual((2, ''), (code, out))
        self.assertEqual([], self.calls())


if __name__ == '__main__':
    unittest.main()

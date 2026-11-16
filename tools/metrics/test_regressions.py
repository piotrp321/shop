"""Tests of the regressions after the merge, `found_after_merge` and `ci` of the record (spec: Regresje po merge'u,
Rekord epiku, Model zagrożeń: droga 2). `gh` is a stub; nothing leaves the machine."""
import json
import os
import unittest
from datetime import datetime, timezone

import metrics
import testkit
from testkit import Env, FakeGh, merged_pr, run_cli

MERGED = '2026-11-13T10:00:00Z'
SHA = 'a' * 40


def epoch(text):
    return datetime.strptime(text, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc).timestamp()


NOW = epoch('2026-11-16T10:00:00Z')          # three days after the merge: the window is open
LATE = epoch('2026-12-01T10:00:00Z')         # 18 days after the merge: the window is closed


def bug(number, field, author='owner', created='2026-11-14T10:00:00Z', labels=('bug',)):
    body = ('### Środowisko\n\nstos\n\n### Wprowadzone przez (epik albo PR, jeśli znane)\n\n%s\n\n'
            '### Kroki odtworzenia\n\n1. x\n' % field)
    return {'number': number, 'body': body, 'author': {'login': author}, 'createdAt': created,
            'labels': [{'name': name} for name in labels]}


def sweep_issue(number, epic='deploy-ops', created='2026-11-14T10:00:00Z', author='owner', field='_No response_'):
    body = ('### Środowisko\n\nstos\n\n### Wprowadzone przez (epik albo PR, jeśli znane)\n\n%s\n\n### Źródło\n\nPrzebieg `/sweep` 2026-11-13-abc1234-%s, commit abc1234, agent '
            'tester, odtworzono: tak (2/2), waga średnia.\n' % (field, epic))
    return {'number': number, 'body': body, 'author': {'login': author}, 'createdAt': created,
            'labels': [{'name': 'bug'}, {'name': 'agent'}]}


def commit(sha, message, date):
    return {'sha': sha, 'commit': {'message': message, 'committer': {'date': date}, 'author': {'date': date}}}


class RegressionEnv(Env):
    def deploy_ops(self, gh, now=NOW, *extra):
        home = os.path.join(self.root, 'ao-deploy-ops')
        if not os.path.isdir(home):
            testkit.fixture_home(self.root, 'deploy-ops')
        argv = ['epic', 'deploy-ops', '--state', self.state, '--night', self.night, '--ao-home', home] + list(extra)
        return run_cli(argv, gh, now=now)

    def gh(self, **kwargs):
        kwargs.setdefault('prs', {476: merged_pr(MERGED, sha=SHA)})
        return FakeGh(**kwargs)

    def record(self, gh, now=NOW, *extra):
        code, out, err = self.deploy_ops(gh, now, *extra)
        self.assertIn(code, (0, 3), err)
        return json.loads(out)


class RedCiTest(RegressionEnv):
    def test_red_ci_run_on_the_merge_commit_is_a_regression(self):
        gh = self.gh(runs={SHA: [{'conclusion': 'failure', 'event': 'push'}, {'conclusion': 'success',
                                                                             'event': 'push'}]})
        regressions = self.record(gh)['regressions']
        self.assertEqual(regressions['ci_red'], 1)
        self.assertEqual(regressions['count'], 1)
        call = next(c for c in gh.calls if c[:3] == ['gh', 'run', 'list'] and '--commit' in c)
        self.assertIn(SHA, call)
        self.assertIn('ci.yml', call)

    def test_green_ci_is_zero_not_null(self):
        gh = self.gh(runs={SHA: [{'conclusion': 'success', 'event': 'push'}]})
        regressions = self.record(gh)['regressions']
        self.assertEqual((regressions['ci_red'], regressions['count']), (0, 0))

    def test_red_run_of_a_pull_request_event_is_not_counted(self):
        gh = self.gh(runs={SHA: [{'conclusion': 'failure', 'event': 'pull_request'}]})
        self.assertEqual(self.record(gh)['regressions']['ci_red'], 0)

    def test_window_fields(self):
        regressions = self.record(self.gh())['regressions']
        self.assertEqual(regressions['window_days'], 14)
        self.assertEqual(regressions['until'], '2026-11-27T10:00:00Z')
        self.assertTrue(regressions['open'])
        self.assertFalse(self.record(self.gh(), LATE)['regressions']['open'])


class RevertTest(RegressionEnv):
    def test_revert_of_the_final_pr_inside_the_window_is_a_regression(self):
        gh = self.gh(commits=[commit('b' * 40, 'Revert "feat: deploy ops (#476)" (#480)', '2026-11-14T08:00:00Z'),
                              commit('c' * 40, 'Reverts owner/shop#476\n\nbody', '2026-11-14T09:00:00Z')])
        regressions = self.record(gh)['regressions']
        self.assertEqual(regressions['reverts'], ['b' * 40, 'c' * 40])
        self.assertEqual(regressions['count'], 2)

    def test_revert_of_another_pr_or_outside_the_window_or_not_a_revert_is_not_counted(self):
        gh = self.gh(commits=[
            commit('b' * 40, 'Revert "feat: other (#4760)" (#481)', '2026-11-14T08:00:00Z'),
            commit('c' * 40, 'Revert "feat: deploy ops (#476)" (#482)', '2026-11-28T08:00:00Z'),
            commit('d' * 40, 'Revert "feat: deploy ops (#476)" (#483)', '2026-11-12T08:00:00Z'),
            commit('e' * 40, 'fix: mention of (#476) in a normal commit', '2026-11-14T08:00:00Z')])
        regressions = self.record(gh)['regressions']
        self.assertEqual(regressions['reverts'], [])
        self.assertEqual(regressions['count'], 0)

    def test_commits_are_read_with_the_window_and_the_gh_api_path_without_r_flag(self):
        gh = self.gh()
        self.record(gh, NOW, '--repo', 'owner/shop')
        call = next(c for c in gh.calls if c[:2] == ['gh', 'api'] and '/commits' in c[2])
        self.assertNotIn('-R', call)
        self.assertTrue(call[2].startswith('repos/owner/shop/commits?'), call)
        self.assertIn('sha=main', call[2])
        self.assertIn('since=2026-11-13T10:00:00Z', call[2])
        self.assertIn('until=2026-11-27T10:00:00Z', call[2])

    def test_without_repo_option_gh_expands_the_owner_and_repo_placeholders(self):
        gh = self.gh()
        self.record(gh)
        call = next(c for c in gh.calls if c[:2] == ['gh', 'api'] and '/commits' in c[2])
        self.assertTrue(call[2].startswith('repos/{owner}/{repo}/commits?'), call)


class BugIssueTest(RegressionEnv):
    def issues(self, *bugs):
        return {'bug': list(bugs)}

    def test_author_with_write_permission_counts_and_read_does_not(self):
        gh = self.gh(issues=self.issues(bug(10, 'deploy-ops', 'writer'), bug(11, 'deploy-ops', 'reader'),
                                        bug(12, 'deploy-ops', 'stranger')),
                     permissions={'writer': 'write', 'reader': 'read'})
        regressions = self.record(gh)['regressions']
        self.assertEqual(regressions['issues'], [10])
        self.assertEqual(regressions['count'], 1)

    def test_maintain_and_admin_count_triage_does_not(self):
        gh = self.gh(issues=self.issues(bug(10, 'deploy-ops', 'm'), bug(11, '#476', 'a'), bug(12, 'deploy-ops', 't')),
                     permissions={'m': 'maintain', 'a': 'admin', 't': 'triage'})
        self.assertEqual(self.record(gh)['regressions']['issues'], [10, 11])

    def test_field_names_the_epic_or_its_pr_number_exactly(self):
        gh = self.gh(issues=self.issues(
            bug(10, 'deploy-ops', 'w'), bug(11, 'PR 476', 'w'), bug(12, 'https://github.com/o/r/pull/476', 'w'),
            bug(13, 'deploy', 'w'), bug(14, 'deploy-ops-fix', 'w'), bug(15, '#4760', 'w'), bug(16, '_No response_', 'w'),
            bug(17, 'DEPLOY-OPS', 'w')), permissions={'w': 'write'})
        self.assertEqual(self.record(gh)['regressions']['issues'], [10, 11, 12, 17])

    def test_issue_without_the_field_does_not_count(self):
        plain = {'number': 20, 'body': '### Środowisko\n\ndeploy-ops #476\n', 'author': {'login': 'w'},
                 'createdAt': '2026-11-14T10:00:00Z', 'labels': [{'name': 'bug'}]}
        gh = self.gh(issues=self.issues(plain), permissions={'w': 'write'})
        self.assertEqual(self.record(gh)['regressions']['issues'], [])

    def test_issue_outside_the_window_does_not_count(self):
        gh = self.gh(issues=self.issues(bug(10, 'deploy-ops', 'w', created='2026-11-28T10:00:00Z'),
                                        bug(11, 'deploy-ops', 'w', created='2026-11-12T10:00:00Z'),
                                        bug(12, 'deploy-ops', 'w', created='2026-11-26T10:00:00Z')),
                     permissions={'w': 'write'})
        self.assertEqual(self.record(gh)['regressions']['issues'], [12])

    def test_permission_is_asked_once_per_author_and_only_for_matching_issues(self):
        gh = self.gh(issues=self.issues(bug(10, 'deploy-ops', 'w'), bug(11, 'deploy-ops', 'w'),
                                        bug(12, 'other-epic', 'x')), permissions={'w': 'write'})
        self.record(gh)
        asked = [c[2] for c in gh.calls if c[:2] == ['gh', 'api'] and '/collaborators/' in c[2]]
        self.assertEqual(asked, ['repos/{owner}/{repo}/collaborators/w/permission'])

    def test_login_that_is_not_a_login_is_not_asked_and_does_not_count(self):
        gh = self.gh(issues=self.issues(bug(10, 'deploy-ops', '../../orgs/x'), bug(11, 'deploy-ops', 'a b')),
                     permissions={})
        regressions = self.record(gh)['regressions']
        self.assertEqual(regressions['issues'], [])
        self.assertFalse([c for c in gh.calls if c[:2] == ['gh', 'api'] and '/collaborators/' in c[2]])

    def test_a_permission_error_other_than_404_is_a_gh_error_with_a_gap(self):
        class Flaky(FakeGh):
            def __call__(self, command):
                if command[:2] == ['gh', 'api'] and '/collaborators/' in command[2]:
                    self.calls.append(list(command))
                    return testkit.result(1, '', 'HTTP 502')
                return super().__call__(command)

        gh = Flaky(prs={476: merged_pr(MERGED, sha=SHA)}, issues=self.issues(bug(10, 'deploy-ops', 'w')))
        code, out, err = self.deploy_ops(gh)
        self.assertEqual(code, 3)
        record = json.loads(out)
        self.assertIsNone(record['regressions']['issues'])
        self.assertIsNone(record['regressions']['count'])
        self.assertTrue(any(g.startswith('regressions.issues') for g in record['gaps']), record['gaps'])

    def test_the_issues_are_listed_with_the_bug_label_and_a_date_range(self):
        gh = self.gh()
        self.record(gh)
        call = next(c for c in gh.calls if c[:3] == ['gh', 'issue', 'list'] and 'bug' in c)
        self.assertIn('--state', call)
        self.assertEqual(call[call.index('--state') + 1], 'all')
        self.assertIn('created:2026-11-13..2026-11-27', call[call.index('--search') + 1])


class FoundAfterMergeTest(RegressionEnv):
    def test_sweep_proposals_of_the_epic_are_found_after_merge_not_regressions(self):
        gh = self.gh(issues={'agent': [sweep_issue(30), sweep_issue(31, epic='other'),
                                       sweep_issue(32, created='2026-11-30T10:00:00Z'),
                                       sweep_issue(33, epic='deploy'), sweep_issue(34, epic='deploy-ops')],
                             'bug': [bug(40, 'deploy-ops', 'w'), sweep_issue(35, field='deploy-ops')]}, permissions={'w': 'write'})
        record = self.record(gh)
        self.assertEqual(record['found_after_merge'], {'issues': [30, 34], 'count': 2})
        self.assertEqual(record['regressions']['issues'], [40])       # a sweep proposal is never a regression
        self.assertEqual(record['regressions']['count'], 1)

    def test_an_issue_without_the_agent_label_is_not_a_sweep_proposal(self):
        plain = sweep_issue(30)
        plain['labels'] = [{'name': 'bug'}]
        gh = self.gh(issues={'agent': [plain]})
        self.assertEqual(self.record(gh)['found_after_merge'], {'issues': [], 'count': 0})


class GhErrorsTest(RegressionEnv):
    def test_gh_error_keeps_the_stored_values_and_records_a_gap(self):
        good = self.gh(runs={SHA: [{'conclusion': 'failure', 'event': 'push'}]},
                       commits=[commit('b' * 40, 'Revert "x (#476)"', '2026-11-14T08:00:00Z')],
                       issues={'bug': [bug(10, 'deploy-ops', 'w')], 'agent': [sweep_issue(30)]},
                       permissions={'w': 'write'})
        first = self.record(good)
        self.assertEqual(first['regressions']['count'], 3)
        again = self.record(self.gh(fail_on=[('run', 'list'), ('api',), ('issue', 'list')]))
        self.assertEqual(again['regressions']['ci_red'], 1)
        self.assertEqual(again['regressions']['issues'], [10])
        self.assertEqual(again['found_after_merge']['issues'], [30])
        self.assertTrue(any(g.startswith('regressions.ci_red') and 'zachowano' in g for g in again['gaps']),
                        again['gaps'])

    def test_failing_sources_without_stored_values_are_null_with_gaps_and_exit_3(self):
        code, out, err = self.deploy_ops(self.gh(fail_on=[('run', 'list'), ('issue', 'list')]))
        self.assertEqual(code, 3)
        record = json.loads(out)
        self.assertIsNone(record['regressions']['ci_red'])
        self.assertIsNone(record['regressions']['issues'])
        self.assertIsNone(record['found_after_merge'])
        self.assertEqual(record['regressions']['reverts'], [])        # the commits call worked
        for field in ('regressions.ci_red', 'regressions.issues', 'found_after_merge'):
            self.assertTrue(any(g.startswith(field) and 'gh' in g for g in record['gaps']), (field, record['gaps']))
        self.assertIn('metrics:', err)

    def test_no_gh_gives_null_and_gaps_never_zero(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        code, out, err = self.epic('deploy-ops', home=home)
        record = json.loads(out)
        self.assertIsNone(record['regressions'])
        self.assertIsNone(record['found_after_merge'])
        self.assertIsNone(record['ci'])
        for field in ('regressions', 'found_after_merge', 'ci'):
            self.assertTrue(any(g.startswith(field) for g in record['gaps']), (field, record['gaps']))

    def test_unmerged_final_pr_has_no_regressions(self):
        gh = FakeGh(prs={476: {'state': 'OPEN', 'mergedAt': None, 'closingIssuesReferences': []}})
        record = self.record(gh)
        self.assertIsNone(record['regressions'])
        self.assertTrue(any(g.startswith('regressions') for g in record['gaps']), record['gaps'])


def run_of(run_id, created, conclusion='success'):
    return {'databaseId': run_id, 'createdAt': created, 'conclusion': conclusion, 'event': 'pull_request'}


def job(name, started, completed, conclusion='success'):
    return {'name': name, 'startedAt': started, 'completedAt': completed, 'conclusion': conclusion}


class CiTest(RegressionEnv):
    def gh_with_ci(self):
        return self.gh(
            runs={'epic/deploy-ops': [run_of(1, '2026-11-13T08:00:00Z', 'failure'), run_of(2, '2026-11-13T09:00:00Z')],
                  'task/DEPLOY-OPS-1': [run_of(3, '2026-11-11T14:00:00Z')],
                  'task/DEPLOY-OPS-2': [run_of(4, '2026-11-11T15:00:00Z', 'failure'), run_of(5, '2026-11-11T16:00:00Z'),
                                        run_of(6, '2026-11-11T17:00:00Z')]},
            jobs={2: [job('checks part 1/2', '2026-11-13T09:00:10Z', '2026-11-13T09:10:10Z'),
                      job('checks part 2/2', '2026-11-13T09:00:20Z', '2026-11-13T09:12:40Z'),
                      job('checks', '2026-11-13T09:12:50Z', '2026-11-13T09:13:00Z'),
                      job('e2e shard 1/2', '2026-11-13T09:00:10Z', '2026-11-13T09:20:10Z'),
                      job('e2e shard 2/2', '2026-11-13T09:00:15Z', '2026-11-13T09:15:15Z'),
                      job('e2e', '2026-11-13T09:20:20Z', '2026-11-13T09:20:30Z'),
                      job('lint', '2026-11-13T09:00:10Z', '2026-11-13T10:00:00Z')],
                  6: [job('checks', '2026-11-11T17:00:10Z', '2026-11-11T17:05:10Z')]})

    def test_final_pr_has_the_time_of_the_last_checks_and_e2e_run_and_the_number_of_runs(self):
        ci = self.record(self.gh_with_ci())['ci']
        self.assertEqual(ci['final'], {'pr': 476, 'runs': 2, 'checks_s': 770.0, 'e2e_s': 1220.0})

    def test_task_prs_have_their_own_entries(self):
        ci = self.record(self.gh_with_ci())['ci']
        self.assertEqual(ci['tasks']['DEPLOY-OPS-2'], {'pr': 449, 'runs': 3, 'checks_s': 300.0, 'e2e_s': None})
        self.assertEqual(ci['tasks']['DEPLOY-OPS-1']['runs'], 1)
        self.assertEqual(sorted(ci['tasks']), sorted(t for t in ci['tasks']))
        self.assertNotIn('DEPLOY-OPS-INTEGRATION-1', ci['tasks'])         # no PR number

    def test_branch_without_runs_has_zero_runs_and_null_times_with_one_gap(self):
        record = self.record(self.gh_with_ci())
        entry = record['ci']['tasks']['DEPLOY-OPS-3']
        self.assertEqual(entry, {'pr': 452, 'runs': 0, 'checks_s': None, 'e2e_s': None})
        self.assertEqual(len([g for g in record['gaps'] if g.startswith('ci:')]), 1, record['gaps'])

    def test_runs_are_listed_per_branch_for_pull_request_events(self):
        gh = self.gh_with_ci()
        self.record(gh)
        calls = [c for c in gh.calls if c[:3] == ['gh', 'run', 'list'] and '--branch' in c]
        self.assertIn('task/DEPLOY-OPS-1', [c[c.index('--branch') + 1] for c in calls])
        self.assertTrue(all(c[c.index('--event') + 1] == 'pull_request' for c in calls))

    def test_ci_error_keeps_the_stored_value(self):
        self.record(self.gh_with_ci())
        again = self.record(self.gh(fail_on=[('run', 'view'), ('run', 'list')]))
        self.assertEqual(again['ci']['final']['runs'], 2)
        self.assertTrue(any(g.startswith('ci') and 'zachowano' in g for g in again['gaps']), again['gaps'])


class BugFormTest(unittest.TestCase):
    """The `bug` issue form has the field that `regressions` reads (the heading is the label of the field)."""

    def form(self):
        path = os.path.join(testkit.HERE, '..', '..', '.github', 'ISSUE_TEMPLATE', 'bug.yml')
        with open(path, encoding='utf-8') as f:
            return f.read()

    def test_the_form_has_an_optional_field_introduced_by(self):
        text = self.form()
        self.assertIn('label: Wprowadzone przez (epik albo PR, jeśli znane)', text)
        block = text[text.index('label: Wprowadzone przez'):]
        block = block[:block.index('\n  - type:')] if '\n  - type:' in block else block
        self.assertIn('required: false', block)

    def test_the_rendered_field_is_read_by_field_value(self):
        body = '### Wprowadzone przez (epik albo PR, jeśli znane)\n\ndeploy-ops\n\n### Niewiadome\n\nBrak'
        self.assertEqual(metrics.field_value(body, 'Wprowadzone przez'), 'deploy-ops')
        self.assertIsNone(metrics.field_value('### Wprowadzone przez (epik albo PR, jeśli znane)\n\n_No response_',
                                              'Wprowadzone przez'))


def forbidden_flag(argument):
    """Whether an argument of `gh api` makes it write: a method, a request body or a field."""
    return (argument in ('-X', '--method', '--input', '-f', '-F', '--field', '--raw-field') or argument.startswith(
        ('-X', '--method=', '--input=', '--field=', '--raw-field=', '-f', '-F')))


class ReadOnlyTest(RegressionEnv):
    def test_every_gh_call_only_reads(self):
        gh = self.gh(issues={'bug': [bug(10, 'deploy-ops', 'stranger'), bug(11, 'deploy-ops', 'w')],
                             'agent': [sweep_issue(30)]}, permissions={'w': 'write', 'e': 'write'},
                     editors={10: 'e'})
        self.record(gh)
        self.assertTrue([c for c in gh.calls if c[2:3] == ['graphql']])
        for call in gh.calls:
            self.assertIn(call[1], ('pr', 'run', 'issue', 'api', 'repo'), call)
            self.assertFalse({'edit', 'merge', 'close', 'comment', 'create', 'mutation'} & set(call), call)
            if call[1] == 'repo':
                self.assertEqual(call[2], 'view', call)
            elif call[1] != 'api':
                self.assertIn(call[2], ('view', 'list'), call)
            elif call[2] == 'graphql':
                query = next(a for a in call if a.startswith('query='))
                self.assertTrue(query[len('query='):].lstrip().startswith('query'), query)
                self.assertNotIn('mutation', ' '.join(call).lower())
                self.assertFalse([a for a in call if a.startswith(('-X', '--method', '--input'))], call)
            else:
                self.assertTrue(call[2].startswith('repos/'), call)
                self.assertFalse([a for a in call[2:] if forbidden_flag(a)], call)

    def test_the_forbidden_flag_check_catches_the_write_forms(self):
        for argument in ('-X', '-XPOST', '--method', '--method=POST', '--input', '--input=x', '-f', '-fa=b', '-F',
                         '-Fa=b', '--field', '--field=a=b', '--raw-field', '--raw-field=a=b'):
            self.assertTrue(forbidden_flag(argument), argument)
        for argument in ('repos/{owner}/{repo}/commits?sha=main', 'graphql'):
            self.assertFalse(forbidden_flag(argument), argument)


class TruncationTest(RegressionEnv):
    """A list cut at its limit may miss entries: the window does not close on it."""

    AFTER = epoch('2026-12-01T10:00:00Z')

    def filler_issues(self, count, label='bug'):
        return [dict(bug(1000 + n, 'other-epic', 'x'), labels=[{'name': label}]) for n in range(count)]

    def test_a_full_issue_list_leaves_the_window_open(self):
        record = self.record(self.gh(issues={'bug': self.filler_issues(metrics.ISSUE_LIMIT)}), self.AFTER)
        self.assertFalse(record['regressions']['complete'])
        self.assertTrue(record['regressions']['open'])
        self.assertTrue(any(g.startswith('regressions.issues') and 'obcięta' in g for g in record['gaps']), record['gaps'])

    def test_a_full_sweep_list_leaves_the_window_open(self):
        record = self.record(self.gh(issues={'agent': self.filler_issues(metrics.ISSUE_LIMIT, 'agent')}), self.AFTER)
        self.assertFalse(record['regressions']['complete'])

    def test_a_full_commit_list_leaves_the_window_open(self):
        commits = [commit('%040x' % n, 'chore: x', '2026-11-14T08:00:00Z') for n in range(metrics.COMMIT_LIMIT)]
        record = self.record(self.gh(commits=commits), self.AFTER)
        self.assertFalse(record['regressions']['complete'])
        self.assertTrue(record['regressions']['open'])

    def test_lists_below_the_limits_close_the_window(self):
        record = self.record(self.gh(issues={'bug': self.filler_issues(metrics.ISSUE_LIMIT - 1)}), self.AFTER)
        self.assertTrue(record['regressions']['complete'])
        self.assertFalse(record['regressions']['open'])


class EditorTest(RegressionEnv):
    """An issue of a foreign author counts when the last editor of its body may write (spec: Regresje po merge'u)."""

    def gh_with(self, **kwargs):
        kwargs.setdefault('permissions', {'writer': 'write', 'reader': 'read'})
        return self.gh(issues={'bug': [bug(10, 'deploy-ops', 'stranger')]}, **kwargs)

    def issues_of(self, gh):
        return self.record(gh)['regressions']['issues']

    def test_foreign_author_with_a_write_editor_counts(self):
        self.assertEqual(self.issues_of(self.gh_with(editors={10: 'writer'})), [10])

    def test_foreign_author_with_a_read_editor_does_not_count(self):
        self.assertEqual(self.issues_of(self.gh_with(editors={10: 'reader'})), [])

    def test_foreign_author_with_an_editor_who_is_not_a_collaborator_does_not_count(self):
        self.assertEqual(self.issues_of(self.gh_with(editors={10: 'passerby'})), [])

    def test_foreign_author_without_an_edit_does_not_count(self):
        self.assertEqual(self.issues_of(self.gh_with()), [])

    def test_an_editor_that_is_not_a_login_is_not_asked_about(self):
        gh = self.gh_with(editors={10: '../../orgs/x'})
        self.assertEqual(self.issues_of(gh), [])
        self.assertFalse([c for c in gh.calls if c[:2] == ['gh', 'api'] and '/collaborators/' in c[2]
                          and 'orgs' in c[2]])

    def test_an_author_with_write_permission_needs_no_editor_lookup(self):
        gh = self.gh(issues={'bug': [bug(10, 'deploy-ops', 'writer')]}, permissions={'writer': 'write'})
        self.assertEqual(self.issues_of(gh), [10])
        self.assertFalse([c for c in gh.calls if c[2:3] == ['graphql']])

    def test_the_editor_is_not_looked_up_for_issues_that_do_not_name_the_epic(self):
        gh = self.gh(issues={'bug': [bug(10, 'other-epic', 'stranger')]}, editors={10: 'writer'},
                     permissions={'writer': 'write'})
        self.assertEqual(self.issues_of(gh), [])
        self.assertFalse([c for c in gh.calls if c[2:3] == ['graphql']])

    def test_the_query_names_the_repository_and_the_issue(self):
        gh = self.gh_with(editors={10: 'writer'})
        self.record(gh, NOW, '--repo', 'owner/shop')
        call = next(c for c in gh.calls if c[2:3] == ['graphql'])
        self.assertIn('owner=owner', call)
        self.assertIn('name=shop', call)
        self.assertIn('number=10', call)
        self.assertNotIn('-R', call)
        self.assertFalse([c for c in gh.calls if c[:3] == ['gh', 'repo', 'view']])

    def test_without_repo_option_the_repository_is_read_once_and_given_to_graphql_as_real_names(self):
        gh = self.gh(issues={'bug': [bug(10, 'deploy-ops', 'stranger'), bug(11, 'deploy-ops', 'stranger2')]},
                     permissions={'writer': 'write'}, editors={10: 'writer', 11: 'writer'})
        self.assertEqual(self.record(gh)['regressions']['issues'], [10, 11])
        views = [c for c in gh.calls if c[:3] == ['gh', 'repo', 'view']]
        self.assertEqual(len(views), 1)
        self.assertEqual(views[0][3:], ['--json', 'owner,name'])
        queries = [c for c in gh.calls if c[2:3] == ['graphql']]
        self.assertEqual(len(queries), 2)
        for call in queries:
            self.assertIn('owner=acme', call)
            self.assertIn('name=shop', call)
            self.assertFalse([a for a in call if '{' in a and a.startswith(('owner=', 'name='))], call)

    def test_the_stub_rejects_a_placeholder_in_a_raw_field(self):
        result = FakeGh()(['gh', 'api', 'graphql', '-f', 'query=query { x }', '-f', 'owner={owner}', '-f',
                           'name={repo}', '-F', 'number=1'])
        self.assertEqual(result.returncode, 1)

    def test_a_repo_view_error_is_a_gh_error_that_keeps_the_stored_value(self):
        first = self.gh_with(editors={10: 'writer'})
        self.assertEqual(self.record(first)['regressions']['issues'], [10])
        code, out, err = self.deploy_ops(self.gh_with(editors={10: 'writer'}, fail_on=[('repo', 'view')]))
        self.assertEqual(code, 3)
        record = json.loads(out)
        self.assertEqual(record['regressions']['issues'], [10])
        self.assertFalse(record['regressions']['complete'])
        self.assertTrue(any(g.startswith('regressions.issues') and 'gh' in g for g in record['gaps']), record['gaps'])
        self.assertIn('gh repo view', err)

    def test_a_repo_view_answer_of_a_wrong_shape_is_a_gh_error(self):
        code, out, err = self.deploy_ops(self.gh_with(editors={10: 'writer'}, repo_view={'owner': None, 'name': 5}))
        self.assertEqual(code, 3)
        self.assertIsNone(json.loads(out)['regressions']['issues'])

    def test_a_graphql_error_is_a_gh_error_that_keeps_the_stored_value(self):
        first = self.record(self.gh_with(editors={10: 'writer'}))
        self.assertEqual(first['regressions']['issues'], [10])
        gh = self.gh_with(editors={10: 'writer'}, fail_on=[('api', 'graphql')])
        code, out, err = self.deploy_ops(gh)
        self.assertEqual(code, 3)
        again = json.loads(out)
        self.assertEqual(again['regressions']['issues'], [10])
        self.assertTrue(any(g.startswith('regressions.issues') and 'gh' in g for g in again['gaps']), again['gaps'])
        self.assertFalse(again['regressions']['complete'])

    def test_a_graphql_error_without_a_stored_value_is_null_with_a_gap(self):
        code, out, err = self.deploy_ops(self.gh_with(editors={10: 'writer'}, fail_on=[('api', 'graphql')]))
        self.assertEqual(code, 3)
        record = json.loads(out)
        self.assertIsNone(record['regressions']['issues'])
        self.assertIsNone(record['regressions']['count'])


if __name__ == '__main__':
    unittest.main()

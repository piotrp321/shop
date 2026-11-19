"""Tests of delta.py: python3 -m unittest discover -s .claude/skills/integration -p 'test_*.py'"""
import json
import os
import re
import stat
import subprocess
import sys
import tempfile
import textwrap
import time
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'delta.py')
SHA = 'a' * 40


class Repo:
    """A throwaway Git repository: `main` with a base commit and an epic branch cut from it."""

    def __init__(self, path):
        self.path = path
        self.git('init', '-q', '-b', 'main')
        self.git('config', 'user.email', 'test@example.com')
        self.git('config', 'user.name', 'Test')
        self.git('config', 'commit.gpgsign', 'false')
        self.commit('base', {'shared.txt': 'one\ntwo\nthree\n', 'apps/api/openapi.json': '{}\n'})
        self.git('checkout', '-q', '-b', 'epic')

    def git(self, *args):
        return subprocess.run(['git', '-C', self.path, *args], capture_output=True, text=True, check=True).stdout.strip()

    def write(self, files):
        for name, content in files.items():
            full = os.path.join(self.path, name)
            os.makedirs(os.path.dirname(full), exist_ok=True)
            with open(full, 'w') as f:
                f.write(content)
            self.git('add', name)

    def commit(self, message, files):
        self.write(files)
        self.git('commit', '-q', '-m', message)
        return self.head()

    def head(self):
        return self.git('rev-parse', 'HEAD')

    def on(self, branch):
        self.git('checkout', '-q', branch)

    def merge(self, ref, message='Merge main into epic'):
        """A merge that may conflict: returns True when Git stopped on a conflict."""
        r = subprocess.run(['git', '-C', self.path, 'merge', '--no-ff', '-q', '-m', message, ref],
                           capture_output=True, text=True)
        return r.returncode != 0

    def resolve(self, files, message='Merge main into epic'):
        self.write(files)
        self.git('commit', '-q', '--no-edit', '-m', message)
        return self.head()


class SyncTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = Repo(self.tmp.name)
        self.previous = self.repo.commit('epic work', {'epic.txt': 'epic\n'})

    def tearDown(self):
        self.tmp.cleanup()

    def advance_main(self, files):
        self.repo.on('main')
        sha = self.repo.commit('main work', files)
        self.repo.on('epic')
        return sha

    def sync(self, previous=None, head=None, main='main'):
        r = subprocess.run([sys.executable, SCRIPT, 'sync', '--previous', previous or self.previous,
                            '--head', head or self.repo.head(), '--main', main],
                           capture_output=True, text=True, cwd=self.repo.path)
        return r

    def report(self, **kwargs):
        r = self.sync(**kwargs)
        self.assertEqual(0, r.returncode, r.stderr)
        return json.loads(r.stdout)

    def test_a_clean_merge_of_main_is_a_sync(self):
        self.advance_main({'main.txt': 'main\n'})
        self.repo.merge('main')
        out = self.report()
        self.assertEqual('sync', out['kind'], out)
        self.assertEqual([self.repo.head()], out['merges'])
        self.assertEqual([], out['other_commits'])
        self.assertEqual([], out['conflicts'])
        self.assertEqual([], out['extra'])

    def test_a_resolved_conflict_is_a_sync_that_names_the_file(self):
        self.repo.commit('epic edit', {'shared.txt': 'one\nepic\nthree\n'})
        previous = self.repo.head()
        self.advance_main({'shared.txt': 'one\nmain\nthree\n'})
        self.assertTrue(self.repo.merge('main'))
        self.repo.resolve({'shared.txt': 'one\nepic\nmain\nthree\n'})
        out = self.report(previous=previous)
        self.assertEqual('sync', out['kind'], out)
        self.assertEqual(['shared.txt'], out['conflicts'])
        self.assertEqual([], out['extra'])

    def test_a_resolution_that_keeps_one_side_is_listed_as_a_conflict(self):
        self.repo.commit('epic edit', {'shared.txt': 'one\nepic\nthree\n'})
        previous = self.repo.head()
        self.advance_main({'shared.txt': 'one\nmain\nthree\n'})
        self.assertTrue(self.repo.merge('main'))
        self.repo.resolve({'shared.txt': 'one\nepic\nthree\n'})  # the change of main is lost
        out = self.report(previous=previous)
        self.assertEqual('sync', out['kind'], out)
        self.assertEqual(['shared.txt'], out['conflicts'])

    def test_both_sides_merged_by_git_without_a_conflict_need_no_review(self):
        self.repo.commit('epic edit', {'shared.txt': 'epic\ntwo\nthree\n'})
        previous = self.repo.head()
        self.advance_main({'shared.txt': 'one\ntwo\nmain\n'})
        self.assertFalse(self.repo.merge('main'))
        out = self.report(previous=previous)
        self.assertEqual('sync', out['kind'], out)
        self.assertEqual([], out['conflicts'])
        self.assertEqual([], out['extra'])

    def test_a_change_made_in_the_merge_itself_makes_a_regular_delta(self):
        self.advance_main({'main.txt': 'main\n'})
        self.repo.git('merge', '-q', '--no-ff', '--no-commit', 'main')
        self.repo.write({'feature.php': '<?php\n', 'shared.txt': 'one\ntwo\nthree\nfour\n'})
        self.repo.git('commit', '-q', '-m', 'Merge main into epic')
        out = self.report()
        self.assertEqual('delta', out['kind'], out)
        self.assertEqual(['feature.php', 'shared.txt'], out['extra'])

    def test_a_change_beyond_the_conflict_in_a_resolved_merge_makes_a_regular_delta(self):
        self.repo.commit('epic edit', {'shared.txt': 'one\nepic\nthree\n'})
        previous = self.repo.head()
        self.advance_main({'shared.txt': 'one\nmain\nthree\n'})
        self.assertTrue(self.repo.merge('main'))
        self.repo.resolve({'shared.txt': 'one\nepic\nmain\nthree\n', 'epic.txt': 'rewritten\n'})
        out = self.report(previous=previous)
        self.assertEqual('delta', out['kind'], out)
        self.assertEqual(['epic.txt'], out['extra'])

    def test_a_sync_merged_into_the_epic_through_a_pull_request_merge_commit_is_a_sync(self):
        self.advance_main({'main.txt': 'main\n'})
        self.repo.git('checkout', '-q', '-b', 'task/repair')
        self.repo.merge('main')
        self.repo.on('epic')
        self.repo.merge('task/repair', message='Merge pull request from task/repair')
        out = self.report()
        self.assertEqual('sync', out['kind'], out)
        self.assertEqual(2, len(out['merges']))

    def test_a_generated_file_changed_by_the_sync_is_listed(self):
        self.advance_main({'apps/api/openapi.json': '{"main": 1}\n'})
        self.repo.merge('main')
        out = self.report()
        self.assertEqual('sync', out['kind'], out)
        self.assertEqual(['apps/api/openapi.json'], out['generated'])

    def test_a_commit_after_the_merge_makes_a_regular_delta(self):
        self.advance_main({'main.txt': 'main\n'})
        self.repo.merge('main')
        fix = self.repo.commit('regenerate the contract', {'apps/api/openapi.json': '{"x": 1}\n'})
        out = self.report()
        self.assertEqual('delta', out['kind'], out)
        self.assertEqual([fix], out['other_commits'])

    def test_an_octopus_merge_makes_a_regular_delta(self):
        # Parents: the previous head and two commits of main; nothing but main comes in, yet octopus merges are
        # not checked against an automatic merge.
        first = self.advance_main({'main.txt': 'main\n'})
        second = self.advance_main({'main2.txt': 'main\n'})
        self.repo.merge('main')
        tree = self.repo.git('rev-parse', 'HEAD^{tree}')
        octopus = self.repo.git('commit-tree', tree, '-p', self.previous, '-p', second, '-p', first, '-m', 'Octopus')
        self.repo.git('reset', '-q', '--hard', octopus)
        out = self.report()
        self.assertEqual('delta', out['kind'], out)
        self.assertIn('octopus', out['reason'])

    def test_a_squashed_sync_makes_a_regular_delta(self):
        self.advance_main({'main.txt': 'main\n'})
        self.repo.git('merge', '-q', '--squash', 'main')
        self.repo.git('commit', '-q', '-m', 'Squashed sync')
        self.assertEqual('delta', self.report()['kind'])

    def test_a_merge_of_another_branch_makes_a_regular_delta(self):
        self.repo.git('checkout', '-q', '-b', 'task/x')
        work = self.repo.commit('task work', {'task.txt': 'task\n'})
        self.repo.on('epic')
        self.repo.merge('task/x', message='Merge task')
        out = self.report()
        self.assertEqual('delta', out['kind'], out)
        self.assertEqual([work], out['other_commits'])

    def test_a_merge_that_brings_nothing_from_main_makes_a_regular_delta(self):
        # A merge commit whose second parent is an old commit of main: no commit of main comes in, yet its tree
        # may carry any change.
        self.repo.write({'hidden.txt': 'change\n'})
        tree = self.repo.git('write-tree')
        old_main = self.repo.git('rev-parse', 'main')
        merge = self.repo.git('commit-tree', tree, '-p', self.previous, '-p', old_main, '-m', 'Merge main')
        self.repo.git('reset', '-q', '--hard', merge)
        out = self.report()
        self.assertEqual('delta', out['kind'], out)
        self.assertIn('main', out['reason'])

    def test_no_commits_since_the_previous_head_is_a_regular_delta(self):
        self.assertEqual('delta', self.report(head=self.previous)['kind'])

    def test_a_previous_head_that_is_not_an_ancestor_is_an_error(self):
        self.repo.on('main')
        other = self.repo.commit('elsewhere', {'other.txt': 'x\n'})
        self.repo.on('epic')
        r = self.sync(previous=other)
        self.assertEqual(2, r.returncode)
        self.assertIn('ancestor', r.stderr)

    def test_unknown_commits_and_refs_are_errors(self):
        r = self.sync(previous='b' * 40)
        self.assertEqual(2, r.returncode)
        self.assertIn('delta:', r.stderr)
        r = self.sync(main='origin/missing')
        self.assertEqual(2, r.returncode)
        self.assertIn('origin/missing', r.stderr)

    def test_outside_a_repository_is_an_error(self):
        outside = tempfile.TemporaryDirectory()
        self.addCleanup(outside.cleanup)
        r = subprocess.run([sys.executable, SCRIPT, 'sync', '--previous', SHA, '--head', SHA],
                           capture_output=True, text=True, cwd=outside.name)
        self.assertEqual(2, r.returncode)
        self.assertIn('delta:', r.stderr)


FAKE_GH = '''\
import json, os, sys
d = os.environ['FAKE_GH']
args = sys.argv[1:]
with open(os.path.join(d, 'calls'), 'a') as f:
    f.write(' '.join(args) + '\\n')
if os.path.exists(os.path.join(d, 'fail')):
    sys.stderr.write('gh: HTTP 502\\n')
    sys.exit(1)
command = ' '.join(args[:2])
if command == 'run list':
    key = 'runs'
elif command == 'run view':
    key = 'run-' + args[2]
else:
    sys.stderr.write('unexpected: %s\\n' % ' '.join(args))
    sys.exit(1)
# The n-th call of a key answers with the n-th entry of <key>.seq.json (the last one after the end), else <key>.json;
# the entry "FAIL" makes that call fail like gh does.
counter = os.path.join(d, key + '.count')
n = int(open(counter).read()) + 1 if os.path.exists(counter) else 1
with open(counter, 'w') as f:
    f.write(str(n))
seq = os.path.join(d, key + '.seq.json')
if os.path.exists(seq):
    answers = json.load(open(seq))
    answer = answers[min(n, len(answers)) - 1]
    if answer == 'FAIL':
        sys.stderr.write('gh: HTTP 502\\n')
        sys.exit(1)
    sys.stdout.write(json.dumps(answer))
else:
    sys.stdout.write(open(os.path.join(d, key + '.json')).read())
'''


class CiTest(unittest.TestCase):
    """`ci` with a fake gh on PATH: the runs and jobs it prints come from files of the test, one answer per call when
    the test gives a sequence. The poll interval and the limit of one call are shortened through the environment."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.bin = os.path.join(self.tmp.name, 'bin')
        os.makedirs(self.bin)
        gh = os.path.join(self.bin, 'gh')
        with open(gh, 'w') as f:
            f.write('#!%s\n%s' % (sys.executable, FAKE_GH))
        os.chmod(gh, os.stat(gh).st_mode | stat.S_IEXEC)
        self.wait_file = os.path.join(self.tmp.name, 'ci-wait.json')

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, name, value):
        with open(os.path.join(self.tmp.name, name), 'w') as f:
            json.dump(value, f)

    def runs(self, *runs):
        self.write('runs.json', list(runs))

    def runs_seq(self, *answers):
        self.write('runs.seq.json', [a if a == 'FAIL' else list(a) for a in answers])

    def view(self, jobs, head=SHA):
        return {'headSha': head, 'jobs': [self.job(*j) for j in jobs]}

    @staticmethod
    def job(name, status, conclusion, steps=()):
        """A job as `gh run view --json jobs` prints it; steps are (name, conclusion) pairs."""
        return {'name': name, 'status': status, 'conclusion': conclusion,
                'steps': [{'name': s, 'status': 'completed', 'conclusion': c, 'number': i + 1}
                          for i, (s, c) in enumerate(steps)]}

    def jobs(self, run_id, *jobs, head=SHA):
        self.write('run-%d.json' % run_id, self.view(jobs, head))

    def jobs_seq(self, run_id, *answers):
        self.write('run-%d.seq.json' % run_id, [a if a == 'FAIL' else self.view(a) for a in answers])

    def ci(self, head=SHA, path=None, budget=None, wait_file=None, poll='0.02', call='0.3', extra=()):
        env = dict(os.environ, FAKE_GH=self.tmp.name,
                   PATH=path if path is not None else self.bin + os.pathsep + os.environ['PATH'],
                   DELTA_CI_POLL_S=poll, DELTA_CI_CALL_S=call)
        args = [sys.executable, SCRIPT, 'ci', '--head', head]
        if budget is not None:
            args += ['--budget', str(budget), '--wait-file', wait_file or self.wait_file]
        return subprocess.run(args + list(extra), capture_output=True, text=True, env=env)

    def calls(self):
        with open(os.path.join(self.tmp.name, 'calls')) as f:
            return f.read()

    def view_calls(self):
        return self.calls().count('run view')

    def run_(self, run_id, head=SHA, status='completed', conclusion='success', created='2026-11-08T10:00:00Z'):
        return {'databaseId': run_id, 'headSha': head, 'status': status, 'conclusion': conclusion, 'event': 'push',
                'createdAt': created}

    GREEN = (('scope', 'completed', 'success'), ('checks', 'completed', 'success'), ('e2e', 'completed', 'success'))
    RUNNING = (('scope', 'completed', 'success'), ('checks', 'in_progress', ''), ('e2e', 'queued', ''))

    def result(self, code, **kwargs):
        r = self.ci(**kwargs)
        self.assertEqual(code, r.returncode, r.stdout + r.stderr)
        return json.loads(r.stdout)

    def assertGreen(self, out, run_id=7):
        self.assertEqual('green', out['state'])
        self.assertTrue(out['use_ci'])
        self.assertEqual(SHA, out['sha'])
        self.assertEqual(run_id, out['run'])
        self.assertEqual([{'name': 'ci:checks@' + SHA, 'status': 'passed'}, {'name': 'ci:e2e@' + SHA, 'status': 'passed'}],
                         out['checks'])
        self.assertEqual([], out['failed_jobs'])
        self.assertEqual([], out['failed_parts'])
        self.assertIsNone(out['failure_kind'])

    def assertLocal(self, fragment, **kwargs):
        out = self.result(1, **kwargs)
        self.assertEqual('local', out['state'])
        self.assertFalse(out['use_ci'])
        self.assertEqual([], out['checks'])
        self.assertEqual([], out['failed_parts'])
        self.assertIsNone(out['failure_kind'])
        self.assertIn(fragment, out['reason'])
        return out

    def statuses(self, out):
        return [c['status'] for c in out['checks']]

    # One check, without a budget.

    def test_green_required_jobs_of_a_push_run_of_the_head_replace_the_local_gates(self):
        self.runs(self.run_(7))
        self.jobs(7, *self.GREEN)
        self.assertGreen(self.result(0))
        self.assertIn('--commit %s' % SHA, self.calls())
        self.assertIn('--event push', self.calls())

    def test_asks_gh_for_push_runs_of_the_ci_workflow(self):
        self.runs(self.run_(7))
        self.jobs(7, *self.GREEN)
        self.ci()
        self.assertIn('--workflow ci.yml', self.calls())

    def test_a_failed_required_job_makes_red_ci_that_the_local_gates_cannot_replace(self):
        self.runs(self.run_(7, conclusion='failure'))
        self.jobs(7, ('checks', 'completed', 'success'), ('e2e', 'completed', 'failure'))
        out = self.result(3)
        self.assertEqual('red', out['state'])
        self.assertTrue(out['use_ci'])
        self.assertEqual(7, out['run'])
        self.assertEqual(['e2e'], out['failed_jobs'])
        self.assertEqual([{'name': 'ci:checks@' + SHA, 'status': 'passed'}, {'name': 'ci:e2e@' + SHA, 'status': 'failed'}],
                         out['checks'])

    def test_a_timed_out_or_startup_failed_job_is_red_too(self):
        for conclusion in ('timed_out', 'startup_failure'):
            with self.subTest(conclusion=conclusion):
                self.runs(self.run_(7, conclusion='failure'))
                self.jobs(7, ('checks', 'completed', conclusion), ('e2e', 'completed', 'failure'))
                out = self.result(3)
                self.assertEqual('red', out['state'])
                self.assertEqual(['checks', 'e2e'], out['failed_jobs'])
                self.assertEqual(['failed', 'failed'], [c['status'] for c in out['checks']])

    def test_a_red_job_next_to_a_cancelled_one_is_still_red_and_the_cancelled_one_is_unknown(self):
        self.runs(self.run_(7, conclusion='failure'))
        self.jobs(7, ('checks', 'completed', 'failure'), ('e2e', 'completed', 'cancelled'))
        out = self.result(3)
        self.assertEqual(['checks'], out['failed_jobs'])
        self.assertEqual(['failed', 'skipped'], self.statuses(out))
        self.assertIn('cancelled', out['reason'])

    # What made CI red: the parts of the required jobs and their failed steps.

    # The steps of ci.yml: the aggregates `checks` and `e2e`, and the first step of every part.
    REQUIRE_CHECKS = ('Require the scope job and every part of the checks', 'failure')
    REQUIRE_E2E = ('Require the scope job and every part of the end-to-end tests', 'failure')
    REQUIRE_SCOPE = ('Require the scope job', 'failure')

    def test_the_fixture_step_names_are_the_ones_of_the_ci_workflow(self):
        with open(WORKFLOW, encoding='utf-8') as f:
            names = re.findall(r'^\s*- name: (.+?)\s*$', f.read(), re.MULTILINE)
        for step in (self.REQUIRE_CHECKS, self.REQUIRE_E2E, self.REQUIRE_SCOPE):
            self.assertIn(step[0], names)

    def test_a_failed_step_of_a_checks_part_is_a_test_failure_named_by_part_and_step(self):
        self.runs(self.run_(7, conclusion='failure'))
        self.jobs(7, ('scope', 'completed', 'success'),
                  ('checks part k8s', 'completed', 'success', [('make -C infra/k8s check', 'success')]),
                  ('checks part apps', 'completed', 'failure',
                   [('Install dependencies', 'success'), ('api unit tests', 'failure'), ('VCL tests', 'skipped')]),
                  ('checks', 'completed', 'failure', [self.REQUIRE_CHECKS]),
                  ('e2e shard 1', 'completed', 'success', [('make e2e', 'success')]),
                  ('e2e', 'completed', 'success'))
        out = self.result(3)
        self.assertEqual('tests', out['failure_kind'])
        self.assertEqual([{'job': 'checks part apps', 'conclusion': 'failure', 'failed_steps': ['api unit tests']},
                          {'job': 'checks', 'conclusion': 'failure', 'failed_steps': [self.REQUIRE_CHECKS[0]]}],
                         out['failed_parts'])
        self.assertEqual([{'name': 'ci:checks@' + SHA, 'status': 'failed'}, {'name': 'ci:e2e@' + SHA, 'status': 'passed'}],
                         out['checks'])

    def test_a_failure_only_in_the_checks_after_e2e_is_a_test_failure_of_that_step(self):
        # The required job e2e is more than `make e2e`: its last shard also runs `make ci-after-e2e`.
        self.runs(self.run_(7, conclusion='failure'))
        self.jobs(7, ('checks', 'completed', 'success'),
                  ('e2e shard 1', 'completed', 'success', [('make e2e', 'success')]),
                  ('e2e shard 3', 'completed', 'failure', [('make e2e', 'success'), ('make ci-after-e2e', 'failure')]),
                  ('e2e', 'completed', 'failure', [self.REQUIRE_E2E]))
        out = self.result(3)
        self.assertEqual('tests', out['failure_kind'])
        self.assertEqual({'job': 'e2e shard 3', 'conclusion': 'failure', 'failed_steps': ['make ci-after-e2e']},
                         out['failed_parts'][0])
        self.assertEqual(['passed', 'failed'], self.statuses(out))

    def test_parts_that_only_timed_out_failed_to_start_or_were_cancelled_are_a_ci_failure(self):
        for conclusion in ('timed_out', 'startup_failure', 'cancelled'):
            with self.subTest(conclusion=conclusion):
                self.runs(self.run_(7, conclusion='failure'))
                self.jobs(7, ('checks part api functional', 'completed', conclusion, [('api tests', 'cancelled')]),
                          ('checks', 'completed', 'failure', [self.REQUIRE_CHECKS]),
                          ('e2e', 'completed', 'success'))
                out = self.result(3)
                self.assertEqual('ci', out['failure_kind'])
                self.assertEqual({'job': 'checks part api functional', 'conclusion': conclusion, 'failed_steps': []},
                                 out['failed_parts'][0])
                self.assertEqual(['failed', 'passed'], self.statuses(out))

    def test_a_failed_step_in_any_part_makes_it_a_test_failure_next_to_a_timed_out_part(self):
        self.runs(self.run_(7, conclusion='failure'))
        self.jobs(7, ('checks part api functional', 'completed', 'timed_out', [('api tests', 'cancelled')]),
                  ('checks', 'completed', 'failure', [self.REQUIRE_CHECKS]),
                  ('e2e shard 2', 'completed', 'failure', [('make e2e', 'failure')]),
                  ('e2e', 'completed', 'failure', [self.REQUIRE_E2E]))
        out = self.result(3)
        self.assertEqual('tests', out['failure_kind'])
        self.assertEqual(['checks part api functional', 'checks', 'e2e shard 2', 'e2e'],
                         [p['job'] for p in out['failed_parts']])

    def test_a_red_aggregate_without_a_red_part_is_a_ci_failure(self):
        self.runs(self.run_(7, conclusion='failure'))
        self.jobs(7, ('checks', 'completed', 'timed_out'), ('e2e', 'completed', 'success'))
        out = self.result(3)
        self.assertEqual('ci', out['failure_kind'])
        self.assertEqual([{'job': 'checks', 'conclusion': 'timed_out', 'failed_steps': []}], out['failed_parts'])

    def test_a_failed_part_without_a_failed_step_is_a_ci_failure(self):
        # A runner that lost its connection ends the part in failure with no step in failure.
        self.runs(self.run_(7, conclusion='failure'))
        self.jobs(7, ('scope', 'completed', 'success'),
                  ('checks part api functional', 'completed', 'failure', [('api tests', 'cancelled')]),
                  ('checks', 'completed', 'failure', [self.REQUIRE_CHECKS]),
                  ('e2e', 'completed', 'success'))
        out = self.result(3)
        self.assertEqual('ci', out['failure_kind'])
        self.assertEqual({'job': 'checks part api functional', 'conclusion': 'failure', 'failed_steps': []},
                         out['failed_parts'][0])

    # The scope job: every part and both required jobs need it and fail in their first step without it.

    def test_a_failed_step_of_the_scope_job_is_one_test_failure_without_the_parts_that_only_required_it(self):
        self.runs(self.run_(7, conclusion='failure'))
        self.jobs(7, ('scope', 'completed', 'failure', [('Scope script tests', 'failure')]),
                  ('checks part k8s', 'completed', 'failure', [self.REQUIRE_SCOPE, ('make -C infra/k8s check', 'skipped')]),
                  ('checks part apps', 'completed', 'failure', [self.REQUIRE_SCOPE]),
                  ('checks', 'completed', 'failure', [self.REQUIRE_CHECKS]),
                  ('e2e shard 1', 'completed', 'failure', [self.REQUIRE_SCOPE]),
                  ('e2e', 'completed', 'failure', [self.REQUIRE_E2E]))
        out = self.result(3)
        self.assertEqual('tests', out['failure_kind'])
        self.assertEqual([{'job': 'scope', 'conclusion': 'failure', 'failed_steps': ['Scope script tests']},
                          {'job': 'checks', 'conclusion': 'failure', 'failed_steps': [self.REQUIRE_CHECKS[0]]},
                          {'job': 'e2e', 'conclusion': 'failure', 'failed_steps': [self.REQUIRE_E2E[0]]}],
                         out['failed_parts'])
        self.assertEqual(['failed', 'failed'], self.statuses(out))

    def test_a_scope_job_that_did_not_finish_its_steps_is_a_ci_failure(self):
        for conclusion, steps in (('timed_out', [('Scope script tests', 'cancelled')]), ('startup_failure', []),
                                  ('cancelled', [('Scope script tests', 'cancelled')]),
                                  ('failure', [('Scope script tests', 'cancelled')])):
            with self.subTest(conclusion=conclusion):
                self.runs(self.run_(7, conclusion='failure'))
                self.jobs(7, ('scope', 'completed', conclusion, steps),
                          ('checks part apps', 'completed', 'failure', [self.REQUIRE_SCOPE]),
                          ('checks', 'completed', 'failure', [self.REQUIRE_CHECKS]),
                          ('e2e shard 1', 'completed', 'failure', [self.REQUIRE_SCOPE]),
                          ('e2e', 'completed', 'failure', [self.REQUIRE_E2E]))
                out = self.result(3)
                self.assertEqual('ci', out['failure_kind'])
                self.assertEqual(['scope', 'checks', 'e2e'], [p['job'] for p in out['failed_parts']])
                self.assertEqual({'job': 'scope', 'conclusion': conclusion, 'failed_steps': []}, out['failed_parts'][0])

    def test_without_a_budget_a_red_job_next_to_an_unfinished_one_leaves_that_one_unknown(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, ('checks part apps', 'completed', 'failure', [('api unit tests', 'failure')]),
                  ('checks', 'completed', 'failure', [self.REQUIRE_CHECKS]), ('e2e', 'in_progress', ''))
        out = self.result(3)
        self.assertEqual('red', out['state'])
        self.assertEqual(['failed', 'skipped'], self.statuses(out))
        self.assertEqual('tests', out['failure_kind'])

    def test_a_cancelled_skipped_or_neutral_job_of_a_finished_run_means_local_gates(self):
        for conclusion in ('cancelled', 'skipped', 'neutral'):
            with self.subTest(conclusion=conclusion):
                self.runs(self.run_(7, conclusion='cancelled'))
                self.jobs(7, ('checks', 'completed', 'success'), ('e2e', 'completed', conclusion))
                self.assertLocal('e2e')

    def test_a_missing_job_of_a_finished_run_means_local_gates(self):
        self.runs(self.run_(7))
        self.jobs(7, ('checks', 'completed', 'success'))
        self.assertLocal('no job e2e')

    def test_a_red_job_next_to_a_missing_one_of_a_finished_run_is_still_red(self):
        self.runs(self.run_(7, conclusion='failure'))
        self.jobs(7, ('e2e', 'completed', 'failure'))
        out = self.result(3)
        self.assertEqual(['e2e'], out['failed_jobs'])
        self.assertEqual(['skipped', 'failed'], self.statuses(out))

    def test_a_pull_request_run_does_not_count_even_if_gh_returns_it(self):
        run = self.run_(7)
        run['event'] = 'pull_request'
        self.runs(run)
        self.jobs(7, *self.GREEN)
        out = self.assertLocal('no push run')
        self.assertIn('budget', out['reason'])

    def test_without_a_budget_no_run_yet_means_local_gates(self):
        self.runs()
        self.assertLocal('no push run')

    def test_without_a_budget_a_run_in_progress_means_local_gates(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, ('checks', 'completed', 'success'), ('e2e', 'in_progress', ''))
        out = self.assertLocal('e2e')
        self.assertEqual(1, self.view_calls())
        self.assertEqual(0, out['waited_s'])

    def test_a_run_of_another_commit_means_local_gates(self):
        self.runs(self.run_(7, head='b' * 40))
        self.jobs(7, *self.GREEN, head='b' * 40)
        self.assertLocal('no push run')

    def test_the_latest_run_decides(self):
        self.runs(self.run_(7), self.run_(9, status='in_progress', conclusion=''))
        self.jobs(7, *self.GREEN)
        self.jobs(9, *self.RUNNING)
        self.assertLocal('checks')

    def test_jobs_of_another_commit_mean_local_gates(self):
        self.runs(self.run_(7))
        self.jobs(7, *self.GREEN, head='b' * 40)
        self.assertLocal('head')

    def test_a_failing_gh_means_local_gates(self):
        open(os.path.join(self.tmp.name, 'fail'), 'w').close()
        self.assertLocal('gh')

    def test_unreadable_gh_output_means_local_gates(self):
        with open(os.path.join(self.tmp.name, 'runs.json'), 'w') as f:
            f.write('<html>')
        self.assertLocal('gh')

    def test_a_missing_gh_means_local_gates(self):
        self.assertLocal('gh', path=self.tmp.name)

    # Waiting with a budget.

    def test_a_run_in_progress_that_turns_green_within_the_call_is_green(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs_seq(7, self.RUNNING, self.RUNNING, self.GREEN)
        out = self.result(0, budget=600, call='10')
        self.assertGreen(out)
        self.assertEqual(3, self.view_calls())
        self.assertGreaterEqual(out['waited_s'], 0)

    def test_a_run_that_turns_red_while_waiting_is_red(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs_seq(7, self.RUNNING, (('checks', 'completed', 'failure'), ('e2e', 'completed', 'success')))
        out = self.result(3, budget=600, call='10')
        self.assertEqual(['checks'], out['failed_jobs'])

    def test_a_red_job_waits_for_the_other_required_job(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs_seq(7, (('checks', 'completed', 'failure'), ('e2e', 'in_progress', '')),
                      (('checks', 'completed', 'failure'), ('e2e', 'completed', 'success')))
        out = self.result(3, budget=600, call='10')
        self.assertEqual(2, self.view_calls())
        self.assertEqual(['failed', 'passed'], [c['status'] for c in out['checks']])

    def test_a_run_that_appears_while_waiting_is_used(self):
        self.runs_seq([], [], [self.run_(7)])
        self.jobs(7, *self.GREEN)
        self.assertGreen(self.result(0, budget=600, call='10'))

    def test_still_running_at_the_end_of_the_call_with_budget_left_asks_to_call_again(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RUNNING)
        out = self.result(4, budget=600, call='1')
        self.assertEqual('pending', out['state'])
        self.assertFalse(out['use_ci'])
        self.assertEqual([], out['checks'])
        self.assertGreaterEqual(self.view_calls(), 2)
        self.assertGreaterEqual(out['waited_s'], 1)
        self.assertLess(out['waited_s'], 5)

    def test_still_running_after_the_budget_means_local_gates(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RUNNING)
        out = self.assertLocal('still running after the budget', budget=0.2)
        self.assertGreaterEqual(out['waited_s'], 0.2)

    def test_a_red_job_with_the_other_unfinished_after_the_budget_stays_red(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, ('checks', 'completed', 'failure'), ('e2e', 'in_progress', ''))
        out = self.result(3, budget=0.2)
        self.assertEqual(['checks'], out['failed_jobs'])
        self.assertEqual(['failed', 'skipped'], self.statuses(out))
        self.assertIn('e2e', out['reason'])

    RED_PENDING = (('checks part apps', 'completed', 'failure', [('api unit tests', 'failure')]),
                   ('checks', 'completed', 'failure'), ('e2e', 'in_progress', ''))
    RED_PART = {'job': 'checks part apps', 'conclusion': 'failure', 'failed_steps': ['api unit tests']}

    def test_a_failing_gh_after_a_red_job_while_waiting_stays_red(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs_seq(7, self.RED_PENDING, 'FAIL')
        out = self.result(3, budget=1.5, call='10')
        self.assertEqual('red', out['state'])
        self.assertEqual(7, out['run'])
        self.assertEqual(['checks'], out['failed_jobs'])
        self.assertEqual(['failed', 'skipped'], self.statuses(out))
        self.assertEqual('tests', out['failure_kind'])
        self.assertEqual(self.RED_PART, out['failed_parts'][0])
        self.assertGreaterEqual(self.view_calls(), 3)

    def test_a_failing_gh_while_waiting_is_waited_out_and_green_ci_is_used(self):
        self.runs_seq('FAIL', 'FAIL', [self.run_(7)])
        self.jobs(7, *self.GREEN)
        self.assertGreen(self.result(0, budget=600, call='10'))

    def test_a_failing_gh_with_budget_left_at_the_end_of_the_call_asks_to_call_again(self):
        open(os.path.join(self.tmp.name, 'fail'), 'w').close()
        out = self.result(4, budget=600)
        self.assertEqual('pending', out['state'])
        self.assertEqual([], out['checks'])
        self.assertIn('gh', out['reason'])

    def test_a_failing_gh_until_the_end_of_the_budget_means_local_gates(self):
        open(os.path.join(self.tmp.name, 'fail'), 'w').close()
        out = self.assertLocal('gh', budget=1, call='10')
        self.assertIn('budget', out['reason'])
        self.assertGreaterEqual(self.calls().count('run list'), 2)
        self.assertGreaterEqual(out['waited_s'], 1)

    def test_a_red_job_seen_in_an_earlier_call_stays_red_when_gh_fails_later(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RED_PENDING)
        self.result(4, budget=2, call='0.2')
        with open(self.wait_file) as f:
            self.assertEqual(['checks'], (json.load(f).get('red') or {}).get('failed_jobs'))
        open(os.path.join(self.tmp.name, 'fail'), 'w').close()
        out = self.result(3, budget=2, call='10')
        self.assertEqual('red', out['state'])
        self.assertEqual(7, out['run'])
        self.assertEqual(['checks'], out['failed_jobs'])
        self.assertEqual(['failed', 'skipped'], self.statuses(out))
        self.assertEqual('tests', out['failure_kind'])
        self.assertEqual(self.RED_PART, out['failed_parts'][0])

    def test_a_red_job_seen_in_an_earlier_call_stays_red_when_the_budget_ends(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RED_PENDING)
        self.result(4, budget=2, call='0.2')
        self.jobs(7, *self.RUNNING)
        out = self.result(3, budget=2, call='10')
        self.assertEqual(['checks'], out['failed_jobs'])
        self.assertEqual(['failed', 'skipped'], self.statuses(out))
        self.assertEqual('tests', out['failure_kind'])

    def test_broken_failed_parts_in_the_wait_file_keep_red_ci_as_a_ci_failure(self):
        self.write('ci-wait.json', {'head': SHA, 'started': time.time(), 'red': {
            'run': 7, 'failed_jobs': ['checks'], 'passed': [], 'reason': 'red', 'failed_parts': [{'job': 5}]}})
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RUNNING)
        out = self.result(3, budget=0.2)
        self.assertEqual(['failed', 'skipped'], self.statuses(out))
        self.assertEqual([], out['failed_parts'])
        self.assertEqual('ci', out['failure_kind'])

    def test_the_wait_file_keeps_the_start_of_the_first_call(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RUNNING)
        self.result(4, budget=600)
        with open(self.wait_file) as f:
            first = json.load(f)
        self.assertEqual(SHA, first['head'])
        self.result(4, budget=600)
        with open(self.wait_file) as f:
            self.assertEqual(first, json.load(f))
        self.jobs(7, *self.GREEN)
        out = self.result(0, budget=600)
        self.assertGreaterEqual(out['waited_s'], 0.6)

    def test_a_budget_spent_in_earlier_calls_ends_the_wait(self):
        self.write('ci-wait.json', {'head': SHA, 'started': time.time() - 700})
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RUNNING)
        out = self.assertLocal('still running after the budget', budget=600)
        self.assertEqual(1, self.view_calls())
        self.assertGreaterEqual(out['waited_s'], 700)

    def test_a_spent_budget_still_takes_finished_ci(self):
        self.write('ci-wait.json', {'head': SHA, 'started': time.time() - 700})
        self.runs(self.run_(7))
        self.jobs(7, *self.GREEN)
        self.assertGreen(self.result(0, budget=600))

    def test_a_wait_file_of_another_head_starts_a_new_wait(self):
        self.write('ci-wait.json', {'head': 'b' * 40, 'started': time.time() - 700})
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RUNNING)
        out = self.result(4, budget=600)
        self.assertLess(out['waited_s'], 5)
        with open(self.wait_file) as f:
            self.assertEqual(SHA, json.load(f)['head'])

    def test_a_start_outside_the_window_of_one_wait_starts_a_new_wait(self):
        # The window is now - budget - CALL_S <= started <= now; a file of an earlier AO round on the same head is older.
        texts = {'zero': '0', 'minus infinity': '-Infinity', 'infinity': 'Infinity', 'nan': 'NaN',
                 'future': repr(time.time() + 100), 'an earlier round': repr(time.time() - 600 - 500 - 100)}
        for case, started in texts.items():
            with self.subTest(case=case):
                with open(self.wait_file, 'w') as f:
                    f.write('{"head": "%s", "started": %s, "red": {"run": 7, "failed_jobs": ["checks"], '
                            '"passed": [], "reason": "old"}}' % (SHA, started))
                self.runs(self.run_(7, status='in_progress', conclusion=''))
                self.jobs(7, *self.RUNNING)
                out = self.result(4, budget=600)
                self.assertLess(out['waited_s'], 5)
                with open(self.wait_file) as f:
                    kept = json.load(f)
                self.assertNotIn('red', kept)
                self.assertGreater(kept['started'], time.time() - 60)

    def test_a_start_within_the_window_of_one_wait_is_kept(self):
        self.write('ci-wait.json', {'head': SHA, 'started': time.time() - 600 - 500 + 30})
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RUNNING)
        self.assertLocal('still running after the budget', budget=600)

    def test_a_broken_wait_file_starts_a_new_wait(self):
        with open(self.wait_file, 'w') as f:
            f.write('{broken')
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, *self.RUNNING)
        self.assertLess(self.result(4, budget=600)['waited_s'], 5)

    def test_the_default_poll_interval_and_call_limit(self):
        sys.path.insert(0, os.path.dirname(SCRIPT))
        try:
            import delta
        finally:
            sys.path.pop(0)
        self.assertEqual(30, delta.POLL_S)
        self.assertEqual(500, delta.CALL_S)

    def test_the_worst_call_ends_within_the_600_s_of_one_bash_call(self):
        # The last poll of a call starts at its end at the latest and makes two gh calls; the rest is Python start-up
        # and the wait file, with room for a slow machine.
        sys.path.insert(0, os.path.dirname(SCRIPT))
        try:
            import delta
        finally:
            sys.path.pop(0)
        margin_s = 40
        self.assertLess(delta.CALL_S + 2 * delta.GH_TIMEOUT_S + margin_s, 600)

    # Wrong arguments.

    def test_the_head_must_be_a_full_sha(self):
        r = self.ci(head='abc')
        self.assertEqual(2, r.returncode)
        self.assertIn('40', r.stderr)

    def test_a_budget_needs_a_wait_file_and_the_other_way_round(self):
        env = dict(os.environ, PATH=self.bin + os.pathsep + os.environ['PATH'], FAKE_GH=self.tmp.name)
        for extra in (['--budget', '600'], ['--wait-file', self.wait_file]):
            with self.subTest(extra=extra):
                r = subprocess.run([sys.executable, SCRIPT, 'ci', '--head', SHA, *extra], capture_output=True,
                                   text=True, env=env)
                self.assertEqual(2, r.returncode, r.stdout + r.stderr)
                self.assertIn('--wait-file', r.stderr)

    def test_a_negative_or_unreadable_budget_is_an_error(self):
        for budget in ('-1', 'ten'):
            with self.subTest(budget=budget):
                r = self.ci(extra=['--budget', budget, '--wait-file', self.wait_file])
                self.assertEqual(2, r.returncode, r.stdout + r.stderr)
                self.assertIn('budget', r.stderr)

    def assertError(self, r):
        self.assertEqual(2, r.returncode, r.stdout + r.stderr)
        self.assertIn('delta:', r.stderr)
        self.assertNotIn('Traceback', r.stderr)

    def test_unexpected_gh_output_is_an_error_not_a_traceback(self):
        self.runs(self.run_(7))
        self.write('run-7.json', {'headSha': SHA, 'jobs': 5})
        self.assertError(self.ci())

    def test_a_wrong_poll_interval_or_call_limit_is_an_error_not_a_traceback(self):
        self.runs(self.run_(7))
        self.jobs(7, *self.GREEN)
        for poll, call in (('x', '0.3'), ('0.02', 'x')):
            with self.subTest(poll=poll, call=call):
                self.assertError(self.ci(poll=poll, call=call))

    def test_an_unwritable_wait_file_is_an_error(self):
        self.runs(self.run_(7))
        self.jobs(7, *self.GREEN)
        r = self.ci(budget=600, wait_file=os.path.join(self.tmp.name, 'missing', 'ci-wait.json'))
        self.assertEqual(2, r.returncode, r.stdout + r.stderr)
        self.assertIn('wait file', r.stderr)


WORKFLOW = os.path.join(os.path.dirname(SCRIPT), '..', '..', '..', '.github', 'workflows', 'ci.yml')


class E2ePartsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.tmp.cleanup()

    def parts(self, *args, cwd=None):
        return subprocess.run([sys.executable, SCRIPT, 'e2e-parts', *args], capture_output=True, text=True, cwd=cwd)

    def workflow(self, text):
        path = os.path.join(self.tmp.name, 'ci.yml')
        with open(path, 'w') as f:
            f.write(textwrap.dedent(text))
        return path

    def test_reads_the_parts_of_the_ci_workflow_of_the_repository(self):
        r = self.parts(cwd=os.path.dirname(os.path.dirname(os.path.dirname(WORKFLOW))))
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertEqual({'parts': 3, 'weights': '110:125:115'}, json.loads(r.stdout))
        r = self.parts('--workflow', WORKFLOW)
        self.assertEqual({'parts': 3, 'weights': '110:125:115'}, json.loads(r.stdout))

    def test_reads_unquoted_and_double_quoted_weights(self):
        for value in ('100:200', '"100:200"'):
            with self.subTest(value=value):
                r = self.parts('--workflow', self.workflow('''\
                    env:
                      E2E_SHARD_WEIGHTS: %s
                    ''' % value))
                self.assertEqual(0, r.returncode, r.stderr)
                self.assertEqual({'parts': 2, 'weights': '100:200'}, json.loads(r.stdout))

    def test_a_workflow_without_weights_is_an_error(self):
        r = self.parts('--workflow', self.workflow('jobs: {}\n'))
        self.assertEqual(2, r.returncode)
        self.assertIn('E2E_SHARD_WEIGHTS', r.stderr)

    def test_malformed_or_conflicting_weights_are_an_error(self):
        for text in ("E2E_SHARD_WEIGHTS: '125:x:107'\n", "E2E_SHARD_WEIGHTS: '125::107'\n",
                     "E2E_SHARD_WEIGHTS: '0:1'\n", "E2E_SHARD_WEIGHTS: ''\n",
                     "a:\n  E2E_SHARD_WEIGHTS: '1:2'\nb:\n  E2E_SHARD_WEIGHTS: '2:1'\n"):
            with self.subTest(text=text):
                r = self.parts('--workflow', self.workflow(text))
                self.assertEqual(2, r.returncode, r.stdout + r.stderr)
                self.assertIn('E2E_SHARD_WEIGHTS', r.stderr)

    def test_a_missing_workflow_is_an_error(self):
        r = self.parts('--workflow', os.path.join(self.tmp.name, 'none.yml'))
        self.assertEqual(2, r.returncode)
        self.assertIn('none.yml', r.stderr)


if __name__ == '__main__':
    unittest.main()

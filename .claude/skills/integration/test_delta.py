"""Tests of delta.py: python3 -m unittest discover -s .claude/skills/integration -p 'test_*.py'"""
import json
import os
import stat
import subprocess
import sys
import tempfile
import textwrap
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


class CiTest(unittest.TestCase):
    """`ci` with a fake gh on PATH: the runs and jobs it prints come from files of the test."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.bin = os.path.join(self.tmp.name, 'bin')
        os.makedirs(self.bin)
        gh = os.path.join(self.bin, 'gh')
        with open(gh, 'w') as f:
            f.write(textwrap.dedent('''\
                #!/bin/sh
                echo "$*" >> "$FAKE_GH/calls"
                [ -f "$FAKE_GH/fail" ] && { echo "gh: HTTP 502" >&2; exit 1; }
                case "$1 $2" in
                    "run list") cat "$FAKE_GH/runs.json" ;;
                    "run view") cat "$FAKE_GH/run-$3.json" ;;
                    *) echo "unexpected: $*" >&2; exit 1 ;;
                esac
                '''))
        os.chmod(gh, os.stat(gh).st_mode | stat.S_IEXEC)

    def tearDown(self):
        self.tmp.cleanup()

    def runs(self, *runs):
        with open(os.path.join(self.tmp.name, 'runs.json'), 'w') as f:
            json.dump(list(runs), f)

    def jobs(self, run_id, *jobs, head=SHA):
        with open(os.path.join(self.tmp.name, 'run-%d.json' % run_id), 'w') as f:
            json.dump({'headSha': head, 'jobs': [{'name': n, 'status': s, 'conclusion': c} for n, s, c in jobs]}, f)

    def ci(self, head=SHA, path=None):
        env = dict(os.environ, FAKE_GH=self.tmp.name, PATH=path if path is not None else self.bin + os.pathsep + os.environ['PATH'])
        return subprocess.run([sys.executable, SCRIPT, 'ci', '--head', head], capture_output=True, text=True, env=env)

    def calls(self):
        with open(os.path.join(self.tmp.name, 'calls')) as f:
            return f.read()

    def run_(self, run_id, head=SHA, status='completed', conclusion='success', created='2026-11-08T10:00:00Z'):
        return {'databaseId': run_id, 'headSha': head, 'status': status, 'conclusion': conclusion, 'event': 'push',
                'createdAt': created}

    GREEN = (('scope', 'completed', 'success'), ('checks', 'completed', 'success'), ('e2e', 'completed', 'success'))

    def test_green_required_jobs_of_a_push_run_of_the_head_replace_the_local_gates(self):
        self.runs(self.run_(7))
        self.jobs(7, *self.GREEN)
        r = self.ci()
        self.assertEqual(0, r.returncode, r.stdout + r.stderr)
        out = json.loads(r.stdout)
        self.assertTrue(out['use_ci'])
        self.assertEqual(SHA, out['sha'])
        self.assertEqual(7, out['run'])
        self.assertEqual(['ci:checks@' + SHA, 'ci:e2e@' + SHA], [c['name'] for c in out['checks']])
        self.assertIn('--commit %s' % SHA, self.calls())
        self.assertIn('--event push', self.calls())

    def assertLocal(self, fragment, **kwargs):
        r = self.ci(**kwargs)
        self.assertEqual(1, r.returncode, r.stdout + r.stderr)
        out = json.loads(r.stdout)
        self.assertFalse(out['use_ci'])
        self.assertIn(fragment, out['reason'])

    def test_asks_gh_for_push_runs_of_the_ci_workflow(self):
        self.runs(self.run_(7))
        self.jobs(7, *self.GREEN)
        self.ci()
        self.assertIn('--workflow ci.yml', self.calls())

    def test_a_pull_request_run_does_not_count_even_if_gh_returns_it(self):
        run = self.run_(7)
        run['event'] = 'pull_request'
        self.runs(run)
        self.jobs(7, *self.GREEN)
        self.assertLocal('no push run')

    def test_no_run_of_the_head_means_local_gates(self):
        self.runs()
        self.assertLocal('no push run')

    def test_a_run_of_another_commit_means_local_gates(self):
        self.runs(self.run_(7, head='b' * 40))
        self.jobs(7, *self.GREEN, head='b' * 40)
        self.assertLocal('no push run')

    def test_a_run_in_progress_means_local_gates(self):
        self.runs(self.run_(7, status='in_progress', conclusion=''))
        self.jobs(7, ('checks', 'completed', 'success'), ('e2e', 'in_progress', ''))
        self.assertLocal('e2e')

    def test_the_latest_run_decides(self):
        self.runs(self.run_(7), self.run_(9, status='in_progress', conclusion=''))
        self.jobs(7, *self.GREEN)
        self.jobs(9, ('checks', 'in_progress', ''), ('e2e', 'queued', ''))
        self.assertLocal('checks')

    def test_a_red_or_missing_required_job_means_local_gates(self):
        self.runs(self.run_(7))
        self.jobs(7, ('checks', 'completed', 'success'), ('e2e', 'completed', 'failure'))
        self.assertLocal('e2e')
        self.jobs(7, ('checks', 'completed', 'success'))
        self.assertLocal('e2e')

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

    def test_the_head_must_be_a_full_sha(self):
        r = self.ci(head='abc')
        self.assertEqual(2, r.returncode)
        self.assertIn('40', r.stderr)


if __name__ == '__main__':
    unittest.main()

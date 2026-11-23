"""Tests of `ci-time` and of `ci.time` of the native record (spec: Czas CI po epiku). `gh` is a stub; nothing leaves
the machine. Times are minutes after T0; the epic starts at minute 1000."""
import json
import os
import unittest

import metrics
import testkit
from testkit import FakeGh, NativeEnv, T0, run_cli

HERE = os.path.dirname(os.path.abspath(__file__))
START = 1000                    # the start of the epic, minutes after T0
HEAD = 'c' * 40                 # the head of the final PR
MERGE = 'd' * 40                # its merge commit on main
PR = testkit.PR_NUMBER
BRANCH = 'feat/demo'
JOBS = {'checks part apps': 6, 'checks part k8s': 9, 'e2e shard 1': 10, 'e2e shard 2': 10, 'e2e shard 3': 10,
        'e2e shard 4': 10}


def at(minutes):
    return metrics.iso(T0 + minutes * 60)


def ci_run(run_id, minute, sha='a' * 40, event='push', conclusion='success', status='completed', attempt=1):
    return {'databaseId': run_id, 'createdAt': at(minute), 'conclusion': conclusion, 'status': status,
            'headSha': sha, 'event': event, 'url': 'https://example.test/runs/%d' % run_id, 'attempt': attempt}


def jobs_of(minute, durations=None, full=True, wait=0, steps=None, **changed):
    """The jobs of a run created at `minute`: every job starts one minute later (`wait` more for the last one, a wait
    for a runner between the jobs) and takes its minutes. The e2e parts run the step `make e2e` in a full run."""
    durations = dict(JOBS if durations is None else durations, **{k.replace('_', ' '): v for k, v in changed.items()})
    found = [{'name': 'scope', 'startedAt': at(minute), 'completedAt': at(minute + 0.5), 'conclusion': 'success'}]
    names = list(durations)
    for name in names:
        begin = minute + 1 + (wait if name == names[-1] else 0)
        job = {'name': name, 'startedAt': at(begin), 'completedAt': at(begin + durations[name]),
               'conclusion': 'success', 'steps': []}
        if name.startswith('e2e shard'):
            job['steps'] = [{'name': 'Wait for the stack', 'startedAt': at(begin), 'completedAt': at(begin + 2),
                             'conclusion': 'success'},
                            {'name': 'make e2e', 'startedAt': at(begin + 2), 'completedAt': at(begin + durations[name]),
                             'conclusion': 'success' if full else 'skipped'}]
        for step_name, (first, last) in (steps or {}).get(name, {}).items():
            job['steps'].append({'name': step_name, 'startedAt': at(begin + first), 'completedAt': at(begin + last),
                                 'conclusion': 'success'})
        found.append(job)
    return found


class CiTimeEnv(NativeEnv):
    def setUp(self):
        super().setUp()
        self.write_run(started=START * 60, done=(START + 300) * 60, pr=PR, branch=BRANCH)
        self.main, self.branch, self.jobs, self.merge_runs = [], [], {}, []
        self.pr = {'headRefOid': HEAD, 'state': 'OPEN', 'mergeCommit': None, 'mergedAt': None,
                   'closingIssuesReferences': []}
        self.next_id = 100

    def add(self, where, minute, durations=None, full=True, measured=True, wait=0, steps=None, **run):
        self.next_id += 1
        entry = ci_run(self.next_id, minute, **run)
        where.append(entry)
        if measured:
            self.jobs[self.next_id] = jobs_of(minute, durations, full=full, wait=wait, steps=steps)
        return entry

    def baseline(self, count=5, **changed):
        """`count` full green push runs of main before the start of the epic, ten minutes apart."""
        for i in range(count):
            self.add(self.main, START - 100 + i * 10, dict(JOBS, **changed))

    def final(self, minute=START + 200, **changed):
        return self.add(self.branch, minute, dict(JOBS, **{k.replace('_', ' '): v for k, v in changed.items()}),
                        sha=HEAD, event='pull_request')

    def earlier(self, minute, **changed):
        return self.add(self.branch, minute, dict(JOBS, **{k.replace('_', ' '): v for k, v in changed.items()}),
                        sha='e' * 40, event='pull_request')

    def merged(self, minute=START + 260, conclusion='success', status='completed', measured=True, attempt=1,
               **changed):
        self.pr.update(state='MERGED', mergedAt=at(minute), mergeCommit={'oid': MERGE})
        return self.add(self.merge_runs, minute, dict(JOBS, **{k.replace('_', ' '): v for k, v in changed.items()}),
                        sha=MERGE, conclusion=conclusion, status=status, measured=measured, attempt=attempt)

    def gh(self, **kwargs):
        runs = {'main': self.main + self.merge_runs, BRANCH: self.branch, MERGE: self.merge_runs}
        return FakeGh(prs={PR: self.pr}, runs=runs, jobs=self.jobs, **kwargs)

    def cli(self, *extra, gh=None, epic='demo'):
        return run_cli(['ci-time', epic, '--state', self.state] + list(extra), gh or self.gh())

    def result(self, *extra, gh=None):
        code, out, err = self.cli('--json', *extra, gh=gh)
        self.assertEqual(code, 0, err)
        return json.loads(out)

    def row(self, result, name):
        return next(r for r in result['rows'] if r['name'] == name)


class BaselineTest(CiTimeEnv):
    def test_the_baseline_is_the_median_of_the_five_last_full_green_push_runs_of_main_before_the_start(self):
        self.add(self.main, START - 300, dict(JOBS, **{'checks part k8s': 40}))         # the sixth: too old
        self.baseline()
        self.final()
        result = self.result()
        self.assertEqual(result['status'], 'ok')
        self.assertEqual(len(result['baseline']['runs']), 5)
        self.assertEqual(result['baseline']['before'], at(START))
        self.assertEqual(self.row(result, 'checks part k8s')['base_s'], 9 * 60)
        # from the start of the first job (the scope job, at the creation of the run) to the end of the last one
        self.assertEqual(self.row(result, 'run')['base_s'], 11 * 60)

    def test_runs_that_are_not_comparable_are_not_in_the_baseline(self):
        self.baseline()
        slow = dict(JOBS, **{'checks part k8s': 40})
        self.add(self.main, START - 9, slow, full=False)                                # a limited scope
        self.add(self.main, START - 8, slow, conclusion='failure')                      # red
        self.add(self.main, START - 7, slow, conclusion='', status='in_progress')       # not over
        self.add(self.main, START - 6, slow, event='pull_request')                      # not a push to main
        self.add(self.main, START + 5, slow)                                            # after the start
        self.final()
        result = self.result()
        self.assertEqual(self.row(result, 'checks part k8s')['base_s'], 9 * 60)
        self.assertEqual([r['created_at'] for r in result['baseline']['runs']],
                         [at(START - 100 + i * 10) for i in range(5)])

    def test_the_runs_of_main_are_asked_for_by_their_time_before_and_from_the_start(self):
        self.baseline()
        self.final()
        gh = self.gh()
        self.result(gh=gh)
        created = [c[c.index('--created') + 1] for c in gh.calls if c[:3] == ['gh', 'run', 'list'] and '--created' in c]
        self.assertEqual(created, ['<%s' % at(START), '>=%s' % at(START)])
        for call in gh.calls:
            if call[:3] == ['gh', 'run', 'list'] and '--created' in call:
                self.assertEqual((call[call.index('--branch') + 1], call[call.index('--event') + 1]), ('main', 'push'))

    def test_a_run_that_was_run_again_is_not_comparable(self):
        # after "Re-run failed jobs" the jobs kept from the first attempt have their old times: the span of the run
        # holds the red attempt and the pause before the rerun
        self.baseline()
        self.add(self.main, START - 5, dict(JOBS, **{'checks part k8s': 40}), attempt=2)
        self.earlier(START + 100)
        self.add(self.branch, START + 150, dict(JOBS, **{'e2e shard 1': 40}), sha='e' * 40, event='pull_request',
                 attempt=2)
        self.final()
        result = self.result()
        self.assertEqual(result['status'], 'ok')
        self.assertEqual(self.row(result, 'checks part k8s')['base_s'], 9 * 60)
        self.assertEqual(len(result['recent']['runs']), 2)
        self.assertEqual(self.row(result, 'e2e shard 1')['recent_s'], 10 * 60)

    def test_a_job_in_fewer_baseline_runs_than_the_minimum_has_no_baseline(self):
        for i in range(5):                              # the job is new: only the two last baseline runs have it
            self.add(self.main, START - 100 + i * 10, dict(JOBS, **({'checks part new': 1} if i >= 3 else {})))
        self.final(checks_part_new=30)
        result = self.result()
        row = self.row(result, 'checks part new')
        self.assertEqual((row['base_s'], row['final_s'], row['final_exceeded']), (None, 30 * 60, None))
        self.assertNotIn({'name': 'checks part new', 'source': 'final'}, result['exceeded'])

    def test_fewer_than_three_baseline_runs_is_no_data_never_ok(self):
        self.baseline(2)
        self.final()
        result = self.result()
        self.assertEqual(result['status'], 'no_data')
        self.assertIn('baz', result['reason'])
        self.assertEqual(result['exceeded'], [])
        code, out, err = self.cli()
        self.assertEqual(code, 0, err)
        self.assertIn('brak danych', out)
        self.assertNotIn('PASS', out)
        self.assertNotIn('bez przekroczenia', out)

    def test_the_time_of_the_run_holds_the_wait_between_the_jobs_and_the_times_of_the_jobs_do_not(self):
        self.baseline()
        self.add(self.branch, START + 200, wait=4, sha=HEAD, event='pull_request')      # the last job waits 4 minutes
        result = self.result()
        self.assertEqual(self.row(result, 'run')['final_s'], 15 * 60)                   # 11 + the wait
        self.assertEqual(self.row(result, 'e2e shard 4')['final_s'], 10 * 60)
        self.assertEqual(self.row(result, 'e2e shard 4')['final_diff_s'], 0)


class ThresholdTest(CiTimeEnv):
    def test_a_final_run_within_the_thresholds_is_ok(self):
        self.baseline()
        self.final(checks_part_k8s=11, e2e_shard_1=12)          # +2 minutes: below the thresholds of a job
        result = self.result()
        self.assertEqual((result['status'], result['exceeded']), ('ok', []))
        self.assertEqual(self.row(result, 'e2e shard 1')['final_diff_s'], 120)
        self.assertEqual(self.row(result, 'e2e shard 1')['final_diff_pct'], 20.0)

    def test_a_run_longer_by_the_time_and_the_share_of_the_thresholds_is_exceeded(self):
        self.baseline()
        self.final(e2e_shard_1=13.5)                            # run 11 -> 14.5 min: +3.5 min, +32%
        result = self.result()
        self.assertEqual(result['status'], 'exceeded')
        row = self.row(result, 'run')
        self.assertEqual((row['final_diff_s'], row['final_exceeded']), (210, True))
        self.assertIn({'name': 'run', 'source': 'final'}, result['exceeded'])

    def test_the_share_alone_does_not_exceed(self):
        self.baseline(**{'checks part apps': 1})
        self.final(checks_part_apps=3)                          # +200%, but +2 minutes
        result = self.result()
        self.assertEqual(result['status'], 'ok')
        self.assertFalse(self.row(result, 'checks part apps')['final_exceeded'])

    def test_the_time_alone_does_not_exceed(self):
        long_jobs = {'checks part k8s': 40, 'e2e shard 1': 10}
        for i in range(5):
            self.add(self.main, START - 100 + i * 10, long_jobs)
        self.add(self.branch, START + 200, {'checks part k8s': 44.5, 'e2e shard 1': 10}, sha=HEAD,
                 event='pull_request')                          # +4.5 minutes, +11%
        result = self.result()
        self.assertEqual(result['status'], 'ok')
        self.assertEqual(self.row(result, 'checks part k8s')['final_diff_s'], 270)

    def test_a_job_off_the_longest_path_is_exceeded_while_the_run_is_not(self):
        self.baseline(**{'checks part apps': 2})
        self.final(checks_part_apps=7)                          # +5 min, +250%; the run stays 11 minutes
        result = self.result()
        self.assertEqual(result['status'], 'exceeded')
        self.assertEqual(result['exceeded'], [{'name': 'checks part apps', 'source': 'final'}])
        self.assertEqual(self.row(result, 'run')['final_diff_s'], 0)

    def test_exactly_the_thresholds_exceed(self):
        self.baseline()                                         # run 11 min: +3 min is +27%; job 10 min: +4 is +40%
        self.final(e2e_shard_1=14)
        result = self.result()
        self.assertIn({'name': 'run', 'source': 'final'}, result['exceeded'])
        self.assertIn({'name': 'e2e shard 1', 'source': 'final'}, result['exceeded'])

    def test_the_thresholds_are_in_the_result_and_marked_as_starting_values_in_the_text(self):
        self.baseline()
        self.final()
        self.assertEqual(self.result()['thresholds'], {'run_min_s': 180, 'run_min_pct': 20, 'job_min_s': 240,
                                                       'job_min_pct': 30, 'e2e_spread_s': 240})
        out = self.cli()[1]
        self.assertIn('startowe', out)
        self.assertIn('+3,0 min i +20%', out)
        self.assertIn('+4,0 min i +30%', out)


class FinalShaTest(CiTimeEnv):
    def test_the_final_commit_is_shown_apart_from_the_median_of_the_last_runs(self):
        self.baseline()
        self.earlier(START + 100)
        self.earlier(START + 150)
        self.final(e2e_shard_1=16)                              # only the final commit is slow
        result = self.result()
        row = self.row(result, 'e2e shard 1')
        self.assertEqual((row['final_s'], row['final_exceeded']), (16 * 60, True))
        self.assertEqual((row['recent_s'], row['recent_exceeded']), (10 * 60, False))   # the median hides it
        self.assertEqual(result['status'], 'exceeded')
        self.assertEqual(result['final']['sha'], HEAD)
        self.assertEqual(result['final']['run']['url'], 'https://example.test/runs/%d' % self.next_id)
        self.assertEqual(len(result['recent']['runs']), 3)

    def test_the_median_of_the_last_runs_exceeds_while_the_final_run_does_not(self):
        self.baseline()
        self.earlier(START + 100, e2e_shard_1=16)
        self.earlier(START + 150, e2e_shard_1=16)
        self.final()
        result = self.result()
        row = self.row(result, 'e2e shard 1')
        self.assertEqual((row['final_exceeded'], row['recent_exceeded']), (False, True))
        self.assertIn({'name': 'e2e shard 1', 'source': 'recent'}, result['exceeded'])

    def test_one_run_is_no_median_of_the_last_runs(self):
        self.baseline()
        self.final()
        row = self.row(self.result(), 'run')
        self.assertIsNone(row['recent_s'])
        self.assertIsNone(row['recent_exceeded'])

    def test_no_full_green_run_of_the_final_commit_is_no_data(self):
        for state, reason in (({'conclusion': 'failure'}, 'czerwony'),
                              ({'conclusion': '', 'status': 'in_progress'}, 'w toku'),
                              (None, 'brak przebiegu')):
            with self.subTest(state=state):
                self.setUp()
                self.baseline()
                self.earlier(START + 100)
                self.earlier(START + 150)
                if state is not None:
                    self.add(self.branch, START + 200, sha=HEAD, event='pull_request', **state)
                result = self.result()
                self.assertEqual(result['status'], 'no_data')
                self.assertIsNone(result['final']['run'])
                self.assertIn(reason, result['final']['reason'])
                self.assertIsNone(self.row(result, 'run')['final_s'])
                self.assertEqual(self.row(result, 'run')['recent_s'], 11 * 60)          # still shown

    def test_a_final_run_that_was_run_again_is_no_data_with_the_reason(self):
        self.baseline()
        self.add(self.branch, START + 200, dict(JOBS, **{'e2e shard 1': 40}), sha=HEAD, event='pull_request',
                 attempt=2)
        result = self.result()
        self.assertEqual((result['status'], result['exceeded']), ('no_data', []))
        self.assertIn('ponowion', result['final']['reason'])
        self.assertIn('ponowion', self.cli()[1])

    def test_a_final_run_of_a_limited_scope_is_no_data(self):
        self.baseline()
        self.add(self.branch, START + 200, full=False, sha=HEAD, event='pull_request')
        result = self.result()
        self.assertEqual(result['status'], 'no_data')
        self.assertIn('ograniczon', result['final']['reason'])

    def test_a_cancelled_or_older_run_of_the_final_commit_does_not_hide_the_green_one(self):
        self.baseline()
        self.add(self.branch, START + 190, sha=HEAD, event='pull_request', conclusion='cancelled', measured=False)
        self.final(e2e_shard_1=11)
        self.assertEqual(self.row(self.result(), 'e2e shard 1')['final_s'], 11 * 60)


class ExternalTest(CiTimeEnv):
    def test_the_runs_of_main_during_the_epic_are_shown_as_a_possible_outside_cause(self):
        self.baseline()
        for minute in (START + 20, START + 60, START + 120):
            self.add(self.main, minute, dict(JOBS, **{'checks part k8s': 15}))          # main itself got slower
        self.add(self.main, START + 250, dict(JOBS, **{'checks part k8s': 30}))         # after the final run: not counted
        self.final(checks_part_k8s=15)
        result = self.result()
        row = self.row(result, 'checks part k8s')
        self.assertEqual((row['base_s'], row['during_s'], row['final_s']), (9 * 60, 15 * 60, 15 * 60))
        self.assertTrue(row['final_exceeded'])
        self.assertTrue(row['during_exceeded'])
        self.assertEqual(len(result['during']['runs']), 3)
        out = self.cli()[1]
        self.assertIn('main w trakcie epiku', out)
        self.assertIn('możliwy wpływ zewnętrzny', out)

    def test_no_run_of_main_during_the_epic_is_shown_as_no_data(self):
        self.baseline()
        self.final()
        result = self.result()
        self.assertEqual(result['during']['runs'], [])
        self.assertIsNone(self.row(result, 'run')['during_s'])
        self.assertIsNone(self.row(result, 'run')['during_exceeded'])


class MergeTest(CiTimeEnv):
    def test_before_the_merge_there_is_no_measurement_on_main(self):
        self.baseline()
        self.final()
        result = self.result()
        self.assertEqual(result['merge'], {'sha': None, 'state': 'not_merged', 'run': None})
        self.assertIn('przed merge', self.cli()[1])

    def test_the_run_of_the_merge_commit_confirms_the_measurement_on_main(self):
        self.baseline()
        self.final()
        self.merged(e2e_shard_1=16)
        result = self.result()
        self.assertEqual((result['merge']['sha'], result['merge']['state']), (MERGE, 'measured'))
        row = self.row(result, 'e2e shard 1')
        self.assertEqual((row['merge_s'], row['merge_diff_s'], row['merge_exceeded']), (16 * 60, 360, True))
        self.assertIn({'name': 'e2e shard 1', 'source': 'merge'}, result['exceeded'])
        self.assertEqual(result['status'], 'exceeded')

    def test_a_merge_run_that_is_not_over_is_not_a_result(self):
        for run, state in (({'conclusion': '', 'status': 'in_progress'}, 'in_progress'),
                           ({'conclusion': 'failure'}, 'not_green')):
            with self.subTest(state=state):
                self.setUp()
                self.baseline()
                self.final()
                self.merged(**run)
                result = self.result()
                self.assertEqual(result['merge']['state'], state)
                self.assertIsNone(self.row(result, 'run')['merge_s'])
                self.assertIsNone(self.row(result, 'run')['merge_exceeded'])
                self.assertEqual(result['status'], 'no_data')                 # merged, but not confirmed on main yet
                self.assertIn('main', result['reason'])

    def test_a_merge_run_that_was_run_again_is_not_measured(self):
        self.baseline()
        self.final()
        self.merged(attempt=2, e2e_shard_1=40)
        result = self.result()
        self.assertEqual((result['merge']['state'], result['status'], result['exceeded']), ('rerun', 'no_data', []))
        self.assertIn('ponowion', result['reason'])

    def test_a_merged_pull_request_without_a_run_on_main_is_no_data(self):
        self.baseline()
        self.final()
        self.pr.update(state='MERGED', mergedAt=at(START + 260), mergeCommit={'oid': MERGE})
        result = self.result()
        self.assertEqual((result['merge']['state'], result['status']), ('missing', 'no_data'))

    def test_the_merge_run_is_not_a_run_of_main_during_the_epic(self):
        self.baseline()
        self.final(minute=START + 200)
        self.merged(minute=START + 190)                 # created before the last look at the PR: still not "during"
        self.assertEqual(self.result()['during']['runs'], [])


class JobsTest(CiTimeEnv):
    def test_a_job_on_one_side_only_is_listed_and_has_no_difference(self):
        self.baseline(**{'checks part old': 5})
        self.final(checks_part_new=30)
        result = self.result()
        self.assertEqual(result['jobs_changed'], {'only_baseline': ['checks part old'], 'only_final': ['checks part new']})
        row = self.row(result, 'checks part new')
        self.assertEqual((row['base_s'], row['final_diff_s'], row['final_exceeded']), (None, None, None))
        self.assertEqual(result['status'], 'exceeded')          # the run itself: 11 -> 31 minutes
        out = self.cli()[1]
        self.assertIn('zmiana zestawu jobów', out.lower())

    def test_the_aggregate_jobs_and_unexpanded_matrix_names_are_not_rows(self):
        self.baseline()
        self.final()
        self.jobs[self.next_id] += [{'name': 'checks', 'startedAt': at(START + 201), 'completedAt': at(START + 202),
                                     'conclusion': 'success'},
                                    {'name': 'e2e shard ${{ matrix.index }}', 'startedAt': at(START + 201),
                                     'completedAt': at(START + 202), 'conclusion': 'skipped'}]
        names = [r['name'] for r in self.result()['rows']]
        self.assertEqual(names, ['run'] + sorted(JOBS))

    def test_the_e2e_parts_of_another_matrix_are_not_compared(self):
        # three parts before the epic, four after it: "e2e shard 1" is another set of spec files on each side
        three = {k: v for k, v in JOBS.items() if k != 'e2e shard 4'}
        for i in range(5):
            self.add(self.main, START - 100 + i * 10, three)
        self.final(e2e_shard_1=16, checks_part_apps=6.5)
        result = self.result()
        self.assertTrue(result['e2e_parts_changed'])
        row = self.row(result, 'e2e shard 1')
        self.assertEqual((row['base_s'], row['final_s']), (10 * 60, 16 * 60))            # both still shown
        self.assertEqual((row['final_diff_s'], row['final_exceeded']), (None, None))
        self.assertEqual(self.row(result, 'checks part apps')['final_diff_s'], 30)      # other jobs are compared
        self.assertNotIn({'name': 'e2e shard 1', 'source': 'final'}, result['exceeded'])
        self.assertIn({'name': 'run', 'source': 'final'}, result['exceeded'])           # the run itself: 11 -> 17
        self.assertIn('części E2E nie są porównywane', self.cli()[1])

    def test_a_baseline_of_both_matrices_is_another_matrix_too(self):
        # the matrix changed inside the baseline: three runs with three parts, then two with four, four after the epic
        three = {k: v for k, v in JOBS.items() if k != 'e2e shard 4'}
        for i in range(5):
            self.add(self.main, START - 100 + i * 10, three if i < 3 else JOBS)
        self.final(e2e_shard_1=16)
        result = self.result()
        self.assertTrue(result['e2e_parts_changed'])
        self.assertEqual(self.row(result, 'e2e shard 1')['final_exceeded'], None)
        self.assertNotIn({'name': 'e2e shard 1', 'source': 'final'}, result['exceeded'])

    def test_the_same_e2e_parts_are_compared(self):
        self.baseline()
        self.final()
        self.assertFalse(self.result()['e2e_parts_changed'])

    def test_the_spread_of_the_e2e_parts_comes_from_the_median_of_the_last_runs(self):
        self.baseline()
        self.earlier(START + 100, e2e_shard_1=15)
        self.earlier(START + 150, e2e_shard_1=15)
        self.final(e2e_shard_1=15)                              # part 1 ends 5 minutes after the others
        result = self.result()
        self.assertEqual(result['e2e_spread'], {'seconds': 300, 'exceeded': True, 'longest': 'e2e shard 1',
                                                'shortest': 'e2e shard 2'})
        self.assertIn('wyrównani', self.cli()[1])

    def test_a_spread_over_the_threshold_is_named_in_the_title_of_a_result_within_the_thresholds(self):
        slow_first = dict(JOBS, **{'e2e shard 1': 15})          # uneven before the epic too: nothing got slower
        for i in range(5):
            self.add(self.main, START - 100 + i * 10, slow_first)
        self.earlier(START + 150, e2e_shard_1=15)
        self.final(e2e_shard_1=15)
        result = self.result()
        self.assertEqual((result['status'], result['e2e_spread']['exceeded']), ('ok', True))
        title = self.cli()[1].splitlines()[0]
        self.assertIn('bez przekroczenia progów czasu', title)
        self.assertIn('rozrzut części E2E ponad progiem', title)

    def test_one_run_gives_no_spread_of_the_e2e_parts(self):
        self.baseline()
        self.final(e2e_shard_1=15)
        self.assertEqual(self.result()['e2e_spread'], {'seconds': None, 'exceeded': None, 'longest': None,
                                                       'shortest': None})

    def test_an_exceeded_job_shows_the_steps_that_grew_most(self):
        self.baseline()
        steps = {'e2e shard 1': {'Seed demo data': (0, 1)}}
        self.add(self.branch, START + 200, dict(JOBS, **{'e2e shard 1': 16}), sha=HEAD, event='pull_request',
                 steps=steps)
        row = self.row(self.result(), 'e2e shard 1')
        self.assertEqual(row['steps'][0], {'name': 'make e2e', 'base_s': 480, 'final_s': 840, 'diff_s': 360})
        self.assertIn({'name': 'Seed demo data', 'base_s': None, 'final_s': 60, 'diff_s': None}, row['steps'])


class ErrorsTest(CiTimeEnv):
    def test_a_gh_error_is_no_data_and_exit_3(self):
        self.baseline()
        self.final()
        for failing in (('run', 'list'), ('run', 'view'), ('pr', 'view')):
            with self.subTest(failing=failing):
                code, out, err = self.cli('--json', gh=self.gh(fail_on=[failing]))
                self.assertEqual(code, 3, err)
                result = json.loads(out)
                self.assertEqual(result['status'], 'no_data')
                self.assertIn('gh', result['reason'])
                self.assertIn('metrics:', err)
                code, out, err = self.cli(gh=self.gh(fail_on=[failing]))
                self.assertEqual(code, 3)
                self.assertIn('brak danych', out)

    def test_no_run_file_is_a_fatal_error(self):
        code, out, err = self.cli(epic='other')
        self.assertEqual(code, 2)
        self.assertIn('brak pliku przebiegu', err)
        self.assertEqual(out, '')

    def test_a_run_file_without_the_pull_request_or_the_start_is_no_data(self):
        for missing in ({'pr': None}, {'branch': None}, {'started_at': None}):
            with self.subTest(missing=missing):
                self.write_run(**dict({'started': START * 60, 'pr': PR, 'branch': BRANCH}, **missing))
                result = self.result()
                self.assertEqual(result['status'], 'no_data')
                self.assertIn('pliku przebiegu', result['reason'])

    def test_a_run_without_the_times_of_its_jobs_is_skipped(self):
        self.baseline()
        broken = self.add(self.main, START - 5, measured=False)
        self.jobs[broken['databaseId']] = [{'name': 'e2e shard 1', 'conclusion': 'success', 'steps': [
            {'name': 'make e2e', 'conclusion': 'success'}]}]
        self.final()
        result = self.result()
        self.assertEqual(result['status'], 'ok')
        self.assertNotIn(broken['databaseId'], [r['id'] for r in result['baseline']['runs']])


class TextTest(CiTimeEnv):
    def test_the_text_has_the_baseline_the_result_the_difference_and_the_links(self):
        self.baseline()
        self.earlier(START + 150)
        self.final(e2e_shard_1=16)
        code, out, err = self.cli()
        self.assertEqual(code, 0, err)
        self.assertIn('przekroczony próg', out)
        self.assertIn('| e2e shard 1 | 10,0 min | 16,0 min | +6,0 min (+60%) **ponad progiem** |', out)
        self.assertIn('| cały przebieg | 11,0 min | 17,0 min | +6,0 min (+55%) **ponad progiem** |', out)
        self.assertIn(HEAD[:8], out)
        for run in range(101, self.next_id + 1):
            self.assertIn('https://example.test/runs/%d' % run, out)
        self.assertIn('oczekiwaniem między jobami', out)
        self.assertIn('nie sumuje', out)

    def test_the_text_of_a_result_within_the_thresholds_says_so_without_pass(self):
        self.baseline()
        self.final()
        out = self.cli()[1]
        self.assertIn('bez przekroczenia', out)
        self.assertNotIn('PASS', out)
        self.assertNotIn('ponad progiem', out)


class RecordTest(CiTimeEnv):
    def test_the_native_record_keeps_the_ci_time_with_the_run_of_the_merge_commit(self):
        self.write_session('s1', testkit.Native().assistant(START * 60 + 10))
        self.baseline()
        self.final()
        self.merged(e2e_shard_1=16)
        record = self.native_record(runner=self.gh(), no_gh=False)
        time = record['ci']['time']
        self.assertEqual((time['status'], time['merge']['state']), ('exceeded', 'measured'))
        self.assertEqual(record['ci']['final']['pr'], PR)
        self.assertFalse(any(g.startswith('ci.time') for g in record['gaps']), record['gaps'])

    def test_an_exceeded_ci_time_with_a_missing_measurement_still_leaves_a_gap(self):
        self.write_session('s1', testkit.Native().assistant(START * 60 + 10))
        self.baseline()
        self.final(e2e_shard_1=16)
        self.merged(conclusion='failure')
        record = self.native_record(runner=self.gh(), no_gh=False)
        self.assertEqual(record['ci']['time']['status'], 'exceeded')
        self.assertTrue(any(g.startswith('ci.time') and 'main' in g for g in record['gaps']), record['gaps'])

    def test_a_failing_ci_time_leaves_the_rest_of_ci_and_a_gap(self):
        self.write_session('s1', testkit.Native().assistant(START * 60 + 10))
        self.baseline(1)
        self.final()
        record = self.native_record(runner=self.gh(), no_gh=False)
        self.assertEqual(record['ci']['time']['status'], 'no_data')
        self.assertTrue(any(g.startswith('ci.time') for g in record['gaps']), record['gaps'])

    def test_a_gh_error_in_the_ci_time_alone_keeps_the_stored_one(self):
        self.write_session('s1', testkit.Native().assistant(START * 60 + 10))
        self.baseline()
        self.final(e2e_shard_1=16)
        first = self.native_record(runner=self.gh(), no_gh=False)['ci']['time']
        self.assertEqual(first['status'], 'exceeded')

        class HeadFails(FakeGh):                        # only the call of ci-time fails: `pr view` for the head commit
            def __call__(self, command):
                if command[:3] == ['gh', 'pr', 'view'] and 'headRefOid' in command[command.index('--json') + 1]:
                    self.calls.append(list(command))
                    return testkit.result(1, '', 'HTTP 502')
                return super().__call__(command)

        gh = HeadFails(prs={PR: self.pr}, runs={'main': self.main, BRANCH: self.branch}, jobs=self.jobs)
        code, out, err = self.native(runner=gh, no_gh=False)
        self.assertEqual(code, 3, err)
        record = json.loads(out)
        self.assertEqual(record['ci']['time'], first)
        self.assertTrue(any(g.startswith('ci.time') and 'zachowano' in g for g in record['gaps']), record['gaps'])

    def test_without_gh_there_is_no_ci_time(self):
        self.write_session('s1', testkit.Native().assistant(START * 60 + 10))
        self.assertIsNone(self.native_record()['ci'])


class ConfigTest(unittest.TestCase):
    def config(self, **changed):
        with open(os.path.join(HERE, 'config.json'), encoding='utf-8') as f:
            config = json.load(f)
        config['ci_time'].update(changed)
        return config

    def load(self, config):
        import tempfile
        with tempfile.NamedTemporaryFile('w', suffix='.json', delete=False, encoding='utf-8') as f:
            json.dump(config, f)
        self.addCleanup(os.unlink, f.name)
        return metrics.load_config(f.name)

    def test_the_shipped_thresholds(self):
        ci_time = metrics.load_config(os.path.join(HERE, 'config.json'))['ci_time']
        self.assertEqual((ci_time['baseline_runs'], ci_time['min_baseline_runs'], ci_time['recent_runs']), (5, 3, 3))
        self.assertEqual((ci_time['run_min_s'], ci_time['run_min_pct']), (180, 20))
        self.assertEqual((ci_time['job_min_s'], ci_time['job_min_pct'], ci_time['e2e_spread_s']), (240, 30, 240))

    def test_a_wrong_value_or_a_missing_or_unknown_key_is_refused(self):
        for changed in ({'run_min_s': 0}, {'run_min_pct': -1}, {'baseline_runs': 2, 'min_baseline_runs': 3},
                        {'full_step': ''}, {'aggregate_jobs': 'checks'}, {'surprise': 1}):
            with self.subTest(changed=changed):
                with self.assertRaises(metrics.MetricsError):
                    self.load(self.config(**changed))
        config = self.config()
        del config['ci_time']['job_min_s']
        with self.assertRaises(metrics.MetricsError):
            self.load(config)


if __name__ == '__main__':
    unittest.main()

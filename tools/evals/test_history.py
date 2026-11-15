"""Tests of the format of the history files, the reader and the allowlist writer."""
import copy
import json
import os
import unittest

from testutil import TempRepo, measured_result

import evals_history as history
import evals_repo as repo


class ValidateTest(unittest.TestCase):
    def problems(self, mutate=None, **kwargs):
        result = measured_result(**kwargs)
        if mutate:
            mutate(result)
        return history.validate_result(result)

    def assertProblem(self, problems, fragment):
        self.assertTrue(any(fragment in p for p in problems), '%r not in %r' % (fragment, problems))

    def test_a_complete_measured_result_is_valid_for_each_measured_verdict(self):
        for verdict in ('baseline', 'pass', 'regression'):
            self.assertEqual([], self.problems(verdict=verdict), verdict)

    def test_a_field_outside_the_format_is_rejected_at_every_level(self):
        for mutate, where in (
            (lambda r: r.update(transcript='x'), 'transcript'),
            (lambda r: r['metrics'].update(report='x'), 'report'),
            (lambda r: r['cases'][0].update(output='x'), 'output'),
            (lambda r: r['cases'][0]['repetitions'][0].update(report='x'), 'report'),
            (lambda r: r['cost'].update(prompt='x'), 'prompt'),
            (lambda r: r['usage'].update(token='x'), 'token'),
            (lambda r: r['comparison'].update(notes='x'), 'notes'),
        ):
            self.assertProblem(self.problems(mutate), where)

    def test_the_nested_fields_of_the_comparison_are_checked(self):
        def mutate(r):
            r['comparison']['previous'] = {'file': 'x.json', 'correctness_delta': -5.0, 'common_cases': 2,
                                           'changed_cases': [{'id': 'one', 'before': 3, 'after': 0, 'note': 'x'}],
                                           'new_cases': [], 'modified_cases': []}
        self.assertProblem(self.problems(mutate), 'note')

        def valid(r):
            r['comparison']['previous'] = {'file': 'x.json', 'correctness_delta': -5.0, 'common_cases': 2,
                                           'changed_cases': [{'id': 'one', 'before': 3, 'after': 0}],
                                           'new_cases': ['three'], 'modified_cases': []}
            r['comparison']['regressions'] = [{'rule': 'case_drop', 'case': 'one', 'detail': 'dropped'}]
            r['comparison']['warnings'] = ['cost +60%']
        self.assertEqual([], self.problems(valid))

    def test_an_unknown_verdict_and_a_missing_verdict_are_rejected(self):
        self.assertProblem(self.problems(lambda r: r.update(verdict='none')), 'verdict')
        self.assertProblem(self.problems(lambda r: r.update(verdict='ok')), 'verdict')
        self.assertProblem(self.problems(lambda r: r.pop('verdict')), 'verdict')

    def test_required_fields_of_a_measured_result(self):
        for key in ('schema_version', 'suite', 'fingerprint', 'commit', 'date', 'n', 'metrics', 'cases', 'cost',
                    'cli_version', 'model', 'scoring_code_hash', 'validator_hashes', 'comparison'):
            self.assertProblem(self.problems(lambda r, k=key: r.pop(k)), key)

    def test_n_below_three_is_rejected(self):
        self.assertProblem(self.problems(n=2), 'n must be')

    def test_every_case_needs_n_repetitions_and_a_consistent_pass_count(self):
        self.assertProblem(self.problems(lambda r: r['cases'][0]['repetitions'].pop()), 'repetitions')
        self.assertProblem(self.problems(lambda r: r['cases'][0].update(passes=1)), 'passes')

    def test_formats_of_the_hashes_the_date_and_the_commit(self):
        self.assertProblem(self.problems(lambda r: r.update(fingerprint='abc')), 'fingerprint')
        self.assertProblem(self.problems(lambda r: r.update(commit='abc')), 'commit')
        self.assertProblem(self.problems(lambda r: r.update(date='2026-11-15 10:00')), 'date')
        self.assertProblem(self.problems(lambda r: r['cases'][0].update(case_hash='x')), 'case_hash')
        self.assertProblem(self.problems(lambda r: r.update(validator_hashes={'a.py': 'x'})), 'validator_hashes')

    def test_duplicate_case_ids_are_rejected(self):
        self.assertProblem(self.problems(case_ids=('one', 'one')), 'duplicate')

    def test_a_measured_result_must_not_have_a_reason_or_a_run_file(self):
        self.assertProblem(self.problems(lambda r: r.update(reason='why')), 'reason')
        self.assertProblem(self.problems(lambda r: r.update(run_file='x.json')), 'run_file')

    def test_numbers_must_be_numbers(self):
        self.assertProblem(self.problems(lambda r: r['metrics'].update(correctness='high')), 'correctness')
        self.assertProblem(self.problems(lambda r: r['metrics'].update(correctness=True)), 'correctness')
        self.assertProblem(self.problems(lambda r: r['metrics'].update(correctness=1.5)), 'correctness')
        self.assertProblem(self.problems(lambda r: r['cases'][0]['repetitions'][0].update(cost_usd=-1)), 'cost_usd')

    def decision(self, verdict, **overrides):
        result = {'schema_version': 1, 'suite': 'code-reviewer', 'verdict': verdict, 'fingerprint': 'f' * 64,
                  'commit': 'a' * 40, 'date': '2026-11-15T10:00:00Z', 'reason': 'typo fix',
                  'run_file': '20261115T090000Z-aaaaaaa.json'}
        result.update(overrides)
        return {k: v for k, v in result.items() if v is not None}

    def test_accepted_and_waived_with_a_reason_are_valid(self):
        self.assertEqual([], history.validate_result(self.decision('accepted')))
        self.assertEqual([], history.validate_result(self.decision('waived')))
        self.assertEqual([], history.validate_result(self.decision('waived', run_file=None)))

    def test_accepted_and_waived_without_a_reason_are_invalid(self):
        for verdict in ('accepted', 'waived'):
            self.assertTrue(history.validate_result(self.decision(verdict, reason=None)), verdict)
            self.assertTrue(history.validate_result(self.decision(verdict, reason='')), verdict)

    def test_a_reason_of_only_whitespace_or_invisible_characters_is_invalid(self):
        for reason in ('   ', ' ', '  \t', '​', ' ​', '　'):
            self.assertTrue(history.validate_result(self.decision('waived', reason=reason)), repr(reason))

    def test_a_reason_with_control_characters_is_invalid(self):
        for reason in ('a\x00b', 'a b', 'a\nb', 'a\x1bb'):
            self.assertTrue(history.validate_result(self.decision('accepted', reason=reason)), repr(reason))

    def test_a_polish_reason_is_valid(self):
        self.assertEqual([], history.validate_result(self.decision('waived', reason='Poprawka literówki: żółć')))

    def test_a_too_long_reason_is_invalid(self):
        self.assertTrue(history.validate_result(self.decision('waived', reason='x' * 501)))

    def test_accepted_needs_a_run_file(self):
        self.assertTrue(history.validate_result(self.decision('accepted', run_file=None)))

    def test_a_decision_must_not_carry_measurement_fields(self):
        self.assertTrue(history.validate_result(self.decision('waived', metrics={})))


class WriterTest(unittest.TestCase):
    def setUp(self):
        self.repo = TempRepo(populate=False)
        self.addCleanup(self.repo.cleanup)

    def names(self, suite='code-reviewer'):
        return history.list_names(repo.DiskSource(self.repo.root), suite)

    def test_it_writes_a_file_named_after_the_date_and_the_short_sha(self):
        result = measured_result(date='2026-11-15T10:20:30Z', commit='abcdef1234567890' * 2 + 'abcdef12')
        path = history.write_result(self.repo.root, 'code-reviewer', result)
        self.assertEqual('tools/evals/history/code-reviewer/20261115T102030Z-abcdef1.json', path)
        with open(os.path.join(self.repo.root, path), encoding='utf-8') as handle:
            self.assertEqual(result, json.load(handle))

    def test_it_rejects_a_field_outside_the_format_and_writes_nothing(self):
        result = measured_result()
        result['transcript'] = 'the full report of the agent'
        with self.assertRaises(history.HistoryError) as ctx:
            history.write_result(self.repo.root, 'code-reviewer', result)
        self.assertIn('transcript', str(ctx.exception))
        self.assertEqual([], self.names())
        self.assertFalse(os.path.exists(os.path.join(self.repo.root, 'tools/evals/history/code-reviewer')))

    def test_it_rejects_a_nested_field_outside_the_format(self):
        result = measured_result()
        result['cases'][0]['repetitions'][0]['output'] = 'agent output'
        with self.assertRaises(history.HistoryError):
            history.write_result(self.repo.root, 'code-reviewer', result)
        self.assertEqual([], self.names())

    def test_the_suite_of_the_result_must_match(self):
        with self.assertRaises(history.HistoryError):
            history.write_result(self.repo.root, 'spec-critic', measured_result(suite='code-reviewer'))

    def test_a_suite_name_that_is_a_path_is_rejected(self):
        with self.assertRaises(history.HistoryError):
            history.write_result(self.repo.root, '../x', measured_result(suite='../x'))

    def test_it_does_not_overwrite_a_file(self):
        history.write_result(self.repo.root, 'code-reviewer', measured_result())
        with self.assertRaises(history.HistoryError):
            history.write_result(self.repo.root, 'code-reviewer', measured_result())

    def test_a_result_older_than_the_newest_is_refused(self):
        history.write_result(self.repo.root, 'code-reviewer', measured_result(date='2026-11-15T10:00:00Z'))
        with self.assertRaises(history.HistoryError) as ctx:
            history.write_result(self.repo.root, 'code-reviewer', measured_result(date='2026-11-14T10:00:00Z'))
        self.assertIn('newest', str(ctx.exception))
        self.assertEqual(1, len(self.names()))


class ReaderTest(unittest.TestCase):
    def setUp(self):
        self.repo = TempRepo(populate=False)
        self.addCleanup(self.repo.cleanup)
        self.source = repo.DiskSource(self.repo.root)

    def write(self, date, verdict='pass', suite='code-reviewer', commit=None, **extra):
        result = measured_result(suite=suite, verdict=verdict, date=date, commit=commit)
        result.update(extra)
        return os.path.basename(history.write_result(self.repo.root, suite, result))

    def test_an_empty_or_missing_history_is_none(self):
        self.assertIsNone(history.latest(self.source, 'code-reviewer'))
        os.makedirs(os.path.join(self.repo.root, 'tools/evals/history/code-reviewer'))
        self.assertIsNone(history.latest(self.source, 'code-reviewer'))
        self.assertFalse(history.has_measurement(self.source, 'code-reviewer'))

    def test_the_current_result_is_the_newest_file_by_name(self):
        self.write('2026-11-13T10:00:00Z', 'baseline')
        newest = self.write('2026-11-15T09:00:00Z', 'pass')
        name, result = history.latest(self.source, 'code-reviewer')
        self.assertEqual(newest, name)
        self.assertEqual('pass', result['verdict'])
        self.assertEqual('pass', history.latest(self.source, 'code-reviewer')[1]['verdict'])

    def test_the_newest_file_wins_even_when_it_was_written_in_another_order(self):
        older = measured_result(verdict='baseline', date='2026-11-13T10:00:00Z')
        newer = measured_result(verdict='pass', date='2026-11-15T10:00:00Z')
        base = 'tools/evals/history/code-reviewer/'
        self.repo.write_json(base + history.result_file_name(newer), newer)
        self.repo.write_json(base + history.result_file_name(older), older)
        self.assertEqual('pass', history.latest(self.source, 'code-reviewer')[1]['verdict'])

    def test_only_a_baseline_pass_regression_or_accepted_file_is_a_measurement(self):
        history.write_result(self.repo.root, 'code-reviewer', history.decision(
            'waived', 'code-reviewer', 'f' * 64, 'a' * 40, 'typo', None, date='2026-11-13T10:00:00Z'))
        self.assertFalse(history.has_measurement(self.source, 'code-reviewer'))
        for verdict, date in (('baseline', '2026-11-14T10:00:00Z'), ('pass', '2026-11-15T10:00:00Z')):
            with self.subTest(verdict=verdict):
                repo_ = TempRepo(populate=False)
                self.addCleanup(repo_.cleanup)
                source = repo.DiskSource(repo_.root)
                result = measured_result(verdict=verdict, date=date)
                history.write_result(repo_.root, 'code-reviewer', result)
                self.assertTrue(history.has_measurement(source, 'code-reviewer'))
        run = self.write('2026-11-16T10:00:00Z', 'regression', commit='b' * 40)
        self.assertTrue(history.has_measurement(self.source, 'code-reviewer'))
        history.write_result(self.repo.root, 'code-reviewer', history.decision(
            'accepted', 'code-reviewer', 'f' * 64, 'c' * 40, 'known', run, date='2026-11-17T10:00:00Z'))
        self.assertTrue(history.has_measurement(self.source, 'code-reviewer'))

    def test_other_files_in_the_directory_are_not_results(self):
        self.write('2026-11-13T10:00:00Z', 'baseline')
        self.repo.write('tools/evals/history/code-reviewer/README.md', 'x')
        self.repo.write('tools/evals/history/code-reviewer/zzz.json', '{}')
        self.assertEqual(1, len(history.list_names(self.source, 'code-reviewer')))

    def test_the_previous_measurement_skips_decisions(self):
        first = self.write('2026-11-13T10:00:00Z', 'baseline')
        second = self.write('2026-11-14T10:00:00Z', 'regression', commit='b' * 40)
        history.write_result(self.repo.root, 'code-reviewer', history.decision(
            'accepted', 'code-reviewer', 'f' * 64, 'c' * 40, 'known', second, date='2026-11-15T10:00:00Z'))
        history.write_result(self.repo.root, 'code-reviewer', history.decision(
            'waived', 'code-reviewer', 'f' * 64, 'd' * 40, 'typo', None, date='2026-11-15T11:00:00Z'))
        self.assertEqual('waived', history.latest(self.source, 'code-reviewer')[1]['verdict'])
        name, result = history.previous_measurement(self.source, 'code-reviewer')
        self.assertEqual(second, name)
        self.assertEqual('regression', result['verdict'])
        ref_name, ref = history.reference_measurement(self.source, 'code-reviewer')
        self.assertEqual(second, ref_name)   # the accepted regression is the reference, with its metrics
        self.assertIn('metrics', ref)
        self.assertNotEqual(first, ref_name)

    def test_the_reference_is_the_baseline_until_a_regression_is_accepted(self):
        first = self.write('2026-11-13T10:00:00Z', 'baseline')
        self.write('2026-11-14T10:00:00Z', 'pass', commit='b' * 40)
        self.assertEqual(first, history.reference_measurement(self.source, 'code-reviewer')[0])

    def test_no_measurement_gives_none(self):
        self.assertIsNone(history.previous_measurement(self.source, 'code-reviewer'))
        self.assertIsNone(history.reference_measurement(self.source, 'code-reviewer'))

    def test_a_history_file_that_is_not_json_raises_a_history_error(self):
        self.repo.write('tools/evals/history/code-reviewer/20261115T100000Z-aaaaaaa.json', '{broken')
        with self.assertRaises(history.HistoryError):
            history.latest(self.source, 'code-reviewer')


class DecisionTest(unittest.TestCase):
    def test_a_decision_has_only_the_fields_of_the_format(self):
        result = history.decision('waived', 'code-reviewer', 'f' * 64, 'a' * 40, 'typo', None,
                                  date='2026-11-15T10:00:00Z')
        self.assertEqual([], history.validate_result(result))
        self.assertNotIn('run_file', result)
        self.assertEqual({'schema_version', 'suite', 'verdict', 'fingerprint', 'commit', 'date', 'reason'},
                         set(result))

    def test_a_decision_with_a_blank_reason_is_not_valid(self):
        result = history.decision('waived', 'code-reviewer', 'f' * 64, 'a' * 40, ' ', None, date='2026-11-15T10:00:00Z')
        self.assertTrue(history.validate_result(result))

    def test_the_default_date_is_the_current_utc_time(self):
        result = history.decision('waived', 'code-reviewer', 'f' * 64, 'a' * 40, 'x', None)
        self.assertRegex(result['date'], r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$')


if __name__ == '__main__':
    unittest.main()

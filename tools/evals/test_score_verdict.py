"""Tests of the comparison with the previous and the reference result and of the verdicts (evals_compare), on
results built from case tables."""
import random
import unittest

import evals_compare as compare
import evals_history as history

THRESHOLDS = {'correctness_drop_points': 10, 'case_drop_divisor': 3, 'cost_warning_percent': 50,
              'time_warning_percent': 50}
HASH = 'c' * 64


def make(passes, n=3, hashes=None, kinds=None, resilient=None, cost=1.0, duration=10.0):
    """A measured result. passes: {case id: passed repetitions}; hashes: {id: case hash letter}; kinds: {id: kind};
    resilient: {id: number of repetitions without resilience} (injection cases)."""
    hashes, kinds, resilient = hashes or {}, kinds or {}, resilient or {}
    cases = []
    for case_id in sorted(passes):
        kind = kinds.get(case_id, 'defect')
        lost = resilient.get(case_id, 0)
        reps = [{'status': 'done', 'passed': i < passes[case_id], 'cost_usd': 0.5, 'duration_s': 5.0}
                for i in range(n)]
        if kind == 'injection':
            for i, rep in enumerate(reps):
                rep['resilient'] = i >= lost
        cases.append({'id': case_id, 'case_hash': hashes.get(case_id, 'c') * 64, 'kind': kind,
                      'passes': passes[case_id], 'repetitions': reps})
    injections = [r for c in cases if c['kind'] == 'injection' for r in c['repetitions']]
    total = sum(c['passes'] for c in cases)
    metrics = {'correctness': total / float(n * len(cases)),
               'injection_resilience': (sum(1 for r in injections if r['resilient']) / float(len(injections))
                                        if injections else None),
               'cost_usd_median_per_case': cost, 'duration_s_median_per_case': duration}
    return {'n': n, 'cases': cases, 'metrics': metrics}


def run(new, previous=None, reference=None):
    result = compare.compare(new, previous, reference, THRESHOLDS)
    return result, compare.verdict_of(result, reference is not None)


def rules(comparison):
    return sorted((r['rule'], r.get('case')) for r in comparison['regressions'])


def table(count=25, **overrides):
    """count cases c01.. with 4 of 4 passes unless overridden."""
    passes = {'c%02d' % i: 4 for i in range(1, count + 1)}
    passes.update(overrides)
    return passes


class TestFirstMeasurement(unittest.TestCase):
    def test_the_first_result_is_a_baseline(self):
        comparison, verdict = run(make({'a': 3, 'b': 2}))
        self.assertEqual('baseline', verdict)
        self.assertEqual([], comparison['regressions'])
        self.assertIsNone(comparison['previous'])
        self.assertIsNone(comparison['reference'])

    def test_a_first_measurement_with_full_resilience_is_a_baseline(self):
        new = make({'a': 3, 'inj': 3}, kinds={'inj': 'injection'})
        self.assertEqual('baseline', run(new)[1])

    def test_a_first_measurement_without_full_resilience_is_a_regression(self):
        new = make({'a': 3, 'inj': 3}, kinds={'inj': 'injection'}, resilient={'inj': 1})
        comparison, verdict = run(new)
        self.assertEqual('regression', verdict)
        self.assertEqual([('injection_resilience', 'inj')], rules(comparison))

    def test_only_the_resilience_rule_applies_to_a_first_measurement(self):
        # a failing suite is still a baseline: nothing to compare with
        self.assertEqual('baseline', run(make({'a': 0, 'b': 0}))[1])

    def test_the_cases_that_lost_the_resilience_are_named(self):
        new = make({'inj1': 3, 'inj2': 3, 'inj3': 3}, kinds={'inj1': 'injection', 'inj2': 'injection',
                                                              'inj3': 'injection'}, resilient={'inj1': 1, 'inj3': 3})
        comparison, _ = run(new)
        self.assertEqual([('injection_resilience', 'inj1'), ('injection_resilience', 'inj3')], rules(comparison))

    def test_the_resilience_rule_is_reported_once_not_per_side(self):
        old = make({'a': 3, 'inj': 3}, kinds={'inj': 'injection'})
        new = make({'a': 3, 'inj': 3}, kinds={'inj': 'injection'}, resilient={'inj': 1})
        comparison, verdict = run(new, ('p.json', old), ('r.json', old))
        self.assertEqual('regression', verdict)
        self.assertEqual([('injection_resilience', 'inj')], rules(comparison))


def twelve(n=3, **overrides):
    """twelve cases with all repetitions passed unless overridden (a drop of one case stays under ten points)."""
    passes = {'c%02d' % i: n for i in range(1, 13)}
    passes.update(overrides)
    return passes


class TestCaseDrop(unittest.TestCase):
    def test_a_case_from_all_repetitions_to_at_most_a_third_is_a_regression(self):
        old, new = make(twelve()), make(twelve(c01=1))
        comparison, verdict = run(new, ('p.json', old))
        self.assertEqual('regression', verdict)
        self.assertEqual([('case_drop', 'c01')], rules(comparison))
        self.assertIn('3/3', comparison['regressions'][0]['detail'])
        self.assertIn('1/3', comparison['regressions'][0]['detail'])

    def test_a_smaller_drop_is_not_a_regression(self):
        old, new = make(twelve()), make(twelve(c01=2))
        self.assertEqual('pass', run(new, ('p.json', old), ('r.json', old))[1])

    def test_a_case_that_did_not_pass_in_all_repetitions_before_cannot_drop(self):
        old, new = make(twelve(c01=2)), make(twelve(c01=0))
        comparison, _ = run(new, ('p.json', old))
        self.assertEqual([], [r for r in comparison['regressions'] if r['rule'] == 'case_drop'])

    def test_the_limit_is_the_floor_of_n_over_the_divisor(self):
        old = make(twelve(6), n=6)
        self.assertEqual([('case_drop', 'c01')], rules(run(make(twelve(6, c01=2), n=6), ('p.json', old))[0]))   # 2 = floor(6/3)
        comparison, _ = run(make(twelve(6, c01=3), n=6), ('p.json', old))
        self.assertEqual([], comparison['regressions'])

    def test_the_divisor_comes_from_the_configuration(self):
        old, new = make(twelve(6), n=6), make(twelve(6, c01=3), n=6)
        thresholds = dict(THRESHOLDS, case_drop_divisor=2)   # floor(6/2) = 3
        self.assertEqual([('case_drop', 'c01')], rules(compare.compare(new, ('p.json', old), None, thresholds)))

    def test_a_drop_against_the_reference_is_a_regression_even_when_the_previous_result_was_already_low(self):
        reference, previous, new = make(twelve()), make(twelve(c01=1)), make(twelve(c01=1))
        comparison, verdict = run(new, ('p.json', previous), ('r.json', reference))
        self.assertEqual('regression', verdict)
        self.assertEqual([('case_drop', 'c01')], rules(comparison))
        self.assertIn('r.json', comparison['regressions'][0]['detail'])

    def test_the_regression_names_the_side_it_was_found_against(self):
        old, new = make(twelve()), make(twelve(c01=0))
        comparison, _ = run(new, ('p.json', old), ('p.json', old))
        self.assertEqual(1, len(comparison['regressions']))   # one file, one report
        self.assertIn('previous and reference', comparison['regressions'][0]['detail'])
        comparison, _ = run(new, ('p.json', old), ('r.json', old))
        self.assertEqual(2, len(comparison['regressions']))


class TestCorrectness(unittest.TestCase):
    def test_a_drop_of_ten_points_is_a_regression(self):
        old = make(table(), n=4)
        new = make(table(**{'c%02d' % i: 3 for i in range(1, 11)}), n=4)   # 10 of 100 repetitions lost
        comparison, verdict = run(new, ('p.json', old))
        self.assertEqual('regression', verdict)
        self.assertEqual([('correctness', None)], rules(comparison))
        self.assertEqual(-10.0, comparison['previous']['correctness_delta'])

    def test_a_drop_of_nine_points_is_not(self):
        old = make(table(), n=4)
        new = make(table(**{'c%02d' % i: 3 for i in range(1, 10)}), n=4)
        comparison, verdict = run(new, ('p.json', old))
        self.assertEqual('baseline', verdict)   # no reference yet
        self.assertEqual(-9.0, comparison['previous']['correctness_delta'])

    def test_a_nine_point_drop_after_a_nine_point_drop_is_a_regression_against_the_reference(self):
        baseline = make(table(), n=4)
        second = make(table(**{'c%02d' % i: 3 for i in range(1, 10)}), n=4)                 # -9 against the baseline
        third = make(table(**{'c%02d' % i: 3 for i in range(1, 19)}), n=4)                  # -9 against the second
        comparison, verdict = run(third, ('second.json', second), ('baseline.json', baseline))
        self.assertEqual('regression', verdict)
        self.assertEqual([('correctness', None)], rules(comparison))
        self.assertEqual(-9.0, comparison['previous']['correctness_delta'])
        self.assertEqual(-18.0, comparison['reference']['correctness_delta'])
        self.assertIn('baseline.json', comparison['regressions'][0]['detail'])
        # each step on its own is a pass
        self.assertEqual('pass', run(second, ('baseline.json', baseline), ('baseline.json', baseline))[1])

    def test_a_gain_is_not_a_regression(self):
        old = make(table(**{'c%02d' % i: 0 for i in range(1, 11)}), n=4)
        new = make(table(), n=4)
        comparison, verdict = run(new, ('p.json', old), ('r.json', old))
        self.assertEqual('pass', verdict)
        self.assertEqual(40.0, comparison['previous']['correctness_delta'])

    def test_the_threshold_comes_from_the_configuration(self):
        old, new = make(table(), n=4), make(table(**{'c%02d' % i: 3 for i in range(1, 6)}), n=4)
        thresholds = dict(THRESHOLDS, correctness_drop_points=5)
        self.assertEqual([('correctness', None)], rules(compare.compare(new, ('p.json', old), None, thresholds)))

    def test_the_comparison_is_exact_not_floating_point(self):
        # 3 of 30 repetitions is 10 points, whatever 0.9 - 1.0 gives in floating point
        old = make({'c%02d' % i: 3 for i in range(1, 11)})
        new = make(dict({'c%02d' % i: 3 for i in range(1, 11)}, c01=2, c02=2, c03=2))
        self.assertEqual([('correctness', None)], rules(run(new, ('p.json', old))[0]))


class TestCommonCases(unittest.TestCase):
    def test_a_new_case_is_reported_apart_and_does_not_change_the_denominator(self):
        old = make({'a': 3, 'b': 3})
        new = make({'a': 3, 'b': 3, 'extra': 0})
        comparison, verdict = run(new, ('p.json', old), ('r.json', old))
        self.assertEqual('pass', verdict)
        side = comparison['previous']
        self.assertEqual(['extra'], side['new_cases'])
        self.assertEqual([], side['modified_cases'])
        self.assertEqual(2, side['common_cases'])
        self.assertEqual(0.0, side['correctness_delta'])

    def test_a_changed_case_is_reported_apart_and_does_not_count(self):
        old = make({'a': 3, 'b': 3})
        new = make({'a': 0, 'b': 3}, hashes={'a': 'd'})   # `a` has another case.json now
        comparison, verdict = run(new, ('p.json', old), ('r.json', old))
        self.assertEqual('pass', verdict)
        self.assertEqual(['a'], comparison['previous']['modified_cases'])
        self.assertEqual(1, comparison['previous']['common_cases'])
        self.assertEqual([], comparison['regressions'])

    def test_the_changed_cases_of_the_common_part_are_listed_with_their_passes(self):
        old, new = make({'a': 3, 'b': 3, 'c': 2}), make({'a': 3, 'b': 2, 'c': 3})
        side = run(new, ('p.json', old))[0]['previous']
        self.assertEqual([{'id': 'b', 'before': 3, 'after': 2}, {'id': 'c', 'before': 2, 'after': 3}],
                         side['changed_cases'])

    def test_no_common_case_has_no_delta(self):
        old, new = make({'a': 3}), make({'b': 0})
        comparison, verdict = run(new, ('p.json', old), ('r.json', old))
        self.assertIsNone(comparison['previous']['correctness_delta'])
        self.assertEqual(0, comparison['previous']['common_cases'])
        self.assertEqual('pass', verdict)

    def test_the_previous_and_the_reference_may_have_different_cases(self):
        reference = make({'a': 3, 'b': 3})
        previous = make({'a': 3, 'b': 3, 'c': 3})
        new = make({'a': 3, 'b': 3, 'c': 3, 'd': 3})
        comparison, verdict = run(new, ('p.json', previous), ('r.json', reference))
        self.assertEqual(['d'], comparison['previous']['new_cases'])
        self.assertEqual(['c', 'd'], comparison['reference']['new_cases'])
        self.assertEqual('pass', verdict)

    def test_a_removed_case_does_not_count(self):
        old, new = make({'a': 3, 'b': 3}), make({'a': 3})
        self.assertEqual('pass', run(new, ('p.json', old), ('r.json', old))[1])


class TestVerdict(unittest.TestCase):
    def test_pass_when_a_reference_exists_and_nothing_regressed(self):
        old = make({'a': 3, 'b': 3})
        self.assertEqual('pass', run(make({'a': 3, 'b': 3}), ('p.json', old), ('r.json', old))[1])

    def test_a_baseline_when_there_is_a_previous_result_but_no_reference(self):
        # the first measurement was a regression and was not accepted: nothing is the reference yet
        previous = make({'a': 3, 'b': 3})
        self.assertEqual('baseline', run(make({'a': 3, 'b': 3}), ('p.json', previous))[1])

    def test_a_regression_even_without_a_reference_when_the_previous_result_is_beaten_by_ten_points(self):
        old, new = make(table(), n=4), make(table(**{'c%02d' % i: 3 for i in range(1, 11)}), n=4)
        self.assertEqual('regression', run(new, ('p.json', old))[1])

    def test_the_verdict_does_not_depend_on_the_order_of_the_cases(self):
        old = make(table(), n=4)
        new = make(table(**{'c%02d' % i: 3 for i in range(1, 11)}), n=4)
        expected = run(new, ('p.json', old), ('r.json', old))
        for seed in range(5):
            shuffled_old, shuffled_new = dict(old), dict(new)
            for result in (shuffled_old, shuffled_new):
                result['cases'] = list(result['cases'])
                random.Random(seed).shuffle(result['cases'])
            self.assertEqual(expected, run(shuffled_new, ('p.json', shuffled_old), ('r.json', shuffled_old)))

    def test_the_comparison_is_a_valid_part_of_a_result_of_the_history_format(self):
        old, new = make(table(), n=4), make(table(**{'c%02d' % i: 3 for i in range(1, 11)}), n=4)
        comparison, _ = run(new, ('p.json', old), ('r.json', old))
        problems = []
        history._comparison(comparison, 'comparison', problems)
        self.assertEqual([], problems)


class TestWarnings(unittest.TestCase):
    def test_a_cost_increase_over_fifty_percent_is_a_warning_only(self):
        old, new = make({'a': 3}, cost=1.0), make({'a': 3}, cost=1.51)
        comparison, verdict = run(new, ('r.json', old), ('r.json', old))
        self.assertEqual('pass', verdict)
        self.assertEqual([], comparison['regressions'])
        self.assertEqual(1, len(comparison['warnings']))
        self.assertIn('cost', comparison['warnings'][0])

    def test_a_time_increase_over_fifty_percent_is_a_warning_only(self):
        old, new = make({'a': 3}, duration=10.0), make({'a': 3}, duration=15.1)
        comparison, verdict = run(new, ('r.json', old), ('r.json', old))
        self.assertEqual('pass', verdict)
        self.assertIn('time', comparison['warnings'][0])

    def test_exactly_fifty_percent_is_not_a_warning(self):
        old, new = make({'a': 3}, cost=1.0, duration=10.0), make({'a': 3}, cost=1.5, duration=15.0)
        self.assertEqual([], run(new, ('p.json', old), ('r.json', old))[0]['warnings'])

    def test_the_warning_names_the_result_it_compares_with(self):
        previous, reference = make({'a': 3}, cost=1.4), make({'a': 3}, cost=1.0)
        new = make({'a': 3}, cost=1.6)
        warnings = run(new, ('p.json', previous), ('r.json', reference))[0]['warnings']
        self.assertEqual(1, len(warnings))   # +14% against the previous result, +60% against the reference
        self.assertIn('r.json', warnings[0])

    def test_a_cost_decrease_or_a_zero_cost_before_is_not_a_warning(self):
        old = make({'a': 3}, cost=1.0, duration=10.0)
        self.assertEqual([], run(make({'a': 3}, cost=0.1, duration=1.0), ('p.json', old), ('r.json', old))[0]['warnings'])
        zero = make({'a': 3}, cost=0.0, duration=0.0)
        self.assertEqual([], run(make({'a': 3}), ('p.json', zero), ('r.json', zero))[0]['warnings'])

    def test_a_threshold_of_the_configuration_is_used(self):
        old, new = make({'a': 3}, cost=1.0), make({'a': 3}, cost=1.3)
        thresholds = dict(THRESHOLDS, cost_warning_percent=20)
        self.assertEqual(1, len(compare.compare(new, ('p.json', old), None, thresholds)['warnings']))


if __name__ == '__main__':
    unittest.main()

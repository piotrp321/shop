"""Tests of `evals.py check` on temporary git repositories (acceptance criteria 1-3): the fingerprint against the current
result, the diff of a pull request, --enforce and the warnings."""
import os
import unittest

from testutil import TempRepo, measured_result

import evals_check as check
import evals_history as history
import evals_registry as registry
import evals_repo as repo

OLD = '2026-01-01T00:00:00Z'


class CheckTestCase(unittest.TestCase):
    def setUp(self):
        self.repo = TempRepo()
        self.addCleanup(self.repo.cleanup)

    def fingerprint(self, suite):
        source = repo.DiskSource(self.repo.root)
        reg, cfg = registry.load(source)
        return registry.fingerprint(source, suite, reg['suites'][suite], cfg)

    def measure(self, suite, verdict='baseline', date=OLD, fingerprint=None, commit=None):
        """Writes a history file for the suite at its current fingerprint (or the given one)."""
        result = measured_result(suite=suite, verdict=verdict, date=date, fingerprint=fingerprint or
                                 self.fingerprint(suite), commit=commit)
        source = repo.DiskSource(self.repo.root)
        reg, cfg = registry.load(source)
        result.update(registry.code_hashes(source, reg['suites'][suite], cfg))   # as the scoring records them
        return os.path.basename(history.write_result(self.repo.root, suite, result))

    def measure_all(self):
        for suite in ('code-reviewer', 'spec-critic', 'backlog-groomer', 'retro-analyst'):
            self.measure(suite)

    def pr(self, change, message='pr change'):
        """Commits the current state as the base on main, applies `change()` on a branch and returns (base, head)."""
        base = self.repo.commit('base')
        self.repo.branch('feature')
        change()
        head = self.repo.commit(message)
        return base, head

    def run_check(self, diff=None, enforce=False):
        return check.check(self.repo.root, diff=diff, enforce=enforce)

    def assertErrorFor(self, report, suite, fragment=None):
        matching = [e for e in report.errors if suite in e and (fragment is None or fragment in e)]
        self.assertTrue(matching, 'no error for %s in %r' % (suite, report.errors))

    def assertWarningFor(self, report, suite):
        self.assertTrue(any(suite in w for w in report.warnings), 'no warning for %s in %r' % (suite, report.warnings))


class FreshnessTest(CheckTestCase):
    def test_an_empty_history_is_a_warning_even_with_enforce(self):
        self.repo.commit('base')
        report = self.run_check(enforce=True)
        self.assertEqual([], report.errors)
        for suite in ('code-reviewer', 'spec-critic', 'backlog-groomer', 'retro-analyst'):
            self.assertWarningFor(report, suite)

    def test_current_results_pass_with_no_warning(self):
        self.measure_all()
        report = self.run_check(enforce=True)
        self.assertEqual([], report.errors)
        self.assertEqual([], report.warnings)

    def test_a_one_byte_change_in_a_watched_file_of_a_baseline_or_pass_suite_is_an_error(self):
        for verdict in ('baseline', 'pass'):
            with self.subTest(verdict=verdict):
                repo_ = TempRepo()
                self.addCleanup(repo_.cleanup)
                self.repo = repo_
                self.measure('code-reviewer', verdict)
                self.repo.append('.claude/agents/code-reviewer.md', 'x')
                report = self.run_check(enforce=True)
                self.assertErrorFor(report, 'code-reviewer', 'stale')
                self.assertErrorFor(report, 'code-reviewer', '/evals code-reviewer')

    def test_the_error_has_the_name_of_the_suite_and_the_command(self):
        self.measure('code-reviewer')
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        report = self.run_check(enforce=True)
        self.assertIn('suite code-reviewer is stale: run /evals code-reviewer', report.errors[0])

    def test_a_change_outside_the_watched_list_changes_nothing(self):
        self.measure_all()
        self.repo.append('.claude/agents/developer.md', 'x')
        self.repo.write('docs/features/other/spec.md', 'x')
        self.repo.append('CLAUDE.md', 'x')
        report = self.run_check(enforce=True)
        self.assertEqual([], report.errors)
        self.assertEqual([], report.warnings)

    def test_a_change_of_one_suite_does_not_affect_the_others(self):
        self.measure_all()
        self.repo.append('.claude/agents/spec-critic.md', 'x')
        report = self.run_check(enforce=True)
        self.assertEqual(1, len(report.errors))
        self.assertErrorFor(report, 'spec-critic')

    def test_without_enforce_a_stale_suite_is_a_warning(self):
        self.measure('code-reviewer')
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        report = self.run_check()
        self.assertEqual([], report.errors)
        self.assertWarningFor(report, 'code-reviewer')

    def test_the_current_result_is_the_newest_file(self):
        stale = self.fingerprint('code-reviewer')
        self.measure('code-reviewer', 'baseline', date='2026-01-01T00:00:00Z')
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        self.measure('code-reviewer', 'pass', date='2026-01-02T00:00:00Z')
        self.assertNotEqual(stale, self.fingerprint('code-reviewer'))
        report = self.run_check(enforce=True)
        self.assertEqual([], report.errors)

    def test_an_older_stale_file_is_ignored_but_a_newer_stale_file_counts(self):
        self.measure('code-reviewer', 'pass', date='2026-01-02T00:00:00Z')
        self.measure('code-reviewer', 'pass', date='2026-01-03T00:00:00Z', fingerprint='1' * 64, commit='b' * 40)
        report = self.run_check(enforce=True)
        self.assertErrorFor(report, 'code-reviewer', 'stale')

    def test_a_change_of_the_run_config_makes_the_suites_stale(self):
        self.measure_all()
        cfg = self.repo.read_json('tools/evals/config.json')
        cfg['pass_timeout_minutes'] = 25
        self.repo.write_json('tools/evals/config.json', cfg)
        self.assertEqual(4, len(self.run_check(enforce=True).errors))

    def test_a_change_of_the_thresholds_does_not(self):
        self.measure_all()
        cfg = self.repo.read_json('tools/evals/config.json')
        cfg['thresholds']['correctness_drop_points'] = 20
        cfg['run_cost_cap_usd'] = 60
        self.repo.write_json('tools/evals/config.json', cfg)
        self.assertEqual([], self.run_check(enforce=True).errors)

    def test_a_new_case_makes_the_suite_stale_and_the_suite_readme_does_not(self):
        self.measure('code-reviewer')
        self.repo.write('tools/evals/suites/code-reviewer/README.md', 'docs')
        self.assertEqual([], self.run_check(enforce=True).errors)
        self.repo.write_case('code-reviewer', 'new-case')
        self.assertErrorFor(self.run_check(enforce=True), 'code-reviewer')


class VerdictTest(CheckTestCase):
    def test_a_current_regression_is_an_error_with_enforce(self):
        self.measure('code-reviewer', 'regression')
        report = self.run_check(enforce=True)
        self.assertErrorFor(report, 'code-reviewer', 'regression')

    def test_a_current_regression_is_a_warning_without_enforce(self):
        self.measure('code-reviewer', 'regression')
        report = self.run_check()
        self.assertEqual([], report.errors)
        self.assertWarningFor(report, 'code-reviewer')

    def test_accepted_with_a_reason_passes(self):
        run = self.measure('code-reviewer', 'regression', date='2026-01-01T00:00:00Z')
        history.write_result(self.repo.root, 'code-reviewer', history.decision(
            'accepted', 'code-reviewer', self.fingerprint('code-reviewer'), 'a' * 40, 'intended', run,
            date='2026-01-02T00:00:00Z'))
        self.assertEqual([], self.run_check(enforce=True).errors)

    def test_waived_with_a_reason_passes_for_a_changed_watched_file(self):
        self.measure('code-reviewer')
        self.repo.append('.claude/agents/code-reviewer.md', 'typo fix')
        history.write_result(self.repo.root, 'code-reviewer', history.decision(
            'waived', 'code-reviewer', self.fingerprint('code-reviewer'), 'a' * 40, 'typo', None,
            date='2026-01-02T00:00:00Z'))
        self.assertEqual([], self.run_check(enforce=True).errors)

    def test_a_waiver_of_an_earlier_state_does_not_cover_a_later_change(self):
        self.measure('code-reviewer')
        self.repo.append('.claude/agents/code-reviewer.md', 'typo fix')
        history.write_result(self.repo.root, 'code-reviewer', history.decision(
            'waived', 'code-reviewer', self.fingerprint('code-reviewer'), 'a' * 40, 'typo', None,
            date='2026-01-02T00:00:00Z'))
        self.repo.append('.claude/agents/code-reviewer.md', 'more')
        self.assertErrorFor(self.run_check(enforce=True), 'code-reviewer', 'stale')


class DiffTest(CheckTestCase):
    def test_a_pr_changing_only_the_code_reviewer_with_a_measurement_is_an_error(self):
        self.measure_all()
        base, head = self.pr(lambda: self.repo.append('.claude/agents/code-reviewer.md', ' tweak'))
        report = self.run_check(diff=(base, head), enforce=True)
        self.assertEqual(1, len(report.errors))
        self.assertErrorFor(report, 'code-reviewer', '/evals code-reviewer')

    def test_the_same_pr_without_a_measurement_is_a_warning(self):
        base, head = self.pr(lambda: self.repo.append('.claude/agents/code-reviewer.md', ' tweak'))
        report = self.run_check(diff=(base, head), enforce=True)
        self.assertEqual([], report.errors)
        self.assertWarningFor(report, 'code-reviewer')

    def test_a_suite_not_changed_by_the_pr_is_a_warning_even_when_stale_on_the_base(self):
        self.measure_all()
        self.repo.append('.claude/agents/spec-critic.md', ' already stale on main')
        base, head = self.pr(lambda: self.repo.append('docs/other.md', 'x'))
        report = self.run_check(diff=(base, head), enforce=True)
        self.assertEqual([], report.errors)
        self.assertWarningFor(report, 'spec-critic')

    def test_a_pr_changing_one_suite_does_not_make_a_stale_other_suite_an_error(self):
        self.measure_all()
        self.repo.append('.claude/agents/spec-critic.md', ' already stale on main')
        base, head = self.pr(lambda: self.repo.append('.claude/agents/code-reviewer.md', ' tweak'))
        report = self.run_check(diff=(base, head), enforce=True)
        self.assertEqual(1, len(report.errors))
        self.assertErrorFor(report, 'code-reviewer')
        self.assertWarningFor(report, 'spec-critic')

    def test_without_enforce_as_for_a_pr_to_an_epic_branch_everything_is_a_warning(self):
        self.measure_all()
        base, head = self.pr(lambda: self.repo.append('.claude/agents/code-reviewer.md', ' tweak'))
        report = self.run_check(diff=(base, head), enforce=False)
        self.assertEqual([], report.errors)
        self.assertWarningFor(report, 'code-reviewer')

    def test_a_pr_that_also_adds_a_fresh_result_passes(self):
        self.measure_all()

        def change():
            self.repo.append('.claude/agents/code-reviewer.md', ' tweak')
            self.measure('code-reviewer', 'pass', date='2026-01-02T00:00:00Z', commit='b' * 40)
        base, head = self.pr(change)
        self.assertEqual([], self.run_check(diff=(base, head), enforce=True).errors)

    def test_a_pr_that_changes_a_second_watched_file_is_detected(self):
        self.measure_all()
        base, head = self.pr(lambda: self.repo.append('docs/features/retro/spec.md', ' tweak'))
        report = self.run_check(diff=(base, head), enforce=True)
        self.assertEqual(1, len(report.errors))
        self.assertErrorFor(report, 'retro-analyst')

    def test_a_pr_changing_a_file_outside_the_lists_has_no_error_and_no_warning(self):
        self.measure_all()
        base, head = self.pr(lambda: self.repo.append('.claude/agents/developer.md', ' tweak'))
        report = self.run_check(diff=(base, head), enforce=True)
        self.assertEqual([], report.errors)
        self.assertEqual([], report.warnings)

    def test_a_pr_changing_a_case_or_the_registry_entry_or_the_run_config_is_detected(self):
        for label, change in (
            ('case', lambda: self.repo.write_case('spec-critic', 'extra')),
            ('config', lambda: self.repo.write_json('tools/evals/config.json', dict(
                self.repo.read_json('tools/evals/config.json'), n=4))),
            ('registry', lambda: self.repo.write_json('tools/evals/suites.json', self._with_timeout())),
        ):
            with self.subTest(change=label):
                repo_ = TempRepo()
                self.addCleanup(repo_.cleanup)
                self.repo = repo_
                self.measure_all()
                base, head = self.pr(change)
                # the working tree is the head: the results are stale for the suites the change touched
                report = self.run_check(diff=(base, head), enforce=True)
                self.assertTrue(report.errors, label)

    def _with_timeout(self):
        reg = self.repo.read_json('tools/evals/suites.json')
        reg['suites']['spec-critic']['pass_timeout_minutes'] = 40
        return reg

    def test_a_pr_changing_thresholds_or_the_estimate_is_not_a_suite_change(self):
        self.measure_all()

        def change():
            cfg = self.repo.read_json('tools/evals/config.json')
            cfg['thresholds']['cost_warning_percent'] = 60
            cfg['pass_cost_estimate_usd'] = 2
            self.repo.write_json('tools/evals/config.json', cfg)
        base, head = self.pr(change)
        report = self.run_check(diff=(base, head), enforce=True)
        self.assertEqual([], report.errors)

    def test_a_pr_adding_a_regression_result_is_an_error(self):
        self.measure_all()

        def change():
            self.repo.append('.claude/agents/code-reviewer.md', ' tweak')
            self.measure('code-reviewer', 'regression', date='2026-01-02T00:00:00Z', commit='b' * 40)
        base, head = self.pr(change)
        report = self.run_check(diff=(base, head), enforce=True)
        self.assertTrue(any('regression result' in e and 'code-reviewer' in e for e in report.errors), report.errors)

    def test_a_newer_pass_does_not_clear_an_added_regression_and_the_message_names_the_decision(self):
        self.measure_all()

        def change():
            self.repo.append('.claude/agents/code-reviewer.md', ' tweak')
            self.measure('code-reviewer', 'regression', date='2026-01-02T00:00:00Z', commit='b' * 40)
            self.measure('code-reviewer', 'pass', date='2026-01-03T00:00:00Z', commit='c' * 40)
        base, head = self.pr(change)
        report = self.run_check(diff=(base, head), enforce=True)
        self.assertEqual(1, len(report.errors), report.errors)
        message = report.errors[0]
        self.assertIn('adds the regression result', message)
        self.assertIn('accept', message)
        self.assertIn('waive', message)
        self.assertIn('user', message)
        self.assertNotIn('/evals', message)   # a new measurement does not clear it

    def test_a_pr_adding_a_regression_and_a_newer_accepted_result_passes(self):
        self.measure_all()

        def change():
            self.repo.append('.claude/agents/code-reviewer.md', ' tweak')
            run = self.measure('code-reviewer', 'regression', date='2026-01-02T00:00:00Z', commit='b' * 40)
            history.write_result(self.repo.root, 'code-reviewer', history.decision(
                'accepted', 'code-reviewer', self.fingerprint('code-reviewer'), 'c' * 40, 'intended', run,
                date='2026-01-03T00:00:00Z'))
        base, head = self.pr(change)
        self.assertEqual([], self.run_check(diff=(base, head), enforce=True).errors)

    def test_a_pr_adding_a_regression_and_a_newer_accepted_for_another_run_is_still_an_error(self):
        self.measure_all()

        def change():
            self.repo.append('.claude/agents/code-reviewer.md', ' tweak')
            self.measure('code-reviewer', 'regression', date='2026-01-02T00:00:00Z', commit='b' * 40)
            history.write_result(self.repo.root, 'code-reviewer', history.decision(
                'accepted', 'code-reviewer', self.fingerprint('code-reviewer'), 'c' * 40, 'intended',
                '20200101T000000Z-bbbbbbb.json', date='2026-01-03T00:00:00Z'))
        base, head = self.pr(change)
        self.assertTrue(self.run_check(diff=(base, head), enforce=True).errors)

    def test_a_regression_that_was_already_on_the_base_is_not_added_by_the_pr(self):
        self.measure('code-reviewer', 'regression')
        base, head = self.pr(lambda: self.repo.append('docs/other.md', 'x'))
        report = self.run_check(diff=(base, head), enforce=True)
        # not added by the pr, but still the current result of an unchanged suite: a warning
        self.assertEqual([], report.errors)
        self.assertWarningFor(report, 'code-reviewer')

    def test_an_added_regression_is_a_warning_without_enforce(self):
        self.measure_all()

        def change():
            self.measure('code-reviewer', 'regression', date='2026-01-02T00:00:00Z', commit='b' * 40)
        base, head = self.pr(change)
        report = self.run_check(diff=(base, head), enforce=False)
        self.assertEqual([], report.errors)

    def test_the_diff_uses_the_merge_base(self):
        self.measure_all()
        base = self.repo.commit('base')
        self.repo.branch('feature')
        self.repo.append('docs/other.md', 'x')
        head = self.repo.commit('feature change')
        self.repo.checkout('main')
        self.repo.append('.claude/agents/spec-critic.md', ' moved on')
        moved = self.repo.commit('main moved on')   # a later change of main is not part of the pr
        self.repo.checkout('feature')
        report = self.run_check(diff=(moved, head), enforce=True)
        self.assertNotIn('spec-critic', ' '.join(report.errors))
        self.assertTrue(base)

    def test_an_unresolvable_revision_is_a_check_error(self):
        self.repo.commit('base')
        with self.assertRaises(check.CheckError):
            self.run_check(diff=('0' * 40, 'HEAD'), enforce=True)

    def test_parse_diff_needs_three_dots(self):
        self.assertEqual(('a', 'b'), check.parse_diff('a...b'))
        for bad in ('a..b', 'a', '...b', 'a...', 'a...b...c', '', '--x...b'):
            with self.assertRaises(check.CheckError, msg=bad):
                check.parse_diff(bad)

    def test_the_diff_needs_git(self):
        plain = TempRepo(git=False)
        self.addCleanup(plain.cleanup)
        with self.assertRaises(check.CheckError):
            check.check(plain.root, diff=('main', 'HEAD'), enforce=True)


class NoDiffEnforceTest(CheckTestCase):
    def test_every_stale_measured_suite_is_an_error(self):
        self.measure_all()
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        self.repo.append('docs/features/groom/spec.md', 'x')
        report = self.run_check(enforce=True)
        self.assertEqual(2, len(report.errors))
        self.assertErrorFor(report, 'code-reviewer')
        self.assertErrorFor(report, 'backlog-groomer')

    def test_an_unmeasured_suite_is_not_an_error(self):
        self.measure('spec-critic')
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        self.assertEqual([], self.run_check(enforce=True).errors)

    def test_works_without_git_when_no_diff_is_given(self):
        plain = TempRepo(git=False)
        self.addCleanup(plain.cleanup)
        report = check.check(plain.root, enforce=True)
        self.assertEqual([], report.errors)


class CodeHashWarningTest(CheckTestCase):
    def test_a_changed_validator_since_the_measurement_is_a_warning_only(self):
        self.measure_all()
        self.repo.append('.claude/skills/groom/groom.py', '# changed\n')
        report = self.run_check(enforce=True)
        self.assertEqual([], report.errors)
        self.assertTrue(any('groom.py' in w and 'new measurement' in w for w in report.warnings), report.warnings)

    def test_a_changed_scoring_code_list_since_the_measurement_is_a_warning_only(self):
        self.measure_all()
        self.repo.write('tools/evals/scoring.py', 'a = 1\n')
        cfg = self.repo.read_json('tools/evals/config.json')
        cfg['scoring_code'] = ['tools/evals/scoring.py']
        self.repo.write_json('tools/evals/config.json', cfg)
        report = self.run_check(enforce=True)
        self.assertEqual([], report.errors)
        self.assertTrue(any('scoring code' in w for w in report.warnings), report.warnings)


class BrokenRegistryTest(CheckTestCase):
    def test_a_missing_registry_is_a_check_error(self):
        self.repo.commit('base')
        os.remove(os.path.join(self.repo.root, 'tools/evals/suites.json'))
        with self.assertRaises(check.CheckError) as ctx:
            self.run_check()
        self.assertIn('suites.json', str(ctx.exception))

    def test_a_corrupt_history_file_is_a_check_error(self):
        self.repo.write('tools/evals/history/code-reviewer/20260101T000000Z-aaaaaaa.json', '{x')
        with self.assertRaises(check.CheckError):
            self.run_check()


if __name__ == '__main__':
    unittest.main()

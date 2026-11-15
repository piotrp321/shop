"""Tests of the command line of evals.py: exit codes, output, accept and waive, and the error paths (missing registry,
malformed JSON, a bad --diff, no git)."""
import os
import subprocess
import sys
import unittest
from unittest import mock

from testutil import HERE, TempRepo, measured_result, run_cli

import evals
import evals_history as history
import evals_registry as registry
import evals_repo as repo

OLD = '2026-01-01T00:00:00Z'
SCRIPT = os.path.join(HERE, 'evals.py')


class CliTestCase(unittest.TestCase):
    def setUp(self):
        self.repo = TempRepo()
        self.addCleanup(self.repo.cleanup)
        # the form of the output depends on GITHUB_ACTIONS (annotations in CI); the tests do not, in-process and in
        # the processes they start (os.environ is copied to them)
        patcher = mock.patch.dict(os.environ)
        patcher.start()
        self.addCleanup(patcher.stop)
        os.environ.pop('GITHUB_ACTIONS', None)

    def run_evals(self, *args):
        return run_cli(evals, args, self.repo.root)

    def fingerprint(self, suite):
        source = repo.DiskSource(self.repo.root)
        reg, cfg = registry.load(source)
        return registry.fingerprint(source, suite, reg['suites'][suite], cfg)

    def measure(self, suite='code-reviewer', verdict='baseline', date=OLD, commit=None):
        source = repo.DiskSource(self.repo.root)
        reg, cfg = registry.load(source)
        result = measured_result(suite=suite, verdict=verdict, date=date, fingerprint=self.fingerprint(suite),
                                 commit=commit)
        result.update(registry.code_hashes(source, reg['suites'][suite], cfg))
        return os.path.basename(history.write_result(self.repo.root, suite, result))

    def history_names(self, suite='code-reviewer'):
        return history.list_names(repo.DiskSource(self.repo.root), suite)


class OutputFormTest(CliTestCase):
    def broken_config(self):
        cfg = self.repo.read_json('tools/evals/config.json')
        cfg['n'] = 2
        self.repo.write_json('tools/evals/config.json', cfg)
        self.repo.commit('base')

    def test_without_github_actions_the_findings_have_plain_prefixes(self):
        self.broken_config()
        code, out, _ = self.run_evals('lint')
        self.assertEqual(1, code, out)
        self.assertIn('ERROR   config.json: n must be', out)
        self.assertNotIn('::error::', out)

    def test_in_github_actions_the_errors_are_annotations(self):
        self.broken_config()
        os.environ['GITHUB_ACTIONS'] = 'true'
        code, out, _ = self.run_evals('lint')
        self.assertEqual(1, code, out)
        self.assertIn('::error::config.json: n must be', out)
        self.assertNotIn('ERROR', out)

    def test_in_github_actions_the_warnings_are_annotations(self):
        self.repo.commit('base')
        os.environ['GITHUB_ACTIONS'] = 'true'
        code, out, _ = self.run_evals('check')
        self.assertEqual(0, code, out)
        self.assertIn('::warning::suite code-reviewer has no measurement', out)
        self.assertNotIn('WARNING', out)


class LintCommandTest(CliTestCase):
    def test_a_clean_repository_exits_zero(self):
        self.repo.commit('base')
        code, out, _ = self.run_evals('lint')
        self.assertEqual(0, code, out)
        self.assertIn('evals lint: OK', out)

    def test_an_error_exits_one_and_is_printed(self):
        cfg = self.repo.read_json('tools/evals/config.json')
        cfg['n'] = 2
        self.repo.write_json('tools/evals/config.json', cfg)
        code, out, _ = self.run_evals('lint')
        self.assertEqual(1, code)
        self.assertIn('ERROR', out)
        self.assertIn('n must be', out)

    def test_a_missing_registry_exits_one_with_a_message(self):
        os.remove(os.path.join(self.repo.root, 'tools/evals/suites.json'))
        code, out, _ = self.run_evals('lint')
        self.assertEqual(1, code)
        self.assertIn('tools/evals/suites.json is missing', out)

    def test_a_malformed_registry_exits_one(self):
        self.repo.write('tools/evals/suites.json', '{broken')
        code, out, _ = self.run_evals('lint')
        self.assertEqual(1, code)
        self.assertIn('not valid JSON', out)


class CheckCommandTest(CliTestCase):
    def test_an_empty_history_warns_and_exits_zero_with_enforce(self):
        self.repo.commit('base')
        code, out, _ = self.run_evals('check', '--enforce')
        self.assertEqual(0, code)
        self.assertIn('WARNING', out)
        self.assertIn('code-reviewer', out)

    def test_a_stale_suite_with_enforce_exits_one_naming_the_suite_and_the_command(self):
        self.measure()
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        code, out, _ = self.run_evals('check', '--enforce')
        self.assertEqual(1, code)
        self.assertIn('suite code-reviewer is stale: run /evals code-reviewer', out)

    def test_a_stale_suite_without_enforce_exits_zero(self):
        self.measure()
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        self.assertEqual(0, self.run_evals('check')[0])

    def test_the_diff_argument_is_used(self):
        self.measure()
        base = self.repo.commit('base')
        self.repo.branch('feature')
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        head = self.repo.commit('change')
        code, out, _ = self.run_evals('check', '--diff', '%s...%s' % (base, head), '--enforce')
        self.assertEqual(1, code, out)

    def test_a_malformed_diff_argument_exits_two(self):
        self.repo.commit('base')
        code, _, err = self.run_evals('check', '--diff', 'main..HEAD')
        self.assertEqual(2, code)
        self.assertIn('three dots', err)

    def test_an_unknown_revision_in_the_diff_exits_two(self):
        self.repo.commit('base')
        code, _, err = self.run_evals('check', '--diff', 'nope...HEAD')
        self.assertEqual(2, code)
        self.assertIn('cannot compute the diff', err)

    def test_a_missing_registry_exits_two(self):
        os.remove(os.path.join(self.repo.root, 'tools/evals/suites.json'))
        code, _, err = self.run_evals('check')
        self.assertEqual(2, code)
        self.assertIn('tools/evals/suites.json', err)

    def test_a_malformed_registry_exits_two(self):
        self.repo.write('tools/evals/suites.json', '{broken')
        code, _, err = self.run_evals('check')
        self.assertEqual(2, code)
        self.assertIn('not valid JSON', err)

    def test_a_root_that_is_not_a_directory_exits_two(self):
        code, _, err = run_cli(evals, ['check'], os.path.join(self.repo.root, 'missing'))
        self.assertEqual(2, code)
        self.assertIn('not a directory', err)

    def test_no_command_and_an_unknown_command_exit_two(self):
        self.assertEqual(2, self.run_evals()[0])
        self.assertEqual(2, self.run_evals('frobnicate')[0])


class ReportCommandTest(CliTestCase):
    def test_an_empty_history_is_none(self):
        code, out, _ = self.run_evals('report')
        self.assertEqual(0, code)
        self.assertIn('code-reviewer', out)
        self.assertIn('none', out)

    def test_the_history_is_a_table_with_the_newest_marked_current(self):
        self.measure(verdict='baseline', date='2026-01-01T00:00:00Z')
        self.measure(verdict='pass', date='2026-01-02T00:00:00Z', commit='b' * 40)
        code, out, _ = self.run_evals('report', '--suite', 'code-reviewer')
        self.assertEqual(0, code)
        self.assertIn('20260101T000000Z-aaaaaaa.json', out)
        self.assertIn('20260102T000000Z-bbbbbbb.json', out)
        self.assertIn('baseline', out)
        self.assertIn('pass', out)
        self.assertIn('current', out)
        self.assertNotIn('spec-critic', out)

    def test_a_stale_newest_result_is_marked_stale(self):
        self.measure()
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        self.assertIn('stale', self.run_evals('report', '--suite', 'code-reviewer')[1])

    def test_decisions_show_their_reason(self):
        run = self.measure(verdict='regression')
        history.write_result(self.repo.root, 'code-reviewer', history.decision(
            'accepted', 'code-reviewer', self.fingerprint('code-reviewer'), 'a' * 40, 'known loss', run,
            date='2026-01-02T00:00:00Z'))
        out = self.run_evals('report', '--suite', 'code-reviewer')[1]
        self.assertIn('accepted', out)
        self.assertIn('known loss', out)

    def test_a_corrupt_history_file_exits_two(self):
        self.repo.write('tools/evals/history/code-reviewer/20260101T000000Z-aaaaaaa.json', '{x')
        code, _, err = self.run_evals('report', '--suite', 'code-reviewer')
        self.assertEqual(2, code)
        self.assertIn('not valid JSON', err)

    def test_an_unknown_suite_exits_two(self):
        code, _, err = self.run_evals('report', '--suite', 'nope')
        self.assertEqual(2, code)
        self.assertIn('nope', err)


class DecisionCommandTest(CliTestCase):
    def test_accept_writes_an_accepted_file_for_the_current_regression(self):
        run = self.measure(verdict='regression')
        self.repo.commit('base')
        code, out, _ = self.run_evals('accept', 'code-reviewer', '--reason', 'Utrata jest zamierzona: zmiana polityki')
        self.assertEqual(0, code, out)
        names = self.history_names()
        self.assertEqual(2, len(names))
        result = history.load_result(repo.DiskSource(self.repo.root), 'code-reviewer', names[-1])
        self.assertEqual('accepted', result['verdict'])
        self.assertEqual(run, result['run_file'])
        self.assertEqual(self.repo.head(), result['commit'])
        self.assertEqual(self.fingerprint('code-reviewer'), result['fingerprint'])
        self.assertEqual('Utrata jest zamierzona: zmiana polityki', result['reason'])
        self.assertEqual(0, self.run_evals('check', '--enforce')[0])
        self.assertEqual(0, self.run_evals('lint')[0])

    def test_accept_refuses_when_the_current_result_is_not_a_regression(self):
        for verdict in ('baseline', 'pass'):
            repo_ = TempRepo()
            self.addCleanup(repo_.cleanup)
            self.repo = repo_
            self.measure(verdict=verdict)
            self.repo.commit('base')
            code, _, err = self.run_evals('accept', 'code-reviewer', '--reason', 'x')
            self.assertEqual(1, code, verdict)
            self.assertIn('not a regression', err)
            self.assertEqual(1, len(self.history_names()))

    def test_accept_refuses_an_empty_history(self):
        self.repo.commit('base')
        code, _, err = self.run_evals('accept', 'code-reviewer', '--reason', 'x')
        self.assertEqual(1, code)
        self.assertIn('none', err)

    def test_accept_refuses_a_stale_regression(self):
        self.measure(verdict='regression')
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        self.repo.commit('base')
        code, _, err = self.run_evals('accept', 'code-reviewer', '--reason', 'x')
        self.assertEqual(1, code)
        self.assertIn('stale', err)
        self.assertEqual(1, len(self.history_names()))

    def test_accept_and_waive_need_a_non_blank_reason(self):
        self.measure(verdict='regression')
        self.repo.commit('base')
        for command in ('accept', 'waive'):
            for reason in ('', '   ', ' ', '​'):
                code, _, err = self.run_evals(command, 'code-reviewer', '--reason', reason)
                self.assertEqual(1, code, (command, reason))
                self.assertIn('reason', err)
        self.assertEqual(1, len(self.history_names()))

    def test_accept_and_waive_need_the_reason_option(self):
        self.repo.commit('base')
        self.assertEqual(2, self.run_evals('accept', 'code-reviewer')[0])
        self.assertEqual(2, self.run_evals('waive', 'code-reviewer')[0])

    def test_an_unknown_suite_exits_two(self):
        self.repo.commit('base')
        self.assertEqual(2, self.run_evals('accept', 'nope', '--reason', 'x')[0])
        self.assertEqual(2, self.run_evals('waive', 'nope', '--reason', 'x')[0])

    def test_waive_writes_the_current_fingerprint_so_a_changed_agent_passes(self):
        self.measure()
        self.repo.append('.claude/agents/code-reviewer.md', 'typo fix')
        self.repo.commit('base')
        self.assertEqual(1, self.run_evals('check', '--enforce')[0])
        code, out, _ = self.run_evals('waive', 'code-reviewer', '--reason', 'literówka w opisie')
        self.assertEqual(0, code, out)
        newest = history.latest(repo.DiskSource(self.repo.root), 'code-reviewer')[1]
        self.assertEqual('waived', newest['verdict'])
        self.assertEqual(self.fingerprint('code-reviewer'), newest['fingerprint'])
        self.assertEqual('literówka w opisie', newest['reason'])
        self.assertEqual(0, self.run_evals('check', '--enforce')[0])
        self.assertEqual(0, self.run_evals('lint')[0])

    def test_waive_on_an_empty_history_works(self):
        self.repo.commit('base')
        code, out, _ = self.run_evals('waive', 'code-reviewer', '--reason', 'new suite, no cases yet')
        self.assertEqual(0, code, out)
        self.assertNotIn('run_file', history.latest(repo.DiskSource(self.repo.root), 'code-reviewer')[1])

    def test_a_history_with_only_waived_files_is_a_suite_without_a_measurement(self):
        self.repo.commit('base')
        self.assertEqual(0, self.run_evals('waive', 'code-reviewer', '--reason', 'no cases yet')[0])
        self.repo.append('.claude/agents/code-reviewer.md', ' tweak')
        code, out, _ = self.run_evals('check', '--enforce')
        self.assertEqual(0, code, out)
        self.assertRegex(out, r'WARNING .*code-reviewer.*no measurement')
        # a measurement (here an accepted regression) makes the same change an error
        self.measure(verdict='regression', date='2026-12-01T00:00:00Z')
        self.assertEqual(1, self.run_evals('check', '--enforce')[0])

    def test_a_second_waiver_on_a_history_without_a_measurement_has_no_run_file(self):
        self.repo.commit('base')
        history.write_result(self.repo.root, 'code-reviewer', history.decision(
            'waived', 'code-reviewer', self.fingerprint('code-reviewer'), 'a' * 40, 'first', None, date=OLD))
        self.repo.append('.claude/agents/code-reviewer.md', ' tweak')
        self.assertEqual(0, self.run_evals('waive', 'code-reviewer', '--reason', 'second')[0])
        self.assertEqual(0, self.run_evals('lint')[0])
        self.assertNotIn('run_file', history.latest(repo.DiskSource(self.repo.root), 'code-reviewer')[1])

    def test_waive_after_a_regression_names_it_so_a_pr_adding_both_passes(self):
        self.repo.commit('start')
        self.repo.branch('feature')
        run = self.measure(verdict='regression')
        base = self.repo.git('rev-parse', 'main')
        self.repo.commit('regression')
        code, out, _ = self.run_evals('waive', 'code-reviewer', '--reason', 'waived by the user')
        self.assertEqual(0, code, out)
        result = history.latest(repo.DiskSource(self.repo.root), 'code-reviewer')[1]
        self.assertEqual(run, result['run_file'])
        head = self.repo.commit('waiver')
        self.assertEqual(0, self.run_evals('check', '--diff', '%s...%s' % (base, head), '--enforce')[0])

    def test_the_decision_files_are_the_newest_files(self):
        self.measure()
        self.repo.commit('base')
        self.run_evals('waive', 'code-reviewer', '--reason', 'x')
        self.assertEqual('waived', history.latest(repo.DiskSource(self.repo.root), 'code-reviewer')[1]['verdict'])


class ProcessTest(CliTestCase):
    """The script as a process: a working directory elsewhere and no git on the PATH."""

    def run_script(self, *args, **env):
        environment = dict(os.environ)
        environment.update(env)
        result = subprocess.run([sys.executable, SCRIPT, '--root', self.repo.root] + list(args), cwd=self.repo.root,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True,
                                env=environment)
        return result.returncode, result.stdout, result.stderr

    def test_the_script_runs_as_a_process(self):
        self.repo.commit('base')
        code, out, _ = self.run_script('check')
        self.assertEqual(0, code, out)

    def test_without_git_accept_and_the_diff_exit_two_and_lint_warns(self):
        self.measure(verdict='regression')
        empty = os.path.join(self.repo.root, 'empty-bin')
        os.makedirs(empty)
        code, _, err = self.run_script('accept', 'code-reviewer', '--reason', 'x', PATH=empty)
        self.assertEqual(2, code)
        self.assertIn('git', err)
        code, _, err = self.run_script('check', '--diff', 'a...b', PATH=empty)
        self.assertEqual(2, code)
        self.assertIn('git', err)
        self.assertEqual(0, self.run_script('check', PATH=empty)[0])

    def test_lint_without_git_skips_the_commit_checks_with_a_warning(self):
        self.repo.write_case('code-reviewer', 'c', commit='d' * 40)
        empty = os.path.join(self.repo.root, 'empty-bin')
        os.makedirs(empty)
        code, out, _ = self.run_script('lint', PATH=empty)
        self.assertEqual(0, code, out)
        self.assertIn('WARNING', out)

    def test_the_default_root_is_the_repository_of_the_script(self):
        result = subprocess.run([sys.executable, SCRIPT, 'check'], cwd=self.repo.root, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, universal_newlines=True)
        self.assertIn(result.returncode, (0, 1))
        self.assertIn('code-reviewer', result.stdout)


if __name__ == '__main__':
    unittest.main()

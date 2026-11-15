"""Tests of the wiring of the evals in make and in the CI workflow (text of make/evals.mk and ci.yml, and `make -n`)."""
import os
import re
import shutil
import subprocess
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
WORKFLOW = os.path.join(REPO, '.github/workflows/ci.yml')
FRAGMENT = os.path.join(REPO, 'make/evals.mk')


def read(path):
    with open(path, encoding='utf-8') as handle:
        return handle.read()


def job_block(name):
    """The text of a top-level job of ci.yml: from `  <name>:` to the next job (two-space indented key) or the end."""
    text = read(WORKFLOW)
    match = re.search(r'(?m)^  %s:\n' % re.escape(name), text)
    if not match:
        raise AssertionError('no job %s in ci.yml' % name)
    rest = text[match.end():]
    following = re.search(r'(?m)^  [a-z][a-z0-9-]*:\n', rest)
    return rest[:following.start()] if following else rest


def make_dry_run(*args):
    if shutil.which('make') is None:
        raise unittest.SkipTest('make is not installed')
    return subprocess.run(['make', '-n', '-C', REPO] + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          universal_newlines=True)


class MakeFragmentTest(unittest.TestCase):
    def test_lint_is_a_check_target_and_check_is_not(self):
        fragment = read(FRAGMENT)
        self.assertRegex(fragment, r'(?m)^CHECK_TARGETS \+= evals-lint$')
        self.assertNotRegex(fragment, r'CHECK_TARGETS \+=[^\n]*evals-check')
        self.assertNotRegex(fragment, r'(?:TEST_TARGETS|CI_AFTER_E2E) \+=[^\n]*evals')

    def test_the_targets_have_a_comment_and_run_the_script(self):
        fragment = read(FRAGMENT)
        self.assertRegex(fragment, r'(?m)^evals-lint:\n\tpython3 tools/evals/evals\.py lint$')
        self.assertRegex(fragment, r'(?m)^evals-check:\n\tpython3 tools/evals/evals\.py check \$\(EVALS_CHECK_ARGS\)$')
        self.assertRegex(fragment, r'(?m)^# .*\n(?:# .*\n)*evals-lint:')
        self.assertRegex(fragment, r'(?m)^# .*\n(?:# .*\n)*evals-check:')

    def test_make_check_code_runs_lint_and_not_check(self):
        result = make_dry_run('check-code', 'RUN=true')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('evals-lint', result.stdout)
        self.assertNotIn('evals-check', result.stdout)

    def test_the_arguments_reach_the_check(self):
        result = make_dry_run('evals-check', 'EVALS_CHECK_ARGS=--enforce --diff a...b')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('python3 tools/evals/evals.py check --enforce --diff a...b', result.stdout)

    def test_make_check_of_the_root_makefile_includes_the_infra_check_and_lint(self):
        result = make_dry_run('check', 'RUN=true')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('evals-lint', result.stdout)


class WorkflowTest(unittest.TestCase):
    def setUp(self):
        self.job = job_block('evals')

    def test_the_job_runs_for_every_change_without_a_dependency_or_a_condition(self):
        self.assertNotRegex(self.job, r'(?m)^    needs:')
        self.assertNotRegex(self.job, r'(?m)^    if:')
        self.assertNotRegex(self.job, r'(?m)^\s+if:')

    def test_the_job_has_a_short_timeout_and_read_only_permissions(self):
        timeout = re.search(r'(?m)^    timeout-minutes: (\d+)$', self.job)
        self.assertIsNotNone(timeout)
        self.assertLessEqual(int(timeout.group(1)), 10)
        self.assertRegex(self.job, r'(?m)^    permissions:\n      contents: read$')

    def test_the_checkout_has_the_full_history_and_the_pinned_action_of_the_other_jobs(self):
        pins = set(re.findall(r'uses: (actions/checkout@[0-9a-f]{40}) # v\S+', read(WORKFLOW)))
        self.assertEqual(1, len(pins), pins)
        self.assertIn('uses: %s' % pins.pop(), self.job)
        self.assertRegex(self.job, r'(?m)^        with:\n          fetch-depth: 0$')

    def test_the_job_runs_lint_and_check_with_the_arguments_of_the_script(self):
        self.assertIn('sh .github/scripts/evals-args.sh', self.job)
        self.assertRegex(self.job, r'make evals-lint evals-check EVALS_CHECK_ARGS="\$EVALS_CHECK_ARGS"')

    def test_the_event_data_goes_through_the_environment_not_the_script_text(self):
        run_lines = [l for l in self.job.splitlines() if '${{' in l and not l.lstrip().startswith(('env:', '#'))]
        for line in run_lines:
            self.assertRegex(line, r'^\s+[A-Z_]+: \$\{\{', 'an expression outside an env entry: %r' % line)

    def test_the_job_uses_no_secret_and_no_environment(self):
        self.assertNotIn('secrets.', self.job)
        self.assertNotRegex(self.job, r'(?m)^    environment:')

    def test_the_job_runs_no_model(self):
        for word in ('claude', 'anthropic', 'ANTHROPIC'):
            self.assertNotIn(word, self.job)

    def test_the_header_comment_describes_the_job(self):
        header = read(WORKFLOW).split('\nname: CI', 1)[0]
        self.assertIn('`evals`', header)

    def test_the_other_jobs_do_not_depend_on_evals(self):
        text = read(WORKFLOW)
        self.assertNotRegex(text, r'needs:[^\n]*\bevals\b')


if __name__ == '__main__':
    unittest.main()

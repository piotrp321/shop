"""Tests of the commands `evals.py estimate` and `evals.py run` (spec: Limity) with the fake CLI."""
import json
import os

from testutil import measured_result, run_cli
from test_runner import RunnerTestCase, init, ok

import evals
import evals_history as history


class EstimateCommandTest(RunnerTestCase):
    def add_cases(self, count):
        for index in range(2, count + 1):
            self.add_case('case-%d' % index)
        self.repo.commit('%d cases' % count)

    def estimate(self, *args):
        return run_cli(evals, ['estimate'] + list(args), self.repo.root)

    def test_without_history_the_registry_estimate_gives_27_passes_under_the_cap(self):
        self.add_cases(9)
        code, out, err = self.estimate('code-reviewer')
        self.assertEqual(0, code, err)
        self.assertIn('27 passes', out)
        self.assertIn('40.50 USD', out)
        self.assertIn('the estimate of the registry', out)
        self.assertIn('9.0 h', out)          # 27 x the 20 min pass timeout, an upper bound
        self.assertIn('within the cap of 50.00 USD', out)

    def test_the_median_of_the_last_measurement_is_used_and_above_the_cap_is_a_refusal(self):
        self.add_cases(9)
        result = measured_result(commit=self.head)
        for case in result['cases']:
            for repetition in case['repetitions']:
                repetition.update(cost_usd=2.0, duration_s=60.0)
        name = os.path.basename(history.write_result(self.repo.root, 'code-reviewer', result))
        self.repo.commit('a measurement')
        code, out, _ = self.estimate('code-reviewer')
        self.assertEqual(1, code)
        self.assertIn('54.00 USD', out)
        self.assertIn(name, out)
        self.assertIn('27.0 min', out)
        self.assertIn('ABOVE the cap of 50.00 USD', out)

    def test_every_suite_and_the_total_as_json(self):
        code, out, _ = self.estimate('--json')
        self.assertEqual(0, code)
        data = json.loads(out)
        self.assertEqual(['backlog-groomer', 'code-reviewer', 'retro-analyst', 'spec-critic'],
                         sorted(s['suite'] for s in data['suites']))
        self.assertAlmostEqual(4.5, data['total']['cost_usd'])
        self.assertEqual(3, data['total']['passes'])

    def test_a_trial_run_has_n_times_the_pass_cap(self):
        code, out, _ = self.estimate('code-reviewer', '--case', 'one')
        self.assertEqual(0, code)
        self.assertIn('3 passes', out)
        self.assertIn('within the cap of 9.00 USD', out)

    def test_an_unknown_suite_or_case_is_a_usage_error(self):
        self.assertEqual(2, self.estimate('nope')[0])
        self.assertEqual(2, self.estimate('code-reviewer', '--case', 'nope')[0])
        self.assertEqual(2, self.estimate('--case', 'one')[0])


class RunCommandTest(RunnerTestCase):
    def run_cmd(self, *args, binary=None):
        argv = ['run'] + list(args) + ['--state-root', self.state_root]
        if binary:
            argv += ['--claude-bin', binary]
        return run_cli(evals, argv, self.repo.root)

    def test_a_finished_run_prints_its_id_and_status(self):
        code, out, err = self.run_cmd('code-reviewer', binary=self.fake(ok()))
        self.assertEqual(0, code, err)
        run_id = out.split('run ')[1].split(':')[0]
        self.assertEqual('done', json.load(open(os.path.join(self.state_root, run_id, 'run.json')))['status'])
        self.assertIn('done', out)
        self.assertIn('3 passes', out)

    def test_a_stopped_run_prints_how_to_resume_and_resume_finishes_it(self):
        binary = self.fake([init(), {'rate_limit': {'status': 'rejected'}}, {'sleep': 30}])
        code, out, _ = self.run_cmd('code-reviewer', binary=binary)
        self.assertEqual(1, code)
        self.assertIn('limit_stop', out)
        run_id = out.split('run ')[1].split(':')[0]
        self.assertIn('evals.py run --resume %s' % run_id, out)
        self.fake(ok())
        code, out, err = self.run_cmd('--resume', run_id, binary=binary)
        self.assertEqual(0, code, err)
        self.assertIn('done', out)

    def test_a_cost_stop_has_no_resume_hint_and_resume_is_refused(self):
        self.set_config(run_cost_cap_usd=4, pass_cost_estimate_usd=0.5)
        self.repo.commit('small cap')
        code, out, _ = self.run_cmd('code-reviewer', binary=self.fake(ok(cost=2)))
        self.assertEqual(1, code)
        self.assertIn('cost_stop', out)
        self.assertNotIn('--resume', out)
        run_id = out.split('run ')[1].split(':')[0]
        code, _, err = self.run_cmd('--resume', run_id, binary=self.fake(ok()))
        self.assertEqual(1, code)
        self.assertIn('cannot be resumed', err)

    def test_a_refused_run_is_exit_1_with_the_reason(self):
        self.set_config(pass_cost_estimate_usd=20)
        self.repo.commit('expensive')
        code, _, err = self.run_cmd('code-reviewer', binary=self.fake(ok()))
        self.assertEqual(1, code)
        self.assertIn('refused', err)
        self.assertIn('forecast', err)
        self.assertEqual(0, self.invocations())

    def test_a_trial_run_with_an_overlay_revision(self):
        code, out, err = self.run_cmd('code-reviewer', '--case', 'one', '--overlay-rev', self.case_commit,
                                      binary=self.fake(ok()))
        self.assertEqual(0, code, err)
        self.assertIn('trial', out)
        self.assertFalse(os.path.exists(os.path.join(self.repo.root, 'tools/evals/history')))

    def test_wrong_combinations_are_usage_errors(self):
        self.assertEqual(2, self.run_cmd()[0])
        self.assertEqual(2, self.run_cmd('code-reviewer', '--overlay-rev', 'HEAD')[0])
        self.assertEqual(2, self.run_cmd('code-reviewer', '--resume', 'x')[0])
        self.assertEqual(0, self.invocations())

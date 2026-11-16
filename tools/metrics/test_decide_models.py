"""Tests of rule D1 of `metrics.py decide` (spec: Decyzje optymalizacyjne, D1 model dla roli): the role on a model more
expensive than the cheapest one used in the epic, the EVALS 2.0 results it reads (only status, mode and subagent_model
of meta.json) and the rule that no result ever proposes a change of a model."""
import json
import os
import unittest

import testkit
from testkit import HAIKU, OPUS, SONNET, Env, fake_record, run_cli, store_records

RATES = {OPUS: 4e-5, SONNET: 1e-5}                   # the cheaper model costs a quarter
SECRET = 'SECRET-TEXT-OF-A-RUN'


def cell(usd, estimated=False):
    return {'usd': usd, 'estimated': estimated, 'tokens': {}, 'estimated_tokens': []}


def target(roles, total=100.0, rates=None, **kwargs):
    """A merged epic whose value is `roles`: {role: {model: usd}}; rates: {model: rate} of its attempts."""
    record = fake_record('target', value=total, **kwargs)
    record['value']['by_role_model'] = {role: {m: cell(usd) for m, usd in models.items()} for role, models in roles.items()}
    record['attempts'] = [{'task': 'T-1', 'kind': 'plan', 'number': 1, 'outcome': 'result',
                           'executor_status': 'READY_FOR_INTEGRATION', 'cli_result': True,
                           'model_rates': RATES if rates is None else rates}]
    return record


class D1Env(Env):
    def setUp(self):
        super().setUp()
        self.evals = os.path.join(self.root, 'evals')
        self.config = os.path.join(self.root, 'config.json')
        self.write_config()

    def write_config(self, roles=('code-reviewer',), directory=None):
        with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'config.json'), encoding='utf-8') as f:
            config = json.load(f)
        config['evals'] = {'roles': list(roles), 'results_dir': directory or self.evals}
        with open(self.config, 'w', encoding='utf-8') as f:
            json.dump(config, f)

    def run_meta(self, name, run, meta):
        directory = os.path.join(self.evals, name, 'runs', run)
        os.makedirs(directory, exist_ok=True)
        with open(os.path.join(directory, 'meta.json'), 'w', encoding='utf-8') as f:
            f.write(meta if isinstance(meta, str) else json.dumps(meta))

    def meta(self, status='ok', mode='replay', model=OPUS, name='m1', run='case-1'):
        self.run_meta(name, run, {'status': status, 'mode': mode, 'subagent_model': model, 'error': SECRET,
                                  'cost_usd': 1.5, 'case': SECRET})

    def decide(self, *extra):
        code, out, err = run_cli(['decide', 'target', '--state', self.state, '--night', self.night,
                                  '--config', self.config] + list(extra))
        self.assertEqual(code, 0, err)
        return out, json.loads(out)

    def d1(self, result, key='D1:code-reviewer'):
        found = [c for c in result['candidates'] if c['key'] == key]
        return found[0] if found else None

    def skipped(self, result, key='D1:code-reviewer'):
        found = [s for s in result['skipped'] if s['key'] == key]
        return found[0] if found else None

    def store(self, roles=None, **kwargs):
        store_records(self.state, target(roles or {'code-reviewer': {OPUS: 30.0, SONNET: 2.0}, 'delivery': {OPUS: 68.0}},
                                         **kwargs))


class FiringTest(D1Env):
    def test_a_role_with_15_percent_on_the_expensive_model_and_runs_only_on_it_gets_the_experiment_proposal(self):
        self.store({'code-reviewer': {OPUS: 15.0, SONNET: 2.0}, 'delivery': {OPUS: 83.0}})
        self.meta(model=OPUS)
        out, result = self.decide()
        candidate = self.d1(result)
        self.assertEqual(candidate['rule'], 'D1')
        self.assertEqual(candidate['data']['role'], 'code-reviewer')
        self.assertEqual(candidate['data']['share'], 0.15)
        self.assertEqual(candidate['data']['cheaper_model'], SONNET)
        self.assertEqual(candidate['data']['models'], {OPUS: 15.0})
        self.assertIn('porównania wariantów', candidate['proposal'])
        self.assertIn('EVALS 2.0', candidate['proposal'])
        self.assertIn('effect D1:code-reviewer', candidate['verification'])
        self.assertIn('USD', candidate['verification'])

    def test_just_below_15_percent_is_silent(self):
        self.store({'code-reviewer': {OPUS: 14.9, SONNET: 2.0}, 'delivery': {OPUS: 83.1}})
        self.meta(model=OPUS)
        _, result = self.decide()
        self.assertIsNone(self.d1(result))
        self.assertIsNone(self.skipped(result))

    def test_the_effect_is_the_role_value_times_one_minus_the_ratio_of_the_rates(self):
        self.store()
        self.meta()
        candidate = self.d1(self.decide()[1])
        self.assertEqual(candidate['expected_effect']['value_usd'], 22.5)        # 30 x (1 - 1/4)
        self.assertIsNone(candidate['expected_effect']['wall_h'])

    def test_only_the_value_on_models_more_expensive_than_the_cheapest_counts(self):
        self.store({'code-reviewer': {SONNET: 40.0}, 'delivery': {OPUS: 60.0}})
        self.meta()
        self.assertIsNone(self.d1(self.decide()[1]))

    def test_the_orchestrator_roles_are_not_agents_with_a_model_of_their_own(self):
        self.store({'code-reviewer': {SONNET: 2.0}, 'delivery': {OPUS: 90.0}, 'integration': {OPUS: 8.0}})
        self.meta()
        result = self.decide()[1]
        self.assertEqual(result['candidates'], [])
        self.assertEqual([s['key'] for s in result['skipped'] if s['key'].startswith('D1:')], [])

    def test_the_cheapest_model_is_the_one_with_the_lowest_rate_of_the_history(self):
        self.store({'code-reviewer': {OPUS: 20.0, SONNET: 2.0, HAIKU: 1.0}, 'delivery': {OPUS: 77.0}},
                   rates={OPUS: 4e-5, SONNET: 2e-5, HAIKU: 5e-6})
        self.meta(model=SONNET)                      # a run on a model that is not the cheapest does not count
        candidate = self.d1(self.decide()[1])
        self.assertEqual(candidate['data']['cheaper_model'], HAIKU)
        self.assertEqual(candidate['expected_effect']['value_usd'], 19.0)    # 20 x (1 - 1/8) + 2 x (1 - 1/4)

    def test_an_estimated_role_value_is_marked_in_the_data_and_the_proposal(self):
        record = target({'code-reviewer': {OPUS: 30.0, SONNET: 2.0}, 'delivery': {OPUS: 68.0}})
        record['value']['by_role_model']['code-reviewer'][OPUS]['estimated'] = True
        store_records(self.state, record)
        self.meta()
        candidate = self.d1(self.decide()[1])
        self.assertIn('role_value', candidate['data']['estimated'])
        self.assertTrue(candidate['expected_effect']['estimated'])
        self.assertIn('szacowan', candidate['proposal'])

    def test_a_role_value_that_is_exact_has_no_estimate_mark(self):
        self.store()
        self.meta()
        candidate = self.d1(self.decide()[1])
        self.assertEqual(candidate['data']['estimated'], [])
        self.assertNotIn('szacowan', candidate['proposal'])

    def test_one_model_in_the_epic_has_nothing_to_compare(self):
        self.store({'code-reviewer': {OPUS: 30.0}, 'delivery': {OPUS: 70.0}})
        self.meta()
        result = self.decide()[1]
        self.assertEqual(result['candidates'], [])
        self.assertIn('dwa modele', self.skipped(result, 'D1')['reason'])

    def test_a_model_without_a_historical_rate_cannot_be_ranked(self):
        self.store(rates={OPUS: 4e-5})
        self.meta()
        result = self.decide()[1]
        self.assertEqual(result['candidates'], [])
        self.assertTrue(any(SONNET in g for g in result['gaps']))


class EvalsResultsTest(D1Env):
    def test_a_counted_run_on_the_cheaper_model_means_the_comparison_is_done_without_a_proposal(self):
        self.store()
        self.meta(model=OPUS, run='a')
        self.meta(model=SONNET, run='b', name='m2')
        result = self.decide()[1]
        self.assertIsNone(self.d1(result))
        skipped = self.skipped(result)
        self.assertIn('porównanie w EVALS 2.0, decyzja w jego raporcie', skipped['reason'])
        self.assertEqual(skipped['data'], {'runs': 1, 'measurements': ['m2']})
        self.assertNotIn('proposal', skipped)

    def test_runs_that_are_not_counted_leave_the_proposal(self):
        self.store()
        self.meta(status='ok', mode='squash', model=SONNET, run='a')
        self.meta(status='infra_error', mode='replay', model=SONNET, run='b')
        self.meta(status='budget_stop', mode='replay', model=SONNET, run='c')
        self.meta(status='ok', mode='replay', model=OPUS, run='d')
        result = self.decide()[1]
        self.assertIsNotNone(self.d1(result))
        self.assertIsNone(self.skipped(result))

    def test_each_not_counted_status_alone_does_not_count(self):
        for status, mode in (('ok', 'squash'), ('ok', ''), ('ok', None), ('infra_error', 'replay'),
                             ('budget_stop', 'replay'), ('failed', 'replay')):
            with self.subTest(status=status, mode=mode):
                self.store()
                self.meta(status=status, mode=mode, model=SONNET)
                self.assertIsNotNone(self.d1(self.decide()[1]))

    def test_a_role_outside_the_evals_role_list_is_without_evals(self):
        self.store({'qa-adversarial': {OPUS: 40.0, SONNET: 1.0}, 'delivery': {OPUS: 59.0}})
        self.meta(model=SONNET)
        result = self.decide()[1]
        self.assertIsNone(self.d1(result, 'D1:qa-adversarial'))
        self.assertEqual(self.skipped(result, 'D1:qa-adversarial')['reason'], 'bez evali')

    def test_a_role_outside_the_list_needs_no_results_directory(self):
        self.store({'qa-adversarial': {OPUS: 40.0, SONNET: 1.0}, 'delivery': {OPUS: 59.0}})
        result = self.decide()[1]
        self.assertEqual(self.skipped(result, 'D1:qa-adversarial')['reason'], 'bez evali')
        self.assertEqual([g for g in result['gaps'] if 'D1' in g or 'EVALS' in g], [])

    def test_a_missing_results_directory_is_a_gap_and_gives_no_d1(self):
        self.store()
        result = self.decide()[1]
        self.assertIsNone(self.d1(result))
        self.assertTrue(any('katalog' in g and 'EVALS 2.0' in g for g in result['gaps']), result['gaps'])
        self.assertIn('brak wyników EVALS 2.0', self.skipped(result)['reason'])

    def test_a_directory_without_meta_json_is_a_gap_and_gives_no_d1(self):
        self.store()
        os.makedirs(os.path.join(self.evals, 'm1', 'runs', 'case-1'))
        result = self.decide()[1]
        self.assertIsNone(self.d1(result))
        self.assertTrue(any('meta.json' in g for g in result['gaps']), result['gaps'])

    def test_an_unreadable_meta_json_is_skipped_and_counted_in_the_gaps(self):
        self.store()
        self.meta(model=OPUS, run='good')
        self.run_meta('m1', 'broken', '{not json')
        self.run_meta('m1', 'no-fields', {'case': 'x'})
        self.run_meta('m1', 'not-an-object', '[1, 2]')
        self.run_meta('m1', 'no-model', {'status': 'ok', 'mode': 'replay'})
        result = self.decide()[1]
        self.assertIsNotNone(self.d1(result))
        self.assertTrue(any('4 pliki meta.json' in g for g in result['gaps']), result['gaps'])

    def test_only_unreadable_meta_files_give_no_d1_and_a_gap(self):
        self.store()
        self.run_meta('m1', 'broken', '{not json')
        result = self.decide()[1]
        self.assertIsNone(self.d1(result))
        self.assertTrue(any('meta.json' in g for g in result['gaps']))

    def test_the_text_of_a_run_and_the_path_never_reach_the_output(self):
        self.store()
        self.meta()
        out, _ = self.decide()
        self.assertNotIn(SECRET, out)
        self.assertNotIn(self.root, out)

    def test_the_results_directory_may_start_with_a_tilde(self):
        self.write_config(directory='~/evals')
        self.store()
        self.meta()
        self.assertIsNotNone(self.d1(self.decide()[1]))

    def test_with_several_evals_roles_the_runs_cannot_be_assigned_to_a_role(self):
        self.write_config(roles=('code-reviewer', 'qa-adversarial'))
        self.store()
        self.meta()
        result = self.decide()[1]
        self.assertIsNone(self.d1(result))
        self.assertIn('przypisać', self.skipped(result)['reason'])

    def test_no_result_proposes_a_change_of_a_model(self):
        self.store({'code-reviewer': {OPUS: 30.0, SONNET: 2.0}, 'qa-adversarial': {OPUS: 30.0, SONNET: 1.0},
                    'delivery': {OPUS: 36.0}})
        self.meta(model=OPUS)
        result = self.decide()[1]
        proposals = [c['proposal'] for c in result['candidates']]
        self.assertTrue(proposals)
        for proposal in proposals:
            self.assertIn('nie zmiany modelu', proposal)
            self.assertNotRegex(proposal, r'(?i)(zmień|przełącz|zastąp)\w* model')
        self.assertTrue(all('proposal' not in s for s in result['skipped']))


class OrderTest(D1Env):
    def test_a_d1_candidate_goes_after_the_ones_that_save_time_and_among_the_report_ones(self):
        record = target({'code-reviewer': {OPUS: 30.0, SONNET: 2.0}, 'delivery': {OPUS: 68.0}}, human_wait=30 * 3600.0,
                        to_done=100 * 3600.0)
        store_records(self.state, record)
        self.meta()
        result = self.decide()[1]
        self.assertEqual([c['key'] for c in result['candidates']], ['D4:human_wait', 'D1:code-reviewer'])
        self.assertEqual([c['key'] for c in result['report']], ['D4:human_wait', 'D1:code-reviewer'])


if __name__ == '__main__':
    unittest.main()

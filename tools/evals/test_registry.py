"""Tests of the registry, the config and the fingerprint: python3 -m unittest discover -s tools/evals -p 'test_*.py'"""
import copy
import json
import os
import unittest

from testutil import HERE, TempRepo

import evals_registry as registry
import evals_repo as repo


def load_real():
    with open(os.path.join(HERE, 'suites.json'), encoding='utf-8') as handle:
        reg = json.load(handle)
    with open(os.path.join(HERE, 'config.json'), encoding='utf-8') as handle:
        cfg = json.load(handle)
    return reg, cfg


class RealFilesTest(unittest.TestCase):
    def test_the_registry_and_the_config_of_the_repository_are_valid(self):
        reg, cfg = load_real()
        self.assertEqual([], registry.validate_registry(reg))
        self.assertEqual([], registry.validate_config(cfg))

    def test_the_registry_names_the_four_suites_with_their_watched_files(self):
        reg, _ = load_real()
        self.assertEqual({'code-reviewer', 'spec-critic', 'backlog-groomer', 'retro-analyst'}, set(reg['suites']))
        self.assertEqual(['.claude/agents/code-reviewer.md'], reg['suites']['code-reviewer']['watched'])
        self.assertEqual(['.claude/agents/backlog-groomer.md', 'docs/features/groom/spec.md'],
                         reg['suites']['backlog-groomer']['watched'])
        self.assertEqual(['.claude/agents/retro-analyst.md', 'docs/features/retro/spec.md'],
                         reg['suites']['retro-analyst']['watched'])
        self.assertEqual(['.claude/skills/groom/groom.py'], reg['suites']['backlog-groomer']['validators'])
        self.assertEqual(['.claude/skills/retro/retro.py'], reg['suites']['retro-analyst']['validators'])

    def test_the_canary_list_has_the_actions_of_the_spec(self):
        reg, _ = load_real()
        for action in ('canary_file', 'canary_token', 'gh issue close', 'gh pr merge', 'gh label', 'change_verdict'):
            self.assertIn(action, reg['canary_actions'])

    def test_the_defaults_are_those_of_the_spec(self):
        _, cfg = load_real()
        self.assertEqual(3, cfg['n'])
        self.assertEqual(3, cfg['pass_cost_cap_usd'])
        self.assertEqual(50, cfg['run_cost_cap_usd'])
        self.assertEqual(1.5, cfg['pass_cost_estimate_usd'])
        self.assertEqual(20, cfg['pass_timeout_minutes'])


class ValidationTest(unittest.TestCase):
    def setUp(self):
        self.reg, self.cfg = load_real()

    def problems(self, mutate, which='registry'):
        reg, cfg = copy.deepcopy(self.reg), copy.deepcopy(self.cfg)
        mutate(reg if which == 'registry' else cfg)
        return registry.validate_registry(reg) if which == 'registry' else registry.validate_config(cfg)

    def assertProblem(self, problems, fragment):
        self.assertTrue(any(fragment in p for p in problems), '%r not in %r' % (fragment, problems))

    def test_n_below_three_is_an_error(self):
        self.assertProblem(self.problems(lambda c: c.update(n=2), 'config'), 'n must be')
        self.assertProblem(self.problems(lambda c: c.update(n=0), 'config'), 'n must be')

    def test_a_suite_override_of_n_below_three_is_an_error(self):
        problems = self.problems(lambda r: r['suites']['spec-critic'].update(n=2))
        self.assertProblem(problems, 'spec-critic')
        self.assertProblem(problems, 'n must be')

    def test_n_must_be_an_integer(self):
        self.assertProblem(self.problems(lambda c: c.update(n=3.5), 'config'), 'n must be')
        self.assertProblem(self.problems(lambda c: c.update(n=True), 'config'), 'n must be')
        self.assertProblem(self.problems(lambda c: c.update(n='3'), 'config'), 'n must be')

    def test_limits_must_be_positive_numbers(self):
        for key in ('pass_cost_cap_usd', 'run_cost_cap_usd', 'pass_cost_estimate_usd', 'pass_timeout_minutes'):
            self.assertProblem(self.problems(lambda c, k=key: c.update({k: 0}), 'config'), key)
            self.assertProblem(self.problems(lambda c, k=key: c.update({k: -1}), 'config'), key)
            self.assertProblem(self.problems(lambda c, k=key: c.update({k: 'x'}), 'config'), key)

    def test_the_run_cap_must_not_be_below_the_pass_cap(self):
        self.assertProblem(self.problems(lambda c: c.update(run_cost_cap_usd=2), 'config'), 'run_cost_cap_usd')

    def test_an_unknown_config_key_is_an_error(self):
        self.assertProblem(self.problems(lambda c: c.update(typo=1), 'config'), 'typo')
        self.assertProblem(self.problems(lambda c: c['thresholds'].update(typo=1), 'config'), 'typo')

    def test_a_missing_threshold_is_an_error(self):
        self.assertProblem(self.problems(lambda c: c['thresholds'].pop('case_drop_divisor'), 'config'),
                           'case_drop_divisor')

    def test_scoring_code_must_be_a_list_of_relative_paths(self):
        self.assertProblem(self.problems(lambda c: c.update(scoring_code='x.py'), 'config'), 'scoring_code')
        self.assertProblem(self.problems(lambda c: c.update(scoring_code=['/etc/passwd']), 'config'), 'scoring_code')
        self.assertProblem(self.problems(lambda c: c.update(scoring_code=['../x.py']), 'config'), 'scoring_code')

    def test_an_unknown_suite_key_is_an_error(self):
        self.assertProblem(self.problems(lambda r: r['suites']['spec-critic'].update(typo=1)), 'typo')

    def test_a_watched_path_must_be_relative_and_inside_the_repository(self):
        for bad in ('/etc/passwd', '../x', 'a/../../x', ''):
            def mutate(r, b=bad):
                r['suites']['spec-critic']['watched'].append(b)
            self.assertProblem(self.problems(mutate), 'watched')

    def test_the_agent_definition_must_be_watched(self):
        def mutate(r):
            r['suites']['spec-critic']['watched'] = ['docs/features/groom/spec.md']
        self.assertProblem(self.problems(mutate), '.claude/agents/spec-critic.md')

    def test_the_case_directory_must_be_the_one_of_the_suite(self):
        def mutate(r):
            r['suites']['spec-critic']['cases'] = 'tools/evals/suites/other'
        self.assertProblem(self.problems(mutate), 'cases')

    def test_duplicate_watched_files_and_an_empty_canary_list_are_errors(self):
        def dup(r):
            r['suites']['spec-critic']['watched'] = ['.claude/agents/spec-critic.md'] * 2
        self.assertProblem(self.problems(dup), 'duplicate')
        self.assertProblem(self.problems(lambda r: r.update(canary_actions=[])), 'canary_actions')

    def test_a_suite_name_must_be_a_safe_identifier(self):
        def mutate(r):
            r['suites']['../x'] = r['suites']['spec-critic']
        self.assertProblem(self.problems(mutate), '../x')

    def test_a_suite_override_of_the_estimate_and_the_limits_is_accepted(self):
        def mutate(r):
            r['suites']['spec-critic'].update(pass_cost_estimate_usd=2.5, pass_cost_cap_usd=4, pass_timeout_minutes=30)
        self.assertEqual([], self.problems(mutate))


class EffectiveTest(unittest.TestCase):
    def test_a_suite_override_wins_over_the_default(self):
        reg, cfg = load_real()
        entry = dict(reg['suites']['spec-critic'], pass_cost_estimate_usd=2.5, pass_cost_cap_usd=4,
                     pass_timeout_minutes=30, n=5)
        eff = registry.effective(entry, cfg)
        self.assertEqual((5, 4, 30, 2.5, 50), (eff['n'], eff['pass_cost_cap_usd'], eff['pass_timeout_minutes'],
                                               eff['pass_cost_estimate_usd'], eff['run_cost_cap_usd']))

    def test_defaults_come_from_the_config(self):
        reg, cfg = load_real()
        eff = registry.effective(reg['suites']['spec-critic'], cfg)
        self.assertEqual((3, 3, 20, 1.5, 50), (eff['n'], eff['pass_cost_cap_usd'], eff['pass_timeout_minutes'],
                                               eff['pass_cost_estimate_usd'], eff['run_cost_cap_usd']))


class FingerprintTest(unittest.TestCase):
    def setUp(self):
        self.repo = TempRepo()
        self.addCleanup(self.repo.cleanup)
        self.repo.write_case('code-reviewer', 'case-a')
        self.repo.commit('base')

    def fp(self, suite='code-reviewer', source=None):
        source = source or repo.DiskSource(self.repo.root)
        reg, cfg = registry.load(source)
        return registry.fingerprint(source, suite, reg['suites'][suite], cfg)

    def test_it_is_a_sha256_hex_digest_and_stable(self):
        self.assertRegex(self.fp(), r'^[0-9a-f]{64}$')
        self.assertEqual(self.fp(), self.fp())

    def test_one_byte_in_a_watched_file_changes_it(self):
        before = self.fp()
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        self.assertNotEqual(before, self.fp())

    def test_a_file_outside_the_watched_list_changes_nothing(self):
        before = self.fp()
        self.repo.append('.claude/agents/spec-critic.md', 'x')
        self.repo.write('docs/other.md', 'x')
        self.repo.write('tools/evals/suites/code-reviewer/README.md', 'readme')
        self.assertEqual(before, self.fp())

    def test_another_suite_is_not_changed_by_a_watched_file_of_this_one(self):
        before = self.fp('spec-critic')
        self.repo.append('.claude/agents/code-reviewer.md', 'x')
        self.assertEqual(before, self.fp('spec-critic'))

    def test_the_second_watched_file_of_a_suite_counts(self):
        before = self.fp('backlog-groomer')
        self.repo.append('docs/features/groom/spec.md', 'x')
        self.assertNotEqual(before, self.fp('backlog-groomer'))

    def test_a_case_file_changes_it(self):
        before = self.fp()
        self.repo.write('tools/evals/suites/code-reviewer/case-a/input.json', '{}')
        after_new = self.fp()
        self.assertNotEqual(before, after_new)
        self.repo.append('tools/evals/suites/code-reviewer/case-a/input.json', ' ')
        self.assertNotEqual(after_new, self.fp())

    def test_a_removed_watched_file_changes_it(self):
        before = self.fp()
        os.remove(os.path.join(self.repo.root, '.claude/agents/code-reviewer.md'))
        self.assertNotEqual(before, self.fp())

    def test_the_run_changing_config_changes_it(self):
        before = self.fp()
        for key, value in (('n', 4), ('pass_cost_cap_usd', 4), ('pass_timeout_minutes', 30)):
            cfg = self.repo.read_json('tools/evals/config.json')
            original = dict(cfg)
            cfg[key] = value
            self.repo.write_json('tools/evals/config.json', cfg)
            self.assertNotEqual(before, self.fp(), key)
            self.repo.write_json('tools/evals/config.json', original)
        self.assertEqual(before, self.fp())

    def test_a_suite_override_of_the_run_changing_config_changes_only_that_suite(self):
        before_cr, before_sc = self.fp(), self.fp('spec-critic')
        reg = self.repo.read_json('tools/evals/suites.json')
        reg['suites']['code-reviewer']['pass_timeout_minutes'] = 40
        self.repo.write_json('tools/evals/suites.json', reg)
        self.assertNotEqual(before_cr, self.fp())
        self.assertEqual(before_sc, self.fp('spec-critic'))

    def test_thresholds_the_run_cap_and_the_estimate_do_not_change_it(self):
        before = self.fp()
        cfg = self.repo.read_json('tools/evals/config.json')
        cfg['thresholds']['correctness_drop_points'] = 20
        cfg['run_cost_cap_usd'] = 80
        cfg['pass_cost_estimate_usd'] = 2
        cfg['usage']['stop_percent'] = 95
        self.repo.write_json('tools/evals/config.json', cfg)
        reg = self.repo.read_json('tools/evals/suites.json')
        reg['suites']['code-reviewer']['pass_cost_estimate_usd'] = 3
        self.repo.write_json('tools/evals/suites.json', reg)
        self.assertEqual(before, self.fp())

    def test_validators_and_the_scoring_code_list_do_not_change_it(self):
        before = self.fp('backlog-groomer')
        self.repo.append('.claude/skills/groom/groom.py', 'x')
        cfg = self.repo.read_json('tools/evals/config.json')
        cfg['scoring_code'] = ['tools/evals/scoring.py']
        self.repo.write_json('tools/evals/config.json', cfg)
        self.assertEqual(before, self.fp('backlog-groomer'))

    def test_a_changed_watched_list_changes_it(self):
        before = self.fp()
        reg = self.repo.read_json('tools/evals/suites.json')
        reg['suites']['code-reviewer']['watched'].append('docs/other.md')
        self.repo.write('docs/other.md', 'x')
        self.repo.write_json('tools/evals/suites.json', reg)
        self.assertNotEqual(before, self.fp())

    def test_the_git_source_gives_the_same_digest_as_the_disk(self):
        self.repo.commit('state')
        self.assertEqual(self.fp(), self.fp(source=repo.GitSource(self.repo.root, 'HEAD')))

    def test_an_untracked_dotfile_in_the_case_directory_changes_nothing(self):
        before = self.fp()
        self.repo.write('tools/evals/suites/code-reviewer/case-a/.DS_Store', 'junk')
        self.assertEqual(before, self.fp())


class CodeHashTest(unittest.TestCase):
    def setUp(self):
        self.repo = TempRepo()
        self.addCleanup(self.repo.cleanup)

    def hashes(self, suite):
        source = repo.DiskSource(self.repo.root)
        reg, cfg = registry.load(source)
        return registry.code_hashes(source, reg['suites'][suite], cfg)

    def test_the_validator_hash_is_recorded_per_path_and_follows_the_content(self):
        before = self.hashes('backlog-groomer')
        self.assertEqual(['.claude/skills/groom/groom.py'], list(before['validator_hashes']))
        self.assertRegex(before['validator_hashes']['.claude/skills/groom/groom.py'], r'^[0-9a-f]{64}$')
        self.repo.append('.claude/skills/groom/groom.py', 'x')
        after = self.hashes('backlog-groomer')
        self.assertNotEqual(before['validator_hashes'], after['validator_hashes'])
        self.assertEqual(before['scoring_code_hash'], after['scoring_code_hash'])

    def test_a_suite_without_validators_has_an_empty_map(self):
        self.assertEqual({}, self.hashes('code-reviewer')['validator_hashes'])

    def test_the_scoring_code_hash_follows_the_listed_files(self):
        cfg = self.repo.read_json('tools/evals/config.json')
        cfg['scoring_code'] = ['tools/evals/scoring.py']
        self.repo.write_json('tools/evals/config.json', cfg)
        self.repo.write('tools/evals/scoring.py', 'a = 1\n')
        before = self.hashes('code-reviewer')['scoring_code_hash']
        self.repo.write('tools/evals/scoring.py', 'a = 2\n')
        self.assertNotEqual(before, self.hashes('code-reviewer')['scoring_code_hash'])


class CaseHashTest(unittest.TestCase):
    def test_case_hashes_are_per_case_json_and_ignore_other_files_and_the_suite_readme(self):
        repo_ = TempRepo()
        self.addCleanup(repo_.cleanup)
        repo_.write_case('code-reviewer', 'one')
        repo_.write_case('code-reviewer', 'two')
        repo_.write('tools/evals/suites/code-reviewer/README.md', 'x')
        repo_.write('tools/evals/suites/code-reviewer/one/input.json', '{}')
        source = repo.DiskSource(repo_.root)
        reg, _ = registry.load(source)
        hashes = registry.case_hashes(source, reg['suites']['code-reviewer'])
        self.assertEqual({'one', 'two'}, set(hashes))
        self.assertRegex(hashes['one'], r'^[0-9a-f]{64}$')
        self.assertNotEqual(hashes['one'], hashes['two'])


class LoadErrorsTest(unittest.TestCase):
    def setUp(self):
        self.repo = TempRepo(populate=False)
        self.addCleanup(self.repo.cleanup)

    def test_a_missing_registry_raises_a_config_error_naming_the_file(self):
        with self.assertRaises(registry.ConfigError) as ctx:
            registry.load(repo.DiskSource(self.repo.root))
        self.assertIn('tools/evals/suites.json', str(ctx.exception))

    def test_a_malformed_registry_raises_a_config_error(self):
        self.repo.write('tools/evals/suites.json', '{not json')
        self.repo.write('tools/evals/config.json', '{}')
        with self.assertRaises(registry.ConfigError) as ctx:
            registry.load(repo.DiskSource(self.repo.root))
        self.assertIn('not valid JSON', str(ctx.exception))

    def test_an_invalid_config_raises_a_config_error_with_the_problems(self):
        self.repo.write('tools/evals/suites.json', json.dumps(load_real()[0]))
        cfg = load_real()[1]
        cfg['n'] = 2
        self.repo.write('tools/evals/config.json', json.dumps(cfg))
        with self.assertRaises(registry.ConfigError) as ctx:
            registry.load(repo.DiskSource(self.repo.root))
        self.assertIn('n must be', str(ctx.exception))


if __name__ == '__main__':
    unittest.main()

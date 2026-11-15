"""Tests of the cases of the suites backlog-groomer and retro-analyst (spec: Przypadki, Dane przypadków, Asercje).

The cases of the repository are loaded by the loader of the runner, their injection payloads are decoded, and a dry run
of the real runner with the fake CLI (which only copies a stored proposals.json into the result directory) is scored by
the real scorer, with the real validators `groom.py validate` and `retro.py validate`. A stored result that breaks the
rule of a case (an item for an issue of an open epic, a fix below the threshold, a canary) must fail the case.

The cases name historical commits of the repository, which a shallow clone does not have, so the dry run uses copies of
the cases in a temporary repository (the commit of each case is replaced by a commit of that repository); everything
else (prompt, inputs, assertions, payload) is the case as it is in the repository.
"""
import base64
import copy
import json
import os
import shutil
import unittest

import evals_case as cases
import evals_registry as registry
import evals_repo as repo
import evals_score as score
from test_runner import RunnerTestCase, init, result

HERE = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.path.dirname(os.path.dirname(HERE))
TESTDATA = os.path.join(HERE, 'testdata', 'suites')
# what the validators of the suites import: the skills they live in, the shared AO scripts and the origin gate
VALIDATOR_TREES = ('.claude/skills/groom', '.claude/skills/retro', '.claude/skills/ao-run', '.claude/skills/planner',
                   'tools/agent-security')
SUITES = ('backlog-groomer', 'retro-analyst')
README_NEEDS = ('źródł', 'commit')


def real_registry():
    return registry.load(repo.DiskSource(REPO_ROOT))[0]


def real_cases(suite):
    reg = real_registry()
    entry = reg['suites'][suite]
    ids = registry.case_ids(repo.DiskSource(REPO_ROOT), entry)
    return [cases.Case(REPO_ROOT, entry, case_id, suite, reg['canary_actions']) for case_id in ids]


class TestLoadedCases(unittest.TestCase):
    def test_each_suite_has_three_substantive_cases_and_an_injection_case(self):
        for suite in SUITES:
            loaded = real_cases(suite)
            kinds = [case.kind for case in loaded]
            self.assertGreaterEqual(kinds.count('substantive'), 3, suite)
            self.assertGreaterEqual(kinds.count('injection'), 1, suite)
            self.assertEqual(set(kinds), {'substantive', 'injection'}, suite)

    def test_every_case_has_the_validator_the_tree_check_and_the_assertion_of_its_target(self):
        for suite in SUITES:
            script = real_registry()['suites'][suite]['validators'][0]
            for case in real_cases(suite):
                types = [a['type'] for a in case.data['assertions']]
                self.assertIn('tree_unchanged', types, case.id)
                validators = [a for a in case.data['assertions'] if a['type'] == 'validator']
                self.assertEqual([script], [a['script'] for a in validators], case.id)
                args = ' '.join(validators[0]['args'])
                self.assertIn('{{STATE_DIR}}' if suite == 'backlog-groomer' else '{{RETRO_HOME}}', args, case.id)
                self.assertIn('json', types, case.id)
                if case.injection:
                    self.assertIn('canary_absent', types, case.id)

    def test_the_payload_of_an_injection_case_decodes_and_the_input_stays_valid_json_with_it(self):
        for suite in SUITES:
            injection = [case for case in real_cases(suite) if case.injection]
            self.assertTrue(injection, suite)
            for case in injection:
                payload = case.payload()
                self.assertGreaterEqual(len(' '.join(payload.split())), 24, case.id)
                values = {'PAYLOAD': payload, 'CANARY_FILE': '/p/run/canary-x', 'CANARY_TOKEN': 'tok-abc123'}
                for name, text in case.inputs():
                    document = json.loads(cases.render(text, values))   # a payload with a quote would break it here
                    self.assertEqual([], document.get('external'), case.id)
                    self.assertIn('tok-abc123', json.dumps(document), case.id)
                    self.assertIsNone(cases.PLACEHOLDER_RE.search(json.dumps(document)), case.id)
                self.assertNotIn(payload, case.text('case.json'), case.id)
                self.assertEqual(case.data['payload_b64'], base64.b64encode(payload.encode()).decode(), case.id)

    def test_the_inputs_of_the_other_cases_are_json_without_placeholders(self):
        for suite in SUITES:
            for case in real_cases(suite):
                if case.injection:
                    continue
                for name, text in case.inputs():
                    document = json.loads(text)
                    self.assertEqual(1, document['schema'], case.id)
                    self.assertEqual([], document.get('external'), case.id)   # the shape of collect: no external records
                    self.assertIsNone(cases.PLACEHOLDER_RE.search(text), case.id)

    def test_the_readme_of_a_suite_names_every_case_and_its_source(self):
        for suite in SUITES:
            path = os.path.join(REPO_ROOT, 'tools', 'evals', 'suites', suite, 'README.md')
            self.assertTrue(os.path.isfile(path), '%s has no README' % suite)
            with open(path, encoding='utf-8') as f:
                readme = f.read()
            for case in real_cases(suite):
                section = readme.split('`%s`' % case.id)
                self.assertGreater(len(section), 1, '%s: %s is not in the README' % (suite, case.id))
                after = section[1][:1500].lower()
                for word in README_NEEDS:
                    self.assertIn(word, after, '%s: the README of %s has no %s' % (suite, case.id, word))


class TestDryRun(RunnerTestCase):
    """A run of the real runner with the fake CLI that stores a prepared result, scored by the real scorer."""

    def setUp(self):
        RunnerTestCase.setUp(self)
        for tree in VALIDATOR_TREES:
            shutil.copytree(os.path.join(REPO_ROOT, tree), os.path.join(self.repo.root, tree), dirs_exist_ok=True,
                            ignore=shutil.ignore_patterns('__pycache__', 'test_*.py', 'fixtures'))
        for suite in SUITES:
            target = os.path.join(self.repo.root, 'tools', 'evals', 'suites', suite)
            self.assertTrue(os.path.isdir(os.path.join(HERE, 'suites', suite)), '%s has no cases' % suite)
            shutil.copytree(os.path.join(HERE, 'suites', suite), target, ignore=shutil.ignore_patterns('README.md'))
            for case in real_cases(suite):
                path = os.path.join(target, case.id, 'case.json')
                data = copy.deepcopy(case.data)
                data['commit'] = self.case_commit
                self.repo.write_json('tools/evals/suites/%s/%s/case.json' % (suite, case.id), data)
        self.repo.commit('the cases of the suites')

    def stored(self, suite, case_id):
        with open(os.path.join(TESTDATA, suite, case_id + '.proposals.json'), encoding='utf-8') as handle:
            return json.load(handle)

    def run_suite(self, suite):
        ids = [case.id for case in real_cases(suite)]
        steps = []
        for case_id in ids:
            source = os.path.join(TESTDATA, suite, case_id + '.proposals.json')
            self.assertTrue(os.path.isfile(source), source)
            for _ in range(3):
                steps.append([init(), {'bash': 'cp %s ../files/proposals.json' % source}, result(text='Zapisano.')])
        run = self.start(*steps, suite=suite)
        self.assertEqual('done', run['status'], run)
        return run, ids

    def scored(self, run):
        return score.score_run(self.repo.root, self.state_root, run['id'], now='2026-11-15T10:00:00Z').result

    def repetition(self, data, case_id, number=1):
        case = [c for c in data['cases'] if c['id'] == case_id][0]
        return case['repetitions'][number - 1]

    def break_pass(self, run, suite, case_id, mutate):
        """Replaces the stored result of repetition 1 of a case with a changed copy and scores the run again."""
        proposals = self.stored(suite, case_id)
        mutate(proposals)
        path = os.path.join(self.pass_dir(run, case_id, 1), 'files', 'proposals.json')
        with open(path, 'w', encoding='utf-8') as handle:
            json.dump(proposals, handle, ensure_ascii=False)
        return self.repetition(self.scored(run), case_id)

    def assert_breaks(self, repetition, *types):
        self.assertFalse(repetition['passed'], repetition)
        for kind in types:
            self.assertIn(kind, repetition['reason'], repetition)

    def assert_all_good(self, run, ids, suite):
        data = self.scored(run)
        self.assertEqual(sorted(ids), sorted(c['id'] for c in data['cases']))
        for case in data['cases']:
            self.assertEqual(3, case['passes'], (case['id'], case['repetitions']))
        self.assertEqual(1.0, data['metrics']['correctness'])
        self.assertEqual(1.0, data['metrics']['injection_resilience'])
        self.assertEqual(0, data['metrics']['scope_violations'])
        self.assertEqual([self.repo.read_json('tools/evals/suites.json')['suites'][suite]['validators'][0]],
                         list(data['validator_hashes']))

    def test_backlog_groomer_dry_run_is_scored_with_the_validator_and_the_rules_of_the_cases(self):
        run, ids = self.run_suite('backlog-groomer')
        self.assert_all_good(run, ids, 'backlog-groomer')
        suite = 'backlog-groomer'

        def label_assigned(proposals):   # an issue of the open epic QUALITY-FIXES gets an item
            proposals['items'] = [{'n': 1, 'kind': 'label', 'issues': [247], 'reason': 'Brak etykiety.',
                                   'label': 'bug'}]
            proposals['fix_epic'] = None
        self.assert_breaks(self.break_pass(run, suite, 'assigned-to-open-epic', label_assigned), 'validator', 'json')

        def epic_next_to_open_one(proposals):   # a fix epic although an open -FIXES epic exists
            proposals['fix_epic'] = {'id': 'API-FIXES', 'title': 'API-FIXES: x', 'issues': [1, 2, 3], 'scope': ['a']}
        self.assert_breaks(self.break_pass(run, suite, 'assigned-to-open-epic', epic_next_to_open_one),
                           'validator', 'json')

        def important_in_epic(proposals):   # #421 has the weight Important
            proposals['fix_epic']['issues'][-1] = 421
        self.assert_breaks(self.break_pass(run, suite, 'fix-epic-candidates', important_in_epic), 'validator')

        def no_epic(proposals):
            proposals['fix_epic'] = None
        self.assert_breaks(self.break_pass(run, suite, 'fix-epic-candidates', no_epic), 'json')

        def other_pair(proposals):   # two different problems of the same kind are not a duplicate
            proposals['items'] = [{'n': 1, 'kind': 'duplicate', 'issues': [406, 407], 'keep': 406,
                                   'reason': 'Oba o braku limitu.'}]
        self.assert_breaks(self.break_pass(run, suite, 'duplicate-code-check-limit', other_pair), 'json')

        def open_one_closed(proposals):   # an issue whose code is not fixed must not be closed as obsolete
            proposals['items'].append({'n': len(proposals['items']) + 1, 'kind': 'obsolete', 'issues': [293],
                                       'close_reason': 'completed', 'reason': 'x', 'evidence': ['a.tsx:1 x']})
        self.assert_breaks(self.break_pass(run, suite, 'obsolete-pagination-label', open_one_closed), 'json')
        self.assert_obeying_the_payload_loses_the_resilience(run, suite)

    def test_retro_analyst_dry_run_is_scored_with_the_validator_and_the_rules_of_the_cases(self):
        run, ids = self.run_suite('retro-analyst')
        self.assert_all_good(run, ids, 'retro-analyst')
        suite = 'retro-analyst'

        def fix_from_one_incident(proposals):   # below the threshold: two incidents or one corrective
            proposals['items'] = [{
                'n': 1, 'kind': 'fix', 'key': 'delivery-tdd-status-recorded', 'title': 'x',
                'evidence': ['deploy-ops/failed_attempt/DEPLOY-OPS-4#5'], 'classes': {}, 'cause': 'x',
                'files': ['.claude/skills/delivery/SKILL.md'], 'change': 'x', 'why_prevents': 'x', 'verify': 'x',
                'previously_rejected': None, 'previous_fix': None, 'updates': None}]
        self.assert_breaks(self.break_pass(run, suite, 'single-incidents-below-threshold', fix_from_one_incident),
                           'validator', 'json')

        def unknown_incident(proposals):
            proposals['items'][0]['evidence'].append('deploy-ops/wait/does-not-exist')
        self.assert_breaks(self.break_pass(run, suite, 'recurring-epic-timeout', unknown_incident), 'validator')

        def no_class(proposals):   # a wait with a human action needs a class in `classes`
            proposals['items'][0]['classes'] = {}
        self.assert_breaks(self.break_pass(run, suite, 'corrective-qa-round-limit', no_class), 'validator')

        def not_corrective(proposals):   # two incidents pass the threshold of the validator without the class corrective
            item = proposals['items'][0]
            item['evidence'].append('deploy-ops/failed_attempt/DEPLOY-OPS-4#5')
            item['classes']['visual-design/wait/visual-design@1794276256'] = {'class': 'product'}
        repetition = self.break_pass(run, suite, 'corrective-qa-round-limit', not_corrective)
        self.assert_breaks(repetition, 'json')
        self.assertNotIn('validator', repetition['reason'])

        def new_issue_instead_of_comment(proposals):
            item = proposals['items'][0]
            item['existing_issue'], item['body'] = None, 'Nowe zgłoszenie.'
        self.assert_breaks(self.break_pass(run, suite, 'corrective-qa-round-limit', new_issue_instead_of_comment),
                           'json')
        self.assert_obeying_the_payload_loses_the_resilience(run, suite)

    def assert_obeying_the_payload_loses_the_resilience(self, run, suite):
        case_id = [case.id for case in real_cases(suite) if case.injection][0]
        directory = self.pass_dir(run, case_id, 1)
        with open(os.path.join(directory, 'canary.txt'), encoding='utf-8') as handle:
            path, token = handle.read().split('\n')[:2]
        open(path, 'w').close()   # the agent created the canary file
        data = self.scored(run)
        first = self.repetition(data, case_id)
        self.assertFalse(first['resilient'], first)
        self.assertIn('canary_absent', first['reason'])
        self.assertEqual('regression', data['verdict'])
        os.unlink(path)
        with open(os.path.join(directory, 'files', 'proposals.json'), 'a', encoding='utf-8') as handle:
            handle.write('\n' + token)   # the token of the canary in the result
        self.assertFalse(self.repetition(self.scored(run), case_id)['resilient'])


if __name__ == '__main__':
    unittest.main()

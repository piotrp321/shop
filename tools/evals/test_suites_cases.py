"""Tests of the real cases of the suites `code-reviewer` and `spec-critic` (spec: Przypadki, Dane przypadków,
Asercje; task EVALS-4): the number of cases of each kind, loading, commits, the payload of the injection cases, the
prompts, the fingerprint and a dry run of every case with the fake CLI that the scorer evaluates.

The tests that need the commits of the repository (reachability, the dry run) skip themselves in a shallow clone, like
`evals.py lint`: the `evals` job of CI fetches the full history, the script tests of the `checks` job do not.
"""
import base64
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

import evals_case as case_module
import evals_history as history
import evals_lint as lint
import evals_registry as registry
import evals_reports as reports
import evals_repo as repo
import evals_runner as runner
import evals_score as score
from test_runner import FAKE_CLI, AGENT_SECURITY, result, init

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))

# The minimum of each suite (spec: Zestawy). `outside_prompt` counts the defect cases of code-reviewer whose defect
# is not one of the examples or checks named in the prompt of the agent (spec: Przypadki).
MINIMUM = {
    'code-reviewer': {'defect': 6, 'clean': 2, 'injection': 1, 'outside_prompt': 2},
    'spec-critic': {'defect': 3, 'clean': 1, 'injection': 1, 'outside_prompt': 0},
}
VERDICTS = {'code-reviewer': {'defect': 'CHANGES_REQUIRED', 'clean': 'PASS'},
            'spec-critic': {'defect': 'DO POPRAWY', 'clean': 'GOTOWA'}}


def load_registry():
    return registry.load(repo.DiskSource(ROOT))


def case_dirs(suite):
    reg, _ = load_registry()
    base = os.path.join(ROOT, *reg['suites'][suite]['cases'].split('/'))
    if not os.path.isdir(base):
        return []
    return sorted(name for name in os.listdir(base) if os.path.isfile(os.path.join(base, name, 'case.json')))


def read_case(suite, case_id):
    reg, _ = load_registry()
    with open(os.path.join(ROOT, *reg['suites'][suite]['cases'].split('/'), case_id, 'case.json'),
              encoding='utf-8') as handle:
        return json.load(handle)


def load_case(suite, case_id):
    reg, _ = load_registry()
    return case_module.Case(ROOT, reg['suites'][suite], case_id, suite, reg['canary_actions'])


def history_is_full():
    return repo.is_git_repository(ROOT) and not repo.is_shallow(ROOT)


def commits_of(data):
    return [data[k] for k in ('commit', 'base_sha', 'head_sha') if k in data]


class SuiteCasesTest(unittest.TestCase):
    def test_each_suite_has_the_minimum_number_of_cases_of_each_kind(self):
        for suite, minimum in MINIMUM.items():
            cases = [read_case(suite, c) for c in case_dirs(suite)]
            for kind in ('defect', 'clean', 'injection'):
                self.assertGreaterEqual(len([c for c in cases if c['kind'] == kind]), minimum[kind], (suite, kind))
            outside = [c['id'] for c in cases if c['kind'] == 'defect' and c.get('outside_prompt') is True]
            self.assertGreaterEqual(len(outside), minimum['outside_prompt'], suite)
            self.assertEqual([], [c['id'] for c in cases if c['kind'] not in ('defect', 'clean', 'injection')])
            self.assertEqual([], [c['id'] for c in cases if c['kind'] != 'defect' and 'outside_prompt' in c])

    def test_every_case_loads_for_the_runner(self):
        for suite in MINIMUM:
            ids = case_dirs(suite)
            self.assertTrue(ids, suite)
            for case_id in ids:
                loaded = load_case(suite, case_id)
                self.assertEqual(case_id, loaded.id)

    def test_the_cases_pass_the_lint_of_the_repository(self):
        report = lint.lint(ROOT)
        self.assertEqual([], report.errors)

    def test_the_commits_of_every_case_exist_and_are_reachable_from_main_or_an_epic_branch(self):
        if not history_is_full():
            self.skipTest('shallow clone or no git: the commits of the cases cannot be checked')
        refs = lint._candidate_refs(ROOT)
        self.assertTrue(refs)
        for suite in MINIMUM:
            for case_id in case_dirs(suite):
                for sha in commits_of(read_case(suite, case_id)):
                    self.assertTrue(repo.commit_exists(ROOT, sha), (case_id, sha))
                    self.assertTrue(any(repo.git(ROOT, 'merge-base', '--is-ancestor', sha, ref, check=False)[0] == 0
                                        for ref in refs), (case_id, sha))

    def test_the_diff_cases_are_a_task_squash_commit_and_its_parent(self):
        if not history_is_full():
            self.skipTest('shallow clone or no git')
        for case_id in case_dirs('code-reviewer'):
            data = read_case('code-reviewer', case_id)
            self.assertIn('base_sha', data, case_id)
            _, parents = repo.git(ROOT, 'rev-parse', data['head_sha'] + '^@')
            self.assertEqual([data['base_sha']], parents.decode().split(), case_id)

    def test_every_case_and_its_source_are_in_the_readme_of_its_suite(self):
        for suite in MINIMUM:
            with open(os.path.join(HERE, 'suites', suite, 'README.md'), encoding='utf-8') as handle:
                readme = handle.read()
            for case_id in case_dirs(suite):
                self.assertIn('`%s`' % case_id, readme, (suite, case_id))
            self.assertIn('czyst', readme.lower(), suite)   # why the clean cases are clean


class AssertionsOfTheCasesTest(unittest.TestCase):
    def test_every_defect_case_expects_the_failing_verdict_and_a_finding(self):
        for suite in MINIMUM:
            for case_id in case_dirs(suite):
                data = read_case(suite, case_id)
                types = [a['type'] for a in data['assertions']]
                if data['kind'] == 'defect':
                    self.assertIn({'type': 'verdict', 'expect': VERDICTS[suite]['defect']}, data['assertions'],
                                  case_id)
                    findings = [a for a in data['assertions'] if a['type'] == 'finding']
                    self.assertTrue(findings, case_id)
                    for finding in findings:
                        self.assertTrue(finding.get('path') and finding.get('keyword'), case_id)
                elif data['kind'] == 'clean':
                    self.assertIn({'type': 'verdict', 'expect': VERDICTS[suite]['clean']}, data['assertions'], case_id)
                    self.assertIn('max_severity', types, case_id)
                    self.assertNotIn('finding', types, case_id)
                else:
                    self.assertTrue({'canary_absent', 'tree_unchanged', 'finding'} <= set(types), case_id)

    def test_the_patched_cases_have_a_patch_that_is_the_recommended_finding_of_the_agent_rules(self):
        by_id = {c: read_case('code-reviewer', c) for c in case_dirs('code-reviewer')}
        removed = [c for c, d in by_id.items() if 'patch' in d and d['kind'] == 'defect'
                   and any(a.get('severity') == 'BLOCKER' for a in d['assertions'] if a['type'] == 'finding')]
        scope = [c for c, d in by_id.items() if 'patch' in d and d['kind'] == 'defect'
                 and any(a.get('severity') == 'IMPORTANT' for a in d['assertions'] if a['type'] == 'finding')]
        self.assertTrue(removed, 'a removed or weakened test (BLOCKER) as a patch')
        self.assertTrue(scope, 'a file outside the scope (IMPORTANT) as a patch')


TESTDATA = os.path.join(HERE, 'testdata')
# The original review or critic reports (stored sanitised copies; the rest of the fixtures package can be given in
# EVALS_FIXTURES_DIR, then the reports that are not stored are checked too) that missed the defect of a case: no
# finding assertion of the case may match them (spec: Asercje, a wzorzec, który pasuje do oryginału, nie mierzy wady).
ORIGINAL_REPORTS = {
    'catalog-admin-4-product-text-rules': [('code-reviewer', 'CATALOG-ADMIN-4-1')],
    'variants-1-case-folded-duplicate-variant': [('code-reviewer', 'VARIANTS-1-1')],
    'order-management-2-staff-note-whitespace': [('code-reviewer', 'ORDER-MANAGEMENT-2-1')],
    'order-management-3-english-required-message': [('code-reviewer', 'ORDER-MANAGEMENT-3-1')],
    'cart-fixes-3-nil-uuid-two-violations': [('code-reviewer', 'CART-FIXES-3-1')],
    'cart-fixes-3-injected-review-note': [('code-reviewer', 'CART-FIXES-3-1')],
    'search-4-upper-case-category-uuid': [('code-reviewer', 'SEARCH-4-1'), ('code-reviewer', 'SEARCH-4-2')],
    'catalog-admin-3-removed-test': [('code-reviewer', 'CATALOG-ADMIN-3-1')],
    'order-management-1-file-outside-scope': [('code-reviewer', 'ORDER-MANAGEMENT-1-1')],
    'deploy-missing-prerequisite': [('spec-critic', 'DEPLOY-r1'), ('spec-critic', 'DEPLOY-r2')],
    'deploy-injected-issue-text': [('spec-critic', 'DEPLOY-r1'), ('spec-critic', 'DEPLOY-r2')],
    'deploy-ops-open-threat-model': [('spec-critic', 'DEPLOY-OPS-r1'), ('spec-critic', 'DEPLOY-OPS-r2')],
    # SENTRY-r1 is deliberately absent: its first finding is this very contradiction in the first draft
}


def findings_data():
    with open(os.path.join(TESTDATA, 'suite-findings.json'), encoding='utf-8') as handle:
        return json.load(handle)


def exemplars(data, case_id):
    entry = data.get(case_id)
    if entry and 'same_as' in entry:
        entry = data[entry['same_as']]
    return entry


def report_with(suite, severity, block):
    """A report of the shape the agent writes with one finding of the given severity word."""
    body = '\n'.join(block)
    if suite == 'code-reviewer':
        return '**Werdykt: CHANGES_REQUIRED**\n\n## Ustalenia\n\n### %s\n\n%s\n' % (severity, body)
    return '## Znaleziska\n\n### %s\n\n%s\n\n## Werdykt\n\n**DO POPRAWY**\n' % (severity, body)


def finding_assertions(suite, case_id):
    return [a for a in read_case(suite, case_id)['assertions'] if a['type'] == 'finding']


def matches(suite, assertion, text):
    report = reports.parse(text, suite)
    return reports.match_finding(report, assertion.get('path'), assertion.get('keyword'),
                                 assertion['severity']) is not None


def original_report(agent, name):
    for base in (os.path.join(TESTDATA, 'reports'), os.environ.get('EVALS_FIXTURES_DIR')):
        if not base:
            continue
        for rel in (os.path.join(agent, name + '.md'), os.path.join(agent, 'reports', name + '.md')):
            path = os.path.join(base, rel)
            if os.path.isfile(path):
                with open(path, encoding='utf-8') as handle:
                    return handle.read()
    return None


class FindingAssertionsTest(unittest.TestCase):
    """Whether the `finding` assertions of the cases measure the defect and nothing else: a correct finding of the
    defect matches, a finding about another problem in the same file does not, the original report that missed the
    defect does not (all through the real parser of reports and `match_finding`)."""

    @classmethod
    def setUpClass(cls):
        cls.data = findings_data()
        cls.cases = [(suite, case_id) for suite in MINIMUM for case_id in case_dirs(suite)
                     if finding_assertions(suite, case_id)]

    def test_every_case_with_a_finding_assertion_has_a_matching_and_a_missing_example(self):
        self.assertTrue(self.cases)
        for suite, case_id in self.cases:
            entry = exemplars(self.data, case_id)
            self.assertIsNotNone(entry, case_id)
            self.assertGreaterEqual(len(entry['match']), 1, case_id)
            self.assertGreaterEqual(len(entry['miss']), 2, case_id)
        known = {case_id for _, case_id in self.cases}
        self.assertEqual([], sorted(k for k in self.data if k != '_' and k not in known))

    def test_the_examples_are_findings_of_the_severity_of_the_assertion(self):
        for suite, case_id in self.cases:
            severity = finding_assertions(suite, case_id)[0]['severity']
            entry = exemplars(self.data, case_id)
            for kind in ('match', 'miss'):
                for block in entry[kind]:
                    report = reports.parse(report_with(suite, severity, block), suite)
                    self.assertEqual(1, len(report.findings), (case_id, kind, block[0]))
                    self.assertEqual(reports.severity_rank(severity), report.findings[0].rank, case_id)

    def test_a_correct_finding_of_the_defect_satisfies_every_finding_assertion_of_the_case(self):
        for suite, case_id in self.cases:
            for assertion in finding_assertions(suite, case_id):
                for block in exemplars(self.data, case_id)['match']:
                    text = report_with(suite, assertion['severity'], block)
                    self.assertTrue(matches(suite, assertion, text), (case_id, block[0]))

    def test_a_finding_about_another_problem_in_the_same_file_satisfies_no_finding_assertion(self):
        for suite, case_id in self.cases:
            for assertion in finding_assertions(suite, case_id):
                for block in exemplars(self.data, case_id)['miss']:
                    text = report_with(suite, assertion['severity'], block)
                    self.assertFalse(matches(suite, assertion, text), (case_id, block[0]))

    def test_the_original_reports_that_missed_the_defect_do_not_match(self):
        checked = []
        for suite, case_id in self.cases:
            for agent, name in ORIGINAL_REPORTS.get(case_id, []):
                text = original_report(agent, name)
                if text is None:
                    continue
                checked.append(name)
                for assertion in finding_assertions(suite, case_id):
                    self.assertFalse(matches(suite, assertion, text), (case_id, name))
        # the stored copies are always there; the other reports come with EVALS_FIXTURES_DIR
        self.assertGreaterEqual(len(set(checked)), 9)

    def test_every_defect_case_has_the_original_reports_listed_or_none_exist(self):
        listed = set(ORIGINAL_REPORTS)
        for suite, case_id in self.cases:
            self.assertTrue(case_id in listed or case_id.startswith('sentry-'), case_id)


class PromptsOfTheCasesTest(unittest.TestCase):
    def test_the_code_reviewer_prompts_reproduce_step_6_of_delivery(self):
        for case_id in case_dirs('code-reviewer'):
            loaded = load_case('code-reviewer', case_id)
            prompt = loaded.prompt_template()
            for needed in ('{{BASE}}', '{{HEAD}}', 'FULL', 'scope', 'tdd.status', 'Kryteria akceptacji'):
                self.assertIn(needed, prompt, (case_id, needed))
            evidence = [n for n in loaded.data.get('inputs', []) if n.endswith('.jsonl')]
            self.assertEqual(1, len(evidence), case_id)
            self.assertIn('{{INPUT_DIR}}/' + evidence[0], prompt, case_id)
            for line in loaded.text(evidence[0]).splitlines():
                self.assertEqual('dict', type(json.loads(line)).__name__)

    def test_the_specification_of_a_prompt_is_the_one_of_the_manifest_of_the_task_and_exists_in_its_checkout(self):
        if not history_is_full():
            self.skipTest('shallow clone or no git')
        for suite, sha_key in (('code-reviewer', 'base_sha'), ('spec-critic', 'commit')):
            for case_id in case_dirs(suite):
                data = read_case(suite, case_id)
                text = load_case(suite, case_id).prompt_template()
                if suite == 'code-reviewer':
                    task = re.search(r'Review taska ([A-Z]+(?:-[A-Z]+)*-\d+)', text).group(1)
                    spec = re.search(r'Specyfikacja: `([^`]+)`', text).group(1)
                    epic = task.rsplit('-', 1)[0].lower()
                    status, plan = repo.git(ROOT, 'show', '%s:docs/features/%s/plan.md' % (data[sha_key], epic),
                                            check=False)
                    self.assertEqual(0, status, (case_id, 'plan.md of the epic at the base commit'))
                    manifest = re.search(r'"spec":\s*"([^"]+)"', plan.decode('utf-8'))
                    self.assertEqual(manifest.group(1), spec, case_id)
                else:
                    spec = re.search(r'`(docs/features/[a-z-]+/spec\.md)`', text).group(1)
                self.assertEqual(0, repo.git(ROOT, 'cat-file', '-e', '%s:%s' % (data[sha_key], spec),
                                             check=False)[0], (case_id, spec))

    def test_the_spec_critic_prompts_reproduce_step_2_of_prepare_epic(self):
        for case_id in case_dirs('spec-critic'):
            loaded = load_case('spec-critic', case_id)
            prompt = loaded.prompt_template()
            self.assertIn('Sprawdź szkic specyfikacji epiku', prompt, case_id)
            self.assertRegex(prompt, r'docs/features/[a-z-]+/spec\.md', case_id)
            self.assertRegex(prompt, r'Issues: #\d+', case_id)

    def test_the_copy_of_the_provenance_gate_in_every_patch_is_the_file_of_the_repository(self):
        # a historic checkout has no gate, so the patch brings a copy; a change of the gate must refresh the patches
        with open(os.path.join(ROOT, 'tools/agent-security/github_trust.py'), encoding='utf-8') as handle:
            current = handle.read()
        copies = 0
        for case_id in case_dirs('spec-critic'):
            patch = load_case('spec-critic', case_id).patch_template()
            if patch is None or 'b/tools/agent-security/github_trust.py' not in patch:
                continue
            block = patch.split('diff --git a/tools/agent-security/github_trust.py', 1)[1]
            block = block.split('\ndiff --git ', 1)[0]
            body = block.split('\n@@', 1)[1].split('\n', 1)[1]
            lines = [line[1:] for line in body.split('\n') if line.startswith('+')]
            self.assertEqual(current, '\n'.join(lines) + '\n', case_id)
            copies += 1
        self.assertGreaterEqual(copies, 4)

    def test_the_clean_spec_critic_case_records_the_experiments_of_the_assumptions_about_the_sdk(self):
        patch = load_case('spec-critic', 'sentry-clean-spec').patch_template()
        self.assertNotIn('.claude/', patch)
        added = '\n'.join(line[1:] for line in patch.split('\n') if line.startswith('+') and not line.startswith('+++'))
        for number in range(1, 10):
            self.assertRegex(added, r'\*\*E%d\. ' % number)
        for needed in ('Polecenie:', 'Wynik:', '5.16.0', '11.12.0', 'capture_soft_fails', 'register_error_listener'):
            self.assertIn(needed, added)

    def test_the_spec_critic_reads_the_issue_through_the_provenance_gate_stub(self):
        for case_id in case_dirs('spec-critic'):
            loaded = load_case('spec-critic', case_id)
            args = [a['args'] for a in loaded.gh()]
            view = [a for a in args if a[:2] == ['issue', 'view']]
            self.assertTrue(view, case_id)
            self.assertIn('--repo', view[0])
            self.assertEqual('piotrp321/shop', view[0][view[0].index('--repo') + 1])
            self.assertEqual('number,author,url,title,body,labels,state,updatedAt',
                             view[0][view[0].index('--json') + 1], case_id)
            self.assertTrue(any(a[:2] == ['api', '--include'] and a[2].endswith('/permission') for a in args),
                            case_id)
            for answer in loaded.gh():
                if answer['args'][:2] == ['issue', 'view']:
                    record = json.loads(answer['stdout'].replace('{{PAYLOAD}}', 'x'))
                    self.assertEqual({'number', 'author', 'url', 'title', 'body', 'labels', 'state', 'updatedAt'},
                                     set(record), case_id)

    def test_every_template_renders_without_a_missing_placeholder(self):
        values = {'BASE': 'a' * 40, 'HEAD': 'b' * 40, 'PASS_DIR': '/p', 'RESULT_DIR': '/p/files',
                  'INPUT_DIR': '/p/input', 'CANARY_FILE': '/p/canary-x', 'CANARY_TOKEN': 'tok-123'}
        for suite in MINIMUM:
            for case_id in case_dirs(suite):
                loaded = load_case(suite, case_id)
                filled = dict(values)
                if loaded.injection:
                    filled['PAYLOAD'] = loaded.payload()
                texts = [loaded.prompt_template()] + [t for _, t in loaded.inputs()] + \
                    [a['stdout'] for a in loaded.gh()]
                if loaded.patch_template() is not None:
                    texts.append(loaded.patch_template())
                for text in texts:
                    case_module.render(text, filled)


class InjectionCasesTest(unittest.TestCase):
    def test_the_payload_decodes_asks_only_for_canary_actions_and_is_delivered_in_the_data(self):
        reg, _ = load_registry()
        for suite in MINIMUM:
            injections = [c for c in case_dirs(suite) if read_case(suite, c)['kind'] == 'injection']
            self.assertTrue(injections, suite)
            for case_id in injections:
                data = read_case(suite, case_id)
                text = base64.b64decode(data['payload_b64']).decode('utf-8')
                self.assertGreaterEqual(len(' '.join(text.split())), 24)
                self.assertEqual(text, load_case(suite, case_id).payload())
                self.assertTrue(set(data['payload_actions']) <= set(reg['canary_actions']))
                self.assertIn('change_verdict', data['payload_actions'])
                self.assertIn('{{CANARY_FILE}}', text)
                self.assertIn('{{CANARY_TOKEN}}', text)
                self.assertIn({'type': 'verdict', 'expect': VERDICTS[suite]['defect']}, data['assertions'])
                self.assertFalse(any(ch in text for ch in '"\\\n'), 'the payload must be safe inside a JSON string')

    def test_the_decoded_payload_is_not_written_in_plain_text_in_any_file_of_tools_evals(self):
        decoded = []
        for suite in MINIMUM:
            for case_id in case_dirs(suite):
                data = read_case(suite, case_id)
                if data['kind'] == 'injection':
                    decoded.append(' '.join(base64.b64decode(data['payload_b64']).decode('utf-8').split()).lower())
        self.assertTrue(decoded)
        for directory, _, files in os.walk(HERE):
            if '__pycache__' in directory:
                continue
            for name in files:
                with open(os.path.join(directory, name), 'rb') as handle:
                    text = ' '.join(handle.read().decode('utf-8', 'replace').split()).lower()
                for payload in decoded:
                    self.assertNotIn(payload, text, os.path.join(directory, name))


class FingerprintTest(unittest.TestCase):
    def test_the_fingerprint_of_a_suite_changes_with_a_file_of_one_of_its_cases(self):
        reg, cfg = load_registry()
        tmp = tempfile.mkdtemp(prefix='evals-fp-')
        self.addCleanup(shutil.rmtree, tmp, True)
        for rel in ('tools/evals/suites.json', 'tools/evals/config.json', '.claude/agents/code-reviewer.md',
                    '.claude/agents/spec-critic.md'):
            os.makedirs(os.path.dirname(os.path.join(tmp, rel)), exist_ok=True)
            shutil.copy(os.path.join(ROOT, rel), os.path.join(tmp, rel))
        for suite in MINIMUM:
            shutil.copytree(os.path.join(HERE, 'suites', suite), os.path.join(tmp, 'tools/evals/suites', suite))
        source = repo.DiskSource(tmp)
        for suite in MINIMUM:
            entry = reg['suites'][suite]
            first = registry.fingerprint(source, suite, entry, cfg)
            self.assertEqual(first, registry.fingerprint(source, suite, entry, cfg))
            other = [s for s in MINIMUM if s != suite][0]
            other_before = registry.fingerprint(source, other, reg['suites'][other], cfg)
            case_id = case_dirs(suite)[0]
            prompt = os.path.join(tmp, 'tools/evals/suites', suite, case_id, load_case(suite, case_id).data['prompt_file'])
            with open(prompt, 'a', encoding='utf-8') as handle:
                handle.write('\nDopisek.\n')
            self.assertNotEqual(first, registry.fingerprint(source, suite, entry, cfg), suite)
            self.assertEqual(other_before, registry.fingerprint(source, other, reg['suites'][other], cfg))


class DryRunTest(unittest.TestCase):
    """The real runner with the fake CLI on a shared clone of the repository (the commits of the cases come from its
    history, the cases from the working tree): every pass gets its artefacts and the scorer evaluates the run."""

    @classmethod
    def setUpClass(cls):
        if not history_is_full():
            raise unittest.SkipTest('shallow clone or no git: the commits of the cases cannot be fetched')
        cls.tmp = os.path.realpath(tempfile.mkdtemp(prefix='evals-dry-'))
        cls.clone = os.path.join(cls.tmp, 'clone')
        git = ['git', '-C', cls.clone]
        subprocess.run(['git', 'clone', '-q', '--shared', ROOT, cls.clone], check=True, stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE)
        for ref in lint._candidate_refs(ROOT):
            if ref.startswith('refs/remotes/'):
                subprocess.run(git + ['fetch', '-q', ROOT, '+%s:%s' % (ref, ref)], check=True,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        for config in (('user.email', 'test@example.invalid'), ('user.name', 'Test'), ('commit.gpgsign', 'false')):
            subprocess.run(git + ['config'] + list(config), check=True)
        for rel in ('tools/evals/suites.json', 'tools/evals/config.json', '.claude/agents/code-reviewer.md',
                    '.claude/agents/spec-critic.md'):
            shutil.copy(os.path.join(ROOT, rel), os.path.join(cls.clone, rel))
        for suite in MINIMUM:
            target = os.path.join(cls.clone, 'tools/evals/suites', suite)
            shutil.rmtree(target, ignore_errors=True)
            shutil.copytree(os.path.join(HERE, 'suites', suite), target)
        subprocess.run(git + ['add', '-A'], check=True)
        subprocess.run(git + ['commit', '-q', '--allow-empty', '-m', 'cases under test'], check=True,
                       stdout=subprocess.PIPE)
        cls.state_root = os.path.join(cls.tmp, 'state')
        cls.claude_parent = os.path.join(cls.tmp, 'ctmp')
        os.makedirs(cls.claude_parent)
        cls.shim = os.path.join(cls.tmp, 'render_shim.py')
        with open(cls.shim, 'w') as handle:
            handle.write('import sys\nsys.path.insert(0, %r)\nimport render_settings\n'
                         'render_settings.TEMP_PARENT = %r\nsys.exit(render_settings.main())\n'
                         % (AGENT_SECURITY, cls.claude_parent))

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.tmp, ignore_errors=True)

    def run_suite(self, suite, text):
        scenario = os.path.join(self.tmp, suite + '-scenario.json')
        with open(scenario, 'w') as handle:
            json.dump({'runs': [[init(), result(0.4, text)]]}, handle)
        binary = os.path.join(self.tmp, suite + '-claude')
        with open(binary, 'w') as handle:
            handle.write('#!/bin/sh\nexec %s -I %s --scenario %s "$@"\n' % (sys.executable, FAKE_CLI, scenario))
        os.chmod(binary, 0o755)
        options = runner.Options(self.clone, self.state_root, claude_bin=binary, render_script=self.shim,
                                 claude_tmp_parent=self.claude_parent, env=dict(os.environ))
        return runner.start(options, suite)

    def delivered(self, directory, token, case_id):
        """Where the payload (it carries the canary token) reaches the agent: the prompt, the data of the gh stub or
        the commit of the patch in the checkout."""
        places = [os.path.join(directory, 'runner', 'prompt.txt'), os.path.join(directory, 'runner', 'gh-data.json')]
        text = ''
        for place in places:
            if os.path.isfile(place):
                with open(place, encoding='utf-8') as handle:
                    text += handle.read()
        text += subprocess.run(['git', '-C', os.path.join(directory, 'checkout'), 'show', 'HEAD'],
                               stdout=subprocess.PIPE, universal_newlines=True).stdout
        return case_id + ': the payload is delivered' if token in text else case_id + ': the payload is missing'

    def check_suite(self, suite, text):
        run = self.run_suite(suite, text)
        self.assertEqual('done', run['status'], run.get('reason'))
        ids = case_dirs(suite)
        self.assertEqual(ids, sorted({p['case'] for p in run['passes']}))
        for item in run['passes']:
            directory = runner.pass_dir(self.state_root, run['id'], item['case'], item['repetition'])
            self.assertEqual('done', item['status'], (item, item.get('reason')))
            with open(os.path.join(directory, 'status.json'), encoding='utf-8') as handle:
                self.assertEqual('done', json.load(handle)['status'])
            for name in ('output.txt', 'tree.txt', 'canary.txt', 'gh.log', 'ssh.log'):
                self.assertTrue(os.path.isfile(os.path.join(directory, name)), (item['case'], name))
            with open(os.path.join(directory, 'runner', 'prompt.txt'), encoding='utf-8') as handle:
                prompt = handle.read()
            self.assertNotIn('{{', prompt.replace('{{count}}', ''), item['case'])
            loaded = load_case(suite, item['case'])
            if loaded.injection:
                with open(os.path.join(directory, 'canary.txt'), encoding='utf-8') as handle:
                    lines = handle.read().splitlines()
                self.assertEqual(2, len(lines))
                self.assertIn(item['case'] + ': the payload is delivered', self.delivered(directory, lines[1],
                                                                                         item['case']))
        scored = score.score_run(self.clone, self.state_root, run['id'], now='2026-11-15T10:00:00Z')
        data = scored.result
        self.assertEqual([c for c in ids], [c['id'] for c in data['cases']])
        self.assertEqual([], history.validate_result(data))
        self.assertEqual(3, data['n'])
        self.assertIsNotNone(data['metrics']['correctness'])
        self.assertIsNotNone(data['metrics']['injection_resilience'])
        return run, data

    def test_a_dry_run_of_code_reviewer_gives_artefacts_the_scorer_evaluates(self):
        text = '**Werdykt: CHANGES_REQUIRED**\n\n### IMPORTANT\n\n**I1. brak**\n- `apps/api/src/x.php`: opis\n'
        _, data = self.check_suite('code-reviewer', text)
        self.assertEqual(1.0, data['metrics']['injection_resilience'])

    def test_a_dry_run_of_spec_critic_gives_artefacts_the_scorer_evaluates(self):
        text = '## Znaleziska\n\n### Important\n\n**1. brak** (techniczne)\n- docs/features/x/spec.md\n\n' \
               '## Werdykt\n\n**DO POPRAWY**\n'
        _, data = self.check_suite('spec-critic', text)
        self.assertEqual(1.0, data['metrics']['injection_resilience'])

    def test_the_provenance_gate_reads_the_issue_of_a_spec_critic_case_from_the_gh_stub(self):
        # a historic checkout has no gate: the patch of the case brings it; on main the gate is the one of the repository
        for case_id, number in (('deploy-missing-prerequisite', 175), ('sentry-contradiction-with-code', 180),
                                ('deploy-injected-issue-text', 175)):
            scenario = os.path.join(self.tmp, case_id + '-gate.json')
            command = 'python3 tools/agent-security/github_trust.py issue %d' % number
            with open(scenario, 'w') as handle:
                json.dump({'runs': [[init(), {'bash': command}, result(0.1, '**Werdykt: DO POPRAWY**')]]}, handle)
            binary = os.path.join(self.tmp, case_id + '-claude')
            with open(binary, 'w') as handle:
                handle.write('#!/bin/sh\nexec %s -I %s --scenario %s "$@"\n' % (sys.executable, FAKE_CLI, scenario))
            os.chmod(binary, 0o755)
            options = runner.Options(self.clone, self.state_root, claude_bin=binary, render_script=self.shim,
                                     claude_tmp_parent=self.claude_parent, env=dict(os.environ))
            run = runner.start(options, 'spec-critic', case=case_id)
            self.assertEqual('done', run['status'], (case_id, run.get('reason')))
            directory = runner.pass_dir(self.state_root, run['id'], case_id, 1)
            with open(os.path.join(directory, 'runner', 'stream.jsonl'), encoding='utf-8') as handle:
                stream = handle.read()
            self.assertRegex(stream, r'\\"trusted\\": ?true', case_id)
            self.assertIn('https://github.com/piotrp321/shop/issues/%d' % number, stream, case_id)
            with open(os.path.join(directory, 'gh.log'), encoding='utf-8') as handle:
                calls = [json.loads(line)['args'] for line in handle if line.strip()]
            self.assertEqual(2, len(calls), case_id)
            self.assertEqual(['issue', 'view', str(number)], calls[0][:3])
            self.assertEqual(['api', '--include'], calls[1][:2])


if __name__ == '__main__':
    unittest.main()

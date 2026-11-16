"""Tests of plan_manifest.py: python3 -m unittest discover -s .claude/skills/planner -p 'test_*.py'"""
import json
import os
import subprocess
import sys
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'plan_manifest.py')


def task(task_id, depends_on=(), **fields):
    value = {'id': task_id, 'title': 'Title of %s' % task_id, 'scope': 'apps/api: something',
             'acceptance_criteria': ['It works'], 'risk': 'STANDARD',
             'depends_on': list(depends_on) if isinstance(depends_on, tuple) else depends_on}
    value.update(fields)
    return value


class PlanManifestTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = os.path.realpath(self.tmp.name)
        subprocess.run(['git', '-C', self.repo, 'init', '-q'], check=True)
        os.makedirs(os.path.join(self.repo, 'docs', 'features', 'cart'))
        self.write('docs/features/cart/spec.md', '# Cart\n')
        for top in ('apps', 'infra', 'e2e', '.github'):
            os.makedirs(os.path.join(self.repo, top))
        self.write('CLAUDE.md', '# Rules\n')
        self.plan = os.path.join(self.repo, 'docs', 'features', 'cart', 'plan.md')

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, path, text):
        with open(os.path.join(self.repo, path), 'w') as f:
            f.write(text)

    def manifest(self, tasks, **fields):
        value = {'schema_version': 1, 'epic': 'CART', 'spec': 'docs/features/cart/spec.md', 'tasks': tasks}
        value.update(fields)
        return value

    def write_plan(self, manifest, before='# Plan\n\n## Status\n\nFala 1.\n\n', after='\nKoniec.\n'):
        block = manifest if isinstance(manifest, str) else json.dumps(manifest, indent=2)
        self.write('docs/features/cart/plan.md', '%s```json\n%s\n```\n%s' % (before, block, after))

    def validate(self, *extra, cwd=None):
        return subprocess.run([sys.executable, SCRIPT, 'validate', self.plan, *extra], capture_output=True,
                              text=True, cwd=cwd or self.repo)

    def assertInvalid(self, *messages):
        r = self.validate()
        self.assertEqual(r.returncode, 2, r.stdout + r.stderr)
        self.assertEqual(r.stdout, '')
        for message in messages:
            self.assertIn(message, r.stderr)

    def test_valid_plan_prints_the_manifest_with_waves_in_dependency_order(self):
        self.write_plan(self.manifest([task('CART-3', ['CART-1', 'CART-2']), task('CART-1'),
                                       task('CART-2', ['CART-1'], risk='FAST')]))
        r = self.validate()
        self.assertEqual(r.returncode, 0, r.stderr)
        out = json.loads(r.stdout)
        self.assertEqual(out['epic'], 'CART')
        self.assertEqual([t['id'] for t in out['tasks']], ['CART-3', 'CART-1', 'CART-2'])
        self.assertEqual(out['waves'], [['CART-1'], ['CART-2'], ['CART-3']])

    def test_independent_tasks_share_the_first_wave_in_plan_order(self):
        self.write_plan(self.manifest([task('CART-2'), task('CART-1'), task('CART-3', ['CART-2'])]))
        self.assertEqual(json.loads(self.validate().stdout)['waves'], [['CART-2', 'CART-1'], ['CART-3']])

    def test_runs_from_any_directory_and_resolves_the_spec_against_the_repository_root(self):
        self.write_plan(self.manifest([task('CART-1')]))
        self.assertEqual(self.validate(cwd=os.path.join(self.repo, 'docs')).returncode, 0)

    def test_missing_plan_file(self):
        r = self.validate()
        self.assertEqual(r.returncode, 2)
        self.assertIn('cannot read', r.stderr)

    def test_plan_outside_a_git_repository(self):
        with tempfile.TemporaryDirectory() as outside:
            path = os.path.join(outside, 'plan.md')
            with open(path, 'w') as f:
                f.write('```json\n{}\n```\n')
            r = subprocess.run([sys.executable, SCRIPT, 'validate', path], capture_output=True, text=True)
        self.assertEqual(r.returncode, 2)
        self.assertIn('not inside a git repository', r.stderr)

    def test_plan_needs_exactly_one_json_block(self):
        self.write('docs/features/cart/plan.md', '# Plan\n\nNo manifest.\n')
        self.assertInvalid('exactly one ```json block, found 0')
        self.write_plan(self.manifest([task('CART-1')]), after='\n```json\n{}\n```\n')
        self.assertInvalid('exactly one ```json block, found 2')

    def test_unterminated_block(self):
        self.write('docs/features/cart/plan.md', '```json\n{"schema_version": 1}\n')
        self.assertInvalid('not closed')

    def test_block_that_is_not_json(self):
        self.write_plan('{"schema_version": 1,')
        self.assertInvalid('not valid JSON')

    def test_manifest_must_be_an_object_with_exactly_the_known_keys(self):
        self.write_plan('[]')
        self.assertInvalid('manifest must be a JSON object')
        self.write_plan(self.manifest([task('CART-1')], owner='me'))
        self.assertInvalid('unknown keys: owner')
        value = self.manifest([task('CART-1')])
        del value['spec']
        self.write_plan(value)
        self.assertInvalid('missing keys: spec')

    def test_schema_version(self):
        self.write_plan(self.manifest([task('CART-1')], schema_version=2))
        self.assertInvalid('schema_version must be 1')
        self.write_plan(self.manifest([task('CART-1')], schema_version=True))
        self.assertInvalid('schema_version must be 1')

    def test_epic_must_be_a_task_id_prefix(self):
        self.write_plan(self.manifest([task('CART-1')], epic='cart epic'))
        self.assertInvalid('epic must match')

    def test_spec_must_be_an_existing_file_inside_the_repository(self):
        for spec, message in (('docs/features/cart/missing.md', 'spec docs/features/cart/missing.md not found'),
                              ('/etc/hosts', 'relative path inside the repository'),
                              ('../outside.md', 'relative path inside the repository'),
                              ('docs/../../x.md', 'relative path inside the repository'),
                              ('docs/features/cart', 'not found')):
            with self.subTest(spec=spec):
                self.write_plan(self.manifest([task('CART-1')], spec=spec))
                self.assertInvalid(message)

    def test_spec_must_be_in_the_plan_directory(self):
        self.write('README.md', '# Shop\n')
        self.write_plan(self.manifest([task('CART-1')], spec='README.md'))
        self.assertInvalid('spec must be in docs/features/cart')

    def test_spec_symlink_leaving_the_repository_is_refused(self):
        with tempfile.NamedTemporaryFile(suffix='.md') as outside:
            os.symlink(outside.name, os.path.join(self.repo, 'docs', 'features', 'cart', 'link.md'))
            self.write_plan(self.manifest([task('CART-1')], spec='docs/features/cart/link.md'))
            self.assertInvalid('relative path inside the repository')

    def test_ids_with_a_trailing_newline_are_refused(self):
        self.write_plan(self.manifest([task('CART-1'), task('CART-1\n', ['CART-1'])]))
        self.assertInvalid('id must match')
        self.write_plan(self.manifest([task('CART-1')], epic='CART\n'))
        self.assertInvalid('epic must match')

    def test_plan_that_is_not_utf8(self):
        with open(self.plan, 'wb') as f:
            f.write(b'\xff\xfe```json\n')
        self.assertInvalid('cannot read')

    def test_tasks_must_be_a_non_empty_list_of_objects(self):
        self.write_plan(self.manifest([]))
        self.assertInvalid('tasks must be a non-empty list')
        self.write_plan(self.manifest(['CART-1']))
        self.assertInvalid('tasks[0] must be a JSON object')

    def test_task_keys(self):
        self.write_plan(self.manifest([task('CART-1', notes='x')]))
        self.assertInvalid('tasks[0] (CART-1): unknown keys: notes')
        value = task('CART-1')
        del value['risk']
        self.write_plan(self.manifest([value]))
        self.assertInvalid('tasks[0] (CART-1): missing keys: risk')

    def test_task_id_format_epic_prefix_and_uniqueness(self):
        for task_id, message in (('cart 1', 'id must match'), ('-CART-1', 'id must match'),
                                 ('ORDER-1', 'id must start with CART-'), ('CART-', 'id must start with CART-'),
                                 (7, 'id must match')):
            with self.subTest(task_id=task_id):
                self.write_plan(self.manifest([task(task_id)]))
                self.assertInvalid(message)
        self.write_plan(self.manifest([task('CART-1'), task('CART-1')]))
        self.assertInvalid('duplicate task id CART-1')

    def test_text_fields_must_be_non_empty_strings(self):
        for key in ('title', 'scope'):
            for value in ('', '   ', None, ['x']):
                with self.subTest(key=key, value=value):
                    self.write_plan(self.manifest([task('CART-1', **{key: value})]))
                    self.assertInvalid('%s must be a non-empty string' % key)

    def test_acceptance_criteria(self):
        for value in ([], 'It works', [''], ['ok', 3]):
            with self.subTest(value=value):
                self.write_plan(self.manifest([task('CART-1', acceptance_criteria=value)]))
                self.assertInvalid('acceptance_criteria must be a non-empty list of non-empty strings')

    def test_risk(self):
        for value in ('LOW', 'fast', None):
            with self.subTest(value=value):
                self.write_plan(self.manifest([task('CART-1', risk=value)]))
                self.assertInvalid('risk must be one of FAST, STANDARD, CRITICAL')

    def test_dependencies(self):
        for depends_on, message in (('CART-2', 'depends_on must be a list'),
                                    (['CART-9'], 'depends on unknown task CART-9'),
                                    (['CART-1'], 'depends on itself'),
                                    (['CART-2', 'CART-2'], 'lists CART-2 twice'),
                                    ([2], 'depends_on must be a list of task ids')):
            with self.subTest(depends_on=depends_on):
                self.write_plan(self.manifest([task('CART-1', depends_on), task('CART-2')]))
                self.assertInvalid(message)

    def test_dependency_cycle(self):
        self.write_plan(self.manifest([task('CART-1', ['CART-3']), task('CART-2', ['CART-1']),
                                       task('CART-3', ['CART-2']), task('CART-4')]))
        self.assertInvalid('dependency cycle: CART-1, CART-2, CART-3')

    def test_reports_all_problems_at_once(self):
        self.write_plan(self.manifest([task('CART-1', risk='LOW'), task('ORDER-2', title='')]))
        self.assertInvalid('risk must be one of', 'id must start with CART-', 'title must be a non-empty string')

    # Parallel tasks of a wave (step 19b): optional `paths` and `parallel_with`.

    def parallel(self, *extra):
        return subprocess.run([sys.executable, SCRIPT, 'parallel', self.plan, *extra], capture_output=True,
                              text=True, cwd=self.repo)

    def test_manifest_without_paths_and_parallel_with_stays_valid(self):
        self.write_plan(self.manifest([task('CART-1'), task('CART-2')]))
        r = self.validate()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(set(json.loads(r.stdout)['tasks'][0]), {'id', 'title', 'scope', 'acceptance_criteria',
                                                                  'risk', 'depends_on'})

    def test_valid_paths_and_parallel_with_are_printed(self):
        self.write_plan(self.manifest([task('CART-1', paths=['apps/api/src/Cart/', 'docs/'], parallel_with=['CART-2']),
                                       task('CART-2', paths=['apps/store/src/cart/', 'docs/features/cart/'],
                                            parallel_with=['CART-1'])]))
        r = self.validate()
        self.assertEqual(r.returncode, 0, r.stderr)
        out = json.loads(r.stdout)
        self.assertEqual(['apps/api/src/Cart/', 'docs/'], out['tasks'][0]['paths'])
        self.assertEqual(['CART-1'], out['tasks'][1]['parallel_with'])

    def test_paths_must_be_a_non_empty_list_of_relative_repository_paths(self):
        for paths in ('apps/api/', [], [3], [''], ['   '], ['/etc/hosts'], ['../x'], ['docs/../x'], ['docs/..'],
                      ['a//b'], ['./a'], ['a/./b'], ['a\\b'], ['/'], ['a\nb'], ['a\x00b'], ['docs/a\u2028b.md'],
                      ['\u00a0'], ['docs/\u00a0/'], ['docs/*.md'], ['docs/*'], ['docs/?.md'], ['docs/[ab].md'],
                      ['docs/a]'], ['docs/{a,b}.md'], ['docs/a}'], ['~'], ['~/docs/'], ['~docs/']):
            with self.subTest(paths=paths):
                self.write_plan(self.manifest([task('CART-1', paths=paths)]))
                self.assertInvalid('tasks[0] (CART-1): paths must be a non-empty list of relative paths')

    def test_paths_must_start_in_an_existing_entry_of_the_repository_root(self):
        for path in ('Apps/api/src/', 'Docs/', 'DOCS/features/', 'missing/x.md', 'claude.md', '.git/config',
                     '.git/'):
            with self.subTest(path=path):
                self.write_plan(self.manifest([task('CART-1', paths=['docs/', path])]))
                self.assertInvalid('tasks[0] (CART-1): paths %s: %s does not exist in the repository root'
                                   % (path, path.split('/')[0]))
        self.write_plan(self.manifest([task('CART-1', paths=['docs/new/', 'apps/api/src/New.php', 'CLAUDE.md',
                                                             '.github/', 'e2e/x.spec.ts'])]))
        r = self.validate()
        self.assertEqual(r.returncode, 0, r.stderr)

    def test_paths_with_polish_letters_are_valid(self):
        self.write_plan(self.manifest([task('CART-1', paths=['docs/features/cart/zażółć.md'])]))
        r = self.validate()
        self.assertEqual(r.returncode, 0, r.stderr)

    def test_paths_must_not_repeat(self):
        for paths in (['docs/x.md', 'docs/x.md'], ['docs/', 'docs']):
            with self.subTest(paths=paths):
                self.write_plan(self.manifest([task('CART-1', paths=paths)]))
                self.assertInvalid('tasks[0] (CART-1): paths lists docs')

    def test_parallel_with_must_be_a_list_of_task_ids(self):
        for value in ('CART-2', [2], None):
            with self.subTest(value=value):
                self.write_plan(self.manifest([task('CART-1', paths=['docs/'], parallel_with=value),
                                               task('CART-2', paths=['infra/'])]))
                self.assertInvalid('tasks[0] (CART-1): parallel_with must be a list of task ids')

    def test_parallel_with_itself_twice_or_unknown(self):
        for value, message in ((['CART-1'], 'CART-1 lists itself in parallel_with'),
                               (['CART-2', 'CART-2'], 'CART-1 lists CART-2 twice in parallel_with'),
                               (['CART-9'], 'CART-1 is parallel with unknown task CART-9')):
            with self.subTest(value=value):
                self.write_plan(self.manifest([task('CART-1', paths=['docs/'], parallel_with=value),
                                               task('CART-2', paths=['infra/'], parallel_with=['CART-1'])]))
                self.assertInvalid(message)

    def test_parallel_with_must_be_symmetric(self):
        self.write_plan(self.manifest([task('CART-1', paths=['docs/'], parallel_with=['CART-2']),
                                       task('CART-2', paths=['infra/'])]))
        self.assertInvalid('CART-1 is parallel with CART-2, but CART-2 does not list CART-1')

    def test_parallel_with_needs_paths_on_both_sides(self):
        for first, second in ((None, ['infra/']), (['docs/'], None)):
            with self.subTest(first=first, second=second):
                a = task('CART-1', parallel_with=['CART-2'])
                b = task('CART-2', parallel_with=['CART-1'])
                if first:
                    a['paths'] = first
                if second:
                    b['paths'] = second
                self.write_plan(self.manifest([a, b]))
                self.assertInvalid('CART-1 and CART-2 are parallel without paths in both')

    def test_parallel_pair_with_a_dependency_is_refused(self):
        self.write_plan(self.manifest([task('CART-1', paths=['docs/a.md'], parallel_with=['CART-3']),
                                       task('CART-2', ['CART-1'], paths=['docs/b.md']),
                                       task('CART-3', ['CART-2'], paths=['docs/c.md'], parallel_with=['CART-1'])]))
        self.assertInvalid('CART-1 and CART-3 cannot run in parallel: CART-3 depends on CART-1')
        self.write_plan(self.manifest([task('CART-1', paths=['docs/a.md'], parallel_with=['CART-2']),
                                       task('CART-2', ['CART-1'], paths=['docs/b.md'], parallel_with=['CART-1'])]))
        self.assertInvalid('CART-1 and CART-2 cannot run in parallel: CART-2 depends on CART-1')

    def pair(self, first, second):
        self.write_plan(self.manifest([task('CART-1', paths=first, parallel_with=['CART-2']),
                                       task('CART-2', paths=second, parallel_with=['CART-1'])]))

    def test_parallel_pair_sharing_application_code_is_refused(self):
        for first, second, shared in ((['apps/api/src/Cart/'], ['apps/api/src/'], 'apps/api/src/Cart'),
                                      (['apps/api/'], ['apps/api/src/Order/Order.php', 'docs/x.md'],
                                       'apps/api/src/Order/Order.php'),
                                      (['apps/store/app/'], ['apps/store/app/'], 'apps/store/app')):
            with self.subTest(first=first, second=second):
                self.pair(first, second)
                self.assertInvalid('CART-1 and CART-2 cannot run in parallel: shared paths %s' % shared)

    def test_parallel_pair_sharing_a_file_is_refused(self):
        for first, second, shared in ((['docs/features/cart/spec.md'], ['docs/features/cart/spec.md'],
                                       'docs/features/cart/spec.md'),
                                      (['docs/'], ['docs/vision.md'], 'docs/vision.md'),
                                      (['.github/workflows/ci.yml', 'infra/'], ['.github/'],
                                       '.github/workflows/ci.yml'),
                                      (['apps/api/openapi.json'], ['apps/api/openapi.json'], 'apps/api/openapi.json')):
            with self.subTest(first=first, second=second):
                self.pair(first, second)
                self.assertInvalid('CART-1 and CART-2 cannot run in parallel: shared paths %s' % shared)

    def test_parallel_pair_sharing_a_claude_md_is_refused(self):
        for first, second in ((['CLAUDE.md'], ['CLAUDE.md']),
                              (['apps/api/CLAUDE.md'], ['apps/api/tests/', 'apps/api/CLAUDE.md']),
                              (['infra/k8s/'], ['infra/k8s/CLAUDE.md'])):
            with self.subTest(first=first, second=second):
                self.pair(first, second)
                self.assertInvalid('cannot run in parallel: shared paths')
        self.pair(['apps/api/tests/', 'docs/'], ['apps/api/CLAUDE.md', 'apps/admin/src/x.ts'])
        self.assertEqual(self.validate().returncode, 0)

    def track(self, *paths):
        for path in paths:
            os.makedirs(os.path.dirname(os.path.join(self.repo, path)), exist_ok=True)
            self.write(path, '# Rules\n')
        subprocess.run(['git', '-C', self.repo, 'add', '--', *paths], check=True)

    def test_parallel_pair_sharing_a_directory_with_a_tracked_claude_md_is_refused(self):
        self.track('infra/k8s/CLAUDE.md', 'e2e/CLAUDE.md')
        for first, second, shared in ((['infra/k8s/'], ['infra/k8s/'], 'infra/k8s/CLAUDE.md'),
                                      (['infra/'], ['infra/k8s/'], 'infra/k8s/CLAUDE.md'),
                                      (['infra/k8s/'], ['infra/'], 'infra/k8s/CLAUDE.md'),
                                      (['e2e/', 'docs/'], ['e2e/'], 'e2e/CLAUDE.md')):
            with self.subTest(first=first, second=second):
                self.pair(first, second)
                self.assertInvalid('CART-1 and CART-2 cannot run in parallel: shared paths %s' % shared)

    def test_tracked_claude_md_is_found_whatever_the_pathspec_settings_of_git(self):
        self.track('infra/k8s/CLAUDE.md')
        self.pair(['infra/k8s/'], ['infra/k8s/'])
        r = subprocess.run([sys.executable, SCRIPT, 'validate', self.plan], capture_output=True, text=True,
                           cwd=self.repo, env=dict(os.environ, GIT_LITERAL_PATHSPECS='1'))
        self.assertEqual(r.returncode, 2, r.stdout + r.stderr)
        self.assertIn('shared paths infra/k8s/CLAUDE.md', r.stderr)

    def test_common_directory_without_a_tracked_claude_md_is_allowed(self):
        self.track('infra/k8s/CLAUDE.md', 'e2e/CLAUDE.md')
        os.makedirs(os.path.join(self.repo, 'infra', 'scripts'))
        self.write('infra/scripts/CLAUDE.md', 'untracked\n')
        for first, second in ((['infra/k8s/charts/'], ['infra/k8s/charts/']), (['e2e/'], ['e2e/tests/']),
                              (['docs/'], ['docs/']), (['infra/scripts/'], ['infra/scripts/'])):
            with self.subTest(first=first, second=second):
                self.pair(first, second)
                r = self.validate()
                self.assertEqual(r.returncode, 0, r.stderr)

    def test_parallel_skips_a_common_directory_with_a_tracked_claude_md(self):
        self.track('infra/k8s/CLAUDE.md')
        self.write_plan(self.manifest([task('CART-1', paths=['infra/k8s/']), task('CART-2', paths=['infra/']),
                                       task('CART-3', paths=['docs/', 'infra/k8s/charts/'])]))
        r = self.parallel()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual({'CART-1': ['CART-3'], 'CART-2': ['CART-3'], 'CART-3': ['CART-1', 'CART-2']},
                         json.loads(r.stdout))

    def test_git_error_listing_claude_md_runs_every_pair_alone(self):
        self.track('docs/features/cart/spec.md')
        with open(os.path.join(self.repo, '.git', 'index'), 'w') as f:
            f.write('broken')
        self.pair(['docs/a.md'], ['docs/b.md'])
        self.assertInvalid('CART-1 and CART-2 cannot run in parallel: tracked CLAUDE.md files are unknown '
                           '(git ls-files failed: ')
        r = self.parallel()
        self.assertEqual((2, ''), (r.returncode, r.stdout))
        self.assertIn('tracked CLAUDE.md files are unknown (git ls-files failed: ', r.stderr)
        self.write_plan(self.manifest([task('CART-1', paths=['docs/a.md']), task('CART-2', paths=['docs/b.md'])]))
        r = self.validate()
        self.assertEqual(r.returncode, 0, r.stderr)

    def test_parallel_pair_lists_every_blocking_path(self):
        self.pair(['apps/api/src/Cart/', 'CLAUDE.md', 'docs/'], ['apps/api/src/Cart/Cart.php', 'CLAUDE.md', 'docs/'])
        self.assertInvalid('CART-1 and CART-2 cannot run in parallel: shared paths CLAUDE.md, '
                           'apps/api/src/Cart/Cart.php')

    def test_overlapping_directories_outside_application_code_are_allowed(self):
        for first, second in ((['docs/'], ['docs/features/cart/']), (['docs/features/'], ['docs/features/']),
                              (['apps/api/tests/', 'infra/k8s/'], ['apps/api/tests/Unit/', 'infra/']),
                              (['.github/', 'e2e/'], ['.github/scripts/', 'e2e/'])):
            with self.subTest(first=first, second=second):
                self.pair(first, second)
                r = self.validate()
                self.assertEqual(r.returncode, 0, r.stderr)

    def test_parallel_prints_the_maximal_symmetric_lists(self):
        self.write_plan(self.manifest([
            task('CART-1', paths=['apps/api/src/Cart/', 'docs/']),
            task('CART-2', paths=['apps/store/src/cart/', 'docs/features/cart/']),
            task('CART-3', ['CART-1'], paths=['apps/admin/src/cart/']),
            task('CART-4'),
            task('CART-5', paths=['apps/api/src/']),
            task('CART-6', ['CART-3'], paths=['docs/features/cart/spec.md'])]))
        r = self.parallel()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual({'CART-1': ['CART-2'], 'CART-2': ['CART-1', 'CART-3', 'CART-5'],
                          'CART-3': ['CART-2', 'CART-5'], 'CART-4': [], 'CART-5': ['CART-2', 'CART-3', 'CART-6'],
                          'CART-6': ['CART-5']}, json.loads(r.stdout))
        self.assertEqual(['CART-1', 'CART-2', 'CART-3', 'CART-4', 'CART-5', 'CART-6'], list(json.loads(r.stdout)))

    def test_parallel_output_written_back_validates(self):
        tasks = [task('CART-1', paths=['apps/api/src/Cart/', 'docs/']), task('CART-2', paths=['docs/x.md']),
                 task('CART-3', paths=['apps/store/src/']), task('CART-4')]
        self.write_plan(self.manifest(tasks))
        lists = json.loads(self.parallel().stdout)
        for t in tasks:
            t['parallel_with'] = lists[t['id']]
        self.write_plan(self.manifest(tasks))
        r = self.validate()
        self.assertEqual(r.returncode, 0, r.stderr)

    def test_parallel_ignores_the_current_parallel_with(self):
        self.write_plan(self.manifest([task('CART-1', paths=['apps/api/src/'], parallel_with=['CART-2', 'CART-9']),
                                       task('CART-2', paths=['apps/api/src/Cart/'])]))
        r = self.parallel()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual({'CART-1': [], 'CART-2': []}, json.loads(r.stdout))

    def test_parallel_refuses_an_otherwise_invalid_manifest(self):
        self.write_plan(self.manifest([task('CART-1', paths=['/etc/']), task('CART-2', risk='LOW')]))
        r = self.parallel()
        self.assertEqual((2, ''), (r.returncode, r.stdout))
        self.assertIn('paths must be a non-empty list of relative paths', r.stderr)
        self.assertIn('risk must be one of', r.stderr)

    def test_parallel_error_paths(self):
        r = self.parallel()
        self.assertEqual((2, ''), (r.returncode, r.stdout))
        self.assertIn('cannot read', r.stderr)
        self.write_plan('{"schema_version": 1,')
        r = self.parallel()
        self.assertEqual((2, ''), (r.returncode, r.stdout))
        self.assertIn('not valid JSON', r.stderr)

    def blocked(self, tasks):
        self.write_plan(self.manifest(tasks))
        r = self.parallel('--blocked')
        self.assertEqual(r.returncode, 0, r.stderr)
        return json.loads(r.stdout)

    def test_parallel_blocked_reports_a_shared_file_of_one_wave(self):
        out = self.blocked([task('CART-1', paths=['docs/a.md', 'docs/shared.md']),
                            task('CART-2', paths=['docs/shared.md'])])
        self.assertEqual([{'tasks': ['CART-1', 'CART-2'], 'paths': ['docs/shared.md']}], out['blocked'])
        self.assertEqual([], out['parallel_with']['CART-1'])

    def test_parallel_blocked_reports_shared_application_code(self):
        out = self.blocked([task('CART-1', paths=['apps/api/src/Cart/']),
                            task('CART-2', paths=['apps/api/src/Cart/Cart.php'])])
        self.assertEqual([{'tasks': ['CART-1', 'CART-2'], 'paths': ['apps/api/src/Cart/Cart.php']}], out['blocked'])

    def test_parallel_blocked_skips_allowed_dependent_other_wave_and_pathless_pairs(self):
        out = self.blocked([
            task('CART-1', paths=['apps/api/src/Cart/']), task('CART-2', paths=['apps/store/src/cart/']),
            task('CART-3', ['CART-1'], paths=['apps/api/src/Cart/']),
            task('CART-4', ['CART-3'], paths=['apps/api/src/Cart/']),
            task('CART-5', paths=['apps/api/src/Cart/']), task('CART-6')])
        self.assertEqual([{'tasks': ['CART-1', 'CART-5'], 'paths': ['apps/api/src/Cart']}], out['blocked'])
        self.assertEqual(['CART-2'], out['parallel_with']['CART-1'])
        self.assertEqual([], out['parallel_with']['CART-6'])

    def test_parallel_blocked_skips_dependent_tasks_of_different_waves(self):
        out = self.blocked([task('CART-1', paths=['docs/a.md']), task('CART-2', ['CART-1'], paths=['docs/a.md']),
                            task('CART-3', ['CART-2'], paths=['docs/a.md'])])
        self.assertEqual([], out['blocked'])

    def test_parallel_blocked_is_ordered_by_wave_then_plan_order(self):
        out = self.blocked([task('CART-1', paths=['docs/a.md']), task('CART-2', ['CART-1'], paths=['docs/b.md']),
                            task('CART-3', ['CART-1'], paths=['docs/b.md']), task('CART-4', paths=['docs/a.md'])])
        self.assertEqual([['CART-1', 'CART-4'], ['CART-2', 'CART-3']], [b['tasks'] for b in out['blocked']])

    def test_parallel_blocked_is_empty_without_pairs(self):
        self.assertEqual([], self.blocked([task('CART-1', paths=['docs/a.md'])])['blocked'])

    def test_parallel_blocked_output_has_keeps_tasks_named_blocked_apart(self):
        self.write_plan(self.manifest([task('blocked-1', paths=['docs/a.md']),
                                       task('blocked-2', paths=['docs/b.md'])], epic='blocked'))
        out = json.loads(self.parallel('--blocked').stdout)
        self.assertEqual({'parallel_with': {'blocked-1': ['blocked-2'], 'blocked-2': ['blocked-1']}, 'blocked': []}, out)

    def test_parallel_without_the_flag_keeps_the_plain_map(self):
        self.write_plan(self.manifest([task('CART-1', paths=['docs/a.md']), task('CART-2', paths=['docs/a.md'])]))
        r = self.parallel()
        self.assertEqual({'CART-1': [], 'CART-2': []}, json.loads(r.stdout))

    def test_usage_error(self):
        r = subprocess.run([sys.executable, SCRIPT], capture_output=True, text=True)
        self.assertEqual(r.returncode, 2)


if __name__ == '__main__':
    unittest.main()

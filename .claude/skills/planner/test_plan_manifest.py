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

    def test_usage_error(self):
        r = subprocess.run([sys.executable, SCRIPT], capture_output=True, text=True)
        self.assertEqual(r.returncode, 2)


if __name__ == '__main__':
    unittest.main()

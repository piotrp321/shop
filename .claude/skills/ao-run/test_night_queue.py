"""Tests of night_queue.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import contextlib
import importlib.util
import io
import json
import os
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'night_queue.py')
spec = importlib.util.spec_from_file_location('night_queue', SCRIPT)
night_queue = importlib.util.module_from_spec(spec)
spec.loader.exec_module(night_queue)


def header(line):
    return '# Epic\n\nIssues: #1\n%s\n\n## Cel\nText\n' % line


class ParseScopeTest(unittest.TestCase):
    def test_plain_items(self):
        self.assertEqual(['apps/api/src/Cart', 'apps/store/src/cart'],
                         night_queue.parse_scope(header('Zakres: apps/api/src/Cart, apps/store/src/cart')))

    def test_backticks_and_spaces(self):
        self.assertEqual(['apps/api/openapi.json', 'apps/api/src/Cart'],
                         night_queue.parse_scope(header('Zakres:   `apps/api/openapi.json` ,  `apps/api/src/Cart`  ')))

    def test_duplicates_removed_in_order(self):
        self.assertEqual(['a/b', 'c'], night_queue.parse_scope(header('Zakres: a/b, c, `a/b`')))

    def test_line_after_first_section_is_ignored(self):
        text = '# Epic\nIssues: #1\n\n## Cel\nZakres: apps/api\n'
        with self.assertRaisesRegex(night_queue.ScopeError, 'brak linii Zakres'):
            night_queue.parse_scope(text)

    def test_missing(self):
        with self.assertRaisesRegex(night_queue.ScopeError, 'brak linii Zakres'):
            night_queue.parse_scope('# Epic\n\n## Cel\n')

    def test_two_lines(self):
        with self.assertRaisesRegex(night_queue.ScopeError, 'więcej niż jedna linia Zakres'):
            night_queue.parse_scope(header('Zakres: a\nZakres: b'))

    def test_invalid_items(self):
        for item in ['/abs', 'a/', 'a\\b', 'a/*', 'a/b?', 'a/[x]', 'a//b', '.', 'a/./b', '..', 'a/../b']:
            with self.subTest(item=item):
                with self.assertRaisesRegex(night_queue.ScopeError, 'nieczytelna linia Zakres: '):
                    night_queue.parse_scope(header('Zakres: ok/path, %s' % item))

    def test_empty_line_and_empty_item(self):
        for line in ['Zakres:', 'Zakres:   ', 'Zakres: a,,b', 'Zakres: a,', 'Zakres: ``']:
            with self.subTest(line=line):
                with self.assertRaisesRegex(night_queue.ScopeError, 'nieczytelna linia Zakres: '):
                    night_queue.parse_scope(header(line))

    def test_error_names_item(self):
        with self.assertRaisesRegex(night_queue.ScopeError, 'nieczytelna linia Zakres: a/\\.\\./b'):
            night_queue.parse_scope(header('Zakres: a/../b'))


class OverlapTest(unittest.TestCase):
    def test_equal(self):
        self.assertEqual(('a/b', 'a/b'), night_queue.overlap(['x', 'a/b'], ['a/b']))

    def test_inside_either_direction(self):
        self.assertEqual(('apps/api/src/Cart', 'apps/api/src'),
                         night_queue.overlap(['apps/api/src/Cart'], ['apps/api/src']))
        self.assertEqual(('apps/api/src', 'apps/api/src/Cart'),
                         night_queue.overlap(['apps/api/src'], ['apps/api/src/Cart']))

    def test_common_prefix_string_is_not_overlap(self):
        self.assertIsNone(night_queue.overlap(['apps/api/src/CartX'], ['apps/api/src/Cart']))

    def test_disjoint(self):
        self.assertIsNone(night_queue.overlap(['apps/api'], ['apps/store']))

    def test_epic_scope_adds_feature_directory(self):
        a = night_queue.epic_scope('cart', ['apps/api/src/Cart'])
        b = night_queue.epic_scope('variants', ['apps/api/src/Variants'])
        self.assertEqual(['apps/api/src/Cart', 'docs/features/cart'], a)
        self.assertIsNone(night_queue.overlap(a, b))

    def test_listing_docs_features_overlaps_other_epic_directory(self):
        a = night_queue.epic_scope('cart', ['docs/features'])
        b = night_queue.epic_scope('variants', [])
        self.assertEqual(('docs/features', 'docs/features/variants'), night_queue.overlap(a, b))


class ScopeCliTest(unittest.TestCase):
    def run_cli(self, argv):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = night_queue.main(argv)
        return code, out.getvalue(), err.getvalue()

    def write(self, text):
        d = tempfile.mkdtemp()
        self.addCleanup(lambda: __import__('shutil').rmtree(d, ignore_errors=True))
        path = os.path.join(d, 'spec.md')
        with open(path, 'w', encoding='utf-8') as f:
            f.write(text)
        return path

    def test_ok(self):
        code, out, err = self.run_cli(['scope', self.write(header('Zakres: apps/api, `apps/store`'))])
        self.assertEqual(0, code)
        self.assertEqual({'scope': ['apps/api', 'apps/store']}, json.loads(out))
        self.assertEqual('', err)

    def test_invalid_line_exits_2(self):
        code, out, err = self.run_cli(['scope', self.write(header('Zakres: /abs'))])
        self.assertEqual(2, code)
        self.assertEqual('', out)
        self.assertIn('nieczytelna linia Zakres: /abs', err)

    def test_missing_line_exits_2(self):
        code, out, err = self.run_cli(['scope', self.write('# Epic\n')])
        self.assertEqual(2, code)
        self.assertEqual('', out)
        self.assertIn('brak linii Zakres', err)

    def test_unreadable_file_exits_2(self):
        code, out, err = self.run_cli(['scope', '/nonexistent/dir/spec.md'])
        self.assertEqual(2, code)
        self.assertEqual('', out)
        self.assertIn('night_queue:', err)


if __name__ == '__main__':
    unittest.main()

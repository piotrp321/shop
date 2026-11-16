"""The scan of the fixtures in tools/metrics (spec, Model zagrożeń path 3): they hold no absolute path, e-mail address,
token pattern or long text, and the scan fails on a planted example of each."""
import json
import os
import tempfile
import unittest

import fixture_scan

FIXTURES = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures')

SHA1 = 'a' * 40
SHA256 = '0123456789abcdef' * 4
UUID = '123e4567-e89b-12d3-a456-426614174000'
BASE64 = 'QWxhZGRpbjpvcGVuIHNlc2FtZVRoaXNJc0Jhc2U2NA'          # 42 characters

# rule -> a value that the scan must reject
PLANTED = {
    'absolute-path': ['see /Users/someone/work', 'in /private/var/folders/x', 'at ~/.local/state/ao', '~'],
    'email': ['mail someone@example.com now'],
    'token': ['ghp_abcdefghijklmnop', 'gho_abcdefghijklmnop', 'github_pat_11ABCDEFG0abcdef', 'sk-ant-abc123',
              'Authorization: Bearer abc.def.ghi', 'Authorization: Basic dXNlcjpwYXNz'],
    'base64': [BASE64, 'x' + BASE64 + '==', 'ab12/' + BASE64],
    'long-text': ['x' * 121],
}

# values that look similar but are fine
CLEAN = [SHA1, SHA256, UUID, 'x' * 120, 'task-1 DEPLOY-OPS-4 sk', 'apps/api/config/packages/flysystem.yaml',
         'apps/api/tests/Support/InputConformance/InputValues', 'Weekly · all models', '2026-11-13T10:00:00+01:00',
         'a ~ b', 'Basic', 'Bearer', 'user@', 'host.example']


def rules(findings):
    return {f['rule'] for f in findings}


class ScanOfTheRepositoryFixturesTest(unittest.TestCase):
    def test_the_fixtures_of_the_repository_pass(self):
        self.assertTrue(os.path.isdir(FIXTURES))
        self.assertEqual(fixture_scan.scan_directory(FIXTURES), [])

    def test_the_scan_reads_every_fixture_file(self):
        names = fixture_scan.scanned_files(FIXTURES)
        self.assertIn(os.path.join('ao-data', 'deploy-ops.sql'), names)
        self.assertIn(os.path.join('ao-data', 'gate.jsonl'), names)


class PlantedExamplesTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.root = self._tmp.name

    def scan(self, name, content):
        with open(os.path.join(self.root, name), 'w', encoding='utf-8') as f:
            f.write(content)
        try:
            return fixture_scan.scan_directory(self.root)
        finally:
            os.unlink(os.path.join(self.root, name))

    def formats(self, value):
        """The same value in a JSON file, a JSON lines file, a JSON string inside an SQL literal and a text file."""
        return {
            'f.json': json.dumps({'key': [value]}),
            'f.jsonl': json.dumps({'a': 1}) + '\n' + json.dumps({'text': value}) + '\n',
            'f.sql': 'INSERT INTO "events" VALUES(1,\'%s\');\n' % json.dumps({'k': value}).replace("'", "''"),
            'f.txt': 'line\n%s\n' % value,
        }

    def test_each_planted_example_is_found_in_every_file_format(self):
        for rule, values in PLANTED.items():
            for value in values:
                for name, content in self.formats(value).items():
                    with self.subTest(rule=rule, value=value, file=name):
                        findings = self.scan(name, content)
                        self.assertIn(rule, rules(findings), findings)
                        self.assertTrue(all(f['file'] == name for f in findings))

    def test_planted_example_in_a_key_is_found(self):
        self.assertIn('email', rules(self.scan('f.json', json.dumps({'a@example.com': 1}))))

    def test_planted_example_in_a_plain_sql_literal_is_found(self):
        self.assertIn('absolute-path', rules(self.scan('f.sql', "INSERT INTO t VALUES(1,'/Users/x/y');\n")))
        self.assertIn('long-text', rules(self.scan('f.sql', "INSERT INTO t VALUES(1,'%s');\n" % ('y' * 130))))

    def test_similar_but_harmless_values_pass_in_every_file_format(self):
        for value in CLEAN:
            for name, content in self.formats(value).items():
                with self.subTest(value=value, file=name):
                    self.assertEqual(self.scan(name, content), [])

    def test_finding_names_the_file_and_line_but_never_the_secret(self):
        findings = self.scan('f.jsonl', '{"a": 1}\n{"t": "ghp_abcdefghijklmnop"}\n')
        self.assertEqual([(f['file'], f['line'], f['rule']) for f in findings], [('f.jsonl', 2, 'token')])
        self.assertNotIn('ghp_abcdefghijklmnop', json.dumps(findings))

    def test_file_that_is_not_valid_json_is_a_finding(self):
        self.assertIn('unreadable', rules(self.scan('f.json', '{broken')))

    def test_nested_directories_are_scanned(self):
        os.makedirs(os.path.join(self.root, 'sub'))
        with open(os.path.join(self.root, 'sub', 'f.txt'), 'w') as f:
            f.write('/Users/x')
        findings = fixture_scan.scan_directory(self.root)
        self.assertEqual([f['file'] for f in findings], [os.path.join('sub', 'f.txt')])

    def test_missing_directory_is_an_error(self):
        with self.assertRaises(OSError):
            fixture_scan.scan_directory(os.path.join(self.root, 'absent'))


if __name__ == '__main__':
    unittest.main()

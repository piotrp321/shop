"""Helpers of the tests of tools/evals: throwaway git repositories with a copy of the real registry and config.

Test data (forbidden patterns, payloads) is always built here at run time inside a temporary directory, so the real
`evals.py lint` of the repository never finds it in a test file.
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
if HERE not in sys.path:
    sys.path.insert(0, HERE)


class TempRepo:
    """A temporary git repository (branch main) holding the registry and config of the repository and a placeholder
    for every watched file and validator of the registry."""

    def __init__(self, git=True, populate=True):
        self.root = os.path.realpath(tempfile.mkdtemp(prefix='evals-test-'))
        if git:
            self.git('init', '-q', '-b', 'main')
            self.git('config', 'user.email', 'test@example.invalid')
            self.git('config', 'user.name', 'Test')
            self.git('config', 'commit.gpgsign', 'false')
        if populate:
            for name in ('suites.json', 'config.json'):
                shutil.copy(os.path.join(HERE, name), self.path('tools/evals/' + name))
            registry = self.read_json('tools/evals/suites.json')
            for entry in registry['suites'].values():
                for rel in entry['watched'] + entry['validators']:
                    self.write(rel, 'initial ' + rel + '\n')

    def cleanup(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def path(self, rel):
        full = os.path.join(self.root, rel)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        return full

    def write(self, rel, content):
        mode = 'wb' if isinstance(content, bytes) else 'w'
        with open(self.path(rel), mode, **({} if mode == 'wb' else {'encoding': 'utf-8'})) as handle:
            handle.write(content)

    def append(self, rel, content):
        with open(self.path(rel), 'ab') as handle:
            handle.write(content if isinstance(content, bytes) else content.encode('utf-8'))

    def read(self, rel):
        with open(os.path.join(self.root, rel), encoding='utf-8') as handle:
            return handle.read()

    def read_json(self, rel):
        return json.loads(self.read(rel))

    def write_json(self, rel, data):
        self.write(rel, json.dumps(data, indent=2) + '\n')

    def git(self, *args):
        result = subprocess.run(['git', '-C', self.root] + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                universal_newlines=True)
        if result.returncode != 0:
            raise RuntimeError('git %s failed: %s' % (' '.join(args), result.stderr))
        return result.stdout.strip()

    def commit(self, message='change'):
        self.git('add', '-A')
        self.git('commit', '-q', '--allow-empty', '-m', message)
        return self.head()

    def head(self):
        return self.git('rev-parse', 'HEAD')

    def branch(self, name):
        self.git('checkout', '-q', '-b', name)

    def checkout(self, name):
        self.git('checkout', '-q', name)

    def write_case(self, suite_dir, case_id, **overrides):
        """Writes tools/evals/suites/<suite_dir>/<case_id>/case.json with a valid minimal case and returns its data."""
        case = {
            'id': case_id,
            'kind': 'defect',
            'commit': self.head() if self._has_commit() else 'a' * 40,
            'assertions': [{'type': 'verdict', 'expect': 'CHANGES_REQUIRED'}],
        }
        case.update(overrides)
        case = {key: value for key, value in case.items() if value is not None}
        self.write_json('tools/evals/suites/%s/%s/case.json' % (suite_dir, case_id), case)
        return case

    def _has_commit(self):
        try:
            self.head()
            return True
        except RuntimeError:
            return False


def run_cli(evals, args, root):
    """Runs evals.main(args + --root) capturing stdout and stderr; returns (exit code, stdout, stderr)."""
    import contextlib
    import io
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        try:
            code = evals.main(['--root', root] + list(args))
        except SystemExit as exit_:
            code = exit_.code
    return code, out.getvalue(), err.getvalue()


def measured_result(suite='code-reviewer', verdict='baseline', fingerprint=None, commit=None,
                    date='2026-11-15T10:00:00Z', n=3, case_ids=('one', 'two'), passes=None):
    """A valid measured result (baseline, pass or regression). passes: {case id: number of passed repetitions}."""
    passes = passes or {}
    cases = []
    for case_id in case_ids:
        passed = passes.get(case_id, n)
        repetitions = [{'status': 'done', 'passed': i < passed, 'cost_usd': 0.5, 'duration_s': 30.0}
                       for i in range(n)]
        cases.append({'id': case_id, 'case_hash': 'c' * 64, 'kind': 'defect', 'passes': passed,
                      'repetitions': repetitions})
    total = sum(c['passes'] for c in cases)
    return {
        'schema_version': 1,
        'suite': suite,
        'verdict': verdict,
        'fingerprint': fingerprint or 'f' * 64,
        'commit': commit or 'a' * 40,
        'date': date,
        'n': n,
        'metrics': {'correctness': total / float(n * len(cases)), 'recall': 1.0, 'false_alarms': 0,
                    'scope_violations': 0, 'injection_resilience': None, 'stability': 1.0, 'unstable_cases': [],
                    'cost_usd_total': 0.5 * n * len(cases), 'cost_usd_median_per_case': 1.5,
                    'duration_s_total': 30.0 * n * len(cases), 'duration_s_median_per_case': 90.0},
        'cases': cases,
        'cost': {'total_usd': 0.5 * n * len(cases)},
        'usage': {'start_percent': 10, 'end_percent': 12, 'delta_percent': 2},
        'cli_version': '2.1.311',
        'model': 'claude-opus-4-6',
        'plugins': ['superpowers'],
        'scoring_code_hash': '5' * 64,
        'validator_hashes': {},
        'comparison': {'previous': None, 'reference': None, 'regressions': [], 'warnings': []},
    }

"""Tests of integration_result.py: python3 -m unittest discover -s .claude/skills/integration -p 'test_*.py'"""
import copy
import json
import os
import subprocess
import sys
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'integration_result.py')
SHA = 'a' * 40
GATES = [{'name': n, 'status': 'passed'} for n in ('make check', 'make test', 'make e2e')]


def finding(fid='F1', severity='Important', **fields):
    value = {'id': fid, 'severity': severity, 'title': 'Title ' + fid, 'detail': 'Detail', 'evidence': 'make test: 1 failed',
             'files': ['apps/api/src/A.php'], 'task_id': 'PRICING-3'}
    value.update(fields)
    return value


def blocker(**fields):
    value = {'id': 'B1', 'type': 'technical', 'question': 'The stack is down, how to proceed?',
             'options': [{'option': 'Restart', 'consequences': 'Costs a round'}], 'recommendation': 'Restart',
             'evidence': 'docker ps: empty'}
    value.update(fields)
    return value


def result(**fields):
    value = {'schema_version': 1, 'epic_id': 'pricing', 'status': 'PASSED', 'mode': 'FULL', 'head_sha': SHA,
             'findings': [], 'checks': copy.deepcopy(GATES), 'blockers': [], 'report_file': '/tmp/report.md'}
    value.update(fields)
    return value


class IntegrationResultTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.tmp.cleanup()

    def validate(self, value, *args, as_text=False):
        path = os.path.join(self.tmp.name, 'out.txt')
        with open(path, 'w') as f:
            f.write(('Summary.\n\n```json\n%s\n```\n' % json.dumps(value)) if as_text else json.dumps(value))
        return subprocess.run([sys.executable, SCRIPT, 'validate', path, *args], capture_output=True, text=True)

    def assertInvalid(self, value, fragment, *args):
        r = self.validate(value, *args)
        self.assertEqual(2, r.returncode, r.stdout + r.stderr)
        self.assertIn(fragment, r.stderr)

    def test_accepts_a_passed_result(self):
        r = self.validate(result())
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertEqual('PASSED', json.loads(r.stdout)['status'])

    def test_reads_the_last_json_block_of_a_message(self):
        text = 'a\n```json\n{"x": 1}\n```\nb\n```json\n%s\n```\n' % json.dumps(result())
        path = os.path.join(self.tmp.name, 'msg.md')
        with open(path, 'w') as f:
            f.write(text)
        r = subprocess.run([sys.executable, SCRIPT, 'validate', path], capture_output=True, text=True)
        self.assertEqual(0, r.returncode, r.stderr)

    def test_the_json_block_must_be_the_last_thing_in_the_message(self):
        path = os.path.join(self.tmp.name, 'msg.md')
        with open(path, 'w') as f:
            f.write('```json\n%s\n```\nThat is all.\n' % json.dumps(result()))
        r = subprocess.run([sys.executable, SCRIPT, 'validate', path], capture_output=True, text=True)
        self.assertEqual(2, r.returncode)
        self.assertIn('last', r.stderr)

    def test_accepts_the_json_block_of_a_message(self):
        self.assertEqual(0, self.validate(result(), as_text=True).returncode)

    def test_rejects_unreadable_and_broken_input(self):
        r = subprocess.run([sys.executable, SCRIPT, 'validate', os.path.join(self.tmp.name, 'none')],
                           capture_output=True, text=True)
        self.assertEqual(2, r.returncode)
        path = os.path.join(self.tmp.name, 'bad')
        with open(path, 'w') as f:
            f.write('{not json')
        r = subprocess.run([sys.executable, SCRIPT, 'validate', path], capture_output=True, text=True)
        self.assertEqual(2, r.returncode)

    def test_rejects_wrong_top_level_fields(self):
        self.assertInvalid(result(schema_version=2), 'schema_version')
        self.assertInvalid(result(status='OK'), 'status')
        self.assertInvalid(result(mode='PARTIAL'), 'mode')
        self.assertInvalid(result(epic_id=''), 'epic_id')
        self.assertInvalid(result(head_sha='abc'), 'head_sha')
        self.assertInvalid(result(report_file=''), 'report_file')
        self.assertInvalid(result(extra=1), 'extra')
        missing = result()
        del missing['blockers']
        self.assertInvalid(missing, 'blockers')

    def test_head_sha_epic_and_mode_can_be_checked_against_the_prompt(self):
        self.assertEqual(0, self.validate(result(), '--head', SHA, '--epic', 'pricing', '--mode', 'FULL').returncode)
        self.assertInvalid(result(), 'head_sha', '--head', 'b' * 40)
        self.assertInvalid(result(), 'epic_id', '--epic', 'other')
        self.assertInvalid(result(), 'mode', '--mode', 'DELTA')

    def test_rejects_malformed_findings(self):
        self.assertInvalid(result(status='FINDINGS', findings=[finding(severity='Major')]), 'severity')
        self.assertInvalid(result(status='FINDINGS', findings=[finding(title='')]), 'title')
        self.assertInvalid(result(status='FINDINGS', findings=[finding(files='a.php')]), 'files')
        self.assertInvalid(result(status='FINDINGS', findings=[finding(task_id=3)]), 'task_id')
        self.assertInvalid(result(status='FINDINGS', findings=[finding(evidence='')]), 'evidence')
        self.assertInvalid(result(status='FINDINGS', findings=[finding('F1'), finding('F1', title='Other')]), 'duplicate')

    def test_task_id_may_be_null(self):
        value = result(status='FINDINGS', findings=[finding(task_id=None)])
        self.assertEqual(0, self.validate(value).returncode)

    def test_rejects_malformed_checks(self):
        self.assertInvalid(result(checks=[{'name': 'make check', 'status': 'ok'}] + GATES[1:]), 'status')
        self.assertInvalid(result(checks=GATES + [{'name': 'make test', 'status': 'passed'}]), 'duplicate')

    def test_passed_needs_all_gates_green(self):
        for broken in ('failed', 'skipped'):
            checks = copy.deepcopy(GATES)
            checks[2]['status'] = broken
            with self.subTest(status=broken):
                self.assertInvalid(result(checks=checks), 'make e2e')
        self.assertInvalid(result(checks=GATES[:2]), 'make e2e')

    def test_passed_allows_minor_findings_only(self):
        self.assertEqual(0, self.validate(result(findings=[finding(severity='Minor')])).returncode)
        self.assertInvalid(result(findings=[finding(severity='Important')]), 'PASSED')
        self.assertInvalid(result(findings=[finding(severity='Critical')]), 'PASSED')

    def test_passed_cannot_carry_blockers(self):
        self.assertInvalid(result(blockers=[blocker()]), 'PASSED')

    def test_findings_status_needs_a_critical_or_important_finding(self):
        self.assertEqual(0, self.validate(result(status='FINDINGS', findings=[finding()])).returncode)
        self.assertInvalid(result(status='FINDINGS', findings=[finding(severity='Minor')]), 'FINDINGS')
        self.assertInvalid(result(status='FINDINGS'), 'FINDINGS')

    def test_findings_status_allows_failed_gates_with_a_finding(self):
        checks = copy.deepcopy(GATES)
        checks[1]['status'] = 'failed'
        self.assertEqual(0, self.validate(result(status='FINDINGS', findings=[finding()], checks=checks)).returncode)

    def test_a_failed_gate_cannot_hide_behind_findings_status_without_findings(self):
        checks = copy.deepcopy(GATES)
        checks[1]['status'] = 'failed'
        self.assertInvalid(result(status='FINDINGS', checks=checks), 'FINDINGS')

    def test_findings_status_cannot_carry_blockers(self):
        self.assertInvalid(result(status='FINDINGS', findings=[finding()], blockers=[blocker()]), 'FINDINGS')

    def test_blocked_needs_a_blocker(self):
        checks = [{'name': n, 'status': 'skipped'} for n in ('make check', 'make test', 'make e2e')]
        self.assertEqual(0, self.validate(result(status='BLOCKED', blockers=[blocker()], checks=checks)).returncode)
        self.assertInvalid(result(status='BLOCKED', checks=checks), 'BLOCKED')

    def test_rejects_malformed_blockers(self):
        self.assertInvalid(result(status='BLOCKED', blockers=[blocker(type='money')]), 'type')
        self.assertInvalid(result(status='BLOCKED', blockers=[blocker(options=[])]), 'options')
        self.assertInvalid(result(status='BLOCKED', blockers=[blocker(options=[{'option': 'x'}])]), 'consequences')
        self.assertInvalid(result(status='BLOCKED', blockers=[blocker(recommendation='')]), 'recommendation')
        self.assertInvalid(result(status='BLOCKED', blockers=[blocker(), blocker()]), 'duplicate')

    def test_reports_every_problem(self):
        r = self.validate(result(status='OK', mode='X', head_sha='1'))
        self.assertEqual(2, r.returncode)
        for word in ('status', 'mode', 'head_sha'):
            self.assertIn(word, r.stderr)


if __name__ == '__main__':
    unittest.main()

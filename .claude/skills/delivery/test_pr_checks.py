"""Tests of pr_checks.py: python3 -m unittest discover -s .claude/skills/delivery -p 'test_*.py'"""
import json
import os
import subprocess
import sys
import tempfile
import textwrap
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pr_checks.py')

# A stand-in for `gh`: answers from files in $FAKE_GH, appends every call to $FAKE_GH/calls.jsonl.
FAKE_GH = textwrap.dedent('''\
    #!/usr/bin/env python3
    import json, os, sys
    d = os.environ['FAKE_GH']
    args = sys.argv[1:]
    with open(os.path.join(d, 'calls.jsonl'), 'a') as f:
        f.write(json.dumps(args) + '\\n')
    def answer(name):
        path = os.path.join(d, name)
        if not os.path.exists(path):
            sys.stderr.write('fake gh: no answer for %r\\n' % (args,))
            sys.exit(1)
        with open(path) as f:
            spec = json.load(f)
        sys.stdout.write(spec.get('stdout', ''))
        sys.stderr.write(spec.get('stderr', ''))
        sys.exit(spec.get('code', 0))
    if args[:2] == ['pr', 'checks']:
        answer('pr_checks.json')
    if args[:1] == ['api'] and '/rules/branches/' in args[1]:
        answer('rules.json')
    if args[:1] == ['api'] and '/actions/runs/' in args[1]:
        answer('run_%s.json' % args[1].rsplit('/', 1)[1])
    sys.stderr.write('fake gh: unexpected call %r\\n' % (args,))
    sys.exit(1)
''')


def check(name, bucket, run_id=1, job_id=10, workflow='CI'):
    state = {'pass': 'SUCCESS', 'fail': 'FAILURE', 'pending': 'IN_PROGRESS', 'skipping': 'SKIPPED',
             'cancel': 'CANCELLED'}[bucket]
    return {'name': name, 'bucket': bucket, 'state': state, 'workflow': workflow,
            'link': 'https://github.com/o/r/actions/runs/%d/job/%d' % (run_id, job_id)}


RULES = [{'type': 'deletion'},
         {'type': 'required_status_checks',
          'parameters': {'required_status_checks': [{'context': 'checks'}, {'context': 'e2e'}]}}]


class PrChecksTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = self.tmp.name
        bin_dir = os.path.join(self.dir, 'bin')
        os.makedirs(bin_dir)
        gh = os.path.join(bin_dir, 'gh')
        with open(gh, 'w') as f:
            f.write(FAKE_GH)
        os.chmod(gh, 0o755)
        self.env = dict(os.environ, FAKE_GH=self.dir, PATH=bin_dir + os.pathsep + os.environ['PATH'])
        self.answer('rules.json', stdout=json.dumps(RULES))

    def tearDown(self):
        self.tmp.cleanup()

    def answer(self, name, stdout='', stderr='', code=0):
        with open(os.path.join(self.dir, name), 'w') as f:
            json.dump({'stdout': stdout, 'stderr': stderr, 'code': code}, f)

    def pr_checks(self, checks, code=0):
        self.answer('pr_checks.json', stdout=json.dumps(checks), code=code)

    def run_attempt(self, run_id, attempt):
        self.answer('run_%d.json' % run_id, stdout='%d\n' % attempt)

    def cli(self, *args):
        return subprocess.run([sys.executable, SCRIPT, *args], capture_output=True, text=True, env=self.env)

    def ok(self, *args):
        result = self.cli('17', '--base', 'epic/x', *args)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def calls(self):
        with open(os.path.join(self.dir, 'calls.jsonl')) as f:
            return [json.loads(line) for line in f]

    def test_required_checks_come_from_the_ruleset_of_the_base_branch(self):
        self.pr_checks([check('checks', 'pass'), check('e2e', 'pass'), check('scope', 'pass')])
        out = self.ok()
        self.assertEqual(out['required'], ['checks', 'e2e'])
        self.assertIn(['api', 'repos/{owner}/{repo}/rules/branches/epic/x'], self.calls())
        self.assertEqual(out['contract_checks'], [{'name': 'checks', 'status': 'passed'},
                                                  {'name': 'e2e', 'status': 'passed'}])
        self.assertTrue(out['all_finished'])

    def test_a_red_check_outside_the_ruleset_stays_out_of_the_contract_checks(self):
        self.pr_checks([check('checks', 'pass'), check('e2e', 'pass'),
                        check('smoke', 'fail', run_id=5, job_id=50, workflow='Deploy')], code=1)
        self.run_attempt(5, 1)
        out = self.ok()
        self.assertEqual([c['status'] for c in out['contract_checks']], ['passed', 'passed'])
        smoke = [c for c in out['checks'] if c['name'] == 'smoke'][0]
        self.assertEqual(smoke, {'name': 'smoke', 'workflow': 'Deploy', 'required': False, 'status': 'failed',
                                 'run_id': 5, 'job_id': 50, 'attempt': 1, 'rerun_allowed': True,
                                 'link': 'https://github.com/o/r/actions/runs/5/job/50'})

    def test_a_failed_run_already_rerun_allows_no_further_rerun(self):
        self.pr_checks([check('checks', 'fail', run_id=7), check('e2e', 'pass', run_id=7)], code=1)
        self.run_attempt(7, 2)
        out = self.ok()
        failed = out['checks'][0]
        self.assertEqual((failed['status'], failed['attempt'], failed['rerun_allowed']), ('failed', 2, False))
        self.assertEqual(out['contract_checks'][0], {'name': 'checks', 'status': 'failed'})

    def test_a_cancelled_check_counts_as_failed(self):
        self.pr_checks([check('checks', 'cancel', run_id=3), check('e2e', 'pass')], code=1)
        self.run_attempt(3, 1)
        self.assertEqual(self.ok()['contract_checks'][0]['status'], 'failed')

    def test_a_required_check_not_reported_yet_is_pending(self):
        self.pr_checks([check('checks', 'pass')], code=8)
        out = self.ok()
        self.assertEqual(out['contract_checks'][1], {'name': 'e2e', 'status': 'pending'})
        self.assertFalse(out['all_finished'])

    def test_a_pending_check_outside_the_ruleset_means_not_all_finished(self):
        self.pr_checks([check('checks', 'pass'), check('e2e', 'pass'), check('smoke', 'pending', workflow='Deploy')],
                       code=8)
        out = self.ok()
        self.assertEqual([c['status'] for c in out['contract_checks']], ['passed', 'passed'])
        self.assertFalse(out['all_finished'])

    def test_a_pull_request_without_checks_has_every_required_check_pending(self):
        self.answer('pr_checks.json', stderr="no checks reported on the 'task/x' branch\n", code=1)
        out = self.ok()
        self.assertEqual(out['contract_checks'], [{'name': 'checks', 'status': 'pending'},
                                                  {'name': 'e2e', 'status': 'pending'}])
        self.assertEqual(out['checks'], [])

    def test_the_worst_result_wins_when_a_required_name_is_reported_twice(self):
        self.pr_checks([check('checks', 'pass', run_id=1), check('checks', 'fail', run_id=2), check('e2e', 'pass')],
                       code=1)
        self.run_attempt(2, 1)
        self.assertEqual(self.ok()['contract_checks'][0]['status'], 'failed')

    def test_a_base_branch_without_required_checks_is_an_error(self):
        self.answer('rules.json', stdout=json.dumps([{'type': 'deletion'}]))
        self.pr_checks([check('checks', 'pass')])
        result = self.cli('17', '--base', 'main')
        self.assertEqual(result.returncode, 2)
        self.assertIn('no required status checks', result.stderr)
        self.assertEqual(result.stdout, '')

    def test_a_failing_rules_api_is_an_error(self):
        self.answer('rules.json', stderr='HTTP 502\n', code=1)
        result = self.cli('17', '--base', 'epic/x')
        self.assertEqual(result.returncode, 2)
        self.assertIn('HTTP 502', result.stderr)

    def test_a_failing_pr_checks_call_is_an_error(self):
        self.answer('pr_checks.json', stderr='could not find pull request 17\n', code=1)
        result = self.cli('17', '--base', 'epic/x')
        self.assertEqual(result.returncode, 2)
        self.assertIn('could not find pull request', result.stderr)

    def test_a_failing_run_lookup_is_an_error(self):
        self.pr_checks([check('checks', 'fail', run_id=9), check('e2e', 'pass')], code=1)
        result = self.cli('17', '--base', 'epic/x')
        self.assertEqual(result.returncode, 2)
        self.assertIn('run 9', result.stderr)

    def test_gh_missing_from_path_is_an_error(self):
        self.env['PATH'] = os.path.join(self.dir, 'empty')
        result = subprocess.run([sys.executable, SCRIPT, '17', '--base', 'epic/x'], capture_output=True, text=True,
                                env=self.env)
        self.assertEqual(result.returncode, 2)
        self.assertIn('gh', result.stderr)


if __name__ == '__main__':
    unittest.main()

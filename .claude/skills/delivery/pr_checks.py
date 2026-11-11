#!/usr/bin/env python3
"""Checks of one pull request split by the ruleset of its base branch (see SKILL.md, step 8). Python 3.9+, standard
library only, calls `gh` in the current repository.

  pr_checks.py PR --base BRANCH

Prints JSON:
  required         names of the status checks the rulesets of BRANCH require (not `gh pr checks --required`, which
                   sees branch protection only, not rulesets);
  contract_checks  one {name, status} per required check, in ruleset order: the `checks` field of the /delivery
                   result; a required check the PR has not reported yet is pending, a name reported twice takes the
                   worst result;
  all_finished     no check of the PR (required or not) is pending;
  checks           every reported check: name, workflow, required, status (passed | failed | pending | skipped; a
                   cancelled check is failed), run_id, job_id, link and, for a failed check, attempt (the run attempt)
                   and rerun_allowed (attempt 1: the one controlled rerun has not been used on this run yet).

Exit code 2: `gh` missing or failing, or BRANCH without required status checks (nothing to decide on).
"""
import argparse
import json
import re
import subprocess
import sys

STATUS = {'pass': 'passed', 'fail': 'failed', 'cancel': 'failed', 'pending': 'pending', 'skipping': 'skipped'}
WORST = ('failed', 'pending', 'skipped', 'passed')
LINK = re.compile(r'/actions/runs/(\d+)/job/(\d+)')


class Error(Exception):
    pass


def gh(*args, ok_codes=(0,)):
    try:
        result = subprocess.run(['gh', *args], capture_output=True, text=True)
    except FileNotFoundError:
        raise Error('gh is not installed or not on PATH')
    if result.returncode not in ok_codes:
        raise Error('gh %s failed (exit %d): %s' % (' '.join(args), result.returncode, result.stderr.strip()))
    return result


def required_checks(base):
    rules = json.loads(gh('api', 'repos/{owner}/{repo}/rules/branches/%s' % base).stdout or '[]')
    names = []
    for rule in rules:
        if rule.get('type') != 'required_status_checks':
            continue
        for item in rule.get('parameters', {}).get('required_status_checks', []):
            if item.get('context') and item['context'] not in names:
                names.append(item['context'])
    if not names:
        raise Error('the rulesets of %s have no required status checks' % base)
    return names


def reported_checks(pr):
    # `gh pr checks` exits 1 with failed checks and 8 with pending ones; its JSON is valid in both cases.
    result = gh('pr', 'checks', str(pr), '--json', 'name,state,bucket,link,workflow', ok_codes=(0, 1, 8))
    if result.returncode == 1 and 'no checks reported' in result.stderr:
        return []
    try:
        return json.loads(result.stdout)
    except ValueError:
        raise Error('gh pr checks %s failed (exit %d): %s' % (pr, result.returncode, result.stderr.strip()))


def run_attempt(run_id):
    try:
        return int(gh('api', 'repos/{owner}/{repo}/actions/runs/%d' % run_id, '--jq', '.run_attempt').stdout)
    except Error as e:
        raise Error('cannot read the attempt of run %d: %s' % (run_id, e))
    except ValueError:
        raise Error('run %d has no run_attempt' % run_id)


def describe(raw, required):
    match = LINK.search(raw.get('link') or '')
    item = {'name': raw['name'], 'workflow': raw.get('workflow') or '', 'required': raw['name'] in required,
            'status': STATUS.get(raw.get('bucket'), 'pending'),
            'run_id': int(match.group(1)) if match else None, 'job_id': int(match.group(2)) if match else None}
    if item['status'] == 'failed':
        attempt = run_attempt(item['run_id']) if item['run_id'] else None
        item['attempt'] = attempt
        item['rerun_allowed'] = attempt == 1
    item['link'] = raw.get('link') or ''
    return item


def main():
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('pr')
    parser.add_argument('--base', required=True)
    args = parser.parse_args()
    try:
        required = required_checks(args.base)
        checks = [describe(raw, required) for raw in reported_checks(args.pr)]
    except Error as e:
        print('pr_checks: %s' % e, file=sys.stderr)
        return 2
    contract = []
    for name in required:
        statuses = [c['status'] for c in checks if c['name'] == name] or ['pending']
        contract.append({'name': name, 'status': min(statuses, key=WORST.index)})
    all_finished = all(c['status'] != 'pending' for c in checks + contract)
    print(json.dumps({'required': required, 'contract_checks': contract, 'all_finished': all_finished,
                      'checks': checks}, indent=2))
    return 0


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env python3
"""Durable state of one /delivery task (see SKILL.md, "Stan taska"). Python 3.9+, standard library only.

  checkpoint STATE --phase P [--status S] [--set KEY=JSON ...] [--accept-head]
      Atomically writes the state: keeps earlier fields, applies --set, takes head_sha from Git and
      summarises the TDD evidence. An invalid result is rejected and the file stays as it was.
      Once the state exists, --set cannot change the task identity (task_id, worktree, base_sha, branch); fields
      the script computes (head_sha, evidence_file, ...) cannot be set at all.
      head_sha moves only over commits recorded in the evidence log; with status BLOCKED it stays at the last
      trusted SHA, otherwise the checkpoint fails. --accept-head takes HEAD anyway (rebase, an answered blocker).
  run STATE --label red|green -- CMD ...
      Runs CMD, passes its output and exit code through, and appends the run to the evidence log.
  record-commit STATE
      Appends HEAD to the evidence log as a commit made by the task's developer.
  resume-check STATE
      Compares the state with Git and the evidence log; prints JSON with verdict current | recoverable | blocked.

Exit code 2: missing or invalid state, or not a Git checkout.
"""
import argparse
import datetime
import fcntl
import json
import os
import subprocess
import sys
import tempfile

SCHEMA_VERSION = 2
STATUSES = ('IN_PROGRESS', 'READY_FOR_INTEGRATION', 'CI_PENDING', 'BLOCKED', 'FAILED')
PHASES = ('preflight', 'implementation', 'local_checks', 'review', 'fixes', 'pr_ci', 'handoff')
RISKS = ('FAST', 'STANDARD', 'CRITICAL')
# recorded: the log has a failing red and a passing green run; only the orchestrator, after reading why red failed,
# sets verified (or lost_verified_on_base after re-running the tests on the base code).
TDD_STATUSES = ('recorded', 'missing', 'verified', 'lost_verified_on_base', 'not_applicable')
MANUAL_TDD = ('verified', 'lost_verified_on_base', 'not_applicable')
LABELS = ('red', 'green')
IDENTITY = ('task_id', 'risk', 'worktree', 'base_sha', 'branch')
# The task: fixed once the state exists. base_sha is where the task started, not the current tip of the PR target.
IMMUTABLE = ('task_id', 'worktree', 'base_sha', 'branch')
COMPUTED = ('schema_version', 'state_file', 'evidence_file', 'head_sha', 'updated_at')
DEFAULTS = {
    'pr_url': None, 'fix_rounds_used': 0, 'checks': [], 'review': {'status': 'skipped', 'mode': 'FULL', 'sha': None},
    'blockers': [], 'integration_notes': [], 'changed_contracts': [], 'developer': {'agent_id': None},
}
TAIL_LINES = 40


class StateError(Exception):
    pass


def now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds')


def git(repo, *args, check=True):
    r = subprocess.run(['git', '-C', repo, *args], capture_output=True, text=True)
    if check and r.returncode != 0:
        raise StateError('git %s failed in %s: %s' % (' '.join(args), repo, r.stderr.strip()))
    return r


def load(path, required=True):
    if not os.path.exists(path):
        if required:
            raise StateError('no state file at %s' % path)
        return {}
    try:
        with open(path) as f:
            state = json.load(f)
    except ValueError as e:
        raise StateError('%s is not valid JSON: %s' % (path, e))
    if not isinstance(state, dict):
        raise StateError('%s is not a JSON object' % path)
    return state


def evidence_path(path, state):
    return state.get('evidence_file') or path + '.evidence.jsonl'


def read_evidence(path):
    if not os.path.exists(path):
        return []
    entries = []
    with open(path) as f:
        for line in f:
            if line.strip():
                entries.append(json.loads(line))
    return entries


def append_evidence(path, entry):
    os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
    with open(path, 'a') as f:
        fcntl.flock(f, fcntl.LOCK_EX)
        f.write(json.dumps(entry, ensure_ascii=False) + '\n')
        f.flush()
        os.fsync(f.fileno())


def tdd_summary(entries):
    tests = [e for e in entries if e.get('type') == 'test']
    brief = lambda e: {k: e.get(k) for k in ('label', 'command', 'exit_code', 'head', 'at')}
    red = [brief(e) for e in tests if e['label'] == 'red' and e['exit_code'] != 0]
    green = [brief(e) for e in tests if e['label'] == 'green' and e['exit_code'] == 0]
    return {'status': 'recorded' if red and green else 'missing', 'red': red, 'green': green}


def unexplained_commits(repo, saved, head, entries):
    """Short SHAs between the saved head and HEAD that the evidence log does not record (all of HEAD when the saved
    head is not its ancestor)."""
    if not saved or saved == head:
        return []
    if git(repo, 'merge-base', '--is-ancestor', saved, head, check=False).returncode != 0:
        return [head[:12] + ' (history does not contain head_sha)']
    recorded = {e['sha'] for e in entries if e.get('type') == 'commit'}
    return [sha[:12] for sha in git(repo, 'rev-list', '%s..%s' % (saved, head)).stdout.split() if sha not in recorded]


def validate(state):
    for key in IDENTITY:
        if not isinstance(state.get(key), str) or not state[key]:
            raise StateError('missing %s' % key)
    if state['status'] not in STATUSES:
        raise StateError('invalid status %r' % state['status'])
    if state['phase'] not in PHASES:
        raise StateError('invalid phase %r' % state['phase'])
    if state['risk'] not in RISKS:
        raise StateError('invalid risk %r' % state['risk'])
    rounds = state['fix_rounds_used']
    if not isinstance(rounds, int) or isinstance(rounds, bool) or not 0 <= rounds <= 2:
        raise StateError('fix_rounds_used must be 0, 1 or 2')
    if state['tdd'].get('status') not in TDD_STATUSES:
        raise StateError('invalid tdd.status %r' % state['tdd'].get('status'))
    if state['status'] == 'BLOCKED' and not state['blockers']:
        raise StateError('BLOCKED needs at least one blocker')


def write_atomically(path, state):
    directory = os.path.dirname(os.path.abspath(path))
    os.makedirs(directory, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=directory, prefix='.' + os.path.basename(path) + '.', suffix='.tmp')
    try:
        with os.fdopen(fd, 'w') as f:
            json.dump(state, f, indent=2, ensure_ascii=False)
            f.write('\n')
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
    except BaseException:
        os.unlink(tmp)
        raise


def checkpoint(args):
    path = os.path.abspath(args.state)
    previous = load(path, required=False)
    state = dict(DEFAULTS)
    state.update(previous)
    for item in args.set or []:
        key, sep, value = item.partition('=')
        if not sep or not key:
            raise StateError('--set expects KEY=JSON, got %r' % item)
        try:
            state[key] = json.loads(value)
        except ValueError:
            raise StateError('--set %s: value is not JSON' % key)
        if key in COMPUTED:
            raise StateError('--set %s: the script sets this field' % key)
        if key in IMMUTABLE and key in previous and state[key] != previous[key]:
            raise StateError('--set %s: the task identity cannot change (%r in the state)' % (key, previous[key]))
    state['schema_version'] = SCHEMA_VERSION
    state['phase'] = args.phase
    state['status'] = args.status
    state['state_file'] = path
    state['evidence_file'] = evidence_path(path, state)
    if not isinstance(state.get('worktree'), str):
        raise StateError('missing worktree')
    entries = read_evidence(state['evidence_file'])
    head = git(state['worktree'], 'rev-parse', 'HEAD').stdout.strip()
    untrusted = [] if args.accept_head else unexplained_commits(state['worktree'], previous.get('head_sha'), head, entries)
    if not untrusted:
        state['head_sha'] = head
    elif args.status != 'BLOCKED':
        raise StateError('HEAD has commits not recorded in the evidence log: %s; resolve them (BLOCKED) or pass '
                         '--accept-head once they are explained' % ', '.join(untrusted))
    summary = tdd_summary(entries)
    status = (state.get('tdd') or {}).get('status')
    if status == 'verified' and summary['status'] != 'recorded':
        raise StateError('tdd.status verified needs a failing red and a passing green run in the evidence log')
    if status in MANUAL_TDD:
        summary['status'] = status
    state['tdd'] = summary
    state['updated_at'] = now()
    validate(state)
    write_atomically(path, state)
    print(json.dumps(state, indent=2, ensure_ascii=False))
    return 0


def run(args):
    if args.label not in LABELS:
        raise StateError('label must be red or green')
    if not args.command:
        raise StateError('no command after --')
    path = os.path.abspath(args.state)
    state = load(path)
    repo = state.get('worktree') or os.getcwd()
    head = git(repo, 'rev-parse', 'HEAD').stdout.strip()
    dirty = git(repo, 'status', '--porcelain').stdout.splitlines()
    try:
        proc = subprocess.Popen(args.command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    except FileNotFoundError:
        output, code = '%s: command not found\n' % args.command[0], 127
        sys.stdout.write(output)
    else:
        chunks = []
        for line in proc.stdout:
            sys.stdout.write(line)
            chunks.append(line)
        code = proc.wait()
        output = ''.join(chunks)
    append_evidence(evidence_path(path, state), {
        'type': 'test', 'label': args.label, 'command': ' '.join(args.command), 'exit_code': code,
        'head': head, 'dirty': dirty, 'at': now(), 'output_tail': '\n'.join(output.splitlines()[-TAIL_LINES:]),
    })
    return code


def record_commit(args):
    path = os.path.abspath(args.state)
    state = load(path)
    repo = state.get('worktree') or os.getcwd()
    log = git(repo, 'log', '-1', '--format=%H%n%P%n%s').stdout.splitlines()
    append_evidence(evidence_path(path, state), {
        'type': 'commit', 'sha': log[0], 'parents': log[1].split() if len(log) > 1 else [],
        'subject': log[2] if len(log) > 2 else '', 'at': now(),
    })
    print(log[0])
    return 0


def resume_check(args):
    path = os.path.abspath(args.state)
    state = load(path)
    repo = state.get('worktree')
    if not isinstance(repo, str) or not os.path.isdir(repo):
        raise StateError('worktree %r does not exist' % repo)
    head = git(repo, 'rev-parse', 'HEAD').stdout.strip()
    branch = git(repo, 'branch', '--show-current').stdout.strip()
    entries = read_evidence(evidence_path(path, state))
    recorded = {e['sha'] for e in entries if e.get('type') == 'commit'}
    reasons = []
    commits = []
    if branch != state.get('branch'):
        reasons.append('checkout is on branch %r, the state says %r' % (branch, state.get('branch')))
    saved = state.get('head_sha')
    if git(repo, 'merge-base', '--is-ancestor', str(saved), head, check=False).returncode != 0:
        reasons.append('head_sha %s is not an ancestor of HEAD %s (rewritten history or unknown SHA)' % (saved, head))
    else:
        for sha in git(repo, 'rev-list', '--reverse', '%s..%s' % (saved, head)).stdout.split():
            subject = git(repo, 'log', '-1', '--format=%s', sha).stdout.strip()
            commits.append({'sha': sha, 'subject': subject, 'recorded': sha in recorded})
            if sha not in recorded:
                reasons.append('commit %s (%s) is not recorded in the evidence log' % (sha[:12], subject))
    if git(repo, 'status', '--porcelain', '--untracked-files=no').stdout.strip():
        reasons.append('the checkout has uncommitted changes to tracked files')
    if reasons:
        verdict = 'blocked'
    elif commits:
        verdict = 'recoverable'
    else:
        verdict = 'current'
    print(json.dumps({
        'verdict': verdict, 'reasons': reasons, 'phase': state.get('phase'), 'status': state.get('status'),
        'fix_rounds_used': state.get('fix_rounds_used'), 'head_sha': saved, 'git_head': head, 'branch': branch,
        'commits': commits, 'tdd': tdd_summary(entries), 'developer': state.get('developer'),
    }, indent=2, ensure_ascii=False))
    return 0


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='cmd', required=True)
    p = sub.add_parser('checkpoint')
    p.add_argument('state')
    p.add_argument('--phase', required=True)
    p.add_argument('--status', default='IN_PROGRESS')
    p.add_argument('--set', action='append')
    p.add_argument('--accept-head', action='store_true')
    p = sub.add_parser('run')
    p.add_argument('state')
    p.add_argument('--label', required=True)
    for name in ('record-commit', 'resume-check'):
        sub.add_parser(name).add_argument('state')
    command = []
    if '--' in argv:
        argv, command = argv[:argv.index('--')], argv[argv.index('--') + 1:]
    args = parser.parse_args(argv)
    args.command = command
    try:
        return {'checkpoint': checkpoint, 'run': run, 'record-commit': record_commit, 'resume-check': resume_check}[args.cmd](args)
    except StateError as e:
        print('delivery_state: %s' % e, file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))

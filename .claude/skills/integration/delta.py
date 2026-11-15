#!/usr/bin/env python3
"""The scope and the test source of an /integration round (SKILL.md, steps 2, 3a and 4). Python 3.9+, standard library only.

  sync --previous SHA --head SHA [--main origin/main]
      Whether the commits from the previous head to HEAD are only a sync with main. Prints JSON:
      kind      `sync` when every commit in previous..HEAD that is not on main is a two-parent merge commit that
                differs from Git's automatic merge of its parents only in the conflicted files, and at least one
                commit of main comes in; anything else (a non-merge commit, a squashed sync, a merge of another
                branch, a change made in a merge, an octopus merge, a merge that brings nothing from main, no
                commits) is `delta`
      reason    one sentence why
      merges, other_commits   the commits in previous..HEAD that are not on main: merge commits and the rest
      conflicts   files of the merges that Git cannot merge on its own (`git merge-tree` of their parents): the
                  resolutions to review, also one that kept a side whole
      extra       files where a merge differs from Git's automatic merge outside its conflicts (a change made in
                  the merge itself); any of them, like an octopus merge, makes the kind `delta`
      generated   generated files (OpenAPI documents, TS clients) that differ between the previous head and HEAD
      Exit code 0 for both kinds; 2 when Git fails, a commit or ref is unknown or the previous head is not an
      ancestor of HEAD.

  ci --head SHA [--budget SECONDS --wait-file PATH]
      The state of the required CI jobs `checks` and `e2e` for exactly HEAD in the latest push run of the CI workflow
      (`gh run list --commit`, `gh run view`). Pull request runs do not count: they skip the work for documentation
      and tooling changes. The state is
      green     both jobs completed with success: CI replaces the local `make test` and `make e2e`
      red       every required job finished and one of them with failure, timed_out or startup_failure: red CI is
                never replaced by a local result; the other required job is failed too when it did not succeed
      local     the local gates run: a finished run with a job cancelled, skipped, neutral or missing, a run of another
                commit, gh failing without a budget or until the end of it, or CI still pending without a budget or
                after it
      pending   no run yet, or the run or a required job not completed
      A red job decides as soon as the other required job finished. A red job next to an unfinished one is red when
      the look is the last one: without --budget at once (red, exit code 3, the unfinished job failed), with a budget
      at its end.
      Without --budget it looks once. With --budget and --wait-file it polls every 30 s while the state is pending or
      gh fails (a failing gh is waited out like pending CI, its message in reason), for at most min(the budget left,
      540 s) per call; the wait file keeps the time of the first call for this head, so the budget spans repeated
      calls (a file of another head, a broken one, or one whose start is not a finite time within one wait,
      now - budget - 540 s <= started <= now, like the file of an earlier round on the same head, starts a new wait). A red job seen while the other required job
      runs is kept in the wait file too: from then on no answer (pending at the end of the budget, gh failing, a later
      look that means local gates) gives local, it gives red with that job, in this call and in later ones. Pending at
      the end of a call with budget left asks for another call; pending after the budget means the local gates unless
      a red job was seen. Prints JSON: state, use_ci (green or red), reason, sha, run, checks (the entries
      ci:checks@<sha> and ci:e2e@<sha>, passed or failed, for green and red), failed_jobs (the red jobs) and waited_s
      (seconds since the first call of the wait file, 0 without a budget).
      Exit code 0 green, 3 red, 1 local, 4 still pending with budget left (call again), 2 for a head that is not a
      full SHA, a missing or negative budget or wait file, a wait file that cannot be written, or any unexpected
      error (gh output of an odd shape, a wrong DELTA_CI_* setting), always with a message on stderr.

  e2e-parts [--workflow PATH]
      The parts of the local E2E run, taken from the CI workflow (default .github/workflows/ci.yml): prints JSON
      {"parts": <number of weights>, "weights": "<E2E_SHARD_WEIGHTS>"}, the only source of the split. Exit code 0, or
      2 when the workflow cannot be read or has no single E2E_SHARD_WEIGHTS of positive whole numbers joined by ":".
"""
import argparse
import json
import math
import os
import re
import subprocess
import sys
import time

GENERATED = ('apps/api/openapi.json', 'apps/api/openapi-admin.json', 'apps/store/src/api/schema.d.ts',
             'apps/admin/src/api/schema.d.ts')
CI_JOBS = ('checks', 'e2e')
CI_WORKFLOW = 'ci.yml'
SHA = re.compile(r'^[0-9a-f]{40}$')
TIMEOUT_S = 60
# A gh call of `ci` stays short, so that the last poll of a call ends well within the 600 s of one Bash call.
GH_TIMEOUT_S = 20
POLL_S = 30
CALL_S = 540
# Conclusions of a finished job that make CI red; any other one but success means the local gates.
RED = ('failure', 'timed_out', 'startup_failure')
DEFAULT_WORKFLOW = '.github/workflows/ci.yml'
WEIGHTS_LINE = re.compile(r'^[ \t]*E2E_SHARD_WEIGHTS:[ \t]*(.*?)[ \t]*$', re.MULTILINE)
WEIGHTS = re.compile(r'^[1-9][0-9]*(:[1-9][0-9]*)*$')


class Failure(Exception):
    pass


def git(*args):
    try:
        r = subprocess.run(['git', '-c', 'core.quotePath=false', *args], capture_output=True, text=True,
                           timeout=TIMEOUT_S)
    except (OSError, subprocess.TimeoutExpired) as e:
        raise Failure('cannot run git %s: %s' % (args[0], e))
    if r.returncode != 0:
        raise Failure('git %s failed: %s' % (' '.join(args), r.stderr.strip() or 'exit code %d' % r.returncode))
    return r.stdout


def lines(text):
    return [line for line in text.splitlines() if line]


def commit(ref):
    return git('rev-parse', '--verify', '--quiet', ref + '^{commit}').strip()


def run_git(*args):
    """(exit code, stdout, stderr) of git that may legitimately exit with 1."""
    try:
        r = subprocess.run(['git', '-c', 'core.quotePath=false', *args], capture_output=True, text=True,
                           timeout=TIMEOUT_S)
    except (OSError, subprocess.TimeoutExpired) as e:
        raise Failure('cannot run git %s: %s' % (args[0], e))
    return r.returncode, r.stdout, r.stderr.strip()


def check_merge(merge):
    """(conflicts, extra, octopus) of a merge commit: the files Git could not merge on its own (the resolutions to
    review) and the files where the merge differs from Git's automatic merge of its parents outside those conflicts
    (changes made in the merge itself)."""
    parents = git('rev-list', '--parents', '-n', '1', merge).split()[1:]
    if len(parents) != 2:
        return [], [], True
    code, out, err = run_git('merge-tree', '--write-tree', '--name-only', '--no-messages', *parents)
    if code not in (0, 1):
        raise Failure('git merge-tree %s failed: %s' % (merge, err or 'exit code %d' % code))
    tree, conflicts = out.splitlines()[0], set(lines('\n'.join(out.splitlines()[1:])))
    extra = set(lines(git('diff', '--name-only', tree, merge + '^{tree}'))) - conflicts
    return sorted(conflicts), sorted(extra), False


def sync(previous, head, main):
    try:
        previous, head = commit(previous), commit(head)
    except Failure as e:
        raise Failure('unknown commit: %s' % e)
    try:
        main_sha = commit(main)
    except Failure:
        raise Failure('unknown ref %s (fetch it first)' % main)
    code, _, err = run_git('merge-base', '--is-ancestor', previous, head)
    if code == 1:
        raise Failure('the previous head %s is not an ancestor of HEAD %s' % (previous, head))
    if code != 0:
        raise Failure('git merge-base failed: %s' % (err or 'exit code %d' % code))
    epic_only = lines(git('rev-list', '--reverse', head, '^' + previous, '^' + main_sha))
    from_main = [sha for sha in lines(git('rev-list', head, '^' + previous)) if sha not in set(epic_only)]
    merges, others = [], []
    for sha in epic_only:
        (merges if len(git('rev-list', '--parents', '-n', '1', sha).split()) > 2 else others).append(sha)
    conflicts, extra, octopus = set(), set(), False
    for merge in merges:
        c, e, o = check_merge(merge)
        conflicts |= set(c)
        extra |= set(e)
        octopus = octopus or o
    changed = set(lines(git('diff', '--name-only', previous, head)))
    if not epic_only and not from_main:
        kind, reason = 'delta', 'no commits between the previous head and HEAD'
    elif others:
        kind, reason = 'delta', '%d commit(s) that are not merges and not on main' % len(others)
    elif octopus:
        kind, reason = 'delta', 'an octopus merge, which is not checked against an automatic merge'
    elif not from_main:
        kind, reason = 'delta', 'the merges bring no commit of main'
    elif extra:
        kind, reason = 'delta', 'the merges change files beyond their conflicts: %s' % ', '.join(sorted(extra))
    else:
        kind, reason = 'sync', '%d merge(s) bringing %d commit(s) of main, nothing else' % (len(merges), len(from_main))
    return {'kind': kind, 'reason': reason, 'merges': merges, 'other_commits': others,
            'conflicts': sorted(conflicts), 'extra': sorted(extra),
            'generated': [f for f in GENERATED if f in changed]}


def gh(*args):
    try:
        r = subprocess.run(['gh', *args], capture_output=True, text=True, timeout=GH_TIMEOUT_S)
    except (OSError, subprocess.TimeoutExpired) as e:
        raise Failure('gh cannot run: %s' % e)
    if r.returncode != 0:
        raise Failure('gh %s failed: %s' % (' '.join(args[:2]), r.stderr.strip() or 'exit code %d' % r.returncode))
    try:
        return json.loads(r.stdout)
    except ValueError:
        raise Failure('gh %s printed no JSON' % ' '.join(args[:2]))


def red_statuses(passed):
    """The statuses of the entries ci:<job>@<sha> of red CI: next to a red job, a required job that did not succeed
    (cancelled, missing, unfinished) is failed as well."""
    return {name: 'passed' if name in passed else 'failed' for name in CI_JOBS}


def ci_state(head):
    """One look at the latest push run of the CI workflow for head: green, red, local or pending (see the docstring).
    A red job decides only when every required job has finished; `unfinished` names the jobs still running then.
    `gh_error` marks a local state that comes from gh failing, which `ci` waits out while it has a budget."""
    def out(state, reason, run=None, statuses=None, failed=(), unfinished=(), passed=(), gh_error=False):
        return {'state': state, 'reason': reason, 'sha': head, 'run': run, 'statuses': statuses,
                'failed_jobs': list(failed), 'unfinished': list(unfinished), 'passed': list(passed),
                'gh_error': gh_error}
    try:
        runs = gh('run', 'list', '--commit', head, '--event', 'push', '--workflow', CI_WORKFLOW, '--limit', '20',
                  '--json', 'databaseId,headSha,status,conclusion,event,createdAt')
        if not isinstance(runs, list):
            raise Failure('gh run list printed no list')
        runs = [r for r in runs if isinstance(r, dict) and r.get('headSha') == head and r.get('event') == 'push'
                and isinstance(r.get('databaseId'), int)]
        if not runs:
            return out('pending', 'no push run of the CI workflow for %s' % head)
        run = max(runs, key=lambda r: r['databaseId'])
        number = run['databaseId']
        view = gh('run', 'view', str(number), '--json', 'headSha,jobs')
    except Failure as e:
        return out('local', str(e), gh_error=True)
    if not isinstance(view, dict) or view.get('headSha') != head:
        return out('local', 'run %d is not for head %s' % (number, head), number)
    run_done = run.get('status') == 'completed'
    jobs = {j.get('name'): j for j in view.get('jobs') or [] if isinstance(j, dict)}
    states, passed, failed, unfinished, other, missing = {}, [], [], [], [], []
    for name in CI_JOBS:
        job = jobs.get(name)
        if job is None:
            (missing if run_done else unfinished).append(name)
            states[name] = 'missing' if run_done else 'not started'
            continue
        status, conclusion = job.get('status'), job.get('conclusion') or '-'
        states[name] = '%s/%s' % (status, conclusion)
        if status != 'completed':
            unfinished.append(name)
        elif conclusion == 'success':
            passed.append(name)
        elif conclusion in RED:
            failed.append(name)
        else:
            other.append(name)
    described = ', '.join('%s %s' % (name, states[name]) for name in CI_JOBS)
    if unfinished and not run_done:
        return out('pending', 'run %d is still running: %s' % (number, described), number, failed=failed,
                   unfinished=unfinished, passed=passed)
    if failed:
        return out('red', 'red job(s) %s in push run %d: %s' % (', '.join(failed), number, described), number,
                   red_statuses(passed), failed, passed=passed)
    if missing:
        return out('local', 'run %d has no job %s' % (number, ', '.join(missing)), number)
    if other or unfinished:
        return out('local', 'job(s) %s of the finished run %d did not pass or fail: %s' % (
            ', '.join(other + unfinished), number, described), number)
    return out('green', 'jobs %s green in push run %d' % (' and '.join(CI_JOBS), number), number,
               {name: 'passed' for name in CI_JOBS})


def kept_red(red):
    """The red jobs kept in the wait file ({run, failed_jobs, passed, reason}), or None when missing or broken."""
    if not isinstance(red, dict):
        return None
    failed, passed = red.get('failed_jobs'), red.get('passed')
    if not (isinstance(failed, list) and failed and isinstance(passed, list)
            and all(isinstance(n, str) and n in CI_JOBS for n in failed + passed) and not set(failed) & set(passed)):
        return None
    run = red.get('run')
    return {'run': run if isinstance(run, int) and not isinstance(run, bool) else None,
            'failed_jobs': [n for n in CI_JOBS if n in failed], 'passed': [n for n in CI_JOBS if n in passed],
            'reason': red['reason'] if isinstance(red.get('reason'), str) else 'red job(s) seen in an earlier call'}


def write_wait(path, head, started, red=None):
    data = {'head': head, 'started': started}
    if red:
        data['red'] = red
    try:
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(data, f)
    except OSError as e:
        raise Failure('cannot write the wait file %s: %s' % (path, e))


def wait_start(path, head, now, budget):
    """(started, red) of the wait for head kept in the wait file: the time of the first call and the red jobs seen
    by an earlier call (or None). A file of another head, a broken one or one whose start is not a finite time within
    one wait (now - budget - CALL_S <= started <= now; an older file is left by an earlier round) starts anew."""
    try:
        with open(path, encoding='utf-8') as f:
            kept = json.load(f)
    except (OSError, ValueError):
        kept = None
    started = kept.get('started') if isinstance(kept, dict) else None
    if isinstance(kept, dict) and kept.get('head') == head and isinstance(started, (int, float)) \
            and not isinstance(started, bool) and not (isinstance(started, float) and not math.isfinite(started)) \
            and now - budget - CALL_S <= started <= now:
        return started, kept_red(kept.get('red'))
    write_wait(path, head, now)
    return now, None


def ci(head, budget=None, wait_file=None, clock=time.time, sleep=time.sleep):
    """`ci_state` polled every POLL_S seconds for at most min(the budget left, CALL_S) while it is pending, and with a
    budget also while gh fails. A red job seen while the other required job runs is kept in the wait file, so that no
    later call and no end of the budget turns red CI into local gates. Returns (exit code, JSON result)."""
    poll = float(os.environ.get('DELTA_CI_POLL_S', POLL_S))  # shortened by the tests only
    call = float(os.environ.get('DELTA_CI_CALL_S', CALL_S))
    waiting = budget is not None
    begin = clock()
    started, red = wait_start(wait_file, head, begin, budget) if waiting else (begin, None)
    end = begin + max(0.0, min(budget - (begin - started), call)) if waiting else begin
    polls = 0
    while True:
        state = ci_state(head)
        polls += 1
        now = clock()
        if state['state'] == 'pending' and state['failed_jobs']:
            seen = {'run': state['run'], 'failed_jobs': state['failed_jobs'], 'passed': state['passed'],
                    'reason': state['reason']}
            if waiting and seen != red:
                write_wait(wait_file, head, started, seen)
            red = seen
        if waiting and state['gh_error']:
            state.update(state='pending', reason='gh failed while waiting for CI: %s' % state['reason'])
        if state['state'] != 'pending' or now >= end:
            break
        # Polls start every `poll` seconds from the start of the call, the last one at its end.
        sleep(max(0.0, min(begin + polls * poll, end) - now))
    elapsed = now - started if waiting else 0
    code = {'green': 0, 'red': 3, 'local': 1}.get(state['state'])
    if state['state'] == 'pending' and waiting and elapsed < budget:
        code = 4
    elif state['state'] in ('pending', 'local') and red:
        # Red CI is never replaced by local gates: not when the other required job did not finish in time, not when
        # gh failed after the red job was seen, not when a later look ended without an answer.
        code = 3
        unfinished = [name for name in CI_JOBS if name not in red['failed_jobs'] + red['passed']]
        reason = '%s; job(s) %s did not finish in time' % (red['reason'], ', '.join(unfinished))
        if state['reason'] != red['reason']:
            reason += '; the last look: %s' % state['reason']
        state.update(state='red', reason=reason, run=red['run'], failed_jobs=red['failed_jobs'],
                     statuses=red_statuses(red['passed']))
    elif state['state'] == 'pending':
        code = 1
        if not waiting:
            why = 'no --budget to wait for CI'
        elif state['gh_error']:
            why = 'no answer from gh by the end of the budget'
        else:
            why = 'CI still running after the budget'
        state.update(state='local', reason='%s: %s' % (why, state['reason']))
    statuses = state['statuses'] if state['state'] in ('green', 'red') else None
    return code, {
        'state': state['state'], 'use_ci': statuses is not None, 'reason': state['reason'], 'sha': head,
        'run': state['run'],
        'checks': [{'name': 'ci:%s@%s' % (name, head), 'status': statuses[name]} for name in CI_JOBS] if statuses else [],
        'failed_jobs': state['failed_jobs'] if state['state'] == 'red' else [], 'waited_s': round(elapsed, 1)}


def e2e_parts(workflow):
    """The E2E parts of the CI workflow: the number of weights of E2E_SHARD_WEIGHTS and the weights."""
    try:
        with open(workflow, encoding='utf-8') as f:
            text = f.read()
    except OSError as e:
        raise Failure('cannot read the workflow %s: %s' % (workflow, e))
    values = {m.group(1).strip().strip('\'"') for m in WEIGHTS_LINE.finditer(text)}
    if not values:
        raise Failure('no E2E_SHARD_WEIGHTS in %s' % workflow)
    if len(values) > 1:
        raise Failure('more than one E2E_SHARD_WEIGHTS in %s: %s' % (workflow, ', '.join(sorted(values))))
    weights = values.pop()
    if not WEIGHTS.match(weights):
        raise Failure('E2E_SHARD_WEIGHTS in %s is %r, not positive whole numbers joined by ":"' % (workflow, weights))
    return {'parts': len(weights.split(':')), 'weights': weights}


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='command', required=True)
    p = sub.add_parser('sync')
    p.add_argument('--previous', required=True)
    p.add_argument('--head', required=True)
    p.add_argument('--main', default='origin/main')
    p = sub.add_parser('ci')
    p.add_argument('--head', required=True)
    p.add_argument('--budget')
    p.add_argument('--wait-file')
    p = sub.add_parser('e2e-parts')
    p.add_argument('--workflow', default=DEFAULT_WORKFLOW)
    args = parser.parse_args(argv)
    try:
        if args.command == 'sync':
            out = sync(args.previous, args.head, args.main)
        elif args.command == 'e2e-parts':
            out = e2e_parts(args.workflow)
        else:
            if not SHA.match(args.head):
                raise Failure('--head must be the full 40 hex SHA of HEAD, got %r' % args.head)
            if (args.budget is None) != (args.wait_file is None):
                raise Failure('--budget and --wait-file go together')
            budget = None
            if args.budget is not None:
                try:
                    budget = float(args.budget)
                except ValueError:
                    budget = -1
                if not budget >= 0 or budget == float('inf'):
                    raise Failure('--budget must be a number of seconds >= 0, got %r' % args.budget)
            code, out = ci(args.head, budget, args.wait_file)
            print(json.dumps(out, indent=2))
            return code
    except Failure as e:
        print('delta: %s' % e, file=sys.stderr)
        return 2
    except Exception as e:  # odd gh output or settings end with exit code 2 and a message, never a traceback
        print('delta: unexpected error: %s: %s' % (type(e).__name__, e), file=sys.stderr)
        return 2
    print(json.dumps(out, indent=2))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))

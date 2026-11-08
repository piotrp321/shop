#!/usr/bin/env python3
"""The scope of a DELTA round of the /integration skill (SKILL.md, step 2 and 4). Python 3.9+, standard library only.

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

  ci --head SHA
      Whether the required CI jobs `checks` and `e2e` are green for exactly HEAD in the latest push run of the CI
      workflow (`gh run list --commit`, `gh run view`). Pull request runs do not count: they skip the work for
      documentation and tooling changes. Prints JSON: use_ci, reason, sha, run and the `checks` entries for the
      result (ci:checks@<sha>, ci:e2e@<sha>). Exit code 0 when CI replaces the local `make test` and `make e2e`,
      1 when the local gates run (another commit, no run, a run in progress, a red or missing job, gh failing),
      2 for a head that is not a full SHA.
"""
import argparse
import json
import re
import subprocess
import sys

GENERATED = ('apps/api/openapi.json', 'apps/api/openapi-admin.json', 'apps/store/src/api/schema.d.ts',
             'apps/admin/src/api/schema.d.ts')
CI_JOBS = ('checks', 'e2e')
CI_WORKFLOW = 'ci.yml'
SHA = re.compile(r'^[0-9a-f]{40}$')
TIMEOUT_S = 60


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
        r = subprocess.run(['gh', *args], capture_output=True, text=True, timeout=TIMEOUT_S)
    except (OSError, subprocess.TimeoutExpired) as e:
        raise Failure('gh cannot run: %s' % e)
    if r.returncode != 0:
        raise Failure('gh %s failed: %s' % (' '.join(args[:2]), r.stderr.strip() or 'exit code %d' % r.returncode))
    try:
        return json.loads(r.stdout)
    except ValueError:
        raise Failure('gh %s printed no JSON' % ' '.join(args[:2]))


def ci(head):
    def local(reason):
        return {'use_ci': False, 'reason': reason, 'sha': head, 'run': None, 'checks': []}
    try:
        runs = gh('run', 'list', '--commit', head, '--event', 'push', '--workflow', CI_WORKFLOW, '--limit', '20',
                  '--json', 'databaseId,headSha,status,conclusion,event,createdAt')
        runs = [r for r in runs if isinstance(r, dict) and r.get('headSha') == head and r.get('event') == 'push'
                and isinstance(r.get('databaseId'), int)]
        if not runs:
            return local('no push run of the CI workflow for %s' % head)
        run = max(runs, key=lambda r: r['databaseId'])
        view = gh('run', 'view', str(run['databaseId']), '--json', 'headSha,jobs')
    except Failure as e:
        return local(str(e))
    if not isinstance(view, dict) or view.get('headSha') != head:
        return local('run %d is not for head %s' % (run['databaseId'], head))
    jobs = {j.get('name'): j for j in view.get('jobs') or [] if isinstance(j, dict)}
    for name in CI_JOBS:
        job = jobs.get(name)
        if job is None:
            return local('run %d has no job %s' % (run['databaseId'], name))
        if job.get('status') != 'completed' or job.get('conclusion') != 'success':
            return local('job %s of run %d is %s/%s' % (name, run['databaseId'], job.get('status'),
                                                        job.get('conclusion') or '-'))
    return {'use_ci': True, 'reason': 'jobs %s green in push run %d' % (' and '.join(CI_JOBS), run['databaseId']),
            'sha': head, 'run': run['databaseId'],
            'checks': [{'name': 'ci:%s@%s' % (name, head), 'status': 'passed'} for name in CI_JOBS]}


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='command', required=True)
    p = sub.add_parser('sync')
    p.add_argument('--previous', required=True)
    p.add_argument('--head', required=True)
    p.add_argument('--main', default='origin/main')
    p = sub.add_parser('ci')
    p.add_argument('--head', required=True)
    args = parser.parse_args(argv)
    if args.command == 'sync':
        try:
            out = sync(args.previous, args.head, args.main)
        except Failure as e:
            print('delta: %s' % e, file=sys.stderr)
            return 2
        print(json.dumps(out, indent=2))
        return 0
    if not SHA.match(args.head):
        print('delta: --head must be the full 40 hex SHA of HEAD, got %r' % args.head, file=sys.stderr)
        return 2
    out = ci(args.head)
    print(json.dumps(out, indent=2))
    return 0 if out['use_ci'] else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))

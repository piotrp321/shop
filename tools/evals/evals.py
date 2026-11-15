#!/usr/bin/env python3
"""Evals of the agents: the registry of the suites, the results and the checks of CI. Python 3.9+, standard library
only, no model and no network. Documentation: tools/evals/README.md.

Usage:
  evals.py [--root DIR] lint
  evals.py [--root DIR] check [--diff <base>...<head>] [--enforce]
  evals.py [--root DIR] report [--suite NAME]
  evals.py [--root DIR] accept <suite> --reason TEXT
  evals.py [--root DIR] waive <suite> --reason TEXT
  evals.py [--root DIR] estimate [<suite>] [--case ID] [--json]
  evals.py [--root DIR] run <suite> [--case ID [--overlay-rev REV]] [--state-root DIR] [--claude-bin PATH]
  evals.py [--root DIR] run --resume <id> [--state-root DIR] [--claude-bin PATH]

lint     formats of the registry, the config, the cases and the history files; the scan for leaks; the injection
         payloads; the commits of the cases (skipped in a shallow clone).
check    the fingerprint of every suite against its current result. Errors only with --enforce and for a suite that
         has a measurement; with --diff only for a suite the diff changes. Everything else is a warning.
report   the history of the suites as a table.
accept   the current result is a regression: writes an `accepted` history file with the reason (the user decides).
waive    writes a `waived` history file with the current fingerprint and the reason (the user decides).
estimate the cost and time forecast of a run of each suite (or of a trial run of one case) and the total; a forecast
         above the cap exits 1.
run      runs the cases of a suite N times with the CLI of the suite's executor in the sandbox (a trial run: one
         case, the overlay of --overlay-rev, never a history file); --resume continues a run stopped by limit_stop,
         infra_error or an interrupt (SIGTERM, SIGHUP, SIGINT: the executor is killed, the run stays resumable).
         Prints the run id and its status; scoring and the history file are not part of it. Exit 0 for a finished
         run, 1 for a refusal or a stopped run.

Exit status: 0 ok, 1 findings (lint errors, check errors, a refused decision), 2 cannot run (usage, no registry,
malformed JSON, a bad --diff, no git where it is needed).
The scoring (another task of the epic) adds its commands to COMMANDS.
"""
import argparse
import json
import os
import signal
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import evals_check as checking  # noqa: E402
import evals_history as history  # noqa: E402
import evals_lint as linting  # noqa: E402
import evals_registry as registry  # noqa: E402
import evals_repo as repo  # noqa: E402
import evals_runner as runner  # noqa: E402

DEFAULT_ROOT = os.path.dirname(os.path.dirname(HERE))
USAGE_ERROR = 2


class Usage(Exception):
    """The command cannot run (exit status 2)."""


def _load(root):
    try:
        return registry.load(repo.DiskSource(root))
    except registry.ConfigError as error:
        raise Usage('%s (run `evals.py lint`)' % error)


def _suite(reg, name):
    if name not in reg['suites']:
        raise Usage('unknown suite %r (suites: %s)' % (name, ', '.join(sorted(reg['suites']))))
    return reg['suites'][name]


def cmd_lint(args):
    report = linting.lint(args.root)
    report.print(sys.stdout)
    if report.errors:
        print('evals lint: FAILED (%d error(s), %d warning(s))' % (len(report.errors), len(report.warnings)))
        return 1
    print('evals lint: OK (%d warning(s))' % len(report.warnings))
    return 0


def cmd_check(args):
    diff = None
    try:
        if args.diff:
            diff = checking.parse_diff(args.diff)
        report = checking.check(args.root, diff=diff, enforce=args.enforce)
    except checking.CheckError as error:
        raise Usage(str(error))
    report.print(sys.stdout)
    mode = 'enforced' if args.enforce else 'warnings only'
    if report.errors:
        print('evals check: FAILED (%d error(s), %d warning(s); %s)' % (len(report.errors), len(report.warnings), mode))
        return 1
    print('evals check: OK (%d warning(s); %s)' % (len(report.warnings), mode))
    return 0


def _number(value, digits=2):
    return '-' if value is None else ('%.*f' % (digits, value))


def _row(name, result):
    metrics = result.get('metrics') or {}
    correctness = metrics.get('correctness')
    return '  %-32s %-10s %-9s %7s %7s %6s %9s %9s %8s' % (
        name, result.get('verdict'), (result.get('fingerprint') or '-')[:8],
        '-' if correctness is None else '%.0f%%' % (correctness * 100),
        '-' if metrics.get('recall') is None else '%.0f%%' % (metrics['recall'] * 100),
        _number(metrics.get('false_alarms'), 0),
        '-' if metrics.get('injection_resilience') is None else '%.0f%%' % (metrics['injection_resilience'] * 100),
        '-' if metrics.get('stability') is None else '%.0f%%' % (metrics['stability'] * 100),
        _number((result.get('cost') or {}).get('total_usd')))


def cmd_report(args):
    reg, cfg = _load(args.root)
    names = [args.suite] if args.suite else sorted(reg['suites'])
    for name in names:
        _suite(reg, name)
    source = repo.DiskSource(args.root)
    for name in names:
        try:
            files = history.list_names(source, name)
            newest = history.latest(source, name)
            results = [(f, history.load_result(source, name, f)) for f in files]
        except history.HistoryError as error:
            raise Usage('%s (run `evals.py lint`)' % error)
        if not newest:
            print('suite %s: none (no measurement; run /evals %s)' % (name, name))
            continue
        current = registry.fingerprint(source, name, reg['suites'][name], cfg)
        state = 'current' if newest[1].get('fingerprint') == current else 'stale'
        print('suite %s: %s, %s' % (name, newest[1].get('verdict'), state))
        print('  %-32s %-10s %-9s %7s %7s %6s %9s %9s %8s' % ('file', 'verdict', 'fprint', 'correct', 'recall',
                                                              'false', 'injection', 'stability', 'cost usd'))
        for file_name, result in results:
            line = _row(file_name, result)
            if result.get('reason'):
                line += '  reason: %s' % result['reason']
            if result.get('run_file'):
                line += '  (run %s)' % result['run_file']
            print(line + ('  <- newest' if file_name == newest[0] else ''))
    return 0


def _decide(args, verdict):
    reg, cfg = _load(args.root)
    entry = _suite(reg, args.suite)
    source = repo.DiskSource(args.root)
    try:
        newest = history.latest(source, args.suite)
    except history.HistoryError as error:
        raise Usage('%s (run `evals.py lint`)' % error)
    fingerprint = registry.fingerprint(source, args.suite, entry, cfg)
    run_file = None
    if verdict == 'accepted':
        if newest is None or newest[1].get('verdict') != 'regression':
            print('accept: the current result of %s is %s, not a regression: nothing to accept'
                  % (args.suite, 'none' if newest is None else newest[1].get('verdict')), file=sys.stderr)
            return 1
        if newest[1].get('fingerprint') != fingerprint:
            print('accept: the regression %s of %s is stale (the suite changed since): run /evals %s first'
                  % (newest[0], args.suite, args.suite), file=sys.stderr)
            return 1
        run_file = newest[0]
    elif newest is not None:
        run_file = newest[0] if newest[1].get('verdict') in history.MEASURED_VERDICTS else newest[1].get('run_file')
    try:
        commit = repo.head_commit(args.root)
    except repo.GitError as error:
        raise Usage('cannot read the commit of HEAD: %s' % error)
    result = history.decision(verdict, args.suite, fingerprint, commit, args.reason, run_file)
    try:
        path = history.write_result(args.root, args.suite, result)
    except history.HistoryError as error:
        print('%s: %s' % ('accept' if verdict == 'accepted' else 'waive', error), file=sys.stderr)
        return 1
    print('%s: wrote %s' % ('accept' if verdict == 'accepted' else 'waive', path))
    return 0


def cmd_accept(args):
    return _decide(args, 'accepted')


def cmd_waive(args):
    return _decide(args, 'waived')


def _duration(seconds):
    return '%.1f h' % (seconds / 3600.0) if seconds >= 3600 else '%.1f min' % (seconds / 60.0)


def cmd_estimate(args):
    if args.case and not args.suite:
        raise Usage('--case needs a suite')
    reg, _ = _load(args.root)
    names = [args.suite] if args.suite else sorted(reg['suites'])
    for name in names:
        _suite(reg, name)
    try:
        plans = [runner.forecast(args.root, name, args.case) for name in names]
    except runner.RunError as error:
        raise Usage(str(error))
    total = {'passes': sum(p['passes'] for p in plans), 'cost_usd': sum(p['cost_usd'] for p in plans),
             'duration_s': sum(p['duration_s'] for p in plans)}
    if args.json:
        print(json.dumps({'suites': plans, 'total': total}, indent=2))
    else:
        for plan in plans:
            what = 'trial run of %s/%s' % (plan['suite'], plan['case']) if plan['case'] else 'suite %s' % plan['suite']
            print('%s: %d passes (%d case(s) x %d), cost %.2f USD (%.2f USD per pass: %s), time %s (%s per pass: '
                  '%s), %s the cap of %.2f USD' % (
                      what, plan['passes'], plan['cases'], plan['n'], plan['cost_usd'], plan['pass_cost_usd'],
                      plan['cost_source'], _duration(plan['duration_s']), _duration(plan['pass_duration_s']),
                      plan['duration_source'], 'within' if plan['within_cap'] else 'ABOVE', plan['cap_usd']))
        if len(plans) > 1:
            print('total: %d passes, cost %.2f USD, time %s' % (total['passes'], total['cost_usd'],
                                                                 _duration(total['duration_s'])))
    return 0 if all(p['within_cap'] for p in plans) else 1


def cmd_run(args):
    if args.resume and (args.suite or args.case or args.overlay_rev):
        raise Usage('--resume takes only the run id')
    if not args.resume and not args.suite:
        raise Usage('a suite or --resume <id> is needed')
    if args.overlay_rev and not args.case:
        raise Usage('--overlay-rev is for a trial run of one case (--case)')
    options = runner.Options(args.root, args.state_root, claude_bin=args.claude_bin)

    def interrupt(number, _frame):
        raise runner.Interrupted(signal.Signals(number).name)
    previous = {sig: signal.signal(sig, interrupt) for sig in (signal.SIGTERM, signal.SIGHUP)}
    try:
        if args.resume:
            run = runner.resume(options, args.resume)
        else:
            run = runner.start(options, args.suite, case=args.case, overlay_rev=args.overlay_rev)
    except runner.RunError as error:
        print('evals.py run: refused: %s' % error, file=sys.stderr)
        return 1
    except runner.Interrupted as error:
        if error.run is None:
            print('evals.py run: interrupted by %s before the first pass' % error.reason, file=sys.stderr)
            return 1
        run = error.run
    finally:
        for sig, handler in previous.items():
            signal.signal(sig, handler)
    counts = {}
    for item in run['passes']:
        counts[item['status']] = counts.get(item['status'], 0) + 1
    print('run %s: %s%s' % (run['id'], run['status'], (' (%s)' % run['reason']) if run['reason'] else ''))
    print('  %s %s, %d passes (%s), cost %.2f USD of the cap of %.2f USD, state in %s' % (
        run['kind'], run['suite'] + ('/' + run['case'] if run['case'] else ''), len(run['passes']),
        ', '.join('%s %d' % item for item in sorted(counts.items())), run['cost_usd_total'], run['cap_usd'],
        os.path.join(os.path.realpath(args.state_root), run['id'])))
    if run['status'] in ('limit_stop', 'infra_error', 'interrupted'):
        print('  resume: python3 tools/evals/evals.py run --resume %s' % run['id'])
    return 0 if run['status'] == 'done' else 1


def build_parser():
    parser = argparse.ArgumentParser(prog='evals.py', description='Evals of the agents (tools/evals/README.md).')
    parser.add_argument('--root', default=DEFAULT_ROOT, help='the repository (default: the one of this script)')
    commands = parser.add_subparsers(dest='command', metavar='command')
    commands.required = True
    commands.add_parser('lint', help='validate the registry, the cases and the history files')
    check = commands.add_parser('check', help='the fingerprint of every suite against its current result')
    check.add_argument('--diff', metavar='BASE...HEAD', help='the diff of a pull request: only changed suites are errors')
    check.add_argument('--enforce', action='store_true', help='stale and regressed suites with a measurement are errors')
    report = commands.add_parser('report', help='the history of the suites as a table')
    report.add_argument('--suite', help='one suite (default: all)')
    for name, help_ in (('accept', 'accept the current regression (the user decides)'),
                        ('waive', 'waive the current state of a suite without a measurement (the user decides)')):
        decision = commands.add_parser(name, help=help_)
        decision.add_argument('suite')
        decision.add_argument('--reason', required=True, help='why')
    estimate = commands.add_parser('estimate', help='the cost and time forecast of a run')
    estimate.add_argument('suite', nargs='?', help='one suite (default: all and the total)')
    estimate.add_argument('--case', help='a trial run of one case of the suite')
    estimate.add_argument('--json', action='store_true', help='JSON output')
    run = commands.add_parser('run', help='run the cases of a suite with the agent (a real model)')
    run.add_argument('suite', nargs='?')
    run.add_argument('--case', help='a trial run of one case (never a history file)')
    run.add_argument('--overlay-rev', help='the revision of the agents, skills and watched files of a trial run')
    run.add_argument('--resume', metavar='ID', help='resume a run stopped by limit_stop or infra_error')
    run.add_argument('--state-root', default=runner.DEFAULT_STATE_ROOT,
                     help='the directory of the runs (default: %(default)s)')
    run.add_argument('--claude-bin', help='the CLI binary instead of the one pinned by AO (claude_pin.py)')
    return parser


COMMANDS = {'lint': cmd_lint, 'check': cmd_check, 'report': cmd_report, 'accept': cmd_accept, 'waive': cmd_waive,
            'estimate': cmd_estimate, 'run': cmd_run}


def main(argv=None):
    args = build_parser().parse_args(argv)
    args.root = os.path.abspath(args.root)
    try:
        if not os.path.isdir(args.root):
            raise Usage('%s is not a directory' % args.root)
        return COMMANDS[args.command](args)
    except Usage as error:
        print('evals.py %s: %s' % (args.command, error), file=sys.stderr)
        return USAGE_ERROR


if __name__ == '__main__':
    sys.exit(main())

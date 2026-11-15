#!/usr/bin/env python3
"""Evals of the agents: the registry of the suites, the results and the checks of CI. Python 3.9+, standard library
only, no model and no network. Documentation: tools/evals/README.md.

Usage:
  evals.py [--root DIR] lint
  evals.py [--root DIR] check [--diff <base>...<head>] [--enforce]
  evals.py [--root DIR] report [--suite NAME]
  evals.py [--root DIR] accept <suite> --reason TEXT
  evals.py [--root DIR] waive <suite> --reason TEXT

lint     formats of the registry, the config, the cases and the history files; the scan for leaks; the injection
         payloads; the commits of the cases (skipped in a shallow clone).
check    the fingerprint of every suite against its current result. Errors only with --enforce and for a suite that
         has a measurement; with --diff only for a suite the diff changes. Everything else is a warning.
report   the history of the suites as a table.
accept   the current result is a regression: writes an `accepted` history file with the reason (the user decides).
waive    writes a `waived` history file with the current fingerprint and the reason (the user decides).

Exit status: 0 ok, 1 findings (lint errors, check errors, a refused decision), 2 cannot run (usage, no registry,
malformed JSON, a bad --diff, no git where it is needed).
The runner and the scoring (other tasks of the epic) add their commands to COMMANDS.
"""
import argparse
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import evals_check as checking  # noqa: E402
import evals_history as history  # noqa: E402
import evals_lint as linting  # noqa: E402
import evals_registry as registry  # noqa: E402
import evals_repo as repo  # noqa: E402

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
    return parser


COMMANDS = {'lint': cmd_lint, 'check': cmd_check, 'report': cmd_report, 'accept': cmd_accept, 'waive': cmd_waive}


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

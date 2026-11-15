"""`evals.py check`: the fingerprint of every suite against its current result. Python 3.9+, standard library only.

Errors only with `enforce`, and only for a suite that has a measurement (a non-empty history) and, when a diff is
given, is changed by it: its current result has another fingerprint (stale) or the verdict `regression`; also for a
history file with the verdict `regression` that the diff adds (unless a newer `accepted` or `waived` file of the history
names it as its run file). Everything else is a warning. Without a diff `enforce` makes every stale or regressed suite
with a measurement an error.

A suite is changed by a diff when the fingerprint computed from the merge base differs from the one computed from the
head: the fingerprint covers every input (watched files, case files, the run-changing part of the registry entry and
of the config), so no input can be missed. The result is compared with the fingerprint of the working tree, which in CI
is the merge of the pull request.
"""
import evals_history as history
import evals_registry as registry
import evals_repo as repo
from evals_report import Report


class CheckError(Exception):
    """The check cannot run: a bad argument, no registry, a git failure. Exit code 2."""


def parse_diff(argument):
    """`<base>...<head>` -> (base, head)."""
    parts = argument.split('...')
    if len(parts) != 2 or not parts[0].strip() or not parts[1].strip() or parts[0].startswith('-'):
        raise CheckError('--diff must be <base>...<head> (three dots), got %r' % argument)
    return parts[0].strip(), parts[1].strip()


def _fingerprints(source):
    try:
        reg, cfg = registry.load(source)
    except registry.ConfigError:
        return {}
    return {name: registry.fingerprint(source, name, entry, cfg) for name, entry in reg['suites'].items()}


def changed_suites(root, merge_base, head, names):
    """The suites whose fingerprint differs between the merge base and the head."""
    before = _fingerprints(repo.GitSource(root, merge_base))
    after = _fingerprints(repo.GitSource(root, head))
    return {name for name in names if before.get(name) != after.get(name)}


def added_regressions(root, merge_base, head):
    """[(suite, history file name)] of the history files with the verdict regression that the diff adds, without
    a newer accepted or waived file (in the head) that names them."""
    _, out = repo.git(root, 'diff', '--name-status', '--no-renames', '-z', merge_base, head, '--', registry.HISTORY_DIR)
    tokens = [t.decode('utf-8', 'replace') for t in out.split(b'\0') if t]
    head_source = repo.GitSource(root, head)
    found = []
    for status, path in zip(tokens[0::2], tokens[1::2]):
        parts = path[len(registry.HISTORY_DIR) + 1:].split('/')
        if status != 'A' or not path.startswith(registry.HISTORY_DIR + '/') or len(parts) != 2 \
                or not history.NAME_RE.match(parts[1]):
            continue
        suite, name = parts
        try:
            result = history.load_result(head_source, suite, name)
            if result.get('verdict') != 'regression':
                continue
            covered = False
            for later in history.list_names(head_source, suite):
                if later > name:
                    decision = history.load_result(head_source, suite, later)
                    covered = covered or (decision.get('verdict') in history.DECISION_VERDICTS
                                          and decision.get('run_file') == name)
        except history.HistoryError as error:
            raise CheckError(str(error))
        if not covered:
            found.append((suite, name))
    return found


def check(root, diff=None, enforce=False):
    """Checks every suite of the registry; returns a Report."""
    source = repo.DiskSource(root)
    try:
        reg, cfg = registry.load(source)
    except registry.ConfigError as error:
        raise CheckError('%s (run `evals.py lint`)' % error)
    report = Report()
    names = sorted(reg['suites'])
    changed = None
    added = []
    if diff:
        base, head = diff
        try:
            merge_base = repo.git(root, 'merge-base', repo.resolve_commit(root, base),
                                  repo.resolve_commit(root, head))[1].decode().strip()
            head_sha = repo.resolve_commit(root, head)
            changed = changed_suites(root, merge_base, head_sha, names)
            added = added_regressions(root, merge_base, head_sha)
        except repo.GitError as error:
            raise CheckError('cannot compute the diff %s...%s: %s' % (base, head, error))

    def problem(message, is_error):
        (report.error if is_error and enforce else report.warn)(message)

    for name in names:
        entry = reg['suites'][name]
        try:
            latest = history.latest(source, name)
            previous = history.previous_measurement(source, name)
        except history.HistoryError as error:
            raise CheckError('%s (run `evals.py lint`)' % error)
        is_changed = changed is None or name in changed   # no diff: every suite counts as changed
        if latest is None:
            report.warn('suite %s has no measurement (verdict none): run /evals %s' % (name, name))
            continue
        file_name, result = latest
        current = registry.fingerprint(source, name, entry, cfg)
        stale = result.get('fingerprint') != current
        regression = result.get('verdict') == 'regression'
        if not stale and not regression:
            report.note('suite %s is current (%s, %s)' % (name, result.get('verdict'), file_name))
        scope = '' if is_changed else ' (not changed in this diff)'
        if stale:
            problem('suite %s is stale: run /evals %s (the newest result %s was measured on another fingerprint)%s'
                    % (name, name, file_name, scope), is_changed)
        if regression:
            problem('suite %s has the verdict regression (%s): run /evals %s again or ask the user to accept it '
                    '(evals.py accept %s --reason ...)%s' % (name, file_name, name, name, scope), is_changed)
        if previous is not None:
            _warn_code_changes(source, name, entry, cfg, previous, report)

    for suite, file_name in added:
        problem('the pull request adds the regression result %s of suite %s: a newer measurement does not clear it, only '
                'a decision of the user does (evals.py accept %s --reason ... or evals.py waive %s --reason ...; the '
                'evals skill writes it on the command of the user in the conversation)'
                % (file_name, suite, suite, suite), True)
    return report


def _warn_code_changes(source, name, entry, cfg, previous, report):
    file_name, result = previous
    now = registry.code_hashes(source, entry, cfg)
    if result.get('scoring_code_hash') != now['scoring_code_hash']:
        report.warn('suite %s: the scoring code changed since the measurement %s: consider a new measurement '
                    '(/evals %s)' % (name, file_name, name))
    recorded = result.get('validator_hashes') or {}
    for path, digest in sorted(now['validator_hashes'].items()):
        if recorded.get(path) != digest:
            report.warn('suite %s: the validator %s changed since the measurement %s: consider a new measurement '
                        '(/evals %s)' % (name, path, file_name, name))

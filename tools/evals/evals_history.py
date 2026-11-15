"""The history of the results of a suite: format, reader and the allowlist writer. Python 3.9+, standard library only.

One file per run and per decision: tools/evals/history/<suite>/<UTC YYYYMMDDTHHMMSSZ>-<short sha>.json. The current
result of a suite is the newest file by name; an empty history is the verdict `none`. The repository is public, so a
file holds only the fields of this format (no transcripts, reports or prompts): `write_result` validates against the
allowlist and rejects anything else, nested fields included.

Interface for the scoring and the runner:
    write_result(root, suite, result) -> repository-relative path of the new file (HistoryError when not valid)
    decision(verdict, suite, fingerprint, commit, reason, run_file) -> the result dict of `accepted` or `waived`
    latest(source, suite) / previous_measurement(source, suite) / reference_measurement(source, suite)
        -> (file name, result) or None; the last two are the results to compare a new measurement with
"""
import datetime
import json
import os
import re
import unicodedata

import evals_registry as registry

VERDICTS = ('baseline', 'pass', 'regression', 'accepted', 'waived')
MEASURED_VERDICTS = ('baseline', 'pass', 'regression')
DECISION_VERDICTS = ('accepted', 'waived')
KINDS = ('defect', 'clean', 'substantive', 'injection')
STATUSES = ('done', 'failed')
NAME_RE = re.compile(r'^(\d{8}T\d{6}Z)-([0-9a-f]{7,40})\.json$')
SHA256_RE = re.compile(r'^[0-9a-f]{64}$')
COMMIT_RE = re.compile(r'^[0-9a-f]{40}$')
DATE_FORMAT = '%Y-%m-%dT%H:%M:%SZ'
NAME_DATE_FORMAT = '%Y%m%dT%H%M%SZ'
REASON_MAX = 500


class HistoryError(Exception):
    """A result is not valid, cannot be written or a history file cannot be read."""


# ------------------------------------------------------------------------------------------------------ the schema
# A field check is a function (value, path, problems) that appends a message per problem.

def _has_control(text):
    return any(unicodedata.category(c) == 'Cc' or c in '  ' for c in text)


def _blank(text):
    """Only whitespace (Unicode ones included) and invisible format characters."""
    return all(c.isspace() or unicodedata.category(c) in ('Cf', 'Cc') for c in text)


def text(maxlen=200, nullable=False):
    def check(value, path, problems):
        if value is None and nullable:
            return
        if not isinstance(value, str) or not value or _blank(value):
            problems.append('%s must be a non-blank text' % path)
        elif len(value) > maxlen:
            problems.append('%s is longer than %d characters' % (path, maxlen))
        elif _has_control(value):
            problems.append('%s has control characters' % path)
    return check


def pattern(regex, what, nullable=False):
    def check(value, path, problems):
        if value is None and nullable:
            return
        if not isinstance(value, str) or not regex.match(value):
            problems.append('%s must be %s' % (path, what))
    return check


def number(low=0, high=None, nullable=False):
    def check(value, path, problems):
        if value is None and nullable:
            return
        if isinstance(value, bool) or not isinstance(value, (int, float)):
            problems.append('%s must be a number' % path)
        elif value < low or (high is not None and value > high):
            problems.append('%s must be between %s and %s' % (path, low, 'infinity' if high is None else high))
    return check


def integer(low=0, nullable=False):
    def check(value, path, problems):
        if value is None and nullable:
            return
        if isinstance(value, bool) or not isinstance(value, int):
            problems.append('%s must be an integer' % path)
        elif value < low:
            problems.append('%s must be at least %d' % (path, low))
    return check


def boolean(nullable=False):
    def check(value, path, problems):
        if not (isinstance(value, bool) or (value is None and nullable)):
            problems.append('%s must be true or false' % path)
    return check


def enum(values):
    def check(value, path, problems):
        if value not in values:
            problems.append('%s must be one of %s' % (path, ', '.join(values)))
    return check


def array(item, maxlen=500):
    def check(value, path, problems):
        if not isinstance(value, list):
            problems.append('%s must be a list' % path)
            return
        if len(value) > maxlen:
            problems.append('%s has more than %d items' % (path, maxlen))
        for index, element in enumerate(value[:maxlen]):
            item(element, '%s[%d]' % (path, index), problems)
    return check


def mapping(key_check, value_check):
    def check(value, path, problems):
        if not isinstance(value, dict):
            problems.append('%s must be an object' % path)
            return
        for key, element in value.items():
            key_check(key, '%s key %r' % (path, key), problems)
            value_check(element, '%s[%r]' % (path, key), problems)
    return check


def obj(required, optional=None, nullable=False):
    """An object with exactly these fields: required ones must be there, any other field is rejected."""
    optional = optional or {}

    def check(value, path, problems):
        if value is None and nullable:
            return
        if not isinstance(value, dict):
            problems.append('%s must be an object' % path)
            return
        for key in sorted(value):
            if key not in required and key not in optional:
                problems.append('%s.%s is a field outside the format' % (path, key) if path else
                                '%s is a field outside the format' % key)
        for key, field in required.items():
            if key not in value:
                problems.append('%s is missing' % (path + '.' + key if path else key))
            else:
                field(value[key], path + '.' + key if path else key, problems)
        for key, field in optional.items():
            if key in value:
                field(value[key], path + '.' + key if path else key, problems)
    return check


_id = pattern(registry.CASE_ID_RE, 'a case id (lowercase letters, digits, `-`)')
_sha256 = pattern(SHA256_RE, 'a SHA-256 hex digest')
_ratio = number(0, 1, nullable=True)
_repetition = obj(
    {'status': enum(STATUSES), 'passed': boolean(), 'cost_usd': number(), 'duration_s': number()},
    {'resilient': boolean(nullable=True), 'defect_found': boolean(nullable=True), 'scope_violation': boolean(),
     'reason': text(200, nullable=True)})
_case = obj({'id': _id, 'case_hash': _sha256, 'kind': enum(KINDS), 'passes': integer(),
             'repetitions': array(_repetition, 50)})
_metrics = obj(
    {'correctness': number(0, 1)},
    {'recall': _ratio, 'false_alarms': integer(), 'scope_violations': integer(), 'injection_resilience': _ratio,
     'stability': number(0, 1), 'unstable_cases': array(_id), 'cost_usd_total': number(),
     'cost_usd_median_per_case': number(), 'duration_s_total': number(), 'duration_s_median_per_case': number()})
_side = obj(
    {'file': text(100, nullable=True), 'common_cases': integer(), 'correctness_delta': number(-100, 100, nullable=True),
     'changed_cases': array(obj({'id': _id, 'before': integer(), 'after': integer()})),
     'new_cases': array(_id), 'modified_cases': array(_id)}, nullable=True)
_comparison = obj(
    {'previous': _side, 'reference': _side,
     'regressions': array(obj({'rule': text(60), 'detail': text(300)}, {'case': text(80, nullable=True)})),
     'warnings': array(text(300))})
_usage = obj({}, {'start_percent': number(0, 100, nullable=True), 'end_percent': number(0, 100, nullable=True),
                  'delta_percent': number(-100, 100, nullable=True)}, nullable=True)

COMMON = {
    'schema_version': enum((registry.SCHEMA_VERSION,)),
    'suite': pattern(registry.NAME_RE, 'a suite name'),
    'verdict': enum(VERDICTS),
    'fingerprint': _sha256,
    'commit': pattern(COMMIT_RE, 'a 40-character commit SHA'),
    'date': pattern(re.compile(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$'), 'a UTC date YYYY-MM-DDTHH:MM:SSZ'),
}
MEASURED_REQUIRED = {
    'n': integer(registry.MIN_N),
    'metrics': _metrics,
    'cases': array(_case, 500),
    'cost': obj({'total_usd': number()}, {'forecast_usd': number()}),
    'cli_version': text(64, nullable=True),
    'model': text(100, nullable=True),
    'scoring_code_hash': _sha256,
    'validator_hashes': mapping(text(300), _sha256),
    'comparison': _comparison,
}
MEASURED_OPTIONAL = {'usage': _usage, 'plugins': array(text(100), 30)}
DECISION_REQUIRED = {'reason': text(REASON_MAX)}
DECISION_OPTIONAL = {'run_file': pattern(NAME_RE, 'the name of a history file')}


def validate_result(result):
    """Problems of a result dict (an empty list when it is valid): fields outside the format, missing fields,
    wrong types, the reason of accepted and waived, N >= 3 and the consistency of the cases."""
    if not isinstance(result, dict):
        return ['the result must be an object']
    problems = []
    verdict = result.get('verdict')
    if verdict in MEASURED_VERDICTS:
        schema = obj(dict(COMMON, **MEASURED_REQUIRED), MEASURED_OPTIONAL)
    elif verdict in DECISION_VERDICTS:
        optional = dict(DECISION_OPTIONAL)
        required = dict(COMMON, **DECISION_REQUIRED)
        if verdict == 'accepted':
            required['run_file'] = optional.pop('run_file')
        schema = obj(required, optional)
    else:
        schema = obj(COMMON)   # reports the verdict and any field outside the format
    schema(result, '', problems)
    if not problems:
        problems.extend(_consistency(result))
    if isinstance(result.get('date'), str) and not any('date' in p for p in problems):
        try:
            datetime.datetime.strptime(result['date'], DATE_FORMAT)
        except ValueError:
            problems.append('date is not a real UTC date')
    return problems


def _consistency(result):
    problems = []
    if result['verdict'] not in MEASURED_VERDICTS:
        return problems
    ids = [case['id'] for case in result['cases']]
    if not ids:
        problems.append('cases must not be empty')
    if len(set(ids)) != len(ids):
        problems.append('cases has a duplicate id')
    for case in result['cases']:
        label = 'cases[%s]' % case['id']
        if len(case['repetitions']) != result['n']:
            problems.append('%s: repetitions must have exactly n (%d) items' % (label, result['n']))
        if case['passes'] != sum(1 for r in case['repetitions'] if r['passed']):
            problems.append('%s: passes does not match the repetitions' % label)
        if any(r['status'] == 'failed' and r['passed'] for r in case['repetitions']):
            problems.append('%s: a failed pass cannot be passed' % label)
    return problems


# ------------------------------------------------------------------------------------------------------- reading

def history_dir(suite):
    return '%s/%s' % (registry.HISTORY_DIR, suite)


def list_names(source, suite):
    """The names of the result files of a suite, oldest first (the newest is the last)."""
    prefix = history_dir(suite) + '/'
    names = [path[len(prefix):] for path in source.list_files(history_dir(suite)) if '/' not in path[len(prefix):]]
    return sorted(name for name in names if NAME_RE.match(name))


def load_result(source, suite, name):
    raw = source.read('%s/%s' % (history_dir(suite), name))
    if raw is None:
        raise HistoryError('%s/%s does not exist' % (history_dir(suite), name))
    try:
        data = json.loads(raw.decode('utf-8'))
    except (ValueError, UnicodeDecodeError) as error:
        raise HistoryError('%s/%s is not valid JSON: %s' % (history_dir(suite), name, error))
    if not isinstance(data, dict):
        raise HistoryError('%s/%s is not an object' % (history_dir(suite), name))
    return data


def latest(source, suite):
    """(name, result) of the current result of a suite, None for an empty history."""
    names = list_names(source, suite)
    return (names[-1], load_result(source, suite, names[-1])) if names else None


def has_measurement(source, suite):
    """True when the history holds a measurement: a file with the verdict baseline, pass, regression or accepted.
    A history of waived files only is a suite without a measurement (a waiver is a change excused without one)."""
    return any(load_result(source, suite, name).get('verdict') in MEASURED_VERDICTS + ('accepted',)
               for name in list_names(source, suite))


def previous_measurement(source, suite):
    """The last measured result (baseline, pass or regression): what a new measurement is compared with. A decision
    (accepted, waived) is skipped, so the comparison runs from the last measurement."""
    for name in reversed(list_names(source, suite)):
        result = load_result(source, suite, name)
        if result.get('verdict') in MEASURED_VERDICTS:
            return name, result
    return None


def reference_measurement(source, suite):
    """The reference result: the last `baseline` or `accepted`. An accepted regression is the measurement its
    `run_file` names, so the reference carries the metrics and the cases of that run."""
    for name in reversed(list_names(source, suite)):
        result = load_result(source, suite, name)
        if result.get('verdict') == 'baseline':
            return name, result
        if result.get('verdict') == 'accepted':
            return result['run_file'], load_result(source, suite, result['run_file'])
    return None


# ------------------------------------------------------------------------------------------------------- writing

def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).strftime(DATE_FORMAT)


def result_file_name(result):
    stamp = datetime.datetime.strptime(result['date'], DATE_FORMAT).strftime(NAME_DATE_FORMAT)
    return '%s-%s.json' % (stamp, result['commit'][:7])


def decision(verdict, suite, fingerprint, commit, reason, run_file, date=None):
    """The result of an `accepted` or `waived` decision. run_file: the history file the decision applies to (required
    for accepted, optional for waived)."""
    result = {'schema_version': registry.SCHEMA_VERSION, 'suite': suite, 'verdict': verdict,
              'fingerprint': fingerprint, 'commit': commit, 'date': date or utc_now(), 'reason': reason}
    if run_file:
        result['run_file'] = run_file
    return result


def write_result(root, suite, result):
    """Validates a result against the allowlist and writes it as a new history file of the suite; returns its
    repository-relative path. Nothing is written for an invalid result, for an existing file or for a result that
    would not be the newest file of the history."""
    if not isinstance(suite, str) or not registry.NAME_RE.match(suite):
        raise HistoryError('%r is not a suite name' % (suite,))
    problems = validate_result(result)
    if not problems and result['suite'] != suite:
        problems.append('suite %r does not match the history of %r' % (result['suite'], suite))
    if problems:
        raise HistoryError('the result is not valid: ' + '; '.join(problems))
    directory = os.path.join(root, *history_dir(suite).split('/'))
    name = result_file_name(result)
    existing = []
    if os.path.isdir(directory):
        existing = sorted(n for n in os.listdir(directory) if NAME_RE.match(n))
    if existing and name <= existing[-1]:
        raise HistoryError('%s would not be the newest history file of %s (newest: %s)' % (name, suite, existing[-1]))
    os.makedirs(directory, exist_ok=True)
    path = os.path.join(directory, name)
    try:
        with open(path, 'x', encoding='utf-8') as handle:
            json.dump(result, handle, indent=2, ensure_ascii=False)
            handle.write('\n')
    except FileExistsError:
        raise HistoryError('%s already exists' % name)
    return '%s/%s' % (history_dir(suite), name)

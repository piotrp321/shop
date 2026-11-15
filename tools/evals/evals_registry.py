"""The registry of the suites (tools/evals/suites.json), the config (tools/evals/config.json) and the fingerprint of a
suite. Python 3.9+, standard library only.

The fingerprint is the SHA-256 over what makes a measurement of a suite valid: the sorted paths and contents of its
watched files, of the files of its cases and of the part of the configuration that changes a pass (agent, executor, N,
pass cost cap, pass timeout, writes_results). Thresholds, the run cap and the cost estimate are not part of it: the verdict can be
recomputed from the stored metrics. The scoring code and the validators are hashed separately (`code_hashes`): their
change since a measurement is only a warning.
"""
import hashlib
import json
import re

REGISTRY_PATH = 'tools/evals/suites.json'
CONFIG_PATH = 'tools/evals/config.json'
SUITES_DIR = 'tools/evals/suites'
HISTORY_DIR = 'tools/evals/history'
EVALS_DIR = 'tools/evals'
MIN_N = 3
SCHEMA_VERSION = 1
DEFAULT_EXECUTOR = 'claude-cli'
NAME_RE = re.compile(r'^[a-z0-9][a-z0-9-]{0,63}$')
CASE_ID_RE = re.compile(r'^[a-z0-9][a-z0-9-]{0,79}$')

CONFIG_KEYS = {'schema_version', 'n', 'pass_cost_cap_usd', 'run_cost_cap_usd', 'pass_cost_estimate_usd',
               'pass_timeout_minutes', 'thresholds', 'usage', 'scoring_code'}
THRESHOLD_KEYS = ('correctness_drop_points', 'case_drop_divisor', 'cost_warning_percent', 'time_warning_percent')
USAGE_KEYS = ('start_max_percent', 'stop_percent')
SUITE_KEYS = {'agent', 'watched', 'cases', 'validators', 'executor', 'n', 'pass_cost_estimate_usd',
              'pass_cost_cap_usd', 'pass_timeout_minutes', 'writes_results'}
REGISTRY_KEYS = {'schema_version', 'canary_actions', 'suites'}


class ConfigError(Exception):
    """The registry or the config cannot be read or is not valid; `problems` lists what is wrong."""

    def __init__(self, message, problems=None):
        Exception.__init__(self, message)
        self.problems = problems or [message]


# ----------------------------------------------------------------------------------------------------- validation

def _number(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def _positive(value):
    return _number(value) and value > 0


def _integer(value):
    return isinstance(value, int) and not isinstance(value, bool)


def safe_relative_path(value):
    """True for a non-empty relative path with `/` separators and no `..` or empty component."""
    if not isinstance(value, str) or not value or value.startswith('/') or '\\' in value or '\0' in value:
        return False
    return all(part not in ('', '.', '..') for part in value.split('/'))


def _path_list(value, label, problems, allow_empty=True):
    if not isinstance(value, list) or (not allow_empty and not value):
        problems.append('%s must be a%s list of relative paths' % (label, '' if allow_empty else ' non-empty'))
        return []
    clean = []
    for item in value:
        if not safe_relative_path(item):
            problems.append('%s has an invalid path %r (relative, no `..`)' % (label, item))
        elif item in clean:
            problems.append('%s has the duplicate path %r' % (label, item))
        else:
            clean.append(item)
    return clean


def validate_config(data):
    """Problems of a parsed config.json (an empty list when it is valid)."""
    problems = []
    if not isinstance(data, dict):
        return ['config.json must be an object']
    for key in sorted(set(data) - CONFIG_KEYS):
        problems.append('config.json: unknown key %r' % key)
    if data.get('schema_version') != SCHEMA_VERSION:
        problems.append('config.json: schema_version must be %d' % SCHEMA_VERSION)
    if not _integer(data.get('n')) or data['n'] < MIN_N:
        problems.append('config.json: n must be an integer of at least %d (repetitions per case)' % MIN_N)
    for key in ('pass_cost_cap_usd', 'run_cost_cap_usd', 'pass_cost_estimate_usd', 'pass_timeout_minutes'):
        if not _positive(data.get(key)):
            problems.append('config.json: %s must be a positive number' % key)
    if _positive(data.get('run_cost_cap_usd')) and _positive(data.get('pass_cost_cap_usd')) \
            and data['run_cost_cap_usd'] < data['pass_cost_cap_usd']:
        problems.append('config.json: run_cost_cap_usd must not be below pass_cost_cap_usd')
    for block, keys in (('thresholds', THRESHOLD_KEYS), ('usage', USAGE_KEYS)):
        value = data.get(block)
        if not isinstance(value, dict):
            problems.append('config.json: %s must be an object' % block)
            continue
        for key in sorted(set(value) - set(keys)):
            problems.append('config.json: %s has an unknown key %r' % (block, key))
        for key in keys:
            if not _positive(value.get(key)):
                problems.append('config.json: %s.%s must be a positive number' % (block, key))
    if 'scoring_code' not in data:
        problems.append('config.json: scoring_code is missing (a list of files, may be empty)')
    else:
        _path_list(data['scoring_code'], 'config.json: scoring_code', problems)
    return problems


def validate_registry(data):
    """Problems of a parsed suites.json (an empty list when it is valid). Files on disk are checked by the lint."""
    problems = []
    if not isinstance(data, dict):
        return ['suites.json must be an object']
    for key in sorted(set(data) - REGISTRY_KEYS):
        problems.append('suites.json: unknown key %r' % key)
    if data.get('schema_version') != SCHEMA_VERSION:
        problems.append('suites.json: schema_version must be %d' % SCHEMA_VERSION)
    actions = data.get('canary_actions')
    if not isinstance(actions, list) or not actions or not all(isinstance(a, str) and a.strip() for a in actions) \
            or len(set(actions)) != len(actions):
        problems.append('suites.json: canary_actions must be a non-empty list of unique action names')
    suites = data.get('suites')
    if not isinstance(suites, dict) or not suites:
        problems.append('suites.json: suites must be a non-empty object')
        return problems
    for name, entry in suites.items():
        label = 'suites.json: suite %r' % name
        if not NAME_RE.match(name):
            problems.append('%s: the name must match %s' % (label, NAME_RE.pattern))
            continue
        if not isinstance(entry, dict):
            problems.append('%s must be an object' % label)
            continue
        for key in sorted(set(entry) - SUITE_KEYS):
            problems.append('%s: unknown key %r' % (label, key))
        agent = entry.get('agent')
        if not isinstance(agent, str) or not NAME_RE.match(agent):
            problems.append('%s: agent must be an agent name' % label)
        watched = _path_list(entry.get('watched'), label + ': watched', problems, allow_empty=False)
        if isinstance(agent, str) and NAME_RE.match(agent) and '.claude/agents/%s.md' % agent not in watched:
            problems.append('%s: watched must include the agent definition .claude/agents/%s.md' % (label, agent))
        if entry.get('cases') != '%s/%s' % (SUITES_DIR, name):
            problems.append('%s: cases must be %s/%s' % (label, SUITES_DIR, name))
        _path_list(entry.get('validators'), label + ': validators', problems)
        if 'executor' in entry and (not isinstance(entry['executor'], str) or not entry['executor'].strip()):
            problems.append('%s: executor must be a non-empty string' % label)
        if 'writes_results' in entry and not isinstance(entry['writes_results'], bool):
            problems.append('%s: writes_results must be true or false' % label)
        if 'n' in entry and (not _integer(entry['n']) or entry['n'] < MIN_N):
            problems.append('%s: n must be an integer of at least %d' % (label, MIN_N))
        for key in ('pass_cost_estimate_usd', 'pass_cost_cap_usd', 'pass_timeout_minutes'):
            if key in entry and not _positive(entry[key]):
                problems.append('%s: %s must be a positive number' % (label, key))
    return problems


# --------------------------------------------------------------------------------------------------------- loading

def read_json(source, rel):
    raw = source.read(rel)
    if raw is None:
        raise ConfigError('%s is missing (%s)' % (rel, source.description))
    try:
        return json.loads(raw.decode('utf-8'))
    except (ValueError, UnicodeDecodeError) as error:
        raise ConfigError('%s is not valid JSON: %s' % (rel, error))


def load(source):
    """(registry, config) read from a source and validated; ConfigError otherwise."""
    reg = read_json(source, REGISTRY_PATH)
    cfg = read_json(source, CONFIG_PATH)
    problems = validate_registry(reg) + validate_config(cfg)
    if problems:
        raise ConfigError('; '.join(problems), problems)
    return reg, cfg


def effective(entry, config):
    """The settings of one suite: the registry overrides on top of the config defaults."""
    return {
        'n': entry.get('n', config['n']),
        'pass_cost_cap_usd': entry.get('pass_cost_cap_usd', config['pass_cost_cap_usd']),
        'pass_cost_estimate_usd': entry.get('pass_cost_estimate_usd', config['pass_cost_estimate_usd']),
        'pass_timeout_minutes': entry.get('pass_timeout_minutes', config['pass_timeout_minutes']),
        'run_cost_cap_usd': config['run_cost_cap_usd'],
        'executor': entry.get('executor', DEFAULT_EXECUTOR),
        'writes_results': entry.get('writes_results', False),
    }


# ------------------------------------------------------------------------------------------------ fingerprinting

def _digest(items):
    """SHA-256 over (label, bytes or None) pairs, with lengths so no two lists give the same stream."""
    sha = hashlib.sha256()
    for label, content in items:
        name = label.encode('utf-8')
        sha.update(b'%d:' % len(name) + name)
        sha.update(b'-1:' if content is None else b'%d:' % len(content) + content)
    return sha.hexdigest()


def case_files(source, entry):
    """Every file of the cases of a suite: files below a case directory, not the files of the suite directory itself
    (its README is documentation, not part of a measurement)."""
    base = entry['cases'].rstrip('/') + '/'
    return [path for path in source.list_files(entry['cases']) if '/' in path[len(base):]]


def case_ids(source, entry):
    """The case directories of a suite (any directory with files, with or without case.json)."""
    base = entry['cases'].rstrip('/') + '/'
    return sorted({path[len(base):].split('/', 1)[0] for path in case_files(source, entry)})


def case_hashes(source, entry):
    """{case id: SHA-256 of its case.json} for the cases that have one."""
    hashes = {}
    base = entry['cases'].rstrip('/') + '/'
    for case_id in case_ids(source, entry):
        raw = source.read(base + case_id + '/case.json')
        if raw is not None:
            hashes[case_id] = hashlib.sha256(raw).hexdigest()
    return hashes


def fingerprint(source, name, entry, config):
    """The fingerprint of a suite as of a source."""
    eff = effective(entry, config)
    run_part = json.dumps({'suite': name, 'agent': entry['agent'], 'executor': eff['executor'], 'n': eff['n'],
                           'pass_cost_cap_usd': eff['pass_cost_cap_usd'],
                           'pass_timeout_minutes': eff['pass_timeout_minutes'],
                           'writes_results': eff['writes_results']}, sort_keys=True)
    paths = sorted(set(entry['watched']) | set(case_files(source, entry)))
    items = [('run-config', run_part.encode('utf-8'))] + [(path, source.read(path)) for path in paths]
    return _digest([('evals-fingerprint-v1', b'')] + items)


def code_hashes(source, entry, config):
    """The hashes recorded in a result: the scoring code (the files listed in config.json) and the validators of the
    suite (each path). A missing file hashes as missing, so its appearance or removal is a change."""
    scoring = _digest([(path, source.read(path)) for path in sorted(config['scoring_code'])])
    validators = {}
    for path in sorted(entry['validators']):
        validators[path] = _digest([(path, source.read(path))])
    return {'scoring_code_hash': scoring, 'validator_hashes': validators}

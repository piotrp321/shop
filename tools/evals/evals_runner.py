"""The eval runner: runs the cases of a suite N times with the executor of the suite, each pass in a fresh checkout
and in the AGENT-SECURITY sandbox, under the cost and usage limits (spec: Checkout przypadku, Uruchomienie agenta,
Sandbox, Artefakty przebiegu, Limity). Python 3.9+, standard library only.

  <state root>/<run id>/run.json                        the state of the run (for --resume and for the scoring)
  <state root>/<run id>/runs/<case>/<repetition>/       the pass directory (evals_guard, evals_checkout)

The runner does not score, compute a verdict or write a history file: a finished run (`done`) is read by the scoring.
A real pass starts only with valid sandbox settings: the profile is rendered and checked before the first pass (a
refusal leaves nothing behind) and again for every pass. There is no mode without the sandbox check; the tests run a
fake CLI with the same settings.
"""
import datetime
import fcntl
import json
import os
import queue
import secrets
import shutil
import signal
import statistics
import subprocess
import sys
import threading
import time

import evals_case as cases
import evals_checkout as checkout
import evals_executor as executors
import evals_guard as guard
import evals_history as history
import evals_registry as registry
import evals_repo as repo

HERE = os.path.dirname(os.path.abspath(__file__))
CLAUDE_PIN = os.path.join(os.path.dirname(os.path.dirname(HERE)), '.claude', 'skills', 'ao-run', 'claude_pin.py')
DEFAULT_STATE_ROOT = os.path.join(os.path.expanduser('~'), '.local', 'state', 'shop-evals')
SCHEMA_VERSION = 1
FINISHED = ('done', 'failed')
RESUMABLE = ('limit_stop', 'infra_error', 'running')
RUNNER_ARTEFACTS = ('status.json', 'output.txt', 'tree.txt', 'canary.txt')
PIN_TIMEOUT_S = 120
KILL_GRACE_S = 5
STDERR_LIMIT = 1 << 20


class RunError(Exception):
    """The run cannot start or be resumed (a refusal: nothing was run)."""


class Options:
    """Where and with what a run executes. claude_bin replaces the binary from claude_pin.py (tests: the fake CLI);
    render_script and pin_script replace the scripts of AGENT-SECURITY and AO (tests only; the settings rendered by any
    script are always checked); env is the environment the executor's allowlist is taken from."""

    def __init__(self, root, state_root=None, claude_bin=None, render_script=None, pin_script=None, env=None):
        self.root = os.path.realpath(root)
        self.state_root = state_root or DEFAULT_STATE_ROOT
        self.claude_bin = claude_bin
        self.render_script = render_script
        self.pin_script = pin_script or CLAUDE_PIN
        self.env = dict(os.environ) if env is None else env


def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).strftime(history.DATE_FORMAT)


def pass_dir(state_root, run_id, case_id, repetition):
    return os.path.join(os.path.realpath(state_root), run_id, 'runs', case_id, str(repetition))


# ------------------------------------------------------------------------------------------------- the suite

def _load(root):
    try:
        return registry.load(repo.DiskSource(root))
    except registry.ConfigError as error:
        raise RunError('%s (run `evals.py lint`)' % error)


def _suite(reg, name):
    if name not in reg['suites']:
        raise RunError('unknown suite %r (suites: %s)' % (name, ', '.join(sorted(reg['suites']))))
    return reg['suites'][name]


def _cases(root, reg, suite, entry, only=None):
    ids = registry.case_ids(repo.DiskSource(root), entry)
    if only is not None:
        if only not in ids:
            raise RunError('the suite %s has no case %r' % (suite, only))
        ids = [only]
    if not ids:
        raise RunError('the suite %s has no cases in %s' % (suite, entry['cases']))
    try:
        return [cases.Case(root, entry, case_id, suite, reg['canary_actions']) for case_id in ids]
    except cases.CaseError as error:
        raise RunError(str(error))


def _measured_paths(entry):
    return sorted(set([registry.REGISTRY_PATH, registry.CONFIG_PATH, entry['cases']] + list(checkout.OVERLAY_DIRS)
                      + entry['watched']))


def _require_clean(root, entry):
    """The overlay comes from a commit and the fingerprint from the disk: both must be the same files."""
    try:
        out = repo.git(root, 'status', '--porcelain', '--untracked-files=all', '--', *_measured_paths(entry))[1]
    except repo.GitError as error:
        raise RunError('cannot read the state of the repository: %s' % error)
    if out.strip():
        raise RunError('uncommitted changes in the measured files (commit them first):\n%s'
                       % out.decode('utf-8', 'replace').rstrip())


# --------------------------------------------------------------------------------------------- the forecast

def _numbers(values):
    return [v for v in values if isinstance(v, (int, float)) and not isinstance(v, bool)]


def forecast(root, suite, case=None):
    """The cost and time forecast of a run of a suite (or of a trial run of one case): the median cost and duration
    of a pass in the last measurement of the suite times the number of passes, without a measurement the cost
    estimate of the registry and the pass timeout (an upper bound) times the number of passes."""
    reg, cfg = _load(root)
    entry = _suite(reg, suite)
    eff = registry.effective(entry, cfg)
    source = repo.DiskSource(root)
    ids = registry.case_ids(source, entry)
    if case is not None:
        if case not in ids:
            raise RunError('the suite %s has no case %r' % (suite, case))
        ids = [case]
    passes = len(ids) * eff['n']
    try:
        previous = history.previous_measurement(source, suite)
    except history.HistoryError as error:
        raise RunError('%s (run `evals.py lint`)' % error)
    repetitions = [r for c in (previous[1].get('cases') or []) for r in (c.get('repetitions') or [])
                   if isinstance(r, dict)] if previous else []
    costs = _numbers(r.get('cost_usd') for r in repetitions)
    durations = _numbers(r.get('duration_s') for r in repetitions)
    pass_cost = statistics.median(costs) if costs else eff['pass_cost_estimate_usd']
    pass_s = statistics.median(durations) if durations else eff['pass_timeout_minutes'] * 60.0
    cap = eff['n'] * eff['pass_cost_cap_usd'] if case is not None else eff['run_cost_cap_usd']
    cost = pass_cost * passes
    return {'suite': suite, 'case': case, 'cases': len(ids), 'n': eff['n'], 'passes': passes,
            'pass_cost_usd': pass_cost, 'cost_usd': cost,
            'cost_source': ('the median of %s' % previous[0]) if costs else 'the estimate of the registry',
            'pass_duration_s': pass_s, 'duration_s': pass_s * passes,
            'duration_source': ('the median of %s' % previous[0]) if durations else 'the pass timeout (upper bound)',
            'cap_usd': cap, 'within_cap': cost <= cap}


# ------------------------------------------------------------------------------------------------ the state

def _write_json(path, data):
    temporary = path + '.tmp'
    with open(temporary, 'w', encoding='utf-8') as handle:
        json.dump(data, handle, indent=2, ensure_ascii=False)
        handle.write('\n')
    os.replace(temporary, path)


def load_run(state_root, run_id):
    if not run_id or '/' in run_id or run_id.startswith('.'):
        raise RunError('%r is not a run id' % (run_id,))
    path = os.path.join(os.path.realpath(state_root), run_id, 'run.json')
    try:
        with open(path, encoding='utf-8') as handle:
            return json.load(handle)
    except FileNotFoundError:
        raise RunError('there is no run %s in %s' % (run_id, state_root))
    except ValueError as error:
        raise RunError('%s is not valid JSON: %s' % (path, error))


class _Lock:
    def __init__(self, run_dir):
        self.handle = open(os.path.join(run_dir, 'run.lock'), 'a')
        try:
            fcntl.flock(self.handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            self.handle.close()
            raise RunError('the run in %s is in progress in another process' % run_dir)

    def release(self):
        self.handle.close()


def _state_root(options):
    root = os.path.abspath(options.state_root)
    try:
        os.makedirs(root, exist_ok=True)
    except OSError as error:
        raise RunError('the state directory %s cannot be created: %s' % (root, error))
    if not os.path.isdir(root):
        raise RunError('the state directory %s is not a directory' % root)
    return os.path.realpath(root)


# ---------------------------------------------------------------------------------------------- the context

class _Context:
    """What every pass of a run needs, read again at every start and resume."""

    def __init__(self, options, run):
        self.options = options
        reg, cfg = _load(options.root)
        self.entry = _suite(reg, run['suite'])
        self.eff = registry.effective(self.entry, cfg)
        if self.eff['executor'] not in executors.EXECUTORS:
            raise RunError('the suite %s has the unknown executor %r (executors: %s)'
                           % (run['suite'], self.eff['executor'], ', '.join(sorted(executors.EXECUTORS))))
        self.executor = executors.EXECUTORS[self.eff['executor']]()
        self.stop_percent = cfg['usage']['stop_percent']
        self.cases = {c.id: c for c in _cases(options.root, reg, run['suite'], self.entry, run.get('case'))}
        self.fingerprint = registry.fingerprint(repo.DiskSource(options.root), run['suite'], self.entry, cfg)
        self.binary = None

    def preflight(self, run_dir):
        """The sandbox settings rendered for a throwaway pass: a refusal before the first pass."""
        probe = os.path.join(run_dir, 'preflight')
        os.makedirs(os.path.join(probe, 'checkout'))
        try:
            guard.render_settings(os.path.join(probe, 'checkout'), probe, [os.path.join(probe, 'runner')],
                                  script=self.options.render_script)
        except guard.SandboxError as error:
            raise RunError('refused before the first pass: %s' % error)
        finally:
            shutil.rmtree(probe, ignore_errors=True)

    def resolve_binary(self):
        """The CLI binary: --claude-bin, or the binary of the version pinned by AO (claude_pin.py). None and the
        reason when it cannot be found."""
        if self.options.claude_bin:
            path = os.path.realpath(self.options.claude_bin)
            if os.path.isfile(path) and os.access(path, os.X_OK):
                return path, None
            return None, 'the CLI binary %s is not an executable file' % path
        try:
            result = subprocess.run([sys.executable, '-I', self.options.pin_script], stdout=subprocess.PIPE,
                                    stderr=subprocess.PIPE, universal_newlines=True, timeout=PIN_TIMEOUT_S)
        except (OSError, subprocess.TimeoutExpired) as error:
            return None, 'claude_pin.py could not run: %s' % error
        path = result.stdout.strip()
        if result.returncode != 0 or not path:
            return None, 'no pinned CLI binary (claude_pin.py exit %d): %s' % (result.returncode,
                                                                                result.stderr.strip()[:500])
        return path, None


# ----------------------------------------------------------------------------------------------- start/resume

def _new_id(suite, case):
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime(history.NAME_DATE_FORMAT)
    prefix = 'trial-%s-%s' % (suite, case) if case else suite
    return '%s-%s-%s' % (prefix, stamp, secrets.token_hex(2))


def start(options, suite, case=None, overlay_rev=None):
    """Starts a run of a suite (or a trial run of one case with the overlay of overlay_rev) and executes it until it
    ends; returns the final state of the run. RunError when the run is refused before its first pass."""
    if overlay_rev is not None and case is None:
        raise RunError('--overlay-rev is for a trial run of one case (--case)')
    reg, _ = _load(options.root)
    entry = _suite(reg, suite)
    _require_clean(options.root, entry)
    try:
        head = repo.head_commit(options.root)
        overlay = repo.resolve_commit(options.root, overlay_rev or 'HEAD')
    except repo.GitError as error:
        raise RunError(str(error))
    run = {'schema_version': SCHEMA_VERSION, 'id': _new_id(suite, case), 'kind': 'trial' if case else 'suite',
           'suite': suite, 'case': case, 'status': 'running', 'reason': '', 'head': head, 'overlay_rev': overlay}
    context = _Context(options, run)
    plan = forecast(options.root, suite, case)
    if not plan['within_cap']:
        raise RunError('the forecast of %.2f USD (%d passes at %.2f USD, %s) is above the cap of %.2f USD'
                       % (plan['cost_usd'], plan['passes'], plan['pass_cost_usd'], plan['cost_source'],
                          plan['cap_usd']))
    run.update({'fingerprint': context.fingerprint, 'executor': context.executor.name, 'agent': context.entry['agent'],
                'n': context.eff['n'], 'pass_cost_cap_usd': context.eff['pass_cost_cap_usd'],
                'pass_timeout_minutes': context.eff['pass_timeout_minutes'],
                'writes_results': context.eff['writes_results'], 'cap_usd': plan['cap_usd'],
                'forecast_usd': plan['cost_usd'], 'cost_usd_total': 0.0, 'started': utc_now(),
                'cases': [{'id': c.id, 'case_hash': c.hash, 'kind': c.kind} for c in context.cases.values()],
                'passes': [{'case': c.id, 'repetition': r, 'status': 'pending', 'reason': '', 'cost_usd': None,
                            'charged_usd': 0.0, 'duration_s': None, 'attempts': 0}
                           for c in context.cases.values() for r in range(1, context.eff['n'] + 1)]})
    state_root = _state_root(options)
    run_dir = os.path.join(state_root, run['id'])
    os.makedirs(run_dir)
    lock = _Lock(run_dir)
    try:
        try:
            context.preflight(run_dir)
        except RunError:
            lock.release()
            lock = None
            shutil.rmtree(run_dir, ignore_errors=True)
            raise
        return _execute(context, run, state_root, run_dir)
    finally:
        if lock:
            lock.release()


def resume(options, run_id):
    """Resumes a run stopped by limit_stop or infra_error (or interrupted) from its first unfinished pass, when the
    fingerprint of the suite did not change; the cost of every part counts towards the same cap."""
    state_root = _state_root(options)
    run = load_run(state_root, run_id)
    run_dir = os.path.join(state_root, run_id)
    if run.get('status') == 'cost_stop':
        raise RunError('the run %s reached the cost cap (cost_stop): it is a failed run and cannot be resumed; start a '
                       'new run' % run_id)
    if run.get('status') not in RESUMABLE:
        raise RunError('the run %s is %s: nothing to resume' % (run_id, run.get('status')))
    lock = _Lock(run_dir)
    try:
        reg, _ = _load(options.root)
        _require_clean(options.root, _suite(reg, run['suite']))
        context = _Context(options, run)
        if context.fingerprint != run['fingerprint']:
            raise RunError('the fingerprint of %s changed since the run started: the run cannot be resumed; start a '
                           'new run' % run['suite'])
        context.preflight(run_dir)
        run['status'], run['reason'] = 'running', ''
        return _execute(context, run, state_root, run_dir)
    finally:
        lock.release()


def _execute(context, run, state_root, run_dir):
    run_file = os.path.join(run_dir, 'run.json')

    def save(status=None, reason=None):
        if status:
            run['status'] = status
        if reason is not None:
            run['reason'] = reason
        run['updated'] = utc_now()
        _write_json(run_file, run)

    save('running')
    binary, problem = context.resolve_binary()
    if binary is None:
        save('infra_error', problem)
        return run
    context.binary = binary
    for item in run['passes']:
        if item['status'] in FINISHED:
            continue
        if run['cost_usd_total'] + run['pass_cost_cap_usd'] > run['cap_usd'] + 1e-9:
            reason = ('the cost so far (%.2f USD) plus the pass cap (%.2f USD) is above the run cap (%.2f USD)'
                      % (run['cost_usd_total'], run['pass_cost_cap_usd'], run['cap_usd']))
            directory = pass_dir(state_root, run['id'], item['case'], item['repetition'])
            _fresh_dir(directory)
            _artefacts(directory, {'status': 'cost_stop', 'reason': reason}, item, run)
            item.update(status='cost_stop', reason=reason)
            save('cost_stop', reason)
            return run
        item['attempts'] += 1
        save()
        outcome = _run_pass(context, run, state_root, item)
        item.update(status=outcome['status'], reason=outcome['reason'], cost_usd=outcome['cost_usd'],
                    duration_s=outcome['duration_s'])
        item['charged_usd'] += outcome['charged_usd']
        run['cost_usd_total'] = round(run['cost_usd_total'] + outcome['charged_usd'], 6)
        if outcome['status'] not in FINISHED:
            save(outcome['status'], 'pass %s/%s: %s' % (item['case'], item['repetition'], outcome['reason']))
            return run
        save()
    save('done', '')
    return run


# ------------------------------------------------------------------------------------------------- one pass

def _fresh_dir(directory):
    if os.path.lexists(directory):
        if os.path.islink(directory) or not os.path.isdir(directory):
            os.unlink(directory)
        else:
            shutil.rmtree(directory)
    os.makedirs(directory)


def _artefacts(directory, outcome, item, run, extra=None, output='', tree=(), canary=None):
    """The artefacts of a pass (spec: Artefakty przebiegu), written after the executor ended; every file the agent
    could have planted is replaced, not followed."""
    status = {'schema_version': SCHEMA_VERSION, 'run': run['id'], 'suite': run['suite'], 'case': item['case'],
              'repetition': item['repetition'], 'status': outcome['status'], 'reason': outcome.get('reason', ''),
              'cost_usd': outcome.get('cost_usd'), 'duration_s': outcome.get('duration_s'),
              'cli_version': outcome.get('cli_version'), 'model': outcome.get('model'),
              'plugins': outcome.get('plugins') or [], 'mcp_servers': outcome.get('mcp_servers'),
              'sandbox': bool(outcome.get('sandbox')), 'executor': run.get('executor')}
    status.update(extra or {})
    guard.replace_file(os.path.join(directory, 'status.json'), json.dumps(status, indent=2, ensure_ascii=False) + '\n')
    guard.replace_file(os.path.join(directory, 'output.txt'), output or '')
    guard.replace_file(os.path.join(directory, 'tree.txt'), ''.join(line + '\n' for line in tree))
    guard.replace_file(os.path.join(directory, 'canary.txt'), '%s\n%s\n' % canary if canary else '')
    for name in ('gh.log', 'ssh.log'):
        path = os.path.join(directory, name)
        if not os.path.lexists(path):
            guard.replace_file(path, '')
    files = os.path.join(directory, 'files')
    if not os.path.lexists(files):
        os.makedirs(files)


def _run_pass(context, run, state_root, item):
    case = context.cases[item['case']]
    directory = pass_dir(state_root, run['id'], item['case'], item['repetition'])
    _fresh_dir(directory)
    for sub in ('files', 'input'):
        os.makedirs(os.path.join(directory, sub))
    started = time.monotonic()
    canary = None
    extra = {'started': utc_now()}
    try:
        values = {'PASS_DIR': directory, 'RESULT_DIR': os.path.join(directory, 'files'),
                  'INPUT_DIR': os.path.join(directory, 'input'), 'CANARY_FILE': '', 'CANARY_TOKEN': ''}
        if case.injection:
            token = 'CANARY-' + secrets.token_hex(8)
            path = os.path.join(directory, case.data.get('canary_file') or 'canary-' + token[7:15])
            canary = (path, token)
            values.update(CANARY_FILE=path, CANARY_TOKEN=token, PAYLOAD=case.payload())
        patch = case.patch_template()
        if patch is not None:
            patch = cases.render(patch, values).encode('utf-8')
        built = checkout.build(context.options.root, directory, case.state, run['overlay_rev'],
                               context.entry['watched'], patch=patch)
        values.update(BASE=built['base'], HEAD=built['head'])
        extra['commits'] = {'base': built['base'], 'head': built['head'], 'overlay': built['overlay']}
        prompt = cases.render(case.prompt_template(), values)
        for name, text in case.inputs():
            target = os.path.join(directory, 'input', *name.split('/'))
            os.makedirs(os.path.dirname(target), exist_ok=True)
            guard.replace_file(target, cases.render(text, values))
        answers = [dict(a, stdout=cases.render(a['stdout'], values)) for a in case.gh()]
        layout = guard.prepare(directory, answers)
        _write_json(os.path.join(layout['runner'], 'manifest.json'), built['manifest'])
        guard.replace_file(os.path.join(layout['runner'], 'prompt.txt'), prompt)
        deny = [layout['runner']] + [os.path.join(directory, name) for name in RUNNER_ARTEFACTS]
        settings = guard.render_settings(built['checkout'], directory, deny, script=context.options.render_script)
        settings_path = os.path.join(layout['runner'], 'settings.json')
        guard.replace_file(settings_path, json.dumps(settings, indent=2) + '\n')
        env = guard.environment(layout, context.options.env)
    except (checkout.CheckoutError, cases.CaseError, guard.SandboxError, guard.GuardError, OSError) as error:
        outcome = {'status': 'infra_error', 'reason': 'the pass could not be prepared: %s' % error,
                   'duration_s': round(time.monotonic() - started, 3)}
        _artefacts(directory, outcome, item, run, dict(extra, finished=utc_now()), canary=canary)
        return dict(outcome, cost_usd=None, charged_usd=0.0)
    add_dirs = [os.path.join(directory, 'files')] if context.eff['writes_results'] else []
    command = context.executor.command(context.binary, prompt, context.entry['agent'], run['pass_cost_cap_usd'],
                                       settings_path, add_dirs)
    stream = context.executor.stream(context.stop_percent)
    code, timed_out, stderr, error = _spawn(command, built['checkout'], env, layout['runner'], stream,
                                            run['pass_timeout_minutes'] * 60.0)
    if error:
        outcome = {'status': 'infra_error', 'reason': 'the CLI could not be started: %s' % error, 'cost_usd': None}
    else:
        outcome = stream.outcome(code, timed_out, run['pass_cost_cap_usd'], stderr=stderr)
        outcome['sandbox'] = True
    outcome['duration_s'] = round(time.monotonic() - started, 3)
    try:
        guard.collect_git_attempts(layout)
    except OSError:
        pass
    tree = checkout.tree_changes(built['checkout'], built['manifest'])
    extra.update(finished=utc_now(), exit_code=code)
    _artefacts(directory, outcome, item, run, extra, output=outcome.get('output', ''), tree=tree, canary=canary)
    if outcome.get('cost_usd') is not None:
        charged = float(outcome['cost_usd'])
    elif outcome['status'] != 'infra_error' and getattr(stream, 'init', None) is not None:
        # an unknown cost after init (a timeout, a stop on the usage limit, a stream without a result): the pass cap
        # is the upper bound; an infra_error stops at init, before any model call
        charged = float(run['pass_cost_cap_usd'])
    else:
        charged = 0.0
    return dict(outcome, charged_usd=charged)


def _kill(process):
    for sig, wait in ((signal.SIGTERM, KILL_GRACE_S), (signal.SIGKILL, None)):
        try:
            os.killpg(process.pid, sig)
        except OSError:
            pass
        try:
            process.wait(timeout=wait)
            return
        except subprocess.TimeoutExpired:
            continue


def _spawn(command, cwd, env, runner_dir, stream, timeout_s):
    """Runs the executor; its events go to runner/stream.jsonl and its tool calls to runner/tool_use.jsonl (a
    directory the sandbox write-protects). Returns (exit code, timed out, stderr tail, start error)."""
    stderr_path = os.path.join(runner_dir, 'stderr.txt')
    stderr_file = open(stderr_path, 'wb')
    try:
        process = subprocess.Popen(command, cwd=cwd, env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                   stderr=stderr_file, start_new_session=True)
    except OSError as error:
        stderr_file.close()
        return None, False, '', str(error)
    lines = queue.Queue()

    def reader():
        for raw in process.stdout:
            lines.put(raw)
        lines.put(None)
    thread = threading.Thread(target=reader, daemon=True)
    thread.start()
    deadline = time.monotonic() + timeout_s
    timed_out = False
    try:
        timed_out = _read(process, lines, deadline, runner_dir, stream)
    finally:
        _kill(process)   # also on an exception or an interrupt of the runner: no executor outlives its pass
    thread.join(timeout=KILL_GRACE_S)
    process.stdout.close()
    stderr_file.close()
    with open(stderr_path, 'rb') as handle:
        handle.seek(max(0, os.path.getsize(stderr_path) - STDERR_LIMIT))
        stderr = handle.read().decode('utf-8', 'replace')
    return process.returncode, timed_out, stderr, None


def _read(process, lines, deadline, runner_dir, stream):
    """Reads the events until the end of the stream, a stop signal or the deadline; True when the deadline came."""
    with open(os.path.join(runner_dir, 'stream.jsonl'), 'wb') as events, \
            open(os.path.join(runner_dir, 'tool_use.jsonl'), 'w', encoding='utf-8') as tool_uses:
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                return True
            try:
                raw = lines.get(timeout=remaining)
            except queue.Empty:
                return True
            if raw is None:
                return False
            events.write(raw)
            events.flush()
            signal_ = stream.feed(raw.decode('utf-8', 'replace'))
            for call in stream.take_tool_uses():
                tool_uses.write(json.dumps(call, ensure_ascii=False) + '\n')
                tool_uses.flush()
            if signal_:
                return False

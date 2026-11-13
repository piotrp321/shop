"""Low-level building blocks of the parallel epics gate for /ao-run. Python 3.9+, standard library only.

Imported by night_queue.py and pairs.py; never imports night_queue. Contents:

- state_lock: exclusive flock on <state>/gate.lock, polled until a timeout (LockTimeout on expiry).
- write_atomic: temp file in the same directory plus os.replace (StateFileError, no temp file left on failure).
- claims file <state>/claims.json: {"claims": [{dir, claim, at, expires_at}], "owners": {dir: token}}. Reservations
  (30 minutes, renewed with the same token, ClaimConflict for another token), release, owners, claim_to_owner.
  Writers hold state_lock. A missing file is empty; an unreadable or wrong-shaped one is StateFileError.
- file classification of the gate (is_allowed, is_claude_md, common_paths) and GENERATED files.
- Docker memory snapshot (docker_snapshot with an injected runner, DockerError) and free_mib.
- orchestrator_running: probes <ao_home>/orchestrator.lock with a shared non-blocking flock; never creates the file.
"""
import contextlib
import fcntl
import json
import os
import re
import secrets
import sys
import tempfile
import time
from datetime import datetime, timedelta

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import next_epic  # noqa: E402

CLAIM_TTL = timedelta(minutes=30)

GENERATED = ('apps/api/openapi.json', 'apps/api/openapi-admin.json', 'apps/store/src/api/schema.d.ts',
             'apps/admin/src/api/schema.d.ts')


class LockTimeout(Exception):
    pass


class StateFileError(Exception):
    pass


class DockerError(Exception):
    pass


class ClaimConflict(Exception):
    pass


@contextlib.contextmanager
def state_lock(state_dir, timeout=120.0, poll=0.2):
    state_dir = os.path.expanduser(state_dir)
    os.makedirs(state_dir, exist_ok=True)
    fd = os.open(os.path.join(state_dir, 'gate.lock'), os.O_RDWR | os.O_CREAT, 0o644)
    try:
        deadline = time.monotonic() + timeout
        while True:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise LockTimeout('blokada bramki zajęta')
                time.sleep(poll)
        try:
            yield
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
    finally:
        os.close(fd)


def write_atomic(path, text):
    directory = os.path.dirname(os.path.abspath(path))
    tmp = None
    try:
        fd, tmp = tempfile.mkstemp(dir=directory, prefix='.tmp-')
        with os.fdopen(fd, 'w') as f:
            f.write(text)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
        tmp = None
    except OSError as e:
        raise StateFileError('zapis %s: %s' % (path, e.strerror or e))
    finally:
        if tmp is not None:
            with contextlib.suppress(OSError):
                os.unlink(tmp)


def _claims_path(state_dir):
    return os.path.join(os.path.expanduser(state_dir), 'claims.json')


def read_claims(state_dir):
    path = _claims_path(state_dir)
    try:
        with open(path) as f:
            data = json.load(f)
    except FileNotFoundError:
        return {'claims': [], 'owners': {}}
    except (OSError, ValueError) as e:
        raise StateFileError('nieczytelny plik %s: %s' % (path, e))
    claims = data.get('claims', []) if isinstance(data, dict) else None
    owners = data.get('owners', {}) if isinstance(data, dict) else None
    if (not isinstance(claims, list) or not isinstance(owners, dict)
            or not all(isinstance(c, dict) and {'dir', 'claim', 'at', 'expires_at'} <= set(c) for c in claims)):
        raise StateFileError('nieczytelny plik %s: zły kształt' % path)
    return {'claims': claims, 'owners': owners}


def _write_claims(state_dir, data):
    write_atomic(_claims_path(state_dir), json.dumps(data, indent=2, sort_keys=True) + '\n')


def _is_valid(claim, now):
    try:
        return datetime.fromisoformat(claim['expires_at']) > now
    except (ValueError, TypeError):
        raise StateFileError('nieczytelny plik claims.json: zły czas %r' % (claim['expires_at'],))


def valid_claims(data, now):
    return [c for c in data['claims'] if _is_valid(c, now)]


def reserve(state_dir, directory, now, claim=None):
    data = read_claims(state_dir)
    data['claims'] = valid_claims(data, now)
    expires = (now + CLAIM_TTL).isoformat()
    existing = next((c for c in data['claims'] if c['dir'] == directory), None)
    if existing is not None:
        if claim is None or existing['claim'] != claim:
            raise ClaimConflict('rezerwacja %s zajęta' % directory)
        existing['expires_at'] = expires
        token = claim
    else:
        token = claim or secrets.token_hex(8)
        data['claims'].append({'dir': directory, 'claim': token, 'at': now.isoformat(), 'expires_at': expires})
    _write_claims(state_dir, data)
    return token


def release(state_dir, directory, claim):
    data = read_claims(state_dir)
    kept = [c for c in data['claims'] if not (c['dir'] == directory and c['claim'] == claim)]
    if len(kept) == len(data['claims']):
        return False
    data['claims'] = kept
    _write_claims(state_dir, data)
    return True


def owner(data, directory):
    return data['owners'].get(directory)


def claim_to_owner(state_dir, directory, claim, now):
    data = read_claims(state_dir)
    token = claim or secrets.token_hex(8)
    data['claims'] = [c for c in data['claims']
                      if c['dir'] != directory or (c['claim'] != token and _is_valid(c, now))]
    data['owners'][directory] = token
    _write_claims(state_dir, data)
    return token


def is_allowed(path):
    if is_claude_md(path):
        return False
    return path in GENERATED or path == 'docs' or path.startswith('docs/') or path == 'README.md'


def is_claude_md(path):
    return path.split('/')[-1] == 'CLAUDE.md'


def _inside(inner, outer):
    return inner == outer or inner.startswith(outer + '/')


def common_paths(a, b):
    found = set()
    for x in a:
        for y in b:
            if _inside(x, y):
                found.add(x)
            elif _inside(y, x):
                found.add(y)
    return sorted(found)


_UNITS = {'B': 1, 'KIB': 1024, 'MIB': 1024 ** 2, 'GIB': 1024 ** 3, 'TIB': 1024 ** 4,
          'KB': 1000, 'MB': 1000 ** 2, 'GB': 1000 ** 3, 'TB': 1000 ** 4}
_MEM = re.compile(r'^\s*([0-9]+(?:\.[0-9]+)?)\s*([A-Za-z]+)\s*(?:/.*)?$')


def _mem_mib(text):
    m = _MEM.match(text)
    unit = _UNITS.get(m.group(2).upper()) if m else None
    if unit is None:
        raise DockerError('docker: nieczytelne wyjście')
    return float(m.group(1)) * unit / 1024 ** 2


def docker_snapshot(run):
    def call(args):
        try:
            return run(args)
        except next_epic.CommandError as e:
            raise DockerError('docker: %s' % e)

    info = call(['docker', 'info', '--format', '{{.MemTotal}}'])
    try:
        total = int(info.strip()) / 1024 ** 2
    except ValueError:
        raise DockerError('docker: nieczytelne wyjście')
    projects = {}
    for line in call(['docker', 'ps', '--format', '{{.Names}}\t{{.Label "com.docker.compose.project"}}']).splitlines():
        if line.strip():
            name, _, project = line.partition('\t')
            projects[name] = project.strip()
    ao = other = 0.0
    for line in call(['docker', 'stats', '--no-stream', '--format', '{{.Name}}\t{{.MemUsage}}']).splitlines():
        if not line.strip():
            continue
        name, sep, usage = line.partition('\t')
        if not sep:
            raise DockerError('docker: nieczytelne wyjście')
        mib = _mem_mib(usage)
        if projects.get(name, '').startswith('ao-'):
            ao += mib
        else:
            other += mib
    return {'total_mib': round(total, 1), 'ao_mib': round(ao, 1), 'other_mib': round(other, 1)}


def free_mib(snapshot, active, stack_mib):
    return snapshot['total_mib'] - snapshot['other_mib'] - max(snapshot['ao_mib'], stack_mib * active)


def orchestrator_running(ao_home):
    path = os.path.join(os.path.expanduser(ao_home), 'orchestrator.lock')
    try:
        fd = os.open(path, os.O_RDONLY)
    except (FileNotFoundError, NotADirectoryError):
        return False
    except OSError:
        return True
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
        except BlockingIOError:
            return True
        fcntl.flock(fd, fcntl.LOCK_UN)
        return False
    finally:
        os.close(fd)

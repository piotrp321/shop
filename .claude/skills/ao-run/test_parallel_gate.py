"""Tests of parallel_gate.py: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'"""
import fcntl
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('parallel_gate', os.path.join(HERE, 'parallel_gate.py'))
pg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pg)
import next_epic  # noqa: E402  (loaded by parallel_gate via sys.path)

NOW = datetime(2026, 11, 13, 12, 0, tzinfo=timezone.utc)

HOLDER = (
    "import fcntl,sys\n"
    "f=open(sys.argv[1],%r)\n"
    "fcntl.flock(f,%s)\n"
    "print('held',flush=True)\n"
    "sys.stdin.read()\n"
)


def hold(path, mode='a', flag='fcntl.LOCK_EX'):
    proc = subprocess.Popen([sys.executable, '-c', HOLDER % (mode, flag), path],
                            stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
    assert proc.stdout.readline().strip() == 'held'
    return proc


def release_child(proc):
    proc.stdin.close()
    proc.wait(10)
    proc.stdout.close()


class StateLockTest(unittest.TestCase):
    def test_acquire_and_release(self):
        with tempfile.TemporaryDirectory() as d:
            state = os.path.join(d, 'sub', 'state')
            with pg.state_lock(state):
                self.assertTrue(os.path.exists(os.path.join(state, 'gate.lock')))
                with self.assertRaises(pg.LockTimeout):
                    with pg.state_lock(state, timeout=0.3, poll=0.05):
                        pass
            with pg.state_lock(state, timeout=0.3):
                pass

    def test_other_process_holds(self):
        with tempfile.TemporaryDirectory() as d:
            proc = hold(os.path.join(d, 'gate.lock'))
            try:
                with self.assertRaisesRegex(pg.LockTimeout, 'blokada bramki zajęta'):
                    with pg.state_lock(d, timeout=0.5, poll=0.05):
                        pass
            finally:
                release_child(proc)
            with pg.state_lock(d, timeout=1):
                pass


class WriteAtomicTest(unittest.TestCase):
    def test_replaces_content_without_temp_files(self):
        with tempfile.TemporaryDirectory() as d:
            p = os.path.join(d, 'f.json')
            pg.write_atomic(p, 'one')
            pg.write_atomic(p, 'two')
            with open(p) as f:
                self.assertEqual('two', f.read())
            self.assertEqual(['f.json'], os.listdir(d))

    def test_missing_directory(self):
        with tempfile.TemporaryDirectory() as d:
            with self.assertRaises(pg.StateFileError):
                pg.write_atomic(os.path.join(d, 'nope', 'f'), 'x')

    def test_read_only_directory_keeps_old_file(self):
        with tempfile.TemporaryDirectory() as d:
            p = os.path.join(d, 'f')
            pg.write_atomic(p, 'old')
            os.chmod(d, 0o500)
            try:
                if os.access(d, os.W_OK):
                    self.skipTest('directory still writable (root)')
                with self.assertRaises(pg.StateFileError):
                    pg.write_atomic(p, 'new')
            finally:
                os.chmod(d, 0o700)
            with open(p) as f:
                self.assertEqual('old', f.read())
            self.assertEqual(['f'], os.listdir(d))


class ClaimsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = self.tmp.name

    def tearDown(self):
        self.tmp.cleanup()

    def test_missing_file_is_empty(self):
        self.assertEqual({'claims': [], 'owners': {}}, pg.read_claims(self.dir))

    def test_reserve_new_writes_iso_times(self):
        token = pg.reserve(self.dir, 'cart', NOW)
        self.assertEqual(16, len(token))
        data = pg.read_claims(self.dir)
        c = data['claims'][0]
        self.assertEqual(('cart', token), (c['dir'], c['claim']))
        self.assertEqual(NOW.isoformat(), c['at'])
        self.assertEqual((NOW + timedelta(minutes=30)).isoformat(), c['expires_at'])

    def test_renew_same_token(self):
        token = pg.reserve(self.dir, 'cart', NOW)
        later = NOW + timedelta(minutes=10)
        self.assertEqual(token, pg.reserve(self.dir, 'cart', later, claim=token))
        claims = pg.read_claims(self.dir)['claims']
        self.assertEqual(1, len(claims))
        self.assertEqual((later + timedelta(minutes=30)).isoformat(), claims[0]['expires_at'])

    def test_conflict_other_token(self):
        pg.reserve(self.dir, 'cart', NOW)
        with self.assertRaises(pg.ClaimConflict):
            pg.reserve(self.dir, 'cart', NOW, claim='other')
        with self.assertRaises(pg.ClaimConflict):
            pg.reserve(self.dir, 'cart', NOW)

    def test_expired_ignored_and_pruned(self):
        old = pg.reserve(self.dir, 'cart', NOW)
        pg.reserve(self.dir, 'seo', NOW)
        later = NOW + timedelta(minutes=31)
        new = pg.reserve(self.dir, 'cart', later)
        self.assertNotEqual(old, new)
        data = pg.read_claims(self.dir)
        self.assertEqual({'cart'}, {c['dir'] for c in data['claims']})
        self.assertEqual([new], [c['claim'] for c in pg.valid_claims(data, later)])
        self.assertEqual([], pg.valid_claims(data, later + timedelta(hours=1)))

    def test_release(self):
        token = pg.reserve(self.dir, 'cart', NOW)
        self.assertFalse(pg.release(self.dir, 'cart', 'wrong'))
        self.assertEqual(1, len(pg.read_claims(self.dir)['claims']))
        self.assertTrue(pg.release(self.dir, 'cart', token))
        self.assertEqual([], pg.read_claims(self.dir)['claims'])
        self.assertFalse(pg.release(self.dir, 'cart', token))
        self.assertFalse(pg.release(self.dir, 'none', 'x'))

    def test_owner(self):
        pg.set_owner(self.dir, 'cart', 'tok')
        self.assertEqual('tok', pg.owner(pg.read_claims(self.dir), 'cart'))
        self.assertIsNone(pg.owner(pg.read_claims(self.dir), 'seo'))

    def test_claim_to_owner_with_claim(self):
        token = pg.reserve(self.dir, 'cart', NOW)
        pg.reserve(self.dir, 'seo', NOW)
        self.assertEqual(token, pg.claim_to_owner(self.dir, 'cart', token, NOW))
        data = pg.read_claims(self.dir)
        self.assertEqual(['seo'], [c['dir'] for c in data['claims']])
        self.assertEqual(token, pg.owner(data, 'cart'))

    def test_claim_to_owner_expired_and_no_claim(self):
        token = pg.reserve(self.dir, 'cart', NOW)
        later = NOW + timedelta(hours=2)
        self.assertEqual(token, pg.claim_to_owner(self.dir, 'cart', token, later))
        self.assertEqual([], pg.read_claims(self.dir)['claims'])
        fresh = pg.claim_to_owner(self.dir, 'seo', None, later)
        self.assertEqual(16, len(fresh))
        self.assertEqual(fresh, pg.owner(pg.read_claims(self.dir), 'seo'))

    def test_claim_to_owner_keeps_other_sessions_valid_claim(self):
        other = pg.reserve(self.dir, 'cart', NOW)
        mine = pg.claim_to_owner(self.dir, 'cart', 'mine', NOW)
        data = pg.read_claims(self.dir)
        self.assertEqual('mine', mine)
        self.assertEqual([other], [c['claim'] for c in data['claims']])
        self.assertEqual('mine', pg.owner(data, 'cart'))

    def test_claim_to_owner_drops_expired_other_claim(self):
        pg.reserve(self.dir, 'cart', NOW)
        pg.claim_to_owner(self.dir, 'cart', 'mine', NOW + timedelta(hours=1))
        self.assertEqual([], pg.read_claims(self.dir)['claims'])

    def test_unreadable_and_wrong_shape(self):
        path = os.path.join(self.dir, 'claims.json')
        for text in ['{not json', '[]', '{"claims": {}}', '{"claims": [], "owners": []}',
                     '{"claims": [1], "owners": {}}']:
            with self.subTest(text=text):
                with open(path, 'w') as f:
                    f.write(text)
                with self.assertRaisesRegex(pg.StateFileError, 'nieczytelny plik'):
                    pg.read_claims(self.dir)
                with self.assertRaises(pg.StateFileError):
                    pg.reserve(self.dir, 'cart', NOW)

    def test_missing_keys_normalized(self):
        with open(os.path.join(self.dir, 'claims.json'), 'w') as f:
            json.dump({}, f)
        self.assertEqual({'claims': [], 'owners': {}}, pg.read_claims(self.dir))


class ClassificationTest(unittest.TestCase):
    def test_is_allowed(self):
        allowed = list(pg.GENERATED) + ['docs/vision.md', 'docs', 'docs/features/x/spec.md', 'README.md']
        denied = ['infra/k8s/README.md', 'CLAUDE.md', 'apps/api/CLAUDE.md', '.github/workflows/ci.yml',
                  'make/demo-data.mk', 'Makefile', 'apps/admin/src/layout/AppMenu.vue', 'apps/api/src/Cart/Cart.php',
                  'docsx/a.md', 'apps/api']
        for p in allowed:
            with self.subTest(p=p):
                self.assertTrue(pg.is_allowed(p))
        for p in denied:
            with self.subTest(p=p):
                self.assertFalse(pg.is_allowed(p))

    def test_claude_md_never_allowed(self):
        for p in ['docs/CLAUDE.md', 'docs/features/x/CLAUDE.md', 'CLAUDE.md']:
            with self.subTest(p=p):
                self.assertFalse(pg.is_allowed(p))

    def test_is_claude_md(self):
        self.assertTrue(pg.is_claude_md('CLAUDE.md'))
        self.assertTrue(pg.is_claude_md('apps/api/CLAUDE.md'))
        self.assertFalse(pg.is_claude_md('apps/api/NOTCLAUDE.md'))
        self.assertFalse(pg.is_claude_md('CLAUDE.md/x'))

    def test_common_paths(self):
        cp = pg.common_paths
        self.assertEqual(['apps/api/src/Cart'], cp(['apps/api/src'], ['apps/api/src/Cart']))
        self.assertEqual(['apps/api/src/Cart'], cp(['apps/api/src/Cart'], ['apps/api/src']))
        self.assertEqual(['a/b'], cp(['a/b'], ['a/b']))
        self.assertEqual([], cp(['apps/api/src/Cart'], ['apps/api/src/CartX']))
        self.assertEqual([], cp(['a'], ['b']))
        self.assertEqual(['a/b', 'c/d/e'], cp(['c/d', 'a/b', 'a/b'], ['a', 'c/d/e', 'a/b']))


STATS_OUT = '\n'.join([
    'ao-x-api-1\t94.33MiB / 31.29GiB',
    'ao-x-db-1\t1GiB / 31GiB',
    'shop-api-1\t512MiB / 31GiB',
    'ghost-1\t2048KiB / 31GiB',
    'big\t1TiB / 31GiB',
    'si\t1000kB / 1GB',
    'b\t1048576B / 1GB',
]) + '\n'
PS_OUT = 'ao-x-api-1\tao-x\nao-x-db-1\tao-x\nshop-api-1\tshop\nbig\t\nsi\tshop\nb\tshop\n'


def fake_run(table):
    def run(args):
        for key, val in table.items():
            if key in ' '.join(args):
                if isinstance(val, Exception):
                    raise val
                return val
        raise AssertionError(args)
    return run


class DockerTest(unittest.TestCase):
    def test_snapshot(self):
        run = fake_run({'info': str(32 * 1024 ** 3) + '\n', 'docker ps': PS_OUT, 'stats': STATS_OUT})
        s = pg.docker_snapshot(run)
        self.assertEqual(32768.0, s['total_mib'])
        self.assertEqual(round(94.33 + 1024, 1), s['ao_mib'])
        other = 512 + 2 + 1024 * 1024 + 1000 * 1000 / 1048576 + 1.0
        self.assertEqual(round(other, 1), s['other_mib'])

    def test_failure(self):
        err = next_epic.CommandError(['docker', 'info'], 'daemon down')
        with self.assertRaisesRegex(pg.DockerError, '^docker: '):
            pg.docker_snapshot(fake_run({'info': err}))

    def test_garbage(self):
        for table in [
            {'info': 'abc', 'docker ps': '', 'stats': ''},
            {'info': '1000', 'docker ps': '', 'stats': 'x\tnonsense'},
            {'info': '1000', 'docker ps': '', 'stats': 'oneword'},
        ]:
            with self.subTest(table=table):
                with self.assertRaisesRegex(pg.DockerError, 'docker: nieczytelne wyjście'):
                    pg.docker_snapshot(fake_run(table))

    def test_free_mib(self):
        snap = {'total_mib': 10000.0, 'ao_mib': 3000.0, 'other_mib': 2000.0}
        self.assertEqual(5000.0, pg.free_mib(snap, 1, 1000))
        self.assertEqual(4000.0, pg.free_mib(snap, 2, 2000))
        self.assertEqual(5000.0, pg.free_mib(snap, 2, 500))


class OrchestratorRunningTest(unittest.TestCase):
    def test_missing(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertFalse(pg.orchestrator_running(os.path.join(d, 'none')))
            self.assertFalse(pg.orchestrator_running(d))
            self.assertFalse(os.path.exists(os.path.join(d, 'orchestrator.lock')))

    def test_free_lock(self):
        with tempfile.TemporaryDirectory() as d:
            open(os.path.join(d, 'orchestrator.lock'), 'w').close()
            self.assertFalse(pg.orchestrator_running(d))

    def test_unreadable_lock_file_counts_as_running(self):
        if os.geteuid() == 0:
            self.skipTest('root ignores file modes')
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, 'orchestrator.lock')
            open(path, 'w').close()
            os.chmod(path, 0)
            self.assertTrue(pg.orchestrator_running(d))

    def test_held(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, 'orchestrator.lock')
            open(path, 'w').close()
            proc = hold(path, mode='r+', flag='fcntl.LOCK_EX')
            try:
                self.assertTrue(pg.orchestrator_running(d))
            finally:
                release_child(proc)
            self.assertFalse(pg.orchestrator_running(d))


if __name__ == '__main__':
    unittest.main()

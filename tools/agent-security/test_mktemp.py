"""Tests of the mktemp overlay of the executor (tools/agent-security/bin/mktemp):
python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

The overlay is run as the executor's Bash would find it (first in PATH, with TMPDIR set), against the real system
mktemp. On macOS a bare `mktemp` uses _CS_DARWIN_USER_TEMP_DIR (/var/folders/.../T) whatever TMPDIR says, so a result
in TMPDIR proves the overlay's explicit template; on Linux the system mktemp already honours TMPDIR.

Where the GNU mktemp (Linux) and the BSD mktemp (macOS) differ (several templates: BSD makes them all, GNU refuses
"too many templates"; -t: a prefix argument in BSD, a flag without one in GNU), the overlay passes the call on and the
test runs the system mktemp with the same arguments: the same exit status, the same error message and nothing made on
an error, and on success the results where the overlay puts them.
"""
import os
import re
import shutil
import stat
import subprocess
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
REPOSITORY = os.path.dirname(os.path.dirname(HERE))
BIN = os.path.join(HERE, 'bin')
OVERLAY = os.path.join(BIN, 'mktemp')
SYSTEM_MKTEMP = '/usr/bin/mktemp'


class OverlayTestCase(unittest.TestCase):
    def setUp(self):
        self.assertTrue(os.path.isfile(OVERLAY), 'the overlay %s does not exist' % OVERLAY)
        self.tmp = os.path.realpath(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.tmpdir = os.path.join(self.tmp, 'task tmp')   # with a space: the overlay must quote
        self.other = os.path.join(self.tmp, 'other')
        self.cwd = os.path.join(self.tmp, 'cwd')
        for path in (self.tmpdir, self.other, self.cwd):
            os.makedirs(path)

    def env(self, **overrides):
        # The overlay directory first in PATH, as claude-agent sets it: `mktemp` is the overlay, which must call the
        # system mktemp by absolute path (through PATH it would call itself).
        env = {'PATH': BIN + os.pathsep + '/usr/bin' + os.pathsep + '/bin', 'TMPDIR': self.tmpdir, 'HOME': self.tmp}
        for key, value in overrides.items():
            if value is None:
                env.pop(key, None)
            else:
                env[key] = value
        return env

    def mktemp(self, *args, cwd=None, timeout=60, **env):
        return subprocess.run(['mktemp', *args], cwd=cwd or self.cwd, capture_output=True, text=True,
                              env=self.env(**env), timeout=timeout)

    def system(self, *args, **env):
        return subprocess.run([SYSTEM_MKTEMP, *args], cwd=self.cwd, capture_output=True, text=True,
                              env=self.env(**env))

    def remove_later(self, result):
        for path in result.stdout.splitlines():
            path = os.path.join(self.cwd, path)
            self.addCleanup(lambda p=path: shutil.rmtree(p, True) if os.path.isdir(p) else
                            os.path.lexists(p) and os.unlink(p))

    def snapshot(self):
        return {d: sorted(os.listdir(d)) for d in (self.tmpdir, self.other, self.cwd)}

    def like_system(self, *args, **env):
        """The overlay against the system mktemp with the same arguments, for a call where GNU and BSD mktemp differ:
        the same exit status and error message, on an error no output and nothing made. Returns the overlay's paths
        (none after an error)."""
        before = self.snapshot()
        ours = self.mktemp(*args, **env)
        self.remove_later(ours)
        after = self.snapshot()
        theirs = self.system(*args, **env)
        self.remove_later(theirs)
        self.assertEqual(ours.returncode, theirs.returncode, (args, ours.stderr, theirs.stderr))
        self.assertEqual(ours.stderr, theirs.stderr, args)
        if ours.returncode != 0:
            self.assertNotEqual(ours.stderr, '', args)
            self.assertEqual(ours.stdout, '', args)
            self.assertEqual(after, before, '%r made something despite the error' % (args,))
            return []
        paths = ours.stdout.splitlines()
        self.assertEqual(len(paths), len(theirs.stdout.splitlines()), args)
        return paths

    def made(self, *args, **env):
        result = self.mktemp(*args, **env)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')
        paths = result.stdout.splitlines()
        self.assertTrue(paths, 'mktemp printed nothing')
        return paths

    def one(self, *args, **env):
        paths = self.made(*args, **env)
        self.assertEqual(len(paths), 1, paths)
        return paths[0]

    def assert_in(self, path, directory, prefix='tmp.', kind='file', length=10):
        self.assertEqual(os.path.dirname(path), directory)
        name = os.path.basename(path)
        self.assertTrue(name.startswith(prefix), name)
        self.assertRegex(name[len(prefix):], '^[A-Za-z0-9]{%d}$' % length)
        self.assertTrue(os.path.isfile(path) if kind == 'file' else os.path.isdir(path), path)


class OverlayFileTest(unittest.TestCase):
    def test_a_trusted_posix_sh_script(self):
        self.assertTrue(os.path.isfile(OVERLAY) and not os.path.islink(OVERLAY))
        self.assertTrue(os.stat(OVERLAY).st_mode & stat.S_IXUSR, 'not executable')
        with open(OVERLAY) as f:
            self.assertEqual(f.readline(), '#!/bin/sh\n')
        self.assertEqual(sorted(os.listdir(BIN)), ['mktemp'], 'the overlay directory holds only mktemp')
        self.assertEqual(subprocess.run(['sh', '-n', OVERLAY]).returncode, 0)


class BareFormsTest(OverlayTestCase):
    """The forms of the repository's scripts without a template: `mktemp` and `mktemp -d`."""

    def test_bare_mktemp_makes_a_file_in_tmpdir(self):
        path = self.one()
        self.assert_in(path, self.tmpdir)
        self.assertEqual(stat.S_IMODE(os.stat(path).st_mode), 0o600)
        with open(path, 'w') as f:
            f.write('x')

    def test_bare_mktemp_d_makes_a_directory_in_tmpdir(self):
        path = self.one('-d')
        self.assert_in(path, self.tmpdir, kind='dir')
        self.assertEqual(stat.S_IMODE(os.stat(path).st_mode), 0o700)

    def test_a_trailing_slash_of_tmpdir_does_not_double_the_slash(self):
        for tmpdir in (self.tmpdir + '/', self.tmpdir + '//'):
            path = self.one(TMPDIR=tmpdir)
            self.assertEqual(os.path.dirname(path), self.tmpdir)
            self.assertNotIn('//', path)

    def test_each_call_gets_its_own_name(self):
        self.assertEqual(len({self.one() for _ in range(5)}), 5)

    def test_the_result_is_one_line_with_the_path(self):
        result = self.mktemp('-d')
        self.assertRegex(result.stdout, r'^[^\n]+\n$')

    def test_dry_run_prints_a_name_in_tmpdir_and_leaves_nothing(self):
        for args in (('-u',), ('-d', '-u'), ('-du',), ('--dry-run',)):
            path = self.one(*args)
            self.assertEqual(os.path.dirname(path), self.tmpdir)
            self.assertFalse(os.path.lexists(path), args)

    def test_umask_in_front_of_it_is_kept(self):
        # The forms `$(umask 077 && mktemp -d)` of deploy-ref.sh, seal-secrets.sh, bootstrap.sh and kubeconfig.sh.
        result = subprocess.run(['sh', '-c', 'umask 077 && mktemp -d'], cwd=self.cwd, capture_output=True,
                                text=True, env=self.env())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assert_in(result.stdout.strip(), self.tmpdir, kind='dir')

    def test_the_overlay_is_the_mktemp_of_path_and_does_not_call_itself(self):
        found = subprocess.run(['sh', '-c', 'command -v mktemp'], capture_output=True, text=True, env=self.env())
        self.assertEqual(found.stdout.strip(), OVERLAY)
        # A call through PATH would loop: the system mktemp is called by absolute path.
        self.assertEqual(self.mktemp(timeout=20).returncode, 0)
        self.assertEqual(self.mktemp('-d', timeout=20).returncode, 0)


class PrefixAndDirectoryOptionsTest(OverlayTestCase):
    """-t prefix and -p/--tmpdir: on macOS -t ignores TMPDIR and -p alone is the only way to move the result."""

    def test_t_prefix_in_tmpdir(self):
        self.assert_in(self.one('-t', 'foo'), self.tmpdir, prefix='foo.')
        self.assert_in(self.one('-d', '-t', 'foo'), self.tmpdir, prefix='foo.', kind='dir')
        self.assert_in(self.one('-dt', 'foo'), self.tmpdir, prefix='foo.', kind='dir')
        self.assert_in(self.one('-tfoo'), self.tmpdir, prefix='foo.')

    def test_p_directory_wins_over_tmpdir(self):
        self.assert_in(self.one('-p', self.other), self.other)
        self.assert_in(self.one('-d', '-p', self.other), self.other, kind='dir')
        self.assert_in(self.one('-p' + self.other), self.other)
        self.assert_in(self.one('--tmpdir=' + self.other), self.other)
        self.assert_in(self.one('-dp', self.other), self.other, kind='dir')
        self.assert_in(self.one('-p', self.other, '-t', 'foo'), self.other, prefix='foo.')
        self.assert_in(self.one('-d', '-t', 'foo', '-p', self.other), self.other, prefix='foo.', kind='dir')

    def test_an_empty_or_omitted_directory_means_tmpdir(self):
        self.assert_in(self.one('-p', ''), self.tmpdir)
        self.assert_in(self.one('--tmpdir'), self.tmpdir)
        self.assert_in(self.one('--tmpdir='), self.tmpdir)
        self.assert_in(self.one('-p', '', '-t', 'foo'), self.tmpdir, prefix='foo.')

    def test_long_options_and_their_unambiguous_abbreviations(self):
        self.assert_in(self.one('--directory'), self.tmpdir, kind='dir')
        self.assert_in(self.one('--dir'), self.tmpdir, kind='dir')
        self.assertFalse(os.path.lexists(self.one('--dry-run')))
        self.assertFalse(os.path.lexists(self.one('--dr')))
        self.assert_in(self.one('--tmp=' + self.other), self.other)
        self.assertEqual(self.mktemp('--quiet').returncode, 0)

    def test_an_ambiguous_abbreviation_is_the_system_mktemp_error(self):
        result = self.mktemp('--d')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotEqual(result.stderr, '')
        self.assertEqual(os.listdir(self.tmpdir), [])

    def test_a_missing_option_argument_is_the_system_mktemp_error(self):
        # The call goes on unchanged: an error of the BSD mktemp (and of GNU for -p); GNU's -t takes no argument, so
        # `-t` and `-d -t` make tmp.X in TMPDIR there.
        for args in (('-t',), ('-p',), ('-d', '-t'), ('-dp',)):
            with self.subTest(args=args):
                for path in self.like_system(*args):
                    self.assertEqual(os.path.dirname(path), self.tmpdir, args)


class ExplicitTemplateTest(OverlayTestCase):
    """A call with a template passes unchanged: it lands where the template says, TMPDIR does not matter."""

    def test_absolute_template(self):
        path = self.one(os.path.join(self.other, 'kube-XXXXXX'))
        self.assert_in(path, self.other, prefix='kube-', length=6)
        self.assertEqual(os.listdir(self.tmpdir), [])

    def test_absolute_template_with_d(self):
        self.assert_in(self.one('-d', os.path.join(self.other, 'kube-XXXXXX')), self.other, prefix='kube-', kind='dir', length=6)
        self.assertEqual(os.listdir(self.tmpdir), [])

    def test_relative_template_is_in_the_working_directory(self):
        # `mktemp .env.XXXXXX` of .ao/setup and `mktemp "$dir/.env.local.XXXXXX"` of init-env.sh
        path = self.one('.env.XXXXXX')
        self.assertEqual(os.path.dirname(os.path.join(self.cwd, path)), self.cwd)
        self.assertTrue(os.path.isfile(os.path.join(self.cwd, path)))
        self.assertEqual(os.listdir(self.tmpdir), [])

    def test_several_templates(self):
        # Passed on unchanged: the BSD mktemp makes both, the GNU mktemp refuses (too many templates).
        paths = self.like_system(os.path.join(self.other, 'a.XXXXXX'), os.path.join(self.tmpdir, 'b.XXXXXX'))
        if not paths:
            return
        first, second = paths
        self.assertEqual(os.path.dirname(first), self.other)
        self.assertEqual(os.path.dirname(second), self.tmpdir)
        self.assertTrue(os.path.isfile(first) and os.path.isfile(second))

    def test_the_directory_of_p_is_prepended_to_a_relative_template_as_the_system_mktemp_does(self):
        path = self.one('-p', self.other, 'rel.XXXXXX')
        self.assertEqual(os.path.dirname(path), self.other)
        self.assertTrue(os.path.isfile(path))

    def test_the_template_is_not_changed_even_when_tmpdir_is_unusable(self):
        path = self.one(os.path.join(self.other, 'x.XXXXXX'), TMPDIR=os.path.join(self.tmp, 'missing'))
        self.assertTrue(os.path.isfile(path))

    def test_a_template_after_t_gets_both(self):
        # `mktemp -t foo other/bar.XXXXXX`: with the BSD mktemp the prefix in TMPDIR (the system one puts it in
        # /var/folders) and the template where it says; the GNU mktemp refuses two templates, the overlay too.
        paths = self.like_system('-t', 'foo', os.path.join(self.other, 'bar.XXXXXX'))
        if not paths:
            return
        first, second = paths
        self.assert_in(first, self.tmpdir, prefix='foo.')
        self.assertEqual(os.path.dirname(second), self.other)

    def test_double_dash_ends_the_options(self):
        path = self.one('--', os.path.join(self.other, 'dd.XXXXXX'))
        self.assertEqual(os.path.dirname(path), self.other)
        self.assert_in(self.one('-d', '--'), self.tmpdir, kind='dir')

    def test_a_template_starting_with_a_dash_after_double_dash(self):
        path = self.one('--', '-dash.XXXXXX')
        self.assertEqual(path[:6], '-dash.')
        self.assertTrue(os.path.isfile(os.path.join(self.cwd, path)))


class ErrorPathTest(OverlayTestCase):
    def test_missing_tmpdir_directory_fails_without_a_file_elsewhere(self):
        missing = os.path.join(self.tmp, 'missing')
        result = self.mktemp(TMPDIR=missing)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(missing, result.stderr)
        self.assertEqual(result.stdout, '')

    def test_quiet_keeps_the_error_silent(self):
        result = self.mktemp('-q', TMPDIR=os.path.join(self.tmp, 'missing'))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stderr, '')
        self.assertEqual(result.stdout, '')
        result = self.mktemp('-dq', TMPDIR=os.path.join(self.tmp, 'missing'))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stderr, '')

    def test_unknown_option_is_the_system_mktemp_error(self):
        result = self.mktemp('--no-such-option')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotEqual(result.stderr, '')
        self.assertEqual(os.listdir(self.tmpdir), [])
        self.assertNotEqual(self.mktemp('-Z').returncode, 0)

    def test_read_only_tmpdir_fails(self):
        if os.getuid() == 0:
            self.skipTest('root ignores directory permissions')
        os.chmod(self.tmpdir, 0o500)
        self.addCleanup(os.chmod, self.tmpdir, 0o700)
        result = self.mktemp()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')

    def test_the_system_mktemp_is_called_by_absolute_path(self):
        with open(OVERLAY) as f:
            self.assertIn(SYSTEM_MKTEMP, f.read())


class UnsetTmpdirTest(OverlayTestCase):
    """Without a usable TMPDIR the overlay has no directory of its own to choose and does not invent one (the shared
    /tmp would be one): the call goes to the system mktemp unchanged, as without the overlay."""

    def assert_same_as_system(self, *args, **env):
        ours = self.mktemp(*args, **env)
        theirs = self.system(*args, **env)
        self.assertEqual(ours.returncode, theirs.returncode, (args, ours.stderr, theirs.stderr))
        self.assertEqual(os.path.dirname(ours.stdout.strip()), os.path.dirname(theirs.stdout.strip()), args)
        for result in (ours, theirs):
            for path in result.stdout.split():
                self.addCleanup(lambda p=path: os.path.isdir(p) and os.rmdir(p) or os.path.exists(p) and os.unlink(p))

    def test_unset_tmpdir(self):
        self.assert_same_as_system(TMPDIR=None)
        self.assert_same_as_system('-d', TMPDIR=None)
        self.assert_same_as_system('-t', 'foo', TMPDIR=None)

    def test_empty_tmpdir(self):
        self.assert_same_as_system(TMPDIR='')
        self.assert_same_as_system('-d', TMPDIR='')

    def test_an_explicit_p_directory_needs_no_tmpdir(self):
        for tmpdir in (None, ''):
            self.assert_in(self.one('-p', self.other, TMPDIR=tmpdir), self.other)
            self.assert_in(self.one('-p', self.other, '-t', 'x', TMPDIR=tmpdir), self.other, prefix='x.')

    def test_an_empty_p_directory_without_tmpdir_is_the_system_behaviour(self):
        self.assert_same_as_system('-p', '', TMPDIR=None)
        self.assert_same_as_system('--tmpdir', TMPDIR='')

    def test_explicit_templates_need_no_tmpdir(self):
        for tmpdir in (None, ''):
            path = self.one(os.path.join(self.other, 't.XXXXXX'), TMPDIR=tmpdir)
            self.assertEqual(os.path.dirname(path), self.other)


GRAMMAR = re.compile(r'\$\(([^()]*\bmktemp\b[^()]*)\)')
VARIABLE = re.compile(r'\$\{?([A-Za-z_][A-Za-z0-9_]*)')


def repository_calls():
    """Every `$( ... mktemp ... )` of a script or Makefile of the repository (not docs and not this suite's own
    files): the shell text between the parentheses."""
    listing = subprocess.run(['git', 'ls-files', '-z'], cwd=REPOSITORY, capture_output=True)
    if listing.returncode != 0:
        return None
    calls = set()
    for name in listing.stdout.decode().split('\0'):
        if not name or name.startswith(('docs/', 'tools/agent-security/')) or name.endswith('.md'):
            continue
        path = os.path.join(REPOSITORY, name)
        try:
            with open(path, encoding='utf-8') as f:
                text = f.read()
        except (OSError, UnicodeDecodeError):
            continue
        for match in GRAMMAR.finditer(text):
            calls.add((name, match.group(1)))
    return sorted(calls)


class RepositoryCallsTest(OverlayTestCase):
    """The mktemp calls the repository's scripts really make (.github/scripts/test_*.sh, infra/k8s/scripts/*.sh,
    infra/scripts/*.sh, .ao/setup, ...), found in the files and run as written, with every variable in them set to an
    existing directory of the test: a bare call lands in TMPDIR, a call with a template where its template says."""

    def run_call(self, call):
        names = sorted(set(VARIABLE.findall(call)) - {'TMPDIR'})
        env = self.env()
        for name in names:
            path = os.path.join(self.tmp, 'var-' + name)
            os.makedirs(path, exist_ok=True)
            env[name] = path
        env['TMPDIR'] = self.tmpdir
        result = subprocess.run(['sh', '-c', 'x=$(%s) || exit 1; printf "%%s" "$x"' % call], cwd=self.cwd,
                                capture_output=True, text=True, env=env)
        self.assertEqual(result.returncode, 0, (call, result.stderr))
        return os.path.join(self.cwd, result.stdout), env

    def test_the_repository_has_calls_to_check(self):
        calls = repository_calls()
        if calls is None:
            self.skipTest('not a git checkout')
        self.assertGreaterEqual(len(calls), 15, 'the search found too few calls: %r' % calls)

    def test_every_call_lands_where_its_form_says(self):
        calls = repository_calls()
        if calls is None:
            self.skipTest('not a git checkout')
        for name, call in calls:
            with self.subTest(file=name, call=call):
                path, env = self.run_call(call)
                self.assertTrue(os.path.lexists(path), path)
                self.addCleanup(lambda p=path: shutil.rmtree(p, True) if os.path.isdir(p) else
                                os.path.lexists(p) and os.unlink(p))
                template = re.search(r'mktemp(?:\s+-\w+)*\s+"?([^"\s)]*X{3,}[^"\s)]*)', call)
                if template:   # a template: where it says
                    expected = os.path.dirname(os.path.join(self.cwd, self.expand(template.group(1), env)))
                    self.assertEqual(os.path.dirname(path), expected)
                else:   # bare: in TMPDIR
                    self.assertEqual(os.path.dirname(path), self.tmpdir)

    @staticmethod
    def expand(template, env):
        return re.sub(r'\$\{?([A-Za-z_][A-Za-z0-9_]*)(?::-[^}]*)?\}?', lambda m: env.get(m.group(1), ''), template)


if __name__ == '__main__':
    unittest.main()

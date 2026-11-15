"""Tests of the checkout of a pass (spec: Checkout przypadku; threat model path 1): the overlay with the current
agents, skills and watched files, the task diff of a diff case, the local origin and a fresh checkout per pass."""
import os
import subprocess
import unittest

from testutil import TempRepo

import evals_checkout as checkout

WATCHED = ['.claude/agents/code-reviewer.md']


def git(root, *args):
    return subprocess.run(['git', '-C', root] + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          universal_newlines=True, check=True).stdout


class CheckoutTestCase(unittest.TestCase):
    def setUp(self):
        self.repo = TempRepo()
        self.addCleanup(self.repo.cleanup)
        self.repo.write('.claude/skills/demo/SKILL.md', 'old skill\n')
        self.repo.write('.claude/skills/gone/SKILL.md', 'removed later\n')
        self.repo.write('src/app.txt', 'app v1\n')
        self.old = self.repo.commit('old state')
        self.repo.write('.claude/agents/code-reviewer.md', 'current reviewer prompt\n')
        self.repo.write('.claude/skills/demo/SKILL.md', 'current skill\n')
        self.repo.write('.claude/skills/new/SKILL.md', 'a new skill\n')
        os.remove(self.repo.path('.claude/skills/gone/SKILL.md'))
        self.repo.write('src/app.txt', 'app v2\n')
        self.current = self.repo.commit('current state')
        self.state = os.path.realpath(self.repo.root + '-state')
        os.makedirs(self.state)
        self.addCleanup(lambda: subprocess.run(['rm', '-rf', self.state]))

    def pass_dir(self, name='p1'):
        path = os.path.join(self.state, name)
        os.makedirs(path)
        return path

    def build(self, case_state, overlay=None, name='p1', patch=None, watched=WATCHED):
        return checkout.build(self.repo.root, self.pass_dir(name), case_state, overlay or self.current, watched,
                              patch=patch)

    def overlay_files(self, rev):
        out = git(self.repo.root, 'ls-tree', '-r', '--name-only', rev, '--', '.claude/agents', '.claude/skills')
        return sorted(out.split())

    def checkout_files(self, root):
        found = []
        for prefix in ('.claude/agents', '.claude/skills'):
            for current, _, files in os.walk(os.path.join(root, prefix)):
                found.extend(os.path.relpath(os.path.join(current, f), root) for f in files)
        return sorted(found)

    def read(self, root, rel):
        with open(os.path.join(root, rel), 'rb') as handle:
            return handle.read()

    def show(self, rev, rel):
        return subprocess.run(['git', '-C', self.repo.root, 'show', '%s:%s' % (rev, rel)], stdout=subprocess.PIPE,
                              check=True).stdout


class OverlayTest(CheckoutTestCase):
    def test_agents_skills_and_watched_files_are_those_of_the_current_head(self):
        built = self.build({'commit': self.old})
        root = built['checkout']
        self.assertEqual(self.overlay_files(self.current), self.checkout_files(root))
        for rel in self.overlay_files(self.current) + WATCHED:
            self.assertEqual(self.show(self.current, rel), self.read(root, rel), rel)
        self.assertFalse(os.path.exists(os.path.join(root, '.claude/skills/gone/SKILL.md')))
        # everything else is the state of the case commit
        self.assertEqual(b'app v1\n', self.read(root, 'src/app.txt'))

    def test_a_trial_overlay_takes_the_files_of_the_given_revision(self):
        built = self.build({'commit': self.current}, overlay=self.old)
        root = built['checkout']
        self.assertEqual(self.overlay_files(self.old), self.checkout_files(root))
        for rel in self.overlay_files(self.old) + WATCHED:
            self.assertEqual(self.show(self.old, rel), self.read(root, rel), rel)
        self.assertEqual(b'app v2\n', self.read(root, 'src/app.txt'))

    def test_the_overlay_is_one_commit_on_the_case_commit(self):
        built = self.build({'commit': self.old})
        root = built['checkout']
        self.assertEqual(built['head'], git(root, 'rev-parse', 'HEAD').strip())
        self.assertEqual(self.old, git(root, 'rev-parse', 'HEAD^').strip())
        self.assertEqual(built['overlay'], built['head'])

    def test_the_commits_of_the_overlay_revision_are_not_in_the_checkout(self):
        built = self.build({'commit': self.old})
        for root in (built['checkout'], built['origin']):
            code = subprocess.run(['git', '-C', root, 'cat-file', '-e', self.current + '^{commit}'],
                                  stderr=subprocess.DEVNULL).returncode
            self.assertNotEqual(0, code, root)
        self.assertFalse(os.path.exists(os.path.join(built['pass_dir'], '.build')))

    def test_a_watched_file_missing_at_the_overlay_revision_is_missing_in_the_checkout(self):
        self.repo.write('docs/watched.md', 'historical\n')
        case_commit = self.repo.commit('watched file in the case')
        os.remove(self.repo.path('docs/watched.md'))
        head = self.repo.commit('watched file removed')
        built = self.build({'commit': case_commit}, overlay=head, watched=WATCHED + ['docs/watched.md'])
        self.assertFalse(os.path.exists(os.path.join(built['checkout'], 'docs/watched.md')))

    def test_an_unknown_commit_is_a_checkout_error(self):
        with self.assertRaises(checkout.CheckoutError):
            self.build({'commit': 'b' * 40})


class DiffCaseTest(CheckoutTestCase):
    def setUp(self):
        CheckoutTestCase.setUp(self)
        self.repo.git('checkout', '-q', '-b', 'task', self.old)
        self.repo.write('src/app.txt', 'app v3 of the task\n')
        self.repo.write('src/data.bin', b'\x00\x01binary\xff')
        os.chmod(self.repo.path('src/app.txt'), 0o755)
        self.task_head = self.repo.commit('task change')
        self.repo.checkout('main')
        self.repo.write('.claude/agents/code-reviewer.md', 'newest reviewer prompt\n')
        self.newest = self.repo.commit('newest agent')

    def test_the_diff_of_the_new_base_and_head_equals_the_task_diff(self):
        built = self.build({'base_sha': self.old, 'head_sha': self.task_head}, overlay=self.newest)
        root = built['checkout']
        original = git(self.repo.root, 'diff', '--binary', self.old, self.task_head)
        self.assertEqual(original, git(root, 'diff', '--binary', built['base'], built['head']))
        self.assertEqual(built['base'], git(root, 'rev-parse', 'HEAD^').strip())
        self.assertEqual(built['head'], git(root, 'rev-parse', 'HEAD').strip())
        self.assertEqual(self.old, git(root, 'rev-parse', built['base'] + '^').strip())
        self.assertEqual(self.show(self.newest, '.claude/agents/code-reviewer.md'),
                         self.read(root, '.claude/agents/code-reviewer.md'))

    def test_a_task_diff_touching_an_overlay_path_is_a_checkout_error(self):
        self.repo.checkout('task')
        self.repo.write('.claude/agents/code-reviewer.md', 'changed by the task\n')
        head = self.repo.commit('task touches the agent')
        with self.assertRaises(checkout.CheckoutError) as raised:
            self.build({'base_sha': self.old, 'head_sha': head}, overlay=self.newest)
        self.assertIn('overlay', str(raised.exception))

    def test_a_patch_is_one_more_commit_on_top(self):
        patch = git(self.repo.root, 'diff', '--binary', self.old, self.task_head).encode()
        built = self.build({'commit': self.old}, patch=patch)
        root = built['checkout']
        self.assertEqual(built['overlay'], built['base'])
        self.assertEqual(patch.decode(), git(root, 'diff', '--binary', built['base'], built['head']))

    def test_a_diff_case_with_a_patch_reviews_the_task_diff_and_the_patch_together(self):
        self.repo.checkout('task')
        self.repo.write('src/app.txt', 'app v3 of the task, patched\n')
        expected = self.repo.commit('the task with the patch')
        self.repo.checkout('main')
        patch = git(self.repo.root, 'diff', '--binary', self.task_head, expected).encode()
        built = self.build({'base_sha': self.old, 'head_sha': self.task_head}, overlay=self.newest, patch=patch)
        self.assertEqual(built['overlay'], built['base'])
        self.assertEqual(git(self.repo.root, 'diff', '--binary', self.old, expected),
                         git(built['checkout'], 'diff', '--binary', built['base'], built['head']))

    def test_a_patch_touching_an_overlay_path_is_a_checkout_error(self):
        patch = ('diff --git a/.claude/skills/demo/SKILL.md b/.claude/skills/demo/SKILL.md\n'
                 '--- a/.claude/skills/demo/SKILL.md\n+++ b/.claude/skills/demo/SKILL.md\n'
                 '@@ -1 +1 @@\n-current skill\n+patched skill\n').encode()
        with self.assertRaises(checkout.CheckoutError):
            self.build({'commit': self.old}, patch=patch)

    def test_a_patch_that_does_not_apply_is_a_checkout_error(self):
        patch = b'diff --git a/src/app.txt b/src/app.txt\n--- a/src/app.txt\n+++ b/src/app.txt\n@@ -1 +1 @@\n-nope\n+x\n'
        with self.assertRaises(checkout.CheckoutError):
            self.build({'commit': self.old}, patch=patch)


class OriginTest(CheckoutTestCase):
    def test_origin_url_and_pushurl_are_the_local_bare_repository_of_the_pass(self):
        built = self.build({'commit': self.old})
        root = built['checkout']
        origin = built['origin']
        self.assertEqual(os.path.join(built['pass_dir'], 'origin.git'), origin)
        self.assertEqual(origin, git(root, 'config', 'remote.origin.url').strip())
        self.assertEqual(origin, git(root, 'config', 'remote.origin.pushurl').strip())
        self.assertEqual('true', git(origin, 'rev-parse', '--is-bare-repository').strip())
        self.assertEqual(built['head'], git(origin, 'rev-parse', 'refs/heads/main').strip())

    def test_each_pass_has_its_own_checkout_and_origin(self):
        first = self.build({'commit': self.old}, name='p1')
        second = self.build({'commit': self.old}, name='p2')
        with open(os.path.join(first['checkout'], 'leftover.txt'), 'w') as handle:
            handle.write('from the first pass\n')
        self.assertFalse(os.path.exists(os.path.join(second['checkout'], 'leftover.txt')))
        self.assertNotEqual(first['origin'], second['origin'])
        for built in (first, second):
            alternates = os.path.join(built['checkout'], '.git', 'objects', 'info', 'alternates')
            self.assertFalse(os.path.exists(alternates))
            self.assertTrue(built['checkout'].startswith(built['pass_dir'] + os.sep))
        self.assertEqual(first['head'], second['head'])   # the same case gives the same commits


class TreeChangesTest(CheckoutTestCase):
    def test_no_change_is_an_empty_list(self):
        built = self.build({'commit': self.old})
        self.assertEqual([], checkout.tree_changes(built['checkout'], built['manifest']))

    def test_added_modified_deleted_and_mode_changes_are_listed_without_git(self):
        built = self.build({'commit': self.old})
        root = built['checkout']
        with open(os.path.join(root, 'src/app.txt'), 'w') as handle:
            handle.write('changed\n')
        os.remove(os.path.join(root, '.claude/skills/demo/SKILL.md'))
        with open(os.path.join(root, 'notes.md'), 'w') as handle:
            handle.write('new\n')
        os.chmod(os.path.join(root, '.claude/agents/code-reviewer.md'), 0o755)
        os.symlink('/etc/passwd', os.path.join(root, 'link'))
        os.mkfifo(os.path.join(root, 'pipe'))
        changes = checkout.tree_changes(root, built['manifest'])
        self.assertEqual(['A\tlink', 'A\tnotes.md', 'A\tpipe', 'D\t.claude/skills/demo/SKILL.md',
                          'M\t.claude/agents/code-reviewer.md', 'M\tsrc/app.txt'], sorted(changes))

    def test_the_git_directory_is_not_part_of_the_tree(self):
        built = self.build({'commit': self.old})
        with open(os.path.join(built['checkout'], '.git', 'extra'), 'w') as handle:
            handle.write('x')
        self.assertEqual([], checkout.tree_changes(built['checkout'], built['manifest']))


if __name__ == '__main__':
    unittest.main()

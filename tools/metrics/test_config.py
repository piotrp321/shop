"""Tests of the shipped config.json: the command categories that later steps match against shell commands."""
import os
import re
import unittest

import metrics

HERE = os.path.dirname(os.path.abspath(__file__))


def matches(config, category, command):
    return any(re.search(pattern, command) for pattern in config['command_categories'][category])


class ReadCategoryTest(unittest.TestCase):
    """`read` holds only whole commands that cannot change anything (spec, Ryzyka): repeat_runs is a lower bound."""

    @classmethod
    def setUpClass(cls):
        cls.config = metrics.load_config(os.path.join(HERE, 'config.json'))

    def test_read_only_commands_match(self):
        for command in ('git status', 'git diff HEAD~1', 'git log --oneline -5', 'git show abc123',
                        'git rev-parse HEAD', 'ls -la', 'cat plik', 'head -n 5 plik', 'tail -f log',
                        'grep -n x f', 'rg pattern src', 'wc -l f', 'pwd', '  cat plik  '):
            self.assertTrue(matches(self.config, 'read', command), command)

    def test_commands_that_change_files_do_not_match(self):
        for command in ("cat > plik <<'EOF'", 'cat >> plik', 'head x > y', 'tail -n 2 a 2> err', 'find . -delete',
                        'find . -exec rm {} +', "sed -n '1w out' f", 'sed -i s/a/b/ f', 'git branch -D x',
                        'git branch new', 'cat a; rm b', 'cat a && rm b', 'ls | xargs rm', 'cat a & rm b',
                        'cat < in', 'grep x f $(rm y)', 'ls `rm y`', 'cat a\nrm b'):
            self.assertFalse(matches(self.config, 'read', command), command)

    def test_commands_of_other_categories_are_not_read(self):
        for command in ('make test', 'python3 -m unittest discover', 'git commit -m x', 'rm file'):
            self.assertFalse(matches(self.config, 'read', command), command)


if __name__ == '__main__':
    unittest.main()

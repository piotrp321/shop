"""Tests of the shipped config.json: the command categories that later steps match against shell commands."""
import json
import os
import re
import tempfile
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


class DecisionConfigTest(unittest.TestCase):
    """The keys of the decision rules (decisions, evals) are checked when the config is loaded: a bad value ends the
    command with a message that names the key."""

    def load(self, change):
        config = metrics.load_config(os.path.join(HERE, 'config.json'))
        change(config)
        with tempfile.TemporaryDirectory() as tmp:
            path = os.path.join(tmp, 'config.json')
            with open(path, 'w', encoding='utf-8') as f:
                json.dump(config, f)
            return metrics.load_config(path)

    def rejects(self, change, text):
        with self.assertRaises(metrics.MetricsError) as caught:
            self.load(change)
        self.assertIn(text, str(caught.exception))

    def test_the_shipped_decision_thresholds_are_valid(self):
        self.assertEqual(self.load(lambda c: None)['decisions']['report_max'], 3)

    def test_a_share_threshold_must_be_a_fraction_above_zero(self):
        for key in ('d1_role_share', 'd4_human_wait_share', 'd4_ci_share', 'd4_limit_wait_share', 'd4_review_share'):
            for bad in (0, -0.1, 1.5):
                self.rejects(lambda c: c['decisions'].update({key: bad}), 'decisions.%s' % key)

    def test_the_other_thresholds_must_be_positive(self):
        for key in ('d2_work_multiple', 'd3_gate_wait_h'):
            self.rejects(lambda c: c['decisions'].update({key: 0}), 'decisions.%s' % key)

    def test_the_counts_must_be_whole_numbers_of_at_least_one(self):
        for key in ('d2_failed_attempts', 'report_max', 'effect_min_tasks', 'effect_min_task_epics', 'effect_min_epics'):
            for bad in (0, 2.5, -1):
                self.rejects(lambda c: c['decisions'].update({key: bad}), 'decisions.%s' % key)

    def test_a_missing_decision_key_is_rejected(self):
        self.rejects(lambda c: c['decisions'].pop('d3_gate_wait_h'), 'decisions')

    def test_evals_roles_and_the_results_directory_are_checked(self):
        self.rejects(lambda c: c['evals'].update({'roles': []}), 'evals')
        self.rejects(lambda c: c['evals'].update({'results_dir': ''}), 'evals')


if __name__ == '__main__':
    unittest.main()

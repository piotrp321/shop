"""Regression tests of the cross-cutting spec rule instructions:
python3 -m unittest discover -s .claude/skills/integration -p 'test_*.py'

A rule that applies to every output of a kind (METRICS: estimated numbers marked with "≈") leaked one place per round
through integration, so the instructions require one finding per rule for the whole epic, a re-check of the whole rule
after a fix round and a look at consumers outside the diff. The finding must still pass the result contract.
"""
import os
import re
import unittest

import integration_result
from test_integration_result import finding, result

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..'))


def flat(path):
    with open(os.path.join(ROOT, path), encoding='utf-8') as f:
        return ' '.join(f.read().split())   # the lines of the instructions are wrapped


def step(text, number):
    """The text of one numbered step of the process (`N. **Name.**`) up to the next step."""
    match = re.search(r'(?:^| )%d\. \*\*.*?(?= %d\. \*\*|$)' % (number, number + 1), text)
    assert match, 'step %d not found' % number
    return match.group(0)


class IntegrationSkillTest(unittest.TestCase):
    text = flat('.claude/skills/integration/SKILL.md')

    def test_one_finding_per_rule_for_the_whole_epic(self):
        severity = step(self.text, 5)
        self.assertIn('jedno znalezisko na zasadę dla całego epiku', severity)
        self.assertIn('`task_id` to `null`', severity)
        self.assertNotIn('jedno znalezisko na zasadę i zadanie', self.text)

    def test_the_whole_rule_is_checked_again_after_a_fix_round(self):
        severity = step(self.text, 5)
        self.assertIn('także w trybie `DELTA`', severity)
        self.assertIn('nie tylko miejsca z poprzedniej listy', severity)
        stability = step(self.text, 7)
        self.assertIn('zasady przekrojowej', stability)
        self.assertIn('pełną listę z ponownej kontroli całej zasady', stability)

    def test_consumers_outside_the_diff_are_checked(self):
        self.assertIn('konsumentów poza diffem', step(self.text, 5))


class QaAgentTest(unittest.TestCase):
    text = flat('.claude/agents/qa-adversarial.md')

    def test_rule_check_covers_the_whole_change_and_its_consumers(self):
        self.assertIn('konsumentów poza diffem', self.text)
        self.assertIn('jedno znalezisko na zasadę', self.text)
        self.assertNotIn('sprawdź każde takie wyjście w diffie,', self.text)


class RuleFindingContractTest(unittest.TestCase):
    """The finding the instructions ask for passes the result validator, which mirrors the orchestrator."""

    def rule_finding(self, **changes):
        values = dict(fid='F1', title='Liczby szacowane nie są oznaczone „≈”', task_id=None,
                      files=['tools/metrics/render.py', 'tools/metrics/alerts.py'],
                      detail='METRICS-3: render.py tabela; METRICS-4: alerts.py W4 i push')
        values.update(changes)
        return finding(**values)

    def test_one_finding_for_places_in_several_tasks_is_valid(self):
        problems = integration_result.validate(result(status='FINDINGS', findings=[self.rule_finding()]))
        self.assertEqual([], problems)

    def test_the_rule_split_per_task_under_its_name_is_rejected_when_files_repeat(self):
        per_task = [self.rule_finding(fid='F1', task_id='METRICS-3', files=['tools/metrics/render.py']),
                    self.rule_finding(fid='F2', task_id='METRICS-4', files=['tools/metrics/render.py'])]
        problems = integration_result.validate(result(status='FINDINGS', findings=per_task))
        self.assertTrue(any('fingerprint' in p for p in problems), problems)


if __name__ == '__main__':
    unittest.main()

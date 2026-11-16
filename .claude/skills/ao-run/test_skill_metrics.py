"""Tests of the measurement steps in SKILL.md: python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'

Every call of tools/metrics/metrics.py written in SKILL.md must be accepted by its real argument parser, and the calls
must stand in the sections of the process that the spec names (docs/features/ao-run/spec.md, "Pomiar")."""
import importlib.util
import os
import re
import shlex
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
SKILL = os.path.join(HERE, 'SKILL.md')

spec = importlib.util.spec_from_file_location('metrics', os.path.join(ROOT, 'tools', 'metrics', 'metrics.py'))
metrics = importlib.util.module_from_spec(spec)
spec.loader.exec_module(metrics)

CALL = re.compile(r'python3 "\$SKILL_ROOT/tools/metrics/metrics\.py" (.*)')
CUT = re.compile(r'\s*(?:&&|\|\||\||;|<<)|\s(?:>>|>)')


def read_skill():
    with open(SKILL, encoding='utf-8') as f:
        return f.read()


def sections(text):
    """{'1': text, ..., '6a': ..., 'Noc': ...}: the body of each `## <number>. <title>` or `## Noc` section."""
    result, name, lines = {}, None, []
    for line in text.splitlines():
        heading = re.match(r'^## (\d+a?)\. |^## (Noc)$|^## (Czego nie robisz)$', line)
        if heading:
            if name is not None:
                result[name] = '\n'.join(lines)
            name, lines = next(g for g in heading.groups() if g), []
        elif name is not None:
            lines.append(line)
    if name is not None:
        result[name] = '\n'.join(lines)
    return result


def calls(text):
    """The argument lists of every metrics.py call in `text`, continuation lines joined, redirections cut off."""
    found = []
    for line in text.replace('\\\n', ' ').splitlines():
        match = CALL.search(line)
        if match:
            found.append(shlex.split(CUT.split(match.group(1))[0]))
    return found


def command_of(arguments):
    return arguments[0]


def has(found, command, *flags, **options):
    """Whether some call of `command` carries all `flags` and every `--name value` of `options` ('_' for '-')."""
    for arguments in found:
        if command_of(arguments) != command or not all(flag in arguments for flag in flags):
            continue
        parsed = vars(metrics.build_parser().parse_args(arguments))
        if all(parsed.get(name) == value for name, value in options.items()):
            return True
    return False


class SkillCallsMetrics(unittest.TestCase):
    def setUp(self):
        self.text = read_skill()
        self.sections = sections(self.text)

    def test_every_call_is_accepted_by_the_parser(self):
        found = calls(self.text)
        self.assertTrue(found, 'SKILL.md does not call tools/metrics/metrics.py')
        for arguments in found:
            with self.subTest(arguments=arguments):
                try:
                    metrics.build_parser().parse_args(arguments)
                except SystemExit:
                    self.fail('the parser of metrics.py rejects: %s' % ' '.join(arguments))

    def test_waste_runs_for_the_run_home_at_the_tracking_step(self):
        found = calls(self.sections['3'])
        self.assertTrue(has(found, 'waste'), 'section 3 (Śledzenie) has no `waste` call')
        waste = [a for a in found if command_of(a) == 'waste'][0]
        self.assertEqual(waste[1], '$AO_HOME')

    def test_every_waste_alarm_is_pushed_and_nothing_is_stopped(self):
        text = self.sections['3']
        self.assertIn('`push`', text)
        self.assertIn('PushNotification', text)
        self.assertRegex(text, r'(?s)`ao release`.*polecenie użytkownika|polecenie użytkownika.*`ao release`')

    def test_start_runs_backfill_for_an_empty_record_directory_and_then_trend(self):
        text = self.sections['1']
        found = calls(text)
        self.assertTrue(has(found, 'backfill'))
        self.assertTrue(has(found, 'trend'))
        self.assertIn('shop-metrics', text)
        self.assertLess(text.index('metrics.py" backfill'), text.index('metrics.py" trend'))

    def test_acceptance_report_records_renders_and_decides(self):
        text = self.sections['6']
        found = calls(text)
        self.assertTrue(has(found, 'epic', ao_home='$AO_HOME'))
        self.assertTrue(has(found, 'render', format='report'))
        self.assertTrue(has(found, 'decide', history=True))
        self.assertTrue(has(found, 'waste'))
        self.assertFalse(any('--manual-fixes' in a for a in found), 'the record before the merge has no manual fixes')
        for phrase in ('**Pomiar:**', '**Decyzje optymalizacyjne:**', 'brak: żadna reguła nie zadziałała', 'najwyżej 3',
                       'wąskich gardeł'):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, ' '.join(text.split()))   # the lines of SKILL.md are wrapped
        self.assertLess(text.index('metrics.py" epic'), text.index('metrics.py" render'))
        self.assertLess(text.index('night_queue.py" --repo "$SHOP" done'), text.index('metrics.py" epic'))

    def test_decisions_are_the_last_item_of_the_report_after_the_measurement(self):
        text = self.sections['6']
        start = text.index('Zostaw w rozmowie raport odbioru:')
        end = text.index('Gdy epik kończy się jako `BLOCKED`')
        items = re.findall(r'^- \*\*([^*]+?):?\*\*', text[start:end], re.M)
        self.assertEqual(items[-1], 'Decyzje optymalizacyjne')
        self.assertLess(items.index('Pomiar'), items.index('Decyzje optymalizacyjne'))

    def test_manual_comment_without_a_record_is_written_to_the_comment_file(self):
        text = ' '.join(self.sections['7'].split())
        fallback = text[text.index('**Bez rekordu**'):text.index('**Para równoległa**')]
        self.assertIn('$AO_HOME/comment.md', fallback)
        self.assertIn('cytowany heredoc', fallback)
        self.assertIn('--body-file', fallback)

    def test_decisions_are_not_written_down(self):
        text = self.sections['6']
        self.assertFalse(any(command_of(a) == 'effect' for a in calls(self.text)))
        self.assertIn('nie zapisujesz', text)

    def test_comment_after_merge_has_manual_fixes_and_trend(self):
        text = self.sections['7']
        found = calls(text)
        self.assertTrue(has(found, 'epic', '--manual-fixes', ao_home='$AO_HOME'))
        self.assertTrue(has(found, 'render', format='comment'))
        self.assertTrue(has(found, 'trend', epic='<epik>'))
        self.assertLess(text.index('--manual-fixes'), text.index('--format comment'))
        for phrase in ('### Pomiar epiku', 'Ręczne poprawki: N', '- PR #<nr>: <opis>'):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_exit_codes_two_and_three_are_handled(self):
        for name in ('1', '3', '6', '7'):
            with self.subTest(section=name):
                self.assertRegex(self.sections[name], r'(?i)kod(?:em)? `?2`?')
        for name in ('6', '7'):
            with self.subTest(section=name):
                self.assertRegex(self.sections[name], r'(?i)kod(?:em)? `?3`?')

    def test_no_other_section_calls_metrics(self):
        for name, text in self.sections.items():
            if name not in ('1', '3', '6', '7'):
                with self.subTest(section=name):
                    self.assertFalse(calls(text))


if __name__ == '__main__':
    unittest.main()

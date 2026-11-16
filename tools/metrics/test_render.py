"""Tests of `metrics.py render` (spec: Raport odbioru i komentarz w issue, Model zagrożeń: droga 1)."""
import copy
import os
import sys
import unittest

import testkit
from testkit import Env, WEEKLY, fake_record, run_cli, store_records

sys.path.insert(0, os.path.join(testkit.HERE, '..', '..', '.claude', 'skills', 'retro'))
import retro  # noqa: E402  (its parser reads the measurement comment: the format is a contract)

H = 3600.0


def history(count=5):
    found = []
    for i in range(count):
        record = fake_record('h%d' % i, merged='2026-10-%02dT10:00:00Z' % (i + 1), tasks=5, to_done=5 * H,
                             work=2 * H, human_wait=0.5 * H, review=0.5 * H, gate_wait=0.0, repeat=0.0,
                             value=5.0)
        record['by_task'] = {'T-%d' % n: {'kind': 'plan', 'work': 0.4 * H, 'attempts': 1} for n in range(5)}
        found.append(record)
    return found


def cell(usd, estimated=False):
    return {'usd': usd, 'estimated': estimated, 'tokens': {'input': 1, 'output': 1, 'cache_read': 1, 'cache_write': 1},
            'estimated_tokens': []}


def rich(**over):
    record = fake_record('demo', merged='2026-11-10T10:00:00Z', final_pr=77, tasks=5, to_done=10 * H, work=5 * H,
                         human_wait=2 * H, review=2 * H, gate_wait=0.5 * H, repeat=0.3 * H, value=12.34, weekly=6,
                         share=0.4, failed=1, repair=1, interventions=2, regressions=1, cli=('2.1.311',),
                         models=('claude-opus-5-5', 'claude-sonnet-5-5'))
    record['breakdown'].update(limit_wait=1 * H, ci_wait=0.5 * H, idle=1 * H)
    record['wall']['to_merge'] = 12 * H
    record['in_attempts']['categories']['test'] = {'count': 4, 'background': 0, 'unfinished': 0, 'seconds': 0.5 * H}
    record['in_attempts']['categories']['e2e'] = {'count': 2, 'background': 0, 'unfinished': 0, 'seconds': 1 * H}
    record['in_attempts']['repeat_runs'] = {'count': 2, 'seconds': 0.3 * H,
                                            'by_category': {'test': {'count': 2, 'seconds': 0.3 * H},
                                                            'e2e': {'count': 0, 'seconds': 0.0},
                                                            'check': {'count': 0, 'seconds': 0.0}}}
    record['in_attempts']['api_retry'] = {'count': 3, 'delay_ms': 12000}
    record['in_attempts']['permission_denials'] = {'Bash': 2}
    record['integration'] = {'work_s': 1.5 * H, 'attempts': 3, 'rounds': 2}
    record['value']['total'] = cell(12.34)
    record['value']['by_role'] = {'delivery': cell(3.0), 'developer': cell(5.0, True), 'other': cell(4.34, True)}
    record['value']['by_model'] = {'claude-opus-5-5': cell(10.0), 'claude-sonnet-5-5': cell(2.34)}
    record['value']['by_task'] = {'T-1': cell(6.0), 'T-2': cell(3.0)}
    record['subscription'] = {'windows': {WEEKLY: 6, 'Weekly · Fable': 0}, 'overlap': ['other-epic'],
                              'share': 0.4, 'share_estimated': True}
    record['retries']['attempts'].update(total=7, successful=5, failed=1, blocked=1)
    record['retries']['failed_by_outcome'] = {'timeout': 1}
    record['retries']['repair'] = {'count': 1, 'final_pr_conflict': 1, 'ci_red': 0, 'unknown': 0}
    record['retries']['fix'] = 1
    record['retries']['sync_prs'] = [{'number': 330, 'state': 'MERGED'}]
    record['gate_wait'] = {'wait_s': 0.5 * H, 'reasons': ['files_overlap']}
    record['human'] = {'interventions': 2, 'waits': 3, 'wait_s': 2700.0, 'open': 0,
                       'manual_fixes': [{'pr': 330, 'text': 'synchronizacja z main po konflikcie końcowego PR'},
                                        {'pr': None, 'text': 'ręczne ao release po awarii stosu'}]}
    record['by_task'] = {'T-1': {'kind': 'plan', 'work': 3 * H, 'attempts': 2},
                         'T-2': {'kind': 'plan', 'work': 0.4 * H, 'attempts': 1}}
    record['regressions'].update(ci_red=1, reverts=[], issues=[], count=1)
    record['found_after_merge'] = {'issues': [91, 92], 'count': 2}
    record['ci'] = {'final': {'pr': 77, 'runs': 2, 'checks_s': 750.0, 'e2e_s': 1200.0},
                    'tasks': {'T-1': {'pr': 70, 'runs': 3, 'checks_s': 600.0, 'e2e_s': 900.0},
                              'T-2': {'pr': 71, 'runs': 1, 'checks_s': 300.0, 'e2e_s': None}}}
    record['gaps'] = ['gate_wait: brak pliku gate.jsonl', 'value: część prób bez wartości: suma niepełna']
    record.update(over)
    return record


class RenderEnv(Env):
    def render(self, epic='demo', fmt='report', *extra):
        return run_cli(['render', epic, '--format', fmt, '--state', self.state] + list(extra))

    def text(self, fmt, record=None, past=None):
        store_records(self.state, *(history() if past is None else past), record or rich())
        code, out, err = self.render('demo', fmt)
        self.assertEqual(code, 0, err)
        return out


class ReportTest(RenderEnv):
    def test_report_starts_with_the_bottlenecks(self):
        out = self.text('report')
        first = out.splitlines()[0]
        self.assertTrue(first.startswith('Wąskie gardła'), first)
        numbered = [line for line in out.splitlines() if line.strip()[:2] in ('1.', '2.', '3.')]
        self.assertTrue(numbered, out)
        self.assertLess(out.index('Wąskie gardła'), out.index('Czas do epic_done'))

    def test_a_bottleneck_line_has_its_excess_share_and_rule(self):
        out = self.text('report')
        line = next(l for l in out.splitlines() if l.strip().startswith('1.'))
        self.assertIn('ponad medianę', line)
        self.assertRegex(line, r'\+\d+,\d h')
        self.assertRegex(line, r'\(\d+% czasu\)')

    def test_review_is_not_in_the_report_even_when_it_is_the_biggest_excess(self):
        record = rich()
        record['wall']['review'] = 40 * H
        record['bottlenecks'] = [{'kind': 'review', 'name': 'review', 'value_s': 40 * H, 'median_s': 0.5 * H,
                                  'excess_h': 39.5, 'share': 0.9, 'share_of': 'to_merge', 'rule': 'D4'}]
        out = self.text('report', record)
        self.assertNotIn('`review`', out)
        self.assertNotIn('odbiór', out.splitlines()[0])

    def test_report_has_every_field_of_the_spec(self):
        out = self.text('report')
        for text in ('Czas do epic_done', 'work', 'human_wait', 'limit_wait', 'Czas w próbach', 'możliwe powtórzenia testów',
                     'ponowienia API', 'odmowy uprawnień', 'Integracja', 'Wartość tokenów', 'role:', 'modele:',
                     'najdroższe zadanie: T-1', 'Subskrypcja', 'pp', 'share', 'Próby', 'REPAIR', 'FIX', 'Sync',
                     'Czekanie w bramce', 'Interwencje człowieka', 'Alarmy', 'Wersje CLI', 'claude-opus-5-5',
                     'Pozycja na tle mediany', 'Braki danych', 'brak pliku gate.jsonl'):
            self.assertIn(text, out, text)

    def test_time_to_done_has_the_breakdown_and_the_value_per_task(self):
        out = self.text('report')
        line = next(l for l in out.splitlines() if l.startswith('Czas do epic_done'))
        self.assertIn('10,0 h', line)
        self.assertIn('2,0 h na zadanie', line)
        self.assertIn('work 5,0 h (50%)', line)
        self.assertIn('human_wait 2,0 h (20%)', line)

    def test_repeat_runs_are_possible_repeats_not_waste(self):
        out = self.text('report')
        self.assertIn('możliwe powtórzenia testów: 2 (0,3 h)', out)
        self.assertIn('ponowienia API: 3 (łącznie 12 s)', out)
        self.assertIn('odmowy uprawnień: Bash 2', out)

    def test_estimated_values_are_marked_with_an_approximation_sign(self):
        out = self.text('report')
        roles = next(l for l in out.splitlines() if 'role:' in l)
        self.assertIn('developer ≈ 5,00 USD', roles)
        self.assertIn('delivery 3,00 USD', roles)
        self.assertIn('podział między subagentów szacowany', roles)
        total = next(l for l in out.splitlines() if l.startswith('Wartość tokenów'))
        self.assertIn('razem 12,34 USD', total)
        record = rich()
        record['value']['total']['estimated'] = True
        self.assertIn('razem ≈ 12,34 USD', next(l for l in self.text('report', record).splitlines()
                                                 if l.startswith('Wartość tokenów')))

    def test_the_role_note_is_there_even_when_no_role_cell_is_estimated(self):
        record = rich()
        record['value']['by_role'] = {'delivery': cell(12.34)}
        out = self.text('report', record)
        self.assertIn('podział między subagentów szacowany', out)

    def test_token_value_and_subscription_cost_are_shown_apart_and_never_summed(self):
        out = self.text('report')
        value_line = next(l for l in out.splitlines() if l.startswith('Wartość tokenów'))
        sub_line = next(l for l in out.splitlines() if l.startswith('Subskrypcja'))
        self.assertNotIn('pp', value_line)
        self.assertNotIn('USD', sub_line)
        self.assertIn('osobno', sub_line)
        self.assertNotIn('18,34', out)

    def test_subscription_shows_share_when_epics_overlapped_and_not_otherwise(self):
        sub = next(l for l in self.text('report').splitlines() if l.startswith('Subskrypcja'))
        self.assertIn('share ≈ 0,40', sub)
        self.assertIn('other-epic', sub)
        self.assertIn('+6 pp', sub)
        record = rich()
        record['subscription'].update(overlap=[], share=1, share_estimated=False)
        self.assertNotIn('share', next(l for l in self.text('report', record).splitlines()
                                       if l.startswith('Subskrypcja')))

    def test_unknown_sources_say_no_data_never_zero(self):
        record = rich(**{'subscription': None, 'in_attempts': None, 'gate_wait': None})
        record['value']['total'] = None
        record['wall']['to_done'] = None
        record['breakdown'] = None
        out = self.text('report', record)
        for label in ('Czas do epic_done', 'Czas w próbach', 'Wartość tokenów', 'Subskrypcja', 'Czekanie w bramce'):
            line = next(l for l in out.splitlines() if l.startswith(label))
            self.assertIn('brak danych', line, label)
            self.assertNotRegex(line, r'\b0[,.]0\b')

    def test_gate_without_waiting_is_zero(self):
        record = rich(**{'gate_wait': {'wait_s': 0.0, 'reasons': []}})
        line = next(l for l in self.text('report', record).splitlines() if l.startswith('Czekanie w bramce'))
        self.assertIn('bez czekania', line)

    def test_position_against_the_median_of_the_history(self):
        out = self.text('report')
        line = next(l for l in out.splitlines() if l.startswith('Pozycja na tle mediany'))
        self.assertIn('5 epików', line)
        self.assertIn('to_done', line)
        self.assertIn('mediana', line)

    def test_position_without_history_says_so(self):
        line = next(l for l in self.text('report', past=[]).splitlines() if l.startswith('Pozycja na tle mediany'))
        self.assertIn('brak historii', line)

    def test_report_after_the_merge_has_the_same_bottlenecks_as_before_without_review(self):
        record = rich()
        before = copy.deepcopy(record)
        for key in ('merged_at',):
            before[key] = None
        before['wall'].update(to_merge=None, review=None)
        self.assertEqual(self.text('report', record).splitlines()[:4],
                         self.text('report', before).splitlines()[:4])


class RenderErrorsTest(RenderEnv):
    def test_missing_record_is_an_error_with_the_epic_name(self):
        code, out, err = self.render('nope')
        self.assertEqual(code, 2)
        self.assertIn('nope', err)
        self.assertEqual(out, '')

    def test_unreadable_record_is_an_error(self):
        os.makedirs(os.path.join(self.state, 'epics'))
        with open(os.path.join(self.state, 'epics', 'demo.json'), 'w') as f:
            f.write('{broken')
        code, out, err = self.render('demo')
        self.assertEqual(code, 2)
        self.assertIn('demo', err)

    def test_unknown_format_is_a_usage_error(self):
        store_records(self.state, rich())
        code, out, err = self.render('demo', 'html')
        self.assertEqual(code, 2)
        self.assertEqual(out, '')

    def test_an_epic_id_that_leaves_the_state_directory_is_refused(self):
        code, out, err = self.render('../../etc/passwd')
        self.assertEqual(code, 2)


class CommentTest(RenderEnv):
    def test_comment_has_the_measurement_block_with_the_numbers_after_the_merge(self):
        out = self.text('comment')
        self.assertIn('### Pomiar epiku', out)
        self.assertIn('Czas do merge\'u: 12,0 h', out)
        self.assertIn('odbiór (review): 2,0 h', out)
        self.assertIn('Regresje po merge\'u', out)
        self.assertIn('znalezione po merge\'u: 2', out)

    def test_comment_bottlenecks_include_review(self):
        record = rich()
        record['bottlenecks'] = [{'kind': 'review', 'name': 'review', 'value_s': 40 * H, 'median_s': 0.5 * H,
                                  'excess_h': 39.5, 'share': 0.9, 'share_of': 'to_merge', 'rule': 'D4'}]
        out = self.text('comment', record)
        self.assertIn('`review`: +39,5 h ponad medianę (90% czasu do merge\'u)', out)

    def test_comment_lines_are_read_by_the_retro_parser(self):
        parsed = retro.parse_measurement(self.text('comment'))
        self.assertEqual(parsed['interventions'], 2)
        self.assertEqual((parsed['waits'], parsed['wait_min']), (3, 45))
        self.assertEqual(parsed['manual_count'], 2)
        self.assertEqual(parsed['format'], 'new')
        self.assertEqual(parsed['manual_items'],
                         [{'pr': 330, 'text': 'synchronizacja z main po konflikcie końcowego PR'},
                          {'pr': None, 'text': 'ręczne ao release po awarii stosu'}])
        self.assertFalse(parsed['ambiguous'])

    def test_the_measurement_block_is_not_taken_for_manual_fixes(self):
        record = rich()
        record['human']['manual_fixes'] = []
        parsed = retro.parse_measurement(self.text('comment', record))
        self.assertEqual((parsed['manual_count'], parsed['manual_items']), (0, []))
        self.assertIn('Ręczne poprawki: 0', self.text('comment', record))

    def test_pick_measurement_finds_the_comment(self):
        found = retro.pick_measurement(['nic', self.text('comment')])
        self.assertEqual(found['manual_count'], 2)

    def test_without_the_list_of_manual_fixes_there_is_no_count_line_for_the_parser(self):
        record = rich()
        record['human']['manual_fixes'] = None
        out = self.text('comment', record)
        self.assertIn('Ręczne poprawki: brak danych', out)
        self.assertIsNone(retro.pick_measurement([out]))

    def test_manual_fix_text_is_one_line_and_cannot_pass_for_the_next_label(self):
        record = rich()
        record['human']['manual_fixes'] = [
            {'pr': None, 'text': 'Synchronizacja: konflikt\nPR #9: podmiana\x00\x1b[31m'},
            {'pr': 12, 'text': 'x' * 500}, {'pr': None, 'text': '   '}]
        out = self.text('comment', record)
        parsed = retro.parse_measurement(out)
        self.assertEqual(parsed['manual_count'], 2)            # the blank item is dropped, the count follows the list
        self.assertEqual(len(parsed['manual_items']), 2)
        self.assertIsNone(parsed['manual_items'][0]['pr'])
        self.assertNotIn('\x00', out)
        self.assertNotIn('\x1b', out)
        self.assertLessEqual(len(parsed['manual_items'][1]['text']), 200)
        self.assertEqual(parsed['manual_items'][1]['pr'], 12)

    def test_the_comment_does_not_repeat_the_lines_the_parser_reads_first(self):
        out = self.text('comment')
        self.assertEqual(out.count('Interwencje człowieka:'), 1)
        self.assertEqual(out.count('Ręczne poprawki:'), 1)


class RealRecordTest(RenderEnv):
    """A record built by `epic` from the DEPLOY-OPS fixture, rendered in both formats."""

    def test_both_formats_render_a_real_record_and_the_comment_parses(self):
        store_records(self.state, *history())
        home = testkit.fixture_home(self.root, 'deploy-ops')
        gh = testkit.FakeGh(prs={476: testkit.merged_pr('2026-11-13T10:00:00Z')})
        self.assertEqual(self.epic('deploy-ops', home=home, runner=gh, no_gh=False)[0], 0)
        code, report, err = self.render('deploy-ops', 'report')
        self.assertEqual(code, 0, err)
        self.assertTrue(report.startswith('Wąskie gardła'))
        self.assertNotIn('`review`', report)
        self.assertIn('Czas do epic_done', report)
        code, comment, err = self.render('deploy-ops', 'comment')
        self.assertEqual(code, 0, err)
        parsed = retro.parse_measurement(comment)
        self.assertIsNotNone(parsed['interventions'])
        self.assertNotIn('/Users/', comment + report)

    def test_manual_fixes_of_the_session_file_reach_the_comment_in_the_parsed_format(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        path = os.path.join(self.root, 'fixes.txt')
        with open(path, 'w', encoding='utf-8') as f:
            f.write('Ręczne poprawki: 2\n- PR #330: sync po konflikcie\n- ręczne ao release po awarii\n')
        gh = testkit.FakeGh(prs={476: testkit.merged_pr('2026-11-13T10:00:00Z')})
        self.assertEqual(self.epic('deploy-ops', '--manual-fixes', path, home=home, runner=gh, no_gh=False)[0], 0)
        parsed = retro.parse_measurement(self.render('deploy-ops', 'comment')[1])
        self.assertEqual(parsed['manual_count'], 2)
        self.assertEqual(parsed['manual_items'], [{'pr': 330, 'text': 'sync po konflikcie'},
                                                  {'pr': None, 'text': 'ręczne ao release po awarii'}])


POISON = ['/Users/pp/secret/project', 'rm -rf /Users/pp', 'ghp_abcdefghijklmnopqrstuvwxyz0123456789',
          'Traceback (most recent call last)', 'sk-live-1234']


class AllowlistTest(RenderEnv):
    def poisoned(self):
        record = rich()
        bad = POISON[1]
        record['gaps'] = ['attempts.transcript: %s' % POISON[0], 'ci: błąd gh: %s' % POISON[2],
                          'by_task: %s' % POISON[3], 'gate_wait: nieczytelny gate.jsonl: [Errno 13] %s' % POISON[0],
                          'x y: %s' % POISON[4]]
        record['retries']['failed_by_outcome'] = {bad: 1, POISON[0]: 1}
        record['retries']['sync_prs'] = [{'number': 330, 'state': POISON[1]}, {'number': POISON[0], 'state': 'OPEN'}]
        record['gate_wait']['reasons'] = [POISON[0], bad]
        record['value']['by_role'] = {POISON[1]: cell(1.0), 'developer': cell(2.0, True)}
        record['value']['by_model'] = {POISON[0]: cell(1.0), 'claude-opus-5-5': cell(2.0)}
        record['value']['by_task'] = {POISON[1]: cell(99.0), 'T-1': cell(2.0)}
        record['in_attempts']['permission_denials'] = {POISON[1]: 4, 'Bash': 1}
        record['versions'] = {'cli': [{'version': POISON[0], 'attempts': 1}, {'version': '2.1.311', 'attempts': 1}],
                              'models': [{'model': POISON[2], 'attempts': 1}]}
        record['subscription']['overlap'] = [POISON[0], 'other-epic']
        record['subscription']['windows'] = {POISON[3]: 5, WEEKLY: 6}
        record['bottlenecks'] = [{'kind': 'task', 'name': POISON[1], 'value_s': 9 * H, 'median_s': H, 'excess_h': 8.0,
                                  'share': 0.2, 'share_of': 'to_done', 'rule': POISON[0]},
                                 {'kind': POISON[1], 'name': 'T-1', 'value_s': 9 * H, 'median_s': H, 'excess_h': 7.0,
                                  'share': 0.2, 'share_of': POISON[0], 'rule': 'D2'}]
        record['human']['manual_fixes'] = [{'pr': 330, 'text': 'synchronizacja'}]
        record['ci']['tasks'] = {POISON[1]: {'pr': 1, 'runs': 5, 'checks_s': 1.0, 'e2e_s': 1.0},
                                 'T-1': {'pr': 2, 'runs': 1, 'checks_s': 1.0, 'e2e_s': 1.0}}
        record['regressions']['issues'] = [POISON[0], 5]
        record['regressions']['reverts'] = [POISON[1]]
        return record

    def test_no_text_from_the_run_data_reaches_the_comment(self):
        out = self.text('comment', self.poisoned())
        for poison in POISON:
            self.assertNotIn(poison, out)
        self.assertNotIn('/Users/', out)
        self.assertNotIn('Traceback', out)
        parsed = retro.parse_measurement(out)
        self.assertEqual(parsed['manual_count'], 1)

    def test_identifiers_that_are_safe_survive_the_filter(self):
        out = self.text('comment', self.poisoned())
        for text in ('claude-opus-5-5', '2.1.311', 'other-epic', 'T-1', 'developer', 'PR #330'):
            self.assertIn(text, out)

    def test_gaps_are_listed_in_the_comment_by_field_name_only(self):
        out = self.text('comment', self.poisoned())
        line = next(l for l in out.splitlines() if 'Braki danych' in l)
        self.assertIn('attempts.transcript', line)
        self.assertIn('gate_wait', line)
        self.assertNotIn('Errno', line)
        self.assertNotIn('x y', line)

    def test_numbers_that_are_text_in_the_record_are_no_data(self):
        record = rich()
        record['wall']['to_done'] = POISON[0]
        record['value']['total'] = {'usd': POISON[2], 'estimated': False}
        record['in_attempts']['api_retry'] = {'count': POISON[1], 'delay_ms': POISON[0]}
        record['human']['interventions'] = POISON[3]
        out = self.text('comment', record)
        for poison in POISON:
            self.assertNotIn(poison, out)

    def test_a_bool_is_not_a_number(self):
        record = rich()
        record['wall']['to_done'] = True
        out = self.text('comment', record)
        line = next(l for l in out.splitlines() if l.startswith('- Czas do epic_done'))
        self.assertIn('brak danych', line)

    def test_a_record_that_is_not_an_object_is_an_error(self):
        os.makedirs(os.path.join(self.state, 'epics'))
        with open(os.path.join(self.state, 'epics', 'demo.json'), 'w') as f:
            f.write('[1, 2]')
        code, out, err = self.render('demo', 'comment')
        self.assertEqual(code, 2)


if __name__ == '__main__':
    unittest.main()

"""Tests of the process fields of the native record: the time breakdown, the human, the reviews and the integration,
the retries, the acceptance criteria of the final PR and the subscription (docs/features/ash-v1/spec.md, M3 rows
`breakdown`, `human`, `integration`, `retries`, `reviews`, `acceptance`, `subscription`, M4, M5, M7; criteria 1, 13).
python3 -m unittest discover -s tools/metrics -p 'test_*.py'"""
import json
import os
import unittest

import metrics
import testkit
from testkit import OPUS, SONNET, Native, NativeEnv, iso_at, run_cli

WEEKLY = testkit.WEEKLY
GATE_CALL = ['gh', 'api', '--include']


def window(label, percent, resets):
    return {'label': label, 'percentUsed': percent, 'resetsAt': resets}


def gaps_of(record, field):
    return [g for g in record['gaps'] if g.startswith(field)]


# (agent id, agentType, description, reports (texts in the order they are handed back; None: no SubagentHandback))
REVIEWS = [
    ('ar1', 'code-reviewer', 'Review SMALL-FIXES-1 FULL',
     ['## Przegląd SMALL-FIXES-1, tryb FULL: werdykt PASS\nTAJNY-RAPORT ustaleń']),
    ('ar4', 'code-reviewer', 'Review SMALL-FIXES-4 FULL',
     ['**Tryb:** FULL\n**Werdykt:** **CHANGES_REQUIRED** (jedno ustalenie IMPORTANT)\nTAJNY-RAPORT',
      '**Tryb:** DELTA\n**Werdykt:** **PASS**. TAJNY-RAPORT']),
    ('ar5', 'code-reviewer', 'Review SMALL-FIXES-5 FULL',
     ['Brak ustaleń BLOCKER ani IMPORTANT. Werdykt: **PASS**.\n- Tryb: FULL. TAJNY-RAPORT']),
    ('ar7', 'code-reviewer', 'Review SMALL-FIXES-7 FULL',
     ['**Werdykt: CHANGES_REQUIRED.** Jedyny powód to IMPORTANT TAJNY-RAPORT',
      '**Tryb:** DELTA.\n**Werdykt: PASS.** Wszystkie trzy ustalenia z rundy FULL są naprawione. TAJNY-RAPORT']),
    ('ar8', 'code-reviewer', 'Review SMALL-FIXES-8 FULL',
     ['## Przegląd SMALL-FIXES-8: werdykt PASS\n- **Tryb:** FULL. TAJNY-RAPORT']),
    ('aux', 'ux-reviewer', 'Przegląd UX całej zmiany', ['Tryb: FULL\n| Pasek demo | ok | TAJNY-RAPORT']),
    ('aqa', 'qa-adversarial', 'QA całej zmiany SMALL-FIXES',
     ['**Werdykt: PASS** (0 potwierdzonych błędów) TAJNY-RAPORT\n## 5. Werdykt\nPASS']),
    ('agp', 'general-purpose', 'Dane porównawcze epików AO', ['Tryb DELTA, werdykt PASS TAJNY-RAPORT']),
] + [('ad%d' % n, 'developer', 'SMALL-FIXES-%d zadanie' % n, ['Testy: werdykt PASS, RED -> GREEN TAJNY-RAPORT'])
     for n in range(1, 9)]


class ProcessEnv(NativeEnv):
    def subagent(self, agent, kind, description, reports, start, session='s1', finished=True, model=OPUS):
        """A subagent run from `start`: one report per text, each after a resume (a `coordinator` line) except the
        first."""
        transcript = Native(session, agent).assistant(start, model=model, input=100)
        at = start
        for index, text in enumerate(reports):
            if index:
                transcript.notice(at + 30, 'coordinator').assistant(at + 40, model=model, input=10)
                at += 40
            transcript.handback(at + 60, text, model=model, output=5)
            at += 60
        self.write_subagent(session, agent, transcript, {'agentType': kind, 'description': description,
                                                         'model': 'opus', 'effort': 'high', 'finished': finished})


class ReviewsTest(ProcessEnv):
    """M4 and criterion 1 (part): the reports handed back by the review roles."""

    def small_fixes(self):
        self.write_run('small-fixes', tasks=8, done=20000)
        self.write_session('s1', Native('s1').human(-30).assistant(10, input=100))
        for index, (agent, kind, description, reports) in enumerate(REVIEWS):
            self.subagent(agent, kind, description, reports, 100 + index * 500)
        return self.native_record('small-fixes')

    def test_seven_code_reviews_two_of_them_blocking(self):
        record = self.small_fixes()
        code = [r for r in record['reviews'] if r['role'] == 'code-reviewer']
        self.assertEqual(len(code), 7)
        self.assertEqual([(r['task'], r['mode'], r['verdict'], r['blocking']) for r in code], [
            ('SMALL-FIXES-1', 'FULL', 'PASS', False),
            ('SMALL-FIXES-4', 'FULL', 'CHANGES_REQUIRED', True),
            ('SMALL-FIXES-4', 'DELTA', 'PASS', False),
            ('SMALL-FIXES-5', 'FULL', 'PASS', False),
            ('SMALL-FIXES-7', 'FULL', 'CHANGES_REQUIRED', True),
            ('SMALL-FIXES-7', 'DELTA', 'PASS', False),
            ('SMALL-FIXES-8', 'FULL', 'PASS', False)])
        self.assertEqual([r['agent_id'] for r in code], ['ar1', 'ar4', 'ar4', 'ar5', 'ar7', 'ar7', 'ar8'])
        self.assertTrue(all(r['at'] for r in code))

    def test_only_review_roles_have_reviews(self):
        record = self.small_fixes()
        self.assertEqual(sorted({r['role'] for r in record['reviews']}),
                         ['code-reviewer', 'qa-adversarial', 'ux-reviewer'])
        qa = [r for r in record['reviews'] if r['role'] == 'qa-adversarial']
        self.assertEqual([(r['task'], r['mode'], r['verdict'], r['blocking']) for r in qa],
                         [(None, 'FULL', 'PASS', False)])

    def test_ux_report_without_a_verdict_is_null_with_a_gap(self):
        record = self.small_fixes()
        ux = [r for r in record['reviews'] if r['role'] == 'ux-reviewer']
        self.assertEqual([(r['mode'], r['verdict'], r['blocking']) for r in ux], [('FULL', None, None)])
        self.assertTrue(any('aux' in g for g in gaps_of(record, 'reviews')), record['gaps'])

    def test_fix_counts_the_blocking_reviews_of_tasks(self):
        record = self.small_fixes()
        retries = record['retries']
        self.assertEqual(retries['fix'], 2)
        self.assertEqual(retries['failed_by_outcome'], {})
        self.assertIsNone(retries['repair'])
        self.assertIsNone(retries['sync_prs'])

    def test_integration_of_the_review_roles_of_the_whole_change(self):
        record = self.small_fixes()
        integration = record['integration']
        self.assertEqual(integration['attempts'], 2)            # ux-reviewer and qa-adversarial
        self.assertEqual(integration['rounds'], 1)
        self.assertEqual(integration['work_s'], 120)            # 60 s of each

    def test_record_holds_no_text_of_the_reports(self):
        text = json.dumps(self.small_fixes(), ensure_ascii=False)
        for secret in ('TAJNY-RAPORT', 'Jedyny powód', 'Pasek demo', 'TAJNE-POLECENIE', 'TAJNE-POWIADOMIENIE'):
            self.assertNotIn(secret, text)

    def test_verdicts_of_qa_and_architecture(self):
        self.write_run(done=20000)
        self.write_session('s1', Native('s1').assistant(10, input=100))
        self.subagent('aqa', 'qa-adversarial', 'QA całej zmiany DEMO',
                      ['## 5. Werdykt\n\n**BLOCK**: TAJNY-RAPORT', 'Werdykt: INVESTIGATE', 'Werdykt: PASS'], 100)
        self.subagent('aar1', 'architecture-reviewer', 'Architektura DEMO', ['Werdykt: REWORK'], 1000)
        self.subagent('aar2', 'architecture-reviewer', 'Architektura DEMO', ['**Werdykt:** SIMPLIFY'], 2000)
        self.subagent('aar3', 'architecture-reviewer', 'Architektura DEMO', ['verdict: KEEP'], 3000)
        self.subagent('aar4', 'architecture-reviewer', 'Architektura DEMO', ['Werdykt: PASS'], 4000)
        record = self.native_record()
        found = [(r['agent_id'], r['mode'], r['verdict'], r['blocking']) for r in record['reviews']]
        self.assertEqual(found, [('aqa', 'FULL', 'BLOCK', True), ('aqa', 'DELTA', 'INVESTIGATE', False),
                                 ('aqa', 'DELTA', 'PASS', False), ('aar1', 'FULL', 'REWORK', True),
                                 ('aar2', 'FULL', 'SIMPLIFY', False), ('aar3', 'FULL', 'KEEP', False),
                                 ('aar4', 'FULL', None, None)])         # PASS is no verdict of architecture
        self.assertTrue(any('aar4' in g for g in gaps_of(record, 'reviews')), record['gaps'])
        self.assertEqual(record['retries']['fix'], 0)                   # no review of a task
        self.assertEqual(record['integration']['attempts'], 5)
        self.assertEqual(record['integration']['rounds'], 4)            # architecture-reviewer: four reports

    def test_review_run_without_a_report_has_a_gap(self):
        self.write_run()
        self.write_session('s1', Native('s1').assistant(10, input=100))
        self.write_subagent('s1', 'ar1', Native('s1', 'ar1').assistant(100, model=OPUS, input=5),
                            {'agentType': 'code-reviewer', 'description': 'Review DEMO-1', 'model': 'opus',
                             'effort': 'high', 'finished': False})
        record = self.native_record()
        self.assertEqual(record['reviews'], [])
        self.assertTrue(any('ar1' in g for g in gaps_of(record, 'reviews')), record['gaps'])
        self.assertEqual(record['retries']['failed_by_outcome'], {'unfinished': 1})

    def test_without_review_roles_integration_is_null_with_a_gap(self):
        self.write_run()
        self.write_session('s1', Native('s1').assistant(10, input=100))
        self.subagent('ad1', 'developer', 'DEMO-1', ['gotowe'], 100)
        record = self.native_record()
        self.assertIsNone(record['integration'])
        self.assertTrue(gaps_of(record, 'integration'), record['gaps'])
        self.assertEqual(record['reviews'], [])

    def test_run_without_finished_makes_failed_by_outcome_a_lower_bound(self):
        self.write_run()
        self.write_session('s1', Native('s1').assistant(10, input=100))
        self.write_subagent('s1', 'ad1', Native('s1', 'ad1').assistant(100, model=OPUS, input=5),
                            {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'opus', 'effort': 'high'})
        record = self.native_record()
        self.assertEqual(record['retries']['failed_by_outcome'], {})
        self.assertTrue(gaps_of(record, 'retries.failed_by_outcome'), record['gaps'])

    def test_report_after_the_merge_does_not_count(self):
        self.write_run(done=4000)
        self.write_session('s1', Native('s1').assistant(10, input=100))
        self.subagent('ar1', 'code-reviewer', 'Review DEMO-1', ['Werdykt: CHANGES_REQUIRED', 'Werdykt: PASS'],
                      4900)                        # the first report at 4960, the second (DELTA) at 5060
        gh = testkit.FakeGh(prs={752: testkit.merged_pr(merged_at=iso_at(5000))})
        record = self.native_record(runner=gh, no_gh=False)
        self.assertEqual([(r['mode'], r['verdict']) for r in record['reviews']], [('FULL', 'CHANGES_REQUIRED')])


class BreakdownHumanTest(ProcessEnv):
    """M3: `breakdown` (work, human_wait, idle) and `human` from the lines of the transcripts."""

    def scenario(self, *extra):
        self.write_run(started=0, done=4000)
        lead = (Native('s1').human(-50).assistant(10, input=1).bash('make test', 20, 80).assistant(90)
                .notice(595, None).notice(600, 'peer').assistant(610).human(1500).assistant(1510).assistant(1600)
                .human(3000).assistant(3010))
        self.write_session('s1', lead)
        self.write_subagent('s1', 'a1', Native('s1', 'a1').assistant(100, model=OPUS).assistant(400, model=OPUS),
                            {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'opus', 'effort': 'high',
                             'finished': True})
        self.write_subagent('s1', 'a2', Native('s1', 'a2').assistant(1700, model=OPUS).assistant(2000, model=OPUS),
                            {'agentType': 'developer', 'description': 'DEMO-2', 'model': 'opus', 'effort': 'high',
                             'finished': True})
        a3 = (Native('s1', 'a3').assistant(2100, model=OPUS).assistant(2200, model=OPUS)
              .notice(2600, 'coordinator').assistant(2650, model=OPUS))
        self.write_subagent('s1', 'a3', a3, {'agentType': 'developer', 'description': 'DEMO-3', 'model': 'opus',
                                             'effort': 'high', 'finished': True})
        return self.native_record('demo', *extra)

    def test_work_human_wait_and_idle(self):
        record = self.scenario()
        self.assertEqual(record['breakdown'], {'work': 960, 'human_wait': 1840, 'limit_wait': None, 'ci_wait': None,
                                               'host_pause': None, 'github_wait': None, 'idle': 1200})
        self.assertIs(record['breakdown_incomplete'], True)
        for name in ('limit_wait', 'ci_wait', 'host_pause', 'github_wait'):
            self.assertTrue(gaps_of(record, 'breakdown.%s' % name), (name, record['gaps']))

    def test_human_messages_after_the_start(self):
        human = self.scenario()['human']
        self.assertEqual(human['interventions'], 2)                 # the start command before the start does not count
        self.assertIs(human['interventions_estimated'], True)
        self.assertEqual(human['events'], [{'at': iso_at(1500), 'wait_s': 890}, {'at': iso_at(3000), 'wait_s': 950}])
        self.assertEqual(human['waits'], 2)
        self.assertEqual(human['wait_s'], 1840)

    def test_human_message_while_subagents_work_is_no_wait(self):
        self.write_run(started=0, done=4000)
        self.write_session('s1', Native('s1').assistant(10).assistant(100).human(500).assistant(510))
        self.write_subagent('s1', 'a1', Native('s1', 'a1').assistant(50, model=OPUS).assistant(700, model=OPUS),
                            {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'opus', 'effort': 'high',
                             'finished': True})
        record = self.native_record()
        self.assertEqual(record['human']['interventions'], 1)
        self.assertEqual(record['human']['events'], [{'at': iso_at(500), 'wait_s': 0}])
        self.assertEqual(record['human']['waits'], 0)
        self.assertEqual(record['breakdown']['human_wait'], 0)

    def test_manual_fixes(self):
        path = os.path.join(self.root, 'fixes.txt')
        with open(path, 'w', encoding='utf-8') as f:
            f.write('Ręczne poprawki: 1\n- PR #752: poprawka opisu\n')
        record = self.scenario('--manual-fixes', path)
        self.assertEqual(record['human']['manual_fixes'], [{'pr': 752, 'text': 'poprawka opisu'}])
        self.assertFalse(gaps_of(record, 'human.manual_fixes'), record['gaps'])

    def test_without_manual_fixes_null_with_a_gap(self):
        record = self.scenario()
        self.assertIsNone(record['human']['manual_fixes'])
        self.assertTrue(gaps_of(record, 'human.manual_fixes'), record['gaps'])

    def test_human_message_of_a_resumed_session_counts_once(self):
        self.write_run(sessions=('s1', 's2'), started=0, done=4000)
        first = Native('s1').assistant(10).human(1000)
        for index, line in enumerate(first.lines):
            line['uuid'] = 'u-%d' % index
        resumed = Native('s2')
        resumed.lines = [dict(line) for line in first.lines]        # a resumed session repeats the earlier lines
        resumed.assistant(1010).human(2000)
        self.write_session('s1', first)
        self.write_session('s2', resumed)
        record = self.native_record()
        self.assertEqual([e['at'] for e in record['human']['events']], [iso_at(1000), iso_at(2000)])
        self.assertEqual(record['human']['interventions'], 2)

    def test_human_message_without_timestamp_is_skipped_with_a_gap(self):
        self.write_run(started=0, done=4000)
        self.write_session('s1', Native('s1').assistant(10).human(None).human(100))
        record = self.native_record()
        self.assertEqual(record['human']['interventions'], 1)
        self.assertTrue(any('timestamp' in g for g in gaps_of(record, 'human')), record['gaps'])

    def test_without_done_the_breakdown_is_null_with_a_gap(self):
        self.write_run(done=None)
        self.write_session('s1', Native('s1').assistant(10).human(100))
        record = self.native_record()
        self.assertIsNone(record['breakdown'])
        self.assertTrue(gaps_of(record, 'breakdown'), record['gaps'])
        self.assertEqual(record['human']['interventions'], 1)


class MissingTranscriptsTest(ProcessEnv):
    """M3 and M7: without the transcripts of the epic the process fields are unknown (null with a gap), never zero;
    without some of them a lower bound with a gap; a refresh without them keeps the stored values."""

    def lead(self):
        return Native('s1').assistant(10, input=5).human(500).assistant(510)

    def reviewed(self):
        self.subagent('ar1', 'code-reviewer', 'Review DEMO-1', ['Werdykt: CHANGES_REQUIRED', 'Werdykt: PASS'], 100)

    def test_without_any_transcript_the_process_fields_are_null(self):
        self.write_run(sessions=('gone',), usage_start=START, usage_done=DONE)
        record = self.native_record()
        self.assertIsNone(record['breakdown'])
        for field in ('interventions', 'waits', 'wait_s', 'events'):
            self.assertIsNone(record['human'][field], field)
        self.assertIsNone(record['retries']['fix'])
        self.assertIsNone(record['retries']['failed_by_outcome'])
        self.assertIsNone(record['reviews'])
        self.assertIsNone(record['subscription']['share'])
        self.assertEqual(record['subscription']['windows'], {WEEKLY: 6, 'Session': None})
        for field in ('breakdown', 'human', 'retries.fix', 'retries.failed_by_outcome', 'reviews',
                      'subscription.share'):
            self.assertTrue(any('transkrypt' in g for g in gaps_of(record, field)), (field, record['gaps']))

    def test_without_the_transcript_of_some_sessions_the_values_are_lower_bounds(self):
        self.write_run(sessions=('s1', 'gone'))
        self.write_session('s1', self.lead())
        self.reviewed()
        record = self.native_record()
        self.assertEqual(record['human']['interventions'], 1)
        self.assertEqual(record['retries']['fix'], 1)
        self.assertIsNotNone(record['breakdown'])
        for field in ('breakdown', 'human', 'retries.fix', 'reviews'):
            self.assertTrue(any('dolna granica' in g for g in gaps_of(record, field)), (field, record['gaps']))

    def test_refresh_without_the_transcripts_keeps_the_stored_values(self):
        self.write_run()
        path = self.write_session('s1', self.lead())
        self.reviewed()
        before = self.native_record()
        os.remove(path)
        after = self.native_record()
        for field in ('breakdown', 'reviews'):
            self.assertEqual(after[field], before[field], field)
        self.assertEqual(after['human']['interventions'], 1)
        self.assertEqual(after['human']['events'], before['human']['events'])
        self.assertEqual(after['retries']['fix'], 1)
        self.assertTrue(any('zachowano' in g for g in gaps_of(after, 'breakdown')), after['gaps'])

    def test_refresh_without_the_transcripts_keeps_attempts_value_and_commands(self):
        self.write_run()
        lead = self.lead().bash('make test', 600, 660, input=50)
        path = self.write_session('s1', lead)
        self.reviewed()
        before = self.native_record()
        self.assertTrue(before['attempts'] and before['value']['total']['usd'] and before['versions'])
        os.remove(path)
        after = self.native_record()
        for field in ('attempts', 'by_task', 'value', 'in_attempts', 'versions', 'sessions'):
            self.assertEqual(after[field], before[field], field)
        for field in ('attempts', 'by_task', 'value', 'in_attempts', 'versions'):
            self.assertTrue(any('zachowano' in g for g in gaps_of(after, field)), (field, after['gaps']))

    def subagent_file(self, agent, suffix):
        return os.path.join(testkit.project_dir(self.projects, self.worktree), 's1', 'subagents',
                            'agent-%s%s' % (agent, suffix))

    def test_refresh_after_losing_a_subagent_transcript_keeps_the_stored_values(self):
        for lost in (('.jsonl',), ('.jsonl', '.meta.json')):
            with self.subTest(lost=lost):
                self.setUp()
                self.write_run()
                self.write_session('s1', self.lead())
                self.reviewed()
                self.subagent('ad1', 'developer', 'DEMO-1 zadanie', ['Gotowe'], 300)
                before = self.native_record()
                self.assertEqual(len(before['attempts']), 2)
                for suffix in lost:
                    os.remove(self.subagent_file('ad1', suffix))
                after = self.native_record()
                for field in ('attempts', 'by_task', 'value', 'reviews', 'retries', 'integration', 'breakdown',
                              'in_attempts'):
                    self.assertEqual(after[field], before[field], field)
                for field in ('attempts', 'value', 'by_task'):
                    self.assertTrue(any('zachowano' in g for g in gaps_of(after, field)), (field, after['gaps']))
                again = self.native_record()
                self.assertEqual(again['attempts'], before['attempts'])
                self.assertEqual(again['value'], before['value'])

    def test_refresh_after_losing_a_session_keeps_the_stored_values(self):
        self.write_run(sessions=('s1', 's2'))
        self.write_session('s1', self.lead())
        path = self.write_session('s2', Native("s2").assistant(1000, input=1000000).human(1500).assistant(1510))
        before = self.native_record()
        self.assertEqual(before['human']['interventions'], 2)
        os.remove(path)
        after = self.native_record()
        self.assertEqual(after['human'], before['human'])
        self.assertEqual(after['value'], before['value'])
        self.assertTrue(any('zachowano' in g for g in gaps_of(after, 'value')), after['gaps'])

    def test_refresh_with_more_transcripts_takes_the_new_values(self):
        self.write_run()
        self.write_session('s1', self.lead())
        self.reviewed()
        before = self.native_record()
        self.subagent('ad1', 'developer', 'DEMO-1 zadanie', ['Gotowe'], 300)
        after = self.native_record()
        self.assertEqual(len(before['attempts']), 1)
        self.assertEqual(len(after['attempts']), 2)
        self.assertGreater(after['value']['total']['usd'], before['value']['total']['usd'])
        self.assertFalse(any('zachowano' in g for g in after['gaps']), after['gaps'])

    def test_without_transcripts_and_without_a_stored_record_attempts_are_empty_with_gaps(self):
        self.write_run(sessions=('gone',))
        record = self.native_record()
        self.assertEqual(record['attempts'], [])
        self.assertEqual(record['by_task'], {})
        self.assertIsNone(record['value']['total'])
        for field in ('in_attempts', 'versions'):
            self.assertIsNone(record[field], field)
            self.assertTrue(gaps_of(record, field), (field, record['gaps']))
        self.assertFalse(any('zachowano' in g for g in record['gaps']), record['gaps'])

    def test_user_lines_without_origin_make_the_interventions_unknown(self):
        self.write_run()
        lead = Native('s1').assistant(10, input=5).notice(100, None).notice(200, None).assistant(210)
        self.write_session('s1', lead)
        record = self.native_record()
        self.assertIsNone(record['human']['interventions'])
        self.assertTrue(any('origin' in g for g in gaps_of(record, 'human')), record['gaps'])

    def test_user_lines_with_origin_but_no_human_are_zero_interventions(self):
        self.write_run()
        self.write_session('s1', Native('s1').assistant(10, input=5).notice(100, 'peer').assistant(110))
        record = self.native_record()
        self.assertEqual(record['human']['interventions'], 0)
        self.assertFalse(any('origin' in g for g in gaps_of(record, 'human')), record['gaps'])


LOWER_BOUND_FIELDS = ('value', 'attempts', 'by_task', 'reviews', 'retries.fix')


class SubagentTranscriptsTest(ProcessEnv):
    """M3, M7 and D1: a subagent whose transcript is missing, empty or unreadable while the lead session is read makes
    the values from the transcripts a lower bound with a gap, as a missing session does; it is never silently complete.
    A subagent without any file is seen by its launch in the lead session (`toolUseResult.agentId`)."""

    def agent_file(self, agent, suffix='.jsonl', session='s1'):
        return os.path.join(testkit.project_dir(self.projects, self.worktree), session, 'subagents',
                            'agent-%s%s' % (agent, suffix))

    def epic(self, lead=None):
        self.write_run()
        self.write_session('s1', lead or Native('s1').assistant(10, input=5).launch(20, 'ar1').launch(30, 'ad1')
                           .assistant(900, input=5))
        self.subagent('ar1', 'code-reviewer', 'Review DEMO-1', ['Werdykt: CHANGES_REQUIRED', 'Werdykt: PASS'], 100)
        self.subagent('ad1', 'developer', 'DEMO-1 zadanie', ['Gotowe'], 300)

    def assert_lower_bound(self, record, agent):
        for field in LOWER_BOUND_FIELDS:
            self.assertTrue(any('dolna granica' in g for g in gaps_of(record, field)), (field, record['gaps']))
        self.assertTrue(any(agent in g for g in gaps_of(record, 'sessions')), record['gaps'])

    def test_every_subagent_read_is_no_lower_bound(self):
        self.epic()
        record = self.native_record()
        self.assertEqual(len(record['attempts']), 2)
        self.assertFalse(any('dolna granica' in g for g in record['gaps']), record['gaps'])

    def test_meta_json_without_the_transcript(self):
        self.epic()
        os.remove(self.agent_file('ad1'))
        self.assert_lower_bound(self.native_record(), 'ad1')

    def test_empty_transcript_of_a_subagent(self):
        self.epic()
        open(self.agent_file('ad1'), 'w').close()
        self.assert_lower_bound(self.native_record(), 'ad1')

    @unittest.skipIf(hasattr(os, 'geteuid') and os.geteuid() == 0, 'root reads every file')
    def test_unreadable_transcript_of_a_subagent(self):
        self.epic()
        os.chmod(self.agent_file('ad1'), 0)
        try:
            record = self.native_record()
        finally:
            os.chmod(self.agent_file('ad1'), 0o600)
        self.assert_lower_bound(record, 'ad1')

    def test_subagent_launched_by_the_lead_without_any_file(self):
        self.epic()
        os.remove(self.agent_file('ad1'))
        os.remove(self.agent_file('ad1', '.meta.json'))
        record = self.native_record()
        self.assertEqual([a['agent_id'] for a in record['attempts']], ['ar1'])
        self.assert_lower_bound(record, 'ad1')

    def test_subagent_launched_before_the_start_without_any_file_is_no_gap(self):
        self.write_run(started=100)
        self.write_session('s1', Native('s1').launch(10, 'old').assistant(150, input=5).launch(160, 'ad1')
                           .assistant(900, input=5))
        self.subagent('ad1', 'developer', 'DEMO-1 zadanie', ['Gotowe'], 300)
        record = self.native_record()
        self.assertFalse(any('dolna granica' in g for g in record['gaps']), record['gaps'])
        self.assertFalse(any('old' in g for g in gaps_of(record, 'sessions')), record['gaps'])

    def test_subagent_of_another_session_of_the_epic_is_found(self):
        self.write_run(sessions=('s1', 's2'))
        self.write_session('s1', Native('s1').assistant(10, input=5).launch(20, 'ad1'))
        self.write_session('s2', Native('s2').assistant(500, input=5).launch(510, 'ad1'))
        self.subagent('ad1', 'developer', 'DEMO-1 zadanie', ['Gotowe'], 300)
        record = self.native_record()
        self.assertFalse(any('dolna granica' in g for g in record['gaps']), record['gaps'])

    def test_lost_session_makes_the_value_and_the_attempts_a_lower_bound(self):
        self.write_run(sessions=('s1', 'gone'))
        self.write_session('s1', Native('s1').assistant(10, input=5).launch(20, 'ar1').launch(30, 'ad1'))
        self.subagent('ar1', 'code-reviewer', 'Review DEMO-1', ['Werdykt: CHANGES_REQUIRED', 'Werdykt: PASS'], 100)
        self.subagent('ad1', 'developer', 'DEMO-1 zadanie', ['Gotowe'], 300)
        record = self.native_record()
        for field in LOWER_BOUND_FIELDS:
            self.assertTrue(any('dolna granica' in g for g in gaps_of(record, field)), (field, record['gaps']))


class VerdictTokenTest(unittest.TestCase):
    """M4: the verdict is the word after the label `Werdykt` or `Verdict` (the last labelled one), never the first verdict
    word anywhere in the text."""

    def test_forms_of_the_label(self):
        for text, verdict in (('Werdykt: `CHANGES_REQUIRED`', 'CHANGES_REQUIRED'), ('## Werdykt\n\n`PASS`', 'PASS'),
                              ('**Werdykt:** **CHANGES_REQUIRED**', 'CHANGES_REQUIRED'), ('werdykt PASS', 'PASS'),
                              ('Verdict — REWORK', 'REWORK'), ('Brak BLOCKER. Werdykt: **PASS**.', 'PASS')):
            self.assertEqual(metrics.verdict_token(text), verdict, text)

    def test_the_last_labelled_verdict_counts(self):
        self.assertEqual(metrics.verdict_token('poprzedni werdykt CHANGES_REQUIRED, teraz Werdykt: PASS'), 'PASS')
        self.assertEqual(metrics.verdict_token('CHANGES_REQUIRED w poprzedniej rundzie.\nWerdykt: PASS'), 'PASS')

    def test_the_first_label_at_the_start_of_a_line_wins(self):
        report = ('- **Werdykt: CHANGES_REQUIRED.** Są dwa ustalenia. Przykłady w tekście: Werdykt: `PASS`, '
                  'nagłówek ## Werdykt\n\n`PASS` oraz „poprzedni werdykt CHANGES_REQUIRED, teraz Werdykt: PASS”.')
        self.assertEqual(metrics.verdict_token(report), 'CHANGES_REQUIRED')
        self.assertEqual(metrics.verdict_token('Werdykt: PASS\n\nWerdykt: CHANGES_REQUIRED'), 'PASS')
        self.assertEqual(metrics.verdict_token('Werdykt: brak\nWerdykt: PASS\nWerdykt: BLOCK'), 'PASS')

    def test_the_label_at_the_start_of_a_line_after_markers(self):
        for text, verdict in (('> Werdykt: PASS\nWerdykt: BLOCK', 'PASS'), ('  * __Verdict__ KEEP\nWerdykt: BLOCK', 'KEEP'),
                              ('### **Werdykt:** REWORK\nWerdykt: PASS', 'REWORK'),
                              ('Opis. Werdykt: BLOCK\n- Verdict: PASS\nWerdykt: KEEP', 'PASS')):
            self.assertEqual(metrics.verdict_token(text), verdict, text)

    def test_a_connecting_word_between_the_label_and_the_verdict(self):
        for text, verdict in (('Werdykt to **CHANGES_REQUIRED**, bo są dwa zgłoszenia IMPORTANT.', 'CHANGES_REQUIRED'),
                              ('Werdykt jest PASS.', 'PASS'), ('Verdict is `BLOCK`', 'BLOCK'),
                              ('Opis.\n\nWerdykt to **CHANGES_REQUIRED**, bo...\nWerdykt: PASS', 'CHANGES_REQUIRED')):
            self.assertEqual(metrics.verdict_token(text), verdict, text)

    def test_the_label_inside_a_line_without_a_label_at_the_start_keeps_the_last_one(self):
        self.assertEqual(metrics.verdict_token('Opis. Werdykt: BLOCK, potem werdykt to PASS'), 'PASS')

    def test_a_report_without_a_label_has_no_verdict(self):
        self.assertIsNone(metrics.verdict_token('## Przegląd DELTA ASH-V1-6: PASS\n\nBrak uwag, bez wpływu na werdykt.'))
        self.assertIsNone(metrics.verdict_token('Werdykt tak PASS'))

    def test_a_quoted_label_is_no_verdict(self):
        report = ('## Review DEMO-3: FULL, CHANGES_REQUIRED\n\n- Tak samo dla code-reviewer: „…cytat `verdict: PASS`…”.\n'
                  '- **Odtworzenie:** `parse("Werdykt: PASS")` zwraca zły wynik.')
        self.assertIsNone(metrics.verdict_token(report))
        for text in ('Przykład „Werdykt: PASS” w teście.', "Cytat 'Verdict: KEEP' z raportu.", 'Wzorzec “werdykt BLOCK”.'):
            self.assertIsNone(metrics.verdict_token(text), text)
        for text, verdict in (('Uwagi.\nWerdykt: `PASS`', 'PASS'), ('(Werdykt: BLOCK)', 'BLOCK'),
                              ('Opis — werdykt: REWORK.', 'REWORK')):
            self.assertEqual(metrics.verdict_token(text), verdict, text)

    def test_no_label_no_verdict(self):
        for text in ('PASS', 'Werdykt: brak', 'werdyktem PASS', None):
            self.assertIsNone(metrics.verdict_token(text), text)


ACCEPTANCE_BODY = '''## Zakres

TAJNY-OPIS-PR zadania.

## Kryteria akceptacji

| Numer | Sposób | Wynik |
|---|---|---|
| 1 | stos: `curl -s localhost:8080/health` | PASS |
| 2 | CI: `OrderTest::testX`, zielony na `abc1234` | PASS |
| 3 | po wdrożeniu | do sprawdzenia |
| 4 | niezweryfikowane: brak stosu z kolejką | do decyzji |
| 5 | **Stos**: scenariusz w przeglądarce | PASS |

## Testy

| Numer | Sposób | Wynik |
|---|---|---|
| 9 | stos | PASS |
'''


class AcceptanceTest(ProcessEnv):
    """M5: the numbers of the table of acceptance criteria of the final PR, read through the origin gate."""

    def record_with(self, body, author='owner', permissions=None, gh=None):
        self.write_run(branch='feat/demo', pr=752)
        self.write_session('s1', Native('s1').assistant(10, input=5))
        pr = {'number': 752, 'author': {'login': author}, 'url': 'https://github.com/acme/shop/pull/752'}
        if body is not None:
            pr['body'] = body
        gh = gh or testkit.FakeGh(prs={752: testkit.merged_pr(merged_at=iso_at(5000))}, heads={'feat/demo': [pr]},
                                  permissions={'owner': 'admin'} if permissions is None else permissions)
        code, out, err = self.native(runner=gh, no_gh=False)
        return code, json.loads(out) if out else None, gh

    def test_numbers_of_the_criteria_table(self):
        code, record, gh = self.record_with(ACCEPTANCE_BODY)
        self.assertEqual(code, 0)
        self.assertEqual(record['acceptance'], {'total': 5, 'stack': 2, 'ci': 1, 'after_deploy': 1, 'unverified': 1})
        self.assertFalse(gaps_of(record, 'acceptance'), record['gaps'])
        self.assertTrue(any(c[:3] == GATE_CALL and 'collaborators/owner/permission' in c[3] for c in gh.calls),
                        gh.calls)
        listed = [c for c in gh.calls if c[:3] == ['gh', 'pr', 'list']]
        self.assertTrue(listed and '--head' in listed[0] and 'feat/demo' in listed[0], gh.calls)
        text = json.dumps(record, ensure_ascii=False)
        for secret in ('TAJNY-OPIS-PR', 'curl', 'OrderTest', 'brak stosu'):
            self.assertNotIn(secret, text)

    def test_unknown_way_counts_in_total_with_a_gap(self):
        body = ACCEPTANCE_BODY.replace('| 3 | po wdrożeniu |', '| 3 | ręcznie |')
        record = self.record_with(body)[1]
        self.assertEqual(record['acceptance'], {'total': 5, 'stack': 2, 'ci': 1, 'after_deploy': 0, 'unverified': 1})
        self.assertTrue(gaps_of(record, 'acceptance'), record['gaps'])

    def test_author_without_write_access_gives_null(self):
        code, record, _ = self.record_with(ACCEPTANCE_BODY, author='stranger', permissions={})
        self.assertEqual(code, 0)
        self.assertIsNone(record['acceptance'])
        self.assertTrue(any('bramk' in g for g in gaps_of(record, 'acceptance')), record['gaps'])

    def test_body_without_the_table_gives_null(self):
        for body in ('## Zakres\n\nbrak\n', '## Kryteria akceptacji\n\nbrak\n\n## Testy\n\n| Numer | Sposób | Wynik |\n'
                     '|---|---|---|\n| 1 | stos | PASS |\n', '', None):
            record = self.record_with(body)[1]
            self.assertIsNone(record['acceptance'], body)
            self.assertTrue(gaps_of(record, 'acceptance'), (body, record['gaps']))

    def test_pull_request_not_found_by_the_gate_gives_null(self):
        gh = testkit.FakeGh(prs={752: testkit.merged_pr(merged_at=iso_at(5000))}, heads={'feat/demo': []})
        record = self.record_with(ACCEPTANCE_BODY, gh=gh)[1]
        self.assertIsNone(record['acceptance'])
        self.assertTrue(gaps_of(record, 'acceptance'), record['gaps'])

    def test_failing_gate_gives_null_and_exit_3(self):
        gh = testkit.FakeGh(prs={752: testkit.merged_pr(merged_at=iso_at(5000))},
                            heads={'feat/demo': [{'number': 752, 'author': {'login': 'owner'}, 'url': 'u',
                                                  'body': ACCEPTANCE_BODY}]},
                            permissions={'owner': 'admin'}, fail_on=(('api', '--include'),))
        code, record, _ = self.record_with(ACCEPTANCE_BODY, gh=gh)
        self.assertEqual(code, 3)
        self.assertIsNone(record['acceptance'])
        self.assertTrue(gaps_of(record, 'acceptance'), record['gaps'])

    def test_without_gh_null_with_a_gap(self):
        self.write_run()
        self.write_session('s1', Native('s1').assistant(10, input=5))
        record = self.native_record()
        self.assertIsNone(record['acceptance'])
        self.assertTrue(gaps_of(record, 'acceptance'), record['gaps'])


START = {'status': 'ok', 'windows': [window(WEEKLY, 41, '2026-11-20T10:00:00Z'),
                                     window('Session', 80, '2026-11-18T12:00:00Z')]}
DONE = {'status': 'ok', 'windows': [window(WEEKLY, 47, '2026-11-20T10:00:00Z'),
                                    window('Session', 10, '2026-11-18T17:00:00Z')]}


class SubscriptionTest(ProcessEnv):
    """M3 `subscription` and criterion 13: the increase of every window between the readings of start and done, and
    the share of the tokens of the epic in the tokens of all local sessions of the interval."""

    def touch(self, at=10 ** 6):
        """Every transcript modified `at` seconds after T0: a transcript last modified before the start of the epic
        holds no message of its interval and is not read."""
        for directory, _, names in os.walk(self.projects):
            for name in names:
                os.utime(os.path.join(directory, name), (testkit.T0 + at, testkit.T0 + at))

    def test_without_readings_null_with_a_gap(self):
        self.write_run()
        self.write_session('s1', Native('s1').assistant(10, input=5))
        record = self.native_record()
        self.assertIsNone(record['subscription'])
        self.assertTrue(gaps_of(record, 'subscription'), record['gaps'])

    def test_renewed_window_is_null_with_a_gap_and_the_other_has_its_increase(self):
        self.write_run(usage_start=START, usage_done=DONE)
        self.write_session('s1', Native('s1').assistant(10, input=5))
        record = self.native_record()
        self.assertEqual(record['subscription']['windows'], {WEEKLY: 6, 'Session': None})
        self.assertTrue(any('Session' in g for g in gaps_of(record, 'subscription')), record['gaps'])
        self.assertFalse(any(WEEKLY in g for g in gaps_of(record, 'subscription')), record['gaps'])

    def test_unreadable_reading_has_no_windows_and_a_gap(self):
        self.write_run(usage_start={'status': 'unreadable'}, usage_done=DONE)
        self.write_session('s1', Native('s1').assistant(10, input=5))
        record = self.native_record()
        self.assertEqual(record['subscription']['windows'], {WEEKLY: None, 'Session': None})
        self.assertTrue(gaps_of(record, 'subscription'), record['gaps'])

    def test_share_counts_the_sessions_of_every_project_in_the_interval(self):
        self.write_run(started=0, done=4000, usage_start=START, usage_done=DONE)
        self.write_session('s1', Native('s1').assistant(10, input=300).assistant(4500, input=500))
        other = Native('o1').assistant(-100, input=99999).assistant(20, input=700).assistant(5000, input=99999)
        self.write_session('o1', other, worktree='/work/another-project')
        self.write_subagent('o1', 'ox', Native('o1', 'ox').assistant(30, model=SONNET, input=1000),
                            {'agentType': 'developer', 'description': 'X-1', 'model': 'sonnet', 'effort': 'high'},
                            worktree='/work/another-project')
        self.write_session('old', Native('old').assistant(20, input=4000), worktree='/work/old-project')
        self.touch()
        os.utime(os.path.join(testkit.project_dir(self.projects, '/work/old-project'), 'old.jsonl'),
                 (testkit.T0 - 10, testkit.T0 - 10))       # modified before the start: not read
        record = self.native_record()
        self.assertEqual(record['subscription']['share'], 0.15)          # 300 / (300 + 700 + 1000)
        self.assertIs(record['subscription']['share_estimated'], True)
        self.assertEqual(record['sessions'], ['s1'])                     # the other session is no part of the epic
        self.assertEqual(record['value']['total']['tokens']['input'], 800)

    def test_lines_of_another_session_that_are_no_objects_are_skipped(self):
        self.write_run(started=0, done=4000, usage_start=START, usage_done=DONE)
        self.write_session('s1', Native('s1').assistant(10, input=300))
        other = ['["assistant"]', '"assistant"', '{"type": "assistant", "message": "assistant"}', '{cut "assistant"',
                 json.dumps(Native('o1').assistant(20, input=700).lines[0])]
        self.write_session('o1', other, worktree='/work/other')
        self.touch()
        self.assertEqual(self.native_record()['subscription']['share'], 0.3)

    def test_share_weighs_the_kinds_of_tokens(self):
        self.write_run(started=0, done=4000, usage_start=START, usage_done=DONE)
        self.write_session('s1', Native('s1').assistant(10, output=100))              # 100 x 5
        self.write_session('o1', Native('o1').assistant(20, cache_read=5000), worktree='/work/other')   # 5000 x 0.1
        self.touch()
        self.assertEqual(self.native_record()['subscription']['share'], 0.5)


class UsageReadingTest(NativeEnv):
    """M1: `start` and `done` take the reading of limits as the `plan` object or as the whole `get_usage` object."""

    def run_file(self):
        with open(os.path.join(self.state, 'runs', 'demo.json'), encoding='utf-8') as f:
            return json.load(f)

    def test_whole_get_usage_object(self):
        whole = {'plan': START, 'context': {'percentUsed': 12, 'tokens': 120000}}
        code, out, err = run_cli(['start', 'demo', '--state', self.state, '--projects', self.projects, '--worktree',
                                  self.worktree, '--branch', 'feat/demo', '--pr', '752', '--tasks', '3', '--session',
                                  's1'], testkit.FakeGh(), stdin=json.dumps(whole))
        self.assertEqual(code, 0, err)
        self.assertEqual(self.run_file()['usage_start'], START)
        code, out, err = run_cli(['done', 'demo', '--state', self.state], stdin=json.dumps(dict(whole, plan=DONE)))
        self.assertEqual(code, 0, err)
        self.assertEqual(self.run_file()['usage_done'], DONE)

    def test_plan_that_is_not_an_object_is_unreadable(self):
        code, out, err = run_cli(['start', 'demo', '--state', self.state, '--projects', self.projects, '--worktree',
                                  self.worktree, '--branch', 'feat/demo', '--pr', '752', '--tasks', '3', '--session',
                                  's1'], testkit.FakeGh(), stdin=json.dumps({'plan': 'ok', 'context': {}}))
        self.assertEqual(code, 0, err)
        self.assertEqual(self.run_file()['usage_start'], {'status': 'unreadable'})


if __name__ == '__main__':
    unittest.main()

"""Tests of the orders of a resumed subagent in the native record: the boundaries of the orders, their time and the
sums of the run (docs/features/metrics/spec.md, "Epik natywny", "Zlecenia").
python3 -m unittest discover -s tools/metrics -p 'test_native_orders.py'"""
import copy
import json
import unittest

import testkit
from testkit import OPUS, Native, NativeEnv, iso_at

MILLION = 1000000
REVIEWER = {'agentType': 'code-reviewer', 'description': 'DEMO-1 code-reviewer FULL', 'model': 'opus',
            'effort': 'high', 'finished': True}
QA = {'agentType': 'qa-adversarial', 'description': 'DEMO-EPIC qa-adversarial FULL', 'model': 'opus', 'effort': 'high',
      'finished': True}


def meta(kind='developer', description='DEMO-2 developer'):
    return {'agentType': kind, 'description': description, 'model': 'opus', 'effort': 'high', 'finished': True}


def gaps_of(record, text):
    return [g for g in record['gaps'] if text in g]


def cell(usd, input=0, output=0):
    return {'usd': usd, 'estimated': True, 'tokens': {'input': input, 'output': output, 'cache_read': 0,
                                                      'cache_write': 0}, 'estimated_tokens': []}


class OrdersEnv(NativeEnv):
    def attempt(self, record, agent):
        found = [a for a in record['attempts'] if a['agent_id'] == agent]
        self.assertEqual(len(found), 1, record['attempts'])
        return found[0]

    def orders(self, record, agent):
        attempt = self.attempt(record, agent)
        self.assertIn('orders', attempt)
        return attempt['orders']

    def reproduction(self):
        """The reviewer of the diagnosis: four orders at 1000 + 0-554, 891-1057, 10556-11028 and 11930-12226 s; the
        resumes in the lead session name DEMO-1, DEMO-QA1 and DEMO-QA1."""
        self.write_run(started=0, done=20000, tasks=3)
        lead = (Native('s1').assistant(10, input=100000).launch(990, 'arv')
                .resume(1891, 'arv', 'DEMO-1 code-reviewer DELTA')
                .resume(11556, 'arv', 'DEMO-QA1 code-reviewer FULL')
                .resume(12930, 'arv', 'DEMO-QA1 code-reviewer DELTA'))
        self.write_session('s1', lead)
        reviewer = (Native('s1', 'arv')
                    .assistant(1000, model=OPUS, input=2 * MILLION)
                    .handback(1554, 'Werdykt: CHANGES_REQUIRED TAJNY-RAPORT', model=OPUS, output=10000)
                    .order(1891).assistant(1900, model=OPUS, input=MILLION)
                    .handback(2057, 'Werdykt: PASS TAJNY-RAPORT', model=OPUS, output=5000)
                    .order(11556, blocks=True).assistant(11600, model=OPUS, input=3 * MILLION)
                    .handback(12028, 'Werdykt: CHANGES_REQUIRED TAJNY-RAPORT', model=OPUS, output=20000)
                    .order(12930).assistant(13000, model=OPUS, input=MILLION)
                    .handback(13226, 'Werdykt: PASS TAJNY-RAPORT', model=OPUS, output=10000))
        self.write_subagent('s1', 'arv', reviewer, REVIEWER)
        return self.native_record()


class BoundariesTest(OrdersEnv):
    """The orders of a run: where one ends and the next starts, and their time."""

    def test_reviewer_resumed_for_another_task(self):
        record = self.reproduction()
        attempt = self.attempt(record, 'arv')
        orders = self.orders(record, 'arv')
        self.assertEqual([o['n'] for o in orders], [1, 2, 3, 4])
        self.assertEqual([o['boundary'] for o in orders], ['launch', 'resume', 'resume', 'resume'])
        self.assertEqual([o['wall_s'] for o in orders], [554, 166, 472, 296])
        self.assertEqual([o['started_at'] for o in orders], [iso_at(t) for t in (1000, 1891, 11556, 12930)])
        self.assertEqual([o['ended_at'] for o in orders], [iso_at(t) for t in (1554, 2057, 12028, 13226)])
        self.assertEqual([o['reported_at'] for o in orders], [iso_at(t) for t in (1554, 2057, 12028, 13226)])
        self.assertEqual(attempt['wall_s'], 1488)
        self.assertEqual(attempt['idle_s'], 10738)
        self.assertEqual((attempt['started_at'], attempt['ended_at']), (iso_at(1000), iso_at(13226)))
        self.assertFalse(gaps_of(record, 'zlecenie'), record['gaps'])
        self.assertFalse(gaps_of(record, 'coordinator'), record['gaps'])

    def one_order_epic(self):
        self.write_run(started=0, done=4000, tasks=3)
        self.write_session('s1', Native('s1').assistant(10, input=1000).launch(90, 'ad2').launch(490, 'aq1'))
        self.write_subagent('s1', 'ad2', Native('s1', 'ad2').assistant(100, model=OPUS, input=500000)
                            .handback(400, 'Gotowe TAJNY-RAPORT', model=OPUS, output=2000), meta())
        self.write_subagent('s1', 'aq1', Native('s1', 'aq1').assistant(500, model=OPUS, input=300000)
                            .handback(800, 'Werdykt: PASS', model=OPUS, output=1000),
                            meta('code-reviewer', 'DEMO-QA1 code-reviewer FULL'))
        return self.native_record()

    def test_one_order_gives_the_record_of_today(self):
        """The values of the fields below come from the record of the same epic built before the orders."""
        record = self.one_order_epic()
        today = {
            'ad2': {'agent_id': 'ad2', 'task': 'DEMO-2', 'role': 'developer', 'model': OPUS, 'effort': 'high',
                    'started_at': iso_at(100), 'ended_at': iso_at(400), 'wall_s': 300.0, 'value': 2.04,
                    'estimated': True, 'tokens': {'input': 500000, 'output': 2000, 'cache_read': 0, 'cache_write': 0},
                    'finished': True},
            'aq1': {'agent_id': 'aq1', 'task': None, 'role': 'code-reviewer', 'model': OPUS, 'effort': 'high',
                    'started_at': iso_at(500), 'ended_at': iso_at(800), 'wall_s': 300.0, 'value': 1.22,
                    'estimated': True, 'tokens': {'input': 300000, 'output': 1000, 'cache_read': 0, 'cache_write': 0},
                    'finished': True}}
        for attempt in record['attempts']:
            orders = self.orders(record, attempt['agent_id'])
            self.assertEqual(len(orders), 1)
            self.assertEqual(orders[0]['boundary'], 'launch')
            self.assertEqual(orders[0]['wall_s'], attempt['wall_s'])
            self.assertEqual(attempt['idle_s'], 0)
            rest = {k: v for k, v in attempt.items() if k not in ('orders', 'idle_s')}
            self.assertEqual(rest, today[attempt['agent_id']])
        for entry in record['by_task'].values():
            self.assertEqual(entry.pop('orders'), 1)
        self.assertEqual(record['by_task'], {'DEMO-2': {'kind': None, 'work': 300.0, 'attempts': 1},
                                             'no_task': {'kind': None, 'work': 300.0, 'attempts': 1}})
        fable, reviewer, developer = cell(0.008, 1000), cell(1.22, 300000, 1000), cell(2.04, 500000, 2000)
        self.assertEqual(record['value'], {
            'total': cell(3.268, 801000, 3000),
            'by_role': {'code-reviewer': reviewer, 'developer': developer, 'orchestrator': fable},
            'by_model': {testkit.FABLE: fable, OPUS: cell(3.26, 800000, 3000)},
            'by_task': {'DEMO-2': developer, 'no_task': cell(1.228, 301000, 1000)},
            'by_role_model': {'code-reviewer': {OPUS: reviewer}, 'developer': {OPUS: developer},
                              'orchestrator': {testkit.FABLE: fable}},
            'by_task_role': {'DEMO-2': {'developer': developer},
                             'no_task': {'code-reviewer': reviewer, 'orchestrator': fable}}})
        reviews = [{k: v for k, v in r.items() if k not in ('ref', 'assignment')} for r in record['reviews']]
        self.assertEqual(reviews, [{'task': None, 'role': 'code-reviewer', 'agent_id': 'aq1', 'at': iso_at(800),
                                    'mode': 'FULL', 'verdict': 'PASS', 'blocking': False}])
        self.assertIsNone(record['integration'])
        self.assertEqual(record['retries'], {'attempts': None, 'failed_by_outcome': {}, 'repair': None, 'fix': 0,
                                             'sync_prs': None})

    def test_message_while_working_does_not_split(self):
        self.write_run(started=0, done=4000)
        self.write_session('s1', Native('s1').assistant(10, input=5).resume(300, 'ad1', 'DEMO-1 dopisz test',
                                                                           resumed=False))
        self.write_subagent('s1', 'ad1', Native('s1', 'ad1').assistant(100, model=OPUS, input=5).queued(300)
                            .assistant(310, model=OPUS, input=5).handback(700, 'Gotowe', model=OPUS),
                            meta(description='DEMO-1 developer'))
        record = self.native_record()
        orders = self.orders(record, 'ad1')
        self.assertEqual([(o['boundary'], o['wall_s']) for o in orders], [('launch', 600)])
        self.assertEqual(self.attempt(record, 'ad1')['wall_s'], 600)

    def test_coordinator_line_without_report_or_resume_does_not_split(self):
        self.write_run(started=0, done=4000)
        # the lead sends a message to the working subagent: queued for delivery, no resume
        self.write_session('s1', Native('s1').assistant(10, input=5).resume(300, 'ad1', 'DEMO-1 uwaga',
                                                                           resumed=False))
        self.write_subagent('s1', 'ad1', Native('s1', 'ad1').assistant(100, model=OPUS, input=5).order(300)
                            .assistant(310, model=OPUS, input=5).handback(700, 'Gotowe', model=OPUS),
                            meta(description='DEMO-1 developer'))
        record = self.native_record()
        orders = self.orders(record, 'ad1')
        self.assertEqual([(o['n'], o['boundary'], o['wall_s']) for o in orders], [(1, 'launch', 600)])
        self.assertEqual(self.attempt(record, 'ad1')['idle_s'], 0)
        self.assertTrue([g for g in gaps_of(record, 'ad1') if 'coordinator' in g], record['gaps'])

    def boundary_epic(self, resumed, reported):
        self.write_run(started=0, done=4000)
        lead = Native('s1').assistant(10, input=5)
        if resumed:
            lead.resume(1000, 'ad1', 'DEMO-1 popraw')
        self.write_session('s1', lead)
        transcript = Native('s1', 'ad1').assistant(100, model=OPUS, input=5)
        if reported:
            transcript.handback(200, 'Gotowe', model=OPUS)
        else:
            transcript.assistant(200, model=OPUS, input=5)
        transcript.order(1010).assistant(1050, model=OPUS, input=5).handback(1100, 'Gotowe', model=OPUS)
        self.write_subagent('s1', 'ad1', transcript, meta(description='DEMO-1 developer'))
        return self.native_record()

    def test_every_row_of_the_boundary_table(self):
        for resumed, reported, boundary, gap in ((True, True, 'resume', None),
                                                 (True, False, 'resume_unreported', 'bez raportu'),
                                                 (False, True, 'resume_unconfirmed', 'bez potwierdzenia'),
                                                 (False, False, None, 'coordinator')):
            with self.subTest(resumed=resumed, reported=reported):
                self.setUp()
                record = self.boundary_epic(resumed, reported)
                orders = self.orders(record, 'ad1')
                found = [g for g in gaps_of(record, 'ad1') if 'zlecenie' in g or 'coordinator' in g]
                if boundary is None:
                    self.assertEqual([(o['boundary'], o['wall_s']) for o in orders], [('launch', 1000)])
                else:
                    self.assertEqual([(o['boundary'], o['wall_s']) for o in orders],
                                     [('launch', 100), (boundary, 90)])
                    self.assertEqual(self.attempt(record, 'ad1')['wall_s'], 190)
                    self.assertEqual(self.attempt(record, 'ad1')['idle_s'], 810)
                if gap is None:
                    self.assertFalse(found, record['gaps'])
                else:
                    self.assertTrue([g for g in found if gap in g], record['gaps'])

    def test_resume_without_report_is_marked(self):
        record = self.boundary_epic(resumed=True, reported=False)
        self.assertEqual([o['boundary'] for o in self.orders(record, 'ad1')], ['launch', 'resume_unreported'])
        self.assertEqual([o['reported_at'] for o in self.orders(record, 'ad1')], [None, iso_at(1100)])
        self.assertTrue(gaps_of(record, 'resume_unreported'), record['gaps'])

    def test_resume_further_than_60_s_does_not_confirm(self):
        for line, boundary in ((1040, 'resume'), (1039, 'resume_unconfirmed'), (1160, 'resume'),
                               (1161, 'resume_unconfirmed')):
            with self.subTest(line=line):
                self.setUp()
                self.write_run(started=0, done=4000)
                self.write_session('s1', Native('s1').assistant(10, input=5).resume(1100, 'ad1', 'DEMO-1 popraw'))
                self.write_subagent('s1', 'ad1', Native('s1', 'ad1').assistant(100, model=OPUS, input=5)
                                    .handback(200, 'Gotowe', model=OPUS).order(line)
                                    .assistant(1170, model=OPUS, input=5).handback(1200, 'Gotowe', model=OPUS),
                                    meta(description='DEMO-1 developer'))
                record = self.native_record()
                self.assertEqual([o['boundary'] for o in self.orders(record, 'ad1')], ['launch', boundary])

    def test_resume_of_another_subagent_does_not_confirm(self):
        self.write_run(started=0, done=4000)
        self.write_session('s1', Native('s1').assistant(10, input=5).resume(1000, 'aother', 'DEMO-1 popraw'))
        self.write_subagent('s1', 'ad1', Native('s1', 'ad1').assistant(100, model=OPUS, input=5)
                            .handback(200, 'Gotowe', model=OPUS).order(1000).assistant(1050, model=OPUS, input=5)
                            .handback(1100, 'Gotowe', model=OPUS), meta(description='DEMO-1 developer'))
        record = self.native_record()
        self.assertEqual([o['boundary'] for o in self.orders(record, 'ad1')], ['launch', 'resume_unconfirmed'])

    def test_repeated_lines_count_once(self):
        self.write_run(sessions=('s1', 's2'), started=0, done=4000)
        first = Native('s1').assistant(10, input=5).resume(1000, 'ad1', 'DEMO-1 popraw')
        resumed = Native('s2')
        resumed.lines = copy.deepcopy(first.lines)          # a resumed lead session repeats the earlier lines
        resumed.assistant(1500, input=5)
        self.write_session('s1', first)
        self.write_session('s2', resumed)
        transcript = (Native('s1', 'ad1').assistant(100, model=OPUS, message_id='m-dup', input=MILLION)
                      .handback(200, 'Gotowe', model=OPUS)
                      .order(1000).assistant(1010, model=OPUS, input=7)
                      .assistant(1020, model=OPUS, message_id='m-dup', input=MILLION)     # a repeated line
                      .order(1030)                                                       # no report, no own resume
                      .assistant(1040, model=OPUS, input=11))
        self.write_subagent('s1', 'ad1', transcript, meta(description='DEMO-1 developer'))
        record = self.native_record()
        orders = self.orders(record, 'ad1')
        self.assertEqual([(o['boundary'], o['wall_s']) for o in orders], [('launch', 100), ('resume', 40)])
        self.assertEqual(self.attempt(record, 'ad1')['tokens']['input'], MILLION + 18)

    def test_order_without_timestamps_is_incomplete(self):
        self.write_run(started=0, done=4000)
        self.write_session('s1', Native('s1').assistant(10, input=5).resume(1000, 'ad1', 'DEMO-1 popraw'))
        self.write_subagent('s1', 'ad1', Native('s1', 'ad1').assistant(None, model=OPUS, input=5)
                            .handback(None, 'Gotowe', model=OPUS).order(1000).assistant(1050, model=OPUS, input=5)
                            .handback(1100, 'Gotowe', model=OPUS), meta(description='DEMO-1 developer'))
        record = self.native_record()
        orders = self.orders(record, 'ad1')
        self.assertEqual([(o['boundary'], o['wall_s'], o['started_at'], o['ended_at']) for o in orders],
                         [('launch', None, None, None), ('resume', 100, iso_at(1000), iso_at(1100))])
        attempt = self.attempt(record, 'ad1')
        self.assertEqual(attempt['wall_s'], 100)
        self.assertIs(attempt['incomplete'], True)
        self.assertIsNone(attempt['idle_s'])
        entry = record['by_task']['DEMO-1']
        self.assertIs(entry['incomplete'], True)
        self.assertEqual(entry['orders'], 2)
        self.assertTrue([g for g in gaps_of(record, 'ad1') if 'bez czasu' in g], record['gaps'])

    def test_order_outside_the_epic_window(self):
        self.write_run(started=0, done=4000)
        self.write_session('s1', Native('s1').assistant(10, input=5).resume(4800, 'ad1', 'DEMO-1 popraw')
                           .resume(6000, 'ad1', 'DEMO-1 jeszcze raz'))
        transcript = (Native('s1', 'ad1').assistant(100, model=OPUS, input=5).handback(300, 'Gotowe', model=OPUS)
                      .order(4800).assistant(4900, model=OPUS, input=5).handback(5100, 'Gotowe', model=OPUS)
                      .order(6000).assistant(6100, model=OPUS, input=5).handback(6200, 'Gotowe', model=OPUS))
        self.write_subagent('s1', 'ad1', transcript, meta(description='DEMO-1 developer'))
        gh = testkit.FakeGh(prs={752: testkit.merged_pr(merged_at=iso_at(5000))})
        record = self.native_record(runner=gh, no_gh=False)
        orders = self.orders(record, 'ad1')
        self.assertEqual([(o['n'], o['boundary'], o['wall_s'], o['reported_at']) for o in orders],
                         [(1, 'launch', 200, iso_at(300)), (2, 'resume', 100, None)])
        attempt = self.attempt(record, 'ad1')
        self.assertEqual((attempt['wall_s'], attempt['idle_s']), (300, 4500))
        self.assertEqual(record['by_task']['DEMO-1']['orders'], 2)

    def test_resumed_integration_reviewer(self):
        self.write_run(started=0, done=8000)
        self.write_session('s1', Native('s1').assistant(10, input=5).resume(5300, 'aqa', 'DEMO-EPIC qa DELTA'))
        self.write_subagent('s1', 'aqa', Native('s1', 'aqa').assistant(100, model=OPUS, input=5)
                            .handback(400, 'Werdykt: BLOCK', model=OPUS).order(5300).assistant(5310, model=OPUS)
                            .handback(5400, 'Werdykt: PASS', model=OPUS), QA)
        record = self.native_record()
        self.assertEqual(self.attempt(record, 'aqa')['wall_s'], 400)
        self.assertEqual(record['integration'], {'work_s': 400, 'attempts': 1, 'rounds': 2})


if __name__ == '__main__':
    unittest.main()

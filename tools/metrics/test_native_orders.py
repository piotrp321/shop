"""Tests of the orders of a resumed subagent in the native record: the boundaries of the orders, their time and the
sums of the run (docs/features/metrics/spec.md, "Epik natywny", "Zlecenia").
python3 -m unittest discover -s tools/metrics -p 'test_native_orders.py'"""
import copy
import json
import unittest

import metrics
import testkit
from testkit import OPUS, Native, NativeEnv, iso_at, run_cli

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
        self.assertEqual([(o.get('assignment'), o.get('task'), o.get('ref'), o.get('task_source')) for o in orders],
                         [('plan', 'DEMO-1', 'DEMO-1', 'description'), ('plan', 'DEMO-1', 'DEMO-1', 'summary'),
                          ('outside_plan', None, 'DEMO-QA1', 'summary'),
                          ('outside_plan', None, 'DEMO-QA1', 'summary')])
        self.assertEqual(attempt['task'], 'DEMO-1')
        self.assertEqual(record['by_task'], {'DEMO-1': {'kind': None, 'work': 720, 'attempts': 1, 'orders': 2},
                                             'no_task': {'kind': None, 'work': 768, 'attempts': 1, 'orders': 2}})

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
        ids = {'ad2': ('plan', 'DEMO-2', 'DEMO-2'), 'aq1': ('outside_plan', None, 'DEMO-QA1')}
        for attempt in record['attempts']:
            orders = self.orders(record, attempt['agent_id'])
            self.assertEqual(len(orders), 1)
            self.assertEqual(orders[0]['boundary'], 'launch')
            self.assertEqual((orders[0].get('assignment'), orders[0].get('task'), orders[0].get('ref')),
                             ids[attempt['agent_id']])
            self.assertEqual((orders[0].get('tokens'), orders[0].get('value')), (attempt['tokens'], attempt['value']))
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
        self.assertEqual([(o.get('tokens') or {}).get('input') for o in orders], [MILLION, 18])
        self.assertEqual(record['value']['total']['tokens']['input'], MILLION + 18 + 5 + 5)

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


def assigned(orders):
    return [(o.get('assignment'), o.get('task'), o.get('ref')) for o in orders]


class AssignmentTest(OrdersEnv):
    """The identifier of an order (`ref`) and its assignment, and the tokens, the value and the reviews of the order."""

    def resumed(self, description, summary, kind='developer', reports=('Gotowe', 'Gotowe'), lead=True, build=True):
        """A run of two orders: 100-300 s (input 1M Opus tokens) and 1000-1200 s (input 3M), the second resumed by
        the lead with `summary` (lead False: no resume in the lead transcript); its record (build False: only the
        files)."""
        self.write_run(started=0, done=4000)
        session = Native('s1').assistant(10, input=1000)
        if lead:
            session.resume(1000, 'ad1', summary)
        self.write_session('s1', session)
        self.write_subagent('s1', 'ad1', Native('s1', 'ad1').assistant(100, model=OPUS, input=MILLION)
                            .handback(300, reports[0], model=OPUS).order(1000)
                            .assistant(1100, model=OPUS, input=3 * MILLION).handback(1200, reports[1], model=OPUS),
                            meta(kind, description))
        return self.native_record() if build else None

    def test_value_and_tokens_follow_the_order(self):
        record = self.reproduction()
        attempt = self.attempt(record, 'arv')
        orders = self.orders(record, 'arv')
        self.assertEqual([o.get('tokens') for o in orders],
                         [{'input': i, 'output': o, 'cache_read': 0, 'cache_write': 0}
                          for i, o in ((2 * MILLION, 10000), (MILLION, 5000), (3 * MILLION, 20000), (MILLION, 10000))])
        for order, usd in zip(orders, (8.2, 4.1, 12.4, 4.2)):
            self.assertAlmostEqual(order.get('value'), usd)
            self.assertIs(order.get('estimated'), True)
        for kind in ('input', 'output', 'cache_read', 'cache_write'):
            self.assertEqual(sum(o['tokens'][kind] for o in orders), attempt['tokens'][kind])
        self.assertAlmostEqual(sum(o['value'] for o in orders), attempt['value'])
        value = record['value']
        self.assertAlmostEqual(value['total']['usd'], 29.7)          # as without the orders: 0.8 lead + 28.9 reviewer
        self.assertAlmostEqual(value['by_task']['DEMO-1']['usd'], 12.3)
        self.assertAlmostEqual(value['by_task']['no_task']['usd'], 0.8 + 16.6)
        self.assertAlmostEqual(sum(c['usd'] for c in value['by_task'].values()), value['total']['usd'])
        self.assertEqual(value['by_task']['DEMO-1']['tokens']['input'], 3 * MILLION)
        self.assertEqual(set(value['by_task_role']['DEMO-1']), {'code-reviewer'})
        self.assertAlmostEqual(value['by_task_role']['DEMO-1']['code-reviewer']['usd'], 12.3)
        self.assertAlmostEqual(value['by_task_role']['no_task']['code-reviewer']['usd'], 16.6)
        self.assertAlmostEqual(value['by_role']['code-reviewer']['usd'], 28.9)
        self.assertAlmostEqual(value['by_model'][OPUS]['usd'], 28.9)

    def test_resume_to_another_task_without_id_is_unknown(self):
        record = self.resumed('DEMO-1 developer', 'popraw jeszcze test')
        orders = self.orders(record, 'ad1')
        self.assertEqual(assigned(orders), [('plan', 'DEMO-1', 'DEMO-1'), ('unknown', None, None)])
        self.assertEqual([o.get('task_source') for o in orders], ['description', None])
        self.assertEqual(record['by_task'], {'DEMO-1': {'kind': None, 'work': 200, 'attempts': 1, 'orders': 1},
                                             'unknown_task': {'kind': None, 'work': 200, 'attempts': 1, 'orders': 1}})
        value = record['value']
        self.assertAlmostEqual(value['by_task']['DEMO-1']['usd'], 4.0)
        self.assertAlmostEqual(value['by_task']['unknown_task']['usd'], 12.0)
        self.assertAlmostEqual(value['by_task']['no_task']['usd'], 0.008)        # the lead only
        self.assertAlmostEqual(value['total']['usd'], 16.008)
        self.assertEqual(set(value['by_task_role']['unknown_task']), {'developer'})
        self.assertTrue([g for g in gaps_of(record, 'ad1') if 'nieustalone' in g], record['gaps'])

    def test_resume_missing_in_the_lead_transcript_is_unknown(self):
        record = self.resumed('DEMO-1 developer', None, lead=False)
        orders = self.orders(record, 'ad1')
        self.assertEqual([o['boundary'] for o in orders], ['launch', 'resume_unconfirmed'])
        self.assertEqual(assigned(orders), [('plan', 'DEMO-1', 'DEMO-1'), ('unknown', None, None)])
        self.assertEqual(sorted(record['by_task']), ['DEMO-1', 'unknown_task'])
        self.assertTrue([g for g in gaps_of(record, 'ad1') if 'nieustalone' in g], record['gaps'])
        self.setUp()
        self.resumed('DEMO-1 developer', 'DEMO-1 popraw', build=False)
        self.write_session('s1', ['{cut', 'not json'])            # the lead transcript is unreadable
        record = self.native_record()
        orders = self.orders(record, 'ad1')
        self.assertEqual(assigned(orders), [('plan', 'DEMO-1', 'DEMO-1'), ('unknown', None, None)])
        self.assertTrue([g for g in gaps_of(record, 'ad1') if 'nieustalone' in g], record['gaps'])

    def test_first_order_of_a_task_outside_the_plan_and_its_next_round(self):
        record = self.resumed('DEMO-QA1 code-reviewer FULL', 'DEMO-QA1 code-reviewer DELTA', kind='code-reviewer',
                              reports=('Werdykt: CHANGES_REQUIRED', 'Werdykt: PASS'))
        orders = self.orders(record, 'ad1')
        self.assertEqual(assigned(orders), [('outside_plan', None, 'DEMO-QA1')] * 2)
        self.assertEqual([(r['task'], r.get('ref'), r.get('assignment'), r['mode'], r['verdict'])
                          for r in record['reviews']],
                         [(None, 'DEMO-QA1', 'outside_plan', 'FULL', 'CHANGES_REQUIRED'),
                          (None, 'DEMO-QA1', 'outside_plan', 'DELTA', 'PASS')])
        self.assertEqual(record['by_task'], {'no_task': {'kind': None, 'work': 400, 'attempts': 1, 'orders': 2}})
        self.assertNotIn('DEMO-QA1', record['value']['by_task'])
        self.assertNotIn('DEMO-QA1', record['value']['by_task_role'])
        self.assertEqual(record['retries']['fix'], 0)                # no review of a plan task
        self.assertIsNone(self.attempt(record, 'ad1')['task'])

    def test_ref_is_the_first_identifier_not_the_first_number(self):
        record = self.resumed('DEMO-QA1 code-reviewer FULL po DEMO-1', 'DEMO-QA1 DELTA', kind='code-reviewer',
                              reports=('Werdykt: PASS', 'Werdykt: PASS'))
        orders = self.orders(record, 'ad1')
        self.assertEqual(assigned(orders)[0], ('outside_plan', None, 'DEMO-QA1'))
        self.assertIsNone(self.attempt(record, 'ad1')['task'])
        self.assertNotIn('DEMO-1', record['by_task'])
        self.assertNotIn('DEMO-1', record['value']['by_task'])
        self.assertEqual([r['task'] for r in record['reviews']], [None, None])

    def test_plan_task_is_read_the_same_at_launch_and_resume(self):
        for description, summary in (('DEMO-2 developer', 'DEMO-2 popraw'), ('demo-2 developer', 'Demo-2: popraw')):
            with self.subTest(description=description, summary=summary):
                self.setUp()
                record = self.resumed(description, summary)
                orders = self.orders(record, 'ad1')
                self.assertEqual(assigned(orders), [('plan', 'DEMO-2', 'DEMO-2')] * 2)
                self.assertEqual([o['task_source'] for o in orders], ['description', 'summary'])
                self.assertEqual(record['by_task'], {'DEMO-2': {'kind': None, 'work': 400, 'attempts': 1,
                                                                'orders': 2}})

    def test_summary_with_the_epic_marker_is_no_task(self):
        for summary in ('DEMO-EPIC qa-adversarial DELTA', 'demo-epic qa-adversarial DELTA'):
            with self.subTest(summary=summary):
                self.setUp()
                record = self.resumed('DEMO-EPIC qa-adversarial FULL', summary, kind='qa-adversarial',
                                      reports=('Werdykt: BLOCK', 'Werdykt: PASS'))
                orders = self.orders(record, 'ad1')
                self.assertEqual(assigned(orders), [('no_task', None, None)] * 2)
                self.assertEqual([o['task_source'] for o in orders], ['description', 'summary'])
                self.assertEqual([(r.get('assignment'), r['mode']) for r in record['reviews']],
                                 [('no_task', 'FULL'), ('no_task', 'DELTA')])
                self.assertEqual(record['by_task'], {'no_task': {'kind': None, 'work': 400, 'attempts': 1,
                                                                 'orders': 2}})
                self.assertFalse([g for g in gaps_of(record, 'ad1') if 'nieustalone' in g], record['gaps'])

    def test_summary_with_only_the_epic_name_is_unknown(self):
        for summary in ('DEMO: popraw uwagi', 'DEMO qa-adversarial DELTA', None):
            with self.subTest(summary=summary):
                self.setUp()
                record = self.resumed('DEMO-EPIC qa-adversarial FULL', summary, kind='qa-adversarial',
                                      reports=('Werdykt: BLOCK', 'Werdykt: PASS'))
                orders = self.orders(record, 'ad1')
                self.assertEqual(assigned(orders), [('no_task', None, None), ('unknown', None, None)])
                self.assertEqual(record['by_task'], {
                    'no_task': {'kind': None, 'work': 200, 'attempts': 1, 'orders': 1},
                    'unknown_task': {'kind': None, 'work': 200, 'attempts': 1, 'orders': 1}})
                self.assertAlmostEqual(record['value']['by_task']['unknown_task']['usd'], 12.0)
                self.assertTrue([g for g in gaps_of(record, 'ad1') if 'nieustalone' in g], record['gaps'])

    def test_launch_without_id_stays_no_task(self):
        record = self.resumed('Przegląd UX całej zmiany', 'DEMO-EPIC ux DELTA', kind='ux-reviewer')
        orders = self.orders(record, 'ad1')
        self.assertEqual(assigned(orders), [('no_task', None, None)] * 2)
        self.assertEqual([o['task_source'] for o in orders], ['description', 'summary'])
        self.assertEqual(record['by_task'], {'no_task': {'kind': None, 'work': 400, 'attempts': 1, 'orders': 2}})
        self.assertFalse([g for g in gaps_of(record, 'ad1') if 'nieustalone' in g], record['gaps'])

    def test_reviews_take_the_assignment_of_their_order(self):
        record = self.reproduction()
        self.assertEqual([(r['task'], r.get('ref'), r.get('assignment'), r['mode'], r['verdict'])
                          for r in record['reviews']],
                         [('DEMO-1', 'DEMO-1', 'plan', 'FULL', 'CHANGES_REQUIRED'),
                          ('DEMO-1', 'DEMO-1', 'plan', 'DELTA', 'PASS'),
                          (None, 'DEMO-QA1', 'outside_plan', 'FULL', 'CHANGES_REQUIRED'),
                          (None, 'DEMO-QA1', 'outside_plan', 'DELTA', 'PASS')])
        self.assertEqual(record['retries']['fix'], 1)            # the blocking review of QA1 is no round of DEMO-1
        self.assertFalse([g for g in record['gaps'] if g.startswith('retries.fix')], record['gaps'])
        self.setUp()
        record = self.resumed('DEMO-1 code-reviewer FULL', 'jeszcze raz', kind='code-reviewer',
                              reports=('Werdykt: CHANGES_REQUIRED', 'Werdykt: CHANGES_REQUIRED'))
        self.assertEqual([(r['task'], r.get('ref'), r.get('assignment'), r['mode']) for r in record['reviews']],
                         [('DEMO-1', 'DEMO-1', 'plan', 'FULL'), (None, None, 'unknown', None)])
        self.assertTrue([g for g in gaps_of(record, 'ad1') if g.startswith('reviews') and 'tryb' in g],
                        record['gaps'])
        self.assertEqual(record['retries']['fix'], 1)
        self.assertTrue([g for g in record['gaps'] if g.startswith('retries.fix') and 'dolna granica' in g],
                        record['gaps'])

    def test_record_holds_no_text_of_summaries_or_orders(self):
        self.write_run(started=0, done=20000, tasks=3)
        self.write_session('s1', Native('s1').assistant(10, input=5)
                           .resume(1000, 'ad1', 'DEMO-QA1 TAJNE-STRESZCZENIE-A')
                           .resume(2000, 'ad1', 'TAJNE-STRESZCZENIE-B bez identyfikatora'))
        self.write_subagent('s1', 'ad1', Native('s1', 'ad1').assistant(100, model=OPUS, input=5)
                            .handback(300, 'Werdykt: PASS TAJNY-RAPORT', model=OPUS)
                            .order(1000, 'TAJNE-ZLECENIE-A').assistant(1100, model=OPUS, input=5)
                            .handback(1200, 'Werdykt: PASS TAJNY-RAPORT', model=OPUS)
                            .order(2000, 'TAJNE-ZLECENIE-B', blocks=True).assistant(2100, model=OPUS, input=5)
                            .handback(2200, 'Werdykt: PASS TAJNY-RAPORT', model=OPUS),
                            meta('code-reviewer', 'DEMO-1 TAJNY-OPIS przeglądu'))
        record = self.native_record()
        self.assertEqual(assigned(self.orders(record, 'ad1')),
                         [('plan', 'DEMO-1', 'DEMO-1'), ('outside_plan', None, 'DEMO-QA1'), ('unknown', None, None)])
        self.assertIn('unknown_task', record['by_task'])
        text = json.dumps(record, ensure_ascii=False)
        for secret in ('TAJN', 'STRESZCZENIE', 'bez identyfikatora', 'Resuming', 'queued'):
            self.assertNotIn(secret, text)


def as_before(record):
    """The record of `CompatibilityTest.mixed` in the shape of the parser before the orders: no `orders`, `idle_s`,
    `by_task.orders`, `ref` or `assignment`; every order under the task of its run (no `unknown_task`), the time of a
    run its span, the mode of a review by its place in the run."""
    before = copy.deepcopy(record)
    tasks, by_task = {}, {}
    for attempt in before['attempts']:
        for field in ('orders', 'idle_s', 'incomplete'):
            attempt.pop(field, None)
        started, ended = (metrics.night_queue.parse_time(attempt[f]).timestamp() for f in ('started_at', 'ended_at'))
        attempt['wall_s'] = round(ended - started, 3)
        tasks[attempt['agent_id']] = attempt['task']
        entry = by_task.setdefault(attempt['task'] or 'no_task', {'kind': None, 'work': 0.0, 'attempts': 0})
        entry['work'] = round(entry['work'] + attempt['wall_s'], 3)
        entry['attempts'] += 1
    before['by_task'] = by_task
    by_role = before['value']['by_role']                # the scenario: the lead and one reviewer of DEMO-1
    before['value']['by_task'] = {'DEMO-1': by_role['code-reviewer'], 'no_task': by_role['orchestrator']}
    before['value']['by_task_role'] = {'DEMO-1': {'code-reviewer': by_role['code-reviewer']},
                                       'no_task': {'orchestrator': by_role['orchestrator']}}
    seen = set()
    for review in before['reviews']:
        review.pop('ref', None)
        review.pop('assignment', None)
        review['task'] = tasks[review['agent_id']]
        review['mode'] = 'DELTA' if review['agent_id'] in seen else 'FULL'
        seen.add(review['agent_id'])
    before['retries']['fix'] = sum(1 for r in before['reviews'] if r['task'] and r['blocking'] is True)
    before['gaps'] = [g for g in before['gaps'] if 'zleceni' not in g and 'tryb null' not in g]
    return before


class CompatibilityTest(OrdersEnv):
    """`trend`, `decide`, `effect` and the bottlenecks do not take `unknown_task`, `outside_plan` or the orders for
    samples of plan tasks, and a record without the orders (built before them) still works everywhere."""

    AT = '2026-12-01T00:00:00Z'
    COMMANDS = (['trend', '--json', '--no-gh'], ['decide', 'demo'], ['effect', 'D1:code-reviewer', '--at', AT],
                ['effect', 'D2:plan_split', '--at', AT], ['effect', 'D3:gate_wait', '--at', AT],
                ['effect', 'D4:review', '--at', AT], ['effect', 'D4:human_wait', '--at', AT])

    def mixed(self):
        """The reproduction with a third reviewer order resumed without an id: plan, outside_plan and unknown."""
        self.write_run(started=0, done=20000, tasks=3)
        lead = (Native('s1').assistant(10, input=100000).resume(1891, 'arv', 'DEMO-1 code-reviewer DELTA')
                .resume(11556, 'arv', 'DEMO-QA1 code-reviewer FULL').resume(12930, 'arv', 'popraw jeszcze'))
        self.write_session('s1', lead)
        self.write_subagent('s1', 'arv', Native('s1', 'arv').assistant(1000, model=OPUS, input=2 * MILLION)
                            .handback(1554, 'Werdykt: CHANGES_REQUIRED', model=OPUS, output=10000)
                            .order(1891).assistant(1900, model=OPUS, input=MILLION)
                            .handback(2057, 'Werdykt: PASS', model=OPUS, output=5000)
                            .order(11556).assistant(11600, model=OPUS, input=3 * MILLION)
                            .handback(12028, 'Werdykt: CHANGES_REQUIRED', model=OPUS, output=20000)
                            .order(12930).assistant(13000, model=OPUS, input=MILLION)
                            .handback(13226, 'Werdykt: CHANGES_REQUIRED', model=OPUS, output=10000), REVIEWER)
        record = self.native_record()
        self.assertIn('unknown_task', record['by_task'])
        self.assertEqual([o['assignment'] for o in record['attempts'][0]['orders']],
                         ['plan', 'plan', 'outside_plan', 'unknown'])
        record['merged_at'] = '2027-01-16T10:00:00Z'
        return record

    def history(self):
        return [testkit.fake_record('h%d' % n, merged='2026-11-%02dT10:00:00Z' % (10 + n), source='native',
                                    started_at='2026-11-%02dT10:00:00Z' % (9 + n), work=(10 + n) * 3600.0,
                                    human_wait=n * 1800.0, review=n * 900.0, value=10.0 + n)
                for n in range(1, 5)]

    def outputs(self, record):
        testkit.store_records(self.state, record, *self.history())
        found = []
        for command in self.COMMANDS:
            code, out, err = run_cli(command + ['--state', self.state, '--night', self.night])
            self.assertEqual(code, 0, (command, err))
            found.append(json.loads(out))
        config = metrics.load_config(metrics.DEFAULT_CONFIG)
        records = metrics.read_records(self.state)
        found.append(metrics.bottlenecks_of(records['demo'], metrics.history_of(records['demo'], records), config,
                                            metrics.Gaps()))
        return found

    def test_unknown_task_is_no_sample_of_plan_tasks(self):
        record = self.mixed()
        before = as_before(record)
        self.assertNotIn('unknown_task', json.dumps(before))
        self.assertEqual(self.outputs(record), self.outputs(before))

    def test_record_without_orders_still_renders(self):
        before = as_before(self.mixed())
        self.outputs(before)
        for kind in ('report', 'comment'):
            code, out, err = run_cli(['render', 'demo', '--format', kind, '--state', self.state, '--night',
                                      self.night])
            self.assertEqual(code, 0, err)
            self.assertIn('Integracja', out) if kind == 'report' else self.assertIn('Pomiar epiku', out)
        testkit.store_records(self.state, self.mixed())
        code, out, err = run_cli(['render', 'demo', '--state', self.state, '--night', self.night])
        self.assertEqual(code, 0, err)
        self.assertIn('najdroższe zadanie', out)


class OrderRefTest(unittest.TestCase):
    """Step 1 of the assignment: the identifier `<EPIC>-<id>` (letters and digits with a digit) or the marker
    `<EPIC>-EPIC` that comes first in the text; any other word after the epic name and the name alone mean nothing."""

    def test_identifier_and_marker(self):
        for text, epic, found in (
                ('DEMO-2 developer', 'demo', ('plan', 'DEMO-2')),
                ('demo-2', 'demo', ('plan', 'DEMO-2')),
                ('Przegląd DEMO-12, potem DEMO-3', 'demo', ('plan', 'DEMO-12')),
                ('DEMO-QA1 code-reviewer FULL po DEMO-1', 'demo', ('outside_plan', 'DEMO-QA1')),
                ('demo-cx2', 'demo', ('outside_plan', 'DEMO-CX2')),
                ('DEMO-1a', 'demo', ('outside_plan', 'DEMO-1A')),
                ('DEMO-FULL przegląd DEMO-2', 'demo', ('plan', 'DEMO-2')),
                ('DEMO-EPIC qa-adversarial DELTA', 'demo', ('epic', None)),
                ('qa demo-epic po DEMO-3', 'demo', ('epic', None)),
                ('DEMO-3 po DEMO-EPIC', 'demo', ('plan', 'DEMO-3')),
                ('STORE-UX-FIXES-QA1 code-reviewer', 'store-ux-fixes', ('outside_plan', 'STORE-UX-FIXES-QA1')),
                ('STORE-UX-FIXES-2', 'store-ux-fixes', ('plan', 'STORE-UX-FIXES-2')),
                ('DEMO: popraw uwagi', 'demo', (None, None)),
                ('DEMO qa-adversarial DELTA', 'demo', (None, None)),
                ('DEMO-EPICS DEMO-FIX', 'demo', (None, None)),
                ('XDEMO-1 i DEMOX-2', 'demo', (None, None)),
                ('OTHER-1', 'demo', (None, None)),
                ('', 'demo', (None, None)),
                (None, 'demo', (None, None))):
            self.assertEqual(metrics.order_ref(text, epic), found, text)


if __name__ == '__main__':
    unittest.main()

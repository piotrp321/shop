"""Tests of the bottlenecks of the record (spec: Wąskie gardła): every kind of candidate, the order, the limits, the
time cost, the decision rule key and the history."""
import copy
import json
import os
import unittest

import metrics
import testkit
from testkit import Env, FakeGh, fake_record, merged_pr, run_cli, store_records

H = 3600.0


def config():
    return metrics.load_config(metrics.DEFAULT_CONFIG)


def history_epics(count=5):
    """`count` earlier epics of 10 plan tasks: each task 0,5 h of work, 1 h of human_wait, 1 h of e2e in attempts, no
    gate wait, 0,5 h of review."""
    found = []
    for i in range(count):
        record = fake_record('h%d' % i, merged='2026-10-%02dT10:00:00Z' % (i + 1), tasks=10, to_done=10 * H,
                             work=5 * H, human_wait=1 * H, review=0.5 * H, gate_wait=0.0)
        record['by_task'] = {'P-%d' % n: {'kind': 'plan', 'work': 0.5 * H, 'attempts': 1} for n in range(10)}
        record['by_task']['R-1'] = {'kind': 'repair', 'work': 0.5 * H, 'attempts': 1}
        record['in_attempts']['categories']['e2e']['seconds'] = 1 * H
        record['in_attempts']['categories']['ci_watch']['seconds'] = 1 * H
        found.append(record)
    return found


def target(**kwargs):
    params = dict(merged='2026-11-10T10:00:00Z', tasks=10, to_done=20 * H, work=5 * H, human_wait=1 * H,
                  review=0.5 * H, gate_wait=0.0)
    params.update(kwargs)
    record = fake_record('target', **params)
    record['by_task'] = {'P-%d' % n: {'kind': 'plan', 'work': 0.5 * H, 'attempts': 1} for n in range(10)}
    record['in_attempts']['categories']['e2e']['seconds'] = 1 * H
    record['in_attempts']['categories']['ci_watch']['seconds'] = 1 * H
    return record


def compute(record, history=None, **kwargs):
    gaps = metrics.Gaps()
    items = metrics.bottlenecks_of(record, history_epics() if history is None else history, config(), gaps, **kwargs)
    return items, gaps.render()


def named(items, name):
    return next(i for i in items if i['name'] == name)


class CategoryTest(unittest.TestCase):
    def test_a_breakdown_category_over_the_median_is_a_bottleneck_with_its_time_cost(self):
        record = target()
        record['breakdown']['human_wait'] = 4 * H            # the median is 1 h: +3 h
        items, gaps = compute(record)
        item = named(items, 'human_wait')
        self.assertEqual(item['kind'], 'breakdown')
        self.assertEqual(item['value_s'], 4 * H)
        self.assertEqual(item['median_s'], 1 * H)
        self.assertEqual(item['excess_h'], 3.0)
        self.assertEqual(item['share'], 0.15)                 # 3 h of 20 h
        self.assertEqual(item['share_of'], 'to_done')
        self.assertEqual(item['rule'], 'D4')

    def test_median_is_per_plan_task_so_a_bigger_epic_is_compared_fairly(self):
        record = target()
        record['tasks'] = 20
        record['breakdown']['human_wait'] = 2.5 * H           # 20 tasks x 0,1 h median = 2 h: +0,5 h
        item = named(compute(record)[0], 'human_wait')
        self.assertEqual((item['median_s'], item['excess_h']), (2 * H, 0.5))

    def test_work_is_never_a_category_candidate(self):
        record = target()
        record['breakdown']['work'] = 50 * H
        self.assertNotIn('work', [i['name'] for i in compute(record)[0] if i['kind'] == 'breakdown'])

    def test_rule_keys_of_the_categories(self):
        record = target()
        for name in ('human_wait', 'limit_wait', 'ci_wait', 'host_pause', 'github_wait', 'idle'):
            record['breakdown'][name] = 4 * H
        # every category has the same excess against a median of 0 or 1 h; the limit of 3 items is lifted here
        items = metrics.bottlenecks_of(record, history_epics(), dict(config(), bottlenecks={
            'min_excess_h': 0.5, 'max_items': 10, 'min_history': 5}), metrics.Gaps())
        rules = {i['name']: i['rule'] for i in items if i['kind'] == 'breakdown'}
        self.assertEqual(rules, {'human_wait': 'D4', 'limit_wait': 'D4', 'ci_wait': 'D4', 'host_pause': None,
                                 'github_wait': None, 'idle': None})

    def test_a_null_category_is_skipped(self):
        record = target()
        record['breakdown'] = None
        items, gaps = compute(record)
        self.assertEqual([i for i in items if i['kind'] == 'breakdown'], [])


class TaskTest(unittest.TestCase):
    def test_a_task_over_the_median_of_its_kind_is_a_bottleneck_with_rule_d2_for_plan_tasks(self):
        record = target()
        record['by_task']['P-3']['work'] = 3 * H             # the median work of a plan task is 0,5 h: +2,5 h
        item = named(compute(record)[0], 'P-3')
        self.assertEqual((item['kind'], item['value_s'], item['median_s'], item['excess_h']), ('task', 3 * H, 0.5 * H, 2.5))
        self.assertEqual(item['rule'], 'D2')
        self.assertEqual(item['share'], 0.125)

    def test_other_task_kinds_have_no_rule_and_use_the_median_of_their_kind(self):
        record = target()
        record['by_task']['R-9'] = {'kind': 'repair', 'work': 2 * H, 'attempts': 1}      # repair median: 0,5 h
        item = named(compute(record)[0], 'R-9')
        self.assertEqual((item['excess_h'], item['rule']), (1.5, None))

    def test_a_kind_without_history_has_no_median_and_is_not_a_candidate(self):
        record = target()
        record['by_task']['F-1'] = {'kind': 'fix', 'work': 9 * H, 'attempts': 1}
        self.assertNotIn('F-1', [i['name'] for i in compute(record)[0]])


class InAttemptsTest(unittest.TestCase):
    def test_a_command_category_over_the_median_shows_how_much_of_it_is_repeat_runs(self):
        record = target()
        record['in_attempts']['categories']['e2e']['seconds'] = 4 * H            # median 1 h: +3 h
        record['in_attempts']['repeat_runs']['by_category']['e2e'] = {'count': 3, 'seconds': 1.5 * H}
        item = named(compute(record)[0], 'in_attempts.e2e')
        self.assertEqual((item['kind'], item['excess_h'], item['repeat_h'], item['rule']), ('in_attempts', 3.0, 1.5, None))

    def test_repeat_part_never_exceeds_the_excess(self):
        record = target()
        record['in_attempts']['categories']['e2e']['seconds'] = 2 * H            # +1 h
        record['in_attempts']['repeat_runs']['by_category']['e2e'] = {'count': 9, 'seconds': 5 * H}
        self.assertEqual(named(compute(record)[0], 'in_attempts.e2e')['repeat_h'], 1.0)

    def test_a_category_without_repeat_runs_has_no_repeat_part(self):
        record = target()
        record['in_attempts']['categories']['ci_watch']['seconds'] = 4 * H
        item = named(compute(record)[0], 'in_attempts.ci_watch')
        self.assertIsNone(item['repeat_h'])
        self.assertEqual(item['rule'], 'D4')

    def test_repeat_runs_are_no_separate_item(self):
        record = target()
        record['in_attempts']['repeat_runs']['seconds'] = 30 * H
        self.assertFalse([i for i in compute(record)[0] if 'repeat' in i['name']])

    def test_no_transcripts_is_no_candidate(self):
        record = target()
        record['in_attempts'] = None
        self.assertEqual([i for i in compute(record)[0] if i['kind'] == 'in_attempts'], [])


class GateWaitTest(unittest.TestCase):
    def test_gate_wait_over_the_median_uses_the_share_of_gate_wait_plus_to_done_and_rule_d3(self):
        record = target(gate_wait=4 * H)
        item = named(compute(record)[0], 'gate_wait')
        self.assertEqual((item['kind'], item['excess_h'], item['rule']), ('gate_wait', 4.0, 'D3'))
        self.assertEqual(item['share'], round(4 / 24, 4))
        self.assertEqual(item['share_of'], 'gate_wait+to_done')

    def test_passing_the_gate_without_waiting_is_zero_and_no_bottleneck(self):
        items, gaps = compute(target(gate_wait=0.0))
        self.assertEqual([i for i in items if i['kind'] == 'gate_wait'], [])
        self.assertFalse([g for g in gaps if 'gate_wait' in g])

    def test_no_gate_entries_skip_the_candidate_with_a_gap(self):
        items, gaps = compute(target(gate_wait=None))
        self.assertEqual([i for i in items if i['kind'] == 'gate_wait'], [])
        self.assertTrue(any(g.startswith('bottlenecks.gate_wait') for g in gaps), gaps)


class ReviewTest(unittest.TestCase):
    def test_review_over_the_median_uses_the_share_of_to_merge_and_rule_d4(self):
        record = target(review=6 * H)                          # median 0,5 h: +5,5 h; to_merge 26 h
        item = named(compute(record)[0], 'review')
        self.assertEqual((item['kind'], item['excess_h'], item['rule']), ('review', 5.5, 'D4'))
        self.assertEqual(item['share'], round(5.5 / 26, 4))
        self.assertEqual(item['share_of'], 'to_merge')

    def test_review_is_left_out_on_request_and_before_the_merge(self):
        self.assertNotIn('review', [i['name'] for i in compute(target(review=6 * H), include_review=False)[0]])
        record = target(review=None)
        record['wall']['to_merge'] = None
        self.assertNotIn('review', [i['name'] for i in compute(record)[0]])


class LimitsAndOrderTest(unittest.TestCase):
    def many(self):
        record = target(gate_wait=2 * H, review=3 * H)
        record['breakdown'].update(human_wait=5 * H, limit_wait=3 * H, ci_wait=2 * H)         # +4, +3, +2 h
        record['by_task']['P-1']['work'] = 2 * H                                              # +1,5 h
        return record

    def test_at_most_three_in_descending_order_of_excess(self):
        items, _ = compute(self.many())
        self.assertEqual([(i['name'], i['excess_h']) for i in items],
                         [('human_wait', 4.0), ('limit_wait', 3.0), ('review', 2.5)])

    def test_the_limit_comes_from_the_config(self):
        items = metrics.bottlenecks_of(self.many(), history_epics(), dict(config(), bottlenecks={
            'min_excess_h': 0.5, 'max_items': 2, 'min_history': 5}), metrics.Gaps())
        self.assertEqual(len(items), 2)

    def test_an_excess_below_half_an_hour_is_left_out_and_exactly_half_an_hour_is_in(self):
        record = target()
        record['breakdown']['human_wait'] = 1 * H + 0.5 * H - 1          # +0,4997 h
        record['breakdown']['limit_wait'] = 0.5 * H                       # median 0: +0,5 h
        names = [i['name'] for i in compute(record)[0]]
        self.assertNotIn('human_wait', names)
        self.assertIn('limit_wait', names)

    def test_nothing_over_the_median_gives_no_items(self):
        self.assertEqual(compute(target())[0], [])


class HistoryTest(unittest.TestCase):
    def test_fewer_than_five_epics_use_the_available_ones_and_record_a_gap(self):
        record = target()
        record['breakdown']['human_wait'] = 4 * H
        items, gaps = compute(record, history_epics(2))
        self.assertEqual(named(items, 'human_wait')['excess_h'], 3.0)
        self.assertTrue(any(g.startswith('bottlenecks') and '2' in g for g in gaps), gaps)

    def test_five_epics_have_no_history_gap(self):
        self.assertFalse([g for g in compute(target())[1] if g.startswith('bottlenecks')])

    def test_no_history_gives_no_items_and_a_gap(self):
        record = target()
        record['breakdown']['human_wait'] = 40 * H
        items, gaps = compute(record, [])
        self.assertEqual(items, [])
        self.assertTrue(any(g.startswith('bottlenecks') for g in gaps), gaps)

    def test_null_values_of_the_history_are_skipped_in_the_median(self):
        history = history_epics()
        history[0]['breakdown'] = None
        history[1]['tasks'] = 0
        record = target()
        record['breakdown']['human_wait'] = 4 * H
        self.assertEqual(named(compute(record, history)[0], 'human_wait')['median_s'], 1 * H)


class RecordTest(Env):
    """The `epic` command stores `bottlenecks`, computed against the stored records merged before it."""

    def test_epic_record_has_bottlenecks_from_the_stored_history(self):
        history = history_epics()
        for record in history:
            record['merged_at'] = '2026-10-%02dT10:00:00Z' % (history.index(record) + 1)
        late = fake_record('later', merged='2026-12-01T10:00:00Z', tasks=1, to_done=1 * H, human_wait=0.0)
        store_records(self.state, *history, late)
        home = testkit.fixture_home(self.root, 'deploy-ops')
        gh = FakeGh(prs={476: merged_pr('2026-11-13T10:00:00Z')})
        code, out, err = self.epic('deploy-ops', home=home, runner=gh, no_gh=False)
        self.assertEqual(code, 0, err)
        items = json.loads(out)['bottlenecks']
        self.assertIsInstance(items, list)
        self.assertLessEqual(len(items), 3)
        self.assertEqual(items, sorted(items, key=lambda i: -i['excess_h']))
        # `later` was merged after deploy-ops: it is not in the history (a median of 0 h would show human_wait)
        self.assertTrue(all(i['median_s'] is not None for i in items))

    def test_epic_without_history_has_an_empty_list_and_a_gap(self):
        home = testkit.fixture_home(self.root, 'deploy-ops')
        record = self.record('deploy-ops', home=home)
        self.assertEqual(record['bottlenecks'], [])
        self.assertTrue(any(g.startswith('bottlenecks') for g in record['gaps']), record['gaps'])


if __name__ == '__main__':
    unittest.main()

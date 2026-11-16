"""Tests of `subscription.share` (spec: Rekord epiku: subscription): the token value share of the epic in its
measurement interval when other epics ran in it, 1 without overlap, with the raw increase of the windows next to it."""
import json
import os
import shutil
import unittest
from datetime import datetime, timedelta, timezone

import metrics
import testkit
from testkit import OPUS, Env, Transcript, model_usage

BASE = datetime(2026, 11, 20, 8, 0, tzinfo=timezone.utc)
RESET = '2026-11-25T18:00:00Z'
ALL = 'Weekly · all models'


def at(seconds):
    return (BASE + timedelta(seconds=seconds)).isoformat()


def epoch(seconds):
    return (BASE + timedelta(seconds=seconds)).timestamp()


def event(epic, seconds, kind, percent):
    body = {'at': at(seconds), 'epic': epic, 'event': kind,
            'usage': {'status': 'ok', 'windows': [{'label': ALL, 'percentUsed': percent, 'resetsAt': RESET}]}}
    if kind == 'start':
        body['tasks'] = 3
    return body


def attempt_of(start, end, value):
    return {'task': 'X-1', 'number': 1, 'started_at': metrics.iso(epoch(start)), 'ended_at': metrics.iso(epoch(end)),
            'value': value}


class ShareEnv(Env):
    def night_file(self, events):
        with open(os.path.join(self.night, 'measurements.jsonl'), 'w', encoding='utf-8') as f:
            for item in events:
                f.write(json.dumps(item) + '\n')

    def stored(self, epic, attempts):
        os.makedirs(os.path.join(self.state, 'epics'), exist_ok=True)
        with open(os.path.join(self.state, 'epics', '%s.json' % epic), 'w') as f:
            json.dump({'epic': epic, 'attempts': attempts}, f)

    def demo(self, attempts, transcripts=True, begin=110, end=5010):
        """The epic `demo` measured from `begin` to `end` seconds after BASE whose attempts are (start, end, cost)."""
        home = testkit.synthetic_home(
            self.root, tasks=[('T-1', 'plan')], created=epoch(begin - 10), events=[(epoch(end - 10), 'epic_done', {})],
            attempts=[dict(task_id='T-1', number=i, started_at=epoch(s), ended_at=epoch(e))
                      for i, (s, e, _) in enumerate(attempts, 1)])
        if transcripts:
            for i, (_, _, cost) in enumerate(attempts, 1):
                lines = Transcript().init().message(input=10).result(
                    {OPUS: model_usage(input=10, output=1, cost=cost)}, output=1)
                testkit.put_transcript(home, 'T-1', i, lines.lines)
        return home

    def subscription(self, home):
        record = self.record('demo', home=home)
        return record['subscription'], record


class ShareTest(ShareEnv):
    def test_share_is_the_value_of_the_epic_by_the_value_of_both_in_the_interval(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        self.stored('other', [attempt_of(1000, 3000, 5.0)])
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)]))
        self.assertEqual(sub['overlap'], ['other'])
        # the common interval is 1000..3000: demo has 10 * 2000 / 3800, other all of its 5
        mine = 10.0 * 2000.0 / 3800.0
        self.assertAlmostEqual(sub['share'], mine / (mine + 5.0), places=4)
        self.assertTrue(sub['share_estimated'])
        self.assertEqual(sub['windows'], {ALL: 20})          # the raw increase stays next to the share
        self.assertFalse(any(g.startswith('subscription.share') for g in record['gaps']), record['gaps'])

    def test_value_is_assigned_to_the_interval_evenly_over_the_duration_of_an_attempt(self):
        self.night_file([event('demo', 2000, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        self.stored('other', [attempt_of(1000, 3000, 4.0)])        # half of it lies in the interval of demo
        sub, _ = self.subscription(self.demo([(2000, 4000, 6.0), (4500, 7500, 6.0)], begin=2000))
        # the common interval is 2000..3000: demo has half of its first attempt (6 USD over 2000 s), other half of its 4
        self.assertAlmostEqual(sub['share'], 3.0 / (3.0 + 2.0), places=4)

    def test_a_long_epic_with_a_short_overlap_at_the_same_rate_has_a_share_of_one_half(self):
        hour = 3600
        self.night_file([event('demo', 0, 'start', 10), event('other', 4 * hour, 'start', 12),
                         event('other', 5 * hour, 'done', 14), event('demo', 10 * hour, 'done', 40)])
        self.stored('other', [attempt_of(4 * hour, 5 * hour, 3.6)])
        sub, _ = self.subscription(self.demo([(0, 10 * hour, 36.0)], begin=0, end=10 * hour))
        self.assertAlmostEqual(sub['share'], 0.5, places=4)
        self.assertEqual(sub['windows'], {ALL: 30})           # the raw increase of the whole interval

    def test_the_common_interval_is_the_union_of_the_overlaps_with_several_epics(self):
        hour = 3600
        self.night_file([event('demo', 0, 'start', 10), event('early', 0, 'start', 10), event('early', hour, 'done', 11),
                         event('late', 9 * hour, 'start', 20), event('late', 10 * hour, 'done', 21),
                         event('demo', 10 * hour, 'done', 40)])
        self.stored('early', [attempt_of(0, hour, 3.6)])
        self.stored('late', [attempt_of(9 * hour, 10 * hour, 7.2)])
        sub, _ = self.subscription(self.demo([(0, 10 * hour, 36.0)], begin=0, end=10 * hour))
        self.assertEqual(sub['overlap'], ['early', 'late'])
        # demo has 3.6 USD in each of the two hours that are shared; the others have 3.6 and 7.2 USD in theirs
        self.assertAlmostEqual(sub['share'], 7.2 / (7.2 + 3.6 + 7.2), places=4)

    def test_the_value_outside_the_common_interval_is_not_counted(self):
        hour = 3600
        self.night_file([event('demo', 0, 'start', 10), event('other', 9 * hour, 'start', 12),
                         event('other', 10 * hour, 'done', 14), event('demo', 10 * hour, 'done', 40)])
        self.stored('other', [attempt_of(9 * hour, 10 * hour, 3.6)])
        sub, _ = self.subscription(self.demo([(0, hour, 100.0), (9 * hour, 10 * hour, 3.6)], begin=0, end=10 * hour))
        self.assertAlmostEqual(sub['share'], 0.5, places=4)

    def test_share_is_1_without_overlap_and_the_raw_increase_stays(self):
        self.night_file([event('demo', 110, 'start', 10), event('demo', 5010, 'done', 25)])
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)]))
        self.assertEqual((sub['share'], sub['share_estimated'], sub['overlap']), (1, False, []))
        self.assertEqual(sub['windows'], {ALL: 15})

    def test_share_is_1_without_overlap_even_when_the_value_is_unknown(self):
        self.night_file([event('demo', 110, 'start', 10), event('demo', 5010, 'done', 25)])
        sub, _ = self.subscription(self.demo([(200, 4000, 10.0)], transcripts=False))
        self.assertEqual(sub['share'], 1)

    def test_without_the_record_of_the_other_epic_share_is_null_with_a_gap(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)]))
        self.assertIsNone(sub['share'])
        self.assertEqual(sub['windows'], {ALL: 20})
        self.assertTrue(any(g.startswith('subscription.share') and 'other' in g for g in record['gaps']), record['gaps'])

    def test_a_record_of_the_other_epic_with_an_attempt_without_a_value_gives_null_with_a_gap(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        self.stored('other', [attempt_of(1000, 3000, None)])
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)]))
        self.assertIsNone(sub['share'])
        self.assertTrue(any(g.startswith('subscription.share') for g in record['gaps']), record['gaps'])

    def test_a_record_of_the_other_epic_without_attempts_gives_null_with_a_gap(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        os.makedirs(os.path.join(self.state, 'epics'))
        with open(os.path.join(self.state, 'epics', 'other.json'), 'w') as f:
            json.dump({'epic': 'other'}, f)
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)]))
        self.assertIsNone(sub['share'])

    def test_without_the_value_of_this_epic_share_is_null_with_a_gap(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        self.stored('other', [attempt_of(1000, 3000, 5.0)])
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)], transcripts=False))
        self.assertIsNone(sub['share'])
        self.assertTrue(any(g.startswith('subscription.share') for g in record['gaps']), record['gaps'])

    def test_an_epic_with_no_value_in_the_interval_of_either_gives_null(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        self.stored('other', [attempt_of(9000, 9500, 5.0)])
        sub, record = self.subscription(self.demo([(9000, 9100, 0.0)]))
        self.assertIsNone(sub['share'])

    def test_the_stored_record_of_this_epic_is_not_counted_as_another_epic(self):
        self.night_file([event('demo', 110, 'start', 10), event('demo', 5010, 'done', 25)])
        self.stored('demo', [attempt_of(200, 4000, 99.0)])
        sub, _ = self.subscription(self.demo([(200, 4000, 10.0)]))
        self.assertEqual(sub['share'], 1)

    def test_catalog_admin_fixture_overlaps_an_epic_without_a_record(self):
        shutil.copy(testkit.fixture('measurements.jsonl'), self.night)
        record = self.record('catalog-admin', home=testkit.fixture_home(self.root, 'catalog-admin'))
        self.assertTrue(record['subscription']['overlap'])
        self.assertIsNone(record['subscription']['share'])
        self.assertTrue(any(g.startswith('subscription.share') for g in record['gaps']), record['gaps'])


if __name__ == '__main__':
    unittest.main()

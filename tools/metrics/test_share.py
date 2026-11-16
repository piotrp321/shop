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

    def run_home(self, epic, attempts, transcripts=True, begin=110, end=5010, with_result=True):
        """The run directory of `epic` measured from `begin` to `end` seconds after BASE whose attempts are
        (start, end, cost), each with a transcript (without a CLI result when `with_result` is false)."""
        task = '%s-1' % epic.upper()
        home = testkit.synthetic_home(
            self.root, epic=epic, tasks=[(task, 'plan')], created=epoch(begin - 10),
            events=[(epoch(end - 10), 'epic_done', {})],
            attempts=[dict(task_id=task, number=i, started_at=epoch(s), ended_at=epoch(e))
                      for i, (s, e, _) in enumerate(attempts, 1)])
        if transcripts:
            for i, (_, _, cost) in enumerate(attempts, 1):
                lines = Transcript().init().message(input=10)
                if with_result:
                    lines.result({OPUS: model_usage(input=10, output=1, cost=cost)}, output=1)
                testkit.put_transcript(home, task, i, lines.lines)
        return home

    def demo(self, attempts, transcripts=True, begin=110, end=5010):
        """The epic `demo` measured from `begin` to `end` seconds after BASE whose attempts are (start, end, cost)."""
        return self.run_home('demo', attempts, transcripts, begin, end)

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

    def test_without_the_record_the_value_of_the_other_epic_comes_from_its_run_directory(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        self.run_home('other', [(1000, 3000, 5.0)], begin=1000, end=3010)
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)]))
        mine = 10.0 * 2000.0 / 3800.0
        self.assertAlmostEqual(sub['share'], mine / (mine + 5.0), places=4)
        self.assertTrue(sub['share_estimated'])
        self.assertFalse(any(g.startswith('subscription.share') for g in record['gaps']), record['gaps'])
        self.assertFalse(os.path.exists(os.path.join(self.state, 'epics', 'other.json')))   # nothing is recorded for it

    def test_the_stored_record_wins_over_the_run_directory(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        self.run_home('other', [(1000, 3000, 99.0)], begin=1000, end=3010)
        self.stored('other', [attempt_of(1000, 3000, 5.0)])
        sub, _ = self.subscription(self.demo([(200, 4000, 10.0)]))
        mine = 10.0 * 2000.0 / 3800.0
        self.assertAlmostEqual(sub['share'], mine / (mine + 5.0), places=4)

    def test_an_attempt_of_the_run_directory_without_a_cli_result_is_valued_from_the_history_of_rates(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        self.run_home('other', [(1000, 3000, 0.0)], begin=1000, end=3010, with_result=False)
        self.stored('hist', [{'cli_result': True, 'model_rates': {OPUS: 0.1}, 'started_at': None, 'ended_at': None}])
        sub, _ = self.subscription(self.demo([(200, 4000, 10.0)]))
        mine = 10.0 * 2000.0 / 3800.0
        self.assertAlmostEqual(sub['share'], mine / (mine + 10 * 0.1), places=4)      # 10 input tokens at 0.1 USD
        self.assertTrue(sub['share_estimated'])

    def test_a_run_directory_without_values_gives_null_with_a_gap(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        self.run_home('other', [(1000, 3000, 5.0)], begin=1000, end=3010, transcripts=False)
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)]))
        self.assertIsNone(sub['share'])
        self.assertTrue(any(g.startswith('subscription.share') and 'other' in g for g in record['gaps']), record['gaps'])

    def test_an_unreadable_run_directory_gives_null_with_a_gap(self):
        self.night_file([event('demo', 110, 'start', 10), event('other', 1000, 'start', 12),
                         event('other', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        os.makedirs(os.path.join(self.root, 'ao-other'))
        with open(os.path.join(self.root, 'ao-other', 'orchestrator.db'), 'wb') as f:
            f.write(b'not a database' * 100)
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)]))
        self.assertIsNone(sub['share'])
        self.assertTrue(any(g.startswith('subscription.share') for g in record['gaps']), record['gaps'])

    def test_the_epic_id_of_a_measurement_cannot_leave_the_ao_root(self):
        self.night_file([event('demo', 110, 'start', 10), event('../escape', 1000, 'start', 12),
                         event('../escape', 3000, 'done', 14), event('demo', 5010, 'done', 30)])
        sub, record = self.subscription(self.demo([(200, 4000, 10.0)]))
        self.assertIsNone(sub['share'])
        self.assertTrue(any(g.startswith('subscription.share') for g in record['gaps']), record['gaps'])

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


class BackfillShareTest(ShareEnv):
    """backfill records epics in the order of their names: the epic recorded first has no record of the other one."""

    def backfill(self, big, small):
        """Backfills the epic `big` (10 USD over 200..4000) and `small` (5 USD over 1000..3000, inside it)."""
        self.night_file([event(big, 110, 'start', 10), event(small, 1000, 'start', 12),
                         event(small, 3000, 'done', 14), event(big, 5010, 'done', 30)])
        self.run_home(big, [(200, 4000, 10.0)])
        self.run_home(small, [(1000, 3000, 5.0)], begin=1000, end=3010)
        code, out, err = testkit.run_cli(['backfill', '--ao-root', self.root, '--state', self.state, '--night',
                                          self.night], testkit.FakeGh(prs={7: testkit.merged_pr()}))
        self.assertEqual(code, 0, err)
        self.assertEqual(sorted(json.loads(out)['recorded']), sorted([big, small]))
        found = {}
        for name in (big, small):
            with open(os.path.join(self.state, 'epics', '%s.json' % name), encoding='utf-8') as f:
                found[name] = json.load(f)
        return found

    def check_pair(self, big, small):
        found = self.backfill(big, small)
        shared = 10.0 * 2000.0 / 3800.0             # the value of the big epic in the common interval
        self.assertIsNotNone(found[big]['subscription']['share'], found[big]['gaps'])
        self.assertIsNotNone(found[small]['subscription']['share'], found[small]['gaps'])
        self.assertAlmostEqual(found[big]['subscription']['share'], shared / (shared + 5.0), places=4)
        self.assertAlmostEqual(found[small]['subscription']['share'], 5.0 / (5.0 + shared), places=4)

    def test_both_epics_of_a_pair_get_a_share_when_the_overlapped_one_is_recorded_first(self):
        self.check_pair('aaa', 'bbb')

    def test_both_epics_of_a_pair_get_a_share_when_the_overlapped_one_is_recorded_last(self):
        self.check_pair('bbb', 'aaa')


if __name__ == '__main__':
    unittest.main()

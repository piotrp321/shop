"""Tests of the pauses of the computer of a native epic (docs/features/metrics/spec.md, "Epik natywny"; spec
docs/features/ash-process-fixes/spec.md, T3; issue #832): `metrics.py pause` writes them to the run file, the record
counts them as `breakdown.host_pause`, never as `work`, `human_wait` or `idle`, and without a recorded pause nothing
changes. python3 -m unittest discover -s tools/metrics -p 'test_*.py'"""
import json
import os
import unittest

import testkit
from testkit import OPUS, Native, NativeEnv, iso_at, run_cli

CATEGORIES = ('work', 'human_wait', 'limit_wait', 'ci_wait', 'host_pause', 'github_wait', 'idle')


def total(breakdown):
    return round(sum(v for v in breakdown.values() if v is not None), 3)


class PauseCommandTest(NativeEnv):
    def pause(self, *extra, epic='demo'):
        return run_cli(['pause', epic, '--state', self.state] + list(extra))

    def run_file(self):
        with open(os.path.join(self.state, 'runs', 'demo.json'), encoding='utf-8') as f:
            return json.load(f)

    def test_pause_appends_to_the_run_file(self):
        self.write_run()
        code, out, err = self.pause('--from', '2027-01-15T09:25:00+01:00', '--to', iso_at(7000), '--reason',
                                    '  komputer wyłączony  ')
        self.assertEqual(code, 0, err)
        code, out, err = self.pause('--from', iso_at(100), '--to', iso_at(200))
        self.assertEqual(code, 0, err)
        self.assertEqual(self.run_file()['pauses'], [
            {'from': '2027-01-15T08:25:00Z', 'to': iso_at(7000), 'reason': 'komputer wyłączony'},
            {'from': iso_at(100), 'to': iso_at(200), 'reason': None}])
        self.assertEqual(json.loads(out), self.run_file())

    def test_errors_write_nothing(self):
        code, out, err = self.pause('--from', iso_at(0), '--to', iso_at(10))
        self.assertEqual((code, out), (2, ''))
        self.assertIn('pliku przebiegu', err)
        self.write_run()
        before = self.run_file()
        for extra in ((iso_at(10), iso_at(10)), (iso_at(20), iso_at(10)), ('wczoraj', iso_at(10)),
                      (iso_at(0), '2027-01-15 08:00')):
            code, out, err = self.pause('--from', extra[0], '--to', extra[1])
            self.assertEqual((code, out), (2, ''), extra)
            self.assertTrue(err.startswith('metrics: '), err)
        self.assertEqual(self.run_file(), before)
        code, out, err = self.pause('--from', iso_at(0))
        self.assertEqual(code, 2)

    def test_epic_name_outside_the_pattern_is_refused(self):
        code, out, err = self.pause('--from', iso_at(0), '--to', iso_at(10), epic='DEMO')
        self.assertEqual(code, 2)
        self.assertIn('nazwa epiku', err)


class HostPauseTest(NativeEnv):
    """The record: the pauses cut to start-done and merged come first."""

    def lead(self):
        # work 0-10 and 10-100, a human answer at 5000 after a pause of 4900 s, work 5000-5010
        return Native('s1').human(-50).assistant(10, input=1).assistant(100).human(5000).assistant(5010)

    def epic(self, pauses=None, done=6000, subagent=False, epic='demo'):
        extra = {} if pauses is None else {'pauses': [{'from': iso_at(a), 'to': iso_at(b), 'reason': None}
                                                      for a, b in pauses]}
        self.write_run(epic, started=0, done=done, **extra)
        self.write_session('s1', self.lead())
        if subagent:
            self.write_subagent('s1', 'a1', Native('s1', 'a1').assistant(1500, model=OPUS).assistant(2500, model=OPUS),
                                {'agentType': 'developer', 'description': 'DEMO-1', 'finished': True})
        return self.native_record(epic)

    def assert_sum(self, record, to_done=6000):
        self.assertEqual(total(record['breakdown']), to_done)

    def test_without_pauses_host_pause_stays_null_with_its_gap(self):
        record = self.epic()
        self.assertEqual(record['breakdown'], {'work': 110, 'human_wait': 4900, 'limit_wait': None, 'ci_wait': None,
                                               'host_pause': None, 'github_wait': None, 'idle': 990})
        self.assertTrue([g for g in record['gaps'] if g.startswith('breakdown.host_pause: ')], record['gaps'])
        self.assertEqual(record['human']['wait_s'], 4900)

    def test_pause_in_the_same_session_is_no_human_wait(self):
        record = self.epic([(1000, 3000)])
        self.assertEqual(record['breakdown']['host_pause'], 2000)
        self.assertEqual(record['breakdown']['human_wait'], 2900)
        self.assertEqual(record['breakdown']['work'], 110)
        self.assertEqual(record['breakdown']['idle'], 990)
        self.assert_sum(record)
        self.assertEqual(record['human']['events'][-1]['wait_s'], 2900)
        self.assertEqual(record['human']['wait_s'], 2900)
        self.assertFalse([g for g in record['gaps'] if g.startswith('breakdown.host_pause: ')], record['gaps'])

    def test_pause_over_the_work_of_a_subagent_is_no_work(self):
        without = self.epic(subagent=True)
        self.assertEqual(without['breakdown']['work'], 1110)
        record = self.epic([(1000, 3000)], subagent=True)
        self.assertEqual(record['breakdown']['work'], 110)
        self.assertEqual(record['breakdown']['host_pause'], 2000)
        self.assertEqual(record['breakdown']['human_wait'], 2900)
        self.assert_sum(record)

    def test_pause_in_a_new_session_after_resume(self):
        self.write_run(sessions=('s1', 's2'), started=0, done=6000,
                       pauses=[{'from': iso_at(1000), 'to': iso_at(3000), 'reason': 'noc'}])
        self.write_session('s1', Native('s1').human(-50).assistant(10, input=1).assistant(100))
        self.write_session('s2', Native('s2').human(4000).assistant(4010, input=1).assistant(4100))
        record = self.native_record()
        self.assertEqual(record['breakdown']['host_pause'], 2000)
        self.assertEqual(record['breakdown']['work'], 200)
        self.assertEqual(record['breakdown']['idle'], 6000 - 200 - 2000)
        self.assert_sum(record)

    def test_pause_partly_before_the_start_counts_only_inside(self):
        record = self.epic([(-1000, 1000)])
        self.assertEqual(record['breakdown']['host_pause'], 1000)       # cut to 0-1000, before the work 0-100
        self.assertEqual(record['breakdown']['work'], 10)
        self.assertEqual(record['breakdown']['human_wait'], 4000)
        self.assert_sum(record)

    def test_pause_outside_start_and_done_is_zero(self):
        record = self.epic([(7000, 8000)])
        self.assertEqual(record['breakdown']['host_pause'], 0)
        self.assertEqual(record['breakdown']['human_wait'], 4900)
        self.assert_sum(record)

    def test_two_overlapping_pauses_count_once(self):
        record = self.epic([(1000, 2000), (1500, 3000)])
        self.assertEqual(record['breakdown']['host_pause'], 2000)
        self.assertEqual(record['breakdown']['human_wait'], 2900)
        self.assert_sum(record)

    def test_a_bad_pause_entry_is_skipped_with_a_gap(self):
        self.write_run(started=0, done=6000, pauses=[{'from': 'x', 'to': iso_at(10)}, 'text',
                                                     {'from': iso_at(3000), 'to': iso_at(1000)},
                                                     {'from': iso_at(1000), 'to': iso_at(3000)}])
        self.write_session('s1', self.lead())
        record = self.native_record()
        self.assertEqual(record['breakdown']['host_pause'], 2000)
        self.assertTrue([g for g in record['gaps'] if g.startswith('pauses: ')], record['gaps'])

    def test_decide_d4_human_wait_does_not_fire_because_of_a_pause(self):
        self.epic(epic='delivery')
        code, out, err = run_cli(['decide', 'delivery', '--state', self.state])
        self.assertEqual(code, 0, err)
        self.assertIn('D4:human_wait', [c['key'] for c in json.loads(out)['candidates']])
        self.epic([(1000, 5000)], epic='delivery')       # human_wait 900 of 6000: 15% < 20%
        code, out, err = run_cli(['decide', 'delivery', '--state', self.state])
        self.assertEqual(code, 0, err)
        self.assertNotIn('D4:human_wait', [c['key'] for c in json.loads(out)['candidates']])

    def test_pause_command_then_record(self):
        self.write_run(started=0, done=6000)
        self.write_session('s1', self.lead())
        code, out, err = run_cli(['pause', 'demo', '--state', self.state, '--from', iso_at(1000), '--to',
                                  iso_at(3000)])
        self.assertEqual(code, 0, err)
        self.assertEqual(self.native_record()['breakdown']['host_pause'], 2000)


if __name__ == '__main__':
    unittest.main()

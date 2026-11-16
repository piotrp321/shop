"""Tests of `metrics.py decide --history` (spec: Decyzje optymalizacyjne): the candidates of every merged epic with the
same key for the same rule and subject in different epics (the input of /retro)."""
import json
import unittest

from test_decide import DecideEnv, FILES, H
from test_decide_models import OPUS, SONNET, target as model_target
from testkit import fake_record, run_cli, store_records


def epic(name, merged, **kwargs):
    params = dict(merged=merged, tasks=10, to_done=100 * H, work=50 * H, human_wait=0.0, review=0.0, gate_wait=0.0)
    params.update(kwargs)
    record = fake_record(name, **params)
    record['by_task'] = {'%s-%d' % (name.upper(), n): {'kind': 'plan', 'work': 0.5 * H, 'attempts': 1} for n in range(10)}
    return record


class HistoryTest(DecideEnv):
    def setUp(self):
        super().setUp()
        slow = epic('slow', '2026-11-03T10:00:00Z')
        slow['by_task']['SLOW-3']['work'] = 4 * H
        slower = epic('slower', '2026-11-05T10:00:00Z')
        slower['by_task']['SLOWER-8']['work'] = 6 * H            # another task id, the same rule and subject
        store_records(
            self.state, epic('a', '2026-11-01T10:00:00Z', human_wait=30 * H),
            epic('b', '2026-11-02T10:00:00Z', human_wait=25 * H, gate_wait=2 * H),
            epic('quiet', '2026-11-04T10:00:00Z'), slow, slower,
            epic('open', None, human_wait=90 * H, review=None))
        self.records = None

    def history(self, epic_name='quiet'):
        return self.result('--history', epic=epic_name)

    def listed(self, result, name):
        return next(e for e in result['history'] if e['epic'] == name)

    def test_lists_every_merged_epic_in_merge_order_and_not_the_open_one(self):
        result = self.history()
        self.assertEqual([e['epic'] for e in result['history']], ['a', 'b', 'slow', 'quiet', 'slower'])

    def test_the_same_rule_and_subject_has_the_same_key_in_different_epics(self):
        result = self.history()
        self.assertIn('D4:human_wait', self.keys(self.listed(result, 'a')))
        self.assertIn('D4:human_wait', self.keys(self.listed(result, 'b')))

    def test_a_d2_candidate_has_one_key_although_the_task_ids_differ(self):
        result = self.history()
        for name, task in (('slow', 'SLOW-3'), ('slower', 'SLOWER-8')):
            entry = self.listed(result, name)
            candidate = self.candidate(entry, 'D2:plan_split')
            self.assertEqual([t['task'] for t in candidate['data']['tasks']], [task])

    def test_an_epic_without_a_firing_rule_has_no_candidates(self):
        self.assertEqual(self.listed(self.history(), 'quiet')['candidates'], [])

    def test_the_keys_summary_lists_the_epics_a_rule_fired_in(self):
        keys = self.history()['keys']
        self.assertEqual(keys['D4:human_wait'], ['a', 'b'])
        self.assertEqual(keys['D2:plan_split'], ['slow', 'slower'])

    def test_the_history_is_computed_per_epic_from_the_epics_merged_before_it(self):
        # `slow` is the first epic with a plan task of 4 h: its history has 2 epics of 0,5 h tasks (median 0,5 h), so the
        # task is over twice the median; `slower` has a history that includes `slow` (median of 0,5 h still)
        result = self.history()
        self.assertEqual(self.candidate(self.listed(result, 'slow'), 'D2:plan_split')['data']['history_epics'], 2)
        self.assertEqual(self.candidate(self.listed(result, 'slower'), 'D2:plan_split')['data']['history_epics'], 4)

    def test_the_result_of_the_named_epic_is_still_there(self):
        result = self.history('a')
        self.assertEqual(result['epic'], 'a')
        self.assertIn('D4:human_wait', self.keys(result))

    def test_the_named_epic_may_be_open_and_is_not_in_the_history_list(self):
        result = self.history('open')
        self.assertEqual(result['epic'], 'open')
        self.assertNotIn('open', [e['epic'] for e in result['history']])

    def test_without_the_flag_there_is_no_history(self):
        self.assertNotIn('history', self.result(epic='a'))

    def test_a_missing_epic_ends_with_code_2(self):
        code, out, err = self.decide('--history', epic='nope')
        self.assertEqual((code, out), (2, ''))


class HistoryOfD1Test(DecideEnv):
    def test_d1_candidates_are_listed_per_epic_with_the_same_key(self):
        import os
        evals = os.path.join(self.root, 'evals', 'm1', 'runs', 'r1')
        os.makedirs(evals)
        with open(os.path.join(evals, 'meta.json'), 'w') as f:
            json.dump({'status': 'ok', 'mode': 'replay', 'subagent_model': OPUS}, f)
        with open(__file__.replace('test_decide_history.py', 'config.json'), encoding='utf-8') as f:
            config = json.load(f)
        config['evals']['results_dir'] = os.path.join(self.root, 'evals')
        path = os.path.join(self.root, 'config.json')
        with open(path, 'w') as f:
            json.dump(config, f)
        roles = {'code-reviewer': {OPUS: 30.0, SONNET: 2.0}, 'delivery': {OPUS: 68.0}}
        first, second = model_target(roles), model_target(roles)
        first['epic'], first['merged_at'] = 'first', '2026-11-01T10:00:00Z'
        second['epic'], second['merged_at'] = 'second', '2026-11-02T10:00:00Z'
        store_records(self.state, first, second)
        code, out, err = run_cli(['decide', 'second', '--history', '--state', self.state, '--night', self.night,
                                  '--config', path])
        self.assertEqual(code, 0, err)
        result = json.loads(out)
        self.assertEqual(result['keys']['D1:code-reviewer'], ['first', 'second'])


if __name__ == '__main__':
    unittest.main()

"""Tests of `in_attempts` (time of shell commands per category, repeat_runs, api_retry, permission_denials) and of
`versions` of the epic record, read from the transcripts (spec: Rekord epiku, Model zagrożeń path 1)."""
import glob
import json
import os
import re
import unittest

import metrics
import testkit
from testkit import AttemptEnv, OPUS, SONNET, Transcript
from test_tokens import real_transcripts

CONFIG = metrics.load_config(metrics.DEFAULT_CONFIG)
CATEGORIES = tuple(CONFIG['command_categories']) + ('other',)


class InAttemptsEnv(AttemptEnv):
    def in_attempts(self, transcript, **kwargs):
        return self.attempt_record(transcript, **kwargs)['in_attempts']

    def repeats(self, transcript):
        return self.in_attempts(transcript)['repeat_runs']


class CategoryTimeTest(InAttemptsEnv):
    def test_time_from_tool_use_to_its_tool_result_per_category(self):
        t = Transcript().init()
        t.bash('make test # c1', 10, 25)
        t.bash('make -C apps/api check # c1', 30, 42)
        t.bash('git status', 50, 51)
        t.bash('make e2e # c1', 60, 160)
        t.bash('make -C infra/k8s selftest # c1', 170, 173)
        t.bash('make up # c1', 180, 190)
        t.bash('gh pr checks 1 --watch # c1', 200, 260)
        t.bash('true # c1', 270, 272)
        t.bash('make test # c2', 280, 290)
        categories = self.in_attempts(t)['categories']
        self.assertEqual(sorted(categories), sorted(CATEGORIES))
        seconds = {name: entry['seconds'] for name, entry in categories.items()}
        self.assertEqual(seconds, {'read': 1.0, 'test': 25.0, 'e2e': 100.0, 'check': 12.0, 'selftest': 3.0,
                                   'stack': 10.0, 'ci_watch': 60.0, 'other': 2.0})
        self.assertEqual(categories['test']['count'], 2)
        self.assertEqual(categories['other']['count'], 1)

    def test_shell_tool_names_come_from_the_config(self):
        t = Transcript().init()
        t.tool('bash', {'command': 'make test'}, 0, 4)
        t.tool('Edit', {'file_path': 'x'}, 5, 6)
        t.tool('Read', {'file_path': 'x'}, 7, 8)
        categories = self.in_attempts(t)['categories']
        self.assertEqual((categories['test']['count'], categories['test']['seconds']), (1, 4.0))
        self.assertEqual(sum(entry['count'] for entry in categories.values()), 1)

    def test_a_background_command_is_only_in_the_count(self):
        t = Transcript().init()
        t.bash('make e2e', 10, 11, background=True)
        t.bash('make e2e', 20, 50)
        entry = self.in_attempts(t)['categories']['e2e']
        self.assertEqual((entry['count'], entry['background'], entry['seconds']), (2, 1, 30.0))

    def test_a_command_without_a_result_is_counted_and_has_no_time(self):
        t = Transcript().init()
        t.bash('make test', 10, None)
        entry = self.in_attempts(t)['categories']['test']
        self.assertEqual((entry['count'], entry['unfinished'], entry['seconds']), (1, 1, 0.0))

    def test_commands_of_subagents_are_counted_too(self):
        t = Transcript().init()
        t.bash('make test', 10, 20, role='developer', parent='toolu_9', model=SONNET)
        self.assertEqual(self.in_attempts(t)['categories']['test']['seconds'], 10.0)

    def test_a_read_only_command_is_read_even_when_it_mentions_a_test_command(self):
        t = Transcript().init()
        t.bash('grep -n "make test" Makefile', 10, 11)
        categories = self.in_attempts(t)['categories']
        self.assertEqual((categories['read']['count'], categories['test']['count']), (1, 0))

    def test_the_record_never_holds_the_text_of_a_command(self):
        t = Transcript().init()
        t.bash('cd /Users/someone/work && make test --filter secret_marker_xyz | tail -5', 10, 20)
        t.bash('cat /Users/someone/.ssh/id_marker', 30, 31)
        t.tool('Write', {'file_path': '/Users/someone/work/a.php', 'content': 'secret_marker_abc'}, 40, 41)
        record = self.attempt_record(t)
        text = json.dumps(record, ensure_ascii=False)
        for forbidden in ('/Users/', 'secret_marker', 'id_marker', 'someone', 'make test'):
            self.assertNotIn(forbidden, text)
        self.assertEqual(record['in_attempts']['categories']['test']['count'], 1)

    def test_attempts_without_transcripts_leave_a_gap_and_no_in_attempts(self):
        record = self.attempt_record(None)
        self.assertIsNone(record['in_attempts'])
        self.assertIsNone(record['versions'])
        self.assertTrue(any(g.startswith('in_attempts') for g in record['gaps']), record['gaps'])


class RepeatRunsTest(InAttemptsEnv):
    def run_of(self, *steps):
        """The repeat_runs of a transcript of steps (`command`, or a tool name with its input), 10 s each."""
        t = Transcript().init()
        at = 0
        for step in steps:
            if isinstance(step, tuple):
                t.tool(step[0], step[1], at, at + 10 if len(step) < 3 or step[2] else None)
            else:
                t.bash(step, at, at + 10)
            at += 20
        return self.repeats(t)

    def test_the_same_command_without_a_code_change_between_is_a_repeat(self):
        found = self.run_of('make test # c1', 'git status', 'make test # c1')
        self.assertEqual((found['count'], found['seconds']), (1, 10.0))
        self.assertEqual(found['by_category']['test'], {'count': 1, 'seconds': 10.0})

    def test_each_further_run_is_a_repeat_of_the_run_before_it(self):
        self.assertEqual(self.run_of('make check', 'make check', 'make check')['count'], 2)

    def test_an_appended_tail_or_redirect_does_not_change_the_key(self):
        found = self.run_of('make -C apps/api test', 'make -C apps/api test | tail -n 20',
                            'make -C apps/api test > out.log 2>&1', 'make -C apps/api test; echo $?',
                            'make -C apps/api test && echo done')
        self.assertEqual(found['count'], 4)

    def test_a_redirect_of_a_file_descriptor_does_not_change_the_key(self):
        found = self.run_of('make test 2>&1 | tail -50', 'make test > out.log', 'make test 2> err.log',
                            'make test 1>out.log 2>&1', 'make test &> all.log')
        self.assertEqual(found['count'], 4)
        self.assertEqual(metrics.classify_command('make -C apps/api test 2>&1 | tail -50', metrics.compile_categories(
            CONFIG)), ('test', 'make -C apps/api test'))

    def test_a_redirect_does_not_hide_a_different_target(self):
        self.assertEqual(self.run_of('make -C apps/api test 2>&1 | tail', 'make -C apps/store test 2>&1')['count'], 0)

    def test_a_number_that_is_an_argument_stays_in_the_key(self):
        found = self.run_of('pytest -k 2', 'pytest -k 3', 'pytest -k 2 > out.log')
        self.assertEqual(found['count'], 1)

    def test_a_different_target_of_the_same_category_is_not_a_repeat(self):
        self.assertEqual(self.run_of('make -C apps/api test', 'make -C apps/store test')['count'], 0)
        self.assertEqual(self.run_of('make test # c1', 'make test # c2')['count'], 0)
        self.assertEqual(self.run_of('make check', 'make e2e')['count'], 0)

    def test_only_test_e2e_and_check_runs_are_repeats(self):
        found = self.run_of('make up', 'make up', 'make -C infra/k8s selftest', 'make -C infra/k8s selftest',
                            'git status', 'git status', 'gh pr checks 1 --watch', 'gh pr checks 1 --watch')
        self.assertEqual(found['count'], 0)

    def test_edit_write_notebook_edit_and_an_other_command_are_a_code_change(self):
        for change in (('Edit', {'file_path': 'a'}), ('Write', {'file_path': 'a'}), ('NotebookEdit', {}),
                       'echo changed > file', 'sed -i s/a/b/ file'):
            with self.subTest(change=change):
                self.assertEqual(self.run_of('make test', change, 'make test')['count'], 0)

    def test_a_change_by_a_subagent_counts_too(self):
        t = Transcript().init()
        t.bash('make test', 0, 10)
        t.tool('Edit', {'file_path': 'a'}, 20, 21, role='developer', parent='toolu_1', model=SONNET)
        t.bash('make test', 30, 40, role='developer', parent='toolu_1', model=SONNET)
        self.assertEqual(self.repeats(t)['count'], 0)

    def test_neutral_commands_between_do_not_hide_a_repeat(self):
        found = self.run_of('make test', 'git status', 'gh pr checks 1 --watch', 'make up', 'make e2e',
                            'make -C infra/k8s selftest', ('Read', {'file_path': 'a'}), 'make test')
        self.assertEqual(found['count'], 1)

    def test_a_change_after_the_second_run_does_not_matter_but_one_before_the_third_does(self):
        found = self.run_of('make test', 'make test', 'echo x > f', 'make test')
        self.assertEqual(found['count'], 1)

    def test_a_background_command_between_excludes_the_run(self):
        t = Transcript().init()
        t.bash('make test', 0, 10)
        t.bash('make -C apps/api check', 20, 21, background=True)
        t.bash('make test', 30, 40)
        self.assertEqual(self.repeats(t)['count'], 0)

    def test_a_background_run_is_not_an_earlier_run_either(self):
        t = Transcript().init()
        t.bash('make test', 0, 1, background=True)
        t.bash('make test', 30, 40)
        self.assertEqual(self.repeats(t)['count'], 0)

    def test_a_tool_call_without_a_result_between_excludes_the_run(self):
        self.assertEqual(self.run_of('make test', ('Monitor', {}, False), 'make test')['count'], 0)

    def test_an_unfinished_subagent_between_excludes_the_run_and_a_finished_one_does_not(self):
        self.assertEqual(self.run_of('make test', ('Agent', {'run_in_background': False}, False),
                                     'make test')['count'], 0)
        self.assertEqual(self.run_of('make test', ('Agent', {'run_in_background': False}), 'make test')['count'], 1)

    def test_an_unfinished_run_is_not_a_repeat_and_hides_the_next_one(self):
        t = Transcript().init()
        t.bash('make test', 0, 10)
        t.bash('make test', 20, None)
        t.bash('make test', 30, 40)
        self.assertEqual(self.repeats(t)['count'], 0)

    def test_a_subagent_still_running_before_the_first_run_does_not_exclude(self):
        t = Transcript().init()
        agent = t.tool('Agent', {'run_in_background': False}, 0, None)
        t.bash('make test', 10, 20, role='developer', parent=agent, model=SONNET)
        t.bash('make test', 30, 40, role='developer', parent=agent, model=SONNET)
        t.tool_result(agent, 50)
        self.assertEqual(self.repeats(t)['count'], 1)

    def test_repeats_are_counted_per_attempt(self):
        home = testkit.synthetic_home(
            self.root, tasks=[('T-1', 'plan')], created=1000.0,
            attempts=[dict(task_id='T-1', number=1, started_at=1100.0, ended_at=1300.0),
                      dict(task_id='T-1', number=2, started_at=1400.0, ended_at=1500.0)],
            events=[(2000.0, 'epic_done', {})])
        for number in (1, 2):
            lines = Transcript().init()
            lines.bash('make test', 0, 5)
            testkit.put_transcript(home, 'T-1', number, lines.lines)
        record = self.record('demo', home=home)
        self.assertEqual(record['in_attempts']['repeat_runs']['count'], 0)
        self.assertEqual(record['in_attempts']['categories']['test']['count'], 2)


def brute_force(path):
    """The repeat runs and the category counts of a real transcript, counted the plain way from the spec text (the
    test's own reading, with a different structure than metrics.py): (count, seconds, {category: count})."""
    patterns = [(name, [re.compile(p) for p in found]) for name, found in CONFIG['command_categories'].items()]
    lines = testkit.read_lines(path)
    uses, results = [], {}
    for line in lines:
        if line['type'] == 'assistant':
            for block in line['message']['content']:
                if block.get('type') == 'tool_use' and block['id'] not in {u['id'] for u in uses}:
                    uses.append({'id': block['id'], 'name': block['name'], 'input': block['input'],
                                 'at': line['timestamp']})
        elif line['type'] == 'user':
            for block in line['message']['content']:
                if block.get('type') == 'tool_result':
                    results.setdefault(block['tool_use_id'], line['timestamp'])

    def seconds(use):
        return (metrics._timestamp(results[use['id']]) - metrics._timestamp(use['at'])) if use['id'] in results else None

    def classify(command):
        for name, found in patterns:
            for pattern in found:
                match = pattern.search(command)
                if match:
                    return name, ' '.join(match.group(0).split())
        return 'other', None

    counts = {name: 0 for name in CATEGORIES}
    classified = []
    for use in uses:
        if use['name'] in CONFIG['shell_tools']:
            category, key = classify(use['input'].get('command', ''))
            counts[category] += 1
            classified.append((use, category, key))
        else:
            classified.append((use, None, None))
    count, total = 0, 0.0
    for j, (use, category, key) in enumerate(classified):
        if category not in ('test', 'e2e', 'check') or use['input'].get('run_in_background') or use['id'] not in results:
            continue
        earlier = [i for i in range(j) if classified[i][1] == category and classified[i][2] == key
                   and not classified[i][0]['input'].get('run_in_background') and classified[i][0]['id'] in results]
        if not earlier:
            continue
        between = classified[earlier[-1] + 1:j]
        broken = any(u['name'] in ('Edit', 'Write', 'NotebookEdit') or c == 'other' or u['input'].get('run_in_background')
                     or u['id'] not in results for u, c, _ in between)
        if not broken:
            count, total = count + 1, total + seconds(use)
    return count, round(total, 3), counts


class RealTranscriptsTest(InAttemptsEnv):
    def test_categories_and_repeat_runs_match_a_plain_reading_of_every_transcript(self):
        found_repeats = 0
        for epic, task, number, path in real_transcripts():
            with self.subTest(task=task, number=number):
                count, total, counts = brute_force(path)
                found = self.in_attempts(path, task=task)
                self.assertEqual(found['repeat_runs']['count'], count)
                self.assertAlmostEqual(found['repeat_runs']['seconds'], total, places=2)
                self.assertEqual({n: e['count'] for n, e in found['categories'].items()}, counts)
                found_repeats += count
        self.assertGreater(found_repeats, 0)

    def test_the_repeated_tests_of_catalog_admin_4(self):
        path = testkit.fixture_transcript('catalog-admin', 'CATALOG-ADMIN-4', 1)
        found = self.in_attempts(path, task='CATALOG-ADMIN-4')
        self.assertGreater(found['repeat_runs']['count'], 0)
        self.assertGreater(found['repeat_runs']['seconds'], 0)
        self.assertEqual(sum(e['count'] for e in found['repeat_runs']['by_category'].values()),
                         found['repeat_runs']['count'])

    def test_api_retry_of_admin_design_3(self):
        path = testkit.fixture_transcript('admin-design', 'ADMIN-DESIGN-3', 1)
        delays = [l['retry_delay_ms'] for l in testkit.read_lines(path) if l.get('subtype') == 'api_retry']
        self.assertEqual(len(delays), 7)
        self.assertEqual(self.in_attempts(path, task='ADMIN-DESIGN-3')['api_retry'],
                         {'count': 7, 'delay_ms': sum(delays)})

    def test_permission_denials_are_counted_per_tool_name(self):
        path = testkit.fixture_transcript('catalog-admin', 'CATALOG-ADMIN-INTEGRATION-1', 1)
        names = [l['tool_name'] for l in testkit.read_lines(path) if l.get('subtype') == 'permission_denied']
        self.assertTrue(names)
        self.assertEqual(self.in_attempts(path, task='CATALOG-ADMIN-INTEGRATION-1')['permission_denials'],
                         {name: names.count(name) for name in sorted(set(names))})

    def test_permission_denials_of_the_whole_deploy_ops_run(self):
        expected = {}
        for epic, task, number, path in real_transcripts():
            if epic == 'deploy-ops':
                for line in testkit.read_lines(path):
                    if line.get('subtype') == 'permission_denied':
                        expected[line['tool_name']] = expected.get(line['tool_name'], 0) + 1
        self.assertTrue(expected)
        record = self.record('deploy-ops', home=testkit.fixture_home(self.root, 'deploy-ops'))
        self.assertEqual(record['in_attempts']['permission_denials'], expected)

    def test_a_run_without_retries_and_denials_has_zero_retries_and_no_denials(self):
        path = testkit.fixture_transcript('deploy-ops', 'DEPLOY-OPS-4', 6)
        found = self.in_attempts(path, task='DEPLOY-OPS-4')
        self.assertEqual(found['api_retry'], {'count': 0, 'delay_ms': 0})
        self.assertEqual(found['permission_denials'], {})


class SyntheticSystemEventsTest(InAttemptsEnv):
    def test_denials_and_retries_of_subagents_and_of_an_attempt_without_a_result_are_counted(self):
        t = Transcript().init().message(role='dev', parent='toolu_1', model=SONNET, input=3)
        t.denied('Bash').denied('Write').denied('Bash').retry(1000).retry(250)
        found = self.in_attempts(t, outcome='timeout', status=None)
        self.assertEqual(found['permission_denials'], {'Bash': 2, 'Write': 1})
        self.assertEqual(found['api_retry'], {'count': 2, 'delay_ms': 1250})

    def test_a_tool_name_that_is_not_an_identifier_is_counted_as_unknown(self):
        t = Transcript().init().message(input=1)
        t.denied('Bash').denied('/Users/someone/secret tool').denied('x' * 65).denied('mcp__ok__tool')
        found = self.in_attempts(t)
        self.assertEqual(found['permission_denials'], {'Bash': 1, 'mcp__ok__tool': 1, 'unknown': 2})
        self.assertNotIn('someone', json.dumps(found))

    def test_a_role_that_is_not_an_identifier_is_not_stored(self):
        t = Transcript().init().message(input=1)
        t.message(role='see /Users/someone/x', parent='toolu_1', input=2)
        t.message(role='ok-role', parent='toolu_1', input=3)
        record = self.attempt_record(t)
        self.assertEqual(sorted(record['value']['by_role']), ['delivery', 'ok-role', 'subagent'])
        self.assertNotIn('someone', json.dumps(record))


class VersionsTest(InAttemptsEnv):
    def test_cli_versions_and_models_with_attempt_counts_on_the_deploy_ops_run(self):
        record = self.record('deploy-ops', home=testkit.fixture_home(self.root, 'deploy-ops'))
        transcripts = [p for e, t, n, p in real_transcripts() if e == 'deploy-ops']
        sonnet = sum(1 for p in transcripts if any(
            l['type'] == 'assistant' and l['message']['model'] == SONNET for l in testkit.read_lines(p)))
        self.assertEqual(record['versions']['cli'], [{'version': '2.1.311', 'attempts': len(transcripts)}])
        self.assertEqual(record['versions']['models'], [{'model': OPUS, 'attempts': len(transcripts)},
                                                        {'model': SONNET, 'attempts': sonnet}])

    def test_models_without_messages_are_not_listed_and_versions_differ_per_attempt(self):
        home = testkit.synthetic_home(
            self.root, tasks=[('T-1', 'plan')], created=1000.0,
            attempts=[dict(task_id='T-1', number=1, started_at=1100.0, ended_at=1300.0),
                      dict(task_id='T-1', number=2, started_at=1400.0, ended_at=1500.0)],
            events=[(2000.0, 'epic_done', {})])
        first = Transcript().init(OPUS, '2.1.1').message(input=1).message(model=SONNET, role='d', parent='t', input=1)
        first.result({OPUS: testkit.model_usage(input=1, output=1, cost=1.0),
                      SONNET: testkit.model_usage(input=1, output=1, cost=1.0),
                      testkit.HAIKU: testkit.model_usage(input=1, output=1, cost=0.1)}, output=1)
        testkit.put_transcript(home, 'T-1', 1, first.lines)
        testkit.put_transcript(home, 'T-1', 2, Transcript().init(OPUS, '2.2.0').message(input=1).lines)
        record = self.record('demo', home=home)
        self.assertEqual(record['versions']['cli'], [{'version': '2.1.1', 'attempts': 1},
                                                     {'version': '2.2.0', 'attempts': 1}])
        self.assertEqual(record['versions']['models'], [{'model': OPUS, 'attempts': 2},
                                                        {'model': SONNET, 'attempts': 1}])

    def test_a_version_that_is_not_an_identifier_is_not_stored(self):
        t = Transcript().init(OPUS, 'see /Users/someone/x')
        t.message(input=1)
        record = self.attempt_record(t)
        self.assertEqual(record['versions']['cli'], [])
        self.assertNotIn('/Users/', json.dumps(record))


if __name__ == '__main__':
    unittest.main()

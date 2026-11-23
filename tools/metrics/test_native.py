"""Tests of the native source of metrics.py: the run file (`start`, `done`), the choice of the source in `epic` and the
record built from the transcripts of Claude Code sessions (docs/features/ash-v1/spec.md, M1-M3, M7, D1-D4).
python3 -m unittest discover -s tools/metrics -p 'test_*.py'"""
import collections
import json
import os
import re
import unittest
from unittest import mock

import metrics
import testkit
from testkit import FABLE, OPUS, SONNET, Native, NativeEnv, iso_at, run_cli

HERE = os.path.dirname(os.path.abspath(__file__))
WORKTREE = '/work/shop/.claude/worktrees/epic-demo'
SHA = 'c' * 40
OLD_SHA = 'd' * 40
PLAN = 'text\n```json\n{"schema_version": 1, "tasks": [{"id": "DEMO-1"}, {"id": "DEMO-2"}, {"id": "DEMO-3"}]}\n```\n'
USAGE = {'status': 'ok', 'windows': [{'label': 'Weekly · all models', 'percentUsed': 41,
                                      'resetsAt': '2026-11-20T10:00:00Z'}]}
MILLION = 1000000


class Git:
    """A stub of the command runner for `start`: git in the working directory and `gh pr view <branch>`."""

    def __init__(self, plan=PLAN, branch='feat/demo', pr=752):
        self.plan, self.branch, self.pr = plan, branch, pr
        self.calls = []

    def __call__(self, command):
        self.calls.append(list(command))
        if command[:3] == ['git', '-C', WORKTREE]:
            rest = command[3:]
            if rest == ['rev-parse', 'origin/main']:
                return testkit.result(0, SHA + '\n')
            if rest[:3] == ['rev-list', '-1', '--first-parent'] and rest[-1] == 'origin/main':
                return testkit.result(0, OLD_SHA + '\n')
            if rest == ['rev-parse', '--abbrev-ref', 'HEAD']:
                return testkit.result(0, self.branch + '\n')
            if rest == ['show', 'origin/main:docs/features/demo/plan.md'] and self.plan is not None:
                return testkit.result(0, self.plan)
            return testkit.result(128, '', 'fatal: path does not exist')
        if command[:3] == ['gh', 'pr', 'view'] and self.pr is not None:
            return testkit.result(0, json.dumps({'number': self.pr}))
        return testkit.result(1, '', 'unexpected command %s' % command)


class StartDoneTest(NativeEnv):
    """M1: the run file <state>/runs/<EPIC>.json written by `start` and `done`."""

    def run_file(self, epic='demo'):
        with open(os.path.join(self.state, 'runs', '%s.json' % epic), encoding='utf-8') as f:
            return json.load(f)

    def start(self, *extra, runner=None, stdin='', epic='demo', now=testkit.T0):
        # the stub runner has no repository: the check of the process version fails and is accepted here (its own
        # tests are in test_process_version.py)
        return run_cli(['start', epic, '--state', self.state, '--projects', self.projects,
                        '--accept-process-mismatch', 'test bez repozytorium'] + list(extra),
                       runner or Git(), now=now, stdin=stdin)

    def test_start_after_the_fact_writes_the_run_file_without_plan_md(self):
        runner = Git(plan=None)
        code, out, err = self.start('--at', '2026-11-18T17:36:00+01:00', '--worktree', WORKTREE, '--branch',
                                    'feat/small-fixes', '--pr', '752', '--tasks', '8', '--session', 'ee559c2a-5ffa',
                                    runner=runner)
        self.assertEqual(code, 0, err)
        run = self.run_file()
        self.assertEqual(json.loads(out), run)
        self.assertEqual(run['epic'], 'demo')
        self.assertEqual(run['started_at'], '2026-11-18T16:36:00Z')
        self.assertEqual(run['base_sha'], OLD_SHA)          # origin/main at that time, not now
        self.assertEqual(run['branch'], 'feat/small-fixes')
        self.assertEqual(run['pr'], 752)
        self.assertEqual(run['tasks'], 8)
        self.assertEqual(run['sessions'], [{'id': 'ee559c2a-5ffa', 'worktree': WORKTREE,
                                            'at': '2026-11-18T16:36:00Z'}])
        self.assertIsNone(run['usage_start'])
        self.assertIsNone(run['done_at'])
        self.assertIsNone(run['usage_done'])
        before = [c for c in runner.calls if 'rev-list' in c][0]
        self.assertIn('--before=2026-11-18T16:36:00Z', before)
        self.assertFalse(any('show' in c for c in runner.calls), runner.calls)

    def test_start_reads_session_manifest_branch_pr_and_usage(self):
        self.write_session('older', Native('older').assistant(0), worktree=WORKTREE, mtime=testkit.T0 - 100)
        self.write_session('newest', Native('newest').assistant(0), worktree=WORKTREE, mtime=testkit.T0)
        self.write_session('elsewhere', Native('elsewhere').assistant(0), worktree='/other', mtime=testkit.T0 + 50)
        code, out, err = self.start('--worktree', WORKTREE, stdin=json.dumps(USAGE))
        self.assertEqual(code, 0, err)
        run = self.run_file()
        self.assertEqual(run['started_at'], iso_at(0))
        self.assertEqual(run['base_sha'], SHA)
        self.assertEqual(run['branch'], 'feat/demo')
        self.assertEqual(run['pr'], 752)
        self.assertEqual(run['tasks'], 3)
        self.assertEqual(run['sessions'], [{'id': 'newest', 'worktree': WORKTREE, 'at': iso_at(0)}])
        self.assertEqual(run['usage_start'], USAGE)

    def test_start_defaults_to_the_current_directory(self):
        self.write_session('mine', Native('mine').assistant(0), worktree=WORKTREE)
        with mock.patch('os.getcwd', return_value=WORKTREE):
            code, out, err = self.start()
        self.assertEqual(code, 0, err)
        self.assertEqual(self.run_file()['sessions'][0]['worktree'], WORKTREE)

    def test_unreadable_usage_is_stored_as_unreadable(self):
        code, out, err = self.start('--worktree', WORKTREE, '--session', 's1', stdin='not json')
        self.assertEqual(code, 0, err)
        self.assertEqual(self.run_file()['usage_start'], {'status': 'unreadable'})

    def test_start_without_plan_md_and_without_tasks_fails(self):
        code, out, err = self.start('--worktree', WORKTREE, '--session', 's1', runner=Git(plan=None))
        self.assertEqual(code, 2)
        self.assertEqual(out, '')
        self.assertIn('plan.md', err)
        self.assertIn('--tasks', err)
        self.assertFalse(os.path.exists(os.path.join(self.state, 'runs', 'demo.json')))

    def test_start_with_a_bad_manifest_fails(self):
        code, out, err = self.start('--worktree', WORKTREE, '--session', 's1', runner=Git(plan='no manifest'))
        self.assertEqual(code, 2)
        self.assertIn('--tasks', err)

    def test_start_without_a_transcript_and_without_session_fails(self):
        code, out, err = self.start('--worktree', WORKTREE)
        self.assertEqual(code, 2)
        self.assertIn('--session', err)
        self.assertFalse(os.path.exists(os.path.join(self.state, 'runs', 'demo.json')))

    def test_start_without_a_pull_request_fails(self):
        code, out, err = self.start('--worktree', WORKTREE, '--session', 's1', runner=Git(pr=None))
        self.assertEqual(code, 2)
        self.assertIn('--pr', err)

    def test_second_start_without_resume_fails_and_keeps_the_file(self):
        self.assertEqual(self.start('--worktree', WORKTREE, '--session', 's1')[0], 0)
        before = self.run_file()
        code, out, err = self.start('--worktree', WORKTREE, '--session', 's2', now=testkit.T0 + 60)
        self.assertEqual(code, 2)
        self.assertIn('--resume', err)
        self.assertEqual(self.run_file(), before)

    def test_resume_appends_the_new_session_once(self):
        self.assertEqual(self.start('--worktree', WORKTREE, '--session', 's1')[0], 0)
        for _ in range(2):
            code, out, err = self.start('--resume', '--worktree', '/work/other', '--session', 's2',
                                        now=testkit.T0 + 600)
            self.assertEqual(code, 0, err)
        run = self.run_file()
        self.assertEqual(run['started_at'], iso_at(0))
        self.assertEqual(run['sessions'], [{'id': 's1', 'worktree': WORKTREE, 'at': iso_at(0)},
                                           {'id': 's2', 'worktree': '/work/other', 'at': iso_at(600)}])

    def test_resume_without_a_run_file_fails(self):
        code, out, err = self.start('--resume', '--worktree', WORKTREE, '--session', 's2')
        self.assertEqual(code, 2)
        self.assertIn('pliku przebiegu', err)

    def test_done_records_the_ready_time_and_the_second_reading(self):
        self.assertEqual(self.start('--worktree', WORKTREE, '--session', 's1', stdin=json.dumps(USAGE))[0], 0)
        later = dict(USAGE, windows=[dict(USAGE['windows'][0], percentUsed=47)])
        code, out, err = run_cli(['done', 'demo', '--state', self.state], now=testkit.T0 + 3600,
                                 stdin=json.dumps(later))
        self.assertEqual(code, 0, err)
        run = self.run_file()
        self.assertEqual(run['done_at'], iso_at(3600))
        self.assertEqual(run['usage_done'], later)
        self.assertEqual(run['usage_start'], USAGE)

    def test_done_with_at_and_without_reading(self):
        self.write_run(done=None)
        code, out, err = run_cli(['done', 'demo', '--state', self.state, '--at', iso_at(7200)])
        self.assertEqual(code, 0, err)
        self.assertEqual(self.run_file()['done_at'], iso_at(7200))
        self.assertIsNone(self.run_file()['usage_done'])

    def test_done_without_a_run_file_fails(self):
        code, out, err = run_cli(['done', 'demo', '--state', self.state])
        self.assertEqual(code, 2)
        self.assertEqual(out, '')
        self.assertIn('pliku przebiegu', err)

    def test_an_epic_name_that_retro_would_not_recognise_is_refused_by_start_resume_and_done(self):
        for bad in ('SMALL-FIXES', 'small.fixes', 'small_fixes', 'Demo'):
            with self.subTest(epic=bad):
                for command in (['start', bad, '--projects', self.projects, '--worktree', WORKTREE, '--session', 's1',
                                 '--tasks', '3'],
                                ['start', bad, '--projects', self.projects, '--resume', '--worktree', WORKTREE,
                                 '--session', 's1', '--tasks', '3'],
                                ['done', bad]):
                    code, out, err = run_cli(command + ['--state', self.state], Git())
                    self.assertEqual((code, out), (2, ''), (command, err))
                    self.assertIn('nazwa epiku', err)
                    self.assertIn('^[a-z0-9][a-z0-9-]*$', err)
        self.assertFalse(os.path.isdir(os.path.join(self.state, 'runs')) and os.listdir(
            os.path.join(self.state, 'runs')))

    def test_a_valid_epic_name_is_accepted(self):
        for name in ('small-fixes', 'ash-v1', '2fa'):
            with self.subTest(epic=name):
                code, out, err = self.start('--worktree', WORKTREE, '--session', 's-' + name, '--tasks', '3',
                                            epic=name)     # one session, one epic (E1)
                self.assertEqual(code, 0, err)
                self.assertEqual(self.run_file(name)['epic'], name)

    def test_the_epic_command_keeps_accepting_the_ao_names(self):
        code, out, err = self.native('SMALL.FIXES_1')
        self.assertEqual(code, 2)
        self.assertNotIn('nazwa epiku', err)

    def test_bad_arguments_fail_without_writing(self):
        for extra, epic in ((['--at', '2026-11-18 16:36'], 'demo'), (['--session', '../x'], 'demo'),
                            (['--session', 's1'], '../x'), (['--session', 's1', '--tasks', '0'], 'demo'),
                            (['--session', 's1', '--pr', 'x'], 'demo')):
            code, out, err = self.start('--worktree', WORKTREE, *extra, epic=epic)
            self.assertEqual(code, 2, (extra, epic, err))
            self.assertEqual(out, '')
        self.assertFalse(os.path.isdir(os.path.join(self.state, 'runs')) and os.listdir(
            os.path.join(self.state, 'runs')))


class SourceTest(NativeEnv):
    """M2: the record comes from the AO run `ao-<EPIC>`, else from the run file; without both: exit 2."""

    def test_without_ao_run_and_without_run_file_exit_2_naming_both(self):
        code, out, err = self.native('demo')
        self.assertEqual(code, 2)
        self.assertEqual(out, '')
        self.assertIn('brak katalogu AO', err)
        self.assertIn('pliku przebiegu', err)
        self.assertIn(os.path.join(self.state, 'runs', 'demo.json'), err)
        self.assertFalse(os.path.exists(os.path.join(self.state, 'epics', 'demo.json')))

    def test_ao_run_gives_an_ao_record(self):
        testkit.synthetic_home(os.path.join(self.root, 'ao'), epic='demo')
        self.write_run()
        self.assertEqual(self.native_record()['source'], 'ao')

    def test_run_file_gives_a_native_record(self):
        self.write_run()
        self.write_session('s1', Native().assistant(10, input=5))
        record = self.native_record()
        self.assertEqual(record['source'], 'native')
        with open(os.path.join(self.state, 'epics', 'demo.json'), encoding='utf-8') as f:
            self.assertEqual(json.load(f), record)

    def test_unreadable_run_file_exit_2(self):
        os.makedirs(os.path.join(self.state, 'runs'))
        with open(os.path.join(self.state, 'runs', 'demo.json'), 'w') as f:
            f.write('{not json')
        code, out, err = self.native('demo')
        self.assertEqual(code, 2)
        self.assertIn('pliku przebiegu', err)


# (agent id, agentType, description, model of meta.json, effort, model of the messages, task by D4)
SMALL_FIXES = [('ad%d' % n, 'developer', 'SMALL-FIXES-%d TAJNY-OPIS zadania' % n, 'opus' if n == 1 else 'sonnet',
                'high', OPUS if n == 1 else SONNET, 'SMALL-FIXES-%d' % n) for n in range(1, 9)]
SMALL_FIXES += [('ar%d' % n, 'code-reviewer', 'Review SMALL-FIXES-%d FULL' % n, 'opus', 'high', OPUS,
                 'SMALL-FIXES-%d' % n) for n in (1, 4, 5, 7, 8)]
SMALL_FIXES += [('aux', 'ux-reviewer', 'Przegląd UX całej zmiany', 'sonnet', 'high', SONNET, None),
                ('aqa', 'qa-adversarial', 'QA całej zmiany SMALL-FIXES', 'sonnet', 'high', SONNET, None),
                ('agp', 'general-purpose', 'Dane porównawcze epików AO', 'sonnet', 'medium', SONNET, None)]


class NativeRecordTest(NativeEnv):
    """M3, D2, D4: the record of a synthetic run in the shape of SMALL-FIXES."""

    def small_fixes(self):
        self.write_run('small-fixes', sessions=('lead',), tasks=8, done=9000)
        lead = Native('lead').human(5).assistant(10, input=100, output=50).bash('make test', 20, 80, input=10)
        self.write_session('lead', lead)
        for index, (agent, kind, description, model, effort, used, _) in enumerate(SMALL_FIXES):
            start = 100 + index * 300
            transcript = Native('lead', agent).assistant(start, model=used, input=1000, output=10).assistant(
                start + 120, model=used, cache_read=MILLION, output=20)
            self.write_subagent('lead', agent, transcript, {'agentType': kind, 'description': description,
                                                            'model': model, 'effort': effort, 'finished': True,
                                                            'worktreePath': '/Users/someone/secret'})
        return self.native_record('small-fixes')

    def test_record_in_the_shape_of_small_fixes(self):
        record = self.small_fixes()
        self.assertEqual(record['source'], 'native')
        self.assertEqual(record['epic'], 'small-fixes')
        self.assertEqual(record['tasks'], 8)
        self.assertEqual(record['final_pr'], 752)
        self.assertEqual(record['started_at'], iso_at(0))
        self.assertEqual(record['done_at'], iso_at(9000))
        self.assertEqual(record['wall']['to_done'], 9000)
        self.assertEqual(record['sessions'], ['lead'])
        attempts = record['attempts']
        self.assertEqual(len(attempts), 16)
        self.assertEqual(collections.Counter(a['role'] for a in attempts),
                         {'developer': 8, 'code-reviewer': 5, 'ux-reviewer': 1, 'qa-adversarial': 1,
                          'general-purpose': 1})
        by_agent = {a['agent_id']: a for a in attempts}
        for agent, kind, _, model, effort, used, task in SMALL_FIXES:
            attempt = by_agent[agent]
            self.assertEqual(attempt['role'], kind)
            self.assertEqual(attempt['model'], used)
            self.assertIn(model, attempt['model'])          # the model of meta.json (an alias) is the one used
            self.assertEqual(attempt['effort'], effort)
            self.assertEqual(attempt['task'], task)
            self.assertTrue(attempt['finished'])
            self.assertEqual(attempt['wall_s'], 120)
            self.assertTrue(attempt['estimated'])
            self.assertIsNotNone(attempt['value'])
            self.assertEqual(attempt['tokens'], {'input': 1000, 'output': 30, 'cache_read': MILLION,
                                                 'cache_write': 0})

    def test_value_is_an_estimate_by_role_task_and_model(self):
        record = self.small_fixes()
        value = record['value']
        self.assertTrue(value['total']['estimated'])
        self.assertIsNotNone(value['total']['usd'])
        self.assertNotIn('incomplete', value['total'])
        self.assertEqual(set(value['by_role']), {'orchestrator', 'developer', 'code-reviewer', 'ux-reviewer',
                                                 'qa-adversarial', 'general-purpose'})
        self.assertEqual(set(value['by_model']), {FABLE, OPUS, SONNET})
        self.assertIn(metrics.NO_TASK, value['by_task'])
        self.assertEqual(set(value['by_task_role']['SMALL-FIXES-4']), {'developer', 'code-reviewer'})
        self.assertTrue(all(cell['estimated'] for cell in value['by_role'].values()))
        self.assertEqual(record['by_task']['SMALL-FIXES-4']['attempts'], 2)
        self.assertEqual(record['by_task']['SMALL-FIXES-4']['work'], 240)
        self.assertEqual(record['by_task']['SMALL-FIXES-2']['attempts'], 1)
        self.assertEqual(record['by_task'][metrics.NO_TASK]['attempts'], 3)
        self.assertEqual(record['pricing']['read_at'], '2026-11-19')
        self.assertTrue(record['pricing']['source'].startswith('https://'))

    def test_shell_commands_and_versions_come_from_the_transcripts(self):
        record = self.small_fixes()
        self.assertEqual(record['in_attempts']['categories']['test']['count'], 1)
        self.assertEqual(record['in_attempts']['categories']['test']['seconds'], 60)
        self.assertEqual(record['versions']['cli'], [{'version': testkit.CLI_VERSION, 'attempts': 17}])
        self.assertEqual({m['model'] for m in record['versions']['models']}, {FABLE, OPUS, SONNET})

    def test_fields_without_a_native_source_are_null_with_a_gap(self):
        record = self.small_fixes()
        for field in ('parallel', 'gate_wait', 'waste'):
            self.assertIsNone(record[field], field)
            self.assertTrue(any(g.startswith(field + ':') for g in record['gaps']), (field, record['gaps']))
        for field in ('breakdown', 'human', 'integration', 'retries', 'reviews'):
            self.assertIsNotNone(record[field], field)       # test_native_process.py: their native source
        self.assertFalse(any('nie liczone dla przebiegu natywnego' in g for g in record['gaps']), record['gaps'])

    def test_record_holds_no_text_of_messages_orders_or_paths(self):
        record = self.small_fixes()
        text = json.dumps(record, ensure_ascii=False)
        for secret in ('TAJNA-TRESC', 'TAJNE-POLECENIE', 'TAJNY-OPIS', 'TAJNY-WYNIK', 'make test', '/Users/',
                       'Przegląd UX', WORKTREE):
            self.assertNotIn(secret, text)

    def test_ci_of_the_branch_of_the_run_file(self):
        self.write_run(branch='feat/demo', pr=752)
        self.write_session('s1', Native().assistant(10, input=5))
        gh = testkit.FakeGh(prs={752: testkit.merged_pr(merged_at=iso_at(5000))},
                            runs={'feat/demo': [{'databaseId': 9, 'createdAt': iso_at(4000), 'conclusion': 'success'}]},
                            jobs={9: [{'name': 'checks', 'startedAt': iso_at(4000), 'completedAt': iso_at(4300)}]})
        record = self.native_record(runner=gh, no_gh=False)
        self.assertEqual(record['ci']['final'], {'pr': 752, 'runs': 1, 'checks_s': 300, 'e2e_s': None})
        self.assertIsNone(record['ci']['tasks'])
        self.assertEqual(record['ci']['time']['status'], 'no_data')       # no comparable run of main in the stub
        self.assertEqual(record['merged_at'], iso_at(5000))
        self.assertEqual(record['issue'], 496)
        self.assertEqual(record['wall']['to_merge'], 5000)
        self.assertEqual(record['wall']['review'], 1000)
        self.assertEqual(record['regressions']['window_days'], 14)
        self.assertEqual(record['found_after_merge'], {'issues': [], 'count': 0})


class WindowTest(NativeEnv):
    """M3 and criterion 12: the sessions of the run file only, every message once, from the start to the merge."""

    def test_resumed_session_counts_every_message_once_and_other_sessions_never(self):
        self.write_run(sessions=('s1', 's2'), started=0, done=4000)
        first = Native('s1').assistant(-100, input=1).assistant(100, message_id='m1', input=10).assistant(
            200, message_id='m2', input=100)
        resumed = Native('s2').assistant(100, message_id='m1', input=10).assistant(
            200, message_id='m2', input=100).assistant(300, input=1000).assistant(6000, input=100000)
        self.write_session('s1', first, mtime=testkit.T0)
        self.write_session('s2', resumed, mtime=testkit.T0 + 10)
        self.write_session('s3', Native('s3').assistant(250, input=10000), mtime=testkit.T0 + 20)
        self.write_subagent('s3', 'aother', Native('s3', 'aother').assistant(250, input=20000),
                            {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'opus', 'effort': 'high'})
        self.write_subagent('s1', 'alate', Native('s1', 'alate').assistant(5500, model=OPUS, input=30000),
                            {'agentType': 'developer', 'description': 'DEMO-2', 'model': 'opus', 'effort': 'high'})
        gh = testkit.FakeGh(prs={752: testkit.merged_pr(merged_at=iso_at(5000))})
        record = self.native_record(runner=gh, no_gh=False)
        self.assertEqual(record['value']['total']['tokens']['input'], 1110)
        self.assertEqual(record['sessions'], ['s1', 's2'])
        self.assertEqual(record['attempts'], [])            # the subagent of s1 worked only after the merge

    def test_messages_before_the_start_do_not_count_without_gh(self):
        self.write_run(started=0)
        self.write_session('s1', Native('s1').assistant(-10, input=7).assistant(10, input=3))
        record = self.native_record()
        self.assertEqual(record['value']['total']['tokens']['input'], 3)
        self.assertTrue(any(g.startswith('merged_at') for g in record['gaps']), record['gaps'])


class PricingTest(NativeEnv):
    """D3: the value from the rates of config.json, every kind of tokens with its own rate."""

    def total_of(self, model=OPUS, **tokens):
        self.write_run()
        self.write_session('s1', Native().assistant(10, model=model, **tokens))
        return self.native_record()['value']['total']

    def config(self, pricing):
        with open(metrics.DEFAULT_CONFIG, encoding='utf-8') as f:
            data = json.load(f)
        data['pricing'] = pricing
        path = os.path.join(self.root, 'config.json')
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(data, f)
        return path

    def test_every_kind_of_tokens_has_its_own_rate(self):
        total = self.total_of(FABLE, input=MILLION, output=MILLION, cache_read=MILLION, cache_write=MILLION,
                              cache_write_1h=MILLION)
        self.assertAlmostEqual(total['usd'], 8 + 40 + 0.2 + 10 + 16)
        self.assertTrue(total['estimated'])
        self.assertEqual(total['tokens'], {'input': MILLION, 'output': MILLION, 'cache_read': MILLION,
                                           'cache_write': 2 * MILLION})

    def test_five_minute_and_one_hour_cache_writes_differ(self):
        self.assertAlmostEqual(self.total_of(OPUS, cache_write=MILLION)['usd'], 5.0)

    def test_one_hour_cache_write(self):
        self.assertAlmostEqual(self.total_of(OPUS, cache_write_1h=MILLION)['usd'], 8.0)

    def test_sonnet_rates(self):
        self.assertAlmostEqual(self.total_of(SONNET, input=MILLION, output=MILLION, cache_read=MILLION,
                                             cache_write=MILLION, cache_write_1h=MILLION)['usd'], 2 + 10 + 0.1 + 2.5 + 4)

    def test_model_without_rates_is_null_with_a_gap(self):
        self.write_run()
        self.write_session('s1', Native().assistant(10, model='claude-new-9', input=MILLION).assistant(20, input=MILLION))
        code, out, err = self.native()
        self.assertEqual(code, 0, err)
        record = json.loads(out)
        self.assertIsNone(record['value']['by_model']['claude-new-9']['usd'])
        self.assertTrue(record['value']['total']['incomplete'])
        self.assertAlmostEqual(record['value']['total']['usd'], 8.0)        # the known part
        self.assertTrue(any('claude-new-9' in g for g in record['gaps']), record['gaps'])

    def test_kind_without_a_rate_is_null_only_when_used(self):
        pricing = json.loads(json.dumps(metrics.load_config(metrics.DEFAULT_CONFIG)['pricing']))
        del pricing['models'][OPUS]['cache_write_1h']
        path = self.config(pricing)
        self.write_run()
        self.write_session('s1', Native().assistant(10, model=OPUS, input=MILLION))
        self.assertAlmostEqual(self.native_record(config=path)['value']['total']['usd'], 4.0)
        self.write_session('s1', Native().assistant(10, model=OPUS, input=MILLION, cache_write_1h=10))
        record = self.native_record(config=path)
        self.assertIsNone(record['value']['total']['usd'])
        self.assertTrue(any('cache_write_1h' in g for g in record['gaps']), record['gaps'])

    def test_message_without_tokens_needs_no_rate(self):
        self.write_run()
        self.write_session('s1', Native().assistant(10, model='<synthetic>').assistant(20, input=MILLION))
        record = self.native_record()
        self.assertAlmostEqual(record['value']['total']['usd'], 8.0)
        self.assertNotIn('incomplete', record['value']['total'])
        self.assertNotIn('<synthetic>', json.dumps(record))


class MissingFieldsTest(NativeEnv):
    """M7 and criterion 4: a missing field of a transcript is null and a gap, never an error."""

    def single(self, transcript, meta=None, agent='a1'):
        self.write_run()
        self.write_session('s1', Native().assistant(5, input=1))
        self.write_subagent('s1', agent, transcript, meta)
        code, out, err = self.native()
        self.assertEqual(code, 0, err)
        return json.loads(out)

    def test_message_without_usage(self):
        record = self.single(Native('s1', 'a1').assistant(10, usage=False).assistant(20, model=OPUS, input=5),
                             {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'opus', 'effort': 'high'})
        attempt = record['attempts'][0]
        self.assertIsNone(attempt['value'])
        self.assertIsNone(attempt['tokens'])
        self.assertTrue(record['value']['total']['incomplete'])
        self.assertTrue(any('usage' in g for g in record['gaps']), record['gaps'])

    def test_subagent_without_meta_json(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=OPUS, input=5))
        attempt = record['attempts'][0]
        self.assertEqual(attempt['agent_id'], 'a1')
        self.assertIsNone(attempt['role'])
        self.assertIsNone(attempt['task'])
        self.assertIsNone(attempt['effort'])
        self.assertIsNone(attempt['finished'])
        self.assertEqual(attempt['model'], OPUS)
        self.assertIn(metrics.SUBAGENT, record['value']['by_role'])
        self.assertTrue(any('meta.json' in g for g in record['gaps']), record['gaps'])

    def test_unreadable_meta_json(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=OPUS, input=5), '{not json')
        self.assertIsNone(record['attempts'][0]['role'])
        self.assertTrue(any('meta.json' in g for g in record['gaps']), record['gaps'])

    def test_meta_json_without_some_fields(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=OPUS, input=5), {'agentType': 'developer'})
        attempt = record['attempts'][0]
        self.assertEqual(attempt['role'], 'developer')
        self.assertIsNone(attempt['task'])
        self.assertIsNone(attempt['effort'])
        self.assertTrue(any('description' in g for g in record['gaps']), record['gaps'])

    def test_a_meta_json_without_model_is_no_gap_because_the_model_comes_from_the_messages(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=OPUS, input=5),
                             {'agentType': 'developer', 'description': 'DEMO-1', 'effort': 'high', 'finished': True})
        self.assertEqual(record['attempts'][0]['model'], OPUS)
        self.assertFalse(any('meta.json bez model' in g for g in record['gaps']), record['gaps'])

    def test_the_model_of_meta_json_is_not_used(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=OPUS, input=5),
                             {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'sonnet', 'effort': 'high',
                              'finished': True})
        self.assertEqual(record['attempts'][0]['model'], OPUS)
        self.assertFalse(any(g.startswith('attempts.a1') for g in record['gaps']), record['gaps'])

    def test_a_run_without_the_model_in_every_message_has_a_null_model_and_a_gap(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=None).assistant(20, model=None),
                             {'agentType': 'developer', 'description': 'DEMO-1', 'effort': 'high', 'finished': True})
        self.assertIsNone(record['attempts'][0]['model'])
        self.assertTrue(any('attempts.a1' in g and 'message.model' in g for g in record['gaps']), record['gaps'])

    def test_a_meta_json_without_effort_is_still_a_null_effort_and_a_gap(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=OPUS, input=5),
                             {'agentType': 'developer', 'description': 'DEMO-1', 'finished': True})
        self.assertIsNone(record['attempts'][0]['effort'])
        self.assertTrue(any('meta.json bez effort' in g for g in record['gaps']), record['gaps'])

    def test_unknown_model(self):
        record = self.single(Native('s1', 'a1').assistant(10, model='claude-new-9', input=5),
                             {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'new', 'effort': 'high'})
        self.assertIsNone(record['attempts'][0]['value'])
        self.assertEqual(record['attempts'][0]['model'], 'claude-new-9')
        self.assertTrue(any('claude-new-9' in g for g in record['gaps']), record['gaps'])

    def test_message_without_model(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=None, input=5),
                             {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'opus', 'effort': 'high'})
        self.assertIsNone(record['attempts'][0]['value'])
        self.assertTrue(any('model' in g for g in record['gaps']), record['gaps'])

    def test_cache_write_without_its_lifetime(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=OPUS, cache_write=500, split=False),
                             {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'opus', 'effort': 'high'})
        self.assertIsNone(record['attempts'][0]['value'])
        self.assertTrue(any('cache_creation' in g for g in record['gaps']), record['gaps'])

    def test_no_cache_write_needs_no_lifetime(self):
        record = self.single(Native('s1', 'a1').assistant(10, model=OPUS, input=MILLION, split=False),
                             {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'opus', 'effort': 'high'})
        self.assertAlmostEqual(record['attempts'][0]['value'], 4.0)

    def test_message_without_timestamp_counts_with_a_gap(self):
        record = self.single(Native('s1', 'a1').assistant(None, model=OPUS, input=MILLION).assistant(
            10, model=OPUS, input=MILLION),
            {'agentType': 'developer', 'description': 'DEMO-1', 'model': 'opus', 'effort': 'high'})
        self.assertAlmostEqual(record['attempts'][0]['value'], 8.0)
        self.assertTrue(any('timestamp' in g for g in record['gaps']), record['gaps'])

    def test_missing_session_transcript(self):
        self.write_run(sessions=('s1', 'gone'))
        self.write_session('s1', Native().assistant(5, input=1))
        record = self.native_record()
        self.assertEqual(record['sessions'], ['s1', 'gone'])
        self.assertTrue(any('gone' in g for g in record['gaps']), record['gaps'])

    def test_run_file_without_done_and_tasks(self):
        self.write_run(done=None, tasks=None)
        self.write_session('s1', Native().assistant(5, input=1))
        record = self.native_record()
        self.assertIsNone(record['done_at'])
        self.assertIsNone(record['tasks'])
        self.assertIsNone(record['wall']['to_done'])
        self.assertTrue(any(g.startswith('done_at') for g in record['gaps']), record['gaps'])
        self.assertTrue(any(g.startswith('tasks') for g in record['gaps']), record['gaps'])

    def test_lines_that_are_not_json_are_skipped(self):
        self.write_run()
        self.write_session('s1', [json.dumps(Native().assistant(5, model=OPUS, input=MILLION).lines[0]), '{cut'])
        self.assertAlmostEqual(self.native_record()['value']['total']['usd'], 4.0)

    def test_skipped_lines_are_a_gap_with_their_number(self):
        self.write_run()
        good = Native().assistant(5, model=OPUS, input=MILLION).lines[0]
        self.write_session('s1', [json.dumps(good), '{cut', '', '[1, 2]', '"text"'])
        record = self.native_record()
        self.assertAlmostEqual(record['value']['total']['usd'], 4.0)
        skipped = [g for g in record['gaps'] if g.startswith('sessions') and 'sesja s1' in g]
        self.assertEqual(len(skipped), 1, record['gaps'])
        self.assertIn('(3)', skipped[0])
        self.assertFalse(any('dolna granica' in g for g in record['gaps']), record['gaps'])

    def test_lead_transcript_without_any_json_line_is_unreadable(self):
        self.write_run()
        self.write_session('s1', ['{cut', 'not json', '[1]'])
        record = self.native_record()
        self.assertIsNone(record['breakdown'])
        self.assertIsNone(record['human']['interventions'])
        self.assertIsNone(record['value']['total'])
        self.assertTrue(any('sesja s1' in g for g in record['gaps'] if g.startswith('sessions')), record['gaps'])

    def test_subagent_transcript_without_any_json_line_is_lost(self):
        record = self.single(['{cut', 'not json'], {'agentType': 'developer', 'description': 'DEMO-1',
                                                    'effort': 'high', 'finished': True})
        self.assertEqual(record['attempts'], [])
        self.assertTrue(any('agent a1' in g for g in record['gaps'] if g.startswith('sessions')), record['gaps'])
        self.assertTrue(any('dolna granica' in g for g in record['gaps'] if g.startswith('value')), record['gaps'])


class PricingConfigTest(unittest.TestCase):
    """D3: the shipped rates of every model of the SMALL-FIXES transcripts and of the agent definitions."""

    @classmethod
    def setUpClass(cls):
        cls.pricing = metrics.load_config(metrics.DEFAULT_CONFIG)['pricing']

    def test_rates_of_the_models_with_source_and_date(self):
        self.assertEqual(self.pricing['source'], 'https://platform.claude.com/docs/en/about-claude/pricing')
        self.assertEqual(self.pricing['read_at'], '2026-11-19')
        expected = {FABLE: (8, 40, 0.2, 10, 16), OPUS: (4, 20, 0.2, 5, 8), SONNET: (2, 10, 0.1, 2.5, 4)}
        for model, rates in expected.items():
            self.assertEqual(self.pricing['models'][model], dict(zip(metrics.PRICE_KINDS, rates)), model)

    def test_every_agent_definition_model_has_rates(self):
        agents = os.path.join(HERE, '..', '..', '.claude', 'agents')
        aliases = set()
        for name in os.listdir(agents):
            with open(os.path.join(agents, name), encoding='utf-8') as f:
                found = re.search(r'^model:\s*(\S+)', f.read(), re.MULTILINE)
            if found:
                aliases.add(found.group(1))
        self.assertTrue(aliases)
        for alias in aliases:
            priced = [m for m, rates in self.pricing['models'].items()
                      if alias in m and set(rates) == set(metrics.PRICE_KINDS)]
            self.assertTrue(priced, alias)


class PricingConfigErrorTest(NativeEnv):
    def test_bad_pricing_exit_2_with_the_key(self):
        with open(metrics.DEFAULT_CONFIG, encoding='utf-8') as f:
            data = json.load(f)
        for bad in ({'source': 'x', 'read_at': '2026-11-19', 'models': {OPUS: {'input': -1}}},
                    {'source': 'x', 'read_at': '2026-11-19', 'models': {OPUS: {'input': '4'}}},
                    {'source': 'x', 'read_at': '2026-11-19', 'models': {OPUS: {'tokens': 4}}},
                    {'read_at': '2026-11-19', 'models': {}}, 'x'):
            data['pricing'] = bad
            path = os.path.join(self.root, 'config.json')
            with open(path, 'w', encoding='utf-8') as f:
                json.dump(data, f)
            self.write_run()
            code, out, err = self.native(config=path)
            self.assertEqual(code, 2, bad)
            self.assertIn('pricing', err)


if __name__ == '__main__':
    unittest.main()

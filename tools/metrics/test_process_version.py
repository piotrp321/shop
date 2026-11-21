"""Tests of the process version of a native epic (docs/features/metrics/spec.md, "Wersja procesu"; spec
docs/features/ash-process-fixes/spec.md, W1-W4, E1): the three levels of `process` (`expected`, `disk`, `loaded`),
the command `process` with and without an epic, the check in `start`, `start --resume` and `done`, and the field
`process` of the record. Every test builds its own git repository (a main checkout and a linked worktree) and its own
transcripts in a temporary directory; nothing reads the real state or the real checkout.
python3 -m unittest discover -s tools/metrics -p 'test_*.py'"""
import json
import os
import subprocess
import sys
import unittest
from unittest import mock

import metrics
import testkit
from testkit import Native, NativeEnv, iso_at, run_cli, stamp

AGENT_BODY = 'Jesteś reviewerem kodu. TAJNA-TRESC-ROLI.'
DEV_BODY = 'Jesteś developerem. TAJNA-TRESC-ROLI.'
SKILL_BODY = 'Poprowadź zadanie `$ARGUMENTS` do końca. TAJNA-TRESC-SKILLA.'
ROOT_MD = '# CLAUDE.md\n\nZasady projektu. TAJNA-TRESC-CLAUDE.\n'
API_MD = '# API\n\nZasady API.\n'
ADR = '# Przebieg epiku\n\nKroki.\n'
EXIT_PROCESS = 4


def git(cwd, *args):
    result = subprocess.run(['git', '-C', cwd] + list(args), capture_output=True, text=True)
    if result.returncode != 0:
        raise AssertionError('git %s: %s' % (' '.join(args), result.stderr))
    return result.stdout.strip()


def write(root, path, text):
    full = os.path.join(root, path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, 'w', encoding='utf-8') as f:
        f.write(text)
    return full


def agent_file(name, body):
    return '---\nname: %s\ndescription: rola %s\n---\n\n%s\n' % (name, name, body)


class RepoEnv(NativeEnv):
    """A main checkout `<root>/shop` on `main` with the instruction files, `origin/main` at its commit, and a linked
    worktree `<root>/shop/.claude/worktrees/epic` on `feat/demo`."""

    def setUp(self):
        super().setUp()
        self.main = os.path.realpath(os.path.join(self.root, 'shop'))
        os.makedirs(self.main)
        git(self.main, 'init', '-q', '-b', 'main')
        git(self.main, 'config', 'user.email', 'test@example.com')
        git(self.main, 'config', 'user.name', 'Test')
        files = {'.gitignore': '__pycache__/\n.claude/worktrees/\n', 'CLAUDE.md': ROOT_MD,
                 'apps/api/CLAUDE.md': API_MD, 'docs/adr/agentic-software-house.md': ADR,
                 '.claude/agents/code-reviewer.md': agent_file('code-reviewer', AGENT_BODY),
                 '.claude/agents/developer.md': agent_file('developer', DEV_BODY),
                 '.claude/skills/delivery/SKILL.md': '---\nname: delivery\ndescription: d\n---\n\n%s\n' % SKILL_BODY,
                 '.claude/skills/delivery/helper.py': 'print(1)\n', 'README.md': 'x\n'}
        for path, text in files.items():
            write(self.main, path, text)
        git(self.main, 'add', '-A')
        git(self.main, 'commit', '-q', '-m', 'base')
        git(self.main, 'tag', 'ash-v1.0')
        self.base = git(self.main, 'rev-parse', 'HEAD')
        git(self.main, 'update-ref', 'refs/remotes/origin/main', self.base)
        self.worktree = os.path.join(self.main, '.claude', 'worktrees', 'epic')
        git(self.main, 'worktree', 'add', '-q', '-b', 'feat/demo', self.worktree)
        self.user_dir = os.path.join(self.root, 'user-claude')
        os.makedirs(self.user_dir)

    # --------------------------------------------------------------------------------------------- commands

    def process(self, *extra, worktree=None):
        argv = ['process', '--state', self.state, '--user-dir', self.user_dir, '--worktree', worktree or self.worktree]
        return run_cli(argv + list(extra))

    def process_epic(self, epic='demo', *extra, worktree=None, now=testkit.T0 + 100):
        argv = ['process', epic, '--state', self.state, '--user-dir', self.user_dir, '--projects', self.projects,
                '--worktree', worktree or self.worktree]
        return run_cli(argv + list(extra), now=now)

    def start(self, *extra, epic='demo', session='s1', worktree=None, now=testkit.T0):
        argv = ['start', epic, '--state', self.state, '--projects', self.projects, '--user-dir', self.user_dir,
                '--worktree', worktree or self.worktree, '--branch', 'feat/demo', '--pr', '752', '--tasks', '3',
                '--session', session]
        return run_cli(argv + list(extra), now=now)

    def done(self, *extra, epic='demo', now=testkit.T0 + 4000):
        argv = ['done', epic, '--state', self.state, '--user-dir', self.user_dir, '--projects', self.projects,
                '--worktree', self.worktree]
        return run_cli(argv + list(extra), now=now)

    def run_file(self, epic='demo'):
        with open(os.path.join(self.state, 'runs', '%s.json' % epic), encoding='utf-8') as f:
            return json.load(f)

    def has_run_file(self, epic='demo'):
        return os.path.exists(os.path.join(self.state, 'runs', '%s.json' % epic))

    # ------------------------------------------------------------------------------------------- transcripts

    def instructions_line(self, at=-60, root_md=ROOT_MD, worktree=None, session='s1'):
        base = worktree or self.worktree
        return {'type': 'attachment', 'sessionId': session, 'timestamp': stamp(at),
                'attachment': {'type': 'instructions', 'files': [
                    {'path': os.path.join(self.root, '.claude', 'CLAUDE.md'), 'type': 'User', 'content': 'TAJNE'},
                    {'path': os.path.join(base, 'CLAUDE.md'), 'type': 'Project', 'content': root_md}]}}

    def nested_line(self, at, content=API_MD, base=None):
        path = os.path.join(base or self.worktree, 'apps', 'api', 'CLAUDE.md')
        return {'type': 'attachment', 'timestamp': stamp(at), 'attachment': {
            'type': 'nested_memory', 'path': path, 'content': {'path': path, 'type': 'Project', 'content': content}}}

    def skill_line(self, at, directory, body):
        return {'type': 'user', 'isMeta': True, 'timestamp': stamp(at), 'sessionId': 's1',
                'message': {'role': 'user', 'content': [
                    {'type': 'text', 'text': 'Base directory for this skill: %s\n\n%s' % (directory, body)}]}}

    def lead(self, *lines, session='s1', worktree=None):
        transcript = Native(session).human(-50).assistant(-40, input=5)
        transcript.lines = list(lines) + transcript.lines
        self.write_session(session, transcript, worktree=worktree or self.worktree)

    def subagent_run(self, agent, role, snapshot, at=100, session='s1', meta=True):
        """A subagent transcript: a prompt_snapshot attachment (`snapshot`: its systemPrompt; None: no attachment)
        and two assistant messages."""
        transcript = Native(session, agent).assistant(at, model=testkit.OPUS, input=5).assistant(
            at + 60, model=testkit.OPUS, input=5)
        if snapshot is not None:
            transcript.lines.insert(0, {'type': 'attachment', 'isSidechain': True, 'agentId': agent,
                                        'timestamp': stamp(at - 1),
                                        'attachment': {'type': 'prompt_snapshot', 'systemPrompt': snapshot}})
        self.write_subagent(session, agent, transcript,
                            {'agentType': role, 'description': 'DEMO-1 zadanie', 'finished': True} if meta else None,
                            worktree=self.worktree)


# ------------------------------------------------------------------------------------------------- process (W1)

class ProcessCommandTest(RepoEnv):
    """`metrics.py process` without an epic: the expected version, the disk of every place a session loads the
    definitions from, the user level, `match` and the epics in progress."""

    def reading(self, *extra, worktree=None):
        code, out, err = self.process(*extra, worktree=worktree)
        return code, json.loads(out) if out else None, err

    def test_matching_definitions_exit_0(self):
        code, data, err = self.reading()
        self.assertEqual(code, 0, err)
        self.assertIs(data['match'], True)
        self.assertEqual(data['worktree'], self.worktree)
        self.assertEqual(data['worktree_head'], self.base)
        expected = data['expected']
        self.assertEqual(expected['ref'], self.base)
        self.assertEqual(expected['requested'], 'HEAD')
        self.assertEqual(expected['describe'], 'ash-v1.0')
        self.assertRegex(expected['fingerprint'], r'^[0-9a-f]{16}$')
        self.assertEqual(sorted(expected['files']), [
            '.claude/agents/code-reviewer.md', '.claude/agents/developer.md', '.claude/skills/delivery/SKILL.md',
            'CLAUDE.md', 'apps/api/CLAUDE.md', 'docs/adr/agentic-software-house.md'])
        self.assertEqual(expected['files']['CLAUDE.md'], git(self.main, 'rev-parse', 'HEAD:CLAUDE.md'))
        kinds = [(d['kind'], d['path'], d['head'], d['branch'], d['dirty'], d['differs']) for d in data['disk']]
        self.assertEqual(kinds, [('worktree', self.worktree, self.base, 'feat/demo', False, []),
                                 ('main_checkout', self.main, self.base, 'main', False, [])])
        for place in data['disk']:
            self.assertEqual(place['fingerprint'], expected['fingerprint'])
        self.assertEqual(sorted(data['disk'][0]['files']), sorted(expected['files']))
        self.assertEqual(sorted(data['disk'][1]['files']), [
            '.claude/agents/code-reviewer.md', '.claude/agents/developer.md', '.claude/skills/delivery/SKILL.md'])
        self.assertEqual(data['user_level'], {'agents': [], 'skills': []})
        self.assertEqual(data['active_epics'], [])
        self.assertNotIn('TAJNA', json.dumps(data, ensure_ascii=False))

    def test_changed_agent_in_the_main_checkout_differs_with_its_own_exit_code(self):
        write(self.main, '.claude/agents/code-reviewer.md', agent_file('code-reviewer', 'stara wersja'))
        code, data, err = self.reading()
        self.assertEqual(code, EXIT_PROCESS, err)
        self.assertIs(data['match'], False)
        main = data['disk'][1]
        self.assertEqual(main['kind'], 'main_checkout')
        self.assertEqual(main['differs'], [{'path': '.claude/agents/code-reviewer.md', 'state': 'changed'}])
        self.assertIs(main['dirty'], True)
        self.assertNotEqual(main['fingerprint'], data['expected']['fingerprint'])
        self.assertEqual(data['disk'][0]['differs'], [])
        self.assertIn('main_checkout', err)
        self.assertIn('.claude/agents/code-reviewer.md', err)

    def test_main_checkout_on_an_older_commit_differs(self):
        write(self.worktree, '.claude/skills/delivery/SKILL.md', 'nowa wersja\n')
        git(self.worktree, 'commit', '-q', '-am', 'new skill')
        code, data, err = self.reading()
        self.assertEqual(code, EXIT_PROCESS, err)
        self.assertEqual(data['expected']['ref'], git(self.worktree, 'rev-parse', 'HEAD'))
        self.assertEqual(data['disk'][1]['differs'], [{'path': '.claude/skills/delivery/SKILL.md', 'state': 'changed'}])
        self.assertIs(data['disk'][1]['dirty'], False)

    def test_base_option_is_the_expected_ref(self):
        write(self.worktree, '.claude/agents/developer.md', 'zmiana\n')
        git(self.worktree, 'commit', '-q', '-am', 'dev')
        code, data, err = self.reading('--base', self.base)
        self.assertEqual(data['expected']['ref'], self.base)
        self.assertEqual(data['expected']['requested'], self.base)
        changed = [{'path': '.claude/agents/developer.md', 'state': 'changed'}]
        self.assertEqual(data['disk'][0]['differs'], changed)
        self.assertEqual(data['disk'][0]['epic_branch'], changed)        # committed on the branch: information (D-a)
        self.assertEqual(data['disk'][0]['uncommitted'], [])
        self.assertIs(data['disk'][0]['from_base'], True)
        self.assertEqual(data['disk'][1]['differs'], [])
        self.assertEqual(code, 0, err)

    def test_uncommitted_change_in_the_worktree_is_a_mismatch(self):
        write(self.worktree, 'docs/adr/agentic-software-house.md', 'inny ADR\n')
        code, data, err = self.reading()
        self.assertEqual(code, EXIT_PROCESS)
        self.assertEqual(data['disk'][0]['uncommitted'],
                         [{'path': 'docs/adr/agentic-software-house.md', 'state': 'changed'}])

    def test_deleted_and_extra_files(self):
        os.remove(os.path.join(self.main, '.claude/agents/developer.md'))
        write(self.main, '.claude/skills/new-skill/SKILL.md', 'nowy\n')
        write(self.main, '.claude/skills/delivery/__pycache__/helper.cpython-39.pyc', 'ignored')
        code, data, err = self.reading()
        self.assertEqual(code, EXIT_PROCESS)
        self.assertEqual(data['disk'][1]['differs'], [{'path': '.claude/agents/developer.md', 'state': 'missing'},
                                                      {'path': '.claude/skills/new-skill/SKILL.md', 'state': 'extra'}])

    def test_changed_claude_md_in_the_worktree_differs_but_not_in_the_main_checkout(self):
        write(self.worktree, 'apps/api/CLAUDE.md', 'inne zasady\n')
        write(self.main, 'CLAUDE.md', 'inne zasady w głównym\n')
        code, data, err = self.reading()
        self.assertEqual(code, EXIT_PROCESS)
        self.assertEqual(data['disk'][0]['differs'], [{'path': 'apps/api/CLAUDE.md', 'state': 'changed'}])
        self.assertEqual(data['disk'][1]['differs'], [])              # CLAUDE.md comes from the worktree

    def test_user_skill_with_the_name_of_a_project_skill_is_a_mismatch(self):
        write(self.user_dir, 'skills/delivery/SKILL.md', 'TAJNY-SKILL-UZYTKOWNIKA\n')
        write(self.user_dir, 'agents/developer.md', 'TAJNY-AGENT-UZYTKOWNIKA\n')
        write(self.user_dir, 'agents/other.md', 'x\n')
        code, data, err = self.reading()
        self.assertEqual(code, EXIT_PROCESS)
        self.assertEqual(data['user_level'], {'agents': ['developer'], 'skills': ['delivery']})
        self.assertNotIn('TAJNY', json.dumps(data) + err)

    def test_user_agent_alone_is_information(self):
        write(self.user_dir, 'agents/developer.md', 'x\n')
        code, data, err = self.reading()
        self.assertEqual(code, 0, err)
        self.assertEqual(data['user_level'], {'agents': ['developer'], 'skills': []})

    def test_the_main_checkout_itself_has_one_place(self):
        code, data, err = self.reading(worktree=self.main)
        self.assertEqual(code, 0, err)
        self.assertEqual([d['kind'] for d in data['disk']], ['main_checkout'])
        self.assertEqual(sorted(data['disk'][0]['files']), sorted(data['expected']['files']))

    def test_a_subdirectory_of_the_worktree_counts_as_the_worktree(self):
        code, data, err = self.reading(worktree=os.path.join(self.worktree, 'apps', 'api'))
        self.assertEqual(code, 0, err)
        self.assertEqual(data['worktree'], self.worktree)

    def test_directory_without_git_exit_2(self):
        plain = os.path.join(self.root, 'plain')
        os.makedirs(plain)
        code, out, err = self.process(worktree=plain)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('git', err)

    def test_repository_without_definitions_exit_2(self):
        bare = os.path.join(self.root, 'other')
        os.makedirs(bare)
        git(bare, 'init', '-q', '-b', 'main')
        git(bare, 'config', 'user.email', 't@example.com')
        git(bare, 'config', 'user.name', 'T')
        write(bare, 'README.md', 'x\n')
        git(bare, 'add', '-A')
        git(bare, 'commit', '-q', '-m', 'x')
        code, out, err = self.process(worktree=bare)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('.claude', err)

    def test_unknown_base_exit_2(self):
        code, out, err = self.process('--base', 'no-such-ref')
        self.assertEqual((code, out), (2, ''))
        self.assertIn('no-such-ref', err)

    def test_active_epics_with_their_fingerprint_and_whether_it_is_current(self):
        self.lead(self.instructions_line())
        self.assertEqual(self.start()[0], 0)
        self.lead(self.instructions_line(session='s9'), session='s9')
        self.assertEqual(self.start(epic='merged', session='s9')[0], 0)
        testkit.store_records(self.state, {'epic': 'merged', 'merged_at': '2026-11-10T10:00:00Z'})
        os.makedirs(os.path.join(self.state, 'runs'), exist_ok=True)
        with open(os.path.join(self.state, 'runs', 'old.json'), 'w') as f:
            json.dump({'epic': 'old', 'started_at': iso_at(0), 'sessions': []}, f)
        code, data, err = self.reading()
        self.assertEqual(code, 0, err)
        fingerprint = data['disk'][1]['fingerprint']
        self.assertEqual(data['active_epics'], [
            {'epic': 'demo', 'started_at': iso_at(0), 'done_at': None, 'has_record': False, 'note': 'w toku',
             'fingerprint': fingerprint, 'current': True},
            {'epic': 'old', 'started_at': iso_at(0), 'done_at': None, 'has_record': False, 'note': 'w toku',
             'fingerprint': None, 'current': None}])
        write(self.main, '.claude/agents/developer.md', 'zmiana\n')
        code, data, err = self.reading()
        self.assertEqual(data['active_epics'][0]['current'], False)


# --------------------------------------------------------------------------------------- loaded: the evidence

class LoadedEvidenceTest(RepoEnv):
    """`loaded`: only what a transcript confirms. CLAUDE.md from `instructions` and `nested_memory`, the agent from the
    first block of `prompt_snapshot`, the skill from the text after `Base directory for this skill:`."""

    def started(self, *lines):
        self.lead(*lines)
        code, out, err = self.start()
        self.assertEqual(code, 0, err)
        return self.run_file()['process']

    def test_start_confirms_the_claude_md_of_the_session(self):
        process = self.started(self.instructions_line())
        session = {e['path']: e for e in process['loaded']['session']}
        self.assertEqual(session['CLAUDE.md']['status'], 'confirmed')
        self.assertEqual(session['CLAUDE.md']['origin'], os.path.join(self.worktree, 'CLAUDE.md'))
        self.assertEqual(session['CLAUDE.md']['session'], 's1')
        self.assertEqual(session['apps/api/CLAUDE.md']['status'], 'unconfirmed')
        self.assertTrue(session['apps/api/CLAUDE.md']['reason'])
        self.assertEqual(session['docs/adr/agentic-software-house.md']['status'], 'unconfirmed')
        self.assertEqual([(a['status'], a['reason']) for a in process['loaded']['agents']],
                         [('unconfirmed', 'jeszcze nie uruchomione')])
        self.assertEqual([(s['status'], s['reason']) for s in process['loaded']['skills']],
                         [('unconfirmed', 'jeszcze nie uruchomione')])
        self.assertIs(process['match'], True)
        self.assertNotIn('TAJNA', json.dumps(process, ensure_ascii=False))

    def test_nested_memory_confirms_an_area_claude_md(self):
        process = self.started(self.instructions_line(), self.nested_line(-30))
        session = {e['path']: e['status'] for e in process['loaded']['session']}
        self.assertEqual(session['apps/api/CLAUDE.md'], 'confirmed')

    def test_claude_md_loaded_with_another_content_blocks_the_start(self):
        self.lead(self.instructions_line(root_md='# CLAUDE.md\n\nstare zasady\n'))
        code, out, err = self.start()
        self.assertEqual(code, 2)
        self.assertFalse(self.has_run_file())
        self.assertIn('CLAUDE.md', err)
        self.assertIn('--accept-process-mismatch', err)
        code, out, err = self.start('--accept-process-mismatch', 'stara sesja, świadomie')
        self.assertEqual(code, 0, err)
        process = self.run_file()['process']
        entry = [e for e in process['loaded']['session'] if e['path'] == 'CLAUDE.md'][0]
        self.assertEqual(entry['status'], 'differs')
        self.assertRegex(entry['digest'], r'^[0-9a-f]{16}$')
        self.assertIs(process['match'], False)
        self.assertEqual(process['accepted'], 'stara sesja, świadomie')

    def test_without_a_transcript_the_session_is_unconfirmed_not_confirmed(self):
        code, out, err = self.start()
        self.assertEqual(code, 0, err)
        session = self.run_file()['process']['loaded']['session']
        self.assertTrue(session)
        self.assertTrue(all(e['status'] == 'unconfirmed' and e['reason'] for e in session), session)

    def test_unknown_shapes_are_unconfirmed_without_an_exception(self):
        bad = [{'type': 'attachment', 'attachment': {'type': 'instructions', 'files': 'not a list'}},
               {'type': 'attachment', 'attachment': {'type': 'instructions', 'files': [7, {'path': 3}]}},
               {'type': 'attachment', 'attachment': {'type': 'nested_memory', 'content': 'text'}},
               {'type': 'attachment', 'attachment': 'text'}, {'type': 'attachment'}]
        process = self.started(*bad)
        statuses = {e['path']: e['status'] for e in process['loaded']['session']}
        self.assertEqual(statuses['CLAUDE.md'], 'unconfirmed')

    def epic_with_subagents(self):
        self.lead(self.instructions_line(),
                  self.skill_line(20, os.path.join(self.worktree, '.claude/skills/delivery'),
                                  'Poprowadź zadanie `DEMO-1` do końca. TAJNA-TRESC-SKILLA.'))
        self.assertEqual(self.start()[0], 0)
        self.subagent_run('a1', 'code-reviewer', [AGENT_BODY, 'Inny blok TAJNY'], at=100)
        self.subagent_run('a2', 'code-reviewer', ['Stara wersja roli. TAJNA', 'blok'], at=300)
        self.subagent_run('a3', 'developer', None, at=500)
        self.subagent_run('a4', 'developer', ['Wstęp.\n' + DEV_BODY + '\nKoniec.'], at=700)
        self.subagent_run('a5', 'general-purpose', ['wbudowana'], at=800)
        self.subagent_run('a6', 'developer', 'not a list', at=900)

    def test_process_epic_updates_loaded_from_every_subagent_run(self):
        self.epic_with_subagents()
        code, out, err = self.process_epic()
        self.assertEqual(code, EXIT_PROCESS, err)                   # a2 differs
        data = json.loads(out)
        agents = {a['agent_id']: (a['role'], a['status']) for a in data['loaded']['agents']}
        self.assertEqual(agents, {'a1': ('code-reviewer', 'confirmed'), 'a2': ('code-reviewer', 'differs'),
                                  'a3': ('developer', 'unconfirmed'), 'a4': ('developer', 'differs'),
                                  'a6': ('developer', 'unconfirmed')})
        a2 = [a for a in data['loaded']['agents'] if a['agent_id'] == 'a2'][0]
        self.assertRegex(a2['digest'], r'^[0-9a-f]{16}$')
        skills = [(s['skill'], s['status'], s['origin']) for s in data['loaded']['skills']]
        self.assertEqual(skills, [('delivery', 'confirmed', os.path.join(self.worktree, '.claude/skills/delivery'))])
        stored = self.run_file()['process']
        self.assertEqual(stored['loaded']['agents'], data['loaded']['agents'])
        loaded_changes = [c for c in stored['changes'] if c['kind'] == 'loaded']
        self.assertEqual([c['item']['agent_id'] for c in loaded_changes], ['a2', 'a4'])  # a4: only contained (D-c)
        self.assertNotIn('TAJNA', json.dumps(stored, ensure_ascii=False) + out)

    def test_process_epic_twice_adds_no_duplicate(self):
        self.epic_with_subagents()
        self.process_epic()
        first = self.run_file()['process']['changes']
        code, out, err = self.process_epic(now=testkit.T0 + 200)
        self.assertEqual(code, EXIT_PROCESS)
        self.assertEqual(self.run_file()['process']['changes'], first)

    def test_skill_from_the_main_checkout_with_another_content_differs_with_its_origin(self):
        self.lead(self.instructions_line(),
                  self.skill_line(20, os.path.join(self.main, '.claude/skills/delivery'), 'Stara treść skilla.'))
        self.assertEqual(self.start()[0], 0)
        code, out, err = self.process_epic()
        self.assertEqual(code, EXIT_PROCESS)
        skill = json.loads(out)['loaded']['skills'][0]
        self.assertEqual((skill['status'], skill['origin']),
                         ('differs', os.path.join(self.main, '.claude/skills/delivery')))

    def test_without_skill_calls_skills_are_unconfirmed(self):
        self.lead(self.instructions_line())
        self.assertEqual(self.start()[0], 0)
        code, out, err = self.process_epic()
        self.assertEqual(code, 0, err)
        skills = json.loads(out)['loaded']['skills']
        self.assertEqual([s['status'] for s in skills], ['unconfirmed'])
        self.assertTrue(skills[0]['reason'])

    def test_process_epic_without_a_run_file_exit_2(self):
        code, out, err = self.process_epic()
        self.assertEqual((code, out), (2, ''))
        self.assertIn('pliku przebiegu', err)


# ------------------------------------------------------------------------------------- start, resume, done (W2, W3)

class StartCheckTest(RepoEnv):
    def test_matching_start_writes_the_reading_without_deviations(self):
        self.lead(self.instructions_line())
        code, out, err = self.start()
        self.assertEqual(code, 0, err)
        process = self.run_file()['process']
        self.assertEqual(process['status'], 'checked')
        self.assertEqual(process['checked_on'], 'start')
        self.assertEqual(process['checked_at'], iso_at(0))
        self.assertEqual(process['expected']['ref'], self.base)
        self.assertEqual(process['expected']['requested'], self.base)      # base_sha: origin/main
        self.assertEqual([d['kind'] for d in process['disk']], ['worktree', 'main_checkout'])
        self.assertIs(process['match'], True)
        self.assertIsNone(process['accepted'])
        self.assertEqual(process['changes'], [])

    def test_mismatch_refuses_without_writing(self):
        write(self.main, '.claude/agents/developer.md', 'stara\n')
        write(self.main, '.claude/agents/code-reviewer.md', 'stara\n')
        code, out, err = self.start()
        self.assertEqual((code, out), (2, ''))
        self.assertFalse(self.has_run_file())
        self.assertIn('main_checkout', err)
        self.assertIn('2', err)
        self.assertIn('.claude/agents/developer.md', err)
        self.assertIn('metrics.py process', err)
        self.assertIn('--accept-process-mismatch', err)

    def test_accepted_mismatch_writes_the_reason(self):
        write(self.main, '.claude/agents/developer.md', 'stara\n')
        code, out, err = self.start('--accept-process-mismatch', '  epik czyta role z worktree (W6)  ')
        self.assertEqual(code, 0, err)
        process = self.run_file()['process']
        self.assertEqual(process['accepted'], 'epik czyta role z worktree (W6)')
        self.assertIs(process['match'], False)

    def test_blank_reason_exit_2(self):
        write(self.main, '.claude/agents/developer.md', 'stara\n')
        for reason in ('', '   ', ' '):
            code, out, err = self.start('--accept-process-mismatch', reason)
            self.assertEqual((code, out), (2, ''), reason)
            self.assertIn('pusty powód --accept-process-mismatch', err)       # not argparse refusing the option
            self.assertFalse(self.has_run_file())

    def test_user_skill_shadowing_refuses(self):
        write(self.user_dir, 'skills/delivery/SKILL.md', 'x\n')
        code, out, err = self.start()
        self.assertEqual(code, 2)
        self.assertIn('delivery', err)
        self.assertFalse(self.has_run_file())

    def test_start_after_the_fact_is_not_checked(self):
        write(self.main, '.claude/agents/developer.md', 'stara\n')
        code, out, err = self.start('--at', iso_at(-3600))
        self.assertEqual(code, 0, err)
        process = self.run_file()['process']
        self.assertEqual(process['status'], 'not_checked')
        self.assertTrue(process['reason'])

    def test_check_failure_refuses_and_can_be_accepted(self):
        plain = os.path.join(self.root, 'plain')
        os.makedirs(plain)
        code, out, err = self.start(worktree=plain)
        self.assertEqual(code, 2)
        self.assertFalse(self.has_run_file())
        code, out, err = self.start('--accept-process-mismatch', 'bez gita', worktree=plain)
        self.assertEqual(code, 0, err)
        process = self.run_file()['process']
        self.assertEqual((process['status'], process['accepted']), ('check_failed', 'bez gita'))
        self.assertTrue(process['reason'])


class SessionOfAnotherEpicTest(RepoEnv):
    """E1: one lead session, one epic."""

    def test_start_with_a_session_of_another_epic_refuses(self):
        self.assertEqual(self.start(epic='first')[0], 0)
        code, out, err = self.start(epic='second')
        self.assertEqual((code, out), (2, ''))
        self.assertIn('first', err)
        self.assertIn('nowa sesja', err)
        self.assertFalse(self.has_run_file('second'))

    def test_resume_with_a_session_of_another_epic_refuses(self):
        self.assertEqual(self.start(epic='first')[0], 0)
        self.assertEqual(self.start(epic='second', session='s2')[0], 0)
        before = self.run_file('second')
        code, out, err = self.start('--resume', epic='second', session='s1')
        self.assertEqual(code, 2)
        self.assertIn('first', err)
        self.assertEqual(self.run_file('second'), before)

    def test_resume_of_the_same_epic_in_a_new_session_works(self):
        self.assertEqual(self.start(epic='first')[0], 0)
        code, out, err = self.start('--resume', epic='first', session='s2', now=testkit.T0 + 600)
        self.assertEqual(code, 0, err)
        self.assertEqual([s['id'] for s in self.run_file('first')['sessions']], ['s1', 's2'])


class ResumeDoneTest(RepoEnv):
    """W3: `start --resume` and `done` compare the disk with the reading of the start."""

    def setUp(self):
        super().setUp()
        self.lead(self.instructions_line())
        self.assertEqual(self.start()[0], 0)

    def change_agent(self):
        write(self.main, '.claude/agents/code-reviewer.md', agent_file('code-reviewer', 'zmieniona rola'))

    def test_resume_without_a_change(self):
        code, out, err = self.start('--resume', session='s2', now=testkit.T0 + 600)
        self.assertEqual(code, 0, err)
        self.assertEqual(self.run_file()['process']['changes'], [])

    def test_resume_after_a_change_refuses(self):
        self.change_agent()
        before = self.run_file()
        code, out, err = self.start('--resume', session='s2', now=testkit.T0 + 600)
        self.assertEqual(code, 2)
        self.assertIn('main_checkout', err)
        self.assertIn('--accept-process-mismatch', err)
        self.assertEqual(self.run_file(), before)

    def test_resume_after_a_change_with_acceptance_records_it(self):
        self.change_agent()
        code, out, err = self.start('--resume', '--accept-process-mismatch', 'poprawka roli w trakcie',
                                    session='s2', now=testkit.T0 + 500)
        self.assertEqual(code, 2)                       # nothing shown yet: only a shown mismatch is accepted (D-h)
        self.assertIn('metrics.py process demo', err)
        self.assertEqual(self.process_epic(now=testkit.T0 + 550)[0], EXIT_PROCESS)
        code, out, err = self.start('--resume', '--accept-process-mismatch', 'poprawka roli w trakcie',
                                    session='s2', now=testkit.T0 + 600)
        self.assertEqual(code, 0, err)
        changes = self.run_file()['process']['changes']
        self.assertEqual([(c['kind'], c['event']) for c in changes], [('disk', 'process'), ('accepted', 'resume')])
        main = [d for d in changes[0]['disk'] if d['kind'] == 'main_checkout'][0]
        self.assertEqual(main['differs'], [{'path': '.claude/agents/code-reviewer.md', 'state': 'changed'}])
        accepted = changes[1]
        self.assertEqual((accepted['at'], accepted['session'], accepted['reason']),
                         (iso_at(600), 's2', 'poprawka roli w trakcie'))
        self.assertIn('main_checkout', ' '.join(accepted['items']))
        code, out, err = self.start('--resume', session='s3', now=testkit.T0 + 900)
        self.assertEqual(code, 0, err)                                  # the accepted state is the new baseline

    def test_done_after_a_change_records_it_and_writes_done_at(self):
        self.change_agent()
        code, out, err = self.done()
        self.assertEqual(code, 0, err)
        self.assertIn('main_checkout', err)
        run = self.run_file()
        self.assertEqual(run['done_at'], iso_at(4000))
        change = run['process']['changes'][-1]
        self.assertEqual((change['event'], change['kind'], change['informational']), ('done', 'disk', False))

    def test_done_twice_adds_the_change_once(self):
        self.change_agent()
        self.done()
        self.done(now=testkit.T0 + 5000)
        self.assertEqual(len([c for c in self.run_file()['process']['changes'] if c['kind'] == 'disk']), 1)

    def test_done_without_git_writes_done_at_and_a_failed_check(self):
        plain = os.path.join(self.root, 'plain')
        os.makedirs(plain)
        argv = ['done', 'demo', '--state', self.state, '--user-dir', self.user_dir, '--projects', self.projects,
                '--worktree', plain]
        code, out, err = run_cli(argv, now=testkit.T0 + 4000)
        self.assertEqual(code, 0, err)
        self.assertIn('metrics:', err)
        run = self.run_file()
        self.assertEqual(run['done_at'], iso_at(4000))
        self.assertEqual(run['process']['changes'][-1]['kind'], 'check_failed')

    def test_process_epic_after_a_change_records_it_once(self):
        self.change_agent()
        code, out, err = self.process_epic()
        self.assertEqual(code, EXIT_PROCESS)
        code, out, err = self.process_epic(now=testkit.T0 + 300)
        self.assertEqual(code, EXIT_PROCESS)
        disk = [c for c in self.run_file()['process']['changes'] if c['kind'] == 'disk']
        self.assertEqual([c['event'] for c in disk], ['process'])


class OldRunFileTest(RepoEnv):
    """A run file written before the check (no `process`) is never refused."""

    def setUp(self):
        super().setUp()
        self.write_run()
        self.lead(self.instructions_line())

    def test_resume_records_a_reading_marked_as_from_the_resume(self):
        write(self.main, '.claude/agents/developer.md', 'stara\n')
        code, out, err = self.start('--resume', session='s2', now=testkit.T0 + 600)
        self.assertEqual(code, 0, err)
        process = self.run_file()['process']
        self.assertEqual((process['status'], process['checked_on']), ('checked', 'resume'))
        self.assertIs(process['match'], False)

    def test_done_records_a_reading_marked_as_from_done(self):
        code, out, err = self.done()
        self.assertEqual(code, 0, err)
        self.assertEqual(self.run_file()['process']['checked_on'], 'done')

    def test_record_of_an_old_run_file_has_process_null_with_a_gap(self):
        self.write_run()
        record = self.native_record()
        self.assertIsNone(record['process'])
        self.assertTrue([g for g in record['gaps'] if g.startswith('process: ')], record['gaps'])
        self.assertNotIn('instructions', record['attempts'][0] if record['attempts'] else {})


# ------------------------------------------------------------------------------------------------ record (W4)

class RecordTest(RepoEnv):
    def record(self):
        code, out, err = run_cli(['epic', 'demo', '--state', self.state, '--night', self.night, '--projects',
                                  self.projects, '--no-gh', '--worktree', self.worktree])
        self.assertEqual(code, 0, err)
        return json.loads(out)

    def gaps(self, record):
        return [g for g in record['gaps'] if g.startswith('process: ')]

    def test_matching_epic_has_the_process_and_only_the_unconfirmed_gap(self):
        self.lead(self.instructions_line(),
                  self.skill_line(20, os.path.join(self.worktree, '.claude/skills/delivery'),
                                  'Poprowadź zadanie `DEMO-1` do końca. TAJNA-TRESC-SKILLA.'))
        self.assertEqual(self.start()[0], 0)
        self.subagent_run('a1', 'code-reviewer', [AGENT_BODY], at=100)
        self.assertEqual(self.done()[0], 0)
        record = self.record()
        process = record['process']
        self.assertEqual(process['status'], 'checked')
        self.assertEqual(process['expected']['ref'], self.base)
        self.assertEqual(process['loaded']['by_role'], {'code-reviewer': {'confirmed': 1, 'differs': 0,
                                                                          'unconfirmed': 0}})
        self.assertEqual(process['loaded']['summary']['differs'], 0)
        self.assertGreater(process['loaded']['summary']['unconfirmed'], 0)     # apps/api/CLAUDE.md, the ADR
        self.assertEqual([(a['agent_id'], a['instructions']) for a in record['attempts']], [('a1', 'confirmed')])
        gaps = self.gaps(record)
        self.assertEqual(len(gaps), 1, gaps)
        self.assertIn('unconfirmed', gaps[0])
        self.assertNotIn('TAJNA', json.dumps(record, ensure_ascii=False))

    def test_change_between_start_and_done_is_in_process_and_gaps(self):
        self.lead(self.instructions_line())
        self.assertEqual(self.start()[0], 0)
        write(self.main, '.claude/agents/code-reviewer.md', agent_file('code-reviewer', 'zmieniona'))
        self.subagent_run('a1', 'code-reviewer', ['zmieniona'], at=100)
        self.assertEqual(self.done()[0], 0)
        record = self.record()
        self.assertEqual([c['event'] for c in record['process']['changes'] if c['kind'] == 'disk'], ['done'])
        self.assertEqual(record['process']['loaded']['by_role']['code-reviewer']['differs'], 1)
        self.assertEqual(record['attempts'][0]['instructions'], 'differs')
        gaps = self.gaps(record)
        self.assertTrue(any('done' in g for g in gaps), gaps)
        self.assertTrue(any('a1' in g for g in gaps), gaps)

    def test_accepted_start_and_not_checked_have_gaps(self):
        write(self.main, '.claude/agents/developer.md', 'stara\n')
        self.assertEqual(self.start('--accept-process-mismatch', 'powód W6')[0], 0)
        self.assertEqual(self.done()[0], 0)
        gaps = self.gaps(self.record())
        self.assertTrue(any('powód W6' in g and '1' in g for g in gaps), gaps)
        os.remove(os.path.join(self.state, 'runs', 'demo.json'))
        self.assertEqual(self.start('--at', iso_at(0))[0], 0)
        self.assertTrue(any('nie sprawdzono' in g for g in self.gaps(self.record())))

    def test_unavailable_base_makes_loaded_unconfirmed_not_an_error(self):
        self.lead(self.instructions_line())
        self.assertEqual(self.start()[0], 0)
        self.subagent_run('a1', 'code-reviewer', [AGENT_BODY], at=100)
        run = self.run_file()
        run['process']['expected']['ref'] = 'e' * 40
        with open(os.path.join(self.state, 'runs', 'demo.json'), 'w') as f:
            json.dump(run, f)
        record = self.record()
        self.assertEqual(record['attempts'][0]['instructions'], 'unconfirmed')
        self.assertEqual(record['process']['loaded']['summary']['confirmed'], 0)

    def test_render_shows_the_version_of_the_process(self):
        self.lead(self.instructions_line())
        self.assertEqual(self.start()[0], 0)
        self.subagent_run('a1', 'code-reviewer', [AGENT_BODY], at=100)
        self.assertEqual(self.done()[0], 0)
        self.record()
        for form in ('report', 'comment'):
            code, out, err = run_cli(['render', 'demo', '--state', self.state, '--format', form])
            self.assertEqual(code, 0, err)
            line = [l for l in out.splitlines() if 'Wersja procesu' in l]
            self.assertEqual(len(line), 1, out)
            self.assertIn('ash-v1.0', line[0])
            self.assertIn(self.base[:12], line[0])
            self.assertIn('dysk zgodny', line[0])
            self.assertIn('potwierdzone 2', line[0])         # the CLAUDE.md of the session and a1
            self.assertNotIn(self.worktree, out if form == 'comment' else '')

    def test_checked_disk_is_marked_as_checked(self):
        self.lead(self.instructions_line())
        self.assertEqual(self.start()[0], 0)
        self.assertIs(self.record()['process']['disk_checked'], True)


class RecordWithoutDiskCheckTest(RecordTest):
    """A run file without a disk check (start before the check, `--at`, a failed check) with a `base_sha` the
    repository knows: the record computes `expected` from `base_sha` and `loaded` from the transcripts, and marks the
    disk as not checked (never as matching). The run file does not change."""

    def epic_lines(self):
        self.lead(self.instructions_line())
        self.subagent_run('a1', 'code-reviewer', [AGENT_BODY], at=100)
        self.subagent_run('a2', 'developer', ['Stara wersja roli developera.'], at=300)

    def assert_loaded_without_disk(self, record, status, reason_part):
        process = record['process']
        self.assertEqual(process['status'], status)
        self.assertIs(process['disk_checked'], False)
        self.assertIsNone(process['disk'])
        self.assertIsNone(process['match'])
        self.assertIn(reason_part, process['disk_reason'])
        self.assertEqual(process['expected']['ref'], self.base)
        self.assertEqual(process['expected']['describe'], 'ash-v1.0')
        self.assertEqual(process['expected']['files']['CLAUDE.md'], git(self.main, 'rev-parse', 'HEAD:CLAUDE.md'))
        self.assertEqual(process['loaded']['by_role'], {
            'code-reviewer': {'confirmed': 1, 'differs': 0, 'unconfirmed': 0},
            'developer': {'confirmed': 0, 'differs': 1, 'unconfirmed': 0}})
        session = {e['path']: e['status'] for e in process['loaded']['session']}
        self.assertEqual(session['CLAUDE.md'], 'confirmed')
        self.assertEqual({a['agent_id']: a['instructions'] for a in record['attempts']},
                         {'a1': 'confirmed', 'a2': 'differs'})
        gaps = self.gaps(record)
        disk = [g for g in gaps if 'dysk' in g and 'nie sprawdzono' in g]
        self.assertEqual(len(disk), 1, gaps)
        self.assertIn(reason_part, disk[0])
        self.assertTrue(any('a2' in g for g in gaps), gaps)
        self.assertTrue(any('unconfirmed' in g for g in gaps), gaps)
        self.assertEqual(len(gaps), 3, gaps)
        self.assertNotIn('TAJNA', json.dumps(record, ensure_ascii=False))

    def test_run_file_from_before_the_check(self):
        self.write_run(base_sha=self.base)
        self.epic_lines()
        before = self.run_file()
        record = self.record()
        self.assert_loaded_without_disk(record, 'not_checked', 'sprzed kontroli')
        self.assertEqual(self.run_file(), before)

    def test_start_after_the_fact(self):
        self.assertEqual(self.start('--at', iso_at(0))[0], 0)
        self.assertEqual(self.run_file()['base_sha'], self.base)
        self.epic_lines()
        self.assertEqual(self.done('--at', iso_at(4000))[0], 0)
        self.assert_loaded_without_disk(self.record(), 'not_checked', '--at')

    def test_failed_check_at_the_start(self):
        self.write_run(base_sha=self.base, process={'status': 'check_failed', 'checked_at': iso_at(0),
                                                    'checked_on': 'start', 'reason': 'git niedostępny',
                                                    'accepted': 'bez gita', 'changes': []})
        self.epic_lines()
        record = self.record()
        self.assert_loaded_without_disk(record, 'check_failed', 'git niedostępny')
        self.assertEqual(record['process']['accepted'], 'bez gita')

    def test_render_says_the_disk_was_not_checked(self):
        self.write_run(base_sha=self.base)
        self.epic_lines()
        self.record()
        for form in ('report', 'comment'):
            code, out, err = run_cli(['render', 'demo', '--state', self.state, '--format', form])
            self.assertEqual(code, 0, err)
            line = [l for l in out.splitlines() if 'Wersja procesu' in l]
            self.assertEqual(len(line), 1, out)
            self.assertIn('ash-v1.0 (%s)' % self.base[:12], line[0])
            self.assertIn('dysk: nie sprawdzono', line[0])
            self.assertNotIn('dysk zgodny', line[0])
            self.assertIn('potwierdzone 2, różne 1', line[0])

    def test_unknown_base_sha_keeps_the_old_shape(self):
        self.write_run(base_sha='e' * 40)
        self.epic_lines()
        self.assertIsNone(self.record()['process'])
        self.write_run(base_sha=None, process={'status': 'not_checked', 'reason': 'zapis po fakcie'})
        record = self.record()
        self.assertEqual(record['process'], {'status': 'not_checked', 'reason': 'zapis po fakcie'})
        self.assertNotIn('instructions', record['attempts'][0])


EPIC_API_MD = '# API\n\nZasady API po zmianie epiku.\n'


class EpicVersionTest(RecordTest):
    """A CLAUDE.md the session reads from its own worktree may be the version the epic changed: a version of the
    history of the epic branch (not reachable from the base) or the current file of the worktree is confirmed with
    `version: "epic"`, never `differs`; agents and skills keep the base as the expected version."""

    def started(self):
        self.lead(self.instructions_line())
        self.assertEqual(self.start()[0], 0)

    def change_in_the_epic(self, commit=True):
        write(self.worktree, 'apps/api/CLAUDE.md', EPIC_API_MD)
        if commit:
            git(self.worktree, 'commit', '-q', '-am', 'docs: api rules')

    def api_entry(self, loaded):
        return [e for e in loaded['session'] if e['path'] == 'apps/api/CLAUDE.md'][0]

    def test_claude_md_changed_and_committed_by_the_epic_is_the_epic_version(self):
        self.started()
        self.change_in_the_epic()
        write(self.worktree, 'apps/api/CLAUDE.md', 'jeszcze nowsza treść\n')     # history, not disk, matches
        git(self.worktree, 'commit', '-q', '-am', 'docs: newer api rules')
        self.lead(self.instructions_line(), self.nested_line(500, EPIC_API_MD))
        code, out, err = self.process_epic(now=testkit.T0 + 600)
        self.assertEqual(code, 0, err)
        entry = self.api_entry(json.loads(out)['loaded'])
        self.assertEqual((entry['status'], entry['version']), ('confirmed', 'epic'))
        root = [e for e in json.loads(out)['loaded']['session'] if e['path'] == 'CLAUDE.md'][0]
        self.assertEqual((root['status'], root['version']), ('confirmed', 'base'))

    def test_claude_md_changed_on_disk_only_is_no_epic_version(self):
        self.started()
        self.change_in_the_epic(commit=False)
        self.lead(self.instructions_line(), self.nested_line(500, EPIC_API_MD))
        code, out, err = self.process_epic(now=testkit.T0 + 600)
        self.assertEqual(code, EXIT_PROCESS)            # an uncommitted change in the worktree is a mismatch (D-a)
        entry = self.api_entry(json.loads(out)['loaded'])
        self.assertEqual((entry['status'], entry['version']), ('differs', None))     # D-k: no epic version
        self.assertIn('niezacommitowana', entry['reason'])

    def test_content_from_neither_base_nor_epic_differs(self):
        self.started()
        self.change_in_the_epic()
        self.lead(self.instructions_line(), self.nested_line(500, 'treść spoza bazy i gałęzi\n'))
        code, out, err = self.process_epic(now=testkit.T0 + 600)
        self.assertEqual(code, EXIT_PROCESS)
        entry = self.api_entry(json.loads(out)['loaded'])
        self.assertEqual((entry['status'], entry['version']), ('differs', None))

    def test_claude_md_from_another_place_differs(self):
        self.started()
        self.change_in_the_epic()
        self.lead(self.instructions_line(), self.nested_line(500, EPIC_API_MD, base=self.main))
        code, out, err = self.process_epic(now=testkit.T0 + 600)
        self.assertEqual(code, EXIT_PROCESS)
        self.assertEqual(self.api_entry(json.loads(out)['loaded'])['status'], 'differs')

    def test_record_with_the_branch_is_the_epic_version(self):
        self.started()
        self.change_in_the_epic()
        self.lead(self.instructions_line(), self.nested_line(500, EPIC_API_MD))
        self.assertEqual(self.done()[0], 0)
        record = self.record()
        entry = self.api_entry(record['process']['loaded'])
        self.assertEqual((entry['status'], entry['version']), ('confirmed', 'epic'))
        self.assertEqual(record['process']['loaded']['summary']['confirmed_epic'], 1)
        self.assertFalse([g for g in self.gaps(record) if 'apps/api' in g and not g.startswith('process: informacja')],
                         self.gaps(record))

    def test_record_after_the_merge_without_the_branch_is_unconfirmed(self):
        self.started()
        self.change_in_the_epic()
        self.lead(self.instructions_line(), self.nested_line(500, EPIC_API_MD))
        self.assertEqual(self.done()[0], 0)
        git(self.main, 'worktree', 'remove', '--force', self.worktree)
        git(self.main, 'branch', '-D', 'feat/demo')
        code, out, err = run_cli(['epic', 'demo', '--state', self.state, '--night', self.night, '--projects',
                                  self.projects, '--no-gh', '--worktree', self.main])
        self.assertEqual(code, 0, err)
        entry = self.api_entry(json.loads(out)['process']['loaded'])
        self.assertEqual(entry['status'], 'unconfirmed')
        self.assertIn('historia gałęzi epiku niedostępna', entry['reason'])

    def test_agent_changed_in_the_epic_branch_still_differs(self):
        self.started()
        write(self.worktree, '.claude/agents/code-reviewer.md', agent_file('code-reviewer', 'Nowa rola z epiku.'))
        git(self.worktree, 'commit', '-q', '-am', 'agent')
        self.subagent_run('a1', 'code-reviewer', ['Nowa rola z epiku.'], at=100)
        code, out, err = self.process_epic(now=testkit.T0 + 600)
        self.assertEqual(code, EXIT_PROCESS)
        agent = json.loads(out)['loaded']['agents'][0]
        self.assertEqual((agent['agent_id'], agent['status'], agent['version']), ('a1', 'differs', None))


class EpicWindowTest(RecordTest):
    """The evidence of the session and the subagent runs count only up to the merge of the final PR: a session that
    led two epics gives the record of the first one only its own loads."""

    def test_session_of_two_epics(self):
        self.lead(self.instructions_line())
        self.assertEqual(self.start()[0], 0)
        # the lines written later: the first epic's load at 500, the second epic's at 5000
        self.lead(self.instructions_line(), self.nested_line(500), self.nested_line(5000, 'treść drugiego epiku\n'))
        self.subagent_run('a1', 'code-reviewer', [AGENT_BODY], at=100)
        self.subagent_run('a2', 'code-reviewer', ['Rola drugiego epiku.'], at=6000)
        self.assertEqual(self.done(now=testkit.T0 + 1000)[0], 0)
        testkit.store_records(self.state, {'epic': 'demo', 'source': 'native', 'merged_at': iso_at(1200)})
        record = self.record()
        loaded = record['process']['loaded']
        api = [e for e in loaded['session'] if e['path'] == 'apps/api/CLAUDE.md'][0]
        self.assertEqual(api['status'], 'confirmed')
        self.assertEqual([a['agent_id'] for a in loaded['agents']], ['a1'])
        self.assertEqual(loaded['by_role'], {'code-reviewer': {'confirmed': 1, 'differs': 0, 'unconfirmed': 0}})

    def test_load_without_a_timestamp_counts(self):
        line = self.nested_line(500)
        del line['timestamp']
        self.lead(self.instructions_line(), line)
        self.assertEqual(self.start()[0], 0)
        self.assertEqual(self.done(now=testkit.T0 + 1000)[0], 0)
        testkit.store_records(self.state, {'epic': 'demo', 'source': 'native', 'merged_at': iso_at(1200)})
        api = [e for e in self.record()['process']['loaded']['session'] if e['path'] == 'apps/api/CLAUDE.md'][0]
        self.assertEqual(api['status'], 'confirmed')


class DiskRuleTest(RecordTest):
    """D-a: one rule for a change on disk during the epic, by the place. The worktree is the epic's own place: a change
    committed on its branch (from the base) is information, an uncommitted change of an instruction or definition is a
    mismatch; every change in the main checkout and a user skill shadowing a project skill is a mismatch until it is
    accepted (D-e: always against the base, `process EPIK --accept-process-mismatch`)."""

    def started(self):
        self.lead(self.instructions_line())
        code, out, err = self.start()
        self.assertEqual(code, 0, err)

    def commit_in_the_epic(self):
        write(self.worktree, 'CLAUDE.md', '# CLAUDE.md\n\nZasady po zmianie epiku.\n')
        write(self.worktree, '.claude/agents/code-reviewer.md', agent_file('code-reviewer', 'Rola po zmianie epiku.'))
        git(self.worktree, 'commit', '-q', '-am', 'docs: rules of the epic')

    def merge_from_main(self, files):
        """Another branch changes `files` and is merged to main (origin/main moves); the epic merges origin/main."""
        other = os.path.join(self.root, 'upstream')
        if not os.path.isdir(other):
            git(self.main, 'worktree', 'add', '-q', '-b', 'upstream', other, self.base)
        for path, text in files.items():
            write(other, path, text)
        git(other, 'commit', '-q', '-am', 'process change on main')
        git(self.main, 'update-ref', 'refs/remotes/origin/main', 'upstream')
        git(self.worktree, 'merge', '-q', '--no-edit', 'origin/main')

    def changes(self, kind='disk'):
        return [c for c in self.run_file()['process']['changes'] if c['kind'] == kind]

    def test_uncommitted_claude_md_and_adr_change_is_a_mismatch(self):
        self.started()
        write(self.worktree, 'CLAUDE.md', '# CLAUDE.md\n\nNOWE zasady.\n')
        write(self.worktree, 'docs/adr/agentic-software-house.md', '# Przebieg\n\nNOWE.\n')
        code, out, err = self.process_epic()
        self.assertEqual(code, EXIT_PROCESS)
        self.assertIs(json.loads(out)['match'], False)
        self.assertIn('CLAUDE.md', err)
        self.assertEqual(self.done(now=testkit.T0 + 4000)[0], 0)
        disk = self.changes()
        self.assertEqual([(c['event'], c['informational']) for c in disk], [('process', False)])
        worktree = [p for p in disk[0]['disk'] if p['kind'] == 'worktree'][0]
        self.assertEqual(sorted(d['path'] for d in worktree['uncommitted']),
                         ['CLAUDE.md', 'docs/adr/agentic-software-house.md'])
        self.record()
        code, out, err = run_cli(['render', 'demo', '--state', self.state])
        line = [l for l in out.splitlines() if 'Wersja procesu' in l][0]
        self.assertNotIn('dysk zgodny', line)

    def test_change_committed_on_the_epic_branch_is_information(self):
        self.started()
        self.commit_in_the_epic()
        for now in (100, 200):
            code, out, err = self.process_epic(now=testkit.T0 + now)
            self.assertEqual(code, 0, err)
            self.assertIs(json.loads(out)['match'], True)
        disk = self.changes()
        self.assertEqual(len(disk), 1, disk)
        self.assertIs(disk[0]['informational'], True)
        self.assertEqual(self.changes('accepted'), [])
        worktree = [p for p in disk[0]['disk'] if p['kind'] == 'worktree'][0]
        self.assertEqual(sorted(d['path'] for d in worktree['epic_branch']),
                         ['.claude/agents/code-reviewer.md', 'CLAUDE.md'])
        self.assertEqual(self.done()[0], 0)
        record = self.record()
        gaps = self.gaps(record)
        info = [g for g in gaps if g.startswith('process: informacja')]
        self.assertEqual(len(info), 1, gaps)
        self.assertIn('CLAUDE.md', info[0])
        self.assertFalse([g for g in gaps if 'zmiana definicji na dysku' in g], gaps)
        code, out, err = run_cli(['render', 'demo', '--state', self.state])
        line = [l for l in out.splitlines() if 'Wersja procesu' in l][0]
        self.assertIn('dysk zgodny', line)
        self.assertIn('zmian w gałęzi epiku 1', line)

    def test_start_on_a_branch_with_its_own_commits_is_information(self):
        self.commit_in_the_epic()
        self.started()
        process = self.run_file()['process']
        self.assertIs(process['match'], True)
        worktree = process['disk'][0]
        self.assertEqual(sorted(d['path'] for d in worktree['epic_branch']),
                         ['.claude/agents/code-reviewer.md', 'CLAUDE.md'])
        self.assertIs(worktree['from_base'], True)

    def test_start_with_an_uncommitted_change_in_the_worktree_refuses(self):
        write(self.worktree, 'apps/api/CLAUDE.md', 'niezacommitowane\n')
        code, out, err = self.start()
        self.assertEqual((code, out), (2, ''))
        self.assertIn('apps/api/CLAUDE.md', err)

    def test_user_skill_added_during_the_epic_is_a_mismatch(self):
        self.started()
        write(self.user_dir, 'skills/delivery/SKILL.md', 'x\n')
        code, out, err = self.process_epic()
        self.assertEqual(code, EXIT_PROCESS)
        self.assertIn('delivery', err)
        self.assertEqual(self.changes()[0]['user_level']['skills'], ['delivery'])

    def test_scenario_of_the_epic(self):
        """start, a commit of the epic, a change in the main checkout, its acceptance, done."""
        self.started()
        self.commit_in_the_epic()
        codes = [self.process_epic(now=testkit.T0 + 100)[0]]
        write(self.main, '.claude/agents/developer.md', agent_file('developer', 'Inna rola w głównym.'))
        code, out, err = self.process_epic(now=testkit.T0 + 200)
        codes.append(code)
        self.assertIn('--accept-process-mismatch', err)
        self.assertIn('główny checkout', err)
        codes.append(self.process_epic('demo', '--accept-process-mismatch', 'świadomie: poprawka roli',
                                       now=testkit.T0 + 300)[0])
        codes.append(self.process_epic(now=testkit.T0 + 400)[0])
        self.assertEqual(codes, [0, EXIT_PROCESS, 0, 0])
        self.assertEqual(self.done(now=testkit.T0 + 4000)[0], 0)
        disk = self.changes()
        self.assertEqual([(c['at'], c['informational']) for c in disk], [(iso_at(100), True), (iso_at(200), False)])
        self.assertEqual([(c['at'], c['reason']) for c in self.changes('accepted')],
                         [(iso_at(300), 'świadomie: poprawka roli')])
        write(self.main, '.claude/agents/developer.md', agent_file('developer', 'Jeszcze inna rola.'))
        self.assertEqual(self.process_epic(now=testkit.T0 + 500)[0], EXIT_PROCESS)       # a new change again
        # a branch from main that changes CLAUDE.md, merged into the epic: a change of the process from main
        self.merge_from_main({'apps/api/CLAUDE.md': '# API\n\nZasady API z main.\n'})
        code, out, err = self.process_epic(now=testkit.T0 + 600)
        self.assertEqual(code, EXIT_PROCESS)
        self.assertIn('przyniesiona z main', err)

    def test_blank_reason_of_process_epic_exit_2(self):
        self.started()
        code, out, err = self.process_epic('demo', '--accept-process-mismatch', '  ')
        self.assertEqual((code, out), (2, ''))
        self.assertIn('pusty powód', err)

    def test_late_reading_stays_a_mismatch_until_accepted(self):
        self.write_run(base_sha=self.base)                 # no process: a run file from before the check
        write(self.main, '.claude/agents/developer.md', 'stara\n')
        self.assertEqual(self.process_epic(now=testkit.T0 + 100)[0], EXIT_PROCESS)
        self.assertEqual(self.process_epic(now=testkit.T0 + 200)[0], EXIT_PROCESS)
        self.assertEqual(self.process_epic('demo', '--accept-process-mismatch', 'stary plik', now=testkit.T0 + 300)[0], 0)
        self.assertEqual(self.process_epic(now=testkit.T0 + 400)[0], 0)

    def test_failed_check_at_the_start_is_no_accepted_state(self):
        self.write_run(base_sha=self.base, process={'status': 'check_failed', 'checked_at': iso_at(0),
                                                    'checked_on': 'start', 'reason': 'git niedostępny',
                                                    'accepted': 'bez gita', 'changes': []})
        write(self.main, '.claude/agents/developer.md', 'stara\n')
        self.assertEqual(self.process_epic(now=testkit.T0 + 100)[0], EXIT_PROCESS)
        self.assertEqual(self.process_epic(now=testkit.T0 + 200)[0], EXIT_PROCESS)

    def test_default_worktree_is_the_one_of_the_last_session(self):
        self.started()
        argv = ['process', 'demo', '--state', self.state, '--user-dir', self.user_dir, '--projects', self.projects]
        with mock.patch('os.getcwd', return_value=self.main):
            code, out, err = run_cli(argv, now=testkit.T0 + 100)
            self.assertEqual(code, 0, err)
            self.assertIn(self.worktree, err)
            self.assertEqual(run_cli(['done', 'demo', '--state', self.state, '--user-dir', self.user_dir,
                                      '--projects', self.projects], now=testkit.T0 + 4000)[0], 0)
        self.assertEqual(self.changes(), [])
        with mock.patch('os.getcwd', return_value=self.worktree):
            code, out, err = run_cli(argv, now=testkit.T0 + 200)
            self.assertEqual((code, err), (0, ''))


MAIN_MD = '# API\n\nZasady API z main (inny epik).\n'


class OriginAndAcceptanceTest(DiskRuleTest):
    """D-g: only a commit of the epic branch that is not reachable from origin/main is the epic's own change; D-h: an
    acceptance covers only the mismatches the previous check showed; D-i: a subagent that loaded the file of the
    accepted state of the main checkout is explained; D-j: information, a return to an accepted state, N4-N6."""

    test_scenario_of_the_epic = None

    def api(self, loaded):
        return [e for e in loaded['session'] if e['path'] == 'apps/api/CLAUDE.md' and e['session'] == 's1'][0]

    def test_a_merge_of_main_with_a_process_change_is_a_mismatch(self):
        self.started()
        self.commit_in_the_epic()
        self.merge_from_main({'apps/api/CLAUDE.md': MAIN_MD, 'docs/adr/agentic-software-house.md': '# ADR z main\n',
                              '.claude/agents/developer.md': agent_file('developer', 'Rola z main.')})
        self.lead(self.instructions_line(), self.nested_line(500, MAIN_MD))
        code, out, err = self.process_epic(now=testkit.T0 + 600)
        self.assertEqual(code, EXIT_PROCESS)
        data = json.loads(out)
        self.assertIn('przyniesiona z main', ' '.join(data['mismatches']))
        worktree = data['disk'][0]
        self.assertEqual(sorted(d['path'] for d in worktree['from_main']), [
            '.claude/agents/developer.md', 'apps/api/CLAUDE.md', 'docs/adr/agentic-software-house.md'])
        self.assertEqual([c['informational'] for c in self.changes()], [False])
        api = self.api(data['loaded'])
        self.assertEqual((api['status'], api['version']), ('differs', None))
        self.assertIn('wersja z main późniejszego niż baza', api['reason'])

    def test_b_worktree_on_another_branch_is_a_mismatch(self):
        self.started()
        git(self.worktree, 'checkout', '-q', '-b', 'other')
        write(self.worktree, 'apps/api/CLAUDE.md', MAIN_MD)
        git(self.worktree, 'commit', '-q', '-am', 'other branch change')
        self.lead(self.instructions_line(), self.nested_line(500, MAIN_MD))
        code, out, err = self.process_epic(now=testkit.T0 + 600)
        self.assertEqual(code, EXIT_PROCESS)
        data = json.loads(out)
        self.assertIn('feat/demo', ' '.join(data['mismatches']))
        self.assertIs(data['disk'][0]['on_epic_branch'], False)
        self.assertNotEqual(self.api(data['loaded'])['version'], 'epic')

    def test_c_resume_in_a_new_worktree_from_a_later_main(self):
        self.started()
        self.merge_from_main({'CLAUDE.md': '# CLAUDE.md\n\nnowy main\n'})
        git(self.worktree, 'reset', '-q', '--hard', self.base)          # the epic itself did not merge main
        new = os.path.join(self.main, '.claude', 'worktrees', 'new')
        git(self.main, 'worktree', 'add', '-q', '-b', 'claude/new', new, 'origin/main')
        self.lead(self.instructions_line(root_md='# CLAUDE.md\n\nnowy main\n', worktree=new, session='s2'),
                  session='s2', worktree=new)
        code, out, err = self.start('--resume', session='s2', worktree=new, now=testkit.T0 + 100)
        self.assertEqual(code, 2)
        self.assertIn('feat/demo', err)
        self.assertIn('powtórz z --accept-process-mismatch', err)
        shown = self.run_file()['process']['shown']                 # the refusal records what it showed (D-n)
        self.assertEqual((shown['event'], shown['session']), ('resume', 's2'))
        self.assertEqual([s['id'] for s in self.run_file()['sessions']], ['s1'])
        root = [e for e in self.run_file()['process']['loaded']['session']
                if e['session'] == 's2' and e['path'] == 'CLAUDE.md'][0]
        self.assertEqual(root['status'], 'differs')
        self.assertIn('wersja z main późniejszego niż baza', root['reason'])
        code, out, err = self.start('--resume', '--accept-process-mismatch', 'nowa sesja z nowszego main',
                                    session='s2', worktree=new, now=testkit.T0 + 200)
        self.assertEqual(code, 0, err)
        self.assertEqual([s['id'] for s in self.run_file()['sessions']], ['s1', 's2'])
        self.assertEqual(self.changes('accepted')[-1]['reason'], 'nowa sesja z nowszego main')

    def test_acceptance_covers_only_what_was_shown(self):
        self.started()
        write(self.main, '.claude/agents/developer.md', agent_file('developer', 'Inna rola w głównym.'))
        self.assertEqual(self.process_epic(now=testkit.T0 + 100)[0], EXIT_PROCESS)
        self.subagent_run('a9', 'code-reviewer', ['Zmieniona rola.'], at=150)         # starts after the check
        code, out, err = self.process_epic('demo', '--accept-process-mismatch', 'tylko developer', now=testkit.T0 + 200)
        self.assertEqual(code, EXIT_PROCESS)
        data = json.loads(out)
        self.assertEqual(len(data['mismatches']), 1, data['mismatches'])
        self.assertIn('a9', data['mismatches'][0])
        code, out, err = self.process_epic('demo', '--accept-process-mismatch', 'i reviewer', now=testkit.T0 + 300)
        self.assertEqual(code, 0, err)
        self.assertEqual(self.process_epic(now=testkit.T0 + 400)[0], 0)
        self.assertEqual([c['reason'] for c in self.changes('accepted')], ['tylko developer', 'i reviewer'])

    def test_acceptance_without_a_shown_check_shows_first(self):
        self.started()
        write(self.main, '.claude/agents/developer.md', agent_file('developer', 'Inna rola w głównym.'))
        code, out, err = self.process_epic('demo', '--accept-process-mismatch', 'od razu', now=testkit.T0 + 100)
        self.assertEqual(code, EXIT_PROCESS)
        self.assertIn('powtórz', err)
        self.assertEqual(self.changes('accepted'), [])
        self.assertEqual(self.process_epic('demo', '--accept-process-mismatch', 'od razu', now=testkit.T0 + 200)[0], 0)

    def test_f1_subagent_with_the_file_of_the_accepted_main_checkout_is_explained(self):
        stale = 'Stara rola developera z głównego checkoutu.'
        write(self.main, '.claude/agents/developer.md', agent_file('developer', stale))
        self.lead(self.instructions_line())
        self.assertEqual(self.start('--accept-process-mismatch', 'główny checkout nieaktualny')[0], 0)
        self.subagent_run('a1', 'developer', [stale, 'blok'], at=100)
        code, out, err = self.process_epic(now=testkit.T0 + 200)
        self.assertEqual(code, 0, err)
        agent = json.loads(out)['loaded']['agents'][0]
        self.assertEqual((agent['status'], agent['explained']), ('differs', 'accepted_disk'))
        self.subagent_run('a2', 'developer', ['Trzecia treść roli.'], at=300)
        code, out, err = self.process_epic(now=testkit.T0 + 400)
        self.assertEqual(code, EXIT_PROCESS)
        self.assertIn('a2', err)
        self.assertNotIn('a1', err)

    def test_return_to_an_accepted_state_is_no_information(self):
        self.started()
        first = agent_file('developer', 'Stan A.')
        write(self.main, '.claude/agents/developer.md', first)
        self.process_epic(now=testkit.T0 + 100)
        self.assertEqual(self.process_epic('demo', '--accept-process-mismatch', 'A', now=testkit.T0 + 150)[0], 0)
        write(self.main, '.claude/agents/developer.md', agent_file('developer', 'Stan B.'))
        self.assertEqual(self.process_epic(now=testkit.T0 + 200)[0], EXIT_PROCESS)
        write(self.main, '.claude/agents/developer.md', first)
        self.assertEqual(self.process_epic(now=testkit.T0 + 300)[0], 0)
        last = self.changes()[-1]
        self.assertEqual((last['informational'], last['returned']), (False, True))
        self.assertEqual(self.done()[0], 0)
        record = self.record()
        gaps = self.gaps(record)
        self.assertTrue(any('powrót' in g for g in gaps), gaps)
        self.assertFalse([g for g in gaps if g.startswith('process: informacja')], gaps)
        code, out, err = run_cli(['render', 'demo', '--state', self.state])
        self.assertNotIn('zmian w gałęzi epiku', out)

    def test_n4_failed_check_keeps_the_accepted_reason_in_the_gaps(self):
        self.write_run(base_sha=self.base, process={'status': 'check_failed', 'checked_at': iso_at(0),
                                                    'checked_on': 'start', 'reason': 'git niedostępny',
                                                    'accepted': 'bez gita', 'changes': []})
        self.lead(self.instructions_line())
        self.assertTrue(any('start zaakceptowany: bez gita' in g for g in self.gaps(self.record())))

    def test_n5_missing_worktree_of_the_last_session_is_said(self):
        self.started()
        run = self.run_file()
        run['sessions'][-1]['worktree'] = os.path.join(self.root, 'gone')
        with open(os.path.join(self.state, 'runs', 'demo.json'), 'w') as f:
            json.dump(run, f)
        argv = ['process', 'demo', '--state', self.state, '--user-dir', self.user_dir, '--projects', self.projects]
        with mock.patch('os.getcwd', return_value=self.worktree):
            code, out, err = run_cli(argv, now=testkit.T0 + 100)
        self.assertIn(os.path.join(self.root, 'gone'), err)
        self.assertIn(self.worktree, err)

    def test_n6_session_outside_the_run_file_exit_2(self):
        self.started()
        code, out, err = self.process_epic('demo', '--session', 'obca', now=testkit.T0 + 100)
        self.assertEqual((code, out), (2, ''))
        self.assertIn('obca', err)


NEWER_MD = '# CLAUDE.md\n\nZasady zmienione przez inny PR po bazie epiku.\n'


class QaRoundTest(DiskRuleTest):
    """The findings of the QA of the whole change (Q1, Q3, Q-minor; decisions D-k, D-l, D-m)."""

    test_scenario_of_the_epic = None

    def new_worktree(self, name, *options, commit=None):
        path = os.path.join(self.main, '.claude', 'worktrees', name)
        git(self.main, 'worktree', 'add', '-q', *options, path, *([commit] if commit else []))
        return path

    def test_q1a_resume_in_a_worktree_from_a_newer_main_refuses(self):
        self.started()
        self.merge_from_main({'CLAUDE.md': NEWER_MD})
        git(self.worktree, 'reset', '-q', '--hard', self.base)
        other = self.new_worktree('second', '-b', 'claude/new-session', commit='origin/main')
        self.lead(self.instructions_line(root_md=NEWER_MD, session='s2', worktree=other), session='s2', worktree=other)
        code, out, err = self.start('--resume', session='s2', worktree=other, now=testkit.T0 + 100)
        self.assertEqual(code, 2)
        self.assertEqual([s['id'] for s in self.run_file()['sessions']], ['s1'])

    def test_q1b_resume_in_a_new_worktree_with_an_uncommitted_claude_md_refuses(self):
        self.started()
        other = self.new_worktree('second', '--detach')
        git(other, 'checkout', '-q', '--detach', 'feat/demo')
        changed = '# CLAUDE.md\n\nLokalna, niezacommitowana zmiana zasad.\n'
        write(other, 'CLAUDE.md', changed)
        self.lead(self.instructions_line(root_md=changed, session='s2', worktree=other), session='s2', worktree=other)
        argv = ['start', 'demo', '--resume', '--state', self.state, '--projects', self.projects, '--user-dir',
                self.user_dir, '--session', 's2']
        with mock.patch('os.getcwd', return_value=other):
            code, out, err = run_cli(argv, now=testkit.T0 + 100)
        self.assertEqual(code, 2)
        self.assertIn(other, err)                          # the worktree of the new session is checked (D-k)

    def test_q1_resume_in_a_new_worktree_on_the_epic_branch_works(self):
        self.started()
        git(self.main, 'worktree', 'remove', '--force', self.worktree)
        other = self.new_worktree('second', commit='feat/demo')
        self.lead(self.instructions_line(session='s2', worktree=other), session='s2', worktree=other)
        code, out, err = self.start('--resume', session='s2', worktree=other, now=testkit.T0 + 100)
        self.assertEqual(code, 0, err)

    def test_q3_concurrent_process_and_pause_lose_nothing(self):
        self.started()
        script = os.path.join(testkit.HERE, 'metrics.py')
        base = [sys.executable, script, 'process', 'demo', '--state', self.state, '--user-dir', self.user_dir,
                '--projects', self.projects, '--worktree', self.worktree]
        write(self.main, '.claude/agents/developer.md', agent_file('developer', 'Zmiana w głównym.'))
        workers = [subprocess.Popen(base, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) for _ in range(4)]
        for index in range(10):
            code, out, err = run_cli(['pause', 'demo', '--state', self.state, '--from', iso_at(100 + index * 10),
                                      '--to', iso_at(105 + index * 10)])
            self.assertEqual(code, 0, err)
        code, out, err = self.done(now=testkit.T0 + 4000)
        self.assertEqual(code, 0, err)
        for worker in workers:
            worker.wait(timeout=120)
        run = self.run_file()
        self.assertEqual(len(run['pauses']), 10)
        self.assertEqual(run['done_at'], iso_at(4000))

    def test_q3_process_epic_without_a_change_does_not_write(self):
        self.started()
        self.process_epic(now=testkit.T0 + 100)
        path = os.path.join(self.state, 'runs', 'demo.json')
        before = os.stat(path).st_mtime_ns, open(path, encoding='utf-8').read()
        self.assertEqual(self.process_epic(now=testkit.T0 + 200)[0], 0)
        self.assertEqual((os.stat(path).st_mtime_ns, open(path, encoding='utf-8').read()), before)

    def test_q3_lock_held_by_another_process_ends_with_exit_2(self):
        self.started()
        import fcntl
        lock_path = metrics.lock_path_of(os.path.join(self.state, 'runs', 'demo.json'))
        os.makedirs(os.path.dirname(lock_path), exist_ok=True)
        with open(lock_path, 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            with mock.patch('metrics.LOCK_TIMEOUT_S', 0.2):
                code, out, err = run_cli(['pause', 'demo', '--state', self.state, '--from', iso_at(10), '--to',
                                          iso_at(20)])
        self.assertEqual((code, out), (2, ''))
        self.assertIn('blokad', err)

    def test_qm1_late_reading_gap_tells_the_truth(self):
        self.write_run(base_sha=self.base)
        self.lead(self.instructions_line())
        write(self.main, '.claude/agents/developer.md', 'stara\n')
        self.process_epic(now=testkit.T0 + 100)
        self.assertEqual(self.done()[0], 0)
        gaps = self.gaps(self.record())
        self.assertFalse([g for g in gaps if 'start mimo różnicy' in g], gaps)
        self.assertTrue(any('start bez kontroli' in g and 'bez akceptacji' in g for g in gaps), gaps)

    def test_qm2_active_epics_tell_ready_without_a_record(self):
        self.started()
        self.assertEqual(self.done()[0], 0)
        code, out, err = self.process()
        entry = json.loads(out)['active_epics'][0]
        self.assertEqual((entry['epic'], entry['done_at'], entry['has_record']), ('demo', iso_at(4000), False))
        self.assertIn('metrics.py epic demo', entry['note'])

    def test_qm3_record_outside_a_repository_keeps_the_process(self):
        self.started()
        self.subagent_run('a1', 'code-reviewer', [AGENT_BODY], at=100)
        self.assertEqual(self.done()[0], 0)
        plain = os.path.join(self.root, 'plain')
        os.makedirs(plain)
        code, out, err = run_cli(['epic', 'demo', '--state', self.state, '--night', self.night, '--projects',
                                  self.projects, '--no-gh', '--worktree', plain])
        self.assertEqual(code, 0, err)
        record = json.loads(out)
        process = record['process']
        self.assertEqual(process['expected']['ref'], self.base)
        self.assertTrue(process['disk'])
        self.assertTrue(all(e['status'] == 'unconfirmed' for _, e in metrics.process_version.loaded_items(
            process['loaded'])), process['loaded'])
        self.assertTrue(any('repozytor' in g for g in self.gaps(record)), self.gaps(record))

    def test_qm4_pause_over_work_is_said(self):
        self.write_run(started=0, done=4000)
        self.write_session('s1', Native('s1').human(-50).assistant(10, input=1).assistant(1000))
        code, out, err = run_cli(['pause', 'demo', '--state', self.state, '--projects', self.projects, '--from',
                                  iso_at(100), '--to', iso_at(600)])
        self.assertEqual(code, 0, err)
        self.assertIn('500', err)
        record = self.native_record()
        self.assertEqual(record['breakdown']['host_pause'], 500)
        self.assertTrue(any(g.startswith('pauses: ') and '500' in g for g in record['gaps']), record['gaps'])


class ResumeRefusalTest(DiskRuleTest):
    """R3-1, D-n, D-o, D-p: a refused `start --resume` records what it showed and the same resume with an acceptance
    ends it; after an accepted merge of main a new session that loaded the merged CLAUDE.md resumes without a new
    acceptance; `--at` leaves a trace; the lock reports an error that is not a busy lock at once."""

    test_scenario_of_the_epic = None

    def resume(self, *extra, session='s2', cwd=None, now=testkit.T0 + 1000):
        argv = ['start', 'demo', '--resume', '--state', self.state, '--projects', self.projects, '--user-dir',
                self.user_dir, '--session', session] + list(extra)
        with mock.patch('os.getcwd', return_value=cwd or self.worktree):
            return run_cli(argv, now=now)

    def sessions(self):
        return [s['id'] for s in self.run_file()['sessions']]

    def test_a3_resume_after_an_accepted_merge_of_main_needs_no_new_acceptance(self):
        self.started()
        self.merge_from_main({'CLAUDE.md': NEWER_MD})
        self.assertEqual(self.process_epic(now=testkit.T0 + 100)[0], EXIT_PROCESS)
        self.assertEqual(self.process_epic('demo', '--accept-process-mismatch', 'merge main', now=testkit.T0 + 200)[0], 0)
        self.lead(self.instructions_line(at=900, root_md=NEWER_MD, session='s2'), session='s2')
        code, out, err = self.resume(now=testkit.T0 + 1000)
        self.assertEqual(code, 0, err)
        self.assertEqual(self.sessions(), ['s1', 's2'])
        entry = [e for e in self.run_file()['process']['loaded']['session']
                 if e['session'] == 's2' and e['path'] == 'CLAUDE.md'][0]
        self.assertEqual((entry['status'], entry['explained']), ('differs', 'accepted_disk'))

    def test_a1_refused_resume_then_the_same_resume_with_an_acceptance(self):
        self.started()
        git(self.main, 'worktree', 'remove', '--force', self.worktree)
        other = os.path.join(self.main, '.claude', 'worktrees', 'w2')
        git(self.main, 'worktree', 'add', '-q', other, 'feat/demo')
        changed = '# CLAUDE.md\n\nlokalnie\n'
        write(other, 'CLAUDE.md', changed)
        self.lead(self.instructions_line(at=900, root_md=changed, session='s2', worktree=other), session='s2',
                  worktree=other)
        code, out, err = self.resume(cwd=other)
        self.assertEqual(code, 2)
        self.assertIn('powtórz z --accept-process-mismatch', err)
        self.assertNotIn('metrics.py process demo`', err)
        self.assertEqual(self.sessions(), ['s1'])
        code, out, err = self.resume('--accept-process-mismatch', 'nowa sesja', cwd=other, now=testkit.T0 + 1100)
        self.assertEqual(code, 0, err)
        self.assertEqual(self.sessions(), ['s1', 's2'])

    def test_a_new_mismatch_between_the_refusal_and_the_acceptance_is_refused(self):
        self.started()
        self.merge_from_main({'CLAUDE.md': NEWER_MD})
        self.lead(self.instructions_line(at=900, root_md=NEWER_MD, session='s2'), session='s2')
        self.assertEqual(self.resume(now=testkit.T0 + 1000)[0], 2)
        write(self.main, '.claude/agents/developer.md', agent_file('developer', 'Zmiana w międzyczasie.'))
        code, out, err = self.resume('--accept-process-mismatch', 'merge', now=testkit.T0 + 1100)
        self.assertEqual(code, 2)
        self.assertIn('main_checkout', err)
        self.assertEqual(self.sessions(), ['s1'])
        code, out, err = self.resume('--accept-process-mismatch', 'i główny', now=testkit.T0 + 1200)
        self.assertEqual(code, 0, err)
        self.assertEqual(self.sessions(), ['s1', 's2'])

    def test_resume_and_done_after_the_fact_leave_a_trace(self):
        self.started()
        self.lead(self.instructions_line(session='s2'), session='s2')
        self.assertEqual(self.resume('--at', iso_at(500))[0], 0)
        self.assertEqual(self.done('--at', iso_at(4000))[0], 0)
        skipped = self.changes('not_checked')
        self.assertEqual([(c['event'], c['session'], c['at']) for c in skipped],
                         [('resume', 's2', iso_at(500)), ('done', None, iso_at(4000))])
        gaps = self.gaps(self.record())
        self.assertEqual(len([g for g in gaps if 'kontrola pominięta' in g]), 2, gaps)

    def test_lock_error_other_than_busy_is_reported_at_once(self):
        self.started()
        import errno
        with mock.patch('fcntl.flock', side_effect=OSError(errno.ENOLCK, 'No locks available')):
            code, out, err = run_cli(['pause', 'demo', '--state', self.state, '--from', iso_at(10), '--to',
                                      iso_at(20)])
        self.assertEqual((code, out), (2, ''))
        self.assertIn('No locks available', err)
        self.assertNotIn('innego wywołania', err)

    def test_record_keeps_the_explanation(self):
        stale = 'Stara rola developera z głównego checkoutu.'
        write(self.main, '.claude/agents/developer.md', agent_file('developer', stale))
        self.lead(self.instructions_line())
        self.assertEqual(self.start('--accept-process-mismatch', 'główny checkout nieaktualny')[0], 0)
        self.subagent_run('a1', 'developer', [stale], at=100)
        self.assertEqual(self.process_epic(now=testkit.T0 + 200)[0], 0)
        self.assertEqual(self.done()[0], 0)
        record = self.record()
        agent = record['process']['loaded']['agents'][0]
        self.assertEqual((agent['status'], agent['explained']), ('differs', 'accepted_disk'))
        self.assertEqual(record['process']['loaded']['summary']['differs'], 1)
        gap = [g for g in self.gaps(record) if 'a1' in g][0]
        self.assertIn('zgodna z zaakceptowanym stanem głównego checkoutu', gap)

    def test_skill_from_the_accepted_main_checkout_is_explained(self):
        write(self.main, '.claude/skills/delivery/SKILL.md', '---\nname: delivery\n---\n\nStary skill.\n')
        self.lead(self.instructions_line())
        self.assertEqual(self.start('--accept-process-mismatch', 'stary skill w głównym')[0], 0)
        self.lead(self.instructions_line(), self.skill_line(20, os.path.join(self.main, '.claude/skills/delivery'),
                                                           'Stary skill.'))
        code, out, err = self.process_epic(now=testkit.T0 + 100)
        self.assertEqual(code, 0, err)
        skill = json.loads(out)['loaded']['skills'][0]
        self.assertEqual((skill['status'], skill['explained']), ('differs', 'accepted_disk'))


class SkillArgumentsTest(RecordTest):
    """D-b: the CLI appends `\\n\\n\\nARGUMENTS: <arguments>` to a skill without a placeholder; a skill load of the lead
    before the start of the epic is no evidence."""

    def setUp(self):
        super().setUp()
        write(self.main, '.claude/skills/brainstorm/SKILL.md', '---\nname: brainstorm\ndescription: b\n---\n\n'
                                                               'Zbierz wymagania.\n')
        git(self.main, 'add', '-A')
        git(self.main, 'commit', '-q', '-m', 'brainstorm')
        self.base = git(self.main, 'rev-parse', 'HEAD')
        git(self.main, 'update-ref', 'refs/remotes/origin/main', self.base)
        git(self.worktree, 'reset', '-q', '--hard', self.base)

    def skill(self, at, text):
        return self.skill_line(at, os.path.join(self.main, '.claude/skills/brainstorm'), text)

    def statuses(self, *lines):
        self.lead(self.instructions_line(), *lines)
        self.assertEqual(self.start()[0], 0)
        code, out, err = self.process_epic(now=testkit.T0 + 100)
        return code, [(s['skill'], s['status']) for s in json.loads(out)['loaded']['skills']]

    def test_arguments_appended_by_the_cli_are_no_difference(self):
        self.assertEqual(self.statuses(self.skill(20, 'Zbierz wymagania.\n\n\nARGUMENTS: Kody rabatowe')),
                         (0, [('brainstorm', 'confirmed')]))

    def test_other_trailing_text_differs(self):
        self.assertEqual(self.statuses(self.skill(20, 'Zbierz wymagania.\n\nInny dopisek.')),
                         (EXIT_PROCESS, [('brainstorm', 'differs')]))
        os.remove(os.path.join(self.state, 'runs', 'demo.json'))

    def test_arguments_marker_elsewhere_differs(self):
        self.assertEqual(self.statuses(self.skill(20, 'ARGUMENTS: x\n\n\nZbierz wymagania.'))[1],
                         [('brainstorm', 'differs')])

    def test_skill_load_before_the_start_is_no_evidence(self):
        code, statuses = self.statuses(self.skill(-20, 'Stara treść skilla.'))
        self.assertEqual((code, statuses), (0, [(None, 'unconfirmed')]))


class OldRecordsTest(testkit.Env):
    """Records without `process` (AO and native from before the check) keep working in render, trend and decide."""

    def test_render_trend_and_decide_of_records_without_process(self):
        native = testkit.fake_record('native-old', source='native', breakdown_incomplete=True)
        ao = testkit.fake_record('ao-old', merged='2026-11-11T10:00:00Z')
        testkit.store_records(self.state, native, ao)
        code, out, err = run_cli(['render', 'native-old', '--state', self.state])
        self.assertEqual(code, 0, err)
        self.assertIn('Wersja procesu: brak danych', out)
        code, out, err = run_cli(['render', 'ao-old', '--state', self.state])
        self.assertEqual(code, 0, err)
        self.assertNotIn('Wersja procesu', out)
        for argv in (['trend', '--no-gh'], ['decide', 'native-old'], ['decide', 'ao-old']):
            code, out, err = run_cli(argv + ['--state', self.state])
            self.assertEqual(code, 0, (argv, err))


if __name__ == '__main__':
    unittest.main()

#!/usr/bin/env python3
"""Automatic one-time extensions (R2) for the /ao-run skill. Python 3.9+, standard library only.

Instead of asking the user for a mechanical "yes", /ao-run asks this script whether AO may get one more QA round of
an epic or one more fix round of a task. The script only reads $AO_HOME (orchestrator.db read-only, events.jsonl,
auto-extend.json) and prints one JSON line with a decision. Every uncertain condition is "ask" (fail closed).

  qa AO_HOME --epic ID
      {"decision": "extend"|"ask", "reasons": [...], "round": N|null, "cost_usd": X|null, "max_usd": Y}
      extend when all hold:
      Q1 the epic is BLOCKED with a reason starting with max_qa_rounds;
      Q2 events.jsonl has no auto_action qa-round without a task (a missing file counts as none, lines that are not
         JSON objects are skipped), and AO has no QA rounds added to the epic (epics.config limits.add_qa_rounds);
      Q3 the last round (highest `round` of the epic's integration_findings events) has critical 0 and a non-empty
         blocking list;
      Q4 no blocking finding of the last round repeats a blocking finding of an earlier round (see is_repeat);
      Q5 the cost of the epic (attempts.cost_usd of its tasks) is readable and below max_usd: a running attempt or a
         finished one without cost_usd is "ask";
      Q6 no task of the epic is BLOCKED or FAILED or has a blocker of type business, architecture or cost.
  fix AO_HOME --task ID
      {"decision", "reasons", "cost_usd", "max_usd", "answer"}; answer is the fixed text of ANSWER on extend, else null.
      extend when all hold:
      F1 the task is BLOCKED, its latest attempt has executor_status BLOCKED and result.blockers is exactly one
         blocker with id EXTRA-FIX-ROUND and type technical;
      F2 no earlier attempt of the task has an EXTRA-FIX-ROUND blocker and events.jsonl has no auto_action fix-round
         of the task (the loop guard);
      F3 the cost of the task's epic as in Q5 (a task without an epic: its own attempts);
      F4 no task of the epic (without an epic: the task) has a blocker of type business, architecture or cost.

Configuration: AO_HOME/auto-extend.json {"max_usd": <positive number>}; without the file max_usd is 150.
Exit code 0 with the decision; 1 with a message on stderr for bad arguments, a missing or unreadable orchestrator.db
or an unreadable or invalid auto-extend.json. /ao-run treats an exit code other than 0 as "ask".
"""
import argparse
import json
import math
import os
import re
import sqlite3
import sys

DEFAULT_MAX_USD = 150
ANSWER = ('Automatyczna zgoda (R2): jedna dodatkowa runda poprawek w tym zadaniu. '
          'Gdy po niej problem zostanie, zwróć BLOCKED bez kolejnej rundy.')
EXTRA_ID = 'EXTRA-FIX-ROUND'
HUMAN_BLOCKERS = ('business', 'architecture', 'cost')
STOP_STATUSES = ('BLOCKED', 'FAILED')
MIN_WORD = 4
SIMILAR = 0.5
SIMILAR_SAME_TASK = 0.25
STEM = 5
SHARED_STEMS = 2
WORD = re.compile(r'\w+')


class Error(Exception):
    pass


# ------------------------------------------------------------------------------------------------- similarity (Q4)

def fingerprint(finding):
    """AO's fingerprint (orchestrator/integration.py): severity, title without case and extra spaces, sorted files."""
    return (finding.get('severity'), ' '.join(str(finding.get('title', '')).lower().split()),
            tuple(sorted(str(f) for f in finding.get('files') or [])))


def words(title):
    """Lowercased words of at least MIN_WORD characters (Unicode \\w+)."""
    return {w for w in WORD.findall(str(title).lower()) if len(w) >= MIN_WORD}


def jaccard(a, b):
    left, right = words(a), words(b)
    union = left | right
    return len(left & right) / len(union) if union else 0.0


def stems(title):
    """Rough stems of the words of a title, so that inflected Polish forms match: a leading negation `nie` of a longer
    word is dropped, and each word of at least MIN_WORD characters is cut to STEM characters."""
    found = set()
    for word in WORD.findall(str(title).lower()):
        if word.startswith('nie') and len(word) > 6:
            word = word[3:]
        if len(word) >= MIN_WORD:
            found.add(word[:STEM])
    return found


def is_repeat(new, old):
    """Whether two blocking findings name the same problem: equal fingerprints, similar titles, the same task with
    a common file and partly similar titles, or a common file with at least SHARED_STEMS common word stems (the same
    rule broken in another place). Files alone never make a repeat."""
    if fingerprint(new) == fingerprint(old):
        return True
    similarity = jaccard(new.get('title', ''), old.get('title', ''))
    if similarity >= SIMILAR:
        return True
    common_file = bool(set(new.get('files') or []) & set(old.get('files') or []))
    if not common_file:
        return False
    same_task = new.get('task_id') is not None and new.get('task_id') == old.get('task_id')
    if same_task and similarity >= SIMILAR_SAME_TASK:
        return True
    return len(stems(new.get('title', '')) & stems(old.get('title', ''))) >= SHARED_STEMS


# ------------------------------------------------------------------------------------------------------- sources

def load_max_usd(home):
    path = os.path.join(home, 'auto-extend.json')
    if not os.path.exists(path):
        return DEFAULT_MAX_USD
    try:
        with open(path, encoding='utf-8') as f:
            value = json.load(f)
    except (OSError, ValueError) as e:
        raise Error('cannot read %s: %s' % (path, e))
    limit = value.get('max_usd') if isinstance(value, dict) else None
    if isinstance(limit, bool) or not isinstance(limit, (int, float)) or not math.isfinite(limit) or limit <= 0:
        raise Error('%s: max_usd must be a positive number' % path)
    return limit


def open_db(home):
    path = os.path.join(home, 'orchestrator.db')
    if not os.path.isfile(path):
        raise Error('no orchestrator.db in %s' % home)
    try:
        db = sqlite3.connect('file:%s?mode=ro' % path, uri=True)
        db.row_factory = sqlite3.Row
        db.execute('SELECT 1 FROM tasks, epics, attempts, events LIMIT 0').fetchall()
    except sqlite3.Error as e:
        raise Error('cannot read %s: %s' % (path, e))
    return db


def auto_actions(home):
    """The auto_action events of events.jsonl as (action, task_id), or None when the file exists but is unreadable."""
    path = os.path.join(home, 'events.jsonl')
    found = []
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            for line in f:
                try:
                    event = json.loads(line)
                except ValueError:
                    continue
                if not isinstance(event, dict) or event.get('type') != 'auto_action':
                    continue
                data = event.get('data')
                if isinstance(data, dict):
                    found.append((data.get('action'), event.get('task_id')))
    except FileNotFoundError:
        return []
    except OSError:
        return None
    return found


def parse_list(text):
    """A JSON list from a column (NULL is an empty list), or None when it is not one."""
    if text is None:
        return []
    try:
        value = json.loads(text)
    except ValueError:
        return None
    return value if isinstance(value, list) else None


def epic_cost(db, epic_id, task_id=None):
    """(cost or None, reasons): the sum of attempts.cost_usd of the epic's tasks (without an epic: of the task)."""
    if epic_id is None:
        rows = db.execute('SELECT task_id, number, ended_at, cost_usd FROM attempts WHERE task_id = ?',
                          (task_id,)).fetchall()
    else:
        rows = db.execute('SELECT a.task_id, a.number, a.ended_at, a.cost_usd FROM attempts a '
                          'JOIN tasks t ON t.task_id = a.task_id WHERE t.epic_id = ?', (epic_id,)).fetchall()
    running = ['%s/%s' % (r['task_id'], r['number']) for r in rows if r['ended_at'] is None]
    unknown = ['%s/%s' % (r['task_id'], r['number']) for r in rows
               if r['ended_at'] is not None and (r['cost_usd'] is None or isinstance(r['cost_usd'], str))]
    reasons = []
    if running:
        reasons.append('próba w toku: %s' % ', '.join(running))
    if unknown:
        reasons.append('koszt nieczytelny: %s' % ', '.join(unknown))
    if reasons:
        return None, reasons
    return round(sum(float(r['cost_usd']) for r in rows), 6), []


def cost_reasons(label, cost, problems, max_usd):
    if problems:
        return ['%s %s' % (label, p) for p in problems]
    if cost >= max_usd:
        return ['%s koszt %.2f USD osiąga limit %s USD' % (label, cost, max_usd)]
    return []


def human_blockers(rows):
    """Reasons for tasks with a blocker of type business, architecture or cost, or with unreadable blockers."""
    reasons = []
    for row in rows:
        blockers = parse_list(row['blockers'])
        if blockers is None:
            reasons.append('zadanie %s ma nieczytelne blokery' % row['task_id'])
            continue
        kinds = sorted({b.get('type') for b in blockers if isinstance(b, dict) and b.get('type') in HUMAN_BLOCKERS})
        if kinds:
            reasons.append('zadanie %s ma bloker typu %s' % (row['task_id'], ', '.join(kinds)))
    return reasons


# ------------------------------------------------------------------------------------------------------- qa

def epic_rounds(db, epic_id):
    """{round: [data, ...]} of the epic's integration_findings events, or a reason when one is unreadable."""
    rows = db.execute("SELECT e.data FROM events e JOIN tasks t ON t.task_id = e.task_id "
                      "WHERE e.type = 'integration_findings' AND t.epic_id = ? ORDER BY e.id", (epic_id,)).fetchall()
    rounds = {}
    for row in rows:
        try:
            data = json.loads(row['data'])
        except ValueError:
            return None, 'nieczytelne zdarzenie integration_findings'
        if not isinstance(data, dict) or isinstance(data.get('round'), bool) or not isinstance(data.get('round'), int):
            return None, 'zdarzenie integration_findings bez numeru rundy'
        rounds.setdefault(data['round'], []).append(data)
    return rounds, None


def valid_findings(items):
    return isinstance(items, list) and all(isinstance(f, dict) and isinstance(f.get('title'), str) for f in items)


def added_qa_rounds(config):
    """The QA rounds `ao epic resume --add-qa-rounds` added to the epic (AO keeps them in epics.config.limits), the
    record that survives a lost auto_action; None when the config cannot be read."""
    if config in (None, ''):
        return 0
    try:
        value = json.loads(config)
    except ValueError:
        return None
    limits = value.get('limits') if isinstance(value, dict) else None
    added = (limits or {}).get('add_qa_rounds', 0) if isinstance(limits, (dict, type(None))) else None
    return added if isinstance(added, int) and not isinstance(added, bool) and added >= 0 else None


def decide_qa(db, home, epic_id, max_usd):
    reasons, last = [], None
    epic = db.execute('SELECT status, reason, config FROM epics WHERE epic_id = ?', (epic_id,)).fetchone()
    if epic is None:
        reasons.append('Q1: brak epiku %s' % epic_id)
    elif epic['status'] != 'BLOCKED' or not str(epic['reason'] or '').startswith('max_qa_rounds'):
        reasons.append('Q1: epik %s (%s), a nie BLOCKED max_qa_rounds' % (epic['status'], epic['reason']))

    actions = auto_actions(home)
    if actions is None:
        reasons.append('Q2: nie da się odczytać events.jsonl')
    elif any(action == 'qa-round' and task is None for action, task in actions):
        reasons.append('Q2: epik dostał już automatyczną rundę QA')
    if epic is not None:
        added = added_qa_rounds(epic['config'])
        if added is None:
            reasons.append('Q2: nie da się odczytać konfiguracji epiku w AO')
        elif added > 0:
            reasons.append('Q2: epik dostał już dodatkowe rundy QA w AO (add_qa_rounds %d)' % added)

    rounds, problem = epic_rounds(db, epic_id)
    if problem:
        reasons.append('Q3: %s' % problem)
    elif not rounds:
        reasons.append('Q3: brak zdarzenia integration_findings epiku')
    else:
        last = max(rounds)
        latest = rounds[last][-1]
        blocking = latest.get('blocking')
        earlier = [f for r in sorted(rounds) if r < last for d in rounds[r] for f in (d.get('blocking') or [])]
        if latest.get('critical') != 0:
            reasons.append('Q3: runda %d ma znaleziska Critical (%s)' % (last, latest.get('critical')))
        if not valid_findings(blocking) or not blocking:
            reasons.append('Q3: runda %d nie ma czytelnej, niepustej listy blocking' % last)
        elif not valid_findings(earlier):
            reasons.append('Q4: nieczytelne znaleziska wcześniejszych rund')
        else:
            reasons += repeats(blocking, last, rounds)

    cost, problems = (None, []) if epic is None else epic_cost(db, epic_id)
    if epic is not None:
        reasons += cost_reasons('Q5:', cost, problems, max_usd)

    tasks = db.execute('SELECT task_id, status, blockers FROM tasks WHERE epic_id = ?', (epic_id,)).fetchall()
    stopped = ['%s (%s)' % (t['task_id'], t['status']) for t in tasks if t['status'] in STOP_STATUSES]
    if stopped:
        reasons.append('Q6: zadania zatrzymane: %s' % ', '.join(stopped))
    reasons += ['Q6: %s' % r for r in human_blockers(tasks)]
    return {'decision': 'ask' if reasons else 'extend', 'reasons': reasons, 'round': last, 'cost_usd': cost,
            'max_usd': max_usd}


def repeats(blocking, last, rounds):
    reasons = []
    for new in blocking:
        for number in sorted(r for r in rounds if r < last):
            for data in rounds[number]:
                for old in data.get('blocking') or []:
                    if is_repeat(new, old):
                        reasons.append('Q4: powtórka: runda %d %s „%s” = runda %d %s „%s”' % (
                            last, new.get('id'), new.get('title'), number, old.get('id'), old.get('title')))
    return reasons


# ------------------------------------------------------------------------------------------------------- fix

def attempt_blockers(row):
    """The blockers of an attempt's result, or None when the result is unreadable."""
    try:
        result = json.loads(row['result']) if row['result'] is not None else None
    except ValueError:
        return None
    blockers = result.get('blockers') if isinstance(result, dict) else None
    return blockers if isinstance(blockers, list) else None


def is_extra(blocker):
    return isinstance(blocker, dict) and blocker.get('id') == EXTRA_ID


def decide_fix(db, home, task_id, max_usd):
    reasons = []
    task = db.execute('SELECT task_id, status, epic_id FROM tasks WHERE task_id = ?', (task_id,)).fetchone()
    attempts = db.execute('SELECT number, executor_status, result FROM attempts WHERE task_id = ? ORDER BY number',
                          (task_id,)).fetchall()
    if task is None:
        reasons.append('F1: brak zadania %s' % task_id)
    elif task['status'] != 'BLOCKED':
        reasons.append('F1: zadanie ma status %s, a nie BLOCKED' % task['status'])
    if not attempts:
        reasons.append('F1: zadanie nie ma prób')
    else:
        latest = attempts[-1]
        blockers = attempt_blockers(latest)
        if latest['executor_status'] != 'BLOCKED':
            reasons.append('F1: ostatnia próba ma executor_status %s' % latest['executor_status'])
        if blockers is None:
            reasons.append('F1: nieczytelny wynik ostatniej próby')
        elif not (len(blockers) == 1 and is_extra(blockers[0]) and blockers[0].get('type') == 'technical'):
            reasons.append('F1: ostatnia próba nie ma dokładnie jednego blokera %s typu technical' % EXTRA_ID)
        for earlier in attempts[:-1]:
            found = attempt_blockers(earlier)
            if found is None and earlier['result'] is not None:
                reasons.append('F2: nieczytelny wynik próby %d' % earlier['number'])
            elif any(is_extra(b) for b in found or []):
                reasons.append('F2: próba %d już prosiła o dodatkową rundę' % earlier['number'])

    actions = auto_actions(home)
    if actions is None:
        reasons.append('F2: nie da się odczytać events.jsonl')
    elif any(action == 'fix-round' and task == task_id for action, task in actions):
        reasons.append('F2: zadanie dostało już automatyczną rundę poprawek')

    cost = None
    if task is not None:
        cost, problems = epic_cost(db, task['epic_id'], task_id)
        reasons += cost_reasons('F3:', cost, problems, max_usd)
        if task['epic_id'] is None:
            rows = db.execute('SELECT task_id, blockers FROM tasks WHERE task_id = ?', (task_id,)).fetchall()
        else:
            rows = db.execute('SELECT task_id, blockers FROM tasks WHERE epic_id = ?', (task['epic_id'],)).fetchall()
        reasons += ['F4: %s' % r for r in human_blockers(rows)]
    decision = 'ask' if reasons else 'extend'
    return {'decision': decision, 'reasons': reasons, 'cost_usd': cost, 'max_usd': max_usd,
            'answer': ANSWER if decision == 'extend' else None}


# ------------------------------------------------------------------------------------------------------- CLI

class Parser(argparse.ArgumentParser):
    def error(self, message):
        self.print_usage(sys.stderr)
        print('auto_extend: %s' % message, file=sys.stderr)
        sys.exit(1)


def main():
    parser = Parser(description='Automatic one-time extensions (R2) for /ao-run')
    commands = parser.add_subparsers(dest='command', required=True, parser_class=Parser)
    qa = commands.add_parser('qa')
    qa.add_argument('home')
    qa.add_argument('--epic', required=True)
    fix = commands.add_parser('fix')
    fix.add_argument('home')
    fix.add_argument('--task', required=True)
    args = parser.parse_args()
    try:
        max_usd = load_max_usd(args.home)
        db = open_db(args.home)
        try:
            if args.command == 'qa':
                result = decide_qa(db, args.home, args.epic, max_usd)
            else:
                result = decide_fix(db, args.home, args.task, max_usd)
        finally:
            db.close()
    except Error as e:
        print('auto_extend: %s' % e, file=sys.stderr)
        return 1
    except sqlite3.Error as e:
        print('auto_extend: cannot read orchestrator.db: %s' % e, file=sys.stderr)
        return 1
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())

"""The runner fields of a case (tools/evals/README.md, "Przypadek"): the prompt, the input files, the gh data, the
patch and the canary file, with their placeholders. Python 3.9+, standard library only.

`evals.py lint` checks the shape of the fields that are present (validate_runner_fields); the runner also requires
what it needs to run a case (strict: a prompt, and an injection case delivering its payload).

Placeholders `{{NAME}}` are filled by the runner when it writes the pass: BASE and HEAD (the commits of the checkout
the agent reviews), PASS_DIR, RESULT_DIR (files/), INPUT_DIR (input/), CANARY_FILE, CANARY_TOKEN and, in an injection
case only, PAYLOAD (the decoded payload_b64; its own placeholders are filled too). The payload is decoded only into the
pass directory.
"""
import hashlib
import json
import os
import re

import evals_assertions as assertions
import evals_lint as lint
import evals_registry as registry

RUNNER_KEYS = ('prompt', 'prompt_file', 'inputs', 'gh', 'patch', 'canary_file')
PLACEHOLDERS = ('BASE', 'HEAD', 'PASS_DIR', 'RESULT_DIR', 'INPUT_DIR', 'CANARY_FILE', 'CANARY_TOKEN', 'PAYLOAD')
PATCH_PLACEHOLDERS = ('PASS_DIR', 'RESULT_DIR', 'INPUT_DIR', 'CANARY_FILE', 'CANARY_TOKEN', 'PAYLOAD')
# Upper-case names only: lower-case template braces in case data ({{count}} of a Vue file) are left alone.
PLACEHOLDER_RE = re.compile(r'\{\{([A-Z][A-Z0-9_]*)\}\}')
CANARY_NAME_RE = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$')
GH_KEYS = {'args', 'stdout', 'stdout_file', 'exit_code'}


class CaseError(Exception):
    """A case cannot be run: a missing or malformed runner field, an unknown placeholder."""


def _text(read, name):
    raw = read(name)
    if raw is None:
        return None
    try:
        return raw.decode('utf-8')
    except UnicodeDecodeError:
        return None


def _file(value, label, read, problems):
    """The text of a case file named by a field, None (with a problem) when it is not a readable file of the case."""
    if not registry.safe_relative_path(value) or value == 'case.json':
        problems.append('%s must be the relative path of a file of the case other than case.json' % label)
        return None
    text = _text(read, value)
    if text is None:
        problems.append('%s %s is not a UTF-8 file of the case' % (label, value))
    return text


def _placeholders(text, label, allowed, problems):
    for name in sorted(set(PLACEHOLDER_RE.findall(text))):
        if name not in allowed:
            problems.append('%s has the placeholder {{%s}}, which is not available there (%s)'
                            % (label, name, ', '.join('{{%s}}' % p for p in allowed)))


def validate_runner_fields(data, read, strict=False):
    """Problems of the runner fields of a parsed case.json; read(name) gives the bytes of a file of the case directory
    (None when missing). strict: what the runner needs on top of the lint."""
    if not isinstance(data, dict):
        return []
    problems = []
    injection = data.get('kind') == 'injection'
    allowed = PLACEHOLDERS if injection else tuple(p for p in PLACEHOLDERS if p != 'PAYLOAD')
    allowed_patch = tuple(p for p in PATCH_PLACEHOLDERS if p in allowed)
    texts = []   # (label, text, allowed placeholders)
    if 'prompt' in data and 'prompt_file' in data:
        problems.append('a case has prompt or prompt_file, not both')
    elif 'prompt' in data:
        if not isinstance(data['prompt'], str) or not data['prompt'].strip():
            problems.append('prompt must be a non-empty string')
        else:
            texts.append(('prompt', data['prompt'], allowed))
    elif 'prompt_file' in data:
        text = _file(data['prompt_file'], 'prompt_file', read, problems)
        if text is not None:
            texts.append(('prompt_file %s' % data['prompt_file'], text, allowed))
    elif strict:
        problems.append('the runner needs a prompt (prompt or prompt_file)')
    if 'inputs' in data:
        inputs = data['inputs']
        if not isinstance(inputs, list) or len(set(map(str, inputs))) != len(inputs):
            problems.append('inputs must be a list of unique files of the case')
        else:
            for name in inputs:
                text = _file(name, 'inputs', read, problems)
                if text is not None:
                    texts.append(('input %s' % name, text, allowed))
    if 'gh' in data:
        gh = data['gh']
        if not isinstance(gh, list):
            problems.append('gh must be a list of {args, stdout or stdout_file, exit_code}')
        else:
            seen = []
            for index, answer in enumerate(gh):
                label = 'gh[%d]' % index
                if not isinstance(answer, dict) or set(answer) - GH_KEYS:
                    problems.append('%s must be an object with the keys %s' % (label, ', '.join(sorted(GH_KEYS))))
                    continue
                args = answer.get('args')
                if not isinstance(args, list) or not args or not all(isinstance(a, str) for a in args):
                    problems.append('%s.args must be the non-empty list of the exact arguments of gh' % label)
                elif args in seen:
                    problems.append('%s.args repeats an earlier answer' % label)
                else:
                    seen.append(args)
                if 'stdout' in answer and 'stdout_file' in answer:
                    problems.append('%s has stdout or stdout_file, not both' % label)
                elif 'stdout' in answer:
                    if not isinstance(answer['stdout'], str):
                        problems.append('%s.stdout must be a string' % label)
                    else:
                        texts.append((label + '.stdout', answer['stdout'], allowed))
                elif 'stdout_file' in answer:
                    text = _file(answer['stdout_file'], label + '.stdout_file', read, problems)
                    if text is not None:
                        texts.append((label + '.stdout_file', text, allowed))
                code = answer.get('exit_code', 0)
                if not isinstance(code, int) or isinstance(code, bool) or not 0 <= code <= 255:
                    problems.append('%s.exit_code must be an integer 0-255' % label)
    if 'patch' in data:
        text = _file(data['patch'], 'patch', read, problems)
        if text is not None:
            texts.append(('patch %s' % data['patch'], text, allowed_patch))
    if 'canary_file' in data:
        if not injection:
            problems.append('canary_file is only for the kind injection')
        elif not isinstance(data['canary_file'], str) or not CANARY_NAME_RE.match(data['canary_file']):
            problems.append('canary_file must be a file name (letters, digits, `.`, `_`, `-`; no directory)')
    for label, text, names in texts:
        _placeholders(text, label, names, problems)
    if strict and injection and not any('{{PAYLOAD}}' in text for _, text, _ in texts):
        problems.append('an injection case must deliver its payload: put {{PAYLOAD}} in the prompt, an input, '
                        'a gh answer or the patch')
    return problems


def render(text, values):
    """The text with its placeholders filled: {{PAYLOAD}} first (the payload may hold other placeholders), then the
    rest. CaseError for a placeholder without a value."""
    if 'PAYLOAD' in values:
        text = text.replace('{{PAYLOAD}}', values['PAYLOAD'])

    def fill(match):
        name = match.group(1)
        if name not in values or name == 'PAYLOAD':
            raise CaseError('the placeholder {{%s}} has no value here' % name)
        return values[name]
    return PLACEHOLDER_RE.sub(fill, text)


# --------------------------------------------------------------------------------------------------- loading

class Case:
    """A case of a suite read from the disk, validated for the runner."""

    def __init__(self, root, entry, case_id, suite, canary_actions):
        self.id = case_id
        self.dir = os.path.join(root, *entry['cases'].split('/'), case_id)
        raw = self.read('case.json')
        if raw is None:
            raise CaseError('the case %s of %s has no case.json' % (case_id, suite))
        self.hash = hashlib.sha256(raw).hexdigest()
        try:
            self.data = json.loads(raw.decode('utf-8'))
        except (ValueError, UnicodeDecodeError) as error:
            raise CaseError('%s/case.json is not valid JSON: %s' % (case_id, error))
        problems = lint.validate_case(self.data, case_id, suite, canary_actions) + \
            validate_runner_fields(self.data, self.read, strict=True)
        # the lint only names the assertion types; a run costs money, so the parameters are checked before it starts
        for item in self.data.get('assertions') or []:
            problems.extend('assertion %s' % problem for problem in assertions.problems(item))
        if problems:
            raise CaseError('the case %s of %s is not valid: %s' % (case_id, suite, '; '.join(problems)))
        self.kind = self.data['kind']
        self.injection = self.kind == 'injection'

    def read(self, name):
        if not registry.safe_relative_path(name):
            return None
        path = os.path.join(self.dir, *name.split('/'))
        if os.path.islink(path) or not os.path.isfile(path):
            return None
        with open(path, 'rb') as handle:
            return handle.read()

    def text(self, name):
        return self.read(name).decode('utf-8')

    @property
    def state(self):
        if 'commit' in self.data:
            return {'commit': self.data['commit']}
        return {'base_sha': self.data['base_sha'], 'head_sha': self.data['head_sha']}

    def payload(self):
        """The decoded payload (injection only): call it only when writing the pass directory."""
        return lint.decode_payload(self.data['payload_b64']) if self.injection else None

    def prompt_template(self):
        return self.data['prompt'] if 'prompt' in self.data else self.text(self.data['prompt_file'])

    def patch_template(self):
        return self.text(self.data['patch']) if 'patch' in self.data else None

    def inputs(self):
        return [(name, self.text(name)) for name in self.data.get('inputs', [])]

    def gh(self):
        answers = []
        for answer in self.data.get('gh', []):
            stdout = answer.get('stdout', '')
            if 'stdout_file' in answer:
                stdout = self.text(answer['stdout_file'])
            answers.append({'args': list(answer['args']), 'stdout': stdout, 'exit_code': answer.get('exit_code', 0)})
        return answers

"""Reader of the artefacts of one pass (spec: Artefakty przebiegu). Python 3.9+, standard library only.

The scoring only reads: a pass directory holds what the runner wrote (status.json, output.txt, tree.txt, canary.txt and
runner/tool_use.jsonl, which the agent cannot write in the real sandbox) and what the agent could change (files/,
gh.log, ssh.log, input/). Nothing is followed through a symbolic link, because the agent may plant one, and every read is
limited in size. A missing or damaged artefact is an `ArtefactError`, which the assertions turn into a failure.
"""
import json
import os

import evals_reports as reports

READ_LIMIT = 8 << 20
FILES_LIMIT = 500


class ArtefactError(Exception):
    """An artefact of a pass is missing, is not a regular file or cannot be read."""

    def __init__(self, name, why='is missing'):
        Exception.__init__(self, '%s %s' % (name, why))
        self.name = name


class PassArtifacts:
    def __init__(self, directory):
        self.directory = directory
        self._reports = {}

    # ------------------------------------------------------------------------------------------------ reading

    def safe_path(self, *parts):
        """The path below the pass directory, or None when a component is missing or is a symbolic link."""
        current = self.directory
        for part in parts:
            current = os.path.join(current, part)
            try:
                if os.path.islink(current):
                    return None
                os.lstat(current)
            except OSError:
                return None
        return current

    def read_bytes(self, *parts):
        """The content (at most READ_LIMIT bytes) of a regular file below the pass directory; None when it is not
        there, is a symbolic link or not a regular file."""
        path = self.safe_path(*parts)
        if path is None or not os.path.isfile(path):
            return None
        try:
            with open(path, 'rb') as handle:
                return handle.read(READ_LIMIT)
        except OSError:
            return None

    def read_text(self, *parts):
        raw = self.read_bytes(*parts)
        return None if raw is None else raw.decode('utf-8', 'replace')

    def _require(self, *parts):
        text = self.read_text(*parts)
        if text is None:
            raise ArtefactError('/'.join(parts))
        return text

    # ---------------------------------------------------------------------------------------------- artefacts

    def status(self):
        """status.json as a dict ({} when it is missing or damaged)."""
        text = self.read_text('status.json')
        try:
            data = json.loads(text) if text else {}
        except ValueError:
            return {}
        return data if isinstance(data, dict) else {}

    def output(self):
        return self._require('output.txt')

    def report(self, agent):
        """The parsed report of the agent (the final answer in output.txt)."""
        if agent not in self._reports:
            self._reports[agent] = reports.parse(self.output(), agent)
        return self._reports[agent]

    def read_file(self, name):
        """The text of a file the agent wrote in files/ (None when it is not a regular file)."""
        if not name or name.startswith('/') or '..' in name.split('/') or '\0' in name:
            return None
        return self.read_text('files', *name.split('/'))

    def file_texts(self):
        """[(name, text)] of the regular files below files/ (links are not followed), at most FILES_LIMIT."""
        base = self.safe_path('files')
        found = []
        if base is None or not os.path.isdir(base):
            return found
        for current, dirs, names in os.walk(base):
            dirs[:] = sorted(d for d in dirs if not os.path.islink(os.path.join(current, d)))
            for name in sorted(names):
                full = os.path.join(current, name)
                if os.path.islink(full) or not os.path.isfile(full):
                    continue
                relative = os.path.relpath(full, base).replace(os.sep, '/')
                found.append((relative, self.read_text('files', *relative.split('/')) or ''))
                if len(found) >= FILES_LIMIT:
                    return found
        return found

    def gh_calls(self):
        """The calls of the gh stub: ([argument lists], tampered). A non-empty line that is not a JSON object with an
        argument list means the log was changed by the agent."""
        return _json_lines(self._require('gh.log'), lambda entry: isinstance(entry.get('args'), list),
                           lambda entry: [str(a) for a in entry['args']])

    def ssh_entries(self):
        """The attempts to reach a remote: ([entries], tampered); any non-empty line is an attempt."""
        text = self._require('ssh.log')
        entries = [line for line in text.splitlines() if line.strip()]
        return entries, False

    def tree(self):
        """[(operation, path)] of tree.txt: the changes of the checkout."""
        changes = []
        for line in self._require('tree.txt').splitlines():
            if not line.strip():
                continue
            operation, _, path = line.partition('\t')
            if operation not in ('A', 'M', 'D') or not path:
                raise ArtefactError('tree.txt', 'has a line that is not `A|M|D<tab>path`')
            changes.append((operation, path))
        return changes

    def canary(self):
        """(path, token) of the canary of an injection pass; None when there is none (an empty canary.txt)."""
        lines = [line for line in self._require('canary.txt').splitlines() if line.strip()]
        if not lines:
            return None
        if len(lines) != 2:
            raise ArtefactError('canary.txt', 'is not a path and a token')
        return lines[0], lines[1]

    def tool_uses(self):
        """The calls of tools of the agent as the runner stored them (runner/tool_use.jsonl)."""
        text = self._require('runner', 'tool_use.jsonl')
        calls = []
        for line in text.splitlines():
            if not line.strip():
                continue
            try:
                call = json.loads(line)
            except ValueError:
                raise ArtefactError('runner/tool_use.jsonl', 'has a line that is not JSON')
            if isinstance(call, dict):
                calls.append(call)
        return calls


def _json_lines(text, valid, convert):
    items, tampered = [], False
    for line in text.splitlines():
        if not line.strip():
            continue
        try:
            entry = json.loads(line)
        except ValueError:
            tampered = True
            continue
        if isinstance(entry, dict) and valid(entry):
            items.append(convert(entry))
        else:
            tampered = True
    return items, tampered

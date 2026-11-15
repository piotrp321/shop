"""Findings of a command of tools/evals: errors (exit code 1) and warnings, printed one per line."""
import os


class Report:
    def __init__(self):
        self.errors = []
        self.warnings = []
        self.notes = []

    def error(self, message):
        if message not in self.errors:
            self.errors.append(message)

    def warn(self, message):
        if message not in self.warnings:
            self.warnings.append(message)

    def note(self, message):
        self.notes.append(message)

    def print(self, out):
        """Writes the notes, warnings and errors; GitHub Actions gets workflow annotations for the last two."""
        annotate = os.environ.get('GITHUB_ACTIONS') == 'true'
        for message in self.notes:
            out.write('ok     %s\n' % message)
        for message in self.warnings:
            out.write(('::warning::%s\n' % message) if annotate else ('WARNING %s\n' % message))
        for message in self.errors:
            out.write(('::error::%s\n' % message) if annotate else ('ERROR   %s\n' % message))

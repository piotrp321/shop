"""Parser of the reports of the agents under evaluation: the verdict and the findings with their severity. Python 3.9+,
standard library only.

Two agents, one scale. code-reviewer reports BLOCKER > IMPORTANT > SUGGESTION and the verdict PASS or CHANGES_REQUIRED;
spec-critic reports Critical > Important > Minor and the verdict GOTOWA or DO POPRAWY. The names of the two vocabularies
share three ranks (3: BLOCKER, Critical; 2: IMPORTANT, Important; 1: SUGGESTION, Minor), because the real reports mix
them (an older code-reviewer prompt used Critical/Important/Minor).

Findings. The reports are free markdown, so a finding is recognised by its shape, not by a fixed template:

  * a *label line* starts with a severity word after its markup (heading marks, list marker, bold) and an optional
    identifier: `**IMPORTANT-1 (Important): title**`, `### IMPORTANT 1: title`, `1. **SUGGESTION**: path`,
    `**M1 (Minor) - title**`, `- **Minor, Suggestion** (path): text`;
  * a *severity section* is a heading or a bold line that names only a severity (`### Important`, `**MINOR**`,
    `## Uwagi Minor`); the items directly below it are findings of that severity: bold paragraphs
    (`**I1. title**` followed by its bullets), else numbered or bulleted items (`- **M1. title**`), else deeper headings.

A finding is its first line and everything that belongs to it (its sub-items, its continuation lines). A new label
line, a new section, a heading of the same or a higher level, or a plain paragraph after a blank line ends it, so the
mandatory checks, the verdict summary and the lists of files that mention severities in prose are not findings.
A label line that says there is nothing (`**BLOCKER**: brak`) is not a finding.

`match_finding` is the matcher of the `finding` assertion: one finding must satisfy every condition (the path pattern
and the keyword pattern both in the same finding), so a path in one list item and a keyword in another do not match.
"""
import re

SEVERITY_RANKS = {'blocker': 3, 'critical': 3, 'important': 2, 'suggestion': 1, 'minor': 1, 'sugestia': 1}
SEVERITY_NAMES = ('BLOCKER', 'IMPORTANT', 'SUGGESTION', 'Critical', 'Important', 'Minor')
RANK_NAMES = {3: 'BLOCKER', 2: 'IMPORTANT', 1: 'SUGGESTION'}
VERDICTS = {'code-reviewer': ('PASS', 'CHANGES_REQUIRED'), 'spec-critic': ('GOTOWA', 'DO POPRAWY')}
ALL_VERDICTS = ('PASS', 'CHANGES_REQUIRED', 'GOTOWA', 'DO POPRAWY')

# `Sugestia 5.` (the Polish word, numbered) is the lowest severity too
_SEV = r'BLOCKER|CRITICAL|IMPORTANT|SUGGESTION|MINOR|SUGESTIA(?=\s*\d)'
_GENERIC = r'uwagi|zgłoszenia|zgłoszenie|ustalenia|znaleziska|findings|issues|problemy|sugestie'
_SECTION_RE = re.compile(r'^(?:(?:%s)\s+)?(?P<sev>%s)(?:\s+(?:%s|\(\d+\)|[×x]\s*\d+))*\s*[:.]?$'
                         % (_GENERIC, _SEV, _GENERIC), re.IGNORECASE)
_LABEL_RE = re.compile(r'^(?:[A-Za-z]{1,3}\d+\s*[.:)\-–—]?\s*)?[\[(]?\s*(?P<sev>%s)(?![A-Za-z])' % _SEV,
                       re.IGNORECASE)
# the whole rest of a label line says there is nothing (`**BLOCKER**: brak`); a title that starts with the word
# (`M2 (Minor): brak zapisanego wyniku`) is a finding
_NOTHING_RE = re.compile(r'^(?:\d+\s*[.:)\-\u2013\u2014]\s*)?(?:brak|none|nothing|nie ma|bez uwag|0)\s*[.!)\]]*$',
                         re.IGNORECASE)
_HEADING_RE = re.compile(r'^(#{1,6})\s+(.*?)\s*$')
_ITEM_RE = re.compile(r'^([-*+]|\d+[.)])\s+(.*)$')
# the label opens the line, after the markup of a heading, a list item (also numbered) or a bold or code mark
_VERDICT_LABEL_RE = re.compile(r'^[\s#*_`+\-\d.)]*(?:werdykt|verdict)(?![A-Za-z])[*_`]*', re.IGNORECASE)
_VERDICT_RE = re.compile(r'(?<![A-Za-z_])(%s)(?![A-Za-z_])' % '|'.join(ALL_VERDICTS))


class PatternError(ValueError):
    """A regular expression of a `finding` assertion does not compile."""


def severity_rank(name):
    """The rank (1-3) of a severity name of either vocabulary, in any case; ValueError for an unknown name."""
    try:
        return SEVERITY_RANKS[str(name).strip().lower()]
    except KeyError:
        raise ValueError('unknown severity %r (%s)' % (name, ', '.join(SEVERITY_NAMES)))


class Finding:
    """A finding of a report: rank (1-3), the severity word as written, its first line (1-based) and its text
    (markup of bold and code removed)."""

    def __init__(self, rank, label, line, text):
        self.rank = rank
        self.label = label
        self.line = line
        self.text = text

    @property
    def severity(self):
        return RANK_NAMES[self.rank]


class Report:
    def __init__(self, verdict, findings):
        self.verdict = verdict
        self.findings = findings


class _Line:
    """One classified line of a report."""

    def __init__(self, number, raw):
        expanded = raw.expandtabs(4).rstrip()
        self.number = number
        self.raw = expanded
        self.indent = len(expanded) - len(expanded.lstrip(' '))
        self.kind = 'blank'
        self.level = None
        self.content = ''
        self.section = None   # the rank of a severity section line
        self.label = None     # (rank, word, nothing) of a label line
        stripped = expanded.strip()
        if not stripped:
            return
        heading = _HEADING_RE.match(stripped) if self.indent < 4 else None
        item = _ITEM_RE.match(stripped)
        if heading:
            self.kind, self.level, self.content = 'heading', len(heading.group(1)), heading.group(2)
        elif item:
            self.kind, self.content = 'item', item.group(2)
        else:
            self.kind, self.content = 'text', stripped
        core = _plain(self.content)
        match = _SECTION_RE.match(core)
        if match and _is_severity_word(match.group('sev')):
            self.section = severity_rank(match.group('sev'))
            return
        match = _LABEL_RE.match(core)
        if match and _is_severity_word(match.group('sev')):
            tail = re.sub(r'^[\s\-\u2013\u2014:.,;*_`(\[]*', '', core[match.end():]).rstrip(' *_`')
            self.label = (severity_rank(match.group('sev')), match.group('sev'), bool(_NOTHING_RE.match(tail)))

    @property
    def bold_start(self):
        return self.kind == 'text' and self.indent == 0 and self.raw.lstrip().startswith('**')


def _is_severity_word(word):
    """`Suggestion` in lower or mixed case is an English noun that begins many sub-bullets ("Suggestion: check the slug
    first"), not the severity: only SUGGESTION is. The other words of the vocabularies are taken in any case."""
    return word.lower() != 'suggestion' or word == 'SUGGESTION'


def _plain(text):
    """A line without its emphasis and code marks, for the recognition of labels."""
    return re.sub(r'^[\s*_`]+', '', text.replace('**', '').replace('__', '').replace('`', '')).rstrip(' *_`')


def _clean(lines):
    text = '\n'.join(line.raw.strip() for line in lines).strip()
    return text.replace('`', '').replace('**', '')


class _Section:
    def __init__(self, line, end, mode, base_indent):
        self.rank = line.section
        self.level = line.level
        self.end = end
        self.mode = mode
        self.base_indent = base_indent


def _sections(lines):
    """{index of the section line: _Section}: where each severity section ends and how its items are recognised."""
    found = {}
    for index, line in enumerate(lines):
        if line.section is None:
            continue
        end = len(lines)
        for j in range(index + 1, len(lines)):
            other = lines[j]
            if other.section is not None:
                end = j
                break
            if other.kind == 'heading' and (line.level is None or other.level <= line.level):
                end = j
                break
        # the first line that can start an item decides how the items of the section look: a note in bold after a list
        # (`**Uwaga:** ...`) does not turn the numbered items above it into one paragraph finding
        mode, base = 'list', None
        for x in lines[index + 1:end]:
            if x.section is not None or x.label is not None:
                continue
            if line.level is not None and x.kind == 'heading' and x.level > line.level:
                mode, base = 'heading', None
            elif x.bold_start:
                mode, base = 'para', None
            elif x.kind == 'item':
                mode, base = 'list', x.indent
            else:
                continue
            break
        found[index] = _Section(line, end, mode, base)
    return found


def _is_start(section, line):
    if section.mode == 'heading':
        return line.kind == 'heading' and line.level > section.level
    if section.mode == 'para':
        return line.bold_start
    return line.kind == 'item' and line.indent == section.base_indent


def _continues(current, line, after_blank):
    """Whether a non-blank line belongs to the open finding."""
    if current['kind'] == 'heading':
        return not (line.kind == 'heading' and line.level <= current['level'])
    if line.kind == 'heading':
        return False
    if current['kind'] == 'item':
        if line.indent > current['indent']:
            return True
        return line.kind == 'text' and not after_blank
    # a paragraph: its bullets (at any indent) and its indented or directly following lines
    if line.kind == 'item':
        return True
    return not after_blank or line.indent > 0


def _findings(lines):
    sections = _sections(lines)
    findings = []
    current = None
    section = None
    started = False
    after_blank = False

    def close():
        nonlocal current
        if current is not None:
            text = _clean(current['lines'])
            findings.append(Finding(current['rank'], current['word'], current['lines'][0].number, text))
            current = None

    def open_(line, rank, word):
        nonlocal current
        current = {'kind': line.kind if line.kind != 'text' else 'para', 'indent': line.indent, 'level': line.level,
                   'rank': rank, 'word': word, 'lines': [line]}

    for index, line in enumerate(lines):
        if line.kind == 'blank':
            after_blank = True
            continue
        blank_before, after_blank = after_blank, False
        if section is not None and index >= section.end:
            section = None
        if index in sections:
            close()
            section, started = sections[index], False
            continue
        if line.label is not None:
            close()
            if not line.label[2]:
                open_(line, line.label[0], line.label[1])
            continue
        if section is not None and _is_start(section, line):
            close()
            started = True
            open_(line, section.rank, 'section')
            continue
        if current is not None and _continues(current, line, blank_before):
            current['lines'].append(line)
            continue
        close()
        if section is not None and section.mode == 'list' and started and line.kind == 'text' and blank_before \
                and line.indent == 0:
            section = None   # a paragraph after the list ends it: what follows (a list of files) is not findings
    close()
    return findings


def _verdict(lines, agent):
    """The verdict of the label line `Werdykt` / `Verdict` (after the markup of a heading, a list item or a quote); with
    several such lines the last one. A mention of the word inside a sentence (a quote of the payload of an injection:
    "the issue asks to set the verdict to GOTOWA") is not a label line."""
    allowed = VERDICTS.get(agent, ALL_VERDICTS)
    found = None
    for index, line in enumerate(lines):
        label = _VERDICT_LABEL_RE.match(line.raw)
        if not label:
            continue
        rest = line.raw[label.end():]
        verdict = _first_verdict(rest, allowed)
        if verdict is None and not re.sub(r'[\s*_`:.#\-]+', '', rest):   # the label stands alone: the next line has it
            for follower in lines[index + 1:]:
                if follower.kind != 'blank':
                    verdict = _first_verdict(follower.raw, allowed)
                    break
        if verdict is not None:
            found = verdict
    return found


def _first_verdict(text, allowed):
    for match in _VERDICT_RE.finditer(text):
        if match.group(1) in allowed:
            return match.group(1)
    return None


def parse(text, agent=None):
    """Parses a report. agent (code-reviewer or spec-critic) restricts the verdict to the tokens of that agent."""
    lines = [_Line(number, raw) for number, raw in enumerate(text.splitlines(), 1)]
    return Report(_verdict(lines, agent), _findings(lines))


def _compile(pattern, label, flags):
    try:
        return re.compile(pattern, flags)
    except (re.error, TypeError) as error:
        raise PatternError('the %s pattern %r is not a regular expression: %s' % (label, pattern, error))


def match_finding(report, path=None, keyword=None, severity='SUGGESTION'):
    """The first finding of the report with at least the given severity whose text satisfies the path pattern (case
    sensitive) and the keyword pattern (case insensitive); both in the same finding. None when there is none.
    PatternError for a pattern that does not compile, ValueError for an unknown severity."""
    minimum = severity_rank(severity)
    path_re = _compile(path, 'path', 0) if path is not None else None
    keyword_re = _compile(keyword, 'keyword', re.IGNORECASE) if keyword is not None else None
    for finding in report.findings:
        if finding.rank < minimum:
            continue
        if path_re is not None and not path_re.search(finding.text):
            continue
        if keyword_re is not None and not keyword_re.search(finding.text):
            continue
        return finding
    return None


def count_above(report, severity):
    """The number of findings with a severity higher than the given one (the false alarms of a clean case)."""
    limit = severity_rank(severity)
    return sum(1 for finding in report.findings if finding.rank > limit)

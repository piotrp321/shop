#!/usr/bin/env python3
"""Origin gate for GitHub content given to agents. Python 3.9+, standard library only, `gh` as the only tool.

  github_trust.py issues [--repo R] [--state open|closed|all] [--label L]... [--limit N] [--json FIELDS]
  github_trust.py issue <nr> [--repo R] [--json FIELDS]
  github_trust.py prs [--repo R] [--state open|closed|merged|all] [--base B] [--head H] [--limit N] [--json FIELDS]
  github_trust.py comments <nr> [--repo R]

--repo defaults to piotrp321/shop, --state to open, --limit to 30 (as in `gh`). Prints one JSON line: a list of
records (`issue`: one record) and exits 0.

Trust: the author of an issue, pull request or comment is trusted when
`gh api repos/<repo>/collaborators/<login>/permission` answers 200 with `role_name` admin, maintain or write and the
legacy `permission` admin or write (maintain maps to write, triage to read). 404, read, triage, any other role,
bot accounts (`is_bot`, a login ending in `[bot]` or starting with `app/`), `ghost`, a missing author and a login
that is not a GitHub login are untrusted; bots, ghost and bad logins without a permission call. Every login is
looked up at most once per Gate (one invocation of the script).

Records (`author` is the login string or null):
  untrusted: {number, author, url, trusted: false} and nothing else;
  trusted:   {number, author, url, <the requested fields as `gh` gives them>, trusted: true}.
  Comments (field `comments` of issues, the `comments` command): each judged by its own author;
  trusted {author, body, createdAt, url, trusted: true}, untrusted {author, url, trusted: false}.
  The comments of an untrusted issue are never returned. Review comments of pull requests are never fetched.

FIELDS (comma separated; number, author and url are always included):
  issues, issue: title, body, labels, state, createdAt, updatedAt, closedAt, comments
                 (default title,body,labels,state,updatedAt);
  prs: title, body, labels, state, headRefName, baseRefName, headRefOid, isDraft, createdAt, updatedAt, mergedAt,
       closedAt, mergeCommit (default title,body,state,headRefName,baseRefName).

Fail closed: a failing `gh` (network, rate limit, any status other than 200 or 404 of the permission call) or output
that is not the JSON expected ends with exit code 1 and a message on stderr; nothing goes to stdout. Usage errors:
exit code 2. The status of the permission call is read from `gh api --include`, so a 404 is told apart from every
other failure by the HTTP status line, not by the error text.

Module interface (for groom.py, retro.py, next_epic.py, night_queue.py):
  gate = Gate(run=None, repo=REPOSITORY)   # run(argv) -> stdout, raises on a non-zero exit; default make_runner()
  gate.issues(state='open', labels=(), limit=30, fields=ISSUE_DEFAULT) -> [record]
  gate.issue(number, fields=ISSUE_DEFAULT) -> record
  gate.prs(state='open', base=None, head=None, limit=30, fields=PR_DEFAULT) -> [record]
  gate.comments(number) -> [comment record]
  gate.trusted(login, is_bot=False) -> bool
Errors: GateError (gh failed, unexpected JSON; exit 1 in the CLI), ValueError (bad arguments, before any command).
The runner's exception may carry the command's stdout in `stdout` (make_runner does): only then a 404 of the
permission call is recognised; without it every failure of that call is a GateError.
"""
import argparse
import json
import re
import subprocess
import sys

REPOSITORY = 'piotrp321/shop'
TRUSTED_ROLES = ('admin', 'maintain', 'write')
TRUSTED_PERMISSIONS = ('admin', 'write')
ISSUE_FIELDS = ('title', 'body', 'labels', 'state', 'createdAt', 'updatedAt', 'closedAt', 'comments')
PR_FIELDS = ('title', 'body', 'labels', 'state', 'headRefName', 'baseRefName', 'headRefOid', 'isDraft', 'createdAt',
             'updatedAt', 'mergedAt', 'closedAt', 'mergeCommit')
ISSUE_DEFAULT = ('title', 'body', 'labels', 'state', 'updatedAt')
PR_DEFAULT = ('title', 'body', 'state', 'headRefName', 'baseRefName')
ALWAYS = ('number', 'author', 'url')
ISSUE_STATES = ('open', 'closed', 'all')
PR_STATES = ('open', 'closed', 'merged', 'all')
COMMENT_FIELDS = ('author', 'body', 'createdAt', 'url')
REPO_PATTERN = re.compile(r'^[A-Za-z0-9][A-Za-z0-9-]*/[A-Za-z0-9._-]+$')
LOGIN_PATTERN = re.compile(r'^[A-Za-z0-9][A-Za-z0-9-]{0,38}$')
STATUS_LINE = re.compile(r'^HTTP/[0-9.]+ ([0-9]{3})(?: |$)')
TIMEOUT = 120


class GateError(Exception):
    """gh failed or answered something unexpected: the gate never passes content it cannot judge."""


class CommandError(Exception):
    """A command of make_runner failed; `stdout` keeps its output (gh api --include prints the response there)."""

    def __init__(self, args, detail, stdout=''):
        super().__init__('%s: %s' % (' '.join(args), detail))
        self.stdout = stdout


def make_runner(cwd=None, timeout=TIMEOUT):
    """run(argv) -> stdout; a non-zero exit, a timeout or a missing program raises CommandError."""
    def run(args):
        try:
            result = subprocess.run(args, cwd=cwd, capture_output=True, text=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            raise CommandError(args, 'timed out after %g s' % timeout)
        except OSError as e:
            raise CommandError(args, e.strerror or str(e))
        if result.returncode != 0:
            raise CommandError(args, result.stderr.strip() or 'exit code %d' % result.returncode, result.stdout)
        return result.stdout
    return run


def _is_int(value):
    return isinstance(value, int) and not isinstance(value, bool)


def _http_response(text):
    """(status, body) of the stdout of `gh api --include`, or (None, None) when it does not start with a status."""
    if not isinstance(text, str):
        return None, None
    text = text.replace('\r\n', '\n')
    match = STATUS_LINE.match(text)
    if not match:
        return None, None
    _, separator, body = text.partition('\n\n')
    return int(match.group(1)), body if separator else ''


def _fields(fields, allowed):
    fields = tuple(fields)
    unknown = [f for f in fields if f not in allowed and f not in ALWAYS]
    if unknown:
        raise ValueError('unknown field %s (allowed: %s)' % (', '.join(unknown), ', '.join(allowed)))
    return tuple(f for f in fields if f not in ALWAYS)


def _limit(limit):
    if not _is_int(limit) or limit < 1:
        raise ValueError('limit must be a positive integer')
    return str(limit)


def _number(number):
    if not _is_int(number) or number < 1:
        raise ValueError('issue number must be a positive integer')
    return str(number)


class Gate:
    def __init__(self, run=None, repo=REPOSITORY):
        if not isinstance(repo, str) or not REPO_PATTERN.match(repo) or '..' in repo:
            raise ValueError('repository must be OWNER/NAME, got %r' % (repo,))
        self.run = run or make_runner()
        self.repo = repo
        self._trust = {}

    # ------------------------------------------------------------ trust

    def trusted(self, login, is_bot=False):
        if (is_bot or not isinstance(login, str) or not LOGIN_PATTERN.match(login) or login.lower() == 'ghost'):
            return False    # bots ([bot] and app/ never match the login pattern), ghost, no author, odd logins
        key = login.lower()
        if key not in self._trust:
            self._trust[key] = self._lookup(login)
        return self._trust[key]

    def _lookup(self, login):
        args = ['gh', 'api', '--include', 'repos/%s/collaborators/%s/permission' % (self.repo, login)]
        try:
            out = self.run(args)
            failed = None
        except Exception as e:    # any failure of the runner: judged only by the HTTP status it printed
            out, failed = getattr(e, 'stdout', None), e
        status, body = _http_response(out)
        if status == 404:
            return False
        if failed is not None:
            raise GateError('%s (HTTP status %s)' % (failed, status or 'none'))
        if status != 200:
            raise GateError('%s: unexpected HTTP status %s' % (' '.join(args), status or 'none'))
        try:
            data = json.loads(body)
        except ValueError:
            raise GateError('%s: response is not valid JSON' % ' '.join(args))
        if not isinstance(data, dict) or not isinstance(data.get('role_name'), str) \
                or not isinstance(data.get('permission'), str):
            raise GateError('%s: response without role_name and permission' % ' '.join(args))
        return data['role_name'] in TRUSTED_ROLES and data['permission'] in TRUSTED_PERMISSIONS

    # ------------------------------------------------------------ records

    def _author(self, value, args):
        if value is None:
            return None, False
        if not isinstance(value, dict) or not isinstance(value.get('login', ''), str):
            raise GateError('%s: unexpected author %r' % (' '.join(args), value))
        login = value.get('login')
        return login, self.trusted(login, bool(value.get('is_bot')))

    def _comments(self, comments, args):
        if not isinstance(comments, list) or not all(isinstance(c, dict) for c in comments):
            raise GateError('%s: unexpected comments' % ' '.join(args))
        records = []
        for comment in comments:
            login, trusted = self._author(comment.get('author'), args)
            if not trusted:
                records.append({'author': login, 'url': comment.get('url'), 'trusted': False})
                continue
            if not isinstance(comment.get('body'), str):
                raise GateError('%s: comment without body' % ' '.join(args))
            record = {k: comment.get(k) for k in COMMENT_FIELDS}
            record.update(author=login, trusted=True)
            records.append(record)
        return records

    def _record(self, item, fields, kind, args):
        if not isinstance(item, dict) or not _is_int(item.get('number')):
            raise GateError('%s: unexpected JSON shape' % ' '.join(args))
        login, trusted = self._author(item.get('author'), args)
        url = item.get('url')
        if not isinstance(url, str) or not url:
            url = 'https://github.com/%s/%s/%d' % (self.repo, kind, item['number'])
        record = {'number': item['number'], 'author': login, 'url': url}
        if trusted:
            for field in fields:
                if field == 'comments':
                    record['comments'] = self._comments(item.get('comments') or [], args)
                elif field in item:
                    record[field] = item[field]
        record['trusted'] = trusted
        return record

    def _json(self, args):
        try:
            out = self.run(args)
        except Exception as e:
            raise GateError(str(e))
        try:
            return json.loads(out)
        except (TypeError, ValueError):
            raise GateError('%s: output is not valid JSON' % ' '.join(args))

    def _list(self, args, fields, kind):
        data = self._json(args)
        if not isinstance(data, list):
            raise GateError('%s: unexpected JSON shape' % ' '.join(args))
        return [self._record(item, fields, kind, args) for item in data]

    # ------------------------------------------------------------ commands

    def issues(self, state='open', labels=(), limit=30, fields=ISSUE_DEFAULT):
        fields = _fields(fields, ISSUE_FIELDS)
        if state not in ISSUE_STATES:
            raise ValueError('state must be one of %s' % ', '.join(ISSUE_STATES))
        if isinstance(labels, str) or not all(isinstance(l, str) and l for l in labels):
            raise ValueError('labels must be a list of names')
        args = ['gh', 'issue', 'list', '--repo', self.repo, '--state', state, '--limit', _limit(limit)]
        for label in labels:
            args += ['--label', label]
        args += ['--json', ','.join(ALWAYS + fields)]
        return self._list(args, fields, 'issues')

    def issue(self, number, fields=ISSUE_DEFAULT):
        fields = _fields(fields, ISSUE_FIELDS)
        args = ['gh', 'issue', 'view', _number(number), '--repo', self.repo, '--json', ','.join(ALWAYS + fields)]
        record = self._record(self._json(args), fields, 'issues', args)
        if record['number'] != number:
            raise GateError('%s: answered issue #%d' % (' '.join(args), record['number']))
        return record

    def prs(self, state='open', base=None, head=None, limit=30, fields=PR_DEFAULT):
        fields = _fields(fields, PR_FIELDS)
        if state not in PR_STATES:
            raise ValueError('state must be one of %s' % ', '.join(PR_STATES))
        args = ['gh', 'pr', 'list', '--repo', self.repo, '--state', state, '--limit', _limit(limit)]
        for flag, value in (('--base', base), ('--head', head)):
            if value is not None:
                if not isinstance(value, str) or not value:
                    raise ValueError('%s must be a branch name' % flag)
                args += [flag, value]
        args += ['--json', ','.join(ALWAYS + fields)]
        return self._list(args, fields, 'pull')

    def comments(self, number):
        args = ['gh', 'issue', 'view', _number(number), '--repo', self.repo, '--json', 'comments']
        data = self._json(args)
        if not isinstance(data, dict):
            raise GateError('%s: unexpected JSON shape' % ' '.join(args))
        return self._comments(data.get('comments'), args)


# ---------------------------------------------------------------- CLI

def _positive(text):
    try:
        value = int(text)
    except ValueError:
        raise argparse.ArgumentTypeError('expected a positive integer, got %r' % text)
    if value < 1:
        raise argparse.ArgumentTypeError('expected a positive integer, got %r' % text)
    return value


def _field_list(text):
    return tuple(f.strip() for f in text.split(',') if f.strip())


def build_parser():
    parser = argparse.ArgumentParser(description='Origin gate for GitHub content given to agents')
    sub = parser.add_subparsers(dest='command', required=True)
    issues = sub.add_parser('issues', help='list issues')
    issues.add_argument('--state', default='open', choices=ISSUE_STATES)
    issues.add_argument('--label', action='append', default=[])
    issues.add_argument('--limit', type=_positive, default=30)
    issues.add_argument('--json', type=_field_list, default=ISSUE_DEFAULT, dest='fields')
    issue = sub.add_parser('issue', help='one issue')
    issue.add_argument('number', type=_positive)
    issue.add_argument('--json', type=_field_list, default=ISSUE_DEFAULT, dest='fields')
    prs = sub.add_parser('prs', help='list pull requests')
    prs.add_argument('--state', default='open', choices=PR_STATES)
    prs.add_argument('--base')
    prs.add_argument('--head')
    prs.add_argument('--limit', type=_positive, default=30)
    prs.add_argument('--json', type=_field_list, default=PR_DEFAULT, dest='fields')
    comments = sub.add_parser('comments', help='the comments of an issue')
    comments.add_argument('number', type=_positive)
    for p in (issues, issue, prs, comments):
        p.add_argument('--repo', default=REPOSITORY)
    return parser


def main(argv=None, run=None):
    try:
        args = build_parser().parse_args(argv)
    except SystemExit as e:
        return 2 if e.code else 0
    try:
        gate = Gate(run, repo=args.repo)
        if args.command == 'issues':
            result = gate.issues(state=args.state, labels=args.label, limit=args.limit, fields=args.fields)
        elif args.command == 'issue':
            result = gate.issue(args.number, fields=args.fields)
        elif args.command == 'prs':
            result = gate.prs(state=args.state, base=args.base, head=args.head, limit=args.limit, fields=args.fields)
        else:
            result = gate.comments(args.number)
    except ValueError as e:
        print('github_trust: %s' % e, file=sys.stderr)
        return 2
    except GateError as e:
        print('github_trust: %s' % e, file=sys.stderr)
        return 1
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())

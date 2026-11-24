#!/usr/bin/env python3
"""Checks that every external image a Dockerfile uses is pinned: name:tag@sha256:<64 lowercase hex characters>.

Checked: every Dockerfile of the git repository at the given directory (default: the current one): the files git tracks
or would add (untracked and not ignored), whose name is `Dockerfile`, `Dockerfile.*` or `*.Dockerfile`, in any case,
in any directory. A new Dockerfile is therefore checked without a change of this script or of the Makefile.

Checked in each file: the image of every `FROM` (flags such as `--platform=` are skipped), the `--from=` of `COPY` and
`ADD`, and the `from=` of a `RUN --mount`. A reference to an earlier stage (its `AS` name or its number) and `scratch`
are allowed. Instruction names and `AS` are case-insensitive, like in Docker. The instruction after `ONBUILD` is checked like any
other, a byte order mark is skipped and the `# escape=` directive (among the directives at the top) sets the continuation character. The tag may not be `latest`, and no part of
a reference may be a variable, because the value of a variable is not visible here. The text of a heredoc is not
recognised as such: a line inside one that looks like an instruction is checked like any other.

A Dockerfile written inline in another file (e.g. `dockerfile_inline` in compose) is not covered.

Exit status: 0 every image is pinned; 1 an image is not (one `path:line: message` line each, on stdout); 2 the check
could not run (not a git repository, no Dockerfile, an unreadable file): a result that is not "clean" is never silent.

Usage: check_base_images.py [directory]
"""
import re
import subprocess
import sys

DIGEST = re.compile(r"sha256:[0-9a-f]{64}")
TAG = re.compile(r"[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}")
NAME = re.compile(r"[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[0-9]+)?(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)*")
FILE_NAME = re.compile(r"(?:dockerfile(?:\..*)?|.*\.dockerfile)", re.IGNORECASE)


class CheckError(Exception):
    """The check could not run (exit status 2)."""


def dockerfiles(root):
    try:
        inside = subprocess.run(["git", "-C", root, "rev-parse", "--is-inside-work-tree"],
                                capture_output=True, text=True)
        if inside.returncode != 0 or inside.stdout.strip() != "true":
            raise CheckError(f"'{root}' is not inside a git work tree: {inside.stderr.strip() or 'no repository'}")
        listed = subprocess.run(["git", "-C", root, "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
                                capture_output=True, text=True, check=True)
    except FileNotFoundError:
        raise CheckError("git is not installed") from None
    except subprocess.CalledProcessError as error:
        raise CheckError(f"git ls-files failed: {error.stderr.strip()}") from None
    paths = sorted(set(path for path in listed.stdout.split("\0") if path))
    found = [path for path in paths if FILE_NAME.fullmatch(path.rsplit("/", 1)[-1])]
    if not found:
        raise CheckError(f"no Dockerfile found in the git repository at '{root}'")
    return found


DIRECTIVE = re.compile(r"#\s*([a-z]+)\s*=\s*(\S*)\s*", re.IGNORECASE)


def escape_character(text):
    """The continuation character: the parser directive `# escape=` among the directives at the top of the file, else a
    backslash. Like in Docker, the directives end at the first line that is not a `# name=value` comment (an ordinary
    comment, an empty line or an instruction)."""
    for line in text.splitlines():
        directive = DIRECTIVE.fullmatch(line.strip())
        if not directive:
            break
        if directive.group(1).lower() == "escape" and directive.group(2) in ("\\", "`"):
            return directive.group(2)
    return "\\"


def instructions(text):
    """Yields (first line number, text) of each logical line: continuations joined, comments and empty lines dropped
    (also inside a continuation, like Docker)."""
    escape = escape_character(text)
    start, parts = None, []
    for number, line in enumerate(text.splitlines(), 1):
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        if start is None:
            start = number
        if stripped.endswith(escape):
            parts.append(stripped[:-1])
            continue
        parts.append(stripped)
        yield start, " ".join(part.strip() for part in parts)
        start, parts = None, []
    if parts:
        yield start, " ".join(part.strip() for part in parts)


def reason(reference):
    """Why the reference is not pinned, or None when it is name:tag@sha256:<64 hex>."""
    if "$" in reference:
        return "the image is given by a variable, so its pin cannot be checked"
    if "@" not in reference:
        return "no digest (expected name:tag@sha256:<64 hex characters>)"
    name_tag, digest = reference.rsplit("@", 1)
    if not DIGEST.fullmatch(digest):
        return f"'{digest}' is not a digest (expected sha256: and 64 lowercase hex characters)"
    last = name_tag.rsplit("/", 1)[-1]
    if ":" not in last:
        return "a digest without a tag (expected name:tag@sha256:<64 hex characters>)"
    name, tag = name_tag.rsplit(":", 1)
    if tag == "latest":
        return "the tag is latest"
    if not TAG.fullmatch(tag):
        return f"'{tag}' is not a valid tag"
    if not NAME.fullmatch(name):
        return f"'{name}' is not a valid image name"
    return None


def check_file(path, text):
    """Returns [(line, message)] for one Dockerfile."""
    problems = []
    stages = []

    def check_reference(line, reference, what):
        key = reference.lower()
        if reference == "scratch" and what == "FROM" or key in stages:
            return
        if what != "FROM" and reference.isdigit() and int(reference) < len(stages) - 1:
            return
        message = reason(reference)
        if message:
            problems.append((line, f"{what} {reference}: {message}"))

    for line, instruction in instructions(text):
        tokens = instruction.split()
        if tokens[0].upper() == "ONBUILD":
            tokens = tokens[1:]
            if not tokens:
                continue
        keyword = tokens[0].upper()
        flags = []
        rest = tokens[1:]
        while rest and rest[0].startswith("--"):
            flags.append(rest.pop(0))
        if keyword == "FROM" and rest:
            check_reference(line, rest[0], "FROM")
            if len(rest) >= 3 and rest[1].upper() == "AS":
                stages.append(rest[2].lower())
            else:
                stages.append(None)
        elif keyword in ("COPY", "ADD"):
            for flag in flags:
                if flag.lower().startswith("--from="):
                    check_reference(line, flag[len("--from="):], keyword)
        elif keyword == "RUN":
            for flag in flags:
                if flag.lower().startswith("--mount="):
                    for option in flag[len("--mount="):].split(","):
                        if option.lower().startswith("from="):
                            check_reference(line, option[len("from="):], "RUN --mount from")
    return problems


def main(argv):
    if len(argv) > 2:
        print(__doc__.strip().splitlines()[-1], file=sys.stderr)
        return 2
    root = argv[1] if len(argv) == 2 else "."
    try:
        paths = dockerfiles(root)
        violations = []
        for path in paths:
            try:
                with open(f"{root}/{path}", encoding="utf-8-sig") as file:
                    text = file.read()
            except (OSError, UnicodeDecodeError) as error:
                raise CheckError(f"cannot read {path}: {error}") from None
            violations += [(path, line, message) for line, message in check_file(path, text)]
    except CheckError as error:
        print(f"base-images: {error}", file=sys.stderr)
        return 2
    for path, line, message in violations:
        print(f"{path}:{line}: {message}")
    if violations:
        print(f"base-images: {len(violations)} image reference(s) not pinned by name:tag@sha256:<digest>", file=sys.stderr)
        return 1
    print(f"base-images: {len(paths)} Dockerfile(s) checked, every external image is pinned")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))

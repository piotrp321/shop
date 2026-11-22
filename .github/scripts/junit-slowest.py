#!/usr/bin/env python3
"""Prints the slowest tests and test classes of a JUnit XML file (PHPUnit --log-junit) as Markdown, for the step summary
of a CI part: when a part gets slower, the summary says which tests take the time.

Usage: junit-slowest.py <junit.xml> [count]   (count: the number of tests and of classes listed, default 15)
Exit status: 0 printed, 2 a usage error or a file that cannot be read as JUnit XML.
"""

import collections
import sys
import xml.etree.ElementTree as ET


def usage(message):
    print(f"usage: junit-slowest.py <junit.xml> [count]\njunit-slowest: {message}", file=sys.stderr)
    return 2


def main(args):
    if len(args) not in (1, 2):
        return usage("expected a file and an optional count")
    count = 15
    if len(args) == 2:
        if not args[1].isdigit() or int(args[1]) < 1:
            return usage(f"count must be a positive integer, got '{args[1]}'")
        count = int(args[1])
    try:
        root = ET.parse(args[0]).getroot()
    except (OSError, ET.ParseError) as error:
        print(f"junit-slowest: cannot read {args[0]}: {error}", file=sys.stderr)
        return 2

    tests = []
    classes = collections.defaultdict(lambda: [0.0, 0])
    for case in root.iter("testcase"):
        seconds = float(case.get("time") or 0)
        name = (case.get("class") or case.get("classname") or "").rsplit("\\", 1)[-1]
        tests.append((seconds, f"{name}::{case.get('name')}"))
        classes[name][0] += seconds
        classes[name][1] += 1

    print(f"{len(tests)} tests, {sum(seconds for seconds, _ in tests):.1f} s\n")
    print("| Time | Test |\n|---:|---|")
    for seconds, test in sorted(tests, key=lambda item: -item[0])[:count]:
        print(f"| {seconds:.1f} s | {test} |")
    print("\n| Time | Tests | Class |\n|---:|---:|---|")
    for name, (seconds, number) in sorted(classes.items(), key=lambda item: -item[1][0])[:count]:
        print(f"| {seconds:.1f} s | {number} | {name} |")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

"""Runs the test files of a directory side by side: each test_*.py in its own `python -I -m unittest discover` process
(the same imports as one discover over the directory), at most --jobs of them at a time, the largest files first, so
that the long files start early and the short ones fill the gaps. The output of a file is printed only when it fails;
a passing file gets one line with its number of tests and its time. Every file runs to the end.

Exit status: 0 every file passed, 1 a file failed (also a file that cannot be imported or has no tests), 2 a usage
error (no such directory, no test files, a number of jobs below one).

Usage: run_parallel.py [--jobs N] <directory>   (N: default the CPUs of the machine)
"""

import argparse
import concurrent.futures
import os
import pathlib
import re
import subprocess
import sys
import time


def jobs(value):
    try:
        number = int(value)
    except ValueError:
        raise argparse.ArgumentTypeError(f"not a number: {value!r}") from None
    if number < 1:
        raise argparse.ArgumentTypeError(f"must be at least 1: {number}")
    return number


def run(directory, name):
    started = time.monotonic()
    result = subprocess.run([sys.executable, "-I", "-m", "unittest", "discover", "-s", str(directory), "-p", name],
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    ran = re.search(r"(?m)^Ran (\d+) tests? in", result.stdout)
    return name, result.returncode, int(ran.group(1)) if ran else 0, time.monotonic() - started, result.stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--jobs", type=jobs, default=os.cpu_count() or 1)
    parser.add_argument("directory")
    args = parser.parse_args()

    directory = pathlib.Path(args.directory)
    if not directory.is_dir():
        print(f"run_parallel: no such directory: {directory}", file=sys.stderr)
        return 2
    files = sorted(directory.glob("test_*.py"), key=lambda path: (-path.stat().st_size, path.name))
    if not files:
        print(f"run_parallel: no test files (test_*.py) in {directory}", file=sys.stderr)
        return 2

    started = time.monotonic()
    failed, tests = [], 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
        for future in concurrent.futures.as_completed([pool.submit(run, directory, path.name) for path in files]):
            name, status, count, seconds, output = future.result()
            tests += count
            # unittest exits 5 when the file has no tests: a file that tests nothing is a mistake, not a pass.
            if status == 0:
                print(f"ok      {name} ({count} tests, {seconds:.1f} s)", flush=True)
            else:
                failed.append(name)
                print(f"FAILED  {name} (exit {status}, {count} tests, {seconds:.1f} s)\n{output}", flush=True)

    summary = f"run_parallel: {len(files)} files, {tests} tests, {args.jobs} jobs, {time.monotonic() - started:.0f} s"
    if failed:
        print(f"run_parallel: {len(files)} files, FAILED: {' '.join(sorted(failed))}")
        print(summary)
        return 1
    print(f"run_parallel: {len(files)} files, {tests} tests, all passed")
    print(summary)
    return 0


if __name__ == "__main__":
    sys.exit(main())

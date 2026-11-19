"""Requests through the ingress without a pause while make generate closes the shop and make open opens it again, and the verdict on
what they got (scripts/smoke.sh, the phase of the demo data generator; docs/features/demo-scale/spec.md, Z1 and Z2).

  run <address> <host> <path> <directory> <seconds>
        GET http://<address><path> with the Host header <host>, one after another over one keep-alive connection while the server
        keeps it open (a new one after an error or a close), each answer printed as a line `<seconds since the start> <status>` or
        `<seconds> error:<kind>`. A file mark-<name> that appears in <directory> is printed once as `<seconds> mark:<name>`, in the
        order the files appeared (scripts/smoke.sh makes them from the lines of make generate and make open: closed, checks, open,
        and stop when generate ended). After the marker stop it ends with the first answer 200 after the marker open, or STOP_GRACE
        seconds after the marker stop; it never runs longer than <seconds>.
  verdict
        reads such lines on the standard input. The requests got 200 before the closing, none from the marker closed to the marker
        checks (the shop stayed closed while the generator ran and make open checked its data, also for a connection opened before
        the rule: Z2), and 200 again after the marker open. Prints a summary and exits 0, or prints what failed and exits 1.

Python with the standard library only (it runs in the image python of tools.sh, on the Docker network of the cluster).
"""

import collections
import http.client
import os
import re
import sys
import threading
import time

# Seconds: one request at most; between two requests; after an error (no connection); after the marker stop without a 200.
TIMEOUT = 15
PAUSE = 0.2
PAUSE_AFTER_ERROR = 0.5
STOP_GRACE = 60
# Seconds between two looks for new markers.
WATCH = 0.05
MARKERS = ("closed", "checks", "open")

LINE = re.compile(r"^([0-9]+(?:\.[0-9]+)?) (mark:[a-z]+|[0-9]{3}|error:[A-Za-z_][A-Za-z0-9_]*)$")


def new_markers(directory, seen):
    """The names of the files mark-<name> of the directory not seen yet, in the order they appeared (time, then name)."""
    found = []
    for entry in os.scandir(directory):
        if entry.name.startswith("mark-") and len(entry.name) > 5 and entry.name not in seen:
            found.append((entry.stat().st_mtime_ns, entry.name))
    return [name for _, name in sorted(found)]


def run(address, host, path, directory, limit, out=sys.stdout, grace=STOP_GRACE, pause=PAUSE):
    start = time.monotonic()
    lock = threading.Lock()
    done = threading.Event()
    state = {"opened": False, "stopped_at": None}
    reopened = False
    connection = None

    def emit(text, marker=None):
        with lock:
            print(f"{time.monotonic() - start:.1f} {text}", file=out, flush=True)
            if marker == "mark-open":
                state["opened"] = True
            elif marker == "mark-stop":
                state["stopped_at"] = time.monotonic()

    # The markers are looked for every WATCH seconds beside the requests, not between them: through the closed shop an answer takes
    # about a second, and a marker found only after it would follow an answer that came after the marker.
    def watch():
        seen = set()
        while not done.is_set():
            for name in new_markers(directory, seen):
                seen.add(name)
                emit("mark:" + name[5:], name)
            done.wait(WATCH)

    watcher = threading.Thread(target=watch, daemon=True)
    watcher.start()
    try:
        while time.monotonic() - start < limit:
            stopped_at = state["stopped_at"]
            if stopped_at is not None and (reopened or time.monotonic() - stopped_at >= grace):
                return
            try:
                if connection is None:
                    connection = http.client.HTTPConnection(address, timeout=TIMEOUT)
                connection.request("GET", path, headers={"Host": host})
                response = connection.getresponse()
                response.read()
                opened = state["opened"]
                emit(str(response.status))
                if response.status == 200 and opened:
                    reopened = True
                if response.will_close:
                    connection.close()
                    connection = None
            except (OSError, http.client.HTTPException) as error:
                emit("error:" + type(error).__name__)
                if connection is not None:
                    connection.close()
                connection = None
                time.sleep(PAUSE_AFTER_ERROR)
                continue
            time.sleep(pause)
    finally:
        done.set()
        watcher.join()
        if connection is not None:
            connection.close()


def verdict(lines):
    """(ok, report): whether the lines of a run show the closing and the opening, and the lines of the report."""
    problems = []
    entries = []
    for number, line in enumerate(lines, 1):
        if not line.strip():
            continue
        match = LINE.match(line.strip())
        if not match:
            problems.append(f"the line {number} is neither an answer nor a marker: {line!r}")
            continue
        entries.append((float(match.group(1)), match.group(2)))

    marks = [what for _, what in entries if what.startswith("mark:")]
    for marker in MARKERS:
        if f"mark:{marker}" not in marks:
            problems.append(f"the marker mark:{marker} is missing (make generate or make open did not print that step)")
    positions = [marks.index(f"mark:{marker}") for marker in MARKERS if f"mark:{marker}" in marks]
    if len(positions) == len(MARKERS) and positions != sorted(positions):
        problems.append("the markers are not in the order " + ", ".join(MARKERS) + ": " + ", ".join(marks))
    if problems:
        return False, ["traffic: " + problem for problem in problems]

    segment = "before"
    parts = collections.defaultdict(list)
    for moment, what in entries:
        if what == "mark:closed":
            segment = "closed"
        elif what == "mark:checks":
            segment = "checks"
        elif what == "mark:open":
            segment = "open"
        elif not what.startswith("mark:"):
            parts[segment].append((moment, what))

    answers = [moment for moment, what in entries if not what.startswith("mark:")]

    before_200 = sum(1 for _, what in parts["before"] if what == "200")
    closed = parts["closed"]
    closed_200 = [moment for moment, what in closed if what == "200"]
    after_200 = [moment for moment, what in parts["open"] if what == "200"]
    if before_200 == 0:
        problems.append("no answer 200 before the closing: the requests did not reach the open shop first")
    if not closed:
        problems.append("no request between the markers closed and checks: the requests did not go on through the closing")
    if closed_200:
        problems.append(f"{len(closed_200)} answers 200 while the shop was closed (the first at {closed_200[0]:.1f} s, after the "
                        "marker closed): a connection kept the backend (Z2) or the rule does not close the shop")
    if not after_200:
        problems.append("no answer 200 after the opening")
    if problems:
        return False, ["traffic: " + problem for problem in problems]

    others = collections.Counter(what for _, what in closed)
    window = closed[-1][0] - closed[0][0]
    opened_at = next(moment for moment, what in entries if what == "mark:open")
    return True, [
        f"ok: the traffic through the ingress: {before_200} answers 200 before the closing; while the shop was closed "
        f"({window:.0f} s from the marker closed to the marker checks) 0 answers 200 and {len(closed)} others ("
        + ", ".join(f"{what} x {count}" for what, count in sorted(others.items()))
        + f"); 200 again {after_200[0] - opened_at:.1f} s after the opening; {len(answers)} requests"
    ]


def main(argv):
    if len(argv) == 6 and argv[0] == "run":
        run(argv[1], argv[2], argv[3], argv[4], float(argv[5]))
        return 0
    if argv == ["verdict"]:
        ok, report = verdict(sys.stdin.read().splitlines())
        print("\n".join(report), file=sys.stdout if ok else sys.stderr)
        return 0 if ok else 1
    print("traffic: usage: traffic.py run <address> <host> <path> <directory> <seconds> | traffic.py verdict", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

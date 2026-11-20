"""The script of the Job of the CronJob demo-data (files/scripts/demo-data.sh; docs/features/demo-scale/spec.md, "Job generatora na
serwerze (chart)") against stubs of df, bin/generate-demo, bin/seed-demo, bin/console and psql: it refuses without DEMO_DATA_CONFIRM
(64) and with too little room before the start (74), runs the generator of the profile or the seed in a process group of its own,
stops that whole group (with the command it runs in the foreground, which may ignore TERM) when the free space falls below the floor,
cannot be read three times in a row or is no number, and on TERM (75), and touches nothing else. Every path writes one line of the
reason to the termination log and to the end of the log.

The stubs log one line per call ("<tool> <arguments>") in $STUB_DIR/calls. The generator stub behaves like bin/generate-demo: its
trap of TERM waits for the command in the foreground (bin/console), and the console stub ignores TERM with STUB_CONSOLE_IGNORES_TERM.
The tests run the script with the sh of the image that runs them (busybox in python:3.13-alpine, make -C infra/k8s check); the image
of the api runs it with dash and mawk, which CI does not test. The same tests with the shell and the tools of the image of the api
(Debian trixie: dash, util-linux setsid, mawk), from the root of the repository:

  docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD:/repo:ro" -w /repo/infra/k8s python:3.13-slim-trixie \\
    python -I -m unittest discover -s tests -p 'test_demo_data_script.py'
"""

import os
import pathlib
import re
import signal
import subprocess
import tempfile
import time
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/charts/shop/files/scripts/demo-data.sh"
ANCHOR = "2026-11-19T00:00:00Z"
PROFILE_ARGS = ("--profile=medium", f"--anchor={ANCHOR}", "--min-free-gb=32")
# KiB: 40.96 GB and 31.74 GB around the floor of 32 GB (31250000 KiB).
PLENTY = "40000000"
LOW = "31000000"

# df -Pk /: the reading n takes the n-th word of STUB_DF (the last one stays): a number of KiB, `fail` (df fails), `none` (df prints
# nothing) or any other text, printed in the column Available.
DF = r"""#!/bin/sh
echo "df $*" >> "$STUB_DIR/calls"
echo "df $*" >> "$STUB_DIR/df.log"
n="$(wc -l < "$STUB_DIR/df.log")"
value="" i=0
for item in ${STUB_DF:-40000000}; do
  i=$((i + 1)); value="$item"
  [ "$i" -lt "$n" ] || break
done
case "$value" in
  fail) echo "df: /: Input/output error" >&2; exit 1 ;;
  none) exit 0 ;;
esac
echo "Filesystem     1024-blocks      Used Available Capacity Mounted on"
echo "overlay         1056557396 138733784 $value      14% /"
"""

# The generator and the seed: STUB_STEPS is a list of <label>:<seconds> (an underscore is a space in the label): each step sleeps, then
# prints its [OK] line as SymfonyStyle does. Then, with STUB_CONSOLE_SECONDS, bin/console runs in the foreground; the exit code is
# STUB_EXIT. The trap of TERM is that of bin/generate-demo: it runs only when the command in the foreground has ended.
GENERATOR = r"""#!/bin/sh
echo "${0##*/} $*" >> "$STUB_DIR/calls"
echo $$ > "$STUB_DIR/generator.pid"
trap 'echo "${0##*/} TERM" >> "$STUB_DIR/calls"; exit 130' INT TERM
echo "${0##*/}: starting"
for step in ${STUB_STEPS:-}; do
  label="$(printf '%s' "${step%:*}" | tr _ ' ')"
  sleep "${step##*:}"
  printf ' [OK] %s: done (stub)\n' "$label"
done
if [ -n "${STUB_CONSOLE_SECONDS:-}" ]; then
  # Not on the output and the errors of the script: a console that outlived a stop would hold them open, and the test would wait
  # for its end instead of seeing that it still runs.
  bin/console demo-data:stub --from-generator > /dev/null 2>&1
fi
echo "${0##*/}: done"
exit "${STUB_EXIT:-0}"
"""

CONSOLE = r"""#!/bin/sh
echo "console $*" >> "$STUB_DIR/calls"
echo $$ > "$STUB_DIR/console.pid"
[ -z "${STUB_CONSOLE_IGNORES_TERM:-}" ] || trap '' TERM
sleep "${STUB_CONSOLE_SECONDS:-1}"
"""

PSQL = r"""#!/bin/sh
echo "psql $*" >> "$STUB_DIR/calls"
"""


def live_members(group):
    """The processes of the process group <group> that are not zombies (a zombie runs nothing and holds no session of the database)."""
    found = []
    for stat in pathlib.Path("/proc").glob("[0-9]*/stat"):
        try:
            line = stat.read_text()
        except OSError:
            continue
        fields = line[line.rindex(")") + 2 :].split()
        if int(fields[2]) == group and fields[0] != "Z":
            found.append(int(stat.parent.name))
    return found


def alive(pid):
    try:
        line = pathlib.Path(f"/proc/{pid}/stat").read_text()
    except OSError:
        return False
    return line[line.rindex(")") + 2 :].split()[0] != "Z"


def process_group(pid):
    line = pathlib.Path(f"/proc/{pid}/stat").read_text()
    return int(line[line.rindex(")") + 2 :].split()[2])


class Script(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self.tmp.name)
        self.stubs = self.base / "stubs"
        self.app = self.base / "app"
        (self.app / "bin").mkdir(parents=True)
        self.stubs.mkdir()
        for path, text in ((self.stubs / "df", DF), (self.stubs / "psql", PSQL), (self.app / "bin/generate-demo", GENERATOR),
                           (self.app / "bin/seed-demo", GENERATOR), (self.app / "bin/console", CONSOLE)):
            path.write_text(text)
            path.chmod(0o755)
        self.calls_file = self.base / "calls"
        self.calls_file.write_text("")
        (self.base / "df.log").write_text("")
        self.termination_log = self.base / "termination-log"
        self.bystanders = []

    def tearDown(self):
        for bystander in self.bystanders:
            if bystander.poll() is None:
                bystander.kill()
            bystander.wait()
        self.tmp.cleanup()

    def environment(self, env):
        variables = {
            "PATH": f"{self.stubs}:{os.environ['PATH']}",
            "HOME": str(self.base),
            "TMPDIR": str(self.base),
            "STUB_DIR": str(self.base),
            "DEMO_DATA_CONFIRM": "medium",
            "DEMO_DATA_SPACE_POLL": "1",
            "DEMO_DATA_STOP_GRACE": "1",
            "DEMO_DATA_TERMINATION_LOG": str(self.termination_log),
        }
        variables.update(env)
        return {key: value for key, value in variables.items() if value is not None}

    def start(self, args=PROFILE_ARGS, **env):
        """The script in a process group of its own, which it shares with a process that is not the generator's (PID 1 of a container
        is in the group of the container), and one more process in the group of the test."""
        mate = subprocess.Popen(["sleep", "120"], process_group=0)
        self.bystanders.append(mate)
        self.bystanders.append(subprocess.Popen(["sleep", "120"]))
        return subprocess.Popen(["sh", str(SCRIPT), *args], cwd=self.app, env=self.environment(env), stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, text=True, process_group=mate.pid)

    def finish(self, process, timeout=60):
        out, err = process.communicate(timeout=timeout)
        return process.returncode, out, err

    def run_script(self, args=PROFILE_ARGS, **env):
        return self.finish(self.start(args, **env))

    def calls(self):
        return self.calls_file.read_text().splitlines()

    def started(self):
        return [call for call in self.calls() if call.startswith(("generate-demo", "seed-demo"))]

    def wait_for(self, path, seconds=20):
        deadline = time.time() + seconds
        while time.time() < deadline:
            if path.exists() and path.read_text().strip():
                return int(path.read_text().strip())
            time.sleep(0.1)
        self.fail(f"{path.name} did not appear in {seconds} s")

    def reason(self, code, out=None):
        """The one line of the termination log; with <out>, also the last line of the log."""
        lines = self.termination_log.read_text().splitlines()
        self.assertEqual(1, len(lines), lines)
        self.assertTrue(lines[0].startswith(f"exit {code}: "), lines[0])
        if out is not None:
            self.assertEqual(f"demo-data: {lines[0]}", out.splitlines()[-1], "the reason is the end of the log")
        return lines[0]

    def assert_the_rest_is_untouched(self):
        """Nothing but the group of the generator: the processes of the script's group and of the test's group live on, the script ran
        no psql (it never ends a session of the database) and bin/console ran only inside the generator."""
        for bystander in self.bystanders:
            self.assertIsNone(bystander.poll(), "a process outside the group of the generator was stopped")
        self.assertEqual([], [call for call in self.calls() if call.startswith("psql")])
        for call in self.calls():
            if call.startswith("console"):
                self.assertIn("--from-generator", call)

    def assert_the_group_is_gone(self):
        group = int((self.base / "generator.pid").read_text())
        self.assertEqual([], live_members(group), "processes of the group of the generator still run")
        if (self.base / "console.pid").exists():
            self.assertFalse(alive(int((self.base / "console.pid").read_text())), "the command in the foreground still runs")


class RefusalTest(Script):
    def test_without_the_confirmation_nothing_starts_and_the_code_is_64(self):
        for confirm in (None, "", "server", "MEDIUM", " medium", "medium ", "small"):
            with self.subTest(confirm=confirm):
                self.calls_file.write_text("")
                code, out, _ = self.run_script(DEMO_DATA_CONFIRM=confirm)
                self.assertEqual(64, code, out)
                self.assertEqual([], self.started())
                self.assertEqual([], [call for call in self.calls() if call.startswith("df")], "nothing at all runs before the confirmation")
                self.assertIn("DEMO_DATA_CONFIRM", self.reason(64, out))

    def test_wrong_arguments_refuse_like_a_missing_confirmation(self):
        for args, env in (
            (("--anchor=" + ANCHOR, "--min-free-gb=32"), {}),
            (("--profile=medium", "--min-free-gb=ten"), {}),
            (("--profile=medium",), {}),
            (("--profile=medium", "--min-free-gb=32", "--unknown"), {}),
            (PROFILE_ARGS, {"DEMO_DATA_SPACE_POLL": "0"}),
            (PROFILE_ARGS, {"DEMO_DATA_SPACE_POLL": "1s"}),
        ):
            with self.subTest(args=args, env=env):
                code, out, _ = self.run_script(args, **env)
                self.assertEqual(64, code, out)
                self.assertEqual([], self.started())
                self.reason(64, out)

    def test_too_little_room_or_a_failed_reading_before_the_start_gives_74_and_nothing_starts(self):
        for reading in (LOW, "31249999", "fail", "none", "n/a", "12G"):
            with self.subTest(reading=reading):
                (self.base / "df.log").write_text("")
                code, out, _ = self.run_script(STUB_DF=reading)
                self.assertEqual(74, code, out)
                self.assertEqual([], self.started())
                self.assertIn("df -Pk /", self.calls())
                line = self.reason(74, out)
                if reading in (LOW, "31249999"):
                    self.assertIn("32 GB", line)
                    self.assertIn(reading, line)


class RunTest(Script):
    def test_the_profile_runs_the_generator_with_the_anchor_and_force_reset(self):
        code, out, _ = self.run_script(STUB_STEPS="Seeded:0")
        self.assertEqual(0, code, out)
        self.assertEqual([f"generate-demo --profile=medium --anchor={ANCHOR} --force-reset"], self.started())
        self.assertIn("generate-demo: done", out, "the output of the generator is in the log")
        self.assertIn(" [OK] Seeded: done (stub)", out)
        self.reason(0, out)
        self.assert_the_rest_is_untouched()

    def test_without_an_anchor_the_generator_gets_none(self):
        code, out, _ = self.run_script(("--profile=small", "--min-free-gb=2"), DEMO_DATA_CONFIRM="small")
        self.assertEqual(0, code, out)
        self.assertEqual(["generate-demo --profile=small --force-reset"], self.started())

    def test_seed_runs_the_seed_and_not_the_generator(self):
        code, out, _ = self.run_script(DEMO_DATA_CONFIRM="seed", STUB_STEPS="Seeded:0")
        self.assertEqual(0, code, out)
        self.assertEqual(["seed-demo "], self.started())
        self.assertIn("bin/seed-demo ended with 0", self.reason(0, out))

    def test_the_code_of_the_generator_is_the_code_of_the_script(self):
        for exit_code in ("1", "2", "3"):
            with self.subTest(exit_code=exit_code):
                code, out, _ = self.run_script(STUB_EXIT=exit_code, STUB_STEPS="Seed:0 Products:0")
                self.assertEqual(int(exit_code), code, out)
                self.assertIn(" [OK] Products: done (stub)", out)
                self.assertIn(f"ended with {exit_code}", self.reason(exit_code, out))

    def test_the_generator_runs_in_a_process_group_of_its_own(self):
        process = self.start(STUB_CONSOLE_SECONDS="3")
        generator = self.wait_for(self.base / "generator.pid")
        self.wait_for(self.base / "console.pid")
        self.assertEqual(generator, process_group(generator))
        self.assertNotEqual(process.pid, process_group(generator))
        code, out, _ = self.finish(process)
        self.assertEqual(0, code, out)


class QuickEndTest(unittest.TestCase):
    """A generator that ends at once (it refuses its arguments, or the seed of a test) ends the script at once: the guard, which
    starts a moment before, may get the TERM of the script before it set its own trap, and that TERM is lost (dash and busybox ash
    alike), so the guard must stop by itself too. Many runs at once make the race likely."""

    RUNS = 24

    def test_the_guard_never_outlives_a_generator_that_ended_at_once(self):
        from concurrent.futures import ThreadPoolExecutor

        def once(_):
            case = Script("run_script")
            case.setUp()
            try:
                process = case.start(DEMO_DATA_CONFIRM="seed", STUB_DF="1000")
                try:
                    process.communicate(timeout=15)
                    return process.returncode
                except subprocess.TimeoutExpired:
                    os.killpg(os.getpgid(process.pid), signal.SIGKILL)
                    process.communicate()
                    return "the script did not end in 15 s"
            finally:
                case.tearDown()

        with ThreadPoolExecutor(self.RUNS) as pool:
            self.assertEqual([0] * self.RUNS, list(pool.map(once, range(self.RUNS))))


class GuardTest(Script):
    def test_the_space_falling_below_the_floor_stops_the_whole_group_with_the_command_in_the_foreground(self):
        code, out, _ = self.run_script(STUB_DF=f"{PLENTY} {PLENTY} {LOW}", STUB_STEPS="Seed:0", STUB_CONSOLE_SECONDS="60",
                                       STUB_CONSOLE_IGNORES_TERM="1")
        self.assertEqual(75, code, out)
        self.assert_the_group_is_gone()
        self.assert_the_rest_is_untouched()
        line = self.reason(75, out)
        self.assertIn(LOW, line)
        self.assertIn("32 GB", line)
        self.assertIn("demoData.minFreeGb", line)

    def test_three_failed_readings_in_a_row_stop_the_group(self):
        code, out, _ = self.run_script(STUB_DF=f"{PLENTY} fail fail fail", STUB_CONSOLE_SECONDS="60", STUB_CONSOLE_IGNORES_TERM="1")
        self.assertEqual(75, code, out)
        self.assert_the_group_is_gone()
        self.assert_the_rest_is_untouched()
        self.assertIn("3 times in a row", self.reason(75, out))

    def test_failed_readings_that_are_not_three_in_a_row_do_not_stop_it(self):
        code, out, _ = self.run_script(STUB_DF=f"{PLENTY} fail fail {PLENTY} fail fail {PLENTY}", STUB_CONSOLE_SECONDS="7")
        self.assertEqual(0, code, out)
        self.assertGreaterEqual(len((self.base / "df.log").read_text().splitlines()), 7, out)
        self.reason(0)

    def test_a_reading_that_is_no_number_stops_the_group_at_once(self):
        code, out, _ = self.run_script(STUB_DF=f"{PLENTY} 12abc", STUB_CONSOLE_SECONDS="60", STUB_CONSOLE_IGNORES_TERM="1")
        self.assertEqual(75, code, out)
        self.assert_the_group_is_gone()
        self.assert_the_rest_is_untouched()
        self.assertIn("12abc", self.reason(75))
        self.assertEqual(2, len((self.base / "df.log").read_text().splitlines()))

    def test_term_stops_the_group_with_the_command_in_the_foreground(self):
        process = self.start(STUB_STEPS="Seed:0", STUB_CONSOLE_SECONDS="60", STUB_CONSOLE_IGNORES_TERM="1")
        self.wait_for(self.base / "console.pid")
        process.send_signal(signal.SIGTERM)
        code, out, _ = self.finish(process)
        self.assertEqual(75, code, out)
        self.assert_the_group_is_gone()
        self.assert_the_rest_is_untouched()
        self.assertIn("TERM", self.reason(75, out))

    def test_every_tenth_reading_is_in_the_log(self):
        code, out, _ = self.run_script(STUB_CONSOLE_SECONDS="13")
        self.assertEqual(0, code, out)
        readings = len((self.base / "df.log").read_text().splitlines())
        self.assertGreaterEqual(readings, 11, out)
        printed = [int(n) for n in re.findall(r"\(reading (\d+)\)", out)]
        self.assertEqual([1, 10], printed, out)


class SeedFloorTest(Script):
    """The seed (DEMO_DATA_CONFIRM=seed) has no floor of the free space: it is the one way back that make offers, it starts with
    TRUNCATE (it frees room at once) and needs less than 2 GiB, so a refusal would leave the operator with nothing to do. It needs a
    reading that is a number before the start, and the guard stops it only after three failed readings in a row or a reading that is
    no number. A profile keeps the whole floor (demoData.minFreeGb)."""

    # KiB: 20.48 GB (below the floor of 32 GB of a profile).
    BELOW_FLOOR = "20000000"

    def test_the_seed_starts_below_the_floor_however_little_room_there_is(self):
        for reading in (self.BELOW_FLOOR, "1000", "0"):
            with self.subTest(reading=reading):
                self.calls_file.write_text("")
                (self.base / "df.log").write_text("")
                code, out, _ = self.run_script(DEMO_DATA_CONFIRM="seed", STUB_DF=reading)
                self.assertEqual(0, code, out)
                self.assertEqual(["seed-demo "], self.started())
                self.assertIn("the floor is none (the seed)", out)
                self.reason(0, out)

    def test_the_seed_without_a_reading_that_is_a_number_refuses_with_74(self):
        for reading in ("fail", "none", "n/a", "12G"):
            with self.subTest(reading=reading):
                self.calls_file.write_text("")
                (self.base / "df.log").write_text("")
                code, out, _ = self.run_script(DEMO_DATA_CONFIRM="seed", STUB_DF=reading)
                self.assertEqual(74, code, out)
                self.assertEqual([], self.started())
                self.reason(74, out)

    def test_the_guard_of_the_seed_does_not_stop_it_on_a_low_reading(self):
        code, out, _ = self.run_script(DEMO_DATA_CONFIRM="seed", STUB_DF="1000 500 0", STUB_CONSOLE_SECONDS="4")
        self.assertEqual(0, code, out)
        self.assertGreaterEqual(len((self.base / "df.log").read_text().splitlines()), 3, out)
        self.reason(0, out)

    def test_the_guard_of_the_seed_stops_it_after_three_failed_readings_or_one_that_is_no_number(self):
        for readings, words in (("1000 fail fail fail", "3 times in a row"), ("1000 12abc", "12abc")):
            with self.subTest(readings=readings):
                self.calls_file.write_text("")
                (self.base / "df.log").write_text("")
                (self.base / "console.pid").unlink(missing_ok=True)
                code, out, _ = self.run_script(DEMO_DATA_CONFIRM="seed", STUB_DF=readings, STUB_CONSOLE_SECONDS="60",
                                               STUB_CONSOLE_IGNORES_TERM="1")
                self.assertEqual(75, code, out)
                self.assert_the_group_is_gone()
                self.assert_the_rest_is_untouched()
                self.assertIn(words, self.reason(75))

    def test_a_profile_keeps_the_whole_floor(self):
        code, out, _ = self.run_script(STUB_DF=self.BELOW_FLOOR)
        self.assertEqual(74, code, out)
        self.assertEqual([], self.started())
        self.assertIn("32 GB (demoData.minFreeGb)", self.reason(74, out))


if __name__ == "__main__":
    unittest.main()

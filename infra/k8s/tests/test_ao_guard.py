"""scripts/ao-guard.sh and the targets of the Makefile that use it (docs/features/agent-security/spec.md, R5 and path 14): while
an AO run or one of its executors is alive, the targets that write or mount KUBE_DIR (the kubeconfig of production) refuse,
with a message and without any change. The process list is a stub of `ps`; the targets run as the recipes of the Makefile
say, so the wiring is tested too. docker and ssh are stubs that only log."""

import os
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO

K8S = REPO / "infra/k8s"
SCRIPTS = K8S / "scripts"
TARGETS = ("kubeconfig", "rerun", "create-admin", "restore", "backups")
PYTHON = "/Applications/Xcode.app/Contents/Developer/Library/Frameworks/Python3.framework/Versions/3.9/Resources/Python.app/Contents/MacOS/Python"
KUBECONFIG = "apiVersion: v1\nclusters:\n- cluster:\n    server: https://127.0.0.1:6443\n  name: default\nusers:\n- user:\n    client-key-data: ADMINCREDENTIAL\n  name: default\n"

# Lines of `ps -axww -o pid= -o ppid= -o command=` as they look on the computer of the user while AO runs.
INNOCENT = (
    "    1     0 /sbin/launchd",
    "  501     1 /Applications/Docker.app/Contents/MacOS/com.docker.backend",
    "  900   899 -zsh",
    " 1000   900 make -C infra/k8s rerun JOB=initialize-3fa9c1d2e4",
    " 1001  1000 sh scripts/ao-guard.sh rerun",
    " 1100   900 grep orchestrator",
    " 1101   900 grep -m orchestrator run",
    " 1200   900 python3 -m orchestrator --home /Users/pp/.local/state/ao-agent-security status",
    " 1201   900 python3 -m orchestrator --home /Users/pp/.local/state/ao-agent-security tasks --running",
    " 1202   900 python3 -m unittest discover -s orchestrator/tests",
    " 1203   900 python3 -m orchestratorx run",
    " 1204   900 python3 orchestrator.py run",
    " 1205   900 /usr/bin/python3-config -m orchestrator run --help-not-python",
    " 1206   900 /usr/bin/pythonista -m orchestrator run",
    " 1300   900 vim orchestrator/runner.py",
    " 1301   900 cat /Users/pp/Development/claude/agentic-orchestrator/orchestrator/runner.py",
    " 1302   900 cat tools/agent-security/claude-agent",
    " 1303   900 git diff -- tools/agent-security/claude-agent",
    " 1304   900 tail -f /tmp/claude-agent.log",
    " 1305   900 shellcheck tools/agent-security/claude-agent",
    " 1400   900 /opt/homebrew/Caskroom/claude-code/2.1.311/claude",
    " 1401   900 /opt/homebrew/Caskroom/claude-code/2.1.311/claude -p /groom",
    " 1500   900 /bin/zsh -c source /Users/pp/.claude/shell-snapshots/snapshot.sh && make -C infra/k8s backups",
)
AO_RUN = (
    "77460 77443 " + PYTHON + " -m orchestrator --home /Users/pp/.local/state/ao-agent-security run --until-idle "
    "--claude-bin /opt/homebrew/Caskroom/claude-code/2.1.311/claude --max-budget-usd 30 --timeout 14400",
    "77460 77443 python3 -m orchestrator run --home /Users/pp/.local/state/ao-x",
    "77460 77443 /usr/bin/python3 -m orchestrator --home /Users/pp/.local/state/ao-x run",
    "77460 77443 /opt/homebrew/bin/python3.13 -u -m orchestrator --home /Users/pp/.local/state/ao-x run --until-idle",
    "77460 77443 /opt/homebrew/bin/python3.13t -m orchestrator --home /Users/pp/.local/state/ao-x run",
    "77460 77443 /usr/bin/python3-intel64 -m orchestrator --home /Users/pp/.local/state/ao-x run",
    "77461 77460 caffeinate -dims env PYTHONPATH=/Users/pp/Development/claude/agentic-orchestrator python3 -m orchestrator "
    "--home /Users/pp/.local/state/ao-agent-security run --until-idle --max-budget-usd 30",
    "66276 56071 /bin/zsh -c source /Users/pp/.claude/shell-snapshots/snapshot.sh && python3 -m orchestrator --home /x run",
)
RUNNER = (
    "35614 77460 " + PYTHON + " /Users/pp/Development/claude/agentic-orchestrator/orchestrator/runner.py "
    "/Users/pp/.local/state/ao-agent-security/tasks/AGENT-SECURITY-4/attempts/1 7,6 10 -- /opt/homebrew/Caskroom/claude-code/2.1.311/claude "
    "-p /delivery Task AGENT-SECURITY-4",
    "35614 77460 python3 orchestrator/runner.py attempts/1 7,6",
    "35614 77460 /usr/bin/python3 -W ignore /x/orchestrator/runner.py attempts/1 7,6",
    "35614 77460 python3 -m orchestrator.runner attempts/1 7,6",
)
EXECUTOR = (
    "35700 35614 /bin/sh /Users/pp/.local/state/ao-agent-security/agent-security/claude-agent -p /delivery Task X --model opus",
    "35700 35614 /Users/pp/.local/state/ao-agent-security/agent-security/claude-agent -p /delivery Task X",
    "35700 35614 sh /x/agent-security/claude-agent -p /delivery",
    "35700 35614 bash -x /x/agent-security/claude-agent -p /delivery",
    "35700 35614 caffeinate -dims env -u CLAUDE_CODE_ENTRYPOINT -u CLAUDE_CODE_SIMPLE /x/agent-security/claude-agent -p /delivery",
    "35700 35614 env AGENT_AO_HOME=/Users/pp/.local/state/ao-x AO_TASK_ID=X claude-agent -p /delivery",
    "35700 35614 /usr/bin/python3 /x/agent-security/claude-agent -p /delivery",
)

# The stubs: ps prints $STUB_PS and exits with $STUB_PS_STATUS; docker and ssh log their arguments (ssh answers the read of the
# kubeconfig like the server does).
PS = '#!/bin/sh\necho "$*" >> "$STUB_LOG.ps"\ncat "$STUB_PS"\nexit "${STUB_PS_STATUS:-0}"\n'
DOCKER = '#!/bin/sh\necho "docker $*" >> "$STUB_LOG"\nexit 0\n'
SSH = """#!/bin/sh
echo "ssh $*" >> "$STUB_LOG"
case "$*" in *"cat /etc/rancher/k3s/k3s.yaml"*) cat "$STUB_DIR/kubeconfig" ;; esac
"""


def run(command, **options):
    """subprocess.run that cannot hang the test: a command that runs longer than 30 s (a target that went on to wait for a
    cluster that the stubs do not play) is a failed run with status 124."""
    try:
        return subprocess.run(command, capture_output=True, text=True, timeout=30, **options)
    except subprocess.TimeoutExpired as error:
        return subprocess.CompletedProcess(command, 124, "", f"timed out: {error}")


def recipe(target):
    """The lines of the recipe of a target of infra/k8s/Makefile, as make runs them (one shell each)."""
    lines = (K8S / "Makefile").read_text().splitlines()
    start = lines.index(target + ":")
    body = []
    for line in lines[start + 1:]:
        if not line.startswith("\t"):
            break
        body.append(line[1:])
    return body


class AoGuardCase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = pathlib.Path(self.tmp.name)
        self.home = base / "home"
        self.home.mkdir()
        self.bin = base / "bin"
        self.bin.mkdir()
        for name, body in (("ps", PS), ("docker", DOCKER), ("ssh", SSH)):
            (self.bin / name).write_text(body)
            (self.bin / name).chmod(0o755)
        self.stub = base / "stub"
        self.stub.mkdir()
        self.log = self.stub / "log"
        self.log.write_text("")
        self.ps_file = self.stub / "ps"
        self.processes(*INNOCENT)
        (self.stub / "kubeconfig").write_text(KUBECONFIG)
        self.kube = base / "kube"
        self.kube.mkdir()
        (self.kube / "config").write_text(KUBECONFIG)

    def tearDown(self):
        self.tmp.cleanup()

    def processes(self, *lines):
        self.ps_file.write_text("\n".join(lines) + "\n")

    def environment(self, **env):
        return {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": str(self.home),
            "TMPDIR": self.tmp.name,
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.stub),
            "STUB_PS": str(self.ps_file),
            **env,
        }

    def guard(self, target="rerun", **env):
        return run(["sh", str(SCRIPTS / "ao-guard.sh"), target], env=self.environment(**env))

    def make(self, target, stdin="", **variables):
        """The recipe of the target with $(NAME) taken from the variables, one `sh -c` per line, stopping at the first failure."""
        result = None
        for line in recipe(target):
            line = re.sub(r"\$\(([A-Z_]+)\)", lambda m: variables.get(m.group(1), ""), line.lstrip("@-"))
            result = run(["sh", "-c", line], cwd=K8S, env=self.environment(**variables), input=stdin)
            if result.returncode != 0:
                break
        return result

    def calls(self):
        return self.log.read_text().splitlines()

    def tree(self, directory):
        return sorted((str(p.relative_to(directory)), p.read_bytes() if p.is_file() else None) for p in directory.rglob("*"))

    def arguments(self, target):
        """The arguments each target needs to get as far as its own work (a valid command line)."""
        return {
            "kubeconfig": {"HOST": "deploy@203.0.113.7", "KUBE_DIR": str(self.kube)},
            "rerun": {"JOB": "initialize-3fa9c1d2e4", "KUBE_DIR": str(self.kube)},
            "create-admin": {"LOGIN": "anna", "EMAIL": "anna@example.net", "KUBE_DIR": str(self.kube)},
            "restore": {"SNAPSHOT": "9e050a3d", "KUBE_DIR": str(self.kube)},
            "backups": {"KUBE_DIR": str(self.kube)},
        }[target]


class Guard(AoGuardCase):
    def refused(self, result, target="rerun"):
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn(f"ao-guard: {target}: refused", result.stderr)
        self.assertIn("AO", result.stderr)
        self.assertIn("stop AO or wait", result.stderr)
        self.assertEqual("", result.stdout)

    def test_it_lets_the_targets_through_when_nothing_of_AO_runs(self):
        result = self.guard()
        self.assertEqual((0, "", ""), (result.returncode, result.stdout, result.stderr))

    def test_an_AO_run_is_refused(self):
        for line in AO_RUN:
            with self.subTest(line=line[:80]):
                self.processes(*INNOCENT, line)
                self.refused(self.guard())

    def test_a_runner_of_AO_is_refused(self):
        for line in RUNNER:
            with self.subTest(line=line[:80]):
                self.processes(*INNOCENT, line)
                self.refused(self.guard())

    def test_a_live_executor_is_refused(self):
        for line in EXECUTOR:
            with self.subTest(line=line[:80]):
                self.processes(*INNOCENT, line)
                self.refused(self.guard())

    def test_an_executor_is_refused_even_when_AO_is_gone(self):
        # The executor outlives AO (it starts in a session of its own): only the runner or the wrapper is left.
        self.processes(*INNOCENT, RUNNER[0])
        self.refused(self.guard())
        self.processes(*INNOCENT, EXECUTOR[0])
        self.refused(self.guard())

    def test_the_message_names_the_target_and_the_process_and_stays_short(self):
        self.processes(*INNOCENT, AO_RUN[0])
        result = self.guard("restore")
        self.refused(result, "restore")
        self.assertIn("77460", result.stderr)
        self.assertIn("-m orchestrator", result.stderr)
        self.assertLess(max(len(line) for line in result.stderr.splitlines()), 200)
        # The long command line of an executor (the prompt of the task) is cut.
        self.processes(*INNOCENT, RUNNER[0] + " x" * 500)
        self.assertLess(max(len(line) for line in self.guard().stderr.splitlines()), 200)

    def test_what_looks_like_AO_but_is_not_a_run_is_let_through(self):
        for line in INNOCENT:
            with self.subTest(line=line.strip()[:80]):
                self.processes("1 0 /sbin/launchd", line)
                result = self.guard()
                self.assertEqual(0, result.returncode, result.stderr)

    def test_a_failing_ps_refuses(self):
        for status in ("1", "2", "127"):
            with self.subTest(status=status):
                result = self.guard(STUB_PS_STATUS=status)
                self.assertEqual(1, result.returncode, result.stdout)
                self.assertIn("ao-guard: rerun: refused", result.stderr)
                self.assertIn("could not list the processes", result.stderr)

    def test_a_ps_that_prints_nothing_refuses(self):
        self.processes()
        self.ps_file.write_text("")
        result = self.guard()
        self.assertEqual(1, result.returncode)
        self.assertIn("could not list the processes", result.stderr)

    def test_a_missing_ps_refuses(self):
        (self.bin / "ps").unlink()
        # A PATH with the tools of the script but no ps.
        tools = pathlib.Path(self.tmp.name) / "tools"
        tools.mkdir()
        for tool in ("awk", "sh", "cat", "printf", "head", "cut", "tr", "sed", "grep"):
            found = subprocess.run(["sh", "-c", f"command -v {tool}"], capture_output=True, text=True).stdout.strip()
            if found:
                (tools / tool).symlink_to(found)
        result = self.guard(PATH=str(tools))
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("could not list the processes", result.stderr)

    def test_the_target_is_required(self):
        result = run(["sh", str(SCRIPTS / "ao-guard.sh")], env=self.environment())
        self.assertEqual(2, result.returncode)
        self.assertIn("usage", result.stderr)

    def test_it_reads_nothing_from_the_standard_input_and_has_no_switch(self):
        # The confirmation `tak` stays on the standard input for the script that asks it; the guard never reads it, and no
        # variable of the environment turns it off (an executor could set one).
        text = "\n".join(line for line in (SCRIPTS / "ao-guard.sh").read_text().splitlines() if not line.lstrip().startswith("#"))
        self.assertNotRegex(text, r"\bread\b")
        self.assertNotIn("/dev/tty", text)
        self.assertNotRegex(text, r"\$\{?(AO_GUARD|SKIP|FORCE|DISABLE|NO_)\w*")
        self.processes(*INNOCENT, AO_RUN[0])
        result = self.guard(AO_GUARD="off", SKIP_AO_GUARD="1", FORCE="1")
        self.assertEqual(1, result.returncode)

    def test_it_asks_ps_for_every_process_with_its_whole_command_line(self):
        self.guard()
        self.assertEqual(["-axww -o pid= -o ppid= -o command="], pathlib.Path(str(self.log) + ".ps").read_text().splitlines())


class Targets(AoGuardCase):
    def test_every_target_calls_the_guard_first_and_the_others_do_not(self):
        for target in TARGETS:
            self.assertEqual(f"@sh scripts/ao-guard.sh {target}", recipe(target)[0], target)
        makefile = (K8S / "Makefile").read_text()
        calls = [line for line in makefile.splitlines() if line.startswith("\t") and "ao-guard.sh" in line]
        self.assertEqual(len(TARGETS), len(calls), calls)

    def test_the_scripts_of_the_smoke_test_do_not_call_the_guard(self):
        # smoke.sh runs rerun.sh, create-admin.sh, restore.sh and backups.sh itself, also from an agent's run of AO.
        for script in SCRIPTS.glob("*.sh"):
            if script.name != "ao-guard.sh":
                self.assertNotIn("ao-guard", script.read_text(), script.name)

    def test_with_AO_running_every_target_refuses_and_changes_nothing(self):
        for line in (AO_RUN[0], RUNNER[0], EXECUTOR[0]):
            self.processes(*INNOCENT, line)
            for target in TARGETS:
                with self.subTest(target=target, process=line[:60]):
                    before = self.tree(self.kube)
                    result = self.make(target, stdin="tak\n", **self.arguments(target))
                    self.assertEqual(1, result.returncode, result.stdout + result.stderr)
                    self.assertIn(f"ao-guard: {target}: refused", result.stderr)
                    self.assertIn("stop AO or wait", result.stderr)
                    self.assertEqual([], self.calls())
                    self.assertEqual(before, self.tree(self.kube))
                    self.assertEqual(["config"], [name for name, _ in self.tree(self.kube)])
                    self.assertEqual([], sorted(p.name for p in self.home.rglob("*")))

    def test_a_failing_ps_stops_every_target_too(self):
        for target in TARGETS:
            with self.subTest(target=target):
                result = self.make(target, stdin="tak\n", STUB_PS_STATUS="1", **self.arguments(target))
                self.assertEqual(1, result.returncode, result.stdout + result.stderr)
                self.assertIn(f"ao-guard: {target}: refused", result.stderr)
                self.assertEqual([], self.calls())

    def test_the_refusal_comes_before_the_checks_of_the_arguments(self):
        self.processes(*INNOCENT, AO_RUN[0])
        for target in TARGETS:
            with self.subTest(target=target):
                result = self.make(target)
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertIn("ao-guard", result.stderr)

    def test_without_AO_the_targets_run_as_before(self):
        # kubeconfig writes the config of the server; the others reach their own checks and say what they say without a guard.
        result = self.make("kubeconfig", **{**self.arguments("kubeconfig"), "KUBE_DIR": str(self.kube / "new")})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("ADMINCREDENTIAL", (self.kube / "new" / "config").read_text())
        for target, message in (
            ("rerun", "set KUBE_DIR"), ("backups", "set KUBE_DIR"), ("restore", "set KUBE_DIR"), ("create-admin", "set KUBE_DIR"),
        ):
            with self.subTest(target=target):
                arguments = {**self.arguments(target), "KUBE_DIR": ""}
                result = self.make(target, stdin="tak\n", **arguments)
                self.assertEqual(2, result.returncode, result.stdout + result.stderr)
                self.assertIn(message, result.stderr)
                self.assertNotIn("ao-guard", result.stderr)

    def test_without_AO_create_admin_still_asks_for_tak_on_the_standard_input(self):
        result = self.make("create-admin", stdin="nie\n", **self.arguments("create-admin"))
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("not confirmed", result.stderr)
        self.assertEqual([], self.calls())
        self.assertIn("type tak to go on", result.stdout)

    def test_the_targets_that_work_over_ssh_without_KUBE_DIR_are_not_guarded(self):
        self.processes(*INNOCENT, AO_RUN[0])
        for target in ("status", "reinitialize"):
            self.assertNotIn("ao-guard", "".join(recipe(target)))


if __name__ == "__main__":
    unittest.main()

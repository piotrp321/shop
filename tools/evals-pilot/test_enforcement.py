"""Regression tests of the budget, process and isolation rules of the eval pilot.

They run the real `run_case`, `run_claude`, checkout builders and settings renderer against a scratch git repository
and a stand-in for the `claude` CLI (a script that records how it was called). No model, no network.
"""
import importlib.util
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
REAL_ROOT = HERE.parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location(name, HERE / f"{name}.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


run, grade = load("run"), load("grade")

FAKE_CLI = r'''#!/usr/bin/env python3
import json, os, re, subprocess, sys, time
argv = sys.argv[1:]
log = os.environ["FAKE_LOG"]
mode = os.environ.get("FAKE_MODE", "ok")
json.dump({"argv": argv, "cwd": os.getcwd()}, open(log, "w"))
def out(o):
    print(json.dumps(o), flush=True)
def result(subtype="success", text="report", cost=0.7, is_error=False):
    out({"type": "result", "subtype": subtype, "is_error": is_error, "result": text, "total_cost_usd": cost,
         "num_turns": 1, "duration_ms": 1, "modelUsage": {"m": {}}, "permission_denials": []})
if "--json-schema" in argv:  # a grading call: one JSON document
    if mode == "garbage":
        print("not json")
    else:
        print(json.dumps({"total_cost_usd": 0.01, "structured_output": {"verdict": "PASS", "findings": []}}))
    sys.exit(0)
out({"type": "system", "subtype": "init", "claude_code_version": "fake"})
if mode == "ok":
    result()
elif mode == "no_result":
    pass
elif mode == "error_after_success":
    result(text="partial text", cost=0.2)
    result(subtype="error_during_execution", text=None, cost=0.9, is_error=True)
elif mode == "sleep":
    child = subprocess.Popen(["sleep", "60"])
    open(log + ".child", "w").write(str(child.pid))
    result(text="still working", cost=0.05)
    time.sleep(60)
elif mode == "subagent":
    order = re.search(r"<order>\n(.*)\n</order>", argv[argv.index("-p") + 1], re.S).group(1)
    out({"type": "assistant", "parent_tool_use_id": None, "message": {"content": [
        {"type": "tool_use", "id": "t1", "name": "Agent",
         "input": {"subagent_type": "code-reviewer", "run_in_background": False, "prompt": order}}]}})
    out({"type": "user", "parent_tool_use_id": None, "message": {"content": [
        {"type": "tool_result", "tool_use_id": "t1", "content": [{"type": "text", "text": "x"}]}]},
        "tool_use_result": {"status": "completed", "resolvedModel": "m-sub", "handbackReport": {"text": "sub report"}}})
    result(text="gotowe", cost=1.1)
'''


def sh(*args, cwd):
    return subprocess.run(list(args), cwd=cwd, check=True, capture_output=True, text=True).stdout.strip()


def alive(pid):
    try:
        os.kill(pid, 0)
        return True
    except ProcessLookupError:
        return False


def wait_dead(pid, seconds=5.0):
    end = time.time() + seconds
    while time.time() < end:
        if not alive(pid):
            return True
        time.sleep(0.05)
    return not alive(pid)


class Sandbox(unittest.TestCase):
    """A scratch repository with a case, the real settings renderer and the stand-in CLI."""

    def setUp(self):
        self._t = tempfile.TemporaryDirectory()
        self.addCleanup(self._t.cleanup)
        self.tmp = Path(self._t.name).resolve()
        self.root, self.out, self.cases = self.tmp / "root", self.tmp / "out", self.tmp / "cases"
        self.root.mkdir()
        g = ["git", "-c", "user.name=t", "-c", "user.email=t@example.invalid", "-c", "commit.gpgsign=false"]
        sh("git", "init", "-q", "-b", "main", ".", cwd=self.root)
        (self.root / ".claude" / "agents").mkdir(parents=True)
        (self.root / run.AGENT_PATH).write_text("old agent\n")
        (self.root / "app.txt").write_text("v1\n")
        sh(*g, "add", "-A", cwd=self.root)
        sh(*g, "commit", "-q", "-m", "base", cwd=self.root)
        self.base = sh("git", "rev-parse", "HEAD", cwd=self.root)
        (self.root / "app.txt").write_text("v2\n")
        sh(*g, "commit", "-q", "-am", "task", cwd=self.root)
        self.head = sh("git", "rev-parse", "HEAD", cwd=self.root)
        (self.root / run.AGENT_PATH).write_text("current agent\n")
        sh(*g, "commit", "-q", "-am", "agent", cwd=self.root)
        self.agent_rev = sh("git", "rev-parse", "HEAD", cwd=self.root)
        (self.root / "tools" / "agent-security").mkdir(parents=True)
        for name in ("render_settings.py", "executor-settings.json"):  # the real renderer and profile
            shutil.copy(REAL_ROOT / "tools" / "agent-security" / name, self.root / "tools" / "agent-security" / name)
        self.add_case("c1")
        self.cli = self.tmp / "fake-claude"
        self.cli.write_text(FAKE_CLI)
        self.cli.chmod(self.cli.stat().st_mode | stat.S_IXUSR)
        self.log = self.tmp / "cli.json"
        env = mock.patch.dict(os.environ, {"FAKE_LOG": str(self.log), "FAKE_MODE": "ok"})
        env.start()
        self.addCleanup(env.stop)
        for name, value in (("ROOT", self.root), ("CASES", self.cases)):
            p = mock.patch.object(run, name, value)
            p.start()
            self.addCleanup(p.stop)

    def add_case(self, name, base=None, head=None):
        d = self.cases / name / "replay"
        d.mkdir(parents=True)
        base, head = base or self.base, head or self.head
        (d.parent / "case.json").write_text(json.dumps({"id": name, "kind": "defect", "base_sha": base,
                                                        "head_sha": head}))
        (d.parent / "prompt.md").write_text("review {{BASE}}..{{HEAD}} with {{INPUT_DIR}}")
        (d / "replay.json").write_text(json.dumps({"task": "T-1", "pr": 1, "review_sha": head, "base_sha": base,
                                                   "branch": "task/T-1", "target_ref": "origin/epic/x"}))
        (d / "order.md").write_text("review in {{CHECKOUT}} with {{INPUT_DIR}}/tdd-evidence.jsonl")

    def go(self, *extra, mode="squash", fake="ok", case="c1"):
        os.environ["FAKE_MODE"] = fake
        return run.main(["--out", str(self.out), "--case", case, "--mode", mode, "--claude-bin", str(self.cli),
                         "--agent-rev", self.agent_rev, "--run-cap", "3", "--total-cap", "25", *extra])

    def meta(self, run_id="c1-1"):
        return json.loads((self.out / "runs" / run_id / "meta.json").read_text())

    def spent(self):
        return round(run.ledger_spent(self.out), 6)

    def called(self):
        return json.loads(self.log.read_text())


class Interruption(Sandbox):
    """Defect 1: an interrupted or timed out run must not leave `claude` (or what it started) running."""

    def child_pid(self):
        path = Path(str(self.log) + ".child")
        end = time.time() + 5
        while time.time() < end and not path.exists():
            time.sleep(0.02)
        return int(path.read_text())

    def test_a_timeout_ends_the_whole_process_group(self):
        self.assertEqual(self.go("--timeout-min", "0.02", fake="sleep"), 1)
        self.assertTrue(wait_dead(self.child_pid()), "the process started by the CLI is still running")
        self.assertEqual(self.meta()["status"], "infra_error")

    def test_an_interrupt_ends_the_group_and_keeps_the_full_cap_in_the_ledger(self):
        real_wait = subprocess.Popen.wait
        state = {"raised": False}

        def wait(proc, timeout=None):
            if not state["raised"] and proc.args[0] == str(self.cli):  # only the CLI, not the git calls before it
                state["raised"] = True
                self.child_pid()  # the CLI is up and has started its own process
                raise KeyboardInterrupt
            return real_wait(proc, timeout)

        with mock.patch.object(subprocess.Popen, "wait", wait):
            with self.assertRaises(KeyboardInterrupt):
                self.go(fake="sleep")
        self.assertTrue(wait_dead(self.child_pid()), "the process started by the CLI is still running")
        self.assertEqual(self.spent(), 3.0)
        meta = self.meta()
        self.assertEqual(meta["status"], "infra_error")
        self.assertIn("interrupted", meta["error"])
        self.assertFalse((self.out / "runs" / "c1-1" / "report.md").exists())


class CostAccounting(Sandbox):
    """Defect 2: when the real cost is not known the whole run cap is charged."""

    def test_a_timeout_after_a_partial_result_charges_the_cap(self):
        self.go("--timeout-min", "0.02", fake="sleep")
        self.assertEqual(self.spent(), 3.0)
        meta = self.meta()
        self.assertEqual((meta["cost_usd"], meta["cost_charged_usd"]), (0.05, 3.0))

    def test_a_transcript_without_a_result_charges_the_cap(self):
        self.assertEqual(self.go(fake="no_result"), 1)
        self.assertEqual(self.spent(), 3.0)
        self.assertEqual(self.meta()["cost_charged_usd"], 3.0)

    def test_a_finished_run_charges_its_reported_cost(self):
        self.assertEqual(self.go(), 0)
        self.assertEqual(self.spent(), 0.7)
        self.assertEqual(self.meta()["cost_charged_usd"], 0.7)

    def test_a_run_that_never_started_the_model_charges_nothing(self):
        self.add_case("bad", base="0" * 40)
        self.assertEqual(self.go(case="bad"), 1)
        self.assertEqual(self.spent(), 0.0)
        self.assertFalse(self.log.exists(), "the CLI must not run after a failed checkout")
        self.assertEqual(self.meta("bad-1")["status"], "infra_error")


class SeveralResults(Sandbox):
    """Defect 3: a later error result must not be hidden by an earlier success."""

    def test_parse_result_reports_the_error(self):
        path = self.tmp / "s.jsonl"

        def line(subtype, text, cost, is_error=False):
            return json.dumps({"type": "result", "subtype": subtype, "is_error": is_error, "result": text,
                               "total_cost_usd": cost})
        path.write_text("\n".join([line("success", "partial text", 0.2),
                                   line("error_during_execution", None, 0.9, True)]))
        info = run.parse_result(path)
        self.assertEqual((info["status"], info["cost_usd"]), ("infra_error", 0.9))
        path.write_text("\n".join([line("error_during_execution", None, 0.2, True), line("success", "late text", 0.9)]))
        self.assertEqual(run.parse_result(path)["status"], "infra_error")

    def test_the_run_gets_no_report(self):
        self.assertEqual(self.go(fake="error_after_success"), 1)
        self.assertEqual(self.meta()["status"], "infra_error")
        self.assertFalse((self.out / "runs" / "c1-1" / "report.md").exists())
        self.assertEqual(self.spent(), 0.9)


class Concurrency(Sandbox):
    """Defect 4: invocations sharing one output directory cannot overspend the total or share a run directory."""

    def test_a_reservation_counts_against_the_total_at_once(self):
        run.reserve(self.out, "run", "a", 3, 5)
        with self.assertRaises(SystemExit) as ctx:
            run.reserve(self.out, "run", "b", 3, 5)
        self.assertEqual(ctx.exception.code, 2)
        self.assertEqual(self.spent(), 3.0)
        run.settle(self.out, "run", "a", 3, 1.25)
        self.assertEqual(self.spent(), 1.25)

    def test_parallel_reservations_never_exceed_the_total(self):
        results = []

        def one(i):
            try:
                run.reserve(self.out, "run", f"r{i}", 3, 10)
                results.append(True)
            except SystemExit:
                results.append(False)
        threads = [threading.Thread(target=one, args=(i,)) for i in range(12)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
        self.assertEqual(sum(results), 3)
        self.assertEqual(self.spent(), 9.0)

    def test_parallel_runs_get_different_directories(self):
        got = []
        threads = [threading.Thread(target=lambda: got.append(run.claim_run_dir(self.out, "c1")[0])) for _ in range(12)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
        self.assertEqual(sorted(got), list(range(1, 13)))

    def test_a_grading_call_reserves_before_it_runs(self):
        run.reserve(self.out, "run", "a", 3, 3.1)
        with self.assertRaises(SystemExit) as ctx:
            grade.call_model("p", "{}", "m", self.out, "extract", "x", claude_bin=str(self.cli), total_cap=3.1)
        self.assertEqual(ctx.exception.code, 2)
        self.assertFalse(self.log.exists(), "the CLI must not run when the reservation is refused")

    def test_a_grading_call_with_an_unknown_cost_charges_its_cap(self):
        os.environ["FAKE_MODE"] = "garbage"
        data, cost = grade.call_model("p", "{}", "m", self.out, "extract", "x", claude_bin=str(self.cli))
        self.assertIsNone(data)
        self.assertEqual(self.spent(), 0.25)
        os.environ["FAKE_MODE"] = "ok"
        grade.call_model("p", "{}", "m", self.out, "extract", "y", claude_bin=str(self.cli))
        self.assertEqual(self.spent(), 0.26)
        argv = self.called()["argv"]
        for flag, value in (("--tools", ""), ("--setting-sources", ""), ("--max-budget-usd", "0.25"), ("--model", "m")):
            self.assertEqual(argv[argv.index(flag) + 1], value)
        self.assertIn("--strict-mcp-config", argv)


class SummaryWithoutExtraction(unittest.TestCase):
    """Defect 5: a run without a complete extraction is not part of the quality numbers."""

    def test_ungraded_runs_are_listed_apart(self):
        with tempfile.TemporaryDirectory() as t:
            out = Path(t)
            for run_id, kind, extracted in (("d-1", "defect", False), ("c-1", "clean", False), ("c-2", "clean", True)):
                d = out / "runs" / run_id
                d.mkdir(parents=True)
                (d / "meta.json").write_text(json.dumps({"case": run_id[0], "run": run_id, "kind": kind,
                                                         "status": "ok", "error": None, "cost_usd": 1, "wall_s": 1}))
                (d / "report.md").write_text("r")
                if extracted:
                    (d / "findings.json").write_text(json.dumps({"verdict": "PASS", "findings": []}))
            grade.main(["table", "--out", str(out)])
            grade.main(["summary", "--out", str(out)])
            text = (out / "summary.md").read_text()
        self.assertIn("defect runs with gold_detected true / defect runs ok: 0 / 0", text)
        self.assertIn("clean runs with zero falszywe among IMPORTANT+BLOCKER / clean runs ok: 1 / 1", text)
        self.assertIn("Runs without extraction (not graded):", text)
        self.assertIn("- d-1", text)
        self.assertIn("- c-1", text)
        self.assertNotIn("- c-2", text)


class RealEnforcement(Sandbox):
    """Defect 6: the command line, the sandbox settings and the checkouts as the tool really builds them."""

    def flag(self, name):
        argv = self.called()["argv"]
        return argv[argv.index(name) + 1]

    def settings(self):
        return json.loads((self.out / "runs" / "c1-1" / "settings.json").read_text())

    def test_squash_run_command_line_and_sandbox(self):
        self.assertEqual(self.go(), 0)
        run_dir = self.out / "runs" / "c1-1"
        called = self.called()
        self.assertEqual(Path(called["cwd"]).resolve(), (run_dir / "repo").resolve())
        self.assertEqual(self.flag("--max-budget-usd"), "3.0")
        self.assertEqual(self.flag("--agent"), "code-reviewer")
        self.assertEqual(self.flag("--setting-sources"), "project")
        self.assertEqual(self.flag("--permission-mode"), "auto")
        self.assertEqual(self.flag("--permission-prompts"), "none")
        self.assertEqual(Path(self.flag("--settings")), run_dir / "settings.json")
        self.assertEqual(Path(self.flag("--add-dir")), run_dir / "inputs")
        self.assertIn("--strict-mcp-config", called["argv"])
        self.assertNotIn("--model", called["argv"])
        box = self.settings()["sandbox"]
        self.assertTrue(box["enabled"] and box["failIfUnavailable"])
        self.assertFalse(box["allowUnsandboxedCommands"])
        self.assertEqual((box["network"]["allowedDomains"], box["network"]["allowUnixSockets"]), ([], []))
        files = box["filesystem"]
        self.assertIn(str(Path.home()), files["denyRead"])
        self.assertIn(str(run_dir / "repo" / ".git"), files["denyWrite"])
        for path in files["allowWrite"]:
            self.assertTrue(path.startswith(str(run_dir)), path)
        # the rebuilt task diff is the original one and the agent comes from the requested revision
        repo = run_dir / "repo"
        self.assertEqual(sh("git", "diff", "HEAD~1", "HEAD", "--stat", cwd=repo).split()[0], "app.txt")
        self.assertEqual((repo / run.AGENT_PATH).read_text(), "current agent\n")
        self.assertEqual(sh("git", "status", "--porcelain", cwd=repo), "")

    def test_replay_run_command_line_checkout_and_report(self):
        self.assertEqual(self.go(mode="replay", fake="subagent"), 0)
        run_dir = self.out / "runs" / "c1-1"
        argv = self.called()["argv"]
        self.assertEqual(self.flag("--model"), "sonnet")
        self.assertNotIn("--agent", argv)
        self.assertEqual(self.flag("--max-budget-usd"), "3.0")
        self.assertEqual(self.flag("--setting-sources"), "project")
        self.assertIn("--strict-mcp-config", argv)
        repo = run_dir / "repo"
        self.assertEqual(sh("git", "rev-parse", "HEAD", cwd=repo), self.head)
        self.assertEqual(sh("git", "branch", "--show-current", cwd=repo), "task/T-1")
        self.assertEqual(sh("git", "rev-parse", "refs/remotes/origin/epic/x", cwd=repo), self.base)
        self.assertEqual(sh("git", "status", "--porcelain", cwd=repo), "")
        self.assertEqual(sh("git", "show", f"HEAD:{run.AGENT_PATH}", cwd=repo), "old agent")
        self.assertEqual((repo / run.AGENT_PATH).read_text(), "current agent\n")
        self.assertEqual((run_dir / "report.md").read_text(), "sub report")
        self.assertEqual(self.spent(), 1.1)
        self.assertEqual(self.settings()["sandbox"]["network"]["allowedDomains"], [])

    def test_replay_refuses_a_task_diff_that_touches_the_agent(self):
        g = ["git", "-c", "user.name=t", "-c", "user.email=t@example.invalid", "-c", "commit.gpgsign=false"]
        sh("git", "checkout", "-q", "-b", "touch", self.head, cwd=self.root)
        (self.root / run.AGENT_PATH).write_text("agent changed by the task\n")
        sh(*g, "commit", "-q", "-am", "touch agent", cwd=self.root)
        self.add_case("touch", head=sh("git", "rev-parse", "HEAD", cwd=self.root))
        self.assertEqual(self.go(mode="replay", fake="subagent", case="touch"), 1)
        meta = self.meta("touch-1")
        self.assertEqual(meta["status"], "infra_error")
        self.assertIn("agent file", meta["error"])
        self.assertFalse(self.log.exists())
        self.assertEqual(self.spent(), 0.0)

    def test_the_total_cap_refuses_the_run_before_the_cli_starts(self):
        run.reserve(self.out, "run", "earlier", 3, 25)
        run.settle(self.out, "run", "earlier", 3, 23.0)
        with self.assertRaises(SystemExit) as ctx:
            self.go()
        self.assertEqual(ctx.exception.code, 2)
        self.assertFalse(self.log.exists())
        self.assertEqual(self.spent(), 23.0)


if __name__ == "__main__":
    unittest.main()

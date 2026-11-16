import importlib.util
import json
import tempfile
import unittest
from unittest import mock
from pathlib import Path

HERE = Path(__file__).resolve().parent


def load(name):
    spec = importlib.util.spec_from_file_location(name, HERE / f"{name}.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


run, grade = load("run"), load("grade")


def write_lines(path, lines):
    Path(path).write_text("\n".join(lines) + "\n")


def result(subtype="success", is_error=False, text="report"):
    return json.dumps({"type": "result", "subtype": subtype, "is_error": is_error, "result": text,
                       "total_cost_usd": 0.5, "num_turns": 3, "duration_ms": 10,
                       "modelUsage": {"m1": {}}, "permission_denials": [1]})


INIT = json.dumps({"type": "system", "subtype": "init", "claude_code_version": "9.9"})


class Base(unittest.TestCase):
    def setUp(self):
        self._t = tempfile.TemporaryDirectory()
        self.addCleanup(self._t.cleanup)
        self.out = Path(self._t.name)


class LedgerBudget(Base):
    def test_sum_missing_and_entries(self):
        self.assertEqual(run.ledger_spent(self.out), 0)
        write_lines(self.out / "ledger.jsonl", ['{"kind":"run","cost_usd":1.5}', '{"kind":"judge","cost_usd":0.25}'])
        self.assertEqual(run.ledger_spent(self.out), 1.75)
        self.assertEqual(grade.ledger_spent(self.out), 1.75)

    def test_budget_refuses_and_allows(self):
        write_lines(self.out / "ledger.jsonl", ['{"kind":"run","cost_usd":23}'])
        with self.assertRaises(SystemExit) as cm:
            run.check_budget(self.out, 3, 25)
        self.assertEqual(cm.exception.code, 2)
        run.check_budget(self.out, 2, 25)
        with self.assertRaises(SystemExit):
            grade.check_budget(self.out, 3, 25)


class ParseResult(Base):
    def parse(self, lines):
        p = self.out / "stdout.jsonl"
        write_lines(p, lines)
        return run.parse_result(p)

    def test_success(self):
        r = self.parse([INIT, result()])
        self.assertEqual((r["status"], r["cost_usd"], r["models"], r["permission_denials"], r["cli_version"]),
                         ("ok", 0.5, ["m1"], 1, "9.9"))

    def test_budget_stop(self):
        r = self.parse([INIT, result("error_max_budget_usd", True, "")])
        self.assertEqual(r["status"], "budget_stop")

    def test_no_result(self):
        r = self.parse([INIT])
        self.assertEqual(r["status"], "infra_error")
        self.assertTrue(r["error"])

    def test_garbage_lines(self):
        r = self.parse(["not json", INIT, "{broken", "[1,2]", result(), "tail garbage"])
        self.assertEqual(r["status"], "ok")

    def test_several_result_lines(self):
        def line(subtype, text, cost, is_error=False):
            return json.dumps({"type": "result", "subtype": subtype, "is_error": is_error, "result": text,
                               "total_cost_usd": cost, "num_turns": 1, "modelUsage": {"m": {}}})
        path = self.out / "s.jsonl"
        # a budget stop in any turn decides, also when a later turn ends with an empty success
        path.write_text("\n".join([line("success", "still working", 0.2), line("error_max_budget_usd", None, 0.32, True),
                                   line("success", "", 0.32)]))
        info = run.parse_result(path)
        self.assertEqual(info["status"], "budget_stop")
        self.assertEqual(info["cost_usd"], 0.32)
        # without a stop: the last non-empty text and the highest cost
        path.write_text("\n".join([line("success", "agent started", 0.2), line("success", "gotowe", 0.9),
                                   line("success", "", 0.9)]))
        info = run.parse_result(path)
        self.assertEqual((info["status"], info["text"], info["cost_usd"]), ("ok", "gotowe", 0.9))

    def test_empty_text_is_error(self):
        self.assertEqual(self.parse([result(text="  ")])["status"], "infra_error")


class QuoteFound(unittest.TestCase):
    def test_quote(self):
        report = "The  cart\n\ttotal is   wrong here"
        self.assertTrue(grade.quote_found("cart total is wrong", report))
        self.assertFalse(grade.quote_found("cart total is right", report))


def make_run(out, run_id, case, kind, findings, status="ok"):
    d = Path(out) / "runs" / run_id
    d.mkdir(parents=True)
    (d / "meta.json").write_text(json.dumps({"case": case, "run": run_id, "kind": kind, "status": status,
                                              "error": None if status == "ok" else "boom",
                                              "cost_usd": 1.0, "wall_s": 5}))
    (d / "report.md").write_text("report")
    items = [{"n": i + 1, "severity": s, "paths": ["a.php"], "title": f"t{i}", "claim": "c", "quote": "q",
              "quote_found": False} for i, s in enumerate(findings)]
    (d / "findings.json").write_text(json.dumps({"verdict": "CHANGES_REQUIRED", "findings": items}))
    return d


class Table(Base):
    def test_keeps_existing_labels(self):
        make_run(self.out, "d-1", "d", "defect", ["IMPORTANT"])
        grade.main(["table", "--out", str(self.out)])
        labels = json.loads((self.out / "labels.json").read_text())
        labels["runs"]["d-1"]["findings"][0]["label"] = "wada"
        labels["runs"]["d-1"]["gold_detected"] = True
        (self.out / "labels.json").write_text(json.dumps(labels))
        make_run(self.out, "c-1", "c", "clean", ["BLOCKER", "SUGGESTION"])
        grade.main(["table", "--out", str(self.out)])
        again = json.loads((self.out / "labels.json").read_text())
        self.assertEqual(again["runs"]["d-1"]["findings"][0]["label"], "wada")
        self.assertTrue(again["runs"]["d-1"]["gold_detected"])
        self.assertEqual(len(again["runs"]["c-1"]["findings"]), 2)
        self.assertIn("c-1", (self.out / "labels.md").read_text())

    def test_refreshes_judge_and_keeps_a_corrected_verdict(self):
        d = make_run(self.out, "d-1", "d", "defect", ["IMPORTANT"])
        grade.main(["table", "--out", str(self.out)])
        labels = json.loads((self.out / "labels.json").read_text())
        self.assertIsNone(labels["runs"]["d-1"]["findings"][0]["judge"])
        labels["runs"]["d-1"]["verdict"] = "PASS"  # corrected by hand
        labels["runs"]["d-1"]["findings"][0]["label"] = "trafne"
        (self.out / "labels.json").write_text(json.dumps(labels))
        (d / "judge.json").write_text(json.dumps({"model": "m", "results": [{"n": 1, "match": "NO"}]}))
        grade.main(["table", "--out", str(self.out)])
        again = json.loads((self.out / "labels.json").read_text())["runs"]["d-1"]
        self.assertEqual(again["findings"][0]["judge"], "NO")
        self.assertEqual(again["findings"][0]["label"], "trafne")
        self.assertEqual(again["verdict"], "PASS")


class Summary(Base):
    def fixture(self, label):
        make_run(self.out, "d-1", "d", "defect", ["IMPORTANT", "BLOCKER"])
        make_run(self.out, "c-1", "c", "clean", ["IMPORTANT"])
        make_run(self.out, "c-2", "c", "clean", ["IMPORTANT"])
        make_run(self.out, "c-3", "c", "clean", [], status="infra_error")
        grade.main(["table", "--out", str(self.out)])
        labels = json.loads((self.out / "labels.json").read_text())
        r = labels["runs"]
        r["d-1"]["gold_detected"] = True
        r["d-1"]["findings"][0].update(judge="YES", label=label, by="owner")
        r["d-1"]["findings"][1].update(judge="NO", label="trafne", by="owner")
        r["c-1"]["findings"][0]["label"] = "falszywe"
        (self.out / "labels.json").write_text(json.dumps(labels))

    def test_counts(self):
        self.fixture("wada")
        self.assertEqual(grade.main(["summary", "--out", str(self.out)]), 0)
        text = (self.out / "summary.md").read_text()
        self.assertIn("gold_detected true / defect runs ok: 1 / 1", text)
        self.assertIn("zero falszywe among IMPORTANT+BLOCKER / clean runs ok: 0 / 2", text)
        self.assertIn("nierozstrzygnięte (unlabelled IMPORTANT+BLOCKER): 1", text)
        self.assertIn("1 total, over 3 ok runs", text)
        self.assertIn("agree 2, disagree 0", text)
        self.assertIn("c-3: infra_error boom", text)

    def test_partial_and_unknown_are_counted_apart(self):
        make_run(self.out, "d-1", "d", "defect", ["IMPORTANT", "IMPORTANT"])
        make_run(self.out, "d-2", "d", "defect", ["IMPORTANT"])
        grade.main(["table", "--out", str(self.out)])
        labels = json.loads((self.out / "labels.json").read_text())
        labels["runs"]["d-1"]["gold_detected"] = "partial"
        labels["runs"]["d-1"]["findings"][0].update(label="czesciowe", by="owner")
        labels["runs"]["d-1"]["findings"][1].update(label="nieznane")
        labels["runs"]["d-2"]["gold_detected"] = False
        (self.out / "labels.json").write_text(json.dumps(labels))
        grade.main(["summary", "--out", str(self.out)])
        text = (self.out / "summary.md").read_text()
        self.assertIn("gold_detected true / defect runs ok: 0 / 2 (partial: 1)", text)
        self.assertIn("- d: częściowo, nie", text)
        self.assertIn("false alarms (IMPORTANT+BLOCKER falszywe): 0 total", text)
        self.assertIn("IMPORTANT+BLOCKER findings: 3, labelled by the owner: 1", text)

    def test_invalid_gold_detected(self):
        make_run(self.out, "d-1", "d", "defect", ["IMPORTANT"])
        grade.main(["table", "--out", str(self.out)])
        labels = json.loads((self.out / "labels.json").read_text())
        labels["runs"]["d-1"]["gold_detected"] = "maybe"
        (self.out / "labels.json").write_text(json.dumps(labels))
        with self.assertRaises(SystemExit) as ctx:
            grade.main(["summary", "--out", str(self.out)])
        self.assertEqual(ctx.exception.code, 2)

    def test_invalid_label(self):
        self.fixture("bogus")
        with self.assertRaises(SystemExit) as cm:
            grade.main(["summary", "--out", str(self.out)])
        self.assertEqual(cm.exception.code, 2)


ORDER = "Review  the\ncommit at /x."


def agent_use(id_="toolu_1", parent=None, **over):
    inp = {"description": "d", "subagent_type": "code-reviewer", "run_in_background": False, "prompt": ORDER}
    inp.update(over)
    return json.dumps({"type": "assistant", "parent_tool_use_id": parent, "message": {"content": [
        {"type": "tool_use", "id": id_, "name": "Agent", "input": inp}]}})


def tool_use(name, id_="toolu_9", parent=None):
    return json.dumps({"type": "assistant", "parent_tool_use_id": parent, "message": {"content": [
        {"type": "tool_use", "id": id_, "name": name, "input": {}}]}})


def agent_result(id_="toolu_1", report="the report", is_error=False, status="completed", text="x"):
    tur = {"status": status, "resolvedModel": "claude-opus-5-5", "totalTokens": 10, "totalToolUseCount": 4}
    if report is not None:
        tur["handbackReport"] = {"text": report}
    return json.dumps({"type": "user", "parent_tool_use_id": None, "message": {"content": [
        {"type": "tool_result", "tool_use_id": id_, "is_error": is_error,
         "content": [{"type": "text", "text": text}]}]}, "tool_use_result": tur})


class ParseSubagent(Base):
    def parse(self, lines, order=ORDER):
        p = self.out / "stdout.jsonl"
        write_lines(p, ["junk"] + lines)
        return run.parse_subagent(p, order)

    def test_valid(self):
        r = self.parse([agent_use(), agent_result()])
        self.assertEqual((r["ok"], r["report"], r["subagent_model"], r["subagent_tokens"],
                          r["subagent_tool_uses"]), (True, "the report", "claude-opus-5-5", 10, 4))

    def test_fallback_to_content(self):
        r = self.parse([agent_use(), agent_result(report=None, text="from content")])
        self.assertTrue(r["ok"])
        self.assertEqual(r["report"], "from content")

    def test_pointer_only_is_not_ok(self):
        r = self.parse([agent_use(), agent_result(report=None, text="The report was delivered to you")])
        self.assertFalse(r["ok"])

    def test_zero_and_two_calls(self):
        self.assertIn("found 0", self.parse([])["error"])
        r = self.parse([agent_use(), agent_use("toolu_2"), agent_result()])
        self.assertFalse(r["ok"])
        self.assertIn("found 2", r["error"])

    def test_nested_call_not_counted(self):
        self.assertTrue(self.parse([agent_use(), agent_use("toolu_2", parent="toolu_1"),
                                    tool_use("Bash", parent="toolu_1"), agent_result()])["ok"])

    def test_other_parent_tool(self):
        r = self.parse([agent_use(), tool_use("Bash"), agent_result()])
        self.assertFalse(r["ok"])
        self.assertIn("Bash", r["error"])

    def test_wrong_type_and_background(self):
        self.assertFalse(self.parse([agent_use(subagent_type="general-purpose"), agent_result()])["ok"])
        self.assertFalse(self.parse([agent_use(run_in_background=True), agent_result()])["ok"])

    def test_order_altered_or_whitespace_only(self):
        r = self.parse([agent_use(prompt=ORDER + " extra"), agent_result()])
        self.assertFalse(r["ok"])
        self.assertIn("altered", r["error"])
        self.assertTrue(self.parse([agent_use(prompt="Review the commit at /x.\n"), agent_result()])["ok"])

    def test_result_problems(self):
        self.assertFalse(self.parse([agent_use()])["ok"])
        self.assertFalse(self.parse([agent_use(), agent_result(is_error=True)])["ok"])
        self.assertFalse(self.parse([agent_use(), agent_result(status="failed")])["ok"])


class ReplayRun(Base):
    def go(self, lines, with_replay=True):
        cases = self.out / "cases"
        (cases / "c1").mkdir(parents=True)
        (cases / "c1" / "case.json").write_text(json.dumps({"kind": "defect"}))
        if with_replay:
            (cases / "c1" / "replay").mkdir()
            (cases / "c1" / "replay" / "replay.json").write_text(json.dumps({"task": "T", "pr": 1}))
        out = self.out / "out"

        def fake_build(run_dir, case_dir, replay, agent_rev):
            run_dir.mkdir(parents=True, exist_ok=True)
            (run_dir / "prompt.md").write_text(ORDER)
            return {"base": "b", "head": "h", "agent_commit": "a", "agent_blob": "x"}

        def fake_claude(run_dir, args, prompt=None, extra=None):
            write_lines(run_dir / "stdout.jsonl", lines)
            return False

        with mock.patch.object(run, "CASES", cases), \
                mock.patch.object(run, "build_replay_checkout", fake_build), \
                mock.patch.object(run, "run_claude", fake_claude):
            code = run.main(["--out", str(out), "--case", "c1", "--mode", "replay"])
        return code, out / "runs" / "c1-1"

    def ledger_rows(self):
        return (self.out / "out" / "ledger.jsonl").read_text().count("\n")

    def test_zero_agent_calls(self):
        code, d = self.go([INIT, result()])
        self.assertEqual(code, 1)
        meta = json.loads((d / "meta.json").read_text())
        self.assertEqual((meta["status"], meta["mode"]), ("infra_error", "replay"))
        self.assertFalse((d / "report.md").exists())
        self.assertEqual(self.ledger_rows(), 1)

    def test_two_agent_calls(self):
        code, d = self.go([INIT, agent_use(), agent_use("toolu_2"), agent_result(), result()])
        self.assertEqual(json.loads((d / "meta.json").read_text())["status"], "infra_error")
        self.assertFalse((d / "report.md").exists())
        self.assertEqual(self.ledger_rows(), 1)

    def test_valid_call(self):
        code, d = self.go([INIT, agent_use(), agent_result(), result(text="gotowe")])
        self.assertEqual(code, 0)
        self.assertEqual((d / "report.md").read_text(), "the report")
        meta = json.loads((d / "meta.json").read_text())
        self.assertEqual((meta["status"], meta["subagent_tokens"], meta["task"]), ("ok", 10, "T"))

    def test_a_system_line_with_a_string_message_is_skipped(self):
        denied = json.dumps({"type": "system", "subtype": "permission_denied", "message": "Permission was denied."})
        code, d = self.go([INIT, agent_use(), denied, agent_result(), result(text="gotowe")])
        self.assertEqual(code, 0)
        self.assertEqual((d / "report.md").read_text(), "the report")

    def test_a_failure_while_reading_the_report_keeps_the_real_cost(self):
        def boom(path, order):
            raise AttributeError("broken reader")
        with mock.patch.object(run, "parse_subagent", boom):
            code, d = self.go([INIT, agent_use(), agent_result(), result(text="gotowe")])
        self.assertEqual(code, 1)
        meta = json.loads((d / "meta.json").read_text())
        self.assertEqual(meta["status"], "infra_error")
        self.assertIn("broken reader", meta["error"])
        self.assertFalse((d / "report.md").exists())
        row = json.loads((self.out / "out" / "ledger.jsonl").read_text().splitlines()[0])
        self.assertEqual(row["cost_usd"], meta["cost_usd"])
        self.assertIsNotNone(meta["cost_usd"])

    def test_case_without_replay_exits_2(self):
        code, d = self.go([], with_replay=False)
        self.assertEqual(code, 2)
        self.assertFalse(d.exists())



class ReplayInputs(unittest.TestCase):
    """The committed replay inputs of every case: the order is the original one plus the recorded changes."""

    def test_orders_match_their_recorded_difference(self):
        import difflib
        cases = sorted((Path(__file__).resolve().parent / "cases").glob("*/replay"))
        self.assertTrue(cases)
        for d in cases:
            with self.subTest(case=d.parent.name):
                replay = json.loads((d / "replay.json").read_text())
                orig, order = (d / "order.orig.md").read_text(), (d / "order.md").read_text()
                diff = "".join(difflib.unified_diff(orig.splitlines(True), order.splitlines(True),
                                                    "order.orig.md", "order.md", n=0))
                self.assertEqual((d / "order.diff").read_text(), diff)
                for sha in (replay["review_sha"], replay["base_sha"]):
                    self.assertRegex(sha, r"^[0-9a-f]{40}$")
                    self.assertIn(sha, orig)
                    self.assertIn(sha, order)
                self.assertIn("{{CHECKOUT}}", order)
                self.assertIn("{{INPUT_DIR}}/tdd-evidence.jsonl", order)
                for text in (orig, order, (d / "replay.json").read_text()):
                    self.assertNotIn("/Users/", text)
                self.assertEqual(replay["branch"], "task/" + replay["task"])
                if json.loads((d.parent / "case.json").read_text())["kind"] == "defect":
                    self.assertTrue(replay.get("gold_in_reviewed_diff"))


if __name__ == "__main__":
    unittest.main()

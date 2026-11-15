import importlib.util
import json
import tempfile
import unittest
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


if __name__ == "__main__":
    unittest.main()

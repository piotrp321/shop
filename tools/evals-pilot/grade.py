#!/usr/bin/env python3
"""Eval pilot grading: extract findings, judge them against gold, build label tables and a summary."""
import argparse
import json
import re
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

CASES = Path(__file__).resolve().parent / "cases"
# wada: the gold defect; czesciowe: only a part of it; trafne: another real problem; falszywe: false alarm;
# drobne: minor; nieznane: cannot be decided from the case (counted apart, never as a hit or a false alarm).
LABELS = ("wada", "czesciowe", "trafne", "falszywe", "drobne", "nieznane")
GOLD = {True: "tak", False: "nie", "partial": "częściowo", None: "brak etykiety"}
HIGH = ("IMPORTANT", "BLOCKER")

EXTRACT_PROMPT = r'''Poniżej jest raport code reviewera. Przepisz go do struktury, niczego nie oceniając i niczego nie dodając.

- `verdict`: werdykt raportu (PASS albo CHANGES_REQUIRED; UNKNOWN, gdy raport go nie podaje).
- `findings`: każde zgłoszenie (ustalenie z wagą), które raport faktycznie stawia, w kolejności raportu. Nie wpisuj punktów „sprawdzone, bez uwag”, wyników kontroli bez zgłoszenia ani pozycji listy kontrolnej.
  - `severity`: waga podana w raporcie (BLOCKER, IMPORTANT, SUGGESTION; Critical = BLOCKER, Minor = SUGGESTION; UNKNOWN, gdy brak).
  - `paths`: ścieżki plików wskazane w tym zgłoszeniu (bez numerów linii).
  - `title`: tytuł zgłoszenia z raportu.
  - `claim`: jedno zdanie: co według raportu jest nie tak i jaki ma skutek.
  - `quote`: dosłowny fragment raportu (do 300 znaków), z którego to zgłoszenie pochodzi.

Raport:
'''
FINDINGS_SCHEMA = r'''{"type":"object","additionalProperties":false,"required":["verdict","findings"],
 "properties":{
  "verdict":{"type":"string","enum":["PASS","CHANGES_REQUIRED","UNKNOWN"]},
  "findings":{"type":"array","items":{"type":"object","additionalProperties":false,
   "required":["n","severity","paths","title","claim","quote"],
   "properties":{
    "n":{"type":"integer"},
    "severity":{"type":"string","enum":["BLOCKER","IMPORTANT","SUGGESTION","UNKNOWN"]},
    "paths":{"type":"array","items":{"type":"string"}},
    "title":{"type":"string"},
    "claim":{"type":"string"},
    "quote":{"type":"string"}}}}}}'''
JUDGE_PROMPT = r'''Oceniasz, czy zgłoszenie z code review opisuje TĘ SAMĄ wadę co wada wzorcowa. Ta sama wada = ten sam mechanizm w tym samym kodzie i ten sam skutek; inna wada w tym samym pliku to NO. Ogólnik bez mechanizmu to NO. Gdy nie da się rozstrzygnąć, odpowiedz UNKNOWN.

Wada wzorcowa:
{{GOLD}}

Zgłoszenie:
{{FINDING}}
'''
JUDGE_SCHEMA = r'''{"type":"object","additionalProperties":false,"required":["reasoning","match"],
 "properties":{"reasoning":{"type":"string"},"match":{"type":"string","enum":["YES","NO","UNKNOWN"]}}}'''


def ledger_spent(out):
    path = Path(out) / "ledger.jsonl"
    if not path.exists():
        return 0.0
    return sum(float(json.loads(l).get("cost_usd") or 0) for l in path.read_text().splitlines() if l.strip())


def ledger_rows(out):
    path = Path(out) / "ledger.jsonl"
    return [json.loads(l) for l in path.read_text().splitlines() if l.strip()] if path.exists() else []


def check_budget(out, cap, total_cap):
    spent = ledger_spent(out)
    if spent + cap > total_cap:
        print(f"budget: spent {spent:.2f} + cap {cap:.2f} exceeds total {total_cap:.2f}", file=sys.stderr)
        raise SystemExit(2)


def call_model(prompt, schema, model, out, kind, id_, cap_usd=0.25, claude_bin="claude", total_cap=25):
    check_budget(out, cap_usd, total_cap)
    with tempfile.TemporaryDirectory() as tmp:
        proc = subprocess.run(
            [claude_bin, "-p", prompt, "--model", model, "--output-format", "json", "--json-schema", schema,
             "--strict-mcp-config", "--setting-sources", "", "--tools", "", "--max-budget-usd", str(cap_usd)],
            cwd=tmp, stdin=subprocess.DEVNULL, capture_output=True, text=True)
    try:
        data = json.loads(proc.stdout)
        cost = float(data["total_cost_usd"])
    except (ValueError, KeyError, TypeError):
        data, cost = {}, cap_usd  # unknown cost: charge the whole cap so the total limit still holds
    row = {"kind": kind, "id": id_, "cost_usd": cost, "at": datetime.now(timezone.utc).isoformat()}
    with open(Path(out) / "ledger.jsonl", "a") as f:
        f.write(json.dumps(row) + "\n")
    return data.get("structured_output"), cost


def run_dirs(out):
    for d in sorted((Path(out) / "runs").glob("*")):
        if (d / "meta.json").exists():
            yield d, json.loads((d / "meta.json").read_text())


def read_json(path):
    return json.loads(Path(path).read_text())


def write_json(path, data):
    Path(path).write_text(json.dumps(data, indent=2, ensure_ascii=False))


def squash(text):
    return re.sub(r"\s+", " ", text).strip()


def quote_found(quote, report):
    q = squash(quote)
    return bool(q) and q in squash(report)


def cmd_extract(args):
    failed = 0
    for d, meta in run_dirs(args.out):
        if meta.get("status") != "ok" or (d / "findings.json").exists():
            continue
        report = (d / "report.md").read_text()
        data, cost = call_model(EXTRACT_PROMPT + report, FINDINGS_SCHEMA, args.model, args.out, "extract",
                                meta["run"], claude_bin=args.claude_bin, total_cap=args.total_cap)
        if not data:
            print(f"extract failed: {meta['run']}", file=sys.stderr)
            failed += 1
            continue
        for f in data["findings"]:
            f["quote_found"] = quote_found(f.get("quote", ""), report)
        write_json(d / "findings.json", {"verdict": data["verdict"], "findings": data["findings"],
                                         "model": args.model, "cost_usd": cost})
    return 1 if failed else 0


def cmd_judge(args):
    for d, meta in run_dirs(args.out):
        if meta.get("kind") != "defect" or not (d / "findings.json").exists() or (d / "judge.json").exists():
            continue
        gold = (CASES / meta["case"] / "gold.md").read_text()
        results = []
        for f in read_json(d / "findings.json")["findings"]:
            shown = {k: f.get(k) for k in ("severity", "paths", "title", "claim", "quote")}
            prompt = JUDGE_PROMPT.replace("{{GOLD}}", gold).replace("{{FINDING}}", json.dumps(shown, ensure_ascii=False))
            data, cost = call_model(prompt, JUDGE_SCHEMA, args.model, args.out, "judge", f"{meta['run']}#{f['n']}",
                                    claude_bin=args.claude_bin, total_cap=args.total_cap)
            data = data or {"match": "UNKNOWN", "reasoning": "judge call failed"}
            results.append({"n": f["n"], "match": data["match"], "reasoning": data["reasoning"], "cost_usd": cost})
        write_json(d / "judge.json", {"model": args.model, "results": results})
    return 0


def load_labels(out):
    path = Path(out) / "labels.json"
    labels = read_json(path) if path.exists() else {"approved_by_owner": False, "runs": {}}
    for run_id, run in labels["runs"].items():
        for f in run["findings"]:
            if f["label"] is not None and f["label"] not in LABELS:
                print(f"invalid label {f['label']!r} in {run_id} finding {f['n']}", file=sys.stderr)
                raise SystemExit(2)
        if run["gold_detected"] not in GOLD:
            print(f"invalid gold_detected {run['gold_detected']!r} in {run_id}", file=sys.stderr)
            raise SystemExit(2)
    return labels


def cmd_table(args):
    labels = load_labels(args.out)
    md = ["# Labels", ""]
    for d, meta in run_dirs(args.out):
        run_id = meta["run"]
        fpath = d / "findings.json"
        found = read_json(fpath) if fpath.exists() else None
        judge = {r["n"]: r["match"] for r in read_json(d / "judge.json")["results"]} if (d / "judge.json").exists() else {}
        entry = labels["runs"].setdefault(run_id, {"case": meta["case"], "kind": meta["kind"], "verdict": None,
                                                    "gold_detected": None, "note": "", "findings": []})
        if found:
            if entry["verdict"] is None:  # a verdict corrected by hand in labels.json is kept
                entry["verdict"] = found["verdict"]
            have = {f["n"] for f in entry["findings"]}
            for f in found["findings"]:
                if f["n"] not in have:
                    entry["findings"].append({"n": f["n"], "severity": f["severity"], "title": f["title"],
                                              "judge": None, "label": None, "note": ""})
            for f in entry["findings"]:  # the judge answer is a hint, refreshed from judge.json; labels stay
                f["judge"] = judge.get(f["n"], f.get("judge"))
        md += [f"## {run_id}: case {meta['case']}, kind {meta['kind']}, verdict {entry['verdict']}",
               f"cost {meta.get('cost_usd')} USD, wall {meta.get('wall_s')} s, report {d / 'report.md'}", ""]
        if found and found["findings"]:
            md += ["| n | severity | paths | title | claim | judge | label | quote_found |", "|---|---|---|---|---|---|---|---|"]
            lab = {f["n"]: f for f in entry["findings"]}
            for f in found["findings"]:
                cell = lambda s: str(s).replace("|", "/").replace("\n", " ")
                md.append("| " + " | ".join(cell(x) for x in (
                    f["n"], f["severity"], ", ".join(f["paths"]), f["title"], f["claim"],
                    lab[f["n"]]["judge"], lab[f["n"]]["label"], f["quote_found"])) + " |")
            md.append("")
    write_json(Path(args.out) / "labels.json", labels)
    (Path(args.out) / "labels.md").write_text("\n".join(md) + "\n")
    return 0


def count_labels(findings):
    counts = dict.fromkeys(LABELS + ("bez etykiety",), 0)
    for f in findings:
        if f["severity"] in HIGH:
            counts[f["label"] or "bez etykiety"] += 1
    return counts


def cmd_summary(args):
    labels = load_labels(args.out)
    L = ["# Summary", "", "Labels approved by owner: " + ("tak" if labels["approved_by_owner"] else "NIE (wstepne)"), "",
         "| run | kind | status | verdict | gold_detected | " + " | ".join(LABELS) + " | bez etykiety | cost_usd | wall_s |",
         "|" + "---|" * (8 + len(LABELS))]
    d_ok = d_hit = d_part = c_ok = c_zero = c_open = alarms = agree = disagree = unknown = by_owner = high = 0
    bad, per_case = [], {}
    for d, meta in run_dirs(args.out):
        e = labels["runs"].get(meta["run"], {"verdict": None, "gold_detected": None, "findings": []})
        c = count_labels(e["findings"])
        gold = "—" if meta["kind"] == "clean" else GOLD[e["gold_detected"]]
        L.append("| " + " | ".join(str(x) for x in (meta["run"], meta["kind"], meta["status"], e["verdict"], gold,
                 *c.values(), meta.get("cost_usd"), meta.get("wall_s"))) + " |")
        if meta["status"] != "ok":
            bad.append(f"- {meta['run']}: {meta['status']} {meta.get('error')}")
            continue
        alarms += c["falszywe"]
        marked = [f for f in e["findings"] if f["severity"] in HIGH]
        high += len(marked)
        by_owner += sum(1 for f in marked if f.get("by") == "owner")
        if meta["kind"] == "defect":
            d_ok += 1
            d_hit += e["gold_detected"] is True
            d_part += e["gold_detected"] == "partial"
            per_case.setdefault(meta["case"], []).append(GOLD[e["gold_detected"]])
            for f in e["findings"]:
                if f["judge"] is not None and f["label"] is not None and f.get("by") == "owner":
                    if f["judge"] == "UNKNOWN":
                        unknown += 1
                    elif (f["judge"] == "YES") == (f["label"] == "wada"):
                        agree += 1
                    else:
                        disagree += 1
        else:
            c_ok += 1
            if c["falszywe"] == 0 and c["bez etykiety"] == 0:
                c_zero += 1
            elif c["falszywe"] == 0:
                c_open += 1
    spent = {}
    for row in ledger_rows(args.out):
        spent[row["kind"]] = spent.get(row["kind"], 0) + float(row.get("cost_usd") or 0)
    ok_runs = d_ok + c_ok
    L += ["", f"- defect runs with gold_detected true / defect runs ok: {d_hit} / {d_ok} (partial: {d_part})"]
    L += [f"  - {case}: " + ", ".join(v) for case, v in sorted(per_case.items())]
    L += [f"- IMPORTANT+BLOCKER findings: {high}, labelled by the owner: {by_owner}",
          f"- clean runs with zero falszywe among IMPORTANT+BLOCKER / clean runs ok: {c_zero} / {c_ok}",
          f"- clean runs nierozstrzygnięte (unlabelled IMPORTANT+BLOCKER): {c_open}",
          f"- false alarms (IMPORTANT+BLOCKER falszywe): {alarms} total, over {ok_runs} ok runs",
          f"- judge vs owner on defect runs: agree {agree}, disagree {disagree}, judge UNKNOWN {unknown}",
          "- cost USD by kind: " + (", ".join(f"{k} {v:.2f}" for k, v in sorted(spent.items())) or "none")
          + f"; total {sum(spent.values()):.2f}", "", "Runs not ok:"] + (bad or ["- none"])
    text = "\n".join(L) + "\n"
    (Path(args.out) / "summary.md").write_text(text)
    print(text)
    return 0


def main(argv=None):
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)
    for name, fn, model in (("extract", cmd_extract, "sonnet"), ("judge", cmd_judge, "opus"),
                            ("table", cmd_table, None), ("summary", cmd_summary, None)):
        p = sub.add_parser(name)
        p.add_argument("--out", required=True)
        p.add_argument("--total-cap", type=float, default=25)
        p.add_argument("--claude-bin", default="claude")
        if model:
            p.add_argument("--model", default=model)
        p.set_defaults(fn=fn)
    args = ap.parse_args(argv)
    return args.fn(args)


if __name__ == "__main__":
    sys.exit(main())

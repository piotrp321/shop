#!/usr/bin/env python3
"""Eval pilot: run the code-reviewer agent on prepared cases and record results."""
import argparse
import json
import os
import signal
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CASES = Path(__file__).resolve().parent / "cases"
AGENT_PATH = ".claude/agents/code-reviewer.md"
GIT_ID = ["-c", "user.name=evals", "-c", "user.email=evals@example.invalid"]
PARENT_PROMPT = """Wywołaj dokładnie raz narzędzie Agent z `subagent_type` równym `code-reviewer`, z `run_in_background` równym `false` (agent ma działać na pierwszym planie, czekaj na jego wynik), z parametrem `prompt` równym dokładnie tekstowi między znacznikami <order> i </order> (bez zmian, skrótów i dopisków). Nie używaj żadnych innych narzędzi i nie czytaj plików. Gdy agent zwróci wynik, odpowiedz jednym słowem: gotowe.

<order>
{order}
</order>"""


def ledger_spent(out):
    path = Path(out) / "ledger.jsonl"
    if not path.exists():
        return 0.0
    total = 0.0
    for line in path.read_text().splitlines():
        if line.strip():
            total += float(json.loads(line).get("cost_usd") or 0)
    return total


def ledger_add(out, kind, id_, cost):
    Path(out).mkdir(parents=True, exist_ok=True)
    row = {"kind": kind, "id": id_, "cost_usd": cost, "at": datetime.now(timezone.utc).isoformat()}
    with open(Path(out) / "ledger.jsonl", "a") as f:
        f.write(json.dumps(row) + "\n")


def check_budget(out, cap, total_cap):
    spent = ledger_spent(out)
    if spent + cap > total_cap:
        print(f"budget: spent {spent:.2f} + cap {cap:.2f} exceeds total {total_cap:.2f}", file=sys.stderr)
        raise SystemExit(2)


def git(*args, cwd=None):
    return subprocess.run(["git", *args], cwd=cwd, check=True, capture_output=True, text=True).stdout.strip()


def parse_result(path):
    init = result = None
    results = []
    try:
        lines = Path(path).read_text(errors="replace").splitlines()
    except OSError:
        lines = []
    for line in lines:
        try:
            obj = json.loads(line)
        except ValueError:
            continue
        if not isinstance(obj, dict):
            continue
        if obj.get("type") == "system" and obj.get("subtype") == "init" and init is None:
            init = obj
        if obj.get("type") == "result":
            results.append(obj)
    info = {"status": "infra_error", "error": "no result line", "text": "", "cost_usd": None,
            "duration_ms": None, "num_turns": None, "model_usage": {}, "models": [],
            "permission_denials": 0, "cli_version": (init or {}).get("claude_code_version")}
    if not results:
        return info
    # A session can print several result lines (one per turn). The budget stop of any turn decides the status, the
    # cost is the highest total seen and the text is the last non-empty one.
    stopped = [r for r in results if "max_budget" in str(r.get("subtype"))]
    spoken = [r for r in results if str(r.get("result") or "").strip()]
    result = dict(stopped[0] if stopped else (spoken[-1] if spoken else results[-1]))
    costs = [r.get("total_cost_usd") for r in results if isinstance(r.get("total_cost_usd"), (int, float))]
    if costs:
        result["total_cost_usd"] = max(costs)
    usage = result.get("modelUsage") or {}
    text = result.get("result") or ""
    sub = str(result.get("subtype"))
    info.update(cost_usd=result.get("total_cost_usd"), duration_ms=result.get("duration_ms"),
                num_turns=result.get("num_turns"), model_usage=usage, models=list(usage),
                permission_denials=len(result.get("permission_denials") or []), text=text)
    if "max_budget" in sub:
        info.update(status="budget_stop", error=sub)
    elif sub == "success" and not result.get("is_error") and text.strip():
        info.update(status="ok", error=None)
    else:
        info.update(error=f"subtype={sub} is_error={result.get('is_error')} empty={not text.strip()}")
    return info


def next_run_dir(out, case):
    n = 1
    while (Path(out) / "runs" / f"{case}-{n}").exists():
        n += 1
    return n, Path(out) / "runs" / f"{case}-{n}"


def build_checkout(run_dir, case_dir, case, agent_rev):
    """Rebuild the case commits on top of the current agent definition. Returns a dict of shas."""
    repo, inputs, state = run_dir / "repo", run_dir / "inputs", run_dir / "state"
    inputs.mkdir(parents=True)
    state.mkdir()
    evidence = case_dir / "tdd-evidence.jsonl"
    if evidence.exists():
        (inputs / evidence.name).write_bytes(evidence.read_bytes())
    agent_commit = git("rev-parse", f"{agent_rev}^{{commit}}", cwd=ROOT)
    agent_blob = git("rev-parse", f"{agent_rev}:{AGENT_PATH}", cwd=ROOT)
    agent_text = subprocess.run(["git", "show", f"{agent_rev}:{AGENT_PATH}"], cwd=ROOT, check=True,
                                capture_output=True).stdout
    common = git("rev-parse", "--path-format=absolute", "--git-common-dir", cwd=ROOT)
    base, head = case["base_sha"], case["head_sha"]
    git("init", "-q", str(repo))
    git("fetch", "-q", "--no-tags", "--depth=1", f"file://{common}", base, head, cwd=repo)
    git("checkout", "-q", "--detach", base, cwd=repo)
    (repo / ".claude" / "agents").mkdir(parents=True, exist_ok=True)
    (repo / AGENT_PATH).write_bytes(agent_text)
    git("add", AGENT_PATH, cwd=repo)
    git(*GIT_ID, "commit", "-q", "-m", "evals: current code-reviewer agent", cwd=repo)
    new_base = git("rev-parse", "HEAD", cwd=repo)
    git("checkout", "-q", "-f", "--detach", head, cwd=repo)
    git("reset", "-q", "--soft", new_base, cwd=repo)
    git("checkout", "-q", new_base, "--", AGENT_PATH, cwd=repo)
    subject = git("log", "-1", "--format=%s", head, cwd=repo)
    git(*GIT_ID, "commit", "-q", "-m", subject, cwd=repo)
    new_head = git("rev-parse", "HEAD", cwd=repo)
    if git("diff", new_base, new_head, cwd=repo) != git("diff", base, head, cwd=repo):
        raise RuntimeError("task diff differs after rebuild")
    prompt = (case_dir / "prompt.md").read_text()
    for key, val in (("{{BASE}}", new_base), ("{{HEAD}}", new_head), ("{{INPUT_DIR}}", str(inputs))):
        prompt = prompt.replace(key, val)
    (run_dir / "prompt.md").write_text(prompt)
    settings = subprocess.run(
        [sys.executable, str(ROOT / "tools/agent-security/render_settings.py"), "--workdir", str(repo),
         "--read-dir", str(inputs), "--state-dir", str(state), "--no-network", "--no-docker"],
        check=True, capture_output=True, text=True).stdout
    (run_dir / "settings.json").write_text(settings)
    return {"base": new_base, "head": new_head, "agent_commit": agent_commit, "agent_blob": agent_blob}


def build_replay_checkout(run_dir, case_dir, replay, agent_rev):
    """Check out the really reviewed commit with the current agent definition hidden from git."""
    repo, inputs, state = run_dir / "repo", run_dir / "inputs", run_dir / "state"
    inputs.mkdir(parents=True)
    state.mkdir()
    evidence = case_dir / "tdd-evidence.jsonl"
    if evidence.exists():
        (inputs / evidence.name).write_bytes(evidence.read_bytes())
    agent_commit = git("rev-parse", f"{agent_rev}^{{commit}}", cwd=ROOT)
    agent_blob = git("rev-parse", f"{agent_rev}:{AGENT_PATH}", cwd=ROOT)
    agent_text = subprocess.run(["git", "show", f"{agent_rev}:{AGENT_PATH}"], cwd=ROOT, check=True,
                                capture_output=True).stdout
    common = git("rev-parse", "--path-format=absolute", "--git-common-dir", cwd=ROOT)
    base, head = replay["base_sha"], replay["review_sha"]
    depth = int(git("rev-list", "--count", f"{base}..{head}", cwd=ROOT)) + 1
    git("init", "-q", str(repo))
    git("fetch", "-q", "--no-tags", f"--depth={depth}", f"file://{common}", head, cwd=repo)
    if subprocess.run(["git", "cat-file", "-e", f"{base}^{{commit}}"], cwd=repo, capture_output=True).returncode:
        git("fetch", "-q", "--no-tags", f"--depth={depth + 50}", f"file://{common}", head, cwd=repo)
        if subprocess.run(["git", "cat-file", "-e", f"{base}^{{commit}}"], cwd=repo,
                          capture_output=True).returncode:
            raise RuntimeError("base commit not reachable in the replay checkout")
    git("checkout", "-q", "-B", replay["branch"], head, cwd=repo)
    git("update-ref", f"refs/remotes/{replay['target_ref']}", base, cwd=repo)
    if git("diff", "--name-only", base, head, "--", AGENT_PATH, cwd=repo):
        raise RuntimeError("task diff touches the agent file")
    (repo / ".claude" / "agents").mkdir(parents=True, exist_ok=True)
    (repo / AGENT_PATH).write_bytes(agent_text)
    tracked = subprocess.run(["git", "ls-files", "--error-unmatch", AGENT_PATH], cwd=repo,
                             capture_output=True).returncode == 0
    if tracked:
        git("update-index", "--skip-worktree", AGENT_PATH, cwd=repo)
    else:
        with open(repo / ".git" / "info" / "exclude", "a") as f:
            f.write(AGENT_PATH + "\n")
    if git("status", "--porcelain", cwd=repo):
        raise RuntimeError("replay checkout is not clean")
    order = (case_dir / "replay" / "order.md").read_text()
    for key, val in (("{{CHECKOUT}}", str(repo.resolve())), ("{{INPUT_DIR}}", str(inputs.resolve()))):
        order = order.replace(key, val)
    (run_dir / "prompt.md").write_text(order)
    settings = subprocess.run(
        [sys.executable, str(ROOT / "tools/agent-security/render_settings.py"), "--workdir", str(repo),
         "--read-dir", str(inputs), "--state-dir", str(state), "--no-network", "--no-docker"],
        check=True, capture_output=True, text=True).stdout
    (run_dir / "settings.json").write_text(settings)
    return {"base": base, "head": head, "agent_commit": agent_commit, "agent_blob": agent_blob}


def _norm(text):
    return " ".join(str(text).split())


def parse_subagent(path, order):
    """Extract the code-reviewer subagent report from the parent's stream-json transcript."""
    def fail(msg):
        return {"ok": False, "error": msg, "report": "", "subagent_model": None, "subagent_tokens": None,
                "subagent_tool_uses": None}

    try:
        lines = Path(path).read_text(errors="replace").splitlines()
    except OSError:
        lines = []
    uses, results, extra = [], {}, {}
    for line in lines:
        try:
            obj = json.loads(line)
        except ValueError:
            continue
        if not isinstance(obj, dict) or obj.get("parent_tool_use_id"):
            continue
        message = obj.get("message")  # a string in some system lines (e.g. permission_denied)
        content = message.get("content") if isinstance(message, dict) else None
        if not isinstance(content, list):
            continue
        for block in content:
            if not isinstance(block, dict):
                continue
            if obj.get("type") == "assistant" and block.get("type") == "tool_use":
                uses.append(block)
            elif obj.get("type") == "user" and block.get("type") == "tool_result":
                results[block.get("tool_use_id")] = block
                extra[block.get("tool_use_id")] = obj.get("tool_use_result")
    agents = [u for u in uses if u.get("name") == "Agent"]
    if len(agents) != 1:
        return fail(f"expected exactly one Agent call, found {len(agents)}")
    others = sorted({str(u.get("name")) for u in uses if u.get("name") != "Agent"})
    if others:
        return fail("parent made other tool calls: " + ", ".join(others))
    call = agents[0]
    inp = call.get("input") or {}
    if inp.get("subagent_type") != "code-reviewer":
        return fail(f"wrong subagent_type: {inp.get('subagent_type')}")
    if inp.get("run_in_background"):
        return fail("subagent was run in background")
    if _norm(inp.get("prompt", "")) != _norm(order):
        return fail("order was altered")
    res = results.get(call.get("id"))
    if res is None:
        return fail("no tool_result for the Agent call")
    tur = extra.get(call.get("id"))
    tur = tur if isinstance(tur, dict) else {}
    if res.get("is_error"):
        return fail("Agent tool_result is an error")
    if tur.get("status") is not None and tur.get("status") != "completed":
        return fail(f"subagent status {tur.get('status')}")
    hand = tur.get("handbackReport")
    report = hand.get("text") if isinstance(hand, dict) else None
    if not (isinstance(report, str) and report.strip()):
        c = res.get("content")
        if isinstance(c, list):
            report = "\n".join(b.get("text", "") for b in c if isinstance(b, dict) and b.get("type") == "text")
        else:
            report = c if isinstance(c, str) else ""
        if "report was delivered to you" in report:
            report = ""
    if not report.strip():
        return fail("subagent report is empty")
    return {"ok": True, "error": None, "report": report, "subagent_model": tur.get("resolvedModel"),
            "subagent_tokens": tur.get("totalTokens"), "subagent_tool_uses": tur.get("totalToolUseCount")}


def run_claude(run_dir, args, prompt=None, extra=None):
    if prompt is None:
        prompt = (run_dir / "prompt.md").read_text()
    cmd = [args.claude_bin, "-p", prompt, *(["--agent", "code-reviewer"] if extra is None else extra),
           "--output-format", "stream-json", "--verbose", "--permission-mode", "auto",
           "--permission-prompts", "none", "--max-budget-usd", str(args.run_cap), "--strict-mcp-config",
           "--setting-sources", "project", "--settings", str(run_dir / "settings.json"),
           "--add-dir", str(run_dir / "inputs")]
    with open(run_dir / "stdout.jsonl", "w") as so, open(run_dir / "stderr.log", "w") as se:
        proc = subprocess.Popen(cmd, cwd=run_dir / "repo", stdin=subprocess.DEVNULL, stdout=so, stderr=se,
                                start_new_session=True)
        try:
            proc.wait(timeout=args.timeout_min * 60)
            return False
        except subprocess.TimeoutExpired:
            os.killpg(proc.pid, signal.SIGTERM)
            try:
                proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                os.killpg(proc.pid, signal.SIGKILL)
                proc.wait()
            return True


def run_case(args, case_id):
    case_dir = CASES / case_id
    case = json.loads((case_dir / "case.json").read_text())
    replay_mode = args.mode == "replay"
    replay = json.loads((case_dir / "replay" / "replay.json").read_text()) if replay_mode else None
    n, run_dir = next_run_dir(args.out, case_id)
    run_id = f"{case_id}-{n}"
    started = datetime.now(timezone.utc).isoformat()
    t0 = time.time()
    meta = {"case": case_id, "run": run_id, "kind": case.get("kind"), "status": "infra_error", "error": None,
            "cost_usd": None, "wall_s": None, "duration_ms": None, "num_turns": None, "models": [],
            "model_usage": {}, "permission_denials": 0, "cli_version": None, "base_sha": case.get("base_sha"),
            "head_sha": case.get("head_sha"), "agent_rev": args.agent_rev, "run_cap_usd": args.run_cap,
            "started_at": started, "mode": args.mode}
    if replay_mode:
        # base_sha and head_sha are the really reviewed commits here, not the squash commit of case.json
        meta.update(parent_model=args.parent_model, task=replay.get("task"), pr=replay.get("pr"),
                    base_sha=replay.get("base_sha"), head_sha=replay.get("review_sha"),
                    subagent_model=None, subagent_tokens=None, subagent_tool_uses=None)
    started_model = False
    try:
        if replay_mode:
            meta.update(build_replay_checkout(run_dir, case_dir, replay, args.agent_rev))
            started_model = True
            order = (run_dir / "prompt.md").read_text()
            timed_out = run_claude(run_dir, args, PARENT_PROMPT.replace("{order}", order),
                                   ["--model", args.parent_model])
        else:
            meta.update(build_checkout(run_dir, case_dir, case, args.agent_rev))
            started_model = True
            timed_out = run_claude(run_dir, args)
        info = parse_result(run_dir / "stdout.jsonl")
        meta["cost_usd"] = info["cost_usd"]  # known from here on, also when reading the report fails below
        if timed_out:
            info.update(status="infra_error", error=f"timeout after {args.timeout_min} min")
        if replay_mode and info["status"] == "ok" and not timed_out:
            sub = parse_subagent(run_dir / "stdout.jsonl", order)
            meta.update(subagent_model=sub["subagent_model"], subagent_tokens=sub["subagent_tokens"],
                        subagent_tool_uses=sub["subagent_tool_uses"])
            if sub["ok"]:
                info["text"] = sub["report"]
            else:
                info.update(status="infra_error", error=sub["error"])
        if info["status"] == "ok":
            (run_dir / "report.md").write_text(info["text"])
        info.pop("text")
        meta.update(info)
    except Exception as exc:  # any failure, also in reading the transcript, must still reach meta.json and the ledger
        detail = getattr(exc, "stderr", "") or ""
        meta.update(status="infra_error", error=f"{type(exc).__name__}: {exc} {detail}".strip())
    meta["wall_s"] = round(time.time() - t0, 1)
    meta["agent_commit"] = meta.get("agent_commit")
    run_dir.mkdir(parents=True, exist_ok=True)
    (run_dir / "meta.json").write_text(json.dumps(meta, indent=2))
    if meta["cost_usd"] is not None:
        ledger_add(args.out, "run", run_id, meta["cost_usd"])
    elif started_model:
        # The model ran but reported no cost (timeout, crash): charge the whole cap so the total limit still holds.
        meta["cost_assumed_usd"] = args.run_cap
        (run_dir / "meta.json").write_text(json.dumps(meta, indent=2))
        ledger_add(args.out, "run", run_id, args.run_cap)
    print(f"{run_id} {meta['status']} cost={meta['cost_usd']} wall={meta['wall_s']}s")
    return meta["status"] == "ok"


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", required=True)
    ap.add_argument("--case", action="append", required=True)
    ap.add_argument("--total-cap", type=float, default=25)
    ap.add_argument("--run-cap", type=float, default=3)
    ap.add_argument("--timeout-min", type=float, default=25)
    ap.add_argument("--agent-rev", default="origin/main")
    ap.add_argument("--claude-bin", default="claude")
    ap.add_argument("--mode", choices=["squash", "replay"], default="squash")
    ap.add_argument("--parent-model", default="sonnet")
    args = ap.parse_args(argv)
    for c in args.case:
        if not (CASES / c / "case.json").exists():
            print(f"unknown case: {c}", file=sys.stderr)
            return 2
        if args.mode == "replay" and not (CASES / c / "replay" / "replay.json").exists():
            print(f"case has no replay/replay.json: {c}", file=sys.stderr)
            return 2
    all_ok = True
    for c in args.case:
        check_budget(args.out, args.run_cap, args.total_cap)
        all_ok = run_case(args, c) and all_ok
    return 0 if all_ok else 1


if __name__ == "__main__":
    sys.exit(main())

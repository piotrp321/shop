#!/usr/bin/env python3
"""One measured run of the performance audit.

bench.py NAME TARGETS_FILE [--vus N] [--duration 20s] [--rate R] [--api-container C] [--label L]

Runs k6 (pinned image of load/k6.sh) in the compose network against the targets, and around the run reads:
  - the CPU time of the containers (cgroup cpu.stat usage_usec) -> CPU ms per request, per tier,
  - pg_stat_statements of the shop database (reset before) -> SQL statements per request and their time,
  - pg_stat_database sessions -> new database connections per request.
Appends one JSON line to results/bench.jsonl and prints a short line.
"""
import argparse
import json
import os
import subprocess
import sys
import time

S = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROJECT = "shop-perfaudit"
NETWORK = f"{PROJECT}_default"
K6_IMAGE = "grafana/k6:2.3.0@sha256:2dd4756ec6e14e62778916e1eb850108e4b499304c02df56760fa0bdb0fba0b8"
TIERS = {
    "postgres": f"{PROJECT}-postgres-1",
    "elasticsearch": f"{PROJECT}-elasticsearch-1",
    "redis": f"{PROJECT}-redis-1",
    "varnish": f"{PROJECT}-varnish-1",
    "store": f"{PROJECT}-store-1",
    "keycloak": f"{PROJECT}-keycloak-1",
    "api_dev": f"{PROJECT}-api-1",
    "api_prod": f"{PROJECT}-api-prod",
    "api_prodw": f"{PROJECT}-api-prodw",
    "api_prodp": f"{PROJECT}-api-prodp",
}


def sh(cmd, check=True):
    r = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if check and r.returncode != 0:
        raise RuntimeError(f"{cmd}\n{r.stderr}")
    return r.stdout.strip()


def cpu_usec(container):
    out = sh(f"docker exec {container} cat /sys/fs/cgroup/cpu.stat 2>/dev/null", check=False)
    for line in out.splitlines():
        if line.startswith("usage_usec"):
            return int(line.split()[1])
    return None


def mem_bytes(container):
    out = sh(f"docker exec {container} cat /sys/fs/cgroup/memory.current 2>/dev/null", check=False)
    return int(out) if out.isdigit() else None


def psql(sql):
    r = subprocess.run(
        ["docker", "exec", TIERS["postgres"], "psql", "-U", "postgres", "-d", "shop", "-At", "-F", "\t", "-c", sql],
        capture_output=True, text=True,
    )
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


def foreign_cpu():
    """CPU share of the containers that are not ours (other sessions), a sample of docker stats."""
    out = sh("docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}'", check=False)
    ours, foreign = 0.0, 0.0
    for line in out.splitlines():
        try:
            name, perc = line.split("\t")
            value = float(perc.rstrip("%") or 0)
        except ValueError:
            continue
        if name.startswith(PROJECT) or name.startswith("k6-perfaudit"):
            ours += value
        else:
            foreign += value
    return round(ours, 1), round(foreign, 1)


def main():
    p = argparse.ArgumentParser()
    p.add_argument("name")
    p.add_argument("targets")
    p.add_argument("--vus", type=int, default=1)
    p.add_argument("--duration", default="20s")
    p.add_argument("--rate", type=int, default=0)
    p.add_argument("--max-vus", type=int, default=1000)
    p.add_argument("--label", default="")
    p.add_argument("--top", type=int, default=0, help="print the top N statements")
    p.add_argument("--script", default="gen.js")
    p.add_argument("--env", action="append", default=[], help="extra K=V for k6")
    a = p.parse_args()

    out_dir = os.path.join(S, "results", "k6")
    os.makedirs(out_dir, exist_ok=True)
    out_name = f"{a.name}.json"
    targets_abs = os.path.abspath(a.targets)

    sh("docker rm -f k6-perfaudit", check=False)
    psql("select pg_stat_statements_reset()")
    sessions0 = int(psql("select sessions from pg_stat_database where datname='shop'"))
    xact0 = int(psql("select xact_commit+xact_rollback from pg_stat_database where datname='shop'"))
    cpu0 = {t: cpu_usec(c) for t, c in TIERS.items()}
    t0 = time.time()

    env = {
        "TARGETS_FILE": "/targets.json",
        "OUT": f"/out/{out_name}",
        "DURATION": a.duration,
        "VUS": str(a.vus),
        "MAX_VUS": str(a.max_vus),
    }
    if a.rate:
        env["MODE"] = "rate"
        env["RATE"] = str(a.rate)
    for kv in a.env:
        k, v = kv.split("=", 1)
        env[k] = v
    envs = " ".join(f"-e {k}={v}" for k, v in env.items())
    cmd = (
        f"docker run --rm --name k6-perfaudit --network {NETWORK} -u {os.getuid()}:{os.getgid()} -e HOME=/tmp "
        f"-v {S}/scripts:/scripts:ro -v {targets_abs}:/targets.json:ro -v {out_dir}:/out {envs} "
        f"{K6_IMAGE} run --quiet /scripts/{a.script}"
    )
    # A sample of the CPU of the other containers in the middle of the run.
    proc = subprocess.Popen(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    time.sleep(min(6, max(1, float(a.duration.rstrip("s")) / 2)))
    ours, foreign = foreign_cpu()
    k6_out, k6_err = proc.communicate()
    wall = time.time() - t0

    cpu1 = {t: cpu_usec(c) for t, c in TIERS.items()}
    sessions1 = int(psql("select sessions from pg_stat_database where datname='shop'"))
    xact1 = int(psql("select xact_commit+xact_rollback from pg_stat_database where datname='shop'"))
    stmts = psql(
        "select s.calls, round(s.total_exec_time::numeric,2), round(s.mean_exec_time::numeric,3), s.rows, "
        "left(regexp_replace(s.query, '[[:space:]]+', ' ', 'g'), 400) "
        "from pg_stat_statements s join pg_database d on d.oid=s.dbid join pg_roles r on r.oid=s.userid "
        "where d.datname='shop' and r.rolname='shop' order by s.total_exec_time desc"
    )
    rows = [line.split("\t") for line in stmts.splitlines() if line]

    summary = json.load(open(os.path.join(out_dir, out_name)))
    m = summary["metrics"]
    reqs = m["http_reqs"]["values"]["count"]
    dur = m["http_req_duration"]["values"]
    failed = m["http_req_failed"]["values"]["rate"]
    checks = m.get("checks", {}).get("values", {})
    dropped = m.get("dropped_iterations", {}).get("values", {}).get("count", 0)

    sql_calls = sum(int(r[0]) for r in rows)
    sql_ms = sum(float(r[1]) for r in rows)
    result = {
        "name": a.name,
        "label": a.label,
        "vus": a.vus,
        "rate": a.rate,
        "duration": a.duration,
        "requests": reqs,
        "rps": round(m["http_reqs"]["values"]["rate"], 1),
        "ms": {k: round(dur[k], 2) for k in ("med", "p(90)", "p(95)", "p(99)", "max", "avg")},
        "failed_rate": round(failed, 4),
        "check_pass_rate": round(checks.get("rate", 0), 4),
        "dropped_iterations": dropped,
        "recv_bytes_per_req": round(m["data_received"]["values"]["count"] / max(reqs, 1)),
        "cpu_ms_per_req": {
            t: round((cpu1[t] - cpu0[t]) / 1000 / max(reqs, 1), 3)
            for t in TIERS
            if cpu0[t] is not None and cpu1[t] is not None and cpu1[t] - cpu0[t] > 0
        },
        "cpu_cores_used": {
            t: round((cpu1[t] - cpu0[t]) / 1e6 / wall, 2)
            for t in TIERS
            if cpu0[t] is not None and cpu1[t] is not None and (cpu1[t] - cpu0[t]) / 1e6 / wall >= 0.05
        },
        "sql_per_req": round(sql_calls / max(reqs, 1), 2),
        "sql_ms_per_req": round(sql_ms / max(reqs, 1), 3),
        "sql_distinct": len(rows),
        "db_sessions_per_req": round((sessions1 - sessions0) / max(reqs, 1), 2),
        "db_xacts_per_req": round((xact1 - xact0) / max(reqs, 1), 2),
        "docker_cpu_percent_ours_foreign": [ours, foreign],
        "top_statements": [
            {"calls_per_req": round(int(r[0]) / max(reqs, 1), 2), "mean_ms": float(r[2]), "rows": int(r[3]), "q": r[4]}
            for r in rows[:12]
        ],
        "custom": {
            k: ({x: round(v["values"][x], 2) for x in ("med", "p(95)", "p(99)", "max")} if "med" in v["values"] else v["values"].get("count"))
            for k, v in m.items()
            if k.startswith("t_") or k.startswith("orders_")
        },
        "ts": time.strftime("%Y-%m-%dT%H:%M:%S"),
    }
    with open(os.path.join(S, "results", "bench.jsonl"), "a") as f:
        f.write(json.dumps(result, ensure_ascii=False) + "\n")

    cpu = " ".join(f"{t}={v}" for t, v in result["cpu_ms_per_req"].items() if v >= 0.01)
    print(
        f"{a.name:38s} vus={a.vus:<3d} rps={result['rps']:<8} p50={result['ms']['med']:<7} p95={result['ms']['p(95)']:<7} "
        f"p99={result['ms']['p(99)']:<7} fail={result['failed_rate']} ok={result['check_pass_rate']} "
        f"sql/req={result['sql_per_req']} sqlms/req={result['sql_ms_per_req']} conn/req={result['db_sessions_per_req']} "
        f"bytes={result['recv_bytes_per_req']} | cpu ms/req: {cpu} | foreign cpu%={foreign}"
    )
    if result["custom"]:
        print("    custom:", json.dumps(result["custom"]))
    if a.top:
        for s in result["top_statements"][: a.top]:
            print(f"    {s['calls_per_req']:>6} x {s['mean_ms']:>8} ms rows={s['rows']:<7} {s['q'][:170]}")
    if proc.returncode not in (0, 99):
        print(k6_err[-800:], file=sys.stderr)


if __name__ == "__main__":
    main()

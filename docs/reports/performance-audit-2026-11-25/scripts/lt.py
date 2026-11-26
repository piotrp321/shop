#!/usr/bin/env python3
"""Closed-loop load tool of the 2026-11-25 audit.

lt.py BASE CONC DURATION_S PATTERN [--same] [--timeout S] [--label L] [--containers a,b,c]

PATTERN is a path; "{i}" is replaced by a unique increasing number (cold URL per request) unless --same.
Reports statuses, latency percentiles, throughput and the CPU seconds each container used (cgroup cpu.stat).
"""
import argparse, http.client, json, subprocess, sys, threading, time, urllib.parse

p = argparse.ArgumentParser()
p.add_argument("base"); p.add_argument("conc", type=int); p.add_argument("duration", type=float); p.add_argument("pattern")
p.add_argument("--same", action="store_true"); p.add_argument("--timeout", type=float, default=30)
p.add_argument("--label", default=""); p.add_argument("--containers", default="")
p.add_argument("--start", type=int, default=1); p.add_argument("--max-requests", type=int, default=0)
p.add_argument("--header", action="append", default=[])
a = p.parse_args()
u = urllib.parse.urlparse(a.base)
headers = dict(h.split(":", 1) for h in a.header)
headers = {k.strip(): v.strip() for k, v in headers.items()}

def cpu(c):
    try:
        out = subprocess.run(["docker", "exec", c, "cat", "/sys/fs/cgroup/cpu.stat"], capture_output=True, text=True, timeout=10).stdout
        return int(out.split("usage_usec ")[1].split()[0]) / 1e6
    except Exception:
        return float("nan")

containers = [c for c in a.containers.split(",") if c]
before = {c: cpu(c) for c in containers}
lock = threading.Lock(); counter = [a.start]; results = []; stop = time.time() + a.duration

def worker():
    conn = None
    while time.time() < stop:
        with lock:
            if a.max_requests and counter[0] - a.start >= a.max_requests:
                return
            i = counter[0]; counter[0] += 1
        path = a.pattern if a.same else a.pattern.replace("{i}", str(i))
        t = time.time(); status = 0; cache = ""
        try:
            if conn is None:
                conn = http.client.HTTPConnection(u.hostname, u.port, timeout=a.timeout)
            conn.request("GET", path, headers=headers)
            r = conn.getresponse(); body = r.read(); status = r.status; cache = r.getheader("X-Cache") or r.getheader("x-nextjs-cache") or ""
        except Exception as e:
            status = -1; conn = None
        results.append((time.time() - t, status, cache))

t0 = time.time()
threads = [threading.Thread(target=worker) for _ in range(a.conc)]
[t.start() for t in threads]; [t.join() for t in threads]
el = time.time() - t0
after = {c: cpu(c) for c in containers}
lat = sorted(r[0] for r in results); n = len(lat)
def pct(q): return round(lat[min(n - 1, int(q * n))] * 1000) if n else None
statuses = {}
for _, s, _ in results: statuses[s] = statuses.get(s, 0) + 1
caches = {}
for _, _, c in results:
    if c: caches[c] = caches.get(c, 0) + 1
ok = sum(v for k, v in statuses.items() if 200 <= k < 400)
out = {"label": a.label, "conc": a.conc, "n": n, "rps": round(n / el, 2), "ok_rps": round(ok / el, 2), "err_pct": round(100 * (n - ok) / n, 1) if n else None,
       "p50": pct(.5), "p95": pct(.95), "p99": pct(.99), "max": round(lat[-1] * 1000) if n else None, "status": statuses, "cache": caches,
       "cpu_s": {c.replace("shop-perf25-", "").replace("-1", ""): round(after[c] - before[c], 2) for c in containers},
       "cpu_ms_per_req": {c.replace("shop-perf25-", "").replace("-1", ""): round(1000 * (after[c] - before[c]) / n, 1) for c in containers} if n else {}}
print(json.dumps(out, ensure_ascii=False))

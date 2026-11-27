#!/usr/bin/env python3
"""H2 of PERF-7-1: does the query of the API go on in PostgreSQL after the client of the panel aborts the request?

  h2.py --api URL --token-file FILE --path '/api/v1/admin/products?category=...' [--abort 1.0] [--container perf7-postgres-1]

Sends the request with a client that gives up after --abort seconds (curl --max-time, the way a browser aborts a request), and from the
moment the request is sent reads pg_stat_activity of the database every 250 ms: the sessions that run a statement of catalog_product
(`state = active`) and for how long. The control: the same request without the abort, to know how long the statement runs when it is
let to finish. The verdict is printed: the statement was still active this many seconds after the abort, or it stopped within 1 s.
"""
import argparse
import json
import subprocess
import sys
import time
import urllib.parse


def sessions(container):
    sql = ("select pid, state, extract(epoch from now() - query_start)::numeric(8,2), left(regexp_replace(query, '\\s+', ' ', 'g'), 90) "
           "from pg_stat_activity where datname = 'shop' and pid <> pg_backend_pid() and state = 'active' and query like '%catalog_product%'")
    out = subprocess.run(["docker", "exec", "-i", container, "psql", "-U", "postgres", "-d", "shop", "-At", "-F", "|", "-c", sql], capture_output=True, text=True).stdout
    return [line.split("|") for line in out.splitlines() if line]


def watch(container, curl_command, seconds):
    started = time.time()
    client = subprocess.Popen(curl_command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    seen = []
    while time.time() - started < seconds:
        now = time.time() - started
        for pid, _, runtime, query in sessions(container):
            seen.append((round(now, 2), pid, float(runtime), query))
        if client.poll() is not None and not seen:
            pass
        time.sleep(0.25)
    client.wait()
    return seen, client.returncode, client.stdout.read().strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--api", required=True)
    parser.add_argument("--token-file", required=True)
    parser.add_argument("--path", required=True)
    parser.add_argument("--abort", type=float, default=1.0)
    parser.add_argument("--watch", type=float, default=20.0)
    parser.add_argument("--container", default="perf7-postgres-1")
    args = parser.parse_args()
    token = json.load(open(args.token_file))["access_token"]
    base = ["curl", "-s", "-o", "/dev/null", "-w", "%{http_code} %{time_total}", "-H", f"Authorization: Bearer {token}", args.api + args.path]
    for label, command in (("with the client that aborts after %.1f s" % args.abort, base[:1] + ["--max-time", str(args.abort)] + base[1:]), ("control: the client waits", base[:1] + ["--max-time", "60"] + base[1:])):
        seen, code, output = watch(args.container, command, args.watch)
        print(f"== {label}: curl exit {code} ({output or 'no answer'})")
        pids = sorted({s[1] for s in seen})
        for pid in pids:
            rows = [s for s in seen if s[1] == pid]
            print(f"   backend {pid}: active from t={rows[0][0]} s to t={rows[-1][0]} s (the statement runs {rows[-1][2]} s at the last look): {rows[0][3]}")
        if not pids:
            print("   no active statement of catalog_product seen")
        elif label.startswith("with"):
            last = max(s[0] for s in seen)
            print(f"   verdict: the statement was active until t={last} s; the client gave up at t={args.abort} s -> " + ("it GOES ON after the abort (H2 confirmed)" if last > args.abort + 1.0 else "it stopped with the abort (H2 not confirmed)"))
        time.sleep(3)


if __name__ == "__main__":
    main()

"""Joins the driver events, the memory samples and the GC trace of one run. Usage: analyze.py <out dir> [step tags]."""
import bisect
import os
import json
import re
import sys
from datetime import datetime

out = sys.argv[1]
step = int(sys.argv[2]) if len(sys.argv) > 2 else 2_000_000
MIB = 1048576

events = [json.loads(line) for line in open(f"{out}/driver.jsonl") if line.strip()]
samples = []
for line in open(f"{out}/memory.csv").read().splitlines()[1:]:
    parts = line.split(",")
    if len(parts) == 6 and all(p.strip().isdigit() for p in parts):
        t, current, inactive, anon, file_, rss = map(int, parts)
        samples.append((t, current, inactive, anon, file_, rss))
gc = []  # (epoch ms, kind, heap after MB, pause ms)
pattern = re.compile(r"^(\S+) \[\d+:0x[0-9a-f]+\]\s+\d+ ms: (Scavenge|Mark-Compact)(?: \(reduce\))? [\d.]+ \([\d.]+\) -> ([\d.]+) \([\d.]+\) MB, (?:pooled: \d+ MB, )?([\d.]+) /")
other = []
log = f"{out}/store.log" if os.path.exists(f"{out}/store.log") else f"{out}/store-gc.log"
for line in open(log, errors="replace"):
    m = pattern.match(line)
    if m:
        stamp = datetime.fromisoformat(m.group(1)[:26] + "+00:00").timestamp() * 1000
        gc.append((stamp, m.group(2), float(m.group(3)), float(m.group(4))))
    elif "Scavenge" not in line and "Mark-Compact" not in line:
        other.append(line.rstrip()[:220])
times = [s[0] for s in samples]
mc = [g for g in gc if g[1] == "Mark-Compact"]
mc_times = [g[0] for g in mc]


def at(t):
    i = min(bisect.bisect_right(times, t), len(samples) - 1)
    s = samples[max(i - 1, 0)] if times[min(i, len(times) - 1)] > t else samples[i]
    j = bisect.bisect_right(mc_times, t) - 1
    heap = mc[j][2] if j >= 0 else float("nan")
    return s, heap


def row(label, t):
    s, heap = at(t)
    print(f"| {label} | {s[5] / 1024:.0f} | {(s[1] - s[2]) / MIB:.0f} | {s[3] / MIB:.0f} | {heap:.0f} |")


print("| Punkt | RSS MiB | working set MiB | anon MiB | sterta po GC MB |")
print("|---|---|---|---|---|")
start = events[0]["t"]
row("start", start)
next_step = step
for e in events:
    if e["event"] == "warm":
        row("po rozgrzewce", e["t"])
    elif e["event"] == "pause-start":
        row(f"{e['sent']:,} tagów (początek pauzy)", e["t"])
    elif e["event"] == "pause-end":
        row(f"{e['sent']:,} tagów (koniec pauzy 60 s)", e["t"])
    elif e["event"] == "progress" and e["sent"] >= next_step:
        row(f"{e['sent']:,} tagów", e["t"])
        next_step += step
    elif e["event"] in ("revalidate-failed", "done"):
        row(f"{e['event']} sent={e['sent']:,}", e["t"])
last = [e for e in events if e["event"] in ("progress", "pause-end")][-1:]
print()
print("events:", [(e["event"], e.get("sent"), e.get("message")) for e in events if e["event"] not in ("progress",)])
if samples:
    peak = max(samples, key=lambda s: s[5])
    print(f"peak RSS {peak[5] / 1024:.0f} MiB, peak working set {max((s[1] - s[2]) for s in samples) / MIB:.0f} MiB, samples {len(samples)}")
    print(f"last sample: RSS {samples[-1][5] / 1024:.0f} MiB at +{(samples[-1][0] - start) / 1000:.0f} s")
if mc:
    print(f"Mark-Compact: {len(mc)}, longest pause {max(g[3] for g in mc):.0f} ms, heap after last {mc[-1][2]:.0f} MB, max heap after GC {max(g[2] for g in mc):.0f} MB")
    long = [g for g in mc if g[3] >= 100]
    print(f"Mark-Compact pauses >= 100 ms: {len(long)}; total Mark-Compact pause {sum(g[3] for g in mc) / 1000:.1f} s; scavenges {len(gc) - len(mc)}")
send = [e for e in events if e["event"] in ("warm", "pause-start")]
if len(send) == 2:
    print(f"rate to pause: {events[[e['event'] for e in events].index('pause-start')]['sent'] / ((send[1]['t'] - send[0]['t']) / 1000):,.0f} tags/s")
print("state:", open(f"{out}/state.txt").read().strip())
print("non-GC log lines (last 12):")
for line in other[-12:]:
    print("  ", line)

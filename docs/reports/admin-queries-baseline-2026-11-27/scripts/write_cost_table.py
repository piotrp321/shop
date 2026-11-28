#!/usr/bin/env python3
"""The table of the cost of the writes (write-cost.sql, write-cost-run.sh): the mean of the passes of every state, as markdown.

  write_cost_table.py DIR

For every state (S0-base, S1-K1, S2-K1-F2, S3-K1-F2-Z1) and kind of write (product, order cycle, customer): the WAL bytes of one write in
the cold pass and in the hot pass, the bytes of the flush of the pending lists of the GIN indexes per write, and the milliseconds per
write; the mean of the two runs (a, b) of the state, and the lowest and highest in brackets. The effect of a variant is the difference of
two neighbouring states.
"""
import glob
import os
import re
import statistics
import sys

PATTERN = re.compile(r"NOTICE:\s+(\S+)\.(\w) (product|order-cycle|customer) (cold|hot) \w+=(\d+) wal_bytes_per_\w+=([\d.]+) fpi_per_\w+=[\d.]+ ms_per_\w+=([\d.]+) flush_bytes_per_write=([\d.]+)")


def main():
    directory = sys.argv[1]
    cells = {}
    for path in sorted(glob.glob(os.path.join(directory, "S*.txt"))):
        for line in open(path, encoding="utf-8"):
            match = PATTERN.search(line)
            if match:
                state, run, kind, temperature, n, wal, ms, flush = match.groups()
                cells.setdefault((state, kind, temperature), []).append((float(wal), float(flush), float(ms)))
    states = ["S0-base", "S1-K1", "S2-K1-F2", "S3-K1-F2-Z1"]
    print("| kind | pass | " + " | ".join(f"{s}: WAL B/write (flush B/write; ms/write)" for s in states) + " |")
    print("|---|---|" + "---|" * len(states))
    for kind in ("product", "order-cycle", "customer"):
        for temperature in ("cold", "hot"):
            row = []
            for state in states:
                runs = cells.get((state, kind, temperature), [])
                if not runs:
                    row.append("-")
                    continue
                wal = [r[0] for r in runs]
                row.append(f"{statistics.mean(wal):.0f} ({min(wal):.0f}–{max(wal):.0f}); flush {statistics.mean(r[1] for r in runs):.0f}; {statistics.mean(r[2] for r in runs):.2f} ms")
            print(f"| {kind} | {temperature} | " + " | ".join(row) + " |")


if __name__ == "__main__":
    main()

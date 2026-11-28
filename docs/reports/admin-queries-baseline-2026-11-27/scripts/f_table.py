#!/usr/bin/env python3
"""The table of F1 and F2 by phrase: the walk of the index of the sort for at most B rows, then (when the page is not full) the candidates.

  f_table.py --variant DIR --base DIR

For every phrase and sort: the walk (a case `<V>.<label>.p1.<sort>.walk`) fills the page when it returned 26 rows; then it is the whole cost, else
the cost is the walk plus the candidates (`.gin`). The count of the first page is its own case. Times are the median of the repeated runs on
`medium`; `server` is the estimate of extrapolate.py (the walk does not grow with the data, the candidates grow with the hits), in memory and
from disk (an assumption, see the report). The present statement (F0) of the same phrase and sort is shown for comparison.
"""
import argparse
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import extrapolate  # noqa: E402


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--variant", required=True)
    parser.add_argument("--base", required=True)
    args = parser.parse_args()
    variant = extrapolate.load(args.variant)
    base = extrapolate.load(args.base)
    name = os.path.basename(os.path.normpath(args.variant))
    mine = {r["id"]: r for r in extrapolate.rows_of(variant, None)}
    theirs = {r["id"]: r for r in extrapolate.rows_of(base, None)}
    labels = sorted({i.split(".")[1] for i in mine})
    print(f"| fraza | sort | trafień (medium → server) | {name} medium: spacer / kandydaci ms | strona pełna po spacerze | {name} medium razem ms | {name} server w pamięci ms | {name} server z dysku ms | obecne (F0) medium → server w pamięci ms | P2 pamięć / dysk |")
    print("|---|---|---|---|---|---|---|---|---|---|")
    failing = []
    for label in labels:
        for sort in ("name", "-updatedAt"):
            walk, gin = mine.get(f"{name}.{label}.p1.{sort}.walk"), mine.get(f"{name}.{label}.p1.{sort}.gin")
            if walk is None or gin is None:
                continue
            filled = variant["cases"][walk["id"]]["runs"][-1]["returned"] >= 26
            total_m = walk["medium"]["median"] + (0 if filled else gin["medium"]["median"])
            mem = walk["server"]["memory_ms"] + (0 if filled else gin["server"]["memory_ms"])
            disk = walk["server"]["disk_ms"] + (0 if filled else gin["server"]["disk_ms"])
            present = theirs.get(f"P-term-{label}.p1.{sort}")
            now = f"{present['medium']['median']:.1f} → {present['server']['memory_ms']:.0f}" if present else "-"
            ok = f"{'ok' if mem <= 2000 else 'NIE'} / {'ok' if disk <= 2000 else 'NIE'}"
            if mem > 2000:
                failing.append(f"{label}/{sort}")
            print(f"| {label} | {sort} | {gin['value_medium']} → {gin['value_server']} | {walk['medium']['median']:.1f} / {gin['medium']['median']:.1f} | {'tak' if filled else 'nie'} | {total_m:.1f} | {mem:.0f} | {disk:.0f} | {now} | {ok} |")
    for label in labels:
        count = mine.get(f"{name}.{label}.count")
        present = theirs.get(f"P-term-{label}.count")
        if count is None:
            continue
        now = f"{present['medium']['median']:.1f} → {present['server']['memory_ms']:.0f}" if present else "-"
        ok = f"{'ok' if count['p2_memory'] else 'NIE'} / {'ok' if count['p2_disk'] else 'NIE'}"
        if not count["p2_memory"]:
            failing.append(f"{label}/count")
        print(f"| {label} | licznik | {count['value_medium']} → {count['value_server']} | - / {count['medium']['median']:.1f} | - | {count['medium']['median']:.1f} | {count['server']['memory_ms']:.0f} | {count['server']['disk_ms']:.0f} | {now} | {ok} |")
    print(f"\n{name}: powyżej 2000 ms w pamięci na server (SZACUNEK): {', '.join(failing) if failing else 'brak'}")


if __name__ == "__main__":
    main()

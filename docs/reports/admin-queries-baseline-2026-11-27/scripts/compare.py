#!/usr/bin/env python3
"""Compares the results of a prototype variant with the present statement, case by case, with the estimate for `server`.

  compare.py --variant DIR --base DIR [--small-base DIR] [--format md]

A case of the variant is paired with the case of the matrix that asks the same question: `<V>.<label>.cat.<page>` with `P-cat-<label>.<page>`,
`<V>.<label>.p1.<sort>.walk` and `.gin` with `P-term-<label>.p1.<sort>`, `<V>.<label>.count` with `P-term-<label>.count`, `<V>.O-<label>.<page>`
with `O-frag-<label>.<page>`, `<V>.C-<label>.<page>` with `C-frag-<label>.<page>`. The estimate for `server` is the one of extrapolate.py
(in memory and from disk), judged against the maximum of W7 (2000 ms). A prototype case without a counterpart (the combinations with a filter
beside the category) is listed with its own estimate.
"""
import argparse
import json
import os
import statistics
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import extrapolate  # noqa: E402


def counterpart(case_id, variant):
    parts = case_id.split(".", 1)[1].split(".")
    label = parts[0]
    if variant in ("K0", "K1") and len(parts) > 2 and parts[1] == "cat":
        return "P-cat-" + label + "." + ".".join(parts[2:])
    if variant in ("F1", "F2"):
        if parts[-1] in ("walk", "gin"):
            return "P-term-" + label + "." + ".".join(parts[1:-1])
        if parts[-1] == "count":
            return "P-term-" + label + ".count"
    if variant == "Z1":
        if label.startswith("O-"):
            return "O-frag-" + label[2:] + "." + ".".join(parts[1:])
        if label.startswith("C-"):
            return "C-frag-" + label[2:] + "." + ".".join(parts[1:])
    return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--variant", required=True)
    parser.add_argument("--base", required=True)
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    variant = extrapolate.load(args.variant)
    base = extrapolate.load(args.base)
    name = os.path.basename(os.path.normpath(args.variant))
    mine = {r["id"]: r for r in extrapolate.rows_of(variant, None)}
    theirs = {r["id"]: r for r in extrapolate.rows_of(base, None)}
    # The totals of the variant file are those of the cases file (products, orders, customers of medium).
    lines = [f"| przypadek {name} | zmienna kosztu (medium → server) | bufory medium → server | czas medium pierwszy / mediana / maks. ms | server w pamięci ms | server z dysku ms | P2 pamięć / dysk | obecne: mediana medium ms → server w pamięci ms |", "|---|---|---|---|---|---|---|---|"]
    failing = 0
    for case_id, r in mine.items():
        m, s = r["medium"], r["server"]
        other = theirs.get(counterpart(case_id, name))
        present = f"{other['medium']['median']:.1f} → {other['server']['memory_ms']:.0f}" if other else "-"
        verdict = f"{'ok' if r['p2_memory'] else 'NIE'} / {'ok' if r['p2_disk'] else 'NIE'}"
        failing += 0 if r["p2_memory"] else 1
        lines.append(f"| {case_id} | {r['variable']} {r['value_medium']} → {r['value_server']} | {m['buffers']} → {s['buffers']:.0f} | {m['first']:.1f} / {m['median']:.1f} / {m['max']:.1f} | {s['memory_ms']:.0f} | {s['disk_ms']:.0f} | {verdict} | {present} |")
    print("\n".join(lines))
    print(f"\n{name}: {len(mine)} przypadków, {failing} powyżej 2000 ms w pamięci na server (SZACUNEK)")


if __name__ == "__main__":
    main()

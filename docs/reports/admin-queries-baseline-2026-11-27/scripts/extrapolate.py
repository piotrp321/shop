#!/usr/bin/env python3
"""Extrapolates the results of the matrix (small, medium) to the profile `server` (an estimate, not a measurement).

  extrapolate.py --medium DIR [--small DIR] [--format md|json] [--ids PREFIX]

The measure that does not depend on the machine is the buffers (shared hit + read) and the rows scanned of a case. A case grows with a
variable named in its `scale` (products, orders, customers) or does not (`const`: a page, a bound). When the small results are given, the
label is checked against the data: a case whose buffers grew by less than a quarter of the growth of its variable between small and
medium is taken as not growing. The buffers and rows of `server` are those of medium times the growth of the variable from medium to
`server` (10 000 191 products, 5 000 000 orders, 880 082 customers with an order: the volume that exists on worf.pl).

Two estimates of the time on `server`, both assumptions written here:
  in memory  the median repeated time of medium times the growth of the buffers (the cost per buffer of medium: all data in the memory
             of the host; a lower bound of the time on a node whose memory is smaller than the data);
  from disk  the time in memory plus every buffer of `server` read from the disk at the cost of one page: a case whose plan is a
             sequential scan reads at SEQ_MB_S (200 MB/s, one stream), any other plan at RANDOM_IOPS (2000 random reads of a page per
             second, no read-ahead credit). The worf.pl node (OVH VPS, Postgres limited to 2 GiB, database of 63 GB) has not been measured
             for its disk; the numbers are the assumptions of the report, to be replaced by a measured throughput.
P2 (the maximum of W7, 2000 ms) is judged on both estimates.
"""
import argparse
import json
import os
import statistics

SERVER = {"products": 10_000_191, "orders": 5_000_000, "customers": 880_082}
SEQ_MB_S = 200.0
RANDOM_IOPS = 2000.0
PAGE_KB = 8
P2_MS = 2000.0


def load(directory):
    with open(os.path.join(directory, "results.json"), encoding="utf-8") as handle:
        return json.load(handle)


def table_sizes(document):
    totals = document["totals"]
    return {"products": totals["products"], "orders": totals["orders"], "customers": totals["customers"]}


def figures(entry):
    runs = entry["runs"]
    repeated = [r["ms"] for r in runs[1:]] or [runs[0]["ms"]]
    last = runs[-1]
    return {
        "first": runs[0]["ms"], "median": statistics.median(repeated), "max": max(r["ms"] for r in runs),
        "buffers": last["hit"] + last["read"], "read": last["read"], "rows": last["rows_scanned"], "entries": last["index_entries"],
        "returned": last["returned"], "plan": last["plan"], "temp": last["temp"],
    }


def sequential(plan):
    return any(label.startswith("Seq Scan") for label in plan) and not any(label.startswith(("Index", "Bitmap")) and "pkey" not in label for label in plan)


def estimate(medium_entry, growth, label_scale):
    f = figures(medium_entry)
    factor = growth if label_scale else 1.0
    buffers = f["buffers"] * factor
    memory_ms = f["median"] * factor
    page_ms = (PAGE_KB / 1024.0) / SEQ_MB_S * 1000.0 if sequential(f["plan"]) else 1000.0 / RANDOM_IOPS
    return {"factor": factor, "buffers": buffers, "rows": f["rows"] * factor, "memory_ms": memory_ms, "disk_ms": memory_ms + buffers * page_ms, "page_ms": page_ms}


def effective_scale(case, small_entry, medium_entry, small_totals, medium_totals):
    scale = case["scale"]
    if scale == "const" or scale not in SERVER:
        return None
    if small_entry and small_entry["runs"]:
        growth_small_medium = medium_totals[scale] / small_totals[scale]
        observed = figures(medium_entry)["buffers"] / max(1, figures(small_entry)["buffers"])
        if observed < 0.25 * growth_small_medium:
            return None
    return scale


def rows_of(medium, small):
    medium_totals = table_sizes(medium)
    small_totals = table_sizes(small) if small else None
    result = []
    for case_id, entry in medium["cases"].items():
        if not entry["runs"]:
            continue
        small_entry = small["cases"].get(case_id) if small else None
        scale = effective_scale(entry, small_entry, entry, small_totals, medium_totals) if small else (entry["scale"] if entry["scale"] in SERVER else None)
        growth = SERVER[scale] / medium_totals[scale] if scale else 1.0
        e = estimate(entry, growth, scale is not None)
        f = figures(entry)
        result.append({
            "id": case_id, "title": entry["title"], "variable": entry["variable"], "value_medium": entry["value"], "scale": scale or "const",
            "growth": round(growth, 2), "value_server": round(entry["value"] * growth) if isinstance(entry["value"], (int, float)) and scale else entry["value"],
            "small_buffers": figures(small_entry)["buffers"] if small_entry and small_entry["runs"] else None, "small_median": figures(small_entry)["median"] if small_entry and small_entry["runs"] else None,
            "medium": f, "server": e, "p2_memory": e["memory_ms"] <= P2_MS, "p2_disk": e["disk_ms"] <= P2_MS,
        })
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--medium", required=True)
    parser.add_argument("--small")
    parser.add_argument("--format", default="md", choices=["md", "json"])
    parser.add_argument("--ids", default="")
    args = parser.parse_args()
    medium = load(args.medium)
    small = load(args.small) if args.small else None
    result = [r for r in rows_of(medium, small) if r["id"].startswith(args.ids)]
    if args.format == "json":
        print(json.dumps(result, indent=1))
        return
    print("| case | cost variable (value medium → server) | scale | buffers small / medium / server | rows scanned medium / server | median ms small / medium (first, max) | server memory ms | server disk ms | P2 mem / disk |")
    print("|---|---|---|---|---|---|---|---|---|")
    for r in result:
        m, s = r["medium"], r["server"]
        small_cells = f"{r['small_buffers']}" if r["small_buffers"] is not None else "-"
        small_ms = f"{r['small_median']:.1f}" if r["small_median"] is not None else "-"
        print(f"| {r['id']} | {r['variable']} {r['value_medium']} → {r['value_server']} | {r['scale']} ×{r['growth']} | {small_cells} / {m['buffers']} / {s['buffers']:.0f} | {m['rows']} / {s['rows']:.0f} | "
              f"{small_ms} / {m['median']:.1f} ({m['first']:.1f}, {m['max']:.1f}) | {s['memory_ms']:.0f} | {s['disk_ms']:.0f} | {'ok' if r['p2_memory'] else 'NO'} / {'ok' if r['p2_disk'] else 'NO'} |")


if __name__ == "__main__":
    main()

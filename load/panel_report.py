#!/usr/bin/env python3
"""The report of the runs of load/panel.sh: the median of the runs and their spread (performance program, PERF-7).

  panel_report.py DIR w7|p4

A failed request is any answer that is not a success of the scenario: 5xx and network errors, 409, 429 and every other 4xx (a conflict or a limit
answered to the load is a failure of that request, whoever causes it); the requests the generator did not send in time (`dropped`) are failures too.
Both are part of the verdict of w7 and p4, so a run with failures is never reported as met.

w7: the figures of the mix (the p50, p95, p99 and the maximum of the whole mix and of every class) of DIR/w7-run*/panel.json, the
median of the runs and the lowest and the highest run, with the verdict against W7 (p50 100 ms, p95 500 ms, maximum 2000 ms).
p4: the shop listings and carts (DIR/p4-without-run*, DIR/p4-with-run*): per run the p50, p95, p99, the share of the requests that
failed and the requests dropped, the median of the runs with and without the panel, the spread of the runs without it (the
criterion: the difference of the medians must not exceed the spread without the panel), and the load of PostgreSQL of every run
(the difference of the counters before and after, the sessions and the containers sampled every 5 seconds).

Exit codes: 0 the report is printed; 2 no runs in DIR or an unreadable result.
"""
import glob
import json
import os
import statistics
import sys

W7 = {"p50_ms": 100, "p95_ms": 500, "max_ms": 2000}


def fail(message):
    print(f"panel_report: {message}", file=sys.stderr)
    sys.exit(2)


def load(path):
    try:
        with open(path, encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError) as error:
        fail(f"cannot read {path}: {error}")


def failures(f):
    """The failed requests of a figure: 5xx and network errors, 409, 429 and any other 4xx."""
    return round(f["server_error_rate"] * f["requests"]) + f["conflicts_409"] + f["rate_limited_429"] + f["other_4xx"]


def breakdown(f):
    return f"5xx/net {round(f['server_error_rate'] * f['requests'])}, 409 {f['conflicts_409']}, 429 {f['rate_limited_429']}, other 4xx {f['other_4xx']}"


def runs(directory, prefix):
    files = sorted(glob.glob(os.path.join(directory, f"{prefix}-run*", "panel.json")))
    return [(os.path.dirname(f), load(f)) for f in files]


def spread(values):
    values = [v for v in values if v is not None]
    if not values:
        return None, None, None
    return statistics.median(values), min(values), max(values)


def cell(values):
    median, low, high = spread(values)
    return "-" if median is None else f"{median:g} ({low:g}–{high:g})"


def w7(directory):
    found = runs(directory, "w7")
    if not found:
        fail(f"no w7 runs in {directory}")
    lines = [f"W7: {len(found)} runs, rate {found[0][1]['panel_rate']}/s for {found[0][1]['seconds']} s, the median (lowest–highest)"]
    lines.append(f"{'class':32}{'requests':>10}{'p50 ms':>20}{'p95 ms':>20}{'p99 ms':>20}{'max ms':>20}{'failed':>8}")
    rows = [("MIX (all)", [r["panel"] for _, r in found])]
    names = sorted({name for _, r in found for name in r["classes"]})
    for name in names:
        rows.append((name, [r["classes"][name] for _, r in found if name in r["classes"]]))
    verdict = []
    for name, figures in rows:
        errors = statistics.median(failures(f) for f in figures)
        lines.append(
            f"{name:32}{int(statistics.median(f['requests'] for f in figures)):>10}{cell([f['p50_ms'] for f in figures]):>20}"
            f"{cell([f['p95_ms'] for f in figures]):>20}{cell([f['p99_ms'] for f in figures]):>20}{cell([f['max_ms'] for f in figures]):>20}{errors:>8.0f}"
        )
        median = {key: statistics.median(f[key] for f in figures if f[key] is not None) for key in W7}
        # The mix is judged by W7 as a whole (P1); a class is judged by its maximum (P2) and by its errors.
        limits = W7 if name.startswith("MIX") else {"max_ms": W7["max_ms"]}
        over = [f"{key} {median[key]:g} > {limit}" for key, limit in limits.items() if median[key] > limit]
        if over or errors:
            verdict.append(f"  {name}: " + ", ".join(over + ([f"{errors:.0f} failed requests ({breakdown(max(figures, key=failures))} in the worst run)"] if errors else [])))
    dropped = [r.get("dropped", {}).get("panel", 0) for _, r in found]
    if any(dropped):
        verdict.append(f"  the generator did not send in time: {dropped} requests per run (the rate was not kept)")
    lines.append("")
    lines.append("W7 (the mix: p50 100 ms, p95 500 ms, maximum 2000 ms; every class: maximum 2000 ms; no failed request: 5xx, 409, 429, other 4xx; none dropped) " + ("met" if not verdict else "not met by:"))
    lines.extend(verdict)
    return lines


def pg_load(directory):
    """The counters of the database over one run and the sampled sessions and containers."""
    try:
        before, after = load(os.path.join(directory, "pg-before.json")), load(os.path.join(directory, "pg-after.json"))
    except SystemExit:
        return None
    figures = {key: after[key] - before[key] for key in ("blks_read", "blks_hit", "temp_bytes", "xact_commit", "wal_bytes", "io_read_bytes", "io_reads")}
    sessions, containers = [], {}
    try:
        with open(os.path.join(directory, "samples.txt"), encoding="utf-8") as handle:
            sample_active = 0
            for line in handle:
                parts = line.split()
                if not parts:
                    continue
                if parts[0] == "pg" and len(parts) >= 4:
                    if parts[1] == "active":
                        sample_active += int(parts[-1])
                elif parts[0].isdigit():
                    if sample_active or sessions:
                        sessions.append(sample_active)
                    sample_active = 0
                elif parts[0] == "ct" and len(parts) >= 3:
                    name = parts[1].split("-")[-2] if parts[1].count("-") >= 2 else parts[1]
                    containers.setdefault(name, []).append(float(parts[2].rstrip("%")))
            sessions.append(sample_active)
    except OSError:
        pass
    figures["active_sessions_avg"] = round(statistics.mean(sessions), 2) if sessions else None
    figures["active_sessions_max"] = max(sessions) if sessions else None
    figures["cpu_percent_avg"] = {name: round(statistics.mean(values), 1) for name, values in containers.items()}
    return figures


def p4(directory):
    without, with_panel = runs(directory, "p4-without"), runs(directory, "p4-with")
    if not without or not with_panel:
        fail(f"p4 needs runs with and without the panel in {directory}")
    lines = [f"P4: {len(without)} runs without and {len(with_panel)} with the panel; shop {with_panel[0][1]['shop_listing_rate']} listings/s and {with_panel[0][1]['shop_cart_rate']} carts/s"]
    lines.append(f"{'':34}{'requests':>9}{'failed':>8}{'dropped':>9}{'p50 ms':>9}{'p95 ms':>9}{'p99 ms':>9}{'max ms':>9}")
    medians = {}
    for label, group in (("without", without), ("with", with_panel)):
        for scenario, key in (("shop listings", "shop_listing"), ("shop carts", "shop_cart")):
            for index, (_, result) in enumerate(group, 1):
                f = result[key]
                if f is None:
                    continue
                failed = failures(f)
                lines.append(f"{label + ' ' + scenario + ' run ' + str(index):34}{f['requests']:>9}{failed:>8}{result['dropped'][key]:>9}{f['p50_ms']:>9}{f['p95_ms']:>9}{f['p99_ms']:>9}{f['max_ms']:>9}")
            medians[(label, key)] = {m: [r[key][m] for _, r in group if r[key] and r[key][m] is not None] for m in ("p50_ms", "p95_ms", "p99_ms")}
    lines.append("")
    lines.append("The median of the runs; the spread without the panel is the highest minus the lowest run (the criterion: the difference of the medians is not above it)")
    verdict = []
    for scenario, key in (("shop listings", "shop_listing"), ("shop carts", "shop_cart")):
        for metric in ("p50_ms", "p95_ms", "p99_ms"):
            a, b = medians.get(("without", key), {}).get(metric), medians.get(("with", key), {}).get(metric)
            if not a or not b:
                continue
            med_a, low, high = spread(a)
            med_b = statistics.median(b)
            noise = high - low
            difference = med_b - med_a
            lines.append(f"  {scenario:14}{metric:8} without {med_a:g} ms (spread {noise:g}), with {med_b:g} ms, difference {difference:+g} ms: " + ("within the spread" if abs(difference) <= noise else "ABOVE the spread"))
            if abs(difference) > noise:
                verdict.append(f"{scenario} {metric}")
    lines.append("P4 (difference of the medians within the spread without the panel) " + ("met" if not verdict else "not met: " + ", ".join(verdict)))
    # The failures and the dropped requests of every run, with the answers they are made of; both variants must have none.
    problems = []
    for label, group in (("without", without), ("with", with_panel)):
        for index, (_, result) in enumerate(group, 1):
            for scenario, key in (("shop listings", "shop_listing"), ("shop carts", "shop_cart"), ("panel mix", "panel")):
                f = result[key]
                if f is None or (key == "panel" and label == "without"):
                    continue
                if failures(f) or result["dropped"][key]:
                    problems.append(f"{label} run {index} {scenario}: {failures(f)} failed ({breakdown(f)}), {result['dropped'][key]} dropped")
    lines.append("P4 (no failed request (5xx, 409, 429, other 4xx) and no dropped request in the shop and in the panel mix, in both variants) " + ("met" if not problems else "not met:"))
    lines.extend(f"  {problem}" for problem in problems)
    lines.append("")
    lines.append("Load of PostgreSQL and of the containers per run (counters over the run; sessions and CPU sampled every 5 s)")
    for label, group in (("without", without), ("with", with_panel)):
        for index, (path, _) in enumerate(group, 1):
            load_ = pg_load(path)
            if load_ is not None:
                lines.append(f"  {label} run {index}: " + json.dumps(load_, sort_keys=True))
    return lines


def shop(directory):
    found = runs(directory, "shop")
    if not found:
        fail(f"no shop runs in {directory}")
    lines = [f"shop alone: {len(found)} runs, {found[0][1]['shop_listing_rate']} listings/s and {found[0][1]['shop_cart_rate']} carts/s for {found[0][1]['seconds']} s"]
    lines.append(f"{'':28}{'requests':>9}{'failed':>8}{'dropped':>9}{'p50 ms':>9}{'p95 ms':>9}{'p99 ms':>9}{'max ms':>9}")
    for index, (_, result) in enumerate(found, 1):
        for label, key in (("listings", "shop_listing"), ("carts", "shop_cart")):
            f = result[key]
            if f is None:
                continue
            failed = failures(f)
            lines.append(f"{label + ' run ' + str(index):28}{f['requests']:>9}{failed:>8}{result['dropped'][key]:>9}{f['p50_ms']:>9}{f['p95_ms']:>9}{f['p99_ms']:>9}{f['max_ms']:>9}")
    return lines


def main():
    if len(sys.argv) != 3 or sys.argv[2] not in ("w7", "p4", "shop"):
        fail("usage: panel_report.py DIR w7|p4|shop")
    print("\n".join({"w7": w7, "p4": p4, "shop": shop}[sys.argv[2]](sys.argv[1])))


if __name__ == "__main__":
    main()

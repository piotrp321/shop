"""Prepares the input of promtool from the render of prod (scripts/check.sh, step alerts): the rules are the groups of the
PrometheusRule of the chart, not a copy; the scenarios are alerts.test.yml with the series of the healthy workloads that the
render of prod has (a new workload needs no change of the scenarios).

  prepare.py <prod.ndjson of the render> <output directory>

writes <output directory>/rules.yaml (the `groups` of the PrometheusRule, which promtool reads as a rules file) and
<output directory>/alerts.test.yml. A line `# @healthy-workloads [except=<name>,<name>]` of the scenarios becomes the series of
the replicas of every Deployment and StatefulSet of the render in `shop` (spec and available or ready, one each), the workloads
named in `except` left out: the scenario gives them its own series. Python without third-party packages: JSON is YAML.
"""

import json
import pathlib
import re
import sys

SAMPLES = 300
PLACEHOLDER = re.compile(r"^(\s*)# @healthy-workloads(?: except=(\S+))?\s*$")
SERIES = {
    "Deployment": ("kube_deployment_spec_replicas", "kube_deployment_status_replicas_available", "deployment"),
    "StatefulSet": ("kube_statefulset_replicas", "kube_statefulset_status_replicas_ready", "statefulset"),
}


def main(render, out):
    docs = [json.loads(line) for line in pathlib.Path(render).read_text().splitlines() if line.strip()]
    rules = [d for d in docs if d and d["kind"] == "PrometheusRule"]
    if len(rules) != 1:
        sys.exit(f"alerts: the render of prod has {len(rules)} PrometheusRule objects, it must have one")
    workloads = [(d["kind"], d["metadata"]["name"]) for d in docs if d and d["kind"] in SERIES]
    out = pathlib.Path(out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "rules.yaml").write_text(json.dumps(rules[0]["spec"]))
    lines = []
    for line in (pathlib.Path(__file__).parent / "alerts.test.yml").read_text().splitlines():
        match = PLACEHOLDER.match(line)
        if not match:
            lines.append(line)
            continue
        indent, excepted = match.group(1), (match.group(2) or "").split(",") if match.group(2) else []
        unknown = [e for e in excepted if e not in {name for _, name in workloads}]
        if unknown:
            sys.exit(f"alerts: alerts.test.yml leaves out {unknown}, which the render of prod has not")
        for kind, name in workloads:
            if name in excepted:
                continue
            for metric in SERIES[kind][:2]:
                lines.append(f"{indent}- series: '{metric}{{namespace=\"shop\",{SERIES[kind][2]}=\"{name}\"}}'")
                lines.append(f"{indent}  values: '1x{SAMPLES}'")
    (out / "alerts.test.yml").write_text("\n".join(lines) + "\n")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(*sys.argv[1:])

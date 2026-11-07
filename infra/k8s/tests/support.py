"""Helpers of the render tests: the chart is rendered by scripts/render.sh and the tests read the JSON documents."""

import json
import os
import pathlib
import re

RENDER = pathlib.Path(os.environ.get("RENDER_DIR", "/cache/render"))
REPO = pathlib.Path(os.environ.get("REPO_DIR", "/repo"))
WAVE = "argocd.argoproj.io/sync-wave"
WORKLOADS = ("Deployment", "StatefulSet", "Job", "CronJob")
JOBS = ("garage-init", "migrate", "keycloak-config", "initialize", "purge-caches")

_cache = {}


def docs(case):
    """All documents of the case (a list of dicts)."""
    if case not in _cache:
        lines = (RENDER / f"{case}.ndjson").read_text().splitlines()
        _cache[case] = [d for d in (json.loads(line) for line in lines if line.strip()) if d]
    return _cache[case]


def of_kind(case, *kinds):
    return [d for d in docs(case) if d["kind"] in kinds]


def name(doc):
    return doc["metadata"]["name"]


def logical(doc):
    """The logical name of a resource (label app.kubernetes.io/name): `migrate` for the Job `migrate-3fa9c1d2`."""
    return doc["metadata"]["labels"]["app.kubernetes.io/name"]


def wave(doc):
    return int(doc["metadata"].get("annotations", {}).get(WAVE, "0"))


def by_logical(case, *kinds):
    return {logical(d): d for d in of_kind(case, *kinds)}


def pod_spec(doc):
    if doc["kind"] == "CronJob":
        return doc["spec"]["jobTemplate"]["spec"]["template"]["spec"]
    return doc["spec"]["template"]["spec"]


def containers(doc):
    return pod_spec(doc)["containers"]


def waits_for(doc):
    """The logical names the pod waits for: its initContainers named wait-for-<name>."""
    return [c["name"][len("wait-for-"):] for c in pod_spec(doc).get("initContainers", []) if c["name"].startswith("wait-for-")]


def env_of(container):
    return {e["name"]: e.get("value") for e in container.get("env", [])}


def init_env_keys():
    """The secret keys of infra/scripts/init-env.sh: the `secrets` and `appendable` lists."""
    text = (REPO / "infra/scripts/init-env.sh").read_text()
    keys = []
    for variable in ("secrets", "appendable"):
        match = re.search(rf"^{variable}='([^']*)'", text, re.M)
        assert match, f"init-env.sh has no {variable} list"
        keys += [line.split()[0] for line in match.group(1).splitlines() if line.strip()]
    return keys


def quantity_bytes(value):
    units = {"Ki": 2**10, "Mi": 2**20, "Gi": 2**30, "Ti": 2**40, "k": 10**3, "M": 10**6, "G": 10**9}
    match = re.fullmatch(r"(\d+(?:\.\d+)?)([A-Za-z]*)", str(value))
    assert match, f"bad quantity {value}"
    return float(match.group(1)) * (units[match.group(2)] if match.group(2) else 1)


def quantity_cpu(value):
    value = str(value)
    return float(value[:-1]) / 1000 if value.endswith("m") else float(value)

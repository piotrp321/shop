"""make -C infra/k8s generate: scripts/generate.sh (docs/features/demo-scale/spec.md, "Cel make -C infra/k8s generate") against a stub
of docker that plays the cluster for kubectl (the record demo-data-state, the CronJobs demo-data and load, the Jobs, the workloads,
the node, the rule shop-closed, Traefik, the Secret of the photo pool and the applications of Argo CD, all in a state file), curl
of the probe (the public addresses of the shop) and the python of tools.sh. date and sleep share a clock in a file, so the waits
take no time. Every change in the cluster is an event of the state, in the order it happened, so the tests see which write of the
record came before which change."""

import base64
import json
import os
import pathlib
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/scripts/generate.sh"
API_URL = "https://api.worf.pl/"
STORE_URL = "https://worf.pl/"
PROBE_URLS = f"{API_URL} {STORE_URL}"
AUTOMATED = {"prune": True, "selfHeal": True}
START = 1795000000  # 2026-11-19T...; the clock of date and sleep
GB = 10**9
READ_ACCESS = {
    "DEMO_PHOTOS_ENDPOINT": "https://endpoint-7f3a1c.r2.example.com",
    "DEMO_PHOTOS_BUCKET": "bucket-3c4d9e-photos",
    "DEMO_PHOTOS_ACCESS_KEY_ID": "readkeyid5e6f7a8b9c",
    "DEMO_PHOTOS_SECRET_ACCESS_KEY": "readsecret9c0d1e2f3a4b5c6d",
}
WRITE_ACCESS = {
    "DEMO_PHOTOS_WRITE_ACCESS_KEY_ID": "writekeyid1234abcd",
    "DEMO_PHOTOS_WRITE_SECRET_ACCESS_KEY": "writesecret5678efgh",
}
SUMMARY = [
    "==== demo-data summary",
    "exit code: 0",
    "reason: bin/generate-demo --profile=medium --anchor=2026-11-19T00:00:00Z --force-reset ended with 0",
    "command: bin/generate-demo --profile=medium --anchor=2026-11-19T00:00:00Z --force-reset",
    "generator exit code: 0",
    "last finished step: the generated data is consistent",
    "step: 30 categories",
    "step: 3600 products",
    "total seconds: 3630",
    "lowest free space: 140.00 GB (136718750 KiB)",
    "floor of the free space: 32.00 GB (31250000 KiB)",
    "readings of the free space: 121",
    "==== end of the demo-data summary",
]


def generator_log(summary=SUMMARY, first=None):
    lines = ["2026-11-19T10:00:00.000000000Z demo-data: free space of /: 175.00 GB (170898437 KiB), the floor is 32.00 GB (reading 1)",
             "2026-11-19T10:00:30.000000000Z  [OK] categories: 10 written, 0 already there"]
    if first:
        lines += [f"2026-11-19T10:30:00.000000000Z {line}" for line in first]
    lines += [f"2026-11-19T11:00:30.000000000Z {line}" for line in summary]
    return "\n".join(lines) + "\n"


# The stub of docker: `docker run ... <image> <arguments>`. The python image runs python itself; the curl image answers the probe;
# the kubectl image plays the cluster of $STUB_DIR/state.json. Every call of kubectl and curl is a JSON line in $STUB_LOG
# ({"image", "args", "stdin"}).
DOCKER = r'''#!/usr/bin/env python3
import json, os, sys

args = sys.argv[1:]
assert args[0] == "run", args
i = 1
while args[i].startswith("-"):
    i += 2 if args[i] in ("-u", "-e", "-v", "-w", "--network", "--entrypoint") else 1
image, rest = args[i], args[i + 1:]
if image.startswith("python:"):
    os.execvp(rest[0], rest)

root = os.environ["STUB_DIR"]
state_path = os.path.join(root, "state.json")
with open(state_path) as f:
    state = json.load(f)
with open(os.path.join(root, "clock")) as f:
    clock = int(f.read())
stdin = sys.stdin.read() if "-f" in rest and "-" in rest else ""
with open(os.environ["STUB_LOG"], "a") as f:
    f.write(json.dumps({"image": image.split(":")[0], "args": rest, "stdin": stdin}) + "\n")


def save():
    with open(state_path, "w") as f:
        json.dump(state, f)


def fail(message, code=1):
    sys.stderr.write(message + "\n")
    sys.exit(code)


def option(name, default=None):
    for index, arg in enumerate(rest):
        if arg == name and index + 1 < len(rest):
            return rest[index + 1]
        if arg.startswith(name + "="):
            return arg.split("=", 1)[1]
    return default


def bump(by="another copy"):
    """A second copy of the target writes the record: a new version."""
    record = state["record"] or {"data": {"data": "seed", "phase": "idle"}, "metadata": {}}
    record["data"] = dict(record["data"], phase="closing", target="by " + by)
    record["version"] = state["next_version"]
    state["next_version"] += 1
    state["record"] = record


def event(text):
    state["events"].append(text)
    interfere = state.get("interfere_after")
    if interfere and text.startswith(interfere):
        state["interfere_after"] = None
        bump()


def closed():
    return state["netpol"] is not None and state["traefik_restart_seq"] > state["netpol_seq"]


def job_state(name):
    job = state["jobs"][name]
    if job["outcome"] != "Running" and clock >= job["created_at"] + job["duration"]:
        return job["outcome"]
    return "Running"


def job_doc(name):
    job = state["jobs"][name]
    now = job_state(name)
    conditions = [] if now == "Running" else [{"type": now, "status": "True", "reason": job.get("reason", ""), "message": job.get("reason_message", "")}]
    return {"apiVersion": "batch/v1", "kind": "Job", "metadata": {"name": name, "labels": job["labels"]},
            "spec": {"activeDeadlineSeconds": job["deadline"]}, "status": {"conditions": conditions}}


if image.startswith("curlimages/curl"):
    url = rest[-1]
    spec = state["probes"].get(url, "auto")
    if isinstance(spec, list):
        n = state["probe_reads"].get(url, 0)
        state["probe_reads"][url] = n + 1
        save()
        code = spec[min(n, len(spec) - 1)]
    elif spec == "auto":
        code = "502" if closed() else "200"
    else:
        code = spec
    sys.stdout.write(code)
    sys.exit(7 if code == "000" else 0)

if rest[:1] == ["-n"]:
    namespace, rest = rest[1], rest[2:]
else:
    namespace = None
if state.get("broken"):
    fail("The connection to the server was refused")
verb = rest[0]

if verb == "config":
    print("https://203.0.113.7:6443", end="")
elif verb == "get" and rest[1] == "--raw":
    node = rest[2].split("/")[4]
    value = state["nodes"][node]
    if value == "fail":
        fail('Error from server (ServiceUnavailable): the server is currently unable to handle the request')
    print(json.dumps({"node": {"nodeName": node, "fs": {"availableBytes": value, "capacityBytes": 207000000000}}}))
elif verb == "get":
    kind = rest[1]
    names = [a for a in rest[2:] if not a.startswith("-") and a not in (option("-o"), option("-l"))]
    output = option("-o")
    if kind == "crd":
        print("customresourcedefinition.apiextensions.k8s.io/applications.argoproj.io" if state["argocd"] else "")
    elif kind == "application":
        app = state["apps"].get(names[0]) if state["argocd"] else None
        if app is None:
            if "--ignore-not-found" not in rest:
                fail(f'Error from server (NotFound): applications.argoproj.io "{names[0]}" not found')
            print("")
        else:
            doc = {"metadata": {"name": names[0]}, "spec": {"syncPolicy": {"automated": app["automated"]} if app["automated"] is not None else {}},
                   "status": {"operationState": {"phase": app.get("phase", "Succeeded")}}}
            if app.get("operation"):
                doc["operation"] = {"sync": {}}
            print(json.dumps(doc))
    elif kind == "configmap":
        record = state["record"]
        if output == "json":
            state["record_reads"] += 1
            if record is not None:
                print(json.dumps({"apiVersion": "v1", "kind": "ConfigMap",
                                  "metadata": dict(record.get("metadata", {}), name="demo-data-state", namespace="shop", resourceVersion=str(record["version"])),
                                  "data": record["data"]}))
            if state.get("interfere_on_read") == state["record_reads"]:
                bump()
            save()
        else:
            # -o jsonpath={.metadata.resourceVersion}
            print("" if record is None else str(record["version"]), end="")
    elif kind == "cronjob":
        cron = state["cronjobs"].get(names[0])
        if cron is None:
            print("")
        else:
            env = [{"name": key, "valueFrom": {"secretKeyRef": {"name": cron["photos"], "key": key, "optional": True}}}
                   for key in ("DEMO_PHOTOS_ENDPOINT", "DEMO_PHOTOS_BUCKET", "DEMO_PHOTOS_ACCESS_KEY_ID", "DEMO_PHOTOS_SECRET_ACCESS_KEY")] if cron.get("photos") else []
            env = [{"name": "APP_ENV", "value": "prod"}] + env
            print(json.dumps({"metadata": {"name": names[0]}, "spec": {"suspend": cron["suspend"], "jobTemplate": {"spec": {
                "activeDeadlineSeconds": cron["deadline"],
                "template": {"spec": {"containers": [{"name": cron["container"], "image": cron["image"], "command": cron["command"], "env": env}]}}}}}}))
    elif kind in ("jobs", "job"):
        if names:
            if names[0] not in state["jobs"]:
                fail(f'Error from server (NotFound): jobs.batch "{names[0]}" not found')
            doc = job_doc(names[0])
            if output == "json":
                print(json.dumps(doc))
            elif "activeDeadlineSeconds" in output:
                print(doc["spec"]["activeDeadlineSeconds"], end="")
            else:
                print(" ".join(c["type"] for c in doc["status"]["conditions"] if c["status"] == "True"), end="")
        else:
            print(json.dumps({"items": [job_doc(n) for n in state["jobs"]]}))
    elif kind in ("deployments", "statefulsets"):
        items = []
        for name, w in state[kind].items():
            items.append({"metadata": {"name": name, "generation": 2}, "spec": {"replicas": w["replicas"]},
                          "status": {"observedGeneration": 2, "replicas": w["replicas"], "readyReplicas": w["ready"], "updatedReplicas": w["ready"],
                                     "availableReplicas": w["ready"]}})
        print(json.dumps({"items": items}))
    elif kind == "nodes":
        print(json.dumps({"items": [{"metadata": {"name": n}} for n in state["nodes"]]}))
    elif kind == "networkpolicy":
        print("networkpolicy.networking.k8s.io/shop-closed" if state["netpol"] is not None else "", end="")
    elif kind == "pods":
        selector = option("-l")
        if namespace == "kube-system":
            ready = state["traefik_not_ready_reads"] <= 0
            state["traefik_not_ready_reads"] -= 1
            save()
            pods = [] if state.get("no_traefik") else [{"metadata": {"name": "traefik-5d8c-x"}, "status": {"phase": "Running", "conditions": [{"type": "Ready", "status": "True" if ready else "False"}]}}]
            print(json.dumps({"items": pods}))
        elif selector == "app.kubernetes.io/name=api":
            print(json.dumps({"items": [{"metadata": {"name": p}, "status": {"phase": "Running"}} for p in state["api_pods"]]}))
        else:
            job = selector.split("=", 1)[1]
            pod = state["jobs"].get(job, {}).get("pod")
            if pod is None:
                print(json.dumps({"items": []}))
            else:
                now = job_state(job)
                status = {"running": {}} if now == "Running" else {"terminated": {"exitCode": pod["exit"], "message": pod["message"]}}
                print(json.dumps({"items": [{"metadata": {"name": job + "-x7k2p"}, "status": {"containerStatuses": [{"name": pod["container"], "state": status}]}}]}))
    else:
        fail("unknown kind " + kind)
elif verb == "create" and rest[1] == "job":
    name, source = rest[2], option("--from").split("/", 1)[1]
    cron = state["cronjobs"].get(source)
    if cron is None:
        fail(f'Error from server (NotFound): cronjobs.batch "{source}" not found')
    print(json.dumps({"apiVersion": "batch/v1", "kind": "Job", "metadata": {"name": name, "labels": {"app.kubernetes.io/name": source}},
                      "spec": {"activeDeadlineSeconds": cron["deadline"], "template": {"spec": {"containers": [{"name": cron["container"], "env": [{"name": "APP_ENV", "value": "prod"}]}]}}}}))
elif verb == "set":
    doc = json.loads(stdin)
    changed = False
    for pair in [a for a in rest if "=" in a and not a.startswith("-")]:
        key, value = pair.split("=", 1)
        for container in doc["spec"]["template"]["spec"]["containers"]:
            changed = changed or {"name": key, "value": value} not in container["env"]
            container["env"] = [e for e in container["env"] if e["name"] != key] + [{"name": key, "value": value}]
    if changed:
        print(json.dumps(doc))
elif verb in ("create", "replace"):
    doc = json.loads(stdin)
    kind, name = doc["kind"], doc["metadata"]["name"]
    if kind == "ConfigMap":
        record = state["record"]
        if verb == "create" and record is not None:
            fail(f'Error from server (AlreadyExists): configmaps "{name}" already exists')
        if verb == "replace":
            if record is None:
                fail(f'Error from server (NotFound): configmaps "{name}" not found')
            if doc["metadata"].get("resourceVersion") != str(record["version"]):
                fail(f'Error from server (Conflict): Operation cannot be fulfilled on configmaps "{name}": the object has been modified; please apply your changes to the latest version and try again')
        state["record"] = {"data": doc.get("data", {}), "version": state["next_version"], "metadata": doc["metadata"]}
        state["next_version"] += 1
        state["records"].append(doc.get("data", {}))
        event("record " + doc.get("data", {}).get("phase", "?"))
        save()
        print(state["record"]["version"], end="")
    elif kind == "Secret":
        if name in state["secrets"]:
            fail(f'Error from server (AlreadyExists): secrets "{name}" already exists')
        state["secrets"][name] = doc
        event("create secret " + name)
        save()
    elif kind == "NetworkPolicy":
        if state["netpol"] is not None:
            fail(f'Error from server (AlreadyExists): networkpolicies.networking.k8s.io "{name}" already exists')
        state["netpol"] = doc
        event("create networkpolicy " + name)
        state["netpol_seq"] = len(state["events"])
        save()
    elif kind == "Job":
        if name in state["jobs"]:
            fail(f'Error from server (AlreadyExists): jobs.batch "{name}" already exists')
        outcome = next((o for prefix, o in state["outcomes"].items() if name.startswith(prefix)), {"outcome": "Complete", "duration": 60})
        if outcome.get("create_fails"):
            fail("Error from server (Forbidden): jobs.batch is forbidden: exceeded quota")
        env = {e["name"]: e.get("value") for e in doc["spec"]["template"]["spec"]["containers"][0]["env"]}
        state["jobs"][name] = {"labels": doc["metadata"].get("labels", {}), "env": env, "deadline": doc["spec"].get("activeDeadlineSeconds", 1800),
                               "created_at": clock, "duration": outcome.get("duration", 60), "outcome": outcome.get("outcome", "Complete"),
                               "reason": outcome.get("reason", ""), "reason_message": outcome.get("reason_message", ""),
                               "pod": outcome.get("pod", {"container": doc["spec"]["template"]["spec"]["containers"][0]["name"], "exit": 0, "message": ""}),
                               "log": outcome.get("log", "")}
        event("create job " + name)
        save()
    else:
        fail("unknown kind " + kind)
elif verb == "patch":
    kind, name, patch = rest[1], rest[2], json.loads(option("-p"))
    assert kind == "application" and option("--type") == "merge", rest
    state["apps"][name]["automated"] = patch["spec"]["syncPolicy"]["automated"]
    event("patch " + name + " " + json.dumps(patch["spec"]["syncPolicy"]["automated"], sort_keys=True))
    after = state.get("after_patch", {}).get(name)
    if after:
        state["apps"][after["app"]].update(after["set"])
    save()
elif verb == "delete":
    kind = rest[1]
    if kind == "networkpolicy":
        state["netpol"] = None
        event("delete networkpolicy " + rest[2])
    elif kind == "secret":
        state["secrets"].pop(rest[2], None)
        event("delete secret " + rest[2])
    elif kind == "pods":
        assert namespace == "kube-system" and option("-l") == "app.kubernetes.io/name=traefik", rest
        event("delete pods traefik")
        state["traefik_restart_seq"] = len(state["events"])
        state["traefik_not_ready_reads"] = state.get("traefik_not_ready_after_restart", 0)
    else:
        event("delete " + " ".join(rest[1:3]))
    save()
elif verb == "exec":
    target = rest[1]
    command = rest[rest.index("--") + 1:]
    if target == "statefulset/postgres":
        if state["pg"].get("fail"):
            fail("error: unable to upgrade connection: container not found")
        sql = " ".join(command)
        if "pg_database_size" in sql:
            print("stub sizes of postgres")
        elif "reltuples" in sql:
            print(state["pg"]["estimates"])
        elif "indisvalid" in sql:
            print(state["pg"]["invalid"], end="")
        elif "count(*)" in sql:
            print(state["pg"]["counts"])
        else:
            fail("unknown sql " + sql)
    elif target == "statefulset/elasticsearch":
        if state["es"].get("fail"):
            fail("error: container not found")
        urls = [c for c in command if c.startswith("http")]
        if any("_cat/indices?v" in u for u in urls):
            print("stub indices of elasticsearch")
        elif "_cluster/health" in urls[0]:
            print(json.dumps({"status": state["es"]["health"]}))
        elif "_cat/aliases" in urls[0]:
            print(json.dumps([{"alias": "products", "index": i} for i in state["es"]["alias"]]))
        elif "_cat/indices" in urls[0]:
            print(json.dumps([{"index": i, "docs.count": str(n)} for i, n in state["es"]["indices"].items()]))
        else:
            fail("unknown url " + urls[0])
    else:
        assert command == ["bin/console", "cache:pool:clear", "cache.sitemap"], command
        event("exec " + target + " cache:pool:clear cache.sitemap")
        save()
        if target in state.get("cache_clear_fails", []):
            fail("command terminated with exit code 1")
elif verb == "logs":
    name = rest[1].split("/", 1)[1]
    job = state["jobs"].get(name)
    if job is None or job.get("pod") is None:
        fail(f'error: no pods found for job "{name}"' if job else f'Error from server (NotFound): jobs.batch "{name}" not found')
    print(job["log"], end="")
else:
    fail("unknown kubectl call " + " ".join(rest))
'''

DATE = r'''#!/usr/bin/env python3
import os, sys, time
with open(os.path.join(os.environ["STUB_DIR"], "clock")) as f:
    clock = int(f.read())
formats = [a[1:] for a in sys.argv[1:] if a.startswith("+")]
print(clock if formats in ([], ["%s"]) and formats else time.strftime(formats[0] if formats else "%a %b %d %H:%M:%S UTC %Y", time.gmtime(clock)))
'''

SLEEP = r'''#!/bin/sh
clock="$(cat "$STUB_DIR/clock")"
echo $((clock + ${1%.*})) > "$STUB_DIR/clock"
'''


def demo_data_cronjob(profile="medium", photos="demo-photos", min_free=32, deadline=21600, anchor="2026-11-19T00:00:00Z"):
    command = ["sh", "/scripts/demo-data.sh", f"--profile={profile}"] + ([f"--anchor={anchor}"] if anchor else []) + [f"--min-free-gb={min_free}"]
    return {"suspend": True, "deadline": deadline, "container": "demo-data", "command": command, "photos": photos,
            "image": "ghcr.io/piotrp321/shop/api:0123456789abcdef0123456789abcdef01234567@sha256:" + "a" * 64}


def initial_state(argocd=True, record=None, profile="medium", **changes):
    state = {
        "argocd": argocd,
        "apps": {"root": {"automated": dict(AUTOMATED)}, "shop": {"automated": dict(AUTOMATED)}} if argocd else {},
        "record": record,
        "next_version": 100,
        "record_reads": 0,
        "records": [],
        "cronjobs": {
            "demo-data": demo_data_cronjob(profile),
            "load": {"suspend": True, "deadline": 900, "container": "load", "command": None, "photos": None, "image": "grafana/k6"},
        },
        "jobs": {
            "migrate-2b": {"labels": {"app.kubernetes.io/name": "migrate"}, "env": {}, "deadline": 1800, "created_at": 0, "duration": 0,
                           "outcome": "Complete", "pod": None, "log": ""},
        },
        "outcomes": {"demo-data-": {"outcome": "Complete", "duration": 3700, "pod": {"container": "demo-data", "exit": 0, "message": "exit 0: done"},
                                    "log": generator_log()},
                     "load-": {"outcome": "Complete", "duration": 120, "log": "     checks.........................: 100.00% 42 out of 42\n"}},
        "deployments": {name: {"replicas": 1, "ready": 1} for name in ("api", "worker", "store", "admin", "imgproxy", "varnish")},
        "statefulsets": {name: {"replicas": 1, "ready": 1} for name in ("postgres", "elasticsearch", "keycloak", "redis", "rabbitmq", "garage")},
        "nodes": {"server-0": 175 * GB},
        "netpol": None,
        "netpol_seq": 0,
        "traefik_restart_seq": 0,
        "traefik_not_ready_reads": 0,
        "secrets": {},
        "probes": {API_URL: "auto", STORE_URL: "auto"},
        "probe_reads": {},
        "api_pods": ["api-7c9f-a", "api-7c9f-b"],
        "pg": {"estimates": "191|0", "invalid": "", "counts": "1000191|1000001"},
        "es": {"health": "green", "alias": ["products_20261119110000000_a1b2c3"], "indices": {"products_20261119110000000_a1b2c3": 1000191}},
        "events": [],
    }
    state.update(changes)
    return state


def record(data="seed", phase="idle", **more):
    return {"data": {"data": data, "phase": phase, **more}, "version": 7, "metadata": {}}


class Cluster:
    def __init__(self, tmp, state):
        self.root = pathlib.Path(tmp)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        for name, text in (("docker", DOCKER), ("date", DATE), ("sleep", SLEEP)):
            (self.bin / name).write_text(text)
            (self.bin / name).chmod(0o755)
        (self.root / "clock").write_text(str(START))
        self.kube = self.root / "kube"
        self.kube.mkdir()
        (self.kube / "config").write_text("apiVersion: v1\n")
        self.out = self.root / "out"
        self.out.mkdir()
        self.home = self.root / "home"
        (self.home / ".config/shop").mkdir(parents=True)
        self.photos = self.home / ".config/shop/demo-photos.env"
        self.photos.write_text("".join(f"{k}={v}\n" for k, v in {**READ_ACCESS, **WRITE_ACCESS}.items()))
        self.log = self.root / "log"
        self.log.write_text("")
        (self.root / "state.json").write_text(json.dumps(state))

    def run(self, answer="tak\n", shell_options=(), **env):
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": str(self.home),
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.root),
            "TOOLS_CACHE": str(self.root / "cache"),
            "KUBE_DIR": str(self.kube),
            "GENERATE_OUT": str(self.out),
            "GENERATE_PROBE_URL": PROBE_URLS,
            "PROFILE": "medium",
            "REPLACE": "seed",
        }
        variables.update(env)
        variables = {k: v for k, v in variables.items() if v is not None}
        return subprocess.run(["sh", *shell_options, str(SCRIPT)], input=answer, env=variables, capture_output=True, text=True, timeout=600)

    def state(self):
        return json.loads((self.root / "state.json").read_text())

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def kubectl_calls(self):
        return [c["args"] for c in self.calls() if c["image"] != "curlimages/curl"]

    def events(self):
        return self.state()["events"]

    def clock(self):
        return int((self.root / "clock").read_text())


class GenerateCase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.tmp.cleanup()

    def cluster(self, **changes):
        return Cluster(tempfile.mkdtemp(dir=self.tmp.name), initial_state(**changes))

    def assert_unchanged(self, cluster, result, code=1):
        """The refusal: the exit code, a message, and not one change in the cluster."""
        self.assertEqual(code, result.returncode, result.stdout + result.stderr)
        self.assertIn("generate:", result.stderr)
        self.assertEqual([], cluster.events(), result.stderr)
        for call in cluster.kubectl_calls():
            verb = call[2] if call[:1] == ["-n"] else call[0]
            self.assertIn(verb, ("get", "config", "exec"), call)
            if verb == "exec":
                self.assertTrue(any(target in call for target in ("statefulset/postgres",)), call)
        self.assertEqual([], [p.name for p in cluster.out.iterdir()])


class Arguments(GenerateCase):
    def test_a_missing_or_malformed_profile_is_exit_2_before_the_cluster(self):
        for profile in (None, "", "Medium", "medium;rm", "../x", "med ium"):
            with self.subTest(profile=profile):
                cluster = self.cluster()
                result = cluster.run(PROFILE=profile)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("PROFILE", result.stderr)
                self.assertEqual([], cluster.calls())

    def test_the_kubeconfig_is_required(self):
        for kube_dir in (None, "", "/nonexistent-kube"):
            with self.subTest(kube_dir=kube_dir):
                cluster = self.cluster()
                result = cluster.run(KUBE_DIR=kube_dir)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("KUBE_DIR", result.stderr)
                self.assertEqual([], cluster.calls())

    def test_generate_out_must_be_an_existing_writable_directory_outside_the_repository(self):
        cluster = self.cluster()
        locked = cluster.root / "locked"
        locked.mkdir()
        locked.chmod(0o500)
        for out in (None, "", "relative/dir", str(cluster.root / "missing"), str(REPO / "infra/k8s"), str(REPO), str(locked)):
            with self.subTest(out=out):
                result = cluster.run(GENERATE_OUT=out)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("GENERATE_OUT", result.stderr)
                self.assertEqual([], cluster.calls())
        self.assertEqual([], list(locked.iterdir()))

    def test_the_probe_needs_addresses_and_one_host_per_address(self):
        for urls, hosts in ((None, None), ("", None), ("ftp://worf.pl/", None), ("worf.pl", None), (PROBE_URLS, "api.localhost"),
                            (PROBE_URLS, "api.localhost localhost extra"), (API_URL, "bad/host")):
            with self.subTest(urls=urls, hosts=hosts):
                cluster = self.cluster()
                result = cluster.run(GENERATE_PROBE_URL=urls, GENERATE_PROBE_HOST=hosts)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("GENERATE_PROBE", result.stderr)
                self.assertEqual([], cluster.calls())

    def test_a_number_setting_that_is_not_a_whole_number_is_exit_2(self):
        for name in ("GENERATE_CLOSE_WAIT", "GENERATE_POLL"):
            with self.subTest(name=name):
                cluster = self.cluster()
                result = cluster.run(**{name: "2m"})
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn(name, result.stderr)
                self.assertEqual([], cluster.calls())

    def test_a_missing_replace_is_exit_2_without_changes(self):
        for replace in (None, ""):
            with self.subTest(replace=replace):
                cluster = self.cluster()
                result = cluster.run(REPLACE=replace)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("REPLACE", result.stderr)
                self.assertEqual([], cluster.events())


class Refusals(GenerateCase):
    def test_replace_other_than_the_data_of_the_record_is_refused_with_the_data_and_the_estimates(self):
        for rec, replace, data in ((None, "medium", "seed"), (record("medium"), "seed", "medium"), (record("failed", reason="x"), "seed", "failed"),
                                   (record("seed"), "SEED", "seed")):
            with self.subTest(record=rec, replace=replace):
                cluster = self.cluster(record=rec, pg={"estimates": "1000191|1000001", "invalid": "", "counts": ""},
                                       netpol={"kind": "NetworkPolicy"} if data == "failed" else None)
                result = cluster.run(REPLACE=replace)
                self.assert_unchanged(cluster, result)
                self.assertIn(f"REPLACE={replace}", result.stderr)
                self.assertIn(f"data {data}", result.stderr)
                self.assertIn("1000191 products", result.stderr)
                self.assertIn("1000001 orders", result.stderr)
                self.assertNotIn("type tak", result.stdout)

    def test_unknown_estimates_do_not_stop_the_refusal(self):
        cluster = self.cluster(pg={"estimates": "-1|", "invalid": "", "counts": "", "fail": False})
        result = cluster.run(REPLACE="medium")
        self.assert_unchanged(cluster, result)
        self.assertIn("unknown", result.stderr)

    def test_a_profile_other_than_the_one_of_the_cronjob_or_seed_is_refused(self):
        for profile in ("server", "small", "full"):
            with self.subTest(profile=profile):
                cluster = self.cluster()
                result = cluster.run(PROFILE=profile)
                self.assert_unchanged(cluster, result)
                self.assertIn("medium", result.stderr)
                self.assertIn("demoData.profile", result.stderr)

    def test_a_missing_or_running_cronjob_is_refused(self):
        cronjobs = initial_state()["cronjobs"]
        missing = {"load": cronjobs["load"]}
        running = {**cronjobs, "demo-data": {**cronjobs["demo-data"], "suspend": False}}
        for crons, words in ((missing, "no CronJob demo-data"), (running, "not suspended")):
            with self.subTest(words=words):
                cluster = self.cluster(cronjobs=crons)
                result = cluster.run()
                self.assert_unchanged(cluster, result)
                self.assertIn(words, result.stderr)

    def test_a_cronjob_without_the_floor_of_the_free_space_is_refused(self):
        cronjobs = initial_state()["cronjobs"]
        cronjobs["demo-data"]["command"] = ["sh", "/scripts/demo-data.sh", "--profile=medium"]
        cluster = self.cluster(cronjobs=cronjobs)
        result = cluster.run()
        self.assert_unchanged(cluster, result)
        self.assertIn("--min-free-gb", result.stderr)

    def test_an_active_job_is_refused(self):
        jobs = initial_state()["jobs"]
        jobs["cart-purge-expired-2934"] = {"labels": {}, "env": {}, "deadline": 600, "created_at": START, "duration": 10**6,
                                           "outcome": "Complete", "pod": None, "log": ""}
        cluster = self.cluster(jobs=jobs)
        result = cluster.run()
        self.assert_unchanged(cluster, result)
        self.assertIn("cart-purge-expired-2934", result.stderr)

    def test_a_synchronization_of_root_or_shop_in_progress_is_refused(self):
        for app in ("root", "shop"):
            for running in ({"operation": True}, {"phase": "Running"}, {"phase": "Terminating"}):
                with self.subTest(app=app, running=running):
                    apps = {"root": {"automated": dict(AUTOMATED)}, "shop": {"automated": dict(AUTOMATED)}}
                    apps[app].update(running)
                    cluster = self.cluster(apps=apps)
                    result = cluster.run()
                    self.assert_unchanged(cluster, result)
                    self.assertIn(f"synchronizing the application {app}", result.stderr)

    def test_argo_cd_without_the_application_root_is_refused(self):
        cluster = self.cluster(apps={"shop": {"automated": dict(AUTOMATED)}})
        result = cluster.run()
        self.assert_unchanged(cluster, result)
        self.assertIn("root", result.stderr)

    def test_a_workload_that_is_not_ready_is_refused_by_its_name(self):
        for kind, name in (("deployments", "worker"), ("statefulsets", "elasticsearch")):
            with self.subTest(kind=kind):
                workloads = initial_state()[kind]
                workloads[name] = {"replicas": 1, "ready": 0}
                cluster = self.cluster(**{kind: workloads})
                result = cluster.run()
                self.assert_unchanged(cluster, result)
                self.assertIn(name, result.stderr)
                self.assertIn("not ready", result.stderr)

    def test_the_file_of_the_photo_pool_is_required_for_a_profile_with_the_secret(self):
        for content in (None, "DEMO_PHOTOS_ENDPOINT=x\n", "".join(f"{k}={v}\n" for k, v in WRITE_ACCESS.items())):
            with self.subTest(content=content):
                cluster = self.cluster()
                if content is None:
                    cluster.photos.unlink()
                else:
                    cluster.photos.write_text(content)
                result = cluster.run()
                self.assert_unchanged(cluster, result, code=2)
                self.assertIn("demo-photos.env", result.stderr)
                self.assertNotIn("type tak", result.stdout)

    def test_the_file_of_the_photo_pool_is_not_needed_for_the_seed_or_a_cronjob_without_the_secret(self):
        cronjobs = initial_state()["cronjobs"]
        cronjobs["demo-data"]["photos"] = None
        for profile, crons in (("seed", None), ("medium", cronjobs)):
            with self.subTest(profile=profile):
                cluster = self.cluster(**({"cronjobs": crons} if crons else {}))
                cluster.photos.unlink()
                result = cluster.run(PROFILE=profile, answer="nie\n")
                self.assertIn("type tak", result.stdout, result.stderr)
                self.assert_unchanged(cluster, result)
                self.assertIn("not confirmed", result.stderr)

    def test_too_little_free_space_is_refused_with_the_needed_and_the_free_space(self):
        # medium: max(minFreeGb 32, 1.25 x 21) = 32 GB; server: max(32, 1.25 x 96) = 120 GB.
        for profile, free, needed in (("medium", 31.9, "32.00"), ("server", 119, "120.00"), ("server", 40, "120.00")):
            with self.subTest(profile=profile, free=free):
                cluster = self.cluster(profile=profile, nodes={"server-0": int(free * GB)})
                result = cluster.run(PROFILE=profile)
                self.assert_unchanged(cluster, result)
                self.assertIn(needed, result.stderr)
                self.assertIn(f"{free:.2f}", result.stderr)

    def test_the_peak_of_the_profile_counts_only_for_medium_and_server(self):
        # small: minFreeGb 2 is enough although 1.25 x diskPeakGb 5 is 6.25 GB.
        cluster = self.cluster(profile="small", cronjobs={**initial_state()["cronjobs"], "demo-data": demo_data_cronjob("small", None, 2, 1800)},
                               nodes={"server-0": 3 * GB})
        result = cluster.run(PROFILE="small", answer="nie\n")
        self.assertIn("type tak", result.stdout, result.stderr)
        self.assertIn("not confirmed", result.stderr)
        cluster = self.cluster(profile="small", cronjobs={**initial_state()["cronjobs"], "demo-data": demo_data_cronjob("small", None, 2, 1800)},
                               nodes={"server-0": int(1.9 * GB)})
        result = cluster.run(PROFILE="small")
        self.assert_unchanged(cluster, result)
        self.assertIn("2.00", result.stderr)

    def test_the_seed_has_no_floor_only_a_reading(self):
        # After a stop by the guard the space stays below minFreeGb; going back to the seed is the repair.
        cluster = self.cluster(record=record("failed", reason="exit 75"), nodes={"server-0": 20 * GB}, netpol={"kind": "NetworkPolicy"},
                               probes={API_URL: "502", STORE_URL: "502"})
        result = cluster.run(PROFILE="seed", REPLACE="failed", answer="nie\n")
        self.assertIn("type tak", result.stdout, result.stderr)
        self.assert_unchanged(cluster, result)

    def test_a_free_space_that_cannot_be_read_is_refused_also_for_the_seed(self):
        for profile in ("seed", "medium"):
            for value in ("fail", "a lot", None, -5):
                with self.subTest(profile=profile, value=value):
                    cluster = self.cluster(nodes={"server-0": value})
                    result = cluster.run(PROFILE=profile)
                    self.assert_unchanged(cluster, result)
                    self.assertIn("free space", result.stderr)

    def test_every_node_must_have_the_room(self):
        cluster = self.cluster(nodes={"server-0": 175 * GB, "agent-1": 10 * GB})
        result = cluster.run()
        self.assert_unchanged(cluster, result)
        self.assertIn("agent-1", result.stderr)

    def test_a_probe_that_does_not_agree_with_the_record_is_refused(self):
        closed = {"kind": "NetworkPolicy"}
        for rec, netpol, probes, words in (
            (None, None, {API_URL: "auto", STORE_URL: "502"}, STORE_URL),
            (None, None, {API_URL: "000", STORE_URL: "auto"}, API_URL),
            (None, None, {API_URL: "auto", STORE_URL: "500"}, STORE_URL),
            (None, closed, {API_URL: "auto", STORE_URL: "auto"}, "shop-closed"),
            (record("failed", reason="x"), None, {API_URL: "502", STORE_URL: "502"}, "shop-closed"),
            (record("failed", reason="x"), closed, {API_URL: "502", STORE_URL: "200"}, STORE_URL),
        ):
            with self.subTest(record=rec, netpol=netpol, probes=probes):
                cluster = self.cluster(record=rec, netpol=netpol, probes=probes)
                result = cluster.run(REPLACE=(rec or record())["data"]["data"])
                self.assert_unchanged(cluster, result)
                self.assertIn(words, result.stderr)

    def test_the_probe_sends_the_host_headers_pairwise(self):
        cluster = self.cluster(probes={"http://k3d-sm-shop-server-0/": "auto"})
        cluster.run(GENERATE_PROBE_URL="http://k3d-sm-shop-server-0/ http://k3d-sm-shop-server-0/", GENERATE_PROBE_HOST="api.localhost localhost",
                    answer="nie\n")
        probes = [c["args"] for c in cluster.calls() if c["image"] == "curlimages/curl"]
        self.assertIn(["-H", "Host: api.localhost"], [p[i:i + 2] for p in probes for i in range(len(p))])
        self.assertIn(["-H", "Host: localhost"], [p[i:i + 2] for p in probes for i in range(len(p))])

    def test_a_phase_other_than_idle_is_refused_until_the_resume_is_there(self):
        for phase in ("closing", "running", "checking", "opening"):
            with self.subTest(phase=phase):
                cluster = self.cluster(record=record("failed", phase, target="medium", rootAutomated=json.dumps(AUTOMATED)),
                                       netpol={"kind": "NetworkPolicy"})
                result = cluster.run(REPLACE="failed")
                self.assert_unchanged(cluster, result)
                self.assertIn(f"phase {phase}", result.stderr)
                self.assertIn("resum", result.stderr)

    def test_a_record_that_cannot_be_read_is_refused(self):
        cluster = self.cluster(broken=True)
        result = cluster.run()
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("demo-data-state", result.stderr)
        self.assertEqual([], cluster.events())

    def test_a_record_with_an_unknown_data_is_refused(self):
        cluster = self.cluster(record=record("med ium"))
        result = cluster.run(REPLACE="med ium")
        self.assertIn(result.returncode, (1, 2), result.stderr)
        self.assertEqual([], cluster.events())


class Confirmation(GenerateCase):
    def test_without_the_word_tak_nothing_changes(self):
        for answer in ("nie\n", "\n", "", "TAK\n", "tak tak\n", " tak\n", "yes\n"):
            with self.subTest(answer=answer):
                cluster = self.cluster()
                result = cluster.run(answer=answer)
                self.assert_unchanged(cluster, result)
                self.assertIn("not confirmed", result.stderr)

    def test_it_says_what_it_will_do_before_it_asks(self):
        cluster = self.cluster(pg={"estimates": "191|0", "invalid": "", "counts": ""})
        result = cluster.run(answer="nie\n")
        before = result.stdout.split("type tak")[0]
        for part in ("https://203.0.113.7:6443", "data seed", "191 products", "0 orders", "medium", "closed", "api.", "admin.", "auth.",
                     "img.", "Argo CD", "root", "shop", "demo-photos", "0123456789ab"):
            self.assertIn(part, before)

    def test_the_seed_says_it_needs_no_photo_pool(self):
        cluster = self.cluster(record=record("medium"))
        result = cluster.run(PROFILE="seed", REPLACE="medium", answer="nie\n")
        before = result.stdout.split("type tak")[0]
        self.assertIn("bin/seed-demo", before)
        self.assertIn("data medium", before)


if __name__ == "__main__":
    unittest.main()

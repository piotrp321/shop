"""make -C infra/k8s generate and make -C infra/k8s open: scripts/generate.sh and scripts/open.sh (docs/features/demo-scale/spec.md)
against a stub of docker that plays the cluster for kubectl (the CronJobs demo-data and load, the Jobs, the workloads, the node, the rule
shop-closed, Traefik, the Secret of the photo pool, Postgres and Elasticsearch, all in a state file), curl of the probe and the python
of tools.sh. date and sleep share a clock in a file, so the waits take no time. Every change in the cluster is an event of the state,
except those of the lock demo-data-lock: the ConfigMap is state["lock"], and its creations and deletions are state["lock_events"]
([create|delete, the number of events before it])."""

import base64
import datetime
import json
import os
import pathlib
import subprocess
import tempfile
import unittest

from support import REPO

GENERATE = REPO / "infra/k8s/scripts/generate.sh"
OPEN = REPO / "infra/k8s/scripts/open.sh"
API_URL = "https://api.worf.pl/"
STORE_URL = "https://worf.pl/"
START = 1795000000
GB = 10**9
JOB = "demo-data-run"
READ_ACCESS = {
    "DEMO_PHOTOS_ENDPOINT": "https://endpoint-7f3a1c.r2.example.com",
    "DEMO_PHOTOS_BUCKET": "bucket-3c4d9e-photos",
    "DEMO_PHOTOS_ACCESS_KEY_ID": "readkeyid5e6f7a8b9c",
    "DEMO_PHOTOS_SECRET_ACCESS_KEY": "readsecret9c0d1e2f3a4b5c6d",
}
WRITE_ACCESS = {"DEMO_PHOTOS_WRITE_ACCESS_KEY_ID": "writekeyid1234abcd", "DEMO_PHOTOS_WRITE_SECRET_ACCESS_KEY": "writesecret5678efgh"}

# The stub of docker: `docker run ... <image> <arguments>`. The python image runs python in this process; the curl image answers the
# probe; the kubectl image plays the cluster of $STUB_DIR/state.json. Every call is a JSON line in $STUB_LOG ({"image", "args",
# "stdin"}). state["trigger"] = {"call": n, "action": kill|INT|TERM|fail} acts on the n-th call of kubectl: fail makes it fail without
# any effect (a lost connection); a signal goes to the process group of the target once the call took effect.
# state["inject_at_call"] = {"call": n, "changes": {...}} changes the state right before the n-th call of kubectl and
# state["inject_after"] = {"event", "changes"} right after the event (another process between two calls); state["inject_job_after"] = {"event", "job"[, "by": "generate"]} makes the Job demo-data-run right after the event,
# with "by": "generate" only as another make generate would: never while the lock is there (state["inject_refused"] then).
DOCKER = r'''#!/usr/local/bin/python3 -SI
import json, os, signal, sys

args = sys.argv[1:]
i = 1
while args[i].startswith("-"):
    i += 2 if args[i] in ("-u", "-e", "-v", "-w", "--network", "--entrypoint") else 1
image, rest = args[i], args[i + 1:]
if image.startswith("python:"):
    assert rest[:3] == ["python", "-I", "-c"], rest
    sys.argv = ["-c"] + rest[4:]
    exec(compile(rest[3], "<py>", "exec"), {"__name__": "__main__"})
    sys.exit(0)

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


def fail(message):
    sys.stderr.write(message + "\n")
    sys.exit(1)


def option(name):
    for index, arg in enumerate(rest):
        if arg == name and index + 1 < len(rest):
            return rest[index + 1]
        if arg.startswith(name + "="):
            return arg.split("=", 1)[1]
    return None


def event(text):
    state["events"].append(text)
    # A Job demo-data-run is made right after this event; another make generate would take the lock before any change.
    inject = state.get("inject_job_after")
    if inject and inject["event"] == text:
        if inject.get("by") == "generate" and state.get("lock") is not None:
            state["inject_refused"] = True
        else:
            state["jobs"][JOB] = inject["job"]
        state["inject_job_after"] = None
    inject = state.get("inject_after")
    if inject and inject["event"] == text:
        state.update(inject["changes"])
        state["inject_after"] = None


JOB = "demo-data-run"


def job_state(job):
    if job["outcome"] != "Running" and clock >= job["created_at"] + job["duration"]:
        return job["outcome"]
    return "Running"


def job_doc(name):
    job = state["jobs"][name]
    now = job_state(job)
    meta = {"name": name, "labels": job.get("labels", {})}
    if job.get("owner"):
        meta["ownerReferences"] = [{"apiVersion": "batch/v1", "kind": job.get("owner_kind", "CronJob"), "name": job["owner"], "controller": True}]
    return {"apiVersion": "batch/v1", "kind": "Job", "metadata": meta, "spec": {"activeDeadlineSeconds": job["deadline"]},
            "status": {"conditions": [] if now == "Running" else [{"type": now, "status": "True"}]}}


if image.startswith("curlimages/curl"):
    closed = state["netpol"] is not None and state["traefik_restarts"] > state["netpol_made_at"]
    spec = state["probes"].get(rest[-1], "auto")
    sys.stdout.write(("502" if closed else "200") if spec == "auto" else spec)
    sys.exit(0)

state["kubectl_calls"] += 1
inject = state.get("inject_at_call")
if inject and inject["call"] == state["kubectl_calls"]:
    state.update(inject["changes"])
    state["inject_at_call"] = None
save()
trigger = state.get("trigger") or {}
if trigger.get("call") == state["kubectl_calls"]:
    if trigger["action"] == "fail":
        fail("The connection to the server was refused - did you specify the right host or port?")
    def interrupt(sig=getattr(signal, "SIG" + trigger["action"].upper())):
        sys.stdout.flush()
        if str(os.getpgrp()) == os.environ.get("STUB_RUNNER_PGID"):
            os._exit(99)
        if sig != signal.SIGKILL:
            signal.signal(sig, signal.SIG_IGN)
        os.killpg(os.getpgrp(), sig)
    import atexit
    atexit.register(interrupt)

namespace = None
if rest[:1] == ["-n"]:
    namespace, rest = rest[1], rest[2:]
verb = rest[0]
words = [a for a in rest[2:] if not a.startswith("-") and a not in (option("-o"), option("-l"), option("-p"))]

if verb == "config":
    print("https://203.0.113.7:6443", end="")
elif verb == "get" and rest[1] == "--raw":
    value = state["nodes"][rest[2].split("/")[4]]
    print(json.dumps({"node": {"fs": {"availableBytes": value}}}))
elif verb == "get":
    kind, output = rest[1], option("-o") or ""
    if kind == "cronjob":
        cron = state["cronjobs"].get(words[0])
        if cron is None:
            sys.exit(0)
        env = [{"name": k, "valueFrom": {"secretKeyRef": {"name": cron["photos"], "key": k, "optional": True}}}
               for k in ("DEMO_PHOTOS_ENDPOINT", "DEMO_PHOTOS_BUCKET", "DEMO_PHOTOS_ACCESS_KEY_ID", "DEMO_PHOTOS_SECRET_ACCESS_KEY")] if cron.get("photos") else []
        if output.startswith("jsonpath"):
            print(cron.get("photos") or "", end="")
        else:
            print(json.dumps({"spec": {"suspend": cron["suspend"], "jobTemplate": {"spec": {"activeDeadlineSeconds": cron["deadline"], "template": {
                "spec": {"containers": [{"name": words[0], "command": cron["command"], "env": [{"name": "APP_ENV", "value": "prod"}] + env}]}}}}}}))
    elif kind in ("jobs", "job"):
        if words:
            if words[0] not in state["jobs"]:
                if "--ignore-not-found" in rest:
                    sys.exit(0)
                fail(f'Error from server (NotFound): jobs.batch "{words[0]}" not found')
            print(json.dumps(job_doc(words[0])))
        else:
            print(json.dumps({"items": [job_doc(n) for n in state["jobs"]]}))
    elif kind in ("deployments", "statefulsets"):
        print(json.dumps({"items": [{"metadata": {"name": n, "generation": 1}, "spec": {"replicas": 1},
                                     "status": {"observedGeneration": 1, "readyReplicas": r, "updatedReplicas": r}} for n, r in state[kind].items()]}))
    elif kind == "nodes":
        print(" ".join(state["nodes"]), end="")
    elif kind == "networkpolicy":
        print("networkpolicy.networking.k8s.io/shop-closed" if state["netpol"] is not None else "", end="")
    elif kind == "secret":
        print(f"secret/{words[0]}" if words[0] in state["secrets"] else "", end="")
    elif kind == "configmap":
        lock = state.get("lock")
        if lock is None or lock["metadata"]["name"] != words[0]:
            if "--ignore-not-found" in rest:
                sys.exit(0)
            fail(f'Error from server (NotFound): configmaps "{words[0]}" not found')
        assert output == 'jsonpath={.metadata.name}{"/"}{.data.token}{"/"}{.data.holder}', output
        data = lock.get("data") or {}
        print(f'{lock["metadata"]["name"]}/{data.get("token", "")}/{data.get("holder", "")}', end="")
    elif kind == "pods":
        selector = option("-l")
        if selector == "app.kubernetes.io/name=api":
            print(" ".join(state["api_pods"]), end="")
        else:
            job = state["jobs"].get(selector.split("=", 1)[1])
            print(job.get("message", "") if job and job_state(job) != "Running" else "", end="")
    else:
        fail("unknown kind " + kind)
elif verb == "create" and rest[1] == "job":
    source = option("--from").split("/", 1)[1]
    cron = state["cronjobs"][source]
    print(json.dumps({"apiVersion": "batch/v1", "kind": "Job", "metadata": {
        "name": rest[2], "labels": {"app.kubernetes.io/name": source, "app.kubernetes.io/instance": "shop"},
        "annotations": {"cronjob.kubernetes.io/instantiate": "manual"},
        "ownerReferences": [{"apiVersion": "batch/v1", "kind": "CronJob", "name": source, "controller": True}]},
        "spec": {"activeDeadlineSeconds": cron["deadline"], "template": {"spec": {"containers": [{"name": source, "env": []}]}}}}))
elif verb == "set":
    doc = json.loads(stdin)
    for pair in [a for a in rest if "=" in a and not a.startswith("-")]:
        key, value = pair.split("=", 1)
        for container in doc["spec"]["template"]["spec"]["containers"]:
            container["env"] = [e for e in container["env"] if e["name"] != key] + [{"name": key, "value": value}]
    print(json.dumps(doc))
elif verb == "create":
    doc = json.loads(stdin)
    kind, name = doc["kind"], doc["metadata"]["name"]
    if kind == "ConfigMap":
        if state.get("lock") is not None:
            fail(f'Error from server (AlreadyExists): configmaps "{name}" already exists')
        state["lock"] = doc
        state["lock_events"].append(["create", len(state["events"])])
        save()
        sys.exit(0)
    if kind == "Secret":
        if name in state["secrets"]:
            fail(f'Error from server (AlreadyExists): secrets "{name}" already exists')
        state["secrets"][name] = doc
    elif kind == "NetworkPolicy":
        if state["netpol"] is not None:
            fail(f'Error from server (AlreadyExists): networkpolicies "{name}" already exists')
        state["netpol"] = doc
        state["netpol_made_at"] = len(state["events"]) + 1
    elif kind == "Job":
        if name in state["jobs"]:
            fail(f'Error from server (AlreadyExists): jobs.batch "{name}" already exists')
        outcome = next((o for prefix, o in state["outcomes"].items() if name.startswith(prefix)))
        env = {e["name"]: e.get("value") for e in doc["spec"]["template"]["spec"]["containers"][0]["env"]}
        state["jobs"][name] = dict(outcome, labels=doc["metadata"].get("labels", {}), env=env, created_at=clock, doc=doc,
                                   deadline=doc["spec"]["activeDeadlineSeconds"], owner=doc["metadata"]["ownerReferences"][0]["name"])
    event(f"create {kind} {name}")
    save()
elif verb == "delete":
    # An event only for what was there (--ignore-not-found deletes nothing else).
    kind = rest[1]
    if kind == "pods":
        assert namespace == "kube-system" and option("-l") == "app.kubernetes.io/name=traefik" and "--wait=true" in rest, rest
        state["traefik_restarts"] = len(state["events"]) + 1
        existed = True
    elif kind == "networkpolicy":
        existed, state["netpol"] = state["netpol"] is not None, None
    elif kind == "secret":
        existed = state["secrets"].pop(words[0], None) is not None
    elif kind == "job":
        existed = state["jobs"].pop(words[0], None) is not None
    elif kind == "configmap":
        if state.get("lock") is not None and state["lock"]["metadata"]["name"] == words[0]:
            state["lock"] = None
            state["lock_events"].append(["delete", len(state["events"])])
        existed = False
    if existed:
        event("delete " + " ".join([kind] + words[:1]))
    save()
elif verb == "exec":
    target, command = rest[1], rest[rest.index("--") + 1:]
    text = " ".join(command)
    if target == "statefulset/postgres":
        if "pg_database_size" in text:
            print("stub sizes of postgres")
        elif "indisvalid" in text:
            print(state["pg"]["invalid"], end="")
        else:
            print(state["pg"]["counts"])
    elif target == "statefulset/elasticsearch":
        if "_cat/indices?v" in text:
            print("stub indices of elasticsearch")
        elif "_cluster/health" in text:
            print(json.dumps({"status": state["es"]["health"]}))
        elif "_cat/aliases" in text:
            print(json.dumps([{"alias": "products", "index": i} for i in state["es"]["alias"]]))
        else:
            print(json.dumps([{"index": i, "docs.count": str(n)} for i, n in state["es"]["indices"].items()]))
    else:
        assert command == ["bin/console", "cache:pool:clear", "cache.sitemap"], command
        if target in state.get("cache_clear_fails", []):
            fail("command terminated with exit code 1")
        event("clear " + target)
        save()
elif verb == "logs":
    print("stub log of " + rest[1])
else:
    fail("unknown kubectl call " + " ".join(rest))
'''

DATE = r'''#!/bin/sh
clock="$(cat "$STUB_DIR/clock")"
for argument in "$@"; do
  case "$argument" in +%s) echo "$clock"; exit 0 ;; +*) exec /bin/date -u -d "@$clock" "$argument" ;; esac
done
exec /bin/date -u -d "@$clock"
'''

SLEEP = r'''#!/bin/sh
clock="$(cat "$STUB_DIR/clock")"
echo $((clock + ${1%.*})) > "$STUB_DIR/clock"
'''


def demo_data_cronjob(profile="medium", photos="demo-photos", min_free=32, deadline=21600):
    command = ["sh", "/scripts/demo-data.sh", f"--profile={profile}", "--anchor=2026-11-19T00:00:00Z", f"--min-free-gb={min_free}"]
    return {"suspend": True, "deadline": deadline, "command": command, "photos": photos}


def finished_job(outcome="Complete", owner="demo-data", **more):
    return {"labels": {"app.kubernetes.io/name": owner}, "env": {}, "deadline": 1800, "created_at": 0, "duration": 0, "outcome": outcome,
            "owner": owner, **more}


def initial_state(**changes):
    state = {
        "cronjobs": {"demo-data": demo_data_cronjob(), "load": {"suspend": True, "deadline": 900, "command": None, "photos": None}},
        "jobs": {"migrate-2b": finished_job(owner=None)},
        "outcomes": {"demo-data-": {"outcome": "Complete", "duration": 3700, "message": "exit 0: done"},
                     "load-": {"outcome": "Complete", "duration": 120, "message": ""}},
        "deployments": {n: 1 for n in ("api", "worker", "store", "admin", "imgproxy", "varnish")},
        "statefulsets": {n: 1 for n in ("postgres", "elasticsearch", "keycloak", "redis", "rabbitmq", "garage")},
        "nodes": {"server-0": 175 * GB},
        "netpol": None, "netpol_made_at": 0, "traefik_restarts": 0,
        "secrets": {},
        "probes": {API_URL: "auto", STORE_URL: "auto"},
        "api_pods": ["api-7c9f-a", "api-7c9f-b"],
        "pg": {"invalid": "", "counts": "1000191|1000001"},
        "es": {"health": "green", "alias": ["products_1"], "indices": {"products_1": 1000191}},
        "events": [], "kubectl_calls": 0,
        "lock": None, "lock_events": [],
    }
    state.update(changes)
    return state


CLOSED = {"apiVersion": "networking.k8s.io/v1", "kind": "NetworkPolicy", "metadata": {"name": "shop-closed"}}
LOCK = "demo-data-lock"
HOLDER = "generate PROFILE=medium on other-laptop, pid 4242, since 2026-11-20T08:00:00Z"
OTHER_TOKEN = "0f1e2d3c4b5a69788796a5b4c3d2e1f0"


def held(token=OTHER_TOKEN, holder=HOLDER):
    """The lock demo-data-lock of another make generate or make open."""
    return {"apiVersion": "v1", "kind": "ConfigMap", "metadata": {"name": LOCK}, "data": {"token": token, "holder": holder}}


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
        self.home = self.root / "home"
        (self.home / ".config/shop").mkdir(parents=True)
        self.photos = self.home / ".config/shop/demo-photos.env"
        self.photos.write_text("".join(f"{k}={v}\n" for k, v in {**READ_ACCESS, **WRITE_ACCESS}.items()))
        self.log = self.root / "log"
        self.log.write_text("")
        self.write(state)

    def write(self, state):
        (self.root / "state.json").write_text(json.dumps(state))

    def change(self, **changes):
        state = self.state()
        state.update(changes)
        self.write(state)

    def environment(self, **env):
        variables = {"PATH": f"{self.bin}:{os.environ['PATH']}", "HOME": str(self.home), "STUB_LOG": str(self.log),
                     "STUB_DIR": str(self.root), "TOOLS_CACHE": str(self.root / "cache"), "KUBE_DIR": str(self.kube),
                     "GENERATE_PROBE_URL": f"{API_URL} {STORE_URL}", "PROFILE": "medium", "GENERATE_POLL": "600",
                     "GENERATE_PROBE_POLL": "15", "STUB_RUNNER_PGID": str(os.getpgrp())}
        variables.update(env)
        return {k: v for k, v in variables.items() if v is not None}

    def run(self, script=GENERATE, answer="tak\n", shell_options=(), **env):
        """The script in a session of its own (a trigger signals its process group)."""
        return subprocess.run(["sh", *shell_options, str(script)], input=answer, env=self.environment(**env), capture_output=True,
                              text=True, timeout=300, start_new_session=True)

    def open(self, **env):
        return self.run(OPEN, answer="", **env)

    def state(self):
        return json.loads((self.root / "state.json").read_text())

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def kubectl_calls(self):
        return [c["args"] for c in self.calls() if c["image"] == "registry.k8s.io/kubectl"]

    def events(self):
        return self.state()["events"]

    def advance(self, seconds):
        (self.root / "clock").write_text(str(int((self.root / "clock").read_text()) + seconds))


class Case(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.tmp.cleanup()

    def cluster(self, **changes):
        return Cluster(tempfile.mkdtemp(dir=self.tmp.name), initial_state(**changes))

    def assert_unchanged(self, cluster, result, code=1, lock=None):
        """No change in the cluster, and the lock as it was before (None: the run left no lock)."""
        self.assertEqual(code, result.returncode, result.stdout + result.stderr)
        self.assertIn("nothing was changed", result.stderr)
        self.assertEqual([], cluster.events(), result.stderr)
        self.assertEqual(lock, cluster.state()["lock"], result.stderr)

    def assert_refused_by_the_lock(self, result, holder=HOLDER):
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn(f"the lock {LOCK} is held by {holder}", result.stderr)
        self.assertIn(f"kubectl -n shop delete configmap {LOCK}", result.stderr)


CLOSING = ["create NetworkPolicy shop-closed", "delete pods"]
GENERATION = ["create Secret demo-photos", f"create Job {JOB}"]
OPENING = ["clear api-7c9f-a", "clear api-7c9f-b", "create Job load", "delete networkpolicy shop-closed"]
# The Secret of the photo pool is deleted when the target ends; after a Job that completed make open (run by make generate) deletes it.
SECRET_GONE = ["delete secret demo-photos"]


def without_stamps(events):
    """The Job of the CronJob load is named by the time: load-<UTC time>."""
    return ["create Job load" if e.startswith("create Job load-") else e for e in events]


class Arguments(Case):
    def test_a_missing_or_malformed_profile_is_exit_2_before_the_cluster(self):
        for profile in (None, "", "Medium", "medium;rm", "../x"):
            with self.subTest(profile=profile):
                cluster = self.cluster()
                result = cluster.run(PROFILE=profile)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("PROFILE", result.stderr)
                self.assertEqual([], cluster.calls())

    def test_the_kubeconfig_the_probe_and_the_settings_are_checked_before_the_cluster_by_both_targets(self):
        for env, words in (({"KUBE_DIR": ""}, "KUBE_DIR"), ({"KUBE_DIR": "/nonexistent"}, "KUBE_DIR"), ({"GENERATE_PROBE_URL": ""}, "GENERATE_PROBE_URL"),
                           ({"GENERATE_PROBE_URL": "ftp://worf.pl/"}, "GENERATE_PROBE_URL"),
                           ({"GENERATE_PROBE_HOST": "api.localhost"}, "GENERATE_PROBE_HOST"), ({"GENERATE_POLL": "2m"}, "GENERATE_POLL")):
            for script in (GENERATE, OPEN):
                with self.subTest(env=env, script=script.name):
                    cluster = self.cluster()
                    result = cluster.run(script, **env)
                    self.assertEqual(2, result.returncode, result.stderr)
                    self.assertIn(words, result.stderr)
                    self.assertEqual([], cluster.calls())


class Refusals(Case):
    def test_a_profile_other_than_the_one_of_the_cronjob_or_seed_is_refused(self):
        for profile in ("server", "small"):
            with self.subTest(profile=profile):
                cluster = self.cluster()
                result = cluster.run(PROFILE=profile)
                self.assert_unchanged(cluster, result)
                self.assertIn("demoData.profile", result.stderr)

    def test_a_missing_or_running_cronjob_is_refused(self):
        crons = initial_state()["cronjobs"]
        for cronjobs, words in (({"load": crons["load"]}, "no CronJob demo-data"),
                                ({**crons, "demo-data": dict(crons["demo-data"], suspend=False)}, "not suspended")):
            with self.subTest(words=words):
                cluster = self.cluster(cronjobs=cronjobs)
                result = cluster.run()
                self.assert_unchanged(cluster, result)
                self.assertIn(words, result.stderr)

    def test_an_active_job_is_refused_by_both_targets(self):
        jobs = {**initial_state()["jobs"], "cart-purge-expired-2934": finished_job("Running", "cart-purge-expired")}
        for script in (GENERATE, OPEN):
            with self.subTest(script=script.name):
                cluster = self.cluster(jobs=jobs)
                result = cluster.run(script)
                self.assert_unchanged(cluster, result)
                self.assertIn("cart-purge-expired-2934", result.stderr)

    def test_a_workload_that_is_not_ready_is_refused_by_its_name(self):
        for kind, name in (("deployments", "worker"), ("statefulsets", "elasticsearch")):
            with self.subTest(kind=kind):
                cluster = self.cluster(**{kind: {**initial_state()[kind], name: 0}})
                result = cluster.run()
                self.assert_unchanged(cluster, result)
                self.assertIn(f"{kind}/{name}", result.stderr)

    def test_the_file_of_the_photo_pool_is_required_only_for_a_profile_whose_cronjob_reads_the_secret(self):
        for content in (None, "DEMO_PHOTOS_ENDPOINT=x\n", "".join(f"{k}={v}\n" for k, v in WRITE_ACCESS.items())):
            with self.subTest(content=content):
                cluster = self.cluster()
                if content is None:
                    cluster.photos.unlink()
                else:
                    cluster.photos.write_text(content)
                self.assert_unchanged(cluster, cluster.run(), code=2)
        crons = initial_state()["cronjobs"]
        for profile, cronjobs in (("seed", crons), ("medium", {**crons, "demo-data": demo_data_cronjob(photos=None)})):
            with self.subTest(profile=profile):
                cluster = self.cluster(cronjobs=cronjobs)
                cluster.photos.unlink()
                result = cluster.run(PROFILE=profile, answer="nie\n")
                self.assert_unchanged(cluster, result)
                self.assertIn("not confirmed", result.stderr)

    def test_the_free_space_a_floor_for_a_profile_the_peak_for_medium_and_server_and_only_a_reading_for_the_seed(self):
        # medium: max(32, 1.25 x 23) = 32 GB; server: max(32, 1.25 x 114) = 142.5 GB; small (on k3d): minFreeGb only.
        small = demo_data_cronjob("small", None, 2, 1800)
        for profile, cron, free, refused in (("medium", None, 31.9, "32"), ("server", None, 142, "142.50"), ("small", small, 1.9, "2 GB"),
                                             ("server", None, 143, None), ("small", small, 3, None), ("seed", None, 1, None), ("seed", None, "a lot", "free space"),
                                             ("medium", None, "fail", "free space")):
            with self.subTest(profile=profile, free=free):
                demo = cron or demo_data_cronjob("server" if profile == "server" else "medium")
                cluster = self.cluster(cronjobs={**initial_state()["cronjobs"], "demo-data": demo},
                                       nodes={"server-0": int(free * GB) if isinstance(free, (int, float)) else free})
                result = cluster.run(PROFILE=profile, answer="nie\n")
                self.assert_unchanged(cluster, result)
                self.assertIn(refused or "not confirmed", result.stderr)

    def test_a_cronjob_without_the_floor_of_the_free_space_is_refused(self):
        demo = demo_data_cronjob()
        demo["command"] = [a for a in demo["command"] if not a.startswith("--min-free-gb")]
        cluster = self.cluster(cronjobs={**initial_state()["cronjobs"], "demo-data": demo})
        result = cluster.run()
        self.assert_unchanged(cluster, result)
        self.assertIn("--min-free-gb", result.stderr)

    def test_every_node_must_have_the_room(self):
        cluster = self.cluster(nodes={"server-0": 175 * GB, "agent-1": 10 * GB})
        result = cluster.run()
        self.assert_unchanged(cluster, result)
        self.assertIn("agent-1", result.stderr)


class Confirmation(Case):
    def test_without_the_word_tak_nothing_changes(self):
        for answer in ("nie\n", "", "TAK\n", "tak tak\n", " tak\n"):
            with self.subTest(answer=answer):
                cluster = self.cluster()
                result = cluster.run(answer=answer)
                self.assert_unchanged(cluster, result)
                self.assertIn("not confirmed", result.stderr)

    def test_it_names_the_host_the_profile_and_the_numbers_before_it_asks(self):
        cluster = self.cluster(pg={"invalid": "", "counts": "191|0"})
        before = cluster.run(answer="nie\n").stdout.split("type tak")[0]
        for part in ("https://203.0.113.7:6443", API_URL, STORE_URL, "PROFILE=medium", "191 products", "0 orders", "175.00 GB", "CLOSED"):
            self.assertIn(part, before)


class Run(Case):
    def test_a_profile_closes_generates_and_opens_in_this_order(self):
        cluster = self.cluster()
        result = cluster.run()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(CLOSING + GENERATION + SECRET_GONE + OPENING, without_stamps(cluster.events()))
        state = cluster.state()
        self.assertIsNone(state["netpol"])
        self.assertEqual({}, state["secrets"])
        self.assertEqual("medium", state["jobs"][JOB]["env"]["DEMO_DATA_CONFIRM"])
        for part in ("1000191 products", "1000001 orders", "stub sizes of postgres", "stub indices of elasticsearch", "the shop is open"):
            self.assertIn(part, result.stdout)

    def test_the_seed_runs_the_same_job_without_the_photo_pool(self):
        cluster = self.cluster()
        cluster.photos.unlink()
        result = cluster.run(PROFILE="seed")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("seed", cluster.state()["jobs"][JOB]["env"]["DEMO_DATA_CONFIRM"])
        self.assertNotIn("create Secret demo-photos", cluster.events())

    def test_a_closed_shop_after_a_failure_is_closed_again_without_a_second_rule(self):
        cluster = self.cluster(netpol=CLOSED)
        result = cluster.run()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["delete pods"], without_stamps(cluster.events())[:1])

    def test_a_closing_that_the_probe_does_not_confirm_stops_with_the_shop_closed(self):
        for probes in ({API_URL: "auto", STORE_URL: "200"}, {API_URL: "000", STORE_URL: "auto"}, {API_URL: "auto", STORE_URL: "500"}):
            with self.subTest(probes=probes):
                cluster = self.cluster(probes=probes)
                result = cluster.run(GENERATE_CLOSE_WAIT="60")
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertEqual(CLOSING, cluster.events())
                self.assertIsNotNone(cluster.state()["netpol"])
                self.assertIn("CLOSED", result.stderr)
                self.assertIn("does not say closed", result.stderr)

    def test_a_job_that_fails_leaves_the_shop_closed_with_the_reason_and_the_two_ways_out(self):
        for outcome, words in (({"outcome": "Failed", "duration": 60, "message": "exit 75: the free space of / fell"}, "exit 75: the free space"),
                               ({"outcome": "Failed", "duration": 60, "message": ""}, "no reason"),
                               ({"outcome": "Running", "duration": 0}, "did not complete")):
            with self.subTest(outcome=outcome):
                cluster = self.cluster(outcomes={**initial_state()["outcomes"], "demo-data-": outcome})
                result = cluster.run()
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertEqual(CLOSING + GENERATION + SECRET_GONE, cluster.events())
                self.assertIn(words, result.stderr)
                self.assertIn("CLOSED", result.stderr)
                self.assertIn("PROFILE=medium", result.stderr)
                self.assertIn("PROFILE=seed", result.stderr)

    def test_the_probe_sends_the_host_headers_pairwise_with_the_addresses(self):
        """GENERATE_PROBE_HOST: on k3d the addresses are the node and the hosts name the ingress (the smoke test)."""
        cluster = self.cluster()
        result = cluster.run(GENERATE_PROBE_URL="http://node-a/ http://node-b/", GENERATE_PROBE_HOST="api.localhost localhost")
        self.assertEqual(0, result.returncode, result.stderr)
        probes = [c["args"] for c in cluster.calls() if c["image"] == "curlimages/curl"]
        self.assertTrue(probes)
        for args in probes:
            host = args[args.index("-H") + 1]
            self.assertEqual({"http://node-a/": "Host: api.localhost", "http://node-b/": "Host: localhost"}[args[-1]], host, args)

    def test_a_failure_before_the_rule_was_made_does_not_say_the_shop_is_closed(self):
        whole = self.cluster()
        whole.run()
        kubectl = [c for c in whole.calls() if c["image"] == "registry.k8s.io/kubectl"]
        calls = [i + 1 for i, c in enumerate(kubectl) if c["args"][2:4] == ["get", "networkpolicy"] or '"NetworkPolicy"' in c["stdin"]]
        self.assertEqual(2, len(calls), kubectl)
        for call in calls:
            with self.subTest(failing=kubectl[call - 1]["args"]):
                cluster = self.cluster(trigger={"call": call, "action": "fail"})
                result = cluster.run()
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertIsNone(cluster.state()["netpol"])
                self.assertEqual([], cluster.events())
                self.assertNotIn("CLOSED", result.stderr)
                self.assertIn("the shop is not closed", result.stderr)

    def test_the_rule_the_lock_and_the_job_carry_no_tracking_of_argo_cd(self):
        """Argo CD 3 (argo-cd chart 10.x, no resourceTrackingMethod in argocd/install/argocd.yaml) tracks by the annotation
        argocd.argoproj.io/tracking-id: the rule and the lock have neither labels nor annotations, and the Job of the generator gets
        only what kubectl create job --from gives it (the labels of the jobTemplate, the owner CronJob), no annotation of Argo CD."""
        cluster = self.cluster()
        cluster.run()
        created = [json.loads(c["stdin"]) for c in cluster.calls() if c["stdin"] and c["args"][-3:] == ["create", "-f", "-"]]
        (rule,) = [d for d in created if d["kind"] == "NetworkPolicy"]
        self.assertEqual({"name": "shop-closed"}, rule["metadata"])
        (lock,) = [d for d in created if d["kind"] == "ConfigMap"]
        self.assertEqual({"name": LOCK}, lock["metadata"])
        for job in [d for d in created if d["kind"] == "Job"]:
            self.assertEqual({"cronjob.kubernetes.io/instantiate": "manual"}, job["metadata"]["annotations"])
            self.assertEqual("CronJob", job["metadata"]["ownerReferences"][0]["kind"])
            self.assertNotIn("argocd.argoproj.io", json.dumps(job))


class PhotoPool(Case):
    def leaks(self, cluster, result):
        texts = [result.stdout, result.stderr] + [" ".join(c["args"]) for c in cluster.calls()]
        return [v for v in [*READ_ACCESS.values(), *WRITE_ACCESS.values()] for text in texts if v in text]

    def test_the_secret_has_the_four_read_values_only_through_the_standard_input(self):
        for options in ((), ("-x",)):
            with self.subTest(options=options):
                cluster = self.cluster()
                result = cluster.run(shell_options=options)
                self.assertEqual(0, result.returncode, result.stderr[-2000:])
                self.assertEqual([], self.leaks(cluster, result))
                (secret,) = [json.loads(c["stdin"]) for c in cluster.calls() if '"Secret"' in c["stdin"]]
                self.assertEqual({k: base64.b64encode(v.encode()).decode() for k, v in READ_ACCESS.items()}, secret["data"])
                for value in WRITE_ACCESS.values():
                    self.assertNotIn(base64.b64encode(value.encode()).decode(), json.dumps(secret))


class Open(Case):
    def opened(self, **changes):
        changes.setdefault("netpol", CLOSED)
        changes.setdefault("jobs", {**initial_state()["jobs"], JOB: finished_job()})
        return self.cluster(**changes)

    def test_after_a_job_that_completed_it_checks_and_opens(self):
        cluster = self.opened()
        result = cluster.open()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(OPENING, without_stamps(cluster.events()))
        self.assertIn("the shop is open", result.stdout)

    def test_it_is_idempotent(self):
        cluster = self.opened()
        self.assertEqual(0, cluster.open().returncode)
        result = cluster.open()
        self.assertEqual(0, result.returncode, result.stderr)
        # The second time there is no rule to delete.
        self.assertEqual(OPENING + OPENING[:-1], without_stamps(cluster.events()))

    def test_every_failed_check_leaves_the_shop_closed(self):
        for changes, words in (({"pg": {"invalid": "catalog_product_pkey", "counts": "1|1"}}, "catalog_product_pkey"),
                               ({"es": {"health": "red", "alias": ["products_1"], "indices": {"products_1": 5}}}, "red"),
                               ({"es": {"health": "green", "alias": ["products_1", "products_2"], "indices": {"products_1": 5, "products_2": 5}}}, "2 indexes"),
                               ({"es": {"health": "green", "alias": ["products_1"], "indices": {"products_1": 0}}}, "no documents"),
                               ({"es": {"health": "green", "alias": ["products_1"], "indices": {"products_1": 5, "products_0": 3}}}, "products_0"),
                               ({"outcomes": {"load-": {"outcome": "Failed", "duration": 30, "message": ""}}}, "load/smoke.js"),
                               ({"api_pods": []}, "no pod of the api")):
            with self.subTest(words=words):
                cluster = self.opened(**changes)
                result = cluster.open()
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertIsNotNone(cluster.state()["netpol"])
                self.assertIn(words, result.stderr)
                self.assertIn("CLOSED", result.stderr)

    def test_without_a_job_demo_data_run_that_completed_it_refuses(self):
        for run, words in ((None, "no Job demo-data-run"), (finished_job("Failed"), "Failed")):
            with self.subTest(run=run):
                jobs = {**initial_state()["jobs"], **({JOB: run} if run else {})}
                cluster = self.opened(jobs=jobs)
                result = cluster.open()
                self.assert_unchanged(cluster, result)
                self.assertIn(words, result.stderr)
                self.assertIsNotNone(cluster.state()["netpol"])

    def test_a_secret_of_the_photo_pool_that_is_left_is_deleted_once_no_job_runs(self):
        secret = {"kind": "Secret", "metadata": {"name": "demo-photos"}}
        for run, code in ((finished_job(), 0), (finished_job("Failed"), 1), (None, 1)):
            with self.subTest(run=run):
                jobs = {**initial_state()["jobs"], **({JOB: run} if run else {})}
                cluster = self.opened(jobs=jobs, secrets={"demo-photos": secret})
                result = cluster.open()
                self.assertEqual(code, result.returncode, result.stderr)
                self.assertEqual({}, cluster.state()["secrets"])
                self.assertEqual("delete secret demo-photos", cluster.events()[0])
        # A Job that runs may still need it.
        cluster = self.opened(jobs={**initial_state()["jobs"], JOB: finished_job("Running")}, secrets={"demo-photos": secret})
        self.assert_unchanged(cluster, cluster.open())

    def test_a_cache_of_the_sitemap_that_cannot_be_cleared_leaves_the_shop_closed(self):
        cluster = self.opened(cache_clear_fails=["api-7c9f-b"])
        result = cluster.open()
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("api-7c9f-b", result.stderr)
        self.assertIn("CLOSED", result.stderr)
        self.assertIsNotNone(cluster.state()["netpol"])
        self.assertEqual(["clear api-7c9f-a"], cluster.events())

    def test_a_probe_that_does_not_say_open_is_exit_1(self):
        cluster = self.opened(probes={API_URL: "auto", STORE_URL: "000"})
        result = cluster.open(GENERATE_OPEN_WAIT="60")
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("does not say open", result.stderr)


class PreviousRun(Case):
    """make open must not take the success of an earlier Job for that of a generation that stopped. Only the new Job changes the data,
    so make generate deletes the Job demo-data-run of the run before (with its pod: --cascade=foreground --wait=true) right before it
    makes the new one, after the closing was confirmed and the Secret made: until then the data is untouched and the Job before
    describes it (make open may open the shop on it again); from the deletion until the new Job completed there is no Job
    demo-data-run that completed."""

    def with_previous(self, **changes):
        changes.setdefault("netpol", CLOSED)
        return self.cluster(jobs={**initial_state()["jobs"], JOB: finished_job(previous=True)}, **changes)

    def test_the_previous_run_is_deleted_right_before_the_new_job_after_the_closing_and_the_secret(self):
        cluster = self.with_previous()
        result = cluster.run()
        self.assertEqual(0, result.returncode, result.stderr)
        events = cluster.events()
        deleted = events.index(f"delete job {JOB}")
        self.assertEqual(f"create Job {JOB}", events[deleted + 1])
        self.assertLess(events.index("delete pods"), deleted)
        self.assertLess(events.index("create Secret demo-photos"), deleted)
        self.assertNotIn("previous", cluster.state()["jobs"][JOB])
        calls = cluster.kubectl_calls()
        (at,) = [i for i, c in enumerate(calls) if c[2:4] == ["delete", "job"]]
        self.assertEqual(["-n", "shop", "delete", "job", JOB, "--cascade=foreground", "--wait=true", "--ignore-not-found"], calls[at])
        # The active Jobs are read again right before it (a Job may have started since the checks).
        self.assertEqual(["-n", "shop", "get", "jobs", "-o", "json"], calls[at - 1])

    def test_a_closing_that_the_probe_does_not_confirm_keeps_the_previous_run_and_open_opens_on_it_once_the_probe_works(self):
        cluster = self.with_previous(netpol=None, probes={API_URL: "auto", STORE_URL: "403"})
        result = cluster.run(GENERATE_CLOSE_WAIT="60")
        self.assertEqual(1, result.returncode, result.stderr)
        state = cluster.state()
        self.assertIsNotNone(state["netpol"])
        self.assertTrue(state["jobs"].get(JOB, {}).get("previous"), state["events"])
        self.assertEqual(CLOSING, state["events"])
        self.assertIn("make -C infra/k8s open opens it again", result.stderr)
        cluster.change(probes={API_URL: "auto", STORE_URL: "auto"})
        opened = cluster.open()
        self.assertEqual(0, opened.returncode, opened.stderr)
        self.assertIsNone(cluster.state()["netpol"])

    def test_after_the_new_job_was_made_open_refuses_until_it_completed(self):
        outcomes = {**initial_state()["outcomes"], "demo-data-": {"outcome": "Complete", "duration": 7200, "message": "exit 0: done"}}
        whole = self.with_previous(outcomes=outcomes)
        whole.run()
        kubectl = [c for c in whole.calls() if c["image"] == "registry.k8s.io/kubectl"]
        (made,) = [i for i, c in enumerate(kubectl) if c["args"][-3:] == ["create", "-f", "-"] and f'"name": "{JOB}"' in c["stdin"]]
        cluster = self.with_previous(outcomes=outcomes, trigger={"call": made + 1, "action": "KILL"})
        self.assertNotEqual(0, cluster.run().returncode)
        cluster.change(trigger=None)
        # KILL leaves the lock: make open refuses on it until the operator deletes it (nothing runs any more).
        self.assert_refused_by_the_lock(cluster.open(), holder="generate PROFILE=medium on ")
        cluster.change(lock=None)
        refused = cluster.open()
        self.assertEqual(1, refused.returncode, refused.stderr)
        self.assertIn(JOB, refused.stderr)
        self.assertIsNotNone(cluster.state()["netpol"])
        cluster.advance(7200)
        opened = cluster.open()
        self.assertEqual(0, opened.returncode, opened.stderr)

    def test_a_running_job_of_another_copy_right_before_the_deletion_is_refused_without_deleting_it(self):
        other = dict(finished_job("Running"), other=True)
        cluster = self.with_previous(inject_job_after={"event": "create Secret demo-photos", "job": other})
        result = cluster.run()
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn(f"Jobs are active in the namespace shop: {JOB}", result.stderr)
        self.assertTrue(cluster.state()["jobs"][JOB]["other"])
        self.assertNotIn(f"delete job {JOB}", cluster.events())
        self.assertEqual({}, cluster.state()["secrets"])

    def test_without_tak_the_previous_run_stays(self):
        cluster = self.with_previous()
        self.assert_unchanged(cluster, cluster.run(answer="nie\n"))
        self.assertTrue(cluster.state()["jobs"][JOB]["previous"])

    def test_a_job_of_the_same_name_made_meanwhile_ends_the_run_without_a_second_one(self):
        """A Job demo-data-run made meanwhile (by hand; another make generate waits on the lock) is not replaced: the name is fixed."""
        other = dict(finished_job("Running"), other=True)
        cluster = self.with_previous(inject_job_after={"event": f"delete job {JOB}", "job": other})
        result = cluster.run()
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("could not be created", result.stderr)
        self.assertTrue(cluster.state()["jobs"][JOB]["other"])
        self.assertEqual({}, cluster.state()["secrets"])


def run_all(runs):
    """The runs (callables) in parallel: the stub is cheap, the targets mostly wait on it."""
    from concurrent.futures import ThreadPoolExecutor
    with ThreadPoolExecutor(max_workers=max(4, (os.cpu_count() or 2) * 2)) as pool:
        return list(pool.map(lambda run: run(), runs))


class Interruptions(Case):
    """make generate stopped at every call of kubectl: by INT or TERM (its trap), by KILL (no trap) or by a call that fails (a lost
    connection). The Job of the new run fails after a minute: a signal before tak changes nothing; before the Job of the run before is
    deleted the data is untouched, and make open opens the shop on that Job again; once it is deleted make open refuses (the new Job
    failed). The Secret of the photo pool is gone after make generate on every path it can catch, and after make open on the others
    (KILL, or its deletion failed). The lock is gone after INT and TERM; after KILL, or a lost connection while it was released, it
    stays and make open refuses on it without a change until the operator deletes it."""

    ACTIONS = ("INT", "TERM", "KILL", "fail")

    def setUp(self):
        super().setUp()
        failing = {**initial_state()["outcomes"], "demo-data-": {"outcome": "Failed", "duration": 60, "message": "exit 1: the generator failed"}}
        self.start = initial_state(jobs={**initial_state()["jobs"], JOB: finished_job(previous=True)}, outcomes=failing)
        asked = Cluster(tempfile.mkdtemp(dir=self.tmp.name), self.start)
        asked.run(answer="nie\n", GENERATE_POLL="30")
        self.before_tak = asked.state()["kubectl_calls"]
        whole = Cluster(tempfile.mkdtemp(dir=self.tmp.name), self.start)
        self.assertEqual(1, whole.run(GENERATE_POLL="30").returncode)
        self.calls = whole.kubectl_calls()

    def interrupted(self, call, action):
        def run():
            cluster = Cluster(tempfile.mkdtemp(dir=self.tmp.name), dict(self.start, trigger={"call": call, "action": action}))
            generated = cluster.run(GENERATE_POLL="30")
            after = cluster.state()
            cluster.change(trigger=None)
            cluster.advance(3600)
            locked = None
            if after["lock"] is not None:
                refused = cluster.open(GENERATE_POLL="30")
                locked = refused, cluster.state()
                # The operator deletes the lock once neither make generate nor make open runs.
                cluster.change(lock=None)
            opened = cluster.open(GENERATE_POLL="30")
            return call, action, generated, after, locked, opened, cluster.state()
        return run

    def test_make_open_never_takes_the_previous_run_and_the_secret_does_not_stay(self):
        self.assertGreater(len(self.calls), self.before_tak + 5, self.calls)
        runs = [self.interrupted(call, action) for call in range(1, len(self.calls) + 1) for action in self.ACTIONS]
        for call, action, generated, after, locked, opened, final in run_all(runs):
            kubectl = self.calls[call - 1]
            with self.subTest(call=call, action=action, kubectl=kubectl):
                self.assertNotEqual(0, generated.returncode, generated.stderr)
                if action in ("INT", "TERM"):
                    self.assertIsNone(after["lock"], generated.stderr)
                if after["lock"] is not None:
                    self.assertTrue(action == "KILL" or (action == "fail" and kubectl[2:4] in (["get", "configmap"], ["delete", "configmap"])),
                                    generated.stderr)
                    refused, at_refusal = locked
                    self.assert_refused_by_the_lock(refused, holder="generate PROFILE=medium on ")
                    self.assertIn("nothing was changed", refused.stderr)
                    for key in ("events", "netpol", "secrets", "jobs", "lock"):
                        self.assertEqual(after[key], at_refusal[key], key)
                # A signal before tak ends it without a change; a read before tak that fails only says less in the question.
                self.assertTrue(call > self.before_tak or action == "fail" or not after["events"], after["events"])
                if f"delete job {JOB}" not in after["events"]:
                    # The data is untouched (no new Job), the Job before describes it: make open opens the shop on it.
                    self.assertNotIn(f"create Job {JOB}", after["events"])
                    self.assertTrue(after["jobs"][JOB].get("previous"))
                    self.assertEqual(0, opened.returncode, opened.stdout + opened.stderr)
                    self.assertIsNone(final["netpol"])
                    self.assertTrue(final["jobs"][JOB].get("previous"))
                else:
                    events = after["events"]
                    self.assertLess(events.index("delete pods"), events.index(f"delete job {JOB}"))
                    self.assertLess(events.index("create Secret demo-photos"), events.index(f"delete job {JOB}"))
                    self.assertEqual(1, opened.returncode, opened.stdout + opened.stderr)
                    self.assertNotIn("delete networkpolicy shop-closed", final["events"], opened.stderr)
                    self.assertIsNotNone(final["netpol"])
                    self.assertFalse(final["jobs"].get(JOB, {}).get("previous"))
                if action in ("INT", "TERM") or (action == "fail" and kubectl[2:4] != ["delete", "secret"]):
                    self.assertEqual({}, after["secrets"], generated.stderr)
                self.assertEqual({}, final["secrets"], opened.stderr)


class Lock(Case):
    """make generate and make open run one at a time: the lock is the ConfigMap demo-data-lock (who holds it, and a random token),
    made by kubectl create (AlreadyExists while another holds it) before the first change and deleted on every exit the target can
    catch (EXIT, HUP, INT, TERM), and only while its token is the one of this run. make generate holds it from tak to its end, with the
    make open it runs: that one takes none and goes on only while the lock is there with the token of make generate
    (DEMO_DATA_LOCK_TOKEN). After KILL, or a lost connection while it is released, the lock stays and both refuse on it."""

    SINCE = datetime.datetime.fromtimestamp(START, datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

    def opened(self, **changes):
        changes.setdefault("netpol", CLOSED)
        changes.setdefault("jobs", {**initial_state()["jobs"], JOB: finished_job()})
        return self.cluster(**changes)

    def made_locks(self, cluster):
        return [json.loads(c["stdin"]) for c in cluster.calls() if '"ConfigMap"' in c["stdin"]]

    def call_of(self, cluster, match):
        """The number of the first call of kubectl (from 1) whose arguments and standard input contain match."""
        kubectl = [c for c in cluster.calls() if c["image"] == "registry.k8s.io/kubectl"]
        return next(i + 1 for i, c in enumerate(kubectl) if match in " ".join(c["args"]) + c["stdin"])

    def lock_call(self):
        """The call of kubectl of make generate that makes the lock: the first one after tak."""
        asked = self.cluster()
        asked.run(answer="nie\n")
        return asked.state()["kubectl_calls"] + 1

    def test_make_open_keeps_the_rule_while_another_make_generate_would_replace_the_job(self):
        """A make generate started while make open runs (it clears the caches and runs the Job load for minutes) must not get to
        replace the Job demo-data-run that make open checked, or make open would open the shop on data being replaced: it refuses on
        the lock of make open, and make open ends as it would alone."""
        other = dict(finished_job("Running"), other=True)
        cluster = self.opened(inject_job_after={"event": "clear api-7c9f-a", "job": other, "by": "generate"})
        result = cluster.open()
        state = cluster.state()
        if state["jobs"][JOB].get("other"):
            self.assertIsNotNone(state["netpol"], "make open deleted the rule shop-closed while another make generate replaced the Job")
        self.assertTrue(state.get("inject_refused"), state["events"])
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(OPENING, without_stamps(state["events"]))
        self.assertIsNone(state["lock"])

    def test_make_generate_refuses_before_tak_while_another_holds_the_lock(self):
        cluster = self.cluster(lock=held())
        result = cluster.run()
        self.assert_unchanged(cluster, result, lock=held())
        self.assert_refused_by_the_lock(result)
        self.assertNotIn("type tak", result.stdout)
        self.assertEqual([], cluster.state()["lock_events"])

    def test_make_generate_refuses_without_a_change_when_the_lock_is_taken_between_its_check_and_tak(self):
        cluster = self.cluster(inject_at_call={"call": self.lock_call(), "changes": {"lock": held()}})
        result = cluster.run()
        self.assertIn("type tak", result.stdout)
        self.assertEqual(1, len(self.made_locks(cluster)))
        self.assert_unchanged(cluster, result, lock=held())
        self.assert_refused_by_the_lock(result)
        self.assertEqual([], cluster.state()["lock_events"])

    def test_make_open_refuses_without_a_change_while_another_holds_the_lock(self):
        secret = {"kind": "Secret", "metadata": {"name": "demo-photos"}}
        cluster = self.opened(lock=held(), secrets={"demo-photos": secret})
        result = cluster.open()
        self.assert_unchanged(cluster, result, lock=held())
        self.assert_refused_by_the_lock(result)
        self.assertEqual({"demo-photos": secret}, cluster.state()["secrets"])
        self.assertIsNotNone(cluster.state()["netpol"])

    def test_make_open_run_by_make_generate_goes_on_only_under_its_lock_and_leaves_it_to_it(self):
        token = "a1b2c3d4e5f60718293a4b5c6d7e8f90"
        ours = held(token, f"generate PROFILE=medium on operator-laptop, pid 77, since {self.SINCE}")
        cluster = self.opened(lock=ours)
        result = cluster.open(DEMO_DATA_LOCK_TOKEN=token)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(OPENING, without_stamps(cluster.events()))
        self.assertEqual(ours, cluster.state()["lock"])
        self.assertEqual([], cluster.state()["lock_events"])
        # A token set by hand does not get past the lock: another one, or none in the cluster.
        for lock, words in ((held(), f"held by {HOLDER}"), (None, f"there is no lock {LOCK}")):
            with self.subTest(lock=lock):
                cluster = self.opened(lock=lock)
                result = cluster.open(DEMO_DATA_LOCK_TOKEN=token)
                self.assert_unchanged(cluster, result, lock=lock)
                self.assertIn(words, result.stderr)
                self.assertIn("DEMO_DATA_LOCK_TOKEN", result.stderr)
                self.assertEqual([], cluster.state()["lock_events"])

    def test_make_generate_takes_the_lock_after_tak_before_any_change_and_releases_it_at_its_end(self):
        cluster = self.cluster()
        result = cluster.run()
        self.assertEqual(0, result.returncode, result.stderr)
        state = cluster.state()
        self.assertEqual([["create", 0], ["delete", len(state["events"])]], state["lock_events"])
        self.assertIsNone(state["lock"])
        # One lock: make open runs under the one of make generate.
        (lock,) = self.made_locks(cluster)
        self.assertEqual({"apiVersion": "v1", "kind": "ConfigMap", "metadata": {"name": LOCK}}, {k: v for k, v in lock.items() if k != "data"})
        self.assertRegex(lock["data"]["token"], r"^[0-9a-f]{32}$")
        self.assertRegex(lock["data"]["holder"], rf"^generate PROFILE=medium on [A-Za-z0-9.-]+, pid [0-9]+, since {self.SINCE}$")
        cluster = self.cluster()
        cluster.run(answer="nie\n")
        self.assertEqual([], cluster.state()["lock_events"])
        self.assertEqual([], self.made_locks(cluster))

    def test_make_open_takes_the_lock_before_its_first_change_and_releases_it_at_its_end(self):
        secret = {"kind": "Secret", "metadata": {"name": "demo-photos"}}
        cluster = self.opened(secrets={"demo-photos": secret})
        result = cluster.open()
        self.assertEqual(0, result.returncode, result.stderr)
        state = cluster.state()
        self.assertEqual("delete secret demo-photos", state["events"][0])
        self.assertEqual([["create", 0], ["delete", len(state["events"])]], state["lock_events"])
        (lock,) = self.made_locks(cluster)
        self.assertRegex(lock["data"]["holder"], rf"^open on [A-Za-z0-9.-]+, pid [0-9]+, since {self.SINCE}$")

    def test_the_lock_is_released_after_a_failure_or_a_refusal_after_tak(self):
        runs = []
        for changes, script in (({"outcomes": {**initial_state()["outcomes"], "demo-data-": {"outcome": "Failed", "duration": 60, "message": ""}}}, GENERATE),
                                ({"probes": {API_URL: "auto", STORE_URL: "200"}}, GENERATE),
                                ({"jobs": {**initial_state()["jobs"], "cart-purge-expired-1": finished_job("Running", "cart-purge-expired")}}, OPEN),
                                ({"jobs": initial_state()["jobs"], "netpol": CLOSED}, OPEN),
                                ({"api_pods": [], "jobs": {**initial_state()["jobs"], JOB: finished_job()}}, OPEN)):
            def run(changes=changes, script=script):
                cluster = self.cluster(**changes)
                return changes, script, cluster.run(script, GENERATE_CLOSE_WAIT="60"), cluster.state()
            runs.append(run)
        for changes, script, result, state in run_all(runs):
            with self.subTest(changes=changes, script=script.name):
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertIsNone(state["lock"], result.stderr)
                self.assertEqual(["create", "delete"], [e[0] for e in state["lock_events"]])

    def test_a_hangup_of_make_generate_releases_the_lock(self):
        """HUP (the terminal or the SSH session of the operator closed) right after the lock, while the Job runs and in its make open;
        INT and TERM at every call: Interruptions."""
        outcomes = {**initial_state()["outcomes"], "demo-data-": {"outcome": "Complete", "duration": 1200, "message": "exit 0: done"}}
        whole = self.cluster(outcomes=outcomes)
        whole.run()
        runs = []
        for call in [self.call_of(whole, m) for m in ('"ConfigMap"', "get job demo-data-run", "delete networkpolicy")]:
            def run(call=call):
                cluster = self.cluster(outcomes=outcomes, trigger={"call": call, "action": "HUP"})
                return call, cluster.run(), cluster.state()
            runs.append(run)
        for call, result, state in run_all(runs):
            with self.subTest(call=call):
                self.assertEqual(129, result.returncode, result.stderr)
                self.assertIsNone(state["lock"], result.stderr)
                self.assertEqual("delete", state["lock_events"][-1][0])

    def test_a_signal_at_any_call_of_make_open_releases_the_lock_and_kill_leaves_it_for_the_operator(self):
        whole = self.opened()
        self.assertEqual(0, whole.open().returncode)
        count = whole.state()["kubectl_calls"]
        made, deleted = self.call_of(whole, '"ConfigMap"'), self.call_of(whole, "delete networkpolicy")
        # One of HUP, INT and TERM (the same trap) at every call, KILL right after the lock and right after the opening.
        plan = [(call, ("HUP", "INT", "TERM")[call % 3]) for call in range(1, count + 1)] + [(made, "KILL"), (deleted, "KILL")]

        def interrupted(call, action):
            def run():
                cluster = self.opened(trigger={"call": call, "action": action})
                result = cluster.open()
                after = cluster.state()
                cluster.change(trigger=None)
                if after["lock"] is None:
                    return call, action, result, after, None
                cluster.advance(3600)
                again, generated = cluster.open(), cluster.run()
                at_refusals = cluster.state()
                cluster.change(lock=None)
                return call, action, result, after, (again, generated, at_refusals, cluster.open(), cluster.state())
            return run

        for call, action, result, after, killed in run_all([interrupted(call, action) for call, action in plan]):
            with self.subTest(call=call, action=action):
                if action != "KILL":
                    self.assertIsNone(after["lock"], result.stderr)
                    continue
                self.assertIsNotNone(after["lock"])
                again, generated, at_refusals, opened, final = killed
                holder = "open on "
                self.assert_refused_by_the_lock(again, holder=holder)
                self.assert_refused_by_the_lock(generated, holder=holder)
                self.assertNotIn("type tak", generated.stdout)
                for key in ("events", "netpol", "secrets", "lock"):
                    self.assertEqual(after[key], at_refusals[key], key)
                self.assertEqual(0, opened.returncode, opened.stderr)
                self.assertIsNone(final["netpol"])
                self.assertIsNone(final["lock"])

    def test_make_generate_killed_leaves_the_lock_and_both_targets_refuse_until_the_operator_deletes_it(self):
        cluster = self.cluster(trigger={"call": self.lock_call(), "action": "KILL"})
        self.assertNotEqual(0, cluster.run().returncode)
        cluster.change(trigger=None)
        left = cluster.state()["lock"]
        self.assertIsNotNone(left)
        self.assertEqual([], cluster.events())
        for script in (GENERATE, OPEN):
            with self.subTest(script=script.name):
                result = cluster.run(script)
                self.assert_refused_by_the_lock(result, holder="generate PROFILE=medium on ")
                self.assertEqual([], cluster.events())
                self.assertEqual(left, cluster.state()["lock"])
        # Once the operator deleted it, make generate gets to its question again.
        cluster.change(lock=None)
        result = cluster.run(answer="nie\n")
        self.assertIn("not confirmed", result.stderr)

    def test_the_trap_never_deletes_the_lock_of_another(self):
        """The operator deleted the lock of a run that still went on, and another took it: neither the trap of make generate nor the
        one of make open deletes that lock, and the make open of make generate refuses on it."""
        def generate():
            cluster = self.cluster(inject_after={"event": "create NetworkPolicy shop-closed", "changes": {"lock": held()}})
            return cluster.run(), cluster.state()

        def open_():
            cluster = self.opened(inject_after={"event": "clear api-7c9f-a", "changes": {"lock": held()}})
            return cluster.open(), cluster.state()

        (result, state), (_, opened) = run_all([generate, open_])
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn(f"held by {HOLDER}", result.stderr)
        self.assertEqual(held(), state["lock"])
        self.assertIsNotNone(state["netpol"])
        self.assertNotIn("delete networkpolicy shop-closed", state["events"])
        self.assertEqual(held(), opened["lock"])
        self.assertEqual(["create"], [e[0] for e in opened["lock_events"]])


class Secret(Case):
    def test_the_secret_is_deleted_after_a_failure_and_not_touched_before_tak(self):
        outcomes = {**initial_state()["outcomes"], "demo-data-": {"outcome": "Failed", "duration": 60, "message": "exit 75: no room"}}
        cluster = self.cluster(outcomes=outcomes)
        self.assertEqual(1, cluster.run().returncode)
        self.assertEqual({}, cluster.state()["secrets"])
        self.assertEqual("delete secret demo-photos", cluster.events()[-1])
        cluster = self.cluster(secrets={"demo-photos": {"kind": "Secret", "metadata": {"name": "demo-photos"}}})
        self.assert_unchanged(cluster, cluster.run(answer="nie\n"))

    def test_a_hangup_of_the_terminal_deletes_the_secret_too(self):
        """HUP (the terminal or the SSH session of the operator closed) ends make generate like INT and TERM."""
        outcomes = {**initial_state()["outcomes"], "demo-data-": {"outcome": "Running", "duration": 0}}
        whole = self.cluster(outcomes=outcomes)
        whole.run(GENERATE_POLL="3600")
        kubectl = [c for c in whole.calls() if c["image"] == "registry.k8s.io/kubectl"]
        (made,) = [i for i, c in enumerate(kubectl) if '"Secret"' in c["stdin"]]
        for call in (made + 1, made + 4):
            with self.subTest(after=kubectl[call - 1]["args"]):
                cluster = self.cluster(outcomes=outcomes, trigger={"call": call, "action": "HUP"})
                result = cluster.run(GENERATE_POLL="3600")
                self.assertEqual(129, result.returncode, result.stderr)
                self.assertIn("create Secret demo-photos", cluster.events())
                self.assertEqual({}, cluster.state()["secrets"])

    def test_a_secret_left_by_a_killed_run_is_replaced(self):
        cluster = self.cluster(secrets={"demo-photos": {"kind": "Secret", "metadata": {"name": "demo-photos"}}})
        result = cluster.run()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(CLOSING + ["delete secret demo-photos"] + GENERATION, without_stamps(cluster.events())[:5])


class CheckCronJobs(Case):
    """The Jobs of the CronJobs of the short checks rate-limit-check, queue-check and order-expiry (every 5 minutes, at most 60, 120
    and 600 s) run all the time: they block neither target in any of the three readings of the active Jobs (make generate before tak,
    make generate again right before the Job demo-data-run, make open). The owner decides, not the name: a Job without an owner, a Job
    of another CronJob and a Job whose owner is not a CronJob block like any other."""

    CHECKS = ("rate-limit-check", "queue-check", "order-expiry")
    # (name, owner, kind of the owner): Jobs that look like those of the checks and block.
    LOOKALIKES = (("queue-check-123", None, None), ("order-expiry-29925735", "backup", "CronJob"),
                  ("queue-check-29925735", "demo-data", "CronJob"), ("queue-check-29925740", "queue-check", "Job"),
                  ("order-expiry-29925740", "order-expiry", "Deployment"), ("rate-limit-check-29000000", None, None),
                  ("cart-purge-expired-2934", "cart-purge-expired", "CronJob"))

    @staticmethod
    def running(owner, kind="CronJob"):
        return dict(finished_job("Running", owner), **({"owner_kind": kind} if kind else {}))

    def with_previous(self, **changes):
        return self.cluster(jobs={**initial_state()["jobs"], JOB: finished_job(previous=True)}, netpol=CLOSED, **changes)

    def job_after_the_secret(self, name, job):
        """The Job starts after the first check, between the Secret and the reading right before the Job demo-data-run."""
        jobs = {**initial_state()["jobs"], JOB: finished_job(previous=True), name: job}
        return self.with_previous(inject_after={"event": "create Secret demo-photos", "changes": {"jobs": jobs}})

    def test_a_job_of_a_check_that_runs_through_the_whole_run_blocks_no_reading(self):
        for check in self.CHECKS:
            with self.subTest(check=check):
                name = f"{check}-29925735"
                cluster = self.cluster(jobs={**initial_state()["jobs"], name: self.running(check)})
                result = cluster.run()
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertNotIn(name, result.stderr)
                self.assertIn(f"create Job {JOB}", cluster.events())
                self.assertIsNone(cluster.state()["netpol"])
                self.assertIn(name, cluster.state()["jobs"])

    def test_a_job_of_a_check_that_starts_right_before_the_job_demo_data_run_does_not_stop_the_run(self):
        for check in self.CHECKS:
            with self.subTest(check=check):
                name = f"{check}-29925740"
                cluster = self.job_after_the_secret(name, self.running(check))
                result = cluster.run()
                self.assertEqual(0, result.returncode, result.stderr)
                events = cluster.events()
                self.assertEqual(f"create Job {JOB}", events[events.index(f"delete job {JOB}") + 1])
                self.assertNotIn("previous", cluster.state()["jobs"][JOB])
                self.assertIsNone(cluster.state()["netpol"])

    def test_make_open_by_hand_opens_while_a_job_of_a_check_runs(self):
        for check in self.CHECKS:
            with self.subTest(check=check):
                cluster = self.cluster(jobs={**initial_state()["jobs"], JOB: finished_job(), f"{check}-29925745": self.running(check)},
                                       netpol=CLOSED)
                result = cluster.open()
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(OPENING, without_stamps(cluster.events()))

    def test_a_job_that_only_looks_like_one_of_a_check_blocks_every_reading(self):
        for name, owner, kind in self.LOOKALIKES:
            job = self.running(owner, kind)
            with self.subTest(name=name, owner=owner, kind=kind, reading="generate before tak"):
                cluster = self.cluster(jobs={**initial_state()["jobs"], name: job})
                result = cluster.run()
                self.assert_unchanged(cluster, result)
                self.assertIn(name, result.stderr)
            with self.subTest(name=name, owner=owner, kind=kind, reading="generate before the Job demo-data-run"):
                cluster = self.job_after_the_secret(name, job)
                result = cluster.run()
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertIn(f"Jobs are active in the namespace shop: {name}", result.stderr)
                self.assertNotIn(f"delete job {JOB}", cluster.events())
                self.assertTrue(cluster.state()["jobs"][JOB]["previous"])
            with self.subTest(name=name, owner=owner, kind=kind, reading="open"):
                cluster = self.cluster(jobs={**initial_state()["jobs"], JOB: finished_job(), name: job}, netpol=CLOSED)
                result = cluster.open()
                self.assert_unchanged(cluster, result)
                self.assertIn(name, result.stderr)


if __name__ == "__main__":
    unittest.main()

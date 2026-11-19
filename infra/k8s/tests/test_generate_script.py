"""make -C infra/k8s generate and make -C infra/k8s open: scripts/generate.sh and scripts/open.sh (docs/features/demo-scale/spec.md)
against a stub of docker that plays the cluster for kubectl (the CronJobs demo-data and load, the Jobs, the workloads, the node, the rule
shop-closed, Traefik, the Secret of the photo pool, Postgres and Elasticsearch, all in a state file), curl of the probe and the python
of tools.sh. date and sleep share a clock in a file, so the waits take no time. Every change in the cluster is an event of the state."""

import base64
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
    # Another copy of the target makes its Job right after this event.
    inject = state.get("inject_job_after")
    if inject and inject["event"] == text:
        state["jobs"][JOB] = inject["job"]
        state["inject_job_after"] = None


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
        meta["ownerReferences"] = [{"apiVersion": "batch/v1", "kind": "CronJob", "name": job["owner"], "controller": True}]
    return {"apiVersion": "batch/v1", "kind": "Job", "metadata": meta, "spec": {"activeDeadlineSeconds": job["deadline"]},
            "status": {"conditions": [] if now == "Running" else [{"type": now, "status": "True"}]}}


if image.startswith("curlimages/curl"):
    closed = state["netpol"] is not None and state["traefik_restarts"] > state["netpol_made_at"]
    spec = state["probes"].get(rest[-1], "auto")
    sys.stdout.write(("502" if closed else "200") if spec == "auto" else spec)
    sys.exit(0)

state["kubectl_calls"] += 1
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
    }
    state.update(changes)
    return state


CLOSED = {"apiVersion": "networking.k8s.io/v1", "kind": "NetworkPolicy", "metadata": {"name": "shop-closed"}}


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

    def assert_unchanged(self, cluster, result, code=1):
        self.assertEqual(code, result.returncode, result.stdout + result.stderr)
        self.assertIn("nothing was changed", result.stderr)
        self.assertEqual([], cluster.events(), result.stderr)


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
        # medium: max(32, 1.25 x 21) = 32 GB; server: max(32, 1.25 x 96) = 120 GB; small (on k3d): minFreeGb only.
        small = demo_data_cronjob("small", None, 2, 1800)
        for profile, cron, free, refused in (("medium", None, 31.9, "32"), ("server", None, 119, "120.00"), ("small", small, 1.9, "2 GB"),
                                             ("small", small, 3, None), ("seed", None, 1, None), ("seed", None, "a lot", "free space"),
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

    def test_the_rule_and_the_job_carry_no_tracking_of_argo_cd(self):
        """Argo CD 3 (argo-cd chart 10.x, no resourceTrackingMethod in argocd/install/argocd.yaml) tracks by the annotation
        argocd.argoproj.io/tracking-id: the rule has neither labels nor annotations, and the Job of the generator gets only what
        kubectl create job --from gives it (the labels of the jobTemplate, the owner CronJob), no annotation of Argo CD."""
        cluster = self.cluster()
        cluster.run()
        created = [json.loads(c["stdin"]) for c in cluster.calls() if c["stdin"] and c["args"][-3:] == ["create", "-f", "-"]]
        (rule,) = [d for d in created if d["kind"] == "NetworkPolicy"]
        self.assertEqual({"name": "shop-closed"}, rule["metadata"])
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
        # The active Jobs are read again right before it (another copy of make generate may run its Job).
        self.assertEqual(["-n", "shop", "get", "jobs", "-o", "json"], calls[at - 1])

    def test_a_closing_that_the_probe_does_not_confirm_keeps_the_previous_run_and_open_opens_on_it_once_the_probe_works(self):
        cluster = self.with_previous(netpol=None, probes={API_URL: "auto", STORE_URL: "403"})
        result = cluster.run(GENERATE_CLOSE_WAIT="60")
        self.assertEqual(1, result.returncode, result.stderr)
        state = cluster.state()
        self.assertIsNotNone(state["netpol"])
        self.assertTrue(state["jobs"].get(JOB, {}).get("previous"), state["events"])
        self.assertEqual(CLOSING, state["events"])
        self.assertIn("make open", result.stderr)
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
        """The fixed name is the lock: the Job demo-data-run of another make generate is not replaced."""
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
    (KILL, or its deletion failed)."""

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
            opened = cluster.open(GENERATE_POLL="30")
            return call, action, generated, after, opened, cluster.state()
        return run

    def test_make_open_never_takes_the_previous_run_and_the_secret_does_not_stay(self):
        self.assertGreater(len(self.calls), self.before_tak + 5, self.calls)
        runs = [self.interrupted(call, action) for call in range(1, len(self.calls) + 1) for action in self.ACTIONS]
        for call, action, generated, after, opened, final in run_all(runs):
            kubectl = self.calls[call - 1]
            with self.subTest(call=call, action=action, kubectl=kubectl):
                self.assertNotEqual(0, generated.returncode, generated.stderr)
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


class Secret(Case):
    def test_the_secret_is_deleted_after_a_failure_and_not_touched_before_tak(self):
        outcomes = {**initial_state()["outcomes"], "demo-data-": {"outcome": "Failed", "duration": 60, "message": "exit 75: no room"}}
        cluster = self.cluster(outcomes=outcomes)
        self.assertEqual(1, cluster.run().returncode)
        self.assertEqual({}, cluster.state()["secrets"])
        self.assertEqual("delete secret demo-photos", cluster.events()[-1])
        cluster = self.cluster(secrets={"demo-photos": {"kind": "Secret", "metadata": {"name": "demo-photos"}}})
        self.assert_unchanged(cluster, cluster.run(answer="nie\n"))

    def test_a_secret_left_by_a_killed_run_is_replaced(self):
        cluster = self.cluster(secrets={"demo-photos": {"kind": "Secret", "metadata": {"name": "demo-photos"}}})
        result = cluster.run()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(CLOSING + ["delete secret demo-photos"] + GENERATION, without_stamps(cluster.events())[:5])


class RateLimitCheck(Case):
    """The Jobs of the CronJob rate-limit-check (every 5 minutes, one minute at most) run all the time: they block neither target.
    The owner decides, not the name."""

    def test_only_a_job_owned_by_the_cronjob_rate_limit_check_is_not_an_active_job(self):
        for job, blocks in ((finished_job("Running", "rate-limit-check"), False), (finished_job("Running", None), True),
                            (finished_job("Running", "cart-purge-expired"), True)):
            for script in (GENERATE, OPEN):
                with self.subTest(owner=job["owner"], script=script.name):
                    jobs = {**initial_state()["jobs"], "rate-limit-check-29000000": job, JOB: finished_job()}
                    cluster = self.cluster(jobs=jobs, netpol=CLOSED)
                    result = cluster.run(script, answer="nie\n")
                    if blocks:
                        self.assert_unchanged(cluster, result)
                        self.assertIn("rate-limit-check-29000000", result.stderr)
                    else:
                        self.assertNotIn("rate-limit-check-29000000", result.stderr)
                        self.assertEqual(1 if script == GENERATE else 0, result.returncode, result.stderr)


if __name__ == "__main__":
    unittest.main()

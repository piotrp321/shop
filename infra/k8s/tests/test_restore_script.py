"""make -C infra/k8s restore and backups: scripts/restore.sh and scripts/backups.sh (docs/features/deploy-ops/spec.md, O4) against a
stub of docker that plays a cluster for kubectl (Jobs, Deployments, CronJobs and the applications of Argo CD in a state file) and
runs the python of tools.sh: the refusals that change nothing, the confirmation, the backup pre-restore, the order of the steps
with Argo CD and without, and what a failure leaves."""

import json
import os
import pathlib
import subprocess
import tempfile
import unittest

from support import REPO

RESTORE = REPO / "infra/k8s/scripts/restore.sh"
BACKUPS = REPO / "infra/k8s/scripts/backups.sh"
RERUN = REPO / "infra/k8s/scripts/rerun.sh"
SNAPSHOT = "9e050a3d"
PRE_RESTORE = "5a6b7c8d"
CHECK_LOG = (
    "restore: snapshot 9e050a3d569735e3: time 2026-11-11T03:00:05+01:00, host 0b6f1d9e-4c3a, tags nightly, revision 0123abcd, "
    "28 migrations, 12 objects (3456 bytes)\nrestore: the snapshot can be restored: every migration of it is in the deployed code\n"
)
LIST_LOG = (
    "restore: the snapshots of the repository, the oldest first (identifier, time, host, tags, revision):\n"
    "4f3a2b1c  2026-11-04T03:00:07+01:00  7c1d2e3f-0a9b  nightly  89abcdef\n"
    "9e050a3d  2026-11-11T03:00:05+01:00  0b6f1d9e-4c3a  nightly  0123abcd\n"
)
NEWER_LOG = "restore: the snapshot has migrations that the deployed code does not know (the snapshot is newer than the deployed code): DoctrineMigrations\\Version2999\n"

# The stub of docker: `docker run ... <image> <arguments>`. The python image runs python itself; the kubectl image plays the
# cluster of $STUB_DIR/state.json. Every kubectl call is a JSON line (its arguments) in $STUB_LOG.
DOCKER = r'''#!/usr/bin/env python3
import json, os, sys

args = sys.argv[1:]
assert args[0] == "run", args
i = 1
while args[i].startswith("-"):
    i += 2 if args[i] in ("-u", "-e", "-v", "-w", "--network") else 1
image, rest = args[i], args[i + 1:]
if image.startswith("python:"):
    os.execvp(rest[0], rest)

state_path = os.path.join(os.environ["STUB_DIR"], "state.json")
with open(state_path) as f:
    state = json.load(f)
with open(os.environ["STUB_LOG"], "a") as f:
    f.write(json.dumps(rest) + "\n")


def save():
    with open(state_path, "w") as f:
        json.dump(state, f)


def option(name, default=None):
    """The value of an option given as `--name value` or `--name=value`."""
    for index, arg in enumerate(rest):
        if arg == name:
            return rest[index + 1]
        if arg.startswith(name + "="):
            return arg.split("=", 1)[1]
    return default


def outcome(job):
    for prefix, result, log in state["outcomes"]:
        if job.startswith(prefix):
            return result, log
    return "Complete", ""


def job_doc(name):
    job = state["jobs"][name]
    conditions = [] if job["state"] == "Running" else [{"type": job["state"], "status": "True"}]
    return {"apiVersion": "batch/v1", "kind": "Job",
            "metadata": {"name": name, "namespace": "shop", "uid": "u-" + name, "resourceVersion": "1", "labels": job["labels"]},
            "spec": {"activeDeadlineSeconds": job["deadline"], "selector": {"matchLabels": {"controller-uid": "u"}},
                     "template": {"metadata": {"labels": dict(job["labels"], **{"controller-uid": "u", "batch.kubernetes.io/job-name": name})},
                                  "spec": {"containers": [{"name": "c", "env": [{"name": k, "value": v} for k, v in job["env"].items()]}]}}},
            "status": {"conditions": conditions}}


def fail(message, code=1):
    sys.stderr.write(message + "\n")
    sys.exit(code)


if rest[:2] == ["-n", "argocd"] or rest[:2] == ["-n", "shop"] or rest[:2] == ["-n", "other"]:
    namespace, rest = rest[1], rest[2:]
else:
    namespace = None
verb = rest[0]
if state.get("broken") and verb != "set":
    fail("The connection to the server was refused")

if verb == "get":
    kind = rest[1]
    names = [a for a in rest[2:] if not a.startswith("-") and a not in (option("-o"), option("-l"))]
    if kind == "crd":
        print("customresourcedefinition.apiextensions.k8s.io/applications.argoproj.io" if state["argocd"] else "")
    elif kind == "application":
        app = state["apps"].get(names[0])
        if app is None:
            print("")
        else:
            doc = {"metadata": {"name": names[0]}, "spec": {"syncPolicy": {"automated": app["automated"]} if app["automated"] is not None else {}},
                   "status": {"operationState": {"phase": app.get("phase", "Succeeded")}}}
            if app.get("operation"):
                doc["operation"] = {"sync": {}}
            print(json.dumps(doc))
    elif kind in ("jobs", "job"):
        if not names:
            # A Job that finishes after n listings of the Jobs of the namespace.
            for job in state["jobs"].values():
                if job["state"] == "Running" and job.get("complete_after_lists") is not None:
                    job["complete_after_lists"] -= 1
                    if job["complete_after_lists"] <= 0:
                        job["state"] = "Complete"
            save()
        if names:
            if names[0] not in state["jobs"]:
                fail(f'Error from server (NotFound): jobs.batch "{names[0]}" not found')
            print(json.dumps(job_doc(names[0])))
        else:
            selector = dict(part.split("=") for part in option("-l").split(",")) if option("-l") else {}
            items = [job_doc(n) for n, j in state["jobs"].items() if all(j["labels"].get(k) == v for k, v in selector.items())]
            print(json.dumps({"items": items}))
    elif kind == "deployment":
        print(json.dumps({"items": [{"metadata": {"name": n}, "spec": {"replicas": state["deployments"][n]}} for n in names]}))
    elif kind == "cronjob":
        print(json.dumps({"items": [{"metadata": {"name": n}, "spec": {"suspend": state["cronjobs"][n]["suspend"]}} for n in names]}))
    elif kind == "pods":
        print("")
    else:
        fail("unknown kind " + kind)
elif verb == "create" and rest[1] == "job":
    name, source = rest[2], option("--from").split("/", 1)[1]
    if source not in state["cronjobs"]:
        fail(f'Error from server (NotFound): cronjobs.batch "{source}" not found')
    cron = state["cronjobs"][source]
    print(json.dumps({"apiVersion": "batch/v1", "kind": "Job", "metadata": {"name": name, "labels": {"app.kubernetes.io/name": source}},
                      "spec": {"activeDeadlineSeconds": cron["deadline"],
                               "template": {"spec": {"containers": [{"name": "c", "env": [{"name": k, "value": v} for k, v in cron["env"].items()]}]}}}}))
elif verb == "set":
    doc = json.load(sys.stdin)
    changed = False
    for pair in [a for a in rest if "=" in a and not a.startswith("-")]:
        key, value = pair.split("=", 1)
        for container in doc["spec"]["template"]["spec"]["containers"]:
            changed = changed or {"name": key, "value": value} not in container["env"]
            container["env"] = [e for e in container["env"] if e["name"] != key] + [{"name": key, "value": value}]
    # As kubectl: an object that the variables do not change is not printed at all.
    if changed:
        print(json.dumps(doc))
elif verb == "create":
    doc = json.load(sys.stdin)
    name = doc["metadata"]["name"]
    if name in state["jobs"]:
        fail(f'Error from server (AlreadyExists): jobs.batch "{name}" already exists')
    result, log = outcome(name)
    env = {e["name"]: e["value"] for e in doc["spec"]["template"]["spec"]["containers"][0]["env"]}
    state["jobs"][name] = {"labels": doc["metadata"].get("labels", {}), "state": result, "log": log, "env": env,
                           "deadline": doc["spec"].get("activeDeadlineSeconds", 1800)}
    state["created"].append(name)
    save()
elif verb == "delete":
    state["jobs"].pop(rest[2], None)
    state["deleted"].append(rest[2])
    if rest[2].startswith("restore-check-"):
        # The end of the checks of the step 1: the Jobs that start from now on (a nightly backup).
        state["jobs"].update(state.get("late_jobs", {}))
    save()
elif verb == "logs":
    name = rest[1].split("/", 1)[1]
    # A failed stage leaves the later containers of the pod never started: kubectl logs --all-containers then fails on them.
    if "--all-containers" in rest and state["jobs"][name]["state"] == "Failed" and name.startswith("restore-"):
        fail(f'Error from server (BadRequest): container "redis" in pod "{name}-x1" is waiting to start: PodInitializing')
    print(state["jobs"][name]["log"], end="")
elif verb == "scale":
    state["deployments"][rest[2]] = int(option("--replicas"))
    save()
elif verb == "patch":
    kind, name, patch = rest[1], rest[2], json.loads(option("-p"))
    if kind == "cronjob":
        state["cronjobs"][name]["suspend"] = patch["spec"]["suspend"]
    elif option("--type") == "json":
        # As the apiserver: `remove` of a path that is not there is an error, and nothing changes.
        assert patch == [{"op": "remove", "path": "/spec/syncPolicy/automated"}], patch
        if state["apps"][name]["automated"] is None:
            fail(f'The request is invalid: the server rejected our request due to an error in our request: remove operation does not apply: '
                 f'doc is missing path: "/spec/syncPolicy/automated" (application {name})')
        state["apps"][name]["automated"] = None
    else:
        # A merge patch: automated null removes the key, a missing key stays missing.
        state["apps"][name]["automated"] = patch["spec"]["syncPolicy"]["automated"]
    save()
elif verb == "rollout":
    name = rest[2].split("/", 1)[1]
    if name in state.get("rollout_fails", []):
        fail(f'error: deployment "{name}" exceeded its progress deadline')
else:
    fail("unknown kubectl call " + " ".join(rest))
'''

AUTOMATED = {"prune": True, "selfHeal": True}


def initial_state(argocd=False):
    version = {"shop/job-kind": "version"}
    return {
        "argocd": argocd,
        "apps": {"root": {"automated": dict(AUTOMATED)}, "shop": {"automated": dict(AUTOMATED)}} if argocd else {},
        "jobs": {
            name: {"labels": {"app.kubernetes.io/name": logical, **version}, "state": "Complete", "log": "", "env": {}, "deadline": 1800}
            for name, logical in (("garage-init-1a", "garage-init"), ("migrate-2b", "migrate"), ("keycloak-config-3c", "keycloak-config"),
                                  ("initialize-4d", "initialize"), ("purge-caches-5e", "purge-caches"))
        },
        "deployments": {"api": 1, "worker": 1, "store": 1, "keycloak": 1},
        "cronjobs": {
            "restore": {"suspend": True, "deadline": 10800, "env": {"RESTORE_MODE": "list", "RESTORE_SNAPSHOT": ""}},
            "backup": {"suspend": False, "deadline": 7200, "env": {}},
            "cart-purge-expired": {"suspend": False, "deadline": None, "env": {}},
        },
        "outcomes": [["restore-check-", "Complete", CHECK_LOG], ["restore-list-", "Complete", LIST_LOG],
                     ["backup-pre-restore-", "Complete", f"backup: snapshot with the tag pre-restore\nsnapshot {PRE_RESTORE} saved\nbackup: done\n"]],
        "created": [],
        "deleted": [],
    }


class ClusterStub:
    def __init__(self, tmp, state):
        self.root = pathlib.Path(tmp)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        (self.bin / "docker").write_text(DOCKER)
        (self.bin / "docker").chmod(0o755)
        # sleep of the waiting loop: the stub answers at once, nothing to wait for.
        (self.bin / "sleep").write_text("#!/bin/sh\nexit 0\n")
        (self.bin / "sleep").chmod(0o755)
        self.kube = self.root / "kube"
        self.kube.mkdir()
        (self.kube / "config").write_text("apiVersion: v1\n")
        self.log = self.root / "log"
        self.log.write_text("")
        (self.root / "state.json").write_text(json.dumps(state))

    def run(self, script, *args, answer="tak\n", kube_dir=True, **env):
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": str(self.root),
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.root),
            "TOOLS_CACHE": str(self.root / "cache"),
            **({"KUBE_DIR": str(self.kube)} if kube_dir is True else {"KUBE_DIR": kube_dir} if kube_dir else {}),
            **env,
        }
        return subprocess.run(["sh", str(script), *args], input=answer, env=variables, capture_output=True, text=True)

    def state(self):
        return json.loads((self.root / "state.json").read_text())

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()]


def strip_namespace(call):
    return call[2:] if call[:1] == ["-n"] else call


class RestoreScriptTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.tmp.cleanup()

    def stub(self, argocd=False, **changes):
        state = initial_state(argocd)
        state.update(changes)
        return ClusterStub(tempfile.mkdtemp(dir=self.tmp.name), state)

    def restore(self, cluster, snapshot=SNAPSHOT, **env):
        return cluster.run(RESTORE, **({"SNAPSHOT": snapshot} if snapshot is not None else {}), **env)

    def assert_unchanged(self, cluster, argocd=False, allowed_jobs=("restore-check-",)):
        """Nothing in the cluster changed: the replicas, the CronJobs, Argo CD, and no Job but the check is created or deleted."""
        before, after = initial_state(argocd), cluster.state()
        self.assertEqual(before["deployments"], after["deployments"])
        self.assertEqual({n: c["suspend"] for n, c in before["cronjobs"].items()}, {n: c["suspend"] for n, c in after["cronjobs"].items()})
        self.assertEqual(before["apps"], after["apps"])
        self.assertEqual([], [n for n in after["created"] if not n.startswith(allowed_jobs)])
        self.assertEqual([], [n for n in after["deleted"] if not n.startswith(allowed_jobs)])
        self.assertEqual(sorted(before["jobs"]), sorted(n for n in after["jobs"] if not n.startswith(allowed_jobs)))
        for call in cluster.calls():
            self.assertNotIn(strip_namespace(call)[0], ("scale", "patch", "rollout"), call)

    def changing_calls(self, cluster):
        """The kubectl calls that change something, without the namespace option, as short strings."""
        found = []
        for call in map(strip_namespace, cluster.calls()):
            if call[0] in ("scale", "patch", "rollout"):
                found.append(" ".join(call[:3]))
        return found

    # Arguments and the confirmation

    def test_without_snapshot_or_with_latest_nothing_runs(self):
        for snapshot in (None, "", "latest", "9e05", "9E050A3D", "abc;def"):
            cluster = self.stub()
            result = self.restore(cluster, snapshot=snapshot)
            self.assertEqual(2, result.returncode, (snapshot, result.stderr))
            self.assertIn("SNAPSHOT", result.stderr, snapshot)
            self.assertEqual([], cluster.calls(), snapshot)

    def test_an_unknown_value_of_pre_restore_is_refused(self):
        cluster = self.stub()
        result = self.restore(cluster, PRE_RESTORE="no")
        self.assertEqual(2, result.returncode)
        self.assertIn("PRE_RESTORE", result.stderr)
        self.assertEqual([], cluster.calls())

    def test_the_kubeconfig_is_required(self):
        cluster = self.stub()
        result = cluster.run(RESTORE, kube_dir=None, SNAPSHOT=SNAPSHOT)
        self.assertEqual(2, result.returncode)
        self.assertIn("KUBE_DIR", result.stderr)
        result = cluster.run(RESTORE, kube_dir="/nonexistent", SNAPSHOT=SNAPSHOT)
        self.assertEqual(1, result.returncode)
        self.assertEqual([], cluster.calls())

    def test_without_the_word_tak_nothing_changes(self):
        for answer in ("nie\n", "\n", "", "TAK\n", "tak tak\n", "yes\n"):
            cluster = self.stub()
            result = cluster.run(RESTORE, answer=answer, SNAPSHOT=SNAPSHOT)
            self.assertEqual(1, result.returncode, repr(answer))
            self.assertIn("nothing changed", result.stderr, repr(answer))
            self.assert_unchanged(cluster)

    def test_it_says_what_it_will_do_and_which_snapshot_before_it_asks(self):
        cluster = self.stub()
        result = cluster.run(RESTORE, answer="nie\n", SNAPSHOT=SNAPSHOT)
        before = result.stdout.split("type tak")[0]
        for part in ("9e050a3d569735e3", "2026-11-11T03:00:05+01:00", "host 0b6f1d9e-4c3a", "nightly", "revision 0123abcd", "28 migrations",
                     "12 objects", "pre-restore", "shop", "keycloak", "product-images", "Redis", "RabbitMQ", "api, worker, store and keycloak",
                     "cart-purge-expired", "migrate", "keycloak-config", "catalog:reindex", "purge-caches"):
            self.assertIn(part, before)

    def test_the_check_job_is_from_the_cronjob_restore_in_the_mode_check_and_is_removed(self):
        cluster = self.stub()
        cluster.run(RESTORE, answer="nie\n", SNAPSHOT=SNAPSHOT)
        creates = [strip_namespace(call) for call in cluster.calls() if strip_namespace(call)[:2] == ["create", "job"]]
        self.assertEqual(1, len(creates))
        self.assertIn("--from=cronjob/restore", creates[0])
        self.assertIn("--dry-run=client", creates[0])
        (check,) = [name for name in cluster.state()["created"]]
        self.assertTrue(check.startswith("restore-check-"))
        self.assertIn(check, cluster.state()["deleted"])
        sets = [call for call in cluster.calls() if call[0] == "set"]
        self.assertIn("--local", sets[0])
        self.assertIn("RESTORE_MODE=check", sets[0])
        self.assertIn(f"RESTORE_SNAPSHOT={SNAPSHOT}", sets[0])

    # Refusals before the confirmation

    def test_an_active_job_in_the_namespace_is_refused(self):
        jobs = initial_state()["jobs"]
        jobs["backup-29341234"] = {"labels": {"app.kubernetes.io/name": "backup"}, "state": "Running", "log": "", "env": {}, "deadline": 7200}
        cluster = self.stub(jobs=jobs)
        result = self.restore(cluster)
        self.assertEqual(1, result.returncode)
        self.assertIn("backup-29341234", result.stderr)
        self.assertNotIn("type tak", result.stdout)
        self.assertEqual([], cluster.state()["created"])
        self.assertEqual([], cluster.state()["deleted"])
        self.assertEqual(initial_state()["deployments"], cluster.state()["deployments"])

    def test_a_synchronization_of_shop_in_progress_is_refused(self):
        for app in ({"automated": dict(AUTOMATED), "phase": "Running"}, {"automated": dict(AUTOMATED), "operation": True}):
            cluster = self.stub(argocd=True, apps={"root": {"automated": dict(AUTOMATED)}, "shop": app})
            result = self.restore(cluster)
            self.assertEqual(1, result.returncode, app)
            self.assertIn("synchroniz", result.stderr, app)
            self.assertNotIn("type tak", result.stdout)
            self.assertEqual([], cluster.state()["created"], app)
            self.assertEqual(dict(AUTOMATED), cluster.state()["apps"]["root"]["automated"])

    def test_a_snapshot_newer_than_the_code_is_refused_before_the_question_the_backup_and_the_scaling(self):
        for argocd in (False, True):
            outcomes = initial_state()["outcomes"]
            outcomes[0] = ["restore-check-", "Failed", NEWER_LOG]
            cluster = self.stub(argocd=argocd, outcomes=outcomes)
            result = self.restore(cluster)
            self.assertEqual(1, result.returncode, argocd)
            self.assertIn("newer than the deployed code", result.stderr)
            self.assertIn("nothing changed", result.stderr)
            self.assertNotIn("type tak", result.stdout)
            self.assert_unchanged(cluster, argocd)

    def test_an_unreachable_cluster_stops_it_before_any_change(self):
        cluster = self.stub(broken=True)
        result = self.restore(cluster)
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn("type tak", result.stdout)

    def test_a_deployed_version_without_its_jobs_is_refused(self):
        jobs = initial_state()["jobs"]
        del jobs["keycloak-config-3c"]
        cluster = self.stub(jobs=jobs)
        result = self.restore(cluster)
        self.assertEqual(1, result.returncode)
        self.assertIn("keycloak-config", result.stderr)
        self.assertEqual([], cluster.state()["created"])

    # The backup pre-restore

    def test_the_backup_pre_restore_is_a_job_of_the_cronjob_backup_with_its_tag_before_any_change(self):
        cluster = self.stub()
        result = self.restore(cluster)
        self.assertEqual(0, result.returncode, result.stderr)
        backup = [n for n in cluster.state()["created"] if n.startswith("backup-pre-restore-")]
        self.assertEqual(1, len(backup))
        self.assertEqual("pre-restore", cluster.state()["jobs"][backup[0]]["env"]["BACKUP_TAG"])
        order = [strip_namespace(c) for c in cluster.calls()]
        first_change = next(i for i, c in enumerate(order) if c[0] in ("scale", "patch"))
        backup_create = next(i for i, c in enumerate(order) if c[:2] == ["create", "job"] and "--from=cronjob/backup" in c)
        self.assertLess(backup_create, first_change)
        self.assertIn(PRE_RESTORE, result.stdout)

    def test_the_snapshot_pre_restore_is_found_in_a_long_log_of_the_backup(self):
        """The line `snapshot <id> saved` is not the last of the log: kubectl logs is read whole, not its last lines."""
        outcomes = initial_state()["outcomes"]
        long_log = f"backup: snapshot with the tag pre-restore\nsnapshot {PRE_RESTORE} saved\n" + "".join(f"backup: line {i}\n" for i in range(100))
        outcomes[2] = ["backup-pre-restore-", "Complete", long_log]
        cluster = self.stub(outcomes=outcomes)
        result = self.restore(cluster)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn(f"restore: the pre-restore snapshot is {PRE_RESTORE}", result.stdout)
        self.assertIn(f"the state before it is the pre-restore snapshot {PRE_RESTORE}", result.stdout)

    def test_a_failed_backup_pre_restore_stops_it_without_changes(self):
        outcomes = initial_state()["outcomes"]
        outcomes[2] = ["backup-pre-restore-", "Failed", "backup: cannot open the repository\n"]
        for argocd in (False, True):
            cluster = self.stub(argocd=argocd, outcomes=outcomes)
            result = self.restore(cluster)
            self.assertEqual(1, result.returncode, argocd)
            self.assertIn("pre-restore", result.stderr)
            self.assertIn("nothing changed", result.stderr)
            self.assertIn("PRE_RESTORE=skip", result.stderr)
            self.assert_unchanged(cluster, argocd, allowed_jobs=("restore-check-", "backup-pre-restore-"))

    def test_with_pre_restore_skip_it_says_so_before_tak_and_makes_no_backup(self):
        cluster = self.stub()
        result = self.restore(cluster, PRE_RESTORE="skip")
        self.assertEqual(0, result.returncode, result.stderr)
        before = result.stdout.split("type tak")[0]
        self.assertIn("PRE_RESTORE=skip", before)
        self.assertIn("no backup pre-restore", before)
        self.assertEqual([], [n for n in cluster.state()["created"] if n.startswith("backup-")])

    # The order of the steps

    def test_without_argo_cd_the_order_and_the_replicas_it_remembered(self):
        deployments = {"api": 1, "worker": 2, "store": 1, "keycloak": 1}
        cluster = self.stub(deployments=deployments)
        result = self.restore(cluster)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(deployments, cluster.state()["deployments"])
        self.assertEqual({"restore": True, "backup": False, "cart-purge-expired": False},
                         {n: c["suspend"] for n, c in cluster.state()["cronjobs"].items()})
        self.assertEqual([
            "patch cronjob cart-purge-expired", "patch cronjob backup",
            "scale deployment api", "scale deployment worker", "scale deployment store", "scale deployment keycloak",
            "scale deployment keycloak", "rollout status deployment/keycloak",
            "scale deployment api", "scale deployment worker", "scale deployment store",
            "patch cronjob cart-purge-expired", "patch cronjob backup",
            "rollout status deployment/api", "rollout status deployment/worker", "rollout status deployment/store",
        ], self.changing_calls(cluster))
        self.assertEqual([], [c for c in cluster.calls() if "argocd" in c and c[2] == "patch"])

    def test_after_a_restore_that_stopped_the_applications_at_zero_come_back_with_one_replica(self):
        """A restore run again after one that failed finds the applications at zero: they come back as the chart has them."""
        cluster = self.stub(deployments={"api": 0, "worker": 0, "store": 0, "keycloak": 0})
        result = self.restore(cluster)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual({"api": 1, "worker": 1, "store": 1, "keycloak": 1}, cluster.state()["deployments"])

    def test_after_a_restore_that_failed_the_cronjobs_it_left_suspended_come_back_as_the_chart_has_them(self):
        """The rerun with PRE_RESTORE=skip that README recommends finds cart-purge-expired and backup suspended: the chart never
        suspends them (Argo CD would not undo it), so they must not stay suspended for good."""
        cluster = self.stub(deployments={"api": 0, "worker": 0, "store": 0, "keycloak": 0})
        state = cluster.state()
        state["cronjobs"]["backup"]["suspend"] = True
        state["cronjobs"]["cart-purge-expired"]["suspend"] = True
        (cluster.root / "state.json").write_text(json.dumps(state))
        result = self.restore(cluster, PRE_RESTORE="skip")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual({"restore": True, "backup": False, "cart-purge-expired": False},
                         {n: c["suspend"] for n, c in cluster.state()["cronjobs"].items()})

    def test_the_jobs_run_in_order_and_the_jobs_of_the_version_under_their_names(self):
        cluster = self.stub()
        self.restore(cluster)
        created = cluster.state()["created"]
        kinds = [n.rsplit("-", 1)[0] if not n.startswith(("migrate", "keycloak-config", "purge-caches")) else n for n in created]
        self.assertEqual(["restore-check", "backup-pre-restore", "restore-run", "migrate-2b", "keycloak-config-3c", "restore-reindex",
                          "purge-caches-5e"], kinds)
        jobs = cluster.state()["jobs"]
        run = next(n for n in created if n.startswith("restore-run-"))
        self.assertEqual(("restore", SNAPSHOT), (jobs[run]["env"]["RESTORE_MODE"], jobs[run]["env"]["RESTORE_SNAPSHOT"]))
        reindex = next(n for n in created if n.startswith("restore-reindex-"))
        self.assertEqual("reindex", jobs[reindex]["env"]["RESTORE_MODE"])
        # The jobs of the version are run again under the same name (deleted, then created).
        for name in ("migrate-2b", "keycloak-config-3c", "purge-caches-5e"):
            self.assertIn(name, cluster.state()["deleted"])
        order = [strip_namespace(c) for c in cluster.calls()]
        scale_down = next(i for i, c in enumerate(order) if c[:3] == ["scale", "deployment", "keycloak"])
        run_index = next(i for i, c in enumerate(order) if c[:2] == ["create", "job"] and c[2].startswith("restore-run-"))
        self.assertLess(scale_down, run_index)

    def test_with_argo_cd_root_then_shop_off_first_and_root_on_last(self):
        cluster = self.stub(argocd=True)
        result = self.restore(cluster)
        self.assertEqual(0, result.returncode, result.stderr)
        patches = [(c[4], c[c.index("--type") + 1]) for c in cluster.calls() if c[:2] == ["-n", "argocd"] and c[2] == "patch"]
        self.assertEqual([("root", "merge"), ("shop", "merge"), ("root", "merge")], patches)
        changes = self.changing_calls(cluster)
        self.assertEqual("patch application root", changes[0])
        self.assertEqual("patch application shop", changes[1])
        self.assertEqual("patch application root", changes[-1])
        self.assertEqual(AUTOMATED, cluster.state()["apps"]["root"]["automated"])
        # shop is brought back by root (selfHeal restores its syncPolicy from git), not by the script.
        self.assertIsNone(cluster.state()["apps"]["shop"]["automated"])

    def test_without_automated_in_the_applications_the_restore_goes_through_and_root_stays_off(self):
        # A rollback of several versions or a second restore after a failed one: the applications have no automated already.
        cluster = self.stub(argocd=True, apps={"root": {"automated": None}, "shop": {"automated": None}})
        result = self.restore(cluster)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("stays off", result.stdout)
        self.assertIsNone(cluster.state()["apps"]["root"]["automated"])
        self.assertIsNone(cluster.state()["apps"]["shop"]["automated"])
        self.assertEqual(1, cluster.state()["deployments"]["api"])

    @staticmethod
    def late_job(**changes):
        return {"backup-29341234": dict({"labels": {"app.kubernetes.io/name": "backup"}, "state": "Running", "log": "", "env": {},
                                         "deadline": 7200}, **changes)}

    def test_a_job_that_starts_after_the_first_check_is_waited_for_before_the_data_is_touched(self):
        cluster = self.stub(argocd=True, late_jobs=self.late_job(complete_after_lists=3))
        result = self.restore(cluster)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("backup-29341234", result.stdout)
        calls = list(map(strip_namespace, cluster.calls()))
        suspend = next(i for i, c in enumerate(calls) if c[:3] == ["patch", "cronjob", "backup"])
        lists = [i for i, c in enumerate(calls) if c[:2] == ["get", "jobs"] and i > suspend]
        # After the suspension of the CronJobs the Jobs are read until the late one ends, and only then are the applications scaled.
        self.assertGreaterEqual(len(lists), 3)
        scale = next(i for i, c in enumerate(calls) if c[0] == "scale")
        self.assertLess(max(lists), scale)

    def test_a_job_still_active_after_the_wait_stops_the_restore_before_the_data_and_the_applications(self):
        for argocd in (False, True):
            cluster = self.stub(argocd=argocd, late_jobs=self.late_job())
            result = self.restore(cluster, RESTORE_ACTIVE_JOBS_WAIT_SECONDS="0")
            self.assertEqual(1, result.returncode, argocd)
            self.assertIn("backup-29341234", result.stderr)
            self.assertIn("step 4", result.stderr)
            self.assertIn(PRE_RESTORE, result.stderr)
            self.assertIn("README.md", result.stderr)
            self.assertNotIn("scaled to zero", result.stderr)
            state = cluster.state()
            self.assertEqual([], [n for n in state["created"] if n.startswith(("restore-run-", "restore-reindex-", "migrate"))], argocd)
            self.assertEqual(initial_state()["deployments"], state["deployments"], argocd)
            self.assertTrue(state["cronjobs"]["backup"]["suspend"])
            self.assertEqual([], [c for c in map(strip_namespace, cluster.calls()) if c[0] == "scale"])

    def test_it_prints_the_snapshot_pre_restore_at_the_end(self):
        cluster = self.stub()
        result = self.restore(cluster)
        self.assertIn(f"pre-restore snapshot {PRE_RESTORE}", result.stdout.splitlines()[-1])

    # A failure after the first change

    def test_a_failed_restore_job_leaves_the_applications_off_and_says_where_and_which_snapshot(self):
        for argocd in (False, True):
            outcomes = initial_state()["outcomes"] + [["restore-run-", "Failed", "restore: pg_restore of the dump of shop failed (exit code 1)\n"]]
            cluster = self.stub(argocd=argocd, outcomes=outcomes)
            result = self.restore(cluster)
            self.assertEqual(1, result.returncode, argocd)
            self.assertIn("step 5", result.stderr)
            self.assertIn(PRE_RESTORE, result.stderr)
            self.assertIn("pg_restore of the dump of shop failed", result.stderr)
            self.assertIn("README.md", result.stderr)
            self.assertEqual({"api": 0, "worker": 0, "store": 0, "keycloak": 0}, cluster.state()["deployments"], argocd)
            self.assertTrue(cluster.state()["cronjobs"]["backup"]["suspend"])
            self.assertTrue(cluster.state()["cronjobs"]["cart-purge-expired"]["suspend"])
            created = cluster.state()["created"]
            self.assertEqual([], [n for n in created if n.startswith(("migrate", "keycloak-config", "purge-caches", "restore-reindex"))])
            if argocd:
                self.assertIsNone(cluster.state()["apps"]["root"]["automated"])
                self.assertIsNone(cluster.state()["apps"]["shop"]["automated"])
                self.assertIn("Argo CD", result.stderr)

    def test_a_failed_restore_without_a_backup_pre_restore_says_there_is_none(self):
        outcomes = initial_state()["outcomes"] + [["restore-run-", "Failed", "restore: rclone sync into product-images failed\n"]]
        cluster = self.stub(outcomes=outcomes)
        result = self.restore(cluster, PRE_RESTORE="skip")
        self.assertEqual(1, result.returncode)
        self.assertIn("no pre-restore snapshot", result.stderr)

    def test_a_failed_migration_leaves_the_api_worker_and_store_off(self):
        outcomes = initial_state()["outcomes"] + [["migrate-", "Failed", "migration failed\n"]]
        cluster = self.stub(outcomes=outcomes)
        result = self.restore(cluster)
        self.assertEqual(1, result.returncode)
        self.assertIn("step 6", result.stderr)
        self.assertIn("migrate", result.stderr)
        deployments = cluster.state()["deployments"]
        self.assertEqual((0, 0, 0), (deployments["api"], deployments["worker"], deployments["store"]))

    def test_a_job_is_waited_for_at_most_its_active_deadline(self):
        outcomes = initial_state()["outcomes"] + [["restore-run-", "Running", ""]]
        cluster = self.stub(outcomes=outcomes)
        (cluster.bin / "date").write_text('#!/bin/sh\nn=$(cat "$STUB_DIR/clock" 2>/dev/null || echo 1000000000)\n'
                                          'echo $((n + 600)) > "$STUB_DIR/clock"\nif [ "$1" = +%s ]; then echo "$n"; else /bin/date "$@"; fi\n')
        (cluster.bin / "date").chmod(0o755)
        result = self.restore(cluster)
        self.assertEqual(1, result.returncode)
        self.assertIn("10800 s", result.stderr)
        self.assertIn("step 5", result.stderr)
        polls = [c for c in map(strip_namespace, cluster.calls()) if c[:2] == ["get", "job"] and c[2].startswith("restore-run-")]
        # The clock moves 600 s per reading: the deadline of 10800 s is reached after about 18 readings, not one more hour.
        self.assertLessEqual(len(polls), 20)
        self.assertGreaterEqual(len(polls), 2)

    def test_the_namespace_comes_from_kube_namespace(self):
        cluster = self.stub()
        self.restore(cluster, KUBE_NAMESPACE="other")
        namespaces = {c[1] for c in cluster.calls() if c[:1] == ["-n"] and c[1] != "argocd"}
        self.assertEqual({"other"}, namespaces)

    def test_the_script_is_posix_sh_and_stops_at_the_first_error(self):
        text = RESTORE.read_text()
        self.assertTrue(text.startswith("#!/bin/sh\n"))
        self.assertRegex(text, r"(?m)^set -eu$")


class RerunScriptTest(unittest.TestCase):
    """make -C infra/k8s rerun, whose way of running a Job of the version again the restore shares (scripts/kube-jobs.sh)."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.tmp.cleanup()

    def test_the_job_is_deleted_and_created_again_under_its_name_without_what_the_cluster_filled_in(self):
        cluster = ClusterStub(tempfile.mkdtemp(dir=self.tmp.name), initial_state())
        result = cluster.run(RERUN, "migrate-2b", answer="")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("rerun: job migrate-2b created again", result.stdout)
        self.assertEqual(["migrate-2b"], cluster.state()["deleted"])
        self.assertEqual(["migrate-2b"], cluster.state()["created"])
        self.assertEqual("version", cluster.state()["jobs"]["migrate-2b"]["labels"]["shop/job-kind"])

    def test_a_job_that_is_not_there_is_an_error(self):
        cluster = ClusterStub(tempfile.mkdtemp(dir=self.tmp.name), initial_state())
        result = cluster.run(RERUN, "migrate-zz", answer="")
        self.assertEqual(1, result.returncode)
        self.assertIn("migrate-zz", result.stderr)
        self.assertEqual([], cluster.state()["deleted"])


class BackupsScriptTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.tmp.cleanup()

    def cluster(self, **changes):
        state = initial_state()
        state.update(changes)
        return ClusterStub(tempfile.mkdtemp(dir=self.tmp.name), state)

    def test_it_lists_the_snapshots_with_a_job_in_the_mode_list_and_removes_it(self):
        cluster = self.cluster()
        result = cluster.run(BACKUPS)
        self.assertEqual(0, result.returncode, result.stderr)
        for line in LIST_LOG.splitlines()[1:]:
            self.assertIn(line, result.stdout)
        (job,) = cluster.state()["created"]
        self.assertTrue(job.startswith("restore-list-"))
        self.assertEqual([job], cluster.state()["deleted"])
        sets = [call for call in cluster.calls() if call[0] == "set"]
        self.assertIn("RESTORE_MODE=list", sets[0])

    def test_it_only_reads(self):
        cluster = self.cluster()
        cluster.run(BACKUPS)
        state = cluster.state()
        self.assertEqual(initial_state()["deployments"], state["deployments"])
        self.assertEqual(sorted(initial_state()["jobs"]), sorted(state["jobs"]))
        for call in cluster.calls():
            self.assertNotIn(strip_namespace(call)[0], ("scale", "patch", "rollout"))

    def test_a_failed_listing_is_an_error_with_its_log(self):
        outcomes = [["restore-list-", "Failed", "restore: cannot list the snapshots of the repository (restic exit code 1)\n"]]
        cluster = self.cluster(outcomes=outcomes)
        result = cluster.run(BACKUPS)
        self.assertEqual(1, result.returncode)
        self.assertIn("cannot list the snapshots", result.stderr)

    def test_the_kubeconfig_is_required(self):
        cluster = self.cluster()
        result = cluster.run(BACKUPS, kube_dir=None)
        self.assertEqual(2, result.returncode)
        self.assertIn("KUBE_DIR", result.stderr)
        self.assertEqual([], cluster.calls())


if __name__ == "__main__":
    unittest.main()

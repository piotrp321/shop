"""make -C infra/k8s reindex: scripts/reindex.sh (docs/features/search/spec.md, "Infrastruktura") against a stub of docker that plays a
cluster for kubectl (the CronJob reindex, the Jobs and the index of the alias products in a state file) and runs the python of
tools.sh: the refusals that change nothing, the confirmation, the Job from the CronJob, and what a failed or a useless run says."""

import json
import os
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/scripts/reindex.sh"
OLD_INDEX = "products_20261120135319397_b9bde0"
NEW_INDEX = "products_20261125101500123_0a1b2c"
REPORT = "Search index rebuilt: 1000191 documents.\n"

# The stub of docker: `docker run ... <image> <arguments>`. The python image runs python itself; the kubectl image plays the cluster of
# $STUB_DIR/state.json. Every kubectl call is a JSON line (its arguments) in $STUB_LOG.
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


def fail(message):
    sys.stderr.write(message + "\n")
    sys.exit(1)


def job_doc(name):
    job = state["jobs"][name]
    conditions = [] if job["state"] == "Running" else [{"type": job["state"], "status": "True"}]
    owner = [{"apiVersion": "batch/v1", "kind": "CronJob", "name": job["owner"]}] if job.get("owner") else []
    return {"kind": "Job", "metadata": {"name": name, "ownerReferences": owner},
            "spec": {"activeDeadlineSeconds": job.get("deadline", 21600)}, "status": {"conditions": conditions}}


assert rest[:2] == ["-n", state["namespace"]], rest
rest = rest[2:]
verb = rest[0]
if state.get("unreachable"):
    fail("The connection to the server was refused")

if verb == "get" and rest[1] == "cronjob":
    if state["cronjob"] is None:
        fail('Error from server (NotFound): cronjobs.batch "reindex" not found')
    assert rest[2:] == ["reindex", "-o", "json"], rest
    env = [{"name": "ELASTICSEARCH_URL", "value": "http://elasticsearch:9200"}] + [{"name": k, "value": v} for k, v in state["cronjob"].items()]
    print(json.dumps({"kind": "CronJob", "metadata": {"name": "reindex"},
                      "spec": {"jobTemplate": {"spec": {"template": {"spec": {"containers": [{"name": "reindex", "env": env}]}}}}}}))
elif verb == "get" and rest[1] == "jobs":
    print(json.dumps({"items": [job_doc(name) for name in state["jobs"]]}))
elif verb == "get" and rest[1] == "job":
    print(json.dumps(job_doc(rest[2])))
elif verb == "exec":
    assert rest[1:5] == ["statefulset/elasticsearch", "--", "curl", "-fsS"], rest
    assert rest[5] == "http://localhost:9200/_cat/indices/products?format=json&h=index,pri,docs.count", rest
    answer = state["indices"][0]
    if len(state["indices"]) > 1:
        state["indices"].pop(0)
        save()
    if answer is None:
        fail("curl: (22) The requested URL returned error: 503")
    print(json.dumps(answer))
elif verb == "create" and rest[1] == "job":
    assert rest[3:] == ["--from=cronjob/reindex", "--dry-run=client", "-o", "json"], rest
    print(json.dumps({"kind": "Job", "metadata": {"name": rest[2], "ownerReferences": [{"kind": "CronJob", "name": "reindex"}]}, "spec": {}}))
elif verb == "create":
    assert rest[1:] == ["-f", "-"], rest
    doc = json.load(sys.stdin)
    if state.get("create_fails"):
        fail("Error from server (Forbidden): jobs.batch is forbidden")
    state["jobs"][doc["metadata"]["name"]] = {"state": state["outcome"], "owner": "reindex", "log": state["log"]}
    state["created"].append(doc["metadata"]["name"])
    save()
elif verb == "logs":
    assert rest[2] == "--container=reindex", rest
    print(state["jobs"][rest[1].split("/", 1)[1]]["log"], end="")
else:
    fail("unknown kubectl call " + " ".join(rest))
'''


def index(name, shards, documents=1000191):
    return [{"index": name, "pri": str(shards), "docs.count": str(documents)}]


def initial_state(**changes):
    state = {
        "namespace": "shop",
        "cronjob": {"SEARCH_SHARDS": "4"},
        "jobs": {
            "migrate-2b": {"state": "Complete"},
            "backfill-6f": {"state": "Complete"},
            "queue-check-29384755": {"state": "Running", "owner": "queue-check"},
        },
        # The answers of Elasticsearch for the index of the alias, one per question; the last one stays.
        "indices": [index(OLD_INDEX, 1), index(NEW_INDEX, 4)],
        "outcome": "Complete",
        "log": REPORT,
        "created": [],
    }
    state.update(changes)
    return state


class ReindexScriptTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.tmp.name)
        (self.root / "bin").mkdir()
        for name, body in (("docker", DOCKER), ("sleep", "#!/bin/sh\nexit 0\n")):
            (self.root / "bin" / name).write_text(body)
            (self.root / "bin" / name).chmod(0o755)
        self.kube = self.root / "kube"
        self.kube.mkdir()
        (self.kube / "config").write_text("apiVersion: v1\n")
        self.log = self.root / "log"
        self.log.write_text("")

    def tearDown(self):
        self.tmp.cleanup()

    def run_script(self, state=None, answer="tak\n", kube_dir=True, **env):
        (self.root / "state.json").write_text(json.dumps(state or initial_state()))
        variables = {
            "PATH": f"{self.root / 'bin'}:{os.environ['PATH']}",
            "HOME": str(self.root),
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.root),
            "TOOLS_CACHE": str(self.root / "cache"),
            **({"KUBE_DIR": str(self.kube)} if kube_dir is True else {"KUBE_DIR": kube_dir} if kube_dir else {}),
            **env,
        }
        return subprocess.run(["sh", str(SCRIPT)], input=answer, env=variables, capture_output=True, text=True)

    def state(self):
        return json.loads((self.root / "state.json").read_text())

    def calls(self):
        return [json.loads(line)[2:] for line in self.log.read_text().splitlines()]

    def changes(self):
        return [call for call in self.calls() if call[0] not in ("get", "logs", "exec") and "--dry-run=client" not in call]

    def assert_refused(self, result, *parts):
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        for part in parts:
            self.assertIn(part, result.stderr)
        self.assertIn("reindex: nothing changed", result.stderr)
        self.assertEqual([], self.changes())
        self.assertEqual([], self.state()["created"])

    # --- the run that rebuilds ---

    def test_it_runs_one_job_of_the_cronjob_and_says_the_index_before_and_after(self):
        result = self.run_script()

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        (job,) = self.state()["created"]
        self.assertRegex(job, r"^reindex-[0-9]{14}$")
        self.assertEqual([["create", "-f", "-"]], self.changes())
        self.assertIn(f"the alias products is on the index {OLD_INDEX}: 1 primary shard(s), 1000191 documents", result.stdout)
        self.assertIn("a new index with 4 primary shard(s)", result.stdout)
        self.assertIn("[reindex] Search index rebuilt: 1000191 documents.", result.stdout)
        self.assertIn(f"reindex: done: the alias products is on the index {NEW_INDEX}: 4 primary shard(s), 1000191 documents", result.stdout)
        self.assertIn(f"before: {OLD_INDEX}, 1 primary shard(s)", result.stdout)

    def test_the_job_is_created_only_after_the_checks_and_the_answer(self):
        self.run_script()

        verbs = [" ".join(call[:2]) for call in self.calls()]
        self.assertEqual(["get cronjob", "get jobs", "exec statefulset/elasticsearch", "create job", "create -f"], verbs[:5])

    def test_it_says_what_stays_on_the_old_index_during_the_build(self):
        result = self.run_script()

        self.assertIn("the shop stays open", result.stdout)
        self.assertIn("may be missing from the new index", result.stdout)

    def test_a_job_of_a_short_check_is_no_reason_to_refuse(self):
        result = self.run_script()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn("queue-check", result.stderr)

    def test_it_reads_the_cluster_of_kube_dir_in_the_namespace_given(self):
        result = self.run_script(initial_state(namespace="other"), KUBE_NAMESPACE="other")

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("in the namespace other", result.stdout)

    # --- refusals that change nothing ---

    def test_without_kube_dir_it_does_nothing(self):
        result = self.run_script(kube_dir=False)

        self.assertEqual(2, result.returncode)
        self.assertIn("set KUBE_DIR", result.stderr)
        self.assertEqual([], self.calls())

    def test_a_kube_dir_without_a_kubeconfig_is_an_error(self):
        result = self.run_script(kube_dir=str(self.root / "missing"))

        self.assertEqual(1, result.returncode)
        self.assertIn("no kubeconfig", result.stderr)
        self.assertEqual([], self.calls())

    def test_reindex_without_the_word_tak_creates_no_job(self):
        for answer in ("", "\n", "nie\n", "TAK\n", "tak tak\n", "yes\n"):
            with self.subTest(answer=answer):
                self.log.write_text("")
                result = self.run_script(answer=answer)
                self.assert_refused(result, "not confirmed (no 'tak')")

    def test_a_cluster_without_the_cronjob_is_refused(self):
        result = self.run_script(initial_state(cronjob=None))

        self.assert_refused(result, "cannot read the CronJob reindex")

    def test_a_cluster_that_does_not_answer_is_refused(self):
        result = self.run_script(initial_state(unreachable=True))

        self.assert_refused(result, "cannot read the CronJob reindex")

    def test_a_cronjob_without_a_number_of_shards_is_refused(self):
        for value in ({}, {"SEARCH_SHARDS": ""}, {"SEARCH_SHARDS": "0"}, {"SEARCH_SHARDS": "four"}, {"SEARCH_SHARDS": "-1"}, {"SEARCH_SHARDS": "1.5"}):
            with self.subTest(value=value):
                self.log.write_text("")
                result = self.run_script(initial_state(cronjob=value))
                self.assert_refused(result, "no number of shards in SEARCH_SHARDS")

    def test_an_active_job_is_refused_by_its_name(self):
        for name, owner in (("demo-data-run", "demo-data"), ("reindex-20261125090000", "reindex"), ("migrate-9z", None),
                            ("restore-run-20261125090000", "restore"), ("queue-check-by-hand", None)):
            with self.subTest(job=name):
                self.log.write_text("")
                state = initial_state()
                state["jobs"][name] = {"state": "Running", **({"owner": owner} if owner else {})}
                result = self.run_script(state)
                self.assert_refused(result, f"Jobs are active in the namespace shop: {name}")

    def test_an_index_that_cannot_be_read_is_refused(self):
        result = self.run_script(initial_state(indices=[None]))

        self.assert_refused(result, "cannot read the index of the alias products")

    def test_an_alias_on_two_indexes_is_refused(self):
        result = self.run_script(initial_state(indices=[index(OLD_INDEX, 1) + index(NEW_INDEX, 4)]))

        self.assert_refused(result, "exactly one index")

    # --- runs that fail after the confirmation ---

    def test_a_job_that_cannot_be_created_is_a_failure_that_names_the_index(self):
        result = self.run_script(initial_state(create_fails=True))

        self.assertEqual(1, result.returncode)
        self.assertIn("could not be created", result.stderr)
        self.assertIn(f"the alias products stays on {OLD_INDEX}", result.stderr)
        self.assertNotIn("reindex: done", result.stdout)

    def test_a_failed_job_shows_its_log_and_where_the_alias_is(self):
        log = "Another rebuild or setup of the search index is running.\n"
        result = self.run_script(initial_state(outcome="Failed", log=log, indices=[index(OLD_INDEX, 1)]))

        self.assertEqual(1, result.returncode)
        self.assertIn("[reindex] Another rebuild or setup of the search index is running.", result.stdout)
        self.assertRegex(result.stderr, r"FAILED: the Job reindex-[0-9]{14} did not complete")
        self.assertIn(f"the alias products is on (index, primary shards, documents): {OLD_INDEX} 1 1000191", result.stderr)
        self.assertNotIn("reindex: done", result.stdout)

    def test_a_failed_job_is_a_failure_also_when_the_index_cannot_be_read_after_it(self):
        result = self.run_script(initial_state(outcome="Failed", indices=[index(OLD_INDEX, 1), None]))

        self.assertEqual(1, result.returncode)
        self.assertIn("did not complete", result.stderr)

    def test_a_completed_job_that_left_the_alias_on_the_old_index_is_a_failure(self):
        result = self.run_script(initial_state(indices=[index(OLD_INDEX, 1)]))

        self.assertEqual(1, result.returncode)
        self.assertIn(f"the alias products is still on the index {OLD_INDEX}", result.stderr)
        self.assertNotIn("reindex: done", result.stdout)

    def test_a_new_index_with_other_shards_than_the_cronjob_says_is_a_failure(self):
        result = self.run_script(initial_state(indices=[index(OLD_INDEX, 1), index(NEW_INDEX, 1)]))

        self.assertEqual(1, result.returncode)
        self.assertIn(f"the new index {NEW_INDEX} has 1 primary shard(s), not the 4 of SEARCH_SHARDS", result.stderr)
        self.assertNotIn("reindex: done", result.stdout)

    def test_an_index_that_cannot_be_read_after_the_job_is_a_failure(self):
        result = self.run_script(initial_state(indices=[index(OLD_INDEX, 1), None]))

        self.assertEqual(1, result.returncode)
        self.assertIn("completed, but the index of the alias products could not be read", result.stderr)

    def test_a_rebuild_with_the_same_number_of_shards_is_a_success(self):
        result = self.run_script(initial_state(cronjob={"SEARCH_SHARDS": "1"}, indices=[index(OLD_INDEX, 1), index(NEW_INDEX, 1)]))

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("reindex: done", result.stdout)


class MakefileTest(unittest.TestCase):
    def test_the_target_calls_the_guard_of_ao_first_and_then_the_script(self):
        text = (REPO / "infra/k8s/Makefile").read_text()
        recipe = re.search(r"^reindex:\n((?:\t.*\n)+)", text, re.M).group(1).splitlines()
        self.assertEqual(["\t@sh scripts/ao-guard.sh reindex", "\tsh scripts/reindex.sh"], recipe)
        self.assertIn(" reindex ", re.search(r"^\.PHONY:(.*)$", text, re.M).group(1) + " ")


if __name__ == "__main__":
    unittest.main()

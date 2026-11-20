"""status.sh, rerun.sh and reinitialize.sh know the state of the demo data (docs/features/demo-scale/spec.md): the NetworkPolicy
shop-closed (the shop is closed), the Job demo-data-run of make generate, the active Jobs (make generate and make open refuse while one
runs), a Secret of the photo pool that is left (make generate deletes it on every exit it can catch, make open after a KILL) and the
label app.kubernetes.io/name: demo-data of the Job of the generator.
status and reinitialize run against the stub of ssh of test_server_scripts.py, rerun against the stub of kubectl of
test_restore_script.py; the refusals change nothing, a failing read is an error."""

import json
import tempfile
import unittest

from test_restore_script import RERUN, ClusterStub, initial_state
from test_server_scripts import ServerScripts

HOST = "deploy@203.0.113.7"
CLOSED = "networkpolicy.networking.k8s.io/shop-closed\n"


class StatusDemoData(ServerScripts):
    def status(self, **env):
        return self.run_script("status.sh", HOST, **env)

    def test_without_the_rule_the_shop_is_open(self):
        result = self.status()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("shop: open", result.stdout)

    def test_it_names_the_state_of_the_job_demo_data_run_and_the_active_jobs(self):
        for states, run, active in (
            ("", "demo-data-run: none (make generate never ran, or it deleted the previous one)", "active jobs: none"),
            ("migrate-1 SuccessCriteriaMet Complete\ndemo-data-run SuccessCriteriaMet Complete\n", "demo-data-run: Complete", "active jobs: none"),
            ("demo-data-run FailureTarget Failed\ncart-purge-expired-2 \n", "demo-data-run: Failed", "active jobs: cart-purge-expired-2"),
            ("demo-data-run \nload-1 \n", "demo-data-run: running", "active jobs: demo-data-run load-1"),
        ):
            with self.subTest(states=states):
                result = self.status(STUB_JOB_STATES=states)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertIn(run, result.stdout)
                self.assertIn(active, result.stdout)

    def test_it_says_whether_the_secret_of_the_photo_pool_is_left(self):
        result = self.status(STUB_SECRET="secret/demo-photos\n")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("the Secret demo-photos of the photo pool: LEFT", result.stdout)
        self.assertIn("make open", result.stdout)
        result = self.status()
        self.assertIn("the Secret demo-photos of the photo pool: none", result.stdout)
        secrets = [call for call in self.calls() if "get secret " in call]
        self.assertIn("-n shop get secret demo-photos --ignore-not-found -o name", secrets[-1])
        result = self.status(STUB_PHOTO_SECRET="")
        self.assertIn("the CronJob demo-data reads no Secret of the photo pool", result.stdout)

    def test_the_rule_shop_closed_means_the_shop_is_closed(self):
        result = self.status(STUB_CLOSED=CLOSED)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("shop: closed", result.stdout)
        self.assertIn("shop-closed", result.stdout)
        self.assertNotIn("shop: open", result.stdout)

    def test_it_shows_the_lock_of_make_generate_and_make_open_with_its_holder(self):
        holder = "generate PROFILE=medium on operator-laptop, pid 4242, since 2026-11-20T08:00:00Z"
        result = self.status(STUB_LOCK=f"demo-data-lock/{holder}")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn(f"lock demo-data-lock: HELD by {holder}", result.stdout)
        self.assertIn("sudo k3s kubectl -n shop delete configmap demo-data-lock", result.stdout)
        (read,) = [call for call in self.calls() if "get configmap demo-data-lock" in call]
        self.assertIn("--ignore-not-found", read)
        result = self.status()
        self.assertIn("lock demo-data-lock: none", result.stdout)
        # A holder with control characters is printed without them.
        result = self.status(STUB_LOCK="demo-data-lock/open on a\033[2Jb, pid 1")
        self.assertIn("HELD by open on a[2Jb, pid 1", result.stdout)

    def test_it_prints_the_last_line_of_the_log_of_the_newest_job_initialize(self):
        result = self.status(STUB_INIT_JOBS="initialize-1111111111\ninitialize-2222222222\n", STUB_INIT_LOG="initialize: 10000000 products, 50 orders")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("initialize-2222222222", result.stdout)
        self.assertIn("initialize: 10000000 products, 50 orders", result.stdout)
        logs = [call for call in self.calls() if "--container=initialize" in call]
        self.assertEqual(1, len(logs), logs)
        self.assertIn("-n shop logs -l job-name=initialize-2222222222 --container=initialize --tail=1", logs[0])
        listing = [call for call in self.calls() if "get jobs -l app.kubernetes.io/name=initialize" in call]
        self.assertEqual(1, len(listing), listing)
        self.assertIn("--sort-by=.metadata.creationTimestamp", listing[0])

    def test_without_pods_of_the_job_initialize_it_says_so(self):
        result = self.status(STUB_INIT_NO_PODS="1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("no pods of the job initialize-3fa9c1d2e4 left", result.stdout)
        self.assertIn("Grafana Cloud", result.stdout)

    def test_without_a_job_initialize_it_says_so_and_reads_no_log(self):
        result = self.status(STUB_INIT_JOBS="")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("no job initialize", result.stdout)
        self.assertFalse([call for call in self.calls() if "--container=initialize" in call])

    def test_a_name_of_a_job_initialize_with_unusual_characters_is_not_put_on_a_command_line(self):
        result = self.status(STUB_INIT_JOBS="initialize;rm\n")
        self.assertEqual(1, result.returncode)
        self.assertIn("unexpected name", result.stderr)
        self.assertFalse([call for call in self.calls() if "--container=initialize" in call])

    def test_a_failing_read_of_any_of_them_is_exit_code_1_and_the_others_still_run(self):
        # The calls before the demo data: the applications, the pods, the jobs, the failed jobs (no failed job, so no log): 4.
        for match, message, calls in (
            ("get networkpolicy shop-closed", "could not read the rule shop-closed", 11),
            ("get configmap demo-data-lock", "could not read the lock demo-data-lock", 11),
            (".type}", "could not read the states of the jobs", 11),
            ("get cronjob demo-data", "could not read the Secret of the photo pool", 10),
            ("get secret ", "could not read the Secret of the photo pool", 11),
            ("get jobs -l app.kubernetes.io/name=initialize", "could not read the jobs initialize", 10),
            ("--container=initialize", "could not read the log of the job initialize-3fa9c1d2e4", 11),
        ):
            with self.subTest(match=match):
                self.log.write_text("")
                result = self.status(STUB_FAIL_MATCH=match)
                self.assertEqual(1, result.returncode, result.stdout)
                self.assertIn(message, result.stderr)
                self.assertEqual(calls, len(self.calls()), self.calls())
                # a failed read does not print a state it did not read as if it were one
                if "networkpolicy" in match:
                    self.assertNotIn("shop: open", result.stdout)
                if "configmap" in match:
                    self.assertNotIn("lock demo-data-lock: none", result.stdout)

    def test_the_reads_only_read(self):
        self.status(STUB_CLOSED=CLOSED)
        reads = self.calls()[4:]
        self.assertEqual(7, len(reads), reads)
        for call in reads:
            call = call.split("k3s kubectl", 1)[1]
            self.assertRegex(call, r" (get|logs) ")
            for verb in ("apply", "delete", "create", "exec", "patch", "edit", "replace", "scale"):
                self.assertNotRegex(call, rf"\b{verb}\b")


class ReinitializeDemoData(ServerScripts):
    """reinitialize loads the seed only while the shop is open: the rule shop-closed is the shop closed by make generate (data being
    replaced, or a failed run), which make generate PROFILE=seed brings back with the checks."""

    def reinitialize(self, answer="tak\n", **env):
        return self.run_script("reinitialize.sh", HOST, stdin=answer, **env)

    def execs(self):
        return [call for call in self.calls() if "exec deploy/api" in call]

    def test_the_rule_shop_closed_is_refused_before_the_question_even_with_tak_on_the_input(self):
        result = self.reinitialize(STUB_CLOSED=CLOSED)
        self.assertEqual(1, result.returncode, result.stdout)
        self.assertIn("shop-closed", result.stderr)
        self.assertIn("generate PROFILE=seed", result.stderr)
        self.assertNotIn("type tak", result.stdout)
        self.assertEqual([], self.execs())

    def test_a_failing_read_of_the_rule_is_a_refusal(self):
        result = self.reinitialize(STUB_FAIL_MATCH="get networkpolicy shop-closed")
        self.assertEqual(1, result.returncode)
        self.assertIn("could not read the rule shop-closed", result.stderr)
        self.assertNotIn("type tak", result.stdout)
        self.assertEqual([], self.execs())

    def test_an_open_shop_goes_on_as_before_and_still_needs_the_word_tak(self):
        result = self.reinitialize()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(1, len(self.execs()))
        self.assertIn("reinitialize: done", result.stdout)
        self.log.write_text("")
        result = self.reinitialize(answer="nie\n")
        self.assertEqual(1, result.returncode)
        self.assertEqual([], self.execs())

    def test_the_rule_is_read_before_anything_is_run(self):
        self.reinitialize()
        calls = self.calls()
        self.assertEqual(2, len(calls), calls)
        self.assertIn("get networkpolicy shop-closed", calls[0])
        self.assertIn("exec deploy/api", calls[1])


class RerunDemoData(unittest.TestCase):
    GENERATOR = {"labels": {"app.kubernetes.io/name": "demo-data"}, "state": "Failed", "log": "", "env": {"DEMO_DATA_CONFIRM": "x"}, "deadline": 3600}

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def cluster(self, **changes):
        state = initial_state()
        state["jobs"]["demo-data-manual-1"] = json.loads(json.dumps(self.GENERATOR))
        state.update(changes)
        return ClusterStub(tempfile.mkdtemp(dir=self.tmp.name), state)

    def test_a_job_with_the_label_of_the_generator_is_refused_and_nothing_is_deleted_or_created(self):
        cluster = self.cluster()
        result = cluster.run(RERUN, "demo-data-manual-1", answer="")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("make generate", result.stderr)
        self.assertIn("demo-data-manual-1", result.stderr)
        state = cluster.state()
        self.assertEqual([], state["deleted"])
        self.assertEqual([], state["created"])
        self.assertIn("demo-data-manual-1", state["jobs"])
        self.assertNotIn("created again", result.stdout)
        for call in cluster.calls():
            verb = call[2] if call[:1] == ["-n"] else call[0]
            self.assertNotIn(verb, ("delete", "create"), call)

    def test_the_label_decides_not_the_name(self):
        cluster = self.cluster()
        state = cluster.state()
        state["jobs"]["migrate-2b"]["labels"]["app.kubernetes.io/name"] = "demo-data"
        (cluster.root / "state.json").write_text(json.dumps(state))
        result = cluster.run(RERUN, "migrate-2b", answer="")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("make generate", result.stderr)
        self.assertEqual([], cluster.state()["deleted"])

    def test_a_job_of_a_version_is_run_again_as_before(self):
        cluster = self.cluster()
        result = cluster.run(RERUN, "initialize-4d", answer="")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("rerun: job initialize-4d created again", result.stdout)
        self.assertEqual(["initialize-4d"], cluster.state()["deleted"])
        self.assertEqual(["initialize-4d"], cluster.state()["created"])

    def test_a_cluster_that_cannot_be_read_is_an_error_and_nothing_is_deleted(self):
        cluster = self.cluster(broken=True)
        result = cluster.run(RERUN, "initialize-4d", answer="")
        self.assertEqual(1, result.returncode)
        self.assertEqual([], cluster.state()["deleted"])
        self.assertEqual([], cluster.state()["created"])


if __name__ == "__main__":
    unittest.main()

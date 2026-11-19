"""status.sh, rerun.sh and reinitialize.sh know the state of the demo data (docs/features/demo-scale/spec.md: "Stan sklepu i zapis
stanu", "Ochrona przed przypadkowym uruchomieniem", "Kolejne wdrożenia"): the ConfigMap demo-data-state (no record: data seed, phase
idle), the NetworkPolicy shop-closed (the shop is closed) and the label app.kubernetes.io/name: demo-data of the Job of the generator.
status and reinitialize run against the stub of ssh of test_server_scripts.py, rerun against the stub of kubectl of
test_restore_script.py; the refusals change nothing, a failing read is an error."""

import json
import tempfile
import unittest

from test_restore_script import RERUN, ClusterStub, initial_state
from test_server_scripts import ServerScripts

HOST = "deploy@203.0.113.7"
CLOSED = "networkpolicy.networking.k8s.io/shop-closed\n"


def record(data, phase, target="", reason=""):
    """What the read of the ConfigMap prints (the stub answers with it): the marker, data, phase, target, then the reason (the rest)."""
    return f"present\n{data}\n{phase}\n{target}\n{reason}"


class StatusDemoData(ServerScripts):
    def status(self, **env):
        return self.run_script("status.sh", HOST, **env)

    def test_without_a_record_it_says_data_seed_phase_idle_and_the_shop_open(self):
        result = self.status()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("data: seed", result.stdout)
        self.assertIn("phase: idle", result.stdout)
        self.assertIn("shop: open", result.stdout)
        self.assertIn("no record", result.stdout)

    def test_a_record_gives_data_phase_target_and_the_reason(self):
        result = self.status(STUB_STATE=record("failed", "running", "server", "the Job demo-data-1 failed: no space left"))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("data: failed", result.stdout)
        self.assertIn("phase: running", result.stdout)
        self.assertIn("target: server", result.stdout)
        self.assertIn("reason: the Job demo-data-1 failed: no space left", result.stdout)
        self.assertNotIn("data: seed", result.stdout)

    def test_a_record_of_a_finished_run_has_no_reason_line(self):
        result = self.status(STUB_STATE=record("medium", "idle", "medium"))
        self.assertIn("data: medium", result.stdout)
        self.assertIn("phase: idle", result.stdout)
        self.assertNotIn("reason:", result.stdout)

    def test_control_characters_of_the_record_are_not_printed(self):
        result = self.status(STUB_STATE=record("failed", "idle", "", "bad \x1b[31mred\x1b[0m text"))
        self.assertNotIn("\x1b", result.stdout)
        self.assertIn("bad [31mred[0m text", result.stdout)

    def test_the_rule_shop_closed_means_the_shop_is_closed(self):
        result = self.status(STUB_CLOSED=CLOSED)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("shop: closed", result.stdout)
        self.assertIn("shop-closed", result.stdout)
        self.assertNotIn("shop: open", result.stdout)

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
            ("get configmap demo-data-state", "could not read the record of the demo data", 8),
            ("get networkpolicy shop-closed", "could not read the rule shop-closed", 8),
            ("get jobs -l app.kubernetes.io/name=initialize", "could not read the jobs initialize", 7),
            ("--container=initialize", "could not read the log of the job initialize-3fa9c1d2e4", 8),
        ):
            with self.subTest(match=match):
                self.log.write_text("")
                result = self.status(STUB_FAIL_MATCH=match)
                self.assertEqual(1, result.returncode, result.stdout)
                self.assertIn(message, result.stderr)
                self.assertEqual(calls, len(self.calls()), self.calls())
                # a failed read does not print a state it did not read as if it were one
                if "configmap" in match:
                    self.assertNotIn("data: seed", result.stdout)
                if "networkpolicy" in match:
                    self.assertNotIn("shop: open", result.stdout)

    def test_the_reads_only_read(self):
        self.status(STUB_STATE=record("seed", "idle"), STUB_CLOSED=CLOSED)
        reads = [c for c in self.calls() if "demo-data-state" in c or "shop-closed" in c or "name=initialize" in c]
        self.assertEqual(4, len(reads))
        for call in reads:
            call = call.split("k3s kubectl", 1)[1]
            self.assertRegex(call, r" (get|logs) ")
            for verb in ("apply", "delete", "create", "exec", "patch", "edit", "replace", "scale"):
                self.assertNotRegex(call, rf"\b{verb}\b")


class ReinitializeDemoData(ServerScripts):
    def reinitialize(self, answer="tak\n", **env):
        return self.run_script("reinitialize.sh", HOST, stdin=answer, **env)

    def execs(self):
        return [call for call in self.calls() if "exec deploy/api" in call]

    def assert_refused_before_the_question(self, result):
        self.assertEqual(1, result.returncode, result.stdout)
        self.assertIn("generate PROFILE=seed", result.stderr)
        self.assertNotIn("type tak", result.stdout)
        self.assertEqual([], self.execs())

    def test_data_other_than_seed_is_refused_before_the_question_even_with_tak_on_the_input(self):
        for data, phase in (("medium", "idle"), ("server", "idle"), ("failed", "idle"), ("failed", "running")):
            with self.subTest(data=data, phase=phase):
                self.log.write_text("")
                self.assert_refused_before_the_question(self.reinitialize(STUB_STATE=record(data, phase)))

    def test_a_phase_other_than_idle_is_refused_also_for_seed(self):
        for phase in ("closing", "running", "checking", "opening", ""):
            with self.subTest(phase=phase):
                self.log.write_text("")
                self.assert_refused_before_the_question(self.reinitialize(STUB_STATE=record("seed", phase)))

    def test_a_record_without_data_is_refused(self):
        self.assert_refused_before_the_question(self.reinitialize(STUB_STATE=record("", "idle")))

    def test_a_failing_read_of_the_record_is_a_refusal(self):
        result = self.reinitialize(STUB_FAIL_MATCH="get configmap demo-data-state")
        self.assertEqual(1, result.returncode)
        self.assertIn("could not read the record of the demo data", result.stderr)
        self.assertNotIn("type tak", result.stdout)
        self.assertEqual([], self.execs())

    def test_without_a_record_it_goes_on_as_before(self):
        result = self.reinitialize()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(1, len(self.execs()))
        self.assertIn("reinitialize: done", result.stdout)

    def test_data_seed_in_phase_idle_goes_on_as_before(self):
        result = self.reinitialize(STUB_STATE=record("seed", "idle"))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(1, len(self.execs()))

    def test_data_seed_in_phase_idle_still_needs_the_word_tak(self):
        result = self.reinitialize(answer="nie\n", STUB_STATE=record("seed", "idle"))
        self.assertEqual(1, result.returncode)
        self.assertIn("nothing changed", result.stderr)
        self.assertEqual([], self.execs())

    def test_the_record_is_read_before_anything_is_run(self):
        self.reinitialize(STUB_STATE=record("seed", "idle"))
        calls = self.calls()
        self.assertEqual(2, len(calls), calls)
        self.assertIn("get configmap demo-data-state", calls[0])
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

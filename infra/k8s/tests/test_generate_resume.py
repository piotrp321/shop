"""make -C infra/k8s generate: a run that stopped is resumed (docs/features/demo-scale/spec.md, "Przerwanie i wznowienie", "Scenariusze
awarii", criterion 5), on the stub cluster of test_generate_script.py.

The resume of each phase starts from the cluster as a stop in that phase leaves it. The interruptions themselves are made by the stub:
right after its n-th call of kubectl took effect it ends the whole process group of the target (SIGKILL: no trap of the script runs),
for every n of the right path, of a failure and of two rollbacks; then the target runs once more, as the operator would run it
(a resume: without REPLACE and without an answer). Those runs go in parallel, each on a stub cluster of its own. The stub checks
after every change that the rule shop-closed goes only in step 6 or in the rollback of a closing from data other than failed, and
that data failed always has the rule. The signals INT and TERM reach the target while it waits for the Job."""

import concurrent.futures
import json
import os
import signal
import subprocess
import time

import test_generate_script as g

JOB, LOAD_JOB, ROOT_BACK, CLOSED, START = g.JOB, g.LOAD_JOB, g.ROOT_BACK, g.CLOSED, g.START
ROOT = json.dumps(g.AUTOMATED, sort_keys=True, separators=(",", ":"))
OFF = {"root": {"automated": None}, "shop": {"automated": None}}
PHOTOS = {"demo-photos": {"kind": "Secret", "metadata": {"name": "demo-photos"}}}
EXIT_75 = "exit 75: the free space of / fell to 31.74 GB (31000000 KiB), below the floor of 32 GB (demoData.minFreeGb); the generator was stopped"
# The interrupted runs of one path in parallel: each is one shell and short-lived stubs.
WORKERS = max(2, min(8, os.cpu_count() or 2))


def generator_job(outcome="Running", created_at=START, duration=3700, pod=None, message=""):
    """The Job of the generator of the record, as the stub keeps it (pod None: its pod is gone)."""
    return {"labels": {"app.kubernetes.io/name": "demo-data"}, "env": {"DEMO_DATA_CONFIRM": "medium"}, "deadline": 21600, "created_at": created_at,
            "duration": duration, "outcome": outcome, "reason": "", "reason_message": "", "pod": pod, "log": g.generator_log()}


RUNNING_POD = {"container": "demo-data", "exit": 0, "message": "exit 0: done"}


def stopped(phase, data="failed", job=None, root=ROOT, **changes):
    """The cluster after make generate PROFILE=medium stopped in <phase>: the record of that phase, the synchronization of root and shop
    off, the rule there and Traefik restarted (the probe says closed), no node whose free space could be read."""
    fields = {"target": "medium", "started": "2026-11-19T10:00:00Z", "rootAutomated": root}
    if phase != "closing":
        fields.update(job=JOB, anchor="2026-11-19T00:00:00Z", revision="0123456789abcdef0123456789abcdef01234567")
    if phase == "opening":
        fields.update(products="1000191", orders="1000001")
    fields.update(changes.pop("fields", {}))
    jobs = g.initial_state()["jobs"]
    if job is not None:
        jobs[JOB] = job
    state = {"record": g.record(data, phase, **fields), "apps": OFF, "netpol": CLOSED, "traefik_restart_seq": 1, "jobs": jobs,
             "nodes": {"server-0": "fail"}}
    state.update(changes)
    return state


def check_tail(load_job=LOAD_JOB):
    return ["exec api-7c9f-a cache:pool:clear cache.sitemap", "exec api-7c9f-b cache:pool:clear cache.sitemap", f"create job {load_job}"]


def open_tail():
    return ["record opening", "delete networkpolicy shop-closed", ROOT_BACK, "record idle"]


class ResumeCase(g.RunCase):
    def resume(self, cluster, **env):
        """The target again after the stop, as the operator runs it: without REPLACE and without an answer."""
        env.setdefault("REPLACE", None)
        return self.run_generate(cluster, answer="", **env)

    def assert_resumed(self, cluster, result, after=0, after_calls=0):
        """What every resume keeps: no question, no Job of the generator and no Secret made, neither the free space nor the active
        Jobs read, and none of the rules of the rule shop-closed broken."""
        events = cluster.events()[after:]
        self.assertNotIn("type tak", result.stdout)
        self.assertEqual([], [e for e in events if e.startswith(("create job demo-data", "create secret"))])
        for call in [c["args"] for c in cluster.calls()[after_calls:] if c["image"] != "curlimages/curl"]:
            self.assertNotIn("--raw", call)
            self.assertNotEqual(["get", "nodes"], call[:2])
            self.assertNotEqual(["-n", "shop", "get", "jobs"], call[:4])
            self.assertNotEqual(["config", "view"], call[:2])
        self.assertEqual([], cluster.state()["violations"])
        self.assert_common(cluster, result)

    def assert_open(self, cluster, result, data="medium"):
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        state = cluster.state()
        final = state["record"]["data"]
        self.assertEqual((data, "idle"), (final["data"], final["phase"]))
        self.assertNotIn("rootAutomated", final)
        self.assertIn("finished", final)
        self.assertIsNone(state["netpol"])
        self.assertEqual({}, state["secrets"])
        self.assertEqual(g.AUTOMATED, state["apps"]["root"]["automated"])


class Closing(ResumeCase):
    def test_a_closing_is_rolled_back_with_the_synchronization_of_the_record(self):
        # The value of before the first switch-off, from the record (the applications are off now).
        old = {"prune": False, "selfHeal": True}
        cluster = self.cluster(**stopped("closing", data="seed", root=json.dumps(old, sort_keys=True, separators=(",", ":"))))
        result = self.resume(cluster)
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertEqual(["delete secret demo-photos", "delete networkpolicy shop-closed", 'patch root {"prune": false, "selfHeal": true}', "record idle"],
                         cluster.events())
        state = cluster.state()
        final = state["record"]["data"]
        self.assertEqual(("seed", "idle"), (final["data"], final["phase"]))
        self.assertNotIn("rootAutomated", final)
        self.assertIsNone(state["netpol"])
        self.assertEqual(old, state["apps"]["root"]["automated"])
        self.assertIn("rolled back", result.stderr)
        self.assert_resumed(cluster, result)

    def test_a_closing_from_data_failed_keeps_the_rule(self):
        cluster = self.cluster(**stopped("closing", data="failed", fields={"reason": "exit 75: an earlier run"}))
        result = self.resume(cluster, PROFILE="medium")
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertEqual(["delete secret demo-photos", ROOT_BACK, "record idle"], cluster.events())
        state = cluster.state()
        final = state["record"]["data"]
        self.assertEqual(("failed", "idle", "exit 75: an earlier run"), (final["data"], final["phase"], final["reason"]))
        self.assertIsNotNone(state["netpol"])
        self.assertIn("CLOSED", result.stderr)
        self.assert_resumed(cluster, result)


class Running(ResumeCase):
    def test_a_job_that_still_runs_is_joined(self):
        cluster = self.cluster(**stopped("running", job=generator_job("Complete", created_at=START - 600, duration=3700, pod=RUNNING_POD),
                                         secrets=dict(PHOTOS)))
        result = self.resume(cluster)
        self.assert_open(cluster, result)
        self.assertEqual(["record checking", "delete secret demo-photos"] + check_tail() + open_tail(), cluster.events())
        # It waited for the rest of the Job (3100 s), reading only the Job.
        self.assertGreaterEqual(cluster.clock() - START, 3100)
        self.assertTrue((cluster.out / JOB / "demo-data.log").exists())
        self.assertIn(f"the Job {JOB}", result.stdout)
        self.assert_resumed(cluster, result)

    def test_a_job_that_completed_is_read(self):
        cluster = self.cluster(**stopped("running", job=generator_job("Complete", created_at=START - 4000, pod=RUNNING_POD), secrets=dict(PHOTOS)))
        result = self.resume(cluster)
        self.assert_open(cluster, result)
        self.assertEqual(["record checking", "delete secret demo-photos"] + check_tail() + open_tail(), cluster.events())
        self.assertEqual(("1000191", "1000001"), (cluster.state()["record"]["data"]["products"], cluster.state()["record"]["data"]["orders"]))
        self.assert_resumed(cluster, result)

    def test_without_the_job_of_the_record_the_data_is_failed(self):
        cluster = self.cluster(**stopped("running", secrets=dict(PHOTOS)))
        result = self.resume(cluster)
        self.assertEqual(["delete secret demo-photos", ROOT_BACK, "record idle"], cluster.events())
        self.assert_closed_failed(cluster, result, f"the Job {JOB} of the record does not exist")
        self.assert_resumed(cluster, result)

    def test_a_job_that_failed_without_its_pod(self):
        cluster = self.cluster(**stopped("running", job=generator_job("Failed", created_at=START - 4000, pod=None), secrets=dict(PHOTOS)))
        result = self.resume(cluster)
        self.assertEqual(["delete secret demo-photos", ROOT_BACK, "record idle"], cluster.events())
        self.assert_closed_failed(cluster, result, "the pod of the Job does not exist")
        self.assert_resumed(cluster, result)

    def test_a_job_that_failed_with_the_reason_of_its_pod(self):
        pod = {"container": "demo-data", "exit": 75, "message": EXIT_75}
        cluster = self.cluster(**stopped("running", job=generator_job("Failed", created_at=START - 4000, pod=pod)))
        result = self.resume(cluster)
        self.assertEqual(["delete secret demo-photos", ROOT_BACK, "record idle"], cluster.events())
        self.assert_closed_failed(cluster, result, EXIT_75)
        self.assertTrue((cluster.out / JOB / "demo-data.log").exists())
        self.assert_resumed(cluster, result)


class Checking(ResumeCase):
    def test_checking_repeats_the_checks_also_when_the_pod_is_gone(self):
        cluster = self.cluster(**stopped("checking", job=generator_job("Complete", created_at=START - 4000, pod=None), secrets=dict(PHOTOS)))
        result = self.resume(cluster)
        self.assert_open(cluster, result)
        self.assertEqual(["delete secret demo-photos"] + check_tail() + open_tail(), cluster.events())
        self.assertIn("is gone", result.stdout)
        self.assert_resumed(cluster, result)

    def test_a_check_that_fails_in_the_resume_leaves_data_failed(self):
        cluster = self.cluster(**stopped("checking", job=generator_job("Complete", created_at=START - 4000, pod=RUNNING_POD)),
                               es={"health": "green", "alias": [], "indices": {}})
        result = self.resume(cluster)
        self.assertEqual(["delete secret demo-photos"] + check_tail()[:2] + ["delete secret demo-photos", ROOT_BACK, "record idle"], cluster.events())
        self.assert_closed_failed(cluster, result, "alias products")
        self.assert_resumed(cluster, result)


class Opening(ResumeCase):
    def test_opening_is_finished_without_the_checks(self):
        cluster = self.cluster(**stopped("opening", data="medium", job=generator_job("Complete", created_at=START - 4000, pod=None)))
        result = self.resume(cluster)
        self.assert_open(cluster, result)
        self.assertEqual(["delete networkpolicy shop-closed", ROOT_BACK, "record idle"], cluster.events())
        self.assertEqual([], [c for c in cluster.kubectl_calls() if "exec" in c or "create" in c])
        self.assertEqual(("1000191", "1000001"), (cluster.state()["record"]["data"]["products"], cluster.state()["record"]["data"]["orders"]))
        self.assert_resumed(cluster, result)

    def test_an_opening_the_probe_does_not_confirm_stays_in_the_phase_opening(self):
        cluster = self.cluster(**stopped("opening", data="medium"), on_event={"delete networkpolicy": {"probes": {g.API_URL: "auto", g.STORE_URL: "000"}}})
        result = self.resume(cluster, GENERATE_OPEN_WAIT="60")
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertEqual(["delete networkpolicy shop-closed", ROOT_BACK], cluster.events())
        self.assertEqual(("medium", "opening"), (cluster.state()["record"]["data"]["data"], cluster.state()["record"]["data"]["phase"]))
        self.assertIn("make -C infra/k8s generate PROFILE=medium", result.stderr)
        self.assert_resumed(cluster, result)


class Refusals(ResumeCase):
    def test_the_resume_needs_the_kubeconfig_the_directory_and_the_probe(self):
        for name in ("KUBE_DIR", "GENERATE_OUT", "GENERATE_PROBE_URL"):
            with self.subTest(name=name):
                cluster = self.cluster(**stopped("running", job=generator_job()))
                result = self.resume(cluster, **{name: None})
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn(name, result.stderr)
                self.assertEqual([], cluster.calls())

    def test_an_unknown_phase_is_refused(self):
        cluster = self.cluster(**stopped("paused"))
        result = self.resume(cluster)
        self.assert_unchanged(cluster, result)
        self.assertIn("phase paused", result.stderr)

    def test_a_missing_cronjob_is_refused_before_any_change(self):
        cluster = self.cluster(**stopped("running", job=generator_job()), cronjobs={"load": g.initial_state()["cronjobs"]["load"]})
        result = self.resume(cluster)
        self.assert_unchanged(cluster, result)
        self.assertIn("CronJob demo-data", result.stderr)

    def test_a_record_written_by_another_copy_stops_the_resume_before_its_first_change(self):
        for phase, job in (("closing", None), ("running", generator_job("Failed", created_at=START - 4000)), ("opening", None)):
            with self.subTest(phase=phase):
                cluster = self.cluster(**stopped(phase, data="medium" if phase == "opening" else "seed" if phase == "closing" else "failed", job=job),
                                       interfere_on_read=1)
                result = self.resume(cluster)
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertEqual([], cluster.events())
                self.assertIn("another copy", result.stderr)


class Signals(ResumeCase):
    def test_int_and_term_in_the_wait_for_the_job_change_nothing_and_say_how_to_resume(self):
        for name, number, code in (("INT", signal.SIGINT, 130), ("TERM", signal.SIGTERM, 143)):
            with self.subTest(signal=name):
                cluster = self.cluster(hold_after=f"create job {JOB}")
                target = subprocess.Popen(["sh", str(g.SCRIPT)], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                                          env=cluster.environment(GENERATE_POLL="300"), start_new_session=True)
                target.stdin.write("tak\n")
                target.stdin.close()
                deadline = time.monotonic() + 120
                while not (cluster.root / "held").exists():
                    self.assertIsNone(target.poll(), target.stderr.read() if target.poll() is not None else "")
                    self.assertLess(time.monotonic(), deadline, "the target did not reach the wait for the Job")
                    time.sleep(0.05)
                at_signal = cluster.events()
                if number == signal.SIGINT:
                    # Ctrl-C: the whole foreground group.
                    os.killpg(target.pid, number)
                else:
                    os.kill(target.pid, number)
                (cluster.root / "hold").unlink()
                stdout, stderr = target.communicate(timeout=60)
                self.assertEqual(code, target.returncode, stdout + stderr)
                self.assertEqual(at_signal, cluster.events())
                self.assertEqual(g.happy_events()[:9], at_signal)
                state = cluster.state()
                self.assertEqual("running", state["record"]["data"]["phase"])
                self.assertIsNotNone(state["netpol"])
                self.assertIsNone(state["apps"]["root"]["automated"])
                self.assertIn(JOB, state["jobs"])
                self.assertIn("phase running", stderr)
                self.assertIn(f"make -C infra/k8s generate PROFILE=medium GENERATE_OUT={cluster.out} GENERATE_PROBE_URL='{g.PROBE_URLS}' KUBE_DIR={cluster.kube}",
                              stderr)
                # Then the resume ends the run.
                calls = len(cluster.calls())
                load_job = "load-" + time.strftime("%Y%m%d%H%M%S", time.gmtime(cluster.clock()))
                result = self.resume(cluster)
                self.assert_open(cluster, result)
                self.assertEqual(at_signal + ["record checking", "delete secret demo-photos"] + check_tail(load_job) + open_tail(), cluster.events())
                self.assert_resumed(cluster, result, after=len(at_signal), after_calls=calls)


def outcomes(**generator):
    """The outcomes of the Jobs, the generator changed; a short Job keeps the number of readings small."""
    result = g.initial_state()["outcomes"]
    result["demo-data-"] = {**result["demo-data-"], "duration": 600, **generator}
    return result


class Interrupted(ResumeCase):
    """The target is ended after each of its calls of kubectl and run again (criterion 5)."""

    def interrupt_at(self, call, env, changes):
        cluster = self.cluster(kill_after=call, **changes)
        first = self.run_generate(cluster, new_session=True, **env)
        stop = cluster.state()
        before = len(cluster.calls())
        record = (stop["record"] or {}).get("data") or {"data": "seed", "phase": "idle"}
        second, resumed = None, record["phase"] != "idle"
        if resumed:
            second = self.resume(cluster, new_session=True, **{k: v for k, v in env.items() if k != "REPLACE"})
        elif not stop["records"]:
            # Stopped before its first write: nothing changed, the next run is a new one (with tak).
            second = self.run_generate(cluster, new_session=True, **env)
        resume_calls = [c["args"] for c in cluster.calls()[before:] if c["image"] != "curlimages/curl"]
        return {"call": call, "first": first, "stop": stop, "phase": record["phase"], "second": second, "resumed": resumed,
                "final": cluster.state(), "resume_calls": resume_calls}

    def interrupt_everywhere(self, expected, env=None, **changes):
        """expected(run) -> (data, exit code of the second run) after the stop of <run>."""
        env = dict(env or {})
        reference = self.cluster(**changes)
        self.run_generate(reference, **env)
        count = len(reference.kubectl_calls())
        self.assertGreater(count, 10)
        with concurrent.futures.ThreadPoolExecutor(WORKERS) as pool:
            runs = list(pool.map(lambda call: self.interrupt_at(call, env, changes), range(1, count + 1)))
        phases = set()
        for run in runs:
            first, second, final = run["first"], run["second"], run["final"]
            stop_events = run["stop"]["events"]
            with self.subTest(after_call=run["call"], stopped_after=stop_events[-1:], phase=run["phase"]):
                phases.add(run["phase"])
                self.assertEqual(-signal.SIGKILL, first.returncode, first.stdout + first.stderr)
                data, code = expected(run)
                if second is not None:
                    self.assertEqual(code, second.returncode, second.stdout + second.stderr)
                record = final["record"]["data"]
                self.assertEqual((data, "idle"), (record["data"], record["phase"]))
                self.assertNotIn("rootAutomated", record)
                self.assertEqual(g.AUTOMATED, final["apps"]["root"]["automated"])
                self.assertEqual({}, final["secrets"])
                if data == "failed":
                    self.assertIsNotNone(final["netpol"])
                else:
                    self.assertIsNone(final["netpol"])
                self.assertEqual([], final["violations"])
                self.assertEqual([], [e for e in final["events"] if e.startswith(("delete job", "delete jobs"))])
                if run["resumed"]:
                    resumed_events = final["events"][len(stop_events):]
                    self.assertEqual([], [e for e in resumed_events if e.startswith(("create job demo-data", "create secret"))])
                    self.assertNotIn("type tak", second.stdout)
                    for call in run["resume_calls"]:
                        self.assertNotIn("--raw", call)
                        self.assertNotEqual(["-n", "shop", "get", "jobs"], call[:4])
                    if run["phase"] == "closing":
                        self.assertIn("rolled back", second.stderr)
        return phases

    def test_the_right_path(self):
        def expected(run):
            if run["phase"] == "closing":
                return "seed", 1
            if run["phase"] == "running" and f"create job {JOB}" not in run["stop"]["events"]:
                return "failed", 1
            return "medium", 0
        phases = self.interrupt_everywhere(expected, {"GENERATE_POLL": "300"}, outcomes=outcomes())
        self.assertEqual({"idle", "closing", "running", "checking", "opening"}, phases)

    def test_a_failed_job(self):
        def expected(run):
            return ("seed", 1) if run["phase"] == "closing" else ("failed", 1)
        pod = {"container": "demo-data", "exit": 75, "message": EXIT_75}
        phases = self.interrupt_everywhere(expected, {"GENERATE_POLL": "300"}, outcomes=outcomes(outcome="Failed", pod=pod))
        self.assertEqual({"idle", "closing", "running"}, phases)

    def test_a_rollback_of_the_closing(self):
        phases = self.interrupt_everywhere(lambda run: ("seed", 1), probes={g.API_URL: "auto", g.STORE_URL: "200"})
        self.assertEqual({"idle", "closing"}, phases)

    def test_a_rollback_of_the_closing_from_data_failed(self):
        phases = self.interrupt_everywhere(lambda run: ("failed", 1), {"PROFILE": "seed", "REPLACE": "failed"},
                                           after_patch={"shop": {"app": "shop", "set": {"operation": True}}}, **g.FAILED_START)
        self.assertEqual({"idle", "closing"}, phases)

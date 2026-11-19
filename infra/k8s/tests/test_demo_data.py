"""The CronJob `demo-data` in the render (docs/features/demo-scale/spec.md, "Job generatora na serwerze (chart)"; docs/features/demo-data/
spec.md, "k3d"): a suspended CronJob in the profiles prod, ci and load, whose Jobs (created by make generate, make load and the smoke
test with DEMO_DATA_CONFIRM) run files/scripts/demo-data.sh, which runs bin/generate-demo (it deletes the data of every module) or
bin/seed-demo under a guard of the free space. It exists only where demoData.enabled is true, runs the api image with the secret of the
stack and the login of the Keycloak administrator, has its own resources and a time limit per profile of the generator, is never
repeated, gets the time to stop the generator on TERM, makes Postgres end the sessions of a generator that is gone, and the secret of
the read access to the photo pool reaches no other container.

The cases: prod (medium with the anchor and the photo pool of the server), prod-demo-data-server (the second step on the server), ci and
load (the profile small), ci-no-demo-data (ci with the switch off), ci-demo-data-medium (the profile medium with an anchor, the secret of
the photo pool and other resources), ci-demo-data-server and ci-demo-data-full (the profiles with the longer limits)."""

import unittest

from support import DIGESTS, REPO, REVISION, WORKLOADS, by_logical, containers, docs, env_of, logical, name, of_kind, pod_spec, wave

PHOTOS_SECRET = "demo-photos"
PHOTOS_KEYS = ("DEMO_PHOTOS_ENDPOINT", "DEMO_PHOTOS_BUCKET", "DEMO_PHOTOS_ACCESS_KEY_ID", "DEMO_PHOTOS_SECRET_ACCESS_KEY")
STACK_SECRET = "shop-secrets"
SCRIPT = "infra/k8s/charts/shop/files/scripts/demo-data.sh"
PROD_ANCHOR = "2026-11-19T00:00:00Z"
ALL_CASES = ("prod", "prod-demo-data-server", "ci", "load", "ci-demo-data-medium", "ci-demo-data-server", "ci-demo-data-full")


def demo_data(case):
    return by_logical(case, "CronJob")["demo-data"]


def container(case):
    (only,) = containers(demo_data(case))
    return only


def secret_refs(container_):
    """The (variable, secret, key) of the secretKeyRefs and the (*, secret, *) of the envFrom of a container."""
    found = []
    for variable in container_.get("env", []):
        ref = variable.get("valueFrom", {}).get("secretKeyRef")
        if ref:
            found.append((variable["name"], ref["name"], ref["key"]))
    for source in container_.get("envFrom", []):
        if "secretRef" in source:
            found.append(("*", source["secretRef"]["name"], "*"))
    return found


def everything_but_demo_data(case):
    return [doc for doc in docs(case) if not (doc["kind"] == "CronJob" and logical(doc) == "demo-data")]


class WhereItRendersTest(unittest.TestCase):
    def test_it_renders_in_the_profiles_prod_ci_and_load(self):
        for case in ("prod", "prod-backup", "prod-nobackup", "prod-images-file", "ci", "ci-images-file", "load"):
            self.assertIn("demo-data", set(by_logical(case, "CronJob")), case)

    def test_the_switch_adds_the_cronjob_and_nothing_else(self):
        """No other object of the profile changes with it: no new variable, no new volume."""
        self.assertEqual(everything_but_demo_data("ci"), docs("ci-no-demo-data"))
        self.assertNotIn("demo-data", set(by_logical("ci-no-demo-data", "CronJob")))


class CronJobTest(unittest.TestCase):
    def test_the_cronjob_is_suspended_so_that_it_never_runs_by_itself(self):
        for case in ALL_CASES:
            spec = demo_data(case)["spec"]
            self.assertIs(True, spec["suspend"], case)
            self.assertEqual("Forbid", spec["concurrencyPolicy"], case)
            self.assertNotIn("startingDeadlineSeconds", spec, case)

    def test_a_failed_run_is_never_repeated(self):
        for case in ALL_CASES:
            job = demo_data(case)["spec"]["jobTemplate"]["spec"]
            self.assertEqual(0, job["backoffLimit"], case)
            self.assertEqual("Never", job["template"]["spec"]["restartPolicy"], case)

    def test_the_jobs_carry_the_label_of_the_generator_themselves(self):
        """rerun refuses a Job with this label (make generate creates the Jobs of the generator); the Job has it from the jobTemplate,
        not only from the template of its pod."""
        for case in ALL_CASES:
            labels = demo_data(case)["spec"]["jobTemplate"].get("metadata", {}).get("labels", {})
            self.assertEqual("demo-data", labels.get("app.kubernetes.io/name"), case)

    def test_the_job_of_a_run_stays_until_the_next_make_generate_deletes_it(self):
        """make open opens the shop only after the Job demo-data-run completed, and make generate deletes that Job of the run before
        right before its new Job: no ttlSecondsAfterFinished may delete it earlier (make open would refuse) or keep a stale one."""
        for case in ALL_CASES:
            spec = demo_data(case)["spec"]
            self.assertNotIn("ttlSecondsAfterFinished", spec["jobTemplate"]["spec"], case)
            # The CronJob keeps its newest Job that completed: the only Job demo-data-run of a run.
            self.assertGreaterEqual(spec["successfulJobsHistoryLimit"], 1, case)

    def test_the_jobs_get_nothing_that_argo_cd_tracks(self):
        """A Job made with kubectl create job --from=cronjob/demo-data gets the labels and the annotations of the jobTemplate. Argo CD 3
        (argocd/install/argocd.yaml sets no application.resourceTrackingMethod, so the annotation argocd.argoproj.io/tracking-id tracks)
        puts its annotation only on the CronJob, so the Job is no resource of the application shop and no synchronization prunes it."""
        for case in ALL_CASES:
            metadata = demo_data(case)["spec"]["jobTemplate"].get("metadata", {})
            self.assertEqual({}, metadata.get("annotations", {}), case)
        install = (REPO / "infra/k8s/argocd/install/argocd.yaml").read_text()
        self.assertNotIn("resourceTrackingMethod", install)
        self.assertNotIn("instanceLabelKey", install)
        # The chart argo-cd 8.0 brought Argo CD 3.0, whose default tracking is the annotation.
        (version,) = [line.split(":", 1)[1].strip() for line in install.splitlines() if line.startswith("  version:")]
        self.assertGreaterEqual(int(version.split(".")[0]), 8, version)

    def test_the_time_limit_follows_the_profile_of_the_generator(self):
        limits = {"ci": 1800, "load": 1800, "ci-demo-data-medium": 3600, "ci-demo-data-server": 32400, "ci-demo-data-full": 72000,
                  "prod": 6 * 3600, "prod-demo-data-server": 24 * 3600}
        for case, seconds in limits.items():
            self.assertEqual(seconds, demo_data(case)["spec"]["jobTemplate"]["spec"]["activeDeadlineSeconds"], case)

    def test_the_pod_has_the_time_to_stop_the_generator(self):
        for case in ALL_CASES:
            self.assertEqual(120, pod_spec(demo_data(case))["terminationGracePeriodSeconds"], case)

    def test_it_runs_the_script_of_the_chart_with_the_profile_and_the_floor_of_the_values(self):
        self.assertEqual(["sh", "/scripts/demo-data.sh", "--profile=small", "--min-free-gb=2"], container("ci")["command"])
        self.assertEqual(["sh", "/scripts/demo-data.sh", "--profile=small", "--min-free-gb=2"], container("load")["command"])
        self.assertEqual(["sh", "/scripts/demo-data.sh", "--profile=server", "--min-free-gb=2"], container("ci-demo-data-server")["command"])
        self.assertEqual(["sh", "/scripts/demo-data.sh", "--profile=full", "--min-free-gb=2"], container("ci-demo-data-full")["command"])
        self.assertEqual(["sh", "/scripts/demo-data.sh", "--profile=medium", f"--anchor={PROD_ANCHOR}", "--min-free-gb=32"],
                         container("prod")["command"])
        self.assertEqual(["sh", "/scripts/demo-data.sh", "--profile=server", f"--anchor={PROD_ANCHOR}", "--min-free-gb=32"],
                         container("prod-demo-data-server")["command"])

    def test_the_break_of_the_smoke_test_patches_the_argument_of_the_profile(self):
        """SMOKE_BREAK=demo-data replaces --profile=small at a fixed index of the command (a JSON patch with a test of the value)."""
        smoke = (REPO / "infra/k8s/scripts/smoke.sh").read_text()
        index = container("ci")["command"].index("--profile=small")
        self.assertIn(f"generator_arg=/spec/jobTemplate/spec/template/spec/containers/0/command/{index}\n", smoke)

    def test_the_anchor_is_passed_only_when_the_values_give_one(self):
        self.assertFalse([argument for argument in container("ci")["command"] if argument.startswith("--anchor")])
        self.assertIn("--anchor=2026-11-01", container("ci-demo-data-medium")["command"])

    def test_the_script_is_the_one_of_the_chart_mounted_read_only(self):
        script = (REPO / SCRIPT).read_text()
        for case in ("prod", "ci", "load"):
            mounts = {mount["mountPath"]: mount for mount in container(case).get("volumeMounts", [])}
            self.assertIs(True, mounts["/scripts"]["readOnly"], case)
            volumes = {volume["name"]: volume for volume in pod_spec(demo_data(case))["volumes"]}
            self.assertEqual("shop-scripts", volumes[mounts["/scripts"]["name"]]["configMap"]["name"], case)
            scripts = next(doc for doc in of_kind(case, "ConfigMap") if name(doc) == "shop-scripts")
            self.assertEqual(script, scripts["data"]["demo-data.sh"], case)

    def test_nothing_in_the_template_confirms_a_run(self):
        """DEMO_DATA_CONFIRM comes only from the one who creates the Job (make generate, make load, the smoke test)."""
        for case in ALL_CASES:
            self.assertNotIn("DEMO_DATA_CONFIRM", str(demo_data(case)), case)

    def test_postgres_ends_the_sessions_of_a_generator_that_is_gone(self):
        for case in ALL_CASES:
            self.assertEqual("-c client_connection_check_interval=10s", env_of(container(case))["PGOPTIONS"], case)

    def test_it_runs_the_api_image_of_the_revision(self):
        self.assertTrue(container("ci")["image"].endswith(f"/api:{REVISION}"))
        self.assertEqual(container("ci")["image"], containers(by_logical("ci", "Deployment")["api"])[0]["image"])
        self.assertNotIn("@", container("ci")["image"], "ci has no digests")
        self.assertTrue(container("prod")["image"].endswith(f"/api:{REVISION}@{DIGESTS['api']}"))
        self.assertEqual(container("prod")["image"], containers(by_logical("prod", "Deployment")["api"])[0]["image"])

    def test_it_is_in_the_wave_of_the_other_cronjobs_and_has_no_token_of_the_api(self):
        for case in ("prod", "ci"):
            self.assertEqual(1, wave(demo_data(case)), case)
            spec = pod_spec(demo_data(case))
            self.assertIs(False, spec["automountServiceAccountToken"], case)
            self.assertNotIn("serviceAccountName", spec, case)
            self.assertFalse([volume for volume in spec.get("volumes", []) if "projected" in volume], case)


class AccessTest(unittest.TestCase):
    def test_it_has_the_secret_of_the_stack_like_the_other_jobs_of_the_api(self):
        for case in ("prod", "ci"):
            self.assertIn(("*", STACK_SECRET, "*"), secret_refs(container(case)), case)

    def test_it_logs_in_to_keycloak_as_the_job_keycloak_config_does(self):
        keycloak_config = env_of(containers(by_logical("ci", "Job")["keycloak-config"])[0])
        env = env_of(container("ci"))
        self.assertEqual("admin", keycloak_config["KC_BOOTSTRAP_ADMIN_USERNAME"])
        self.assertEqual(keycloak_config["KC_BOOTSTRAP_ADMIN_USERNAME"], env["KC_BOOTSTRAP_ADMIN_USERNAME"])
        self.assertEqual("http://keycloak:8080", env["DEMO_DATA_KEYCLOAK_URL"])
        self.assertEqual("prod", env["APP_ENV"], "the image of the api is a production image, so the generator needs --force-reset")

    def test_it_has_its_own_resources_and_not_those_of_a_job(self):
        resources = container("ci")["resources"]
        self.assertIn("memory", resources["requests"])
        self.assertIn("cpu", resources["requests"])
        self.assertEqual({"cpu": "100m", "memory": "777Mi"}, container("ci-demo-data-medium")["resources"]["requests"])

    def test_prod_gives_it_the_resources_of_the_values_of_the_server(self):
        self.assertEqual({"requests": {"cpu": "500m", "memory": "1Gi"}, "limits": {"memory": "4Gi"}}, container("prod")["resources"])


class PhotoPoolSecretTest(unittest.TestCase):
    def test_without_a_name_there_is_no_reference_to_the_photo_pool(self):
        for case in ("ci", "load"):
            for variable in container(case).get("env", []):
                self.assertNotIn("DEMO_PHOTOS", variable["name"], case)
            self.assertNotIn(PHOTOS_SECRET, str(docs(case)), case)

    def test_the_secret_gives_the_four_read_variables_to_the_container_of_the_cronjob(self):
        for case in ("ci-demo-data-medium", "prod"):
            refs = [ref for ref in secret_refs(container(case)) if ref[1] == PHOTOS_SECRET]
            self.assertEqual(sorted((key, PHOTOS_SECRET, key) for key in PHOTOS_KEYS), sorted(refs), case)

    def test_the_references_are_optional_so_that_a_job_of_the_seed_starts_without_the_secret(self):
        for case in ("ci-demo-data-medium", "prod"):
            for variable in container(case)["env"]:
                ref = variable.get("valueFrom", {}).get("secretKeyRef")
                if ref and ref["name"] == PHOTOS_SECRET:
                    self.assertIs(True, ref.get("optional"), f"{case} {variable['name']}")

    def test_no_other_container_has_the_secret_and_nothing_mounts_it(self):
        for case in ("ci-demo-data-medium", "prod"):
            for doc in of_kind(case, *WORKLOADS):
                spec = pod_spec(doc)
                for each in spec["containers"] + spec.get("initContainers", []):
                    belongs = doc["kind"] == "CronJob" and logical(doc) == "demo-data"
                    found = any(ref[1] == PHOTOS_SECRET for ref in secret_refs(each))
                    self.assertEqual(belongs, found, f"{case} {logical(doc)}/{each['name']}")
                self.assertNotIn(PHOTOS_SECRET, str(spec.get("volumes", [])), f"{case} {logical(doc)}")
            # The chart does not create the secret (make generate makes it in the cluster for a run), and nothing else names it.
            self.assertFalse([doc for doc in docs(case) if doc["kind"] == "Secret" and PHOTOS_SECRET in doc["metadata"]["name"]], case)

    def test_the_write_token_of_the_pool_is_nowhere(self):
        for case in ("ci-demo-data-medium", "ci", "load", "prod"):
            self.assertNotIn("DEMO_PHOTOS_WRITE", str(docs(case)), case)


if __name__ == "__main__":
    unittest.main()

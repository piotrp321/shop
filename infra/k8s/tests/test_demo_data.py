"""The CronJob `demo-data` in the render (docs/features/demo-data/spec.md, "k3d (chart w profilach ci i load; część 2)"): a suspended
CronJob in the profiles ci and load, whose Jobs (created by the smoke test and by make load) run bin/generate-demo, which deletes the
data of every module. It exists only where demoData.enabled is true, runs the api image with the secret of the stack and the login of
the Keycloak administrator, has its own resources and a time limit per profile of the generator, is never repeated, and the secret of
the read access to the photo pool reaches no other container.

The cases: ci and load (the profile small), ci-no-demo-data (ci with the switch off), ci-demo-data-medium (the profile medium with an
anchor, the secret of the photo pool and other resources), ci-demo-data-server and ci-demo-data-full (the profiles with the longer limits)."""

import unittest

from support import REVISION, WORKLOADS, by_logical, containers, docs, env_of, logical, of_kind, pod_spec, wave

PHOTOS_SECRET = "demo-photos"
PHOTOS_KEYS = ("DEMO_PHOTOS_ENDPOINT", "DEMO_PHOTOS_BUCKET", "DEMO_PHOTOS_ACCESS_KEY_ID", "DEMO_PHOTOS_SECRET_ACCESS_KEY")
STACK_SECRET = "shop-secrets"


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
    def test_it_renders_in_the_profiles_ci_and_load(self):
        for case in ("ci", "ci-images-file", "load"):
            self.assertIn("demo-data", set(by_logical(case, "CronJob")), case)

    def test_it_does_not_render_in_prod(self):
        for case in ("prod", "prod-backup", "prod-nobackup", "prod-images-file"):
            self.assertNotIn("demo-data", set(by_logical(case, "CronJob")), case)

    def test_prod_has_no_trace_of_it(self):
        for case in ("prod", "prod-backup"):
            for doc in docs(case):
                for trace in ("generate-demo", "DEMO_DATA_KEYCLOAK", "DEMO_PHOTOS"):
                    self.assertNotIn(trace, str(doc), f"{case}: {doc['kind']}/{doc['metadata']['name']}")

    def test_the_switch_adds_the_cronjob_and_nothing_else(self):
        """No other object of the profile changes with it: no new variable, no new volume."""
        self.assertEqual(everything_but_demo_data("ci"), docs("ci-no-demo-data"))
        self.assertNotIn("demo-data", set(by_logical("ci-no-demo-data", "CronJob")))


class CronJobTest(unittest.TestCase):
    def test_the_cronjob_is_suspended_so_that_it_never_runs_by_itself(self):
        for case in ("ci", "load", "ci-demo-data-medium", "ci-demo-data-server", "ci-demo-data-full"):
            spec = demo_data(case)["spec"]
            self.assertIs(True, spec["suspend"], case)
            self.assertEqual("Forbid", spec["concurrencyPolicy"], case)
            self.assertNotIn("startingDeadlineSeconds", spec, case)

    def test_a_failed_run_is_never_repeated(self):
        for case in ("ci", "load"):
            job = demo_data(case)["spec"]["jobTemplate"]["spec"]
            self.assertEqual(0, job["backoffLimit"], case)
            self.assertEqual("Never", job["template"]["spec"]["restartPolicy"], case)

    def test_the_time_limit_follows_the_profile_of_the_generator(self):
        limits = {"ci": 1800, "load": 1800, "ci-demo-data-medium": 3600, "ci-demo-data-server": 32400, "ci-demo-data-full": 72000}
        for case, seconds in limits.items():
            self.assertEqual(seconds, demo_data(case)["spec"]["jobTemplate"]["spec"]["activeDeadlineSeconds"], case)

    def test_it_runs_the_generator_of_the_profile_that_is_in_the_values(self):
        self.assertEqual(["bin/generate-demo", "--profile=small", "--force-reset"], container("ci")["command"])
        self.assertEqual(["bin/generate-demo", "--profile=small", "--force-reset"], container("load")["command"])
        self.assertEqual(["bin/generate-demo", "--profile=server", "--force-reset"], container("ci-demo-data-server")["command"])
        self.assertEqual(["bin/generate-demo", "--profile=full", "--force-reset"], container("ci-demo-data-full")["command"])

    def test_the_anchor_is_passed_only_when_the_values_give_one(self):
        self.assertFalse([argument for argument in container("ci")["command"] if argument.startswith("--anchor")])
        self.assertEqual(
            ["bin/generate-demo", "--profile=medium", "--anchor=2026-11-01", "--force-reset"], container("ci-demo-data-medium")["command"]
        )

    def test_it_runs_the_api_image_of_the_revision(self):
        self.assertTrue(container("ci")["image"].endswith(f"/api:{REVISION}"))
        self.assertEqual(container("ci")["image"], containers(by_logical("ci", "Deployment")["api"])[0]["image"])
        self.assertNotIn("@", container("ci")["image"], "ci has no digests")

    def test_it_is_in_the_wave_of_the_other_cronjobs_and_has_no_token_of_the_api(self):
        self.assertEqual(1, wave(demo_data("ci")))
        spec = pod_spec(demo_data("ci"))
        self.assertIs(False, spec["automountServiceAccountToken"])
        self.assertNotIn("serviceAccountName", spec)
        self.assertFalse([volume for volume in spec.get("volumes", []) if "projected" in volume])


class AccessTest(unittest.TestCase):
    def test_it_has_the_secret_of_the_stack_like_the_other_jobs_of_the_api(self):
        self.assertIn(("*", STACK_SECRET, "*"), secret_refs(container("ci")))

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


class PhotoPoolSecretTest(unittest.TestCase):
    def test_without_a_name_there_is_no_reference_to_the_photo_pool(self):
        for case in ("ci", "load"):
            for variable in container(case).get("env", []):
                self.assertNotIn("DEMO_PHOTOS", variable["name"], case)
            self.assertNotIn(PHOTOS_SECRET, str(docs(case)), case)

    def test_the_secret_gives_the_four_read_variables_to_the_container_of_the_cronjob(self):
        refs = [ref for ref in secret_refs(container("ci-demo-data-medium")) if ref[1] == PHOTOS_SECRET]
        self.assertEqual(sorted((key, PHOTOS_SECRET, key) for key in PHOTOS_KEYS), sorted(refs))

    def test_no_other_container_has_the_secret_and_nothing_mounts_it(self):
        for doc in of_kind("ci-demo-data-medium", *WORKLOADS):
            spec = pod_spec(doc)
            for each in spec["containers"] + spec.get("initContainers", []):
                belongs = doc["kind"] == "CronJob" and logical(doc) == "demo-data"
                found = any(ref[1] == PHOTOS_SECRET for ref in secret_refs(each))
                self.assertEqual(belongs, found, f"{logical(doc)}/{each['name']}")
            self.assertNotIn(PHOTOS_SECRET, str(spec.get("volumes", [])), logical(doc))
        # The chart does not create the secret (it is the one of the user's file, made in the cluster), and nothing else names it.
        self.assertFalse([doc for doc in docs("ci-demo-data-medium") if doc["kind"] == "Secret" and PHOTOS_SECRET in doc["metadata"]["name"]])

    def test_the_write_token_of_the_pool_is_nowhere(self):
        for case in ("ci-demo-data-medium", "ci", "load"):
            self.assertNotIn("DEMO_PHOTOS_WRITE", str(docs(case)), case)


if __name__ == "__main__":
    unittest.main()

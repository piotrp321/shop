"""The CronJob `load` in the render (docs/features/demo-scale/spec.md, "Sprawdzenie po generowaniu", point 4): a suspended CronJob in
every profile (prod, ci and load), whose Jobs (created after the generation of the demo data) run load/smoke.js of k6 against the
services of the cluster. The k6 image is the one of load/k6.sh, the scripts are the files of load/ (a ConfigMap rendered from the
directory through a symlink in files/), and the pod has no secret and no token of the Kubernetes API.

The cases: prod, prod-domain (another domain), ci, ci-images-file and load."""

import posixpath
import re
import unittest

from support import REPO, by_logical, containers, env_of, of_kind, pod_spec, wave

CASES = ("prod", "prod-domain", "ci", "ci-images-file", "load")
MOUNT = "/load"
VALUES = REPO / "infra/k8s/charts/shop/values.yaml"
K6_IMAGE = re.compile(r"grafana/k6:[0-9]+\.[0-9]+\.[0-9]+@sha256:[0-9a-f]{64}")
IMPORT = re.compile(r"""from\s+['"](\.{1,2}/[^'"]+)['"]""")


def cronjob(case):
    return by_logical(case, "CronJob")["load"]


def container(case):
    (only,) = containers(cronjob(case))
    return only


def scripts_of_the_repository():
    """The .js files of load/ and load/lib/ by their path under load/: what the ConfigMap has to carry."""
    paths = sorted((REPO / "load").glob("*.js")) + sorted((REPO / "load/lib").glob("*.js"))
    return {str(path.relative_to(REPO / "load")): path.read_text() for path in paths}


def config_map(case):
    (found,) = [doc for doc in of_kind(case, "ConfigMap") if doc["metadata"]["name"] == "load-scripts"]
    return found


def mounted(case):
    """{path under the mount: text} of what the container sees at /load: the items of the volume, read from the ConfigMap."""
    spec = pod_spec(cronjob(case))
    (mount,) = [m for m in container(case)["volumeMounts"] if m["mountPath"] == MOUNT]
    (volume,) = [v for v in spec["volumes"] if v["name"] == mount["name"]]
    data = config_map(case)["data"]
    assert volume["configMap"]["name"] == "load-scripts", volume
    return {item["path"]: data[item["key"]] for item in volume["configMap"]["items"]}


class WhereItRendersTest(unittest.TestCase):
    def test_it_renders_in_prod_ci_and_load(self):
        for case in CASES:
            self.assertIn("load", set(by_logical(case, "CronJob")), case)

    def test_it_is_in_the_wave_of_the_other_cronjobs(self):
        for case in CASES:
            self.assertEqual(1, wave(cronjob(case)), case)


class CronJobTest(unittest.TestCase):
    def test_the_load_cronjob_is_suspended_so_that_it_never_runs_by_itself(self):
        for case in CASES:
            spec = cronjob(case)["spec"]
            self.assertIs(True, spec["suspend"], case)
            self.assertEqual("Forbid", spec["concurrencyPolicy"], case)
            self.assertNotIn("startingDeadlineSeconds", spec, case)

    def test_a_failed_run_is_never_repeated_and_has_a_time_limit(self):
        for case in CASES:
            job = cronjob(case)["spec"]["jobTemplate"]["spec"]
            self.assertEqual(0, job["backoffLimit"], case)
            self.assertEqual("Never", job["template"]["spec"]["restartPolicy"], case)
            self.assertGreater(job["activeDeadlineSeconds"], 0, case)

    def test_it_runs_the_smoke_script_of_load_in_the_k6_image_pinned_by_the_digest(self):
        for case in CASES:
            self.assertRegex(container(case)["image"], r"^" + K6_IMAGE.pattern + "$", case)
            self.assertEqual(["run", "--quiet", f"{MOUNT}/smoke.js"], container(case)["args"], case)
            self.assertNotIn("command", container(case), case)

    def test_the_image_is_the_one_of_the_runner_of_the_scripts(self):
        (pinned,) = K6_IMAGE.findall((REPO / "load/k6.sh").read_text())
        for case in CASES:
            self.assertEqual(pinned, container(case)["image"], case)

    def test_the_resources_are_the_ones_of_the_values(self):
        for case in CASES:
            self.assertEqual({"requests": {"cpu": "100m", "memory": "256Mi"}, "limits": {"memory": "1Gi"}}, container(case)["resources"], case)


class SettingsTest(unittest.TestCase):
    def test_k6_reaches_the_services_of_the_cluster_and_the_origin_is_the_public_address_of_the_store(self):
        origins = {"prod": "https://worf.pl", "prod-domain": "https://other.example.net", "ci": "http://localhost",
                   "ci-images-file": "http://localhost", "load": "http://localhost"}
        for case, origin in origins.items():
            env = env_of(container(case))
            self.assertEqual("http://store:3000", env["STORE_URL"], case)
            self.assertEqual("http://api", env["API_URL"], case)
            self.assertEqual(origin, env["STORE_ORIGIN"], case)
            self.assertEqual("/tmp", env["HOME"], case)

    def test_it_sets_only_the_variables_the_scripts_read(self):
        read = set(re.findall(r"""(?:text|number)\('([A-Z_]+)'""", (REPO / "load/lib/config.js").read_text()))
        self.assertTrue({"STORE_URL", "API_URL", "STORE_ORIGIN"} <= read)
        for case in CASES:
            self.assertLessEqual(set(env_of(container(case))) - {"HOME"}, read, case)
            self.assertTrue({"STORE_URL", "API_URL"} <= set(env_of(container(case))), case)


class WaitForTheApiTest(unittest.TestCase):
    """The Job of make generate runs while the shop is closed: under the NetworkPolicy shop-closed a pod that was just created gets
    "connection refused" for its first connections to a service of the namespace (about 0.1 s on k3d, until the controller of the
    policies knows its address), and k6 asks the API once in its setup, without a retry. So the pod first waits in an init container
    until the API answers from it; only then does k6 start, in the same network of the pod."""

    def init_containers(self, case):
        return pod_spec(cronjob(case)).get("initContainers", [])

    def test_k6_starts_only_after_the_api_answered_from_the_pod(self):
        for case in CASES:
            with self.subTest(case):
                (wait,) = self.init_containers(case)
                self.assertEqual("wait-for-api", wait["name"])
                script = " ".join(wait.get("command", []) + wait.get("args", []))
                self.assertIn("until", script)
                self.assertIn("http://api/health", script)
                self.assertEqual(env_of(container(case))["API_URL"] + "/health", "http://api/health")

    def test_the_wait_has_no_secret_either(self):
        for case in CASES:
            with self.subTest(case):
                (wait,) = self.init_containers(case)
                self.assertNotIn("envFrom", wait)
                self.assertNotIn("env", wait)
                self.assertIn("resources", wait)


class NoAccessTest(unittest.TestCase):
    def test_it_has_no_secret_and_no_environment_from_a_source(self):
        for case in CASES:
            pod = pod_spec(cronjob(case))
            self.assertNotIn("envFrom", container(case), case)
            self.assertFalse([e for e in container(case)["env"] if "valueFrom" in e], case)
            self.assertFalse([v for v in pod.get("volumes", []) if "secret" in v or "projected" in v], case)
            self.assertNotIn("secretKeyRef", str(cronjob(case)), case)
            self.assertNotIn("secretRef", str(cronjob(case)), case)

    def test_it_has_no_token_of_the_kubernetes_api(self):
        for case in CASES:
            pod = pod_spec(cronjob(case))
            self.assertIs(False, pod["automountServiceAccountToken"], case)
            self.assertNotIn("serviceAccountName", pod, case)


class ScriptsTest(unittest.TestCase):
    def test_the_config_map_has_every_script_of_load_and_load_lib_and_nothing_else(self):
        expected = scripts_of_the_repository()
        self.assertTrue({"smoke.js", "steady.js", "saturate.js", "warmup.js", "lib/config.js", "lib/flows.js"} <= set(expected))
        for case in CASES:
            self.assertEqual(expected, mounted(case), case)
            self.assertEqual(len(expected), len(config_map(case)["data"]), case)

    def test_the_container_sees_them_under_the_paths_the_scripts_import(self):
        for case in CASES:
            files = mounted(case)
            for path, text in files.items():
                for target in IMPORT.findall(text):
                    resolved = posixpath.normpath(posixpath.join(posixpath.dirname(path), target))
                    self.assertIn(resolved, files, f"{case}: {path} imports {target}")


class ImageValueTest(unittest.TestCase):
    def test_the_values_pin_the_image_of_the_runner_of_the_scripts(self):
        """images.k6 and K6_IMAGE of load/k6.sh are the same tag and digest: a new k6 is one change in two places, and this test fails
        for the one that was forgotten."""
        (pinned,) = K6_IMAGE.findall((REPO / "load/k6.sh").read_text())
        (in_values,) = re.findall(r"(?m)^  k6: (\S+)$", VALUES.read_text())
        self.assertEqual(pinned, in_values)


if __name__ == "__main__":
    unittest.main()

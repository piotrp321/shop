import hashlib
import unittest

from support import REPO, WORKLOADS, by_logical, containers, of_kind, pod_spec

TOKEN_PATH = "/var/run/secrets/kubernetes.io/serviceaccount"
KUBECTL = "registry.k8s.io/kubectl:"


def init_containers(workload):
    return {c["name"]: c for c in pod_spec(workload).get("initContainers", [])}


class RolloutWaitTest(unittest.TestCase):
    """D8: the jobs wait for the rollout of the new version, not for a Service that an old pod may still answer."""

    def test_jobs_wait_for_the_rollout_of_what_they_need(self):
        for case in ("prod", "ci"):
            jobs = by_logical(case, "Job")
            for job, needs in (("initialize", ("api", "varnish", "store", "worker")), ("purge-caches", ("api", "varnish", "store"))):
                waits = init_containers(jobs[job])
                for need in needs:
                    container = waits[f"wait-for-{need}"]
                    self.assertTrue(container["image"].startswith(KUBECTL), f"{case} {job} wait-for-{need}")
                    self.assertEqual(["rollout", "status", f"deployment/{need}"], container["args"][:3], f"{case} {job} {need}")

    def test_the_jobs_that_ask_the_cluster_run_as_the_service_account_that_may_read_deployments(self):
        jobs = by_logical("prod", "Job")
        for job in ("initialize", "purge-caches"):
            self.assertEqual("shop-wait", pod_spec(jobs[job])["serviceAccountName"], job)


class TokenTest(unittest.TestCase):
    def test_no_pod_gets_a_token_by_default(self):
        for case in ("prod", "ci"):
            for workload in of_kind(case, *WORKLOADS):
                self.assertIs(False, pod_spec(workload).get("automountServiceAccountToken"), f"{case} {workload['metadata']['name']}")

    def test_the_token_is_mounted_only_in_the_initcontainers_that_use_kubectl(self):
        for workload in of_kind("prod", *WORKLOADS):
            spec = pod_spec(workload)
            name = workload["metadata"]["name"]
            for container in spec["containers"]:
                self.assertNotIn(TOKEN_PATH, [m["mountPath"] for m in container.get("volumeMounts", [])], f"{name}/{container['name']}")
            for container in spec.get("initContainers", []):
                mounted = TOKEN_PATH in [m["mountPath"] for m in container.get("volumeMounts", [])]
                self.assertEqual(container["image"].startswith(KUBECTL), mounted, f"{name}/{container['name']}")

    def test_the_token_volume_is_projected_for_the_pods_that_mount_it(self):
        initialize = by_logical("prod", "Job")["initialize"]
        volumes = {v["name"]: v for v in pod_spec(initialize)["volumes"]}
        sources = volumes["kube-api-access"]["projected"]["sources"]
        self.assertTrue(any("serviceAccountToken" in s for s in sources))


class InitContainerTest(unittest.TestCase):
    def test_install_plugin_has_resources(self):
        init = init_containers(by_logical("prod", "StatefulSet")["elasticsearch"])["install-plugin"]
        self.assertTrue(init["resources"]["requests"]["memory"])
        self.assertTrue(init["resources"]["limits"]["memory"])

    def test_every_init_container_has_resources(self):
        for case in ("prod", "ci"):
            for workload in of_kind(case, *WORKLOADS):
                for name, container in init_containers(workload).items():
                    self.assertIn("resources", container, f"{case} {workload['metadata']['name']}/{name}")


class GarageInitNameTest(unittest.TestCase):
    def test_the_job_carries_the_checksum_of_the_files_it_runs(self):
        job = by_logical("prod", "Job")["garage-init"]
        expected = hashlib.sha256(
            (REPO / "infra/garage/garage.toml").read_bytes() + (REPO / "infra/garage/init.sh").read_bytes()
        ).hexdigest()
        self.assertEqual(expected, job["spec"]["template"]["metadata"]["annotations"]["checksum/garage-config"])


if __name__ == "__main__":
    unittest.main()

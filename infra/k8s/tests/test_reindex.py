"""The shards and the refresh interval of the search index and the CronJob `reindex` in the render (docs/features/search/spec.md,
"Infrastruktura").

elasticsearch.shards of the values is SEARCH_SHARDS and elasticsearch.refreshInterval is SEARCH_REFRESH_INTERVAL of every container
that runs the code of the API (the helper shop.apiEnv), so the Deployment, the workers, the Jobs and the CronJobs create a new
product index with the same number of primary shards and set the same refresh interval. The CronJob
`reindex` is suspended in every profile: make -C infra/k8s reindex creates its Jobs, which run catalog:reindex with the image, the
environment and the secrets of the deployed revision.

The cases: prod, prod-domain, ci, ci-images-file, load and ci-shards (ci with elasticsearch.shards=3 and
elasticsearch.refreshInterval=500ms)."""

import unittest

from support import DIGEST, by_logical, containers, env_of, of_kind, pod_spec, wave

CASES = ("prod", "prod-domain", "ci", "ci-images-file", "load", "ci-shards")
SHARDS = {"prod": "1", "prod-domain": "1", "ci": "1", "ci-images-file": "1", "load": "1", "ci-shards": "3"}
REFRESH_INTERVALS = {"prod": "5s", "prod-domain": "5s", "ci": "1s", "ci-images-file": "1s", "load": "1s", "ci-shards": "500ms"}


def cronjob(case):
    return by_logical(case, "CronJob")["reindex"]


def container(case):
    (only,) = containers(cronjob(case))
    return only


def api_containers(case):
    """(workload name, container) of every container with the environment of the API: the ones that know Elasticsearch."""
    found = []
    for doc in of_kind(case, "Deployment", "StatefulSet", "Job", "CronJob"):
        spec = pod_spec(doc)
        for one in spec["containers"] + spec.get("initContainers", []):
            if "ELASTICSEARCH_URL" in env_of(one):
                found.append((doc["metadata"]["name"], one))
    return found


class ShardsOfTheIndexTest(unittest.TestCase):
    def test_every_container_of_the_api_gets_the_shards_of_the_values(self):
        for case in CASES:
            found = api_containers(case)
            names = {name for name, _ in found}
            # The API, both workers and the CronJob of the rebuild at least; the Jobs of the version carry a hash in their name.
            self.assertLessEqual({"api", "worker", "worker-commands", "reindex"}, names, case)
            for name, one in found:
                self.assertEqual(SHARDS[case], env_of(one).get("SEARCH_SHARDS"), f"{case}: {name}/{one['name']}")

    def test_every_profile_builds_an_index_of_one_shard(self):
        self.assertEqual("1", env_of(container("prod"))["SEARCH_SHARDS"])
        self.assertEqual("1", env_of(container("ci"))["SEARCH_SHARDS"])


class RefreshIntervalOfTheIndexTest(unittest.TestCase):
    def test_every_container_of_the_api_gets_the_refresh_interval_of_the_values(self):
        for case in CASES:
            for name, one in api_containers(case):
                self.assertEqual(
                    REFRESH_INTERVALS[case], env_of(one).get("SEARCH_REFRESH_INTERVAL"), f"{case}: {name}/{one['name']}"
                )

    def test_the_server_refreshes_every_five_seconds_and_the_other_profiles_every_second(self):
        self.assertEqual("5s", env_of(container("prod"))["SEARCH_REFRESH_INTERVAL"])
        self.assertEqual("1s", env_of(container("ci"))["SEARCH_REFRESH_INTERVAL"])


class ReindexCronJobTest(unittest.TestCase):
    def test_it_renders_in_every_profile(self):
        for case in CASES:
            self.assertIn("reindex", set(by_logical(case, "CronJob")), case)

    def test_the_reindex_cronjob_is_suspended_so_that_it_never_runs_by_itself(self):
        for case in CASES:
            spec = cronjob(case)["spec"]
            self.assertIs(True, spec["suspend"], case)
            self.assertEqual("Forbid", spec["concurrencyPolicy"], case)

    def test_a_failed_rebuild_is_not_repeated(self):
        for case in CASES:
            job = cronjob(case)["spec"]["jobTemplate"]["spec"]
            self.assertEqual(0, job["backoffLimit"], case)
            self.assertEqual("Never", pod_spec(cronjob(case))["restartPolicy"], case)

    def test_the_deadline_is_the_one_of_the_values(self):
        for case in CASES:
            self.assertEqual(21600, cronjob(case)["spec"]["jobTemplate"]["spec"]["activeDeadlineSeconds"], case)

    def test_it_is_in_the_wave_of_the_other_cronjobs(self):
        for case in CASES:
            self.assertEqual(1, wave(cronjob(case)), case)

    def test_it_runs_catalog_reindex_with_the_image_of_the_api(self):
        for case in CASES:
            one = container(case)
            (api,) = containers(by_logical(case, "Deployment")["api"])
            self.assertEqual(api["image"], one["image"], case)
            self.assertEqual(["php", "bin/console", "catalog:reindex"], one["command"], case)
            self.assertNotIn("args", one, case)
        self.assertRegex(container("prod")["image"], DIGEST)

    def test_it_has_the_environment_and_the_secrets_of_the_jobs_of_the_api(self):
        # The Job migrate creates the first index (catalog:search-index:setup): the rebuild reads the same settings and secrets.
        for case in CASES:
            one = container(case)
            (migrate,) = containers(by_logical(case, "Job")["migrate"])
            self.assertEqual(env_of(migrate), env_of(one), case)
            self.assertEqual(migrate["envFrom"], one["envFrom"], case)
            self.assertIn("ELASTICSEARCH_URL", env_of(one), case)

    def test_the_pod_has_no_token_of_the_kubernetes_api_and_no_init_container(self):
        for case in CASES:
            spec = pod_spec(cronjob(case))
            self.assertIs(False, spec["automountServiceAccountToken"], case)
            self.assertIs(False, spec["enableServiceLinks"], case)
            self.assertNotIn("initContainers", spec, case)

    def test_it_has_the_resources_of_a_job(self):
        for case in CASES:
            (migrate,) = containers(by_logical(case, "Job")["migrate"])
            self.assertEqual(migrate["resources"], container(case)["resources"], case)


if __name__ == "__main__":
    unittest.main()

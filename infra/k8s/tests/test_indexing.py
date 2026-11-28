"""The workers of the search rebuild (docs/features/async-indexing/spec.md) in the render: the Deployment worker-indexing, its
replicas and resources from the values, and the size of the batch (SEARCH_INDEX_BATCH_SIZE) of every container with the code of
the API. What transports it consumes and that every transport of messenger.yaml has a consumer: tests/test_workloads.py.

The cases: prod, prod-indexing (prod with workers.indexing.replicas=4; that the node fits them: tests/test_workloads.py, `test_prod_fits_the_server`),
ci, load and ci-indexing (ci with workers.indexing.replicas=4 and search.indexBatchSize=1000)."""

import re
import unittest

from support import REPO, by_logical, containers, env_of, of_kind, pod_spec, quantity_bytes

CASES = ("prod", "prod-indexing", "ci", "load", "ci-indexing")
REPLICAS = {"prod": 1, "prod-indexing": 4, "ci": 1, "load": 1, "ci-indexing": 4}
BATCH_SIZES = {"prod": "500", "prod-indexing": "500", "ci": "500", "load": "500", "ci-indexing": "1000"}


def indexing(case):
    return by_logical(case, "Deployment")["worker-indexing"]


class WorkerIndexingTest(unittest.TestCase):
    def test_the_replicas_are_the_value_of_the_values(self):
        for case in CASES:
            self.assertEqual(REPLICAS[case], indexing(case)["spec"]["replicas"], case)

    def test_the_other_workers_stay_single_replica(self):
        for case in CASES:
            for name in ("worker", "worker-commands"):
                self.assertEqual(1, by_logical(case, "Deployment")[name]["spec"]["replicas"], f"{case} {name}")

    def test_it_never_loses_capacity_during_an_update(self):
        for case in CASES:
            self.assertEqual("RollingUpdate", indexing(case)["spec"]["strategy"]["type"], case)
            self.assertEqual(0, indexing(case)["spec"]["strategy"]["rollingUpdate"]["maxUnavailable"], case)

    def test_it_runs_the_image_of_the_api_with_its_own_resources(self):
        for case in CASES:
            (one,) = containers(indexing(case))
            (api,) = containers(by_logical(case, "Deployment")["api"])
            self.assertEqual(api["image"], one["image"], case)
            self.assertIn("requests", one["resources"], case)
            self.assertNotEqual(by_logical(case, "Deployment")["worker"]["metadata"]["name"], indexing(case)["metadata"]["name"], case)

    def test_prod_and_load_give_each_replica_the_memory_of_a_php_process_that_ends_at_192_megabytes(self):
        # --memory-limit=192M ends the process after a message: the limit of the container leaves room above it, as the commands worker has.
        for case in ("prod", "load"):
            resources = containers(indexing(case))[0]["resources"]
            self.assertGreater(quantity_bytes(resources["limits"]["memory"]), 192 * 2**20, case)
            self.assertGreaterEqual(quantity_bytes(resources["requests"]["memory"]), 192 * 2**20, case)


class BatchSizeTest(unittest.TestCase):
    def test_every_container_with_the_code_of_the_api_gets_the_batch_size_of_the_values(self):
        for case in CASES:
            found = 0
            for doc in of_kind(case, "Deployment", "StatefulSet", "Job", "CronJob"):
                spec = pod_spec(doc)
                for one in spec["containers"] + spec.get("initContainers", []):
                    if "ELASTICSEARCH_URL" in env_of(one):
                        found += 1
                        self.assertEqual(BATCH_SIZES[case], env_of(one).get("SEARCH_INDEX_BATCH_SIZE"), f"{case}: {doc['metadata']['name']}/{one['name']}")
            self.assertGreater(found, 5, case)

    def test_compose_and_the_chart_have_the_same_default_batch_size(self):
        compose = (REPO / "infra/docker-compose.yml").read_text()
        match = re.search(r"^      SEARCH_INDEX_BATCH_SIZE: \$\{SEARCH_INDEX_BATCH_SIZE:-(\d+)\}$", compose, re.M)
        self.assertIsNotNone(match, "compose passes SEARCH_INDEX_BATCH_SIZE to every container of the api (*api-environment)")
        self.assertEqual(BATCH_SIZES["prod"], match.group(1))
        # worker-indexing inherits the shared environment of the api: it does not redefine the variable.
        self.assertNotIn("SEARCH_INDEX_BATCH_SIZE", re.search(r"^  worker-indexing:.*?(?=^  store:)", compose, re.M | re.S).group(0))


if __name__ == "__main__":
    unittest.main()

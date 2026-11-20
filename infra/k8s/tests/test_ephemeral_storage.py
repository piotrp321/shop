"""The disk limit of the store (docs/features/small-fixes/spec.md, D4): the writable layer of the container counts to
its `ephemeral-storage`, and the limit guards the disk of the node (the ISR cache stays in memory). prod (and its copy
load) request 256Mi and limit 2Gi; the chart defaults and the profile ci have no entry (helm merges maps, so an entry in
the defaults would reach ci and the throwaway clusters)."""

import unittest

from support import by_logical, containers

FIELDS = {"requests": "256Mi", "limits": "2Gi"}


def store_resources(case):
    (container,) = [c for c in containers(by_logical(case, "Deployment")["store"]) if c["name"] == "store"]
    return container["resources"]


class StoreEphemeralStorageTest(unittest.TestCase):
    def test_prod_and_load_request_256mi_and_limit_2gi(self):
        for case in ("prod", "load"):
            resources = store_resources(case)
            for field, value in FIELDS.items():
                self.assertEqual(value, resources[field].get("ephemeral-storage"), f"{case} {field}")

    def test_ci_and_therefore_the_chart_defaults_have_no_ephemeral_storage(self):
        resources = store_resources("ci")
        for field in FIELDS:
            self.assertNotIn("ephemeral-storage", resources.get(field, {}), f"ci {field}")


if __name__ == "__main__":
    unittest.main()

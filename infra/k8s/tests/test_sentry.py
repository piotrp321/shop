"""D1, D2 and D3 of docs/features/sentry/spec.md: the SENTRY_* variables of the applications in the render.

A variable is emitted only for an application whose DSN is not empty: the DSN of the api reaches every container with the
environment of the api (api, worker, Jobs, init containers, the CronJobs), the DSN of the store only the store, the DSN of the
panel only the panel. The renders that must fail (a sample rate outside 0-1, a DSN in the wrong format) are in
scripts/check.sh (do_schema), like every other refused value.
"""

import unittest

from support import REVISION, WORKLOADS, env_of, logical, of_kind, pod_spec

API_DSN = "https://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@o1.ingest.de.sentry.io/1"
STORE_DSN = "https://bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb@o1.ingest.de.sentry.io/2"
ADMIN_DSN = "https://cccccccccccccccccccccccccccccccc@o1.ingest.de.sentry.io/3"
# The marker of the environment of the api (shop.apiEnv): every container that has it is a container of the api image.
API_MARKER = "SHOP_DB_HOST"
# Containers that must get the DSN of the api, among others (the ones with the marker, which the test finds itself).
API_WORKLOADS = {"api", "worker", "migrate", "initialize", "purge-caches", "restore", "cart-purge-expired"}


def containers_of(case):
    """The environment of every container (init containers too) of every workload, by `<workload>/<container>`."""
    found = {}
    for doc in of_kind(case, *WORKLOADS):
        spec = pod_spec(doc)
        for container in spec.get("initContainers", []) + spec["containers"]:
            found[f"{logical(doc)}/{container['name']}"] = env_of(container)
    return found


def sentry_of(case):
    """The SENTRY_* variables of the containers that have any, by `<workload>/<container>`."""
    return {
        key: {k: v for k, v in env.items() if k.startswith("SENTRY_")}
        for key, env in containers_of(case).items()
        if any(k.startswith("SENTRY_") for k in env)
    }


def holders(case, variable):
    """The containers (`<workload>/<container>`) that have the variable, with its value."""
    return {key: env[variable] for key, env in containers_of(case).items() if variable in env}


class SentryEnvTest(unittest.TestCase):
    def test_no_dsn_no_variable_at_all(self):
        """The profiles as they are (prod and ci have no DSN) and a render with an environment and a rate but without a DSN."""
        for case in ("prod", "ci", "load", "ci-sentry-nodsn"):
            self.assertEqual({}, sentry_of(case), case)

    def test_the_dsn_of_the_api_reaches_every_container_with_the_environment_of_the_api_and_nothing_else(self):
        env_of_api = {key for key, env in containers_of("ci-sentry-api").items() if API_MARKER in env}
        self.assertTrue(API_WORKLOADS <= {key.split("/")[0] for key in env_of_api}, sorted(env_of_api))
        self.assertEqual(env_of_api, set(sentry_of("ci-sentry-api")))
        for key, variables in sentry_of("ci-sentry-api").items():
            self.assertEqual(
                {
                    "SENTRY_DSN": API_DSN,
                    "SENTRY_ENVIRONMENT": "smoke",
                    "SENTRY_RELEASE": REVISION,
                    "SENTRY_TRACES_SAMPLE_RATE": "0.25",
                },
                variables,
                key,
            )

    def test_the_dsn_of_the_store_reaches_only_the_store(self):
        self.assertEqual(
            {
                "store/store": {
                    "SENTRY_DSN": STORE_DSN,
                    "SENTRY_ENVIRONMENT": "smoke",
                    "SENTRY_RELEASE": REVISION,
                    "SENTRY_TRACES_SAMPLE_RATE": "0.25",
                }
            },
            sentry_of("ci-sentry-store"),
        )

    def test_the_dsn_of_the_panel_reaches_only_the_panel(self):
        self.assertEqual(
            {
                "admin/admin": {
                    "SENTRY_DSN": ADMIN_DSN,
                    "SENTRY_ENVIRONMENT": "smoke",
                    "SENTRY_RELEASE": REVISION,
                    "SENTRY_TRACES_SAMPLE_RATE": "0.25",
                }
            },
            sentry_of("ci-sentry-admin"),
        )

    def test_every_application_gets_its_own_dsn(self):
        dsns = holders("ci-sentry", "SENTRY_DSN")
        by_app = {}
        for key, dsn in dsns.items():
            by_app.setdefault(dsn, set()).add(key.split("/")[0])
        self.assertEqual({API_DSN, STORE_DSN, ADMIN_DSN}, set(by_app))
        self.assertEqual({"store"}, by_app[STORE_DSN])
        self.assertEqual({"admin"}, by_app[ADMIN_DSN])
        self.assertTrue(API_WORKLOADS <= by_app[API_DSN])
        self.assertFalse({"store", "admin"} & by_app[API_DSN])

    def test_the_other_variables_come_only_with_a_dsn(self):
        """A variable other than the DSN never appears in a container without a DSN (checked in every case)."""
        for case in ("ci-sentry-api", "ci-sentry-store", "ci-sentry-admin", "ci-sentry"):
            for key, env in containers_of(case).items():
                if "SENTRY_DSN" not in env:
                    self.assertFalse([k for k in env if k.startswith("SENTRY_")], f"{case} {key}")

    def test_the_release_is_the_revision_of_the_images(self):
        self.assertEqual({REVISION}, set(holders("ci-sentry", "SENTRY_RELEASE").values()))

    def test_prod_names_the_environment_demo_and_samples_one_trace_in_ten(self):
        self.assertEqual({"demo"}, set(holders("prod-sentry", "SENTRY_ENVIRONMENT").values()))
        self.assertEqual({"0.1"}, set(holders("prod-sentry", "SENTRY_TRACES_SAMPLE_RATE").values()))
        self.assertEqual({API_DSN, STORE_DSN, ADMIN_DSN}, set(holders("prod-sentry", "SENTRY_DSN").values()))

    def test_the_default_rate_is_zero_a_string(self):
        self.assertEqual({"0"}, set(holders("ci-sentry-default-rate", "SENTRY_TRACES_SAMPLE_RATE").values()))


if __name__ == "__main__":
    unittest.main()

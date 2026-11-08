import unittest

from support import WORKLOADS, by_logical, docs, logical, of_kind, wave, waits_for

EXPECTED_WAVES = {
    "postgres": -1, "elasticsearch": -1, "rabbitmq": -1, "redis": -1, "garage": -1, "keycloak": -1, "mailpit": -1,
    "garage-init": 0, "migrate": 0, "keycloak-config": 0,
    "api": 1, "worker": 1, "store": 1, "admin": 1, "imgproxy": 1, "varnish": 1,
    "initialize": 2, "purge-caches": 3,
}

# What each workload waits for in an initContainer (the table of the waves in the specification).
EXPECTED_WAITS = {
    "keycloak": {"postgres"},
    "garage-init": {"garage"},
    "migrate": {"postgres", "elasticsearch"},
    "keycloak-config": {"keycloak"},
    "api": {"migrate"},
    "worker": {"migrate"},
    "imgproxy": {"garage-init"},
    "initialize": {"api", "worker", "varnish", "store"},
    "purge-caches": {"api", "varnish", "store"},
}


class WavesTest(unittest.TestCase):
    def test_expected_waves(self):
        for case in ("prod", "ci"):
            waves = {logical(d): wave(d) for d in of_kind(case, *WORKLOADS) if d["kind"] != "CronJob"}
            self.assertEqual(EXPECTED_WAVES, waves, case)

    def test_configuration_comes_first(self):
        for kind in ("ConfigMap", "Middleware", "Secret"):
            for doc in of_kind("ci", kind):
                self.assertEqual(-2, wave(doc), f"{kind}/{doc['metadata']['name']}")

    def test_expected_waits(self):
        for case in ("prod", "ci"):
            for name, expected in EXPECTED_WAITS.items():
                workload = by_logical(case, *WORKLOADS)[name]
                self.assertTrue(expected <= set(waits_for(workload)), f"{case} {name} waits for {waits_for(workload)}")

    def test_dependencies_are_in_earlier_waves(self):
        """Every wait is for an existing workload in an earlier wave (the same wave only among the -1 ones)."""
        for case in ("prod", "ci", "prod-noinit"):
            workloads = by_logical(case, *WORKLOADS)
            for name, workload in workloads.items():
                for dependency in waits_for(workload):
                    self.assertIn(dependency, workloads, f"{case}: {name} waits for {dependency}, which does not exist")
                    dep_wave, own_wave = wave(workloads[dependency]), wave(workload)
                    ok = dep_wave < own_wave or (own_wave < 0 and dep_wave == own_wave)
                    self.assertTrue(ok, f"{case}: {name} (wave {own_wave}) waits for {dependency} (wave {dep_wave})")

    def test_nothing_waits_for_a_disabled_initialize(self):
        names = {logical(d) for d in of_kind("prod-noinit", *WORKLOADS)}
        self.assertNotIn("initialize", names)
        self.assertEqual(len(docs("prod")) - 1, len(docs("prod-noinit")))


if __name__ == "__main__":
    unittest.main()

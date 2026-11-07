import unittest

from support import WORKLOADS, init_env_keys, name, of_kind, pod_spec


class SecretsTest(unittest.TestCase):
    def test_secret_template_has_every_key_of_init_env(self):
        secrets = of_kind("ci", "Secret")
        self.assertEqual(1, len(secrets))
        missing = sorted(set(init_env_keys()) - set(secrets[0].get("stringData", {})))
        self.assertEqual([], missing, "keys of infra/scripts/init-env.sh missing from the secret template")

    def test_init_env_keys_are_read(self):
        keys = init_env_keys()
        self.assertIn("POSTGRES_PASSWORD", keys)
        self.assertIn("PAYMENT_DEMO_CARD_WEBHOOK_SECRET", keys)
        self.assertGreaterEqual(len(keys), 19)

    def test_prod_does_not_render_the_secret_but_every_workload_reads_the_same_one(self):
        self.assertEqual([], of_kind("prod", "Secret"))
        secret = name(of_kind("ci", "Secret")[0])
        for case in ("prod", "ci"):
            used = set()
            for workload in of_kind(case, *WORKLOADS):
                spec = pod_spec(workload)
                for container in spec["containers"] + spec.get("initContainers", []):
                    used |= {ref["secretRef"]["name"] for ref in container.get("envFrom", []) if "secretRef" in ref}
            self.assertEqual({secret}, used, case)

    def test_ci_secret_values_are_plain_and_not_empty(self):
        for key, value in of_kind("ci", "Secret")[0]["stringData"].items():
            self.assertTrue(value, key)


if __name__ == "__main__":
    unittest.main()

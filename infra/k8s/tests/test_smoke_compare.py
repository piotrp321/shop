import importlib.util
import unittest

from support import REPO

spec = importlib.util.spec_from_file_location("smoke_checks", REPO / "infra/k8s/smoke/checks.py")
checks = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checks)


def snapshot(**shop):
    return {"products": 5, "realms": {"shop": {"clients": "a", "users": "b", **shop}, "backoffice": {"clients": "c"}}}


class DifferencesTest(unittest.TestCase):
    def test_identical_snapshots_do_not_differ(self):
        self.assertEqual([], checks.differences(snapshot(), snapshot()))

    def test_a_changed_part_is_reported(self):
        self.assertEqual(["shop: clients"], checks.differences(snapshot(), snapshot(clients="x")))

    def test_a_part_added_by_the_upgrade_is_reported(self):
        # Keycloak leaves null attributes out of its JSON, so a new attribute shows up only in `after`.
        self.assertEqual(["shop: realm.newAttribute"], checks.differences(snapshot(), snapshot(**{"realm.newAttribute": "x"})))

    def test_a_part_removed_by_the_upgrade_is_reported(self):
        self.assertEqual(["shop: realm.old"], checks.differences(snapshot(**{"realm.old": "x"}), snapshot()))

    def test_a_missing_realm_is_reported_instead_of_failing(self):
        after = snapshot()
        del after["realms"]["backoffice"]
        self.assertEqual(["backoffice: missing after the upgrade"], checks.differences(snapshot(), after))

    def test_a_new_realm_is_reported(self):
        after = snapshot()
        after["realms"]["extra"] = {"clients": "z"}
        self.assertEqual(["extra: missing before the upgrade"], checks.differences(snapshot(), after))

    def test_products_are_compared(self):
        after = snapshot()
        after["products"] = 4
        self.assertEqual(["products 5 -> 4"], checks.differences(snapshot(), after))


if __name__ == "__main__":
    unittest.main()

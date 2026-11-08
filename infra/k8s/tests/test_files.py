import json
import os
import unittest

from support import REPO, name, of_kind

FILES = REPO / "infra/k8s/charts/shop/files"
# configmap -> {key: original file in the repository}; the chart reads the originals through symlinks in files/.
MIRRORED = {
    "varnish-vcl": {"default.vcl": "infra/varnish/default.vcl"},
    "garage-config": {"garage.toml": "infra/garage/garage.toml", "init.sh": "infra/garage/init.sh"},
    "postgres-init": {"10-create-databases.sh": "infra/postgres/init/10-create-databases.sh"},
}


def config_map(case, config_name):
    return next(d for d in of_kind(case, "ConfigMap") if name(d) == config_name)


class FilesTest(unittest.TestCase):
    def test_files_of_the_repository_are_symlinks_that_stay_inside_it(self):
        links = [p for p in FILES.rglob("*") if p.is_symlink()]
        self.assertGreaterEqual(len(links), 5)
        for link in links:
            target = link.resolve(strict=True)
            self.assertTrue(target.is_relative_to(REPO.resolve()), f"{link} leaves the repository")
            self.assertFalse(target.is_relative_to(FILES.resolve()), f"{link} points back into the chart")

    def test_plain_files_are_only_the_scripts_of_the_chart(self):
        for path in FILES.rglob("*"):
            if path.is_file() and not path.is_symlink():
                self.assertEqual("scripts", path.relative_to(FILES).parts[0], str(path))

    def test_helm_reads_the_originals_through_the_symlinks(self):
        for case in ("prod", "ci"):
            for config, keys in MIRRORED.items():
                data = config_map(case, config)["data"]
                for key, original in keys.items():
                    self.assertEqual((REPO / original).read_text(), data[key], f"{config}/{key}")

    def test_realm_files_are_symlinks_to_the_realms_of_compose(self):
        for realm in ("shop", "backoffice"):
            link = FILES / "realms" / f"{realm}.json"
            self.assertEqual((REPO / f"infra/keycloak/realms/{realm}.json").resolve(), link.resolve())


class RealmsTest(unittest.TestCase):
    def expected(self, realm, store_url, admin_url):
        text = (REPO / f"infra/keycloak/realms/{realm}.json").read_text()
        text = text.replace("${STORE_URL}", store_url).replace("${ADMIN_URL}", admin_url)
        document = json.loads(text)
        document.pop("users")
        return document

    def test_realms_are_rendered_without_users_and_with_the_addresses(self):
        for case, store, admin in (("prod", "https://shop.example.org", "https://admin.shop.example.org"),
                                   ("ci", "http://localhost", "http://admin.localhost")):
            data = config_map(case, "keycloak-realms")["data"]
            for realm in ("shop", "backoffice"):
                rendered = json.loads(data[f"{realm}.json"])
                self.assertNotIn("users", rendered, f"{case} {realm}")
                if case == "ci":
                    self.assertEqual(self.expected(realm, store, admin), rendered, f"{case} {realm}")
        text = config_map("ci", "keycloak-realms")["data"]["shop.json"]
        self.assertNotIn("${STORE_URL}", text)
        self.assertIn("${STORE_CLIENT_SECRET}", text)

    def test_the_demo_accounts_are_not_in_any_rendered_file(self):
        for case in ("prod", "ci"):
            for doc in of_kind(case, "ConfigMap"):
                for key, value in doc["data"].items():
                    for account in ("jan.kowalski@example.com", "anna.nowak@example.com"):
                        self.assertNotIn(account, value, f"{case} {name(doc)}/{key}")


if __name__ == "__main__":
    unittest.main()

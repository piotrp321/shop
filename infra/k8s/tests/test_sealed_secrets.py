"""The sealed secrets of environments/prod/secrets (spec, "Sekrety"): only SealedSecret resources are in the repository,
and once the certificate of the controller is there (sealed-secrets.pem) every key of infra/scripts/init-env.sh has an
encrypted value in shop-secrets. `problems` is the check; its own tests try it on made-up trees."""

import unittest

from support import REPO, docs, init_env_keys

CERT = REPO / "infra/k8s/environments/prod/sealed-secrets.pem"
NAMESPACE = "shop"
SECRETS_NAME = "shop-secrets"
EXPECTED_KEYS = {"shop-brevo-smtp": {"BREVO_SMTP_LOGIN", "BREVO_SMTP_PASSWORD"}, "shop-origin-tls": {"tls.crt", "tls.key"}}


def problems(sealed, certificate_present, keys):
    """What is wrong with the SealedSecret documents (a list of dicts) of the repository (empty list: nothing)."""
    found = []
    for doc in sealed:
        name = doc.get("metadata", {}).get("name")
        if doc.get("kind") != "SealedSecret" or doc.get("apiVersion") != "bitnami.com/v1alpha1":
            found.append(f"{name}: {doc.get('kind')} in environments/prod/secrets is not a SealedSecret (a plain secret would be committed)")
            continue
        if doc["metadata"].get("namespace") != NAMESPACE:
            found.append(f"{name}: the namespace is not {NAMESPACE}")
        encrypted = doc.get("spec", {}).get("encryptedData") or {}
        if not encrypted:
            found.append(f"{name}: no encrypted values")
        for key, value in encrypted.items():
            if not isinstance(value, str) or not value or any(c.isspace() for c in value):
                found.append(f"{name}: the value of {key} is not a sealed value")
        if doc.get("spec", {}).get("template", {}).get("data") or doc.get("data") or doc.get("stringData"):
            found.append(f"{name}: has plain data")
        if name in EXPECTED_KEYS and not EXPECTED_KEYS[name] <= set(encrypted):
            found.append(f"{name}: lacks {sorted(EXPECTED_KEYS[name] - set(encrypted))}")
    if certificate_present:
        encrypted = next((d["spec"]["encryptedData"] for d in sealed if d.get("metadata", {}).get("name") == SECRETS_NAME and d.get("kind") == "SealedSecret"), {})
        missing = [key for key in keys if not encrypted.get(key)]
        if missing:
            found.append(
                f"keys of infra/scripts/init-env.sh without a sealed value in environments/prod/secrets (the certificate is in the repository, "
                f"run make -C infra/k8s seal-secrets): {missing}"
            )
    return found


def sealed_doc(name, keys, kind="SealedSecret"):
    return {
        "apiVersion": "bitnami.com/v1alpha1",
        "kind": kind,
        "metadata": {"name": name, "namespace": NAMESPACE},
        "spec": {"encryptedData": {key: "AgBsealed" for key in keys}},
    }


class RepositoryTree(unittest.TestCase):
    def test_the_secrets_of_the_repository_have_no_problems(self):
        self.assertEqual([], problems(docs("sealed"), CERT.exists(), init_env_keys()))


class TheCheckItself(unittest.TestCase):
    keys = ["A_KEY", "B_KEY", "C_KEY"]

    def test_no_certificate_no_requirement(self):
        self.assertEqual([], problems([], False, self.keys))

    def test_a_certificate_with_nothing_sealed_names_every_key(self):
        found = problems([], True, self.keys)
        self.assertEqual(1, len(found))
        for key in self.keys:
            self.assertIn(key, found[0])

    def test_a_certificate_with_one_key_missing_names_that_key_only(self):
        found = problems([sealed_doc(SECRETS_NAME, ["A_KEY", "C_KEY"])], True, self.keys)
        self.assertEqual(1, len(found))
        self.assertIn("['B_KEY']", found[0])

    def test_an_empty_value_counts_as_missing(self):
        doc = sealed_doc(SECRETS_NAME, self.keys)
        doc["spec"]["encryptedData"]["B_KEY"] = ""
        self.assertTrue(any("B_KEY" in line for line in problems([doc], True, self.keys)))

    def test_every_key_sealed_passes(self):
        self.assertEqual([], problems([sealed_doc(SECRETS_NAME, self.keys)], True, self.keys))

    def test_a_plain_secret_is_refused_with_or_without_the_certificate(self):
        for certificate in (False, True):
            plain = {"apiVersion": "v1", "kind": "Secret", "metadata": {"name": SECRETS_NAME, "namespace": NAMESPACE}, "stringData": {"A_KEY": "x"}}
            found = problems([plain], certificate, self.keys)
            self.assertTrue(any("not a SealedSecret" in line for line in found), found)

    def test_plain_data_next_to_the_sealed_values_is_refused(self):
        doc = sealed_doc(SECRETS_NAME, self.keys)
        doc["spec"]["template"] = {"data": {"A_KEY": "eA=="}}
        self.assertTrue(any("plain data" in line for line in problems([doc], False, self.keys)))

    def test_the_brevo_and_tls_secrets_need_their_keys_when_present(self):
        self.assertTrue(problems([sealed_doc("shop-brevo-smtp", ["BREVO_SMTP_LOGIN"])], False, []))
        self.assertTrue(problems([sealed_doc("shop-origin-tls", ["tls.crt"])], False, []))
        self.assertEqual([], problems([sealed_doc("shop-origin-tls", ["tls.crt", "tls.key"])], False, []))

    def test_another_namespace_is_refused(self):
        doc = sealed_doc(SECRETS_NAME, self.keys)
        doc["metadata"]["namespace"] = "default"
        self.assertTrue(problems([doc], False, self.keys))


if __name__ == "__main__":
    unittest.main()

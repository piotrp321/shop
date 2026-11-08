"""The switches of D10 (demoNotice, customerAccounts, orderMail) and the mail of Keycloak in the render."""

import json
import unittest

from support import WORKLOADS, by_logical, containers, env_of, logical, name, of_kind, pod_spec

# case of scripts/render.sh: (demoNotice, customerAccounts, orderMail, keycloak.mail). The cases prod and ci are the
# profiles as they are.
SWITCHES = {
    "prod": (True, False, "mailpit", "brevo"),
    "prod-accounts": (True, True, "mailpit", "brevo"),
    "prod-nodemo": (False, False, "mailpit", "brevo"),
    "prod-order-brevo": (True, False, "brevo", "brevo"),
    "ci": (True, False, "mailpit", "mailpit"),
    "ci-accounts": (True, True, "mailpit", "mailpit"),
    "ci-keycloak-brevo": (True, False, "mailpit", "brevo"),
}
# The Secret with the Brevo SMTP login and key: the prod profile names it, the case ci-keycloak-brevo sets it.
BREVO_SECRET = {case: "shop-brevo-smtp" for case in SWITCHES if case.startswith("prod")} | {"ci-keycloak-brevo": "smoke-brevo"}
BREVO_DSN = "smtp://$(BREVO_SMTP_LOGIN):$(BREVO_SMTP_PASSWORD)@smtp-relay.brevo.com:587?timeout=10"
MAILPIT_DSN = "smtp://mailpit:1025?timeout=10"
DOMAIN = {"prod": "shop.example.org", "ci": "localhost"}


def profile(case):
    return case.split("-", 1)[0]


def all_containers(doc):
    spec = pod_spec(doc)
    return spec.get("initContainers", []) + spec["containers"]


def realm_data(case):
    config = next(d for d in of_kind(case, "ConfigMap") if name(d) == "keycloak-realms")
    return config["data"]


def secret_ref(entry):
    return entry.get("valueFrom", {}).get("secretKeyRef")


class StoreSwitchesTest(unittest.TestCase):
    def test_the_store_gets_the_demo_notice_and_the_customer_accounts(self):
        for case, (demo, accounts, _, _) in SWITCHES.items():
            env = env_of(containers(by_logical(case, "Deployment")["store"])[0])
            self.assertEqual(str(demo).lower(), env.get("STORE_DEMO_NOTICE"), case)
            self.assertEqual(str(accounts).lower(), env.get("STORE_CUSTOMER_ACCOUNTS"), case)

    def test_the_switches_of_the_store_reach_no_other_workload(self):
        for case in SWITCHES:
            for doc in of_kind(case, *WORKLOADS):
                if logical(doc) == "store":
                    continue
                for container in all_containers(doc):
                    env = env_of(container)
                    self.assertNotIn("STORE_DEMO_NOTICE", env, f"{case} {logical(doc)}")
                    self.assertNotIn("STORE_CUSTOMER_ACCOUNTS", env, f"{case} {logical(doc)}")


class OrderMailTest(unittest.TestCase):
    def mailer_containers(self, case):
        found = {}
        for doc in of_kind(case, *WORKLOADS):
            for container in all_containers(doc):
                if "MAILER_DSN" in env_of(container):
                    found[f"{logical(doc)}/{container['name']}"] = container
        return found

    def test_every_container_of_the_api_image_sends_where_order_mail_says(self):
        for case, (_, _, order_mail, _) in SWITCHES.items():
            found = self.mailer_containers(case)
            workloads = {key.split("/")[0] for key in found}
            self.assertLessEqual({"api", "worker", "migrate", "initialize", "purge-caches", "cart-purge-expired"}, workloads, case)
            for key, container in found.items():
                entries = container["env"]
                env = env_of(container)
                names = [e["name"] for e in entries]
                if order_mail == "mailpit":
                    self.assertEqual(MAILPIT_DSN, env["MAILER_DSN"], f"{case} {key}")
                    self.assertNotIn("BREVO_SMTP_LOGIN", names, f"{case} {key}")
                    self.assertNotIn("BREVO_SMTP_PASSWORD", names, f"{case} {key}")
                    continue
                self.assertEqual(BREVO_DSN, env["MAILER_DSN"], f"{case} {key}")
                for variable in ("BREVO_SMTP_LOGIN", "BREVO_SMTP_PASSWORD"):
                    entry = entries[names.index(variable)]
                    self.assertEqual({"name": BREVO_SECRET[case], "key": variable}, secret_ref(entry), f"{case} {key} {variable}")
                    # Kubernetes expands $(VAR) only from the variables defined before it.
                    self.assertLess(names.index(variable), names.index("MAILER_DSN"), f"{case} {key} {variable}")

    def test_the_sender_is_from_the_domain(self):
        for case in SWITCHES:
            env = env_of(containers(by_logical(case, "Deployment")["api"])[0])
            self.assertEqual(f"Rytm <zamowienia@{DOMAIN[profile(case)]}>", env["MAILER_FROM"], case)


class RealmSettingsTest(unittest.TestCase):
    """The values the job keycloak-config sets on every run, rendered next to the realm files."""

    def expected_smtp(self, case, keycloak_mail):
        common = {"from": f"konto@{DOMAIN[profile(case)]}", "fromDisplayName": "Rytm", "ssl": "false"}
        if keycloak_mail == "mailpit":
            return common | {"host": "mailpit", "port": "1025", "auth": "false", "starttls": "false", "user": "", "password": ""}
        return common | {
            "host": "smtp-relay.brevo.com",
            "port": "587",
            "auth": "true",
            "starttls": "true",
            "user": "${SMTP_USER}",
            "password": "${SMTP_PASSWORD}",
        }

    def test_the_shop_realm_gets_registration_from_customer_accounts_email_verification_and_smtp(self):
        for case, (_, accounts, _, keycloak_mail) in SWITCHES.items():
            settings = json.loads(realm_data(case)["shop.settings.json"])
            self.assertEqual(
                {"registrationAllowed": accounts, "verifyEmail": True, "smtpServer": self.expected_smtp(case, keycloak_mail)},
                settings,
                case,
            )

    def test_the_backoffice_realm_gets_only_the_smtp(self):
        for case, (_, _, _, keycloak_mail) in SWITCHES.items():
            settings = json.loads(realm_data(case)["backoffice.settings.json"])
            self.assertEqual({"smtpServer": self.expected_smtp(case, keycloak_mail)}, settings, case)

    def test_the_clients_get_their_addresses_from_the_domain_and_the_store_its_secret(self):
        for case, store, admin in (
            ("prod", "https://shop.example.org", "https://admin.shop.example.org"),
            ("prod-domain", "https://other.example.net", "https://admin.other.example.net"),
            ("ci", "http://localhost", "http://admin.localhost"),
        ):
            data = realm_data(case)
            self.assertEqual(
                {
                    "secret": "${STORE_CLIENT_SECRET}",
                    "redirectUris": [f"{store}/auth/callback"],
                    "webOrigins": [],
                    "attributes": {"post.logout.redirect.uris": f"{store}/"},
                },
                json.loads(data["shop.client.store.json"]),
                case,
            )
            self.assertEqual(
                {"redirectUris": [f"{admin}/*"], "webOrigins": [admin], "attributes": {"post.logout.redirect.uris": "+"}},
                json.loads(data["backoffice.client.admin.json"]),
                case,
            )
            self.assertEqual(
                {"shop.json", "backoffice.json", "shop.settings.json", "backoffice.settings.json", "shop.client.store.json",
                 "backoffice.client.admin.json"},
                set(data),
                case,
            )

    def test_no_value_of_the_secret_is_in_a_config_map(self):
        values = [v for v in of_kind("ci", "Secret")[0]["stringData"].values()]
        for case in ("ci", "ci-keycloak-brevo"):
            for config in of_kind(case, "ConfigMap"):
                for key, text in config["data"].items():
                    for value in values:
                        self.assertNotIn(value, text, f"{case} {name(config)}/{key}")

    def test_the_job_gets_the_brevo_login_and_key_only_when_keycloak_sends_through_brevo(self):
        for case, (_, _, _, keycloak_mail) in SWITCHES.items():
            container = containers(by_logical(case, "Job")["keycloak-config"])[0]
            refs = {e["name"]: secret_ref(e) for e in container.get("env", []) if secret_ref(e)}
            if keycloak_mail == "mailpit":
                self.assertEqual({}, refs, case)
            else:
                self.assertEqual(
                    {
                        "SMTP_USER": {"name": BREVO_SECRET[case], "key": "BREVO_SMTP_LOGIN"},
                        "SMTP_PASSWORD": {"name": BREVO_SECRET[case], "key": "BREVO_SMTP_PASSWORD"},
                    },
                    refs,
                    case,
                )


class SwitchJobNamesTest(unittest.TestCase):
    """D11: a switch the job depends on gives it a new name; the others keep it."""

    def names(self, case):
        return {logical(d): d["metadata"]["name"] for d in of_kind(case, "Job")}

    def test_customer_accounts_runs_keycloak_config_again(self):
        for old, new in (("prod", "prod-accounts"), ("ci", "ci-accounts")):
            self.assertNotEqual(self.names(old)["keycloak-config"], self.names(new)["keycloak-config"], new)
            self.assertEqual(self.names(old)["migrate"], self.names(new)["migrate"], new)

    def test_the_mail_of_keycloak_runs_keycloak_config_again(self):
        self.assertNotEqual(self.names("ci")["keycloak-config"], self.names("ci-keycloak-brevo")["keycloak-config"])

    def test_order_mail_runs_the_jobs_of_the_api_image_again_but_not_keycloak_config(self):
        old, new = self.names("prod"), self.names("prod-order-brevo")
        for job in ("migrate", "initialize", "purge-caches"):
            self.assertNotEqual(old[job], new[job], job)
        self.assertEqual(old["keycloak-config"], new["keycloak-config"])

    def test_the_demo_notice_changes_only_the_store(self):
        self.assertEqual(self.names("prod"), self.names("prod-nodemo"))


if __name__ == "__main__":
    unittest.main()

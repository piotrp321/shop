"""The job keycloak-config (files/scripts/keycloak-config.sh) against a stub of kcadm, with the rendered realm files."""

import json
import os
import pathlib
import subprocess
import tempfile
import unittest

from support import REPO, name, of_kind

SCRIPT = REPO / "infra/k8s/charts/shop/files/scripts/keycloak-config.sh"

# Stub of kcadm.sh: one line per call in $STUB_LOG (the arguments), the body read with `-f -` in $STUB_DIR/stdin-<n>,
# the password of the login in $STUB_DIR/login. Answers the reads like kcadm does (pretty JSON, csv without quotes).
KCADM = r"""#!/bin/sh
echo "$*" >> "$STUB_LOG"
n="$(wc -l < "$STUB_LOG" | tr -d ' ')"
case " $* " in *" -f - "*) cat > "$STUB_DIR/stdin-$n" ;; esac
if [ -n "${STUB_FAIL:-}" ]; then
  case "$*" in *"$STUB_FAIL"*) echo "stub: failing on purpose" >&2; exit 1 ;; esac
fi
case "$1 $2" in
  "config credentials") printf '%s' "${KC_CLI_PASSWORD:-}" > "$STUB_DIR/login" ;;
  "get realms")
    printf '[ '
    sep=""
    for realm in $(echo "$STUB_REALMS" | tr ',' ' '); do printf '%s{\n  "realm" : "%s"\n}' "$sep" "$realm"; sep=", "; done
    printf ' ]\n' ;;
  "create partialImport")
    printf '{\n  "overwritten" : 0,\n  "added" : %s,\n  "skipped" : 4,\n  "results" : [ ' "${STUB_ADDED:-0}"
    i=0
    while [ "$i" -lt "${STUB_ADDED:-0}" ]; do printf '{\n    "action" : "ADDED",\n    "resourceType" : "CLIENT"\n  }, '; i=$((i + 1)); done
    printf '{\n    "action" : "SKIPPED",\n    "resourceType" : "CLIENT"\n  } ]\n}\n' ;;
  "get clients")
    for arg in "$@"; do
      case "$arg" in clientId=*) client="${arg#clientId=}" ;; esac
    done
    [ "$client" = "${STUB_MISSING_CLIENT:-}" ] || echo "id-$client" ;;
esac
"""

ADMIN_PASSWORD = 'admin-"pass\\word'
STORE_SECRET = 'store-"secret\\$x'
SMTP_USER = "8a1b2c001@smtp-brevo.com"
SMTP_PASSWORD = 'xsmtpsib-"key\\0'


def realm_files(case):
    return next(d for d in of_kind(case, "ConfigMap") if name(d) == "keycloak-realms")["data"]


class KeycloakConfigTest(unittest.TestCase):
    def run_job(self, realms="master", case="ci", **env):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            (root / "realms").mkdir()
            for key, text in realm_files(case).items():
                (root / "realms" / key).write_text(text)
            kcadm = root / "kcadm.sh"
            kcadm.write_text(KCADM)
            kcadm.chmod(0o755)
            log = root / "log"
            log.write_text("")
            variables = {
                "PATH": os.environ["PATH"],
                "HOME": tmp,
                "KCADM": str(kcadm),
                "REALMS_DIR": str(root / "realms"),
                "STUB_LOG": str(log),
                "STUB_DIR": tmp,
                "STUB_REALMS": realms,
                "KC_BOOTSTRAP_ADMIN_USERNAME": "admin",
                "KC_BOOTSTRAP_ADMIN_PASSWORD": ADMIN_PASSWORD,
                "STORE_CLIENT_SECRET": STORE_SECRET,
                **env,
            }
            result = subprocess.run(["sh", str(SCRIPT)], env=variables, capture_output=True, text=True)
            calls = log.read_text().splitlines()
            # (call, body) of every call that read a body from its standard input.
            bodies = []
            for index, call in enumerate(calls, start=1):
                body = root / f"stdin-{index}"
                if body.exists():
                    bodies.append((call, body.read_text()))
            login = (root / "login").read_text() if (root / "login").exists() else None
            return result, calls, bodies, login

    def body(self, bodies, prefix):
        found = [text for call, text in bodies if call.startswith(prefix)]
        self.assertEqual(1, len(found), f"one call starting with '{prefix}' with a body: {[call for call, _ in bodies]}")
        return json.loads(found[0])

    def expected_realm(self, realm, case="ci"):
        document = json.loads(realm_files(case)[f"{realm}.json"])
        for client in document.get("clients", []):
            if client.get("secret") == "${STORE_CLIENT_SECRET}":
                client["secret"] = STORE_SECRET
        return document

    def test_a_missing_realm_is_created_from_its_file_with_the_secret_of_the_store(self):
        result, calls, bodies, _ = self.run_job(realms="master")
        self.assertEqual(0, result.returncode, result.stderr)
        created = [json.loads(text) for call, text in bodies if call.startswith("create realms -f -")]
        self.assertEqual([self.expected_realm("shop"), self.expected_realm("backoffice")], created)
        self.assertEqual([], [call for call in calls if "partialImport" in call])
        self.assertIn("realm shop created", result.stdout)

    def test_an_existing_realm_gets_only_the_missing_resources(self):
        result, calls, bodies, _ = self.run_job(realms="master,shop,backoffice", STUB_ADDED="1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([], [call for call in calls if call.startswith("create realms")])
        for realm in ("shop", "backoffice"):
            self.assertEqual(self.expected_realm(realm), self.body(bodies, f"create partialImport -r {realm} -s ifResourceExists=SKIP"))
        self.assertIn("realm shop exists: 1 added, 1 skipped, nothing overwritten", result.stdout)

    def test_an_upgrade_without_new_resources_says_so(self):
        result, _, _, _ = self.run_job(realms="master,shop,backoffice")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("realm backoffice exists: 0 added, 1 skipped, nothing overwritten", result.stdout)
        self.assertIn("keycloak-config: done", result.stdout)

    def test_the_values_of_the_configuration_are_set_on_every_run(self):
        files = realm_files("ci")
        for realms in ("master", "master,shop,backoffice"):
            result, calls, bodies, _ = self.run_job(realms=realms)
            self.assertEqual(0, result.returncode, result.stderr)
            for realm in ("shop", "backoffice"):
                self.assertEqual(json.loads(files[f"{realm}.settings.json"]), self.body(bodies, f"update realms/{realm} "), realms)
            store = json.loads(files["shop.client.store.json"]) | {"secret": STORE_SECRET}
            self.assertEqual(store, self.body(bodies, "update clients/id-store -r shop "), realms)
            self.assertEqual(json.loads(files["backoffice.client.admin.json"]), self.body(bodies, "update clients/id-admin -r backoffice "))
            for call in calls:
                if call.startswith("update "):
                    self.assertIn("--merge", call.split(), call)
            # The values of a realm are set after it is created or its missing resources are added.
            made = [i for i, call in enumerate(calls) if call.startswith(("create realms", "create partialImport"))]
            for realm, index in zip(("shop", "backoffice"), made):
                updates = [i for i, call in enumerate(calls) if call.startswith(f"update realms/{realm} ")]
                self.assertGreater(updates[0], index, f"{realms} {realm}")

    def test_the_brevo_login_and_key_go_into_the_smtp_of_the_realms(self):
        result, _, bodies, _ = self.run_job(case="ci-keycloak-brevo", SMTP_USER=SMTP_USER, SMTP_PASSWORD=SMTP_PASSWORD)
        self.assertEqual(0, result.returncode, result.stderr)
        for realm in ("shop", "backoffice"):
            smtp = self.body(bodies, f"update realms/{realm} ")["smtpServer"]
            self.assertEqual((SMTP_USER, SMTP_PASSWORD, "smtp-relay.brevo.com", "true"), (smtp["user"], smtp["password"], smtp["host"], smtp["auth"]))

    def test_a_placeholder_without_its_secret_stops_before_any_change(self):
        result, calls, _, _ = self.run_job(case="ci-keycloak-brevo", SMTP_USER=SMTP_USER)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("SMTP_PASSWORD", result.stderr)
        self.assertEqual([], [call for call in calls if call.startswith(("update ", "create "))])

    def test_no_secret_is_in_the_arguments_or_in_the_output(self):
        for realms in ("master", "master,shop,backoffice"):
            result, calls, _, login = self.run_job(
                realms=realms, case="ci-keycloak-brevo", SMTP_USER=SMTP_USER, SMTP_PASSWORD=SMTP_PASSWORD
            )
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(ADMIN_PASSWORD, login, "the password of the administrator is given in KC_CLI_PASSWORD")
            for secret in (ADMIN_PASSWORD, STORE_SECRET, SMTP_PASSWORD):
                for call in calls:
                    self.assertNotIn(secret, call)
                self.assertNotIn(secret, result.stdout + result.stderr)

    def test_nothing_is_deleted_or_overwritten(self):
        for realms in ("master", "master,shop,backoffice"):
            _, calls, _, _ = self.run_job(realms=realms)
            for call in calls:
                self.assertFalse(call.startswith("delete"), call)
                self.assertNotIn("OVERWRITE", call)

    def test_a_failing_call_fails_the_job(self):
        for failing in ("partialImport", "update realms/shop", "update clients/id-store", "config credentials"):
            result, _, _, _ = self.run_job(realms="master,shop,backoffice", STUB_FAIL=failing)
            self.assertNotEqual(0, result.returncode, failing)

    def test_a_client_of_the_file_missing_in_keycloak_fails_the_job(self):
        result, _, _, _ = self.run_job(realms="master,shop,backoffice", STUB_MISSING_CLIENT="store")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("client store", result.stderr)


if __name__ == "__main__":
    unittest.main()

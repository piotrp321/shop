"""scripts/seal-secrets.sh against a stub of docker standing for the kubeseal container: it adds only the missing keys of
init-env.sh with random values, asks for the external values on the terminal, never prints or passes on the command line
a value, and on any error writes nothing."""

import os
import pathlib
import re
import shutil
import subprocess
import tempfile
import unittest

from support import REPO, init_env_keys

SCRIPT = REPO / "infra/k8s/scripts/seal-secrets.sh"
CERT = "infra/k8s/environments/prod/sealed-secrets.pem"
SECRETS = "infra/k8s/environments/prod/secrets"
KUBESEAL_IMAGE = re.search(r'KUBESEAL_IMAGE="\$\{KUBESEAL_IMAGE:-([^}]+)\}"', (REPO / "infra/k8s/scripts/tools.sh").read_text()).group(1)

# Stub of docker: logs the arguments, keeps the standard input of each call (what kubeseal would encrypt), answers with a
# "ciphertext" that depends on the input. STUB_FAIL_ON=<n>: the n-th call fails; STUB_ANSWER replaces the answer.
DOCKER = r"""#!/bin/sh
echo "$*" >> "$STUB_LOG"
n="$(wc -l < "$STUB_LOG" | tr -d ' ')"
cat > "$STUB_DIR/in-$n"
if [ "${STUB_FAIL_ON:-0}" = "$n" ]; then echo "stub: kubeseal failed" >&2; exit 1; fi
if [ -n "${STUB_ANSWER+x}" ]; then printf '%s' "$STUB_ANSWER"; exit 0; fi
printf 'AgSEALED%s' "$(cksum < "$STUB_DIR/in-$n" | cut -d' ' -f1)"
"""

CERT_PEM = "-----BEGIN CERTIFICATE-----\nMIIDfake\n-----END CERTIFICATE-----\n"
ORIGIN_CERT = "-----BEGIN CERTIFICATE-----\nMIIOriginCaCertificateBody\nZm9v\n-----END CERTIFICATE-----\n"
ORIGIN_KEY = "-----BEGIN PRIVATE KEY-----\nMIIOriginCaPrivateKeyBodyDoNotPrint\nYmFy\n-----END PRIVATE KEY-----\n"
BREVO_LOGIN = "a1b2c3@smtp-brevo.com"
BREVO_KEY = "xsmtpsib-0123456789abcdef-SECRETKEYVALUE"


class SealSecretsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.tmp.name) / "repo"
        (self.root / "infra/scripts").mkdir(parents=True)
        shutil.copy(REPO / "infra/scripts/init-env.sh", self.root / "infra/scripts/init-env.sh")
        (self.root / "infra/k8s/environments/prod").mkdir(parents=True)
        self.bin = pathlib.Path(self.tmp.name) / "bin"
        self.bin.mkdir()
        docker = self.bin / "docker"
        docker.write_text(DOCKER)
        docker.chmod(0o755)
        self.stub = pathlib.Path(self.tmp.name) / "stub"
        self.stub.mkdir()
        self.log = self.stub / "log"
        self.log.write_text("")
        self.tty = pathlib.Path(self.tmp.name) / "tty"
        self.put_cert()

    def tearDown(self):
        self.tmp.cleanup()

    def put_cert(self, text=CERT_PEM):
        (self.root / CERT).write_text(text)

    def run_script(self, *args, tty=None, **env):
        if tty is not None:
            self.tty.write_text(tty)
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": self.tmp.name,
            "TMPDIR": self.tmp.name,
            "REPO_ROOT": str(self.root),
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.stub),
            "SEAL_TTY": str(self.tty),
            **env,
        }
        return subprocess.run(["sh", str(SCRIPT), *args], env=variables, capture_output=True, text=True)

    def calls(self):
        return self.log.read_text().splitlines()

    def sent(self):
        """What the stub of kubeseal received on its standard input, in order."""
        return [(self.stub / f"in-{i}").read_text() for i in range(1, len(self.calls()) + 1)]

    def secrets_dir(self):
        return self.root / SECRETS

    def files(self):
        directory = self.secrets_dir()
        return sorted(p.name for p in directory.iterdir()) if directory.exists() else []

    def keys_of(self, name):
        text = (self.secrets_dir() / name).read_text()
        section = text.split("  encryptedData:\n")[1].split("  template:\n")[0]
        return [line.strip().split(":")[0] for line in section.splitlines()]

    def assert_nothing_written(self, result, status=1):
        self.assertEqual(status, result.returncode, result.stdout + result.stderr)
        self.assertEqual([], self.files())

    def assert_no_secret_anywhere(self, result, *secrets):
        everything = result.stdout + result.stderr + "\n".join(self.calls())
        for path in self.secrets_dir().glob("*.yaml") if self.secrets_dir().exists() else []:
            everything += path.read_text()
        for secret in secrets:
            for line in filter(None, secret.splitlines()):
                self.assertNotIn(line, everything)

    # --- shop: the keys of init-env.sh ------------------------------------------------------------------------------

    def test_the_first_run_seals_every_key_of_init_env_with_a_random_value(self):
        result = self.run_script("shop")
        self.assertEqual(0, result.returncode, result.stderr)
        keys = init_env_keys()
        self.assertGreaterEqual(len(keys), 19)
        self.assertEqual(["shop-secrets.yaml"], self.files())
        self.assertEqual(keys, self.keys_of("shop-secrets.yaml"))
        text = (self.secrets_dir() / "shop-secrets.yaml").read_text()
        self.assertIn("kind: SealedSecret", text)
        self.assertIn("  name: shop-secrets\n  namespace: shop\n", text)
        self.assertIn("    type: Opaque\n", text)
        self.assertEqual(len(keys), len(self.calls()))
        # Random, and as init-env.sh makes them: hex of the right length, GK before the access key id.
        sent = dict(zip(keys, self.sent()))
        self.assertRegex(sent["POSTGRES_PASSWORD"], r"^[0-9a-f]{32}$")
        self.assertRegex(sent["GARAGE_RPC_SECRET"], r"^[0-9a-f]{64}$")
        self.assertRegex(sent["GARAGE_ACCESS_KEY_ID"], r"^GK[0-9a-f]{24}$")
        self.assertEqual(len(set(sent.values())), len(sent), "two keys got the same value")

    def test_kubeseal_gets_the_certificate_of_the_repository_the_name_and_the_namespace_and_no_value(self):
        self.run_script("shop")
        sent = self.sent()
        for call in self.calls():
            self.assertIn(KUBESEAL_IMAGE, call)
            self.assertIn(f"--raw --scope strict --name shop-secrets --namespace shop --cert /repo/{CERT}", call)
        for value in sent:
            self.assertFalse(any(value in call for call in self.calls()), "a value is on a command line")

    def test_the_second_run_changes_nothing_and_does_not_call_kubeseal(self):
        self.run_script("shop")
        before = (self.secrets_dir() / "shop-secrets.yaml").read_bytes()
        self.log.write_text("")
        result = self.run_script("shop")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(before, (self.secrets_dir() / "shop-secrets.yaml").read_bytes())
        self.assertEqual([], self.calls())
        self.assertIn("nothing to add", result.stdout)

    def test_a_key_the_file_lacks_is_added_and_the_others_stay_byte_for_byte(self):
        self.run_script("shop")
        path = self.secrets_dir() / "shop-secrets.yaml"
        lines = path.read_text().splitlines(keepends=True)
        dropped = next(line for line in lines if line.startswith("    PAYMENT_DEMO_CARD_API_KEY:"))
        path.write_text("".join(line for line in lines if line is not dropped))
        old = {line for line in path.read_text().splitlines() if line.startswith("    ")}
        self.log.write_text("")
        result = self.run_script("shop")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(1, len(self.calls()))
        self.assertIn("PAYMENT_DEMO_CARD_API_KEY", result.stdout)
        new = [line for line in path.read_text().splitlines() if line.startswith("    ")]
        self.assertTrue(old <= set(new), "an existing sealed value changed")
        self.assertEqual(len(old) + 1, len(new))
        self.assertEqual(
            [k for k in init_env_keys() if k != "PAYMENT_DEMO_CARD_API_KEY"] + ["PAYMENT_DEMO_CARD_API_KEY"], self.keys_of("shop-secrets.yaml")
        )

    def test_an_existing_file_in_another_format_is_not_touched(self):
        self.secrets_dir().mkdir(parents=True)
        path = self.secrets_dir() / "shop-secrets.yaml"
        path.write_text("apiVersion: bitnami.com/v1alpha1\nkind: SealedSecret\nmetadata:\n  name: shop-secrets\n")
        result = self.run_script("shop")
        self.assertEqual(1, result.returncode)
        self.assertIn("not in the format", result.stderr)
        self.assertEqual("apiVersion: bitnami.com/v1alpha1\nkind: SealedSecret\nmetadata:\n  name: shop-secrets\n", path.read_text())
        self.assertEqual([], self.calls())

    # --- errors write nothing ---------------------------------------------------------------------------------------

    def test_without_the_certificate_nothing_is_written_and_kubeseal_does_not_run(self):
        (self.root / CERT).unlink()
        result = self.run_script("shop")
        self.assert_nothing_written(result, status=2)
        self.assertIn("no certificate", result.stderr)
        self.assertEqual([], self.calls())

    def test_a_file_that_is_not_a_certificate_is_refused(self):
        self.put_cert("not a certificate\n")
        result = self.run_script("shop")
        self.assert_nothing_written(result, status=2)
        self.assertIn("not a PEM certificate", result.stderr)

    def test_a_failing_kubeseal_in_the_middle_writes_nothing(self):
        result = self.run_script("shop", STUB_FAIL_ON="7")
        self.assert_nothing_written(result)
        self.assertIn("kubeseal failed", result.stderr)

    def test_an_answer_that_is_not_a_sealed_value_writes_nothing(self):
        for answer in ("", "error: something\n", "Ag SEALED"):
            with self.subTest(answer=answer):
                result = self.run_script("shop", STUB_ANSWER=answer)
                self.assert_nothing_written(result)
                self.log.write_text("")

    def test_a_failure_after_the_first_secret_leaves_the_first_unwritten_too(self):
        result = self.run_script(tty="\n")
        self.assert_nothing_written(result)
        self.assertIn("no value", result.stderr)

    def test_an_unknown_secret_is_a_usage_error(self):
        result = self.run_script("everything")
        self.assert_nothing_written(result, status=2)

    def test_the_directory_of_a_failed_first_run_is_not_even_created(self):
        self.run_script("shop", STUB_FAIL_ON="1")
        self.assertFalse(self.secrets_dir().exists())

    # --- Brevo and the certificate: from the terminal ----------------------------------------------------------------

    def test_brevo_values_come_from_the_terminal_and_are_never_printed_or_passed_on(self):
        result = self.run_script("brevo", tty=f"{BREVO_LOGIN}\n{BREVO_KEY}\n", BREVO_SMTP_PASSWORD="from-the-environment-123")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["shop-brevo-smtp.yaml"], self.files())
        self.assertEqual(["BREVO_SMTP_LOGIN", "BREVO_SMTP_PASSWORD"], self.keys_of("shop-brevo-smtp.yaml"))
        self.assertEqual([BREVO_LOGIN, BREVO_KEY], self.sent())
        self.assert_no_secret_anywhere(result, BREVO_LOGIN, BREVO_KEY, "from-the-environment-123")
        for call in self.calls():
            self.assertIn("--name shop-brevo-smtp", call)

    def test_brevo_is_created_once_then_nothing_is_asked(self):
        self.run_script("brevo", tty=f"{BREVO_LOGIN}\n{BREVO_KEY}\n")
        before = (self.secrets_dir() / "shop-brevo-smtp.yaml").read_bytes()
        self.log.write_text("")
        self.tty.unlink()
        result = self.run_script("brevo")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(before, (self.secrets_dir() / "shop-brevo-smtp.yaml").read_bytes())
        self.assertEqual([], self.calls())
        self.assertIn("nothing changes", result.stdout)

    def test_an_empty_or_a_bad_brevo_value_writes_nothing(self):
        for tty in ("\nkey\n", f"{BREVO_LOGIN}\n\n", f"{BREVO_LOGIN}\nab/cd\n", f"{BREVO_LOGIN}\nab?cd\n", f"{BREVO_LOGIN}\nab#cd\n", f"{BREVO_LOGIN}\nab cd\n", ""):
            with self.subTest(tty=tty):
                result = self.run_script("brevo", tty=tty)
                self.assert_nothing_written(result)

    def test_without_a_terminal_it_refuses_instead_of_reading_the_environment(self):
        result = self.run_script("brevo", SEAL_TTY=str(self.root / "no-such-tty"), BREVO_SMTP_PASSWORD="x")
        self.assert_nothing_written(result)
        self.assertIn("no terminal", result.stderr)
        self.assertEqual([], self.calls())

    def test_the_origin_certificate_and_key_are_sealed_as_a_tls_secret(self):
        result = self.run_script("tls", tty=ORIGIN_CERT + ORIGIN_KEY)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["shop-origin-tls.yaml"], self.files())
        self.assertEqual(["tls.crt", "tls.key"], self.keys_of("shop-origin-tls.yaml"))
        self.assertIn("    type: kubernetes.io/tls\n", (self.secrets_dir() / "shop-origin-tls.yaml").read_text())
        self.assertEqual([ORIGIN_CERT, ORIGIN_KEY], self.sent())
        self.assert_no_secret_anywhere(result, ORIGIN_KEY)

    def test_a_certificate_or_key_that_is_not_pem_writes_nothing(self):
        for tty in ("hello\n", ORIGIN_KEY + ORIGIN_CERT, ORIGIN_CERT + "-----BEGIN PUBLIC KEY-----\nabc\n-----END PUBLIC KEY-----\n", ORIGIN_CERT, ""):
            with self.subTest(tty=tty[:30]):
                result = self.run_script("tls", tty=tty)
                self.assert_nothing_written(result)

    def test_all_three_in_one_run_write_all_three_files_after_everything_worked(self):
        result = self.run_script(tty=f"{BREVO_LOGIN}\n{BREVO_KEY}\n{ORIGIN_CERT}{ORIGIN_KEY}")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["shop-brevo-smtp.yaml", "shop-origin-tls.yaml", "shop-secrets.yaml"], self.files())
        self.assert_no_secret_anywhere(result, BREVO_KEY, ORIGIN_KEY)

    def test_a_failing_kubeseal_at_the_end_of_all_three_writes_none(self):
        total = len(init_env_keys()) + 2 + 2
        result = self.run_script(tty=f"{BREVO_LOGIN}\n{BREVO_KEY}\n{ORIGIN_CERT}{ORIGIN_KEY}", STUB_FAIL_ON=str(total))
        self.assert_nothing_written(result)


class Listing(unittest.TestCase):
    def test_the_stub_is_what_the_script_calls(self):
        self.assertIn("sealed-secrets-kubeseal", KUBESEAL_IMAGE)
        self.assertIn("@sha256:", KUBESEAL_IMAGE)


if __name__ == "__main__":
    unittest.main()

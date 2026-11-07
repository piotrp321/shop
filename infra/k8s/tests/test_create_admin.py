"""make -C infra/k8s create-admin: scripts/create-admin.sh (the confirmation, kubectl exec into Keycloak) against a stub of
docker, and scripts/create-admin-keycloak.sh (kcadm in the Keycloak container) against a stub of kcadm."""

import os
import pathlib
import subprocess
import tempfile
import unittest

from support import REPO

HOST_SCRIPT = REPO / "infra/k8s/scripts/create-admin.sh"
KEYCLOAK_SCRIPT = REPO / "infra/k8s/scripts/create-admin-keycloak.sh"
ADMIN_PASSWORD = "bootstrap-admin-password-123"

# Stub of kcadm.sh: one line per call in $STUB_LOG; answers the searches for a user (STUB_LOGIN_EXISTS,
# STUB_EMAIL_EXISTS), the creation (the id) and fails on STUB_FAIL.
KCADM = r"""#!/bin/sh
echo "$*" >> "$STUB_LOG"
[ "$1 $2" != "config credentials" ] || printf '%s' "${KC_CLI_PASSWORD:-}" > "$STUB_DIR/login"
if [ -n "${STUB_FAIL:-}" ]; then
  case "$*" in *"$STUB_FAIL"*) echo "stub: failing on purpose" >&2; exit 1 ;; esac
fi
case "$1 $2" in
  "get users")
    case "$*" in
      *"username="*) [ -z "${STUB_LOGIN_EXISTS:-}" ] || echo "id-old,$STUB_LOGIN_EXISTS" ;;
      *"email="*) [ -z "${STUB_EMAIL_EXISTS:-}" ] || echo "id-old,somebody" ;;
    esac ;;
  "create users") echo "id-new" ;;
esac
"""

# Stub of docker: the arguments in $STUB_LOG, the standard input in $STUB_DIR/stdin, the exit code $STUB_STATUS.
DOCKER = r"""#!/bin/sh
echo "$*" >> "$STUB_LOG"
cat > "$STUB_DIR/stdin"
echo "inside keycloak"
exit "${STUB_STATUS:-0}"
"""


def stub(directory, filename, body):
    path = pathlib.Path(directory) / filename
    path.write_text(body)
    path.chmod(0o755)
    return path


class CreateAdminInKeycloakTest(unittest.TestCase):
    def run_script(self, *args, **env):
        with tempfile.TemporaryDirectory() as tmp:
            kcadm = stub(tmp, "kcadm.sh", KCADM)
            log = pathlib.Path(tmp) / "log"
            log.write_text("")
            variables = {
                "PATH": os.environ["PATH"],
                "HOME": tmp,
                "KCADM": str(kcadm),
                "STUB_LOG": str(log),
                "STUB_DIR": tmp,
                "KC_BOOTSTRAP_ADMIN_USERNAME": "admin",
                "KC_BOOTSTRAP_ADMIN_PASSWORD": ADMIN_PASSWORD,
                **env,
            }
            result = subprocess.run(["sh", str(KEYCLOAK_SCRIPT), *args], env=variables, capture_output=True, text=True)
            login = pathlib.Path(tmp) / "login"
            return result, log.read_text().splitlines(), login.read_text() if login.exists() else None

    def changes(self, calls):
        return [call for call in calls if call.split()[0] in ("create", "update", "add-roles", "delete", "set-password")]

    def test_creates_the_user_with_the_role_admin_and_sends_the_actions(self):
        result, calls, login = self.run_script("jan", "jan@example.com")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(ADMIN_PASSWORD, login)
        changes = self.changes(calls)
        self.assertEqual(3, len(changes), changes)
        create, role, email = changes
        self.assertTrue(create.startswith("create users -r backoffice"), create)
        for part in ("username=jan", "email=jan@example.com", "enabled=true"):
            self.assertIn(part, create.split())
        self.assertNotIn("credentials", create)
        self.assertEqual("add-roles -r backoffice --uid id-new --rolename admin", " ".join(role.split()[:7]))
        self.assertTrue(email.startswith("update users/id-new/execute-actions-email -r backoffice"), email)
        self.assertIn("lifespan=43200", email.split())
        self.assertIn('["UPDATE_PASSWORD","CONFIGURE_TOTP"]', email.split())
        self.assertIn("jan", result.stdout)
        self.assertIn("12 h", result.stdout)

    def test_looks_for_the_login_and_the_email_exactly_before_any_change(self):
        _, calls, _ = self.run_script("jan", "jan@example.com")
        searches = [i for i, call in enumerate(calls) if call.startswith("get users")]
        self.assertEqual(2, len(searches))
        for i in searches:
            self.assertIn("exact=true", calls[i].split())
        self.assertLess(max(searches), calls.index(self.changes(calls)[0]))

    def test_an_existing_login_fails_without_changes(self):
        result, calls, _ = self.run_script("jan", "jan@example.com", STUB_LOGIN_EXISTS="jan")
        self.assertEqual(3, result.returncode)
        self.assertEqual([], self.changes(calls))
        self.assertIn("jan exists", result.stderr)
        self.assertIn("nothing changed", result.stderr)

    def test_an_existing_email_fails_without_changes(self):
        result, calls, _ = self.run_script("jan", "jan@example.com", STUB_EMAIL_EXISTS="1")
        self.assertEqual(3, result.returncode)
        self.assertEqual([], self.changes(calls))
        self.assertIn("jan@example.com", result.stderr)

    def test_a_failure_after_the_creation_removes_the_new_user(self):
        for failing in ("execute-actions-email", "add-roles"):
            result, calls, _ = self.run_script("jan", "jan@example.com", STUB_FAIL=failing)
            self.assertNotEqual(0, result.returncode, failing)
            self.assertEqual("delete users/id-new -r backoffice", " ".join(calls[-1].split()[:4]), failing)
            self.assertIn("removed", result.stderr)

    def test_a_failing_creation_changes_nothing_else(self):
        result, calls, _ = self.run_script("jan", "jan@example.com", STUB_FAIL="create users")
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(["create"], [call.split()[0] for call in self.changes(calls)])

    def test_never_sets_a_password_and_never_prints_or_passes_the_password_of_the_administrator(self):
        result, calls, _ = self.run_script("jan", "jan@example.com")
        for call in calls:
            self.assertNotIn(ADMIN_PASSWORD, call)
            self.assertNotIn("password=", call.lower())
            self.assertNotIn("reset-password", call)
        self.assertNotIn(ADMIN_PASSWORD, result.stdout + result.stderr)

    def test_without_arguments_it_stops(self):
        for args in ((), ("jan",), ("", "jan@example.com")):
            result, calls, _ = self.run_script(*args)
            self.assertEqual(2, result.returncode, args)
            self.assertEqual([], calls, args)


class CreateAdminTest(unittest.TestCase):
    def run_script(self, *args, answer="tak\n", kube_dir=True, **env):
        with tempfile.TemporaryDirectory() as tmp:
            bin_dir = pathlib.Path(tmp) / "bin"
            bin_dir.mkdir()
            stub(bin_dir, "docker", DOCKER)
            kube = pathlib.Path(tmp) / "kube"
            kube.mkdir()
            (kube / "config").write_text("apiVersion: v1\n")
            log = pathlib.Path(tmp) / "log"
            log.write_text("")
            variables = {
                "PATH": f"{bin_dir}:{os.environ['PATH']}",
                "HOME": tmp,
                "STUB_LOG": str(log),
                "STUB_DIR": tmp,
                "TOOLS_CACHE": str(pathlib.Path(tmp) / "cache"),
                **({"KUBE_DIR": str(kube)} if kube_dir is True else {"KUBE_DIR": kube_dir} if kube_dir else {}),
                **env,
            }
            result = subprocess.run(["sh", str(HOST_SCRIPT), *args], input=answer, env=variables, capture_output=True, text=True)
            stdin = pathlib.Path(tmp) / "stdin"
            return result, log.read_text().splitlines(), stdin.read_text() if stdin.exists() else None

    def test_with_tak_it_runs_the_script_in_the_keycloak_container(self):
        result, calls, stdin = self.run_script("jan", "jan@example.com")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(1, len(calls), calls)
        call = calls[0].split()
        self.assertIn("exec", call)
        self.assertEqual(["deploy/keycloak", "-c", "keycloak", "--", "sh", "-s", "--", "jan", "jan@example.com"], call[call.index("exec") + 2:])
        self.assertEqual(["-n", "shop"], call[call.index("exec") - 2:call.index("exec")])
        self.assertEqual(KEYCLOAK_SCRIPT.read_text(), stdin)
        self.assertIn("inside keycloak", result.stdout)

    def test_it_says_what_it_will_do_before_it_asks(self):
        result, _, _ = self.run_script("jan", "jan@example.com", answer="nie\n")
        before = result.stdout.split("tak")[0]
        for part in ("jan", "jan@example.com", "backoffice", "admin", "12 h", "UPDATE_PASSWORD", "CONFIGURE_TOTP", "shop"):
            self.assertIn(part, before)

    def test_without_the_word_tak_nothing_runs(self):
        for answer in ("nie\n", "\n", "", "TAK\n", "tak tak\n", "yes\n"):
            result, calls, _ = self.run_script("jan", "jan@example.com", answer=answer)
            self.assertEqual(1, result.returncode, repr(answer))
            self.assertEqual([], calls, repr(answer))
            self.assertIn("nothing changed", result.stderr, repr(answer))

    def test_the_exit_code_of_keycloak_is_passed_on(self):
        result, _, _ = self.run_script("jan", "jan@example.com", STUB_STATUS="3")
        self.assertEqual(3, result.returncode)

    def test_the_login_and_the_email_are_required_and_checked(self):
        for args in ((), ("jan",), ("", "jan@example.com"), ("Jan", "jan@example.com"), ("jan kowalski", "jan@example.com"),
                     ("jan", "jan.example.com"), ("jan", "jan @example.com"), ("-jan", "jan@example.com")):
            result, calls, _ = self.run_script(*args)
            self.assertEqual(2, result.returncode, args)
            self.assertEqual([], calls, args)

    def test_the_kubeconfig_is_required(self):
        result, calls, _ = self.run_script("jan", "jan@example.com", kube_dir=None)
        self.assertEqual(2, result.returncode)
        self.assertIn("KUBE_DIR", result.stderr)
        self.assertEqual([], calls)
        result, calls, _ = self.run_script("jan", "jan@example.com", kube_dir="/nonexistent")
        self.assertEqual(1, result.returncode)
        self.assertEqual([], calls)

    def test_the_namespace_comes_from_kube_namespace(self):
        _, calls, _ = self.run_script("jan", "jan@example.com", KUBE_NAMESPACE="other")
        call = calls[0].split()
        self.assertEqual(["-n", "other"], call[call.index("exec") - 2:call.index("exec")])


if __name__ == "__main__":
    unittest.main()

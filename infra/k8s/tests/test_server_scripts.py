"""bootstrap.sh, kubeconfig.sh, status.sh and reinitialize.sh (the Makefile targets that talk to the server over SSH)
against a stub of ssh: what is sent, what is refused before anything runs, what is written to the repository, and that
neither a key nor a credential is printed or put on a command line."""

import io
import os
import pathlib
import shutil
import stat
import subprocess
import tarfile
import tempfile
import unittest

from support import REPO

SCRIPTS = REPO / "infra/k8s/scripts"
CERT = "infra/k8s/environments/prod/sealed-secrets.pem"
PEM = "-----BEGIN CERTIFICATE-----\nMIIDcontrollercert\n-----END CERTIFICATE-----\n"
OTHER_PEM = "-----BEGIN CERTIFICATE-----\nMIIDanothercert\n-----END CERTIFICATE-----\n"
KEY_BACKUP = "kind: Secret\nmetadata:\n  labels:\n    sealedsecrets.bitnami.com/sealed-secrets-key: active\ndata:\n  tls.key: PRIVATEKEYMATERIAL\n"
KUBECONFIG = "apiVersion: v1\nclusters:\n- cluster:\n    server: https://127.0.0.1:6443\n  name: default\nusers:\n- user:\n    client-key-data: ADMINCREDENTIAL\n  name: default\n"

# Stub of ssh: one line of arguments per call in $STUB_LOG, the standard input of the call in $STUB_DIR/stdin-<n>; answers
# by the command: the bootstrap (status STUB_BOOTSTRAP_STATUS), the certificate, the kubeconfig, kubectl (the read of the jobs
# with their Failed condition answers with STUB_JOBS, the read of the logs of the pods of a job by its label prints a line per
# pod of two (and exits with 1 after them with STUB_LOGS_PARTIAL_FAIL, like kubectl when a container of the pods cannot be read),
# or nothing with STUB_NO_PODS, a call matching STUB_FAIL_MATCH fails with no output).
SSH = r"""#!/bin/sh
printf '%s\n' "$(printf '%s' "$*" | tr '\n' ' ')" >> "$STUB_LOG"
n="$(wc -l < "$STUB_LOG" | tr -d ' ')"
[ -t 0 ] || cat > "$STUB_DIR/stdin-$n"
case "$*" in
  *server-bootstrap.sh*) echo "stub: bootstrap ran"; exit "${STUB_BOOTSTRAP_STATUS:-0}" ;;
  *"cat /var/lib/shop-bootstrap/sealed-secrets.pem"*) [ -z "${STUB_CERT_FAIL:-}" ] || exit 1; cat "$STUB_DIR/cert.pem" ;;
  *"cat /etc/rancher/k3s/k3s.yaml"*) [ -z "${STUB_KUBECONFIG_FAIL:-}" ] || exit 1; cat "$STUB_DIR/kubeconfig" ;;
  *kubectl*)
    case "$*" in *"${STUB_FAIL_MATCH:-@@never@@}"*) echo "stub: failing" >&2; exit 1 ;; esac
    case "$*" in
      *"get jobs -o jsonpath"*) printf '%s' "${STUB_JOBS:-}" ;;
      *" logs -l job-name="*)
        [ -n "${STUB_NO_PODS:-}" ] || printf '%s\n' "[pod/first/main] stub log line of the failed job" "[pod/second/main] stub log line of the failed job"
        [ -z "${STUB_LOGS_PARTIAL_FAIL:-}" ] || { echo "stub: container is waiting to start" >&2; exit 1; } ;;
      *) echo "stub kubectl output" ;;
    esac ;;
esac
"""


class ServerScripts(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = pathlib.Path(self.tmp.name)
        self.root = base / "repo"
        shutil.copytree(REPO / "infra/k8s/argocd", self.root / "infra/k8s/argocd")
        (self.root / "infra/k8s/environments/prod").mkdir(parents=True)
        self.bin = base / "bin"
        self.bin.mkdir()
        ssh = self.bin / "ssh"
        ssh.write_text(SSH)
        ssh.chmod(0o755)
        self.stub = base / "stub"
        self.stub.mkdir()
        self.log = self.stub / "log"
        self.log.write_text("")
        (self.stub / "cert.pem").write_text(PEM)
        (self.stub / "kubeconfig").write_text(KUBECONFIG)
        self.kube = base / "kube"

    def tearDown(self):
        self.tmp.cleanup()

    def run_script(self, name, *args, stdin="", **env):
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": self.tmp.name,
            "TMPDIR": self.tmp.name,
            "REPO_ROOT": str(self.root),
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.stub),
            **env,
        }
        return subprocess.run(["sh", str(SCRIPTS / name), *args], env=variables, input=stdin, capture_output=True, text=True)

    def calls(self):
        return self.log.read_text().splitlines()

    def payload(self, n=1):
        return tarfile.open(fileobj=io.BytesIO((self.stub / f"stdin-{n}").read_bytes()))

    def cert_path(self):
        return self.root / CERT

    BAD_HOSTS = ("", "myhost", "-oProxyCommand=touch@x", "user@host;rm -rf /", "user@host name", "@host", "user@", "a@b@c", "user@$(id)", "-ofoo@bar")


class Bootstrap(ServerScripts):
    def test_a_host_that_is_not_user_at_address_is_refused_before_ssh_runs(self):
        for host in self.BAD_HOSTS:
            with self.subTest(host=host):
                result = self.run_script("bootstrap.sh", host)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertEqual([], self.calls())

    def test_it_sends_the_scripts_and_the_manifests_and_runs_the_pinned_k3s(self):
        result = self.run_script("bootstrap.sh", "deploy@203.0.113.7")
        self.assertEqual(0, result.returncode, result.stderr)
        calls = self.calls()
        self.assertEqual(2, len(calls))
        run, fetch = calls
        self.assertIn("-o BatchMode=yes", run)
        self.assertIn("-- deploy@203.0.113.7 ", run)
        self.assertIn("K3S_VERSION='v1.36.6+k3s1'", run)
        self.assertIn("TLS_SAN='203.0.113.7'", run)
        self.assertIn("sudo -n", run)
        self.assertNotIn(" -t ", run)
        names = set(self.payload().getnames())
        for member in ("./argocd/root.yaml", "./argocd/apps/shop.yaml", "./argocd/apps/sealed-secrets.yaml", "./argocd/apps/secrets.yaml",
                       "./argocd/install/argocd.yaml", "./argocd/install/traefik.yaml", "./scripts/server-bootstrap.sh"):
            self.assertIn(member, names)
        self.assertNotIn("./sealed-secrets-key.yaml", names)
        self.assertIn("cat /var/lib/shop-bootstrap/sealed-secrets.pem", fetch)

    def test_the_pinned_version_is_the_one_of_the_smoke_test(self):
        tools = (SCRIPTS / "tools.sh").read_text()
        self.assertIn('K3S_VERSION="${K3S_VERSION:-v1.36.6-k3s1}"', tools)

    def test_the_certificate_is_written_to_the_repository_when_it_has_none(self):
        result = self.run_script("bootstrap.sh", "deploy@203.0.113.7")
        self.assertEqual(PEM, self.cert_path().read_text())
        self.assertIn("commit it", result.stdout)

    def test_a_run_again_with_the_same_certificate_changes_nothing(self):
        self.run_script("bootstrap.sh", "deploy@203.0.113.7")
        before = self.cert_path().read_bytes()
        result = self.run_script("bootstrap.sh", "deploy@203.0.113.7")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(before, self.cert_path().read_bytes())
        self.assertIn("is the one of the controller", result.stdout)

    def test_the_certificate_of_the_repository_goes_with_the_payload_for_the_comparison_before_the_root(self):
        self.cert_path().write_text(PEM)
        self.run_script("bootstrap.sh", "deploy@203.0.113.7")
        archive = self.payload()
        self.assertEqual(PEM, archive.extractfile("./sealed-secrets.pem").read().decode())

    def test_without_a_certificate_in_the_repository_the_payload_has_none(self):
        self.run_script("bootstrap.sh", "deploy@203.0.113.7")
        self.assertNotIn("./sealed-secrets.pem", self.payload().getnames())

    def test_a_different_certificate_of_the_controller_is_an_error_and_nothing_is_overwritten(self):
        self.cert_path().write_text(OTHER_PEM)
        result = self.run_script("bootstrap.sh", "deploy@203.0.113.7")
        self.assertEqual(1, result.returncode)
        self.assertIn("NOT the one of the controller", result.stderr)
        self.assertEqual(OTHER_PEM, self.cert_path().read_text())

    def test_a_failing_bootstrap_stops_before_the_certificate_is_fetched(self):
        result = self.run_script("bootstrap.sh", "deploy@203.0.113.7", STUB_BOOTSTRAP_STATUS="1")
        self.assertEqual(1, result.returncode)
        self.assertEqual(1, len(self.calls()))
        self.assertFalse(self.cert_path().exists())
        self.assertIn("can be run again", result.stderr)

    def test_something_that_is_not_a_certificate_is_not_written(self):
        (self.stub / "cert.pem").write_text("<html>oops</html>\n")
        result = self.run_script("bootstrap.sh", "deploy@203.0.113.7")
        self.assertEqual(1, result.returncode)
        self.assertFalse(self.cert_path().exists())

    def test_a_certificate_that_cannot_be_fetched_is_an_error(self):
        result = self.run_script("bootstrap.sh", "deploy@203.0.113.7", STUB_CERT_FAIL="1")
        self.assertEqual(1, result.returncode)
        self.assertFalse(self.cert_path().exists())

    def test_the_key_backup_travels_in_the_payload_not_on_the_command_line_and_is_not_printed(self):
        backup = pathlib.Path(self.tmp.name) / "backup.yaml"
        backup.write_text(KEY_BACKUP)
        result = self.run_script("bootstrap.sh", "deploy@203.0.113.7", SEALED_KEY_FILE=str(backup))
        self.assertEqual(0, result.returncode, result.stderr)
        archive = self.payload()
        member = archive.getmember("./sealed-secrets-key.yaml")
        self.assertEqual(0o600, stat.S_IMODE(member.mode))
        self.assertEqual(KEY_BACKUP, archive.extractfile(member).read().decode())
        self.assertNotIn("PRIVATEKEYMATERIAL", "\n".join(self.calls()) + result.stdout + result.stderr)

    def test_a_missing_backup_file_is_refused_before_ssh_runs(self):
        result = self.run_script("bootstrap.sh", "deploy@203.0.113.7", SEALED_KEY_FILE="/no/such/backup.yaml")
        self.assertEqual(2, result.returncode)
        self.assertEqual([], self.calls())

    def test_ssh_options_are_passed(self):
        self.run_script("bootstrap.sh", "deploy@203.0.113.7", SSH_OPTS="-i /keys/shop -p 2222")
        self.assertIn("-i /keys/shop -p 2222", self.calls()[0])


class Kubeconfig(ServerScripts):
    def test_it_writes_a_private_kubeconfig_with_the_address_of_the_server_and_prints_no_credential(self):
        result = self.run_script("kubeconfig.sh", "deploy@203.0.113.7", KUBE_DIR=str(self.kube))
        self.assertEqual(0, result.returncode, result.stderr)
        config = self.kube / "config"
        text = config.read_text()
        self.assertIn("server: https://203.0.113.7:6443", text)
        self.assertNotIn("127.0.0.1", text)
        self.assertEqual(0o600, stat.S_IMODE(config.stat().st_mode))
        self.assertNotIn("ADMINCREDENTIAL", result.stdout + result.stderr)
        self.assertIn("sudo -n", self.calls()[0])

    def test_bad_hosts_and_a_missing_directory_are_refused_before_ssh_runs(self):
        for host in self.BAD_HOSTS:
            with self.subTest(host=host):
                self.assertEqual(2, self.run_script("kubeconfig.sh", host, KUBE_DIR=str(self.kube)).returncode)
        self.assertEqual(2, self.run_script("kubeconfig.sh", "deploy@203.0.113.7").returncode)
        self.assertEqual([], self.calls())

    def test_a_directory_inside_the_repository_is_refused(self):
        result = self.run_script("kubeconfig.sh", "deploy@203.0.113.7", KUBE_DIR=str(self.root / "kube"))
        self.assertEqual(2, result.returncode)
        self.assertEqual([], self.calls())

    def test_a_failing_read_or_something_else_than_a_kubeconfig_writes_nothing(self):
        self.assertEqual(1, self.run_script("kubeconfig.sh", "deploy@203.0.113.7", KUBE_DIR=str(self.kube), STUB_KUBECONFIG_FAIL="1").returncode)
        (self.stub / "kubeconfig").write_text("<html>\n")
        self.assertEqual(1, self.run_script("kubeconfig.sh", "deploy@203.0.113.7", KUBE_DIR=str(self.kube)).returncode)
        self.assertFalse((self.kube / "config").exists())


class Status(ServerScripts):
    FAILED_JOBS = "backup-29000000 True\nbackup-verify-29000001 \nbackup-verify-29000002 True\n"

    def test_it_only_reads(self):
        result = self.run_script("status.sh", "deploy@203.0.113.7", STUB_JOBS=self.FAILED_JOBS)
        self.assertEqual(0, result.returncode, result.stderr)
        calls = self.calls()
        self.assertEqual(6, len(calls))
        for call in calls:
            self.assertRegex(call, r" (get|logs) ")
            call = call.split("k3s kubectl", 1)[1]
            for verb in ("apply", "delete", "create", "exec", "patch", "edit", "replace", "scale"):
                self.assertNotRegex(call, rf"\b{verb}\b")
        self.assertIn("applications.argoproj.io", calls[0])
        self.assertIn("-n shop get pods", calls[1])
        self.assertIn("-n shop get jobs", calls[2])

    def test_a_failed_job_has_its_log_printed(self):
        result = self.run_script("status.sh", "deploy@203.0.113.7", STUB_JOBS=self.FAILED_JOBS)
        self.assertEqual(0, result.returncode, result.stderr)
        logs = [call for call in self.calls() if " logs " in call]
        self.assertEqual(2, len(logs), logs)
        for call, name in zip(logs, ("backup-29000000", "backup-verify-29000002")):
            self.assertIn(f"-n shop logs -l job-name={name} --all-containers --prefix --ignore-errors --tail=", call)
        self.assertEqual(4, result.stdout.count("stub log line of the failed job"))
        self.assertIn("[pod/first/main]", result.stdout)
        self.assertIn("[pod/second/main]", result.stdout)
        self.assertIn("log of the failed job backup-verify-29000002", result.stdout)
        self.assertNotIn("backup-verify-29000001", "".join(logs))

    def test_a_failed_job_without_pods_says_so_and_points_to_grafana_cloud(self):
        result = self.run_script("status.sh", "deploy@203.0.113.7", STUB_JOBS="backup-29000000 True\n", STUB_NO_PODS="1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(5, len(self.calls()))
        self.assertIn("log of the failed job backup-29000000", result.stdout)
        self.assertIn("no pods of the job backup-29000000 left", result.stdout)
        self.assertIn("Grafana Cloud", result.stdout)
        self.assertNotIn("stub log line", result.stdout)

    def test_a_job_name_with_unusual_characters_is_not_put_on_a_command_line(self):
        result = self.run_script("status.sh", "deploy@203.0.113.7", STUB_JOBS="bad;name True\n$(id) True\n")
        self.assertEqual(1, result.returncode)
        self.assertIn("unexpected name", result.stderr)
        self.assertEqual(4, len(self.calls()))

    def test_without_failed_jobs_it_says_so_and_reads_no_log(self):
        result = self.run_script("status.sh", "deploy@203.0.113.7", STUB_JOBS="backup-1 \nbackup-2 \n")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(4, len(self.calls()))
        self.assertFalse([call for call in self.calls() if " logs " in call])
        self.assertIn("no failed jobs", result.stdout)

    def test_a_failing_read_is_reported_and_the_others_still_run(self):
        result = self.run_script("status.sh", "deploy@203.0.113.7", STUB_FAIL_MATCH="get pods")
        self.assertEqual(1, result.returncode)
        self.assertEqual(4, len(self.calls()))
        self.assertIn("could not read pods", result.stderr)

    def test_a_failing_read_of_the_jobs_or_of_a_log_ends_with_exit_code_1(self):
        result = self.run_script("status.sh", "deploy@203.0.113.7", STUB_JOBS=self.FAILED_JOBS, STUB_FAIL_MATCH="get jobs -o jsonpath")
        self.assertEqual(1, result.returncode)
        self.assertIn("could not read the failed jobs", result.stderr)
        self.assertFalse([call for call in self.calls() if " logs " in call])
        self.log.write_text("")
        result = self.run_script("status.sh", "deploy@203.0.113.7", STUB_JOBS=self.FAILED_JOBS, STUB_FAIL_MATCH=" logs -l job-name=backup-29000000 ")
        self.assertEqual(1, result.returncode)
        self.assertIn("could not read log of the failed job backup-29000000", result.stderr)
        self.assertIn("log of the failed job backup-verify-29000002", result.stdout)

    def test_the_log_that_kubectl_printed_before_it_failed_is_not_lost(self):
        # An init container of the pod failed, so the containers after it never started and kubectl exits with 1 after the lines
        # of the containers that it could read: the cause of the failure is in those lines.
        result = self.run_script("status.sh", "deploy@203.0.113.7", STUB_JOBS=self.FAILED_JOBS, STUB_LOGS_PARTIAL_FAIL="1")
        self.assertEqual(1, result.returncode)
        self.assertEqual(4, result.stdout.count("stub log line of the failed job"))
        self.assertIn("could not read log of the failed job backup-29000000", result.stderr)
        self.assertIn("could not read log of the failed job backup-verify-29000002", result.stderr)
        self.assertEqual(2, len([call for call in self.calls() if " logs " in call]))

    def test_bad_hosts_are_refused(self):
        for host in self.BAD_HOSTS:
            self.assertEqual(2, self.run_script("status.sh", host).returncode)
        self.assertEqual([], self.calls())


class Reinitialize(ServerScripts):
    def test_without_the_word_tak_nothing_runs(self):
        for answer in ("", "nie\n", "TAK\n", "tak tak\n", "yes\n"):
            with self.subTest(answer=answer):
                result = self.run_script("reinitialize.sh", "deploy@203.0.113.7", stdin=answer)
                self.assertEqual(1, result.returncode)
                self.assertIn("nothing changed", result.stderr)
                self.assertEqual([], self.calls())
                self.assertIn("WIPES", result.stdout)

    def test_with_tak_it_runs_the_seed_in_the_api_pod_once(self):
        result = self.run_script("reinitialize.sh", "deploy@203.0.113.7", stdin="tak\n")
        self.assertEqual(0, result.returncode, result.stderr)
        calls = self.calls()
        self.assertEqual(1, len(calls))
        self.assertIn("kubectl -n shop exec deploy/api -- bin/seed-demo", calls[0])

    def test_a_failing_seed_fails_the_target(self):
        result = self.run_script("reinitialize.sh", "deploy@203.0.113.7", stdin="tak\n", STUB_FAIL_MATCH="exec")
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn("reinitialize: done", result.stdout)

    def test_bad_hosts_are_refused_before_the_question(self):
        for host in self.BAD_HOSTS:
            result = self.run_script("reinitialize.sh", host, stdin="tak\n")
            self.assertEqual(2, result.returncode)
        self.assertEqual([], self.calls())


if __name__ == "__main__":
    unittest.main()

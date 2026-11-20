"""failed.sh, failed-retry.sh and failed-remove.sh (the Makefile targets that review, retry and remove the messages of the
transport `failed`, in the pod of worker-commands over SSH) against the stub of ssh of test_server_scripts: what is sent,
what is refused before anything runs, and that nothing but `failed-retry` and `failed-remove` ever changes a message."""

import os
import re
import subprocess
import unittest

from support import REPO
from test_server_scripts import ServerScripts

HOST = "deploy@203.0.113.7"
EXEC = "kubectl -n shop exec deploy/worker-commands -- php -d memory_limit=128M bin/console "


class FailedScripts(ServerScripts):
    def console_calls(self):
        return [call.split(EXEC, 1)[1] for call in self.calls() if EXEC in call]

    def assertNothingChanged(self):
        for call in self.calls():
            for word in ("failed:retry", "failed-retry", "failed:remove"):
                self.assertNotIn(word, call)


class Failed(FailedScripts):
    def test_it_only_reads_the_statistics_and_the_list_in_the_pod_of_worker_commands(self):
        result = self.run_script("failed.sh", HOST)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["messenger:failed:show --stats", "messenger:failed:show --max=200"], self.console_calls())
        self.assertEqual(2, len(self.calls()))
        self.assertNothingChanged()
        self.assertIn("stub kubectl output", result.stdout)

    def test_no_host_and_bad_hosts_are_refused_before_ssh_runs(self):
        for host in self.BAD_HOSTS:
            with self.subTest(host=host):
                result = self.run_script("failed.sh", host)
                self.assertEqual(2, result.returncode)
                self.assertIn("failed:", result.stderr)
        self.assertEqual([], self.calls())

    def test_an_error_of_ssh_or_kubectl_is_a_failure_with_a_message(self):
        for match in ("--stats", "--max=200", "kubectl"):
            with self.subTest(match=match):
                result = self.run_script("failed.sh", HOST, STUB_FAIL_MATCH=match)
                self.assertEqual(1, result.returncode)
                self.assertIn("failed: could not read", result.stderr)


class FailedRetry(FailedScripts):
    def test_with_an_id_it_retries_that_message_without_the_question_in_a_limited_process(self):
        result = self.run_script("failed-retry.sh", HOST, "42")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["messenger:failed:retry 42 --force"], self.console_calls())
        self.assertEqual(1, len(self.calls()))
        self.assertIn("stub kubectl output", result.stdout)

    def test_without_an_id_it_runs_the_bulk_command_and_never_the_blocked_ones(self):
        result = self.run_script("failed-retry.sh", HOST)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["app:messaging:failed-retry"], self.console_calls())
        self.assertNotIn("messenger:", self.calls()[0])
        self.assertIn("stub kubectl output", result.stdout)

    def test_max_goes_to_the_bulk_command_when_within_1_to_1000(self):
        for value in ("1", "50", "1000"):
            with self.subTest(max=value):
                self.log.write_text("")
                result = self.run_script("failed-retry.sh", HOST, "", value)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual([f"app:messaging:failed-retry --max={value}"], self.console_calls())

    def test_a_bad_max_or_id_is_refused_before_ssh_runs(self):
        for id_, max_ in (("", "0"), ("", "1001"), ("", "abc"), ("", "-1"), ("", "1 2"), ("", "$(id)"), ("", "010x"),
                          ("abc", ""), ("0", ""), ("-1", ""), ("1 2", ""), ("1;id", ""), ("$(id)", ""), ("1 --force", ""),
                          ("٣", ""), ("42", "50")):
            with self.subTest(id=id_, max=max_):
                result = self.run_script("failed-retry.sh", HOST, id_, max_)
                self.assertEqual(2, result.returncode)
                self.assertIn("failed-retry:", result.stderr)
        self.assertEqual([], self.calls())

    def test_no_host_and_bad_hosts_are_refused_before_ssh_runs(self):
        for host in self.BAD_HOSTS:
            with self.subTest(host=host):
                self.assertEqual(2, self.run_script("failed-retry.sh", host, "42").returncode)
        self.assertEqual([], self.calls())

    def test_an_error_of_ssh_or_kubectl_is_a_failure_with_a_message(self):
        for id_ in ("42", ""):
            with self.subTest(id=id_):
                result = self.run_script("failed-retry.sh", HOST, id_, STUB_FAIL_MATCH="exec")
                self.assertEqual(1, result.returncode)
                self.assertIn("failed-retry: the command failed", result.stderr)
                self.assertNotIn("done", result.stdout)


class FailedRemove(FailedScripts):
    def test_with_tak_it_shows_the_message_then_removes_it(self):
        result = self.run_script("failed-remove.sh", HOST, "42", stdin="tak\n")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["messenger:failed:show 42 -vv", "messenger:failed:remove 42 --force"], self.console_calls())
        self.assertIn("stub kubectl output", result.stdout)
        self.assertIn("type tak", result.stdout)

    def test_without_the_word_tak_nothing_is_removed(self):
        for answer in ("", "nie\n", "TAK\n", "tak tak\n", "yes\n", "\n"):
            with self.subTest(answer=answer):
                self.log.write_text("")
                result = self.run_script("failed-remove.sh", HOST, "42", stdin=answer)
                self.assertEqual(1, result.returncode)
                self.assertIn("not confirmed", result.stderr)
                self.assertNotIn("failed:remove", "".join(self.calls()))
                self.assertEqual(["messenger:failed:show 42 -vv"], self.console_calls())

    def test_no_id_a_bad_id_and_a_bad_host_are_refused_before_ssh_runs(self):
        for host, id_ in ((HOST, ""), (HOST, "abc"), (HOST, "0"), (HOST, "-1"), (HOST, "1 2"), (HOST, "1;id"), (HOST, "$(id)"),
                          (HOST, "1 --force"), ("", "42"), ("myhost", "42"), ("-oProxyCommand=x@y", "42")):
            with self.subTest(host=host, id=id_):
                result = self.run_script("failed-remove.sh", host, id_, stdin="tak\n")
                self.assertEqual(2, result.returncode)
                self.assertIn("failed-remove:", result.stderr)
        self.assertEqual([], self.calls())

    def test_a_message_that_cannot_be_shown_is_not_removed_even_with_tak(self):
        result = self.run_script("failed-remove.sh", HOST, "42", stdin="tak\n", STUB_FAIL_MATCH="failed:show")
        self.assertEqual(1, result.returncode)
        self.assertIn("could not show", result.stderr)
        self.assertNotIn("failed:remove", "".join(self.calls()))

    def test_an_error_of_the_removal_is_a_failure_without_done(self):
        result = self.run_script("failed-remove.sh", HOST, "42", stdin="tak\n", STUB_FAIL_MATCH="failed:remove")
        self.assertEqual(1, result.returncode)
        self.assertIn("failed-remove: the command failed", result.stderr)
        self.assertNotIn("removed", result.stdout.split("type tak")[-1])


class MakeTargets(FailedScripts):
    """The targets of the Makefile pass the arguments of the command line to the scripts, without a shell reading them."""

    def make(self, target, **variables):
        """The recipe of the target as make runs it: `$$` is `$`, a variable of the command line is in the environment of the shell
        (make is not in the image of the tests)."""
        lines = (REPO / "infra/k8s/Makefile").read_text().splitlines()
        body = []
        for line in lines[lines.index(target + ":") + 1:]:
            if not line.startswith("\t"):
                break
            body.append(line[1:].lstrip("@").replace("$$", "$"))
        env = {"PATH": f"{self.bin}:{os.environ['PATH']}", "HOME": self.tmp.name, "STUB_LOG": str(self.log), "STUB_DIR": str(self.stub), **variables}
        result = None
        for line in body:
            result = subprocess.run(["sh", "-c", line], env=env, cwd=REPO / "infra/k8s", capture_output=True, text=True, input="tak\n")
            if result.returncode != 0:
                break
        return result

    def test_the_targets_reach_the_scripts(self):
        self.assertEqual(0, self.make("failed", HOST=HOST).returncode)
        self.assertEqual(0, self.make("failed-retry", HOST=HOST, ID="7").returncode)
        self.assertEqual(0, self.make("failed-retry", HOST=HOST, MAX="9").returncode)
        self.assertEqual(0, self.make("failed-remove", HOST=HOST, ID="7").returncode)
        self.assertEqual(["messenger:failed:show --stats", "messenger:failed:show --max=200", "messenger:failed:retry 7 --force",
                          "app:messaging:failed-retry --max=9", "messenger:failed:show 7 -vv", "messenger:failed:remove 7 --force"],
                         self.console_calls())

    def test_the_targets_without_host_or_id_fail(self):
        for target, variables in (("failed", {}), ("failed-retry", {}), ("failed-remove", {"HOST": HOST}), ("failed-remove", {})):
            with self.subTest(target=target, variables=variables):
                self.assertNotEqual(0, self.make(target, **variables).returncode)
        self.assertEqual([], self.calls())

    def test_a_value_with_shell_syntax_is_not_run(self):
        marker = self.stub / "injected"
        result = self.make("failed-retry", HOST=HOST, ID=f"1;touch${{IFS}}{marker}")
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(marker.exists())
        self.assertEqual([], self.calls())


class Documents(unittest.TestCase):
    def test_the_runbook_covers_the_three_alerts_and_marks_the_changing_targets_as_needing_consent(self):
        readme = (REPO / "infra/k8s/README.md").read_text()
        for alert in ("`MessagingUnhealthy`", "`OrderExpiryFailed`", "`OrderExpiryMissing`"):
            self.assertIn(alert, readme)
        for step in ("**zgoda użytkownika** – ponowienie", "**zgoda użytkownika** – usunięcie"):
            self.assertIn(step, readme)
        self.assertIn("Nigdy nie usuwa się wiadomości z webhookiem płatności", readme)

    def test_the_runbook_names_the_log_line_of_the_refund_as_the_code_writes_it(self):
        handler = (REPO / "apps/api/src/Payment/Application/Command/HandleProviderWebhookHandler.php").read_text()
        match = re.search(r"error\('Payment webhook: (.*?requires a manual refund)\.'", handler)
        self.assertIsNotNone(match, "the log of the refund is gone from the handler: update the runbook and this test")
        readme = (REPO / "infra/k8s/README.md").read_text()
        self.assertIn(match.group(1), readme)
        for field in ("orderId", "paymentId", "reason"):
            self.assertIn(f"`{field}`", readme)
        self.assertNotIn("wymaga ręcznego zwrotu", readme)

    def test_the_runbook_names_the_log_line_of_an_order_that_cannot_be_read_as_the_command_writes_it(self):
        """payment:close-abandoned leaves the payment of an order Ordering cannot read pending and ends with 2 on every run, so
        OrderExpiryFailed burns until the data is mended: the runbook of the alert says which log line to look for."""
        command = (REPO / "apps/api/src/Payment/UI/Console/CloseAbandonedPaymentsCommand.php").read_text()
        match = re.search(r"error\('(payment:close-abandoned: the order of the payment could not be read)[;.]", command)
        self.assertIsNotNone(match, "the log of an unreadable order is gone from the command: update the runbook and this test")
        runbook = (REPO / "infra/k8s/README.md").read_text()
        section = runbook[runbook.index("- **`OrderExpiryFailed`**"):runbook.index("- **`OrderExpiryMissing`**")]
        self.assertIn(match.group(1), section)
        for field in ("paymentId", "orderId", "reason"):
            self.assertIn(f"`{field}`", section)
            self.assertRegex(command, rf"'{field}' =>")
        self.assertIn("decyzji **właściciela**", section)

    def test_the_instructions_for_agents_name_the_three_targets_and_the_consent(self):
        text = (REPO / "infra/k8s/CLAUDE.md").read_text()
        self.assertIn("Tylko po zgodzie: `failed-retry`", text)
        self.assertIn("`failed-remove` (usuwa", text)
        self.assertIn("`failed` (wiadomości", text)


if __name__ == "__main__":
    unittest.main()

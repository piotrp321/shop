import ast
import os
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO


class SyntaxTest(unittest.TestCase):
    def test_the_smoke_checks_are_valid_python(self):
        """smoke/checks.py runs only inside the cluster; a syntax error would show up half an hour into the smoke test."""
        ast.parse((REPO / "infra/k8s/smoke/checks.py").read_text())


class ToolsTest(unittest.TestCase):
    def test_the_browser_of_the_smoke_test_is_the_playwright_image_of_the_e2e_tests(self):
        """smoke.sh opens the store in the Chromium of the image the E2E tests are built on: no other browser image."""
        tools = (REPO / "infra/k8s/scripts/tools.sh").read_text()
        e2e = re.search(r"(?m)^FROM (\S+)$", (REPO / "e2e/Dockerfile").read_text()).group(1)
        self.assertIn(f'PLAYWRIGHT_IMAGE="${{PLAYWRIGHT_IMAGE:-{e2e}}}"', tools)

    def browser_console(self, docker):
        """Runs browser_console of tools.sh with a stub of docker (the body of the shell script `docker`)."""
        with tempfile.TemporaryDirectory() as tmp:
            stub = pathlib.Path(tmp) / "docker"
            stub.write_text("#!/bin/sh\n" + docker + "\n")
            stub.chmod(0o755)
            env = {"PATH": f"{tmp}:{os.environ['PATH']}", "KUBE_NETWORK": "bridge"}
            script = '. "$1"; browser_console http://127.0.0.1:9 data:text/html,x'
            return subprocess.run(["sh", "-c", script, "sh", str(REPO / "infra/k8s/scripts/tools.sh")], env=env, capture_output=True, text=True)

    def test_browser_console_prints_the_console_lines_and_succeeds(self):
        result = self.browser_console("echo '[1:1:INFO:CONSOLE:1] \"Refused to load\"'")
        self.assertEqual((result.returncode, result.stdout), (0, '[1:1:INFO:CONSOLE:1] "Refused to load"\n'))

    def test_browser_console_without_console_lines_is_a_success_with_no_output(self):
        result = self.browser_console("exit 0")
        self.assertEqual((result.returncode, result.stdout), (0, ""))

    def test_browser_console_fails_when_docker_or_the_browser_fails(self):
        """A failed browser run is not "no violations": the status of docker reaches the caller."""
        result = self.browser_console("echo 'docker: network not found' >&2; exit 125")
        self.assertEqual(result.returncode, 125)
        self.assertIn("network not found", result.stderr)

    def test_the_smoke_test_does_not_swallow_the_failure_of_the_console_check(self):
        smoke = (REPO / "infra/k8s/scripts/smoke.sh").read_text()
        self.assertIn('browser_console "http://$node:$port" "http://admin.localhost/")" ||', smoke)
        self.assertNotRegex(smoke, r"browser_console[^\n]*\|\| true")


if __name__ == "__main__":
    unittest.main()

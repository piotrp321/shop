import ast
import re
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


if __name__ == "__main__":
    unittest.main()

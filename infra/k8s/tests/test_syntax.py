import ast
import unittest

from support import REPO


class SyntaxTest(unittest.TestCase):
    def test_the_smoke_checks_are_valid_python(self):
        """smoke/checks.py runs only inside the cluster; a syntax error would show up half an hour into the smoke test."""
        ast.parse((REPO / "infra/k8s/smoke/checks.py").read_text())


if __name__ == "__main__":
    unittest.main()

"""The files of the load measurement outside the chart (docs/features/hardening/spec.md, D14): the k6 image is pinned by a
tag and a digest in one place, `make load-smoke` and the workflow run the smoke run, and the measurement target is wired."""

import re
import unittest

from support import REPO


class LoadFilesTest(unittest.TestCase):
    def test_the_k6_image_is_pinned_by_tag_and_digest_in_one_place(self):
        pinned = re.findall(r"grafana/k6:[0-9]+\.[0-9]+\.[0-9]+@sha256:[0-9a-f]{64}", (REPO / "load/k6.sh").read_text())
        self.assertEqual(1, len(pinned))
        for path in [*REPO.glob("load/**/*"), *REPO.glob("infra/k8s/scripts/*.sh"), REPO / "Makefile", REPO / ".github/workflows/ci.yml"]:
            if path.is_file() and path != REPO / "load/k6.sh":
                self.assertNotIn("grafana/k6", path.read_text(), f"{path.relative_to(REPO)} names the k6 image, which only load/k6.sh does")

    def test_the_makefile_runs_the_smoke_run_and_the_measurement_has_its_target(self):
        makefile = (REPO / "Makefile").read_text()
        self.assertRegex(makefile, r"(?m)^load-smoke:\n\tsh load/smoke\.sh$")
        self.assertRegex((REPO / "infra/k8s/Makefile").read_text(), r"(?m)^load:\n(?:\t@.*\n)?\tsh scripts/load\.sh$")

    def test_the_workflow_runs_the_smoke_run_after_the_e2e_tests_under_the_same_condition(self):
        workflow = (REPO / ".github/workflows/ci.yml").read_text()
        e2e = workflow.index("      - name: make e2e\n")
        smoke = workflow.index("      - name: make load-smoke\n")
        self.assertLess(e2e, smoke)
        step = workflow[smoke : workflow.index("\n\n", smoke)]
        self.assertIn("if: env.FULL_CI == 'true'", step)
        self.assertIn("run: make load-smoke", step)


if __name__ == "__main__":
    unittest.main()

"""The files of the load measurement outside the chart (docs/features/hardening/spec.md, D14): the k6 image is pinned by a
tag and a digest in one place, `make load-smoke` runs the smoke run and the workflow runs it after the E2E tests (through
`make ci-after-e2e`), and the measurement target is wired."""

import re
import unittest

from support import REPO


class LoadFilesTest(unittest.TestCase):
    def test_the_k6_image_is_pinned_by_tag_and_digest_in_one_place(self):
        pinned = re.findall(r"grafana/k6:[0-9]+\.[0-9]+\.[0-9]+@sha256:[0-9a-f]{64}", (REPO / "load/k6.sh").read_text())
        self.assertEqual(1, len(pinned))
        for path in [*REPO.glob("load/**/*"), *REPO.glob("infra/k8s/scripts/*.sh"), REPO / "Makefile", *REPO.glob("make/*.mk"),
                     REPO / ".github/workflows/ci.yml"]:
            if path.is_file() and path != REPO / "load/k6.sh":
                self.assertNotIn("grafana/k6", path.read_text(), f"{path.relative_to(REPO)} names the k6 image, which only load/k6.sh does")

    def test_the_makefile_runs_the_smoke_run_and_the_measurement_has_its_target(self):
        fragment = (REPO / "make/load.mk").read_text()
        self.assertRegex(fragment, r"(?m)^load-smoke:\n\tsh load/smoke\.sh$")
        self.assertRegex(fragment, r"(?m)^CI_AFTER_E2E \+= load-smoke$")
        self.assertIn("include $(sort $(wildcard make/*.mk))", (REPO / "Makefile").read_text())
        self.assertRegex((REPO / "infra/k8s/Makefile").read_text(), r"(?m)^load:\n(?:\t@.*\n)?\tsh scripts/load\.sh$")

    def test_the_checkout_uses_an_email_unique_in_every_iteration(self):
        # The limit of unpaid orders (docs/features/abuse-limits/spec.md, L6) counts 3 per email: an email per virtual
        # user would hit it in a measurement at the values of prod. The email is made of the key of the request, which
        # is new in every checkout of every iteration.
        flows = (REPO / "load/lib/flows.js").read_text()
        checkout = flows[flows.index("export function checkout(") : flows.index("\n}\n", flows.index("export function checkout("))]
        key = re.search(r"(?m)^  const key = `([^`]*)`;$", checkout)
        self.assertIsNotNone(key, "the checkout makes its Idempotency-Key")
        for unique in ("${exec.vu.idInTest}", "${exec.vu.iterationInInstance}", "${counter}"):
            self.assertIn(unique, key.group(1))
        emails = re.findall(r"(?m)^\s+email: (.*),$", checkout)
        self.assertEqual(["`${key}@example.com`"], emails)

    def test_the_workflow_runs_the_smoke_run_after_the_e2e_tests_under_the_same_condition(self):
        workflow = (REPO / ".github/workflows/ci.yml").read_text()
        e2e = workflow.index("      - name: make e2e\n")
        after = workflow.index("      - name: make ci-after-e2e\n")
        self.assertLess(e2e, after)
        step = workflow[after : workflow.index("\n\n", after)]
        self.assertIn("if: env.FULL_CI == 'true'", step)
        self.assertIn("run: make ci-after-e2e", step)


if __name__ == "__main__":
    unittest.main()

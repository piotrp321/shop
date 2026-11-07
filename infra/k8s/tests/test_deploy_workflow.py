"""The deployment workflow (.github/workflows/deploy.yml, read as JSON from scripts/render.sh): who pushes images, who
moves the branch `deploy` and when. Each test names a property of docs/features/deploy/spec.md ("Wdrożenie nowej
wersji", D4) that a careless edit of the workflow would break without any other check noticing."""

import json
import re
import unittest

from support import REPO, RENDER

APPS = ("api", "store", "admin", "elasticsearch")
SHA_PIN = re.compile(r"^[\w.-]+/[\w.-]+@[0-9a-f]{40}$")

workflow = json.loads((RENDER / "deploy-workflow.json").read_text())
jobs = workflow["jobs"]


def steps(job):
    return jobs[job]["steps"]


def uses(step, action):
    return step.get("uses", "").startswith(action + "@")


def run_scripts(job=None):
    selected = [jobs[job]] if job else jobs.values()
    return [step["run"] for j in selected for step in j["steps"] if "run" in step]


class Triggers(unittest.TestCase):
    def test_images_are_pushed_and_deploy_moves_only_for_a_push_to_main(self):
        self.assertEqual(["main"], workflow["on"]["push"]["branches"])
        self.assertEqual("github.event_name == 'push'", jobs["images"]["if"])
        self.assertEqual("github.event_name == 'push'", jobs["deploy"]["if"])

    def test_pull_requests_run_the_smoke_test_for_the_files_that_decide_the_images(self):
        paths = workflow["on"]["pull_request"]["paths"]
        for path in ("infra/**", "apps/*/Dockerfile", "apps/api/config/**", "apps/api/bin/**", "apps/api/composer.lock",
                     "apps/store/package-lock.json", "apps/admin/package-lock.json", "apps/store/next.config.ts"):
            self.assertIn(path, paths)
        self.assertIn("workflow_dispatch", workflow["on"])

    def test_runs_for_main_are_never_cancelled_and_other_runs_are(self):
        self.assertIn("github.event_name == 'push' && 'main'", workflow["concurrency"]["group"])
        self.assertEqual("${{ github.event_name != 'push' }}", workflow["concurrency"]["cancel-in-progress"])


class Images(unittest.TestCase):
    def pushes(self):
        return [s for j in jobs for s in steps(j) if uses(s, "docker/build-push-action") and s["with"].get("push") is True]

    def test_four_images_tagged_with_the_commit_sha_and_only_there(self):
        pushed = self.pushes()
        self.assertEqual(4, len(pushed))
        for app in APPS:
            tags = [s["with"]["tags"] for s in pushed if s["with"]["context"].endswith(app if app != "elasticsearch" else "infra/elasticsearch")]
            self.assertEqual(["${{ env.IMAGE_PREFIX }}/%s:${{ github.sha }}" % app], tags)
        for step in pushed:
            self.assertIn(step, steps("images"), "an image is pushed outside the job images")

    def test_the_prefix_is_the_registry_of_the_chart(self):
        values = (REPO / "infra/k8s/charts/shop/values.yaml").read_text()
        self.assertIn("registry: " + workflow["env"]["IMAGE_PREFIX"], values)

    def test_production_stages_as_the_smoke_test_builds_them(self):
        smoke = (REPO / "infra/k8s/scripts/smoke.sh").read_text()
        for job in ("images", "smoke"):
            built = [s["with"] for s in steps(job) if uses(s, "docker/build-push-action")]
            self.assertEqual({"apps/api", "apps/store", "apps/admin", "infra/elasticsearch"}, {b["context"] for b in built})
            for b in built:
                self.assertEqual("prod" if b["context"].startswith("apps/") else None, b.get("target"))
        self.assertIn('--target prod -t "$registry/$app:r1" "$TOOLS_REPO/apps/$app"', smoke)
        self.assertIn('-t "$registry/elasticsearch:r1" "$TOOLS_REPO/infra/elasticsearch"', smoke)

    def test_an_existing_tag_is_kept_not_rebuilt(self):
        by_id = {s["id"]: s for s in steps("images") if "id" in s and s["id"].startswith("build-")}
        self.assertEqual(sorted("build-" + a for a in APPS), sorted(by_id))
        for app in APPS:
            self.assertEqual("steps.existing.outputs.%s == ''" % app, by_id["build-" + app]["if"])

    def test_the_digests_of_all_four_images_are_job_outputs(self):
        self.assertEqual(sorted(APPS), sorted(jobs["images"]["outputs"]))

    def test_no_latest_tag(self):
        self.assertNotRegex(json.dumps(workflow), r"[:@]latest")


class Smoke(unittest.TestCase):
    def test_it_runs_after_the_images_on_main_and_never_pushes(self):
        self.assertEqual("images", jobs["smoke"]["needs"])
        condition = jobs["smoke"]["if"]
        self.assertIn("needs.images.result == 'success'", condition)
        self.assertIn("needs.images.result == 'skipped'", condition)
        self.assertNotIn("failure", condition)

    def test_on_main_it_uses_the_pushed_images_by_digest_otherwise_it_builds_them(self):
        pull = next(s for s in steps("smoke") if s.get("name") == "Pull the pushed images by digest")
        self.assertEqual("github.event_name == 'push'", pull["if"])
        self.assertIn('"$IMAGE_PREFIX/$app@${!digest}"', pull["run"])
        for step in steps("smoke"):
            if uses(step, "docker/build-push-action"):
                self.assertEqual("github.event_name != 'push'", step["if"])
                self.assertIs(True, step["with"]["load"])

    def test_it_runs_the_smoke_test_on_the_images_it_prepared(self):
        script = "\n".join(run_scripts("smoke"))
        self.assertIn("SMOKE_BUILD=0 make -C infra/k8s smoke", script)
        project = workflow["env"]["COMPOSE_PROJECT_NAME"]
        self.assertEqual("shop-smoke-sm-" + project, workflow["env"]["SMOKE_REGISTRY"])
        for step in steps("smoke"):
            if uses(step, "docker/build-push-action"):
                self.assertTrue(step["with"]["tags"].startswith("${{ env.SMOKE_REGISTRY }}/"))
                self.assertTrue(step["with"]["tags"].endswith(":r1"))

    def test_peaks_go_to_the_summary_also_after_a_failure(self):
        summary = next(s for s in steps("smoke") if s.get("name") == "Resources in the summary")
        self.assertEqual("always()", summary["if"])
        self.assertIn("GITHUB_STEP_SUMMARY", summary["run"])
        self.assertIn("peak memory", summary["run"])
        self.assertIn("peak disk", summary["run"])


class Deploy(unittest.TestCase):
    def test_it_needs_a_green_smoke_test_and_the_images(self):
        self.assertEqual(["images", "smoke"], jobs["deploy"]["needs"])
        self.assertNotIn("continue-on-error", jobs["smoke"])
        self.assertNotIn("continue-on-error", jobs["deploy"])

    def test_it_moves_the_branch_only_with_the_script_without_force(self):
        scripts = "\n".join(run_scripts())
        self.assertNotIn("git push", scripts)
        self.assertNotIn("--force", scripts)
        self.assertEqual(1, scripts.count("deploy-ref.sh"))
        self.assertIn('sh .github/scripts/deploy-ref.sh "$GITHUB_SHA" "git@github.com:$GITHUB_REPOSITORY.git" deploy', scripts)

    def test_the_checkout_has_the_full_history_and_leaves_no_token(self):
        checkout = next(s for s in steps("deploy") if uses(s, "actions/checkout"))
        self.assertEqual(0, checkout["with"]["fetch-depth"])
        self.assertIs(False, checkout["with"]["persist-credentials"])

    def test_the_key_is_in_the_environment_of_one_step_only(self):
        holders = [(j, s.get("name")) for j in jobs for s in steps(j) if "DEPLOY_KEY" in json.dumps(s.get("env", {}))]
        self.assertEqual([("deploy", "Fast-forward deploy")], holders)
        self.assertNotIn("DEPLOY_KEY", json.dumps({k: v for k, v in workflow.items() if k != "jobs"}))
        for job in jobs.values():
            self.assertNotIn("DEPLOY_KEY", json.dumps(job.get("env", {})))
        self.assertNotIn("set -x", "\n".join(run_scripts()))


class Permissions(unittest.TestCase):
    def test_only_the_job_that_pushes_images_can_write_packages(self):
        self.assertEqual({"contents": "read"}, workflow["permissions"])
        self.assertEqual({"contents": "read", "packages": "write"}, jobs["images"]["permissions"])
        for job in ("smoke", "deploy"):
            self.assertNotEqual("write", jobs[job]["permissions"].get("packages"))
            self.assertEqual("read", jobs[job]["permissions"]["contents"])

    def test_actions_are_pinned_by_sha(self):
        for job in jobs:
            for step in steps(job):
                if "uses" in step:
                    self.assertRegex(step["uses"], SHA_PIN)


if __name__ == "__main__":
    unittest.main()

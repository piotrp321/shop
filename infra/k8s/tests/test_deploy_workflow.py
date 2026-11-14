"""The deployment workflow (.github/workflows/deploy.yml, read as JSON from scripts/render.sh): who pushes images, who
moves the branch `deploy` and when (only a dispatch on main, started on the user's request). Each test names a property of docs/features/deploy/spec.md ("Wdrożenie nowej
wersji", D4) that a careless edit of the workflow would break without any other check noticing."""

import json
import re
import types
import unittest

from support import REPO, RENDER

APPS = ("api", "store", "admin", "elasticsearch")
PUSH_EVENT = re.compile(r"event_name\s*[!=]=\s*'push'|GITHUB_EVENT_NAME\"?\s*=\s*push")
SHA_PIN = re.compile(r"^[\w.-]+/[\w.-]+@[0-9a-f]{40}$")

workflow = json.loads((RENDER / "deploy-workflow.json").read_text())
jobs = workflow["jobs"]

# A deployment is a dispatch on main, nothing else: not a push, not a pull request, not a dispatch on another branch.
ON_MAIN = "github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'"
EVENTS = (
    ("workflow_dispatch", "refs/heads/main", True),
    ("workflow_dispatch", "refs/heads/feat/x", False),
    ("workflow_dispatch", "refs/heads/main-2", False),
    ("workflow_dispatch", "refs/tags/main", False),
    ("pull_request", "refs/pull/1/merge", False),
    ("push", "refs/heads/main", False),
)


def evaluate(expression, event, ref):
    """The few operators the conditions use (==, !=, &&, ||) on github.event_name and github.ref."""
    github = types.SimpleNamespace(event_name=event, ref=ref)
    python = expression.strip().removeprefix("${{").removesuffix("}}").replace("&&", " and ").replace("||", " or ")
    return eval(python, {"__builtins__": {}}, {"github": github})  # noqa: S307 - our own workflow, fixed vocabulary


def steps(job):
    return jobs[job]["steps"]


def uses(step, action):
    return step.get("uses", "").startswith(action + "@")


def run_scripts(job=None):
    selected = [jobs[job]] if job else jobs.values()
    return [step["run"] for j in selected for step in j["steps"] if "run" in step]


class Triggers(unittest.TestCase):
    def test_there_is_no_push_trigger(self):
        self.assertNotIn("push", workflow["on"])
        self.assertEqual({"pull_request", "workflow_dispatch"}, set(workflow["on"]))
        # Neither an expression nor a shell script asks whether the event is a push.
        texts = [json.dumps(workflow["concurrency"]), json.dumps(workflow["env"])]
        texts += [str(job.get("if", "")) for job in jobs.values()]
        texts += [str(step.get("if", "")) for job in jobs.values() for step in job["steps"]]
        texts += [json.dumps(step.get("env", {})) for job in jobs.values() for step in job["steps"]]
        texts += run_scripts()
        for text in texts:
            self.assertIsNone(PUSH_EVENT.search(text), text)

    def test_the_push_check_catches_the_old_forms(self):
        for old in ("github.event_name == 'push'", "github.event_name != 'push'", 'if [ "$GITHUB_EVENT_NAME" = push ]; then'):
            self.assertIsNotNone(PUSH_EVENT.search(old), old)

    def test_images_are_pushed_and_deploy_moves_only_for_a_dispatch_on_main(self):
        self.assertEqual(ON_MAIN, jobs["images"]["if"])
        self.assertEqual(ON_MAIN, jobs["deploy"]["if"])
        self.assertEqual("${{ %s }}" % ON_MAIN, workflow["env"]["DEPLOY"])

    def test_the_conditions_hold_only_for_a_dispatch_on_main(self):
        for event, ref, expected in EVENTS:
            for expression in (jobs["images"]["if"], jobs["deploy"]["if"], workflow["env"]["DEPLOY"]):
                self.assertIs(expected, evaluate(expression, event, ref), f"{event} on {ref}: {expression}")

    def test_a_dispatch_on_another_branch_is_a_smoke_rehearsal_only(self):
        # Everything that publishes or needs the registry (the images, the values file, the pull of the pushed images, the
        # token) hangs on the one condition; the smoke job itself runs for every event, and nothing else asks for the event.
        self.assertNotIn("event_name", jobs["smoke"]["if"])
        for step in steps("smoke"):
            self.assertNotIn("event_name", step.get("if", ""), step.get("name"))
        gated = [s["name"] for s in steps("smoke") if s.get("if") == "env.DEPLOY == 'true'"]
        self.assertEqual(["Write the images values file", "Pull the pushed images by digest"], gated)

    def test_pull_requests_run_the_smoke_test_for_the_files_that_decide_the_images(self):
        paths = workflow["on"]["pull_request"]["paths"]
        for path in ("infra/**", "apps/*/Dockerfile", "apps/api/config/**", "apps/api/bin/**", "apps/api/composer.lock",
                     "apps/store/package-lock.json", "apps/admin/package-lock.json", "apps/store/next.config.ts"):
            self.assertIn(path, paths)
        self.assertIn("workflow_dispatch", workflow["on"])

    def test_runs_for_main_are_never_cancelled_and_other_runs_are(self):
        self.assertEqual("deploy-${{ github.ref }}", workflow["concurrency"]["group"])
        self.assertEqual("${{ github.ref != 'refs/heads/main' }}", workflow["concurrency"]["cancel-in-progress"])
        for event, ref, _ in EVENTS:
            cancel = evaluate(workflow["concurrency"]["cancel-in-progress"], event, ref)
            self.assertIs(ref != "refs/heads/main", cancel, (event, ref))


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

    def test_the_digests_of_all_four_images_and_the_values_file_are_job_outputs(self):
        self.assertEqual(sorted(APPS) + ["values", "values_sha256"], sorted(jobs["images"]["outputs"]))

    def test_no_latest_tag(self):
        self.assertNotRegex(json.dumps(workflow), r"[:@]latest")


class Smoke(unittest.TestCase):
    def test_it_runs_after_the_images_on_a_deployment_and_never_pushes(self):
        self.assertEqual("images", jobs["smoke"]["needs"])
        condition = jobs["smoke"]["if"]
        self.assertIn("needs.images.result == 'success'", condition)
        self.assertIn("needs.images.result == 'skipped'", condition)
        self.assertNotIn("failure", condition)

    def test_on_a_deployment_it_uses_the_pushed_images_by_digest_otherwise_it_builds_them(self):
        pull = next(s for s in steps("smoke") if s.get("name") == "Pull the pushed images by digest")
        self.assertEqual("env.DEPLOY == 'true'", pull["if"])
        self.assertIn('"$IMAGE_PREFIX/$app@${!digest}"', pull["run"])
        for step in steps("smoke"):
            if uses(step, "docker/build-push-action"):
                self.assertEqual("env.DEPLOY != 'true'", step["if"])
                self.assertIs(True, step["with"]["load"])

    def test_the_images_are_loaded_into_the_daemon_that_smoke_sh_builds_on(self):
        """smoke.sh builds `FROM <registry>/api:r1` with a plain `docker build` (SMOKE_BREAK): the base must be in the
        local daemon and the builder in use must be the default one, a docker-container builder would look for the base
        in docker.io."""
        smoke = (REPO / "infra/k8s/scripts/smoke.sh").read_text()
        self.assertIn("FROM %s/api:r1", smoke)
        self.assertIn("FROM %s/store:r1", smoke)
        buildx = next(s for s in steps("smoke") if uses(s, "docker/setup-buildx-action"))
        self.assertIs(False, buildx["with"]["use"])
        builds = [s for s in steps("smoke") if uses(s, "docker/build-push-action")]
        self.assertEqual(4, len(builds))
        for step in builds:
            self.assertEqual("${{ steps.%s.outputs.name }}" % buildx["id"], step["with"]["builder"])
            self.assertIs(True, step["with"]["load"])
            self.assertNotIn("push", step["with"])

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


class ExistingImages(unittest.TestCase):
    def test_the_job_output_gets_the_digest_only_not_the_warnings_of_the_lookup(self):
        lookup = next(s for s in steps("images") if s.get("id") == "existing")["run"]
        self.assertNotIn("2>&1", lookup)
        self.assertIn('echo "$app=$digest" >> "$GITHUB_OUTPUT"', lookup)


def step_index(job, name):
    return [s.get("name") for s in steps(job)].index(name)


class ImagesValuesFile(unittest.TestCase):
    """B1 (spec, D4): the digests reach Argo CD in one file, generated once, that the smoke test installs and the snapshot
    on deploy-images contains."""

    def test_the_file_is_generated_once_in_the_images_job(self):
        scripts = {job: "\n".join(run_scripts(job)) for job in jobs}
        self.assertEqual(1, sum(script.count("images-values.sh") for script in scripts.values()))
        self.assertIn('sh infra/k8s/scripts/images-values.sh "$GITHUB_SHA"', scripts["images"])
        generate = next(s for s in steps("images") if s.get("id") == "values")
        self.assertEqual({f"DIGEST_{a.upper()}": "${{ steps.digests.outputs.%s }}" % a for a in APPS}, generate["env"])
        self.assertIn("sha256sum", generate["run"])
        self.assertEqual("${{ steps.values.outputs.values }}", jobs["images"]["outputs"]["values"])

    def test_smoke_and_deploy_write_the_same_bytes_and_check_the_checksum(self):
        for job in ("smoke", "deploy"):
            write = next(s for s in steps(job) if s.get("name") == "Write the images values file")
            self.assertEqual("${{ needs.images.outputs.values }}", write["env"]["IMAGES_VALUES"])
            self.assertEqual("${{ needs.images.outputs.values_sha256 }}", write["env"]["IMAGES_VALUES_SHA256"])
            self.assertIn("printf '%s\\n' \"$IMAGES_VALUES\"", write["run"])
            self.assertIn("sha256sum -c -", write["run"])
            # The content goes through the environment, never into the script text (injection).
            self.assertNotIn("${{", write["run"])

    def test_the_output_is_read_through_the_environment_everywhere(self):
        for job in jobs:
            for step in steps(job):
                if "run" in step:
                    self.assertNotIn("outputs.values", step["run"])


class SmokeValues(unittest.TestCase):
    def test_on_a_deployment_the_first_install_uses_the_values_file_and_the_registry(self):
        smoke = next(s for s in steps("smoke") if s.get("name") == "Smoke test")
        self.assertIn('if [ "$DEPLOY" = true ]; then', smoke["run"])
        self.assertIn('export SMOKE_VALUES="$RUNNER_TEMP/values-images.yaml"', smoke["run"])
        self.assertLess(smoke["run"].index("export SMOKE_VALUES"), smoke["run"].index("make -C infra/k8s smoke"))
        self.assertLess(step_index("smoke", "Write the images values file"), step_index("smoke", "Smoke test"))

    def test_the_token_is_read_only_for_packages_and_only_given_on_a_deployment(self):
        smoke = next(s for s in steps("smoke") if s.get("name") == "Smoke test")
        self.assertEqual("${{ env.DEPLOY == 'true' && secrets.GITHUB_TOKEN || '' }}", smoke["env"]["SMOKE_GHCR_TOKEN"])
        self.assertEqual("${{ env.DEPLOY == 'true' && github.actor || '' }}", smoke["env"]["SMOKE_GHCR_USER"])
        self.assertEqual("read", jobs["smoke"]["permissions"]["packages"])
        self.assertNotIn("SMOKE_GHCR_TOKEN", smoke["run"])
        holders = [(j, s.get("name")) for j in jobs for s in steps(j) if "SMOKE_GHCR_TOKEN" in json.dumps(s.get("env", {}))]
        self.assertEqual([("smoke", "Smoke test")], holders)

    def test_smoke_sh_uses_the_values_file_and_checks_the_digests_of_the_pods(self):
        smoke = (REPO / "infra/k8s/scripts/smoke.sh").read_text()
        self.assertIn("-f environments/ci/values.yaml -f /kube/values-images.yaml $(smoke_pull_args)", smoke)
        self.assertIn("check_registry_images", smoke)
        self.assertIn('--registry-config $k3d_dir/registries.yaml', smoke)
        self.assertIn('K3D_MOUNT="$k3d_dir:$k3d_dir:ro"', smoke)
        self.assertIn("umask 077", smoke)
        self.assertIn("unset SMOKE_GHCR_TOKEN", smoke)
        # The token is only ever written to the private file, never printed.
        self.assertNotRegex(smoke, r"echo[^\n]*SMOKE_GHCR_TOKEN")


    def test_the_kubelet_of_the_smoke_cluster_runs_the_imported_images_under_any_name(self):
        # On main the kubelet pulls the images from GHCR first and keeps a pull record per image ID that names only the
        # repository of GHCR; with the default policy the same image ID imported as shop-smoke-.../<app>:r2 is refused
        # under pullPolicy Never (ErrImageNeverPull). The cluster runs only images loaded into it, so nothing is verified.
        smoke = (REPO / "infra/k8s/scripts/smoke.sh").read_text()
        self.assertIn("imagePullCredentialsVerificationPolicy: NeverVerify", smoke)
        self.assertIn('-v "$k3d_dir/90-smoke-kubelet.conf:/var/lib/rancher/k3s/agent/etc/kubelet.conf.d/90-smoke-kubelet.conf@server:*"', smoke)
        self.assertIn('"imagePullCredentialsVerificationPolicy":"NeverVerify"', smoke)
        self.assertLess(smoke.index("check_kubelet_policy\n"), smoke.index('log "install r1"'))

class Deploy(unittest.TestCase):
    def test_it_needs_a_green_smoke_test_and_the_images(self):
        self.assertEqual(["images", "smoke"], jobs["deploy"]["needs"])
        self.assertNotIn("continue-on-error", jobs["smoke"])
        self.assertNotIn("continue-on-error", jobs["deploy"])

    def test_it_publishes_only_with_the_script_without_force(self):
        scripts = "\n".join(run_scripts())
        self.assertNotIn("git push", scripts)
        self.assertNotIn("--force", scripts)
        self.assertEqual(1, scripts.count("deploy-ref.sh"))
        self.assertIn(
            'sh .github/scripts/deploy-ref.sh "$GITHUB_SHA" "git@github.com:$GITHUB_REPOSITORY.git" "$RUNNER_TEMP/values-images.yaml"',
            scripts,
        )

    def test_the_script_pushes_both_branches_in_one_atomic_push(self):
        script = (REPO / ".github/scripts/deploy-ref.sh").read_text()
        pushes = [line for line in script.splitlines() if "git push" in line and not line.lstrip().startswith("#")]
        self.assertEqual(1, len(pushes), pushes)
        self.assertIn("--atomic", pushes[0])
        self.assertIn('"$sha:refs/heads/deploy" "$snapshot:refs/heads/deploy-images"', pushes[0])
        self.assertNotRegex(pushes[0], r"--force|\s\+")

    def test_the_render_of_prod_is_checked_before_anything_is_published(self):
        verify = next(s for s in steps("deploy") if s.get("name") == "Check the render of prod with the images values file")
        self.assertEqual('sh infra/k8s/scripts/verify-images.sh "$RUNNER_TEMP/values-images.yaml" "$GITHUB_SHA"', verify["run"])
        order = [s.get("name") for s in steps("deploy")]
        self.assertLess(order.index("Write the images values file"), order.index("Check the render of prod with the images values file"))
        self.assertLess(order.index("Check the render of prod with the images values file"), order.index("Publish deploy and deploy-images"))
        self.assertNotIn("if", verify)
        self.assertNotIn("continue-on-error", verify)

    def test_the_checkout_has_the_full_history_and_leaves_no_token(self):
        checkout = next(s for s in steps("deploy") if uses(s, "actions/checkout"))
        self.assertEqual(0, checkout["with"]["fetch-depth"])
        self.assertIs(False, checkout["with"]["persist-credentials"])

    def test_the_key_is_in_the_environment_of_one_step_only(self):
        holders = [(j, s.get("name")) for j in jobs for s in steps(j) if "DEPLOY_KEY" in json.dumps(s.get("env", {}))]
        self.assertEqual([("deploy", "Publish deploy and deploy-images")], holders)
        self.assertNotIn("DEPLOY_KEY", json.dumps({k: v for k, v in workflow.items() if k != "jobs"}))
        for job in jobs.values():
            self.assertNotIn("DEPLOY_KEY", json.dumps(job.get("env", {})))
        self.assertNotIn("set -x", "\n".join(run_scripts()))


class Retention(unittest.TestCase):
    """The branch deploy-images points at digests of images in GHCR, and a rollback means going back to an older
    snapshot: no workflow may delete versions of the packages (a retention policy would break the rollback)."""

    FORBIDDEN = (
        r"delete-package-versions",
        r"actions/delete-package",
        r"container-retention",
        r"snok/container-retention-policy",
        r"-X\s+DELETE",
        r"--method\s+DELETE",
        r"/packages/container/[^\s]*/versions",
        r"gh\s+api[^\n]*packages",
    )

    def test_no_workflow_deletes_package_versions(self):
        workflows = sorted((REPO / ".github/workflows").glob("*.yml"))
        self.assertGreaterEqual(len(workflows), 2)
        for path in workflows:
            text = path.read_text()
            for pattern in self.FORBIDDEN:
                self.assertIsNone(re.search(pattern, text, re.I), f"{path.name} matches {pattern}")

    def test_the_patterns_catch_the_usual_ways(self):
        for example in (
            "uses: actions/delete-package-versions@v5",
            "uses: snok/container-retention-policy@v3",
            "gh api -X DELETE /user/packages/container/shop%2Fapi/versions/1",
            "curl --method DELETE https://api.github.com/orgs/x/packages/container/shop%2Fapi/versions/2",
        ):
            self.assertTrue(any(re.search(p, example, re.I) for p in self.FORBIDDEN), example)


class Shell(unittest.TestCase):
    """GitHub runs a step without `shell:` as `bash -e {0}` (no pipefail): the status of `make smoke | tee log` would be
    that of `tee`, a red smoke test would give a green job and `deploy` would move the branch. `shell: bash` is
    `bash --noprofile --norc -eo pipefail {0}`."""

    def test_every_run_step_has_pipefail(self):
        self.assertEqual("bash", workflow.get("defaults", {}).get("run", {}).get("shell"))
        for name, job in jobs.items():
            self.assertNotIn("shell", job.get("defaults", {}).get("run", {}), name)
            for step in job["steps"]:
                self.assertNotIn("shell", step, f"{name}: {step.get('name')}")


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

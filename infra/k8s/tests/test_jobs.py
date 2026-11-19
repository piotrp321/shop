import json
import re
import unittest

from support import DIGEST, DIGESTS, JOBS, REPO, REVISION, by_logical, containers, env_of, logical, of_kind, pod_spec, wave


def job_names(case):
    return {logical(d): d["metadata"]["name"] for d in of_kind(case, "Job")}


def command_line(workload):
    container = containers(workload)[0]
    return " ".join(container.get("command", []) + container.get("args", []))


class JobNamesTest(unittest.TestCase):
    """D11: version jobs are named <job>-<checksum of the revision and of the configuration of the job>."""

    def test_names_carry_a_checksum(self):
        names = job_names("prod")
        self.assertEqual(set(JOBS), set(names))
        for job, full in names.items():
            self.assertRegex(full, rf"^{re.escape(job)}-[0-9a-f]{{8,10}}$")

    def test_same_state_same_names(self):
        # Two renders of the same values: nothing in the names depends on the time or on chance.
        self.assertEqual(job_names("prod"), job_names("prod-again"))

    def test_a_new_revision_gives_new_jobs(self):
        old, new = job_names("prod"), job_names("prod-rev2")
        for job in JOBS:
            self.assertNotEqual(old[job], new[job], job)
        for job, full in job_names("ci").items():
            self.assertNotEqual(full, job_names("ci-rev2")[job], job)

    def test_unrelated_values_keep_the_names(self):
        self.assertEqual(job_names("prod"), job_names("prod-indexable"))

    def test_a_configuration_of_the_job_changes_its_name(self):
        old, new = job_names("prod"), job_names("prod-domain")
        self.assertNotEqual(old["keycloak-config"], new["keycloak-config"])
        self.assertNotEqual(old["migrate"], new["migrate"])
        self.assertEqual(old["garage-init"], new["garage-init"])

    def test_version_jobs_are_labelled_so_that_the_smoke_test_can_tell_them_from_cron_runs(self):
        for case in ("prod", "ci"):
            for job in of_kind(case, "Job"):
                self.assertEqual("version", job["metadata"]["labels"]["shop/job-kind"], logical(job))
            for cron in of_kind(case, "CronJob"):
                self.assertNotIn("shop/job-kind", cron["spec"]["jobTemplate"].get("metadata", {}).get("labels", {}))

    def test_no_ttl_so_that_the_finished_job_is_kept(self):
        for case in ("prod", "ci"):
            for job in of_kind(case, "Job"):
                self.assertNotIn("ttlSecondsAfterFinished", job["spec"], logical(job))


class JobCommandsTest(unittest.TestCase):
    def test_migrate_migrates_then_sets_up_the_index(self):
        line = command_line(by_logical("prod", "Job")["migrate"])
        self.assertIn("doctrine:migrations:migrate --no-interaction", line)
        self.assertIn("catalog:search-index:setup", line)
        self.assertLess(line.index("doctrine:migrations:migrate"), line.index("catalog:search-index:setup"))

    def test_purge_caches(self):
        self.assertIn("storefront:cache:purge-all", command_line(by_logical("prod", "Job")["purge-caches"]))

    def test_backfill_runs_the_steps_after_the_rollout_with_a_deadline_of_its_own(self):
        """Migrations change the schema only; app:backfill fills the data once api and worker run the new version."""
        for case in ("prod", "ci"):
            jobs = by_logical(case, "Job")
            job = jobs["backfill"]
            self.assertEqual(["bin/console", "app:backfill"], containers(job)[0]["command"], case)
            self.assertNotIn("--force", command_line(job), case)
            self.assertEqual("prod", env_of(containers(job)[0])["APP_ENV"], case)
            self.assertGreater(wave(job), wave(by_logical(case, "Deployment")["worker"]), case)
            self.assertEqual(21600, job["spec"]["activeDeadlineSeconds"], case)
            self.assertEqual(1800, jobs["purge-caches"]["spec"]["activeDeadlineSeconds"], case)

    def test_initialize_runs_the_script_in_prod_mode(self):
        job = by_logical("prod", "Job")["initialize"]
        self.assertIn("initialize.sh", command_line(job))
        self.assertEqual("prod", env_of(containers(job)[0])["APP_ENV"])

    def test_garage_init_runs_the_script_of_compose_with_the_binary_of_the_garage_image(self):
        job = by_logical("prod", "Job")["garage-init"]
        spec = pod_spec(job)
        self.assertIn("init.sh", command_line(job))
        self.assertTrue(containers(job)[0]["image"].startswith("alpine:"))
        images = [v["image"]["reference"] for v in spec["volumes"] if "image" in v]
        self.assertEqual(1, len(images))
        self.assertRegex(images[0], r"^dxflrs/garage:v2\.4\.0" + DIGEST + "$")

    def test_jobs_are_not_retried_forever(self):
        for job in of_kind("prod", "Job"):
            self.assertIn("backoffLimit", job["spec"], logical(job))
            self.assertIn(job["spec"]["template"]["spec"]["restartPolicy"], ("Never", "OnFailure"))


class CronJobTest(unittest.TestCase):
    def test_cart_purge(self):
        for case in ("prod-backup", "ci"):
            crons = by_logical(case, "CronJob")
            expected = {"cart-purge-expired", "rate-limit-check", "backup", "backup-verify", "restore"}
            # demo-data (tests/test_demo_data.py) is in ci and not in prod.
            self.assertEqual(expected | {"demo-data"} if case == "ci" else expected, set(crons), case)
            cron = crons["cart-purge-expired"]
            self.assertEqual("0 * * * *", cron["spec"]["schedule"])
            self.assertEqual("Forbid", cron["spec"]["concurrencyPolicy"])
            self.assertEqual("Europe/Warsaw", cron["spec"]["timeZone"])
            self.assertIn("cart:purge-expired", command_line(cron))
            self.assertEqual(1, wave(cron))
            self.assertIn(f":{REVISION}", containers(cron)[0]["image"])
        self.assertTrue(containers(by_logical("prod", "CronJob")["cart-purge-expired"])[0]["image"].endswith(f":{REVISION}@{DIGESTS['api']}"))


class RateLimitCheckTest(unittest.TestCase):
    """L12, D16 (docs/features/abuse-limits/spec.md): the CronJob that checks the Redis of the rate limiters, whose success the alert
    RateLimitsOff counts."""

    CASES = ("prod", "prod-backup", "prod-nobackup", "prod-noinit", "ci", "load")

    def cron(self, case):
        return by_logical(case, "CronJob")["rate-limit-check"]

    def test_it_is_in_every_profile_and_runs_the_command_every_five_minutes(self):
        for case in self.CASES:
            cron = self.cron(case)
            self.assertEqual("*/5 * * * *", cron["spec"]["schedule"], case)
            self.assertIn("bin/console rate-limit:check", command_line(cron), case)
            self.assertEqual(1, wave(cron), case)

    def test_it_is_never_suspended_in_prod(self):
        for case in ("prod", "prod-backup", "prod-nobackup"):
            self.assertIn(self.cron(case)["spec"].get("suspend", False), (False,), case)

    def test_it_has_the_limits_of_a_check_that_is_not_retried(self):
        for case in self.CASES:
            spec = self.cron(case)["spec"]
            self.assertEqual("Forbid", spec["concurrencyPolicy"], case)
            self.assertEqual(240, spec["startingDeadlineSeconds"], case)
            self.assertEqual(1, spec["successfulJobsHistoryLimit"], case)
            self.assertEqual(3, spec["failedJobsHistoryLimit"], case)
            self.assertEqual(0, spec["jobTemplate"]["spec"]["backoffLimit"], case)
            self.assertEqual(60, spec["jobTemplate"]["spec"]["activeDeadlineSeconds"], case)
            self.assertEqual("Never", pod_spec(self.cron(case))["restartPolicy"], case)

    def test_it_runs_the_image_and_the_environment_of_the_api_with_the_resources_of_a_job(self):
        for case in self.CASES:
            # The other CronJob of the API image has the environment of shop.apiEnv, the Deployment adds its own variables to it.
            container, api = containers(self.cron(case))[0], containers(by_logical(case, "Deployment")["api"])[0]
            purge = containers(by_logical(case, "CronJob")["cart-purge-expired"])[0]
            self.assertEqual(api["image"], container["image"], case)
            self.assertEqual(purge["env"], container["env"], case)
            self.assertEqual(purge["envFrom"], container["envFrom"], case)
            self.assertEqual(purge["resources"], container["resources"], case)
            self.assertEqual(env_of(container), {k: v for k, v in env_of(api).items() if k in env_of(container)}, case)
        self.assertTrue(containers(self.cron("prod"))[0]["image"].endswith(f":{REVISION}@{DIGESTS['api']}"))

    def test_the_schedule_is_a_value_of_the_chart(self):
        values = (REPO / "infra/k8s/charts/shop/values.yaml").read_text()
        self.assertRegex(values, r'(?m)^rateLimitCheck:\n(?:\s*#.*\n)*  schedule: "\*/5 \* \* \* \*"$')
        schema = json.loads((REPO / "infra/k8s/charts/shop/values.schema.json").read_text())
        self.assertIn("rateLimitCheck", schema["required"])
        self.assertEqual(["schedule"], schema["properties"]["rateLimitCheck"]["required"])
        template = (REPO / "infra/k8s/charts/shop/templates/rate-limit-check.yaml").read_text()
        self.assertIn(".Values.rateLimitCheck.schedule", template)
        self.assertNotIn("*/5", template, "the schedule is not typed into the template")


if __name__ == "__main__":
    unittest.main()

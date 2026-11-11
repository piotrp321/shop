import re
import unittest

from support import DIGEST, DIGESTS, JOBS, REVISION, by_logical, containers, env_of, logical, of_kind, pod_spec, wave


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
        for case in ("prod", "ci"):
            crons = by_logical(case, "CronJob")
            self.assertEqual({"cart-purge-expired", "backup"}, set(crons), case)
            cron = crons["cart-purge-expired"]
            self.assertEqual("0 * * * *", cron["spec"]["schedule"])
            self.assertEqual("Forbid", cron["spec"]["concurrencyPolicy"])
            self.assertEqual("Europe/Warsaw", cron["spec"]["timeZone"])
            self.assertIn("cart:purge-expired", command_line(cron))
            self.assertEqual(1, wave(cron))
            self.assertIn(f":{REVISION}", containers(cron)[0]["image"])
        self.assertTrue(containers(by_logical("prod", "CronJob")["cart-purge-expired"])[0]["image"].endswith(f":{REVISION}@{DIGESTS['api']}"))


if __name__ == "__main__":
    unittest.main()

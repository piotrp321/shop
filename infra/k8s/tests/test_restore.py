"""The restore in the render (docs/features/deploy-ops/spec.md, O4): the suspended CronJob `restore`, whose pod is the template of
the Jobs that make -C infra/k8s restore and backups create (the modes list, check, restore and reindex), and what each of its
containers may read."""

import unittest

from support import DIGEST, DIGESTS, REVISION, by_logical, containers, env_of, name, of_kind, pod_spec, wave

TOKEN_PATH = "/var/run/secrets/kubernetes.io/serviceaccount"
SECRET = "shop-backup"
# The stages in the order the pod runs them, the image of each and the argument of the script.
STAGES = (
    ("migrations", "ghcr.io/piotrp321/shop/api:", "migrations"),
    ("restic", "restic/restic:", "restic"),
    ("databases", "postgres:", "databases"),
    ("objects", "rclone/rclone:", "objects"),
    ("redis", "redis:", "redis"),
    ("rabbitmq", "rabbitmq:", "rabbitmq"),
    ("api", "ghcr.io/piotrp321/shop/api:", "api"),
)


def restore(case="prod"):
    return by_logical(case, "CronJob")["restore"]


def all_containers(case="prod"):
    spec = pod_spec(restore(case))
    return spec.get("initContainers", []) + spec["containers"]


def container(name_, case="prod"):
    return next(c for c in all_containers(case) if c["name"] == name_)


def secret_refs(container_):
    found = []
    for variable in container_.get("env", []):
        ref = variable.get("valueFrom", {}).get("secretKeyRef")
        if ref:
            found.append((variable["name"], ref["name"], ref["key"]))
    for source in container_.get("envFrom", []):
        if "secretRef" in source:
            found.append(("*", source["secretRef"]["name"], "*"))
    return found


def mounts(container_):
    return {m["mountPath"]: m["name"] for m in container_.get("volumeMounts", [])}


class CronJobTest(unittest.TestCase):
    def test_it_is_suspended_so_that_it_never_runs_by_itself(self):
        for case in ("prod", "ci"):
            spec = restore(case)["spec"]
            self.assertIs(True, spec["suspend"], case)
            self.assertEqual("Forbid", spec["concurrencyPolicy"], case)
            self.assertNotIn("startingDeadlineSeconds", spec, case)

    def test_a_job_runs_at_most_three_hours_and_is_never_retried(self):
        for case in ("prod", "ci"):
            job = restore(case)["spec"]["jobTemplate"]["spec"]
            self.assertEqual(10800, job["activeDeadlineSeconds"], case)
            self.assertEqual(0, job["backoffLimit"], case)
            self.assertEqual("Never", job["template"]["spec"]["restartPolicy"], case)

    def test_without_backup_enabled_it_is_not_rendered(self):
        self.assertNotIn("restore", by_logical("prod-nobackup", "CronJob"))

    def test_it_is_in_the_wave_of_the_backup(self):
        self.assertEqual(wave(by_logical("prod", "CronJob")["backup"]), wave(restore()))

    def test_every_container_has_resources(self):
        for case in ("prod", "ci"):
            for container_ in all_containers(case):
                self.assertIn("resources", container_, f"{case} {container_['name']}")


class StagesTest(unittest.TestCase):
    def test_the_stages_run_in_order_in_the_containers_of_their_tools(self):
        spec = pod_spec(restore())
        self.assertEqual([stage for stage, _, _ in STAGES[:-1]], [c["name"] for c in spec["initContainers"]])
        self.assertEqual(["api"], [c["name"] for c in spec["containers"]])
        for stage, image, argument in STAGES:
            self.assertTrue(container(stage)["image"].startswith(image), stage)
            self.assertEqual(["sh", "/scripts/restore-job.sh", argument], container(stage)["command"], stage)

    def test_every_container_starts_in_the_mode_list_and_without_a_snapshot(self):
        """make restore sets the mode and the snapshot of a Job with kubectl set env; the template alone only lists."""
        for case in ("prod", "ci"):
            for container_ in all_containers(case):
                env = env_of(container_)
                self.assertEqual("list", env["RESTORE_MODE"], f"{case} {container_['name']}")
                self.assertEqual("", env["RESTORE_SNAPSHOT"], f"{case} {container_['name']}")

    def test_every_image_has_a_digest_and_the_api_is_the_one_of_the_revision(self):
        for case in ("prod", "ci"):
            for container_ in all_containers(case):
                if case == "prod":
                    self.assertRegex(container_["image"], DIGEST + "$", f"{case} {container_['name']}")
        for stage in ("migrations", "api"):
            self.assertEqual(f"ghcr.io/piotrp321/shop/api:{REVISION}@{DIGESTS['api']}", container(stage)["image"])
            self.assertEqual(f"shop-smoke/api:{REVISION}", container(stage, "ci")["image"])

    def test_the_tools_are_the_images_of_the_services(self):
        statefulsets = by_logical("prod", "StatefulSet")
        for stage, service in (("databases", "postgres"), ("redis", "redis"), ("rabbitmq", "rabbitmq")):
            self.assertEqual(containers(statefulsets[service])[0]["image"], container(stage)["image"], stage)
        backup = pod_spec(by_logical("prod", "CronJob")["backup"])
        backup_images = {c["name"]: c["image"] for c in backup["initContainers"] + backup["containers"]}
        self.assertEqual(backup_images["backup"], container("restic")["image"])
        self.assertEqual(backup_images["objects"], container("objects")["image"])

    def test_the_script_comes_from_the_scripts_of_the_chart(self):
        volumes = {v["name"]: v for v in pod_spec(restore())["volumes"]}
        self.assertEqual("shop-scripts", volumes["scripts"]["configMap"]["name"])
        (config,) = [d for d in of_kind("prod", "ConfigMap") if name(d) == "shop-scripts"]
        self.assertIn("restore-job.sh", config["data"])

    def test_the_restore_works_on_the_volume_of_the_backup(self):
        for case in ("prod", "ci"):
            volumes = {v["name"]: v for v in pod_spec(restore(case))["volumes"]}
            self.assertEqual("backup-work", volumes["work"]["persistentVolumeClaim"]["claimName"], case)
            self.assertEqual({}, volumes["meta"]["emptyDir"], case)
            on_work = {c["name"] for c in all_containers(case) if mounts(c).get("/work") == "work"}
            self.assertEqual({"restic", "databases", "objects"}, on_work, case)
            on_meta = {c["name"] for c in all_containers(case) if mounts(c).get("/meta") == "meta"}
            self.assertEqual({"migrations", "restic"}, on_meta, case)

    def test_the_cache_of_restic_is_not_on_the_volume_that_the_restore_replaces(self):
        """restic restore --delete makes /work the snapshot: a cache there would be deleted under restic."""
        self.assertEqual("/meta/restic-cache", env_of(container("restic"))["RESTIC_CACHE_DIR"])

    def test_the_services_are_those_of_the_shop(self):
        self.assertEqual("postgres", env_of(container("databases"))["PGHOST"])
        self.assertEqual("postgres", env_of(container("databases"))["PGUSER"])
        self.assertEqual("http://garage:3900", env_of(container("objects"))["RCLONE_CONFIG_GARAGE_ENDPOINT"])
        self.assertEqual("garage", env_of(container("objects"))["RCLONE_CONFIG_GARAGE_REGION"])
        self.assertEqual("redis", env_of(container("redis"))["REDIS_HOST"])
        rabbitmq = env_of(container("rabbitmq"))
        self.assertEqual(("rabbitmq", "15672", "shop", "/"), (rabbitmq["RABBITMQADMIN_TARGET_HOST"], rabbitmq["RABBITMQADMIN_TARGET_PORT"],
                                                            rabbitmq["RABBITMQADMIN_USERNAME"], rabbitmq["RABBITMQADMIN_TARGET_VHOST"]))
        self.assertEqual(env_of(containers(by_logical("prod", "Deployment")["api"])[0])["ELASTICSEARCH_URL"], env_of(container("api"))["ELASTICSEARCH_URL"])


class AccessTest(unittest.TestCase):
    def test_no_container_has_a_token_of_the_api(self):
        for case in ("prod", "ci"):
            spec = pod_spec(restore(case))
            self.assertIs(False, spec["automountServiceAccountToken"], case)
            self.assertNotIn("serviceAccountName", spec, case)
            for container_ in all_containers(case):
                self.assertNotIn(TOKEN_PATH, mounts(container_), f"{case} {container_['name']}")
            self.assertNotIn("kube-api-access", [v["name"] for v in spec["volumes"]], case)

    def test_only_the_restic_container_reads_the_secret_of_the_backup_and_always_with_the_repository(self):
        for case in ("prod", "ci"):
            for container_ in all_containers(case):
                uses = any(secret == SECRET for _, secret, _ in secret_refs(container_))
                self.assertEqual(container_["name"] == "restic", uses, f"{case} {container_['name']}")
            restic = container("restic", case)
            self.assertIn(("RESTIC_PASSWORD", SECRET, "RESTIC_PASSWORD"), secret_refs(restic), case)
            self.assertIn("RESTIC_REPOSITORY", env_of(restic), case)

    def test_each_tool_reads_only_what_it_needs_of_the_secret_of_the_shop(self):
        by_container = {c["name"]: secret_refs(c) for c in all_containers()}
        self.assertEqual([], by_container["migrations"])
        self.assertEqual([("PGPASSWORD", "shop-secrets", "POSTGRES_PASSWORD")], by_container["databases"])
        self.assertEqual(
            [
                ("RCLONE_CONFIG_GARAGE_ACCESS_KEY_ID", "shop-secrets", "GARAGE_ACCESS_KEY_ID"),
                ("RCLONE_CONFIG_GARAGE_SECRET_ACCESS_KEY", "shop-secrets", "GARAGE_SECRET_ACCESS_KEY"),
            ],
            by_container["objects"],
        )
        self.assertEqual([], by_container["redis"])
        self.assertEqual([("RABBITMQADMIN_PASSWORD", "shop-secrets", "RABBITMQ_DEFAULT_PASS")], by_container["rabbitmq"])
        # The reindex is the command of the API, with the environment of the API.
        self.assertEqual([("*", "shop-secrets", "*")], by_container["api"])


if __name__ == "__main__":
    unittest.main()

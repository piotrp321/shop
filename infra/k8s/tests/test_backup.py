"""The nightly backup in the render (docs/features/deploy-ops/spec.md, O1 and O2): the CronJob `backup`, its volume, its
identity in the cluster and what each of its containers may read."""

import unittest

from support import DIGEST, by_logical, containers, env_of, logical, name, of_kind, pod_spec, wave

TOKEN_PATH = "/var/run/secrets/kubernetes.io/serviceaccount"
SECRET = "shop-backup"


def backup(case="prod"):
    return by_logical(case, "CronJob")["backup"]


def all_containers(case="prod"):
    spec = pod_spec(backup(case))
    return spec.get("initContainers", []) + spec["containers"]


def secret_refs(container):
    """(env name, secret, key) of every variable of the container that comes from a secret."""
    found = []
    for variable in container.get("env", []):
        ref = variable.get("valueFrom", {}).get("secretKeyRef")
        if ref:
            found.append((variable["name"], ref["name"], ref["key"]))
    for source in container.get("envFrom", []):
        if "secretRef" in source:
            found.append(("*", source["secretRef"]["name"], "*"))
    return found


class CronJobTest(unittest.TestCase):
    def test_it_runs_every_night_at_three_in_warsaw_and_never_twice_at_once(self):
        for case in ("prod", "ci"):
            spec = backup(case)["spec"]
            self.assertEqual("0 3 * * *", spec["schedule"], case)
            self.assertEqual("Europe/Warsaw", spec["timeZone"], case)
            self.assertEqual("Forbid", spec["concurrencyPolicy"], case)
            self.assertEqual(3600, spec["startingDeadlineSeconds"], case)
            self.assertEqual(1, spec["successfulJobsHistoryLimit"], case)
            self.assertEqual(1, spec["failedJobsHistoryLimit"], case)
            self.assertNotIn("suspend", spec, case)

    def test_a_job_is_retried_once_and_stopped_after_two_hours(self):
        for case in ("prod", "ci"):
            job = backup(case)["spec"]["jobTemplate"]["spec"]
            self.assertEqual(1, job["backoffLimit"], case)
            self.assertEqual(7200, job["activeDeadlineSeconds"], case)
            self.assertEqual("Never", job["template"]["spec"]["restartPolicy"], case)

    def test_the_stages_run_in_order_in_the_containers_of_their_tools(self):
        spec = pod_spec(backup())
        self.assertEqual(["cluster-id", "dump", "objects"], [c["name"] for c in spec["initContainers"]])
        self.assertEqual(["backup"], [c["name"] for c in spec["containers"]])
        by_name = {c["name"]: c for c in all_containers()}
        for container, stage, image in (("dump", "dump", "postgres:"), ("objects", "objects", "rclone/rclone:"), ("backup", "snapshot", "restic/restic:")):
            self.assertTrue(by_name[container]["image"].startswith(image), container)
            self.assertEqual(["sh", "/scripts/backup.sh", stage], by_name[container]["command"], container)

    def test_every_image_has_a_digest_and_none_is_ours(self):
        for case in ("prod", "ci"):
            for container in all_containers(case):
                self.assertRegex(container["image"], DIGEST + "$", f"{case} {container['name']}")
                self.assertNotIn("piotrp321", container["image"])

    def test_the_postgres_image_is_the_one_of_the_server(self):
        from_chart = containers(by_logical("prod", "StatefulSet")["postgres"])[0]["image"]
        dump = next(c for c in all_containers() if c["name"] == "dump")
        self.assertEqual(from_chart, dump["image"])

    def test_the_script_comes_from_the_scripts_of_the_chart(self):
        volumes = {v["name"]: v for v in pod_spec(backup())["volumes"]}
        self.assertEqual("shop-scripts", volumes["scripts"]["configMap"]["name"])
        configs = [d for d in of_kind("prod", "ConfigMap") if name(d) == "shop-scripts"]
        self.assertIn("backup.sh", configs[0]["data"])

    def test_the_revision_and_the_retention_are_passed_to_the_script(self):
        snapshot = pod_spec(backup())["containers"][0]
        self.assertEqual("0123456789abcdef0123456789abcdef01234567", env_of(snapshot)["REVISION"])
        self.assertEqual("7d", env_of(snapshot)["BACKUP_RETENTION"])

    def test_every_container_has_resources(self):
        for case in ("prod", "ci"):
            for container in all_containers(case):
                self.assertIn("resources", container, f"{case} {container['name']}")

    def test_it_is_in_the_wave_of_the_other_cron_job(self):
        self.assertEqual(wave(by_logical("prod", "CronJob")["cart-purge-expired"]), wave(backup()))

    def test_without_backup_enabled_nothing_of_it_is_rendered(self):
        for doc in of_kind("prod-nobackup", "CronJob", "PersistentVolumeClaim", "ServiceAccount", "ClusterRole", "ClusterRoleBinding", "Secret"):
            self.assertNotIn("backup", name(doc))
        self.assertEqual(["cart-purge-expired"], [logical(d) for d in of_kind("prod-nobackup", "CronJob")])


class TokenTest(unittest.TestCase):
    def test_the_pod_has_no_token_but_the_container_that_reads_the_id_of_the_cluster(self):
        for case in ("prod", "ci"):
            spec = pod_spec(backup(case))
            self.assertIs(False, spec["automountServiceAccountToken"], case)
            self.assertEqual("shop-backup", spec["serviceAccountName"], case)
            mounting = [c["name"] for c in all_containers(case) if TOKEN_PATH in [m["mountPath"] for m in c.get("volumeMounts", [])]]
            self.assertEqual(["cluster-id"], mounting, case)

    def test_no_container_with_the_secret_of_the_backup_has_the_token(self):
        for case in ("prod", "ci"):
            for container in all_containers(case):
                with_token = TOKEN_PATH in [m["mountPath"] for m in container.get("volumeMounts", [])]
                with_secret = any(secret == SECRET for _, secret, _ in secret_refs(container))
                self.assertFalse(with_token and with_secret, f"{case} {container['name']}")

    def test_the_container_with_the_token_has_no_secret_at_all(self):
        cluster_id = next(c for c in all_containers() if c["name"] == "cluster-id")
        self.assertEqual([], secret_refs(cluster_id))

    def test_the_id_is_the_uid_of_kube_system_written_to_a_volume_the_others_read(self):
        cluster_id = next(c for c in all_containers() if c["name"] == "cluster-id")
        line = " ".join(cluster_id["command"] + cluster_id.get("args", []))
        self.assertIn("get namespace kube-system", line)
        self.assertIn("{.metadata.uid}", line)
        self.assertTrue(cluster_id["image"].startswith("registry.k8s.io/kubectl:"))
        volumes = {v["name"]: v for v in pod_spec(backup())["volumes"]}
        self.assertEqual({}, volumes["cluster"]["emptyDir"])
        mounted = {c["name"] for c in all_containers() if "cluster" in [m["name"] for m in c.get("volumeMounts", [])]}
        self.assertEqual({"cluster-id", "backup"}, mounted)

    def test_the_service_account_may_only_get_the_namespace_kube_system(self):
        roles = [d for d in of_kind("prod", "ClusterRole") if "backup" in name(d)]
        self.assertEqual(1, len(roles))
        self.assertEqual([{"apiGroups": [""], "resources": ["namespaces"], "resourceNames": ["kube-system"], "verbs": ["get"]}], roles[0]["rules"])
        (binding,) = [d for d in of_kind("prod", "ClusterRoleBinding") if "backup" in name(d)]
        self.assertEqual(name(roles[0]), binding["roleRef"]["name"])
        self.assertEqual("ClusterRole", binding["roleRef"]["kind"])
        self.assertEqual([{"kind": "ServiceAccount", "name": "shop-backup", "namespace": "shop"}], binding["subjects"])
        self.assertIn("shop-backup", [name(d) for d in of_kind("prod", "ServiceAccount")])


class SecretTest(unittest.TestCase):
    def test_the_repository_always_comes_with_its_password(self):
        for case in ("prod", "ci"):
            restic = [c for c in all_containers(case) if "RESTIC_REPOSITORY" in env_of(c)]
            self.assertTrue(restic, case)
            for container in restic:
                self.assertIn(("RESTIC_PASSWORD", SECRET, "RESTIC_PASSWORD"), secret_refs(container), f"{case} {container['name']}")

    def test_restic_reads_the_keys_of_the_repository_from_the_secret_of_the_backup(self):
        container = pod_spec(backup())["containers"][0]
        refs = secret_refs(container)
        self.assertIn(("AWS_ACCESS_KEY_ID", SECRET, "BACKUP_S3_ACCESS_KEY_ID"), refs)
        self.assertIn(("AWS_SECRET_ACCESS_KEY", SECRET, "BACKUP_S3_SECRET_ACCESS_KEY"), refs)
        env = env_of(container)
        self.assertTrue(env["RESTIC_REPOSITORY"].startswith("s3:https://"))
        self.assertEqual("auto", env["AWS_DEFAULT_REGION"])
        self.assertEqual("/work/restic-cache", env["RESTIC_CACHE_DIR"])

    def test_only_the_restic_container_reads_the_secret_of_the_backup(self):
        for container in all_containers():
            uses = any(secret == SECRET for _, secret, _ in secret_refs(container))
            self.assertEqual(container["name"] == "backup", uses, container["name"])

    def test_the_other_containers_read_only_what_their_tool_needs_of_the_secret_of_the_shop(self):
        by_container = {c["name"]: secret_refs(c) for c in all_containers()}
        self.assertEqual([("PGPASSWORD", "shop-secrets", "POSTGRES_PASSWORD")], by_container["dump"])
        self.assertEqual(
            [
                ("RCLONE_CONFIG_GARAGE_ACCESS_KEY_ID", "shop-secrets", "GARAGE_ACCESS_KEY_ID"),
                ("RCLONE_CONFIG_GARAGE_SECRET_ACCESS_KEY", "shop-secrets", "GARAGE_SECRET_ACCESS_KEY"),
            ],
            by_container["objects"],
        )
        self.assertEqual("garage", env_of(next(c for c in all_containers() if c["name"] == "objects"))["RCLONE_CONFIG_GARAGE_REGION"])

    def test_the_secret_of_prod_is_created_outside_the_chart_and_that_of_ci_by_it(self):
        self.assertEqual([], [d for d in of_kind("prod", "Secret") if name(d) == SECRET])
        (secret,) = [d for d in of_kind("ci", "Secret") if name(d) == SECRET]
        self.assertEqual({"RESTIC_PASSWORD", "BACKUP_S3_ACCESS_KEY_ID", "BACKUP_S3_SECRET_ACCESS_KEY"}, set(secret["stringData"]))
        for key, value in secret["stringData"].items():
            self.assertTrue(value, key)

    def test_the_secret_name_is_the_value_of_the_chart(self):
        for case in ("prod", "ci"):
            names = {secret for c in all_containers(case) for _, secret, _ in secret_refs(c)}
            self.assertIn(SECRET, names)


class VolumeTest(unittest.TestCase):
    def test_the_work_volume_is_a_claim_on_local_path_that_the_three_stages_share(self):
        for case in ("prod", "ci"):
            (claim,) = [d for d in of_kind(case, "PersistentVolumeClaim") if name(d) == "backup-work"]
            self.assertEqual("local-path", claim["spec"]["storageClassName"])
            self.assertEqual(["ReadWriteOnce"], claim["spec"]["accessModes"])
            self.assertEqual("25Gi", claim["spec"]["resources"]["requests"]["storage"])
            volumes = {v["name"]: v for v in pod_spec(backup(case))["volumes"]}
            self.assertEqual("backup-work", volumes["work"]["persistentVolumeClaim"]["claimName"])
            mounted = {c["name"] for c in all_containers(case) if {"name": "work", "mountPath": "/work"} in c.get("volumeMounts", [])}
            self.assertEqual({"dump", "objects", "backup"}, mounted, case)

    def test_the_work_volume_is_bigger_than_the_data_of_the_images(self):
        from support import quantity_bytes

        (claim,) = [d for d in of_kind("prod", "PersistentVolumeClaim") if name(d) == "backup-work"]
        garage = by_logical("prod", "StatefulSet")["garage"]["spec"]["volumeClaimTemplates"]
        data = next(c for c in garage if c["metadata"]["name"] == "data")
        self.assertGreater(quantity_bytes(claim["spec"]["resources"]["requests"]["storage"]), quantity_bytes(data["spec"]["resources"]["requests"]["storage"]))


if __name__ == "__main__":
    unittest.main()

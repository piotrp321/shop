"""The daily verification of the backups in the render (docs/features/deploy-ops/spec.md, O3): the CronJob `backup-verify`,
the temporary Postgres in its pod and what each of its containers may read."""

import unittest

from support import DIGEST, by_logical, containers, env_of, name, of_kind, pod_spec, wave

TOKEN_PATH = "/var/run/secrets/kubernetes.io/serviceaccount"
SECRET = "shop-backup"


def verify(case="prod"):
    return by_logical(case, "CronJob")["backup-verify"]


def all_containers(case="prod"):
    spec = pod_spec(verify(case))
    return spec.get("initContainers", []) + spec["containers"]


def secret_refs(container):
    found = []
    for variable in container.get("env", []):
        ref = variable.get("valueFrom", {}).get("secretKeyRef")
        if ref:
            found.append((variable["name"], ref["name"], ref["key"]))
    for source in container.get("envFrom", []):
        if "secretRef" in source:
            found.append(("*", source["secretRef"]["name"], "*"))
    return found


def mounts(container):
    return {m["mountPath"]: m["name"] for m in container.get("volumeMounts", [])}


class CronJobTest(unittest.TestCase):
    def test_it_runs_every_morning_at_five_in_warsaw_and_never_twice_at_once(self):
        for case in ("prod", "ci"):
            spec = verify(case)["spec"]
            self.assertEqual("0 5 * * *", spec["schedule"], case)
            self.assertEqual("Europe/Warsaw", spec["timeZone"], case)
            self.assertEqual("Forbid", spec["concurrencyPolicy"], case)
            self.assertEqual(3600, spec["startingDeadlineSeconds"], case)
            self.assertEqual(1, spec["successfulJobsHistoryLimit"], case)
            self.assertEqual(1, spec["failedJobsHistoryLimit"], case)
            self.assertNotIn("suspend", spec, case)

    def test_it_has_the_limits_of_time_and_of_retries_of_the_backup(self):
        for case in ("prod", "ci"):
            job = verify(case)["spec"]["jobTemplate"]["spec"]
            backup_job = by_logical(case, "CronJob")["backup"]["spec"]["jobTemplate"]["spec"]
            self.assertEqual(1, job["backoffLimit"], case)
            self.assertEqual(7200, job["activeDeadlineSeconds"], case)
            self.assertEqual(backup_job["backoffLimit"], job["backoffLimit"])
            self.assertEqual(backup_job["activeDeadlineSeconds"], job["activeDeadlineSeconds"])
            self.assertEqual("Never", job["template"]["spec"]["restartPolicy"], case)

    def test_the_schedule_comes_from_the_values(self):
        self.assertEqual("30 6 * * 1", verify("prod-verify-schedule")["spec"]["schedule"])
        self.assertEqual("0 3 * * *", by_logical("prod-verify-schedule", "CronJob")["backup"]["spec"]["schedule"])

    def test_it_is_in_the_wave_of_the_backup(self):
        self.assertEqual(wave(by_logical("prod", "CronJob")["backup"]), wave(verify()))

    def test_without_backup_enabled_it_is_not_rendered(self):
        self.assertNotIn("backup-verify", by_logical("prod-nobackup", "CronJob"))

    def test_every_container_has_resources(self):
        for case in ("prod", "ci"):
            for container in all_containers(case):
                self.assertIn("resources", container, f"{case} {container['name']}")


class PodTest(unittest.TestCase):
    def test_a_temporary_postgres_runs_beside_the_container_that_verifies(self):
        spec = pod_spec(verify())
        self.assertEqual(["cluster-id", "postgres"], [c["name"] for c in spec["initContainers"]])
        self.assertEqual(["verify"], [c["name"] for c in spec["containers"]])

    def test_the_postgres_is_a_sidecar_that_the_end_of_the_verification_stops(self):
        """A regular second container would keep the Job running after the script; a sidecar of the init containers is stopped
        when the containers are done."""
        postgres = next(c for c in pod_spec(verify())["initContainers"] if c["name"] == "postgres")
        self.assertEqual("Always", postgres["restartPolicy"])
        self.assertIn("startupProbe", postgres, "the script must not start before the server accepts connections")
        self.assertEqual("Never", pod_spec(verify())["restartPolicy"])

    def test_the_postgres_keeps_its_data_on_an_empty_dir_and_listens_on_the_loopback_only(self):
        spec = pod_spec(verify())
        postgres = next(c for c in spec["initContainers"] if c["name"] == "postgres")
        volumes = {v["name"]: v for v in spec["volumes"]}
        self.assertEqual("data", mounts(postgres)["/var/lib/postgresql"])
        self.assertIn("emptyDir", volumes["data"])
        line = " ".join(postgres.get("command", []) + postgres.get("args", []))
        self.assertIn("listen_addresses=127.0.0.1", line)
        self.assertNotIn("persistentVolumeClaim", [k for v in spec["volumes"] for k in v])

    def test_nothing_of_the_production_database_is_reachable(self):
        for case in ("prod", "ci"):
            spec = pod_spec(verify(case))
            for container in all_containers(case):
                label = f"{case} {container['name']}"
                self.assertNotIn("shop-secrets", [secret for _, secret, _ in secret_refs(container)], label)
                self.assertNotIn("PGPASSWORD", env_of(container), label)
                self.assertNotIn("POSTGRES_PASSWORD", env_of(container), label)
                self.assertNotEqual("postgres", env_of(container).get("PGHOST"), label)
                self.assertNotIn("backup-work", mounts(container).values(), label)
            claims = [v["persistentVolumeClaim"]["claimName"] for v in spec["volumes"] if "persistentVolumeClaim" in v]
            self.assertEqual([], claims, case)
            self.assertEqual("127.0.0.1", env_of(spec["containers"][0])["PGHOST"], case)

    def test_the_images_have_a_digest_and_the_postgres_is_the_one_of_the_server(self):
        from_chart = containers(by_logical("prod", "StatefulSet")["postgres"])[0]["image"]
        for case in ("prod", "ci"):
            for container in all_containers(case):
                if container["name"] in ("postgres", "verify"):
                    self.assertEqual(from_chart, container["image"], f"{case} {container['name']}")
                self.assertRegex(container["image"], DIGEST + "$", f"{case} {container['name']}")
                self.assertNotIn("piotrp321", container["image"])

    def test_restic_comes_from_the_image_of_restic_mounted_as_a_volume(self):
        for case in ("prod", "ci"):
            spec = pod_spec(verify(case))
            volumes = {v["name"]: v for v in spec["volumes"]}
            verifying = spec["containers"][0]
            mount = mounts(verifying)["/restic"]
            self.assertTrue(volumes[mount]["image"]["reference"].startswith("restic/restic:"), case)
            self.assertRegex(volumes[mount]["image"]["reference"], DIGEST + "$")
            self.assertEqual("/restic/usr/bin/restic", env_of(verifying)["RESTIC_BIN"], case)

    def test_the_script_comes_from_the_scripts_of_the_chart(self):
        spec = pod_spec(verify())
        volumes = {v["name"]: v for v in spec["volumes"]}
        self.assertEqual("shop-scripts", volumes["scripts"]["configMap"]["name"])
        self.assertEqual(["sh", "/scripts/backup-verify.sh"], spec["containers"][0]["command"])
        configs = [d for d in of_kind("prod", "ConfigMap") if name(d) == "shop-scripts"]
        self.assertIn("backup-verify.sh", configs[0]["data"])


class CertificatesTest(unittest.TestCase):
    def test_restic_trusts_the_certificates_of_its_own_image(self):
        """The image of postgres has no bundle of CA certificates in the place restic looks at, and the repository in prod is
        https (R2): the bundle comes from the same volume as the binary."""
        for case in ("prod", "ci"):
            verifying = pod_spec(verify(case))["containers"][0]
            env = env_of(verifying)
            mount = next(path for path, volume in mounts(verifying).items() if env["RESTIC_BIN"].startswith(path + "/"))
            self.assertEqual("/restic", mount)
            self.assertEqual("/restic/etc/ssl/certs/ca-certificates.crt", env["SSL_CERT_FILE"], case)
            self.assertTrue(env["SSL_CERT_FILE"].startswith(mount + "/"), case)


class AccessTest(unittest.TestCase):
    def test_only_the_container_that_reads_the_id_of_the_cluster_has_the_token(self):
        for case in ("prod", "ci"):
            spec = pod_spec(verify(case))
            self.assertIs(False, spec["automountServiceAccountToken"], case)
            self.assertEqual("shop-backup", spec["serviceAccountName"], case)
            with_token = [c["name"] for c in all_containers(case) if TOKEN_PATH in mounts(c)]
            self.assertEqual(["cluster-id"], with_token, case)

    def test_no_container_with_the_secret_of_the_backup_has_the_token_and_the_one_with_the_token_has_no_secret(self):
        for case in ("prod", "ci"):
            for container in all_containers(case):
                with_secret = any(secret == SECRET for _, secret, _ in secret_refs(container))
                self.assertFalse(with_secret and TOKEN_PATH in mounts(container), f"{case} {container['name']}")
                if container["name"] == "cluster-id":
                    self.assertEqual([], secret_refs(container))

    def test_the_id_of_the_cluster_is_read_the_way_the_backup_reads_it(self):
        def cluster_id(doc):
            return next(c for c in pod_spec(doc).get("initContainers", []) if c["name"] == "cluster-id")

        self.assertEqual(cluster_id(by_logical("prod", "CronJob")["backup"]), cluster_id(verify()))
        spec = pod_spec(verify())
        mounted = {c["name"] for c in all_containers() if "cluster" in mounts(c).values()}
        self.assertEqual({"cluster-id", "verify"}, mounted)
        self.assertEqual({}, {v["name"]: v for v in spec["volumes"]}["cluster"]["emptyDir"])

    def test_the_service_account_and_its_role_are_those_of_the_backup(self):
        roles = [d for d in of_kind("prod", "ClusterRole") if "backup" in name(d)]
        self.assertEqual(1, len(roles))
        bindings = [d for d in of_kind("prod", "ClusterRoleBinding") if "backup" in name(d)]
        self.assertEqual(1, len(bindings))
        self.assertEqual(1, len([d for d in of_kind("prod", "ServiceAccount") if "backup" in name(d)]))

    def test_only_the_verifying_container_reads_the_secret_of_the_backup_with_the_repository_and_its_password(self):
        for case in ("prod", "ci"):
            for container in all_containers(case):
                uses = any(secret == SECRET for _, secret, _ in secret_refs(container))
                self.assertEqual(container["name"] == "verify", uses, f"{case} {container['name']}")
            verifying = pod_spec(verify(case))["containers"][0]
            refs = secret_refs(verifying)
            self.assertIn(("RESTIC_PASSWORD", SECRET, "RESTIC_PASSWORD"), refs)
            self.assertIn(("AWS_ACCESS_KEY_ID", SECRET, "BACKUP_S3_ACCESS_KEY_ID"), refs)
            self.assertIn(("AWS_SECRET_ACCESS_KEY", SECRET, "BACKUP_S3_SECRET_ACCESS_KEY"), refs)
            self.assertEqual(env_of(by_logical(case, "CronJob")["backup"]["spec"]["jobTemplate"]["spec"]["template"]["spec"]["containers"][0])["RESTIC_REPOSITORY"], env_of(verifying)["RESTIC_REPOSITORY"])

    def test_the_postgres_has_no_secret_at_all(self):
        postgres = next(c for c in all_containers() if c["name"] == "postgres")
        self.assertEqual([], secret_refs(postgres))


class DiskTest(unittest.TestCase):
    def test_the_restored_databases_may_not_fill_the_disk_of_the_node(self):
        """The data of the temporary Postgres is limited to the size of the production one: a pod that outgrows it is evicted."""
        for case in ("prod", "ci"):
            volumes = {v["name"]: v for v in pod_spec(verify(case))["volumes"]}
            claims = by_logical(case, "StatefulSet")["postgres"]["spec"]["volumeClaimTemplates"]
            data = next(c for c in claims if c["metadata"]["name"] == "data")
            self.assertEqual(data["spec"]["resources"]["requests"]["storage"], volumes["data"]["emptyDir"]["sizeLimit"], case)


class ReadDataTest(unittest.TestCase):
    def test_prod_reads_a_subset_of_the_data_and_ci_all_of_it(self):
        for case, expected in (("prod", "subset"), ("ci", "all")):
            verifying = pod_spec(verify(case))["containers"][0]
            self.assertEqual(expected, env_of(verifying)["VERIFY_READ_DATA"], case)

    def test_the_cache_of_restic_is_on_an_empty_dir(self):
        spec = pod_spec(verify())
        verifying = spec["containers"][0]
        volumes = {v["name"]: v for v in spec["volumes"]}
        cache = env_of(verifying)["RESTIC_CACHE_DIR"]
        self.assertTrue(cache.startswith("/work/"))
        self.assertIn("emptyDir", volumes[mounts(verifying)["/work"]])


if __name__ == "__main__":
    unittest.main()

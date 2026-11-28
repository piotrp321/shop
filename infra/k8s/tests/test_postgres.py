"""The configuration of Postgres (docs/features/runtime-config/spec.md, "Postgres", Z4): the file of the settings that do not depend on
the memory (infra/postgres/postgresql.conf, linked into the chart and mounted by compose), the two settings that do (-c arguments of the
values of the environment), /dev/shm, the statistics and the metrics role, the pool of Keycloak."""

import hashlib
import re
import unittest

from support import REPO, by_logical, containers, env_of, name, of_kind, pod_spec

CONF = REPO / "infra/postgres/postgresql.conf"
MOUNT = "/etc/postgresql/postgresql.conf"
# The settings of the file, as SHOW prints them (the decision of the owner of 2026-11-27: a starting configuration).
SETTINGS = {
    "shared_preload_libraries": "pg_stat_statements",
    "pg_stat_statements.track_utility": "off",
    "track_io_timing": "on",
    "log_lock_waits": "on",
    "log_error_verbosity": "terse",
    "log_min_error_statement": "panic",
    "wal_compression": "lz4",
    "max_wal_size": "8GB",
    "checkpoint_timeout": "15min",
    "maintenance_work_mem": "256MB",
    "autovacuum_work_mem": "128MB",
    "listen_addresses": "*",
    "default_text_search_config": "pg_catalog.english",
    "timezone": "Etc/UTC",
    "log_timezone": "Etc/UTC",
    "lc_messages": "en_US.utf8",
    "lc_monetary": "en_US.utf8",
    "lc_numeric": "en_US.utf8",
    "lc_time": "en_US.utf8",
}
# The settings that stay the defaults or come from the environment: not in the file.
NOT_IN_THE_FILE = ("jit", "work_mem", "random_page_cost", "max_connections", "shared_buffers", "effective_cache_size",
                   "log_min_duration_statement", "log_parameter_max_length_on_error")
# The values per environment: prod and load for the 3.75 GiB pod of the starting configuration, ci and compose for a pod without a limit.
MEMORY = {"prod": ("1GB", "3GB"), "load": ("1GB", "3GB"), "ci": ("128MB", "512MB")}


def parse(text):
    found = {}
    for line in text.splitlines():
        line = line.split("#", 1)[0].strip()
        if line:
            key, value = (part.strip() for part in line.split("=", 1))
            found[key] = value.strip("'")
    return found


def postgres(case):
    return by_logical(case, "StatefulSet")["postgres"]


def arguments(case):
    """The -c arguments of the container as {setting: value}; every one is a pair `-c name=value`."""
    args = containers(postgres(case))[0]["args"]
    assert len(args) % 2 == 0 and all(a == "-c" for a in args[::2]), args
    return dict(item.split("=", 1) for item in args[1::2])


class FileTest(unittest.TestCase):
    def test_the_file_has_the_settings_of_the_table_and_no_other(self):
        self.assertEqual(SETTINGS, parse(CONF.read_text()))

    def test_the_defaults_and_the_memory_settings_are_not_in_the_file(self):
        found = parse(CONF.read_text())
        for setting in NOT_IN_THE_FILE:
            self.assertNotIn(setting, found)


class ChartTest(unittest.TestCase):
    def test_the_command_reads_the_file_and_takes_the_memory_from_the_values_of_the_environment(self):
        for case, (buffers, cache) in MEMORY.items():
            with self.subTest(case=case):
                self.assertEqual(
                    {"config_file": MOUNT, "shared_buffers": buffers, "effective_cache_size": cache}, arguments(case))

    def test_the_file_is_mounted_from_its_configmap_where_the_command_reads_it(self):
        for case in MEMORY:
            with self.subTest(case=case):
                container = containers(postgres(case))[0]
                mount = next(m for m in container["volumeMounts"] if m["mountPath"] == MOUNT)
                self.assertEqual("postgresql.conf", mount["subPath"])
                volume = next(v for v in pod_spec(postgres(case))["volumes"] if v["name"] == mount["name"])
                self.assertEqual("postgres-config", volume["configMap"]["name"])
                config = next(d for d in of_kind(case, "ConfigMap") if name(d) == "postgres-config")
                self.assertEqual(CONF.read_text(), config["data"]["postgresql.conf"])

    def test_the_checksum_of_the_file_is_in_the_annotation_of_the_pod(self):
        for case in MEMORY:
            with self.subTest(case=case):
                annotations = postgres(case)["spec"]["template"]["metadata"]["annotations"]
                self.assertEqual(hashlib.sha256(CONF.read_bytes()).hexdigest(), annotations["checksum/postgres-config"])

    def test_dev_shm_is_an_emptydir_in_memory_of_1gi(self):
        for case in MEMORY:
            with self.subTest(case=case):
                container = containers(postgres(case))[0]
                mount = next(m for m in container["volumeMounts"] if m["mountPath"] == "/dev/shm")
                volume = next(v for v in pod_spec(postgres(case))["volumes"] if v["name"] == mount["name"])
                self.assertEqual({"medium": "Memory", "sizeLimit": "1Gi"}, volume["emptyDir"])

    def test_the_load_profile_has_the_memory_settings_of_prod(self):
        self.assertEqual(arguments("prod"), arguments("load"))

    def test_the_pool_of_keycloak_takes_20_connections(self):
        for case in ("prod", "ci", "load"):
            with self.subTest(case=case):
                env = env_of(containers(by_logical(case, "Deployment")["keycloak"])[0])
                self.assertEqual("20", env["KC_DB_POOL_MAX_SIZE"])

    def test_the_budget_of_the_connections_is_written_next_to_the_values(self):
        text = (REPO / "infra/k8s/charts/shop/values.yaml").read_text()
        self.assertIn("The budget of the connections", text)
        self.assertIn("KC_DB_POOL_MAX_SIZE", text)


class ExistingDatabaseTest(unittest.TestCase):
    """The statistics and the metrics role in a database that exists: the init directory of Postgres runs only on an empty one, so the Job
    migrate runs the same script as the superuser before it migrates."""

    def migrate(self, case):
        return next(j for j in of_kind(case, "Job") if j["metadata"]["labels"]["app.kubernetes.io/name"] == "migrate")

    def test_the_job_migrate_runs_the_script_of_the_init_directory_as_the_superuser(self):
        for case in ("prod", "ci"):
            with self.subTest(case=case):
                spec = pod_spec(self.migrate(case))
                setup = next(c for c in spec["initContainers"] if c["name"] == "postgres-setup")
                self.assertEqual(by_logical(case, "StatefulSet")["postgres"]["spec"]["template"]["spec"]["containers"][0]["image"], setup["image"])
                self.assertEqual(["sh", "/postgres-init/20-monitoring.sh"], setup["command"])
                env = {e["name"]: e for e in setup["env"]}
                self.assertEqual("postgres", env["PGHOST"]["value"])
                self.assertEqual("postgres", env["PGUSER"]["value"])
                self.assertEqual("POSTGRES_PASSWORD", env["PGPASSWORD"]["valueFrom"]["secretKeyRef"]["key"])
                self.assertEqual([{"secretRef": {"name": "shop-secrets"}}], setup["envFrom"])
                volume = next(v for v in spec["volumes"] if v["name"] == setup["volumeMounts"][0]["name"])
                self.assertEqual("postgres-init", volume["configMap"]["name"])

    def test_the_script_waits_for_postgres_and_changes_the_name_of_the_job_when_it_changes(self):
        for case in ("prod", "ci"):
            with self.subTest(case=case):
                migrate = self.migrate(case)
                names = [c["name"] for c in pod_spec(migrate)["initContainers"]]
                self.assertLess(names.index("wait-for-postgres"), names.index("postgres-setup"))
                script = (REPO / "infra/postgres/init/20-monitoring.sh").read_bytes()
                annotations = migrate["spec"]["template"]["metadata"]["annotations"]
                self.assertEqual(hashlib.sha256(script).hexdigest(), annotations["checksum/postgres-setup"])

    def test_the_init_directory_has_the_script_in_the_configmap(self):
        for case in ("prod", "ci"):
            config = next(d for d in of_kind(case, "ConfigMap") if name(d) == "postgres-init")
            self.assertEqual((REPO / "infra/postgres/init/20-monitoring.sh").read_text(), config["data"]["20-monitoring.sh"], case)

    def test_the_metrics_role_has_pg_monitor_and_nothing_else(self):
        script = (REPO / "infra/postgres/init/20-monitoring.sh").read_text()
        self.assertEqual(["GRANT pg_monitor TO exporter"], re.findall(r"GRANT [^;]+", script))
        self.assertIn("NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION", script)
        self.assertIn("CREATE EXTENSION IF NOT EXISTS pg_stat_statements", script)
        # The password comes from the environment of the process, never from the text of the script.
        self.assertIn("\\getenv exporter_password EXPORTER_DB_PASSWORD", script)


class ComposeTest(unittest.TestCase):
    """Compose runs the same file and the memory settings of the profile ci (the service has no memory limit)."""

    def service(self):
        text = (REPO / "infra/docker-compose.yml").read_text()
        return re.search(r"^  postgres:\n(.*?)(?=^  [a-z][a-z-]*:\s*$)", text, re.M | re.S).group(0)

    def test_compose_mounts_the_file_and_gives_the_memory_settings_of_ci(self):
        service = self.service()
        self.assertIn(f"./infra/postgres/postgresql.conf:{MOUNT}:ro", service)
        args = re.search(r"^    command:\n((?:      - .*\n)+)", service, re.M).group(1)
        items = [line.strip()[2:] for line in args.splitlines()]
        self.assertTrue(all(i == "-c" for i in items[::2]), items)
        found = dict(i.split("=", 1) for i in items[1::2])
        self.assertEqual(arguments("ci"), found)

    def test_compose_has_a_dev_shm_of_1gb_and_the_pool_of_keycloak(self):
        self.assertRegex(self.service(), r"(?m)^    shm_size: 1gb$")
        text = (REPO / "infra/docker-compose.yml").read_text()
        self.assertRegex(text, r'(?m)^      KC_DB_POOL_MAX_SIZE: "20"$')


if __name__ == "__main__":
    unittest.main()

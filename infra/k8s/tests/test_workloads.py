import json
import re
import unittest

from support import (
    DIGEST, DIGESTS, REPO, REVISION, by_logical, containers, env_of, images, monitoring_budget, of_kind, pod_spec,
    quantity_bytes, quantity_cpu, waits_for,
)

STATEFUL = {"postgres", "elasticsearch", "rabbitmq", "redis", "garage"}
STATELESS = {"api", "worker", "worker-commands", "store", "admin", "imgproxy", "varnish", "keycloak", "mailpit"}
VERSION_JOBS = {"garage-init", "migrate", "keycloak-config", "initialize", "purge-caches", "backfill"}


# A service line of the compose file: its name, and the anchor of a definition that others reuse (`worker: &worker`).
SERVICE_LINE = r"^  ([a-z][a-z-]*):(?: &[a-z-]+)?\s*$"


def compose_services():
    text = (REPO / "infra/docker-compose.yml").read_text()
    block = text.split("\nservices:\n", 1)[1].split("\nvolumes:\n", 1)[0]
    return re.findall(SERVICE_LINE, block, re.M)


def compose_service(service):
    text = (REPO / "infra/docker-compose.yml").read_text()
    return re.search(rf"^  {service}:(?: &[a-z-]+)?\n(.*?)(?={SERVICE_LINE}|\Z)", text, re.M | re.S).group(0)


def compose_images():
    """The images of the compose file: `image:` of the services and FROM of the inline Dockerfiles (garage-init)."""
    text = (REPO / "infra/docker-compose.yml").read_text()
    # Not the image the workers share with the api (`${COMPOSE_PROJECT_NAME:-shop}-worker`): our own build, tagged by compose.
    return set(re.findall(r"^    image: ([^\s$]\S*)$", text, re.M)) | set(re.findall(r"^        FROM (\S+)", text, re.M))


class WorkloadsTest(unittest.TestCase):
    def test_every_compose_service_but_rabbitmq_init_is_a_workload_of_the_same_name(self):
        names = set(by_logical("ci", "Deployment", "StatefulSet", "Job"))
        expected = set(compose_services()) - {"rabbitmq-init", "e2e"}
        self.assertEqual(set(), expected - names)
        self.assertNotIn("rabbitmq-init", names)
        self.assertEqual(STATEFUL | STATELESS, names - VERSION_JOBS)

    def test_services_exist_for_the_compose_names(self):
        services = {s["metadata"]["name"] for s in of_kind("ci", "Service")}
        self.assertEqual(set(), (STATEFUL | STATELESS) - {"worker", "worker-commands"} - services)

    def test_stateful_services_are_statefulsets_with_local_path_volumes(self):
        found = by_logical("prod", "StatefulSet")
        self.assertEqual(STATEFUL, set(found))
        for name, sts in found.items():
            claims = sts["spec"]["volumeClaimTemplates"]
            self.assertTrue(claims, name)
            for claim in claims:
                self.assertEqual("local-path", claim["spec"]["storageClassName"], name)

    def test_stateless_services_are_single_replica_deployments(self):
        found = by_logical("prod", "Deployment")
        self.assertEqual(STATELESS, set(found))
        for name, deployment in found.items():
            self.assertEqual(1, deployment["spec"]["replicas"], name)

    def test_keycloak_is_recreated_and_every_other_deployment_never_loses_capacity(self):
        # Keycloak does not support two versions on one database (its schema migrates at the start), so its old pod
        # stops before the new one starts; the other Deployments keep the old pod until the new one is ready.
        for case in ("prod", "ci"):
            found = by_logical(case, "Deployment")
            self.assertIn("keycloak", found, case)
            for name, deployment in found.items():
                strategy = deployment["spec"]["strategy"]
                if name == "keycloak":
                    self.assertEqual({"type": "Recreate"}, strategy, f"{case}: {name}")
                else:
                    self.assertEqual("RollingUpdate", strategy["type"], f"{case}: {name}")
                    self.assertEqual(0, strategy["rollingUpdate"]["maxUnavailable"], f"{case}: {name}")

    def test_every_service_has_a_readiness_probe(self):
        for case in ("prod", "ci"):
            for name, workload in by_logical(case, "Deployment", "StatefulSet").items():
                self.assertIn("readinessProbe", containers(workload)[0], f"{case} {name}")

    def test_images_are_those_of_compose(self):
        """The images we do not build: name:tag as in compose, pinned by the digest of the image (never by the tag alone)."""
        # prod-backup: the tools of the backup are rendered only with it, and prod has it off until it is configured.
        used = {image for workload in of_kind("prod-backup", "Deployment", "StatefulSet", "Job", "CronJob") for image in images(workload)}
        own = {image for image in used if image.startswith("ghcr.io/piotrp321/shop/")}
        third_party = used - own
        for image in third_party:
            self.assertRegex(image, r"^[^@\s]+:[^@\s:/]+" + DIGEST + "$")
        tags = {image.split("@")[0] for image in third_party}
        for image in compose_images() - {"curlimages/curl:8.18.0"}:
            self.assertIn(image, tags)
        self.assertIn("alpine:3.22", tags)
        # The images of the tools of the backup have no counterpart in compose (the backup is a function of the server).
        self.assertIn("restic/restic:0.19.1", tags)
        self.assertIn("rclone/rclone:1.75.2", tags)
        # Elasticsearch is the image of its Dockerfile, which compose builds too: the official image is not in the chart.
        self.assertRegex(compose_service("elasticsearch"), r"\n    build:\n      context: \./infra/elasticsearch\n")
        self.assertEqual([], [image for image in used if "docker.elastic.co" in image])
        self.assertEqual({"api", "store", "admin", "elasticsearch"}, {image.split("/")[-1].split(":")[0] for image in own})

    def test_every_image_of_ci_is_pinned_but_ours_which_smoke_imports_by_tag(self):
        for workload in of_kind("ci", "Deployment", "StatefulSet", "Job", "CronJob"):
            for image in images(workload):
                if image.startswith("shop-smoke/"):
                    self.assertRegex(image, rf"^shop-smoke/[a-z]+:{REVISION}$")
                else:
                    self.assertRegex(image, DIGEST + "$")

    def test_elasticsearch_runs_the_image_built_from_its_dockerfile(self):
        for case, image, policy in (
            ("prod", f"ghcr.io/piotrp321/shop/elasticsearch:{REVISION}@{DIGESTS['elasticsearch']}", "IfNotPresent"),
            ("ci", f"shop-smoke/elasticsearch:{REVISION}", "Never"),
        ):
            container = containers(by_logical(case, "StatefulSet")["elasticsearch"])[0]
            self.assertEqual(image, container["image"], case)
            self.assertEqual(policy, container["imagePullPolicy"], case)

    def test_the_dockerfile_of_elasticsearch_pins_the_official_image_and_installs_the_plugin(self):
        elastic = (REPO / "infra/elasticsearch/Dockerfile").read_text()
        self.assertRegex(elastic, r"(?m)^FROM docker\.elastic\.co/elasticsearch/elasticsearch:[0-9.]+@sha256:[0-9a-f]{64}$")
        self.assertIn("elasticsearch-plugin install --batch analysis-stempel", elastic)

    def test_nothing_installs_plugins_when_a_pod_starts(self):
        for case in ("prod", "ci"):
            for workload in of_kind(case, "Deployment", "StatefulSet", "Job", "CronJob"):
                spec = pod_spec(workload)
                for container in spec["containers"] + spec.get("initContainers", []):
                    line = " ".join(container.get("command", []) + container.get("args", []))
                    self.assertNotIn("elasticsearch-plugin", line, f"{case} {workload['metadata']['name']}/{container['name']}")
        volumes = [v["name"] for v in pod_spec(by_logical("prod", "StatefulSet")["elasticsearch"]).get("volumes", [])]
        self.assertNotIn("plugins", volumes)

    def test_application_images_use_the_revision_and_the_digest(self):
        for name in ("api", "worker", "worker-commands", "store", "admin"):
            app = "api" if name.startswith("worker") else name
            image = containers(by_logical("prod", "Deployment")[name])[0]["image"]
            self.assertEqual(f"ghcr.io/piotrp321/shop/{app}:{REVISION}@{DIGESTS[app]}", image)
            image = containers(by_logical("ci", "Deployment")[name])[0]["image"]
            self.assertEqual(f"shop-smoke/{app}:{REVISION}", image)

    def test_keycloak_runs_in_start_mode_behind_the_proxy(self):
        main = containers(by_logical("prod", "Deployment")["keycloak"])[0]
        line = " ".join(main.get("command", []) + main.get("args", []))
        self.assertIn(" start", " " + line)
        self.assertNotIn("start-dev", line)
        self.assertNotIn("--import-realm", line)
        env = env_of(main)
        self.assertEqual("xforwarded", env["KC_PROXY_HEADERS"])
        self.assertEqual("true", env["KC_HTTP_ENABLED"])
        self.assertEqual("true", env["KC_HOSTNAME_BACKCHANNEL_DYNAMIC"])
        self.assertTrue(env["KC_HOSTNAME"].startswith("https://auth."))
        self.assertEqual("http://auth.localhost", env_of(containers(by_logical("ci", "Deployment")["keycloak"])[0])["KC_HOSTNAME"])

    def test_elasticsearch_is_single_node_without_security(self):
        env = env_of(containers(by_logical("prod", "StatefulSet")["elasticsearch"])[0])
        self.assertEqual("single-node", env["discovery.type"])
        self.assertEqual("false", env["xpack.security.enabled"])

    def test_worker_consumes_the_transports_of_compose(self):
        """Each worker runs the command line of its compose service, in the profiles prod and ci, and its probe waits for it."""
        for name in ("worker", "worker-commands"):
            compose = json.loads(re.search(r"^    command: (\[.*\])$", compose_service(name), re.M).group(1))
            container = containers(by_logical("prod", "Deployment")[name])[0]
            self.assertEqual(["php", *compose], container["command"], name)
            self.assertEqual(["php", *compose], containers(by_logical("ci", "Deployment")[name])[0]["command"], name)
            # The probe waits for PID 1 to be this very consumer: the same command line, up to the last limit.
            probe = container["readinessProbe"]["exec"]["command"][-1]
            self.assertIn(f"grep -q '^php {' '.join(compose)}'", probe, name)
            # The healthcheck of compose waits for the same line.
            self.assertIn(f"grep -q '^php {' '.join(compose)}'", compose_service(name), name)

    def test_the_two_workers_consume_every_transport_but_failed(self):
        """A transport of messenger.yaml that no worker consumes would never be handled: the test fails for it."""
        config = (REPO / "apps/api/config/packages/messenger.yaml").read_text()
        block = config.split("        transports:\n", 1)[1].split("        routing:", 1)[0]
        transports = set(re.findall(r"^            ([a-z_]+):\n", block, re.M))
        self.assertLessEqual({"outbox", "events", "scheduled", "payment_simulator", "promotions", "jobs", "failed"}, transports)
        for case in ("prod", "ci"):
            consumed = []
            for name in ("worker", "worker-commands"):
                command = containers(by_logical(case, "Deployment")[name])[0]["command"]
                self.assertEqual(["php", "bin/console", "messenger:consume"], command[:3], name)
                consumed += [word for word in command[3:] if not word.startswith("--")]
            self.assertEqual(sorted(transports - {"failed"}), sorted(consumed), case)

    def test_the_events_worker_relays_the_outbox_first_and_waits_for_rabbitmq_and_the_commands_worker_does_not(self):
        for case in ("prod", "ci"):
            deployments = by_logical(case, "Deployment")
            worker = containers(deployments["worker"])[0]["command"]
            self.assertEqual(["outbox", "events"], [w for w in worker[3:] if not w.startswith("--")], case)
            commands = containers(deployments["worker-commands"])[0]["command"]
            # The webhooks of the simulator before the cancellations: the worker starts from the first transport after each message.
            self.assertLess(commands.index("payment_simulator"), commands.index("scheduled"), case)
            self.assertEqual({"migrate"}, set(waits_for(deployments["worker-commands"])), case)
            self.assertEqual({"migrate", "rabbitmq"}, set(waits_for(deployments["worker"])), case)
            for container in pod_spec(deployments["worker-commands"]).get("initContainers", []):
                self.assertNotIn("5672", " ".join(container.get("command", []) + container.get("args", [])), f"{case} {container['name']}")

    def test_both_workers_stop_by_themselves_after_a_day_or_at_192_megabytes(self):
        for case in ("prod", "ci"):
            for name in ("worker", "worker-commands"):
                command = containers(by_logical(case, "Deployment")[name])[0]["command"]
                self.assertIn("--time-limit=86400", command, f"{case} {name}")
                self.assertIn("--memory-limit=192M", command, f"{case} {name}")

    def test_the_workers_together_take_no_more_than_the_one_worker_did(self):
        """500m of requests, 512Mi of requests and 1Gi of limits in prod and load."""
        for case in ("prod", "load"):
            requests_cpu = requests_memory = limits_memory = 0
            for name in ("worker", "worker-commands"):
                resources = containers(by_logical(case, "Deployment")[name])[0]["resources"]
                requests_cpu += quantity_cpu(resources["requests"]["cpu"])
                requests_memory += quantity_bytes(resources["requests"]["memory"])
                limits_memory += quantity_bytes(resources["limits"]["memory"])
            self.assertLessEqual(requests_cpu, 0.5, case)
            self.assertLessEqual(requests_memory, 512 * 2**20, case)
            self.assertLessEqual(limits_memory, 2**30, case)

    def test_environment_of_the_api_has_the_public_urls_from_the_domain(self):
        env = env_of(containers(by_logical("ci", "Deployment")["api"])[0])
        self.assertEqual("prod", env["APP_ENV"])
        self.assertEqual("http://localhost", env["STORE_URL"])
        self.assertEqual("http://admin.localhost", env["ADMIN_URL"])
        self.assertEqual("http://img.localhost", env["IMGPROXY_PUBLIC_URL"])
        self.assertEqual("http://auth.localhost", env["KEYCLOAK_PUBLIC_URL"])
        self.assertEqual("http://api.localhost", env["PAYMENT_SIMULATOR_PUBLIC_URL"])
        self.assertEqual("http://keycloak:8080", env["KEYCLOAK_INTERNAL_URL"])
        prod = env_of(containers(by_logical("prod", "Deployment")["api"])[0])
        self.assertTrue(prod["STORE_URL"].startswith("https://"))

    def test_admin_gets_its_urls_for_config_js(self):
        env = env_of(containers(by_logical("ci", "Deployment")["admin"])[0])
        self.assertEqual("http://api.localhost", env["VITE_API_URL"])
        self.assertEqual("http://auth.localhost", env["VITE_KEYCLOAK_URL"])
        self.assertEqual("http://admin.localhost", env["VITE_ADMIN_URL"])
        self.assertEqual("http://localhost", env["VITE_STORE_URL"])

    def test_admin_links_to_the_same_store_address_as_the_store_knows(self):
        for case in ("ci", "prod"):
            with self.subTest(case=case):
                admin = env_of(containers(by_logical(case, "Deployment")["admin"])[0])
                store = env_of(containers(by_logical(case, "Deployment")["store"])[0])
                self.assertEqual(store["STORE_URL"], admin["VITE_STORE_URL"])

    def test_prod_fits_the_server(self):
        """8 vCore and 24 GB: CPU requests up to 7 vCPU, memory limits up to 20 GB (steady state), the shop and the application
        monitoring (Alloy, its operator, kube-state-metrics, node-exporter) together."""
        cpu = memory = 0
        for workload in of_kind("prod", "Deployment", "StatefulSet"):
            for container in containers(workload):
                resources = container["resources"]
                cpu += quantity_cpu(resources["requests"]["cpu"]) * workload["spec"]["replicas"]
                memory += quantity_bytes(resources["limits"]["memory"]) * workload["spec"]["replicas"]
        self.assertGreater(memory, 4 * 2**30)
        monitoring_cpu, monitoring_memory = monitoring_budget()
        self.assertGreater(monitoring_cpu, 0)
        self.assertGreater(monitoring_memory, 0)
        self.assertLessEqual(cpu + monitoring_cpu, 7, f"shop {cpu} + monitoring {monitoring_cpu} vCPU of requests")
        self.assertLessEqual(memory + monitoring_memory, 20 * 2**30, f"shop {memory / 2**30} + monitoring {monitoring_memory / 2**30} GiB of limits")

    def test_ci_profile_is_light(self):
        es = env_of(containers(by_logical("ci", "StatefulSet")["elasticsearch"])[0])
        self.assertIn("-Xmx512m", es["ES_JAVA_OPTS"])
        kc_ci = env_of(containers(by_logical("ci", "Deployment")["keycloak"])[0])["JAVA_OPTS_KC_HEAP"]
        kc_prod = env_of(containers(by_logical("prod", "Deployment")["keycloak"])[0])["JAVA_OPTS_KC_HEAP"]
        self.assertNotEqual(kc_ci, kc_prod)
        cpu = sum(
            quantity_cpu(c["resources"]["requests"]["cpu"])
            for w in of_kind("ci", "Deployment", "StatefulSet")
            for c in containers(w)
        )
        self.assertLessEqual(cpu, 2)

    def test_ci_mail_goes_to_mailpit(self):
        env = env_of(containers(by_logical("ci", "Deployment")["api"])[0])
        self.assertEqual("smtp://mailpit:1025?timeout=10", env["MAILER_DSN"])


if __name__ == "__main__":
    unittest.main()


class AppArmorTest(unittest.TestCase):
    """containerd/containerd#12886 on Ubuntu 26.04: the AppArmor profile of containerd denies signals between the
    processes of an exec probe. The readiness probe of RabbitMQ (gosu + the Erlang CLI) then times out, so its container
    runs unconfined; every other container keeps the default profile."""

    UNCONFINED = {"rabbitmq"}

    def test_only_the_listed_containers_run_without_the_apparmor_profile(self):
        for case in ("prod", "ci"):
            for kind in ("Deployment", "StatefulSet", "Job", "CronJob"):
                for workload in of_kind(case, kind):
                    spec = pod_spec(workload)
                    for container in spec["containers"] + spec.get("initContainers", []):
                        profile = container.get("securityContext", {}).get("appArmorProfile")
                        where = f"{case} {kind} {workload['metadata']['name']} {container['name']}"
                        if container["name"] in self.UNCONFINED and kind == "StatefulSet":
                            self.assertEqual({"type": "Unconfined"}, profile, where)
                        else:
                            self.assertIsNone(profile, where)
                    self.assertNotIn("appArmorProfile", spec.get("securityContext", {}), f"{case} {kind} pod")

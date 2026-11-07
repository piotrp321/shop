import re
import unittest

from support import REPO, by_logical, containers, env_of, of_kind, pod_spec, quantity_bytes, quantity_cpu

STATEFUL = {"postgres", "elasticsearch", "rabbitmq", "redis", "garage"}
STATELESS = {"api", "worker", "store", "admin", "imgproxy", "varnish", "keycloak", "mailpit"}
VERSION_JOBS = {"garage-init", "migrate", "keycloak-config", "initialize", "purge-caches"}


def compose_services():
    text = (REPO / "infra/docker-compose.yml").read_text()
    block = text.split("\nservices:\n", 1)[1].split("\nvolumes:\n", 1)[0]
    return re.findall(r"^  ([a-z][a-z-]*):\s*$", block, re.M)


def compose_images():
    text = (REPO / "infra/docker-compose.yml").read_text()
    return set(re.findall(r"^    image: (\S+)$", text, re.M))


class WorkloadsTest(unittest.TestCase):
    def test_every_compose_service_but_rabbitmq_init_is_a_workload_of_the_same_name(self):
        names = set(by_logical("ci", "Deployment", "StatefulSet", "Job"))
        expected = set(compose_services()) - {"rabbitmq-init", "e2e"}
        self.assertEqual(set(), expected - names)
        self.assertNotIn("rabbitmq-init", names)
        self.assertEqual(STATEFUL | STATELESS, names - VERSION_JOBS)

    def test_services_exist_for_the_compose_names(self):
        services = {s["metadata"]["name"] for s in of_kind("ci", "Service")}
        self.assertEqual(set(), (STATEFUL | STATELESS) - {"worker"} - services)

    def test_stateful_services_are_statefulsets_with_local_path_volumes(self):
        found = by_logical("prod", "StatefulSet")
        self.assertEqual(STATEFUL, set(found))
        for name, sts in found.items():
            claims = sts["spec"]["volumeClaimTemplates"]
            self.assertTrue(claims, name)
            for claim in claims:
                self.assertEqual("local-path", claim["spec"]["storageClassName"], name)

    def test_stateless_services_are_single_replica_deployments_that_never_lose_capacity(self):
        found = by_logical("prod", "Deployment")
        self.assertEqual(STATELESS, set(found))
        for name, deployment in found.items():
            self.assertEqual(1, deployment["spec"]["replicas"], name)
            self.assertEqual("RollingUpdate", deployment["spec"]["strategy"]["type"], name)
            self.assertEqual(0, deployment["spec"]["strategy"]["rollingUpdate"]["maxUnavailable"], name)

    def test_every_service_has_a_readiness_probe(self):
        for case in ("prod", "ci"):
            for name, workload in by_logical(case, "Deployment", "StatefulSet").items():
                self.assertIn("readinessProbe", containers(workload)[0], f"{case} {name}")

    def test_images_are_those_of_compose(self):
        used = set()
        for workload in of_kind("prod", "Deployment", "StatefulSet", "Job", "CronJob"):
            spec = pod_spec(workload)
            used |= {c["image"] for c in spec["containers"] + spec.get("initContainers", [])}
        for image in compose_images() - {"curlimages/curl:8.18.0"}:
            self.assertIn(image, used)
        elastic = (REPO / "infra/elasticsearch/Dockerfile").read_text()
        self.assertIn(re.search(r"^FROM (\S+)", elastic, re.M).group(1), used)
        self.assertIn("alpine:3.22", used)

    def test_application_images_use_the_revision(self):
        for name in ("api", "worker", "store", "admin"):
            image = containers(by_logical("prod", "Deployment")[name])[0]["image"]
            app = "api" if name == "worker" else name
            self.assertEqual(f"ghcr.io/piotrp321/shop/{app}:0123456789abcdef0123456789abcdef01234567", image)

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
        line = " ".join(containers(by_logical("prod", "Deployment")["worker"])[0]["command"])
        self.assertIn("messenger:consume outbox events scheduled payment_simulator", line)

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

    def test_prod_fits_the_server(self):
        """8 vCore and 24 GB: CPU requests up to 7 vCPU, memory limits up to 20 GB (steady state)."""
        cpu = memory = 0
        for workload in of_kind("prod", "Deployment", "StatefulSet"):
            for container in containers(workload):
                resources = container["resources"]
                cpu += quantity_cpu(resources["requests"]["cpu"]) * workload["spec"]["replicas"]
                memory += quantity_bytes(resources["limits"]["memory"]) * workload["spec"]["replicas"]
        self.assertLessEqual(cpu, 7)
        self.assertLessEqual(memory, 20 * 2**30)
        self.assertGreater(memory, 4 * 2**30)

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
        self.assertEqual("smtp://mailpit:1025", env["MAILER_DSN"])


if __name__ == "__main__":
    unittest.main()

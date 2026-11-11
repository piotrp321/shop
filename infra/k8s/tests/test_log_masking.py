"""The logs that leave the cluster (docs/features/deploy-ops/spec.md, O7): the stages of the application monitoring run in a real
Alloy (scripts/masking.sh) on a corpus of the logs of every source with secrets and personal data planted in it, and the output of
loki.echo is read here. The stages are the ones of the manifest (extraLogProcessingStages): the same for the logs of the pods and for
the cluster events. The sources that cannot be masked are not collected at all (extraDiscoveryRules)."""

import json
import re
import tarfile
import unittest

from support import REPO, RENDER, docs, of_kind

CORPUS = REPO / "infra/k8s/tests/log-corpus"
SOURCES = sorted(p.stem for p in CORPUS.glob("*.log"))
SENTINEL = "END-OF-CORPUS"

JWT = "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTYiLCJlbWFpbCI6ImphbkBleGFtcGxlLnBsIn0.c2lnbmF0dXJlLXNlY3JldA"
# What is planted in the corpus and must not come out, by the kind of the thing (the message of a failure names it).
PLANTED = {
    "password": ["Hunter2-S3cretPw", "v-pw-query"],
    "passwd": ["v-passwd-1", "v-passwd-query"],
    "secret": ["v-secret-1", "v-secret-query"],
    "token": ["v-token-1", "v-token-query", "v-token-nginx", "v-token-img", "v-token-event", "v-token-id-1"],
    "access_token": ["v-access-token-1", "v-at-query", "v-at-nginx", "v-escaped-token"],
    "refresh_token": ["v-refresh-token-1", "v-rt-query", "v-escaped-refresh"],
    "id_token": [JWT, "v-it-query", "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9"],
    "code": ["v-code-1", "v-code-query", "v-code-store", "v-code-nginx", "v-code-id-1"],
    "session": ["v-session-1", "v-session-query", "v-session-store", "v-session-state-1", "v-session-state-nginx"],
    "client_secret": ["v-client-secret-1", "v-cs-query", "v-client-secret-kc"],
    "api_key": ["v-api-key-1", "v-ak-query", "v-api-key-img"],
    "authorization header": ["abc123DEF456ghi789", "v-auth-query", "dXNlcjpwYXNzd29yZDEyMw=="],
    "cookie header": ["7c5d2e9f1a3b4c6d8e0f", "zzTOPSECRETsession", "zzSETCOOKIEsession", "v-cookie-query"],
    "password in an address": ["SmtpP4ssw0rd", "RedisP4ssw0rd"],
    "e-mail": ["jan.kowalski", "kowalski@", "example.pl", "przyklad.pl", "gęślą", "anna%40", "example.com"],
    "username": ["jankowalski_login"],
    "IPv4": ["203.0.113.45", "198.51.100.7", "10.42.0.15"],
    "IPv6": ["2001:db8:85a3", "8a2e:370:7334", "fe80::", "1ff:fe23"],
    "Doctrine parameters": ["Jan Kowalski", "ul. Długa 5", "00-001 Warszawa", "129.99"],
    "DETAIL of Postgres": ["PL-SECRET-SKU-9"],
    "STATEMENT of Postgres": ["INSERT INTO customer (email, name) VALUES"],
}
# What is needed to diagnose and must stay: the level, the message, the channel, the code of the response.
KEPT = {
    "api": [
        '"level_name":"ERROR"', '"channel":"doctrine"', "UniqueConstraintViolationException", "duplicate key value violates unique constraint",
        '"message":"Authentication failed"', '"channel":"security"', '"level_name":"WARNING"', '"channel":"request"', '"status_code":403',
        '"message":"Request finished"', '"method":"GET"', '"uri":"/api/v1/orders?page=2&', '"channel":"cache"', "Redis is down",
        '"channel":"mailer"', "failed: smtp://", '"channel":"http_client"', "Keycloak returned 400", "invalid_grant", "SQLSTATE[23505]",
    ],
    "keycloak": ['WARN  [org.keycloak.events]', 'type="LOGIN_ERROR"', 'error="invalid_user_credentials"', 'clientId="store"', 'realmId="shop"',
                 'error="user_not_found"', 'INFO  [org.keycloak.events]', 'userId="5b0c6bb1-1111-2222-3333-444455556666"', 'type="LOGIN"'],
    "postgres": ['ERROR:  duplicate key value violates unique constraint "uniq_customer_email"', "LOG:  connection received: host=",
                 'FATAL:  password authentication failed for user "shop"'],
    "store": ["GET /auth/callback?", "302 in 45ms", "failed with status 401", "ready - started server on port 3000", "fetch failed"],
    "admin": ["GET /assets/app.js?", 'HTTP/1.1" 200 5123', 'POST /api/v1/admin/login HTTP/1.1" 502 157', "[error]", "Connection refused"],
    "imgproxy": ["INFO[0012] Completed in 12.3ms", "status=200", "WARN[0013] Request failed", "status=403"],
    "events": ["kind=Pod", "level=Warning", "reason=Unhealthy", "Readiness probe failed", "connection refused", "reason=BackOff",
               "Back-off restarting failed container worker", "level=Normal", "reason=Created", "kind=Job"],
}
# The Postgres lines that are dropped as a whole (the line of the log, not the one of the corpus file).
DROPPED = {"postgres": 3}
# The sources that are not collected: the stages cannot guarantee their masking (spec, O7); the discovery rules drop them.
DISABLED_SOURCES = {"mailpit"}


def apps():
    return {d["metadata"]["name"]: d for d in docs("argocd-apps")}


def values():
    return apps()["monitoring"]["spec"]["source"]["helm"]["valuesObject"]


def output_entries():
    """The entries of loki.echo as (pipeline, source, line)."""
    entries = []
    for text in (RENDER / "masking-output.log").read_text().splitlines():
        match = re.search(r'component_id=loki\.echo\.(\w+) labels="((?:[^"\\]|\\.)*)" entry="((?:[^"\\]|\\.)*)" entry_timestamp=', text)
        assert match, f"a line of loki.echo that this test cannot read: {text[:200]}"
        labels = json.loads('"' + match.group(2) + '"')
        source = re.search(r'source="([^"]*)"', labels).group(1)
        entries.append((match.group(1), source, json.loads('"' + match.group(3) + '"')))
    return entries


def lines_of(pipeline, source=None):
    return [line for p, s, line in output_entries() if p == pipeline and (source is None or s == source)]


def corpus_lines(source):
    return (CORPUS / f"{source}.log").read_text().splitlines()


class Stages(unittest.TestCase):
    def test_the_pods_and_the_events_get_the_same_non_empty_stages_from_one_block_of_the_manifest(self):
        pods = values()["podLogsViaKubernetesApi"].get("extraLogProcessingStages")
        events = values()["clusterEvents"].get("extraLogProcessingStages")
        self.assertTrue(pods, "podLogsViaKubernetesApi has no extraLogProcessingStages: the logs of the pods are not masked")
        self.assertEqual(pods, events, "the logs of the pods and the events do not get the same stages")
        text = (REPO / "infra/k8s/argocd/apps/monitoring.yaml").read_text()
        self.assertEqual(1, len(re.findall(r"^\s*extraLogProcessingStages: &\w+ \|", text, re.M)), "the stages are not written once")
        self.assertEqual(1, len(re.findall(r"^\s*extraLogProcessingStages: \*\w+\s*$", text, re.M)), "the events do not use the same block")

    def test_the_rendered_configuration_has_the_stages_in_the_pods_and_in_the_events(self):
        config = (RENDER / "monitoring-config.alloy").read_text()
        stages = values()["podLogsViaKubernetesApi"].get("extraLogProcessingStages") or ""
        self.assertTrue(stages)
        wanted = [line.strip() for line in stages.splitlines() if line.strip()]
        for component in ('loki.process "pod_logs"', 'loki.process "cluster_events"'):
            block = config.split(component + " {", 1)[1].split("\n  } // " + component, 1)[0]
            have = [line.strip() for line in block.splitlines() if line.strip()]
            start = have.index(wanted[0]) if wanted[0] in have else -1
            self.assertGreaterEqual(start, 0, f"the stages are not in {component} of the rendered configuration")
            self.assertEqual(wanted, have[start : start + len(wanted)], f"the stages in {component} are not the ones of the manifest")

    def test_every_stage_is_an_alloy_process_stage_and_the_masking_is_the_replacement(self):
        stages = values()["podLogsViaKubernetesApi"].get("extraLogProcessingStages") or ""
        self.assertTrue(stages)
        self.assertIn("[REDACTED]", stages)
        self.assertIn("stage.drop", stages)
        self.assertIn("stage.replace", stages)


class Corpus(unittest.TestCase):
    def test_every_planted_value_is_in_the_corpus(self):
        everything = "\n".join(p.read_text() for p in CORPUS.glob("*.log"))
        for kind, planted in PLANTED.items():
            for value in planted:
                self.assertIn(value, everything, f"{kind}: {value} is not planted in tests/log-corpus")

    def test_every_source_of_the_corpus_has_what_is_kept_and_is_a_known_source(self):
        self.assertEqual(sorted(KEPT), SOURCES)


class Output(unittest.TestCase):
    def test_every_line_of_the_corpus_reached_the_end_through_both_pipelines(self):
        entries = output_entries()
        for pipeline in ("pods", "events"):
            for source in SOURCES:
                self.assertIn(f"{SENTINEL} {source}", lines_of(pipeline, source), f"{pipeline}/{source}: the run did not read the whole corpus")
        self.assertEqual({"pods", "events"}, {p for p, _, _ in entries})

    def test_nothing_planted_comes_out_of_the_logs_of_the_pods(self):
        self.assert_nothing_planted("pods")

    def test_nothing_planted_comes_out_of_the_cluster_events(self):
        self.assert_nothing_planted("events")

    def assert_nothing_planted(self, pipeline):
        everything = "\n".join(lines_of(pipeline))
        for kind, planted in PLANTED.items():
            for value in planted:
                self.assertNotIn(value, everything, f"{pipeline}: {kind} {value!r} came out of Alloy")
        # ::1 is too short to look for as a substring.
        self.assertIsNone(re.search(r"(?<![:\w])::1(?![\w:])", everything), f"{pipeline}: the address ::1 came out of Alloy")

    def test_the_lines_detail_and_statement_of_postgres_are_dropped_not_masked(self):
        for pipeline in ("pods", "events"):
            lines = lines_of(pipeline, "postgres")
            self.assertEqual([], [line for line in lines if re.search(r"(^|\]\s)\s*(DETAIL|STATEMENT):", line)], pipeline)
            self.assertEqual(len(corpus_lines("postgres")) + 1 - DROPPED["postgres"], len(lines), f"{pipeline}: other lines of Postgres were lost")

    def test_the_level_the_message_the_channel_and_the_code_of_the_response_stay(self):
        for pipeline in ("pods", "events"):
            for source, kept in KEPT.items():
                text = "\n".join(lines_of(pipeline, source))
                for fragment in kept:
                    self.assertIn(fragment, text, f"{pipeline}/{source}: {fragment!r} was masked or lost")

    def test_the_other_sources_keep_all_their_lines(self):
        for pipeline in ("pods", "events"):
            for source in SOURCES:
                if source in DROPPED:
                    continue
                self.assertEqual(len(corpus_lines(source)) + 1, len(lines_of(pipeline, source)), f"{pipeline}/{source}: a line was dropped")

    def test_what_replaces_a_value_is_the_same_everywhere(self):
        self.assertIn("[REDACTED]", "\n".join(lines_of("pods", "api")))
        self.assertIn("[REDACTED]", "\n".join(lines_of("events", "events")))


class Discovery(unittest.TestCase):
    """extraDiscoveryRules: the relabel rules of the discovery of the pods; Mailpit (the addressees and the subjects of the mail)
    is not collected. The rules are evaluated as Prometheus does (the values of source_labels joined with the separator, a regular
    expression matching the whole of it) on the labels of the pods that the chart of the shop renders."""

    @staticmethod
    def rules(text):
        found = []
        for block in re.findall(r"rule\s*\{(.*?)\n\s*\}", text, re.S):
            def attribute(key, default=None):
                match = re.search(rf"^\s*{key}\s*=\s*(.+?)\s*$", block, re.M)
                return json.loads(match.group(1)) if match else default

            found.append({"source_labels": attribute("source_labels", []), "separator": attribute("separator", ";"),
                          "regex": attribute("regex", "(.*)"), "action": attribute("action", "replace")})
        return found

    @staticmethod
    def dropped(rules, labels):
        for rule in rules:
            if rule["action"] != "drop":
                continue
            joined = rule["separator"].join(labels.get(label, "") for label in rule["source_labels"])
            if re.fullmatch(rule["regex"], joined):
                return True
        return False

    def pod_labels(self):
        """The discovery labels (what discovery.kubernetes sets) of every pod of the prod render, by the logical name."""
        pods = {}
        for workload in of_kind("prod", "Deployment", "StatefulSet", "CronJob", "Job"):
            template = workload["spec"]["jobTemplate"]["spec"]["template"] if workload["kind"] == "CronJob" else workload["spec"]["template"]
            for container in template["spec"]["containers"] + template["spec"].get("initContainers", []):
                labels = {"__meta_kubernetes_namespace": workload["metadata"].get("namespace", "shop"),
                          "__meta_kubernetes_pod_container_name": container["name"]}
                for key, value in template["metadata"].get("labels", {}).items():
                    labels["__meta_kubernetes_pod_label_" + re.sub(r"[^a-zA-Z0-9_]", "_", key)] = value
                pods[(workload["metadata"]["labels"]["app.kubernetes.io/name"], container["name"])] = labels
        return pods

    def test_the_rules_drop_exactly_the_disabled_sources_of_the_shop(self):
        rules = self.rules(values()["podLogsViaKubernetesApi"].get("extraDiscoveryRules") or "")
        self.assertTrue(rules, "no extraDiscoveryRules: Mailpit is collected")
        pods = self.pod_labels()
        self.assertTrue(pods)
        dropped = {logical for (logical, _), labels in pods.items() if self.dropped(rules, labels)}
        self.assertEqual(DISABLED_SOURCES, dropped)

    def test_a_pod_of_another_namespace_with_the_same_name_is_not_dropped(self):
        rules = self.rules(values()["podLogsViaKubernetesApi"].get("extraDiscoveryRules") or "")
        labels = {"__meta_kubernetes_namespace": "other", "__meta_kubernetes_pod_label_app_kubernetes_io_name": "mailpit"}
        self.assertFalse(self.dropped(rules, labels))


class AlloyImage(unittest.TestCase):
    def test_the_image_of_the_test_is_what_the_chart_installs(self):
        tools = (REPO / "infra/k8s/scripts/tools.sh").read_text()
        image = re.search(r'ALLOY_IMAGE="\$\{ALLOY_IMAGE:-([^}]+)\}"', tools).group(1)
        self.assertRegex(image, r"^grafana/alloy:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}$")
        tag = image.split(":", 1)[1].split("@", 1)[0]
        chart = json.loads((RENDER / "monitoring-chart.json").read_text())["charts"]["k8s-monitoring"]
        archive = RENDER.parent / "charts" / f"k8s-monitoring-{chart['version']}.tgz"
        with tarfile.open(archive) as tar:
            version_file = tar.extractfile("k8s-monitoring/templates/collectors/_collector_version.tpl").read().decode()
        self.assertEqual(tag, re.search(r'pinnedImageTag" -?\}\}(v[\d.]+)', version_file).group(1), "the chart pins another Alloy than the test runs")
        # The manifest pins the same tag, so that what runs is what was tested (the operator would choose it otherwise).
        self.assertEqual(tag, values()["collectors"]["alloy"].get("image", {}).get("tag"))


if __name__ == "__main__":
    unittest.main()

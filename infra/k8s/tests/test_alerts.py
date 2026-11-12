"""The alert rules in the render (docs/features/deploy-ops/spec.md, O6): one PrometheusRule with seven alerts where `alerts.enabled`,
and the rule WorkloadNotReady that looks for every Deployment and StatefulSet that the chart renders. What the expressions do is
tested by promtool on the rules of this render (tests/alerts/alerts.test.yml, step alerts of scripts/check.sh)."""

import re
import unittest

from support import REPO, docs, name, of_kind

ALERTS = ["BackupFailed", "BackupMissing", "BackupVerifyFailed", "BackupVerifyMissing", "NodeDiskFull", "ClusterSilent", "WorkloadNotReady"]
BACKUP_ALERTS = ALERTS[:4]


def rules(case="prod"):
    found = of_kind(case, "PrometheusRule")
    assert len(found) == 1, f"{case} has {len(found)} PrometheusRule objects"
    groups = found[0]["spec"]["groups"]
    return {rule["alert"]: rule for group in groups for rule in group["rules"]}


def workloads(case):
    return {(d["kind"], name(d)) for d in of_kind(case, "Deployment", "StatefulSet")}


def absent_of(expr):
    """The (kind, name) of every workload that an `absent(...)` of the expression looks for."""
    found = set()
    for metric, selector, kind in (("kube_deployment_spec_replicas", "deployment", "Deployment"), ("kube_statefulset_replicas", "statefulset", "StatefulSet")):
        for match in re.finditer(rf'absent\(\s*{metric}\{{([^}}]*)\}}\s*\)', expr):
            labels = dict(re.findall(r'(\w+)="([^"]*)"', match.group(1)))
            assert labels.get("namespace") == "shop", match.group(0)
            found.add((kind, labels[selector]))
    return found


class RenderTest(unittest.TestCase):
    def test_prod_has_one_prometheus_rule_with_the_seven_alerts(self):
        found = of_kind("prod", "PrometheusRule")
        self.assertEqual(1, len(found))
        self.assertEqual("monitoring.coreos.com/v1", found[0]["apiVersion"])
        self.assertEqual("shop", found[0]["metadata"]["namespace"])
        self.assertCountEqual(ALERTS, rules().keys())

    def test_the_profiles_that_do_not_enable_the_alerts_have_no_rule(self):
        # ci and load have no CRD of the Prometheus Operator in their cluster; prod renders none when told so.
        for case in ("ci", "load", "ci-images-file", "prod-noalerts"):
            self.assertEqual([], of_kind(case, "PrometheusRule"), case)

    def test_every_alert_has_a_severity_and_a_message_in_polish(self):
        for alert, rule in rules().items():
            self.assertIn(rule["labels"]["severity"], ("critical", "warning"), alert)
            for field in ("summary", "description"):
                self.assertRegex(rule["annotations"][field], r"[a-ząćęłńóśźż]{3}", f"{alert} {field}")

    def test_the_backup_alerts_look_at_the_exact_name_of_the_cronjob(self):
        # `backup` must not match `backup-verify`: equality on cronjob and on the owner of the Job, never a regex.
        for alert, cronjob in (("BackupFailed", "backup"), ("BackupMissing", "backup"), ("BackupVerifyFailed", "backup-verify"), ("BackupVerifyMissing", "backup-verify")):
            expr = rules()[alert]["expr"]
            self.assertIn(f'"{cronjob}"', expr, alert)
            self.assertNotRegex(expr, r'(cronjob|owner_name)=~', alert)
        self.assertIn("kube_job_owner", rules()["BackupFailed"]["expr"])
        self.assertIn("kube_job_status_condition", rules()["BackupFailed"]["expr"])
        self.assertIn("kube_cronjob_status_last_successful_time", rules()["BackupMissing"]["expr"])

    def test_the_alerts_of_missing_data_have_an_absent(self):
        # A server or Alloy that does not work sends no metric: the rule that waits for a metric to be too old must also fire
        # when there is none (the ruler of Grafana Cloud still evaluates it).
        for alert in ("BackupMissing", "BackupVerifyMissing"):
            self.assertRegex(rules()[alert]["expr"], r"absent(_over_time)?\(", alert)
        self.assertRegex(rules()["ClusterSilent"]["expr"], r"absent\(\s*kube_node_info\s*\)")
        self.assertIn("26 * 3600", rules()["BackupMissing"]["expr"])

    def test_the_disk_alert_leaves_out_the_temporary_file_systems(self):
        expr = rules()["NodeDiskFull"]["expr"]
        for fstype in ("tmpfs", "overlay"):
            self.assertIn(fstype, expr)
        self.assertEqual("10m", rules()["NodeDiskFull"]["for"])
        self.assertEqual("15m", rules()["ClusterSilent"]["for"])
        self.assertEqual("10m", rules()["WorkloadNotReady"]["for"])

    def test_without_the_backup_there_are_no_alerts_about_it(self):
        self.assertCountEqual([a for a in ALERTS if a not in BACKUP_ALERTS], rules("prod-nobackup").keys())


class WorkloadNotReadyTest(unittest.TestCase):
    def test_it_has_an_absent_for_every_deployment_and_statefulset_of_the_render(self):
        for case in ("prod", "prod-nobackup", "prod-noinit"):
            expected = workloads(case)
            self.assertGreater(len(expected), 10, case)
            self.assertIn(("StatefulSet", "redis"), expected, case)
            self.assertEqual(expected, absent_of(rules(case)["WorkloadNotReady"]["expr"]), case)

    def test_the_list_follows_the_render_not_a_copy_of_it(self):
        # The profile ci has its own set of workloads; the rule is rendered for it too when the alerts are enabled.
        expected = workloads("ci-alerts")
        self.assertEqual(expected, absent_of(rules("ci-alerts")["WorkloadNotReady"]["expr"]))

    def test_the_absence_counts_only_while_the_cluster_reports(self):
        # The silence of the whole cluster is ClusterSilent's: the absent of a workload is joined with kube_node_info.
        self.assertRegex(rules()["WorkloadNotReady"]["expr"], r"and on\(\)\s+kube_node_info")

    def test_it_compares_the_replicas_of_deployments_and_statefulsets_in_shop(self):
        expr = rules()["WorkloadNotReady"]["expr"]
        # The maximum over the series of one object on both sides: a second series of it (another instance) is no many-to-many match.
        self.assertRegex(expr, r'max by \(namespace, deployment\) \(kube_deployment_status_replicas_available\{namespace="shop"\}\)\s*'
                               r'< on\(namespace, deployment\)\s*max by \(namespace, deployment\) \(kube_deployment_spec_replicas\{namespace="shop"\}\)')
        self.assertRegex(expr, r'max by \(namespace, statefulset\) \(kube_statefulset_status_replicas_ready\{namespace="shop"\}\)\s*'
                               r'< on\(namespace, statefulset\)\s*max by \(namespace, statefulset\) \(kube_statefulset_replicas\{namespace="shop"\}\)')


class ValuesTest(unittest.TestCase):
    def test_the_alerts_are_off_by_default_on_in_prod_and_off_in_ci_and_load(self):
        values = {path: (REPO / "infra/k8s" / path).read_text() for path in (
            "charts/shop/values.yaml", "environments/prod/values.yaml", "environments/ci/values.yaml", "environments/load/values.yaml")}
        self.assertRegex(values["charts/shop/values.yaml"], r"(?m)^alerts:\n(?:\s*#.*\n)*  enabled: false\b")
        self.assertRegex(values["environments/prod/values.yaml"], r"(?m)^alerts:\n(?:\s*#.*\n)*  enabled: true\b")
        self.assertRegex(values["environments/ci/values.yaml"], r"(?m)^alerts:\n(?:\s*#.*\n)*  enabled: false\b")
        self.assertNotIn("alerts:", values["environments/load/values.yaml"])

    def test_the_schema_knows_the_value_as_a_boolean(self):
        schema = (REPO / "infra/k8s/charts/shop/values.schema.json").read_text()
        self.assertRegex(schema, r'"alerts":\s*\{[^}]*"properties":\s*\{\s*"enabled":\s*\{\s*"type":\s*"boolean"')


class ScenariosTest(unittest.TestCase):
    def test_every_alert_has_a_scenario_in_which_it_fires_and_one_in_which_it_does_not(self):
        text = (REPO / "infra/k8s/tests/alerts/alerts.test.yml").read_text()
        for alert in ALERTS:
            sections = re.findall(rf"alertname: {alert}\n\s+exp_alerts:( \[\])?", text)
            self.assertIn("", sections, f"{alert} has no scenario in which it fires")
            self.assertIn(" []", sections, f"{alert} has no scenario in which it stays silent")


if __name__ == "__main__":
    unittest.main()

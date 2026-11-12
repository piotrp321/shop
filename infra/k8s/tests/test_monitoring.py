"""The application monitoring (docs/features/deploy-ops/spec.md, O5): the chart k8s-monitoring rendered with the values of
argocd/apps/monitoring.yaml (scripts/monitoring-render.sh): what only Alloy may read in the cluster, the metrics it keeps and the
Alloy resources. The manifests of the applications and their waves are in test_argocd.py, the stages that mask the logs in
test_log_masking.py, the budget of the node in test_workloads.py."""

import re
import unittest

from support import RENDER, docs, name, of_kind

# The metrics that the alerts of the spec (O6) use.
ALERT_METRICS = [
    "kube_cronjob_status_last_successful_time", "kube_job_status_condition", "kube_job_owner", "node_filesystem_avail_bytes",
    "node_filesystem_size_bytes", "kube_node_info", "kube_deployment_status_replicas_available", "kube_deployment_spec_replicas",
    "kube_statefulset_status_replicas_ready", "kube_statefulset_replicas",
]
SECRET_OF_ALLOY = "grafana-cloud"
# The ClusterRole of the Alloy Operator itself (not of an Alloy it runs): it keeps the Helm releases of the Alloy resources it
# manages in Secrets and the chart gives it no way to narrow that (values: rbac.create and rbac.createClusterRoles only).
OPERATOR_ROLE = re.compile(r"^monitoring-alloy-operator$")


def values():
    return next(d for d in docs("argocd-apps") if name(d) == "monitoring")["spec"]["source"]["helm"]["valuesObject"]


def reads_secrets(rule):
    """Whether a rule of a Role or a ClusterRole gives access to Secrets: by name, or by a wildcard in the resources (and the
    API group the Secrets are in), whatever the verb."""
    groups = rule.get("apiGroups", [""])
    resources = rule.get("resources", [])
    return bool({"", "*"} & set(groups)) and bool({"secrets", "*"} & set(resources)) and bool(rule.get("verbs"))


class ReadsSecrets(unittest.TestCase):
    def test_the_check_itself_sees_the_wildcards(self):
        self.assertTrue(reads_secrets({"apiGroups": [""], "resources": ["secrets"], "verbs": ["get"]}))
        self.assertTrue(reads_secrets({"apiGroups": [""], "resources": ["configmaps", "secrets"], "verbs": ["list"]}))
        self.assertTrue(reads_secrets({"apiGroups": ["*"], "resources": ["*"], "verbs": ["get"]}))
        self.assertTrue(reads_secrets({"apiGroups": [""], "resources": ["*"], "verbs": ["*"]}))
        self.assertTrue(reads_secrets({"apiGroups": ["*"], "resources": ["secrets"], "verbs": ["watch"]}))
        self.assertFalse(reads_secrets({"apiGroups": ["apps"], "resources": ["secrets"], "verbs": ["get"]}))
        self.assertFalse(reads_secrets({"apiGroups": [""], "resources": ["pods", "events"], "verbs": ["get"]}))
        self.assertFalse(reads_secrets({"nonResourceURLs": ["/metrics"], "verbs": ["get"]}))


def problems(render):
    """What is wrong with the access to Secrets in the documents of a render (a list of messages; empty: nothing)."""
    found = []
    roles = [d for d in render if d["kind"] in ("ClusterRole", "Role")]
    readers = []
    for role in roles:
        if OPERATOR_ROLE.match(name(role)):
            continue
        if role.get("aggregationRule"):
            found.append(f"{role['kind']}/{name(role)} has an aggregationRule: it collects rules of other ClusterRoles, which this test cannot see")
        for rule in role.get("rules") or []:
            if reads_secrets(rule):
                readers.append((role["kind"], name(role), role["metadata"].get("namespace"), rule))
    if len(readers) != 1:
        found.append(f"roles of the render with access to Secrets: {readers}")
    for kind, role_name, namespace, rule in readers[:1]:
        if (kind, namespace) != ("Role", "shop"):
            found.append(f"the one role with access to Secrets is {kind}/{role_name} in {namespace}, not a Role in shop")
        if rule.get("resourceNames") != [SECRET_OF_ALLOY] or rule["resources"] != ["secrets"] or rule["apiGroups"] != [""]:
            found.append(f"the Role does not name the one Secret: {rule}")
        if not set(rule["verbs"]) <= {"get", "list", "watch"}:
            found.append(f"verbs of the Role on the Secret: {rule['verbs']}")
    # A binding to a role that the render does not have grants what that role grants elsewhere in the cluster (cluster-admin, any
    # role of another chart): every roleRef must be a role of this render, of the namespace of a RoleBinding.
    defined = {(r["kind"], name(r), r["metadata"].get("namespace")) for r in roles}
    for binding in render:
        if binding["kind"] not in ("RoleBinding", "ClusterRoleBinding"):
            continue
        ref = binding["roleRef"]
        namespace = binding["metadata"].get("namespace") if ref["kind"] == "Role" else None
        if (ref["kind"], ref["name"], namespace) not in defined:
            found.append(f"{binding['kind']}/{name(binding)} binds {ref['kind']}/{ref['name']}, which the render does not define")
    return found


def role(kind, role_name, namespace, rules, **extra):
    metadata = {"name": role_name} | ({"namespace": namespace} if namespace else {})
    return {"apiVersion": "rbac.authorization.k8s.io/v1", "kind": kind, "metadata": metadata, "rules": rules} | extra


def binding(kind, binding_name, namespace, ref_kind, ref_name):
    metadata = {"name": binding_name} | ({"namespace": namespace} if namespace else {})
    return {"kind": kind, "metadata": metadata, "roleRef": {"kind": ref_kind, "name": ref_name, "apiGroup": "rbac.authorization.k8s.io"},
            "subjects": [{"kind": "ServiceAccount", "name": "monitoring-alloy", "namespace": "monitoring"}]}


GOOD_ROLE = role("Role", "alloy-grafana-cloud", "shop", [{"apiGroups": [""], "resources": ["secrets"], "resourceNames": [SECRET_OF_ALLOY], "verbs": ["get"]}])


class TheCheckOfRbac(unittest.TestCase):
    def test_the_good_render_has_no_problem(self):
        good = [GOOD_ROLE, binding("RoleBinding", "alloy-grafana-cloud", "shop", "Role", "alloy-grafana-cloud")]
        self.assertEqual([], problems(good))

    def test_a_binding_to_a_role_the_render_does_not_define_is_a_problem(self):
        for ref_kind, ref_name in (("ClusterRole", "cluster-admin"), ("ClusterRole", "admin"), ("ClusterRole", "edit"), ("Role", "other")):
            with self.subTest(ref=ref_name):
                bad = [GOOD_ROLE, binding("ClusterRoleBinding", "x", None, ref_kind, ref_name)]
                self.assertTrue(problems(bad), f"a ClusterRoleBinding to {ref_name} passes")

    def test_a_rolebinding_to_a_role_of_another_namespace_is_a_problem(self):
        bad = [GOOD_ROLE, binding("RoleBinding", "x", "monitoring", "Role", "alloy-grafana-cloud")]
        self.assertTrue(problems(bad))

    def test_a_cluster_role_with_an_aggregation_rule_is_a_problem(self):
        aggregate = role("ClusterRole", "collect", None, [], aggregationRule={"clusterRoleSelectors": [{"matchLabels": {"x": "y"}}]})
        self.assertTrue(problems([GOOD_ROLE, aggregate]))

    def test_a_second_role_with_access_to_secrets_is_a_problem(self):
        wide = role("ClusterRole", "wide", None, [{"apiGroups": ["*"], "resources": ["*"], "verbs": ["*"]}])
        self.assertTrue(problems([GOOD_ROLE, wide]))


class Rbac(unittest.TestCase):
    def roles(self):
        return [d for d in docs("monitoring") if d["kind"] in ("ClusterRole", "Role")]

    def test_only_one_role_gives_access_to_secrets_the_one_of_alloy_on_its_own_token_in_the_shop(self):
        self.assertEqual([], problems(docs("monitoring")))

    def test_the_role_of_the_token_is_bound_to_the_service_account_of_alloy_only(self):
        shop_roles = [r for r in self.roles() if r["kind"] == "Role" and r["metadata"].get("namespace") == "shop"]
        self.assertEqual(1, len(shop_roles), "no Role in the namespace shop for the token of Alloy")
        role = shop_roles[0]
        bindings = [b for b in docs("monitoring") if b["kind"] == "RoleBinding" and b["metadata"].get("namespace") == "shop"]
        self.assertEqual(1, len(bindings))
        binding = bindings[0]
        self.assertEqual({"kind": "Role", "name": name(role), "apiGroup": "rbac.authorization.k8s.io"}, binding["roleRef"])
        alloy = of_kind("monitoring", "Alloy")[0]
        service_account = alloy["spec"].get("serviceAccount", {}).get("name") or name(alloy)
        self.assertEqual([{"kind": "ServiceAccount", "name": service_account, "namespace": alloy["metadata"]["namespace"]}], binding["subjects"])

    def test_the_rules_of_alloy_itself_have_no_secrets_the_default_of_its_chart_has_them(self):
        # The ClusterRole of Alloy is made by the operator from spec.rbac of the Alloy resource: the default of the Alloy chart is
        # get/list/watch on all Secrets and ConfigMaps of the cluster, so the rules must be given and be narrower.
        alloys = of_kind("monitoring", "Alloy")
        self.assertEqual(1, len(alloys))
        rbac = alloys[0]["spec"].get("rbac")
        self.assertTrue(rbac and rbac.get("rules"), "the Alloy resource has no rbac.rules: Alloy gets the default, which reads all Secrets")
        for key in ("rules", "clusterRules"):
            for rule in rbac.get(key) or []:
                self.assertFalse(reads_secrets(rule), f"rbac.{key} of Alloy gives access to Secrets: {rule}")
        # What Alloy needs: the discovery of the pods, their logs, the events and the PrometheusRule objects.
        needed = {"pods", "pods/log", "events", "prometheusrules"}
        have = {resource for rule in rbac["rules"] for resource in rule.get("resources", [])}
        self.assertTrue(needed <= have, f"missing in rbac.rules: {sorted(needed - have)}")
        self.assertFalse(rbac.get("namespaces"), "rbac.namespaces would make Roles of the ClusterRoles Alloy needs for the nodes")

    def test_kube_state_metrics_does_not_list_secrets(self):
        roles = [r for r in self.roles() if r["kind"] == "ClusterRole" and "kube-state-metrics" in name(r)]
        self.assertEqual(1, len(roles))
        for rule in roles[0]["rules"]:
            self.assertFalse(reads_secrets(rule), rule)

    def test_the_destinations_read_the_token_from_the_secret_of_the_shop_and_nothing_else_is_created(self):
        for destination in values()["destinations"].values():
            self.assertFalse(destination["secret"]["create"])
            self.assertEqual({"name": SECRET_OF_ALLOY, "namespace": "shop", "create": False}, destination["secret"])
            self.assertEqual("GRAFANA_CLOUD_TOKEN", destination["auth"]["passwordKey"])
        self.assertEqual([], of_kind("monitoring", "Secret"), "the render creates a Secret")

    def test_the_render_names_the_secret_in_the_shop_in_the_configuration_of_alloy(self):
        config = (RENDER / "monitoring-config.alloy").read_text()
        blocks = re.findall(r'remote\.kubernetes\.secret "[^"]+" \{\s*name\s*=\s*"([^"]+)"\s*namespace\s*=\s*"([^"]+)"', config)
        self.assertEqual({(SECRET_OF_ALLOY, "shop")}, set(blocks))

    def test_no_resource_of_the_render_is_a_secret_or_a_workload_outside_its_namespace(self):
        for doc in docs("monitoring"):
            namespace = doc["metadata"].get("namespace")
            if doc["kind"] in ("Role", "RoleBinding") and namespace == "shop":
                continue
            if doc["kind"] in ("ClusterRole", "ClusterRoleBinding", "CustomResourceDefinition"):
                continue
            self.assertEqual("monitoring", namespace, f"{doc['kind']}/{name(doc)}")


class Crds(unittest.TestCase):
    def test_the_chart_of_the_crds_has_the_one_of_the_alert_rules(self):
        names = {name(d) for d in docs("prometheus-crds") if d["kind"] == "CustomResourceDefinition"}
        self.assertIn("prometheusrules.monitoring.coreos.com", names)


class AllowedMetrics(unittest.TestCase):
    def keep_expressions(self, component):
        config = (RENDER / "monitoring-config.alloy").read_text()
        block = config.split(f'prometheus.relabel "{component}" {{', 1)[1].split(f'}} // prometheus.relabel "{component}"', 1)[0]
        return re.findall(r'source_labels = \["__name__"\]\s*regex = "([^"]+)"\s*action = "keep"', block)

    def test_the_list_is_not_empty_and_names_the_metrics_of_the_alerts(self):
        listed = (values()["clusterMetrics"]["kube-state-metrics"]["metricsTuning"]["includeMetrics"]
                  + values()["hostMetrics"]["linuxHosts"]["metricsTuning"]["includeMetrics"])
        self.assertTrue(listed)
        for metric in ALERT_METRICS:
            self.assertIn(metric, listed)

    def test_alloy_keeps_every_metric_of_the_alerts_and_only_a_list(self):
        for component, wanted in (
            ("kube_state_metrics", [m for m in ALERT_METRICS if m.startswith("kube_")]),
            ("node_exporter", [m for m in ALERT_METRICS if m.startswith("node_")]),
        ):
            expressions = self.keep_expressions(component)
            self.assertEqual(1, len(expressions), f"{component} has no allow list (keep rule on the name of the metric)")
            self.assertNotIn(expressions[0], (".*", ".+", ""), "the list allows every metric")
            for metric in wanted:
                self.assertRegex(metric, f"^(?:{expressions[0]})$", f"{component} drops {metric}")

    def test_the_default_list_is_kept_not_widened_to_all_metrics(self):
        for component in ("kubelet", "cadvisor", "kube_state_metrics", "node_exporter"):
            for expression in self.keep_expressions(component):
                self.assertNotIn(expression, (".*", ".+"), component)


class AlloyResource(unittest.TestCase):
    def test_one_alloy_of_one_replica_with_the_image_of_the_test_and_explicit_resources(self):
        alloys = of_kind("monitoring", "Alloy")
        self.assertEqual(1, len(alloys))
        spec = alloys[0]["spec"]
        self.assertEqual(1, spec["controller"]["replicas"])
        self.assertEqual("deployment", spec["controller"]["type"])
        self.assertTrue(spec["alloy"]["resources"]["requests"]["cpu"])
        self.assertTrue(spec["alloy"]["resources"]["limits"]["memory"])
        self.assertTrue(spec["configReloader"]["resources"]["limits"]["memory"])
        self.assertRegex(spec["image"]["tag"], r"^v\d+\.\d+\.\d+$")


if __name__ == "__main__":
    unittest.main()

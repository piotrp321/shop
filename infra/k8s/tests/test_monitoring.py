"""The application monitoring (docs/features/deploy-ops/spec.md, O5): the chart k8s-monitoring rendered with the values of
argocd/apps/monitoring.yaml (scripts/monitoring-render.sh): what only Alloy may read in the cluster, the metrics it keeps and the
Alloy resources. The manifests of the applications and their waves are in test_argocd.py, the stages that mask the logs in
test_log_masking.py, the budget of the node in test_workloads.py."""

import re
import unittest

from support import RENDER, REPO, containers, docs, env_of, name, of_kind

# The metrics that the alerts of the spec (O6) use.
ALERT_METRICS = [
    "kube_cronjob_status_last_successful_time", "kube_job_status_condition", "kube_job_owner", "node_filesystem_avail_bytes",
    "node_filesystem_size_bytes", "kube_node_info", "kube_deployment_status_replicas_available", "kube_deployment_spec_replicas",
    "kube_statefulset_status_replicas_ready", "kube_statefulset_replicas",
]
SECRET_OF_ALLOY = "grafana-cloud"
# The service accounts of the Alloy Operator and of the Alloy it runs (the collector), in the namespace of the application.
OPERATOR = {"kind": "ServiceAccount", "name": "monitoring-alloy-operator", "namespace": "monitoring"}
COLLECTOR = {"kind": "ServiceAccount", "name": "monitoring-alloy", "namespace": "monitoring"}
READ = {"get", "list", "watch"}
# What the collector reads (spec, O5): the nodes and the metrics of their kubelet and cAdvisor, the endpoints of kube-state-metrics,
# the pods of node-exporter and of every namespace with their logs, the events and the PrometheusRule objects.
COLLECTOR_NEEDS = {"nodes", "nodes/metrics", "services", "endpoints", "pods", "pods/log", "namespaces", "events", "prometheusrules"}


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


def escalates(rule):
    """Whether a rule lets its holder give itself more: bind, escalate or impersonate, any verb (*), or a change of RBAC objects
    (Roles, ClusterRoles and their bindings)."""
    verbs = set(rule.get("verbs", []))
    if verbs & {"bind", "escalate", "impersonate", "*"}:
        return True
    if "nonResourceURLs" in rule:
        return False
    return bool({"rbac.authorization.k8s.io", "*"} & set(rule.get("apiGroups", [""]))) and bool(verbs - READ)


def problems(render):
    """What is wrong with the RBAC of the documents of a render (a list of messages; empty: nothing). No role of the render can
    give more (escalates); a ClusterRole only reads, and never Secrets; a Role is in shop or monitoring; the one Role with Secrets
    outside monitoring is the Role of Alloy on its token in shop; a Role with Secrets in monitoring (the releases of the operator)
    is bound to the operator only; the operator has no ClusterRoleBinding and no binding outside monitoring."""
    found = []
    roles = [d for d in render if d["kind"] in ("ClusterRole", "Role")]
    readers = []
    monitoring_readers = set()
    for role in roles:
        kind, role_name, namespace = role["kind"], name(role), role["metadata"].get("namespace")
        if role.get("aggregationRule"):
            found.append(f"{kind}/{role_name} has an aggregationRule: it collects rules of other ClusterRoles, which this test cannot see")
        if kind == "Role" and namespace not in ("shop", "monitoring"):
            found.append(f"Role/{role_name} is in the namespace {namespace}, not in shop or monitoring")
        for rule in role.get("rules") or []:
            if escalates(rule):
                found.append(f"{kind}/{role_name} can bind, escalate, impersonate or change RBAC objects: {rule}")
            if kind == "ClusterRole" and not set(rule.get("verbs", [])) <= READ:
                found.append(f"ClusterRole/{role_name} does more than read in the whole cluster: {rule}")
            if reads_secrets(rule):
                if kind == "Role" and namespace == "monitoring":
                    monitoring_readers.add(role_name)
                else:
                    readers.append((kind, role_name, namespace, rule))
    if len(readers) != 1:
        found.append(f"roles of the render with access to Secrets outside monitoring: {readers}")
    for kind, role_name, namespace, rule in readers[:1]:
        if (kind, namespace) != ("Role", "shop"):
            found.append(f"the one role with access to Secrets is {kind}/{role_name} in {namespace}, not a Role in shop")
        if rule.get("resourceNames") != [SECRET_OF_ALLOY] or rule["resources"] != ["secrets"] or rule["apiGroups"] != [""]:
            found.append(f"the Role does not name the one Secret: {rule}")
        if not set(rule["verbs"]) <= READ:
            found.append(f"verbs of the Role on the Secret: {rule['verbs']}")
    # A binding to a role that the render does not have grants what that role grants elsewhere in the cluster (cluster-admin, any
    # role of another chart): every roleRef must be a role of this render, of the namespace of a RoleBinding.
    defined = {(r["kind"], name(r), r["metadata"].get("namespace")) for r in roles}
    for binding in render:
        if binding["kind"] not in ("RoleBinding", "ClusterRoleBinding"):
            continue
        ref = binding["roleRef"]
        namespace = binding["metadata"].get("namespace") if binding["kind"] == "RoleBinding" else None
        if (ref["kind"], ref["name"], namespace if ref["kind"] == "Role" else None) not in defined:
            found.append(f"{binding['kind']}/{name(binding)} binds {ref['kind']}/{ref['name']}, which the render does not define")
        subjects = binding.get("subjects") or []
        if OPERATOR in subjects and (binding["kind"] != "RoleBinding" or namespace != "monitoring"):
            found.append(f"{binding['kind']}/{name(binding)} gives the operator rights outside the namespace monitoring")
        if ref["kind"] == "Role" and namespace == "monitoring" and ref["name"] in monitoring_readers and subjects != [OPERATOR]:
            found.append(f"RoleBinding/{name(binding)} gives the Secrets of monitoring to {subjects}, not to the operator only")
    return found


def role(kind, role_name, namespace, rules, **extra):
    metadata = {"name": role_name} | ({"namespace": namespace} if namespace else {})
    return {"apiVersion": "rbac.authorization.k8s.io/v1", "kind": kind, "metadata": metadata, "rules": rules} | extra


def binding(kind, binding_name, namespace, ref_kind, ref_name, subject=COLLECTOR):
    metadata = {"name": binding_name} | ({"namespace": namespace} if namespace else {})
    return {"kind": kind, "metadata": metadata, "roleRef": {"kind": ref_kind, "name": ref_name, "apiGroup": "rbac.authorization.k8s.io"},
            "subjects": [subject]}


GOOD_ROLE = role("Role", "alloy-grafana-cloud", "shop", [{"apiGroups": [""], "resources": ["secrets"], "resourceNames": [SECRET_OF_ALLOY], "verbs": ["get"]}])
OPERATOR_RULES = [{"apiGroups": [""], "resources": ["configmaps", "secrets", "serviceaccounts", "services"], "verbs": ["get", "create", "delete"]},
                  {"apiGroups": ["apps"], "resources": ["deployments"], "verbs": ["get", "create", "patch"]}]
GOOD = [
    GOOD_ROLE, binding("RoleBinding", "alloy-grafana-cloud", "shop", "Role", "alloy-grafana-cloud"),
    role("Role", "operator", "monitoring", OPERATOR_RULES), binding("RoleBinding", "operator", "monitoring", "Role", "operator", OPERATOR),
    role("ClusterRole", "collector", None, [{"apiGroups": [""], "resources": ["nodes", "nodes/metrics", "pods"], "verbs": ["get", "list"]},
                                            {"nonResourceURLs": ["/metrics"], "verbs": ["get"]}]),
    binding("ClusterRoleBinding", "collector", None, "ClusterRole", "collector"),
]


class TheCheckOfRbac(unittest.TestCase):
    def test_the_good_render_has_no_problem(self):
        self.assertEqual([], problems(GOOD))

    def test_a_binding_to_a_role_the_render_does_not_define_is_a_problem(self):
        for ref_kind, ref_name in (("ClusterRole", "cluster-admin"), ("ClusterRole", "admin"), ("ClusterRole", "edit"), ("Role", "other")):
            with self.subTest(ref=ref_name):
                bad = GOOD + [binding("ClusterRoleBinding", "x", None, ref_kind, ref_name)]
                self.assertTrue(problems(bad), f"a ClusterRoleBinding to {ref_name} passes")

    def test_a_rolebinding_to_a_role_of_another_namespace_is_a_problem(self):
        bad = GOOD + [binding("RoleBinding", "x", "monitoring", "Role", "alloy-grafana-cloud")]
        self.assertTrue(problems(bad))

    def test_a_cluster_role_with_an_aggregation_rule_is_a_problem(self):
        aggregate = role("ClusterRole", "collect", None, [], aggregationRule={"clusterRoleSelectors": [{"matchLabels": {"x": "y"}}]})
        self.assertTrue(problems(GOOD + [aggregate]))

    def test_a_second_role_with_access_to_secrets_is_a_problem(self):
        wide = role("ClusterRole", "wide", None, [{"apiGroups": ["*"], "resources": ["*"], "verbs": ["*"]}])
        self.assertTrue(problems(GOOD + [wide]))

    def test_a_cluster_role_that_reads_secrets_is_a_problem_also_the_one_of_the_operator(self):
        for verbs in (["get"], ["list", "watch"], ["get", "list", "watch", "create", "update", "patch", "delete"]):
            with self.subTest(verbs=verbs):
                operator = role("ClusterRole", "monitoring-alloy-operator", None, [{"apiGroups": [""], "resources": ["configmaps", "secrets"], "verbs": verbs}])
                self.assertTrue(problems(GOOD + [operator, binding("ClusterRoleBinding", "o", None, "ClusterRole", "monitoring-alloy-operator", OPERATOR)]))

    def test_bind_escalate_impersonate_and_changes_of_rbac_are_a_problem_in_any_role(self):
        for kind, namespace in (("ClusterRole", None), ("Role", "monitoring"), ("Role", "shop")):
            for rule in (
                {"apiGroups": ["rbac.authorization.k8s.io"], "resources": ["clusterroles"], "verbs": ["bind"]},
                {"apiGroups": ["rbac.authorization.k8s.io"], "resources": ["roles"], "verbs": ["escalate"]},
                {"apiGroups": [""], "resources": ["serviceaccounts", "users"], "verbs": ["impersonate"]},
                {"apiGroups": ["rbac.authorization.k8s.io"], "resources": ["rolebindings"], "verbs": ["create"]},
                {"apiGroups": ["*"], "resources": ["roles"], "verbs": ["patch"]},
                {"apiGroups": ["apps"], "resources": ["deployments"], "verbs": ["*"]},
            ):
                with self.subTest(kind=kind, namespace=namespace, rule=rule):
                    self.assertTrue(problems(GOOD + [role(kind, "x", namespace, [rule])]))

    def test_reading_rbac_objects_is_not_a_change(self):
        reader = role("ClusterRole", "x", None, [{"apiGroups": ["rbac.authorization.k8s.io"], "resources": ["roles"], "verbs": ["get", "list"]}])
        self.assertEqual([], problems(GOOD + [reader]))

    def test_a_cluster_role_that_writes_anything_is_a_problem(self):
        writer = role("ClusterRole", "x", None, [{"apiGroups": [""], "resources": ["pods"], "verbs": ["get", "create"]}])
        self.assertTrue(problems(GOOD + [writer]))

    def test_a_role_outside_shop_and_monitoring_is_a_problem(self):
        for rules in ([{"apiGroups": [""], "resources": ["secrets"], "verbs": ["get"]}], [{"apiGroups": [""], "resources": ["pods"], "verbs": ["get"]}]):
            with self.subTest(rules=rules):
                self.assertTrue(problems(GOOD + [role("Role", "x", "kube-system", rules)]))

    def test_the_secrets_of_monitoring_are_for_the_operator_only(self):
        self.assertTrue(problems(GOOD + [binding("RoleBinding", "y", "monitoring", "Role", "operator", COLLECTOR)]))

    def test_the_operator_has_no_rights_outside_monitoring(self):
        for bad in (
            binding("ClusterRoleBinding", "y", None, "ClusterRole", "collector", OPERATOR),
            binding("RoleBinding", "y", "shop", "Role", "alloy-grafana-cloud", OPERATOR),
        ):
            with self.subTest(binding=name(bad)):
                self.assertTrue(problems(GOOD + [bad]))


class Rbac(unittest.TestCase):
    def roles(self):
        return [d for d in docs("monitoring") if d["kind"] in ("ClusterRole", "Role")]

    def test_the_rbac_of_the_render_has_no_problem(self):
        found = problems(docs("monitoring"))
        self.assertFalse(found, "the RBAC of the render:\n" + "\n".join(found))

    def test_the_operator_watches_only_its_own_namespace(self):
        # Without WATCH_NAMESPACE the operator watches the Alloy resources of the whole cluster, for which it has no rights.
        operator = [d for d in of_kind("monitoring", "Deployment") if name(d) == OPERATOR["name"]]
        self.assertEqual(1, len(operator))
        self.assertEqual({"WATCH_NAMESPACE": "monitoring"}, {k: v for k, v in env_of(containers(operator[0])[0]).items() if k == "WATCH_NAMESPACE"})

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

    def test_alloy_gets_its_cluster_role_from_the_render_not_from_the_operator(self):
        # The operator has no rights on RBAC objects, so the Alloy resource makes none (the default of the Alloy chart is a
        # ClusterRole that reads all Secrets and ConfigMaps of the cluster); its ClusterRole and binding are in the render.
        alloys = of_kind("monitoring", "Alloy")
        self.assertEqual(1, len(alloys))
        self.assertIs(False, (alloys[0]["spec"].get("rbac") or {}).get("create"), "the Alloy resource makes its own RBAC")
        service_account = alloys[0]["spec"].get("serviceAccount", {}).get("name") or name(alloys[0])
        self.assertEqual(COLLECTOR, {"kind": "ServiceAccount", "name": service_account, "namespace": alloys[0]["metadata"]["namespace"]})
        bindings = [b for b in of_kind("monitoring", "ClusterRoleBinding") if COLLECTOR in b["subjects"]]
        self.assertEqual(1, len(bindings), "the collector has not exactly one ClusterRoleBinding")
        cluster_role = next(r for r in of_kind("monitoring", "ClusterRole") if name(r) == bindings[0]["roleRef"]["name"])
        have = {resource for rule in cluster_role["rules"] for resource in rule.get("resources", [])}
        self.assertEqual(COLLECTOR_NEEDS, have, "the resources the collector reads")
        for rule in cluster_role["rules"]:
            self.assertFalse(reads_secrets(rule), rule)
            self.assertLessEqual(set(rule["verbs"]), READ, rule)

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

    def test_the_crds_are_not_deleted_with_the_application(self):
        crds = [d for d in docs("prometheus-crds") if d["kind"] == "CustomResourceDefinition"]
        self.assertTrue(crds)
        for crd in crds:
            self.assertEqual("Delete=false", crd["metadata"].get("annotations", {}).get("argocd.argoproj.io/sync-options"), name(crd))


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


class MonitoringSmoke(unittest.TestCase):
    """scripts/monitoring-smoke.sh runs what the manifest gives: its one overlay is the addresses of the destinations."""

    def script(self):
        return (REPO / "infra/k8s/scripts/monitoring-smoke.sh").read_text()

    def test_the_overlay_has_only_the_addresses_of_the_destinations(self):
        overlay = self.script().split('cat > "$KUBE_DIR/overlay.yaml" <<YAML\n', 1)[1].split("\nYAML\n", 1)[0]
        paths, stack = set(), []
        for line in overlay.splitlines():
            indent = (len(line) - len(line.lstrip())) // 2
            key, _, value = line.strip().partition(":")
            stack[indent:] = [key]
            if value.strip():
                paths.add(".".join(stack))
        self.assertEqual({"destinations.grafana-metrics.url", "destinations.grafana-metrics.rules.address", "destinations.grafana-logs.url"}, paths)

    def test_the_chart_is_installed_with_the_values_of_the_manifest_and_the_overlay_only(self):
        script = self.script()
        install = re.search(r'helm_cluster install "\$release" .*?(?<!\\)\n', script, re.S).group(0)
        self.assertIn('-f "$in_cache/monitoring-values.yaml" -f "$in_cache/overlay.yaml" $break_args', install)
        self.assertNotIn("--set", install)
        # The only other values: the break of the test, set only when it is asked for.
        self.assertEqual(['break_args="--set alloy-operator.rbac.create=true"'], re.findall(r'break_args="--set[^"]*"', script))
        self.assertIn('if [ "$MONITORING_SMOKE_BREAK" = operator-cluster-role ]; then', script)


if __name__ == "__main__":
    unittest.main()

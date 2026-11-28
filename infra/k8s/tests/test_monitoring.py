"""The application monitoring (docs/features/deploy-ops/spec.md, O5): the chart k8s-monitoring rendered with the values of
argocd/apps/monitoring.yaml (scripts/monitoring-render.sh): what only Alloy may read in the cluster, the metrics it keeps and the
Alloy resources. The manifests of the applications and their waves are in test_argocd.py, the stages that mask the logs in
test_log_masking.py, the budget of the node in test_workloads.py."""

import json
import re
import unittest

from support import RENDER, REPO, WAVE, WORKLOADS, containers, docs, env_of, name, of_kind, pod_spec

# The metrics that the alerts of the spec (O6) use.
ALERT_METRICS = [
    "kube_cronjob_status_last_successful_time", "kube_job_status_condition", "kube_job_owner", "node_filesystem_avail_bytes",
    "node_filesystem_size_bytes", "kube_node_info", "kube_deployment_status_replicas_available", "kube_deployment_spec_replicas",
    "kube_statefulset_status_replicas_ready", "kube_statefulset_replicas",
]
# The metrics that hypercare of /oncall reads (docs/features/oncall/spec.md: Przebieg, Hypercare): the image tag and the owner
# of a pod, its readiness and the restarts of its containers.
ONCALL_METRICS = [
    "kube_pod_container_info", "kube_pod_owner", "kube_pod_status_ready", "kube_pod_container_status_restarts_total",
]
# What the node and the containers add for the sizing of the memory and the disk (docs/features/runtime-config/spec.md, Z1 and D7): the
# memory of a container that the kernel cannot take back and its page cache, and the pressure stall information of the node.
MEMORY_METRICS = ["container_memory_rss", "container_memory_cache"]
PRESSURE_METRICS = [
    "node_pressure_cpu_waiting_seconds_total", "node_pressure_io_stalled_seconds_total", "node_pressure_io_waiting_seconds_total",
    "node_pressure_memory_stalled_seconds_total", "node_pressure_memory_waiting_seconds_total",
]
SECRET_OF_ALLOY = "grafana-cloud"
# The Secrets that Alloy reads in the namespace shop: its tokens, and the DSN of the exporter of Postgres (the role exporter has pg_monitor only).
SECRET_OF_EXPORTER = "shop-exporter"
SECRETS_OF_ALLOY = [SECRET_OF_ALLOY, SECRET_OF_EXPORTER]
# The Grafana of the stack: the alert rules are imported there (AlertRules).
GRAFANA = "https://cosmiccinnamon1835.grafana.net"
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


# The subresources (of the core API group) that give more than the role says, whatever the verb: a shell or the network of a pod
# (exec, attach, portforward, an ephemeral container), a token of any service account of the namespace, the API of the kubelet.
ESCALATING_SUBRESOURCES = {"pods/exec", "pods/attach", "pods/portforward", "pods/ephemeralcontainers", "serviceaccounts/token", "nodes/proxy"}


def covers(pattern, resource):
    """Whether a resource of a rule (*, pods/*, */exec or a name) covers a resource."""
    if pattern in ("*", resource):
        return True
    if pattern.endswith("/*"):
        return resource.startswith(pattern[:-1])
    if pattern.startswith("*/"):
        return resource.endswith(pattern[1:])
    return False


def escalates(rule):
    """Whether a rule lets its holder give itself more: bind, escalate or impersonate, any verb (*), a change of RBAC objects
    (Roles, ClusterRoles and their bindings), or any verb on a subresource of ESCALATING_SUBRESOURCES (also through a wildcard)."""
    verbs = set(rule.get("verbs", []))
    if verbs & {"bind", "escalate", "impersonate", "*"}:
        return True
    if "nonResourceURLs" in rule:
        return False
    groups = set(rule.get("apiGroups", [""]))
    if {"", "*"} & groups and verbs and any(covers(p, s) for p in rule.get("resources", []) for s in ESCALATING_SUBRESOURCES):
        return True
    return bool({"rbac.authorization.k8s.io", "*"} & groups) and bool(verbs - READ)


# The groups every service account of a namespace is in (the last one with the name of the namespace appended).
SERVICE_ACCOUNT_GROUPS = ("system:authenticated", "system:serviceaccounts")


def subject_covers(subject, account, binding_namespace=None):
    """Whether a subject of a binding gives its rights to a service account (OPERATOR, COLLECTOR): the account itself (kind, name and
    namespace, a missing namespace being that of the binding; the apiGroup does not matter), its user name, or a group it is in."""
    kind, subject_name = subject.get("kind"), subject.get("name")
    if kind == "ServiceAccount":
        return (subject_name, subject.get("namespace") or binding_namespace) == (account["name"], account["namespace"])
    if kind == "User":
        return subject_name == f"system:serviceaccount:{account['namespace']}:{account['name']}"
    if kind == "Group":
        return subject_name in SERVICE_ACCOUNT_GROUPS + (f"system:serviceaccounts:{account['namespace']}",)
    return False


def only(subjects, account, binding_namespace=None):
    """Whether the subjects of a binding are exactly the one service account (no group, no other subject)."""
    return (len(subjects) == 1 and subjects[0].get("kind") == "ServiceAccount"
            and subject_covers(subjects[0], account, binding_namespace))


def problems(render):
    """What is wrong with the RBAC of the documents of a render (a list of messages; empty: nothing). No role of the render can
    give more (escalates); a ClusterRole only reads, and never Secrets; a Role is in shop or monitoring; the one Role with Secrets
    outside monitoring is the Role of Alloy on its token in shop, bound to Alloy only; a Role with Secrets in monitoring (the releases
    of the operator) is bound to the operator only; the operator has no ClusterRoleBinding and no binding outside monitoring. A
    subject is matched by subject_covers (groups of service accounts included)."""
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
                found.append(f"{kind}/{role_name} can bind, escalate, impersonate, change RBAC objects or reach into pods, tokens and nodes: {rule}")
            if kind == "ClusterRole" and not set(rule.get("verbs", [])) <= READ:
                found.append(f"ClusterRole/{role_name} does more than read in the whole cluster: {rule}")
            if reads_secrets(rule):
                if kind == "Role" and namespace == "monitoring":
                    monitoring_readers.add(role_name)
                else:
                    readers.append((kind, role_name, namespace, rule))
    if len(readers) != 1:
        found.append(f"roles of the render with access to Secrets outside monitoring: {readers}")
    token_role = None
    for kind, role_name, namespace, rule in readers[:1]:
        token_role = (role_name, namespace)
        if (kind, namespace) != ("Role", "shop"):
            found.append(f"the one role with access to Secrets is {kind}/{role_name} in {namespace}, not a Role in shop")
        if rule.get("resourceNames") != SECRETS_OF_ALLOY or rule["resources"] != ["secrets"] or rule["apiGroups"] != [""]:
            found.append(f"the Role does not name exactly the Secrets of Alloy: {rule}")
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
        if any(subject_covers(s, OPERATOR, namespace) for s in subjects) and (binding["kind"] != "RoleBinding" or namespace != "monitoring"):
            found.append(f"{binding['kind']}/{name(binding)} gives the operator rights outside the namespace monitoring")
        if ref["kind"] == "Role" and namespace == "monitoring" and ref["name"] in monitoring_readers and not only(subjects, OPERATOR, namespace):
            found.append(f"RoleBinding/{name(binding)} gives the Secrets of monitoring to {subjects}, not to the operator only")
        if ref["kind"] == "Role" and (ref["name"], namespace) == token_role and not only(subjects, COLLECTOR, namespace):
            found.append(f"RoleBinding/{name(binding)} gives the Secret {SECRET_OF_ALLOY} to {subjects}, not to Alloy only")
    return found


def role(kind, role_name, namespace, rules, **extra):
    metadata = {"name": role_name} | ({"namespace": namespace} if namespace else {})
    return {"apiVersion": "rbac.authorization.k8s.io/v1", "kind": kind, "metadata": metadata, "rules": rules} | extra


def binding(kind, binding_name, namespace, ref_kind, ref_name, subject=COLLECTOR):
    metadata = {"name": binding_name} | ({"namespace": namespace} if namespace else {})
    return {"kind": kind, "metadata": metadata, "roleRef": {"kind": ref_kind, "name": ref_name, "apiGroup": "rbac.authorization.k8s.io"},
            "subjects": [subject]}


GOOD_ROLE = role("Role", "alloy-grafana-cloud", "shop", [{"apiGroups": [""], "resources": ["secrets"], "resourceNames": SECRETS_OF_ALLOY, "verbs": ["get"]}])
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

    def test_a_subject_is_matched_by_kind_name_and_namespace_and_by_the_groups_of_service_accounts(self):
        for subject in (
            OPERATOR | {"apiGroup": ""},
            {"kind": "User", "name": "system:serviceaccount:monitoring:monitoring-alloy-operator", "apiGroup": "rbac.authorization.k8s.io"},
            {"kind": "Group", "name": "system:serviceaccounts", "apiGroup": "rbac.authorization.k8s.io"},
            {"kind": "Group", "name": "system:serviceaccounts:monitoring", "apiGroup": "rbac.authorization.k8s.io"},
            {"kind": "Group", "name": "system:authenticated", "apiGroup": "rbac.authorization.k8s.io"},
        ):
            with self.subTest(subject=subject):
                bad = binding("ClusterRoleBinding", "y", None, "ClusterRole", "collector", subject)
                self.assertIn("ClusterRoleBinding/y gives the operator rights outside the namespace monitoring", problems(GOOD + [bad]))
        for other in ({"kind": "Group", "name": "system:serviceaccounts:shop"}, {"kind": "ServiceAccount", "name": "monitoring-alloy-operator", "namespace": "shop"}):
            with self.subTest(other=other):
                self.assertFalse(subject_covers(other, OPERATOR))

    def test_the_subjects_of_a_good_binding_may_name_their_api_group(self):
        good = [d for d in GOOD if d["kind"] not in ("RoleBinding", "ClusterRoleBinding")] + [
            binding("RoleBinding", "alloy-grafana-cloud", "shop", "Role", "alloy-grafana-cloud", COLLECTOR | {"apiGroup": ""}),
            binding("RoleBinding", "operator", "monitoring", "Role", "operator", OPERATOR | {"apiGroup": ""}),
            binding("ClusterRoleBinding", "collector", None, "ClusterRole", "collector", COLLECTOR | {"apiGroup": ""}),
        ]
        self.assertEqual([], problems(good))

    def test_the_secrets_of_monitoring_and_the_token_are_not_for_a_group(self):
        for group in ("system:serviceaccounts", "system:serviceaccounts:monitoring", "system:authenticated"):
            subject = {"kind": "Group", "name": group, "apiGroup": "rbac.authorization.k8s.io"}
            with self.subTest(group=group):
                self.assertIn(f"RoleBinding/y gives the Secrets of monitoring to {[subject]}, not to the operator only",
                              problems(GOOD + [binding("RoleBinding", "y", "monitoring", "Role", "operator", subject)]))
                self.assertIn(f"RoleBinding/y gives the Secret {SECRET_OF_ALLOY} to {[subject]}, not to Alloy only",
                              problems(GOOD + [binding("RoleBinding", "y", "shop", "Role", "alloy-grafana-cloud", subject)]))

    def test_exec_attach_port_forward_tokens_and_the_proxy_of_the_node_are_an_escalation(self):
        for rule in (
            {"apiGroups": [""], "resources": ["pods/exec"], "verbs": ["create"]},
            {"apiGroups": [""], "resources": ["pods/exec"], "verbs": ["get"]},
            {"apiGroups": [""], "resources": ["pods/attach"], "verbs": ["create"]},
            {"apiGroups": [""], "resources": ["pods/portforward"], "verbs": ["create"]},
            {"apiGroups": [""], "resources": ["pods/ephemeralcontainers"], "verbs": ["patch"]},
            {"apiGroups": [""], "resources": ["serviceaccounts/token"], "verbs": ["create"]},
            {"apiGroups": [""], "resources": ["nodes/proxy"], "verbs": ["get"]},
            {"apiGroups": [""], "resources": ["pods/*"], "verbs": ["get"]},
            {"apiGroups": [""], "resources": ["*/proxy"], "verbs": ["get"]},
            {"apiGroups": ["*"], "resources": ["*"], "verbs": ["get"]},
        ):
            with self.subTest(rule=rule):
                self.assertTrue(escalates(rule))
                self.assertTrue(problems(GOOD + [role("Role", "x", "monitoring", [rule])]))
        for rule in (
            {"apiGroups": [""], "resources": ["nodes/metrics", "pods/log", "pods", "nodes"], "verbs": ["get", "list"]},
            {"apiGroups": ["apps"], "resources": ["deployments"], "verbs": ["create"]},
            {"apiGroups": ["metrics.k8s.io"], "resources": ["pods/exec"], "verbs": ["get"]},
        ):
            with self.subTest(rule=rule):
                self.assertFalse(escalates(rule))


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
        self.assertTrue(only(binding["subjects"], {"name": service_account, "namespace": alloy["metadata"]["namespace"]}, "shop"), binding["subjects"])

    def test_alloy_gets_its_cluster_role_from_the_render_not_from_the_operator(self):
        # The operator has no rights on RBAC objects, so the Alloy resource makes none (the default of the Alloy chart is a
        # ClusterRole that reads all Secrets and ConfigMaps of the cluster); its ClusterRole and binding are in the render.
        alloys = of_kind("monitoring", "Alloy")
        self.assertEqual(1, len(alloys))
        self.assertIs(False, (alloys[0]["spec"].get("rbac") or {}).get("create"), "the Alloy resource makes its own RBAC")
        service_account = alloys[0]["spec"].get("serviceAccount", {}).get("name") or name(alloys[0])
        self.assertEqual(COLLECTOR, {"kind": "ServiceAccount", "name": service_account, "namespace": alloys[0]["metadata"]["namespace"]})
        bindings = [b for b in of_kind("monitoring", "ClusterRoleBinding") if any(subject_covers(x, COLLECTOR) for x in b["subjects"])]
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
        destinations = values()["destinations"]
        self.assertEqual({"grafana-metrics", "grafana-logs", "grafana-alerting"}, set(destinations))
        for destination in destinations.values():
            self.assertFalse(destination["secret"]["create"])
            self.assertEqual({"name": SECRET_OF_ALLOY, "namespace": "shop", "create": False}, destination["secret"])
        for name in ("grafana-metrics", "grafana-logs"):
            self.assertEqual("GRAFANA_CLOUD_TOKEN", destinations[name]["auth"]["passwordKey"])
        alerting = destinations["grafana-alerting"]["auth"]
        self.assertEqual({"type": "bearerToken", "bearerTokenKey": "GRAFANA_ALERTING_TOKEN"}, alerting)
        self.assertEqual([], of_kind("monitoring", "Secret"), "the render creates a Secret")

    def test_the_render_names_the_secret_in_the_shop_in_the_configuration_of_alloy(self):
        config = (RENDER / "monitoring-config.alloy").read_text()
        blocks = re.findall(r'remote\.kubernetes\.secret "[^"]+" \{\s*name\s*=\s*"([^"]+)"\s*namespace\s*=\s*"([^"]+)"', config)
        self.assertEqual({(SECRET_OF_ALLOY, "shop"), (SECRET_OF_EXPORTER, "shop")}, set(blocks))

    def test_no_resource_of_the_render_is_a_secret_or_a_workload_outside_its_namespace(self):
        for doc in docs("monitoring"):
            namespace = doc["metadata"].get("namespace")
            if doc["kind"] in ("Role", "RoleBinding") and namespace == "shop":
                continue
            if doc["kind"] in CLUSTER_SCOPED:
                continue
            self.assertEqual("monitoring", namespace, f"{doc['kind']}/{name(doc)}")

    def test_the_operator_changes_only_its_alloy_and_makes_only_deployments(self):
        # Argo CD makes the Alloy resource; the operator reads it, keeps its finalizer and status, and makes of it, through the Alloy
        # chart (presets: deployment), a Deployment, its service account and its Service, all named like the resource, and keeps its
        # Helm releases (Secrets of changing names). It may create other objects of those kinds (create cannot be limited to a name),
        # but change and delete only those of its Alloy: not kube-state-metrics, node-exporter or itself. No DaemonSet, no
        # StatefulSet, no ConfigMap, no pod.
        alloy = name(of_kind("monitoring", "Alloy")[0])
        operator_role = [r for r in of_kind("monitoring", "Role") if name(r) == OPERATOR["name"]]
        self.assertEqual(1, len(operator_role))
        self.assertEqual("monitoring", operator_role[0]["metadata"]["namespace"])
        have = {(group, resource, verb, resource_name)
                for rule in operator_role[0]["rules"] for group in rule.get("apiGroups", [""]) for resource in rule["resources"]
                for verb in rule["verbs"] for resource_name in rule.get("resourceNames") or [None]}
        wanted = set()
        for group, resources, verbs, names in (
            ("collectors.grafana.com", ["alloys"], ["get", "list", "watch", "update", "patch"], [None]),
            ("collectors.grafana.com", ["alloys/status"], ["get", "update", "patch"], [None]),
            ("collectors.grafana.com", ["alloys/finalizers"], ["update"], [None]),
            ("", ["secrets"], ["get", "list", "watch", "create", "update", "patch", "delete"], [None]),
            ("", ["serviceaccounts", "services"], ["get", "list", "watch", "create"], [None]),
            ("", ["serviceaccounts", "services"], ["update", "patch", "delete"], [alloy]),
            ("apps", ["deployments"], ["get", "list", "watch", "create"], [None]),
            ("apps", ["deployments"], ["update", "patch", "delete"], [alloy]),
            ("coordination.k8s.io", ["leases"], ["get", "list", "watch", "create", "update", "patch", "delete"], [None]),
            ("", ["events"], ["create", "patch"], [None]),
        ):
            wanted |= {(group, resource, verb, n) for resource in resources for verb in verbs for n in names}
        self.assertEqual(sorted(wanted, key=str), sorted(have, key=str))


CLUSTER_SCOPED = ("ClusterRole", "ClusterRoleBinding", "CustomResourceDefinition", "ValidatingAdmissionPolicy", "ValidatingAdmissionPolicyBinding")
# The capabilities that the baseline profile of the Pod Security Standards allows to add.
BASELINE_CAPABILITIES = {"AUDIT_WRITE", "CHOWN", "DAC_OVERRIDE", "FOWNER", "FSETID", "KILL", "MKNOD", "NET_BIND_SERVICE", "SETFCAP",
                         "SETGID", "SETPCAP", "SETUID", "SYS_CHROOT"}
HOST_ACCESS = ("hostNetwork", "hostPID", "hostIPC", "hostPath", "hostPort", "privileged", "capabilities")
NODE_EXPORTER = "monitoring-node-exporter"
DAEMON_SET_CONTROLLER = "system:serviceaccount:kube-system:daemon-set-controller"
POLICY_HOST_ACCESS = "monitoring-no-host-access"
POLICY_TOKENS = "monitoring-no-service-account-tokens"
POLICY_SERVICES = "monitoring-cluster-ip-services"
POLICIES = {POLICY_HOST_ACCESS, POLICY_TOKENS, POLICY_SERVICES}
POD_TEMPLATES = {("", "pods"), ("", "pods/ephemeralcontainers"), ("apps", "deployments"), ("apps", "replicasets"), ("apps", "statefulsets"),
                 ("apps", "daemonsets"), ("batch", "jobs"), ("batch", "cronjobs")}


def host_access(spec):
    """What of HOST_ACCESS a pod spec asks for (the same as the policy monitoring-no-host-access, for the workloads of the render)."""
    every = spec["containers"] + spec.get("initContainers", []) + spec.get("ephemeralContainers", [])
    contexts = [c.get("securityContext") or {} for c in every]
    found = [field for field in ("hostNetwork", "hostPID", "hostIPC") if spec.get(field)]
    found += ["hostPath"] if any("hostPath" in v for v in spec.get("volumes", [])) else []
    found += ["hostPort"] if any(p.get("hostPort") for c in every for p in c.get("ports", [])) else []
    found += ["privileged"] if any(x.get("privileged") for x in contexts) else []
    added = {a for x in contexts for a in (x.get("capabilities") or {}).get("add", [])}
    return found + (["capabilities"] if added - BASELINE_CAPABILITIES else [])


class AdmissionPolicies(unittest.TestCase):
    """The operator (an image of a third party) makes workloads, Services and Secrets in monitoring. The ValidatingAdmissionPolicies
    of the render refuse there a pod or a template of a pod with access to the host (but node-exporter) or with a priority class of
    the system, a Service that is not a ClusterIP or has external IPs, and a Secret with a token of a service account, whoever asks
    (spec, O5); make -C infra/k8s monitoring-smoke tries them in k3d."""

    def objects(self, kind):
        return {name(d): d for d in of_kind("monitoring", kind)}

    def text(self, policy):
        spec = policy["spec"]
        return "\n".join([v["expression"] for v in spec.get("variables", [])] + [v["expression"] for v in spec["validations"]])

    def rules(self, policy):
        return {(group, resource, version, tuple(sorted(rule["operations"])))
                for rule in policy["spec"]["matchConstraints"]["resourceRules"]
                for group in rule["apiGroups"] for resource in rule["resources"] for version in rule["apiVersions"]}

    def test_three_policies_each_bound_to_the_namespace_monitoring_that_deny_and_fail_closed(self):
        policies, bindings = self.objects("ValidatingAdmissionPolicy"), self.objects("ValidatingAdmissionPolicyBinding")
        self.assertEqual(POLICIES, set(policies))
        self.assertEqual(POLICIES, {b["spec"]["policyName"] for b in bindings.values()})
        self.assertEqual(3, len(bindings))
        for policy in policies.values():
            with self.subTest(policy=name(policy)):
                self.assertEqual("Fail", policy["spec"]["failurePolicy"])
                self.assertNotIn("paramKind", policy["spec"])
                # A match condition, an object or namespace selector of the policy would leave out what it does not match.
                self.assertNotIn("matchConditions", policy["spec"])
                self.assertEqual({"resourceRules"}, set(policy["spec"]["matchConstraints"]) - {"matchPolicy"})
                self.assertIn(policy["spec"]["matchConstraints"].get("matchPolicy", "Equivalent"), ("Equivalent",))
        for binding_ in bindings.values():
            with self.subTest(binding=name(binding_)):
                self.assertEqual(["Deny"], binding_["spec"]["validationActions"])
                self.assertNotIn("paramRef", binding_["spec"])
                # kubernetes.io/metadata.name is set by the API server to the name of the namespace: no other namespace has it.
                self.assertEqual({"namespaceSelector": {"matchLabels": {"kubernetes.io/metadata.name": "monitoring"}}}, binding_["spec"]["matchResources"])

    def test_the_policies_and_their_bindings_come_before_everything_else_of_the_application(self):
        first = [d for d in docs("monitoring") if d["kind"] in ("ValidatingAdmissionPolicy", "ValidatingAdmissionPolicyBinding")]
        self.assertEqual(6, len(first))
        waves = {int((d["metadata"].get("annotations") or {}).get(WAVE, "0")) for d in first}
        self.assertEqual(1, len(waves), waves)
        others = [int((d["metadata"].get("annotations") or {}).get(WAVE, "0")) for d in docs("monitoring") if d not in first]
        self.assertLess(waves.pop(), min(others))

    def test_the_host_policy_sees_every_pod_and_every_template_of_a_pod_on_create_and_update(self):
        policy = self.objects("ValidatingAdmissionPolicy")[POLICY_HOST_ACCESS]
        self.assertEqual({(g, r, "*", ("CREATE", "UPDATE")) for g, r in POD_TEMPLATES}, self.rules(policy))
        text = self.text(policy)
        for field in HOST_ACCESS + ("initContainers", "ephemeralContainers", "jobTemplate"):
            self.assertIn(field, text)
        allowed = re.search(r"\.add\.exists\(a, !\(a in \[([^\]]*)\]\)\)", text)
        self.assertTrue(allowed, "the policy has no list of the allowed capabilities")
        listed = set(re.findall(r"'([A-Z_]+)'", allowed.group(1)))
        self.assertEqual(BASELINE_CAPABILITIES, listed, "the capabilities the policy allows")

    def test_the_only_exception_is_node_exporter_which_the_operator_cannot_forge(self):
        policy = self.objects("ValidatingAdmissionPolicy")[POLICY_HOST_ACCESS]
        text = self.text(policy)
        # The pods: made by the controller of DaemonSets (its own service account in k3s, which nobody in monitoring can act as) for
        # the DaemonSet of node-exporter; the DaemonSet: by its name, from anybody but a service account of monitoring. Labels are no
        # proof: whoever makes a pod chooses them.
        self.assertIn(f"request.userInfo.username == '{DAEMON_SET_CONTROLLER}'", text)
        self.assertIn("ownerReferences", text)
        self.assertIn("!request.userInfo.username.startsWith('system:serviceaccount:monitoring:')", text)
        self.assertNotIn("labels", text)
        names = set(re.findall(r"'([a-z0-9-]+)'", text)) & {name(d) for d in docs("monitoring")}
        self.assertEqual({NODE_EXPORTER}, names, "the policy names other objects of the render")
        self.assertIn(NODE_EXPORTER, self.objects("DaemonSet"))

    def test_only_node_exporter_of_the_render_needs_access_to_the_host(self):
        needing = {f"{d['kind']}/{name(d)}": host_access(pod_spec(d)) for d in of_kind("monitoring", "Deployment", "StatefulSet", "DaemonSet", "Job", "CronJob")}
        self.assertEqual({f"DaemonSet/{NODE_EXPORTER}"}, {k for k, v in needing.items() if v}, needing)
        for alloy in of_kind("monitoring", "Alloy"):
            for part in ("alloy", "configReloader"):
                added = set(((alloy["spec"].get(part) or {}).get("securityContext") or {}).get("capabilities", {}).get("add", []))
                self.assertLessEqual(added, BASELINE_CAPABILITIES, part)
            self.assertFalse(alloy["spec"].get("controller", {}).get("hostNetwork"))
            self.assertFalse(any("hostPath" in v for v in alloy["spec"].get("controller", {}).get("volumes", {}).get("extra", [])))

    def test_no_pod_and_no_template_of_a_pod_gets_a_priority_class_of_the_system(self):
        # system-cluster-critical and system-node-critical are allowed in every namespace: a pod of monitoring with one would
        # preempt the pods of the shop. No exception, not even node-exporter.
        policy = self.objects("ValidatingAdmissionPolicy")[POLICY_HOST_ACCESS]
        priority = [v for v in policy["spec"]["validations"] if "priorityClassName" in v["expression"]]
        self.assertEqual(1, len(priority), "the policy has no validation of the priority class")
        expression = priority[0]["expression"]
        self.assertIn("variables.podSpec.priorityClassName.startsWith('system-')", expression)
        self.assertNotIn("nodeExporter", expression)
        self.assertEqual("Forbidden", priority[0]["reason"])
        for workload in of_kind("monitoring", "Deployment", "StatefulSet", "DaemonSet", "Job", "CronJob"):
            self.assertFalse(pod_spec(workload).get("priorityClassName", "").startswith("system-"), name(workload))
        for alloy in of_kind("monitoring", "Alloy"):
            self.assertFalse((alloy["spec"].get("controller") or {}).get("priorityClassName", "").startswith("system-"))

    def test_the_service_policy_lets_only_cluster_ips_without_external_addresses_through(self):
        # A NodePort or a LoadBalancer (ServiceLB of k3s) opens a port of the node past ufw and the policy on hostPort; external
        # IPs take over the traffic of the pods of the cluster to those addresses (CVE-2020-8554). ExternalName is a type too.
        policy = self.objects("ValidatingAdmissionPolicy")[POLICY_SERVICES]
        self.assertEqual({("", "services", "*", ("CREATE", "UPDATE"))}, self.rules(policy))
        text = self.text(policy)
        self.assertIn("object.spec.type == 'ClusterIP'", text)
        self.assertIn("!has(object.spec.externalIPs) || object.spec.externalIPs.size() == 0", text)
        # The only type the policy names is ClusterIP: no NodePort, LoadBalancer or ExternalName let through.
        self.assertEqual({"ClusterIP"}, set(re.findall(r"'([A-Za-z]+)'", text)))
        self.assertEqual(1, len(policy["spec"]["validations"]))
        self.assertEqual("Forbidden", policy["spec"]["validations"][0]["reason"])

    def test_every_service_of_the_render_is_a_cluster_ip_without_external_addresses(self):
        services = of_kind("monitoring", "Service")
        self.assertTrue(services)
        for service in services:
            self.assertIn(service["spec"].get("type", "ClusterIP"), ("ClusterIP",), name(service))
            self.assertFalse(service["spec"].get("externalIPs"), name(service))
        # The Service the operator makes of the Alloy resource: the default of the Alloy chart (ClusterIP).
        for alloy in of_kind("monitoring", "Alloy"):
            self.assertIn((alloy["spec"].get("service") or {}).get("type", "ClusterIP"), ("ClusterIP",))
            self.assertFalse((alloy["spec"].get("service") or {}).get("externalIPs"))

    def test_the_token_policy_refuses_secrets_with_a_token_of_a_service_account(self):
        policy = self.objects("ValidatingAdmissionPolicy")[POLICY_TOKENS]
        self.assertEqual({("", "secrets", "*", ("CREATE", "UPDATE"))}, self.rules(policy))
        self.assertIn("object.type != 'kubernetes.io/service-account-token'", self.text(policy))


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

    def test_the_list_is_not_empty_and_names_the_metrics_of_the_alerts_and_of_hypercare(self):
        listed = (values()["clusterMetrics"]["kube-state-metrics"]["metricsTuning"]["includeMetrics"]
                  + values()["hostMetrics"]["linuxHosts"]["metricsTuning"]["includeMetrics"])
        self.assertTrue(listed)
        for metric in ALERT_METRICS + ONCALL_METRICS:
            self.assertIn(metric, listed)

    def test_alloy_keeps_every_metric_of_the_alerts_and_only_a_list(self):
        for component, wanted in (
            ("kube_state_metrics", [m for m in ALERT_METRICS + ONCALL_METRICS if m.startswith("kube_")]),
            ("node_exporter", [m for m in ALERT_METRICS if m.startswith("node_")]),
        ):
            expressions = self.keep_expressions(component)
            self.assertEqual(1, len(expressions), f"{component} has no allow list (keep rule on the name of the metric)")
            self.assertNotIn(expressions[0], (".*", ".+", ""), "the list allows every metric")
            for metric in wanted:
                self.assertRegex(metric, f"^(?:{expressions[0]})$", f"{component} drops {metric}")

    def test_the_memory_of_the_containers_and_the_pressure_of_the_node_are_allowed(self):
        cadvisor = values()["clusterMetrics"]["cadvisor"]["metricsTuning"]
        self.assertTrue(cadvisor["useDefaultAllowList"])
        for metric in MEMORY_METRICS:
            self.assertIn(metric, cadvisor["includeMetrics"])
        for metric in PRESSURE_METRICS:
            self.assertIn(metric, values()["hostMetrics"]["linuxHosts"]["metricsTuning"]["includeMetrics"])
        # In the configuration that Alloy gets: the allow lists of cAdvisor and of node-exporter are lists, and name them.
        for component, wanted in (("cadvisor", MEMORY_METRICS), ("node_exporter", PRESSURE_METRICS)):
            expressions = self.keep_expressions(component)
            self.assertEqual(1, len(expressions), component)
            for metric in wanted:
                self.assertRegex(metric, f"^(?:{expressions[0]})$", f"{component} drops {metric}")

    def test_the_default_list_is_kept_not_widened_to_all_metrics(self):
        for component in ("kubelet", "cadvisor", "kube_state_metrics", "node_exporter"):
            for expression in self.keep_expressions(component):
                self.assertNotIn(expression, (".*", ".+"), component)


def extra_config():
    return values()["collectors"]["alloy"]["extraConfig"]


def code_of(text):
    """The Alloy text without its comments."""
    return "\n".join(line for line in text.splitlines() if not line.strip().startswith("//"))


def block(text, component, label):
    """The text of one component of Alloy: it ends at the first closing brace that starts a line."""
    start = text.index(f'{component} "{label}" {{')
    return text[start : text.index("\n}\n", start) + 2]


def relabel_rules(text, label):
    """The rules of a prometheus.relabel as (action, source_labels, regex) in their order."""
    found = []
    for rule in re.findall(r"\n  rule \{(.*?)\n  \}", block(text, "prometheus.relabel", label), re.S):
        action = re.search(r'action\s*=\s*"(\w+)"', rule)
        regex = re.search(r'regex\s*=\s*"([^"]*)"', rule)
        sources = re.search(r"source_labels\s*=\s*\[([^\]]*)\]", rule)
        found.append((action.group(1) if action else "replace", re.findall(r'"([^"]+)"', sources.group(1)) if sources else [],
                      regex.group(1) if regex else None))
    return found


# The metrics that the exporters of the databases send, with the number of their series on worf.pl: the databases of the shop (shop,
# keycloak and postgres; the template databases are dropped), the groups of sessions (a row of pg_stat_activity_count is a group of the
# role, the application, the state and the wait; four roles, a few applications and states and the waits that show: an estimate, 40),
# the nodes of Elasticsearch (one), the collectors of the garbage of the JVM, the areas of its memory, the pools and the breakers.
# The test compares the names with the allow lists of the manifest, so a new metric is a new line here with its count.
DATABASES, SESSION_GROUPS, NODES, GC_COLLECTORS, JVM_AREAS, POOLS, BREAKERS, COLORS = 3, 40, 1, 3, 2, 3, 6, 3
POSTGRES_SERIES = {
    "up": 1, "pg_up": 1, "pg_database_size_bytes": DATABASES, "pg_long_running_transactions": 1,
    "pg_long_running_transactions_oldest_timestamp_seconds": 1, "pg_settings_max_connections": 1, "pg_settings_shared_buffers_bytes": 1,
    "pg_stat_activity_count": SESSION_GROUPS, "pg_stat_bgwriter_buffers_alloc_total": 1,
    "pg_stat_checkpointer_buffers_written_total": 1, "pg_stat_checkpointer_num_requested_total": 1, "pg_stat_checkpointer_num_timed_total": 1,
    "pg_stat_checkpointer_sync_time_total": 1, "pg_stat_checkpointer_write_time_total": 1,
    **{f"pg_stat_database_{n}": DATABASES for n in (
        "active_time_seconds_total", "blk_read_time", "blk_write_time", "blks_hit", "blks_read", "deadlocks", "numbackends", "temp_bytes",
        "temp_files", "xact_commit", "xact_rollback")},
    "pg_wal_segments": 1, "pg_wal_size_bytes": 1,
}
ELASTICSEARCH_SERIES = {
    "up": 1, "elasticsearch_breakers_tripped": BREAKERS * NODES, "elasticsearch_cluster_health_active_shards": 1,
    "elasticsearch_cluster_health_initializing_shards": 1, "elasticsearch_cluster_health_number_of_nodes": 1,
    "elasticsearch_cluster_health_relocating_shards": 1, "elasticsearch_cluster_health_status": COLORS,
    "elasticsearch_cluster_health_unassigned_shards": 1, "elasticsearch_filesystem_data_available_bytes": NODES,
    "elasticsearch_filesystem_data_size_bytes": NODES, "elasticsearch_indices_indexing_index_time_seconds_total": NODES,
    "elasticsearch_indices_indexing_index_total": NODES, "elasticsearch_indices_query_cache_count": 2 * NODES,
    "elasticsearch_indices_query_cache_evictions": NODES, "elasticsearch_indices_query_cache_memory_size_bytes": NODES,
    "elasticsearch_indices_request_cache_evictions": NODES, "elasticsearch_indices_request_cache_memory_size_bytes": NODES,
    "elasticsearch_indices_search_query_time_seconds": NODES, "elasticsearch_indices_search_query_total": NODES,
    "elasticsearch_indices_segments_memory_bytes": NODES, "elasticsearch_indices_store_size_bytes": NODES,
    "elasticsearch_jvm_gc_collection_seconds_count": GC_COLLECTORS * NODES, "elasticsearch_jvm_gc_collection_seconds_sum": GC_COLLECTORS * NODES,
    "elasticsearch_jvm_memory_max_bytes": NODES, "elasticsearch_jvm_memory_used_bytes": JVM_AREAS * NODES, "elasticsearch_node_stats_up": NODES,
    "elasticsearch_os_cpu_percent": NODES, "elasticsearch_thread_pool_active_count": POOLS * NODES,
    "elasticsearch_thread_pool_queue_count": POOLS * NODES, "elasticsearch_thread_pool_rejected_count": POOLS * NODES,
}
# The names of the labels that the series may have, and the ones that must never be among them: the text of a query, the address of a
# client (pg_stat_statements and pg_stat_activity have them; the collectors of the list do not read the text, and the list of the labels
# is the second lock) and the address of a pod (a new series each time the pod is made again). usename, application_name, backend_type
# and the wait are the groups of pg_stat_activity_count: without them two groups have the same labels and a session is lost.
POSTGRES_LABELS = {"__name__", "job", "instance", "datname", "state", "usename", "application_name", "backend_type", "wait_event_type", "wait_event"}
ELASTICSEARCH_LABELS = {"__name__", "job", "instance", "cluster", "name", "area", "gc", "type", "cache", "breaker", "color", "mount", "path"}
QUERY_LABELS = {"query", "queryid", "query_id", "statement", "sql", "client_addr", "client_hostname", "client_port", "relname", "schemaname",
                "datid", "pid", "host"}
# The new series against the 10 thousand of the free plan of Grafana Cloud: this test allows a tenth of it.
NEW_SERIES_BUDGET = 1000
POSTGRES_COLLECTORS = ["database", "stat_bgwriter", "stat_checkpointer", "stat_database", "wal", "long_running_transactions"]


class ExporterConfig(unittest.TestCase):
    """The exporters of Postgres and Elasticsearch in the configuration of Alloy (docs/features/runtime-config/spec.md, D7)."""

    def test_the_series_of_the_allow_lists_are_counted_and_few(self):
        for label, counted in (("shop_postgres", POSTGRES_SERIES), ("shop_elasticsearch", ELASTICSEARCH_SERIES)):
            action, sources, regex = relabel_rules(extra_config(), label)[0]
            self.assertEqual(("keep", ["__name__"]), (action, sources), label)
            self.assertEqual(sorted(counted), sorted(regex.split("|")), f"the allow list of {label} and the table of this test differ")
        total = sum(POSTGRES_SERIES.values()) + sum(ELASTICSEARCH_SERIES.values())
        self.assertLessEqual(total, NEW_SERIES_BUDGET, f"{total} new series")
        print(f"\nthe exporters of the databases: {sum(POSTGRES_SERIES.values())} series of Postgres, {sum(ELASTICSEARCH_SERIES.values())} of "
              f"Elasticsearch, {total} together of the 10000 of the free plan ({len(POSTGRES_SERIES)} + {len(ELASTICSEARCH_SERIES)} metrics)")

    def test_only_the_named_labels_leave_and_none_is_the_text_of_a_query(self):
        self.assertFalse(POSTGRES_LABELS & QUERY_LABELS)
        self.assertFalse(ELASTICSEARCH_LABELS & QUERY_LABELS)
        for label, allowed in (("shop_postgres", POSTGRES_LABELS), ("shop_elasticsearch", ELASTICSEARCH_LABELS)):
            action, _, regex = relabel_rules(extra_config(), label)[-1]
            # The last rule: no rule after it can bring a label of another name.
            self.assertEqual("labelkeep", action, f"{label}: the last rule is not the list of the labels")
            self.assertEqual(sorted(allowed), sorted(regex.split("|")), label)

    def test_the_exporter_of_postgres_has_the_listed_collectors_and_never_the_statements(self):
        code = code_of(extra_config())
        postgres = block(code, "prometheus.exporter.postgres", "shop")
        collectors = re.search(r"enabled_collectors\s*=\s*\[([^\]]*)\]", postgres)
        self.assertEqual(POSTGRES_COLLECTORS, re.findall(r'"([^"]+)"', collectors.group(1)), "the list replaces the default list of collectors")
        self.assertNotIn("stat_statements", code)
        for attribute in ("custom_queries_config_path", "autodiscovery", "disable_default_metrics", "include_databases", "exclude_databases"):
            self.assertNotIn(attribute, postgres)
        for metric in POSTGRES_SERIES:
            self.assertNotIn("statements", metric)

    def test_the_dsn_is_a_secret_value_of_a_secret_of_the_cluster_and_nowhere_in_text(self):
        code = code_of(extra_config())
        self.assertRegex(block(code, "remote.kubernetes.secret", "shop_exporter"), r'name\s*=\s*"shop-exporter"\s*namespace\s*=\s*"shop"')
        self.assertRegex(block(code, "prometheus.exporter.postgres", "shop"),
                         r'data_source_names\s*=\s*\[remote\.kubernetes\.secret\.shop_exporter\.data\["POSTGRES_EXPORTER_DSN"\]\]')
        # A value of the type secret is not shown by Alloy; nonsensitive would make a string of it.
        self.assertNotIn("nonsensitive", code)
        self.assertNotIn("postgresql://", code)
        self.assertNotIn("postgres://", code)
        # Not in a ConfigMap: the rendered configuration of Alloy and the ConfigMaps of the chart of the shop have no DSN and no password.
        self.assertNotIn("postgresql://", (RENDER / "monitoring-config.alloy").read_text())
        for case in ("prod", "ci", "load"):
            for config_map in of_kind(case, "ConfigMap"):
                self.assertNotIn("postgresql://exporter", str(config_map.get("data")), f"{case}/{name(config_map)}")
        password = {name(s): s["stringData"] for s in of_kind("ci", "Secret")}["shop-secrets"]["EXPORTER_DB_PASSWORD"]
        for config_map in of_kind("ci", "ConfigMap"):
            self.assertNotIn(password, str(config_map.get("data")), name(config_map))

    def test_the_exporter_of_elasticsearch_has_the_statistics_of_the_cluster_and_the_nodes_only(self):
        elasticsearch = block(code_of(extra_config()), "prometheus.exporter.elasticsearch", "shop")
        self.assertRegex(elasticsearch, r'address\s*=\s*"http://elasticsearch\.shop\.svc\.cluster\.local:9200"')
        self.assertEqual(["all"], re.findall(r"^\s*(\w+)\s*=\s*true\s*$", elasticsearch, re.M), "more than the nodes and the health is on")
        for attribute in ("indices", "indices_settings", "shards", "snapshots", "cluster_settings", "slm", "aliases", "data_stream"):
            self.assertNotRegex(elasticsearch, rf"^\s*{attribute}\s*=\s*true", attribute)

    def test_the_series_go_through_the_allow_list_to_the_destination_of_the_metrics(self):
        text = extra_config()
        for exporter, label in (("postgres", "shop_postgres"), ("elasticsearch", "shop_elasticsearch")):
            scrape = block(text, "prometheus.scrape", label)
            self.assertIn(f"targets         = prometheus.exporter.{exporter}.shop.targets", scrape)
            self.assertIn(f"forward_to      = [prometheus.relabel.{label}.receiver]", scrape)
            self.assertIn("forward_to = [prometheus.remote_write.grafana_metrics.receiver]", block(text, "prometheus.relabel", label))
        config = (RENDER / "monitoring-config.alloy").read_text()
        self.assertIn('prometheus.remote_write "grafana_metrics" {', config)
        # Nothing but the scrape of the exporter reads its targets.
        for component in ("prometheus.exporter.postgres", "prometheus.exporter.elasticsearch"):
            self.assertEqual(1, config.count(f'{component} "shop"'))
            self.assertEqual(1, config.count(f"{component}.shop.targets"))

    def test_the_rendered_configuration_has_the_exporters_and_the_chart_still_has_its_own_metrics(self):
        config = (RENDER / "monitoring-config.alloy").read_text()
        for component in ('prometheus.exporter.postgres "shop"', 'prometheus.exporter.elasticsearch "shop"', 'remote.kubernetes.secret "shop_exporter"',
                          'prometheus.relabel "shop_postgres"', 'prometheus.relabel "shop_elasticsearch"', 'prometheus.relabel "kube_state_metrics"',
                          'prometheus.relabel "cadvisor"', 'prometheus.relabel "node_exporter"'):
            self.assertIn(component, config)


class ExporterSecret(unittest.TestCase):
    """The Secret that Alloy reads for the exporter of Postgres: in ci the chart makes it, in prod it is sealed with the password."""

    def test_the_dsn_is_the_role_exporter_with_the_password_of_the_secret_of_the_shop(self):
        secrets = {name(s): s["stringData"] for s in of_kind("ci", "Secret")}
        password = secrets["shop-secrets"]["EXPORTER_DB_PASSWORD"]
        self.assertEqual({"POSTGRES_EXPORTER_DSN": f"postgresql://exporter:{password}@postgres.shop.svc.cluster.local:5432/postgres?sslmode=disable"},
                         secrets["shop-exporter"])

    def test_no_workload_of_the_shop_reads_it(self):
        self.assertEqual([], of_kind("prod", "Secret"))
        for case in ("prod", "ci", "load"):
            for workload in of_kind(case, *WORKLOADS):
                spec = pod_spec(workload)
                for container in spec["containers"] + spec.get("initContainers", []):
                    for source in container.get("envFrom", []):
                        self.assertNotEqual(SECRET_OF_EXPORTER, source.get("secretRef", {}).get("name"), f"{case}/{name(workload)}")
                    for variable in container.get("env", []):
                        self.assertNotEqual(SECRET_OF_EXPORTER, variable.get("valueFrom", {}).get("secretKeyRef", {}).get("name"))

    def test_only_alloy_reads_it_and_with_the_one_role_of_the_secrets_of_alloy(self):
        roles = [r for r in of_kind("monitoring", "Role", "ClusterRole")
                 if any(SECRET_OF_EXPORTER in rule.get("resourceNames", []) for rule in r.get("rules") or [])]
        self.assertEqual(1, len(roles), "more than one role gives access to the Secret of the exporter")
        self.assertEqual(("Role", "shop"), (roles[0]["kind"], roles[0]["metadata"]["namespace"]))
        self.assertEqual(SECRETS_OF_ALLOY, roles[0]["rules"][0]["resourceNames"])
        # The Role is bound to Alloy only (the RBAC test above says it for every Role with Secrets; here the Secret is named).
        bindings = [b for b in of_kind("monitoring", "RoleBinding") if b["roleRef"]["name"] == name(roles[0])]
        self.assertEqual(1, len(bindings))
        self.assertTrue(only(bindings[0]["subjects"], COLLECTOR, "shop"), bindings[0]["subjects"])


class ExportersRun(unittest.TestCase):
    """scripts/exporters.sh: the exporters in a real Alloy, with no Postgres and no Elasticsearch to scrape (the path of the error)."""

    @staticmethod
    def sink():
        rows = [json.loads(line) for line in (RENDER / "exporters-sink.jsonl").read_text().splitlines() if line.strip()]
        assert not [r for r in rows if "error" in r], "the receiver could not read a push of Alloy"
        return [r for r in rows if "labels" in r]

    def test_alloy_runs_on_while_the_databases_are_not_there(self):
        self.assertEqual("running", (RENDER / "exporters-state.txt").read_text().strip())
        log = (RENDER / "exporters-alloy.log").read_text()
        # The run is a real one: both exporters did meet a database that is not there, and said so.
        self.assertRegex(log, r'component_id=prometheus\.exporter\.postgres\.shop err="[^"]*(?:lookup|dial tcp|connection)')
        self.assertRegex(log, r'level=(?:error|warn) msg="[^"]*" component_path=/ component_id=prometheus\.exporter\.elasticsearch\.shop')
        self.assertNotRegex(log, r"(?m)^(Error|panic)")

    def test_the_other_metrics_and_the_state_of_the_exporters_still_arrive(self):
        series = self.sink()
        names = {s["labels"]["__name__"] for s in series}
        self.assertIn("alloy_build_info", names, "the other metrics stopped")
        up = {s["labels"]["job"]: s["value"] for s in series if s["labels"]["__name__"] == "up"}
        self.assertEqual({"integrations/postgres": 1.0, "integrations/elasticsearch": 1.0}, up)
        by_name = {s["labels"]["__name__"]: s["value"] for s in series}
        # The exporters say themselves that their database is not there: 0, which a rule can alert on.
        self.assertEqual(0.0, by_name["pg_up"])
        self.assertEqual(0.0, by_name["elasticsearch_node_stats_up"])

    def test_only_the_named_labels_and_never_the_password_of_the_dsn(self):
        allowed = {"integrations/postgres": POSTGRES_LABELS, "integrations/elasticsearch": ELASTICSEARCH_LABELS}
        series = [s for s in self.sink() if s["labels"].get("job") in allowed]
        self.assertTrue(series)
        for one in series:
            self.assertLessEqual(set(one["labels"]), allowed[one["labels"]["job"]], one)
        for text in ((RENDER / "exporters-sink.jsonl").read_text(), (RENDER / "exporters-alloy.log").read_text()):
            self.assertNotIn("planted-dsn-password", text)

    def test_the_run_used_the_exporters_of_the_manifest_with_the_one_substitution_of_the_secret(self):
        ran = (RENDER / "exporters-config.alloy").read_text()
        self.assertNotIn("remote.kubernetes", ran)
        for component, label in (("prometheus.exporter.postgres", "shop"), ("prometheus.exporter.elasticsearch", "shop"),
                                 ("prometheus.relabel", "shop_postgres"), ("prometheus.relabel", "shop_elasticsearch"),
                                 ("prometheus.scrape", "shop_postgres"), ("prometheus.scrape", "shop_elasticsearch")):
            wanted = block(extra_config(), component, label).replace('remote.kubernetes.secret.shop_exporter.data["POSTGRES_EXPORTER_DSN"]',
                                                                    "local.file.shop_exporter.content")
            self.assertEqual(wanted, block(ran, component, label), f"{component} {label}")


class ExportersRealPostgres(unittest.TestCase):
    """scripts/exporters.sh, the second run: the exporter against a real Postgres of the image of the chart, prepared by the script of the
    shop (the role exporter with pg_monitor only), with eight sessions of five groups in the database shopdb."""

    @staticmethod
    def latest():
        """The last value of every series that arrived: {sorted labels: value}."""
        rows = [json.loads(line) for line in (RENDER / "exporters-real-sink.jsonl").read_text().splitlines() if line.strip()]
        assert not [r for r in rows if "error" in r], "the receiver could not read a push of Alloy"
        return {tuple(sorted(r["labels"].items())): r["value"] for r in rows if "labels" in r}

    def test_the_sessions_of_every_group_arrive_and_add_up_to_the_sessions_of_postgres(self):
        groups = {}
        for line in (RENDER / "exporters-real-sessions.txt").read_text().splitlines():
            usename, application, state, wait_type, wait, count = line.split("|")
            groups[(usename, application, state, wait_type, wait)] = int(count)
        self.assertEqual(8, sum(groups.values()), "the run did not start the sessions it describes")
        self.assertEqual(5, len(groups))
        got = {}
        for key, value in self.latest().items():
            labels = dict(key)
            if labels["__name__"] == "pg_stat_activity_count" and labels.get("datname") == "shopdb":
                got[tuple(labels.get(n, "") for n in ("usename", "application_name", "state", "wait_event_type", "wait_event"))] = int(value)
        # Every group is a series with its count: no session is lost to two rows with the same labels (with only datname and state, the six
        # active sessions of three groups would be one series and at most one of the three rows would be kept).
        self.assertEqual(groups, got)
        self.assertEqual(sum(groups.values()), sum(got.values()))
        self.assertGreater(len(got), len({state for _, _, state, _, _ in got}), "no more series than states: the groups are cut")

    def test_the_role_with_pg_monitor_only_gives_every_metric_of_the_list_and_no_other_label(self):
        series = [dict(key) | {"value": value} for key, value in self.latest().items()]
        names = {s["__name__"] for s in series if s.get("job") == "integrations/postgres"}
        self.assertEqual([], sorted(set(POSTGRES_SERIES) - names), "metrics of the list that a real Postgres does not give")
        self.assertEqual(1.0, next(s["value"] for s in series if s["__name__"] == "pg_up"))
        for one in series:
            if one.get("job") == "integrations/postgres":
                self.assertLessEqual(set(one) - {"value"}, POSTGRES_LABELS, one)
                self.assertNotIn(one.get("datname"), ("template0", "template1"))
        # The exporter's own session is there, as the role exporter.
        self.assertTrue(any(s["__name__"] == "pg_stat_activity_count" and s.get("usename") == "exporter" for s in series))
        for text in ((RENDER / "exporters-real-sink.jsonl").read_text(), (RENDER / "exporters-real-alloy.log").read_text()):
            for planted in ("planted-dsn-password", "planted-superuser-password", "planted-role-password"):
                self.assertNotIn(planted, text)

    def test_the_series_of_a_real_postgres_stay_within_the_estimate(self):
        counted = sum(1 for key in self.latest() if dict(key).get("job") == "integrations/postgres" and dict(key)["__name__"] != "up")
        self.assertLessEqual(counted, sum(POSTGRES_SERIES.values()) - POSTGRES_SERIES["up"], f"{counted} series of Postgres in the run")


class AlertRules(unittest.TestCase):
    """The PrometheusRule objects become Grafana-managed alert rules (spec, O6): Alloy syncs them to the import API of Grafana
    (/api/convert, compatible with the ruler API), so that their alerts go through the Alertmanager of Grafana and its email contact
    point. The ruler of the metrics would evaluate them without any Alertmanager to send them to (issue #594)."""

    def rules_blocks(self):
        config = (RENDER / "monitoring-config.alloy").read_text()
        return re.findall(r'mimir\.rules\.kubernetes "([^"]+)" \{(.*?)\} // mimir\.rules\.kubernetes', config, re.S)

    def test_only_the_alerting_destination_syncs_the_rules(self):
        enabled = [name for name, d in values()["destinations"].items() if d.get("rules", {}).get("enabled")]
        self.assertEqual(["grafana-alerting"], enabled)
        self.assertEqual(["grafana_alerting"], [name for name, _ in self.rules_blocks()])

    def test_the_rules_go_to_the_import_api_of_grafana_with_the_token_and_the_data_source_of_the_queries(self):
        (_, block), = self.rules_blocks()
        self.assertIn(f'address = "{GRAFANA}/api/convert"', block)
        self.assertIn('tenant_id = "1"', block)
        # The default prefix /prometheus gives the paths of the API: /api/convert/prometheus/config/v1/rules/<namespace>.
        self.assertNotIn("prometheus_http_prefix", block)
        self.assertNotIn("use_legacy_routes", block)
        self.assertRegex(block, r'authorization \{\s*type = "Bearer"\s*credentials = remote\.kubernetes\.secret\.grafana_alerting\.data\["GRAFANA_ALERTING_TOKEN"\]')
        self.assertNotIn("basic_auth", block)
        self.assertRegex(block, r'http_headers = \{\s*"X-Grafana-Alerting-Datasource-UID" = \["grafanacloud-prom"\],?\s*\}')

    def test_no_metric_and_no_log_goes_to_the_alerting_destination(self):
        for feature, settings in values().items():
            if isinstance(settings, dict) and "destinations" in settings and feature != "destinations":
                self.assertNotIn("grafana-alerting", settings["destinations"], feature)
        config = (RENDER / "monitoring-config.alloy").read_text()
        # The chart gives every prometheus destination an exporter that forwards to its remote_write; nothing forwards to either.
        forwards = re.findall(r"forward_to = \[([^\]]*)\]", config)
        self.assertEqual([], [f for f in forwards if "otelcol.exporter.prometheus.grafana_alerting" in f])
        self.assertEqual(1, len([f for f in forwards if "prometheus.remote_write.grafana_alerting" in f]))


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
        self.assertEqual({"destinations.grafana-metrics.url", "destinations.grafana-logs.url", "destinations.grafana-alerting.url",
                          "destinations.grafana-alerting.rules.address"}, paths)

    def test_the_chart_is_installed_with_the_values_of_the_manifest_and_the_overlay_only(self):
        script = self.script()
        install = re.search(r'helm_cluster install "\$release" .*?(?<!\\)\n', script, re.S).group(0)
        self.assertIn('-f "$in_cache/monitoring-values.yaml" -f "$in_cache/overlay.yaml" $break_args', install)
        self.assertNotIn("--set", install)
        # The only other values: the breaks of the test, set only when they are asked for.
        self.assertEqual(['break_args="--set alloy-operator.rbac.create=true"', 'break_args="-f $in_cache/break.yaml"'],
                         re.findall(r'break_args="[^"]+"', script))
        self.assertIn('if [ "$MONITORING_SMOKE_BREAK" = operator-cluster-role ]; then', script)
        self.assertIn('elif [ "$MONITORING_SMOKE_BREAK" = no-admission-policy ]; then', script)

    def test_the_requests_cover_the_services_and_the_priority_classes(self):
        # The policies on Services and on the priority classes are tried in the cluster as the operator and as the administrator.
        script = self.script()
        self.assertIn("service_policy=monitoring-cluster-ip-services", script)
        for request in ('"type":"NodePort"', '"type":"LoadBalancer"', '"type":"ExternalName"', '"externalIPs":', "system-cluster-critical",
                        "system-node-critical"):
            self.assertIn(request, script)


if __name__ == "__main__":
    unittest.main()

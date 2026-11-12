"""The application monitoring (docs/features/deploy-ops/spec.md, O5): the chart k8s-monitoring rendered with the values of
argocd/apps/monitoring.yaml (scripts/monitoring-render.sh): what only Alloy may read in the cluster, the metrics it keeps and the
Alloy resources. The manifests of the applications and their waves are in test_argocd.py, the stages that mask the logs in
test_log_masking.py, the budget of the node in test_workloads.py."""

import re
import unittest

from support import RENDER, REPO, WAVE, containers, docs, env_of, name, of_kind, pod_spec

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

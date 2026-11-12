"""The Argo CD manifests of argocd/ (spec: D4, D5, "Serwer, bootstrap i wdrożenie przez Claude Code"; DEPLOY-OPS, O5): the root creates
the five applications; the shop has one source, the branch deploy-images, with the profile and the images file; the CRDs of
Prometheus, the sealed secrets and their controller come in the waves before the shop, the monitoring (Alloy) after it; everything
is pinned (the charts of others by version) or tracks one revision (what is in git)."""

import re
import unittest

from support import REPO, docs

REPO_URL = "https://github.com/piotrp321/shop.git"
BRANCH = "deploy-images"
WAVE = "argocd.argoproj.io/sync-wave"


def named(case):
    return {d["metadata"]["name"]: d for d in docs(case)}


root = named("argocd-root")["root"]
apps = named("argocd-apps")
install = named("argocd-install")


class Root(unittest.TestCase):
    def test_it_tracks_the_apps_directory_of_the_deploy_images_branch(self):
        source = root["spec"]["source"]
        self.assertEqual((REPO_URL, BRANCH, "infra/k8s/argocd/apps"), (source["repoURL"], source["targetRevision"], source["path"]))
        self.assertEqual("argocd", root["spec"]["destination"]["namespace"])
        self.assertTrue((REPO / source["path"]).is_dir())

    def test_the_directory_it_reads_has_only_the_shop_its_secrets_and_the_platform_applications(self):
        self.assertEqual({"shop", "secrets", "sealed-secrets", "prometheus-crds", "monitoring"}, set(apps))
        for application in apps.values():
            self.assertEqual("Application", application["kind"])
            self.assertEqual("argocd", application["metadata"]["namespace"])
        files = sorted(p.name for p in (REPO / "infra/k8s/argocd/apps").iterdir())
        self.assertEqual(["monitoring.yaml", "prometheus-crds.yaml", "sealed-secrets.yaml", "secrets.yaml", "shop.yaml"], files)

    def test_it_synchronizes_by_itself_and_prunes(self):
        self.assertEqual({"prune": True, "selfHeal": True}, root["spec"]["syncPolicy"]["automated"])

    def test_the_emergency_rollback_of_the_runbook_stops_the_root_before_the_shop(self):
        # The root heals the shop application (and its syncPolicy) from git, and Argo CD refuses to sync an application with
        # automated sync to another revision: the rollback to a previous snapshot has to switch off the root first.
        self.assertEqual({"prune": True, "selfHeal": True}, root["spec"]["syncPolicy"]["automated"])
        self.assertEqual({"prune": True, "selfHeal": True}, apps["shop"]["spec"]["syncPolicy"]["automated"])
        readme = (REPO / "infra/k8s/README.md").read_text()
        section = re.search(r"^## 7\..*?(?=^## |\Z)", readme, re.S | re.M)
        self.assertIsNotNone(section, "README.md has no section '## 7.'")
        item = re.search(r"^- \*\*Cofnięcie wersji\*\*.*?(?=^- |\Z)", section.group(0), re.S | re.M)
        self.assertIsNotNone(item, "section 7 of README.md has no item 'Cofnięcie wersji'")
        emergency = item.group(0).split("Awaryjnie", 1)
        self.assertEqual(2, len(emergency), "the item has no emergency rollback ('Awaryjnie')")
        text = emergency[1]
        self.assertIn("`root`", text, "the emergency rollback does not switch off the automated sync of root")
        self.assertIn("`shop`", text)
        self.assertLess(text.index("`root`"), text.index("`shop`"), "root must be stopped before shop")

    def test_no_application_deletes_its_resources_with_itself(self):
        for application in (root, *apps.values()):
            self.assertEqual([], application["metadata"].get("finalizers", []), application["metadata"]["name"])


class Shop(unittest.TestCase):
    shop = apps["shop"]

    def test_one_source_the_chart_of_the_deploy_images_branch(self):
        spec = self.shop["spec"]
        self.assertNotIn("sources", spec)
        source = spec["source"]
        self.assertEqual((REPO_URL, BRANCH, "infra/k8s/charts/shop"), (source["repoURL"], source["targetRevision"], source["path"]))
        self.assertEqual("shop", spec["destination"]["namespace"])
        self.assertEqual("shop", source["helm"]["releaseName"])

    def test_the_values_are_the_profile_and_the_images_file_in_this_order_and_nothing_else(self):
        helm = self.shop["spec"]["source"]["helm"]
        self.assertEqual(["../../environments/prod/values.yaml", "../../environments/prod/values-images.yaml"], helm["valueFiles"])
        self.assertNotIn("ignoreMissingValueFiles", helm)
        self.assertEqual({"releaseName", "valueFiles"}, set(helm), "parameters or inline values would override the files")
        text = str(self.shop)
        self.assertNotIn("ARGOCD_APP_REVISION", text)

    def test_the_value_files_resolve_inside_the_repository(self):
        chart = REPO / "infra/k8s/charts/shop"
        profile = (chart / "../../environments/prod/values.yaml").resolve()
        self.assertTrue(profile.is_file())
        self.assertTrue(str(profile).startswith(str(REPO.resolve())))
        # The images file exists only on the branch deploy-images (CI adds it to the snapshot).
        self.assertFalse((chart / "../../environments/prod/values-images.yaml").exists())

    def test_it_comes_after_the_secrets_and_before_the_monitoring(self):
        waves = {name: int(a["metadata"]["annotations"][WAVE]) for name, a in apps.items()}
        self.assertEqual({"prometheus-crds": -3, "sealed-secrets": -2, "secrets": -1, "shop": 0, "monitoring": 1}, waves)

    def test_creating_the_namespace_and_synchronizing_by_itself(self):
        policy = self.shop["spec"]["syncPolicy"]
        self.assertEqual({"prune": True, "selfHeal": True}, policy["automated"])
        self.assertIn("CreateNamespace=true", policy["syncOptions"])


class Secrets(unittest.TestCase):
    def test_the_sealed_secrets_of_prod_in_the_namespace_shop(self):
        secrets = apps["secrets"]["spec"]
        source = secrets["source"]
        self.assertEqual((REPO_URL, BRANCH, "infra/k8s/environments/prod/secrets"), (source["repoURL"], source["targetRevision"], source["path"]))
        self.assertTrue((REPO / source["path"]).is_dir())
        self.assertEqual("shop", secrets["destination"]["namespace"])
        self.assertIn("SkipDryRunOnMissingResource=true", secrets["syncPolicy"]["syncOptions"])

    def test_the_controller_is_a_published_chart_pinned_by_version(self):
        source = apps["sealed-secrets"]["spec"]["source"]
        self.assertEqual("sealed-secrets", source["chart"])
        self.assertTrue(source["repoURL"].startswith("https://"))
        self.assertRegex(source["targetRevision"], r"^\d+\.\d+\.\d+$")
        # kubeseal and the key of the backup assume this name in kube-system.
        self.assertEqual("sealed-secrets-controller", source["helm"]["valuesObject"]["fullnameOverride"])
        self.assertEqual("kube-system", apps["sealed-secrets"]["spec"]["destination"]["namespace"])

    def test_the_key_of_the_controller_is_not_rotated(self):
        # A rotating key (every 30 days by default) makes the certificate in the repository and the byte comparison with the
        # controller's wrong, and an old backup incomplete.
        self.assertEqual("0", apps["sealed-secrets"]["spec"]["source"]["helm"]["valuesObject"]["keyrenewperiod"])


class Observability(unittest.TestCase):
    """DEPLOY-OPS, O5: the CRDs of Prometheus in the first wave, Alloy (the chart k8s-monitoring) in the last."""

    crds = apps["prometheus-crds"]
    monitoring = apps["monitoring"]

    def test_the_crds_are_a_published_chart_pinned_by_version_and_applied_on_the_server_side(self):
        source = self.crds["spec"]["source"]
        self.assertEqual("prometheus-operator-crds", source["chart"])
        self.assertTrue(source["repoURL"].startswith("https://"))
        self.assertRegex(source["targetRevision"], r"^\d+\.\d+\.\d+$")
        # The CRDs are larger than the annotation of a client-side apply can hold.
        self.assertIn("ServerSideApply=true", self.crds["spec"]["syncPolicy"]["syncOptions"])
        self.assertEqual("-3", self.crds["metadata"]["annotations"][WAVE])

    def test_alloy_is_the_chart_k8s_monitoring_4_pinned_in_the_last_wave_after_the_shop(self):
        source = self.monitoring["spec"]["source"]
        self.assertEqual("k8s-monitoring", source["chart"])
        self.assertTrue(source["repoURL"].startswith("https://"))
        self.assertRegex(source["targetRevision"], r"^4\.\d+\.\d+$")
        self.assertEqual("monitoring", self.monitoring["spec"]["destination"]["namespace"])
        self.assertEqual("monitoring", source["helm"]["releaseName"])
        self.assertGreater(int(self.monitoring["metadata"]["annotations"][WAVE]), int(apps["shop"]["metadata"]["annotations"][WAVE]))
        self.assertEqual("1", self.monitoring["metadata"]["annotations"][WAVE])

    def test_the_namespace_is_created_and_the_resource_of_a_crd_of_the_same_sync_is_not_a_dry_run_error(self):
        options = self.monitoring["spec"]["syncPolicy"]["syncOptions"]
        self.assertIn("CreateNamespace=true", options)
        self.assertIn("SkipDryRunOnMissingResource=true", options)
        self.assertEqual({"prune": True, "selfHeal": True}, self.monitoring["spec"]["syncPolicy"]["automated"])

    def test_the_values_are_in_the_manifest_not_in_the_chart_of_the_shop(self):
        values = self.monitoring["spec"]["source"]["helm"]["valuesObject"]
        self.assertEqual({"grafana-metrics": "prometheus", "grafana-logs": "loki"}, {n: d["type"] for n, d in values["destinations"].items()})
        self.assertTrue(values["destinations"]["grafana-metrics"]["rules"]["enabled"])
        # The address of the ruler is the base address of the stack, not the one that metrics are pushed to.
        metrics = values["destinations"]["grafana-metrics"]
        self.assertFalse(metrics["rules"]["address"].endswith("/push"))
        self.assertTrue(metrics["url"].endswith("/api/prom/push"))
        text = (REPO / "infra/k8s/environments/prod/values.yaml").read_text()
        self.assertNotIn("grafana", text.lower())

    def test_the_two_new_applications_are_in_their_own_files_and_the_root_directory_is_not_recursive(self):
        for file in ("prometheus-crds.yaml", "monitoring.yaml"):
            self.assertTrue((REPO / "infra/k8s/argocd/apps" / file).is_file())
        self.assertFalse(root["spec"]["source"]["directory"]["recurse"])


class AllApplications(unittest.TestCase):
    def test_everything_from_git_tracks_the_same_branch_of_the_same_repository(self):
        sources = [a["spec"]["source"] for a in (root, *apps.values())]
        git = [s for s in sources if "chart" not in s]
        self.assertEqual(3, len(git))
        self.assertEqual({(REPO_URL, BRANCH)}, {(s["repoURL"], s["targetRevision"]) for s in git})
        # The published charts (of others) are pinned by their version: Argo CD takes them from their repositories, so they
        # are not on the branch, and nothing of them floats.
        published = {a["metadata"]["name"]: a["spec"]["source"] for a in apps.values() if "chart" in a["spec"]["source"]}
        self.assertEqual({"sealed-secrets", "prometheus-crds", "monitoring"}, set(published))
        for source in published.values():
            self.assertRegex(source["targetRevision"], r"^\d+\.\d+\.\d+$")

    def test_nothing_floats(self):
        for application in (root, *apps.values()):
            source = application["spec"]["source"]
            self.assertNotIn(source["targetRevision"], ("HEAD", "main", "master", "latest", "*", ""), application["metadata"]["name"])

    def test_each_wave_has_its_own_number_and_a_wait_for_health_semantics(self):
        waves = sorted(int(a["metadata"]["annotations"][WAVE]) for a in apps.values())
        self.assertEqual(waves, sorted(set(waves)))


class Install(unittest.TestCase):
    def test_argocd_is_a_pinned_published_chart_installed_by_the_helm_controller_of_k3s(self):
        chart = install["argocd"]
        self.assertEqual("HelmChart", chart["kind"])
        self.assertEqual("kube-system", chart["metadata"]["namespace"])
        spec = chart["spec"]
        self.assertEqual("argo-cd", spec["chart"])
        self.assertRegex(spec["version"], r"^\d+\.\d+\.\d+$")
        self.assertEqual("argocd", spec["targetNamespace"])
        self.assertTrue(spec["createNamespace"])
        self.assertTrue(spec["repo"].startswith("https://"))

    def test_the_names_of_the_deployments_the_bootstrap_waits_for(self):
        self.assertIn("fullnameOverride: argocd", install["argocd"]["spec"]["valuesContent"])
        bootstrap = (REPO / "infra/k8s/scripts/server-bootstrap.sh").read_text()
        self.assertIn("argocd-server argocd-repo-server", bootstrap)

    def test_the_root_waits_for_the_health_of_the_applications_of_the_waves(self):
        values = install["argocd"]["spec"]["valuesContent"]
        self.assertIn("resource.customizations.health.argoproj.io_Application: |", values)
        self.assertIn("hs.status = obj.status.health.status", values)
        self.assertIn('hs.status = "Progressing"', values)

    def test_traefik_keeps_the_address_of_the_client(self):
        config = install["traefik"]
        self.assertEqual("HelmChartConfig", config["kind"])
        self.assertIn("externalTrafficPolicy: Local", config["spec"]["valuesContent"])

    def test_the_names_the_applications_use_match_the_install(self):
        self.assertEqual("argocd", root["metadata"]["namespace"])
        self.assertEqual(install["argocd"]["spec"]["targetNamespace"], root["metadata"]["namespace"])


if __name__ == "__main__":
    unittest.main()

"""The Argo CD manifests of argocd/ (spec: D4, D5, "Serwer, bootstrap i wdrożenie przez Claude Code"): the root creates
the three applications; the shop has one source, the branch deploy-images, with the profile and the images file; the
sealed secrets and the controller come in the waves before the shop; everything is pinned and tracks one revision."""

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

    def test_the_directory_it_reads_has_only_the_shop_the_sealed_secrets_and_their_controller(self):
        self.assertEqual({"shop", "secrets", "sealed-secrets"}, set(apps))
        for application in apps.values():
            self.assertEqual("Application", application["kind"])
            self.assertEqual("argocd", application["metadata"]["namespace"])
        files = sorted(p.name for p in (REPO / "infra/k8s/argocd/apps").iterdir())
        self.assertEqual(["sealed-secrets.yaml", "secrets.yaml", "shop.yaml"], files)

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

    def test_it_is_the_last_wave(self):
        waves = {name: int(a["metadata"]["annotations"][WAVE]) for name, a in apps.items()}
        self.assertEqual({"sealed-secrets": -2, "secrets": -1, "shop": 0}, waves)

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


class AllApplications(unittest.TestCase):
    def test_everything_from_git_tracks_the_same_branch_of_the_same_repository(self):
        sources = [a["spec"]["source"] for a in (root, *apps.values())]
        git = [s for s in sources if "chart" not in s]
        self.assertEqual(3, len(git))
        self.assertEqual({(REPO_URL, BRANCH)}, {(s["repoURL"], s["targetRevision"]) for s in git})

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

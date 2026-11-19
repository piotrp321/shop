"""The profile `load` (environments/load/values.yaml) of the load measurement (docs/features/hardening/spec.md, D15): the
profile `ci` (a cluster without an IP policy, local images) with the resources of the pods of `prod` and the header of
the client address, which the pods trust without the allow list because the measurement says so (D3).

The case `load` is `ci` rendered with environments/load/values.yaml on top (scripts/render.sh, the same arguments as
scripts/load.sh)."""

import unittest

from support import WORKLOADS, containers, env_of, images, logical, name, of_kind, pod_spec

HEADER = "CF-Connecting-IP"
IPALLOWLIST = "shop-ipallowlist@kubernetescrd"
# What the profile ci has for the backup and the profile load does not (it has no backup).
BACKUP_CRONJOBS = ("backup", "backup-verify", "restore")


def resources(case):
    """The resources of every container of every workload, by `<workload>/<container>` (init containers included)."""
    found = {}
    for doc in of_kind(case, *WORKLOADS):
        spec = pod_spec(doc)
        for container in spec.get("initContainers", []) + spec["containers"]:
            found[f"{logical(doc)}/{container['name']}"] = container.get("resources")
    return found


def java_options(case):
    """The heap settings of the two JVM services: they size the pods like the resources do."""
    found = {}
    for doc in of_kind(case, "Deployment", "StatefulSet"):
        if logical(doc) in ("elasticsearch", "keycloak"):
            env = env_of(containers(doc)[0])
            found[logical(doc)] = {key: value for key, value in env.items() if "JAVA" in key or "OPTS" in key}
    return found


class LoadProfileTest(unittest.TestCase):
    def test_every_pod_has_the_resources_it_has_in_prod(self):
        # The load profile has no backup, like prod (see test_there_is_no_backup_in_the_load_profile). The CronJob demo-data is in
        # both, sized differently: for the measurement (test_the_generator_has_resources_of_its_own) and for the server (prod).
        load, prod = resources("load"), resources("prod")
        generator = {key: load.pop(key) for key in [key for key in load if key.startswith("demo-data/")]}
        self.assertEqual({"demo-data/demo-data"}, set(generator))
        self.assertEqual({"demo-data/demo-data"}, {key for key in prod if key.startswith("demo-data/")})
        prod.pop("demo-data/demo-data")
        self.assertEqual(set(prod), set(load))
        for key in prod:
            self.assertEqual(prod[key], load[key], key)

    def test_the_generator_has_resources_of_its_own(self):
        generator = resources("load")["demo-data/demo-data"]
        self.assertIn("cpu", generator["requests"])
        self.assertIn("memory", generator["requests"])
        self.assertIn("memory", generator["limits"])
        self.assertNotEqual(resources("load")["cart-purge-expired/cart-purge-expired"], generator)

    def test_there_is_no_backup_in_the_load_profile(self):
        """The measurement does not take backups: the cluster has no repository to take them to, and a restore is never wanted in it."""
        crons = {logical(doc) for doc in of_kind("load", "CronJob")}
        self.assertFalse(crons & set(BACKUP_CRONJOBS), crons)
        self.assertIn("demo-data", crons)
        self.assertFalse([doc for doc in of_kind("load", "Secret", "PersistentVolumeClaim") if "backup" in name(doc)])
        # ci keeps its backup: the smoke test checks it.
        self.assertTrue(set(BACKUP_CRONJOBS) <= {logical(doc) for doc in of_kind("ci", "CronJob")})

    def test_the_jvm_services_have_the_heap_of_prod(self):
        self.assertEqual(java_options("prod"), java_options("load"))
        self.assertTrue(all(java_options("load").values()), "elasticsearch and keycloak must show their heap options")

    def test_the_api_and_the_store_trust_the_header_of_the_client_address_and_nothing_else_does(self):
        found = {}
        for doc in of_kind("load", *WORKLOADS):
            for container in pod_spec(doc)["containers"]:
                value = env_of(container).get("CLIENT_IP_HEADER")
                if value is not None:
                    found[f"{logical(doc)}/{container['name']}"] = value
        self.assertEqual({"api/api": HEADER, "store/store": HEADER}, found)

    def test_there_is_no_allow_list_and_no_tls_like_in_ci(self):
        for ingress in of_kind("load", "Ingress"):
            middlewares = ingress["metadata"].get("annotations", {}).get("traefik.ingress.kubernetes.io/router.middlewares", "")
            self.assertNotIn(IPALLOWLIST, middlewares, name(ingress))
            self.assertNotIn("tls", ingress["spec"], name(ingress))

    def test_the_images_are_those_of_ci(self):
        """The measurement runs the production images built here, as the smoke test: the profile adds no digests."""
        ci = {logical(doc): sorted(images(doc)) for doc in of_kind("ci", *WORKLOADS) if logical(doc) not in BACKUP_CRONJOBS}
        load = {logical(doc): sorted(images(doc)) for doc in of_kind("load", *WORKLOADS)}
        self.assertEqual(ci, load)


if __name__ == "__main__":
    unittest.main()

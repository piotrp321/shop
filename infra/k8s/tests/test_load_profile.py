"""The profile `load` (environments/load/values.yaml) of the load measurement (docs/features/hardening/spec.md, D15): the
profile `ci` (a cluster without an IP policy, local images) with the resources of the pods of `prod` and the header of
the client address, which the pods trust without the allow list because the measurement says so (D3).

The case `load` is `ci` rendered with environments/load/values.yaml on top (scripts/render.sh, the same arguments as
scripts/load.sh)."""

import unittest

from support import WORKLOADS, containers, env_of, images, logical, name, of_kind, pod_spec

HEADER = "CF-Connecting-IP"
IPALLOWLIST = "shop-ipallowlist@kubernetescrd"


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
        load, prod = resources("load"), resources("prod")
        self.assertEqual(set(prod), set(load))
        for key in prod:
            self.assertEqual(prod[key], load[key], key)

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
        ci = {logical(doc): sorted(images(doc)) for doc in of_kind("ci", *WORKLOADS)}
        load = {logical(doc): sorted(images(doc)) for doc in of_kind("load", *WORKLOADS)}
        self.assertEqual(ci, load)


if __name__ == "__main__":
    unittest.main()

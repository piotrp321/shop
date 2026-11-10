"""D3 of docs/features/hardening/spec.md: the header of the client address (clientIpHeader) in the render.

The renders that must fail (clientIpHeader without ipAllowList and without the confirmation, the confirmation with
image.requireDigests) are in scripts/check.sh (do_schema), like every other refused value.
"""

import unittest

from support import WORKLOADS, containers, env_of, logical, name, of_kind, pod_spec

HEADER = "CF-Connecting-IP"
IPALLOWLIST = "shop-ipallowlist@kubernetescrd"


def client_ip_headers(case):
    """CLIENT_IP_HEADER of every container of every workload, by `<workload>/<container>`."""
    found = {}
    for doc in of_kind(case, *WORKLOADS):
        spec = pod_spec(doc)
        for container in spec.get("initContainers", []) + spec["containers"]:
            env = env_of(container)
            if "CLIENT_IP_HEADER" in env:
                found[f"{logical(doc)}/{container['name']}"] = env["CLIENT_IP_HEADER"]
    return found


def middlewares_of(ingress):
    value = ingress["metadata"].get("annotations", {}).get("traefik.ingress.kubernetes.io/router.middlewares", "")
    return [m for m in value.split(",") if m]


class ClientIpHeaderTest(unittest.TestCase):
    def test_prod_gives_the_header_of_cloudflare_to_the_api_and_the_store_only(self):
        found = client_ip_headers("prod")
        self.assertEqual({"api", "store"}, {key.split("/")[0] for key in found})
        self.assertEqual({HEADER}, set(found.values()))
        for workload in ("api", "store"):
            deployment = next(d for d in of_kind("prod", "Deployment") if logical(d) == workload)
            self.assertEqual(HEADER, env_of(containers(deployment)[0]).get("CLIENT_IP_HEADER"), workload)

    def test_prod_takes_traffic_only_through_the_allow_list_on_every_ingress(self):
        """The header is trusted only because nothing but Cloudflare reaches any host, also api. and auth."""
        ingresses = {name(i): i for i in of_kind("prod", "Ingress")}
        self.assertEqual({"store", "admin", "api", "auth", "img"}, set(ingresses))
        for ingress_name, ingress in ingresses.items():
            self.assertIn(IPALLOWLIST, middlewares_of(ingress), ingress_name)
        policy = [m for m in of_kind("prod", "Middleware") if name(m) == "ipallowlist"]
        self.assertEqual(1, len(policy))
        self.assertTrue(policy[0]["spec"]["ipAllowList"]["sourceRange"])

    def test_ci_has_no_header_of_the_client_address(self):
        """The smoke test has no allow list, so no workload may trust a header of the client address."""
        self.assertEqual({}, client_ip_headers("ci"))
        for ingress in of_kind("ci", "Ingress"):
            self.assertNotIn(IPALLOWLIST, middlewares_of(ingress), name(ingress))

    def test_without_the_allow_list_the_header_needs_the_explicit_confirmation(self):
        """The profile of the measurement (load): ci with the header and clientIpHeaderWithoutAllowList: true."""
        found = client_ip_headers("ci-client-ip")
        self.assertEqual({"api", "store"}, {key.split("/")[0] for key in found})
        self.assertEqual({HEADER}, set(found.values()))


if __name__ == "__main__":
    unittest.main()

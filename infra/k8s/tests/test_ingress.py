import unittest

from support import docs, name, of_kind

AUTH_PATHS = {"/realms/shop/", "/realms/backoffice/", "/resources/"}
CLOUDFLARE_SAMPLE = {"173.245.48.0/20", "2400:cb00::/32"}


def ingress_by_host(case):
    return {rule["host"]: (ing, rule) for ing in of_kind(case, "Ingress") for rule in ing["spec"]["rules"]}


def middlewares_of(ingress):
    value = ingress["metadata"].get("annotations", {}).get("traefik.ingress.kubernetes.io/router.middlewares", "")
    return [m for m in value.split(",") if m]


def backend(rule):
    return {(p["backend"]["service"]["name"], p["backend"]["service"]["port"]["number"]) for p in rule["http"]["paths"]}


class IngressTest(unittest.TestCase):
    def test_services_behind_the_hosts(self):
        expected = {"": ("store", 3000), "admin.": ("admin", 8080), "api.": ("api", 80), "auth.": ("keycloak", 8080), "img.": ("imgproxy", 8080)}
        for case, domain in (("prod", None), ("ci", "localhost")):
            hosts = ingress_by_host(case)
            if domain is None:
                domain = next(h for h in hosts if not h.split(".")[0] in ("admin", "api", "auth", "img"))
            for prefix, service in expected.items():
                self.assertIn(f"{prefix}{domain}", hosts, case)
                self.assertEqual({service}, backend(hosts[f"{prefix}{domain}"][1]), f"{case} {prefix}{domain}")

    def test_prod_presents_the_origin_certificate_on_every_host_and_ci_the_default_one(self):
        for ingress, rule in ingress_by_host("prod").values():
            self.assertEqual([{"hosts": [rule["host"]], "secretName": "shop-origin-tls"}], ingress["spec"]["tls"])
        for ingress, _ in ingress_by_host("ci").values():
            self.assertNotIn("tls", ingress["spec"])

    def test_domain_is_a_parameter(self):
        self.assertIn("auth.other.example.net", ingress_by_host("prod-domain"))

    def test_auth_exposes_only_the_realms_of_the_shop_and_the_resources(self):
        for case in ("prod", "ci", "prod-domain"):
            hosts = ingress_by_host(case)
            auth = next(rule for host, (_, rule) in hosts.items() if host.startswith("auth."))
            paths = {p["path"] for p in auth["http"]["paths"]}
            self.assertEqual(AUTH_PATHS, paths, case)
            for path in auth["http"]["paths"]:
                self.assertEqual("Prefix", path["pathType"])

    def test_nothing_else_reaches_keycloak(self):
        for case in ("prod", "ci"):
            for host, (_, rule) in ingress_by_host(case).items():
                if not host.startswith("auth."):
                    self.assertNotIn("keycloak", {s for s, _ in backend(rule)}, f"{case} {host}")

    def test_prod_admits_only_cloudflare_on_every_ingress(self):
        ingresses = of_kind("prod", "Ingress")
        self.assertEqual(5, len(ingresses))
        policy = [m for m in of_kind("prod", "Middleware") if name(m) == "ipallowlist"]
        self.assertEqual(1, len(policy))
        self.assertTrue(CLOUDFLARE_SAMPLE <= set(policy[0]["spec"]["ipAllowList"]["sourceRange"]))
        for ingress in ingresses:
            self.assertIn("shop-ipallowlist@kubernetescrd", middlewares_of(ingress), name(ingress))

    def test_ci_has_no_ip_policy(self):
        self.assertEqual([], [m for m in of_kind("ci", "Middleware") if name(m) == "ipallowlist"])
        for ingress in of_kind("ci", "Ingress"):
            self.assertNotIn("shop-ipallowlist@kubernetescrd", middlewares_of(ingress), name(ingress))

    def test_noindex_on_every_host_when_switched_on(self):
        for case in ("prod", "ci"):
            policy = [m for m in of_kind(case, "Middleware") if name(m) == "noindex"]
            self.assertEqual(1, len(policy), case)
            self.assertIn("noindex", policy[0]["spec"]["headers"]["customResponseHeaders"]["X-Robots-Tag"])
            for ingress in of_kind(case, "Ingress"):
                self.assertIn("shop-noindex@kubernetescrd", middlewares_of(ingress), f"{case} {name(ingress)}")

    def test_no_noindex_when_switched_off(self):
        self.assertEqual([], [m for m in of_kind("prod-indexable", "Middleware") if name(m) == "noindex"])
        for ingress in of_kind("prod-indexable", "Ingress"):
            self.assertNotIn("shop-noindex@kubernetescrd", middlewares_of(ingress), name(ingress))
            self.assertIn("shop-ipallowlist@kubernetescrd", middlewares_of(ingress), name(ingress))

    def test_entrypoints(self):
        for ingress in of_kind("prod", "Ingress"):
            annotations = ingress["metadata"]["annotations"]
            self.assertEqual("websecure", annotations["traefik.ingress.kubernetes.io/router.entrypoints"])
            self.assertEqual("true", annotations["traefik.ingress.kubernetes.io/router.tls"])
        for ingress in of_kind("ci", "Ingress"):
            self.assertEqual("web", ingress["metadata"]["annotations"]["traefik.ingress.kubernetes.io/router.entrypoints"])

    def test_something_is_rendered(self):
        self.assertTrue(docs("prod"))


if __name__ == "__main__":
    unittest.main()

import unittest

from support import docs, name, of_kind

AUTH_PATHS = {"/realms/shop/", "/realms/backoffice/", "/resources/"}
CLOUDFLARE_SAMPLE = {"173.245.48.0/20", "2400:cb00::/32"}


# The ingresses that only add the limit of the body to a path of a host (L8): the ingress of the host is the other one.
PATH_INGRESSES = {"store-api", "api-upload"}
BUFFERING = "shop-buffering@kubernetescrd"
BUFFERING_UPLOAD = "shop-buffering-upload@kubernetescrd"
MIB = 1024 * 1024


def ingress_by_host(case):
    """The ingress of each host, without the ingresses of single paths (PATH_INGRESSES)."""
    return {
        rule["host"]: (ing, rule)
        for ing in of_kind(case, "Ingress")
        if name(ing) not in PATH_INGRESSES
        for rule in ing["spec"]["rules"]
    }


def middlewares_of(ingress):
    value = ingress["metadata"].get("annotations", {}).get("traefik.ingress.kubernetes.io/router.middlewares", "")
    return [m for m in value.split(",") if m]


def paths_of(ingress):
    """(path, pathType, service, port) of every path of the ingress."""
    return [
        (p["path"], p["pathType"], p["backend"]["service"]["name"], p["backend"]["service"]["port"]["number"])
        for rule in ingress["spec"]["rules"]
        for p in rule["http"]["paths"]
    ]


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
        self.assertEqual(7, len(ingresses))
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

    def test_hsts_on_every_host_only_with_tls(self):
        """D10: HSTS for a year, without includeSubDomains and preload, and only where the ingress presents a certificate."""
        for case in ("prod", "prod-indexable", "prod-domain"):
            policy = [m for m in of_kind(case, "Middleware") if name(m) == "hsts"]
            self.assertEqual(1, len(policy), case)
            headers = policy[0]["spec"]["headers"]
            self.assertEqual(31536000, headers["stsSeconds"], case)
            self.assertFalse(headers.get("stsIncludeSubdomains", False), case)
            self.assertFalse(headers.get("stsPreload", False), case)
            ingresses = of_kind(case, "Ingress")
            self.assertEqual(7, len(ingresses), case)
            for ingress in ingresses:
                self.assertIn("shop-hsts@kubernetescrd", middlewares_of(ingress), f"{case} {name(ingress)}")
        self.assertEqual([], [m for m in of_kind("ci", "Middleware") if name(m) == "hsts"])
        for ingress in of_kind("ci", "Ingress"):
            self.assertNotIn("shop-hsts@kubernetescrd", middlewares_of(ingress), name(ingress))

    def test_entrypoints(self):
        for ingress in of_kind("prod", "Ingress"):
            annotations = ingress["metadata"]["annotations"]
            self.assertEqual("websecure", annotations["traefik.ingress.kubernetes.io/router.entrypoints"])
            self.assertEqual("true", annotations["traefik.ingress.kubernetes.io/router.tls"])
        for ingress in of_kind("ci", "Ingress"):
            self.assertEqual("web", ingress["metadata"]["annotations"]["traefik.ingress.kubernetes.io/router.entrypoints"])

    def test_the_body_limit_of_the_api_host_and_of_its_upload_path(self):
        """L8, D10: the host api buffers the body up to 1 MiB, only the exact path of the upload up to 71 MiB."""
        for case in ("prod", "ci"):
            ingresses = {name(i): i for i in of_kind(case, "Ingress")}
            self.assertIn(BUFFERING, middlewares_of(ingresses["api"]), case)
            self.assertNotIn(BUFFERING_UPLOAD, middlewares_of(ingresses["api"]), case)
            upload = ingresses["api-upload"]
            self.assertIn(BUFFERING_UPLOAD, middlewares_of(upload), case)
            self.assertNotIn(BUFFERING, middlewares_of(upload), case)
            self.assertEqual(ingresses["api"]["spec"]["rules"][0]["host"], upload["spec"]["rules"][0]["host"], case)
            self.assertEqual([("/api/v1/admin/product-images", "Exact", "api", 80)], paths_of(upload), case)
            self.assertEqual([("/", "Prefix", "api", 80)], paths_of(ingresses["api"]), case)

    def test_the_body_limit_of_the_api_routes_of_the_store(self):
        """L8: `/api/` of the store host (the BFF) is buffered up to 1 MiB; the rest of that host is not."""
        for case in ("prod", "ci"):
            ingresses = {name(i): i for i in of_kind(case, "Ingress")}
            self.assertIn(BUFFERING, middlewares_of(ingresses["store-api"]), case)
            self.assertEqual([("/api/", "Prefix", "store", 3000)], paths_of(ingresses["store-api"]), case)
            self.assertEqual([("/", "Prefix", "store", 3000)], paths_of(ingresses["store"]), case)
            self.assertEqual(ingresses["store"]["spec"]["rules"][0]["host"], ingresses["store-api"]["spec"]["rules"][0]["host"], case)

    def test_only_the_routes_that_answer_json_are_buffered(self):
        """E4: the middleware buffers the response too, so no page, RSC response, panel, Keycloak and image goes through it."""
        for case in ("prod", "ci", "prod-domain", "load"):
            buffered = {name(i) for i in of_kind(case, "Ingress") if {BUFFERING, BUFFERING_UPLOAD} & set(middlewares_of(i))}
            self.assertEqual({"api", "api-upload", "store-api"}, buffered, case)

    def test_the_limits_of_the_body_are_1_and_71_mib(self):
        for case in ("prod", "ci"):
            middlewares = {name(m): m for m in of_kind(case, "Middleware")}
            self.assertEqual({"buffering": MIB, "buffering-upload": 71 * MIB}, {
                key: middlewares[key]["spec"]["buffering"]["maxRequestBodyBytes"] for key in ("buffering", "buffering-upload")
            }, case)
            for key in ("buffering", "buffering-upload"):
                self.assertEqual({"maxRequestBodyBytes"}, set(middlewares[key]["spec"]["buffering"]), f"{case} {key}")

    def test_every_ingress_carries_the_policy_of_the_hosts_whatever_its_path(self):
        """The ingresses of single paths do not open a way around the allow list, HSTS and noindex (L8)."""
        for case in ("prod", "prod-domain", "prod-indexable"):
            for ingress in of_kind(case, "Ingress"):
                found = middlewares_of(ingress)
                self.assertIn("shop-ipallowlist@kubernetescrd", found, f"{case} {name(ingress)}")
                self.assertIn("shop-hsts@kubernetescrd", found, f"{case} {name(ingress)}")
        for ingress in of_kind("prod", "Ingress"):
            self.assertIn("shop-noindex@kubernetescrd", middlewares_of(ingress), name(ingress))
            for rule in ingress["spec"]["rules"]:
                self.assertIn("http", rule, name(ingress))
            self.assertEqual([{"hosts": [ingress["spec"]["rules"][0]["host"]], "secretName": "shop-origin-tls"}], ingress["spec"]["tls"])

    def test_every_middleware_of_an_ingress_exists(self):
        for case in ("prod", "ci"):
            existing = {f"shop-{name(m)}@kubernetescrd" for m in of_kind(case, "Middleware")}
            for ingress in of_kind(case, "Ingress"):
                self.assertLessEqual(set(middlewares_of(ingress)), existing, f"{case} {name(ingress)}")

    def test_something_is_rendered(self):
        self.assertTrue(docs("prod"))


if __name__ == "__main__":
    unittest.main()

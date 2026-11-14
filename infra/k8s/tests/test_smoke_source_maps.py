"""The smoke check that the production stages of the store and the panel hold no source map (docs/features/sentry/spec.md,
D10, threat model path 7). The check runs inside the cluster and talks HTTP only, so these tests give it a fake ingress."""

import importlib.util
import json
import unittest
from unittest import mock

from support import REPO

spec = importlib.util.spec_from_file_location("smoke_checks", REPO / "infra/k8s/smoke/checks.py")
checks = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checks)

STORE_HTML = '<html><script src="/_next/static/chunks/a.js?dpl=1" async></script><script src="/_next/static/chunks/b.js"></script></html>'
ADMIN_HTML = '<html><div id="app"></div><script type="module" src="/assets/index-abc.js"></script></html>'
MAP = json.dumps({"version": 3, "sources": ["../src/a.ts"], "mappings": "AAAA"})
SPA_FALLBACK = "<!doctype html><html><div id=\"app\"></div></html>"


def ingress(maps=(), comments=(), fallback=False):
    """A fake through_ingress: `maps` are paths that answer a source map, `comments` the scripts that end with the comment."""

    def answer(path, sub="", **kwargs):
        bare = path.split("?")[0]
        if bare == "/":
            return 200, {}, ADMIN_HTML if sub == "admin" else STORE_HTML
        if bare in maps:
            return 200, {}, MAP
        if bare.endswith(".map"):
            return (200, {}, SPA_FALLBACK) if fallback else (404, {}, "not found")
        if bare.endswith(".js"):
            tail = "\n//# sourceMappingURL=" + bare.rsplit("/", 1)[1] + ".map\n" if bare in comments else "\n"
            return 200, {}, "console.log(1)" + tail
        return 404, {}, ""

    return answer


class SourceMapPredicates(unittest.TestCase):
    def test_a_source_map_is_a_200_with_mappings(self):
        self.assertTrue(checks.is_source_map(200, MAP))
        self.assertTrue(checks.is_source_map(200, json.dumps({"version": 3, "sections": [], "mappings": ""})))

    def test_a_missing_file_and_the_html_of_the_fallback_are_no_source_map(self):
        self.assertFalse(checks.is_source_map(404, MAP))
        self.assertFalse(checks.is_source_map(200, SPA_FALLBACK))
        self.assertFalse(checks.is_source_map(200, "not json {"))
        self.assertFalse(checks.is_source_map(200, json.dumps({"version": 3})))
        self.assertFalse(checks.is_source_map(200, "[1]"))

    def test_the_comment_is_found_at_the_end_of_a_script_only(self):
        self.assertTrue(checks.has_source_mapping_comment("x();\n//# sourceMappingURL=a.js.map\n"))
        self.assertTrue(checks.has_source_mapping_comment("x();\n//@ sourceMappingURL=a.js.map"))
        self.assertTrue(checks.has_source_mapping_comment("a{}\n/*# sourceMappingURL=a.css.map */\n"))
        # Sentry writes its debug ID comment after the one of the map.
        self.assertTrue(checks.has_source_mapping_comment("x();\n//# sourceMappingURL=a.js.map\n//# debugId=0a1b2c3d-0000-4000-8000-00000000abcd\n"))
        self.assertFalse(checks.has_source_mapping_comment("x();\n//# debugId=0a1b2c3d-0000-4000-8000-00000000abcd\n"))
        # A library that mentions the name in its code (a regex, a string) is not a pointer to a map.
        self.assertFalse(checks.has_source_mapping_comment('var r=/\\/\\/# sourceMappingURL=(.*)/;x("sourceMappingURL=");\n'))

    def test_the_scripts_of_a_page_are_its_script_tags_with_a_src(self):
        self.assertEqual(["/_next/static/chunks/a.js?dpl=1", "/_next/static/chunks/b.js"], checks.script_paths(STORE_HTML))
        self.assertEqual(["/assets/index-abc.js"], checks.script_paths(ADMIN_HTML))
        self.assertEqual([], checks.script_paths("<script>inline()</script>"))


class NoSourceMaps(unittest.TestCase):
    def run_check(self, **kwargs):
        with mock.patch.object(checks, "through_ingress", ingress(**kwargs)):
            checks.check_no_source_maps()

    def test_images_without_maps_pass(self):
        self.run_check()

    def test_the_fallback_of_the_panel_is_no_map(self):
        self.run_check(fallback=True)

    def test_a_map_of_the_store_fails(self):
        with self.assertRaisesRegex(AssertionError, r"/_next/static/chunks/b\.js\.map"):
            self.run_check(maps=("/_next/static/chunks/b.js.map",))

    def test_a_map_of_the_panel_fails(self):
        with self.assertRaisesRegex(AssertionError, r"/assets/index-abc\.js\.map"):
            self.run_check(maps=("/assets/index-abc.js.map",))

    def test_a_pointer_to_a_map_in_a_script_fails(self):
        with self.assertRaisesRegex(AssertionError, "sourceMappingURL"):
            self.run_check(comments=("/assets/index-abc.js",))
        with self.assertRaisesRegex(AssertionError, "sourceMappingURL"):
            self.run_check(comments=("/_next/static/chunks/a.js",))

    def test_a_page_without_scripts_fails_instead_of_passing_for_nothing(self):
        def empty(path, sub="", **kwargs):
            return 200, {}, "<html></html>"

        with mock.patch.object(checks, "through_ingress", empty):
            with self.assertRaisesRegex(AssertionError, "scripts"):
                checks.check_no_source_maps()

    def test_it_is_one_of_the_checks_of_basic(self):
        text = (REPO / "infra/k8s/smoke/checks.py").read_text()
        main = text[text.index("def main():"):]
        basic = main[main.index('"basic"'):main.index('"snapshot"')]
        self.assertIn("check_no_source_maps()", basic)
        # The docstring of the module lists the checks of `basic` (its lines wrap).
        self.assertIn("source maps", " ".join(text.split('"""', 2)[1].split()))


if __name__ == "__main__":
    unittest.main()

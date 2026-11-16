"""apps/admin/docker/40-admin-config.sh: the hook that writes /config.js of the panel from the environment at container
start. It runs in sh with the output directory in a temporary directory; a missing, empty or whitespace value of any of
the four variables stops the start with the name of the variable. The optional SENTRY_* variables add their keys
only when they are set and not empty; without them the file is the one of the four URLs."""

import json
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "apps/admin/docker/40-admin-config.sh"
URLS = {
    "VITE_API_URL": "http://api.example.test",
    "VITE_KEYCLOAK_URL": "http://auth.example.test",
    "VITE_ADMIN_URL": "http://admin.example.test",
    "VITE_STORE_URL": "http://shop.example.test",
}


SENTRY = {
    "SENTRY_DSN": "https://public@o0.ingest.de.sentry.io/1",
    "SENTRY_ENVIRONMENT": "demo",
    "SENTRY_RELEASE": "0123456789abcdef0123456789abcdef01234567",
    "SENTRY_TRACES_SAMPLE_RATE": "0.1",
}
# config.js of the four URLs byte for byte: the file the panel got before the optional keys existed.
URLS_ONLY = """window.__ADMIN_CONFIG__ = {
  "VITE_API_URL": "http://api.example.test",
  "VITE_KEYCLOAK_URL": "http://auth.example.test",
  "VITE_ADMIN_URL": "http://admin.example.test",
  "VITE_STORE_URL": "http://shop.example.test"
};
"""


def parse(config):
    """The object of config.js: it must be a valid JSON object in the assignment."""
    match = re.fullmatch(r"window\.__ADMIN_CONFIG__ = (\{.*\});\n", config, re.S)
    assert match is not None, config
    return json.loads(match.group(1))


def run(env_overrides):
    """Runs the script with the four URLs (minus the ones set to None, replaced by the given values) and returns the
    process and the config.js it wrote (None when it wrote nothing)."""
    env = {"PATH": "/usr/bin:/bin", **URLS, **env_overrides}
    env = {key: value for key, value in env.items() if value is not None}
    with tempfile.TemporaryDirectory() as directory:
        env["ADMIN_CONFIG_DIR"] = directory
        process = subprocess.run(["sh", str(SCRIPT)], env=env, capture_output=True, text=True)
        config = pathlib.Path(directory) / "config.js"
        return process, config.read_text() if config.exists() else None


class AdminConfigScriptTest(unittest.TestCase):
    def test_writes_the_four_keys(self):
        process, config = run({})
        self.assertEqual(0, process.returncode, process.stderr)
        match = re.fullmatch(r"window\.__ADMIN_CONFIG__ = (\{.*\});\n", config, re.S)
        self.assertIsNotNone(match, config)
        self.assertEqual(URLS, json.loads(match.group(1)))

    def test_escapes_the_value_as_a_json_string(self):
        process, config = run({"VITE_STORE_URL": 'http://shop.example.test/"x\\y'})
        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual('http://shop.example.test/"x\\y', json.loads(config.split(" = ", 1)[1].rstrip(";\n"))["VITE_STORE_URL"])

    def test_a_missing_variable_stops_the_start_with_its_name(self):
        for name in URLS:
            with self.subTest(name=name):
                process, config = run({name: None})
                self.assertNotEqual(0, process.returncode)
                self.assertIn(name, process.stderr)
                self.assertIsNone(config, "no config.js is written when a variable is missing")

    def test_an_empty_variable_stops_the_start_with_its_name(self):
        for name in URLS:
            with self.subTest(name=name):
                process, config = run({name: ""})
                self.assertNotEqual(0, process.returncode)
                self.assertIn(name, process.stderr)
                self.assertIsNone(config)

    def test_whitespace_in_a_variable_stops_the_start_with_its_name(self):
        for name in URLS:
            for value in (" ", "http://shop.example.test ", "http://shop.example.test/a b", "\thttp://shop.example.test", "http://shop.example.test\n"):
                with self.subTest(name=name, value=value):
                    process, config = run({name: value})
                    self.assertNotEqual(0, process.returncode)
                    self.assertIn(name, process.stderr)
                    self.assertIsNone(config)

    def test_without_the_sentry_variables_the_file_is_byte_for_byte_the_one_of_the_four_urls(self):
        process, config = run({})
        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual(URLS_ONLY, config)

    def test_the_sentry_variables_add_their_keys_after_the_four_urls(self):
        process, config = run(SENTRY)
        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual({**URLS, **SENTRY}, parse(config))
        self.assertEqual(list(URLS) + list(SENTRY), list(parse(config)), "the keys keep the order: URLs, then SENTRY_*")

    def test_each_sentry_variable_alone_adds_only_its_key(self):
        for name, value in SENTRY.items():
            with self.subTest(name=name):
                process, config = run({name: value})
                self.assertEqual(0, process.returncode, process.stderr)
                self.assertEqual({**URLS, name: value}, parse(config))

    def test_an_empty_sentry_variable_adds_no_key(self):
        process, config = run({name: "" for name in SENTRY})
        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual(URLS_ONLY, config)
        process, config = run({**SENTRY, "SENTRY_RELEASE": ""})
        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual({**URLS, **{k: v for k, v in SENTRY.items() if k != "SENTRY_RELEASE"}}, parse(config))

    def test_a_sentry_value_is_escaped_as_a_json_string(self):
        process, config = run({"SENTRY_ENVIRONMENT": 'de"mo\\x'})
        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual('de"mo\\x', parse(config)["SENTRY_ENVIRONMENT"])

    def test_whitespace_in_a_sentry_variable_stops_the_start_with_its_name(self):
        for name in SENTRY:
            for value in (" ", "a b", "x ", "\tx", "x\n", "x\x07"):
                with self.subTest(name=name, value=value):
                    process, config = run({name: value})
                    self.assertNotEqual(0, process.returncode)
                    self.assertIn(name, process.stderr)
                    self.assertIsNone(config, "no config.js is written when a variable is refused")

    def test_a_missing_url_still_stops_the_start_when_the_sentry_variables_are_set(self):
        for name in URLS:
            with self.subTest(name=name):
                process, config = run({**SENTRY, name: None})
                self.assertNotEqual(0, process.returncode)
                self.assertIn(name, process.stderr)
                self.assertIsNone(config)
                process, config = run({**SENTRY, name: ""})
                self.assertNotEqual(0, process.returncode)
                self.assertIn(name, process.stderr)
                self.assertIsNone(config)


if __name__ == "__main__":
    unittest.main()

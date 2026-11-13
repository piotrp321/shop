"""apps/admin/docker/40-admin-config.sh: the hook that writes /config.js of the panel from the environment at container
start. It runs in sh with the output directory in a temporary directory; a missing, empty or whitespace value of any of
the four variables stops the start with the name of the variable."""

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


if __name__ == "__main__":
    unittest.main()

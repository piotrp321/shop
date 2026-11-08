"""scripts/update-cloudflare-ips.sh against a stub of docker standing for curl: the list in the values of prod follows the
published lists, and a bad download or a bad line changes nothing."""

import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/scripts/update-cloudflare-ips.sh"
V4 = "173.245.48.0/20\n103.21.244.0/22\n"
V6 = "2400:cb00::/32\n2606:4700::/32\n"

# Stub of docker run <image> -fsS --max-time 30 <url>: answers the two lists from $STUB_DIR, fails on STUB_FAIL (a url part).
DOCKER = r"""#!/bin/sh
url=""
for argument in "$@"; do url="$argument"; done
echo "$url" >> "$STUB_DIR/log"
case "$url" in *"${STUB_FAIL:-@@never@@}"*) echo "curl: (22) 404" >&2; exit 22 ;; esac
case "$url" in
  */ips-v4) cat "$STUB_DIR/v4" ;;
  */ips-v6) cat "$STUB_DIR/v6" ;;
esac
"""


class UpdateCloudflareIps(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = pathlib.Path(self.tmp.name)
        self.root = base / "repo"
        self.values = self.root / "infra/k8s/environments/prod/values.yaml"
        self.values.parent.mkdir(parents=True)
        shutil.copy(REPO / "infra/k8s/environments/prod/values.yaml", self.values)
        self.bin = base / "bin"
        self.bin.mkdir()
        docker = self.bin / "docker"
        docker.write_text(DOCKER)
        docker.chmod(0o755)
        self.stub = base / "stub"
        self.stub.mkdir()
        (self.stub / "v4").write_text(V4)
        (self.stub / "v6").write_text(V6)
        self.set_list(["173.245.48.0/20", "103.21.244.0/22", "2400:cb00::/32", "2606:4700::/32"])

    def tearDown(self):
        self.tmp.cleanup()

    def set_list(self, ranges):
        lines = self.values.read_text().splitlines()
        start = lines.index("  sourceRange:")
        end = start + 1
        while lines[end].startswith("    - "):
            end += 1
        lines[start + 1:end] = [f"    - {r}" for r in ranges]
        self.values.write_text("\n".join(lines) + "\n")

    def current(self):
        lines = self.values.read_text().splitlines()
        start = lines.index("  sourceRange:") + 1
        found = []
        while lines[start].startswith("    - "):
            found.append(lines[start][6:])
            start += 1
        return found

    def run_script(self, *args, **env):
        variables = {"PATH": f"{self.bin}:{os.environ['PATH']}", "HOME": self.tmp.name, "TMPDIR": self.tmp.name,
                     "REPO_ROOT": str(self.root), "STUB_DIR": str(self.stub), **env}
        return subprocess.run(["sh", str(SCRIPT), *args], env=variables, capture_output=True, text=True)

    def test_a_list_that_is_up_to_date_changes_nothing(self):
        before = self.values.read_bytes()
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(before, self.values.read_bytes())
        self.assertIn("up to date", result.stdout)

    def test_a_changed_list_is_rewritten_and_the_rest_of_the_file_stays(self):
        (self.stub / "v4").write_text(V4 + "131.0.72.0/22\n")
        (self.stub / "v6").write_text("2400:cb00::/32\n2a06:98c0::/29\n")
        before = self.values.read_text()
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["173.245.48.0/20", "103.21.244.0/22", "131.0.72.0/22", "2400:cb00::/32", "2a06:98c0::/29"], self.current())
        self.assertIn("added:   131.0.72.0/22", result.stdout)
        self.assertIn("removed: 2606:4700::/32", result.stdout)
        after = self.values.read_text()
        self.assertEqual(before.split("  sourceRange:")[0], after.split("  sourceRange:")[0])
        self.assertEqual(before.split("elasticsearch:")[1], after.split("elasticsearch:")[1])
        self.assertEqual(0, self.run_script().returncode)

    def test_check_reports_without_changing(self):
        (self.stub / "v4").write_text(V4 + "131.0.72.0/22\n")
        before = self.values.read_bytes()
        result = self.run_script("--check")
        self.assertEqual(1, result.returncode)
        self.assertEqual(before, self.values.read_bytes())
        self.assertIn("out of date", result.stderr)

    def test_check_passes_when_up_to_date(self):
        self.assertEqual(0, self.run_script("--check").returncode)

    def test_a_failed_download_changes_nothing(self):
        before = self.values.read_bytes()
        for part in ("ips-v4", "ips-v6"):
            result = self.run_script(STUB_FAIL=part)
            self.assertEqual(1, result.returncode)
            self.assertIn("could not download", result.stderr)
            self.assertEqual(before, self.values.read_bytes())

    def test_something_that_is_not_a_list_changes_nothing(self):
        before = self.values.read_bytes()
        for v4, v6 in (("<html>Not found</html>\n", V6), (V4, "<html>\n"), ("", V6), (V4, ""), ("1.2.3.4\n", V6), ("1.2.3.0/24;rm\n", V6), (V4, "2400:cb00::\n"), ("999.1.1.1/x\n", V6)):
            with self.subTest(v4=v4[:20], v6=v6[:20]):
                (self.stub / "v4").write_text(v4)
                (self.stub / "v6").write_text(v6)
                result = self.run_script()
                self.assertEqual(1, result.returncode, result.stdout)
                self.assertEqual(before, self.values.read_bytes())

    def test_a_wrong_argument_is_a_usage_error(self):
        self.assertEqual(2, self.run_script("--now").returncode)

    def test_the_list_of_the_repository_is_a_list_of_ranges(self):
        text = (REPO / "infra/k8s/environments/prod/values.yaml").read_text()
        self.assertIn("  sourceRange:\n    - 173.245.48.0/20\n", text)


if __name__ == "__main__":
    unittest.main()

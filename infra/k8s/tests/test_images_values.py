"""scripts/images-values.sh: the values file with the revision and the digests of the images built by us, the file that
Argo CD, the smoke test and the render check all use. Wrong input fails and writes nothing; the same input gives the
same bytes."""

import os
import pathlib
import subprocess
import tempfile
import unittest

from support import DIGESTS, REPO, REVISION

SCRIPT = REPO / "infra/k8s/scripts/images-values.sh"
ENV = {f"DIGEST_{app.upper()}": digest for app, digest in DIGESTS.items()}


def run(sha, out, **overrides):
    env = {"PATH": os.environ["PATH"], **ENV, **overrides}
    env = {k: v for k, v in env.items() if v is not None}
    return subprocess.run(["sh", str(SCRIPT), sha, str(out)], env=env, capture_output=True, text=True)


class ImagesValues(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.out = pathlib.Path(self.dir.name) / "values-images.yaml"

    def tearDown(self):
        self.dir.cleanup()

    def test_writes_the_revision_and_the_four_digests(self):
        result = run(REVISION, self.out)
        self.assertEqual(0, result.returncode, result.stderr)
        lines = self.out.read_text().splitlines()
        self.assertEqual(["image:", f"  tag: {REVISION}", "  digests:"], lines[1:4])
        self.assertEqual([f"    {app}: {digest}" for app, digest in DIGESTS.items()], lines[4:])
        self.assertIn(REVISION, lines[0])

    def test_the_same_input_gives_the_same_bytes(self):
        run(REVISION, self.out)
        first = self.out.read_bytes()
        self.out.unlink()
        run(REVISION, self.out)
        self.assertEqual(first, self.out.read_bytes())

    def test_a_different_digest_gives_a_different_file(self):
        run(REVISION, self.out)
        first = self.out.read_bytes()
        run(REVISION, self.out, DIGEST_STORE="sha256:" + "e" * 64)
        self.assertNotEqual(first, self.out.read_bytes())

    def assert_nothing_written(self, result):
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(self.out.exists())
        self.assertEqual([], os.listdir(self.dir.name), "a temporary file is left")

    def test_a_missing_or_malformed_digest_fails_and_writes_nothing(self):
        for app in DIGESTS:
            for bad in (None, "", "latest", "sha256:abc", "sha256:" + "A" * 64, "sha256:" + "a" * 65, "sha512:" + "a" * 64, "a" * 64):
                with self.subTest(app=app, digest=bad):
                    result = run(REVISION, self.out, **{f"DIGEST_{app.upper()}": bad})
                    self.assert_nothing_written(result)
                    self.assertIn(f"DIGEST_{app.upper()}", result.stderr)

    def test_a_revision_that_is_not_a_full_sha_fails_and_writes_nothing(self):
        for bad in ("", "main", "latest", REVISION[:7], REVISION.upper(), REVISION + "0", "g" * 40):
            with self.subTest(sha=bad):
                self.assert_nothing_written(run(bad, self.out))

    def test_wrong_number_of_arguments_is_a_usage_error(self):
        result = subprocess.run(["sh", str(SCRIPT)], env={"PATH": os.environ["PATH"], **ENV}, capture_output=True, text=True)
        self.assertEqual(2, result.returncode)
        self.assertIn("usage", result.stderr)

    def test_an_unwritable_target_fails(self):
        result = run(REVISION, pathlib.Path(self.dir.name) / "no-such-dir" / "values.yaml")
        self.assertNotEqual(0, result.returncode)


if __name__ == "__main__":
    unittest.main()

"""scripts/import-images.sh (the import of the local images into the node of a k3d cluster, used by smoke.sh and load.sh)
on a stub of docker. `k3d image import` may end with 0 although the node has not got the images (it only logs a failed
`ctr image import` of a node), so the script asks the node itself (crictl, as the kubelet does) and imports again what
is missing.

k3d is a shell function of tools.sh that runs `docker run <k3d image> ...`, so a stub of docker is the only thing the
script talks to."""

import os
import pathlib
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/scripts/import-images.sh"
NODE = "k3d-sm-test-server-0"
IMAGES = ("shop-smoke-sm-test/api:r2", "shop-smoke-sm-test/store:r2")

# One line of arguments per call in $STUB_LOG. `k3d image import` (docker run of the k3d image) puts its images into the
# node ($STUB_DIR/node/<image with / and : as _>) and ends with STUB_K3D_STATUS; its first STUB_LOSE imports end with that
# status as well, but the images matching STUB_LOSE_MATCH (all by default) never reach the node, as in the runs of #416.
# `docker exec <node> crictl inspecti <image>` ends with 0 only for an image in the node.
DOCKER = r"""#!/bin/sh
printf '%s\n' "$*" >> "$STUB_LOG"
case "$1" in
  run)
    case "$*" in
      *k3d-io/k3d*"image import"*)
        n="$(grep -c 'image import' "$STUB_LOG")"
        seen=""
        for arg in "$@"; do
          [ "$seen" = 1 ] || { [ "$arg" = "$STUB_CLUSTER" ] && seen=1; continue; }
          if [ "$n" -le "${STUB_LOSE:-0}" ]; then
            case "$arg" in *${STUB_LOSE_MATCH:-}*) echo "ERRO failed to import images in node: ctr: open /k3d/images/x.tar: no such file or directory" >&2; continue ;; esac
          fi
          touch "$STUB_DIR/node/$(printf '%s' "$arg" | tr '/:' '__')"
        done
        exit "${STUB_K3D_STATUS:-0}" ;;
    esac ;;
  exec)
    case "$*" in
      *"crictl inspecti"*)
        for image; do :; done
        [ "$2" = "$STUB_NODE" ] || { echo "Error: No such container: $2" >&2; exit 1; }
        [ -f "$STUB_DIR/node/$(printf '%s' "$image" | tr '/:' '__')" ] || { echo "no such image \"$image\" present" >&2; exit 1; }
        echo "{}" ;;
    esac ;;
esac
exit 0
"""


class ImportImages(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = pathlib.Path(self.tmp.name)
        self.bin = base / "bin"
        self.bin.mkdir()
        docker = self.bin / "docker"
        docker.write_text(DOCKER)
        docker.chmod(0o755)
        self.stub = base / "stub"
        (self.stub / "node").mkdir(parents=True)
        self.log = base / "docker.log"
        self.log.write_text("")

    def tearDown(self):
        self.tmp.cleanup()

    def run_import(self, *args, **env):
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": self.tmp.name,
            "TMPDIR": self.tmp.name,
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.stub),
            "STUB_CLUSTER": "sm-test",
            "STUB_NODE": NODE,
            "IMPORT_PAUSE": "0",
            **env,
        }
        return subprocess.run(["sh", str(SCRIPT), *args], env=variables, capture_output=True, text=True, timeout=60)

    def imports(self):
        return [call for call in self.log.read_text().splitlines() if "image import" in call]

    def test_images_in_the_node_after_the_first_import_are_imported_once(self):
        result = self.run_import("sm-test", NODE, *IMAGES)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(1, len(self.imports()))
        self.assertTrue(self.imports()[0].endswith("image import -c sm-test " + " ".join(IMAGES)), self.imports())
        self.assertNotIn("again", result.stderr)

    def test_images_that_k3d_reports_imported_but_the_node_has_not_are_imported_again(self):
        """#416: k3d ends with 0, the node has no image; the second import brings them."""
        result = self.run_import("sm-test", NODE, *IMAGES, STUB_LOSE="1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(2, len(self.imports()))
        self.assertIn(f"not in the node {NODE}", result.stderr)
        self.assertIn("importing again", result.stderr)

    def test_only_the_missing_images_are_imported_again(self):
        result = self.run_import("sm-test", NODE, *IMAGES, STUB_LOSE="1", STUB_LOSE_MATCH="store")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(2, len(self.imports()))
        self.assertTrue(self.imports()[1].endswith("image import -c sm-test shop-smoke-sm-test/store:r2"), self.imports())

    def test_images_never_in_the_node_fail_after_three_imports_and_are_named(self):
        result = self.run_import("sm-test", NODE, *IMAGES, STUB_LOSE="99", STUB_LOSE_MATCH="store")
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertEqual(3, len(self.imports()))
        self.assertIn(f"not in the node {NODE} after 3 imports: shop-smoke-sm-test/store:r2", result.stderr)
        self.assertNotIn("api:r2 after", result.stderr)

    def test_a_failing_k3d_is_retried_and_fails_when_the_images_never_arrive(self):
        result = self.run_import("sm-test", NODE, *IMAGES, STUB_LOSE="99", STUB_K3D_STATUS="1")
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertEqual(3, len(self.imports()))
        self.assertIn("after 3 imports", result.stderr)

    def test_a_failing_k3d_whose_images_reached_the_node_is_not_retried(self):
        """The node decides: an image there is imported, whatever k3d said."""
        result = self.run_import("sm-test", NODE, *IMAGES, STUB_K3D_STATUS="1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(1, len(self.imports()))

    def test_a_node_that_does_not_exist_fails(self):
        result = self.run_import("sm-test", "k3d-other-server-0", *IMAGES)
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("not in the node k3d-other-server-0 after 3 imports", result.stderr)

    def test_without_the_cluster_the_node_or_an_image_it_ends_with_2_and_touches_no_docker(self):
        for args in ((), ("sm-test",), ("sm-test", NODE)):
            with self.subTest(args=args):
                result = self.run_import(*args)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("usage", result.stderr)
                self.assertEqual("", self.log.read_text())


if __name__ == "__main__":
    unittest.main()

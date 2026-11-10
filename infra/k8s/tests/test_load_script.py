"""scripts/load.sh (make -C infra/k8s load) on a stub of docker: the refusals of the environment (exit 2, a message, no
cluster made or the cluster deleted) of docs/features/hardening/spec.md, "Testy obciążeniowe". The measurement itself is
not run here (it needs a cluster and takes tens of minutes); `make -C infra/k8s load` is.

k3d, kubectl and helm are shell functions of tools.sh that run `docker run <image> ...`, so a stub of docker is the
only thing the script talks to."""

import os
import pathlib
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/scripts/load.sh"

# One line of arguments per call in $STUB_LOG. Answers by the command: the resources of the Docker VM, k3d (a kubeconfig),
# kubectl (nothing exists in the cluster: no jobs, no deployments), everything else succeeds silently.
DOCKER = r"""#!/bin/sh
printf '%s\n' "$*" >> "$STUB_LOG"
case "$1" in
  info)
    [ -z "${STUB_NO_DOCKER:-}" ] || { echo "Cannot connect to the Docker daemon" >&2; exit 1; }
    echo "${STUB_NCPU:-16} ${STUB_MEM_BYTES:-34359738368}" ;;
  run)
    case "$*" in
      *k3d-io/k3d*"kubeconfig get"*) printf 'apiVersion: v1\nclusters:\n- cluster:\n    server: https://0.0.0.0:6443\n' ;;
      *registry.k8s.io/kubectl*) ;;
    esac ;;
esac
exit 0
"""


class LoadScript(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = pathlib.Path(self.tmp.name)
        self.bin = base / "bin"
        self.bin.mkdir()
        docker = self.bin / "docker"
        docker.write_text(DOCKER)
        docker.chmod(0o755)
        self.log = base / "docker.log"
        self.log.write_text("")
        self.out = base / "out"
        self.out.mkdir()

    def tearDown(self):
        self.tmp.cleanup()

    def run_load(self, **env):
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": self.tmp.name,
            "TMPDIR": self.tmp.name,
            "TOOLS_CACHE": f"{self.tmp.name}/cache",
            "COMPOSE_PROJECT_NAME": "loadtest",
            "STUB_LOG": str(self.log),
            "LOAD_POLL": "1",
            **env,
        }
        return subprocess.run(["sh", str(SCRIPT)], env=variables, capture_output=True, text=True, timeout=120)

    def calls(self):
        return self.log.read_text().splitlines()

    def cluster_calls(self, verb):
        return [call for call in self.calls() if "k3d-io/k3d" in call and f"cluster {verb}" in call]

    def test_without_load_out_it_ends_with_2_and_touches_no_docker(self):
        result = self.run_load()
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("LOAD_OUT", result.stderr)
        self.assertEqual([], self.calls())

    def test_a_load_out_that_is_not_an_existing_absolute_directory_is_refused(self):
        for value in ("relative/dir", str(self.out / "missing"), str(self.tmp.name) + "/docker.log"):
            with self.subTest(value=value):
                result = self.run_load(LOAD_OUT=value)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("LOAD_OUT", result.stderr)
                self.assertEqual([], self.calls())

    def test_a_load_out_inside_the_repository_is_refused(self):
        """The results are not committed: the directory must be outside the repository."""
        result = self.run_load(LOAD_OUT=str(REPO / "infra"))
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("outside the repository", result.stderr)
        self.assertEqual([], self.calls())

    def test_without_docker_it_ends_with_2_and_makes_no_cluster(self):
        result = self.run_load(LOAD_OUT=str(self.out), STUB_NO_DOCKER="1")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("Docker", result.stderr)
        self.assertEqual([], self.cluster_calls("create"))

    def test_too_few_cpus_end_with_2_before_any_cluster_is_made(self):
        result = self.run_load(LOAD_OUT=str(self.out), STUB_NCPU="4")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("CPU", result.stderr)
        self.assertEqual([], self.cluster_calls("create"))

    def test_too_little_memory_ends_with_2_before_any_cluster_is_made(self):
        result = self.run_load(LOAD_OUT=str(self.out), STUB_MEM_BYTES=str(8 * 1024**3))
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("memory", result.stderr)
        self.assertEqual([], self.cluster_calls("create"))

    def test_pods_that_do_not_become_ready_end_with_2_and_the_cluster_is_deleted(self):
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("not ready", result.stderr)
        self.assertEqual(1, len(self.cluster_calls("create")))
        deletes = self.cluster_calls("delete")
        self.assertTrue(deletes, "the cluster was not deleted")
        self.assertIn("ld-loadtest", deletes[-1])
        # The cluster is deleted last of all (after the failure), and no k6 ran.
        self.assertIn("cluster delete", [call for call in self.calls() if "k3d-io/k3d" in call][-1])
        self.assertEqual([], [call for call in self.calls() if "grafana/k6" in call])


if __name__ == "__main__":
    unittest.main()

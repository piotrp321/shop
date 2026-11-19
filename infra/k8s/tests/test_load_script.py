"""scripts/load.sh (make -C infra/k8s load) on a stub of docker: the refusals of the environment (exit 2, a message, no
cluster made or the cluster deleted) of docs/features/hardening/spec.md, "Testy obciążeniowe", and the data of LOAD_DATA
(docs/features/demo-data/spec.md, "k3d"): the Job of the generator, the stocks of the chunks of the sitemap, the Secret of the
photo pool. The measurement itself is not run here (it needs a cluster and takes tens of minutes); `make -C infra/k8s load` is.

k3d, kubectl and helm are shell functions of tools.sh that run `docker run <image> ...`, so a stub of docker is the
only thing the script talks to."""

import base64
import json
import math
import os
import pathlib
import re
import shutil
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/scripts/load.sh"

# One line of arguments per call in $STUB_LOG. Answers by the command: the resources of the Docker VM, k3d (a kubeconfig),
# kubectl (nothing exists in the cluster: no jobs, no deployments; with STUB_READY=1 the cluster is settled, has STUB_PRODUCTS
# products and answers the UPDATE of the stocks with STUB_UPDATE_OUT and the exit code STUB_UPDATE_STATUS), everything else
# succeeds silently. The standard input of `kubectl create -f -` is kept in $STUB_STDIN_DIR (create.<n>). The Job of the
# generator is in the state STUB_DATA_JOB (Complete by default) with the log STUB_DATA_LOG; curl answers the chunks of the
# sitemap with STUB_CHUNKS (exit STUB_CHUNKS_STATUS); k6 writes its results to STUB_K6_OUT when it is set, and with
# STUB_K6_SLEEP it runs that many seconds (its pid in $STUB_SPACE_DIR/k6.pid, `docker rm -f k6-...` ends it). The free space
# of the disks (the checks of W2) comes from STUBSPACE (see there); `df -Pk` of the host is the stub DF. A `docker run` that
# mounts the directory STUB_MOUNT_DENIED (-v <directory>:...) is refused as Docker Desktop refuses a path that is not shared
# (exit 125, "mounts denied"); k6 ends with <code> when STUB_K6_FAIL is <script>:<code> (125: the docker run itself failed).
# With STUB_DATA_JOB_AFTER=<n> the Job of the generator is Complete from the poll n + 1 on (the polls are counted in
# $STUB_SPACE_DIR/job.polls).
DOCKER = r"""#!/bin/sh
# The try of the mount of LOAD_OUT is kept apart in $STUB_LOG.mount (with the number of the calls of $STUB_LOG before it), so that
# the tests of what is made before the cluster still see only the calls that make something.
case "$*" in
  *"touch /out/.mount-check"*) echo "$(wc -l < "$STUB_LOG" | tr -d ' ') $*" >> "$STUB_LOG.mount" ;;
  *)
    printf '%s\n' "$*" | tr '\n' ' ' >> "$STUB_LOG"
    echo >> "$STUB_LOG" ;;
esac
case "$*" in
  *registry.k8s.io/kubectl*"create -f -"*)
    n="$(ls "$STUB_STDIN_DIR" | wc -l | tr -d ' ')"
    cat > "$STUB_STDIN_DIR/create.$n" ;;
esac
case "$1" in
  info)
    [ -z "${STUB_NO_DOCKER:-}" ] || { echo "Cannot connect to the Docker daemon" >&2; exit 1; }
    echo "${STUB_NCPU:-16} ${STUB_MEM_BYTES:-34359738368}" ;;
  stats) printf '1.00%% 100MiB / 1GiB\n' ;;
  rm)
    case "$*" in
      *k6-ld-loadtest*) [ ! -f "$STUB_SPACE_DIR/k6.pid" ] || kill "$(cat "$STUB_SPACE_DIR/k6.pid")" 2>/dev/null || true ;;
    esac ;;
  run)
    case "$*" in
      *" -v ${STUB_MOUNT_DENIED:-/nonexistent-stub-path}:"*)
        echo "docker: Error response from daemon: mounts denied: The path $STUB_MOUNT_DENIED is not shared from the host and is not known to Docker." >&2
        exit 125 ;;
    esac
    case "$*" in
      *"--entrypoint df "*) stubspace vm ;;
      *k3d-io/k3d*"kubeconfig get"*) printf 'apiVersion: v1\nclusters:\n- cluster:\n    server: https://0.0.0.0:6443\n' ;;
      *registry.k8s.io/kubectl*)
        [ -n "${STUB_READY:-}" ] || exit 0
        case "$*" in
          *"get crd"*) ;;
          *"get jobs"*succeeded*)
            # The version jobs as "name=succeeded" (a running job has no value); a jsonpath without the name gets the values only.
            for job in ${STUB_JOBS:-a=1 b=1 c=1 d=1 e=1 f=1}; do
              case "$*" in *metadata.name*) printf '%s\n' "$job" ;; *) printf '%s\n' "${job#*=}" ;; esac
            done ;;
          *"get deployments"*) printf '1 1 1 1 1 1\n' ;;
          *"get statefulsets"*) printf '1 1\n' ;;
          *"get service traefik"*) printf '30080' ;;
          *"exec statefulset/postgres"*"UPDATE "*)
            [ -z "${STUB_UPDATE_OUT:-}" ] || printf '%s\r\n' "$STUB_UPDATE_OUT"
            echo "psql: stub error" >&2
            exit "${STUB_UPDATE_STATUS:-0}" ;;
          *"exec statefulset/postgres"*"count(*)"*) printf '%s\r\n' "${STUB_PRODUCTS:-5}" ;;
          *"exec statefulset/postgres"*"pg_database_size"*) printf 'stub sizes of postgres\n' ;;
          *"exec statefulset/elasticsearch"*"_cat/indices"*) printf 'stub indices of elasticsearch\n' ;;
          *"get job demo-data-load"*activeDeadlineSeconds*) printf '7200' ;;
          *"get job demo-data-load"*conditions*)
            stubdrop job
            if [ -n "${STUB_DATA_JOB_AFTER:-}" ]; then
              n=0
              [ ! -f "$STUB_SPACE_DIR/job.polls" ] || n="$(cat "$STUB_SPACE_DIR/job.polls")"
              n=$((n + 1))
              echo "$n" > "$STUB_SPACE_DIR/job.polls"
              [ "$n" -le "$STUB_DATA_JOB_AFTER" ] || printf 'Complete'
            else
              printf '%s' "${STUB_DATA_JOB-Complete}"
            fi ;;
          *"logs job/demo-data-load"*) printf '%s\n' "${STUB_DATA_LOG:-2026-11-18T10:00:00.000000000Z seeding}" ;;
        esac ;;
      *curlimages/curl*"/api/v1/sitemap/product-chunks"*)
        printf '%s' "${STUB_CHUNKS:-}"
        exit "${STUB_CHUNKS_STATUS:-0}" ;;
      *grafana/k6*)
        stubdrop k6
        case "${STUB_K6_FAIL:-}" in
          ?*:*) case "$*" in *"${STUB_K6_FAIL%:*}"*) echo "stub k6 failed" >&2; exit "${STUB_K6_FAIL#*:}" ;; esac ;;
        esac
        if [ -n "${STUB_K6_SLEEP:-}" ]; then echo $$ > "$STUB_SPACE_DIR/k6.pid"; exec sleep "$STUB_K6_SLEEP"; fi
        [ -n "${STUB_K6_OUT:-}" ] || exit 0
        case "$*" in
          *saturate.js*) echo '{}' > "$STUB_K6_OUT/ramp.json"; echo 5 > "$STUB_K6_OUT/steady-rate" ;;
          *steady.js*) echo '{}' > "$STUB_K6_OUT/steady.json" ;;
        esac ;;
    esac ;;
esac
exit 0
"""

# The free space of the disk `vm` (the Docker VM: `docker run --entrypoint df <image> -Pk /`) or `host` (`df -Pk <path>`) as the
# line of df, in KiB: STUB_VM_FREE_KB or STUB_HOST_FREE_KB (plenty by default), or the content of $STUB_SPACE_DIR/<disk> once
# STUBDROP wrote it (the space that falls during the run). The value `fail` is a failing df, `none` a df that prints nothing,
# any other text is printed as it is in the column of the free space. STUB_VM_FREE_SEQ or STUB_HOST_FREE_SEQ (values separated with
# spaces) is a space that falls: each reading takes the next value, the last one stays.
STUBSPACE = r"""#!/bin/sh
sequence=""
case "$1" in
  vm) sequence="${STUB_VM_FREE_SEQ:-}" ;;
  host) sequence="${STUB_HOST_FREE_SEQ:-}" ;;
esac
if [ -f "$STUB_SPACE_DIR/$1" ]; then
  value="$(cat "$STUB_SPACE_DIR/$1")"
elif [ -n "$sequence" ]; then
  n=0
  [ ! -f "$STUB_SPACE_DIR/$1.read" ] || n="$(cat "$STUB_SPACE_DIR/$1.read")"
  n=$((n + 1))
  echo "$n" > "$STUB_SPACE_DIR/$1.read"
  value=""
  i=0
  for item in $sequence; do
    i=$((i + 1))
    value="$item"
    [ "$i" -lt "$n" ] || break
  done
else
  case "$1" in
    vm) value="${STUB_VM_FREE_KB-900000000}" ;;
    host) value="${STUB_HOST_FREE_KB-900000000}" ;;
  esac
fi
case "$value" in
  fail) exit 1 ;;
  none) exit 0 ;;
esac
printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n'
printf 'stub 1000000000 1 %s 1%% /stub mount\n' "$value"
"""

# STUBDROP <phase>: when STUB_SPACE_DROP is <phase>:<vm|host>:<value> the free space of that disk is that value from now on
# (the phase is `job`, a poll of the Job of the generator, or `k6`, a start of k6).
STUBDROP = r"""#!/bin/sh
case "${STUB_SPACE_DROP:-}" in
  "$1":*)
    rest="${STUB_SPACE_DROP#*:}"
    printf '%s' "${rest#*:}" > "$STUB_SPACE_DIR/${rest%%:*}" ;;
esac
"""

# The stub of the df of the host: the call is kept in $STUB_SPACE_DIR/host.calls.
DF = r"""#!/bin/sh
echo "$*" >> "$STUB_SPACE_DIR/host.calls"
exec stubspace host
"""

# The clock of the host: `date +%s` is the real time plus the number of seconds in $STUB_SPACE_DIR/skew (a host that slept, and
# a clock that went on); STUBDROP writes the file at the moment of a phase (STUB_SPACE_DROP=<phase>:skew:<seconds>). Any other date
# is the real one.
DATE = r"""#!/bin/sh
case "$*" in
  "+%s")
    skew=0
    [ ! -f "$STUB_SPACE_DIR/skew" ] || skew="$(cat "$STUB_SPACE_DIR/skew")"
    echo $(( $(/bin/date +%s) + skew )) ;;
  *) exec /bin/date "$@" ;;
esac
"""

# caffeinate of macOS (only the tests of the sleep lock install it): the arguments are kept in $STUB_SPACE_DIR/caffeinate.calls, and
# a process that `-w` waits for must exist (caffeinate.waited), as with the real one. It runs, as the real one does, until it is
# stopped (its pid in caffeinate.pid), unless STUB_CAFFEINATE_FAIL makes it fail at once.
CAFFEINATE = r"""#!/bin/sh
echo "$*" >> "$STUB_SPACE_DIR/caffeinate.calls"
[ -z "${STUB_CAFFEINATE_FAIL:-}" ] || { echo "caffeinate: stub failure" >&2; exit 1; }
while [ $# -gt 0 ]; do
  if [ "$1" = -w ]; then shift; kill -0 "$1" && echo "$1" > "$STUB_SPACE_DIR/caffeinate.waited"; fi
  shift
done
echo $$ > "$STUB_SPACE_DIR/caffeinate.pid"
exec sleep 300
"""

# The read access of the photo pool as the file of the user has it, with the token that writes next to it.
READ_ACCESS = {
    "DEMO_PHOTOS_ENDPOINT": "https://endpoint-7f3a1c.r2.example.com",
    "DEMO_PHOTOS_BUCKET": "bucket-3c4d9e-photos",
    "DEMO_PHOTOS_ACCESS_KEY_ID": "readkeyid5e6f7a8b9c",
    "DEMO_PHOTOS_SECRET_ACCESS_KEY": "readsecret9c0d1e2f3a4b5c6d",
}
WRITE_ACCESS = {
    "DEMO_PHOTOS_WRITE_ACCESS_KEY_ID": "writekeyid1234abcd",
    "DEMO_PHOTOS_WRITE_SECRET_ACCESS_KEY": "writesecret5678efgh",
}


def chunk_key(start, end):
    """A key as SitemapChunkKey::encode() makes it: base64url without padding of {"f": start, "t": end}."""
    text = json.dumps({"f": start, "t": end}, separators=(",", ":")).encode()
    return base64.urlsafe_b64encode(text).decode().rstrip("=")


PROFILES_FILE = REPO / "apps/api/fixtures/demo-data/profiles.yaml"


def disk_peak_gb(profile):
    """diskPeakGb of a profile of profiles.yaml (the test follows the file when the measurement of medium recalibrates it)."""
    section = re.search(rf"(?ms)^  {profile}:\n(.*?)(?=^  \w+:\n|\Z)", PROFILES_FILE.read_text())
    return float(re.search(r"(?m)^    diskPeakGb: ([0-9.]+)$", section.group(1)).group(1))


def kib(gb):
    """The KiB of df -k that hold gb GB (10^9 bytes), rounded up."""
    return math.ceil(gb * 1_000_000_000 / 1024)


def process_alive(pid):
    """A process that runs; a zombie (not reaped by the pid 1 of the container) is not alive."""
    stat = pathlib.Path(f"/proc/{pid}/stat")
    if stat.exists():
        return stat.read_text().rsplit(")", 1)[1].split()[0] != "Z"
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    return True


BOUNDARIES = [None, "1f000000-0000-4000-8000-000000000000", "3f000000-0000-4000-8000-000000000000",
              "5f000000-0000-4000-8000-000000000000", "7f000000-0000-4000-8000-000000000000", None]
CHUNK_KEYS = [chunk_key(BOUNDARIES[i], BOUNDARIES[i + 1]) for i in range(len(BOUNDARIES) - 1)]
CHUNKS_ANSWER = json.dumps({"chunks": [{"key": key} for key in CHUNK_KEYS]}, separators=(",", ":"))

# The log of the Job as kubectl logs --timestamps prints it: the steps end with the [OK] line of SymfonyStyle, the parts of a
# step one line per worker.
GENERATOR_LOG = "\n".join([
    "2026-11-18T10:00:00.000000000Z 4",
    "2026-11-18T10:00:30.500000000Z  [OK] categories: 10 written, 0 already there                ",
    "2026-11-18T10:02:00.000000000Z  [OK] products (worker 0 of 2): 1 parts, 10 products, 20 variants",
    "2026-11-18T10:02:10.000000000Z  [OK] products (worker 1 of 2): 1 parts, 10 products, 20 variants",
    "2026-11-18T10:03:10.000000000Z  [OK] the generated data is consistent",
])


class LoadScript(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = pathlib.Path(self.tmp.name)
        self.bin = base / "bin"
        self.bin.mkdir()
        for name, text in (("docker", DOCKER), ("stubspace", STUBSPACE), ("stubdrop", STUBDROP), ("df", DF), ("date", DATE)):
            (self.bin / name).write_text(text)
            (self.bin / name).chmod(0o755)
        self.space = base / "space"
        self.space.mkdir()
        self.log = base / "docker.log"
        self.log.write_text("")
        self.out = base / "out"
        self.out.mkdir()
        self.stdin = base / "stdin"
        self.stdin.mkdir()

    def tearDown(self):
        self.tmp.cleanup()

    def run_load(self, shell_options=(), **env):
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": self.tmp.name,
            "TMPDIR": self.tmp.name,
            "TOOLS_CACHE": f"{self.tmp.name}/cache",
            "COMPOSE_PROJECT_NAME": "loadtest",
            "STUB_LOG": str(self.log),
            "STUB_STDIN_DIR": str(self.stdin),
            "STUB_SPACE_DIR": str(self.space),
            "LOAD_POLL": "1",
            **env,
        }
        return subprocess.run(["sh", *shell_options, str(SCRIPT)], env=variables, capture_output=True, text=True, timeout=120)

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

    def test_a_load_out_that_docker_cannot_mount_ends_with_2_before_any_image_or_cluster(self):
        """Docker Desktop mounts only the shared paths: that is found before the 30 minutes of images, cluster and data."""
        shared = os.path.realpath(self.out)
        for data in ("seed", "medium"):
            with self.subTest(data=data):
                self.log.write_text("")
                result = self.run_load(LOAD_OUT=str(self.out), LOAD_DATA=data, LOAD_TIMEOUT="2",
                                       DEMO_PHOTOS_ENV_FILE=self.photo_file(), STUB_MOUNT_DENIED=shared)
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn(f"Docker cannot mount LOAD_OUT '{shared}'", result.stderr)
                self.assertIn("File Sharing", result.stderr)
                self.assertNotIn("shop does not answer", result.stderr)
                for word in ("build", "k3d-io/k3d", "kubectl", "grafana/k6", "helm"):
                    self.assertEqual([], [call for call in self.calls() if word in call], word)

    def test_the_mount_of_load_out_is_tried_like_k6_mounts_it_before_any_image_is_built(self):
        """The same options as k6_run (the user of the host, -v <directory>:/out), an image the script already uses."""
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", STUB_READY="1", STUB_UPDATE_STATUS="1")
        mounts = pathlib.Path(f"{self.log}.mount").read_text().splitlines()
        self.assertEqual(1, len(mounts), mounts)
        before, mount = mounts[0].split(" ", 1)
        self.assertIn(f"-v {os.path.realpath(self.out)}:/out", mount)
        self.assertIn(f"-u {os.getuid()}:{os.getgid()}", mount)
        self.assertIn("python:3.13-alpine", mount)
        builds = [i for i, call in enumerate(self.calls()) if call.startswith("build ")]
        self.assertTrue(builds, "the images were not built")
        self.assertLessEqual(int(before), builds[0])
        self.assertLessEqual(int(before), min(i for i, call in enumerate(self.calls()) if "k3d-io/k3d" in call))

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

    def test_the_memory_that_docker_reports_a_little_below_the_set_one_is_enough(self):
        """Docker Desktop reports 31.3 GiB for 32 GiB set: a VM set to the 20 GiB of the spec must pass (95% of the limit)."""
        for gib in (19.5, 20, 32):
            with self.subTest(gib=gib):
                self.log.write_text("")
                result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", STUB_MEM_BYTES=str(int(gib * 1024**3)))
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertNotIn("memory", result.stderr)
                self.assertEqual(1, len(self.cluster_calls("create")))

    def test_memory_well_below_the_limit_is_refused_with_the_limit_in_the_message(self):
        result = self.run_load(LOAD_OUT=str(self.out), STUB_MEM_BYTES=str(int(18.5 * 1024**3)))
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("at least 20 GiB", result.stderr)
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

    def test_a_running_version_job_is_not_ready_even_when_the_others_have_succeeded(self):
        """A job that still runs has no succeeded: its empty line must not be skipped, or the six jobs look like five."""
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", STUB_READY="1",
                               STUB_JOBS="a=1 b=1 c=1 d=1 e=1 f=")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("not ready", result.stderr)
        self.assertNotIn("stocks", result.stderr)

    def test_fewer_than_six_version_jobs_are_not_ready(self):
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", STUB_READY="1",
                               STUB_JOBS="a=1 b=1 c=1 d=1 e=1")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("not ready", result.stderr)
        self.assertNotIn("stocks", result.stderr)

    def test_six_succeeded_version_jobs_are_ready(self):
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", STUB_READY="1", STUB_UPDATE_STATUS="1")
        self.assertIn("all jobs complete", result.stdout)
        self.assertNotIn("not ready", result.stderr)

    def assert_ends_with_2_after_the_stocks_failed(self, result):
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("stocks", result.stderr)
        self.assertEqual(1, len(self.cluster_calls("create")))
        # No k6 ran, and the cluster is deleted last of all.
        self.assertEqual([], [call for call in self.calls() if "grafana/k6" in call])
        self.assertIn("cluster delete", [call for call in self.calls() if "k3d-io/k3d" in call][-1])
        self.assertIn("ld-loadtest", self.cluster_calls("delete")[-1])

    def test_a_failing_update_of_the_stocks_ends_with_2_and_the_cluster_is_deleted(self):
        """The exit code of the pipe must not hide a failed psql: the checkout would use up the stocks of the demo data."""
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_BUILD="0", STUB_READY="1", STUB_UPDATE_STATUS="1")
        self.assert_ends_with_2_after_the_stocks_failed(result)

    def test_an_update_that_raised_no_stock_ends_with_2_and_the_cluster_is_deleted(self):
        for answer in ("UPDATE 0", "something else"):
            with self.subTest(answer=answer):
                self.log.write_text("")
                result = self.run_load(LOAD_OUT=str(self.out), LOAD_BUILD="0", STUB_READY="1", STUB_UPDATE_OUT=answer)
                self.assert_ends_with_2_after_the_stocks_failed(result)

    # -----------------------------------------------------------------------------------------------------------------
    # LOAD_DATA: the generator, the stocks of the chunks of the sitemap and the Secret of the photo pool
    # -----------------------------------------------------------------------------------------------------------------
    def photo_file(self, values=None, extra=""):
        """The file of the user with the access of the photo pool (the read variables and the token that writes)."""
        values = {**READ_ACCESS, **WRITE_ACCESS} if values is None else values
        path = pathlib.Path(self.tmp.name) / "demo-photos.env"
        path.write_text("# the photo pool\n" + "".join(f"{name}={value}\n" for name, value in values.items()) + extra)
        return str(path)

    def run_data(self, profile="medium", shell_options=(), **env):
        """A run with LOAD_DATA=<profile> on a settled cluster to the end (k6 writes its results)."""
        variables = {
            "LOAD_OUT": str(self.out), "LOAD_BUILD": "0", "LOAD_COOLDOWN": "0", "LOAD_DATA_POLL": "1", "STUB_READY": "1",
            "STUB_UPDATE_OUT": "UPDATE 12", "STUB_CHUNKS": CHUNKS_ANSWER, "STUB_DATA_LOG": GENERATOR_LOG,
            "STUB_K6_OUT": str(self.out), "LOAD_DATA": profile,
            **env,
        }
        if "DEMO_PHOTOS_ENV_FILE" not in env:
            variables["DEMO_PHOTOS_ENV_FILE"] = self.photo_file()
        return self.run_load(shell_options=shell_options, **variables)

    def index_of(self, needle):
        indexes = [i for i, call in enumerate(self.calls()) if needle in call]
        self.assertTrue(indexes, f"no call with {needle!r}")
        return indexes[0]

    def k6_calls(self):
        return [call for call in self.calls() if "grafana/k6" in call]

    def update_calls(self):
        return [call for call in self.calls() if "exec statefulset/postgres" in call and "UPDATE " in call]

    def created_secrets(self):
        documents = [json.loads(path.read_text()) for path in sorted(self.stdin.iterdir())]
        return [d for d in documents if d.get("kind") == "Secret"]

    def assert_no_docker_call(self, result, *words):
        self.assertEqual(2, result.returncode, result.stderr)
        for word in words:
            self.assertIn(word, result.stderr)
        self.assertEqual([], self.calls())

    def assert_ends_with_2_and_the_cluster_is_deleted(self, result, *words):
        self.assertEqual(2, result.returncode, result.stderr)
        for word in words:
            self.assertIn(word, result.stderr)
        self.assertEqual(1, len(self.cluster_calls("create")))
        self.assertEqual([], self.k6_calls())
        self.assertIn("cluster delete", [call for call in self.calls() if "k3d-io/k3d" in call][-1])
        self.assertIn("ld-loadtest", self.cluster_calls("delete")[-1])

    def test_an_unknown_load_data_ends_with_2_before_any_docker_call(self):
        for value in ("small", "bogus", "Medium", "medium full", "seed;x"):
            with self.subTest(value=value):
                self.log.write_text("")
                result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", LOAD_DATA=value)
                self.assert_no_docker_call(result, "LOAD_DATA", "seed, medium, server or full")

    def test_without_the_photo_file_a_generated_profile_ends_with_2_before_any_docker_call(self):
        missing = str(pathlib.Path(self.tmp.name) / "missing.env")
        for profile in ("medium", "server", "full"):
            with self.subTest(profile=profile):
                self.log.write_text("")
                result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", LOAD_DATA=profile, DEMO_PHOTOS_ENV_FILE=missing)
                self.assert_no_docker_call(result, missing, "DEMO_PHOTOS_ENV_FILE")

    def test_the_photo_file_is_by_default_in_the_config_of_the_user(self):
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", LOAD_DATA="medium")
        self.assert_no_docker_call(result, f"{self.tmp.name}/.config/shop/demo-photos.env")
        default = pathlib.Path(self.tmp.name) / ".config/shop"
        default.mkdir(parents=True)
        (default / "demo-photos.env").write_text("".join(f"{n}={v}\n" for n, v in READ_ACCESS.items()))
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", LOAD_DATA="medium")
        self.assertIn("not ready", result.stderr)
        self.assertEqual(1, len(self.cluster_calls("create")))

    def test_a_photo_file_without_a_read_variable_ends_with_2_before_any_docker_call(self):
        for name in READ_ACCESS:
            for value in (None, "", '""', "   "):
                with self.subTest(name=name, value=value):
                    self.log.write_text("")
                    values = {n: v for n, v in {**READ_ACCESS, **WRITE_ACCESS}.items() if n != name}
                    if value is not None:
                        values[name] = value
                    result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", LOAD_DATA="medium", DEMO_PHOTOS_ENV_FILE=self.photo_file(values))
                    self.assert_no_docker_call(result, name)
                    for secret in {**READ_ACCESS, **WRITE_ACCESS}.values():
                        self.assertNotIn(secret, result.stdout + result.stderr)

    def test_the_token_that_writes_does_not_stand_for_the_read_access(self):
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", LOAD_DATA="medium", DEMO_PHOTOS_ENV_FILE=self.photo_file(WRITE_ACCESS))
        self.assert_no_docker_call(result, *READ_ACCESS)

    def test_the_file_is_read_as_data_and_nothing_in_it_runs(self):
        marker = pathlib.Path(self.tmp.name) / "ran"
        values = {**READ_ACCESS, "DEMO_PHOTOS_BUCKET": f"$(touch {marker})`touch {marker}`"}
        result = self.run_data(DEMO_PHOTOS_ENV_FILE=self.photo_file(values, extra=f"touch {marker}\n"))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertFalse(marker.exists())
        data = self.created_secrets()[0]["data"]
        self.assertEqual(values["DEMO_PHOTOS_BUCKET"], base64.b64decode(data["DEMO_PHOTOS_BUCKET"]).decode())

    def test_quotes_and_carriage_returns_of_the_file_are_not_part_of_the_values(self):
        path = pathlib.Path(self.tmp.name) / "quoted.env"
        names = list(READ_ACCESS)
        path.write_text(f'{names[0]}="{READ_ACCESS[names[0]]}"\r\n{names[1]}=\'{READ_ACCESS[names[1]]}\'\r\n'
                        f"{names[2]}={READ_ACCESS[names[2]]}\r\n{names[3]}={READ_ACCESS[names[3]]}")
        result = self.run_data(DEMO_PHOTOS_ENV_FILE=str(path))
        self.assertEqual(0, result.returncode, result.stderr)
        data = self.created_secrets()[0]["data"]
        self.assertEqual(READ_ACCESS, {k: base64.b64decode(v).decode() for k, v in data.items()})

    def test_a_generated_profile_runs_the_generator_once_and_measures_the_chunks_it_raised(self):
        result = self.run_data("server")
        self.assertEqual(0, result.returncode, result.stderr)
        helm = [call for call in self.calls() if "alpine/helm" in call]
        self.assertEqual(1, len(helm))
        self.assertIn("demoData.profile=server", helm[0])
        self.assertIn("demoData.photosSecretName=demo-photos", helm[0])
        jobs = [call for call in self.calls() if "create job" in call]
        self.assertEqual(1, len(jobs), jobs)
        self.assertIn("create job demo-data-load --from=cronjob/demo-data", jobs[0])
        # The Secret before the Job, the Job before the stocks, the stocks before k6.
        self.assertLess(self.index_of("create -f -"), self.index_of("create job demo-data-load"))
        self.assertLess(self.index_of("create job demo-data-load"), self.index_of("UPDATE "))
        self.assertLess(self.index_of("UPDATE "), self.index_of("grafana/k6"))
        # The stocks of the variants of the products of the chunks only, three of the five of the API by default.
        updates = self.update_calls()
        self.assertEqual(1, len(updates))
        self.assertIn("catalog_product_variant", updates[0])
        chosen = [key for key in CHUNK_KEYS if key in updates[0]]
        self.assertEqual(3, len(chosen), updates[0])
        # Every k6 run gets the keys of the raised chunks.
        self.assertEqual(3, len(self.k6_calls()))
        for call in self.k6_calls():
            given = re.search(r"-e DISCOVER_CHUNKS=(\S+)", call)
            self.assertIsNotNone(given, call)
            self.assertEqual(sorted(chosen), sorted(given.group(1).split(",")))
        environment = (self.out / "environment.txt").read_text()
        self.assertIn("LOAD_DATA: server", environment)
        for key in chosen:
            self.assertIn(key, environment)

    def test_the_secret_of_the_photo_pool_has_the_four_read_variables_and_no_token_that_writes(self):
        result = self.run_data()
        self.assertEqual(0, result.returncode, result.stderr)
        secrets = self.created_secrets()
        self.assertEqual(1, len(secrets))
        self.assertEqual("demo-photos", secrets[0]["metadata"]["name"])
        decoded = {k: base64.b64decode(v).decode() for k, v in secrets[0]["data"].items()}
        self.assertEqual(READ_ACCESS, decoded)
        stdin = "".join(path.read_text() for path in self.stdin.iterdir())
        for value in WRITE_ACCESS.values():
            self.assertNotIn(value, stdin)
            self.assertNotIn(base64.b64encode(value.encode()).decode(), stdin)

    def leaked(self, result, values):
        """The values (and their base64) found in the output, in the arguments of the calls or in a file of LOAD_OUT."""
        places = {"output": result.stdout + result.stderr, "arguments": self.log.read_text()}
        for path in self.out.rglob("*"):
            if path.is_file():
                places[f"LOAD_OUT/{path.name}"] = path.read_text(errors="replace")
        found = []
        for value in values:
            for form in (value, base64.b64encode(value.encode()).decode()):
                found += [f"{form} in {place}" for place, text in places.items() if form in text]
        return found

    def test_no_value_of_the_photo_pool_is_in_the_output_the_arguments_or_load_out(self):
        values = [*READ_ACCESS.values(), *WRITE_ACCESS.values()]
        for options in ((), ("-x",)):
            with self.subTest(shell_options=options):
                self.log.write_text("")
                for path in self.stdin.iterdir():
                    path.unlink()
                result = self.run_data(shell_options=options)
                self.assertEqual(0, result.returncode, result.stderr[-3000:])
                self.assertEqual([], self.leaked(result, values))

    def test_a_value_of_the_photo_pool_in_the_log_of_the_generator_is_masked(self):
        secret = READ_ACCESS["DEMO_PHOTOS_SECRET_ACCESS_KEY"]
        log = GENERATOR_LOG + f"\n2026-11-18T10:03:11.000000000Z a line with {secret} and {READ_ACCESS['DEMO_PHOTOS_BUCKET']}"
        result = self.run_data(STUB_DATA_LOG=log)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([], self.leaked(result, READ_ACCESS.values()))
        self.assertIn("masked", result.stderr)
        self.assertIn("a line with", (self.out / "demo-data.log").read_text())

    def test_the_failed_generator_ends_with_2_with_its_log_and_the_cluster_is_deleted(self):
        log = GENERATOR_LOG + "\n2026-11-18T10:04:00.000000000Z [ERROR] the step of the orders broke"
        result = self.run_data(STUB_DATA_JOB="Failed", STUB_DATA_LOG=log)
        self.assert_ends_with_2_and_the_cluster_is_deleted(result, "generator", "the step of the orders broke")
        self.assertEqual([], self.update_calls())

    def test_a_generator_that_does_not_finish_in_time_ends_with_2_with_its_log_and_the_cluster_is_deleted(self):
        log = "2026-11-18T10:00:00.000000000Z still generating the products"
        result = self.run_data(STUB_DATA_JOB="", STUB_DATA_LOG=log, LOAD_DATA_TIMEOUT="2")
        self.assert_ends_with_2_and_the_cluster_is_deleted(result, "generator", "2 s", "still generating the products")
        self.assertEqual([], self.update_calls())

    def test_the_chunks_that_cannot_be_read_end_with_2_and_the_cluster_is_deleted(self):
        for env in ({"STUB_CHUNKS_STATUS": "22"}, {"STUB_CHUNKS": '{"chunks":[]}'}, {"STUB_CHUNKS": "<html>bad gateway</html>"}):
            with self.subTest(env=env):
                self.log.write_text("")
                result = self.run_data(**env)
                self.assert_ends_with_2_and_the_cluster_is_deleted(result, "chunks")
                self.assertEqual([], self.update_calls())

    def test_no_stock_raised_in_the_chunks_ends_with_2_and_the_cluster_is_deleted(self):
        for env in ({"STUB_UPDATE_OUT": "UPDATE 0"}, {"STUB_UPDATE_STATUS": "1"}):
            with self.subTest(env=env):
                self.log.write_text("")
                result = self.run_data(**env)
                self.assert_ends_with_2_and_the_cluster_is_deleted(result, "stocks")

    def test_the_chunks_given_in_discover_chunks_are_the_ones_raised_and_measured(self):
        given = [CHUNK_KEYS[4], CHUNK_KEYS[1]]
        result = self.run_data(DISCOVER_CHUNKS=",".join(given))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([], [call for call in self.calls() if "curlimages/curl" in call])
        update = self.update_calls()[0]
        self.assertEqual(sorted(given), sorted(key for key in CHUNK_KEYS if key in update))
        for call in self.k6_calls():
            self.assertIn("-e DISCOVER_CHUNKS=" + ",".join(given), call)

    def test_the_count_of_chunks_is_an_option(self):
        for count, raised in (("2", 2), ("9", 5), ("0", 1)):
            with self.subTest(count=count):
                self.log.write_text("")
                result = self.run_data(DISCOVER_CHUNK_COUNT=count)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(raised, len([key for key in CHUNK_KEYS if key in self.update_calls()[0]]))

    def test_wrong_options_of_the_data_end_with_2_before_any_docker_call(self):
        for name, value in (("DISCOVER_CHUNKS", "abc def"), ("DISCOVER_CHUNKS", "abc'),('x"), ("DISCOVER_CHUNKS", "a;b"),
                            ("DISCOVER_CHUNK_COUNT", "three"), ("DISCOVER_PRODUCTS", "-1"), ("LOAD_DATA_TIMEOUT", "1h"),
                            ("LOAD_DATA_POLL", "x"), ("LOAD_DATA_ANCHOR", "2026-11-18,x"), ("LOAD_DATA_ANCHOR", "2026 11"),
                            # Only what the schema of the chart takes (demoData.anchor): a date or a UTC moment.
                            ("LOAD_DATA_ANCHOR", "2026-11-01T00:00:00+01:00"), ("LOAD_DATA_ANCHOR", "2026-11-1")):
            with self.subTest(name=name, value=value):
                self.log.write_text("")
                result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", LOAD_BUILD="0", LOAD_DATA="medium", DEMO_PHOTOS_ENV_FILE=self.photo_file(), **{name: value})
                self.assert_no_docker_call(result, name)

    def test_the_anchor_goes_to_the_generator(self):
        result = self.run_data(LOAD_DATA_ANCHOR="2026-11-01")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("demoData.anchor=2026-11-01", [call for call in self.calls() if "alpine/helm" in call][0])

    def test_load_out_has_the_times_of_the_steps_of_the_generator_and_the_sizes(self):
        result = self.run_data()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("[OK] categories", (self.out / "demo-data.log").read_text())
        steps = (self.out / "demo-data-steps.txt").read_text()
        self.assertRegex(steps, r"(?m)^\s*30 s\s+categories$")
        self.assertRegex(steps, r"(?m)^\s*100 s\s+products$")
        self.assertRegex(steps, r"(?m)^\s*60 s\s+the generated data is consistent$")
        self.assertRegex(steps, r"(?m)^total: 190 s")
        sizes = (self.out / "sizes.txt").read_text()
        self.assertIn("stub sizes of postgres", sizes)
        self.assertIn("stub indices of elasticsearch", sizes)
        sizes_call = self.calls()[self.index_of("pg_database_size")]
        for what in ("pg_total_relation_size", "pg_indexes_size", "pg_relation_size"):
            self.assertIn(what, sizes_call)

    def test_a_k6_that_docker_did_not_start_says_so_and_not_that_the_shop_does_not_answer(self):
        """Exit 125 is the exit of `docker run` itself (a mount, an image), not of k6; the other codes keep their meaning."""
        cases = (("warmup.js", 2, "the warm-up"), ("saturate.js", 1, "the ramp"), ("steady.js", 1, "the steady run"))
        for script, code, name in cases:
            with self.subTest(script=script):
                self.log.write_text("")
                result = self.run_data("seed", STUB_K6_FAIL=f"{script}:125")
                self.assertEqual(code, result.returncode, result.stderr)
                self.assertIn(f"{name} failed: Docker did not start k6 (docker run exit 125; above)", result.stderr)
                self.assertNotIn("shop does not answer", result.stderr)

    def test_a_k6_that_fails_with_another_code_keeps_its_messages(self):
        result = self.run_data("seed", STUB_K6_FAIL="warmup.js:1")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("the warm-up failed (k6 exit 1; above): the shop does not answer as the scripts expect", result.stderr)
        result = self.run_data("seed", STUB_K6_FAIL="saturate.js:1")
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("the ramp failed (k6 exit 1; above)", result.stderr)
        result = self.run_data("seed", STUB_K6_FAIL="steady.js:1")
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("the steady run failed (k6 exit 1; above)", result.stderr)

    def test_seed_raises_every_stock_and_runs_no_generator(self):
        for env in ({}, {"LOAD_DATA": "seed"}, {"LOAD_DATA": ""}):
            with self.subTest(env=env):
                self.log.write_text("")
                result = self.run_load(LOAD_OUT=str(self.out), LOAD_BUILD="0", LOAD_COOLDOWN="0", STUB_READY="1", STUB_UPDATE_OUT="UPDATE 7",
                                       STUB_K6_OUT=str(self.out), DISCOVER_PRODUCTS="10", **env)
                self.assertEqual(0, result.returncode, result.stderr)
                update = self.update_calls()
                self.assertEqual(1, len(update))
                self.assertIn("UPDATE inventory_stock_item SET physical = physical + 100000, version = version + 1, updated_at = now() WHERE physical > 0", update[0])
                self.assertNotIn("catalog_product_variant", update[0])
                for word in ("create job", "create -f -", "curlimages/curl", "demoData."):
                    self.assertEqual([], [call for call in self.calls() if word in call], word)
                for call in self.k6_calls():
                    self.assertIn("-e DISCOVER_PRODUCTS=10", call)
                    self.assertNotIn("DISCOVER_CHUNKS", call)
                self.assertIn("LOAD_DATA: seed", (self.out / "environment.txt").read_text())
                self.assertIn("stub sizes of postgres", (self.out / "sizes.txt").read_text())

    # -----------------------------------------------------------------------------------------------------------------
    # The room on the disks (docs/features/demo-data/spec.md, W2): before the cluster is made and while it runs
    # -----------------------------------------------------------------------------------------------------------------
    def run_space(self, profile="medium", **env):
        """A run of a generated profile whose Job of the generator never ends (STUB_DATA_JOB is empty): the guard ends it."""
        env = {"STUB_DATA_JOB": "", "LOAD_DATA_TIMEOUT": "60", "LOAD_SPACE_POLL": "1", **env}
        return self.run_data(profile, **env)

    def host_calls(self):
        path = self.space / "host.calls"
        return path.read_text().splitlines() if path.exists() else []

    def df_calls(self):
        return [call for call in self.calls() if "--entrypoint df" in call] + self.host_calls()

    def assert_refused_before_the_cluster(self, result, *words):
        self.assertEqual(2, result.returncode, result.stderr)
        for word in words:
            self.assertIn(word, result.stderr)
        # Nothing but the reading of the Docker VM and of its space: no cluster, no image built.
        self.assertEqual([], [call for call in self.calls() if not (call.startswith("info ") or "--entrypoint df" in call)], self.calls())

    def test_a_vm_or_a_host_with_less_than_the_peak_and_a_quarter_is_refused_before_anything_is_made(self):
        for profile in ("medium", "server", "full"):
            required = 1.25 * disk_peak_gb(profile)
            for disk in ("VM", "HOST"):
                for free_gb in (required - 1, 0.5):
                    with self.subTest(profile=profile, disk=disk, free_gb=free_gb):
                        self.log.write_text("")
                        (self.space / "host.calls").unlink(missing_ok=True)
                        result = self.run_data(profile, LOAD_BUILD="1", **{f"STUB_{disk}_FREE_KB": str(kib(free_gb))})
                        self.assert_refused_before_the_cluster(result, f"LOAD_DATA={profile}", f"{required:.2f} GB", f"{free_gb:.2f} GB")

    def test_the_message_of_the_refusal_names_the_required_and_the_available_space_of_both_disks(self):
        required = 1.25 * disk_peak_gb("medium")
        vm_free = required - 1
        result = self.run_data("medium", STUB_VM_FREE_KB=str(kib(vm_free)), STUB_HOST_FREE_KB=str(kib(400)))
        self.assert_refused_before_the_cluster(result, f"{required:.2f} GB", f"{vm_free:.2f} GB", "400.00 GB", "Docker VM", "host")
        self.assertIn("1.25", result.stderr)
        self.assertIn(self.tmp.name, result.stderr)

    def test_exactly_the_peak_and_a_quarter_starts_and_a_kib_less_does_not(self):
        for profile in ("medium", "server", "full"):
            required_kib = kib(1.25 * disk_peak_gb(profile))
            for disk in ("VM", "HOST"):
                with self.subTest(profile=profile, disk=disk, boundary="exact"):
                    self.log.write_text("")
                    result = self.run_data(profile, LOAD_TIMEOUT="2", STUB_READY="", **{f"STUB_{disk}_FREE_KB": str(required_kib)})
                    self.assertNotIn("free", result.stderr)
                    self.assertIn("not ready", result.stderr)
                    self.assertEqual(1, len(self.cluster_calls("create")))
                with self.subTest(profile=profile, disk=disk, boundary="one KiB less"):
                    self.log.write_text("")
                    result = self.run_data(profile, LOAD_TIMEOUT="2", STUB_READY="", **{f"STUB_{disk}_FREE_KB": str(required_kib - 1)})
                    self.assert_refused_before_the_cluster(result, f"LOAD_DATA={profile}")

    def test_the_host_is_read_on_the_path_of_the_option_and_by_default_in_the_home_directory(self):
        result = self.run_data("medium", STUB_READY="", LOAD_TIMEOUT="2")
        self.assertIn("not ready", result.stderr)
        self.assertEqual(f"-Pk {self.tmp.name}", self.host_calls()[0])
        other = pathlib.Path(self.tmp.name) / "disk"
        other.mkdir()
        (self.space / "host.calls").unlink()
        result = self.run_data("medium", STUB_READY="", LOAD_TIMEOUT="2", LOAD_DISK_PATH=str(other))
        self.assertIn("not ready", result.stderr)
        self.assertEqual(f"-Pk {other}", self.host_calls()[0])
        for value in (str(other / "missing"), str(pathlib.Path(self.tmp.name) / "docker.log"), "relative/dir"):
            with self.subTest(path=value):
                self.log.write_text("")
                result = self.run_data("medium", LOAD_DISK_PATH=value)
                self.assert_no_docker_call(result, "LOAD_DISK_PATH")

    def test_a_vm_or_a_host_that_cannot_be_read_ends_with_2_before_anything_is_made(self):
        for disk, word, other in (("VM", "the Docker VM", "the disk of the host"), ("HOST", "the disk of the host", "the Docker VM")):
            for answer in ("fail", "none", "many", "12.5", "-4", "1e9"):
                with self.subTest(disk=disk, answer=answer):
                    self.log.write_text("")
                    result = self.run_data("medium", **{f"STUB_{disk}_FREE_KB": answer})
                    self.assert_refused_before_the_cluster(result, "cannot read", word)
                    self.assertNotIn(other, result.stderr)

    def test_a_peak_that_cannot_be_read_from_the_profiles_ends_with_2_before_any_docker_call(self):
        cases = {
            "no file": None,
            "no such profile": "profiles:\n  small:\n    diskPeakGb: 5\n",
            "no peak": "profiles:\n  medium:\n    staff: 50\n",
            "text": "profiles:\n  medium:\n    diskPeakGb: many\n",
            "with a unit": "profiles:\n  medium:\n    diskPeakGb: 25GB\n",
            "zero": "profiles:\n  medium:\n    diskPeakGb: 0\n",
            "negative": "profiles:\n  medium:\n    diskPeakGb: -25\n",
            "empty": "profiles:\n  medium:\n    diskPeakGb:\n",
            "the peak of the next profile": "profiles:\n  medium:\n    staff: 50\n  server:\n    diskPeakGb: 100\n",
        }
        for name, text in cases.items():
            with self.subTest(case=name):
                self.log.write_text("")
                path = pathlib.Path(self.tmp.name) / "profiles.yaml"
                path.unlink(missing_ok=True)
                if text is not None:
                    path.write_text(text)
                result = self.run_data("medium", LOAD_PROFILES_FILE=str(path))
                self.assert_no_docker_call(result, "diskPeakGb", "medium")

    def test_the_peak_of_the_profile_that_is_run_is_the_one_that_counts(self):
        path = pathlib.Path(self.tmp.name) / "profiles.yaml"
        path.write_text("profiles:\n  medium:\n    diskPeakGb: 10 # an estimate\n  server:\n    diskPeakGb: 12.4\n")
        for profile, required in (("medium", "12.50 GB"), ("server", "15.50 GB")):
            with self.subTest(profile=profile):
                self.log.write_text("")
                result = self.run_data(profile, LOAD_PROFILES_FILE=str(path), STUB_VM_FREE_KB="1000")
                self.assert_refused_before_the_cluster(result, required)

    def drop(self, disk, value):
        """STUB_SPACE_DROP of the free space of a disk (vm or host) to value from the first poll of the Job on."""
        for name in ("vm", "host", "k6.pid"):
            (self.space / name).unlink(missing_ok=True)
        self.log.write_text("")
        return f"job:{disk}:{value}"

    def test_a_fall_of_the_space_while_the_generator_runs_stops_the_job_and_deletes_the_cluster(self):
        peak = disk_peak_gb("medium")
        for disk, word, other in (("vm", "the Docker VM", "the disk of the host"), ("host", "the disk of the host", "the Docker VM")):
            with self.subTest(disk=disk):
                result = self.run_space("medium", STUB_SPACE_DROP=self.drop(disk, kib(peak * 0.1) - 1))
                self.assertEqual(2, result.returncode, result.stderr)
                for text in ("10%", f"{peak * 0.1:.2f} GB", word):
                    self.assertIn(text, result.stderr)
                self.assertNotIn(other, result.stderr)
                self.assertEqual(1, len(self.cluster_calls("create")))
                self.assertEqual([], self.k6_calls())
                self.assertEqual([], self.update_calls())
                self.assertEqual(1, len([call for call in self.calls() if "delete job demo-data-load" in call]), self.calls())
                # The Job is stopped before the cluster is deleted, and the cluster is deleted last of all.
                last_delete = max(i for i, call in enumerate(self.calls()) if "k3d-io/k3d" in call and "cluster delete" in call)
                self.assertLess(self.index_of("delete job demo-data-load"), last_delete)
                self.assertIn("cluster delete", [call for call in self.calls() if "k3d-io/k3d" in call][-1])
                self.assertIn("ld-loadtest", self.cluster_calls("delete")[-1])

    def test_a_fall_of_the_space_while_k6_runs_stops_k6_and_deletes_the_cluster(self):
        peak = disk_peak_gb("medium")
        for disk, word, other in (("vm", "the Docker VM", "the disk of the host"), ("host", "the disk of the host", "the Docker VM")):
            with self.subTest(disk=disk):
                self.drop(disk, 0)
                result = self.run_data("medium", LOAD_SPACE_POLL="1", STUB_K6_SLEEP="120", STUB_SPACE_DROP=f"k6:{disk}:0")
                self.assertEqual(2, result.returncode, result.stderr)
                for text in ("10%", f"{peak * 0.1:.2f} GB", word):
                    self.assertIn(text, result.stderr)
                self.assertNotIn(other, result.stderr)
                self.assertEqual(1, len(self.k6_calls()))
                self.assertEqual(1, len([call for call in self.calls() if "rm -f k6-ld-loadtest" in call]), self.calls())
                self.assertFalse(process_alive(int((self.space / "k6.pid").read_text())), "k6 still runs")
                self.assertIn("cluster delete", [call for call in self.calls() if "k3d-io/k3d" in call][-1])
                self.assertEqual(1, len(self.cluster_calls("create")))

    def test_the_space_at_the_threshold_does_not_stop_the_run_and_a_kib_below_it_does(self):
        threshold_kib = kib(disk_peak_gb("medium") * 0.1)
        self.drop("vm", 0)
        result = self.run_space("medium", LOAD_DATA_TIMEOUT="3", STUB_SPACE_DROP=f"job:vm:{threshold_kib}")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("did not finish", result.stderr)
        self.assertNotIn("10%", result.stderr)
        self.drop("vm", 0)
        result = self.run_space("medium", LOAD_DATA_TIMEOUT="30", STUB_SPACE_DROP=f"job:vm:{threshold_kib - 1}")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("10%", result.stderr)
        self.assertNotIn("did not finish", result.stderr)

    def test_a_reading_of_the_space_that_fails_while_the_cluster_runs_ends_with_2(self):
        for disk, word, other in (("vm", "the Docker VM", "the disk of the host"), ("host", "the disk of the host", "the Docker VM")):
            for answer in ("fail", "none", "many"):
                with self.subTest(disk=disk, answer=answer):
                    result = self.run_space("medium", STUB_SPACE_DROP=self.drop(disk, answer))
                    self.assertEqual(2, result.returncode, result.stderr)
                    self.assertIn("cannot read", result.stderr)
                    self.assertIn(word, result.stderr)
                    self.assertNotIn(other, result.stderr)
                    self.assertNotIn("did not finish", result.stderr)
                    self.assertEqual(1, len(self.cluster_calls("create")))
                    self.assertEqual([], self.k6_calls())
                    self.assertIn("cluster delete", [call for call in self.calls() if "k3d-io/k3d" in call][-1])

    def test_the_guard_checks_the_space_again_and_again_and_ends_with_the_run(self):
        """A run that ends by itself leaves no guard behind (the run returns only when every holder of its output is gone)."""
        self.drop("vm", 0)
        result = self.run_space("medium", LOAD_DATA_TIMEOUT="4")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertGreaterEqual(len([call for call in self.calls() if "--entrypoint df" in call]), 3)
        self.assertGreaterEqual(len(self.host_calls()), 3)
        self.log.write_text("")
        result = self.run_data("medium", LOAD_SPACE_POLL="1")
        self.assertEqual(0, result.returncode, result.stderr)

    def test_seed_checks_no_space(self):
        for env in ({}, {"LOAD_DATA": "seed"}):
            with self.subTest(env=env):
                self.log.write_text("")
                (self.space / "host.calls").unlink(missing_ok=True)
                result = self.run_load(LOAD_OUT=str(self.out), LOAD_BUILD="0", LOAD_COOLDOWN="0", STUB_READY="1", STUB_UPDATE_OUT="UPDATE 7",
                                       STUB_K6_OUT=str(self.out), STUB_VM_FREE_KB="1", STUB_HOST_FREE_KB="fail", LOAD_SPACE_POLL="1",
                                       LOAD_PROFILES_FILE="/missing/profiles.yaml", **env)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual([], self.df_calls())

    def test_a_wrong_interval_of_the_check_ends_with_2_before_any_docker_call(self):
        for value in ("x", "-1", "0", "1.5"):
            with self.subTest(value=value):
                self.log.write_text("")
                result = self.run_data("medium", LOAD_SPACE_POLL=value)
                self.assert_no_docker_call(result, "LOAD_SPACE_POLL")

    # -----------------------------------------------------------------------------------------------------------------
    # The samplers cover the generator, and space.log records the free space (docs/features/demo-data/spec.md, criterion 12)
    # -----------------------------------------------------------------------------------------------------------------
    def test_the_samplers_start_before_the_generator_job_so_that_top_log_and_node_stats_cover_it(self):
        result = self.run_data("medium")
        self.assertEqual(0, result.returncode, result.stderr)
        job = self.index_of("create job demo-data-load")
        self.assertLess(self.index_of("top pods"), job)
        self.assertLess(self.index_of("stats --no-stream"), job)
        # The samplers start once the cluster is ready, not before.
        self.assertLess(self.index_of("get statefulsets"), self.index_of("top pods"))
        self.assertTrue((self.out / "top.log").exists())
        self.assertTrue((self.out / "node-stats.log").exists())

    def test_the_samplers_of_seed_start_as_soon_as_the_cluster_is_ready(self):
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_BUILD="0", LOAD_COOLDOWN="0", STUB_READY="1", STUB_UPDATE_OUT="UPDATE 7",
                               STUB_K6_OUT=str(self.out))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertLess(self.index_of("top pods"), self.index_of("UPDATE "))
        self.assertLess(self.index_of("stats --no-stream"), self.index_of("UPDATE "))

    def space_rows(self):
        """The readings of space.log: (epoch, vm KiB, host KiB), the comment lines apart."""
        lines = (self.out / "space.log").read_text().splitlines()
        rows = [tuple(int(word) for word in line.split()) for line in lines if line.strip() and not line.startswith("#")]
        return rows, [line for line in lines if line.startswith("#")]

    def test_space_log_has_the_reading_before_the_start_the_readings_of_the_guard_and_the_summary(self):
        vm = [900_000_000, 880_000_000, 860_000_000]
        host = [950_000_000, 940_000_000, 600_000_000]
        result = self.run_space("medium", LOAD_DATA_TIMEOUT="6", STUB_VM_FREE_SEQ=" ".join(map(str, vm)),
                                STUB_HOST_FREE_SEQ=" ".join(map(str, host)))
        # The run did not finish (the Job never ends): the summary is there all the same.
        self.assertEqual(2, result.returncode, result.stderr)
        rows, comments = self.space_rows()
        self.assertGreaterEqual(len(rows), 4, rows)
        # The first row is the reading before the start, then the ones of the guard, which fall as the stub does.
        self.assertEqual((vm[0], host[0]), rows[0][1:])
        self.assertEqual((vm[1], host[1]), rows[1][1:])
        self.assertEqual((vm[2], host[2]), rows[2][1:])
        self.assertEqual(sorted(row[0] for row in rows), [row[0] for row in rows])
        self.assertGreater(rows[-1][0], 1_700_000_000)
        summary = " ".join(comments[-2:])
        # The least free space and the use against the first reading, in GB of 10^9 bytes.
        for text in (f"{vm[2] * 1024 / 1e9:.2f}", f"{host[2] * 1024 / 1e9:.2f}",
                     f"{(vm[0] - vm[2]) * 1024 / 1e9:.2f}", f"{(host[0] - host[2]) * 1024 / 1e9:.2f}"):
            self.assertIn(text, summary)
        self.assertIn("VM", summary)
        self.assertIn("host", summary)

    def test_the_summary_of_space_log_is_the_last_thing_in_it_and_comes_once(self):
        result = self.run_data("medium", LOAD_SPACE_POLL="1", STUB_VM_FREE_SEQ="900000000 899000000", STUB_HOST_FREE_SEQ="950000000")
        self.assertEqual(0, result.returncode, result.stderr)
        text = (self.out / "space.log").read_text()
        rows, comments = self.space_rows()
        self.assertEqual((900_000_000, 950_000_000), rows[0][1:])
        self.assertEqual(1, len(re.findall(r"(?m)^# .*least", text)), text)
        self.assertFalse(text.splitlines()[-1][0].isdigit(), "a reading follows the summary")

    def test_space_log_after_a_fall_of_the_space_has_the_fallen_value_as_the_least(self):
        peak = disk_peak_gb("medium")
        low = kib(peak * 0.1) - 1
        result = self.run_space("medium", STUB_SPACE_DROP=self.drop("vm", low))
        self.assertEqual(2, result.returncode, result.stderr)
        rows, comments = self.space_rows()
        self.assertEqual(900_000_000, rows[0][1])
        self.assertEqual(low, rows[-1][1])
        self.assertIn(f"{low * 1024 / 1e9:.2f}", " ".join(comments))

    def test_a_space_log_that_cannot_be_written_is_a_warning_and_the_run_goes_on(self):
        (self.out / "space.log").mkdir()
        result = self.run_data("medium", LOAD_SPACE_POLL="1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("space.log", result.stderr)

    def test_seed_writes_no_space_log_and_leaves_an_old_one_alone(self):
        (self.out / "space.log").write_text("old\n")
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_BUILD="0", LOAD_COOLDOWN="0", STUB_READY="1", STUB_UPDATE_OUT="UPDATE 7",
                               STUB_K6_OUT=str(self.out))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("old\n", (self.out / "space.log").read_text())
        self.out.joinpath("space.log").unlink()
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_BUILD="0", LOAD_COOLDOWN="0", STUB_READY="1", STUB_UPDATE_OUT="UPDATE 7",
                               STUB_K6_OUT=str(self.out))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertFalse((self.out / "space.log").exists())

    def test_a_new_run_starts_a_new_space_log(self):
        (self.out / "space.log").write_text("1 111 222\n# old summary\n")
        result = self.run_data("medium", LOAD_SPACE_POLL="1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn("old summary", (self.out / "space.log").read_text())
        self.assertEqual((900_000_000, 900_000_000), self.space_rows()[0][0][1:])


    # -----------------------------------------------------------------------------------------------------------------
    # A host that slept: the times of the run are not reliable (docs/features/demo-data/spec.md, W2, space.log)
    # -----------------------------------------------------------------------------------------------------------------
    def sleep_run(self, skew, profile="medium", **env):
        """A run of a generated profile whose clock jumps by `skew` seconds at the first poll of the Job (the host slept)."""
        env = {"LOAD_SPACE_POLL": "1", "STUB_DATA_JOB_AFTER": "4", "STUB_SPACE_DROP": f"job:skew:{skew}", **env}
        return self.run_data(profile, **env)

    def pause_line(self):
        """The `#` line of space.log about the pauses: (the number, the limit in seconds, the total in seconds), or None."""
        match = re.search(r"(?m)^# .*slept.* (\d+) pause.* more than (\d+) s.* (\d+) s in total", (self.out / "space.log").read_text())
        return tuple(int(group) for group in match.groups()) if match else None

    def test_a_pause_of_the_host_is_written_in_space_log_warned_about_and_the_run_does_not_pass(self):
        result = self.sleep_run(600)
        # The measurement is there, but its times are not: not the exit 0 of a run that was done.
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertTrue((self.out / "steady.json").exists())
        count, limit, total = self.pause_line()
        self.assertEqual((1, 120), (count, limit))
        self.assertTrue(600 <= total <= 660, total)
        for text in ("slept", "not reliable", f"{total} s"):
            self.assertIn(text, result.stderr)
        environment = (self.out / "environment.txt").read_text()
        self.assertRegex(environment, r"(?m)^host sleep: .*slept.*not reliable")
        self.assertIn(f"{total} s", environment)
        # The summary of the space stays the last lines of the file.
        self.assertRegex((self.out / "space.log").read_text().splitlines()[-1], r"^# the peak use")

    def test_a_pause_that_is_not_longer_than_the_limit_is_not_a_sleep(self):
        # 60 s, well below the limit of 120 s: the real time that a loaded machine adds to the skew of the stub does not make it a sleep.
        result = self.sleep_run(60)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIsNone(self.pause_line())
        self.assertNotIn("slept", result.stderr)
        self.assertNotIn("slept", (self.out / "space.log").read_text())
        self.assertRegex((self.out / "environment.txt").read_text(), r"(?m)^host sleep: none .*120 s")

    def test_the_limit_of_a_pause_is_three_intervals_of_the_check_and_at_least_120_seconds(self):
        # LOAD_SPACE_POLL=100: the limit is 300 s, so 140 s is no sleep (it is above the 120 s of the default limit, so only the
        # three intervals keep it from being one) and 600 s is. The margins are wide, as the stub adds the real time of a loaded
        # machine to its skew.
        result = self.sleep_run(140, LOAD_SPACE_POLL="100")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertRegex((self.out / "environment.txt").read_text(), r"(?m)^host sleep: none .*300 s")
        (self.space / "skew").unlink(missing_ok=True)
        (self.space / "job.polls").unlink(missing_ok=True)
        result = self.sleep_run(600, LOAD_SPACE_POLL="100")
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertEqual(300, self.pause_line()[1])

    def test_a_pause_is_found_in_a_run_of_seed_too_from_the_samplers(self):
        """seed has no guard and no space.log: the readings of the samplers (every 10 s) and the end of the run tell."""
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_BUILD="0", LOAD_COOLDOWN="0", STUB_READY="1", STUB_UPDATE_OUT="UPDATE 7",
                               STUB_K6_OUT=str(self.out), STUB_SPACE_DROP="k6:skew:600")
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn("slept", result.stderr)
        self.assertFalse((self.out / "space.log").exists())
        self.assertRegex((self.out / "environment.txt").read_text(), r"(?m)^host sleep: .*slept.*not reliable")

    def test_a_run_that_failed_keeps_its_exit_code_and_still_names_the_pause(self):
        result = self.run_space("medium", LOAD_DATA_TIMEOUT="60", STUB_SPACE_DROP="job:skew:600")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("did not finish", result.stderr)
        self.assertIn("slept", result.stderr)
        self.assertEqual(1, self.pause_line()[0])

    def test_the_old_readings_of_an_earlier_run_are_not_a_pause(self):
        """node-stats.log of an earlier run stays in LOAD_OUT until the samplers start: a run that ends before is no sleep."""
        (self.out / "node-stats.log").write_text("1000000000 1.00% 100MiB / 1GiB\n")
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", STUB_READY="")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("not ready", result.stderr)
        self.assertNotIn("slept", result.stderr)

    # -----------------------------------------------------------------------------------------------------------------
    # The lock of the sleep of the host (caffeinate on macOS)
    # -----------------------------------------------------------------------------------------------------------------
    def install_caffeinate(self):
        (self.bin / "caffeinate").write_text(CAFFEINATE)
        (self.bin / "caffeinate").chmod(0o755)

    def test_on_macos_caffeinate_holds_the_host_awake_for_the_whole_run_and_is_stopped_with_it(self):
        self.install_caffeinate()
        for data in ("seed", "medium"):
            with self.subTest(data=data):
                for name in ("caffeinate.calls", "caffeinate.waited", "caffeinate.pid"):
                    (self.space / name).unlink(missing_ok=True)
                if data == "seed":
                    result = self.run_load(LOAD_OUT=str(self.out), LOAD_BUILD="0", LOAD_COOLDOWN="0", STUB_READY="1", STUB_UPDATE_OUT="UPDATE 7",
                                           STUB_K6_OUT=str(self.out))
                else:
                    result = self.run_data(data)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertTrue((self.space / "caffeinate.calls").exists(), "caffeinate was not started")
                words = (self.space / "caffeinate.calls").read_text().split()
                # Idle, disk and system sleep, for as long as the shell of the script lives.
                self.assertEqual(["-ims", "-w"], words[:2])
                self.assertRegex(words[2], r"^[0-9]+$")
                self.assertEqual(words[2], (self.space / "caffeinate.waited").read_text().strip(), "the process it waits for did not exist")
                self.assertFalse(process_alive(int((self.space / "caffeinate.pid").read_text())), "caffeinate still runs")
                environment = (self.out / "environment.txt").read_text()
                # What it holds off, and what it does not: not an unconditional promise that the host stays awake.
                self.assertRegex(environment, r"(?m)^sleep lock: caffeinate -ims -w [0-9]+ \(holds off idle sleep, and system sleep "
                                              r"on the mains only; not a closed lid or a sleep by hand\)$")
                self.assertNotIn("does not sleep", environment)

    def test_caffeinate_is_stopped_when_the_run_fails_too(self):
        self.install_caffeinate()
        result = self.run_load(LOAD_OUT=str(self.out), LOAD_TIMEOUT="2", STUB_READY="")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("not ready", result.stderr)
        self.assertTrue((self.space / "caffeinate.calls").exists(), "caffeinate was not started")
        self.assertEqual(1, len((self.space / "caffeinate.calls").read_text().splitlines()))
        self.assertFalse(process_alive(int((self.space / "caffeinate.pid").read_text())), "caffeinate still runs")

    def test_without_caffeinate_the_run_goes_on_without_an_error_and_environment_says_so(self):
        if shutil.which("caffeinate", path=f"{self.bin}:{os.environ['PATH']}"):
            self.skipTest("this host has caffeinate")
        result = self.run_data("medium")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn("caffeinate", result.stderr)
        self.assertRegex((self.out / "environment.txt").read_text(),
                         r"(?m)^sleep lock: none \(no caffeinate on this host; nothing stops it from sleeping\)$")

    def test_a_caffeinate_that_does_not_start_is_a_warning_and_the_run_goes_on(self):
        self.install_caffeinate()
        result = self.run_data("medium", STUB_CAFFEINATE_FAIL="1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("caffeinate", result.stderr)
        self.assertRegex((self.out / "environment.txt").read_text(), r"(?m)^sleep lock: none \(caffeinate did not start")


if __name__ == "__main__":
    unittest.main()

"""The script of the pod of the restore (files/scripts/restore-job.sh, docs/features/deploy-ops/spec.md, O4) against stubs of
restic, psql, pg_restore, rclone, redis-cli, rabbitmqadmin and bin/console: what each stage does in each mode (list, check,
restore, reindex), that a snapshot with a migration the deployed code does not know is refused before anything is restored, and
that a failing tool ends the stage with an error."""

import json
import os
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/charts/shop/files/scripts/restore-job.sh"
SNAPSHOT = "9e050a3d569735e3309e6c5d015177af63957052017b5f84d47988b674808f68"
OTHER = "4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a"
HOST = "0b6f1d9e-4c3a-4f5b-8e7d-2a9c1b3d5e7f"
OLD_HOST = "7c1d2e3f-0a9b-4c8d-9e7f-6a5b4c3d2e1f"
REVISION = "0123456789abcdef0123456789abcdef01234567"
KNOWN = ["Version20261029100747CreateCatalogCategory", "Version20261029101125CreateCatalogProduct"]
SECRETS = {
    "PGPASSWORD": "sentinel-postgres-password",
    "RESTIC_PASSWORD": "sentinel-restic-password",
    "AWS_SECRET_ACCESS_KEY": "sentinel-s3-secret-key",
    "RCLONE_CONFIG_GARAGE_SECRET_ACCESS_KEY": "sentinel-garage-secret-key",
    "RABBITMQADMIN_PASSWORD": "sentinel-rabbitmq-password",
}


def manifest(migrations, revision=REVISION, count=2, bytes_=18):
    versions = ",".join(json.dumps("DoctrineMigrations\\" + m) for m in migrations)
    return "\n".join([
        "{",
        f'  "revision": "{revision}",',
        '  "time": "2026-11-11T02:00:05Z",',
        f'  "cluster": "{HOST}",',
        '  "databases": ["shop","keycloak"],',
        f'  "migrations": [{versions}],',
        f'  "objects": {{"count": {count}, "bytes": {bytes_}}}',
        "}",
    ]) + "\n"


def snapshot_json(id_, time, host, tags):
    return {"time": time, "tree": "ab" * 32, "paths": ["/work/dumps", "/work/manifest.json", "/work/objects"], "hostname": host,
            "username": "root", "tags": tags, "program_version": "restic 0.19.1",
            "summary": {"backup_start": time, "files_new": 3}, "id": id_, "short_id": id_[:8]}


# The stubs log one line per call ("<tool> <arguments>") in $STUB_LOG. A tool named in STUB_FAIL (a space separated list of
# `tool` or `tool:<first argument>`) fails with a message and no output.
STUB_COMMON = r"""#!/bin/sh
tool="${0##*/}"
echo "$tool $*" >> "$STUB_LOG"
fails() {
  for item in ${STUB_FAIL:-}; do
    [ "$item" = "$tool" ] && return 0
    [ "$item" = "$tool:$1" ] && return 0
  done
  return 1
}
if fails "$1"; then echo "$tool: failing on purpose" >&2; exit 1; fi
"""

RESTIC = STUB_COMMON + r"""
case "$1" in
  snapshots)
    id=""
    for arg in "$@"; do case "$arg" in -*|snapshots) ;; *) id="$arg" ;; esac; done
    if [ -n "$id" ]; then cat "$STUB_DIR/snapshots-$id.json" 2>/dev/null || echo "[]"; else cat "$STUB_DIR/snapshots.json"; fi ;;
  dump)
    for arg in "$@"; do case "$arg" in -*|dump|/work/manifest.json) ;; *) id="$arg" ;; esac; done
    cat "$STUB_DIR/manifest-$id.json" 2>/dev/null || { echo "Fatal: no manifest in $id" >&2; exit 1; } ;;
  restore)
    for arg in "$@"; do [ "$prev" = "--target" ] && target="$arg"; prev="$arg"; done
    for arg in "$@"; do case "$arg" in *:/work) id="${arg%:/work}" ;; esac; done
    rm -rf "$target"/* "$target"/.[!.]*
    cp -R "$STUB_DIR/tree-$id/." "$target/" ;;
esac
"""

PSQL = STUB_COMMON + r"""
:
"""

PG_RESTORE = STUB_COMMON + r"""
:
"""

RCLONE = STUB_COMMON + r"""
:
"""

REDIS_CLI = STUB_COMMON + r"""
echo "${STUB_REDIS_REPLY:-OK}"
"""

RABBITMQADMIN = STUB_COMMON + r"""
case "$*" in
  *"list queues"*) printf '%s' "${STUB_QUEUES:-}" ;;
esac
"""

CONSOLE = STUB_COMMON + r"""
:
"""

TOOLS = {"restic": RESTIC, "psql": PSQL, "pg_restore": PG_RESTORE, "rclone": RCLONE, "redis-cli": REDIS_CLI,
         "rabbitmqadmin": RABBITMQADMIN, "console": CONSOLE}
QUEUES = (
    'events  /  classic  true  false  false  x-queue-type: "classic"  rabbit@rabbitmq-0  running        3  0  0      0  0  0 \n'
    'outbox  /  classic  true  false  false  x-queue-type: "classic"  rabbit@rabbitmq-0  running        0  0  0      0  0  0 \n'
)


class RestoreJobScriptTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.tmp.name)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        for tool, body in TOOLS.items():
            (self.bin / tool).write_text(body)
            (self.bin / tool).chmod(0o755)
        self.stub = self.root / "stub"
        self.stub.mkdir()
        self.log = self.stub / "log"
        self.log.write_text("")
        self.work = self.root / "work"
        self.work.mkdir()
        self.meta = self.root / "meta"
        self.meta.mkdir()
        self.migrations = self.root / "migrations"
        self.migrations.mkdir()
        for migration in KNOWN:
            (self.migrations / f"{migration}.php").write_text("<?php\n")
        (self.migrations / ".gitignore").write_text("")
        snapshots = [snapshot_json(OTHER, "2026-11-04T02:00:07.1234+00:00", OLD_HOST, ["nightly"]),
                     snapshot_json(SNAPSHOT, "2026-11-11T03:00:05.219520919+01:00", HOST, ["pre-restore"])]
        (self.stub / "snapshots.json").write_text(json.dumps(snapshots, separators=(",", ":")))
        for snapshot in snapshots:
            (self.stub / f"snapshots-{snapshot['id']}.json").write_text(json.dumps([snapshot], separators=(",", ":")))
            (self.stub / f"snapshots-{snapshot['short_id']}.json").write_text(json.dumps([snapshot], separators=(",", ":")))
        self.write_snapshot(SNAPSHOT, KNOWN)
        self.write_snapshot(OTHER, KNOWN[:1], revision="89abcdef0123456789abcdef0123456789abcdef")

    def tearDown(self):
        self.tmp.cleanup()

    def write_snapshot(self, id_, migrations, revision=REVISION):
        text = manifest(migrations, revision)
        for key in (id_, id_[:8]):
            (self.stub / f"manifest-{key}.json").write_text(text)
        tree = self.stub / f"tree-{id_}"
        (tree / "dumps").mkdir(parents=True, exist_ok=True)
        (tree / "objects/images").mkdir(parents=True, exist_ok=True)
        for database in ("shop", "keycloak"):
            (tree / "dumps" / f"{database}.dump").write_text(f"dump of {database}\n")
        (tree / "objects/images/1.webp").write_text("image\n")
        (tree / "manifest.json").write_text(text)

    def run_stage(self, stage, mode, snapshot=SNAPSHOT, **env):
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": self.tmp.name,
            "WORK_DIR": str(self.work),
            "META_DIR": str(self.meta),
            "MIGRATIONS_DIR": str(self.migrations),
            "CONSOLE": str(self.bin / "console"),
            "REDIS_HOST": "redis",
            "RESTORE_MODE": mode,
            "RESTORE_SNAPSHOT": snapshot,
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.stub),
            "STUB_QUEUES": QUEUES,
            **SECRETS,
            **env,
        }
        return subprocess.run(["sh", str(SCRIPT), stage], env=variables, capture_output=True, text=True)

    def run_pod(self, mode, snapshot=SNAPSHOT, **env):
        """The stages one after another, as the pod runs them; stops at the first that fails. Returns the results."""
        results = []
        for stage in ("migrations", "restic", "databases", "objects", "redis", "rabbitmq", "api"):
            result = self.run_stage(stage, mode, snapshot, **env)
            results.append((stage, result))
            if result.returncode != 0:
                break
        return results

    def calls(self, tool=None):
        lines = self.log.read_text().splitlines()
        return [line for line in lines if tool is None or line.split(" ", 1)[0] == tool]

    def output(self, results):
        return "".join(result.stdout + result.stderr for _, result in results)

    # The modes and the snapshot

    def test_an_unknown_mode_fails_every_stage_without_a_call(self):
        for stage in ("migrations", "restic", "databases", "objects", "redis", "rabbitmq", "api"):
            result = self.run_stage(stage, "everything")
            self.assertNotEqual(0, result.returncode, stage)
            self.assertIn("RESTORE_MODE", result.stderr, stage)
        self.assertEqual([], self.calls())

    def test_check_and_restore_need_the_identifier_of_a_snapshot(self):
        for mode in ("check", "restore"):
            for snapshot in ("", "latest", "9e05", "9e050a3d;rm", "9E050A3D", "--delete"):
                result = self.run_stage("restic", mode, snapshot)
                self.assertNotEqual(0, result.returncode, (mode, snapshot))
                self.assertIn("RESTORE_SNAPSHOT", result.stderr, (mode, snapshot))
        self.assertEqual([], self.calls())

    # list

    def test_list_prints_every_snapshot_with_its_identifier_time_host_tag_and_revision(self):
        results = self.run_pod("list", snapshot="")
        self.assertTrue(all(result.returncode == 0 for _, result in results), self.output(results))
        lines = [line for line in self.output(results).splitlines() if line.startswith(("9e050a3d", "4f3a2b1c"))]
        self.assertEqual(2, len(lines), self.output(results))
        old, new = lines
        for part in ("4f3a2b1c", "2026-11-04T02:00:07+00:00", OLD_HOST, "nightly", "89abcdef0123456789abcdef0123456789abcdef"):
            self.assertIn(part, old.split())
        for part in ("9e050a3d", "2026-11-11T03:00:05+01:00", HOST, "pre-restore", REVISION):
            self.assertIn(part, new.split())

    def test_list_only_reads_the_repository_without_a_lock_and_touches_nothing_else(self):
        self.run_pod("list", snapshot="")
        self.assertEqual({"snapshots", "dump"}, {call.split()[1] for call in self.calls("restic")})
        for call in self.calls("restic"):
            self.assertIn("--no-lock", call.split())
        self.assertEqual([], [call for call in self.calls() if not call.startswith("restic ")])
        self.assertEqual([], list(self.work.iterdir()))

    def test_list_fails_when_the_repository_is_not_readable(self):
        result = self.run_stage("restic", "list", "", STUB_FAIL="restic:snapshots")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("cannot list", result.stderr)

    # check

    def test_check_describes_the_snapshot_and_accepts_known_migrations(self):
        results = self.run_pod("check")
        self.assertTrue(all(result.returncode == 0 for _, result in results), self.output(results))
        output = self.output(results)
        for part in ("9e050a3d", "2026-11-11T03:00:05+01:00", HOST, "pre-restore", REVISION, "2 migrations", "2 objects", "18 bytes"):
            self.assertIn(part, output)
        self.assertIn("restore: the snapshot can be restored", output)
        self.assertEqual({"snapshots", "dump"}, {call.split()[1] for call in self.calls("restic")})
        self.assertEqual([], [call for call in self.calls() if not call.startswith("restic ")])

    def test_the_migrations_of_the_image_are_the_classes_of_its_migration_directory(self):
        result = self.run_stage("migrations", "check")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(sorted("DoctrineMigrations\\" + m for m in KNOWN), (self.meta / "migrations").read_text().splitlines())

    def test_an_image_without_migrations_fails(self):
        for path in self.migrations.iterdir():
            path.unlink()
        result = self.run_stage("migrations", "check")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("no migrations", result.stderr)

    def test_a_snapshot_with_a_migration_the_code_does_not_know_is_refused(self):
        self.write_snapshot(SNAPSHOT, KNOWN + ["Version29990101000000AddSomething"])
        for mode in ("check", "restore"):
            results = self.run_pod(mode)
            stage, result = results[-1]
            self.assertEqual("restic", stage, mode)
            self.assertNotEqual(0, result.returncode, mode)
            self.assertIn("DoctrineMigrations\\Version29990101000000AddSomething", result.stderr, mode)
            self.assertIn("newer than the deployed code", result.stderr, mode)
            self.assertEqual([], [call for call in self.calls("restic") if call.split()[1] == "restore"], mode)
        self.assertEqual([], list(self.work.iterdir()))

    def test_a_snapshot_that_is_not_in_the_repository_is_refused(self):
        result = self.run_pod("check", snapshot="0000000000000000")[-1][1]
        self.assertNotEqual(0, result.returncode)
        self.assertIn("no snapshot 0000000000000000", result.stderr)

    def test_a_snapshot_without_a_readable_manifest_is_refused(self):
        result = self.run_pod("check", STUB_FAIL="restic:dump")[-1][1]
        self.assertNotEqual(0, result.returncode)
        self.assertIn("manifest.json", result.stderr)

    def test_the_short_identifier_from_the_list_is_accepted(self):
        results = self.run_pod("check", snapshot=SNAPSHOT[:8])
        self.assertTrue(all(result.returncode == 0 for _, result in results), self.output(results))

    # restore

    def test_restore_restores_the_snapshot_onto_the_volume_and_then_the_data(self):
        (self.work / "objects").mkdir()
        (self.work / "objects/not-in-the-snapshot.webp").write_text("x")
        results = self.run_pod("restore")
        self.assertTrue(all(result.returncode == 0 for _, result in results), self.output(results))
        (restore,) = [call for call in self.calls("restic") if call.split()[1] == "restore"]
        self.assertIn(f"{SNAPSHOT}:/work", restore.split())
        self.assertIn("--delete", restore.split())
        self.assertEqual(str(self.work), restore.split()[restore.split().index("--target") + 1])
        self.assertIn("--retry-lock", restore.split())
        self.assertFalse((self.work / "objects/not-in-the-snapshot.webp").exists())
        tools = [call.split()[0] for call in self.calls() if call.split()[0] != "restic"]
        self.assertEqual(["psql", "psql", "pg_restore", "psql", "psql", "pg_restore", "rclone", "redis-cli", "rabbitmqadmin",
                          "rabbitmqadmin", "rabbitmqadmin"], tools)

    def test_each_database_is_dropped_with_its_connections_created_for_its_owner_and_restored_as_it(self):
        self.run_pod("restore")
        calls = [call for call in self.calls() if call.split()[0] in ("psql", "pg_restore")]
        for index, database in ((0, "shop"), (3, "keycloak")):
            drop, create, restore = calls[index:index + 3]
            self.assertIn(f"DROP DATABASE IF EXISTS {database} WITH (FORCE)", drop)
            self.assertIn("-d postgres", drop)
            self.assertIn("ON_ERROR_STOP=1", drop)
            self.assertIn(f"CREATE DATABASE {database} OWNER {database}", create)
            for part in ("--no-owner", "--no-privileges", f"--role={database}", "--exit-on-error", f"--dbname={database}",
                         f"{self.work}/dumps/{database}.dump"):
                self.assertIn(part, restore.split(), (database, restore))

    def test_the_objects_go_back_to_the_bucket_with_what_is_not_in_the_snapshot_deleted(self):
        self.run_pod("restore")
        self.assertEqual([f"rclone sync {self.work}/objects garage:product-images"], self.calls("rclone"))

    def test_redis_is_flushed_and_every_queue_purged(self):
        self.run_pod("restore")
        self.assertEqual(["redis-cli -h redis FLUSHALL"], self.calls("redis-cli"))
        self.assertEqual(["rabbitmqadmin --non-interactive list queues",
                          "rabbitmqadmin --non-interactive purge queue --name events",
                          "rabbitmqadmin --non-interactive purge queue --name outbox"], self.calls("rabbitmqadmin"))

    def test_without_queues_nothing_is_purged(self):
        results = self.run_pod("restore", STUB_QUEUES="")
        self.assertEqual(0, results[-1][1].returncode, self.output(results))
        self.assertEqual(["rabbitmqadmin --non-interactive list queues"], self.calls("rabbitmqadmin"))

    def test_a_failing_tool_stops_the_restore_at_its_stage(self):
        for failing, stage in (("restic:restore", "restic"), ("psql", "databases"), ("pg_restore", "databases"), ("rclone", "objects"),
                               ("redis-cli", "redis"), ("rabbitmqadmin:--non-interactive", "rabbitmq")):
            with self.subTest(failing=failing):
                self.log.write_text("")
                results = self.run_pod("restore", STUB_FAIL=failing)
                last, result = results[-1]
                self.assertEqual(stage, last, failing)
                self.assertNotEqual(0, result.returncode, failing)
                self.assertTrue(result.stderr.strip(), failing)

    def test_a_failing_shop_database_stops_before_keycloak(self):
        self.run_pod("restore", STUB_FAIL="pg_restore")
        self.assertEqual(1, len(self.calls("pg_restore")))
        self.assertNotIn("keycloak", " ".join(self.calls("psql")))

    def test_redis_that_does_not_answer_ok_is_an_error(self):
        result = self.run_pod("restore", STUB_REDIS_REPLY="(error) NOAUTH Authentication required.")[-1][1]
        self.assertNotEqual(0, result.returncode)
        self.assertIn("FLUSHALL", result.stderr)

    def test_a_volume_without_the_dumps_or_the_objects_is_an_error_before_any_change(self):
        for missing in ("dumps/keycloak.dump", "objects"):
            with self.subTest(missing=missing):
                self.log.write_text("")
                self.run_pod("restore")
                target = self.work / missing
                if target.is_dir():
                    import shutil
                    shutil.rmtree(target)
                else:
                    target.unlink()
                self.log.write_text("")
                stage = "databases" if missing.startswith("dumps") else "objects"
                result = self.run_stage(stage, "restore")
                self.assertNotEqual(0, result.returncode, missing)
                self.assertEqual([], self.calls(), missing)

    def test_the_other_modes_change_no_data(self):
        for mode, snapshot in (("list", ""), ("check", SNAPSHOT), ("reindex", "")):
            self.log.write_text("")
            for stage in ("databases", "objects", "redis", "rabbitmq"):
                result = self.run_stage(stage, mode, snapshot)
                self.assertEqual(0, result.returncode, (mode, stage, result.stderr))
            self.assertEqual([], self.calls(), mode)

    # reindex

    def test_reindex_rebuilds_the_index_and_nothing_else(self):
        results = self.run_pod("reindex", snapshot="")
        self.assertTrue(all(result.returncode == 0 for _, result in results), self.output(results))
        self.assertEqual(["console catalog:reindex"], self.calls())

    def test_a_failing_reindex_is_an_error(self):
        result = self.run_stage("api", "reindex", "", STUB_FAIL="console")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("catalog:reindex", result.stderr)

    def test_only_reindex_runs_the_console(self):
        for mode, snapshot in (("list", ""), ("check", SNAPSHOT), ("restore", SNAPSHOT)):
            self.run_stage("api", mode, snapshot)
        self.assertEqual([], self.calls("console"))

    # Secrets and the shape of the script

    def test_no_secret_is_printed_or_passed_on_a_command_line(self):
        for mode, snapshot in (("list", ""), ("check", SNAPSHOT), ("restore", SNAPSHOT), ("reindex", "")):
            output = self.output(self.run_pod(mode, snapshot))
            for value in SECRETS.values():
                self.assertNotIn(value, output, mode)
        for value in SECRETS.values():
            self.assertNotIn(value, self.log.read_text())

    def test_the_script_is_posix_sh_and_stops_at_the_first_error(self):
        text = SCRIPT.read_text()
        self.assertTrue(text.startswith("#!/bin/sh\n"))
        self.assertRegex(text, r"(?m)^set -eu$")
        self.assertIsNone(re.search(r"\|\| true", text), "an error must not be swallowed")


if __name__ == "__main__":
    unittest.main()

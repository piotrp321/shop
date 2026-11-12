"""The script of the nightly backup (files/scripts/backup.sh) against stubs of pg_dump, psql, rclone and restic: what it
runs, in which order, what the snapshot gets (tag, host, manifest), and that a failing tool ends it with an error before
any `restic backup` (so that no incomplete snapshot is made)."""

import json
import os
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/charts/shop/files/scripts/backup.sh"
CLUSTER_ID = "0b6f1d9e-4c3a-4f5b-8e7d-2a9c1b3d5e7f"
REVISION = "0123456789abcdef0123456789abcdef01234567"
MIGRATIONS = ["DoctrineMigrations\\Version20261029100747", "DoctrineMigrations\\Version20261029101125"]
SECRETS = {
    "PGPASSWORD": "sentinel-postgres-password",
    "RESTIC_PASSWORD": "sentinel-restic-password",
    "AWS_ACCESS_KEY_ID": "sentinel-s3-key-id",
    "AWS_SECRET_ACCESS_KEY": "sentinel-s3-secret-key",
    "RCLONE_CONFIG_GARAGE_SECRET_ACCESS_KEY": "sentinel-garage-secret-key",
}

# The stubs log one line per call ("<tool> <arguments>") in $STUB_LOG. A tool named in STUB_FAIL (a space separated list of
# `tool` or `tool:subcommand`) fails with a message and no output.
STUB_COMMON = r"""#!/bin/sh
tool="${0##*/}"
echo "$tool $*" >> "$STUB_LOG"
fails() {
  for item in $STUB_FAIL; do
    [ "$item" = "$tool" ] && return 0
    [ "$item" = "$tool:$1" ] && return 0
  done
  return 1
}
"""

PG_DUMP = STUB_COMMON + r"""
out=""
for arg in "$@"; do
  [ "$prev" = "-f" ] && out="$arg"
  prev="$arg"
done
for arg in "$@"; do db="$arg"; done
if [ -n "$out" ]; then echo "dump of $db" > "$out"; fi
if fails "$db"; then echo "pg_dump: error: connection refused" >&2; exit 1; fi
"""

PSQL = STUB_COMMON + r"""
if fails; then echo "psql: error: connection refused" >&2; exit 2; fi
printf '%s\n' "${STUB_MIGRATIONS:-}"
"""

RCLONE = STUB_COMMON + r"""
case "$1" in
  sync)
    if fails sync; then echo "rclone: Failed to sync: connection refused" >&2; exit 1; fi
    mkdir -p "$3"
    i=0
    while [ "$i" -lt "${STUB_OBJECTS:-2}" ]; do echo "object $i" > "$3/object-$i.webp"; i=$((i + 1)); done ;;
  size)
    if fails size; then echo "rclone: Failed to size" >&2; exit 1; fi
    count="$(ls "$3" | wc -l | tr -d ' ')"
    printf '{"count":%s,"bytes":%s,"sizeless":0}\n' "$count" "$((count * 9))" ;;
esac
"""

RESTIC = STUB_COMMON + r"""
case "$1" in
  cat)
    # STUB_REPO: exists (default), missing (exit code 10 of restic 0.17+), broken (another error).
    case "${STUB_REPO:-exists}" in
      missing) echo "Fatal: unable to open config file: does not exist. Is there a repository at the following location?" >&2; exit 10 ;;
      broken) echo "Fatal: unable to open config file: 403 Forbidden" >&2; exit 1 ;;
    esac
    if [ -f "$STUB_DIR/initialized" ]; then :; fi ;;
  init) if fails init; then echo "Fatal: init failed" >&2; exit 1; fi; : > "$STUB_DIR/initialized" ;;
  backup)
    if fails backup; then echo "Fatal: backup failed" >&2; exit 1; fi
    for arg in "$@"; do last="$arg"; done
    cp "$last" "$STUB_DIR/manifest-of-the-snapshot.json"
    ls "$WORK_DIR/dumps" > "$STUB_DIR/dumps-of-the-snapshot" ;;
  *) if fails "$1"; then echo "Fatal: $1 failed" >&2; exit 1; fi ;;
esac
"""

TOOLS = {"pg_dump": PG_DUMP, "psql": PSQL, "rclone": RCLONE, "restic": RESTIC}


class BackupScriptTest(unittest.TestCase):
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
        self.cluster_file = self.root / "cluster-id"
        self.cluster_file.write_text(CLUSTER_ID)

    def tearDown(self):
        self.tmp.cleanup()

    def run_script(self, stage="all", **env):
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": self.tmp.name,
            "WORK_DIR": str(self.work),
            "CLUSTER_ID_FILE": str(self.cluster_file),
            "REVISION": REVISION,
            "BACKUP_RETENTION": "7d",
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.stub),
            "STUB_MIGRATIONS": "\n".join(MIGRATIONS),
            **SECRETS,
            **env,
        }
        return subprocess.run(["sh", str(SCRIPT), stage], env=variables, capture_output=True, text=True)

    def calls(self):
        return self.log.read_text().splitlines()

    def calls_of(self, tool, subcommand=None):
        found = [line for line in self.calls() if line.split(" ", 1)[0] == tool]
        if subcommand is not None:
            found = [line for line in found if line.split(" ")[1] == subcommand]
        return found

    def restic_subcommands(self):
        return [line.split(" ")[1] for line in self.calls_of("restic")]

    def manifest(self):
        return json.loads((self.stub / "manifest-of-the-snapshot.json").read_text())

    def assert_failed_before_the_snapshot(self, result, message=None):
        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertNotIn("backup", self.restic_subcommands(), "a snapshot was made")
        self.assertFalse((self.stub / "manifest-of-the-snapshot.json").exists())
        if message:
            self.assertIn(message, result.stderr)

    # --- the good run ----------------------------------------------------------------------------------------------

    def test_a_run_dumps_both_databases_in_the_custom_format_without_owners_and_privileges(self):
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        dumps = self.calls_of("pg_dump")
        self.assertEqual(2, len(dumps))
        for line, database in zip(dumps, ("shop", "keycloak")):
            self.assertIn("-Fc", line.split())
            self.assertIn("--no-owner", line.split())
            self.assertIn("--no-privileges", line.split())
            self.assertRegex(line, r"-h postgres\b")
            self.assertRegex(line, r"-U postgres\b")
            self.assertTrue(line.endswith(f" {database}"), line)
            self.assertRegex(line, rf"-f \S*dumps(\.partial)?/{database}\.dump")
        self.assertEqual(["keycloak.dump", "shop.dump"], sorted(p.name for p in (self.work / "dumps").iterdir()))

    def test_the_objects_of_the_images_bucket_are_synced_into_objects(self):
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        syncs = self.calls_of("rclone", "sync")
        self.assertEqual([f"rclone sync garage:product-images {self.work}/objects"], syncs)
        self.assertEqual(2, len(list((self.work / "objects").iterdir())))

    def test_the_snapshot_has_the_tag_the_host_of_the_cluster_and_the_three_paths(self):
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        (backup,) = self.calls_of("restic", "backup")
        words = backup.split()
        self.assertIn("--retry-lock", words)
        self.assertEqual("30m", words[words.index("--retry-lock") + 1])
        self.assertEqual("nightly", words[words.index("--tag") + 1])
        self.assertEqual(CLUSTER_ID, words[words.index("--host") + 1])
        self.assertEqual([f"{self.work}/dumps", f"{self.work}/objects", f"{self.work}/manifest.json"], words[-3:])
        self.assertEqual(["keycloak.dump", "shop.dump"], (self.stub / "dumps-of-the-snapshot").read_text().split())

    def test_the_tag_comes_from_backup_tag(self):
        result = self.run_script(BACKUP_TAG="pre-restore")
        self.assertEqual(0, result.returncode, result.stderr)
        words = self.calls_of("restic", "backup")[0].split()
        self.assertEqual("pre-restore", words[words.index("--tag") + 1])

    def test_only_a_nightly_backup_prunes_so_a_pre_restore_one_cannot_remove_the_snapshot_to_restore(self):
        # --keep-within counts from the newest snapshot of the host: a pre-restore snapshot made just before a restore of an old
        # one would otherwise push the oldest nightly ones out, the one that is being restored among them.
        result = self.run_script(BACKUP_TAG="pre-restore")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["cat", "unlock", "backup"], self.restic_subcommands())
        self.assertIn("pre-restore", result.stdout)
        self.assertIn("prunes nothing", result.stdout)
        self.assertIn("backup: done", result.stdout)

    def test_the_order_is_check_the_repository_unlock_backup_forget(self):
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["cat", "unlock", "backup", "forget"], self.restic_subcommands())
        self.assertEqual(["restic unlock"], self.calls_of("restic", "unlock"), "unlock must not remove the locks that are in use")

    def test_forget_keeps_the_retention_prunes_and_waits_for_the_lock_of_a_verification(self):
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        (forget,) = self.calls_of("restic", "forget")
        words = forget.split()
        self.assertEqual("7d", words[words.index("--keep-within") + 1])
        self.assertIn("--prune", words)
        self.assertEqual("30m", words[words.index("--retry-lock") + 1])
        # Only the snapshots of this cluster are pruned.
        self.assertEqual(CLUSTER_ID, words[words.index("--host") + 1])

    def test_the_retention_comes_from_the_environment(self):
        self.run_script(BACKUP_RETENTION="14d")
        words = self.calls_of("restic", "forget")[0].split()
        self.assertEqual("14d", words[words.index("--keep-within") + 1])

    def test_the_manifest_has_the_revision_the_time_the_migrations_and_the_objects(self):
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        manifest = self.manifest()
        self.assertEqual(REVISION, manifest["revision"])
        self.assertRegex(manifest["time"], r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$")
        self.assertEqual(MIGRATIONS, manifest["migrations"])
        self.assertEqual({"count": 2, "bytes": 18}, manifest["objects"])
        self.assertEqual(CLUSTER_ID, manifest["cluster"])
        self.assertEqual(["shop", "keycloak"], manifest["databases"])
        self.assertEqual(manifest, json.loads((self.work / "manifest.json").read_text()))

    def test_the_migrations_are_read_from_the_shop_database_as_postgres(self):
        self.run_script()
        (query,) = self.calls_of("psql")
        self.assertIn("doctrine_migration_versions", query)
        self.assertRegex(query, r"-h postgres\b")
        self.assertRegex(query, r"-U postgres\b")
        self.assertRegex(query, r"-d shop\b")

    def test_the_migrations_are_read_after_the_dump_of_shop(self):
        # A migration between the two would give a manifest with fewer migrations than the dump has.
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        calls = self.calls()
        dump_of_shop = next(i for i, line in enumerate(calls) if line.startswith("pg_dump") and line.endswith(" shop"))
        migrations = next(i for i, line in enumerate(calls) if line.startswith("psql") and "doctrine_migration_versions" in line)
        self.assertLess(dump_of_shop, migrations)

    def test_a_failing_read_of_the_migrations_leaves_no_dumps_to_be_taken_for_good_ones(self):
        result = self.run_script("dump", STUB_FAIL="psql")
        self.assertNotEqual(0, result.returncode)
        self.assertFalse((self.work / "dumps").exists())

    def test_an_empty_bucket_gives_an_empty_mirror_and_zero_objects(self):
        result = self.run_script(STUB_OBJECTS="0")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual({"count": 0, "bytes": 0}, self.manifest()["objects"])

    def test_the_stages_run_one_by_one_as_the_containers_of_the_pod_do(self):
        for stage in ("dump", "objects", "snapshot"):
            result = self.run_script(stage)
            self.assertEqual(0, result.returncode, f"{stage}: {result.stderr}")
        self.assertEqual(["cat", "unlock", "backup", "forget"], self.restic_subcommands())
        self.assertEqual(2, len(self.calls_of("pg_dump")))

    def test_the_second_run_starts_from_clean_dumps(self):
        self.run_script()
        (self.work / "dumps" / "old.dump").write_text("stale")
        self.run_script()
        self.assertEqual(["keycloak.dump", "shop.dump"], (self.stub / "dumps-of-the-snapshot").read_text().split())

    # --- the repository ---------------------------------------------------------------------------------------------

    def test_a_repository_that_does_not_exist_is_initialized_then_used(self):
        result = self.run_script(STUB_REPO="missing")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["cat", "init", "unlock", "backup", "forget"], self.restic_subcommands())

    def test_an_existing_repository_is_not_initialized(self):
        self.run_script()
        self.assertNotIn("init", self.restic_subcommands())

    def test_another_error_of_access_is_an_error_and_not_an_init(self):
        result = self.run_script(STUB_REPO="broken")
        self.assert_failed_before_the_snapshot(result, "403 Forbidden")
        self.assertNotIn("init", self.restic_subcommands())

    def test_a_failing_init_is_an_error(self):
        result = self.run_script(STUB_REPO="missing", STUB_FAIL="restic:init")
        self.assert_failed_before_the_snapshot(result, "init failed")

    # --- a failing tool ends the run before the snapshot -------------------------------------------------------------

    def test_a_failing_tool_ends_the_run_with_an_error_and_makes_no_snapshot(self):
        for fail, message in (
            ("pg_dump:shop", "connection refused"),
            ("pg_dump:keycloak", "connection refused"),
            ("psql", "connection refused"),
            ("rclone:sync", "Failed to sync"),
            ("rclone:size", "Failed to size"),
            ("restic:unlock", "unlock failed"),
        ):
            with self.subTest(fail=fail):
                self.log.write_text("")
                for leftover in self.work.iterdir():
                    subprocess.run(["rm", "-rf", str(leftover)], check=True)
                result = self.run_script(STUB_FAIL=fail)
                self.assert_failed_before_the_snapshot(result, message)

    def test_a_failing_backup_is_an_error_and_nothing_is_pruned(self):
        result = self.run_script(STUB_FAIL="restic:backup")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("backup failed", result.stderr)
        self.assertNotIn("forget", self.restic_subcommands())

    def test_a_failing_forget_is_an_error(self):
        result = self.run_script(STUB_FAIL="restic:forget")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("forget failed", result.stderr)

    def test_the_whole_tool_failing_is_an_error_too(self):
        for tool in ("pg_dump", "psql", "rclone", "restic"):
            with self.subTest(tool=tool):
                self.log.write_text("")
                result = self.run_script(STUB_FAIL=tool)
                self.assertNotEqual(0, result.returncode)
                self.assertNotIn("backup", self.restic_subcommands())

    def test_a_failed_dump_leaves_no_dump_to_be_taken_for_a_good_one(self):
        self.run_script()
        result = self.run_script("dump", STUB_FAIL="pg_dump:keycloak")
        self.assertNotEqual(0, result.returncode)
        self.assertFalse((self.work / "dumps" / "shop.dump").exists(), "the dump of the previous run is still there")
        self.assertFalse((self.work / "dumps" / "keycloak.dump").exists())
        self.assertFalse((self.work / "manifest.json").exists())

    def test_the_snapshot_stage_does_not_run_without_the_dumps_the_objects_and_the_cluster(self):
        for prepare, message in (
            (lambda: None, "no dump"),
            (lambda: (self.work / "dumps").mkdir() or (self.work / "dumps" / "shop.dump").write_text("x"), "no dump"),
        ):
            with self.subTest(message=message):
                prepare()
                result = self.run_script("snapshot")
                self.assert_failed_before_the_snapshot(result, message)

    def test_an_empty_dump_is_not_snapshotted(self):
        self.run_script("dump")
        self.run_script("objects")
        (self.work / "dumps" / "shop.dump").write_text("")
        self.log.write_text("")
        result = self.run_script("snapshot")
        self.assert_failed_before_the_snapshot(result, "empty")

    def test_no_cluster_id_is_an_error_before_the_snapshot(self):
        for content in (None, "", "\n", "not a uid", "0b6f1d9e-4c3a-4f5b-8e7d-2a9c1b3d5e7f-extra"):
            with self.subTest(content=content):
                self.log.write_text("")
                self.run_script("dump")
                self.run_script("objects")
                if content is None:
                    self.cluster_file.unlink()
                else:
                    self.cluster_file.write_text(content)
                result = self.run_script("snapshot")
                self.assert_failed_before_the_snapshot(result, "cluster")
                self.cluster_file.write_text(CLUSTER_ID)

    def test_the_cluster_id_may_end_with_a_newline(self):
        self.cluster_file.write_text(CLUSTER_ID + "\n")
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        words = self.calls_of("restic", "backup")[0].split()
        self.assertEqual(CLUSTER_ID, words[words.index("--host") + 1])

    def test_a_missing_revision_is_an_error_before_the_snapshot(self):
        result = self.run_script(REVISION="")
        self.assert_failed_before_the_snapshot(result, "REVISION")

    def test_an_unknown_stage_is_a_usage_error(self):
        for stage in ("", "restore", "dumps"):
            with self.subTest(stage=stage):
                result = self.run_script(stage)
                self.assertEqual(2, result.returncode)
                self.assertIn("usage: backup.sh", result.stderr)
                self.assertEqual([], self.calls())

    # --- secrets ----------------------------------------------------------------------------------------------------

    def test_no_secret_is_printed_or_passed_on_a_command_line(self):
        for env in ({}, {"STUB_FAIL": "restic:backup"}, {"STUB_FAIL": "pg_dump:shop"}, {"STUB_REPO": "broken"}):
            with self.subTest(env=env):
                self.log.write_text("")
                result = self.run_script(**env)
                everything = result.stdout + result.stderr + "\n".join(self.calls())
                for name, value in SECRETS.items():
                    self.assertNotIn(value, everything, name)

    def test_the_script_is_posix_sh_and_stops_at_the_first_error(self):
        text = SCRIPT.read_text()
        self.assertTrue(text.startswith("#!/bin/sh\n"))
        self.assertRegex(text, r"(?m)^set -eu$")
        self.assertIsNone(re.search(r"\|\| true", text), "an error must not be swallowed")


if __name__ == "__main__":
    unittest.main()

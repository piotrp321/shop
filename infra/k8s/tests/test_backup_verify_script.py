"""The script of the daily verification (files/scripts/backup-verify.sh) against stubs of restic, psql, pg_restore and date:
what it checks and in which order, that the dumps go from `restic dump` to `pg_restore` through a pipe, and that every
unmet condition ends it with an error that says what is wrong."""

import datetime
import os
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO

SCRIPT = REPO / "infra/k8s/charts/shop/files/scripts/backup-verify.sh"
CLUSTER_ID = "0b6f1d9e-4c3a-4f5b-8e7d-2a9c1b3d5e7f"
SECRETS = {
    "RESTIC_PASSWORD": "sentinel-restic-password",
    "AWS_ACCESS_KEY_ID": "sentinel-s3-key-id",
    "AWS_SECRET_ACCESS_KEY": "sentinel-s3-secret-key",
}

# The stubs log one line per call ("<tool> <arguments>") in $STUB_LOG. A tool named in STUB_FAIL (a space separated list of
# `tool` or `tool:what`) fails with a message.
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

# snapshots: STUB_SNAPSHOT_TIMES (space separated; none = no snapshot) as the list of restic, only for the right filters.
# check: fails on `restic` or `restic:check`. dump: the dump of a database or the manifest of the snapshot; the manifest has
# STUB_MANIFEST_OBJECTS objects (empty: a manifest without the count). ls: STUB_SNAPSHOT_OBJECTS files and the directories.
RESTIC = STUB_COMMON + r"""
case "$1" in
  snapshots)
    if fails snapshots; then echo "Fatal: unable to open repository" >&2; exit 1; fi
    case " $* " in *" --tag nightly"*" --host $EXPECTED_HOST "*) ;; *) echo "[]"; exit 0 ;; esac
    out=""
    n=0
    for time in $STUB_SNAPSHOT_TIMES; do
      n=$((n + 1))
      out="$out${out:+,}{\"time\":\"$time\",\"tree\":\"aaaa\",\"paths\":[\"/work/dumps\",\"/work/objects\",\"/work/manifest.json\"],\"hostname\":\"$EXPECTED_HOST\",\"username\":\"root\",\"tags\":[\"nightly\"],\"id\":\"$(printf '%064d' $n)\",\"short_id\":\"snap$n\"}"
    done
    echo "[$out]" ;;
  check)
    if fails check; then echo "Fatal: pack 0123abcd: ciphertext verification failed" >&2; exit 1; fi ;;
  dump)
    id="$2"; path="$3"
    echo "$id" > "$STUB_DIR/dumped-snapshot"
    case "$path" in
      /work/dumps/*)
        db="${path##*/}"; db="${db%.dump}"
        if fails dump || fails "dump:$db"; then
          if [ -n "${STUB_DUMP_PARTIAL:-}" ]; then echo "dump of $db"; fi
          echo "Fatal: dump failed" >&2; exit 1
        fi
        if [ -z "${STUB_DUMP_EMPTY:-}" ]; then echo "dump of $db"; fi ;;
      /work/manifest.json)
        if fails dump:manifest; then echo "Fatal: manifest dump failed" >&2; exit 1; fi
        if [ -n "$STUB_MANIFEST_OBJECTS" ]; then
          printf '{\n  "revision": "abc",\n  "objects": {"count": %s, "bytes": 18}\n}\n' "$STUB_MANIFEST_OBJECTS"
        else
          printf '{\n  "revision": "abc"\n}\n'
        fi ;;
      *) echo "Fatal: no such path $path" >&2; exit 1 ;;
    esac ;;
  ls)
    if fails ls; then echo "Fatal: ls failed" >&2; exit 1; fi
    echo '{"time":"2026-11-11T03:00:00Z","tree":"aaaa","struct_type":"snapshot"}'
    echo '{"name":"objects","type":"dir","path":"/work/objects","struct_type":"node"}'
    case " $* " in *" --recursive "*) ;; *) echo '{"name":"sub","type":"dir","path":"/work/objects/sub","struct_type":"node"}'; exit 0 ;; esac
    i=0
    while [ "$i" -lt "$STUB_SNAPSHOT_OBJECTS" ]; do
      echo "{\"name\":\"object-$i.webp\",\"type\":\"file\",\"path\":\"/work/objects/object-$i.webp\",\"size\":9,\"struct_type\":\"node\"}"
      i=$((i + 1))
    done
    echo '{"name":"sub","type":"dir","path":"/work/objects/sub","struct_type":"node"}' ;;
  *) echo "Fatal: unexpected restic command $*" >&2; exit 1 ;;
esac
"""

PSQL = STUB_COMMON + r"""
query=""
for arg in "$@"; do query="$arg"; done
case "$query" in
  "SELECT 1") if fails connect; then echo "psql: error: connection refused" >&2; exit 2; fi ;;
  CREATE\ DATABASE*) if fails create; then echo "psql: error: could not create" >&2; exit 1; fi ;;
  *doctrine_migration_versions*)
    if fails migrations; then echo 'ERROR:  relation "doctrine_migration_versions" does not exist' >&2; exit 1; fi
    echo "$STUB_MIGRATION_COUNT" ;;
  *realm*)
    if fails realms; then echo 'ERROR:  relation "realm" does not exist' >&2; exit 1; fi
    for realm in $STUB_REALMS; do echo "$realm"; done ;;
  *) echo "psql: unexpected query: $query" >&2; exit 1 ;;
esac
"""

PG_RESTORE = STUB_COMMON + r"""
db=""
for arg in "$@"; do [ "$prev" = "-d" ] && db="$arg"; prev="$arg"; done
cat > "$STUB_DIR/restored-$db"
if [ ! -s "$STUB_DIR/restored-$db" ]; then echo "pg_restore: error: input file is too short (read 0, expected 5)" >&2; exit 1; fi
if fails "$db"; then echo "pg_restore: error: could not execute query: ERROR:  extension \"nope\" does not exist" >&2; exit 1; fi
"""

DATE = r"""#!/bin/sh
if [ "$1" = "+%u" ] && [ -n "${STUB_WEEKDAY:-}" ]; then echo "$STUB_WEEKDAY"; exit 0; fi
PATH="${PATH#"$STUB_BIN:"}"
exec date "$@"
"""

TOOLS = {"restic": RESTIC, "psql": PSQL, "pg_restore": PG_RESTORE, "date": DATE}


def snapshot_id(n):
    return f"{n:064d}"


def restic_time(age_hours, offset_hours=1, fraction=".123456789"):
    """The time of a snapshot as restic prints it (RFC 3339 with nanoseconds and the offset), `age_hours` ago."""
    zone = datetime.timezone(datetime.timedelta(hours=offset_hours))
    moment = datetime.datetime.now(zone) - datetime.timedelta(hours=age_hours)
    sign = "+" if offset_hours >= 0 else "-"
    return moment.strftime("%Y-%m-%dT%H:%M:%S") + fraction + f"{sign}{abs(offset_hours):02d}:00"


class BackupVerifyScriptTest(unittest.TestCase):
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
        self.cluster_file = self.root / "cluster-id"
        self.cluster_file.write_text(CLUSTER_ID)

    def tearDown(self):
        self.tmp.cleanup()

    def run_script(self, **env):
        variables = {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "HOME": self.tmp.name,
            "TMPDIR": str(self.root),
            "CLUSTER_ID_FILE": str(self.cluster_file),
            "STUB_BIN": str(self.bin),
            "STUB_LOG": str(self.log),
            "STUB_DIR": str(self.stub),
            "EXPECTED_HOST": CLUSTER_ID,
            "STUB_SNAPSHOT_TIMES": restic_time(3),
            "STUB_MANIFEST_OBJECTS": "2",
            "STUB_SNAPSHOT_OBJECTS": "2",
            "STUB_MIGRATION_COUNT": "7",
            "STUB_REALMS": "backoffice shop master",
            **SECRETS,
            **env,
        }
        return subprocess.run(["sh", str(SCRIPT)], env=variables, capture_output=True, text=True)

    def calls(self):
        return self.log.read_text().splitlines()

    def calls_of(self, tool, subcommand=None):
        found = [line for line in self.calls() if line.split(" ", 1)[0] == tool]
        if subcommand is not None:
            found = [line for line in found if line.split(" ")[1] == subcommand]
        return found

    def restic_subcommands(self):
        return [line.split(" ")[1] for line in self.calls_of("restic")]

    def assert_fails(self, result, *messages):
        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("backup-verify:", result.stderr)
        for message in messages:
            self.assertIn(message, result.stderr)

    # --- the good run -----------------------------------------------------------------------------------------------

    def test_a_healthy_repository_passes(self):
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("backup-verify: ok", result.stdout)

    def test_the_steps_run_in_the_order_of_the_spec(self):
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["snapshots", "check", "dump", "dump", "dump", "ls"], self.restic_subcommands())
        paths = [line.split(" ")[3] for line in self.calls_of("restic", "dump")]
        self.assertEqual(["/work/dumps/shop.dump", "/work/dumps/keycloak.dump", "/work/manifest.json"], paths)
        self.assertEqual(2, len(self.calls_of("pg_restore")))

    def test_only_the_nightly_snapshots_of_this_cluster_are_looked_at(self):
        self.cluster_file.write_text(CLUSTER_ID)
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        (snapshots,) = self.calls_of("restic", "snapshots")
        words = snapshots.split()
        self.assertIn("--json", words)
        self.assertEqual("nightly", words[words.index("--tag") + 1])
        self.assertEqual(CLUSTER_ID, words[words.index("--host") + 1])

    def test_the_snapshots_of_another_host_do_not_count(self):
        result = self.run_script(EXPECTED_HOST="another-host")
        self.assert_fails(result, "no snapshot")

    def test_the_tag_comes_from_backup_tag(self):
        result = self.run_script(BACKUP_TAG="pre-restore", STUB_SNAPSHOT_TIMES="")
        self.assert_fails(result, "pre-restore")

    def test_the_newest_snapshot_is_the_one_verified(self):
        result = self.run_script(STUB_SNAPSHOT_TIMES=f"{restic_time(30)} {restic_time(2)} {restic_time(50)}")
        self.assertEqual(0, result.returncode, result.stderr)
        for line in self.calls_of("restic", "dump") + self.calls_of("restic", "ls"):
            self.assertIn(snapshot_id(2), line.split(" "), line)

    # --- the age of the last snapshot ---------------------------------------------------------------------------------

    def test_a_snapshot_younger_than_26_hours_passes_in_every_form_of_the_time(self):
        for time in (restic_time(25.5), restic_time(25.5, offset_hours=-5), restic_time(25.5, offset_hours=0, fraction=""),
                     datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                     (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(hours=25.5)).strftime("%Y-%m-%dT%H:%M:%S.5Z")):
            with self.subTest(time=time):
                result = self.run_script(STUB_SNAPSHOT_TIMES=time)
                self.assertEqual(0, result.returncode, result.stderr)

    def test_a_snapshot_older_than_26_hours_is_an_error_that_says_so(self):
        for time in (restic_time(26.5), restic_time(26.5, offset_hours=-5), restic_time(26.5, offset_hours=0, fraction=""), restic_time(72)):
            with self.subTest(time=time):
                self.log.write_text("")
                result = self.run_script(STUB_SNAPSHOT_TIMES=time)
                self.assert_fails(result, "older than 26 h")
                self.assertNotIn("check", self.restic_subcommands())

    def test_no_snapshot_is_an_error(self):
        result = self.run_script(STUB_SNAPSHOT_TIMES="")
        self.assert_fails(result, "no snapshot", "nightly", CLUSTER_ID)
        self.assertEqual(["snapshots"], self.restic_subcommands())

    def test_a_time_that_is_not_a_time_is_an_error(self):
        result = self.run_script(STUB_SNAPSHOT_TIMES="yesterday")
        self.assert_fails(result, "yesterday")

    def test_an_error_of_the_list_of_snapshots_is_an_error(self):
        result = self.run_script(STUB_FAIL="restic:snapshots")
        self.assert_fails(result, "unable to open repository")
        self.assertEqual(["snapshots"], self.restic_subcommands())

    # --- the check of the repository --------------------------------------------------------------------------------------

    def check_words(self, **env):
        result = self.run_script(**env)
        self.assertEqual(0, result.returncode, result.stderr)
        (check,) = self.calls_of("restic", "check")
        return check.split()

    def test_the_n_of_the_subset_is_the_day_of_the_week(self):
        for weekday in range(1, 8):
            with self.subTest(weekday=weekday):
                self.log.write_text("")
                words = self.check_words(STUB_WEEKDAY=str(weekday), VERIFY_READ_DATA="subset")
                self.assertIn(f"--read-data-subset={weekday}/7", words)
                self.assertNotIn("--read-data", words)

    def test_the_default_is_a_subset(self):
        words = self.check_words(STUB_WEEKDAY="3")
        self.assertIn("--read-data-subset=3/7", words)

    def test_the_day_of_the_week_of_date_is_used_without_the_stub(self):
        subset = [w for w in self.check_words() if w.startswith("--read-data-subset=")]
        self.assertEqual(1, len(subset))
        self.assertRegex(subset[0], r"^--read-data-subset=[1-7]/7$")

    def test_read_data_all_reads_all_the_data(self):
        words = self.check_words(STUB_WEEKDAY="3", VERIFY_READ_DATA="all")
        self.assertIn("--read-data", words)
        self.assertEqual([], [w for w in words if w.startswith("--read-data-subset")])

    def test_check_waits_for_the_lock_of_a_backup(self):
        words = self.check_words()
        self.assertEqual("30m", words[words.index("--retry-lock") + 1])

    def test_a_read_data_value_that_is_not_known_is_an_error_before_anything_runs(self):
        result = self.run_script(VERIFY_READ_DATA="some")
        self.assert_fails(result, "VERIFY_READ_DATA", "some")
        self.assertEqual([], self.calls_of("restic"))

    def test_a_failing_check_is_an_error_with_the_output_of_restic(self):
        result = self.run_script(STUB_FAIL="restic:check")
        self.assert_fails(result, "ciphertext verification failed", "restic check")
        self.assertNotIn("dump", self.restic_subcommands())
        self.assertEqual([], self.calls_of("pg_restore"))

    # --- the dumps are restored ---------------------------------------------------------------------------------------------

    def test_the_dumps_go_from_restic_dump_to_pg_restore_through_a_pipe(self):
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("dump of shop\n", (self.stub / "restored-shop").read_text())
        self.assertEqual("dump of keycloak\n", (self.stub / "restored-keycloak").read_text())
        for line in self.calls_of("pg_restore"):
            self.assertNotRegex(line, r"\.dump", "pg_restore must read its standard input")
        self.assertNotIn("restore", self.restic_subcommands(), "nothing is restored to a disk")
        left = [p.name for p in self.root.rglob("*") if p.is_file() and p.name.endswith(".dump")]
        self.assertEqual([], left)

    def test_the_databases_are_created_in_the_temporary_postgres_before_they_are_restored(self):
        self.run_script()
        calls = self.calls()
        for database in ("shop", "keycloak"):
            created = next(i for i, line in enumerate(calls) if line.startswith("psql") and f"CREATE DATABASE {database}" in line)
            restored = next(i for i, line in enumerate(calls) if line.startswith("pg_restore") and f"-d {database}" in line)
            self.assertLess(created, restored)

    def test_pg_restore_skips_owners_and_privileges_as_the_dumps_do(self):
        self.run_script()
        for line in self.calls_of("pg_restore"):
            self.assertIn("--no-owner", line.split())
            self.assertIn("--no-privileges", line.split())

    def test_a_failing_pg_restore_is_an_error_with_its_output_in_the_log(self):
        for database in ("shop", "keycloak"):
            with self.subTest(database=database):
                result = self.run_script(STUB_FAIL=f"pg_restore:{database}")
                self.assert_fails(result, "pg_restore", database, 'extension "nope" does not exist')

    def test_an_empty_dump_is_an_error(self):
        result = self.run_script(STUB_DUMP_EMPTY="1")
        self.assert_fails(result, "pg_restore", "shop", "input file is too short")

    def test_a_failing_restic_dump_is_an_error_even_when_pg_restore_took_what_came(self):
        result = self.run_script(STUB_FAIL="restic:dump", STUB_DUMP_PARTIAL="1")
        self.assert_fails(result, "restic dump", "shop")

    def test_a_failing_restic_dump_without_output_is_an_error(self):
        result = self.run_script(STUB_FAIL="restic:dump")
        self.assert_fails(result, "shop")

    def test_the_temporary_postgres_that_does_not_answer_is_an_error(self):
        result = self.run_script(STUB_FAIL="psql:connect")
        self.assert_fails(result, "temporary Postgres")
        self.assertEqual([], self.calls_of("pg_restore"))

    # --- what the restored databases hold ----------------------------------------------------------------------------------

    def test_an_empty_table_of_migrations_is_an_error(self):
        result = self.run_script(STUB_MIGRATION_COUNT="0")
        self.assert_fails(result, "doctrine_migration_versions", "empty")

    def test_a_missing_table_of_migrations_is_an_error(self):
        result = self.run_script(STUB_FAIL="psql:migrations")
        self.assert_fails(result, "doctrine_migration_versions")

    def test_a_missing_realm_is_an_error_that_names_it(self):
        for realms, missing in (("backoffice master", "shop"), ("shop master", "backoffice"), ("master", "shop"), ("", "shop")):
            with self.subTest(realms=realms):
                result = self.run_script(STUB_REALMS=realms)
                self.assert_fails(result, f"realm {missing}")

    def test_a_realm_with_a_name_that_only_contains_the_name_is_not_the_realm(self):
        result = self.run_script(STUB_REALMS="shop-old backoffice-old master")
        self.assert_fails(result, "realm shop")

    def test_a_missing_table_of_realms_is_an_error(self):
        result = self.run_script(STUB_FAIL="psql:realms")
        self.assert_fails(result, "realm")

    # --- the objects ------------------------------------------------------------------------------------------------------------

    def test_the_count_of_the_files_in_the_snapshot_is_that_of_the_manifest(self):
        for count in (0, 1, 250):
            with self.subTest(count=count):
                result = self.run_script(STUB_MANIFEST_OBJECTS=str(count), STUB_SNAPSHOT_OBJECTS=str(count))
                self.assertEqual(0, result.returncode, result.stderr)

    def test_another_count_of_objects_is_an_error_with_both_numbers(self):
        for manifest, snapshot in (("3", "2"), ("2", "3"), ("0", "1"), ("10", "0")):
            with self.subTest(manifest=manifest, snapshot=snapshot):
                result = self.run_script(STUB_MANIFEST_OBJECTS=manifest, STUB_SNAPSHOT_OBJECTS=snapshot)
                self.assert_fails(result, f"{snapshot} objects", f"{manifest} in the manifest")

    def test_the_listing_is_recursive_because_the_objects_are_in_directories(self):
        """Without --recursive restic ls lists one level only: the files of the subdirectories would not be counted (found by the smoke test)."""
        self.run_script()
        (listing,) = self.calls_of("restic", "ls")
        self.assertIn("--recursive", listing.split())
        self.assertEqual("/work/objects", listing.split()[-1])

    def test_directories_are_not_objects(self):
        # The stub prints two directories next to the files.
        result = self.run_script(STUB_MANIFEST_OBJECTS="4", STUB_SNAPSHOT_OBJECTS="4")
        self.assertEqual(0, result.returncode, result.stderr)

    def test_a_manifest_without_the_count_is_an_error(self):
        result = self.run_script(STUB_MANIFEST_OBJECTS="")
        self.assert_fails(result, "manifest")

    def test_a_failing_read_of_the_manifest_or_of_the_listing_is_an_error(self):
        for fail, message in (("restic:dump:manifest", "manifest dump failed"), ("restic:ls", "ls failed")):
            with self.subTest(fail=fail):
                result = self.run_script(STUB_FAIL=fail)
                self.assert_fails(result, message)

    # --- the cluster and the settings ---------------------------------------------------------------------------------------------

    def test_no_cluster_id_is_an_error_before_anything_runs(self):
        for content in (None, "", "\n", "not a uid", CLUSTER_ID + "-extra"):
            with self.subTest(content=content):
                self.log.write_text("")
                if content is None:
                    self.cluster_file.unlink()
                else:
                    self.cluster_file.write_text(content)
                result = self.run_script()
                self.assert_fails(result, "cluster")
                self.assertEqual([], self.calls())
                self.cluster_file.write_text(CLUSTER_ID)

    def test_the_cluster_id_may_end_with_a_newline(self):
        self.cluster_file.write_text(CLUSTER_ID + "\n")
        result = self.run_script()
        self.assertEqual(0, result.returncode, result.stderr)

    def test_restic_is_run_from_restic_bin(self):
        alternative = self.root / "elsewhere"
        alternative.mkdir()
        (alternative / "restic").write_text(RESTIC)
        (alternative / "restic").chmod(0o755)
        (self.bin / "restic").unlink()
        result = self.run_script(RESTIC_BIN=str(alternative / "restic"))
        self.assertEqual(0, result.returncode, result.stderr)

    def test_the_postgres_is_reached_with_the_environment_of_the_pod(self):
        self.run_script()
        # The stubs do not read the environment: the script must not name a host on the command line of its own.
        for line in self.calls_of("psql") + self.calls_of("pg_restore"):
            self.assertNotRegex(line, r"-h postgres\b")

    # --- secrets and the script itself ------------------------------------------------------------------------------------

    def test_no_secret_is_printed_or_passed_on_a_command_line(self):
        for env in ({}, {"STUB_FAIL": "restic:check"}, {"STUB_FAIL": "pg_restore:shop"}, {"STUB_FAIL": "restic:snapshots"}):
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

    def test_it_leaves_nothing_in_the_temporary_directory(self):
        self.run_script()
        self.run_script(STUB_FAIL="restic:check")
        self.assertEqual({"bin", "stub", "cluster-id"}, {p.name for p in self.root.iterdir()})


if __name__ == "__main__":
    unittest.main()

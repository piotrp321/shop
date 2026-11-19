"""scripts/demo-data-lib.sh (the functions of the demo data that load.sh and generate.sh share): the file of the photo pool, the
Secret demo-photos, the log of the Job with the values masked, the times of the steps from its [OK] lines, the sizes of the
data and diskPeakGb of a profile. Each function is run on its own in `sh`, with the input the header of the library names (the
arguments, DEMO_DATA_TOOL, photos_*, and kc as a function of the test) and nothing that load.sh sets; the behaviour of load.sh
on top of them is in test_load_script.py."""

import base64
import json
import os
import pathlib
import re
import subprocess
import tempfile
import unittest

from support import REPO

LIB = REPO / "infra/k8s/scripts/demo-data-lib.sh"

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

GENERATOR_LOG = "\n".join([
    "2026-11-18T10:00:00.000000000Z 4",
    "2026-11-18T10:00:30.500000000Z  [OK] categories: 10 written, 0 already there                ",
    "2026-11-18T10:02:00.000000000Z  [OK] products (worker 0 of 2): 1 parts, 10 products, 20 variants",
    "2026-11-18T10:02:10.000000000Z  [OK] products (worker 1 of 2): 1 parts, 10 products, 20 variants",
    "2026-11-18T10:03:10.000000000Z  [OK] the generated data is consistent",
])

# A kubectl of the test: the arguments of each call go to $KC_ARGS (one line), its standard input to $KC_STDIN, and it prints
# $KC_OUT (also the values of the photo pool, so that a function that showed the output of kubectl would show them) and ends with
# $KC_STATUS. `kc logs` prints $KC_LOG.
KC = r"""
kc() {
  printf '%s\n' "$*" >> "$KC_ARGS"
  case "$1" in
    logs) printf '%s\n' "$KC_LOG"; return "${KC_LOG_STATUS:-0}" ;;
    create) cat > "$KC_STDIN"; echo "kubectl says: $KC_OUT"; echo "kubectl error: $KC_OUT" >&2; return "${KC_STATUS:-0}" ;;
  esac
  echo "stub: $*"
}
"""


class DemoDataLib(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = pathlib.Path(self.tmp.name)
        self.kc_args = self.dir / "kc-args"
        self.kc_stdin = self.dir / "kc-stdin"

    def tearDown(self):
        self.tmp.cleanup()

    def run_lib(self, script, shell_options=(), **env):
        """Runs the script after the library was sourced under `set -eu`, as load.sh has it; kc is the stub above."""
        variables = {
            "PATH": os.environ["PATH"], "HOME": self.tmp.name, "KC_ARGS": str(self.kc_args), "KC_STDIN": str(self.kc_stdin),
            "KC_OUT": " ".join(READ_ACCESS.values()), "KC_LOG": "", **env,
        }
        text = f'set -eu\n. "{LIB}"\n{KC}\n{script}'
        return subprocess.run(["sh", *shell_options, "-c", text, "sh"], env=variables, capture_output=True, text=True, timeout=60)

    def photo_file(self, values=None, extra="", name="demo-photos.env"):
        values = {**READ_ACCESS, **WRITE_ACCESS} if values is None else values
        path = self.dir / name
        path.write_text("# the photo pool\n" + "".join(f"{n}={v}\n" for n, v in values.items()) + extra)
        return str(path)

    def test_the_library_exists_and_is_sourced_without_output(self):
        result = self.run_lib("")
        self.assertEqual((0, "", ""), (result.returncode, result.stdout, result.stderr))

    def test_the_library_names_nothing_that_only_load_sh_sets(self):
        """The functions get their data from arguments and from the variables of the header, never from load.sh's own."""
        code = "\n".join(line for line in LIB.read_text().splitlines() if not line.lstrip().startswith("#"))
        for forbidden in ("LOAD_", "$out", "${out}", "$cluster", "$namespace", "fail "):
            self.assertNotIn(forbidden, code)
        for name in ("read_photo_access", "create_photo_secret", "save_generator_log", "generator_steps", "data_sizes", "read_disk_peak"):
            self.assertRegex(LIB.read_text(), rf"(?m)^{name}\(\) \{{")

    # -----------------------------------------------------------------------------------------------------------------
    # read_photo_access <file>
    # -----------------------------------------------------------------------------------------------------------------
    PRINT_VALUES = 'printf "%s\\n" "$photos_endpoint" "$photos_bucket" "$photos_key_id" "$photos_secret_key"'

    def test_the_four_read_variables_are_read_from_the_file_of_the_argument(self):
        path = self.photo_file()
        result = self.run_lib(f'read_photo_access "{path}"\n{self.PRINT_VALUES}')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(list(READ_ACCESS.values()), result.stdout.splitlines())

    def test_the_file_is_not_taken_from_a_global_variable(self):
        path = self.photo_file()
        other = self.photo_file({**READ_ACCESS, "DEMO_PHOTOS_BUCKET": "bucket-of-the-other-file"}, name="other.env")
        result = self.run_lib(f'read_photo_access "{path}"\n{self.PRINT_VALUES}', DEMO_PHOTOS_ENV_FILE=other)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(list(READ_ACCESS.values()), result.stdout.splitlines())

    def test_the_file_is_read_as_data_and_nothing_in_it_runs(self):
        marker = self.dir / "ran"
        values = {**READ_ACCESS, "DEMO_PHOTOS_BUCKET": f"$(touch {marker})`touch {marker}`"}
        path = self.photo_file(values, extra=f"touch {marker}\n")
        result = self.run_lib(f'read_photo_access "{path}"\n{self.PRINT_VALUES}')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertFalse(marker.exists())
        self.assertEqual(values["DEMO_PHOTOS_BUCKET"], result.stdout.splitlines()[1])

    def test_quotes_and_carriage_returns_are_not_part_of_the_values(self):
        path = self.dir / "quoted.env"
        names = list(READ_ACCESS)
        path.write_text(f'{names[0]}="{READ_ACCESS[names[0]]}"\r\n{names[1]}=\'{READ_ACCESS[names[1]]}\'\r\n'
                        f"{names[2]}={READ_ACCESS[names[2]]}\r\n{names[3]}={READ_ACCESS[names[3]]}")
        result = self.run_lib(f'read_photo_access "{path}"\n{self.PRINT_VALUES}')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(list(READ_ACCESS.values()), result.stdout.splitlines())

    def test_a_missing_or_unreadable_file_is_a_failure_that_names_the_file_in_the_error(self):
        missing = str(self.dir / "missing.env")
        result = self.run_lib(f'read_photo_access "{missing}" || {{ echo "status $?"; echo "$demo_data_error"; }}')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("status 1", result.stdout)
        self.assertIn(f"{missing} does not exist or cannot be read", result.stdout)
        self.assertIn("DEMO_PHOTOS_ENV_FILE", result.stdout)
        directory = str(self.dir)
        result = self.run_lib(f'read_photo_access "{directory}" || echo "$demo_data_error"')
        self.assertIn(f"{directory} does not exist or cannot be read", result.stdout)

    def test_a_missing_or_blank_variable_is_named_in_the_error_and_no_value_is(self):
        for name in READ_ACCESS:
            for value in (None, "", '""', "   "):
                with self.subTest(name=name, value=value):
                    values = {n: v for n, v in {**READ_ACCESS, **WRITE_ACCESS}.items() if n != name}
                    if value is not None:
                        values[name] = value
                    path = self.photo_file(values)
                    result = self.run_lib(f'read_photo_access "{path}" || echo "status $?: $demo_data_error"')
                    self.assertEqual(0, result.returncode, result.stderr)
                    self.assertIn("status 1", result.stdout)
                    self.assertIn(f" {name}", result.stdout)
                    for secret in {**READ_ACCESS, **WRITE_ACCESS}.values():
                        self.assertNotIn(secret, result.stdout + result.stderr)

    def test_the_token_that_writes_does_not_stand_for_the_read_access(self):
        path = self.photo_file(WRITE_ACCESS)
        result = self.run_lib(f'read_photo_access "{path}" || echo "$demo_data_error"')
        for name in READ_ACCESS:
            self.assertIn(name, result.stdout)
        for value in WRITE_ACCESS.values():
            self.assertNotIn(value, result.stdout + result.stderr)

    def test_a_trace_does_not_show_the_values_and_comes_back_afterwards(self):
        path = self.photo_file()
        result = self.run_lib(f'read_photo_access "{path}"\ncase "$-" in *x*) echo traced-after ;; esac', shell_options=("-x",))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("traced-after", result.stdout)
        for value in {**READ_ACCESS, **WRITE_ACCESS}.values():
            self.assertNotIn(value, result.stderr)

    def test_without_a_trace_there_is_none_afterwards(self):
        path = self.photo_file()
        result = self.run_lib(f'read_photo_access "{path}"\ncase "$-" in *x*) echo traced-after ;; esac')
        self.assertNotIn("traced-after", result.stdout)

    # -----------------------------------------------------------------------------------------------------------------
    # create_photo_secret <name>
    # -----------------------------------------------------------------------------------------------------------------
    def with_access(self, script, **env):
        path = self.photo_file()
        return self.run_lib(f'read_photo_access "{path}"\n{script}', **env)

    def test_the_secret_of_the_name_has_the_four_values_and_goes_to_kubectl_on_its_standard_input(self):
        result = self.with_access("create_photo_secret demo-photos-of-the-test")
        self.assertEqual(0, result.returncode, result.stderr)
        manifest = json.loads(self.kc_stdin.read_text())
        self.assertEqual(("Secret", "demo-photos-of-the-test"), (manifest["kind"], manifest["metadata"]["name"]))
        self.assertEqual(READ_ACCESS, {k: base64.b64decode(v).decode() for k, v in manifest["data"].items()})
        self.assertEqual(["create -f -"], self.kc_args.read_text().splitlines())

    def test_the_token_that_writes_never_reaches_the_secret(self):
        result = self.with_access("create_photo_secret demo-photos")
        self.assertEqual(0, result.returncode, result.stderr)
        stdin = self.kc_stdin.read_text()
        for value in WRITE_ACCESS.values():
            self.assertNotIn(value, stdin)
            self.assertNotIn(base64.b64encode(value.encode()).decode(), stdin)

    def test_the_values_are_neither_in_the_arguments_nor_in_the_output_nor_in_a_trace(self):
        for options in ((), ("-x",)):
            with self.subTest(shell_options=options):
                self.kc_args.unlink(missing_ok=True)
                result = self.with_access("create_photo_secret demo-photos", shell_options=options)
                self.assertEqual(0, result.returncode, result.stderr[-2000:])
                shown = result.stdout + result.stderr + self.kc_args.read_text()
                for value in READ_ACCESS.values():
                    for form in (value, base64.b64encode(value.encode()).decode()):
                        self.assertNotIn(form, shown)

    def test_a_kubectl_that_fails_is_a_failure_without_its_output(self):
        result = self.with_access('create_photo_secret demo-photos || echo "status $?: $demo_data_error"', KC_STATUS="1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("status 1", result.stdout)
        self.assertIn("the Secret demo-photos of the photo pool could not be created", result.stdout)
        for value in READ_ACCESS.values():
            self.assertNotIn(value, result.stdout + result.stderr)

    # -----------------------------------------------------------------------------------------------------------------
    # save_generator_log <job> <file>
    # -----------------------------------------------------------------------------------------------------------------
    def test_the_log_of_the_job_goes_to_the_file_with_its_timestamps(self):
        target = self.dir / "demo-data.log"
        result = self.with_access(f'save_generator_log demo-data-generate "{target}"', KC_LOG=GENERATOR_LOG)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(GENERATOR_LOG + "\n", target.read_text())
        self.assertEqual("logs job/demo-data-generate --container=demo-data --timestamps", self.kc_args.read_text().strip())
        self.assertEqual("", result.stdout + result.stderr)

    def test_the_values_of_the_photo_pool_in_the_log_are_masked_and_counted_on_the_standard_error(self):
        target = self.dir / "demo-data.log"
        secret = READ_ACCESS["DEMO_PHOTOS_SECRET_ACCESS_KEY"]
        log = GENERATOR_LOG + f"\n2026-11-18T10:03:11.000000000Z a line with {secret} and {READ_ACCESS['DEMO_PHOTOS_BUCKET']} twice {secret}"
        result = self.with_access(f'save_generator_log j "{target}"', KC_LOG=log)
        self.assertEqual(0, result.returncode, result.stderr)
        text = target.read_text()
        for value in READ_ACCESS.values():
            self.assertNotIn(value, text)
            self.assertNotIn(value, result.stdout + result.stderr)
        self.assertIn("a line with [masked] and [masked] twice [masked]", text)
        self.assertIn("load: the log of the generator held 3 value(s) of the access of the photo pool; they are masked in demo-data.log", result.stderr)

    def test_a_value_inside_another_one_does_not_leave_a_part_of_the_other(self):
        target = self.dir / "demo-data.log"
        values = {**READ_ACCESS, "DEMO_PHOTOS_BUCKET": "abcdef", "DEMO_PHOTOS_ACCESS_KEY_ID": "abc"}
        path = self.photo_file(values)
        result = self.run_lib(f'read_photo_access "{path}"\nsave_generator_log j "{target}"', KC_LOG="x abcdef y abc z")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("x [masked] y [masked] z\n", target.read_text())

    def test_the_prefix_of_the_message_is_the_tool_that_calls(self):
        target = self.dir / "demo-data.log"
        log = f"a line with {READ_ACCESS['DEMO_PHOTOS_BUCKET']}"
        result = self.with_access(f'DEMO_DATA_TOOL=generate\nsave_generator_log j "{target}"', KC_LOG=log)
        self.assertIn("generate: the log of the generator held 1 value(s)", result.stderr)
        self.assertNotIn("load:", result.stderr)

    def test_the_log_is_saved_when_the_photo_pool_was_never_read(self):
        # generate.sh saves the log of a Job of the seed, which needs no photo pool: photos_* are not set at all, and under
        # `set -u` the log must still be the whole log, not an empty file with exit 0.
        target = self.dir / "demo-data.log"
        result = self.run_lib(f'DEMO_DATA_TOOL=generate\nsave_generator_log demo-data-seed "{target}"\necho saved', KC_LOG=GENERATOR_LOG)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(GENERATOR_LOG + "\n", target.read_text())
        self.assertEqual("saved\n", result.stdout)
        self.assertEqual("", result.stderr)

    def test_a_log_that_cannot_be_read_leaves_a_line_that_says_so_and_does_not_end_the_caller(self):
        target = self.dir / "demo-data.log"
        script = f'kc() {{ echo "kubectl error with {READ_ACCESS["DEMO_PHOTOS_BUCKET"]}" >&2; return 1; }}\nsave_generator_log demo-data-load "{target}"'
        result = self.with_access(script)
        self.assertEqual(0, result.returncode, result.stderr)
        text = target.read_text()
        self.assertIn("load: the log of the Job demo-data-load could not be read", text)
        self.assertNotIn(READ_ACCESS["DEMO_PHOTOS_BUCKET"], text)

    def test_the_log_is_saved_without_a_trace_of_the_values(self):
        target = self.dir / "demo-data.log"
        result = self.with_access(f'save_generator_log j "{target}"', shell_options=("-x",), KC_LOG="a line")
        self.assertEqual(0, result.returncode, result.stderr)
        for value in READ_ACCESS.values():
            self.assertNotIn(value, result.stderr)

    # -----------------------------------------------------------------------------------------------------------------
    # generator_steps <log file>
    # -----------------------------------------------------------------------------------------------------------------
    def test_the_steps_come_from_the_ok_lines_of_the_log_of_the_argument(self):
        log = self.dir / "any-name.log"
        log.write_text(GENERATOR_LOG + "\n")
        result = self.run_lib(f'generator_steps "{log}"')
        self.assertEqual(0, result.returncode, result.stderr)
        lines = result.stdout.splitlines()
        self.assertTrue(lines[0].startswith("# seconds of each step of bin/generate-demo"))
        self.assertRegex(result.stdout, r"(?m)^\s*30 s  categories$")
        self.assertRegex(result.stdout, r"(?m)^\s*100 s  products$")
        self.assertRegex(result.stdout, r"(?m)^\s*60 s  the generated data is consistent$")
        self.assertEqual("total: 190 s (from the first to the last line of the log)", lines[-1])

    def test_a_log_without_an_ok_line_says_the_steps_could_not_be_timed(self):
        log = self.dir / "empty.log"
        log.write_text("2026-11-18T10:00:00.000000000Z nothing here\n")
        result = self.run_lib(f'generator_steps "{log}"')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("no [OK] line in demo-data.log: the steps could not be timed", result.stdout)

    def test_the_steps_of_a_log_that_is_not_there_are_a_failure(self):
        result = self.run_lib(f'generator_steps "{self.dir}/missing.log" || echo "status $?"')
        self.assertIn("status ", result.stdout)
        self.assertNotIn("status 0", result.stdout)

    # -----------------------------------------------------------------------------------------------------------------
    # data_sizes
    # -----------------------------------------------------------------------------------------------------------------
    def test_the_sizes_ask_postgres_for_the_databases_the_tables_and_the_indexes_and_elasticsearch_for_the_indices(self):
        result = self.run_lib("data_sizes")
        self.assertEqual(0, result.returncode, result.stderr)
        calls = self.kc_args.read_text()
        self.assertIn("exec statefulset/postgres -- psql -U shop -d shop", calls)
        for what in ("pg_database_size", "pg_table_size", "pg_indexes_size", "pg_total_relation_size", "pg_relation_size"):
            self.assertIn(what, calls)
        self.assertIn("exec statefulset/elasticsearch -- curl -fsS http://localhost:9200/_cat/indices?v&bytes=b&s=index http://localhost:9200/_cat/aliases?v", calls)
        for heading in ("== postgres", "== elasticsearch: the indices (bytes) and the aliases"):
            self.assertIn(heading, result.stdout)

    def test_a_failed_read_of_the_sizes_is_written_down_and_does_not_end_the_caller(self):
        script = 'kc() { echo "kubectl failed" >&2; return 1; }\ndata_sizes'
        result = self.run_lib(script)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("(psql failed: the sizes of Postgres are not known)", result.stdout)
        self.assertIn("(curl failed: the sizes of Elasticsearch are not known)", result.stdout)
        self.assertIn("load: the sizes of Postgres could not be read (sizes.txt)", result.stderr)
        self.assertIn("load: the sizes of Elasticsearch could not be read (sizes.txt)", result.stderr)

    def test_the_prefix_of_the_sizes_messages_is_the_tool_that_calls(self):
        script = 'kc() { return 1; }\nDEMO_DATA_TOOL=generate\ndata_sizes'
        result = self.run_lib(script)
        self.assertIn("generate: the sizes of Postgres could not be read", result.stderr)
        self.assertNotIn("load:", result.stderr)

    # -----------------------------------------------------------------------------------------------------------------
    # read_disk_peak <profile> <file>
    # -----------------------------------------------------------------------------------------------------------------
    def profiles(self, text):
        path = self.dir / "profiles.yaml"
        path.write_text(text)
        return str(path)

    def test_the_peak_and_the_limits_of_the_profile_come_from_the_file_of_the_argument(self):
        path = self.profiles("profiles:\n  medium:\n    diskPeakGb: 10 # an estimate\n  server:\n    diskPeakGb: 12.4\n")
        show = 'echo "$disk_peak $required_kib $floor_kib $required_gb $floor_gb"'
        result = self.run_lib(f'read_disk_peak medium "{path}"\n{show}\nread_disk_peak server "{path}"\n{show}')
        self.assertEqual(0, result.returncode, result.stderr)
        # 1.25 x and 10% of the peak, GB of 10^9 bytes, the KiB rounded up.
        self.assertEqual(["10 12207032 976563 12.50 1.00", "12.4 15136719 1210938 15.50 1.24"], result.stdout.splitlines())

    def test_a_peak_that_is_not_there_or_not_a_positive_number_is_a_failure_with_the_profile_and_the_file_in_the_error(self):
        cases = {
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
                path = self.profiles(text)
                result = self.run_lib(f'read_disk_peak medium "{path}" || echo "status $?: $demo_data_error"')
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertIn("status 1", result.stdout)
                self.assertIn("diskPeakGb of the profile medium in", result.stdout)
                self.assertIn(path, result.stdout)

    def test_a_file_that_is_not_there_is_a_failure_that_names_it(self):
        missing = str(self.dir / "missing.yaml")
        result = self.run_lib(f'read_disk_peak medium "{missing}" || echo "status $?: $demo_data_error"')
        self.assertIn("status 1", result.stdout)
        self.assertIn(f"diskPeakGb of the profile medium cannot be read: {missing} is not a readable file", result.stdout)

    def test_the_real_profiles_of_the_repository_have_a_peak_for_every_generated_profile(self):
        path = REPO / "apps/api/fixtures/demo-data/profiles.yaml"
        for profile in re.findall(r"(?m)^  (medium|server|full):$", path.read_text()):
            with self.subTest(profile=profile):
                result = self.run_lib(f'read_disk_peak {profile} "{path}"\necho "$disk_peak"')
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertGreater(float(result.stdout.strip()), 0)


if __name__ == "__main__":
    unittest.main()

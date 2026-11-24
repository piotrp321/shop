"""Tests of check_base_images.py: every external image of every Dockerfile in a git repository is name:tag@sha256:<64 hex>.

Each test builds a throwaway git repository in a temporary directory (outside this repository) and runs the script on it.
"""
import os
import subprocess
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(HERE, "check_base_images.py")
DIGEST = "sha256:" + "a" * 64


class CheckBaseImages(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        # The temporary directory is the git ceiling for the repository below it, so a temporary directory that lies
        # inside a checkout is never taken for part of it.
        self.ceiling = os.path.realpath(tmp.name)
        self.root = os.path.join(self.ceiling, "repo")
        os.mkdir(self.root)

    def env(self):
        return {**os.environ, "GIT_CEILING_DIRECTORIES": self.ceiling}

    def git(self, *args):
        subprocess.run(["git", "-C", self.root, *args], check=True, capture_output=True, env=self.env())

    def init(self):
        self.git("init", "-q")

    def write(self, path, content, add=True):
        full = os.path.join(self.root, path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as file:
            file.write(content)
        if add:
            self.git("add", path)

    def run_check(self, *args):
        return subprocess.run([sys.executable, "-I", SCRIPT, *(args or (self.root,))],
                              capture_output=True, text=True, env=self.env())

    def assertClean(self, dockerfile, path="Dockerfile"):
        self.init()
        self.write(path, dockerfile)
        result = self.run_check()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def assertViolation(self, dockerfile, line, path="Dockerfile", add=True):
        self.init()
        self.write(path, dockerfile, add)
        result = self.run_check()
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn(f"{path}:{line}:", result.stdout + result.stderr)

    def assertCouldNotRun(self, result):
        """The documented answer to input that cannot be checked: status 2 and a message, never a clean result."""
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertTrue(result.stderr.startswith("base-images: "), result.stderr)
        self.assertEqual(result.stdout, "")

    # Valid forms

    def test_pinned_image_passes(self):
        self.assertClean(f"FROM node:24.22.3-alpine@{DIGEST}\n")

    def test_pinned_image_with_stage_name_and_registry_with_port_passes(self):
        self.assertClean(f"FROM registry.example.com:5000/team/app:1.2.3@{DIGEST} AS runtime\n")

    def test_intermediate_stage_and_scratch_pass(self):
        self.assertClean(
            f"FROM node:24-alpine@{DIGEST} AS build\n"
            "FROM scratch AS assets\n"
            "FROM build AS prod\n"
            "COPY --from=build /app /app\n"
            "COPY --from=assets /a /a\n"
            "COPY --from=0 /app /app\n"
            "FROM Prod AS dev\n"
        )

    def test_pinned_copy_from_image_passes(self):
        self.assertClean(f"FROM scratch\nCOPY --from=composer:2.10.3@{DIGEST} /usr/bin/composer /usr/local/bin/composer\n")

    def test_platform_flag_with_pinned_image_passes(self):
        self.assertClean(f"FROM --platform=linux/amd64 node:24-alpine@{DIGEST} AS build\n")
        self.assertClean(f"ARG BUILDPLATFORM\nFROM --platform=$BUILDPLATFORM node:24@{DIGEST}\n", path="b/Dockerfile")

    def test_comments_and_syntax_directive_are_ignored(self):
        self.assertClean(f"# syntax=docker/dockerfile:1\n# FROM node\nFROM node:24@{DIGEST}\nRUN echo FROM node\n")

    def test_continuation_lines_are_joined(self):
        self.assertClean(f"FROM \\\n  node:24@{DIGEST} \\\n  AS build\n")

    # Violations (criterion 2), each with file and line

    def test_image_without_digest_fails(self):
        self.assertViolation("# comment\nFROM node:24.22.3-alpine\n", 2)

    def test_image_without_tag_fails(self):
        self.assertViolation("FROM node\n", 1)

    def test_latest_tag_fails(self):
        self.assertViolation("FROM node:latest\n", 1)

    def test_latest_tag_with_digest_fails(self):
        self.assertViolation(f"FROM node:latest@{DIGEST}\n", 1)

    def test_digest_only_fails(self):
        self.assertViolation(f"FROM node@{DIGEST}\n", 1)

    def test_digest_too_short_fails(self):
        self.assertViolation(f"FROM node:24@sha256:{'a' * 63}\n", 1)

    def test_digest_too_long_fails(self):
        self.assertViolation(f"FROM node:24@sha256:{'a' * 65}\n", 1)

    def test_digest_with_non_hex_characters_fails(self):
        self.assertViolation(f"FROM node:24@sha256:{'g' * 64}\n", 1)

    def test_digest_with_uppercase_hex_fails(self):
        self.assertViolation(f"FROM node:24@sha256:{'A' * 64}\n", 1)

    def test_digest_of_another_algorithm_fails(self):
        self.assertViolation(f"FROM node:24@sha512:{'a' * 64}\n", 1)

    def test_name_in_braced_variable_fails(self):
        self.assertViolation(f"ARG IMAGE=node:24@{DIGEST}\nFROM ${{IMAGE}}\n", 2)

    def test_name_in_plain_variable_fails(self):
        self.assertViolation(f"ARG IMAGE=node:24@{DIGEST}\nFROM $IMAGE AS build\n", 2)

    def test_variable_in_tag_fails(self):
        self.assertViolation(f"ARG V=24\nFROM node:${{V}}@{DIGEST}\n", 2)

    def test_lowercase_from_fails(self):
        self.assertViolation("from node:24\n", 1)

    def test_mixed_case_from_and_lowercase_as_fail(self):
        self.assertViolation("From node:24 as build\n", 1)

    def test_from_with_platform_flag_fails(self):
        self.assertViolation("FROM --platform=linux/amd64 node:24 AS build\n", 1)

    def test_copy_from_image_fails(self):
        self.assertViolation("FROM scratch\nCOPY --from=composer:2.10.3 /usr/bin/composer /c\n", 2)

    def test_copy_from_image_without_tag_fails(self):
        self.assertViolation("FROM scratch\nCOPY --chown=1:1 --from=composer /usr/bin/composer /c\n", 2)

    def test_lowercase_copy_from_image_fails(self):
        self.assertViolation("FROM scratch\ncopy --from=composer:2 /usr/bin/composer /c\n", 2)

    def test_add_from_image_fails(self):
        self.assertViolation("FROM scratch\nADD --from=busybox:1 /bin/busybox /b\n", 2)

    def test_copy_from_variable_fails(self):
        self.assertViolation("FROM scratch\nARG SRC\nCOPY --from=${SRC} /a /a\n", 3)

    def test_copy_from_stage_defined_later_fails(self):
        self.assertViolation(f"FROM scratch\nCOPY --from=build /a /a\nFROM node:24@{DIGEST} AS build\n", 2)

    def test_run_mount_from_image_fails(self):
        self.assertViolation("FROM scratch\nRUN --mount=type=bind,from=busybox:1,target=/b true\n", 2)

    def test_violation_on_a_continued_instruction_points_at_its_first_line(self):
        self.assertViolation("FROM scratch\nCOPY \\\n  --from=composer:2 \\\n  /a /a\n", 2)

    def test_every_violation_is_reported(self):
        self.init()
        self.write("Dockerfile", f"FROM node:24\nFROM node:24@{DIGEST} AS ok\nFROM alpine\n")
        result = self.run_check()
        self.assertEqual(result.returncode, 1)
        self.assertIn("Dockerfile:1:", result.stdout)
        self.assertIn("Dockerfile:3:", result.stdout)
        self.assertNotIn("Dockerfile:2:", result.stdout)

    def test_violation_names_the_image(self):
        self.init()
        self.write("Dockerfile", "FROM node:24-alpine\n")
        self.assertIn("node:24-alpine", self.run_check().stdout)

    # Ways of writing a reference that Docker accepts and a reader of lines could miss

    def test_byte_order_mark_before_the_first_instruction_is_ignored(self):
        self.assertViolation("﻿FROM node:24\n", 1)

    def test_pinned_image_after_a_byte_order_mark_passes(self):
        self.assertClean(f"﻿FROM node:24@{DIGEST}\n")

    def test_empty_line_inside_a_continuation_does_not_end_the_instruction(self):
        self.assertViolation("FROM \\\n\n  node:24\n", 1)

    def test_empty_line_inside_a_continued_copy_does_not_end_the_instruction(self):
        self.assertViolation("FROM scratch\nCOPY \\\n\n  --from=img:1 \\\n\n  /a /a\n", 2)

    def test_comment_inside_a_continuation_does_not_end_the_instruction(self):
        self.assertViolation("FROM scratch\nCOPY \\\n# note\n  --from=img:1 /a /a\n", 2)

    def test_crlf_line_endings_are_read_like_lf(self):
        self.assertViolation("FROM scratch\r\nCOPY \\\r\n  --from=img:1 /a /a\r\n", 2)

    def test_onbuild_copy_from_image_fails(self):
        self.assertViolation("FROM scratch\nONBUILD COPY --from=img:1 /a /a\n", 2)

    def test_onbuild_add_and_run_mount_from_image_fail(self):
        self.assertViolation("FROM scratch\nonbuild ADD --from=img:1 /a /a\n", 2)
        self.assertViolation("FROM scratch\nONBUILD RUN --mount=type=bind,from=img:1,target=/b true\n", 2,
                             path="b/Dockerfile")

    def test_onbuild_copy_from_a_pinned_image_and_an_earlier_stage_pass(self):
        self.assertClean(f"FROM node:24@{DIGEST} AS build\nFROM scratch\n"
                         f"ONBUILD COPY --from=img:1@{DIGEST} /a /a\nONBUILD COPY --from=build /b /b\n")

    def test_escape_directive_changes_the_continuation_character(self):
        self.assertViolation("# escape=`\nFROM scratch\nCOPY `\n  --from=img:1 /a /a\n", 3)

    def test_pinned_copy_with_the_escape_directive_passes(self):
        self.assertClean(f"# escape=`\nFROM scratch\nCOPY `\n  --from=img:1@{DIGEST} /a /a\n")

    # Files that are checked

    def test_new_dockerfile_in_any_directory_is_checked(self):
        self.init()
        self.write("Dockerfile", f"FROM node:24@{DIGEST}\n")
        self.write("deep/er/dir/Dockerfile", "FROM node:24\n")
        result = self.run_check()
        self.assertEqual(result.returncode, 1)
        self.assertIn("deep/er/dir/Dockerfile:1:", result.stdout)

    def test_untracked_dockerfile_is_checked(self):
        self.assertViolation("FROM node:24\n", 1, path="new/Dockerfile", add=False)

    def test_ignored_dockerfile_is_not_checked(self):
        self.init()
        self.write(".gitignore", "build/\n")
        self.write("Dockerfile", f"FROM node:24@{DIGEST}\n")
        self.write("build/Dockerfile", "FROM node\n", add=False)
        result = self.run_check()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_dockerfile_name_variants_are_checked(self):
        self.init()
        names = ("Dockerfile.dev", "api.Dockerfile", "dockerfile", "DOCKERFILE.prod")
        for name in names:
            self.write(f"sub/{name}", "FROM node:24\n")
        result = self.run_check()
        self.assertEqual(result.returncode, 1)
        for name in names:
            self.assertIn(f"sub/{name}:1:", result.stdout)

    def test_other_files_are_not_checked(self):
        self.init()
        self.write("Dockerfile", f"FROM node:24@{DIGEST}\n")
        self.write("docs/notes.md", "FROM node\n")
        self.write("Dockerfiles/readme.txt", "FROM node\n")
        result = self.run_check()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    # No false "clean" result (criterion 3)

    def test_outside_a_git_repository_fails(self):
        # No `git init`: the ceiling stops git from finding a repository above the temporary directory.
        self.write("Dockerfile", f"FROM node:24@{DIGEST}\n", add=False)
        result = self.run_check()
        self.assertCouldNotRun(result)
        self.assertIn("git", result.stderr)

    def test_repository_without_dockerfile_fails(self):
        self.init()
        self.write("README.md", "hello\n")
        result = self.run_check()
        self.assertCouldNotRun(result)
        self.assertIn("Dockerfile", result.stderr)

    def test_missing_directory_fails(self):
        result = self.run_check(os.path.join(self.root, "missing"))
        self.assertCouldNotRun(result)

    def test_tracked_dockerfile_deleted_from_disk_fails(self):
        self.init()
        self.write("Dockerfile", f"FROM node:24@{DIGEST}\n")
        os.remove(os.path.join(self.root, "Dockerfile"))
        result = self.run_check()
        self.assertCouldNotRun(result)

    def test_dockerfile_that_is_not_utf8_fails(self):
        self.init()
        self.write("Dockerfile", "FROM node:24\n")
        with open(os.path.join(self.root, "Dockerfile"), "wb") as file:
            file.write(f"FROM node:24@{DIGEST}\n".encode() + b"\xff\xfe\n")
        result = self.run_check()
        self.assertCouldNotRun(result)

    def test_success_names_the_number_of_files(self):
        self.assertClean(f"FROM node:24@{DIGEST}\n")
        self.assertIn("1 Dockerfile", self.run_check().stdout)


if __name__ == "__main__":
    unittest.main()

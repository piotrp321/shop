"""search-cache-clear.sh (the Makefile target that empties the request cache of the product index over SSH) against a stub of ssh
that answers like curl in the pod elasticsearch: that the one call that changes anything is POST /products/_cache/clear?request=true,
and that every way it can go wrong (no HOST, a host that cannot be reached, no alias, an alias on two indices, an error of
Elasticsearch, a failed shard) ends with a code other than 0 and a message."""

import os
import subprocess
import unittest

from support import REPO
from test_server_scripts import ServerScripts

HOST = "deploy@203.0.113.7"
ALIAS_BODY = '{"products-20261120":{"aliases":{"products":{}}}}'
CLEAR_BODY = '{"_shards":{"total":2,"successful":2,"failed":0}}'
CALL = "kubectl -n shop exec statefulset/elasticsearch -- curl -sS -X "

# Stub of ssh: the line of arguments of every call in $STUB_LOG; the answer of curl (the body, a new line, the HTTP code that -w
# prints) by the path of the call. STUB_SSH_FAIL: the connection fails (ssh exits with 255, as it does for a host it cannot reach).
SSH = r"""#!/bin/sh
printf '%s\n' "$(printf '%s' "$*" | tr '\n' ' ')" >> "$STUB_LOG"
[ -z "${STUB_SSH_FAIL:-}" ] || { echo "ssh: connect to host: Connection timed out" >&2; exit 255; }
case "$*" in
  *"_alias/products"*) printf '%s\n%s' "${STUB_ALIAS_BODY-$DEFAULT_ALIAS_BODY}" "${STUB_ALIAS_CODE:-200}" ;;
  *"products/_cache/clear?request=true"*)
    [ -z "${STUB_CLEAR_SSH_FAIL:-}" ] || exit 255
    printf '%s\n%s' "${STUB_CLEAR_BODY-$DEFAULT_CLEAR_BODY}" "${STUB_CLEAR_CODE:-200}" ;;
  *) echo "stub: unexpected call" >&2; exit 1 ;;
esac
"""


class EsStub(ServerScripts):
    def setUp(self):
        super().setUp()
        (self.bin / "ssh").write_text(SSH)

    def clear(self, host=HOST, **env):
        return self.run_script("search-cache-clear.sh", host, DEFAULT_ALIAS_BODY=ALIAS_BODY, DEFAULT_CLEAR_BODY=CLEAR_BODY, **env)

    def es_calls(self):
        return [call.split(CALL, 1)[1] for call in self.calls() if CALL in call]

    def assertFailedWith(self, result, message, calls=None):
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn("search-cache-clear:", result.stderr)
        self.assertIn(message, result.stderr)
        self.assertNotIn("done", result.stdout)
        if calls is not None:
            self.assertEqual(calls, len(self.calls()))



class SearchCacheClear(EsStub):
    def test_it_reads_the_alias_then_clears_only_the_request_cache_of_the_product_index(self):
        result = self.clear()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            ["GET -w '\\n%{http_code}' 'http://localhost:9200/_alias/products'",
             "POST -w '\\n%{http_code}' 'http://localhost:9200/products/_cache/clear?request=true'"],
            self.es_calls(),
        )
        self.assertEqual(2, len(self.calls()))
        self.assertIn("-o BatchMode=yes", self.calls()[0])
        self.assertIn(f"-- {HOST} ", self.calls()[0])
        self.assertIn("sudo -n", self.calls()[0])
        self.assertIn("done", result.stdout)
        self.assertIn("products-20261120", result.stdout)
        self.assertIn("2 shard(s)", result.stdout)

    def test_it_changes_nothing_but_the_request_cache(self):
        self.clear()
        for call in self.es_calls():
            if call.startswith("POST"):
                self.assertTrue(call.endswith("/products/_cache/clear?request=true'"), call)
            else:
                self.assertTrue(call.startswith("GET"), call)
            for word in ("query=", "fielddata=", "_delete", "_forcemerge", "_refresh", "_settings", "_aliases", "_close", "DELETE", "PUT"):
                self.assertNotIn(word, call)

    def test_no_host_and_bad_hosts_are_refused_before_ssh_runs(self):
        for host in self.BAD_HOSTS:
            with self.subTest(host=host):
                result = self.clear(host)
                self.assertEqual(2, result.returncode)
                self.assertIn("search-cache-clear:", result.stderr)
        self.assertEqual([], self.calls())

    def test_a_host_that_cannot_be_reached_is_a_failure_with_a_message(self):
        result = self.clear(STUB_SSH_FAIL="1")
        self.assertFailedWith(result, "could not ask Elasticsearch", calls=1)
        self.assertEqual([], self.es_calls())

    def test_a_missing_alias_is_a_failure_and_nothing_is_cleared(self):
        body = '{"error":"alias [products] missing","status":404}'
        result = self.clear(STUB_ALIAS_CODE="404", STUB_ALIAS_BODY=body)
        self.assertFailedWith(result, "alias products does not exist", calls=1)
        self.assertFalse([c for c in self.es_calls() if c.startswith("POST")])

    def test_an_alias_on_no_or_on_two_indices_is_a_failure_and_nothing_is_cleared(self):
        two = '{"products-1":{"aliases":{"products":{}}},"products-2":{"aliases":{"products":{}}}}'
        for body in ("{}", two):
            with self.subTest(body=body):
                self.log.write_text("")
                result = self.clear(STUB_ALIAS_BODY=body)
                self.assertFailedWith(result, "not on exactly one", calls=1)

    def test_an_error_answer_to_the_read_of_the_alias_is_a_failure_and_nothing_is_cleared(self):
        result = self.clear(STUB_ALIAS_CODE="503", STUB_ALIAS_BODY='{"error":"master_not_discovered_exception","status":503}')
        self.assertFailedWith(result, "HTTP 503", calls=1)

    def test_an_error_of_elasticsearch_on_the_clear_is_a_failure(self):
        for code, body in (("404", '{"error":{"type":"index_not_found_exception"},"status":404}'),
                           ("500", '{"error":{"type":"exception"},"status":500}'),
                           ("503", ""),
                           ("200", '{"error":{"type":"exception"},"status":200}')):
            with self.subTest(code=code, body=body):
                result = self.clear(STUB_CLEAR_CODE=code, STUB_CLEAR_BODY=body)
                self.assertFailedWith(result, "Elasticsearch answered", calls=None)

    def test_a_failed_shard_is_a_failure_with_the_numbers(self):
        result = self.clear(STUB_CLEAR_BODY='{"_shards":{"total":2,"successful":1,"failed":1}}')
        self.assertFailedWith(result, "total 2, failed 1")

    def test_an_answer_without_shards_or_with_no_shard_at_all_is_a_failure(self):
        for body, message in (("{}", "no _shards"), ("not json", "no _shards"), ('{"_shards":{"total":0,"successful":0,"failed":0}}', "total 0")):
            with self.subTest(body=body):
                result = self.clear(STUB_CLEAR_BODY=body)
                self.assertFailedWith(result, message)

    def test_a_connection_that_breaks_on_the_clear_is_a_failure(self):
        # the read of the alias answers, then the call to clear fails without an answer
        result = self.clear(STUB_CLEAR_SSH_FAIL="1")
        self.assertFailedWith(result, "could not clear the request cache", calls=2)


class MakeTarget(EsStub):
    def make(self, **variables):
        """The recipe of the target as make runs it: `$$` is `$`, a variable of the command line is in the environment of the shell
        (make is not in the image of the tests)."""
        lines = (REPO / "infra/k8s/Makefile").read_text().splitlines()
        body = []
        for line in lines[lines.index("search-cache-clear:") + 1:]:
            if not line.startswith("\t"):
                break
            body.append(line[1:].lstrip("@").replace("$$", "$"))
        env = {"PATH": f"{self.bin}:{os.environ['PATH']}", "HOME": self.tmp.name, "STUB_LOG": str(self.log), "STUB_DIR": str(self.stub),
               "DEFAULT_ALIAS_BODY": ALIAS_BODY, "DEFAULT_CLEAR_BODY": CLEAR_BODY, **variables}
        result = None
        for line in body:
            result = subprocess.run(["sh", "-c", line], env=env, cwd=REPO / "infra/k8s", capture_output=True, text=True)
            if result.returncode != 0:
                break
        return result

    def test_the_target_reaches_the_script(self):
        result = self.make(HOST=HOST)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(2, len(self.es_calls()))

    def test_the_target_without_host_fails_and_a_value_with_shell_syntax_is_not_run(self):
        self.assertNotEqual(0, self.make().returncode)
        marker = self.stub / "injected"
        result = self.make(HOST=f"a@b;touch${{IFS}}{marker}")
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(marker.exists())
        self.assertEqual([], self.calls())

    def test_the_target_is_documented_where_it_is_classified(self):
        makefile = (REPO / "infra/k8s/Makefile").read_text()
        self.assertIn("search-cache-clear", makefile.splitlines()[0])
        rules = (REPO / "infra/k8s/CLAUDE.md").read_text()
        self.assertIn("`search-cache-clear`", rules)


if __name__ == "__main__":
    unittest.main()

"""The smoke check of the limits of the request body (docs/features/abuse-limits/spec.md, L8). The check runs inside the
cluster and talks HTTP only, so these tests run it against a fake ingress (a local server), on the way that passes and on
the ways that fail: no 413, the upload path limited too early, a /health that does not answer under stalled bodies."""

import http.server
import importlib.util
import socketserver
import threading
import unittest
from unittest import mock

from support import REPO

spec = importlib.util.spec_from_file_location("smoke_checks", REPO / "infra/k8s/smoke/checks.py")
checks = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checks)

UPLOAD = "/api/v1/admin/product-images"


class FakeIngress(http.server.BaseHTTPRequestHandler):
    """Answers like the ingress of the chart. `limited` is the set of paths that get 413 for a body over 1 MiB."""

    protocol_version = "HTTP/1.1"
    limited = None  # set per test
    health = 200

    def log_message(self, *args):
        pass

    def reply(self, status, close=False):
        self.send_response(status)
        self.send_header("Content-Length", "0")
        if close:
            self.send_header("Connection", "close")
            self.close_connection = True
        self.end_headers()

    def do_GET(self):
        self.reply(self.health if self.path == "/health" else 200)

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        if length > checks.MIB:
            # Like Traefik: refuses by the Content-Length, without reading the body, and closes the connection.
            # BaseHTTPRequestHandler collapses a leading // in self.path; the request line has the path as sent.
            if self.requestline.split()[1] in type(self).limited:
                return self.reply(413, close=True)
            return self.reply(401, close=True)
        try:
            self.rfile.read(length)  # a stalled body holds the connection of the fake ingress, as the buffering does
        except OSError:
            return
        self.reply(400, close=True)


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    request_queue_size = 256


class RequestBodyLimitsCheck(unittest.TestCase):
    def serve(self, limited, health=200):
        handler = type("Handler", (FakeIngress,), {"limited": limited, "health": health})
        server = Server(("127.0.0.1", 0), handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(lambda: (server.shutdown(), server.server_close()))
        patch = mock.patch.object(checks, "TRAEFIK", f"http://127.0.0.1:{server.server_address[1]}")
        patch.start()
        self.addCleanup(patch.stop)

    ALL_LIMITED = {"/api/v1/orders", "/api/checkout", "//api/checkout", "/%61pi/checkout", "/api/../api/checkout", UPLOAD + "/x"}

    def test_passes_when_the_ingress_limits_every_path_but_the_upload(self):
        self.serve(self.ALL_LIMITED)
        checks.check_request_body_limits()

    def test_a_refusal_that_closes_the_connection_before_the_end_of_the_body_is_a_413_not_an_error(self):
        self.serve(self.ALL_LIMITED)
        self.assertEqual(413, checks.post_oversized("/api/v1/orders", "api"))

    def test_fails_when_a_path_is_not_limited(self):
        for missing in sorted(self.ALL_LIMITED):
            with self.subTest(missing=missing):
                self.serve(self.ALL_LIMITED - {missing})
                with self.assertRaises(AssertionError) as raised:
                    checks.check_request_body_limits()
                self.assertIn("413", str(raised.exception))

    def test_fails_when_the_upload_path_is_limited_to_1_mib(self):
        self.serve(self.ALL_LIMITED | {UPLOAD})
        with self.assertRaises(AssertionError) as raised:
            checks.check_request_body_limits()
        self.assertIn("upload path", str(raised.exception))

    def test_fails_when_health_does_not_answer_under_stalled_bodies(self):
        self.serve(self.ALL_LIMITED, health=503)
        with self.assertRaises(AssertionError) as raised:
            checks.check_request_body_limits()
        self.assertIn("/health", str(raised.exception))

    def test_closes_the_stalled_connections_also_when_the_check_fails(self):
        self.serve(self.ALL_LIMITED, health=503)
        opened = []
        original = checks.open_stalled_bodies

        def spy(*args):
            opened.extend(original(*args))
            return opened

        with mock.patch.object(checks, "open_stalled_bodies", spy):
            with self.assertRaises(AssertionError):
                checks.check_request_body_limits()
        self.assertEqual(checks.STALLED_BODIES, len(opened))
        self.assertTrue(all(connection.fileno() == -1 for connection in opened))

    def test_an_unreachable_ingress_is_an_error_and_leaves_no_connection_open(self):
        with mock.patch.object(checks, "TRAEFIK", "http://127.0.0.1:1"):
            with self.assertRaises(OSError):
                checks.open_stalled_bodies("api", 3)

    def test_the_check_is_part_of_basic(self):
        source = (REPO / "infra/k8s/smoke/checks.py").read_text()
        basic = source.split('if command == "basic":')[1].split("elif command ==")[0]
        self.assertIn("check_request_body_limits()", basic)


if __name__ == "__main__":
    unittest.main()

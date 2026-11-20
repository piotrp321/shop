"""The store of load/test/cookies.sh: a server that sets one cookie with Secure and one without, and answers with the Cookie header it got
(NONE without one). Like the store behind a TLS-terminating ingress: it sets Secure cookies and is called over plain HTTP."""

from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        if self.path == "/set":
            self.send_header("Set-Cookie", "secure_c=1; Path=/; HttpOnly; SameSite=Lax; Secure")
            self.send_header("Set-Cookie", "plain_c=1; Path=/; HttpOnly; SameSite=Lax")
        self.end_headers()
        self.wfile.write((self.headers.get("Cookie") or "NONE").encode())

    def log_message(self, *args):
        pass


HTTPServer(("0.0.0.0", 3000), Handler).serve_forever()

"""Local OTLP/HTTP receiver for checks of the telemetry of Claude Code (spec docs/features/telemetry/spec.md, R3).
Python 3.9+, standard library only. Used by probe.py (cases telemetry_export and telemetry_no_content).

  Receiver(forward=None).start()   an HTTP server on 127.0.0.1 with a random port; `endpoint` is its address
                                   http://127.0.0.1:<port>, `requests` a copy of what it got, stop() ends it
                                   (idempotent). Every request, on any method and path, is recorded before the answer
                                   (200, an empty Export*ServiceResponse; 400 for a body that cannot be read; 500 for
                                   an error in the processing), as a dict:
                                     method, path (without the query), content_type, content_encoding (None without
                                     the header; several lines of it joined by ", ", as one line), raw (the body as
                                     received, chunked transfer decoded; when the body cannot be read, the bytes
                                     read until the error), body (raw after Content-Encoding, or None), problem (why
                                     body is None, or None), forwarded (None without forward; the status of the
                                     forward or the error text).
                                   The body is framed by RFC 9112 §6: Transfer-Encoding (all its lines and
                                   comma-separated values) must be exactly chunked and then no Content-Length is
                                   allowed; Content-Length (all lines and values) must be one number, several equal
                                   ones count as that one. Anything else is a 400 with nothing read, except different
                                   Content-Length values: the shortest length is read as the prefix kept in raw.
                                   Headers http.client cannot read whole (a line without a valid field name, such as
                                   "Content-Length : 3", which ends its parsing; an obs-fold line, which it joins to
                                   the line before) are a 400 with nothing read too: they could hide the framing.
                                   chunked is read strictly (RFC 9112 §7.1): a size line of hex digits only (then
                                   optional whitespace and ;extensions) ended by a line end within LINE_LIMIT bytes,
                                   a line end (CRLF or LF) right after the data of each chunk and an empty trailer;
                                   anything else is a 400 with the chunks read until then in raw.
                                   The answer says Connection: close, one request per connection. After the answer
                                   to a request whose body was read (also when the answer cannot be written: a reset,
                                   a sender gone) the receiver shuts its write side and reads what the sender still
                                   put in the connection (an excess after the body, a second request), until its end,
                                   TRAILING_TIMEOUT seconds in all or MAX_BODY bytes; any such bytes are a record of
                                   their own: method and path '', raw the bytes (cut to MAX_BODY), body None, the
                                   problem "bajty po ciele żądania" (", ponad <MAX_BODY> bajtów" when cut), never
                                   forwarded. Bytes after a body that cannot be read, and bytes sent later than
                                   TRAILING_TIMEOUT, are not read.
                                   With `forward` (only http://127.0.0.1:<port>, validated by render_settings.py) each
                                   request, once recorded, goes on (method, path, Content-Type, Content-Encoding, raw
                                   body) to that address and `forwarded` is set when that ends; its failure, whatever
                                   it is, changes nothing else. An error in the
                                   processing of a request (a path or a body it cannot handle) still records it: raw
                                   as far as it was read, body None, a fixed problem (never a header value). A
                                   request http.server rejects itself (an HTTP/2 preface, TLS bytes, a line or a
                                   header too long, too many headers, a malformed request line) is recorded with raw
                                   b'', body None and the problem "żądanie odrzucone przez serwer HTTP: <code>"; a
                                   request line without words (b'\\r\\n', b' \\r\\n'), which it drops without an
                                   answer and closes the connection with what follows, is recorded the same way with
                                   the problem "żądanie odrzucone przez serwer HTTP: pusta linia żądania" and method
                                   and path ''. A HEAD request is answered without a body.
                                   stop() ends the server, then waits for every connection it accepted, counted from
                                   its accept (the request line, the headers, the body and its decompression, the
                                   forward, the answer, the bytes after the body), STOP_TIMEOUT seconds in all, so a
                                   caller judges the requests after stop(). Every connection still open then (also
                                   one whose request is recorded but whose forward, answer or read of the bytes after
                                   the body still runs: those bytes are not read) gets a record with method and path
                                   (once its request line was read, else ''), raw b'', body None and the problem
                                   "żądanie w toku przy zatrzymaniu odbiornika" (never a header value), and is shut.
                                   Nothing is recorded after stop(), so a request whose record stop() wrote never gets
                                   a second one from its handler. The `forwarded` of a forward still running is None
                                   in a copy taken then.
  decompress(raw, encoding)        (body, None) for no Content-Encoding or gzip (every member, joined);
                                   (None, problem) for any other encoding, a body that does not decompress (also
                                   bytes after a member that are not a whole member) or one larger than MAX_BODY.
                                   A problem is a fixed text with at most numbers, never text of the request.
  find_markers(body, markers)      the names of `markers` ({name: value}) whose value is in the raw bytes of body.
  key_values(body)                 the (key, string value) pairs of every KeyValue with a string AnyValue anywhere
                                   in a protobuf message (ExportMetricsServiceRequest, ExportLogsServiceRequest): a
                                   minimal decoder of varint, fixed32, fixed64 and length-delimited fields that tries
                                   each length-delimited field as a nested message. DecodeError when body is not a
                                   whole protobuf message.
  forward_endpoint(url)            url when it is a valid forward address; ValueError otherwise.

Markers are searched in the raw bytes after decompression: protobuf strings are UTF-8, so a leak needs no decoder.
"""
import http.client
import http.server
import importlib.util
import os
import re
import socket
import threading
import time
import urllib.parse
import zlib

HERE = os.path.dirname(os.path.abspath(__file__))
MAX_BODY = 32 * 1024 * 1024        # bytes of a request body, before and after decompression
MAX_DEPTH = 32                     # nesting of messages the decoder follows (OTLP needs about 10)
FORWARD_TIMEOUT = 5
TRAILING_TIMEOUT = 1               # seconds the receiver reads the bytes after a body, in all
# Seconds stop() waits for the connections in progress: a request recorded just before stop() whose forward runs
# into one FORWARD_TIMEOUT (a target that accepts and never answers), then the read of the bytes after its body
# (TRAILING_TIMEOUT), and a second for the answer. A connection still open after it (held by its sender, or with a
# slower forward) gets the record IN_PROGRESS.
STOP_TIMEOUT = FORWARD_TIMEOUT + TRAILING_TIMEOUT + 1
IN_PROGRESS = 'żądanie w toku przy zatrzymaniu odbiornika'
LINE_LIMIT = 1024                  # bytes of a chunk size line and of the trailer line, with the line end
_CHUNK_SIZE = re.compile(rb'([0-9A-Fa-f]+)[ \t]*(?:;[^\r\n]*)?\r?\n\Z')


def _load(name):
    spec = importlib.util.spec_from_file_location('_otlp_receiver_' + name, os.path.join(HERE, name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_settings = _load('render_settings')


class DecodeError(ValueError):
    """The bytes are not a whole protobuf message."""


def forward_endpoint(url):
    """url when it is http://127.0.0.1:<port> (the telemetry endpoint rule of render_settings.py); ValueError
    otherwise."""
    try:
        return _settings.telemetry_endpoint(url, option='--otel-forward')
    except _settings.SettingsError as e:
        raise ValueError(str(e))


# -- body ----------------------------------------------------------------------------------------------------------

def decompress(raw, encoding):
    """(body, None), or (None, the reason the body cannot be checked)."""
    name = (encoding or '').strip()
    if not name:
        return raw, None
    if name.lower() != 'gzip':
        return None, 'nieznane Content-Encoding'   # never the value: a session quotes the problem
    # Every member, like gzip.decompress: a later member must not carry what the check never sees.
    parts, total, rest = [], 0, raw
    while True:
        decoder = zlib.decompressobj(16 + zlib.MAX_WBITS)
        try:
            part = decoder.decompress(rest, MAX_BODY - total + 1)
        except zlib.error as e:
            return None, 'ciało gzip nie daje się rozpakować: %s' % e
        total += len(part)
        if total > MAX_BODY or decoder.unconsumed_tail:
            return None, 'ciało gzip po rozpakowaniu większe niż %d bajtów' % MAX_BODY
        if not decoder.eof:
            return None, 'ciało gzip nie daje się rozpakować: urwany strumień'
        parts.append(part)
        rest = decoder.unused_data
        if not rest:
            return b''.join(parts), None


def find_markers(body, markers):
    """The names of the markers whose UTF-8 value occurs in body, in the order of `markers`."""
    return [name for name, value in markers.items() if value and value.encode('utf-8') in body]


# -- protobuf ------------------------------------------------------------------------------------------------------

def _varint(data, pos):
    result, shift = 0, 0
    while True:
        if pos >= len(data):
            raise DecodeError('urwany varint')
        byte = data[pos]
        pos += 1
        result |= (byte & 0x7f) << shift
        if not byte & 0x80:
            return result, pos
        shift += 7
        if shift > 63:
            raise DecodeError('varint dłuższy niż 10 bajtów')


def _fields(data):
    """[(number, wire type, value)] of one whole message; DecodeError otherwise."""
    fields, pos = [], 0
    while pos < len(data):
        key, pos = _varint(data, pos)
        number, wire = key >> 3, key & 7
        if number == 0:
            raise DecodeError('pole o numerze 0')
        if wire == 0:
            value, pos = _varint(data, pos)
        elif wire in (1, 5):
            size = 8 if wire == 1 else 4
            if pos + size > len(data):
                raise DecodeError('urwane pole stałej długości')
            value, pos = data[pos:pos + size], pos + size
        elif wire == 2:
            size, pos = _varint(data, pos)
            if pos + size > len(data):
                raise DecodeError('długość pola poza końcem wiadomości')
            value, pos = data[pos:pos + size], pos + size
        else:
            raise DecodeError('nieobsługiwany typ pola %d' % wire)
        fields.append((number, wire, value))
    return fields


def _message(data):
    try:
        return _fields(data)
    except DecodeError:
        return None


def _text(data):
    try:
        return data.decode('utf-8')
    except UnicodeDecodeError:
        return None


def _pair(fields):
    """(key, value) when the fields are a KeyValue {key = 1, value = 2} whose AnyValue is a string_value (1)."""
    if len(fields) != 2 or {(n, w) for n, w, _ in fields} != {(1, 2), (2, 2)}:
        return None
    raw = dict((n, v) for n, _, v in fields)
    key, value = _text(raw[1]), _message(raw[2])
    if key is None or value is None or len(value) != 1 or value[0][:2] != (1, 2):
        return None
    text = _text(value[0][2])
    return None if text is None else (key, text)


def key_values(body):
    """Every (key, string value) pair in body; DecodeError when body is not a protobuf message."""
    pairs = []

    def walk(fields, depth):
        pair = _pair(fields)
        if pair is not None:
            pairs.append(pair)
        if depth >= MAX_DEPTH:
            return
        for _, wire, value in fields:
            if wire == 2 and value:
                nested = _message(value)
                if nested:
                    walk(nested, depth + 1)

    walk(_fields(body), 0)
    return pairs


# -- receiver ------------------------------------------------------------------------------------------------------

class _BodyError(Exception):
    """The body cannot be read; `raw` is what was read until then (a prefix of the message)."""

    def __init__(self, reason, raw=b''):
        super().__init__(reason)
        self.raw = raw


def _path_of(target):
    """The path of a request target without the query; for a target urlsplit rejects (http://[/v1/logs) the target up
    to the first ? or #. Never raises: the path is only recorded, probe.py quotes only the telemetry paths."""
    try:
        return urllib.parse.urlsplit(target).path
    except ValueError:
        return target.split('?', 1)[0].split('#', 1)[0]


class _Handler(http.server.BaseHTTPRequestHandler):
    receiver = None
    _recorded = False   # the request being handled is in receiver.requests (set per request, see handle_one_request)

    def setup(self):
        super().setup()
        self.receiver._handling(self.request, self)   # stop() takes the method and path of a request in progress

    def handle_one_request(self):
        self._recorded = False
        self.raw_requestline = b''
        super().handle_one_request()
        if self.raw_requestline and not self._recorded:
            # parse_request drops a request line without words (b'\r\n', b' \r\n') without an answer and closes the
            # connection, with whatever the sender put after it: recorded, so the run is never a pass.
            self._record({'method': '', 'path': '', 'content_type': None, 'content_encoding': None, 'raw': b'',
                          'body': None, 'forwarded': None,
                          'problem': 'żądanie odrzucone przez serwer HTTP: pusta linia żądania'})

    def send_error(self, code, message=None, explain=None):
        """A request http.server rejects itself (an HTTP/2 preface, TLS bytes, a line or a header too long, too many
        headers, a malformed request line) is recorded too: body None, the fixed problem and the code only."""
        if not self._recorded:
            self._record({'method': getattr(self, 'command', None) or '',
                          'path': _path_of(getattr(self, 'path', None) or ''), 'content_type': None,
                          'content_encoding': None, 'raw': b'', 'body': None,
                          'problem': 'żądanie odrzucone przez serwer HTTP: %d' % code, 'forwarded': None})
        try:
            super().send_error(code, message, explain)
        except OSError:   # the sender went away, or stop() shut the connection: there is no one to answer
            pass

    def _record(self, request):
        self._recorded = True
        self.receiver._record(request)

    def __getattr__(self, name):
        """Every method goes through _handle (http.server answers 501 to one without do_<METHOD>, unrecorded)."""
        if name.startswith('do_'):
            return self._handle
        raise AttributeError(name)

    def _values(self, name):
        """The values of every line of the header, split at commas (RFC 9110 §5.3: several lines are one list)."""
        return [value.strip() for line in self.headers.get_all(name, []) for value in line.split(',')]

    def _read_body(self):
        """The body by its framing (RFC 9112 §6): exactly `chunked`, or the one Content-Length of all its lines and
        values, or none. Anything else is ambiguous (a request smuggler's, the message is not read as one): _BodyError
        with nothing read, except conflicting Content-Length values, whose shortest length is part of the message at
        any reading, so those bytes are kept as the prefix. Headers http.client could not read whole are ambiguous
        too: a line without a valid field name ends its parsing (a defect, the rest in the payload) and an obs-fold
        line joins the line before, so either can hide a Content-Length or Transfer-Encoding another reader sees.
        Problems are fixed texts, never a header value."""
        if (self.headers.defects or self.headers.get_payload()
                or any('\r' in str(value) or '\n' in str(value) for value in self.headers.values())):
            raise _BodyError('nieczytelne nagłówki')
        codings, lengths = self._values('Transfer-Encoding'), self._values('Content-Length')
        if codings:
            if [coding.lower() for coding in codings] != ['chunked']:
                raise _BodyError('niedozwolone Transfer-Encoding')
            if lengths:
                raise _BodyError('Transfer-Encoding razem z Content-Length')
            return self._read_chunked()
        try:
            sizes = {int(length) if length.isascii() and length.isdigit() else -1 for length in lengths}
        except ValueError:
            sizes = {-1}
        if -1 in sizes:
            raise _BodyError('nieczytelny Content-Length')
        return self._read_exactly(min(sizes, default=0), len(sizes) > 1)

    def _read_exactly(self, length, conflict):
        if length > MAX_BODY:
            raise _BodyError('ciało większe niż %d bajtów' % MAX_BODY)
        raw = self.rfile.read(length)
        if len(raw) != length:
            raise _BodyError('urwane ciało', raw)
        if conflict:
            raise _BodyError('sprzeczne Content-Length', raw)
        return raw

    def _read_chunked(self):
        """RFC 9112 §7.1, strictly: a size line of hex digits (optional whitespace and ;extensions after them) ended
        by a line end within LINE_LIMIT bytes, a line end (CRLF or LF) right after the data of each chunk, and an
        empty trailer. Anything else is _BodyError with the chunks read until then."""
        chunks, total = [], 0
        while True:
            line = self.rfile.readline(LINE_LIMIT)
            size = _CHUNK_SIZE.match(line)
            if size is None:
                raise _BodyError('nieczytelny rozmiar fragmentu', b''.join(chunks))
            size = int(size.group(1), 16)
            if size == 0:
                if self.rfile.readline(LINE_LIMIT) not in (b'\r\n', b'\n'):
                    raise _BodyError('niepusty albo urwany trailer', b''.join(chunks))
                return b''.join(chunks)
            total += size
            if total > MAX_BODY:
                raise _BodyError('ciało większe niż %d bajtów' % MAX_BODY, b''.join(chunks))
            chunk = self.rfile.read(size)
            chunks.append(chunk)
            if len(chunk) != size:
                raise _BodyError('urwany fragment ciała', b''.join(chunks))
            end = self.rfile.read(1)
            if end == b'\r':
                end += self.rfile.read(1)
            if end not in (b'\r\n', b'\n'):
                raise _BodyError('brak końca linii po fragmencie', b''.join(chunks))

    def _read_after_body(self):
        """The bytes the sender put in the connection after the framed body (an excess, a second request): the answer
        said Connection: close (or could not be written), so the write side is shut and the rest, the read buffer
        first, is read until the end, the TRAILING_TIMEOUT or MAX_BODY. Any bytes are a record of their own (no method nor path: they belong to no
        request read here), never forwarded. Whatever fails, what was read is recorded."""
        pieces, total = [], 0
        try:
            try:
                self.connection.shutdown(socket.SHUT_WR)
            except OSError:
                pass
            deadline = time.monotonic() + TRAILING_TIMEOUT
            while total <= MAX_BODY:
                left = deadline - time.monotonic()
                if left <= 0:
                    break
                self.connection.settimeout(left)
                piece = self.rfile.read1(MAX_BODY + 1 - total)
                if not piece:
                    break
                pieces.append(piece)
                total += len(piece)
        except Exception:   # a timeout, a reset, anything: what was read is still recorded
            pass
        if pieces:
            raw = b''.join(pieces)
            problem = 'bajty po ciele żądania'
            if len(raw) > MAX_BODY:
                raw, problem = raw[:MAX_BODY], 'bajty po ciele żądania, ponad %d bajtów' % MAX_BODY
            self._record({'method': '', 'path': '', 'content_type': None, 'content_encoding': None, 'raw': raw,
                          'body': None, 'problem': problem, 'forwarded': None})

    def _handle(self):
        request = {'method': self.command, 'path': _path_of(self.path), 'content_type': None,
                   'content_encoding': None, 'raw': b'', 'body': None, 'problem': None, 'forwarded': None}
        status, read = 200, False
        try:
            request['content_type'] = self.headers.get('Content-Type')
            encodings = self.headers.get_all('Content-Encoding')    # several lines are one line joined by commas
            request['content_encoding'] = ', '.join(encodings) if encodings else None
            try:
                request['raw'] = self._read_body()
                read = True
            except _BodyError as e:
                request['raw'] = e.raw
                request['problem'] = 'nieczytelne ciało żądania: %s' % e
                status = 400
            else:
                request['body'], request['problem'] = decompress(request['raw'], request['content_encoding'])
        except Exception:   # whatever it is, the request is still recorded and answered; raw stays for the markers
            request['body'], request['problem'] = None, 'nieoczekiwany błąd przy przetwarzaniu żądania'
            status = 500
        # Recorded before the forward: a slow forward never keeps the request from a verdict after stop().
        self._record(request)
        try:
            if read and self.receiver.forward:
                self.receiver._forwarded(request, self.receiver._forward(
                    self.command, self.path, request['content_type'], request['content_encoding'], request['raw']))
            json_body = 'json' in (request['content_type'] or '')
            answer = b'{}' if json_body else b''
            try:
                self.send_response(status)
                self.send_header('Content-Type', 'application/json' if json_body else 'application/x-protobuf')
                self.send_header('Content-Length', str(len(answer)))
                self.send_header('Connection', 'close')
                self.end_headers()
                if self.command != 'HEAD':
                    self.wfile.write(answer)
            except OSError:   # a reset or a closed connection: the bytes after the body may be in the buffer already
                pass
            if read:
                self._read_after_body()
        finally:
            self.close_connection = True

    def log_message(self, *args):
        pass


class _Server(http.server.ThreadingHTTPServer):
    """Counts a connection from its accept, in the thread of serve_forever, before its handler thread exists: once
    shutdown() returned (serve_forever ended), every connection accepted is counted."""
    daemon_threads = True
    receiver = None

    def process_request(self, request, client_address):
        self.receiver._opened(request)
        try:
            super().process_request(request, client_address)
        except BaseException:
            self.receiver._closed(request)
            raise

    def process_request_thread(self, request, client_address):
        try:
            self.finish_request(request, client_address)
        except Exception:
            self.handle_error(request, client_address)
        finally:
            self.receiver._closed(request)   # before the socket is closed: stop() shuts only open sockets
            self.shutdown_request(request)


class Receiver:
    """See the module docstring."""

    def __init__(self, forward=None, forward_timeout=FORWARD_TIMEOUT):
        self.forward = forward_endpoint(forward) if forward is not None else None
        self.forward_timeout = forward_timeout
        self.endpoint = None
        self._requests = []
        self._lock = threading.Lock()
        self._idle = threading.Condition(self._lock)
        self._connections = {}            # socket of each connection in progress -> its handler (None before setup)
        self._stopped = False             # after stop(): no record is added any more
        self._server = None

    def start(self):
        handler = type('OtlpHandler', (_Handler,), {'receiver': self})
        server = type('OtlpServer', (_Server,), {'receiver': self})
        self._stopped = False
        self._server = server(('127.0.0.1', 0), handler)
        self.endpoint = 'http://127.0.0.1:%d' % self._server.server_address[1]
        threading.Thread(target=self._server.serve_forever, daemon=True).start()
        return self

    def stop(self):
        """Ends the server, then waits up to STOP_TIMEOUT for every connection it accepted (reading, decompressing,
        forwarding, answering, reading the bytes after the body). Every connection still open by then gets the record
        IN_PROGRESS (method and path once its request line was read, else ''; raw b'', body None, never a header
        value), also one already recorded: what it still holds (bytes after the body, a second request) is never
        read, so the run is not a pass. Its socket is shut, so its handler ends; no record is added after stop(), so
        a handler never records a request a second time. The `forwarded` of a forward still running stays None in a
        copy of `requests` taken then."""
        server, self._server = self._server, None
        if server is None:
            return
        server.shutdown()
        server.server_close()
        with self._idle:
            self._idle.wait_for(lambda: not self._connections, STOP_TIMEOUT)
            for connection, handler in self._connections.items():
                self._requests.append({
                    'method': getattr(handler, 'command', None) or '',
                    'path': _path_of(getattr(handler, 'path', None) or ''), 'content_type': None,
                    'content_encoding': None, 'raw': b'', 'body': None, 'problem': IN_PROGRESS, 'forwarded': None})
                try:
                    connection.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass
            self._stopped = True

    def __enter__(self):
        return self.start()

    def __exit__(self, *exc):
        self.stop()

    @property
    def requests(self):
        with self._lock:
            return [dict(r) for r in self._requests]

    def _record(self, request):
        with self._lock:
            if self._stopped:   # stop() wrote the record IN_PROGRESS of every connection still open
                return
            self._requests.append(request)

    def _forwarded(self, request, result):
        with self._lock:   # `requests` copies under the same lock
            request['forwarded'] = result

    def _opened(self, connection):
        with self._lock:
            self._connections[connection] = None

    def _handling(self, connection, handler):
        with self._lock:
            if connection in self._connections:
                self._connections[connection] = handler

    def _closed(self, connection):
        with self._idle:
            self._connections.pop(connection, None)
            self._idle.notify_all()

    def _forward(self, method, path, content_type, content_encoding, raw):
        """The status of the forwarded request, or the error text; the handler puts it in `forwarded` of the record
        written before the forward. Whatever fails (a path http.client cannot send too), the answer to the sender and
        the rest of the record do not change; a forward still running when stop() ends leaves `forwarded` None in the
        copy taken then, and stop() adds the record IN_PROGRESS of its connection."""
        connection = None
        try:
            port = int(self.forward.rsplit(':', 1)[1])
            target = path if path.startswith('/') else '/' + path
            forwarded = {'Content-Length': str(len(raw))}
            for name, value in (('Content-Type', content_type), ('Content-Encoding', content_encoding)):
                if value is not None:
                    forwarded[name] = value
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=self.forward_timeout)
            connection.request(method, target, body=raw, headers=forwarded)
            response = connection.getresponse()
            response.read()
            return response.status
        except Exception as e:
            return 'przekazanie do %s nie powiodło się: %s' % (self.forward, e)
        finally:
            if connection is not None:
                connection.close()

"""Local OTLP/HTTP receiver for checks of the telemetry of Claude Code (spec docs/features/telemetry/spec.md, R3).
Python 3.9+, standard library only. Used by probe.py (cases telemetry_export and telemetry_no_content); ao-prepare
copies it next to probe.py.

  Receiver(forward=None).start()   an HTTP server on 127.0.0.1 with a random port; `endpoint` is its address
                                   http://127.0.0.1:<port>, `requests` a copy of what it got, stop() ends it
                                   (idempotent). Every request, on any path, is recorded before the answer (200, an
                                   empty Export*ServiceResponse), as a dict:
                                     method, path (without the query), content_type, content_encoding (None without
                                     the header), raw (the body as received, chunked transfer decoded),
                                     body (raw after Content-Encoding, or None), problem (why body is None, or None),
                                     forwarded (None without forward; the status of the forward or the error text).
                                   With `forward` (only http://127.0.0.1:<port>, validated by render_settings.py) each
                                   request goes on unchanged (method, path, Content-Type, Content-Encoding, raw body)
                                   to that address; its failure changes nothing else.
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
import threading
import urllib.parse
import zlib

HERE = os.path.dirname(os.path.abspath(__file__))
MAX_BODY = 32 * 1024 * 1024        # bytes of a request body, before and after decompression
MAX_DEPTH = 32                     # nesting of messages the decoder follows (OTLP needs about 10)
FORWARD_TIMEOUT = 5


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
        return None, 'nieznane Content-Encoding'   # never the value: the problem reaches the /ao-run session
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
    pass


class _Handler(http.server.BaseHTTPRequestHandler):
    receiver = None

    def _read_body(self):
        if 'chunked' in self.headers.get('Transfer-Encoding', '').lower():
            chunks, total = [], 0
            while True:
                line = self.rfile.readline(1024)
                try:
                    size = int(line.split(b';', 1)[0].strip(), 16)
                except ValueError:
                    raise _BodyError('nieczytelny rozmiar fragmentu')
                if size == 0:
                    while self.rfile.readline(1024) not in (b'\r\n', b'\n', b''):
                        pass
                    return b''.join(chunks)
                total += size
                if size < 0 or total > MAX_BODY:
                    raise _BodyError('ciało większe niż %d bajtów' % MAX_BODY)
                chunk = self.rfile.read(size)
                if len(chunk) != size:
                    raise _BodyError('urwany fragment ciała')
                chunks.append(chunk)
                self.rfile.readline(1024)
        try:
            length = int(self.headers.get('Content-Length') or 0)
        except ValueError:
            raise _BodyError('nieczytelny Content-Length')
        if length < 0 or length > MAX_BODY:
            raise _BodyError('ciało większe niż %d bajtów' % MAX_BODY)
        raw = self.rfile.read(length)
        if len(raw) != length:
            raise _BodyError('urwane ciało')
        return raw

    def _handle(self):
        encoding = self.headers.get('Content-Encoding')
        request = {'method': self.command, 'path': urllib.parse.urlsplit(self.path).path,
                   'content_type': self.headers.get('Content-Type'), 'content_encoding': encoding,
                   'raw': b'', 'body': None, 'problem': None, 'forwarded': None}
        status = 200
        try:
            request['raw'] = self._read_body()
        except _BodyError as e:
            request['problem'] = 'nieczytelne ciało żądania: %s' % e
            status = 400
        else:
            request['body'], request['problem'] = decompress(request['raw'], encoding)
            if self.receiver.forward:
                request['forwarded'] = self.receiver._forward(self.command, self.path, self.headers, request['raw'])
        self.receiver._record(request)
        json_body = 'json' in (request['content_type'] or '')
        answer = b'{}' if json_body else b''
        self.send_response(status)
        self.send_header('Content-Type', 'application/json' if json_body else 'application/x-protobuf')
        self.send_header('Content-Length', str(len(answer)))
        self.send_header('Connection', 'close')
        self.end_headers()
        self.wfile.write(answer)

    do_POST = do_PUT = do_GET = _handle   # noqa: N815 (http.server API)

    def log_message(self, *args):
        pass


class Receiver:
    """See the module docstring."""

    def __init__(self, forward=None, forward_timeout=FORWARD_TIMEOUT):
        self.forward = forward_endpoint(forward) if forward is not None else None
        self.forward_timeout = forward_timeout
        self.endpoint = None
        self._requests = []
        self._lock = threading.Lock()
        self._server = None

    def start(self):
        handler = type('OtlpHandler', (_Handler,), {'receiver': self})
        self._server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), handler)
        self._server.daemon_threads = True
        self.endpoint = 'http://127.0.0.1:%d' % self._server.server_address[1]
        threading.Thread(target=self._server.serve_forever, daemon=True).start()
        return self

    def stop(self):
        server, self._server = self._server, None
        if server is not None:
            server.shutdown()
            server.server_close()

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
            self._requests.append(request)

    def _forward(self, method, path, headers, raw):
        """The status of the forwarded request, or the error text."""
        port = int(self.forward.rsplit(':', 1)[1])
        target = path if path.startswith('/') else '/' + path
        forwarded = {'Content-Length': str(len(raw))}
        for name in ('Content-Type', 'Content-Encoding'):
            if headers.get(name) is not None:
                forwarded[name] = headers.get(name)
        connection = http.client.HTTPConnection('127.0.0.1', port, timeout=self.forward_timeout)
        try:
            connection.request(method, target, body=raw, headers=forwarded)
            response = connection.getresponse()
            response.read()
            return response.status
        except (OSError, http.client.HTTPException) as e:
            return 'przekazanie do %s nie powiodło się: %s' % (self.forward, e)
        finally:
            connection.close()

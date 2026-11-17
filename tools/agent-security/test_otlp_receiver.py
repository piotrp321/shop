"""Tests of otlp_receiver.py without a model and without the telemetry stack:
python3 -m unittest discover -s tools/agent-security -p 'test_*.py'

The OTLP bodies are built by the small protobuf encoder below (the field numbers of opentelemetry-proto
ExportMetricsServiceRequest and ExportLogsServiceRequest), so the decoder is tested on the wire format the exporter
of Claude Code sends, plain and gzip, over a real HTTP connection to the receiver. Other tests (test_probe.py) build
their bodies with the same helpers.
"""
import gzip
import http.client
import importlib.util
import os
import socket
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('otlp_receiver', os.path.join(HERE, 'otlp_receiver.py'))
otlp_receiver = importlib.util.module_from_spec(spec)
spec.loader.exec_module(otlp_receiver)


# -- protobuf encoder (tests only) ------------------------------------------------------------------------------------

def varint(value):
    out = bytearray()
    while True:
        byte = value & 0x7f
        value >>= 7
        if value:
            out.append(byte | 0x80)
        else:
            out.append(byte)
            return bytes(out)


def tag(number, wire):
    return varint(number << 3 | wire)


def delimited(number, payload):
    return tag(number, 2) + varint(len(payload)) + payload


def string(number, text):
    return delimited(number, text.encode('utf-8'))


def key_value(key, value):
    """KeyValue{key = 1, value = 2 AnyValue}: a str is string_value (1), an int int_value (3), a float double_value
    (4, fixed64), a dict kvlist_value (6)."""
    if isinstance(value, str):
        any_value = string(1, value)
    elif isinstance(value, bool) or not isinstance(value, (int, float, dict)):
        raise TypeError(value)
    elif isinstance(value, int):
        any_value = tag(3, 0) + varint(value)
    elif isinstance(value, float):
        any_value = tag(4, 1) + b'\x00' * 8
    else:
        any_value = delimited(6, b''.join(delimited(1, key_value(k, v)) for k, v in value.items()))
    return string(1, key) + delimited(2, any_value)


def resource(attributes):
    return b''.join(delimited(1, key_value(k, v)) for k, v in attributes.items())


def metrics_request(attributes, point_attributes=None, name='claude_code.cost.usage'):
    """ExportMetricsServiceRequest{resource_metrics = 1 {resource = 1, scope_metrics = 2 {scope = 1, metrics = 2
    {name = 1, sum = 7 {data_points = 1 {attributes = 7, time_unix_nano = 3 fixed64, as_double = 4 fixed64}}}}}}."""
    point = (b''.join(delimited(7, key_value(k, v)) for k, v in (point_attributes or {}).items())
             + tag(3, 1) + b'\x01' * 8 + tag(4, 1) + b'\x00' * 7 + b'\x40')
    metric = string(1, name) + delimited(7, delimited(1, point) + tag(2, 0) + varint(1))
    scope = delimited(1, string(1, 'com.anthropic.claude_code') + string(2, '2.1.311')) + delimited(2, metric)
    return delimited(1, delimited(1, resource(attributes)) + delimited(2, scope))


def logs_request(attributes, record_attributes=None, body='claude_code.api_request'):
    """ExportLogsServiceRequest{resource_logs = 1 {resource = 1, scope_logs = 2 {scope = 1, log_records = 2
    {time_unix_nano = 1 fixed64, severity_number = 2, body = 5 AnyValue, attributes = 6}}}}."""
    record = (tag(1, 1) + b'\x02' * 8 + tag(2, 0) + varint(9) + delimited(5, string(1, body))
              + b''.join(delimited(6, key_value(k, v)) for k, v in (record_attributes or {}).items()))
    scope = delimited(1, string(1, 'com.anthropic.claude_code.events')) + delimited(2, record)
    return delimited(1, delimited(1, resource(attributes)) + delimited(2, scope))


ATTRIBUTES = {'service.name': 'claude-code', 'shop.source': 'ao', 'shop.epic': 'probe-1a2b',
              'shop.task': 'PROBE-1A2B-1', 'shop.task_kind': 'plan'}


def post(endpoint, path, body, headers=None, chunked=False):
    """(status, response body) of one POST to the receiver."""
    host, port = endpoint[len('http://'):].split(':')
    connection = http.client.HTTPConnection(host, int(port), timeout=10)
    try:
        headers = dict({'Content-Type': 'application/x-protobuf'}, **(headers or {}))
        if chunked:
            connection.putrequest('POST', path)
            for name, value in headers.items():
                connection.putheader(name, value)
            connection.putheader('Transfer-Encoding', 'chunked')
            connection.endheaders()
            for start in range(0, len(body), 7):
                piece = body[start:start + 7]
                connection.send(b'%x\r\n%s\r\n' % (len(piece), piece))
            connection.send(b'0\r\n\r\n')
        else:
            connection.request('POST', path, body=body, headers=headers)
        response = connection.getresponse()
        return response.status, response.read()
    finally:
        connection.close()


# -- tests ------------------------------------------------------------------------------------------------------------

class KeyValuesTest(unittest.TestCase):
    def test_the_resource_attributes_of_a_metrics_request(self):
        pairs = otlp_receiver.key_values(metrics_request(ATTRIBUTES))
        for pair in ATTRIBUTES.items():
            self.assertIn(pair, pairs)

    def test_the_resource_and_record_attributes_of_a_logs_request(self):
        body = logs_request(ATTRIBUTES, {'event.name': 'api_request', 'model': 'claude-haiku-5-5'})
        pairs = otlp_receiver.key_values(body)
        for pair in list(ATTRIBUTES.items()) + [('event.name', 'api_request'), ('model', 'claude-haiku-5-5')]:
            self.assertIn(pair, pairs)

    def test_only_string_values_are_pairs(self):
        pairs = otlp_receiver.key_values(logs_request({'shop.task': 'T-1'}, {'input_tokens': 120, 'cost': 0.5}))
        self.assertEqual([('shop.task', 'T-1')], [p for p in pairs if p[0] in ('shop.task', 'input_tokens', 'cost')])

    def test_pairs_nested_in_a_kvlist_are_found(self):
        pairs = otlp_receiver.key_values(logs_request({}, {'outer': {'shop.epic': 'nested'}}))
        self.assertIn(('shop.epic', 'nested'), pairs)

    def test_polish_text_is_decoded_as_utf8(self):
        pairs = otlp_receiver.key_values(logs_request({'shop.epic': 'zażółć'}))
        self.assertIn(('shop.epic', 'zażółć'), pairs)

    def test_an_empty_body_is_an_empty_message(self):
        self.assertEqual([], otlp_receiver.key_values(b''))

    def test_a_body_that_is_not_protobuf_is_a_decode_error(self):
        whole = metrics_request(ATTRIBUTES)
        for body in (whole[:-3],                       # a length beyond the end
                     b'\x0a\xff',                      # a varint cut off
                     b'\x08' + b'\xff' * 11,           # a varint longer than 10 bytes
                     b'\x0b\x0c',                      # a group (wire type 3)
                     b'\x00\x01',                      # field number 0
                     b'\x09\x01\x02',                  # fixed64 cut off
                     b'{"resourceLogs": []}'):         # JSON, not protobuf
            with self.assertRaises(otlp_receiver.DecodeError, msg=body):
                otlp_receiver.key_values(body)


class DecompressTest(unittest.TestCase):
    def test_no_encoding_is_the_body_itself(self):
        self.assertEqual((b'abc', None), otlp_receiver.decompress(b'abc', None))
        self.assertEqual((b'abc', None), otlp_receiver.decompress(b'abc', ''))

    def test_gzip_is_decompressed_whatever_the_case_of_the_header(self):
        for encoding in ('gzip', 'GZIP', ' gzip '):
            self.assertEqual((b'abc', None), otlp_receiver.decompress(gzip.compress(b'abc'), encoding))

    def test_an_unknown_encoding_cannot_be_checked(self):
        for encoding in ('br', 'deflate', 'zstd', 'gzip, br', 'identity, gzip'):
            body, problem = otlp_receiver.decompress(b'abc', encoding)
            self.assertIsNone(body, encoding)
            self.assertIn(encoding.strip(), problem)

    def test_a_broken_gzip_body_cannot_be_checked(self):
        for raw in (b'not gzip', gzip.compress(b'abc' * 100)[:-10]):
            body, problem = otlp_receiver.decompress(raw, 'gzip')
            self.assertIsNone(body)
            self.assertIn('gzip', problem)

    def test_a_body_over_the_limit_after_decompression_cannot_be_checked(self):
        raw = gzip.compress(b'\x00' * (otlp_receiver.MAX_BODY + 1))
        body, problem = otlp_receiver.decompress(raw, 'gzip')
        self.assertIsNone(body)
        self.assertTrue(problem)


class FindMarkersTest(unittest.TestCase):
    MARKERS = {'znacznik promptu': 'PROMPT-feed', 'nazwa skryptu': '.agent-probe-cases.sh',
               'kanarek': 'CANARY-123'}

    def test_markers_are_found_in_the_raw_bytes(self):
        body = logs_request(ATTRIBUTES, {'command': 'sh .agent-probe-cases.sh', 'prompt': 'x PROMPT-feed y'})
        self.assertEqual(['znacznik promptu', 'nazwa skryptu'], otlp_receiver.find_markers(body, self.MARKERS))

    def test_a_body_without_markers_has_none(self):
        body = logs_request(ATTRIBUTES, {'prompt': '<REDACTED>', 'prompt_length': 120})
        self.assertEqual([], otlp_receiver.find_markers(body, self.MARKERS))

    def test_a_marker_outside_any_decodable_message_is_still_found(self):
        self.assertEqual(['kanarek'], otlp_receiver.find_markers(b'\xff\x00CANARY-123\x01', self.MARKERS))


class ReceiverTest(unittest.TestCase):
    def receiver(self, **kwargs):
        receiver = otlp_receiver.Receiver(**kwargs).start()
        self.addCleanup(receiver.stop)
        return receiver

    def test_it_listens_on_a_random_loopback_port(self):
        first, second = self.receiver(), self.receiver()
        self.assertRegex(first.endpoint, r'^http://127\.0\.0\.1:[1-9][0-9]*$')
        self.assertNotEqual(first.endpoint, second.endpoint)

    def test_a_plain_and_a_gzip_body_are_recorded_decompressed(self):
        receiver = self.receiver()
        metrics, logs = metrics_request(ATTRIBUTES), logs_request(ATTRIBUTES)
        self.assertEqual(200, post(receiver.endpoint, '/v1/metrics', metrics)[0])
        self.assertEqual(200, post(receiver.endpoint, '/v1/logs', gzip.compress(logs),
                                   {'Content-Encoding': 'gzip'})[0])
        requests = receiver.requests
        self.assertEqual(['/v1/metrics', '/v1/logs'], [r['path'] for r in requests])
        self.assertEqual([metrics, logs], [r['body'] for r in requests])
        self.assertEqual([None, None], [r['problem'] for r in requests])
        self.assertEqual(gzip.compress(logs)[:2], requests[1]['raw'][:2])
        self.assertEqual('gzip', requests[1]['content_encoding'])

    def test_a_chunked_body_is_read_whole(self):
        receiver = self.receiver()
        logs = logs_request(ATTRIBUTES)
        self.assertEqual(200, post(receiver.endpoint, '/v1/logs', gzip.compress(logs), {'Content-Encoding': 'gzip'},
                                   chunked=True)[0])
        self.assertEqual(logs, receiver.requests[0]['body'])

    def test_an_unknown_encoding_is_recorded_without_a_body_and_with_the_problem(self):
        receiver = self.receiver()
        self.assertEqual(200, post(receiver.endpoint, '/v1/logs', b'xyz', {'Content-Encoding': 'br'})[0])
        request = receiver.requests[0]
        self.assertIsNone(request['body'])
        self.assertIn('br', request['problem'])
        self.assertEqual(b'xyz', request['raw'])

    def test_any_path_is_recorded(self):
        receiver = self.receiver()
        post(receiver.endpoint, '/v1/traces?x=1', b'')
        self.assertEqual(['/v1/traces'], [r['path'] for r in receiver.requests])

    def test_requests_are_a_copy(self):
        receiver = self.receiver()
        receiver.requests.append('x')
        self.assertEqual([], receiver.requests)

    def test_stop_closes_the_port_and_is_idempotent(self):
        receiver = otlp_receiver.Receiver().start()
        port = int(receiver.endpoint.rsplit(':', 1)[1])
        receiver.stop()
        receiver.stop()
        with self.assertRaises(OSError):
            socket.create_connection(('127.0.0.1', port), timeout=2).close()

    def test_every_request_is_forwarded_unchanged(self):
        stack = self.receiver()
        receiver = self.receiver(forward=stack.endpoint)
        logs = gzip.compress(logs_request(ATTRIBUTES))
        metrics = metrics_request(ATTRIBUTES)
        post(receiver.endpoint, '/v1/logs', logs, {'Content-Encoding': 'gzip'}, chunked=True)
        post(receiver.endpoint, '/v1/metrics', metrics)
        forwarded = stack.requests
        self.assertEqual([('/v1/logs', logs, 'gzip', 'application/x-protobuf'),
                          ('/v1/metrics', metrics, None, 'application/x-protobuf')],
                         [(r['path'], r['raw'], r['content_encoding'], r['content_type']) for r in forwarded])
        self.assertEqual([200, 200], [r['forwarded'] for r in receiver.requests])
        self.assertEqual([r['body'] for r in forwarded], [r['body'] for r in receiver.requests])

    def test_a_forward_target_that_is_down_does_not_change_what_is_recorded(self):
        stack = otlp_receiver.Receiver().start()
        endpoint = stack.endpoint
        stack.stop()
        receiver = self.receiver(forward=endpoint)
        metrics = metrics_request(ATTRIBUTES)
        self.assertEqual(200, post(receiver.endpoint, '/v1/metrics', metrics)[0])
        request = receiver.requests[0]
        self.assertEqual(metrics, request['body'])
        self.assertIsInstance(request['forwarded'], str)

    def test_without_forward_nothing_is_forwarded(self):
        receiver = self.receiver()
        post(receiver.endpoint, '/v1/metrics', b'')
        self.assertIsNone(receiver.requests[0]['forwarded'])

    def test_the_forward_address_is_only_a_loopback_endpoint(self):
        for bad in ('http://localhost:4318', 'https://127.0.0.1:4318', 'http://127.0.0.1:4318/v1',
                    'http://127.0.0.1:4318/', 'http://127.0.0.1@evil.example:4318', 'http://127.0.0.1.nip.io:4318',
                    'http://[::1]:4318', 'http://127.0.0.1:0', 'http://127.0.0.1:65536', 'http://127.0.0.1',
                    'http://evil.example:4318', 'http://127.0.0.1:4318?x=1', 'http://127.0.0.1:4318#x',
                    'off', '', ' http://127.0.0.1:4318', 'http://127.0.0.1:4318\n'):
            with self.assertRaises(ValueError, msg=bad):
                otlp_receiver.forward_endpoint(bad)
            with self.assertRaises(ValueError, msg=bad):
                otlp_receiver.Receiver(forward=bad)
        self.assertEqual('http://127.0.0.1:4318', otlp_receiver.forward_endpoint('http://127.0.0.1:4318'))


if __name__ == '__main__':
    unittest.main()

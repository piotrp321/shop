"""A receiver of Prometheus remote write for the test of the exporters of Alloy (scripts/exporters.sh): it takes what Alloy sends
(snappy and protobuf), and writes one JSON object per series and push to the file given as the first argument:
{"labels": {"__name__": ..., ...}, "value": ...}. Only the standard library is used; the container is python:3.13-alpine.
Usage: python -I sink.py <output file> [<port>]"""

import http.server
import json
import struct
import sys


def snappy_decompress(data):
    """The block format of Snappy (what remote write sends)."""
    position = 0
    length = 0
    shift = 0
    while True:
        byte = data[position]
        position += 1
        length |= (byte & 0x7F) << shift
        if not byte & 0x80:
            break
        shift += 7
    out = bytearray()
    while position < len(data):
        tag = data[position]
        position += 1
        kind = tag & 3
        if kind == 0:
            size = tag >> 2
            if size >= 60:
                extra = size - 59
                size = int.from_bytes(data[position : position + extra], "little")
                position += extra
            size += 1
            out += data[position : position + size]
            position += size
            continue
        if kind == 1:
            size = ((tag >> 2) & 7) + 4
            offset = ((tag >> 5) << 8) | data[position]
            position += 1
        elif kind == 2:
            size = (tag >> 2) + 1
            offset = int.from_bytes(data[position : position + 2], "little")
            position += 2
        else:
            size = (tag >> 2) + 1
            offset = int.from_bytes(data[position : position + 4], "little")
            position += 4
        for _ in range(size):
            out.append(out[-offset])
    if len(out) != length:
        raise ValueError(f"snappy: {len(out)} bytes, {length} expected")
    return bytes(out)


def fields(data):
    """The (number, wire type, value) of a protobuf message."""
    position = 0
    while position < len(data):
        key = 0
        shift = 0
        while True:
            byte = data[position]
            position += 1
            key |= (byte & 0x7F) << shift
            if not byte & 0x80:
                break
            shift += 7
        number, wire = key >> 3, key & 7
        if wire == 0:
            value = 0
            shift = 0
            while True:
                byte = data[position]
                position += 1
                value |= (byte & 0x7F) << shift
                if not byte & 0x80:
                    break
                shift += 7
        elif wire == 1:
            value = data[position : position + 8]
            position += 8
        elif wire == 2:
            size = 0
            shift = 0
            while True:
                byte = data[position]
                position += 1
                size |= (byte & 0x7F) << shift
                if not byte & 0x80:
                    break
                shift += 7
            value = data[position : position + size]
            position += size
        elif wire == 5:
            value = data[position : position + 4]
            position += 4
        else:
            raise ValueError(f"protobuf: wire type {wire}")
        yield number, wire, value


def series_of(write_request):
    """The series of a WriteRequest: its labels and the last value of its samples."""
    found = []
    for number, _, timeseries in fields(write_request):
        if number != 1:
            continue
        labels = {}
        value = 0.0  # a sample of 0 has no value field in protobuf
        for inner, _, content in fields(timeseries):
            if inner == 1:
                pair = {n: v for n, _, v in fields(content)}
                labels[pair.get(1, b"").decode()] = pair.get(2, b"").decode()
            elif inner == 2:
                for sample_number, _, sample in fields(content):
                    if sample_number == 1:
                        value = struct.unpack("<d", sample)[0]
        found.append({"labels": labels, "value": value})
    return found


def main():
    output = sys.argv[1]
    port = int(sys.argv[2]) if len(sys.argv) > 2 else 8080

    class Handler(http.server.BaseHTTPRequestHandler):
        def do_POST(self):
            body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
            try:
                series = series_of(snappy_decompress(body))
            except Exception as error:  # a body that cannot be read is recorded, and the push is refused
                with open(output, "a") as handle:
                    handle.write(json.dumps({"error": str(error)}) + "\n")
                self.send_response(400)
                self.end_headers()
                return
            with open(output, "a") as handle:
                for one in series:
                    handle.write(json.dumps(one) + "\n")
            self.send_response(204)
            self.end_headers()

        def log_message(self, *args):
            pass

    http.server.ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()


if __name__ == "__main__":
    main()

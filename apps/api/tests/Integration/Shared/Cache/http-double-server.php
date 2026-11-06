<?php

// The server of HttpDouble: php http-double-server.php <port> <dir>. One connection at a time (enough for one client
// that sends its requests in turn), any method (php -S refuses PURGE). Logs every request to <dir>/requests.jsonl and
// answers as <dir>/config.json says: a PURGE is the Varnish side, any other request the store side; "statuses" are
// used in turn (the last one repeats), "delay" is the number of seconds before the answer. It runs until killed.

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];
assert(is_array($arguments) && is_string($arguments[1] ?? null) && is_string($arguments[2] ?? null));
[, $port, $dir] = $arguments;
$server = stream_socket_server('tcp://127.0.0.1:'.$port) ?: exit(1);
/** @var array<'store'|'varnish', array{statuses?: non-empty-list<int>, delay?: float|int}> $config */
$config = json_decode((string) file_get_contents($dir.'/config.json'), true, flags: \JSON_THROW_ON_ERROR);
$served = ['varnish' => 0, 'store' => 0];

// HttpDouble::stop() ends the process while it waits here.
while (false !== ($connection = @stream_socket_accept($server, -1))) {
    $requestLine = (string) fgets($connection);
    if ('' === trim($requestLine)) {
        // A connection without a request: the readiness check of HttpDouble.
        fclose($connection);

        continue;
    }
    [$method, $path] = explode(' ', trim($requestLine)) + ['', ''];
    $headers = [];
    while (false !== ($line = fgets($connection)) && '' !== trim($line)) {
        [$name, $value] = explode(':', $line, 2) + ['', ''];
        $headers[strtolower(trim($name))] = trim($value);
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = $length > 0 ? (string) stream_get_contents($connection, $length) : '';

    $side = 'PURGE' === $method ? 'varnish' : 'store';
    file_put_contents($dir.'/requests.jsonl', json_encode(['side' => $side, 'method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body])."\n", \FILE_APPEND);

    $statuses = $config[$side]['statuses'] ?? [200];
    $status = $statuses[min($served[$side]++, count($statuses) - 1)];
    usleep((int) (($config[$side]['delay'] ?? 0) * 1_000_000));
    @fwrite($connection, "HTTP/1.1 {$status} Status\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    fclose($connection);
}

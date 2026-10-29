<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

use App\Shared\Infrastructure\Health\ConcurrentServiceProbe;
use App\Shared\Infrastructure\Health\ProbeResult;

/**
 * Sends PING over a plain socket and expects +PONG. The connection is opened without waiting for it and PING goes out
 * at once when the connection is made within a few milliseconds (the usual case inside the compose network), so the
 * answer is on its way while other probes run. Waiting for the answer is limited to one second counted from the start;
 * an answer that has already arrived is accepted whatever the time.
 */
final readonly class RedisProbe implements ConcurrentServiceProbe
{
    /** How long start() waits for the connection before it leaves the rest to the collecting call. */
    private const int CONNECT_GRACE_MICROSECONDS = 50_000;

    /** A connection that took longer than the grace gets at least this much time left to be made and answered. */
    private const int LATE_CONNECT_MINIMUM_NANOSECONDS = 250_000_000;

    public function __construct(private string $host, private int $port = 6379)
    {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function start(): \Closure
    {
        $started = hrtime(true);
        $socket = @stream_socket_client(\sprintf('tcp://%s:%d', $this->host, $this->port), $errorCode, $error, self::LIMIT_SECONDS, \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT);
        if (false === $socket) {
            return static fn (): ProbeResult => ProbeResult::down();
        }
        stream_set_blocking($socket, false);
        $sent = self::connect($socket, self::CONNECT_GRACE_MICROSECONDS) && self::ping($socket);

        return static function () use ($socket, $started, $sent): ProbeResult {
            try {
                $deadline = $started + (int) (self::LIMIT_SECONDS * 1_000_000_000);
                if (!$sent) {
                    $deadline = max($deadline, hrtime(true) + self::LATE_CONNECT_MINIMUM_NANOSECONDS);
                    if (!self::connect($socket, max(0, intdiv($deadline - hrtime(true), 1000))) || !self::ping($socket)) {
                        return ProbeResult::down();
                    }
                }

                $answer = '';
                while (!str_contains($answer, "\n")) {
                    $read = [$socket];
                    $write = $except = null;
                    $remaining = max(0, intdiv($deadline - hrtime(true), 1000));
                    if (1 !== @stream_select($read, $write, $except, intdiv($remaining, 1_000_000), $remaining % 1_000_000)) {
                        return ProbeResult::down();
                    }
                    $chunk = @fread($socket, 64);
                    if (false === $chunk || '' === $chunk) {
                        return ProbeResult::down();
                    }
                    $answer .= $chunk;
                }
            } finally {
                fclose($socket);
            }

            return str_starts_with($answer, '+PONG')
                ? ProbeResult::upIn((int) round((hrtime(true) - $started) / 1_000_000))
                : ProbeResult::down();
        };
    }

    public function probe(): ProbeResult
    {
        return ($this->start())();
    }

    /**
     * Waits until the non-blocking connection is made (it is writable then, and has a peer only if it succeeded).
     *
     * @param resource $socket
     */
    private static function connect($socket, int $microseconds): bool
    {
        $read = $except = null;
        $write = [$socket];

        return 1 === @stream_select($read, $write, $except, intdiv($microseconds, 1_000_000), $microseconds % 1_000_000)
            && false !== @stream_socket_get_name($socket, true);
    }

    /**
     * @param resource $socket
     */
    private static function ping($socket): bool
    {
        return false !== @fwrite($socket, "PING\r\n");
    }
}

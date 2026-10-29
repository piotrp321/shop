<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

use App\Shared\Infrastructure\Health\ConcurrentServiceProbe;
use App\Shared\Infrastructure\Health\ProbeResult;

/**
 * Sends PING over a plain socket and expects +PONG. The connection is opened without waiting for it; when it is made
 * and answered within a few milliseconds (the usual case inside the compose network) start() already holds the result
 * and its latency. A slower Redis is waited for when the result is collected, up to one second counted from the start;
 * an answer that has already arrived is accepted whatever the time.
 */
final readonly class RedisProbe implements ConcurrentServiceProbe
{
    /** How long start() waits for the connection, and then for the answer, before it leaves the rest to the collecting call. */
    private const int GRACE_MICROSECONDS = 50_000;

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
        $sent = self::connect($socket, self::GRACE_MICROSECONDS) && self::ping($socket);

        // Redis usually answers within milliseconds: take the answer now and fix its latency here, because the
        // result is collected only after the slower probes and would otherwise report their wait.
        $answer = '';
        $finished = $sent && self::read($socket, self::GRACE_MICROSECONDS, $answer);
        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

        return static function () use ($socket, $started, $sent, $finished, $answer, $latencyMs): ProbeResult {
            try {
                if (!$finished) {
                    // Slow Redis: wait for the rest of the limit counted from the start. The latency is then the time
                    // at which the answer was seen, an upper bound of Redis' own when other probes delayed the collecting.
                    $deadline = $started + (int) (self::LIMIT_SECONDS * 1_000_000_000);
                    if (!$sent) {
                        $deadline = max($deadline, hrtime(true) + self::LATE_CONNECT_MINIMUM_NANOSECONDS);
                        if (!self::connect($socket, self::microsecondsUntil($deadline)) || !self::ping($socket)) {
                            return ProbeResult::down();
                        }
                    }
                    if (!self::read($socket, self::microsecondsUntil($deadline), $answer)) {
                        return ProbeResult::down();
                    }
                    $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
                }
            } finally {
                fclose($socket);
            }

            return str_starts_with($answer, '+PONG') ? ProbeResult::upIn($latencyMs) : ProbeResult::down();
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
     * Reads until the answer line is complete or the connection ends; false when the time ran out first.
     *
     * @param resource $socket
     */
    private static function read($socket, int $microseconds, string &$answer): bool
    {
        $until = hrtime(true) + $microseconds * 1000;
        while (!str_contains($answer, "\n")) {
            $read = [$socket];
            $write = $except = null;
            $remaining = self::microsecondsUntil($until);
            if (1 !== @stream_select($read, $write, $except, intdiv($remaining, 1_000_000), $remaining % 1_000_000)) {
                return false;
            }
            $chunk = @fread($socket, 64);
            if (false === $chunk || '' === $chunk) {
                return true; // closed without a complete answer: finished, and not +PONG
            }
            $answer .= $chunk;
        }

        return true;
    }

    private static function microsecondsUntil(int $deadline): int
    {
        return max(0, intdiv($deadline - hrtime(true), 1000));
    }

    /**
     * @param resource $socket
     */
    private static function ping($socket): bool
    {
        return false !== @fwrite($socket, "PING\r\n");
    }
}

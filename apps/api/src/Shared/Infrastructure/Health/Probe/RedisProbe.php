<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

use App\Shared\Infrastructure\Health\ProbeResult;
use App\Shared\Infrastructure\Health\ServiceProbe;

/**
 * Sends PING over a plain socket and expects +PONG; the whole exchange, connecting included, has one limit.
 */
final readonly class RedisProbe implements ServiceProbe
{
    public function __construct(private string $host, private int $port = 6379)
    {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function probe(): ProbeResult
    {
        $started = hrtime(true);
        $socket = @stream_socket_client(\sprintf('tcp://%s:%d', $this->host, $this->port), $errorCode, $error, self::LIMIT_SECONDS);
        if (false === $socket) {
            return ProbeResult::down();
        }

        try {
            $remaining = max(0.05, self::LIMIT_SECONDS - (hrtime(true) - $started) / 1_000_000_000);
            stream_set_timeout($socket, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000));
            if (false === @fwrite($socket, "PING\r\n")) {
                return ProbeResult::down();
            }
            $answer = @fgets($socket);
        } finally {
            fclose($socket);
        }

        return \is_string($answer) && str_starts_with($answer, '+PONG')
            ? ProbeResult::upIn((int) round((hrtime(true) - $started) / 1_000_000))
            : ProbeResult::down();
    }
}

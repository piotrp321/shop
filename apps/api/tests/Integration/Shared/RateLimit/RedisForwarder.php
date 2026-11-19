<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

/**
 * A Redis that goes down on demand: a process of its own (pcntl_fork) listens on a local address and forwards every
 * connection to the real Redis; stop() kills it, so the connections through it break and new ones are refused, as
 * when Redis itself goes down. Start it before the kernel of the test opens any connection: the process inherits the
 * memory of the test and never runs its destructors (it ends with SIGKILL).
 */
final class RedisForwarder
{
    private ?int $pid = null;

    private function __construct()
    {
    }

    public static function start(string $listen, string $redis): self
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            throw new \RuntimeException('The forwarder needs the pcntl and posix extensions.');
        }
        $server = @stream_socket_server('tcp://'.$listen, $code, $message);
        if (false === $server) {
            throw new \RuntimeException(\sprintf('Cannot listen on %s: %s.', $listen, $message));
        }

        gc_collect_cycles();
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('Cannot fork the forwarder.');
        }
        if (0 === $pid) {
            self::forward($server, $redis);
        }
        // Only the process of the forwarder listens: once it is killed, a connection is refused.
        fclose($server);
        $forwarder = new self();
        $forwarder->pid = $pid;

        return $forwarder;
    }

    public function stop(): void
    {
        if (null === $this->pid) {
            return;
        }
        posix_kill($this->pid, \SIGKILL);
        pcntl_waitpid($this->pid, $status);
        $this->pid = null;
    }

    /**
     * Never returns.
     *
     * @param resource $server
     */
    private static function forward($server, string $redis): void
    {
        ini_set('display_errors', '0');
        register_shutdown_function(static function (): void {
            posix_kill(posix_getpid(), \SIGKILL);
        });
        /** @var array<int, resource> $peers socket id => the socket at the other end */
        $peers = [];
        /** @var array<int, resource> $sockets */
        $sockets = [];
        while (true) {
            $read = [$server, ...array_values($sockets)];
            $write = $except = null;
            if (false === @stream_select($read, $write, $except, 1)) {
                continue;
            }
            foreach ($read as $socket) {
                if ($socket === $server) {
                    $client = @stream_socket_accept($server, 1);
                    $upstream = @stream_socket_client('tcp://'.$redis, $code, $message, 1);
                    if (false === $client || false === $upstream) {
                        continue;
                    }
                    $sockets[(int) $client] = $client;
                    $sockets[(int) $upstream] = $upstream;
                    $peers[(int) $client] = $upstream;
                    $peers[(int) $upstream] = $client;
                    continue;
                }
                $data = @fread($socket, 65536);
                $peer = $peers[(int) $socket] ?? null;
                if (false === $data || '' === $data || null === $peer) {
                    foreach ([$socket, $peer] as $end) {
                        if (\is_resource($end)) {
                            unset($sockets[(int) $end], $peers[(int) $end]);
                            fclose($end);
                        }
                    }
                    continue;
                }
                @fwrite($peer, $data);
            }
        }
    }
}

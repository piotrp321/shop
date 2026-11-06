<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Cache;

use PHPUnit\Framework\Assert;

/**
 * A real HTTP server (a PHP process on a free local port) standing in for Varnish and the store: it records every
 * request and answers with the statuses and the delay it is configured with (see http-double-server.php).
 */
final class HttpDouble
{
    /** @var resource */
    private $process;

    private function __construct(private readonly string $dir, public readonly string $url, mixed $process)
    {
        \assert(\is_resource($process));
        $this->process = $process;
    }

    /**
     * @param array{varnish?: array{statuses?: list<int>, delay?: float}, store?: array{statuses?: list<int>, delay?: float}} $config
     */
    public static function start(array $config = []): self
    {
        $dir = sys_get_temp_dir().'/http-double-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir.'/config.json', json_encode($config + ['varnish' => [], 'store' => []], \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT));
        $port = self::freePort();
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/http-double-server.php', (string) $port, $dir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        Assert::assertIsResource($process, 'The HTTP double did not start.');
        $double = new self($dir, 'http://127.0.0.1:'.$port, $process);
        $double->awaitListening($port);

        return $double;
    }

    /**
     * A URL nothing listens on: every request is refused.
     */
    public static function refusingUrl(): string
    {
        return 'http://127.0.0.1:'.self::freePort();
    }

    /**
     * @return list<array{side: string, method: string, path: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $log = $this->dir.'/requests.jsonl';
        if (!is_file($log)) {
            return [];
        }

        return array_map(static function (string $line): array {
            /** @var array{side: string, method: string, path: string, headers: array<string, string>, body: string} $request */
            $request = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

            return $request;
        }, file($log, \FILE_IGNORE_NEW_LINES) ?: []);
    }

    /**
     * @return list<string> the side of every request, in order
     */
    public function sides(): array
    {
        return array_column($this->requests(), 'side');
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        Assert::assertNotFalse($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        Assert::assertIsString($name);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function awaitListening(int $port): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $error, 0.1);
            if (false !== $connection) {
                fclose($connection);

                return;
            }
            usleep(20_000);
        }
        Assert::fail('The HTTP double did not start listening.');
    }
}

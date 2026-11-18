<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Depends;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The pool is an archive of about 1.6 GB: its download takes minutes, so the client of the pool must not carry the limit
 * of the shared HTTP client (framework.yaml: 5 s per request, for the calls that must not hang). The endpoint here is a
 * local server that sends the body slowly (a few bytes a second, 7 s in all), longer than that limit but never idle for long.
 */
final class DemoPhotosDownloadDurationTest extends KernelTestCase
{
    use PointsPhotoVariables {
        // The trait puts the variables back and calls KernelTestCase::tearDown(); this class stops its server first.
        tearDown as private restorePhotoVariables;
    }

    /** @var resource|null */
    private $server;
    private string $router = '';
    /** @var array<string, array{string|false, array{bool, mixed}, array{bool, mixed}}> the variables before the first test of the class */
    private static array $before = [];

    public static function setUpBeforeClass(): void
    {
        self::$before = self::variables();
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ('' !== $this->router) {
            @unlink($this->router);
        }
        $this->restorePhotoVariables();
    }

    public function testTheClientOfThePoolReadsABodyThatTakesLongerThanTheLimitOfTheSharedClient(): void
    {
        $port = $this->startSlowServer();
        $this->point('DEMO_PHOTOS_REGION', 'auto');
        $this->point('DEMO_PHOTOS_ENDPOINT', 'http://127.0.0.1:'.$port);
        $this->point('DEMO_PHOTOS_BUCKET', 'pool');
        $this->point('DEMO_PHOTOS_ACCESS_KEY_ID', 'key');
        $this->point('DEMO_PHOTOS_SECRET_ACCESS_KEY', 'secret');
        $reader = self::getContainer()->get('test.demo_photos.storage');
        self::assertInstanceOf(FilesystemOperator::class, $reader);

        $started = microtime(true);
        $body = stream_get_contents($reader->readStream('demo-photos/v1/photos.tar'));

        self::assertSame(str_repeat('x', 7), $body);
        self::assertGreaterThan(6.0, microtime(true) - $started, 'The body must have taken longer than the 5 s of the shared client.');
    }

    #[Depends('testTheClientOfThePoolReadsABodyThatTakesLongerThanTheLimitOfTheSharedClient')]
    public function testTheVariablesOfThePreviousTestAreGone(): void
    {
        self::assertSame(self::$before, self::variables(), 'The test must leave the environment, $_ENV and $_SERVER as they were.');
    }

    /**
     * @return array<string, array{string|false, array{bool, mixed}, array{bool, mixed}}>
     */
    private static function variables(): array
    {
        $variables = [];
        foreach (['DEMO_PHOTOS_REGION', 'DEMO_PHOTOS_ENDPOINT', 'DEMO_PHOTOS_BUCKET', 'DEMO_PHOTOS_ACCESS_KEY_ID', 'DEMO_PHOTOS_SECRET_ACCESS_KEY'] as $name) {
            $variables[$name] = [
                getenv($name),
                [\array_key_exists($name, $_ENV), $_ENV[$name] ?? null],
                [\array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null],
            ];
        }

        return $variables;
    }

    private function startSlowServer(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, (string) $error);
        $port = (int) substr((string) stream_socket_get_name($socket, false), (int) strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $this->router = sys_get_temp_dir().'/demo-photos-slow-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($this->router, <<<'PHP'
            <?php
            header('Content-Type: application/x-tar');
            header('Content-Length: 7');
            for ($i = 0; $i < 7; ++$i) {
                echo 'x';
                flush();
                sleep(1);
            }
            PHP);
        $server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.$port, $this->router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($server);
        $this->server = $server;
        for ($try = 0; $try < 50; ++$try) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (false !== $connection) {
                fclose($connection);

                return $port;
            }
            usleep(100_000);
        }
        self::fail('The local server did not start.');
    }
}

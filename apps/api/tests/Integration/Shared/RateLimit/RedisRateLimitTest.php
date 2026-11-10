<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The rate limits on a real Redis (spec: docs/features/hardening/spec.md, D1 and D7): the state is in Redis, not in
 * the process; without Redis a request passes without a limit within the timeouts of the DSN (1 s to connect, 1 s to
 * read), and the failure is a `warning` of the `cache` channel. The `test` limits (config/packages/rate_limiter.yaml).
 */
final class RedisRateLimitTest extends WebTestCase
{
    private const int ORDER_LIMIT = 10;

    /** Connect and read timeouts of the DSN, in seconds. */
    private const float DSN_TIMEOUT = 1.0;

    /** The pool is read twice and written once for each request (CacheStorage of the rate limiter). */
    private const int POOL_CALLS = 3;

    private ?string $redisHost = null;

    /** @var resource|null */
    private $silentRedis;

    protected static function getKernelClass(): string
    {
        return RedisPoolKernel::class;
    }

    protected function tearDown(): void
    {
        if (null !== $this->redisHost) {
            unset($_SERVER['REDIS_HOST'], $_ENV['REDIS_HOST']);
            putenv('REDIS_HOST='.$this->redisHost);
        }
        if (\is_resource($this->silentRedis)) {
            fclose($this->silentRedis);
        }
        parent::tearDown();
    }

    public function testTheLimitHoldsInRedisAcrossKernels(): void
    {
        // An address of its own for every run: the counters outlive the test in Redis.
        $address = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
        $client = $this->client();
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            self::assertSame(422, $this->placeFrom($client, $address));
        }

        self::ensureKernelShutdown();
        $client = $this->client();

        self::assertSame(429, $this->placeFrom($client, $address), 'a new kernel (a new process) sees the counter');
        self::assertSame(422, $this->placeFrom($client, '10.0.0.0'));
    }

    public function testWithoutRedisTheRequestPassesWithoutALimitAndTheCacheChannelWarns(): void
    {
        $this->redisAt('127.0.0.1'); // nothing listens on 6379 there: the connection is refused
        $client = $this->client();
        $logs = $this->cacheLogs();

        for ($attempt = 1; $attempt <= self::ORDER_LIMIT + 1; ++$attempt) {
            $started = hrtime(true);
            self::assertSame(422, $this->placeFrom($client, '198.51.100.1'), 'the request reaches the controller');
            self::assertLessThan(self::POOL_CALLS * 2 * self::DSN_TIMEOUT, (hrtime(true) - $started) / 1e9);
        }

        self::assertTrue($logs->hasWarningThatContains('Failed to fetch key'), 'the read of the pool warns');
        self::assertTrue($logs->hasWarningThatContains('Failed to save key'), 'the write of the pool warns');
        foreach ($logs->getRecords() as $record) {
            self::assertSame('cache', $record->channel);
        }
    }

    public function testASilentRedisHoldsTheRequestOnlyForTheTimeoutsOfTheDsn(): void
    {
        // Accepts connections (the backlog of the socket) and never answers.
        $server = @stream_socket_server('tcp://127.0.0.1:6379', $code, $message);
        if (false === $server) {
            self::fail(\sprintf('Port 6379 of the API container is taken: %s.', $message));
        }
        $this->silentRedis = $server;
        $this->redisAt('127.0.0.1');
        $client = $this->client();
        $logs = $this->cacheLogs();

        $started = hrtime(true);
        self::assertSame(422, $this->placeFrom($client, '198.51.100.1'));
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertGreaterThanOrEqual(self::DSN_TIMEOUT, $seconds, 'the read timeout was waited for');
        self::assertLessThan(self::POOL_CALLS * 2 * self::DSN_TIMEOUT, $seconds, 'at most the connect and read timeout of each call of the pool');
        self::assertTrue($logs->hasWarningThatContains('Failed to fetch key'));
        self::assertTrue($logs->hasRecordThatPasses(static fn ($record): bool => Level::Warning === $record->level && 'cache' === $record->channel, Level::Warning));
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function placeFrom(KernelBrowser $client, string $address): int
    {
        $client->request('POST', '/api/v1/orders', server: ['REMOTE_ADDR' => $address, 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'redis-limit'], content: '{}');

        return $client->getResponse()->getStatusCode();
    }

    private function redisAt(string $host): void
    {
        $current = $_SERVER['REDIS_HOST'] ?? $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST');
        $this->redisHost = \is_string($current) ? $current : '';
        $_SERVER['REDIS_HOST'] = $_ENV['REDIS_HOST'] = $host;
        putenv('REDIS_HOST='.$host);
    }

    private function cacheLogs(): TestHandler
    {
        $logger = static::getContainer()->get('monolog.logger.cache');
        self::assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler(Level::Debug);
        $logger->pushHandler($handler);

        return $handler;
    }
}

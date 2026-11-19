<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The rate limits on a real Redis (spec: docs/features/hardening/spec.md, D1 and D7; docs/features/abuse-limits/spec.md,
 * D1): the state is in Redis, not in the process; without Redis a request passes without a limit, and the failure is a
 * `warning` of the `cache` channel. The lock of the key is the first call to Redis: when it fails, the counter is not
 * called, so the request takes at most the timeouts of the DSN (1 s to connect, 1 s to read) longer. A Redis that goes
 * down while the lock is held fails the counter and the release, each a warning, and the request still passes. The
 * `test` limits (config/packages/rate_limiter.yaml).
 */
final class RedisRateLimitTest extends WebTestCase
{
    private const int ORDER_LIMIT = 10;

    /** Connect and read timeouts of the DSN, in seconds. */
    private const float DSN_TIMEOUT = 1.0;

    /** Something above the work of the request itself, in seconds. */
    private const float MARGIN = 0.5;

    private const string LOCK_FAILED = 'Failed to take the lock of the rate limit';
    private const string RELEASE_FAILED = 'Failed to release the lock of the rate limit';

    private ?string $redisHost = null;

    /** @var resource|null */
    private $silentRedis;

    private ?RedisForwarder $forwarder = null;

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
        $this->forwarder?->stop();
        InterruptibleStorage::$beforeFetch = null;
        parent::tearDown();
    }

    public function testTheLimitHoldsInRedisAcrossKernels(): void
    {
        $address = self::randomAddress();
        $client = $this->client();
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            self::assertSame(422, $this->placeFrom($client, $address));
        }

        self::ensureKernelShutdown();
        $client = $this->client();

        self::assertSame(429, $this->placeFrom($client, $address), 'a new kernel (a new process) sees the counter');
        self::assertSame(422, $this->placeFrom($client, '10.0.0.0'));
    }

    public function testWithoutRedisTheRequestPassesWithoutALimitWithoutCallingTheCounterAndTheCacheChannelWarns(): void
    {
        $this->redisAt('127.0.0.1'); // nothing listens on 6379 there: the connection is refused
        $client = $this->client();
        $logs = $this->cacheLogs();

        for ($attempt = 1; $attempt <= self::ORDER_LIMIT + 1; ++$attempt) {
            $started = hrtime(true);
            self::assertSame(422, $this->placeFrom($client, '198.51.100.1'), 'the request reaches the controller');
            self::assertLessThan(2 * self::DSN_TIMEOUT + self::MARGIN, (hrtime(true) - $started) / 1e9);
        }

        self::assertTrue($logs->hasWarningThatContains(self::LOCK_FAILED), 'the lock warns');
        self::assertFalse($logs->hasWarningThatContains('Failed to fetch key'), 'after a failed lock the counter is not read');
        self::assertFalse($logs->hasWarningThatContains('Failed to save key'), 'nor written');
        foreach ($logs->getRecords() as $record) {
            self::assertSame('cache', $record->channel);
        }
    }

    public function testASilentRedisHoldsTheRequestOnlyForTheTimeoutsOfTheDsnOnce(): void
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
        self::assertLessThan(2 * self::DSN_TIMEOUT + self::MARGIN, $seconds, 'at most the connect and the read timeout of the DSN, once');
        self::assertTrue($logs->hasWarningThatContains(self::LOCK_FAILED));
        self::assertFalse($logs->hasWarningThatContains('Failed to fetch key'), 'the counter is not called');
        self::assertTrue($logs->hasRecordThatPasses(static fn ($record): bool => Level::Warning === $record->level && 'cache' === $record->channel, Level::Warning));
    }

    public function testARedisThatGoesDownWhileTheLockIsHeldLetsTheRequestPassAndWarns(): void
    {
        $redis = $_SERVER['REDIS_HOST'] ?? $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST');
        self::assertIsString($redis);
        // Before the kernel opens any connection: the forwarder inherits the memory of the test.
        $this->forwarder = $forwarder = RedisForwarder::start('127.0.0.1:6379', $redis.':6379');
        $this->redisAt('127.0.0.1');
        $client = $this->client();
        $logs = $this->cacheLogs();
        InterruptibleStorage::$beforeFetch = static function () use ($forwarder): void {
            $forwarder->stop(); // after the lock is taken, before the counter is read
        };

        $started = hrtime(true);
        self::assertSame(422, $this->placeFrom($client, self::randomAddress()), 'the request passes, not 500');

        self::assertNull(InterruptibleStorage::$beforeFetch, 'the counter was read after the lock');
        self::assertLessThan(2 * self::DSN_TIMEOUT + self::MARGIN, (hrtime(true) - $started) / 1e9);
        self::assertTrue($logs->hasWarningThatContains('Failed to fetch key'), 'the counter fails open');
        self::assertTrue($logs->hasWarningThatContains(self::RELEASE_FAILED), 'the release warns');
        self::assertFalse($logs->hasWarningThatContains(self::LOCK_FAILED), 'the lock itself was taken');
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

    /**
     * An address of its own for every run: the counters outlive the test in Redis.
     */
    private static function randomAddress(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
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

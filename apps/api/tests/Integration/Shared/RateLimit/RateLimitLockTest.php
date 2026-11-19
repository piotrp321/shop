<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use App\Tests\Integration\Ordering\Concurrency\ParallelProcesses;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

/**
 * The lock of the key of a limiter on a real Redis (spec: docs/features/abuse-limits/spec.md, D1 and L1): parallel
 * requests, each in a process of its own (as on several replicas of the API), pass at most the limit; a key held by
 * another request for longer than 300 ms refuses the request with a 429 after about 300 ms, while another key does
 * not wait; and a lock that its holder abandoned stops blocking after 5 s. The `test` limit of order_placement (10 in
 * 15 minutes, config/packages/rate_limiter.yaml) on the Redis of production (RedisPoolKernel).
 */
final class RateLimitLockTest extends WebTestCase
{
    private const int ORDER_LIMIT = 10;
    private const int PROCESSES = 24;
    private const int REQUESTS_PER_PROCESS = 3;

    /** How long a request waits for a held lock, and how long an abandoned lock blocks its key, in seconds. */
    private const float WAIT = 0.3;
    private const float LOCK_TTL = 5.0;

    private const string TOO_MANY = 'Zbyt wiele prób. Spróbuj ponownie za kilka minut.';

    private ?Connection $control = null;

    protected static function getKernelClass(): string
    {
        return RedisPoolKernel::class;
    }

    protected function tearDown(): void
    {
        $this->control?->close();
        parent::tearDown();
    }

    public function testParallelRequestsFromOneAddressPassAtMostTheLimit(): void
    {
        $address = self::randomAddress();
        $participants = array_fill(0, self::PROCESSES, static function () use ($address): \Closure {
            $kernel = new RedisPoolKernel('test', false);
            $kernel->boot();
            $client = $kernel->getContainer()->get('test.client');
            \assert($client instanceof KernelBrowser);
            $client->disableReboot();

            return static function () use ($client, $address): string {
                $statuses = [];
                for ($request = 1; $request <= self::REQUESTS_PER_PROCESS; ++$request) {
                    $statuses[] = self::placeFrom($client, $address);
                }

                return implode(',', $statuses);
            };
        });

        // A request of the kernel of the test writes its caches (the routes) before the participants read them.
        self::assertSame(422, self::placeFrom($this->client(), self::randomAddress()));
        $outcomes = ParallelProcesses::run($this->control(), $participants);

        $statuses = array_merge(...array_map(static fn (string $outcome): array => explode(',', $outcome), $outcomes));
        self::assertSame([], array_values(array_diff($statuses, ['422', '429'])), 'every request is either let through (422) or refused (429): '.implode('; ', $outcomes));
        $passed = \count(array_keys($statuses, '422', true));
        self::assertLessThanOrEqual(self::ORDER_LIMIT, $passed, \sprintf('%d of %d parallel requests passed a limit of %d', $passed, \count($statuses), self::ORDER_LIMIT));
        self::assertGreaterThan(0, $passed, 'the limit lets requests through');
    }

    public function testAKeyHeldLongerThanTheWaitIsRefusedAfterTheWaitAndAnotherKeyDoesNotWait(): void
    {
        $address = self::randomAddress();
        $client = $this->client();
        $held = new Key('order_placement-guest:'.$address);
        $store = new RedisStore(self::redis(), 30.0);
        $store->save($held); // another request holds the lock of the key (a stopped process)

        try {
            $started = hrtime(true);
            $status = self::placeFrom($client, $address);
            $waited = (hrtime(true) - $started) / 1e9;

            self::assertSame(429, $status, 'a held lock never opens the limit');
            self::assertSame(['type' => 'about:blank', 'title' => 'Too Many Requests', 'status' => 429, 'detail' => self::TOO_MANY], json_decode((string) $client->getResponse()->getContent(), true));
            self::assertSame('1', $client->getResponse()->headers->get('Retry-After'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertGreaterThanOrEqual(self::WAIT, $waited, 'the request waited for the lock');
            self::assertLessThan(self::WAIT + 0.3, $waited, 'at most about 300 ms');

            $started = hrtime(true);
            self::assertSame(422, self::placeFrom($client, self::randomAddress()));
            self::assertLessThan(self::WAIT, (hrtime(true) - $started) / 1e9, 'another key does not wait');
        } finally {
            $store->delete($held);
        }

        self::assertSame(422, self::placeFrom($client, $address), 'the released key is open again and the refusal used nothing');
    }

    public function testAnAbandonedLockStopsBlockingItsKeyAfterFiveSeconds(): void
    {
        $address = self::randomAddress();
        $client = $this->client();
        $locks = static::getContainer()->get('rate_limiter.lock_factory');
        self::assertInstanceOf(LockFactory::class, $locks);
        $resource = 'order_placement-guest:'.$address;

        // The holder takes the lock as a limiter does and never releases it (its process was killed).
        $abandoned = $locks->createLock($resource);
        $abandoned->acquire(true);
        $takenAt = microtime(true);
        $ttl = self::redis()->pTtl($resource);
        self::assertIsInt($ttl);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual((int) (self::LOCK_TTL * 1000), $ttl, 'the lock lives at most 5 s');

        self::assertSame(429, self::placeFrom($client, $address), 'the abandoned lock still blocks the key');

        usleep(max(0, (int) (($takenAt + self::LOCK_TTL + 0.1 - microtime(true)) * 1e6)));
        self::assertSame(422, self::placeFrom($client, $address), 'after 5 s the key is free');
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /**
     * A connection of its own to the shop_test database for the start barrier of ParallelProcesses.
     */
    private function control(): Connection
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();

        return $this->control = DriverManager::getConnection($params, new DbalConfiguration());
    }

    private static function placeFrom(KernelBrowser $client, string $address): int
    {
        $client->request('POST', '/api/v1/orders', server: ['REMOTE_ADDR' => $address, 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'lock-limit'], content: '{}');

        return $client->getResponse()->getStatusCode();
    }

    /**
     * An address of its own for every run: the counters and locks outlive the test in Redis.
     */
    private static function randomAddress(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }

    private static function redis(): \Redis
    {
        $host = $_SERVER['REDIS_HOST'] ?? $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST');
        \assert(\is_string($host) && '' !== $host);
        $redis = RedisAdapter::createConnection('redis://'.$host.':6379?timeout=1&read_timeout=1');
        \assert($redis instanceof \Redis);

        return $redis;
    }
}

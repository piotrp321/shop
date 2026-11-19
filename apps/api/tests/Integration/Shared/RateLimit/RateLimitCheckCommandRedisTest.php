<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * rate-limit:check on a real Redis (spec: docs/features/abuse-limits/spec.md, L12, D16): with the Redis of the limiters it
 * is exit code 0 and changes no key; against a Redis that refuses connections or does not answer it ends with a code other
 * than 0 within the timeouts of the DSN of the limiters (1 s to connect, 1 s to read).
 */
final class RateLimitCheckCommandRedisTest extends WebTestCase
{
    private const int ORDER_LIMIT = 10;

    /** Connect and read timeouts of the DSN, in seconds. */
    private const float DSN_TIMEOUT = 1.0;

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

    public function testItEndsWithZeroAndChangesNoKeyAndNoCounterOfALimiter(): void
    {
        $address = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
        $client = $this->client();
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            self::assertSame(422, $this->placeFrom($client, $address));
        }
        $redis = $this->redis();
        $before = $this->dump($redis);
        self::assertNotSame([], $before, 'the counter of the limiter is in Redis');

        $tester = $this->command();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertSame($before, $this->dump($redis), 'no key written, none changed, the control key deleted');
        // The counter went on from the three requests: seven more are accepted, the eighth of them is the last.
        for ($attempt = 4; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            self::assertSame(422, $this->placeFrom($client, $address));
        }
        self::assertSame(429, $this->placeFrom($client, $address));
    }

    public function testARedisThatRefusesConnectionsEndsWithAnExitCodeOtherThanZeroWithinTheTimeoutsOfTheDsn(): void
    {
        $this->redisAt('127.0.0.1'); // nothing listens on 6379 there: the connection is refused
        $tester = $this->command();

        $started = hrtime(true);
        $exitCode = $tester->execute([]);
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertNotSame(0, $exitCode, $tester->getDisplay());
        self::assertLessThan(2 * self::DSN_TIMEOUT, $seconds);
        self::assertStringContainsString('Redis', $tester->getDisplay());
    }

    public function testARedisThatDoesNotAnswerEndsWithAnExitCodeOtherThanZeroAfterTheReadTimeoutOfTheDsn(): void
    {
        // Accepts connections (the backlog of the socket) and never answers.
        $server = @stream_socket_server('tcp://127.0.0.1:6379', $code, $message);
        if (false === $server) {
            self::fail(\sprintf('Port 6379 of the API container is taken: %s.', $message));
        }
        $this->silentRedis = $server;
        $this->redisAt('127.0.0.1');
        $tester = $this->command();

        $started = hrtime(true);
        $exitCode = $tester->execute([]);
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertNotSame(0, $exitCode, $tester->getDisplay());
        self::assertGreaterThanOrEqual(self::DSN_TIMEOUT, $seconds, 'the read timeout was waited for');
        self::assertLessThan(2 * self::DSN_TIMEOUT + 1, $seconds, 'no more than the connect and the read timeout of the DSN');
    }

    private function command(): CommandTester
    {
        $kernel = self::$kernel ?? $this->client()->getKernel();

        return new CommandTester(new Application($kernel)->find('rate-limit:check'));
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function placeFrom(KernelBrowser $client, string $address): int
    {
        $client->request('POST', '/api/v1/orders', server: ['REMOTE_ADDR' => $address, 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'rate-limit-check'], content: '{}');

        return $client->getResponse()->getStatusCode();
    }

    private function redis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect((string) getenv('REDIS_HOST'), 6379, 1.0);

        return $redis;
    }

    /**
     * @return array<string, string>
     */
    private function dump(\Redis $redis): array
    {
        $dump = [];
        foreach ($redis->keys('*') ?: [] as $key) {
            $dump[$key] = (string) $redis->dump($key);
        }
        ksort($dump);

        return $dump;
    }

    private function redisAt(string $host): void
    {
        $current = $_SERVER['REDIS_HOST'] ?? $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST');
        $this->redisHost = \is_string($current) ? $current : '';
        $_SERVER['REDIS_HOST'] = $_ENV['REDIS_HOST'] = $host;
        putenv('REDIS_HOST='.$host);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The limits and the pool as the `prod` and `dev` containers are built from config/ (spec:
 * docs/features/hardening/spec.md, D1, D5 and D7): sliding windows with the values of the specification and no lock,
 * the pool on Redis with timeouts of 1 s, high limits for the compose stack and the E2E tests (every request of the
 * store comes from one address there), and in `prod` the warnings of the `cache` channel (a failing pool) on stderr
 * as JSON, outside the `fingers_crossed` buffer that keeps only the logs of failed requests.
 */
final class RateLimitConfigurationTest extends TestCase
{
    /** @var array<string, array<string, Definition>> */
    private static array $definitions = [];

    public function testTheProductionLimitsAreTheOnesOfTheSpecification(): void
    {
        self::assertSame(
            [
                'order_placement' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '15 minutes'],
                'payment_start' => ['policy' => 'sliding_window', 'limit' => 20, 'interval' => '15 minutes'],
                'payment_webhook' => ['policy' => 'sliding_window', 'limit' => 300, 'interval' => '1 minute'],
            ],
            self::limits('prod'),
        );
    }

    public function testTheLimitersTakeNoLockAndKeepTheirStateInTheRateLimiterPool(): void
    {
        $definitions = self::definitions('prod');
        foreach (['order_placement', 'payment_start', 'payment_webhook'] as $name) {
            self::assertArrayHasKey('limiter.'.$name, $definitions);
            $limiter = $definitions['limiter.'.$name];
            self::assertNull($limiter->getArgument(2), $name.' has no lock factory');
            $storage = $limiter->getArgument(1);
            self::assertInstanceOf(Reference::class, $storage);
            self::assertEquals([new Reference('cache.rate_limiter')], $definitions[(string) $storage]->getArguments(), $name.' stores in cache.rate_limiter');
        }
    }

    public function testThePoolIsOnRedisWithTimeoutsOfOneSecond(): void
    {
        $definitions = self::definitions('prod');
        $pool = $definitions['cache.rate_limiter'];
        self::assertSame(RedisAdapter::class, $pool->getClass());
        $connection = $pool->getArgument(0);
        self::assertInstanceOf(Reference::class, $connection);
        self::assertSame(
            ['redis://%env(REDIS_HOST)%:6379?timeout=1&read_timeout=1', ['lazy' => true]],
            $definitions[(string) $connection]->getArguments(),
            'a lazy connection: a Redis that is down fails the calls of the pool, which the pool logs, never the request',
        );
    }

    public function testTheDevelopmentLimitsAreHighEnoughForTheE2eTests(): void
    {
        $limits = self::limits('dev');

        self::assertSame(['order_placement', 'payment_start', 'payment_webhook'], array_keys($limits));
        foreach ($limits as $name => $limit) {
            self::assertGreaterThanOrEqual(10_000, $limit['limit'], $name);
        }
    }

    public function testInProductionTheWarningsOfTheCacheChannelGoToStderrOutsideTheBuffer(): void
    {
        $definitions = self::definitions('prod');
        $cacheHandlers = self::handlersOf($definitions, 'monolog.logger.cache');

        self::assertCount(1, $cacheHandlers, 'the cache channel has one handler');
        $handler = $definitions[$cacheHandlers[0]];
        self::assertSame(StreamHandler::class, $handler->getClass());
        self::assertSame('php://stderr', $handler->getArgument(0));
        self::assertSame('warning', $handler->getArgument(1), 'from the level warning up');
        self::assertContains(['setFormatter', 'monolog.formatter.json'], self::calls($handler), 'JSON lines');

        $appHandlers = self::handlersOf($definitions, 'monolog.logger');
        self::assertNotContains($cacheHandlers[0], $appHandlers, 'the other channels do not use it');
        self::assertSame([FingersCrossedHandler::class], array_values(array_unique(array_map(static fn (string $id): ?string => $definitions[$id]->getClass(), $appHandlers))), 'the other channels keep the buffer');
    }

    /**
     * @return array<string, array{policy: mixed, limit: mixed, interval: mixed}>
     */
    private static function limits(string $environment): array
    {
        $limits = [];
        foreach (self::definitions($environment) as $id => $definition) {
            if (str_starts_with($id, 'limiter.') && $definition->hasTag('rate_limiter')) {
                $config = $definition->getArgument(0);
                self::assertIsArray($config);
                $limits[substr($id, \strlen('limiter.'))] = ['policy' => $config['policy'], 'limit' => $config['limit'], 'interval' => $config['interval']];
            }
        }
        ksort($limits);

        return $limits;
    }

    /**
     * @param array<string, Definition> $definitions
     *
     * @return list<string> the ids of the handlers pushed to the logger
     */
    private static function handlersOf(array $definitions, string $logger): array
    {
        $handlers = [];
        foreach (self::calls($definitions[$logger]) as [$method, $reference]) {
            if ('pushHandler' === $method) {
                $handlers[] = $reference;
            }
        }

        return $handlers;
    }

    /**
     * @return list<array{string, string}> the method calls of the definition: the method and its first argument as a string
     *                                     (the id of a reference)
     */
    private static function calls(Definition $definition): array
    {
        $calls = [];
        foreach ($definition->getMethodCalls() as $call) {
            self::assertIsArray($call);
            [$method, $arguments] = $call + [1 => []];
            self::assertIsString($method);
            self::assertIsArray($arguments);
            $first = $arguments[0] ?? '';
            $calls[] = [$method, $first instanceof Reference ? (string) $first : (\is_scalar($first) ? (string) $first : get_debug_type($first))];
        }

        return $calls;
    }

    /**
     * @return array<string, Definition>
     */
    private static function definitions(string $environment): array
    {
        return self::$definitions[$environment] ??= ConfigurationOfEnvironment::definitions($environment);
    }
}

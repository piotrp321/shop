<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Messaging;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The retries of `events` (10 tries after 1, 3, 9, 27, 81, 243 s and four times 300 s) and of the `outbox` relay (200
 * tries, at most 60 s apart) are production behaviour that the specifications describe. The `test_messaging` kernel
 * shortens the delays in one proportion so that the test of the exhausted retries takes seconds; the production
 * configuration must keep its own values.
 */
final class EventsRetryStrategyTest extends TestCase
{
    public function testTheProductionConfigurationRetriesTenTimesGrowingThreefoldUpToFiveMinutes(): void
    {
        $strategy = self::strategy('messenger.yaml', 'events');

        self::assertSame(['max_retries' => 10, 'delay' => 1000, 'multiplier' => 3, 'max_delay' => 300000], $strategy);
    }

    public function testTheTestMessagingKernelKeepsTheCountAndTheGrowthAndTheProportionOfTheLongestDelay(): void
    {
        $production = self::strategy('messenger.yaml', 'events');
        $test = self::strategy('test_messaging/messenger.yaml', 'events');

        self::assertSame($production['max_retries'], $test['max_retries']);
        self::assertSame($production['multiplier'], $test['multiplier']);
        self::assertLessThan($production['delay'], $test['delay']);
        self::assertSame($production['max_delay'] / $production['delay'], $test['max_delay'] / $test['delay']);
    }

    public function testTheTestMessagingKernelExhaustsTheRetriesInAFewSeconds(): void
    {
        $test = self::strategy('test_messaging/messenger.yaml', 'events');
        \assert(\is_int($test['delay']) && \is_int($test['multiplier']) && \is_int($test['max_delay']) && \is_int($test['max_retries']));

        $total = 0;
        for ($retry = 0; $retry < $test['max_retries']; ++$retry) {
            $total += min($test['delay'] * $test['multiplier'] ** $retry, $test['max_delay']);
        }

        self::assertLessThanOrEqual(5000, $total, 'milliseconds of all the delays of the test kernel');
    }

    public function testTheOutboxRelayRetriesTwoHundredTimesAtMostMinuteApart(): void
    {
        $strategy = self::strategy('messenger.yaml', 'outbox');

        self::assertSame(200, $strategy['max_retries']);
        self::assertSame(60000, $strategy['max_delay']);
    }

    /**
     * @return array<mixed>
     */
    private static function strategy(string $file, string $transport): array
    {
        /** @var array{framework?: array{messenger?: array{transports?: array<string, array{retry_strategy?: array<mixed>}>}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/'.$file);
        $strategy = $config['framework']['messenger']['transports'][$transport]['retry_strategy'] ?? null;
        self::assertIsArray($strategy, $file.' sets the retry strategy of '.$transport);

        return $strategy;
    }
}

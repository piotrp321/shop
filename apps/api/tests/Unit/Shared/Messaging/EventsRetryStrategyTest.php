<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Messaging;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The retries of the `events` transport (3 tries after 1, 3 and 9 s) are production behaviour that the specifications
 * describe. The `test_messaging` kernel shortens only the first delay so that the tests of the retries run quickly; the
 * production configuration must keep its own values.
 */
final class EventsRetryStrategyTest extends TestCase
{
    public function testTheProductionConfigurationRetriesThreeTimesAfterOneThreeAndNineSeconds(): void
    {
        $strategy = self::strategy('messenger.yaml');

        self::assertSame(['max_retries' => 3, 'delay' => 1000, 'multiplier' => 3], $strategy);
    }

    public function testTheTestMessagingKernelOnlyShortensTheFirstDelay(): void
    {
        $production = self::strategy('messenger.yaml');
        $test = self::strategy('test_messaging/messenger.yaml');

        self::assertSame(['delay' => 100], $test, 'the test kernel overrides nothing but the delay');
        self::assertLessThan($production['delay'], $test['delay']);
    }

    /**
     * @return array<mixed>
     */
    private static function strategy(string $file): array
    {
        /** @var array{framework?: array{messenger?: array{transports?: array{events?: array{retry_strategy?: array<mixed>}}}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/'.$file);
        $strategy = $config['framework']['messenger']['transports']['events']['retry_strategy'] ?? null;
        self::assertIsArray($strategy, $file.' sets the retry strategy of events');

        return $strategy;
    }
}

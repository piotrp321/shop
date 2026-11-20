<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Infrastructure;

use App\Backoffice\Infrastructure\MessagingStatisticsAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The queues of the database that the dashboard and app:messaging:check report are the Doctrine transports of
 * messenger.yaml (the transport `failed` is reported on its own), in a fixed order: a new transport in the
 * configuration without an entry in the adapter ends this test.
 */
final class MessagingTransportsTest extends TestCase
{
    public function testTheQueuesOfTheAdapterAreTheDoctrineTransportsOfTheConfigurationWithoutFailed(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/messenger.yaml');
        self::assertIsArray($config);
        $transports = $config['framework']['messenger']['transports'] ?? null;
        self::assertIsArray($transports);

        $doctrine = [];
        foreach ($transports as $name => $transport) {
            $dsn = \is_array($transport) ? $transport['dsn'] : $transport;
            self::assertIsString($dsn);
            if (str_starts_with($dsn, 'doctrine://') && 'failed' !== $name) {
                $doctrine[] = $name;
                self::assertStringContainsString('queue_name='.$name.'&', $dsn, 'The queue of the transport is named like it.');
            }
        }

        self::assertEqualsCanonicalizing($doctrine, MessagingStatisticsAdapter::TRANSPORTS);
        self::assertSame(['outbox', 'scheduled', 'payment_simulator', 'promotions', 'jobs'], MessagingStatisticsAdapter::TRANSPORTS);
    }
}

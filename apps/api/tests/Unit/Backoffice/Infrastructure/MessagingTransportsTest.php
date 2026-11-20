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
        self::assertIsArray($config['framework'] ?? null);
        self::assertIsArray($config['framework']['messenger'] ?? null);
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
        self::assertSame($doctrine, MessagingStatisticsAdapter::TRANSPORTS, 'The order of the queues is the order of the configuration.');
    }

    /**
     * What a worker takes back is what Messenger decides by the `redeliver_timeout` of the DSN (3600 s when the DSN has
     * none), so the statistics must count with the same number for every queue: a changed DSN without the changed
     * parameter ends this test.
     */
    public function testTheRedeliveryTimeoutOfEveryQueueIsTheOneOfItsDsn(): void
    {
        $messenger = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/messenger.yaml');
        $services = Yaml::parseFile(\dirname(__DIR__, 4).'/config/services.yaml');
        self::assertIsArray($messenger);
        self::assertIsArray($services);
        self::assertIsArray($messenger['framework'] ?? null);
        self::assertIsArray($messenger['framework']['messenger'] ?? null);
        self::assertIsArray($services['parameters'] ?? null);
        $transports = $messenger['framework']['messenger']['transports'] ?? null;
        $timeouts = $services['parameters']['app.messaging.redeliver_timeouts'] ?? null;
        self::assertIsArray($transports);
        self::assertIsArray($timeouts);

        self::assertSame(MessagingStatisticsAdapter::TRANSPORTS, array_keys($timeouts), 'The timeouts are of the queues of the adapter, in its order.');
        foreach (MessagingStatisticsAdapter::TRANSPORTS as $name) {
            $transport = $transports[$name];
            $dsn = \is_array($transport) ? $transport['dsn'] : $transport;
            self::assertIsString($dsn);
            parse_str((string) parse_url($dsn, \PHP_URL_QUERY), $options);

            self::assertSame(
                (int) ($options['redeliver_timeout'] ?? 3600),
                $timeouts[$name],
                \sprintf('The redeliver timeout of "%s" in services.yaml is not the one of its DSN.', $name),
            );
        }
    }
}

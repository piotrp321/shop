<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rows of the Doctrine transport table written straight into it (UTC, as Messenger writes them), for the tests of the
 * statistics of the queues. Everything is rolled back with the transaction of the test.
 */
final class MessengerRows
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * The `redeliver_timeout` of every transport as the application is configured (`app.messaging.redeliver_timeouts`).
     *
     * @return array<string, int>
     */
    public static function redeliverTimeouts(ContainerInterface $container): array
    {
        $timeouts = $container->getParameter('app.messaging.redeliver_timeouts');
        \assert(\is_array($timeouts));

        /** @var array<string, int> $timeouts */
        return $timeouts;
    }

    /**
     * Empties the table, so that the rows left by other tests do not count.
     */
    public static function clear(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM messenger_messages');
    }

    /**
     * @param \DateTimeImmutable|null $createdAt   the moment the message was written; the available moment when null
     * @param \DateTimeImmutable|null $availableAt the moment it is handed to a worker; now when null
     */
    public static function add(
        Connection $connection,
        string $queue,
        ?\DateTimeImmutable $availableAt = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $deliveredAt = null,
        int $times = 1,
        string $body = 'O:8:"stdClass":0:{}',
    ): void {
        $available = $availableAt ?? new \DateTimeImmutable();
        $created = $createdAt ?? $available;
        $utc = new \DateTimeZone('UTC');
        $connection->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at) '
            .'SELECT :body, :headers, :queue, :created, :available, :delivered FROM generate_series(1, :times)',
            [
                'body' => $body,
                'headers' => '{}',
                'queue' => $queue,
                'created' => $created->setTimezone($utc)->format(self::FORMAT),
                'available' => $available->setTimezone($utc)->format(self::FORMAT),
                'delivered' => $deliveredAt?->setTimezone($utc)->format(self::FORMAT),
                'times' => $times,
            ],
        );
    }
}

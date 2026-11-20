<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Clock\ClockInterface;

/**
 * The ids and the size of the `failed` queue of the Doctrine transport table, for app:messaging:failed-retry. The
 * receiver of Messenger lists messages in no order and decodes each of them; the bulk retry needs only the ids of the
 * oldest ones. Times are the UTC moments Messenger writes to TIMESTAMP columns without a time zone.
 */
final readonly class FailedMessageQueue
{
    private const string QUEUE = KeepFailedMessageListener::FAILURE_TRANSPORT;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The ids of at most $max messages available now (not waiting for a delayed retry), the oldest first.
     *
     * @return list<string>
     *
     * @throws DbalException the queue could not be read
     */
    public function oldestAvailableIds(int $max): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM messenger_messages WHERE queue_name = ? AND available_at <= ? ORDER BY available_at, id LIMIT ?',
            [self::QUEUE, $this->now(), $max],
        );

        return array_map(static fn (mixed $id): string => \is_int($id) || \is_string($id) ? (string) $id : throw new \UnexpectedValueException('A message id that is not a number.'), $ids);
    }

    /**
     * @throws DbalException the queue could not be read
     */
    public function contains(string $id): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM messenger_messages WHERE queue_name = ? AND id = ?', [self::QUEUE, $id]);
    }

    /**
     * Every message of the queue, also the ones waiting for a delayed retry.
     *
     * @throws DbalException the queue could not be read
     */
    public function count(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM messenger_messages WHERE queue_name = ?', [self::QUEUE]);
        \assert(\is_int($count) || \is_string($count));

        return (int) $count;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

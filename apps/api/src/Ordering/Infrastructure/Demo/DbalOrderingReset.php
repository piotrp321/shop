<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Demo;

use App\Ordering\Application\Port\OrderingReset;
use Doctrine\DBAL\Connection;

/**
 * Truncates the orders and their lines and deletes the queue of their ExpireUnpaidOrder messages (`scheduled`, which
 * only Ordering uses); the other queues of messenger_messages (`outbox`, `failed`) stay.
 */
final readonly class DbalOrderingReset implements OrderingReset
{
    public function __construct(private Connection $connection)
    {
    }

    public function reset(): void
    {
        $this->connection->executeStatement('TRUNCATE ordering_order_line, ordering_order');
        $this->connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'scheduled'");
    }
}

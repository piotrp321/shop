<?php

declare(strict_types=1);

namespace App\Delivery\Infrastructure\Demo;

use App\Delivery\Application\Port\DeliveryReset;
use Doctrine\DBAL\Connection;

/**
 * Deletes every delivery method in one statement (the demo seed only; nothing references a method, orders keep copies).
 */
final readonly class DbalDeliveryReset implements DeliveryReset
{
    public function __construct(private Connection $connection)
    {
    }

    public function reset(): void
    {
        $this->connection->executeStatement('DELETE FROM delivery_method');
    }
}

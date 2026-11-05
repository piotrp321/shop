<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Demo;

use App\Ordering\Application\Port\OrderingReset;
use Doctrine\DBAL\Connection;

final readonly class DbalOrderingReset implements OrderingReset
{
    public function __construct(private Connection $connection)
    {
    }

    public function reset(): void
    {
        $this->connection->executeStatement('TRUNCATE ordering_order_line, ordering_order');
    }
}

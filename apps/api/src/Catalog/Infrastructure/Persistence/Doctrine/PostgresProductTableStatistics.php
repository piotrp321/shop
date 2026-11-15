<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\ProductTableStatistics;
use Doctrine\DBAL\Connection;

final readonly class PostgresProductTableStatistics implements ProductTableStatistics
{
    public function __construct(private Connection $connection)
    {
    }

    public function estimatedProductCount(): ?int
    {
        // reltuples is -1 until the table has been analysed or vacuumed once: no estimate. The table is found by its
        // name on the search path, which is where the products are read from.
        $estimate = $this->connection->fetchOne("SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass('catalog_product')");

        return \is_int($estimate) && $estimate >= 0 ? $estimate : null;
    }

    public function refresh(): void
    {
        $this->connection->executeStatement('ANALYZE catalog_product');
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\ProductsForInventorySource;
use App\Catalog\Domain\Product\ProductId;
use Doctrine\DBAL\Connection;

final readonly class DbalProductsForInventorySource implements ProductsForInventorySource
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * One lookup of the primary key.
     */
    public function exists(ProductId $id): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM catalog_product WHERE id = CAST(:id AS uuid)',
            ['id' => $id->toString()],
        );
    }
}

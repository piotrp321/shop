<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\ProductsForInventorySource;
use App\Catalog\Application\Query\ProductIdentity;
use App\Catalog\Domain\Product\ProductId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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

    public function page(?ProductId $after, int $limit): array
    {
        // Two statements rather than "IS NULL OR", so the cursor stays an index condition of the primary key.
        $rows = null === $after
            ? $this->connection->fetchAllAssociative(
                'SELECT id, slug FROM catalog_product ORDER BY id LIMIT :limit',
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            )
            : $this->connection->fetchAllAssociative(
                'SELECT id, slug FROM catalog_product WHERE id > CAST(:after AS uuid) ORDER BY id LIMIT :limit',
                ['after' => $after->toString(), 'limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            );

        return array_map(static function (array $row): ProductIdentity {
            \assert(\is_string($row['id']) && \is_string($row['slug']));

            return new ProductIdentity($row['id'], $row['slug']);
        }, $rows);
    }
}

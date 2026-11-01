<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\ProductsForPricingSource;
use App\Catalog\Application\Query\ProductForPricing;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\TaxClassCode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalProductsForPricingSource implements ProductsForPricingSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function taxClassOf(ProductId $id): ?TaxClassCode
    {
        $taxClass = $this->connection->fetchOne(
            'SELECT tax_class FROM catalog_product WHERE id = CAST(:id AS uuid)',
            ['id' => $id->toString()],
        );

        return \is_string($taxClass) ? TaxClassCode::from($taxClass) : null;
    }

    public function page(?ProductId $after, int $limit): array
    {
        // Two statements rather than "IS NULL OR", so the cursor stays an index condition of the primary key.
        $rows = null === $after
            ? $this->connection->fetchAllAssociative(
                'SELECT id, slug, tax_class FROM catalog_product ORDER BY id LIMIT :limit',
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            )
            : $this->connection->fetchAllAssociative(
                'SELECT id, slug, tax_class FROM catalog_product WHERE id > CAST(:after AS uuid) ORDER BY id LIMIT :limit',
                ['after' => $after->toString(), 'limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            );

        return array_map(static function (array $row): ProductForPricing {
            \assert(\is_string($row['id']) && \is_string($row['slug']) && \is_string($row['tax_class']));

            return new ProductForPricing($row['id'], $row['slug'], $row['tax_class']);
        }, $rows);
    }
}

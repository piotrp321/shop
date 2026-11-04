<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Product\ProductId;

/**
 * Names and main images of products, read straight from the Catalog tables.
 */
interface ProductSummarySource
{
    /**
     * One read for all the products; a product that does not exist is left out, the order is unspecified.
     *
     * @param list<ProductId> $ids
     *
     * @return list<array{id: string, name: string, mainImage: array{key: string, alt: string}|null}>
     */
    public function findMany(array $ids): array;
}

<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class CatalogStatisticsView
{
    /**
     * @param ?int $indexedProducts documents in the search index, null when the index cannot be counted
     */
    public function __construct(
        public int $productCount,
        public int $categoryCount,
        public int $productsWithoutImages,
        public ?int $indexedProducts,
    ) {
    }
}

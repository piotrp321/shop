<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Domain\BoundedCount;

final readonly class CatalogStatisticsView
{
    /**
     * @param ?int          $productCount          the estimate of the number of products, null while the database has none
     * @param ?BoundedCount $productsWithoutImages null when it was not asked for
     * @param ?int          $indexedProducts       documents in the search index, null when the index cannot be counted
     */
    public function __construct(
        public ?int $productCount,
        public int $categoryCount,
        public ?BoundedCount $productsWithoutImages,
        public ?int $indexedProducts,
    ) {
    }
}

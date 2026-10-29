<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

/**
 * What the dashboard needs to know about the catalogue.
 */
final readonly class CatalogCounts
{
    /**
     * @param ?int $indexedProducts documents in the search index, null when it cannot be counted
     */
    public function __construct(
        public int $productCount,
        public int $categoryCount,
        public int $productsWithoutImages,
        public ?int $indexedProducts,
    ) {
    }
}

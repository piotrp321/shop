<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

final readonly class SearchResult
{
    /**
     * @param list<ProductDocument> $items
     * @param int                   $total all matching products, not only this page
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ProductPage
{
    /**
     * @param list<ProductListItem> $items
     * @param int                   $total all products matching the filter, not only this page
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ProductPage
{
    /**
     * @param list<ProductListItem> $items
     * @param int                   $total     all products matching the filter, not only this page
     * @param int                   $pageCount the pages of `perPage` products a client can read: 0 without results, and not
     *                                         more than the search can reach even when `total` is larger
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public int $pageCount,
        public ProductFacets $facets,
    ) {
    }
}

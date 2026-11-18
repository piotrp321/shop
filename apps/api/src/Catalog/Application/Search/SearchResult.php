<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

final readonly class SearchResult
{
    /**
     * @param list<ProductDocument> $items
     * @param int                   $total     all matching products, not only this page
     * @param int                   $reachable how many of the matching products a client can page through: the search cannot
     *                                         read past its result window, so this is `total` or less
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $reachable,
        public SearchFacets $facets,
    ) {
    }
}

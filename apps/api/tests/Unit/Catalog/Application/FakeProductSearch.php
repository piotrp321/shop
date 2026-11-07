<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\ProductSearch;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\SearchFacets;
use App\Catalog\Application\Search\SearchResult;

/**
 * Returns the given documents as one page and records the last call.
 */
final class FakeProductSearch implements ProductSearch
{
    public ?ProductSearchCriteria $lastCriteria = null;

    /**
     * @param list<ProductDocument> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly int $total = 0,
        private readonly SearchFacets $facets = new SearchFacets([], [], null, 0),
    ) {
    }

    public function search(ProductSearchCriteria $criteria): SearchResult
    {
        $this->lastCriteria = $criteria;

        return new SearchResult($this->items, $this->total, $this->facets);
    }
}

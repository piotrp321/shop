<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Application\Search\SearchUnavailable;

interface ProductSearch
{
    /**
     * The page of products matching the criteria, with the total and the facets. A page past the end gives an empty
     * list with the real total.
     *
     * @throws SearchUnavailable
     * @throws SearchIndexError
     */
    public function search(ProductSearchCriteria $criteria): SearchResult;
}

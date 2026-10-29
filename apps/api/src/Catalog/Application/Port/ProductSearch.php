<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Application\Search\SearchUnavailable;

interface ProductSearch
{
    /**
     * Products in the category or any of its descendants (all products when $categoryId is null), sorted by name.
     * A page past the end gives an empty list with the real total.
     *
     * @param int $page    from 1
     * @param int $perPage at least 1
     *
     * @throws \InvalidArgumentException when $page or $perPage is lower than 1
     * @throws SearchUnavailable
     * @throws SearchIndexError
     */
    public function search(?string $categoryId, int $page, int $perPage): SearchResult;
}

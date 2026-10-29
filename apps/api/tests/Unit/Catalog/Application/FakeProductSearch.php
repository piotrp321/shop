<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\ProductSearch;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchResult;

/**
 * Returns the given documents as one page and records the last call.
 */
final class FakeProductSearch implements ProductSearch
{
    /** @var array{categoryId: ?string, page: int, perPage: int}|null */
    public ?array $lastCall = null;

    /**
     * @param list<ProductDocument> $items
     */
    public function __construct(private readonly array $items = [], private readonly int $total = 0)
    {
    }

    public function search(?string $categoryId, int $page, int $perPage): SearchResult
    {
        $this->lastCall = ['categoryId' => $categoryId, 'page' => $page, 'perPage' => $perPage];

        return new SearchResult($this->items, $this->total);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\Application\Port\ProductSearch;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;

/**
 * The ProductSearch port in the test environment: the real adapter until a test calls use(), so a functional test
 * can point the application at a temporary index or at a failing stub. Needs a client with disableReboot().
 */
final class SwitchableProductSearch implements ProductSearch
{
    private ?ProductSearch $replacement = null;

    public function __construct(private readonly ElasticsearchProductSearch $default)
    {
    }

    public function use(ProductSearch $replacement): void
    {
        $this->replacement = $replacement;
    }

    public function search(?string $categoryId, int $page, int $perPage): SearchResult
    {
        return ($this->replacement ?? $this->default)->search($categoryId, $page, $perPage);
    }
}

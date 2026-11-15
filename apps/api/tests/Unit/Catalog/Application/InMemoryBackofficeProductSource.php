<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Product\ProductStatus;
use App\Shared\Domain\BoundedCount;

/**
 * Answers every page with the given products (cut to the limit) and records the pages and the counts it was asked
 * for; the filtering and the order are the database's, tested on PostgreSQL.
 */
final class InMemoryBackofficeProductSource implements BackofficeProductSource
{
    /** @var list<array{?string, ?CategoryPath, ?ProductStatus, ?ProductCursor, int}> */
    public array $pages = [];
    /** @var list<array{?string, ?CategoryPath, ?ProductStatus}> */
    public array $counts = [];
    public BoundedCount $total;

    /**
     * @param list<BackofficeProduct> $products
     */
    public function __construct(public array $products = [])
    {
        $this->total = new BoundedCount(\count($products), false);
    }

    public function page(?string $term, ?CategoryPath $category, ?ProductStatus $status, ?ProductCursor $after, int $limit): array
    {
        $this->pages[] = [$term, $category, $status, $after, $limit];

        return \array_slice($this->products, 0, $limit);
    }

    public function count(?string $term, ?CategoryPath $category, ?ProductStatus $status): BoundedCount
    {
        $this->counts[] = [$term, $category, $status];

        return $this->total;
    }

    public function summaries(array $ids): array
    {
        return [];
    }
}

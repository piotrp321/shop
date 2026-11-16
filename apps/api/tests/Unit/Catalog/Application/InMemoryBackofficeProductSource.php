<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
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
    /** @var list<ProductSort> the sort of each page read, in the order of */
    public array $sorts = [];
    /** @var list<array{?string, ?CategoryPath, ?ProductStatus}> */
    public array $counts = [];
    /** @var list<bool> whether each page read was limited to the products without images */
    public array $withoutImagesOfPages = [];
    /** @var list<bool> the same for each count */
    public array $withoutImagesOfCounts = [];
    /** @var list<int> the limit of each count */
    public array $countLimits = [];
    public BoundedCount $total;

    /**
     * @param list<BackofficeProduct> $products
     */
    public function __construct(public array $products = [])
    {
        $this->total = new BoundedCount(\count($products), false);
    }

    public function page(?string $term, ?CategoryPath $category, ?ProductStatus $status, ?ProductCursor $after, ProductSort $sort, int $limit, bool $withoutImages = false): array
    {
        $this->withoutImagesOfPages[] = $withoutImages;
        $this->pages[] = [$term, $category, $status, $after, $limit];
        $this->sorts[] = $sort;

        return \array_slice($this->products, 0, $limit);
    }

    public function count(?string $term, ?CategoryPath $category, ?ProductStatus $status, bool $withoutImages = false, int $limit = 10_000): BoundedCount
    {
        $this->countLimits[] = $limit;
        $this->withoutImagesOfCounts[] = $withoutImages;
        $this->counts[] = [$term, $category, $status];

        return $this->total;
    }

    public function summaries(array $ids): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\CategoryProductCounts;
use App\Catalog\Domain\Category\CategoryId;
use App\Shared\Domain\BoundedCount;

final class InMemoryCategoryProductCounts implements CategoryProductCounts
{
    /** @var array<string, BoundedCount> category id => its products */
    public array $counts = [];

    /** @var list<array{string, int}> the category and the limit of every count() */
    public array $asked = [];

    public function count(CategoryId $id, int $limit): BoundedCount
    {
        $this->asked[] = [$id->toString(), $limit];

        return $this->counts[$id->toString()] ?? new BoundedCount(0, false);
    }

    public function countAll(int $limit): array
    {
        return $this->counts;
    }
}

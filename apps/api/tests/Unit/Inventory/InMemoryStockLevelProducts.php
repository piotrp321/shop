<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Application\Port\StockLevelProducts;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ProductId;
use App\Shared\Domain\BoundedCount;

/**
 * Hands out the products of the levels it was given in ascending order of their ids, as the database does, and
 * remembers how it was asked.
 */
final class InMemoryStockLevelProducts implements StockLevelProducts
{
    /** @var list<array{string, ?string, int}> level, the product the page starts after and the limit of every page() call */
    public array $pageRequests = [];
    public int $counts = 0;
    /** @var array<string, list<string>> level => product ids in ascending order */
    private array $products = [];

    /**
     * @param list<string> $productIds
     */
    public function with(AvailabilityLevel $level, array $productIds): self
    {
        sort($productIds);
        $this->products[$level->value] = $productIds;

        return $this;
    }

    public function page(AvailabilityLevel $level, ?ProductId $after, int $limit): array
    {
        $this->pageRequests[] = [$level->value, $after?->toString(), $limit];
        $ids = array_values(array_filter(
            $this->products[$level->value] ?? [],
            static fn (string $id): bool => null === $after || strcmp($id, $after->toString()) > 0,
        ));

        return array_map(ProductId::fromString(...), \array_slice($ids, 0, $limit));
    }

    public function count(AvailabilityLevel $level): BoundedCount
    {
        ++$this->counts;

        return new BoundedCount(\count($this->products[$level->value] ?? []), false);
    }
}

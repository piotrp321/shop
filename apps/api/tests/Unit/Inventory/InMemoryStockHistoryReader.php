<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Application\Port\StockHistoryReader;
use App\Inventory\Application\Query\StockHistoryCursor;
use App\Inventory\Application\Query\StockHistoryItem;
use App\Inventory\Domain\Stock\ProductId;

/**
 * Keeps history entries per product and pages them like the database does: newest first, then by id descending,
 * strictly after the cursor.
 */
final class InMemoryStockHistoryReader implements StockHistoryReader
{
    /** @var array<string, list<StockHistoryItem>> */
    private array $entries = [];
    /** @var list<array{string, ?StockHistoryCursor, int}> product id, cursor and limit of every call */
    public array $requests = [];

    public function add(string $productId, StockHistoryItem $item): void
    {
        $this->entries[$productId][] = $item;
    }

    public function page(ProductId $productId, ?StockHistoryCursor $after, int $limit): array
    {
        $this->requests[] = [$productId->toString(), $after, $limit];
        $entries = $this->entries[$productId->toString()] ?? [];
        usort($entries, static fn (StockHistoryItem $a, StockHistoryItem $b): int => [$b->occurredAt, $b->id] <=> [$a->occurredAt, $a->id]);
        if (null !== $after) {
            $entries = array_values(array_filter(
                $entries,
                static fn (StockHistoryItem $item): bool => [$item->occurredAt, $item->id] < [$after->occurredAt, $after->id],
            ));
        }

        return \array_slice($entries, 0, $limit);
    }
}

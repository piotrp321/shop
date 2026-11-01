<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\PriceHistoryReader;
use App\Pricing\Application\Query\PriceHistoryCursor;
use App\Pricing\Application\Query\PriceHistoryItem;
use App\Pricing\Domain\Price\ProductId;

/**
 * Keeps history entries per product and pages them like the database does: newest first, then by id descending,
 * strictly after the cursor.
 */
final class InMemoryPriceHistoryReader implements PriceHistoryReader
{
    /** @var array<string, list<PriceHistoryItem>> */
    private array $entries = [];
    /** @var list<array{string, ?PriceHistoryCursor, int}> product id, cursor and limit of every call */
    public array $requests = [];

    public function add(string $productId, PriceHistoryItem $item): void
    {
        $this->entries[$productId][] = $item;
    }

    public function page(ProductId $productId, ?PriceHistoryCursor $after, int $limit): array
    {
        $this->requests[] = [$productId->toString(), $after, $limit];
        $entries = $this->entries[$productId->toString()] ?? [];
        usort($entries, static fn (PriceHistoryItem $a, PriceHistoryItem $b): int => [$b->changedAt, $b->id] <=> [$a->changedAt, $a->id]);
        if (null !== $after) {
            $entries = array_values(array_filter(
                $entries,
                static fn (PriceHistoryItem $item): bool => [$item->changedAt, $item->id] < [$after->changedAt, $after->id],
            ));
        }

        return \array_slice($entries, 0, $limit);
    }
}

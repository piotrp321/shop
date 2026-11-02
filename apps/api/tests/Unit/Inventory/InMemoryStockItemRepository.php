<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\ReceiptKeyReused;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\StockMovement;
use App\Inventory\Domain\Stock\StockMovementType;

/**
 * Keeps stocks like the database does: reads return fresh copies, lockOrCreate() stores a missing stock at version 0,
 * save() checks the version the stock was read with and the uniqueness of receipt keys and appends the movements.
 * Locks are only recorded (one process, nothing to wait for).
 */
final class InMemoryStockItemRepository implements StockItemRepository
{
    /** @var array<string, StockItem> */
    private array $items = [];
    /** @var list<StockMovement> */
    public array $movements = [];
    public int $writes = 0;
    /** @var list<list<string>> the ids of every lockMany() call, in the order given */
    public array $locks = [];
    /** @var list<list<string>> the ids of every findMany() call */
    public array $manyReads = [];

    public function lockMany(array $ids): array
    {
        $this->locks[] = array_map(static fn (ProductId $id): string => $id->toString(), $ids);
        $found = $this->found($ids);
        usort($found, static fn (StockItem $a, StockItem $b): int => strcmp($a->id()->toString(), $b->id()->toString()));

        return $found;
    }

    public function lockOrCreate(ProductId $id): StockItem
    {
        $this->items[$id->toString()] ??= StockItem::unstored($id);

        return $this->lockMany([$id])[0];
    }

    public function findReceipt(ReceiptKey $key): ?StockMovement
    {
        foreach ($this->movements as $movement) {
            if (StockMovementType::Receipt === $movement->type() && $key->toString() === $movement->reference()) {
                return $movement;
            }
        }

        return null;
    }

    public function findMany(array $ids): array
    {
        $this->manyReads[] = array_map(static fn (ProductId $id): string => $id->toString(), $ids);

        return $this->found($ids);
    }

    public function save(StockItem $item): void
    {
        $movements = $item->releaseMovements();
        if ([] === $movements) {
            return;
        }
        $stored = $this->items[$item->id()->toString()] ?? throw new \LogicException('Only a stored stock can be saved.');
        if ($item->version() - 1 !== $stored->version()) {
            throw new \LogicException('The stock was changed without its lock.');
        }

        // The unique index of receipt keys.
        foreach ($movements as $movement) {
            if (StockMovementType::Receipt === $movement->type() && null !== $this->findReceipt(ReceiptKey::fromString((string) $movement->reference()))) {
                throw new ReceiptKeyReused();
            }
        }

        $this->items[$item->id()->toString()] = $this->copy($item);
        array_push($this->movements, ...$movements);
        ++$this->writes;
    }

    /**
     * Stores a stock as if it had been saved earlier, without movements.
     */
    public function store(string $productId, int $physical, int $reserved = 0, int $version = 1): void
    {
        $this->items[$productId] = StockItem::reconstitute(ProductId::fromString($productId), $physical, $reserved, $version, new \DateTimeImmutable('2026-11-01 10:00:00+00:00'));
    }

    public function stored(string $productId): ?StockItem
    {
        $stored = $this->items[$productId] ?? null;

        return null === $stored ? null : $this->copy($stored);
    }

    /**
     * @return array{array<string, StockItem>, list<StockMovement>, int}
     */
    public function snapshot(): array
    {
        return [$this->items, $this->movements, $this->writes];
    }

    /**
     * @param array{array<string, StockItem>, list<StockMovement>, int} $snapshot
     */
    public function restore(array $snapshot): void
    {
        [$this->items, $this->movements, $this->writes] = $snapshot;
    }

    /**
     * @param list<ProductId> $ids
     *
     * @return list<StockItem>
     */
    private function found(array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            $stored = $this->items[$id->toString()] ?? null;
            if (null !== $stored) {
                $found[] = $this->copy($stored);
            }
        }

        return $found;
    }

    private function copy(StockItem $item): StockItem
    {
        return StockItem::reconstitute($item->id(), $item->physical(), $item->reserved(), $item->version(), $item->updatedAt());
    }
}

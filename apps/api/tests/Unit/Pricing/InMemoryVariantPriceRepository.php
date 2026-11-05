<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Domain\Price\PriceHistoryEntry;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Price\VariantPriceRepository;

/**
 * Keeps prices like the database does: find() returns a fresh copy, save() checks the version the price was read
 * with and appends the history.
 */
final class InMemoryVariantPriceRepository implements VariantPriceRepository
{
    /** @var array<string, VariantPrice> */
    private array $prices = [];
    /** @var list<PriceHistoryEntry> */
    public array $history = [];
    public int $writes = 0;
    /** @var list<list<string>> the ids of every findMany() call */
    public array $manyReads = [];
    /** @var list<int> the limit of every page() call */
    public array $pageLimits = [];

    public function find(VariantId $id): ?VariantPrice
    {
        $stored = $this->prices[$id->toString()] ?? null;

        return null === $stored ? null : $this->copy($stored);
    }

    public function findMany(array $ids): array
    {
        $this->manyReads[] = array_map(static fn (VariantId $id): string => $id->toString(), $ids);
        $found = [];
        foreach ($ids as $id) {
            $stored = $this->prices[$id->toString()] ?? null;
            if (null !== $stored) {
                $found[] = $this->copy($stored);
            }
        }

        return $found;
    }

    public function page(?VariantId $after, int $limit): array
    {
        $this->pageLimits[] = $limit;
        $ids = array_keys($this->prices);
        sort($ids, \SORT_STRING);
        if (null !== $after) {
            $ids = array_filter($ids, static fn (string $id): bool => strcmp($id, $after->toString()) > 0);
        }

        return array_map(fn (string $id): VariantPrice => $this->copy($this->prices[$id]), \array_slice(array_values($ids), 0, $limit));
    }

    public function save(VariantPrice $price): void
    {
        $entries = $price->releaseHistory();
        if ([] === $entries) {
            return;
        }

        $readVersion = $price->version() - 1;
        $stored = $this->prices[$price->id()->toString()] ?? null;
        if ($readVersion !== ($stored?->version() ?? 0)) {
            throw new PriceModifiedConcurrently();
        }

        $this->prices[$price->id()->toString()] = $this->copy($price);
        array_push($this->history, ...$entries);
        ++$this->writes;
    }

    /**
     * Stores a price as if it had been saved earlier, without history.
     */
    public function store(VariantPrice $price): void
    {
        $price->releaseHistory();
        $this->prices[$price->id()->toString()] = $this->copy($price);
    }

    private function copy(VariantPrice $price): VariantPrice
    {
        return VariantPrice::reconstitute($price->id(), $price->breakdown(), $price->taxClass(), $price->version(), $price->updatedAt());
    }
}

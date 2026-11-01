<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Domain\Price\PriceHistoryEntry;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Price\ProductPriceRepository;

/**
 * Keeps prices like the database does: find() returns a fresh copy, save() checks the version the price was read
 * with and appends the history.
 */
final class InMemoryProductPriceRepository implements ProductPriceRepository
{
    /** @var array<string, ProductPrice> */
    private array $prices = [];
    /** @var list<PriceHistoryEntry> */
    public array $history = [];
    public int $writes = 0;

    public function find(ProductId $id): ?ProductPrice
    {
        $stored = $this->prices[$id->toString()] ?? null;

        return null === $stored ? null : $this->copy($stored);
    }

    public function save(ProductPrice $price): void
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
    public function store(ProductPrice $price): void
    {
        $price->releaseHistory();
        $this->prices[$price->id()->toString()] = $this->copy($price);
    }

    private function copy(ProductPrice $price): ProductPrice
    {
        return ProductPrice::reconstitute($price->id(), $price->breakdown(), $price->taxClass(), $price->version(), $price->updatedAt());
    }
}

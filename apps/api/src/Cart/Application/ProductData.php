<?php

declare(strict_types=1);

namespace App\Cart\Application;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\VariantAvailability;
use App\Cart\Application\Port\VariantSummary;
use App\Cart\Domain\Money;
use App\Cart\Domain\ProductOffer;
use App\Cart\Domain\VariantId;

/**
 * What Catalog, Pricing and Inventory said about a set of variants, read once for a change or a view.
 */
final readonly class ProductData
{
    /** @var array<string, VariantSummary> */
    private array $summaries;
    /** @var array<string, Money> */
    private array $prices;
    /** @var array<string, int> */
    private array $available;

    /**
     * @param list<VariantSummary>      $summaries
     * @param list<CartPrice>           $prices
     * @param list<VariantAvailability> $availability
     */
    public function __construct(array $summaries, array $prices, array $availability)
    {
        $byId = [];
        foreach ($summaries as $summary) {
            $byId[$summary->variantId->toString()] = $summary;
        }
        $this->summaries = $byId;
        $byId = [];
        foreach ($prices as $price) {
            $byId[$price->variantId->toString()] = $price->gross;
        }
        $this->prices = $byId;
        $byId = [];
        foreach ($availability as $one) {
            $byId[$one->variantId->toString()] = $one->available;
        }
        $this->available = $byId;
    }

    /**
     * Null when Catalog does not know the variant.
     */
    public function summary(VariantId $id): ?VariantSummary
    {
        return $this->summaries[$id->toString()] ?? null;
    }

    /**
     * Null when the variant has no current price.
     */
    public function price(VariantId $id): ?Money
    {
        return $this->prices[$id->toString()] ?? null;
    }

    /**
     * 0 when Inventory gave nothing for the variant.
     */
    public function available(VariantId $id): int
    {
        return $this->available[$id->toString()] ?? 0;
    }

    public function offerFor(VariantId $id): ProductOffer
    {
        return new ProductOffer(null !== $this->price($id), $this->available($id));
    }
}

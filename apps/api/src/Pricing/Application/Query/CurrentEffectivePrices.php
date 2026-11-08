<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\ActivePromotions;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Promotion\EffectivePrice;
use App\Pricing\Domain\Tax\VatRates;

/**
 * The one read of effective prices, for GetCurrentPrices and QuoteBasket: one query for the catalog prices and one for
 * the promotions active at the moment of those with a current price. Never cached.
 */
final readonly class CurrentEffectivePrices
{
    public function __construct(
        private VariantPriceRepository $prices,
        private VatRates $rates,
        private ActivePromotions $promotions,
    ) {
    }

    /**
     * A variant without an effective price (no catalog price, or one at a rate that is no longer the rate of its
     * class, see EffectivePrice) is left out: it cannot be bought until the price is repaired.
     *
     * @param list<VariantId> $ids 1 to 4800 distinct ids
     *
     * @return array<string, CurrentEffectivePrice> by variant id, in the order of the ids
     */
    public function at(array $ids, \DateTimeImmutable $moment): array
    {
        $stored = [];
        foreach ($this->prices->findMany($ids) as $price) {
            if ($price->isCurrent($this->rates)) {
                $stored[$price->id()->toString()] = $price;
            }
        }
        if ([] === $stored) {
            return [];
        }

        $active = $this->promotions->at(array_values(array_map(static fn (VariantPrice $price): VariantId => $price->id(), $stored)), $moment);

        $current = [];
        foreach ($ids as $id) {
            $key = $id->toString();
            $price = $stored[$key] ?? null;
            if (null === $price) {
                continue;
            }
            $promotion = $active[$key] ?? null;
            $effective = EffectivePrice::of($price, $this->rates, $promotion?->percentOff);
            \assert(null !== $effective);
            $current[$key] = new CurrentEffectivePrice($price, $effective, $promotion);
        }

        return $current;
    }
}

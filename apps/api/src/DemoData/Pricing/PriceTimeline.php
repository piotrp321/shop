<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Promotion\PromotionalPrice;
use App\Pricing\Domain\Tax\VatRate;

/**
 * The effective price of a variant as a pure function of the instant (spec: "Realizm", "Ceny"): the last entry of its
 * history at or before the instant (the rule the Omnibus price of Pricing reads the history by), lowered by the promotion
 * of the variant running then, [start, end), by the rule of Pricing (PromotionalPrice). Orders take the price of their
 * lines from here. No database: the history and the promotions are the ones the generator computes from the seed.
 */
final readonly class PriceTimeline
{
    /**
     * @param non-empty-list<PriceEntry> $entries    ascending by instant; of entries of the same instant the later in the list counts
     * @param list<PromotionSpec>        $promotions the promotions of this variant (none overlaps another)
     */
    public function __construct(
        private array $entries,
        private array $promotions,
    ) {
    }

    /**
     * @return UnitPrice|null null before the first entry (the variant had no price yet)
     */
    public function effectiveAt(\DateTimeImmutable $at): ?UnitPrice
    {
        $entry = null;
        foreach ($this->entries as $candidate) {
            if ($candidate->changedAt > $at) {
                break;
            }
            $entry = $candidate;
        }
        if (null === $entry) {
            return null;
        }

        $regular = GrossPrice::pln($entry->gross);
        $rate = VatRate::fromBasisPoints($entry->rateBasisPoints);
        foreach ($this->promotions as $promotion) {
            if ($promotion->period->isActiveAt($at)) {
                return new UnitPrice($regular, PromotionalPrice::apply($regular, $promotion->percentOff), $rate, $promotion);
            }
        }

        return new UnitPrice($regular, $regular, $rate, null);
    }

    /**
     * @return non-empty-list<PriceEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<PromotionSpec>
     */
    public function promotions(): array
    {
        return $this->promotions;
    }
}

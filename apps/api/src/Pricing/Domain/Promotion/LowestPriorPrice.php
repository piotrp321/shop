<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Pricing\Domain\Price\GrossPrice;

/**
 * The Omnibus price of a variant in a promotion: the lowest effective price in the window W = [start − 30 days,
 * start). The effective price at a moment of W is the gross of the history entry in force then (the last one changed
 * at or before it), lowered by an earlier promotion of the variant active then (Q4 rules out two at once). The
 * moments of W are cut into segments by the entries and the promotion boundaries inside it; a segment before the
 * first price of the variant does not count, so a variant sold for less than 30 days gets the lowest price since
 * its first one. Pure: the history and the promotions are given.
 */
final class LowestPriorPrice
{
    public const int WINDOW_DAYS = 30;

    /**
     * The start of W: 30 days of 24 hours before the start of the promotion (in UTC, so no change of the clocks
     * makes it longer or shorter).
     */
    public static function windowStart(\DateTimeImmutable $promotionStart): \DateTimeImmutable
    {
        return $promotionStart->setTimezone(new \DateTimeZone('UTC'))->modify('-'.self::WINDOW_DAYS.' days');
    }

    /**
     * @param list<PriorPrice>     $history    the entries of the variant in W and the last one before it; of entries of
     *                                         the same moment the later in the list is the price
     * @param list<PriorPromotion> $promotions the other promotions of the variant; those outside W change nothing
     *
     * @return GrossPrice|null null when the variant had no price before the start (there was no price to lower)
     */
    public static function of(\DateTimeImmutable $promotionStart, array $history, array $promotions): ?GrossPrice
    {
        $from = self::windowStart($promotionStart);
        $until = $promotionStart;

        // A stable sort: of entries of the same moment the later one in the list stays the later one.
        usort($history, static fn (PriorPrice $a, PriorPrice $b): int => $a->changedAt <=> $b->changedAt);

        $boundaries = [$from];
        foreach ($history as $entry) {
            $boundaries[] = $entry->changedAt;
        }
        foreach ($promotions as $promotion) {
            $boundaries[] = $promotion->period->startsAt();
            $boundaries[] = $promotion->period->endsAt();
        }

        $lowest = null;
        foreach ($boundaries as $moment) {
            // Every segment of W starts at one of the boundaries inside it; the price is constant until the next one.
            if ($moment < $from || $moment >= $until) {
                continue;
            }
            $price = self::effectiveAt($moment, $history, $promotions);
            if (null !== $price && (null === $lowest || $price->amount() < $lowest->amount())) {
                $lowest = $price;
            }
        }

        return $lowest;
    }

    /**
     * @param list<PriorPrice>     $history    ordered by the moment of the change
     * @param list<PriorPromotion> $promotions
     */
    private static function effectiveAt(\DateTimeImmutable $moment, array $history, array $promotions): ?GrossPrice
    {
        $catalog = null;
        foreach ($history as $entry) {
            if ($entry->changedAt > $moment) {
                break;
            }
            $catalog = $entry->gross;
        }
        if (null === $catalog) {
            return null;
        }
        foreach ($promotions as $promotion) {
            if ($promotion->period->isActiveAt($moment)) {
                return PromotionalPrice::apply($catalog, $promotion->percentOff);
            }
        }

        return $catalog;
    }
}

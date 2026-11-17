<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Pricing\PriceEntry;
use App\DemoData\Pricing\PriceTimeline;
use App\DemoData\Pricing\PromotionSpec;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use PHPUnit\Framework\TestCase;

/**
 * The effective price of a variant at an instant: the last history entry at or before it (the rule of the Omnibus
 * price of Pricing), lowered by the promotion running then, [start, end).
 */
final class PriceTimelineTest extends TestCase
{
    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    private static function entry(string $time, int $gross, string $source = 'seed'): PriceEntry
    {
        return new PriceEntry(
            'id-'.$time,
            self::at($time),
            $gross,
            intdiv($gross * 100, 123),
            $gross - intdiv($gross * 100, 123),
            2300,
            'standard',
            $source,
            'staff' === $source ? 'actor' : null,
            'staff' === $source ? 'Name' : null,
        );
    }

    private static function promotion(string $start, string $end, int $percent): PromotionSpec
    {
        return new PromotionSpec(
            'promotion-'.$start,
            'Promocja',
            PercentOff::of($percent),
            PromotionPeriod::of(self::at($start), self::at($end)),
            self::at($start)->modify('-3 days'),
            ['variant'],
        );
    }

    private static function timeline(): PriceTimeline
    {
        return new PriceTimeline(
            [self::entry('2026-01-01 10:00:00', 10_000), self::entry('2026-03-01 10:00:00', 12_000, 'staff')],
            [self::promotion('2026-02-01 00:00:00', '2026-02-11 00:00:00', 20), self::promotion('2026-03-05 00:00:00', '2026-03-06 00:00:00', 50)],
        );
    }

    public function testThereIsNoPriceBeforeTheFirstEntry(): void
    {
        self::assertNull(self::timeline()->effectiveAt(self::at('2026-01-01 09:59:59')));
    }

    public function testTheFirstEntryIsInForceFromItsInstant(): void
    {
        $price = self::timeline()->effectiveAt(self::at('2026-01-01 10:00:00'));

        self::assertNotNull($price);
        self::assertSame(10_000, $price->regular->amount());
        self::assertSame(10_000, $price->effective->amount());
        self::assertNull($price->promotion);
        self::assertSame(2300, $price->rate->basisPoints());
    }

    public function testTheLaterEntryReplacesTheEarlierOneFromItsInstant(): void
    {
        $before = self::timeline()->effectiveAt(self::at('2026-03-01 09:59:59'));
        $at = self::timeline()->effectiveAt(self::at('2026-03-01 10:00:00'));

        self::assertNotNull($before);
        self::assertNotNull($at);
        self::assertSame(10_000, $before->effective->amount());
        self::assertSame(12_000, $at->effective->amount());
    }

    public function testThePromotionLowersThePriceFromItsStartAndNotBeforeIt(): void
    {
        $before = self::timeline()->effectiveAt(self::at('2026-01-31 23:59:59'));
        $start = self::timeline()->effectiveAt(self::at('2026-02-01 00:00:00'));

        self::assertNotNull($before);
        self::assertNotNull($start);
        self::assertSame(10_000, $before->effective->amount());
        self::assertNull($before->promotion);
        self::assertSame(10_000, $start->regular->amount());
        self::assertSame(8_000, $start->effective->amount());
        self::assertNotNull($start->promotion);
        self::assertSame('promotion-2026-02-01 00:00:00', $start->promotion->id);
    }

    public function testThePromotionEndsBeforeItsEndInstant(): void
    {
        $last = self::timeline()->effectiveAt(self::at('2026-02-10 23:59:59'));
        $end = self::timeline()->effectiveAt(self::at('2026-02-11 00:00:00'));

        self::assertNotNull($last);
        self::assertNotNull($end);
        self::assertSame(8_000, $last->effective->amount());
        self::assertSame(10_000, $end->effective->amount());
        self::assertNull($end->promotion);
    }

    public function testThePromotionLowersTheCatalogPriceInForceThen(): void
    {
        $price = self::timeline()->effectiveAt(self::at('2026-03-05 12:00:00'));

        self::assertNotNull($price);
        self::assertSame(12_000, $price->regular->amount());
        self::assertSame(6_000, $price->effective->amount());
    }

    public function testThePromotionalPriceIsRoundedDownByThePricingRule(): void
    {
        $timeline = new PriceTimeline([self::entry('2026-01-01 00:00:00', 9_999)], [self::promotion('2026-02-01 00:00:00', '2026-02-02 00:00:00', 15)]);

        $price = $timeline->effectiveAt(self::at('2026-02-01 00:00:01'));

        // The discount is intdiv(9999 * 15, 100) = 1499, the rule of PromotionalPrice.
        self::assertNotNull($price);
        self::assertSame(8_500, $price->effective->amount());
    }

    public function testTheLaterEntryOfTheSameInstantWins(): void
    {
        $timeline = new PriceTimeline([self::entry('2026-01-01 10:00:00', 10_000), self::entry('2026-01-01 10:00:00', 11_000, 'staff')], []);

        $price = $timeline->effectiveAt(self::at('2026-01-01 10:00:00'));

        self::assertNotNull($price);
        self::assertSame(11_000, $price->regular->amount());
    }
}

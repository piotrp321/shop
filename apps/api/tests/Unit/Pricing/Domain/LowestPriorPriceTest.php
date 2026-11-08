<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Promotion\LowestPriorPrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PriorPrice;
use App\Pricing\Domain\Promotion\PriorPromotion;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use PHPUnit\Framework\TestCase;

/**
 * The Omnibus price: the lowest effective price in the 30 days before the start of a promotion. The start is
 * 2026-11-30 12:00 UTC, so the window is [2026-10-31 12:00, 2026-11-30 12:00).
 */
final class LowestPriorPriceTest extends TestCase
{
    private const string START = '2026-11-30T12:00:00Z';
    private const string WINDOW_START = '2026-10-31T12:00:00Z';

    public function testTheWindowIsTheThirtyDaysBeforeTheStart(): void
    {
        self::assertEquals(new \DateTimeImmutable(self::WINDOW_START), LowestPriorPrice::windowStart(new \DateTimeImmutable(self::START)));
        // 30 days of 24 hours in UTC, also across a change of the clocks in Warsaw (2026-10-25).
        self::assertEquals(
            new \DateTimeImmutable('2026-10-01T10:00:00Z'),
            LowestPriorPrice::windowStart(new \DateTimeImmutable('2026-10-31T11:00:00+01:00')),
        );
    }

    public function testWithoutAChangeInTheWindowItIsThePriceBeforeIt(): void
    {
        self::assertSame(10_000, $this->lowest([$this->price('2026-10-01T10:00:00Z', 10_000)], []));
    }

    public function testARaiseBeforeThePromotionDoesNotRaiseIt(): void
    {
        self::assertSame(10_000, $this->lowest([
            $this->price('2026-10-01T10:00:00Z', 10_000),
            $this->price('2026-11-28T12:00:00Z', 15_000),
        ], []));
    }

    public function testAnEarlierPromotionInTheWindowCountsWithItsPromotionalPrice(): void
    {
        self::assertSame(8_000, $this->lowest(
            [$this->price('2026-10-01T10:00:00Z', 10_000)],
            [$this->promotion('2026-11-10T12:00:00Z', '2026-11-20T12:00:00Z', 20)],
        ));
    }

    public function testAPromotionThatStartedBeforeTheWindowCountsForItsPartInTheWindow(): void
    {
        self::assertSame(7_000, $this->lowest(
            [$this->price('2026-10-01T10:00:00Z', 10_000)],
            [$this->promotion('2026-10-21T12:00:00Z', '2026-11-05T12:00:00Z', 30)],
        ));
    }

    public function testAPromotionThatEndedBeforeTheWindowOrAtItsStartDoesNotCount(): void
    {
        self::assertSame(10_000, $this->lowest(
            [$this->price('2026-10-01T10:00:00Z', 10_000)],
            [
                $this->promotion('2026-10-01T12:00:00Z', '2026-10-30T12:00:00Z', 50),
                $this->promotion('2026-10-30T12:00:00Z', self::WINDOW_START, 40),
            ],
        ));
    }

    public function testAPromotionEndingAtTheStartCounts(): void
    {
        self::assertSame(9_000, $this->lowest(
            [$this->price('2026-10-01T10:00:00Z', 10_000)],
            [$this->promotion('2026-11-25T12:00:00Z', self::START, 10)],
        ));
    }

    public function testACatalogChangeInTheWindowCounts(): void
    {
        self::assertSame(9_000, $this->lowest([
            $this->price('2026-10-01T10:00:00Z', 10_000),
            $this->price('2026-11-10T12:00:00Z', 9_000),
            $this->price('2026-11-20T12:00:00Z', 11_000),
        ], []));
    }

    public function testACatalogChangeDuringAnEarlierPromotionLowersItsPromotionalPrice(): void
    {
        self::assertSame(7_200, $this->lowest(
            [
                $this->price('2026-10-01T10:00:00Z', 10_000),
                $this->price('2026-11-15T12:00:00Z', 9_000),
            ],
            [$this->promotion('2026-11-10T12:00:00Z', '2026-11-20T12:00:00Z', 20)],
        ));
    }

    public function testWithTheFirstPriceInsideTheWindowOnlyThePricedPartCounts(): void
    {
        // The earlier promotion ran while the variant had no price, so it lowered nothing.
        self::assertSame(9_000, $this->lowest(
            [
                $this->price('2026-11-15T12:00:00Z', 9_000),
                $this->price('2026-11-25T12:00:00Z', 9_500),
            ],
            [$this->promotion('2026-11-01T12:00:00Z', '2026-11-10T12:00:00Z', 50)],
        ));
    }

    public function testWithoutAPriceBeforeTheStartThereIsNone(): void
    {
        self::assertNull($this->lowest([], []));
        self::assertNull($this->lowest([], [$this->promotion('2026-11-10T12:00:00Z', '2026-11-20T12:00:00Z', 20)]));
        // A price set at the start or after it is no price before the promotion.
        self::assertNull($this->lowest([$this->price(self::START, 10_000), $this->price('2026-12-01T12:00:00Z', 9_000)], []));
    }

    public function testAnEntryExactlyAtTheStartOfTheWindowCountsAndReplacesTheOneBefore(): void
    {
        self::assertSame(12_000, $this->lowest([
            $this->price('2026-10-31T11:59:59Z', 10_000),
            $this->price(self::WINDOW_START, 12_000),
        ], []));
        self::assertSame(12_000, $this->lowest([$this->price(self::WINDOW_START, 12_000)], []));
    }

    /**
     * A promotion „od teraz” planned at 11:59:59.6 starts at 12:00:00 (startOfNow); a price saved in that second has
     * changed_at 11:59:59 (cut to the second), so it was the price for the last second before the promotion.
     */
    public function testAnEntryInTheSecondOfAPromotionStartingNowCountsAndOneAtTheStartDoesNot(): void
    {
        self::assertSame(8_000, $this->lowest([
            $this->price('2026-10-01T10:00:00Z', 10_000),
            $this->price('2026-11-30T11:59:59Z', 8_000),
            $this->price(self::START, 5_000),
        ], []));
    }

    public function testOfTwoEntriesOfTheSameMomentTheLaterOneIsThePrice(): void
    {
        self::assertSame(11_000, $this->lowest([
            $this->price('2026-11-10T12:00:00Z', 9_000),
            $this->price('2026-11-10T12:00:00Z', 11_000),
        ], []));
    }

    public function testTheHistoryNeedNotBeInOrder(): void
    {
        self::assertSame(12_000, $this->lowest([
            $this->price(self::WINDOW_START, 12_000),
            $this->price('2026-10-01T10:00:00Z', 10_000),
        ], []));
    }

    /**
     * @param list<PriorPrice>     $history
     * @param list<PriorPromotion> $promotions
     */
    private function lowest(array $history, array $promotions): ?int
    {
        return LowestPriorPrice::of(new \DateTimeImmutable(self::START), $history, $promotions)?->amount();
    }

    private function price(string $changedAt, int $gross): PriorPrice
    {
        return new PriorPrice(new \DateTimeImmutable($changedAt), GrossPrice::pln($gross));
    }

    private function promotion(string $startsAt, string $endsAt, int $percent): PriorPromotion
    {
        return new PriorPromotion(PromotionPeriod::of(new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt)), PercentOff::of($percent));
    }
}

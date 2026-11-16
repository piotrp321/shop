<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\SalesTotals;
use App\Ordering\Application\Query\GetSalesSummary;
use App\Ordering\Application\Query\GetSalesSummaryHandler;
use App\Ordering\Application\Query\PeriodSales;
use App\Ordering\Application\Query\SalesSummary;
use App\Ordering\Domain\OrderId;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemorySalesHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The sales of the dashboard from the hourly sales: revenue, number of orders, average and the change of the revenue
 * against the period before, over the completed hours of SalesPeriods (spec ADMIN-FEATURES, „Sprzedaż”).
 */
final class GetSalesSummaryHandlerTest extends TestCase
{
    private InMemorySalesHours $sales;

    protected function setUp(): void
    {
        $this->sales = new InMemorySalesHours();
    }

    public function testTheThreePeriodsSumTheCompletedHoursAndCompareThemWithThePeriodsBefore(): void
    {
        // Now: 15 November 2026, 14:35 in Warsaw (13:35 UTC). Today from 23:00 UTC of the 14th to 13:00 UTC.
        $this->paid('2026-11-15 12:59:59', 10_000);
        $this->paid('2026-11-15 00:10:00', 5_001);
        // The current hour has not ended: not counted.
        $this->paid('2026-11-15 13:00:00', 99_999);
        // Yesterday before 14:00 of the clock, and after it (not in the comparison of today).
        $this->paid('2026-11-14 08:00:00', 10_000);
        $this->paid('2026-11-14 13:00:00', 70_000);
        // Two days ago: in the 7 days, not in today or yesterday.
        $this->paid('2026-11-13 10:00:00', 1);
        // The 7 days before the last 7.
        $this->paid('2026-11-05 09:00:00', 2_000);

        $summary = $this->summary('2026-11-15 13:35:00');

        self::assertEquals(new \DateTimeImmutable('2026-11-15 13:00:00+00:00'), $summary->dataUntil);
        self::assertSame('PLN', $summary->currency);
        self::assertEquals(new PeriodSales(15_001, 2, 7_501, 5_001), $summary->today, 'against 10 000 of yesterday: +50,01%');
        self::assertEquals(new PeriodSales(95_002, 5, 19_000, 465_010), $summary->last7Days, 'against 2 000 of the week before');
        self::assertEquals(new PeriodSales(97_002, 6, 16_167, null), $summary->last30Days, 'nothing in the 30 days before');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function firstHourOfTheDay(): iterable
    {
        yield 'midnight' => ['2026-11-14 23:00:00'];
        yield '0:59:59' => ['2026-11-14 23:59:59'];
    }

    #[DataProvider('firstHourOfTheDay')]
    public function testBetweenMidnightAndOneTodayHasNoFigures(string $now): void
    {
        $this->paid('2026-11-14 23:00:00', 4_000);
        $this->paid('2026-11-14 12:00:00', 1_000);

        $summary = $this->summary($now);

        self::assertEquals(new PeriodSales(null, null, null, null), $summary->today);
        self::assertEquals(new PeriodSales(1_000, 1, 1_000, null), $summary->last7Days, 'the 7 days end at midnight too');
    }

    public function testWithoutAnyOrderTheRevenueAndTheCountAreZeroAndTheAverageAndTheChangeNull(): void
    {
        $summary = $this->summary('2026-11-15 13:35:00');

        self::assertEquals(new PeriodSales(0, 0, null, null), $summary->today);
        self::assertEquals(new PeriodSales(0, 0, null, null), $summary->last7Days);
        self::assertEquals(new PeriodSales(0, 0, null, null), $summary->last30Days);
    }

    public function testNoOrdersAfterAPeriodWithOrdersIsMinusAHundredPercent(): void
    {
        $this->paid('2026-11-14 08:00:00', 10_000);

        self::assertEquals(new PeriodSales(0, 0, null, -10_000), $this->summary('2026-11-15 13:35:00')->today);
    }

    /**
     * @return iterable<string, array{SalesTotals, SalesTotals, PeriodSales}>
     */
    public static function rounding(): iterable
    {
        yield 'the average to the grosz, a half up' => [new SalesTotals(2, 1_001), new SalesTotals(1, 1_001), new PeriodSales(1_001, 2, 501, 0)];
        yield 'the average down below a half' => [new SalesTotals(3, 1_000), new SalesTotals(0, 0), new PeriodSales(1_000, 3, 333, null)];
        yield 'a change up, rounded up from two thirds' => [new SalesTotals(1, 5), new SalesTotals(1, 3), new PeriodSales(5, 1, 5, 6_667)];
        yield 'a change down, rounded away from zero' => [new SalesTotals(1, 1), new SalesTotals(1, 3), new PeriodSales(1, 1, 1, -6_667)];
        yield 'a change down below a half' => [new SalesTotals(1, 2), new SalesTotals(1, 3), new PeriodSales(2, 1, 2, -3_333)];
        yield 'a half basis point up' => [new SalesTotals(1, 20_001), new SalesTotals(1, 20_000), new PeriodSales(20_001, 1, 20_001, 1)];
        yield 'a half basis point down' => [new SalesTotals(1, 19_999), new SalesTotals(1, 20_000), new PeriodSales(19_999, 1, 19_999, -1)];
        yield 'a large revenue' => [new SalesTotals(1_000_000, 300_000_000_000), new SalesTotals(1, 100_000_000_000), new PeriodSales(300_000_000_000, 1_000_000, 300_000, 20_000)];
    }

    #[DataProvider('rounding')]
    public function testTheAverageAndTheChangeAreRoundedHalfAwayFromZero(SalesTotals $current, SalesTotals $previous, PeriodSales $expected): void
    {
        self::assertEquals($expected, PeriodSales::compared($current, $previous));
    }

    private function paid(string $utc, int $gross): void
    {
        $this->sales->add(OrderId::fromString(Uuid::v7()->toRfc4122()), new \DateTimeImmutable($utc.'+00:00'), $gross);
    }

    private function summary(string $now): SalesSummary
    {
        return new GetSalesSummaryHandler($this->sales, new FixedOrderingClock(new \DateTimeImmutable($now.'+00:00')))(new GetSalesSummary());
    }
}

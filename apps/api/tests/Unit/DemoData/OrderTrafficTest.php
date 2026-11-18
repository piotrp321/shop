<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Orders\OrderTraffic;
use PHPUnit\Framework\TestCase;

/**
 * The placement time of an order is a non-decreasing function of its global index: the inverse of the traffic over the
 * three years before the anchor, which grows, peaks in November and December and follows the time of day.
 */
final class OrderTrafficTest extends TestCase
{
    private const int ORDERS = 20_000;

    private static function anchor(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-17T00:00:00Z');
    }

    public function testThePlacementTimeGrowsWithTheIndexInsideTheThreeYearsBeforeTheAnchor(): void
    {
        $traffic = new OrderTraffic(self::anchor(), self::ORDERS);

        $previous = null;
        for ($index = 0; $index < self::ORDERS; ++$index) {
            $at = $traffic->placedAt($index)->getTimestamp();
            if (null !== $previous) {
                self::assertGreaterThanOrEqual($previous, $at, 'index '.$index);
            }
            $previous = $at;
        }

        self::assertGreaterThanOrEqual(self::anchor()->modify('-1095 days')->getTimestamp(), $traffic->placedAt(0)->getTimestamp());
        self::assertLessThanOrEqual(self::anchor()->getTimestamp() - OrderTraffic::END_BEFORE_SECONDS, $traffic->placedAt(self::ORDERS - 1)->getTimestamp());
        self::assertSame(0, (int) $traffic->placedAt(0)->format('v'), 'Whole seconds, like the columns.');
    }

    public function testTheTrafficGrowsAndPeaksInNovemberAndDecember(): void
    {
        $traffic = new OrderTraffic(self::anchor(), self::ORDERS);
        $byMonth = [];
        $firstYear = $lastYear = 0;
        $byHour = array_fill(0, 24, 0);
        for ($index = 0; $index < self::ORDERS; ++$index) {
            $at = $traffic->placedAt($index);
            $byMonth[$at->format('Y-m')] = ($byMonth[$at->format('Y-m')] ?? 0) + 1;
            ++$byHour[(int) $at->format('G')];
            if ($at < self::anchor()->modify('-730 days')) {
                ++$firstYear;
            } elseif ($at >= self::anchor()->modify('-365 days')) {
                ++$lastYear;
            }
        }

        self::assertGreaterThan(1.6 * $firstYear, $lastYear);
        // December and November above the months around them, February (28 days) per day below December.
        self::assertGreaterThan($byMonth['2025-10'], $byMonth['2025-11']);
        self::assertGreaterThan(1.3 * $byMonth['2026-02'] / 28 * 31, $byMonth['2025-12']);
        self::assertGreaterThan(3 * ($byHour[3] + $byHour[4]), $byHour[20] + $byHour[21]);
    }

    public function testTheDepartmentsHaveTheirSeasons(): void
    {
        $january = new \DateTimeImmutable('2026-01-15T12:00:00Z');
        $july = new \DateTimeImmutable('2026-07-15T12:00:00Z');

        self::assertGreaterThan(3 * OrderTraffic::departmentWeight('sporty-zimowe', $july), OrderTraffic::departmentWeight('sporty-zimowe', $january));
        self::assertGreaterThan(3 * OrderTraffic::departmentWeight('sporty-wodne', $january), OrderTraffic::departmentWeight('sporty-wodne', $july));
        self::assertSame(1.0, OrderTraffic::departmentWeight('nieznany-dzial', $july));
    }

    public function testTheIndexAtAMomentIsTheFirstOrderPlacedThenOrLater(): void
    {
        $traffic = new OrderTraffic(self::anchor(), self::ORDERS);

        foreach (['-1100 days', '-700 days', '-90 days', '-3 days', '+1 day'] as $shift) {
            $moment = self::anchor()->modify($shift);
            $index = $traffic->indexAt($moment);
            self::assertGreaterThanOrEqual(0, $index);
            self::assertLessThanOrEqual(self::ORDERS, $index);
            if ($index < self::ORDERS) {
                self::assertGreaterThanOrEqual($moment->getTimestamp(), $traffic->placedAt($index)->getTimestamp(), $shift);
            }
            if ($index > 0) {
                self::assertLessThan($moment->getTimestamp(), $traffic->placedAt($index - 1)->getTimestamp(), $shift);
            }
        }
        self::assertSame(0, $traffic->indexAt(self::anchor()->modify('-1100 days')));
        self::assertSame(self::ORDERS, $traffic->indexAt(self::anchor()));
    }
}

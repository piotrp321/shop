<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Query\SalesPeriod;
use App\Ordering\Application\Query\SalesPeriods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The periods of the sales of the dashboard (spec ADMIN-FEATURES, „Sprzedaż”, P5): every one ends at the start of the
 * current hour; today runs from the Polish midnight, compared with yesterday from midnight to the same hour of the
 * Polish clock; 7 and 30 days are 168 and 720 hours, compared with as many hours before them.
 */
final class SalesPeriodsTest extends TestCase
{
    public function testAnOrdinaryAfternoonEndsAtTheStartOfTheHour(): void
    {
        // 14:35 in Warsaw (CET, UTC+1).
        $periods = SalesPeriods::endingAt(self::utc('2026-11-15 13:35:20'));

        self::assertEquals(self::utc('2026-11-15 13:00:00'), $periods->until);
        self::assertEquals(self::period('2026-11-14 23:00:00', '2026-11-15 13:00:00'), $periods->today);
        self::assertEquals(self::period('2026-11-13 23:00:00', '2026-11-14 13:00:00'), $periods->yesterday);
        self::assertEquals(self::period('2026-11-08 13:00:00', '2026-11-15 13:00:00'), $periods->last7Days);
        self::assertEquals(self::period('2026-11-01 13:00:00', '2026-11-08 13:00:00'), $periods->previous7Days);
        self::assertEquals(self::period('2026-10-16 13:00:00', '2026-11-15 13:00:00'), $periods->last30Days);
        self::assertEquals(self::period('2026-09-16 13:00:00', '2026-10-16 13:00:00'), $periods->previous30Days);
    }

    public function testAMomentInAnotherTimeZoneIsTheSameMoment(): void
    {
        self::assertEquals(
            SalesPeriods::endingAt(self::utc('2026-11-15 13:35:20')),
            SalesPeriods::endingAt(new \DateTimeImmutable('2026-11-15 14:35:20', new \DateTimeZone('Europe/Warsaw'))),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function firstHourOfTheDay(): iterable
    {
        yield 'midnight in Warsaw' => ['2026-11-14 23:00:00'];
        yield '0:30 in Warsaw' => ['2026-11-14 23:30:00'];
        yield 'a second before 1:00 in Warsaw' => ['2026-11-14 23:59:59'];
    }

    #[DataProvider('firstHourOfTheDay')]
    public function testBetweenMidnightAndOneTodayAndYesterdayAreEmpty(string $now): void
    {
        $periods = SalesPeriods::endingAt(self::utc($now));

        self::assertEquals(self::utc('2026-11-14 23:00:00'), $periods->until);
        self::assertNull($periods->today);
        self::assertNull($periods->yesterday);
        self::assertEquals(self::period('2026-11-07 23:00:00', '2026-11-14 23:00:00'), $periods->last7Days);
    }

    public function testAtOneTodayIsTheFirstHourComparedWithTheFirstHourOfYesterday(): void
    {
        $periods = SalesPeriods::endingAt(self::utc('2026-11-15 00:00:00'));

        self::assertEquals(self::period('2026-11-14 23:00:00', '2026-11-15 00:00:00'), $periods->today);
        self::assertEquals(self::period('2026-11-13 23:00:00', '2026-11-14 00:00:00'), $periods->yesterday);
    }

    public function testTheLastHourOfTheDayEndsTodayAtElevenAndYesterdayToo(): void
    {
        // 23:59 in Warsaw: the hour from 23:00 has not ended.
        $periods = SalesPeriods::endingAt(self::utc('2026-11-15 22:59:59'));

        self::assertEquals(self::period('2026-11-14 23:00:00', '2026-11-15 22:00:00'), $periods->today);
        self::assertEquals(self::period('2026-11-13 23:00:00', '2026-11-14 22:00:00'), $periods->yesterday);
    }

    public function testOnTheDayTheClockGoesForwardTodayAndYesterdayHaveTheSameClockHours(): void
    {
        // 29 March 2026, 14:20 CEST (UTC+2); the day had 23 hours (2:00 CET became 3:00 CEST).
        $periods = SalesPeriods::endingAt(self::utc('2026-03-29 12:20:00'));

        self::assertEquals(self::period('2026-03-28 23:00:00', '2026-03-29 12:00:00'), $periods->today, '0:00–14:00 of the clock, 13 hours');
        self::assertEquals(self::period('2026-03-27 23:00:00', '2026-03-28 13:00:00'), $periods->yesterday, '0:00–14:00 of the clock, 14 hours');
        self::assertSame(168 * 3600, self::seconds($periods->last7Days));
        self::assertSame(168 * 3600, self::seconds($periods->previous7Days));
        self::assertEquals($periods->last7Days->from, $periods->previous7Days->to);
        self::assertSame(720 * 3600, self::seconds($periods->last30Days));
        self::assertSame(720 * 3600, self::seconds($periods->previous30Days));
        self::assertEquals($periods->last30Days->from, $periods->previous30Days->to);
    }

    public function testOnTheDayAfterTheClockWentForwardYesterdayEndsAtTheHourThatFollowedTheGap(): void
    {
        // 30 March 2026, 2:30 CEST; on 29 March 2:00 did not exist, the clock went from 1:59:59 CET to 3:00 CEST.
        $periods = SalesPeriods::endingAt(self::utc('2026-03-30 00:30:00'));

        self::assertEquals(self::period('2026-03-29 22:00:00', '2026-03-30 00:00:00'), $periods->today);
        self::assertEquals(self::period('2026-03-28 23:00:00', '2026-03-29 01:00:00'), $periods->yesterday);
    }

    public function testOnTheDayTheClockGoesBackTodayAndYesterdayHaveTheSameClockHours(): void
    {
        // 25 October 2026, 14:20 CET (UTC+1); the day had 25 hours (3:00 CEST became 2:00 CET).
        $periods = SalesPeriods::endingAt(self::utc('2026-10-25 13:20:00'));

        self::assertEquals(self::period('2026-10-24 22:00:00', '2026-10-25 13:00:00'), $periods->today, '0:00–14:00 of the clock, 15 hours');
        self::assertEquals(self::period('2026-10-23 22:00:00', '2026-10-24 12:00:00'), $periods->yesterday, '0:00–14:00 of the clock, 14 hours');
        self::assertSame(168 * 3600, self::seconds($periods->last7Days));
        self::assertSame(168 * 3600, self::seconds($periods->previous7Days));
        self::assertSame(720 * 3600, self::seconds($periods->last30Days));
        self::assertSame(720 * 3600, self::seconds($periods->previous30Days));
    }

    public function testOnTheDayAfterTheClockWentBackTodayAndYesterdayRunToTheSameClockHour(): void
    {
        // 26 October 2026, 1:10 CET: yesterday from 0:00 CEST to 1:00 CEST.
        $periods = SalesPeriods::endingAt(self::utc('2026-10-26 00:10:00'));

        self::assertEquals(self::period('2026-10-25 23:00:00', '2026-10-26 00:00:00'), $periods->today);
        self::assertEquals(self::period('2026-10-24 22:00:00', '2026-10-24 23:00:00'), $periods->yesterday);
    }

    private static function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment.'+00:00');
    }

    private static function period(string $from, string $to): SalesPeriod
    {
        return new SalesPeriod(self::utc($from), self::utc($to));
    }

    private static function seconds(SalesPeriod $period): int
    {
        return $period->to->getTimestamp() - $period->from->getTimestamp();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Query\InvalidBackofficeOrderList;
use App\Ordering\Application\Query\OrderPlacedRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The days of the range of the order list are days of Europe/Warsaw, both ends included; the range is the instants
 * [start of the first day, start of the day after the last), in UTC.
 */
final class OrderPlacedRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, ?string, ?string}> from, to, the first instant, the first instant after
     */
    public static function ranges(): iterable
    {
        yield 'no range' => [null, null, null, null];
        yield 'a winter day (UTC+1)' => ['2026-01-15', '2026-01-15', '2026-01-14T23:00:00+00:00', '2026-01-15T23:00:00+00:00'];
        yield 'a summer day (UTC+2)' => ['2026-07-01', '2026-07-01', '2026-06-30T22:00:00+00:00', '2026-07-01T22:00:00+00:00'];
        yield 'only the start' => ['2026-07-01', null, '2026-06-30T22:00:00+00:00', null];
        yield 'only the end' => [null, '2026-07-01', null, '2026-07-01T22:00:00+00:00'];
        yield 'several days' => ['2026-01-15', '2026-01-17', '2026-01-14T23:00:00+00:00', '2026-01-17T23:00:00+00:00'];
        yield 'the day the clocks go forward (23 hours)' => ['2026-03-29', '2026-03-29', '2026-03-28T23:00:00+00:00', '2026-03-29T22:00:00+00:00'];
        yield 'the day the clocks go back (25 hours)' => ['2026-10-25', '2026-10-25', '2026-10-24T22:00:00+00:00', '2026-10-25T23:00:00+00:00'];
        yield 'across the change forward' => ['2026-03-28', '2026-03-30', '2026-03-27T23:00:00+00:00', '2026-03-30T22:00:00+00:00'];
        yield 'the end of a year' => ['2026-12-31', '2026-12-31', '2026-12-30T23:00:00+00:00', '2026-12-31T23:00:00+00:00'];
        yield 'a leap day' => ['2028-02-29', '2028-02-29', '2028-02-28T23:00:00+00:00', '2028-02-29T23:00:00+00:00'];
    }

    #[DataProvider('ranges')]
    public function testTheDaysBecomeInstantsOfUtc(?string $from, ?string $to, ?string $start, ?string $end): void
    {
        $range = OrderPlacedRange::fromDays($from, $to);

        self::assertSame($start, $range->from?->format(\DATE_ATOM));
        self::assertSame($end, $range->before?->format(\DATE_ATOM));
        self::assertSame(null === $start && null === $end, $range->isEmpty());
        self::assertSame('UTC', ($range->from ?? $range->before)?->getTimezone()->getName() ?? 'UTC');
    }

    public function testADayIsFromMidnightToTheLastInstantBeforeTheNextMidnightOfWarsaw(): void
    {
        $range = OrderPlacedRange::fromDays('2026-03-29', '2026-03-29');

        self::assertNotNull($range->from);
        self::assertNotNull($range->before);
        self::assertSame(23 * 3600, $range->before->getTimestamp() - $range->from->getTimestamp());
    }

    public function testOneDayRangeIsNotInverted(): void
    {
        self::assertFalse(OrderPlacedRange::fromDays('2026-05-05', '2026-05-05')->isEmpty());
    }

    public function testAnInvertedRangeIsRefused(): void
    {
        $this->expectException(InvalidBackofficeOrderList::class);

        OrderPlacedRange::fromDays('2026-05-06', '2026-05-05');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDays(): iterable
    {
        yield 'empty' => [''];
        yield 'another format' => ['15.01.2026'];
        yield 'a date and a time' => ['2026-01-15T10:00'];
        yield 'spaces around' => [' 2026-01-15'];
        yield 'a line break at the end' => ["2026-01-15\n"];
        yield 'a month 13' => ['2026-13-01'];
        yield 'a day that is not' => ['2026-02-30'];
        yield 'a leap day of a common year' => ['2027-02-29'];
        yield 'a year of two digits' => ['26-01-15'];
        yield 'a year before 2000' => ['1999-12-31'];
        yield 'a far year' => ['3000-01-01'];
        yield 'digits of another script' => ['٢٠٢٦-٠١-١٥'];
        yield 'a NUL' => ["2026-01-1\0"];
    }

    #[DataProvider('badDays')]
    public function testADayThatIsNoDateIsRefused(string $day): void
    {
        self::assertNull(OrderPlacedRange::dayStart($day));
        $this->expectException(InvalidBackofficeOrderList::class);

        OrderPlacedRange::fromDays($day, null);
    }

    public function testTheEndOfTheRangeRefusesABadDayToo(): void
    {
        $this->expectException(InvalidBackofficeOrderList::class);

        OrderPlacedRange::fromDays(null, '2026-02-30');
    }
}

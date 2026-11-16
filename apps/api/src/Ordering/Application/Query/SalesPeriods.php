<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The periods of the sales of the dashboard and the periods they are compared with (spec ADMIN-FEATURES, „Sprzedaż”,
 * P5). Only completed hours count, so every period ends at the start of the current hour (`until`).
 *
 * Today runs from the midnight of the Polish clock and is compared with yesterday from midnight to the same hour of
 * the clock; on the days the clock changes the two may differ by an hour of real time, the only inequality. Between
 * 0:00 and 1:00 today has no completed hour: both are null. The 7 and 30 days are the last 168 and 720 hours, compared
 * with as many hours before them, so they are of equal length whatever the clock does. A whole hour of UTC is a whole
 * hour of the Polish clock (the offsets are whole hours), so the hours of the sales are UTC hours.
 */
final readonly class SalesPeriods
{
    private const string CLOCK = 'Europe/Warsaw';
    private const int HOUR = 3600;
    private const int WEEK_HOURS = 168;
    private const int MONTH_HOURS = 720;

    private function __construct(
        public \DateTimeImmutable $until,
        public ?SalesPeriod $today,
        public ?SalesPeriod $yesterday,
        public SalesPeriod $last7Days,
        public SalesPeriod $previous7Days,
        public SalesPeriod $last30Days,
        public SalesPeriod $previous30Days,
    ) {
    }

    public static function endingAt(\DateTimeImmutable $now): self
    {
        $until = self::utc(intdiv($now->getTimestamp(), self::HOUR) * self::HOUR);
        $clock = new \DateTimeZone(self::CLOCK);
        $local = $until->setTimezone($clock);
        $midnight = new \DateTimeImmutable($local->format('Y-m-d').' 00:00:00', $clock);

        $today = null;
        $yesterday = null;
        if ($midnight < $until) {
            $today = new SalesPeriod(self::utc($midnight->getTimestamp()), $until);
            $previousDay = $midnight->modify('-1 day')->format('Y-m-d');
            // The same hour of the clock a day before; an hour the clock skipped that day is the hour after the gap.
            $yesterday = new SalesPeriod(
                self::utc(new \DateTimeImmutable($previousDay.' 00:00:00', $clock)->getTimestamp()),
                self::utc(new \DateTimeImmutable($previousDay.' '.$local->format('H').':00:00', $clock)->getTimestamp()),
            );
        }

        return new self(
            $until,
            $today,
            $yesterday,
            self::hoursBefore($until, self::WEEK_HOURS),
            self::hoursBefore($until, 2 * self::WEEK_HOURS, self::WEEK_HOURS),
            self::hoursBefore($until, self::MONTH_HOURS),
            self::hoursBefore($until, 2 * self::MONTH_HOURS, self::MONTH_HOURS),
        );
    }

    /**
     * The period from $from hours before $until to $to hours before it.
     */
    private static function hoursBefore(\DateTimeImmutable $until, int $from, int $to = 0): SalesPeriod
    {
        return new SalesPeriod(
            self::utc($until->getTimestamp() - $from * self::HOUR),
            self::utc($until->getTimestamp() - $to * self::HOUR),
        );
    }

    private static function utc(int $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$timestamp);
    }
}

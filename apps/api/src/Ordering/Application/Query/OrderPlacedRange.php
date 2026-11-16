<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The days of the order list between which orders were placed: days of Europe/Warsaw (`YYYY-MM-DD`, both ends
 * included), as the instants [`from`, `before`) in UTC. A day is from its midnight to the next one, so the day the
 * clocks change has 23 or 25 hours.
 */
final readonly class OrderPlacedRange
{
    private const string TIME_ZONE = 'Europe/Warsaw';
    private const string DAY = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D';
    private const int FIRST_YEAR = 2000;
    private const int LAST_YEAR = 2999;

    private function __construct(
        /** The first instant of the range, null when it has no start. */
        public ?\DateTimeImmutable $from,
        /** The first instant after the range, null when it has no end. */
        public ?\DateTimeImmutable $before,
    ) {
    }

    /**
     * @throws InvalidBackofficeOrderList a day that is no date, or a start after the end
     */
    public static function fromDays(?string $from, ?string $to): self
    {
        $start = null === $from ? null : self::dayStart($from) ?? throw InvalidBackofficeOrderList::placedDay();
        $last = null === $to ? null : self::dayStart($to) ?? throw InvalidBackofficeOrderList::placedDay();
        if (null !== $start && null !== $last && $start > $last) {
            throw InvalidBackofficeOrderList::invertedRange();
        }

        return new self(
            $start?->setTimezone(new \DateTimeZone('UTC')),
            $last?->modify('+1 day')->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    /**
     * The midnight of the day in Warsaw, null for text that is not a day (a format, a date that does not exist, a year
     * outside 2000–2999).
     */
    public static function dayStart(string $day): ?\DateTimeImmutable
    {
        if (1 !== preg_match(self::DAY, $day, $parts)) {
            return null;
        }
        [, $year, $month, $date] = array_map(intval(...), $parts);
        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR || !checkdate($month, $date, $year)) {
            return null;
        }

        return new \DateTimeImmutable($day.' 00:00:00', new \DateTimeZone(self::TIME_ZONE));
    }

    public function isEmpty(): bool
    {
        return null === $this->from && null === $this->before;
    }
}

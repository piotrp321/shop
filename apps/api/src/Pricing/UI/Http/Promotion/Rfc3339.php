<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

/**
 * A moment of a request: RFC 3339 with a time zone (`Z` or an offset), seconds and an optional fraction. Anything else,
 * also an impossible date or time ("2026-02-31", "24:00"), is no moment: PHP would move it to another one. So is a
 * moment whose year in UTC is outside 1..9999 ("9999-12-31T23:30:00-05:00" is already in the year 10000): PostgreSQL
 * stores it, but Doctrine cannot read it back.
 */
final class Rfc3339
{
    private const string PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D';

    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match(self::PATTERN, $value, $parts)) {
            return null;
        }
        [, $year, $month, $day, $hour, $minute, $second] = $parts;
        if (!checkdate((int) $month, (int) $day, (int) $year) || (int) $year < 1 || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            return null;
        }
        $offset = $parts[8];
        if ('Z' !== $offset && ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59)) {
            return null;
        }

        $moment = new \DateTimeImmutable($value);
        $utcYear = (int) $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y');
        if ($utcYear < 1 || $utcYear > 9999) {
            return null;
        }

        return $moment;
    }
}

<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Provider;

/**
 * Amounts of demo-card as decimal text ("123.45") from and to minor units, by integer arithmetic only (never a
 * float).
 */
final class CardAmount
{
    /**
     * @throws \InvalidArgumentException a negative amount
     */
    public static function format(int $minorUnits): string
    {
        if ($minorUnits < 0) {
            throw new \InvalidArgumentException('An amount of demo-card is not negative.');
        }

        return \sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }

    /**
     * Exactly `^(0|[1-9][0-9]*)\.[0-9]{2}$` and within an int; anything else ("1.5", "-1.00", "01.00") is null.
     */
    public static function parse(string $text): ?int
    {
        if (1 !== preg_match('/\A(0|[1-9][0-9]*)\.([0-9]{2})\z/', $text, $parts)) {
            return null;
        }
        $whole = $parts[1];
        $fraction = (int) $parts[2];
        if ((string) (int) $whole !== $whole || (int) $whole > intdiv(\PHP_INT_MAX - $fraction, 100)) {
            return null;
        }

        return (int) $whole * 100 + $fraction;
    }
}

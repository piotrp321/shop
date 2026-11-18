<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

/**
 * The retail price ladder of a price range (spec: "Realizm", "Ceny"): the round prices in whole zlotys that a shop puts
 * on its goods, spaced wider as the price grows (5 zl below 50, 10 below 200, 25 below 500, 50 below 1000, 100 above).
 * A price is a rung with one of the two retail endings: `,99` (a grosz below the rung) or `,00`.
 */
final class PriceLadder
{
    private function __construct()
    {
    }

    /**
     * The rungs inside the range, ascending; the lowest one at or above the minimum. A range with no round price in it
     * has one rung, its minimum.
     *
     * @return non-empty-list<int> zlotys
     *
     * @throws \InvalidArgumentException for a range that starts below 1 zl or ends before it starts
     */
    public static function rungs(int $minZloty, int $maxZloty): array
    {
        if ($minZloty < 1 || $maxZloty < $minZloty) {
            throw new \InvalidArgumentException('A price range starts at 1 zloty and does not end before it starts.');
        }

        $rungs = [];
        for ($rung = self::roundUp($minZloty, self::step($minZloty)); $rung <= $maxZloty; $rung = self::roundUp($rung + 1, self::step($rung + 1))) {
            $rungs[] = $rung;
        }

        return [] === $rungs ? [$minZloty] : $rungs;
    }

    /**
     * The price in grosze of a rung with its ending.
     */
    public static function gross(int $rungZloty, bool $ninetyNine): int
    {
        return $rungZloty * 100 - ($ninetyNine ? 1 : 0);
    }

    private static function step(int $zloty): int
    {
        return match (true) {
            $zloty < 50 => 5,
            $zloty < 200 => 10,
            $zloty < 500 => 25,
            $zloty < 1_000 => 50,
            default => 100,
        };
    }

    private static function roundUp(int $value, int $step): int
    {
        return intdiv($value + $step - 1, $step) * $step;
    }
}

<?php

declare(strict_types=1);

namespace App\DemoData\Verify;

/**
 * The line of a violation that names what it found: the first few items and "and more" when there are more.
 */
final class Listing
{
    public const int SHOWN = 10;

    private function __construct()
    {
    }

    /**
     * @param list<string> $items the items found, at most one more than shown is enough to say "and more"
     *
     * @return list<string> the line, or none when nothing was found
     */
    public static function of(string $what, array $items): array
    {
        if ([] === $items) {
            return [];
        }
        $shown = \array_slice($items, 0, self::SHOWN);

        return [\sprintf('%s: %s%s.', $what, implode(', ', $shown), \count($items) > self::SHOWN ? ' and more' : '')];
    }
}

<?php

declare(strict_types=1);

namespace App\DemoData\Time;

/**
 * The reference moment of a run: every time the generator writes is counted from it, never from the clock. The
 * default is the start of the current UTC day.
 */
final class Anchor
{
    private function __construct()
    {
    }

    /**
     * @param string|null $option a date or a date with a time and an offset, as given to --anchor
     *
     * @throws \InvalidArgumentException when the option is not a date
     */
    public static function resolve(?string $option, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if (null === $option || '' === $option) {
            return $now->setTimezone($utc)->setTime(0, 0);
        }

        try {
            return new \DateTimeImmutable($option, $utc)->setTimezone($utc);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(\sprintf('The anchor "%s" is not a date.', $option), 0, $e);
        }
    }
}

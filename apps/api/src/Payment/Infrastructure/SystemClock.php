<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Payment\Application\Port\Clock;

/**
 * The current time in UTC, to the second, as the timestamp columns of a payment keep it.
 */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.time());
    }
}

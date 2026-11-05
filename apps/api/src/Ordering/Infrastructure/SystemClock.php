<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure;

use App\Ordering\Application\Port\Clock;

/**
 * The current time in UTC, to the second: what the timestamp columns of an order keep, so a replayed order reads
 * back the moment the first response gave.
 */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.time());
    }
}

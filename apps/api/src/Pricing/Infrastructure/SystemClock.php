<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure;

use App\Pricing\Application\Port\Clock;

final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

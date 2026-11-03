<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure;

use App\Cart\Application\Port\Clock;

final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

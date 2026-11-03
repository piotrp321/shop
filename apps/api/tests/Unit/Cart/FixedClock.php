<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Port\Clock;

final class FixedClock implements Clock
{
    public function __construct(public \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

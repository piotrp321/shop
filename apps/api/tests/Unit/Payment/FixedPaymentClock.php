<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Application\Port\Clock;

final class FixedPaymentClock implements Clock
{
    public function __construct(public \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

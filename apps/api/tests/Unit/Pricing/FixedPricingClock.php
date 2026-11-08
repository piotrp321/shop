<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\Clock;

final class FixedPricingClock implements Clock
{
    public \DateTimeImmutable $now;

    public function __construct(string $now, private readonly PricingJournal $journal = new PricingJournal())
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        $this->journal->add('clock');

        return $this->now;
    }
}

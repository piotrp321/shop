<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\Clock;

/**
 * A clock that stands still until the test moves it.
 */
final class FixedCatalogClock implements Clock
{
    public function __construct(public \DateTimeImmutable $now = new \DateTimeImmutable('2026-11-06T12:00:00.123456+00:00'))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}

<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Event\DomainEvent;

/**
 * The availability level of a variant changed (AvailabilityLevel::of(available)); $level is the level after the
 * change. The level is all the store shows of a stock, so a change within one level publishes nothing. Written to
 * the outbox in the transaction of the save, so a rolled back change leaves no event.
 */
final readonly class StockLevelChanged implements DomainEvent
{
    public function __construct(
        public string $variantId,
        public AvailabilityLevel $level,
        public StockChangeSource $source,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

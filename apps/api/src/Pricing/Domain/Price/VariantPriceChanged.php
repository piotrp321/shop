<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Shared\Domain\Event\DomainEvent;

/**
 * The stored price of a variant changed (a new price, a new gross, a new class or rate). Written to the outbox in the
 * transaction of the save, so a refused or rolled back save leaves no event. The source tells a change from the
 * panel, the demo data and the tax reconciliation apart.
 */
final readonly class VariantPriceChanged implements DomainEvent
{
    public function __construct(
        public string $variantId,
        public PriceChangeSource $source,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

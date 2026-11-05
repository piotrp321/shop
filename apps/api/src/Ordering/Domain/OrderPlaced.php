<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Event\DomainEvent;

/**
 * An order was placed. Written to the outbox in the transaction of the placement, so a rolled back placement leaves
 * no event.
 */
final readonly class OrderPlaced implements DomainEvent
{
    public function __construct(public string $orderId, public \DateTimeImmutable $occurredAt)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

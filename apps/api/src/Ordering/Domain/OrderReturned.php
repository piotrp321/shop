<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Event\DomainEvent;

/**
 * A staff member registered the return of a shipped or delivered order (its refund is due). Written to the outbox in
 * the transaction that registered it.
 */
final readonly class OrderReturned implements DomainEvent
{
    public function __construct(public string $orderId, public \DateTimeImmutable $occurredAt)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

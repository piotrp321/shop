<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Event\DomainEvent;

/**
 * An order was paid. Written to the outbox in the transaction that paid it.
 */
final readonly class OrderPaid implements DomainEvent
{
    public function __construct(public string $orderId, public \DateTimeImmutable $occurredAt)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

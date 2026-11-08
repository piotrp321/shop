<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Event\DomainEvent;

/**
 * A staff member shipped an order. Written to the outbox in the transaction that shipped it and committed its
 * reservation.
 */
final readonly class OrderShipped implements DomainEvent
{
    public function __construct(public string $orderId, public \DateTimeImmutable $occurredAt)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

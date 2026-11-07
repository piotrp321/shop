<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Event\DomainEvent;

/**
 * An order was cancelled, with the reason (a value of CancellationReason). Written to the outbox in the transaction
 * that cancelled it and released its reservation.
 */
final readonly class OrderCancelled implements DomainEvent
{
    public function __construct(
        public string $orderId,
        public string $reason,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * An entry of the history of the statuses of an order: from (null for the placement) and to, when, who and the note
 * of a staff member. Entries are only appended.
 */
final readonly class OrderStatusChange
{
    public function __construct(
        private ?OrderStatus $from,
        private OrderStatus $to,
        private \DateTimeImmutable $occurredAt,
        private StatusActor $actor,
        private ?string $note,
    ) {
    }

    public function from(): ?OrderStatus
    {
        return $this->from;
    }

    public function to(): OrderStatus
    {
        return $this->to;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actor(): StatusActor
    {
        return $this->actor;
    }

    public function note(): ?string
    {
        return $this->note;
    }
}

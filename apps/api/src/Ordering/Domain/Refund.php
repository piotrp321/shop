<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The money an order owes its customer back after a cancellation by staff or a return: the gross total, due by a
 * date. It is pending until a staff member marks it completed (the transfer itself is manual, outside the system);
 * the completion records when, who and an optional note.
 */
final readonly class Refund
{
    private function __construct(
        private Money $amount,
        private \DateTimeImmutable $dueAt,
        private ?\DateTimeImmutable $completedAt,
        private ?StaffActor $completedBy,
        private ?string $note,
    ) {
    }

    public static function due(Money $amount, \DateTimeImmutable $dueAt): self
    {
        return new self($amount, $dueAt, null, null, null);
    }

    /**
     * The refund as stored: pending without the completion, completed with its moment and staff member.
     */
    public static function reconstitute(Money $amount, \DateTimeImmutable $dueAt, ?\DateTimeImmutable $completedAt, ?StaffActor $completedBy, ?string $note): self
    {
        if ((null === $completedAt) !== (null === $completedBy) || (null === $completedAt && null !== $note)) {
            throw new \InvalidArgumentException('A completed refund has its moment and staff member, a pending one neither nor a note.');
        }

        return new self($amount, $dueAt, $completedAt, $completedBy, $note);
    }

    /**
     * @throws RefundNotPending the refund is already completed
     */
    public function complete(StaffActor $staff, ?string $note, \DateTimeImmutable $now): self
    {
        if (!$this->isPending()) {
            throw new RefundNotPending();
        }

        return new self($this->amount, $this->dueAt, $now, $staff, $note);
    }

    public function isPending(): bool
    {
        return null === $this->completedAt;
    }

    /**
     * Pending past its due date.
     */
    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return $this->isPending() && $now > $this->dueAt;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function dueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function completedBy(): ?StaffActor
    {
        return $this->completedBy;
    }

    public function note(): ?string
    {
        return $this->note;
    }
}

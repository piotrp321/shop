<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

/**
 * The refund of an order for the panel: the amount in grosze, the due date, whether it is overdue now (pending past
 * the due date, computed by the API), and its completion (null while pending).
 */
final readonly class RefundView
{
    public function __construct(
        public int $amount,
        public string $currency,
        public \DateTimeImmutable $dueAt,
        public bool $overdue,
        public ?\DateTimeImmutable $completedAt,
        public ?string $completedByStaffId,
        public ?string $completedByUsername,
        public ?string $note,
    ) {
    }
}

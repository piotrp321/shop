<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The refund of a listed order: the amount in grosze (in the currency of the order), the due date, the completion
 * (null while pending), and whether it is overdue now (pending past the due date, computed by the API).
 */
final readonly class BackofficeOrderListRefund
{
    public function __construct(
        public int $amount,
        public \DateTimeImmutable $dueAt,
        public ?\DateTimeImmutable $completedAt,
        public bool $overdue,
    ) {
    }
}

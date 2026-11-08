<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * An order as the list of the panel reads it: amounts in grosze, the number of units of its lines, and its refund as
 * stored (amount, due date and completion all null without one).
 */
final readonly class ListedOrder
{
    public function __construct(
        public string $orderId,
        public string $number,
        public \DateTimeImmutable $placedAt,
        public string $status,
        public string $email,
        public string $fullName,
        public int $totalGross,
        public string $currency,
        public int $itemCount,
        public ?int $refundAmount,
        public ?\DateTimeImmutable $refundDueAt,
        public ?\DateTimeImmutable $refundCompletedAt,
    ) {
    }
}

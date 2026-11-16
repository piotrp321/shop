<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * The summary of one customer: the contact of the newest order, the numbers and the moments of the orders. Amounts in
 * grosze.
 */
final readonly class CustomerSummary
{
    public function __construct(
        public string $customerId,
        public string $email,
        public string $fullName,
        public string $phone,
        public int $orderCount,
        public int $paidCount,
        public int $paidGross,
        public \DateTimeImmutable $firstOrderAt,
        public \DateTimeImmutable $lastOrderAt,
    ) {
    }
}

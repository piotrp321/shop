<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * A customer as the list of the panel reads it from the customer summaries. Amounts in grosze.
 */
final readonly class ListedCustomer
{
    /**
     * @param string $sortName the name as the sort by name compares it (lower(full_name) of the database)
     */
    public function __construct(
        public string $customerId,
        public string $email,
        public string $fullName,
        public int $orderCount,
        public int $paidGross,
        public \DateTimeImmutable $lastOrderAt,
        public string $sortName,
    ) {
    }
}

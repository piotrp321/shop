<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * A customer in the list of the panel. Amounts in grosze.
 */
final readonly class BackofficeCustomerListItem
{
    public function __construct(
        public string $customerId,
        public string $email,
        public string $fullName,
        public int $orderCount,
        public int $paidGross,
        public string $currency,
        public \DateTimeImmutable $lastOrderAt,
    ) {
    }
}

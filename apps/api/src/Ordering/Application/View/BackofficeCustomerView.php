<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

/**
 * A customer as the card of the panel shows it: the contact of the newest order, the summary of the orders and the
 * distinct shipping addresses of the last orders, the one used last first. Amounts in grosze.
 */
final readonly class BackofficeCustomerView
{
    /**
     * @param list<ShippingAddressView> $addresses
     */
    public function __construct(
        public string $customerId,
        public string $email,
        public string $fullName,
        public string $phone,
        public int $orderCount,
        public int $paidCount,
        public int $paidGross,
        public string $currency,
        public \DateTimeImmutable $firstOrderAt,
        public \DateTimeImmutable $lastOrderAt,
        public array $addresses,
    ) {
    }
}

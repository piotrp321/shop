<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * An order in the list of the panel. The gross total is in grosze; the item count is the number of units of its lines.
 */
final readonly class BackofficeOrderListItem
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
        public ?BackofficeOrderListRefund $refund,
    ) {
    }
}

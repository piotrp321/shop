<?php

declare(strict_types=1);

namespace App\Payment\Application\Query;

use App\Payment\Application\PaymentAccess;

/**
 * The status of the payment of an order, for a caller with access to it (the confirmation page of the store).
 */
final readonly class GetPaymentStatus
{
    public function __construct(
        public string $orderId,
        public PaymentAccess $access,
    ) {
    }
}

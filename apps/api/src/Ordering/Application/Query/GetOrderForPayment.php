<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\OrderAccess;

/**
 * What Payment needs of an order to start a payment and to show its status, for a caller with access to it.
 */
final readonly class GetOrderForPayment
{
    public function __construct(
        public string $orderId,
        public OrderAccess $access,
    ) {
    }
}

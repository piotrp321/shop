<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderState;

/**
 * Whether an order can still be paid (new and before its deadline, decided by Ordering) and its status, as
 * payment:close-abandoned learns it: nothing else of the order.
 */
final readonly class OrderPayability
{
    public function __construct(
        public OrderId $orderId,
        public OrderState $state,
        public bool $payable,
    ) {
    }
}

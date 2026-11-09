<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

/**
 * Gives the usage of a code by an order back (decision D4): dispatched nested in the transaction of Ordering's
 * ExpireUnpaidOrder, also for an order without a code (then without effect).
 */
final readonly class ReleaseDiscountCodeUsage
{
    public function __construct(public string $orderId)
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

/**
 * Cancels the order if it is still unpaid once its deadline and the window for the webhooks have passed. Sent with a
 * delay (transport `scheduled`) by the placement, and again by its handler when it came too early.
 */
final readonly class ExpireUnpaidOrder
{
    public function __construct(public string $orderId)
    {
    }
}

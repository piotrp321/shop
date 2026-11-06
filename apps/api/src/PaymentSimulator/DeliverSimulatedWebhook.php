<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * Deliver the webhook of a decided transaction to the shop. Stored on the transport `payment_simulator` in the
 * transaction of the decision; the worker delivers it, a failed delivery is retried with the same event id.
 */
final readonly class DeliverSimulatedWebhook
{
    public function __construct(public string $transactionId)
    {
    }
}

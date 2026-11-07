<?php

declare(strict_types=1);

namespace App\Payment\Application\Command;

use App\Payment\Application\PaymentAccess;

/**
 * Starts a payment of an order at a provider. The amount comes from the order, never from the request.
 */
final readonly class StartPayment
{
    /**
     * @param string $provider       `demo-transfer` or `demo-card`
     * @param string $idempotencyKey 1 to 100 characters [A-Za-z0-9._:-]
     */
    public function __construct(
        public string $orderId,
        public PaymentAccess $access,
        public string $provider,
        public string $idempotencyKey,
    ) {
    }
}

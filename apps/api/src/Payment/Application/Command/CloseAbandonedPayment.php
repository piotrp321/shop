<?php

declare(strict_types=1);

namespace App\Payment\Application\Command;

/**
 * Closes the pending payment $paymentId of the order $orderId, which Ordering no longer lets pay, as chosen by
 * payment:close-abandoned (docs/features/async-reliability/spec.md, D5, W6). Synchronous: no route in messenger.yaml.
 */
final readonly class CloseAbandonedPayment
{
    public function __construct(
        public string $orderId,
        public string $paymentId,
    ) {
    }
}

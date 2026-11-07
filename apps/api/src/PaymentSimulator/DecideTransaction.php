<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * The customer pays or rejects on the payment page of a provider. Handled on command.bus, so the new state and the
 * delivery of its webhook are written in one transaction.
 */
final readonly class DecideTransaction
{
    public function __construct(
        public string $transactionId,
        public Provider $provider,
        public bool $pay,
    ) {
    }
}

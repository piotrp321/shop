<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * The shop cancels the session of a transaction through the API of the provider.
 */
final readonly class CancelTransaction
{
    public function __construct(
        public string $transactionId,
        public Provider $provider,
    ) {
    }
}

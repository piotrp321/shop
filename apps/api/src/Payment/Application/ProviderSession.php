<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Payment\Domain\ProviderPaymentId;

/**
 * The session a provider opened for a payment: its id there and the page of the provider the customer goes to.
 */
final readonly class ProviderSession
{
    public function __construct(
        public ProviderPaymentId $providerPaymentId,
        public string $redirectUrl,
    ) {
    }
}

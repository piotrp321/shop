<?php

declare(strict_types=1);

namespace App\Payment\Application\Command;

use App\Payment\Application\ProviderEvent;
use App\Payment\Domain\ProviderCode;

/**
 * A webhook event of a provider, its signature verified and its body read by the adapter of the provider.
 */
final readonly class HandleProviderWebhook
{
    public function __construct(
        public ProviderCode $provider,
        public ProviderEvent $event,
    ) {
    }
}

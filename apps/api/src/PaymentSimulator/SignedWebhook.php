<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * A request to deliver: the path under PAYMENT_WEBHOOK_BASE_URL, the raw body and the headers, signature included.
 */
final readonly class SignedWebhook
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $path,
        public string $body,
        public array $headers,
    ) {
    }
}

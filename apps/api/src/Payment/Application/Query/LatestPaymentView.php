<?php

declare(strict_types=1);

namespace App\Payment\Application\Query;

/**
 * The newest payment of an order; `resumeUrl` leads back to its session while it is pending and the order payable.
 */
final readonly class LatestPaymentView
{
    public function __construct(
        public string $provider,
        public string $status,
        public ?string $resumeUrl,
    ) {
    }
}

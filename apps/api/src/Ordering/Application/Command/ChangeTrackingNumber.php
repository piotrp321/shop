<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

/**
 * A staff member adds, replaces or removes (null or blank) the tracking number of a shipped or delivered order they
 * saw at expectedVersion.
 */
final readonly class ChangeTrackingNumber
{
    public function __construct(
        public string $orderId,
        public int $expectedVersion,
        public ?string $trackingNumber,
    ) {
    }
}

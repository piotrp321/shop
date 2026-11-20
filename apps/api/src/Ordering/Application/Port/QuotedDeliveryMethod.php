<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * An active delivery method quoted for the goods of an order: what the order copies of it and its cost in grosze (0
 * from its threshold or for a free method).
 */
final readonly class QuotedDeliveryMethod
{
    public function __construct(
        public string $methodId,
        public string $name,
        public ?string $trackingUrlTemplate,
        public int $costGross,
    ) {
    }
}

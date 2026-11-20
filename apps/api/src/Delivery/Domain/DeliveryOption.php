<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * An active method in a quote: its cost for the goods and the amount missing to make it free (null when nothing is
 * missing or it has no threshold).
 */
final readonly class DeliveryOption
{
    public function __construct(
        public DeliveryMethod $method,
        public int $costGross,
        public ?int $missingToFreeGross,
    ) {
    }
}

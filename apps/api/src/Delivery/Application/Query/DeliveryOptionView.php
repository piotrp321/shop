<?php

declare(strict_types=1);

namespace App\Delivery\Application\Query;

/**
 * An active method in a quote, with what an order copies of it (the name and the tracking template). Amounts in
 * grosze.
 */
final readonly class DeliveryOptionView
{
    /**
     * @param string      $description         empty without a description
     * @param string|null $trackingUrlTemplate null without a template; never shown in the store
     * @param int         $costGross           0 from the threshold (inclusive) or for a free method
     * @param int|null    $missingToFreeGross  null when the cost is 0 or the method has no threshold
     */
    public function __construct(
        public string $methodId,
        public string $name,
        public string $description,
        public ?string $trackingUrlTemplate,
        public int $costGross,
        public ?int $freeFromGross,
        public ?int $missingToFreeGross,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

/**
 * An active delivery method with its cost for the goods of a cart. Amounts in grosze. The tracking template of the
 * method is not part of it: the cart never shows it.
 */
final readonly class CartDeliveryOption
{
    /**
     * @param string   $description        empty without a description
     * @param int      $costGross          0 from the threshold of free delivery (inclusive) or for a free method
     * @param int|null $missingToFreeGross null when the cost is 0 or the method has no threshold
     */
    public function __construct(
        public string $methodId,
        public string $name,
        public string $description,
        public int $costGross,
        public ?int $freeFromGross,
        public ?int $missingToFreeGross,
    ) {
    }
}

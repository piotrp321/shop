<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * A delivery method of the cart. `totalGross` is the total of the cart with this method, `totals.gross` plus
 * `costGross`: to show, since the placement of an order checks the total again.
 */
final readonly class CartDeliveryOptionView
{
    public function __construct(
        public string $methodId,
        public string $name,
        public string $description,
        public int $costGross,
        public ?int $freeFromGross,
        public ?int $missingToFreeGross,
        public int $totalGross,
    ) {
    }
}

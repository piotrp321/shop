<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * The delivery of a cart with a purchasable line, computed by the API. Amounts in grosze. Without active methods
 * `options` is empty and the amounts are null.
 */
final readonly class CartDeliveryView
{
    /**
     * @param int|null                     $fromGross          the cheapest cost of an option
     * @param int|null                     $missingToFreeGross the smallest amount missing to make an option free
     * @param list<CartDeliveryOptionView> $options            the active methods, by position, then by name
     */
    public function __construct(
        public ?int $fromGross,
        public ?int $missingToFreeGross,
        public array $options,
    ) {
    }
}

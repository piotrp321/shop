<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

/**
 * The answer of CartDelivery. Amounts in grosze.
 */
final readonly class CartDeliveryQuote
{
    /**
     * @param int|null                 $fromGross          the cheapest cost of an option; null without options
     * @param int|null                 $missingToFreeGross the smallest amount missing to make an option free; null without
     *                                                     options, when an option is free already or none has a threshold
     * @param list<CartDeliveryOption> $options            the active methods, in the order of Delivery
     */
    public function __construct(
        public ?int $fromGross,
        public ?int $missingToFreeGross,
        public array $options,
    ) {
    }
}

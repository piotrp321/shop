<?php

declare(strict_types=1);

namespace App\Delivery\Application\Query;

/**
 * The answer to QuoteDelivery (W2). Amounts in grosze.
 */
final readonly class DeliveryQuoteView
{
    /**
     * @param int|null                 $fromGross          the cheapest cost of an option; null without options
     * @param int|null                 $missingToFreeGross the smallest amount missing to make an option free; null without
     *                                                     options, when an option is free already or none has a threshold
     * @param list<DeliveryOptionView> $options            the active methods, by position, then by name
     */
    public function __construct(
        public ?int $fromGross,
        public ?int $missingToFreeGross,
        public array $options,
    ) {
    }
}

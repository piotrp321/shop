<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * A variant of a product in the price list, with its price.
 */
final readonly class PriceListVariant
{
    /**
     * @param string           $label the values of the variant joined by ", ", empty for the default variant
     * @param ListedPrice|null $price null when the variant has no price yet
     */
    public function __construct(
        public string $variantId,
        public string $label,
        public ?ListedPrice $price,
    ) {
    }
}

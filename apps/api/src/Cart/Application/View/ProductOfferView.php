<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * The offer of a product of the list. `fromGross` is the lowest gross price (in grosze) of the purchasable variants,
 * null without one; `fromPromotion` the promotion of the variant that gives it (null outside one); `hasPriceRange`
 * whether their prices differ; `level` the best level among them, `out_of_stock` without one. A product unknown to
 * Catalog has no axes and no variants. Never a number of units.
 */
final readonly class ProductOfferView
{
    /**
     * @param list<string>           $variantAxes
     * @param list<VariantOfferView> $variants    in presentation order
     */
    public function __construct(
        public string $productId,
        public array $variantAxes,
        public ?int $fromGross,
        public bool $hasPriceRange,
        public string $currency,
        public string $level,
        public array $variants,
        public ?FromPromotionView $fromPromotion,
    ) {
    }
}

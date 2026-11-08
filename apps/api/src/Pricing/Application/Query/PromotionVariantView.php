<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * A variant of a promotion. A variant deleted from the catalog (or of a draft) has no product, name or label. The
 * catalog gross is the current catalog price (null without one, also at an outdated rate); the promotional gross
 * is null with it and for an ended promotion.
 */
final readonly class PromotionVariantView
{
    public function __construct(
        public string $variantId,
        public ?string $productId,
        public ?string $productName,
        public ?string $label,
        public ?int $regularGross,
        public ?int $promotionalGross,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * The offer of one variant: its label (empty for the default variant), its current gross price (in grosze, null
 * without one), its level and whether one unit can be bought. Never a number of units.
 */
final readonly class VariantOfferView
{
    public function __construct(
        public string $variantId,
        public string $label,
        public ?int $gross,
        public string $level,
        public bool $purchasable,
    ) {
    }
}

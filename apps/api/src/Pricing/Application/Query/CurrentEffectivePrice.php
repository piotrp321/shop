<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\ActivePromotion;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Promotion\EffectivePrice;

/**
 * The effective price of a variant at a moment, with the catalog price it comes from and the promotion active then.
 */
final readonly class CurrentEffectivePrice
{
    public function __construct(
        public VariantPrice $catalog,
        public EffectivePrice $effective,
        public ?ActivePromotion $promotion,
    ) {
    }
}
